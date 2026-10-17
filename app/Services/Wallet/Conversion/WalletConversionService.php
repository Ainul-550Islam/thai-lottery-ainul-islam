<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\DTOs\Wallet\ConversionResultData;
use App\Enums\Currency;
use App\Enums\KycStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletStatus;
use App\Events\Wallet\ConversionCompleted;
use App\Exceptions\FinancialException;
use App\Exceptions\Wallet\ConversionDisabledException;
use App\Exceptions\Wallet\ConversionException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Repositories\Wallet\WalletConversionAuditRepository;
use App\Repositories\Wallet\WalletConversionRepository;
use App\Services\Finance\LedgerPostingService;
use Carbon\Carbon;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one and only entry point for moving money between the Cash (primary) and
 * Win wallets. Nothing bypasses its policy gate, quote arithmetic, idempotency
 * behaviour or ledger postings.
 *
 * THE MOVEMENT, IN ONE TRANSACTION
 * 1. Refusals that cost nothing (lane off, identity, standing, limits, price)
 *    happen BEFORE any lock is taken.
 * 2. A money-moving request without its idempotency twin either replays the
 *    earlier movement exactly or is refused — never re-executed.
 * 3. Inside DB::transaction: lock both wallet rows, re-check the balance,
 *    create the conversion row, create its financial transaction, post the
 *    balanced double-entry set, move the wallet balances, complete the row with
 *    the balances captured in the same transaction, and bind the quote it
 *    consumed.
 * 4. Every success AND every refusal is written to the audit trail.
 *
 * THE POSTING SHAPE (conversions.ledger)
 * amount_in leaves the source wallet, amount_out arrives at the destination
 * wallet, and the fee lands in fee revenue. Each pair is balanced through the
 * transfer counterpart account (3000) — never wallet-to-wallet — and the
 * posting identity (transaction + account + side) allows at most one debit and
 * one credit per account, so the fee leg is folded into the bridge debit:
 *
 *   debit  2000@source  amount_in        / credit 3000     amount_in
 *   debit  3000         amount_in        / credit 2000@dest amount_out
 *                                        / credit 4100      fee
 *
 * A reversal posts the mirror image and refunds the original fee through the
 * same accounts, so the conversion reconciliation job's expectations hold for
 * both kinds of movement:
 *
 *   debit  2000@from    amount_in        / credit 3000     amount_out
 *   debit  4100         fee_refund       |
 *   debit  3000         amount_out       / credit 2000@to  amount_out
 */
final class WalletConversionService implements \App\Contracts\Wallet\WalletConversionServiceContract
{
    public function __construct(
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionQuoteService $quotes,
        private readonly WalletConversionRepository $conversions,
        private readonly WalletConversionAuditRepository $audit,
        private readonly LedgerPostingService $ledger,
    ) {
    }

    /**
     * Price a conversion without moving anything.
     */
    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        $this->assertLaneUsable($request);
        $this->assertMemberMayConvert($request);
        $this->assertAmountWithinEnvelope($request);

        $limits = $this->limits->limits($request->currency);
        $dailyTotal = $this->limits->dailyTotal($request->userId);
        $monthlyTotal = $this->limits->monthlyTotal($request->userId);

        $violations = $limits->rollingViolations($request->amount, $dailyTotal, $monthlyTotal);
        if ($violations !== []) {
            $this->refuseFor($violations[0], $request);
        }

        $this->assertBalanceCovers($request);

        $quote = $this->quotes->issue($request, $limits, $dailyTotal, $monthlyTotal);

        $this->recordAudit(
            conversionId: null,
            reference: $quote->reference,
            event: 'quote_issued',
            errorCode: null,
            channel: $request->channel,
            context: $request->auditContext,
            userId: $request->userId,
        );

        return $quote;
    }

    /**
     * Execute a conversion: exactly once per idempotency key, or not at all.
     */
    public function convert(ConversionRequestData $request): ConversionResultData
    {
        // Idempotency comes FIRST: a retry must replay the earlier movement even
        // when that movement consumed the daily allowance or drained the wallet
        // that a fresh attempt could no longer cover. A key with no twin falls
        // through to the gates below and is refused like any other request.
        $replay = $this->existingForKey($request);
        if ($replay instanceof WalletConversion) {
            return $this->replayResult($replay, $request->idempotencyKey);
        }

        $this->assertLaneUsable($request);
        $this->assertMemberMayConvert($request);
        $this->assertAmountWithinEnvelope($request);

        $limits = $this->limits->limits($request->currency);
        $violations = $limits->rollingViolations(
            $request->amount,
            $this->limits->dailyTotal($request->userId),
            $this->limits->monthlyTotal($request->userId),
        );
        if ($violations !== []) {
            $this->refuseFor($violations[0], $request);
        }

        $this->assertBalanceCovers($request);

        $quote = null;
        if ((bool) config('conversions.quotes.require_for_execution', true)) {
            if ($request->quoteReference === null) {
                throw ConversionException::failure(
                    WalletConversionRejectionReason::QuoteNotFound->value,
                    'Request a quote first, then execute against it — the price you were shown must be the price charged.',
                    ['direction' => $request->direction->value],
                );
            }

            $quote = $this->quotes->resolveForExecution($request->quoteReference, $request);
        }

        // The movement charges the QUOTED numbers, whatever the live tariff
        // says now: the price a member saw is the price the member pays.
        $breakdown = $this->fees->breakdownFor($request->direction, $request->amount, $request->currency->value);
        $amountIn = bcadd($request->amount, '0', 2);
        $feeAmount = $quote !== null ? $quote->feeAmount : $breakdown->feeAmount;
        $amountOut = $quote !== null ? $quote->amountOut : $breakdown->netAmount;
        $rate = $quote !== null ? $quote->rate : $breakdown->rate;
        $ruleKey = $quote !== null ? $quote->feeRuleKey : $breakdown->ruleKey;
        $ruleVersion = $quote !== null ? $quote->feeRuleVersion : $breakdown->ruleVersion;

        $reference = $this->reference('WC');

        try {
            return DB::transaction(function () use (
                $request,
                $quote,
                $reference,
                $amountIn,
                $feeAmount,
                $amountOut,
                $rate,
                $ruleKey,
                $ruleVersion,
            ): ConversionResultData {
                [$from, $to] = $this->lockWalletPair($request);

                if (bccomp((string) $from->balance, $amountIn, 2) < 0) {
                    throw ConversionException::failure(
                        WalletConversionRejectionReason::InsufficientBalance->value,
                        'Your wallet does not hold enough for this conversion.',
                        ['required' => $amountIn],
                    );
                }

                $conversion = $this->conversions->create([
                    'reference' => $reference,
                    'user_id' => $request->userId,
                    'direction' => $request->direction->value,
                    'status' => WalletConversionStatus::Processing->value,
                    'currency' => $request->currency->value,
                    'amount_in' => $amountIn,
                    'fee_amount' => $feeAmount,
                    'amount_out' => $amountOut,
                    'fee_rate' => $rate,
                    'fee_rule_key' => $ruleKey,
                    'fee_rule_version' => $ruleVersion,
                    'from_wallet_id' => (int) $from->getKey(),
                    'to_wallet_id' => (int) $to->getKey(),
                    'from_wallet_type' => (string) $from->type?->value,
                    'to_wallet_type' => (string) $to->type?->value,
                    'channel' => $request->channel->value,
                    'quote_reference' => $quote?->reference,
                    'idempotency_key' => $request->idempotencyKey,
                ]);

                $transaction = $this->createFinancialTransaction(
                    conversion: $conversion,
                    userId: $request->userId,
                    currency: $request->currency,
                    amountIn: $amountIn,
                    feeAmount: $feeAmount,
                    idempotencyKey: $request->idempotencyKey,
                );

                $this->postMovement(
                    transaction: $transaction,
                    conversion: $conversion,
                    amountIn: $amountIn,
                    amountOut: $amountOut,
                    feeAmount: $feeAmount,
                    currency: $request->currency,
                );

                $from->balance = bcsub((string) $from->balance, $amountIn, 2);
                $from->save();
                $to->balance = bcadd((string) $to->balance, $amountOut, 2);
                $to->save();

                $transaction->status = TransactionStatus::Completed;
                $transaction->processed_at = Carbon::now();
                $transaction->save();

                $conversion = $this->conversions->markCompleted($conversion, [
                    'from_balance_after' => bcadd((string) $from->balance, '0', 2),
                    'to_balance_after' => bcadd((string) $to->balance, '0', 2),
                    'financial_transaction_id' => (int) $transaction->getKey(),
                ]);

                if ($quote !== null) {
                    $this->quotes->consume($quote->reference, (int) $conversion->getKey());
                }

                $this->recordAudit(
                    conversionId: (int) $conversion->getKey(),
                    reference: $reference,
                    event: 'conversion_completed',
                    errorCode: null,
                    channel: $request->channel,
                    context: $request->auditContext,
                    userId: $request->userId,
                );

                $result = $this->resultFrom($conversion, replayed: false, idempotencyKey: $request->idempotencyKey);

                event(ConversionCompleted::fromConversion($conversion));

                return $result;
            });
        } catch (ConversionException $exception) {
            $this->recordAudit(
                conversionId: null,
                reference: $reference,
                event: 'conversion_refused',
                errorCode: (string) $exception->errorCode(),
                channel: $request->channel,
                context: $request->auditContext,
                userId: $request->userId,
            );

            throw $exception;
        }
    }

    /**
     * Reverse a completed conversion by posting the opposite movement.
     */
    public function reverse(
        int $conversionId,
        ConversionAuditContextData $context,
        string $reason,
        ?string $idempotencyKey = null,
    ): ConversionResultData {
        $reason = trim($reason);
        $minimumReason = (int) config('conversions.reversal.min_reason_length', 10);

        ConversionException::refuseUnless(
            (bool) config('conversions.reversal.enabled', true),
            WalletConversionRejectionReason::FeatureDisabled->value,
            'Reversals are currently disabled.',
        );

        ConversionException::refuseUnless(
            strlen($reason) >= $minimumReason,
            WalletConversionRejectionReason::AmountInvalid->value,
            sprintf('A reversal must state why it is being performed, in at least %d characters.', $minimumReason),
        );

        $original = $this->conversions->findForUpdate($conversionId);

        if (! $original instanceof WalletConversion) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteNotFound->value,
                'That conversion could not be found.',
            );
        }

        // Idempotency is checked BEFORE the "can be reversed" gate so a retry
        // of a successful reversal replays instead of tripping over the fact
        // that the original is already reversed.
        $replay = $this->existingReversalForKey(
            conversionId: $conversionId,
            userId: (int) $original->user_id,
            channel: $context->channel,
            idempotencyKey: $idempotencyKey,
        );
        if ($replay instanceof WalletConversion) {
            return $this->resultFrom($replay, replayed: true, idempotencyKey: $idempotencyKey);
        }

        $reference = $this->reference('WCR');

        try {
            return DB::transaction(function () use ($conversionId, $context, $reason, $idempotencyKey, $original, $reference): ConversionResultData {
                /** @var WalletConversion $original */
                $original = $this->conversions->findForUpdate($conversionId) ?? $original;

                ConversionException::refuseUnless(
                    $original->canBeReversed(),
                    WalletConversionRejectionReason::AmountInvalid->value,
                    'That conversion cannot be reversed.',
                    ['reference' => (string) $original->reference],
                );

                ConversionException::refuseUnless(
                    $original->isInsideReversalWindow((int) config('conversions.reversal.window_hours', 24)),
                    WalletConversionRejectionReason::AmountInvalid->value,
                    'The reversal window for that conversion has closed.',
                    ['reference' => (string) $original->reference],
                );

                /** @var Wallet $from */
                $from = Wallet::query()->whereKey((int) $original->to_wallet_id)->lockForUpdate()->firstOrFail();
                /** @var Wallet $to */
                $to = Wallet::query()->whereKey((int) $original->from_wallet_id)->lockForUpdate()->firstOrFail();

                // The reversal moves back the DELIVERED amount and refunds the
                // fee the original charged: the source wallet ends exactly
                // where it started.
                $amountIn = bcadd((string) $original->amount_out, '0', 2);
                $refund = bcadd((string) $original->fee_amount, '0', 2);
                $amountOut = bcadd($amountIn, $refund, 2);
                $currency = Currency::tryFrom((string) $original->currency) ?? Currency::primary();

                if (bccomp((string) $from->balance, $amountIn, 2) < 0) {
                    throw ConversionException::failure(
                        WalletConversionRejectionReason::InsufficientBalance->value,
                        'The destination wallet does not hold enough to reverse this movement.',
                        ['required' => $amountIn],
                    );
                }

                $reversal = $this->conversions->create([
                    'reference' => $reference,
                    'user_id' => (int) $original->user_id,
                    'direction' => $original->direction->opposite()->value,
                    'status' => WalletConversionStatus::Processing->value,
                    'currency' => (string) $original->currency,
                    'amount_in' => $amountIn,
                    'fee_amount' => $refund,
                    'amount_out' => $amountOut,
                    'fee_rate' => (string) $original->fee_rate,
                    'fee_rule_key' => (string) $original->fee_rule_key,
                    'fee_rule_version' => (string) $original->fee_rule_version,
                    'from_wallet_id' => (int) $from->getKey(),
                    'to_wallet_id' => (int) $to->getKey(),
                    'from_wallet_type' => (string) $from->type?->value,
                    'to_wallet_type' => (string) $to->type?->value,
                    'channel' => $context->channel->value,
                    'idempotency_key' => $idempotencyKey,
                    'reversal_of_id' => (int) $original->getKey(),
                    'reversal_reason' => $reason,
                ]);

                $transaction = $this->createFinancialTransaction(
                    conversion: $reversal,
                    userId: (int) $original->user_id,
                    currency: $currency,
                    amountIn: $amountIn,
                    feeAmount: $refund,
                    idempotencyKey: $idempotencyKey,
                    type: TransactionType::Reversal,
                );

                $this->postMovement(
                    transaction: $transaction,
                    conversion: $reversal,
                    amountIn: $amountIn,
                    amountOut: $amountOut,
                    feeAmount: $refund,
                    currency: $currency,
                    feeRefund: true,
                );

                $from->balance = bcsub((string) $from->balance, $amountIn, 2);
                $from->save();
                $to->balance = bcadd((string) $to->balance, $amountOut, 2);
                $to->save();

                $transaction->status = TransactionStatus::Completed;
                $transaction->processed_at = Carbon::now();
                $transaction->save();

                $reversal = $this->conversions->markCompleted($reversal, [
                    'from_balance_after' => bcadd((string) $from->balance, '0', 2),
                    'to_balance_after' => bcadd((string) $to->balance, '0', 2),
                    'financial_transaction_id' => (int) $transaction->getKey(),
                ]);

                $this->conversions->transition($original, WalletConversionStatus::Reversed, [
                    'reversed_by_id' => (int) $reversal->getKey(),
                    'reversed_at' => Carbon::now(),
                    'reversal_reason' => $reason,
                ]);

                $this->recordAudit(
                    conversionId: (int) $reversal->getKey(),
                    reference: (string) $reversal->reference,
                    event: 'conversion_reversed',
                    errorCode: null,
                    channel: $context->channel,
                    context: $context,
                    userId: (int) $original->user_id,
                );

                return $this->resultFrom($reversal, replayed: false, idempotencyKey: $idempotencyKey);
            });
        } catch (ConversionException $exception) {
            $this->recordAudit(
                conversionId: null,
                reference: $reference,
                event: 'reversal_refused',
                errorCode: (string) $exception->errorCode(),
                channel: $context->channel,
                context: $context,
                userId: (int) $context->actorUserId,
            );

            throw $exception;
        }
    }

    /**
     * Read one conversion by its public reference, scoped to its owner.
     */
    public function findForUser(string $reference, int $userId): ?WalletConversion
    {
        $conversion = $this->conversions->findByReference($reference);

        if (! $conversion instanceof WalletConversion) {
            return null;
        }

        return (int) $conversion->user_id === $userId ? $conversion : null;
    }

    /**
     * A page of conversion history, always scoped to one owner.
     */
    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        return $this->conversions->paginate($filter);
    }

    /**
     * Whether conversions are available at all, or for one specific direction.
     */
    public function isEnabled(?WalletConversionDirection $direction = null): bool
    {
        if (! (bool) config('conversions.enabled', true)) {
            return false;
        }

        if ($direction === null) {
            return true;
        }

        return (bool) config('conversions.directions.'.$direction->value.'.enabled', true);
    }

    /**
     * The still-open quotes a member holds. The form lists them so a member
     * can execute against the price they already saw.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\WalletConversionQuote>
     */
    public function openQuotesForUser(int $userId)
    {
        return $this->quotes->openForUser($userId);
    }

    /**
     * @return array{total: int, completed: int, completed_volume_in: string}
     */
    public function summaryForUser(int $userId): array
    {
        $counts = $this->conversions->statusCountsForUser($userId);

        return [
            'total' => array_sum($counts),
            'completed' => (int) ($counts[WalletConversionStatus::Completed->value] ?? 0),
            'completed_volume_in' => $this->conversions->completedTotalForUser($userId, new DateTimeImmutable('@0')),
        ];
    }

    private function assertLaneUsable(ConversionRequestData $request): void
    {
        if (! (bool) config('conversions.enabled', true)) {
            throw ConversionDisabledException::feature();
        }

        if (! (bool) config('conversions.directions.'.$request->direction->value.'.enabled', true)) {
            throw ConversionDisabledException::forDirection($request->direction);
        }

        $breakdown = $this->fees->breakdownFor($request->direction, $request->amount, $request->currency->value);
        if (! $breakdown->isConfigured) {
            // With no published price the direction is, from the member's
            // point of view, unavailable.
            throw ConversionDisabledException::feature();
        }
    }

    private function assertMemberMayConvert(ConversionRequestData $request): void
    {
        $user = User::query()->find($request->userId);

        if (! $user instanceof User) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::QuoteNotFound->value,
                'That member could not be found.',
            );
        }

        if ((bool) config('conversions.gates.require_kyc', true) && $user->kycStatus() !== KycStatus::Verified) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::KycNotVerified->value,
                'Verify your identity before converting between wallets.',
            );
        }

        if ((bool) config('conversions.gates.block_if_suspended', true) && ! $user->isActive()) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::AccountSuspended->value,
                'Your account cannot convert right now.',
            );
        }

        if ((bool) config('conversions.gates.block_if_self_excluded', true) && $user->isSelfExcluded()) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::SelfExclusionActive->value,
                'Your self-exclusion is active and blocks conversions.',
            );
        }
    }

    private function assertAmountWithinEnvelope(ConversionRequestData $request): void
    {
        $limits = $this->limits->limits($request->currency);

        $violations = $limits->transactionViolations($request->amount);
        if ($violations !== []) {
            $this->refuseFor($violations[0], $request);
        }
    }

    private function assertBalanceCovers(ConversionRequestData $request): void
    {
        $sourceType = $request->direction->fromType();

        $wallet = Wallet::query()
            ->where('user_id', $request->userId)
            ->where('type', $sourceType->value)
            ->where('currency', $request->currency->value)
            ->first();

        $available = $wallet instanceof Wallet ? (string) $wallet->balance : '0.00';

        if (bccomp($available, $request->amount, 2) < 0) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::InsufficientBalance->value,
                'Your wallet does not hold enough for this conversion.',
                ['required' => $request->amount, 'available' => $available],
            );
        }

        if ($wallet instanceof Wallet && $wallet->status !== WalletStatus::Active) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::WalletLocked->value,
                'Your wallet is locked and cannot move funds.',
            );
        }
    }

    /**
     * @return array{0: Wallet, 1: Wallet}
     */
    private function lockWalletPair(ConversionRequestData $request): array
    {
        $from = Wallet::query()
            ->where('user_id', $request->userId)
            ->where('type', $request->direction->fromType()->value)
            ->where('currency', $request->currency->value)
            ->lockForUpdate()
            ->first();

        $to = Wallet::query()
            ->where('user_id', $request->userId)
            ->where('type', $request->direction->toType()->value)
            ->where('currency', $request->currency->value)
            ->lockForUpdate()
            ->first();

        if (! $from instanceof Wallet || ! $to instanceof Wallet) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::WalletLocked->value,
                'Both wallets must exist and be active before converting.',
            );
        }

        return [$from, $to];
    }

    private function existingForKey(ConversionRequestData $request): ?WalletConversion
    {
        if ($request->idempotencyKey === null || $request->idempotencyKey === '') {
            if ((bool) config('conversions.idempotency.required', true)) {
                throw ConversionException::failure(
                    WalletConversionRejectionReason::IdempotencyConflict->value,
                    'This request moves money and must carry an idempotency key so a retry cannot convert twice.',
                );
            }

            return null;
        }

        $existing = WalletConversion::query()
            ->where('user_id', $request->userId)
            ->where('channel', $request->channel->value)
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();

        if (! $existing instanceof WalletConversion) {
            return null;
        }

        $identical = $existing->direction === $request->direction
            && bccomp((string) $existing->amount_in, $request->amount, 2) === 0
            && (string) $existing->currency === $request->currency->value;

        if (! $identical) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::IdempotencyConflict->value,
                'That idempotency key was already used for a different request.',
                ['idempotency_key' => $request->idempotencyKey],
            );
        }

        return $existing;
    }

    private function existingReversalForKey(
        int $conversionId,
        int $userId,
        WalletConversionChannel $channel,
        ?string $idempotencyKey,
    ): ?WalletConversion {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            if ((bool) config('conversions.idempotency.required', true)) {
                throw ConversionException::failure(
                    WalletConversionRejectionReason::IdempotencyConflict->value,
                    'This request moves money and must carry an idempotency key so a retry cannot reverse twice.',
                );
            }

            return null;
        }

        $existing = WalletConversion::query()
            ->where('user_id', $userId)
            ->where('channel', $channel->value)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $existing instanceof WalletConversion) {
            return null;
        }

        if ((int) $existing->reversal_of_id !== $conversionId) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::IdempotencyConflict->value,
                'That idempotency key was already used for a different request.',
                ['idempotency_key' => $idempotencyKey],
            );
        }

        return $existing;
    }

    private function replayResult(WalletConversion $conversion, ?string $idempotencyKey): ConversionResultData
    {
        if ($conversion->status === WalletConversionStatus::Completed
            || $conversion->status === WalletConversionStatus::Reversed) {
            return $this->resultFrom($conversion, replayed: true, idempotencyKey: $idempotencyKey);
        }

        throw ConversionException::failure(
            WalletConversionRejectionReason::IdempotencyConflict->value,
            'A conversion with that idempotency key is still in progress.',
            ['reference' => (string) $conversion->reference],
        );
    }

    private function resultFrom(WalletConversion $conversion, bool $replayed, ?string $idempotencyKey): ConversionResultData
    {
        return new ConversionResultData(
            conversionId: (int) $conversion->getKey(),
            reference: (string) $conversion->reference,
            status: $conversion->status,
            direction: $conversion->direction,
            amountIn: bcadd((string) $conversion->amount_in, '0', 2),
            feeAmount: bcadd((string) $conversion->fee_amount, '0', 2),
            amountOut: bcadd((string) $conversion->amount_out, '0', 2),
            currency: Currency::tryFrom((string) $conversion->currency) ?? Currency::primary(),
            fromWalletId: (int) $conversion->from_wallet_id,
            toWalletId: (int) $conversion->to_wallet_id,
            fromBalanceAfter: $conversion->from_balance_after === null ? null : bcadd((string) $conversion->from_balance_after, '0', 2),
            toBalanceAfter: $conversion->to_balance_after === null ? null : bcadd((string) $conversion->to_balance_after, '0', 2),
            financialTransactionId: $conversion->financial_transaction_id === null ? null : (int) $conversion->financial_transaction_id,
            idempotencyKey: $idempotencyKey,
            replayed: $replayed,
            completedAt: $conversion->completed_at ? new DateTimeImmutable($conversion->completed_at->toDateTimeString()) : null,
        );
    }

    private function createFinancialTransaction(
        WalletConversion $conversion,
        int $userId,
        Currency $currency,
        string $amountIn,
        string $feeAmount,
        ?string $idempotencyKey,
        TransactionType $type = TransactionType::Transfer,
    ): FinancialTransaction {
        $transaction = new FinancialTransaction;
        $transaction->fill([
            'reference_number' => $this->reference('FT'),
            'user_id' => $userId,
            'wallet_id' => (int) $conversion->from_wallet_id,
            'type' => $type,
            'currency' => $currency,
            'amount' => $amountIn,
            'fee' => $feeAmount,
            'description' => sprintf('Wallet conversion %s', $conversion->reference),
            'metadata' => [
                'wallet_conversion_reference' => (string) $conversion->reference,
                'direction' => (string) $conversion->direction->value,
            ],
            'idempotency_key' => $idempotencyKey,
            'reference_type' => (string) config('conversions.ledger.reference_type', 'wallet_conversion'),
            'reference_id' => (int) $conversion->getKey(),
        ]);
        $transaction->uuid = (string) Str::uuid();
        $transaction->status = TransactionStatus::Pending;
        $transaction->save();

        return $transaction;
    }

    /**
     * Post the balanced double-entry set for one movement. The pair discipline
     * is fixed: every wallet debit is balanced by the transfer counterpart, and
     * the fee leg shares the bridge debit so each account keeps at most one
     * debit and one credit per transaction (the posting-identity constraint).
     */
    private function postMovement(
        FinancialTransaction $transaction,
        WalletConversion $conversion,
        string $amountIn,
        string $amountOut,
        string $feeAmount,
        Currency $currency,
        bool $feeRefund = false,
    ): void {
        $referenceType = (string) config('conversions.ledger.reference_type', 'wallet_conversion');
        $referenceId = (int) $conversion->getKey();

        $sourceAccount = $this->walletAccountFor(
            (int) $conversion->from_wallet_id,
            (int) $conversion->user_id,
            (string) $conversion->from_wallet_type,
            $currency,
        );
        $destinationAccount = $this->walletAccountFor(
            (int) $conversion->to_wallet_id,
            (int) $conversion->user_id,
            (string) $conversion->to_wallet_type,
            $currency,
        );
        $bridgeAccount = $this->ledger->resolveAccount(
            LedgerPostingService::accountCodeFor((string) config('conversions.ledger.transfer_counterpart_code', '3000'), $currency),
        );
        $feeAccount = $this->ledger->resolveAccount(
            LedgerPostingService::accountCodeFor((string) config('conversions.ledger.fee_revenue_account_code', '4100'), $currency),
        );

        $entries = [];

        if (! $feeRefund) {
            // amount_in leaves the source wallet into the transfer bridge …
            $entries[] = [
                'ledger_account_id' => (int) $sourceAccount->getKey(),
                'type' => LedgerEntryType::Debit,
                'amount' => $amountIn,
                'wallet_id' => (int) $conversion->from_wallet_id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion source debit',
            ];
            $entries[] = [
                'ledger_account_id' => (int) $bridgeAccount->getKey(),
                'type' => LedgerEntryType::Credit,
                'amount' => $amountIn,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion source bridge',
            ];

            // … and the bridge funds the delivered amount and the fee revenue.
            $entries[] = [
                'ledger_account_id' => (int) $bridgeAccount->getKey(),
                'type' => LedgerEntryType::Debit,
                'amount' => $amountIn,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion destination bridge',
            ];
            $entries[] = [
                'ledger_account_id' => (int) $destinationAccount->getKey(),
                'type' => LedgerEntryType::Credit,
                'amount' => $amountOut,
                'wallet_id' => (int) $conversion->to_wallet_id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion destination credit',
            ];

            if (bccomp($feeAmount, '0', 2) !== 0) {
                $entries[] = [
                    'ledger_account_id' => (int) $feeAccount->getKey(),
                    'type' => LedgerEntryType::Credit,
                    'amount' => $feeAmount,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => 'Conversion fee revenue',
                ];
            }
        } else {
            // A reversal: the delivered amount plus the refunded fee leave the
            // source wallet and fee revenue, and the bridge pays all of it back
            // into the destination wallet.
            $entries[] = [
                'ledger_account_id' => (int) $sourceAccount->getKey(),
                'type' => LedgerEntryType::Debit,
                'amount' => $amountIn,
                'wallet_id' => (int) $conversion->from_wallet_id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion reversal source debit',
            ];

            if (bccomp($feeAmount, '0', 2) !== 0) {
                $entries[] = [
                    'ledger_account_id' => (int) $feeAccount->getKey(),
                    'type' => LedgerEntryType::Debit,
                    'amount' => $feeAmount,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'description' => 'Conversion fee refund',
                ];
            }

            $entries[] = [
                'ledger_account_id' => (int) $bridgeAccount->getKey(),
                'type' => LedgerEntryType::Credit,
                'amount' => $amountOut,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion reversal bridge',
            ];
            $entries[] = [
                'ledger_account_id' => (int) $bridgeAccount->getKey(),
                'type' => LedgerEntryType::Debit,
                'amount' => $amountOut,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion reversal destination bridge',
            ];
            $entries[] = [
                'ledger_account_id' => (int) $destinationAccount->getKey(),
                'type' => LedgerEntryType::Credit,
                'amount' => $amountOut,
                'wallet_id' => (int) $conversion->to_wallet_id,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'description' => 'Conversion reversal destination credit',
            ];
        }

        $this->ledger->post($transaction, $entries);
    }

    /**
     * Resolve the chart-of-accounts row for one wallet. The conversion lane
     * prefers the per-wallet liability account (`2000:{wallet_id}`) and falls
     * back to the shared player-liability account for deployments whose chart
     * predates per-wallet accounts — never to a freshly invented account.
     */
    private function walletAccountFor(int $walletId, int $userId, string $walletType, Currency $currency): LedgerAccount
    {
        $base = (string) config('conversions.ledger.wallet_account_code', '2000');

        // The shared player-liability account is the documented chart entry
        // (WalletService::ACCOUNT_PLAYER_LIABILITY); the per-wallet code is
        // honoured for deployments that split liability per wallet.
        $candidates = [
            LedgerPostingService::accountCodeFor($base, $currency),
            LedgerPostingService::accountCodeFor($base.':'.$walletId, $currency),
        ];

        foreach ($candidates as $code) {
            $account = LedgerAccount::query()->where('code', $code)->first();
            if ($account instanceof LedgerAccount) {
                return $account;
            }
        }

        throw FinancialException::withCode(
            'ledger_account_missing',
            sprintf(
                'No ledger account exists for wallet %d (%s) under codes [%s]. Run the WalletConversionLedgerSeeder.',
                $walletId,
                $walletType,
                implode(', ', $candidates),
            ),
            ['wallet_id' => $walletId, 'user_id' => $userId],
        );
    }

    private function refuseFor(WalletConversionRejectionReason $reason, ConversionRequestData $request): never
    {
        throw ConversionException::failure(
            $reason->value,
            $reason->label().'.',
            ['direction' => $request->direction->value, 'amount' => $request->amount],
        );
    }

    private function recordAudit(
        ?int $conversionId,
        string $reference,
        string $event,
        ?string $errorCode,
        WalletConversionChannel $channel,
        ?ConversionAuditContextData $context,
        int $userId,
    ): void {
        $this->audit->record([
            'wallet_conversion_id' => $conversionId,
            'reference' => $reference,
            'event' => $event,
            'error_code' => $errorCode,
            'channel' => $channel->value,
            'actor_user_id' => $context?->actorUserId ?? $userId,
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'request_id' => $context?->requestId,
            'context' => $context?->toArray(),
            'recorded_at' => Carbon::now(),
        ]);
    }

    private function reference(string $prefix): string
    {
        return $prefix.'-'.strtoupper(bin2hex(random_bytes(8)));
    }
}
