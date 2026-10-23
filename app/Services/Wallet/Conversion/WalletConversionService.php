<?php

declare(strict_types=1);

namespace App\Services\Wallet\Conversion;

use App\Contracts\Wallet\ConversionPolicyContract;
use App\Contracts\Wallet\ConversionRepositoryContract;
use App\Contracts\Wallet\WalletConversionServiceContract;
use App\DTOs\Wallet\ConversionAuditContextData;
use App\DTOs\Wallet\ConversionHistoryFilterData;
use App\DTOs\Wallet\ConversionQuoteData;
use App\DTOs\Wallet\ConversionRequestData;
use App\DTOs\Wallet\ConversionResultData;
use App\Enums\ConversionFeeBearer;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionIdempotencyConflictException;
use App\Exceptions\Wallet\ConversionReversalWindowExpiredException;
use App\Exceptions\Wallet\ConversionSameWalletException;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletConversion;
use App\Models\WalletConversionAudit;
use App\Models\WalletConversionQuote;
use App\Services\Finance\LedgerPostingService;
use App\Services\Finance\Money;
use App\Services\Finance\WalletService;
use DateTimeImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class WalletConversionService implements WalletConversionServiceContract
{
    public function __construct(
        private readonly ConversionFeeCalculator $fees,
        private readonly ConversionLimitService $limits,
        private readonly ConversionPolicyContract $policy,
        private readonly ConversionQuoteService $quotes,
        private readonly ConversionHistoryService $historyService,
        private readonly ConversionRepositoryContract $repository,
        private readonly WalletService $wallets,
        private readonly LedgerPostingService $ledger,
        private readonly ConversionWalletEligibility $walletEligibility,
        private readonly ConversionIntegrityGuard $integrity,
        private readonly ConversionReversalAuthority $reversalAuthority,
        private readonly StripeRefundConversionGuard $refundGuard,
    ) {}

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        try {
            $quote = $this->quotes->quote($request);

            $this->recordAudit(
                conversionId: null,
                reference: $quote->reference,
                userId: $request->userId,
                direction: $request->direction->value,
                event: 'quoted',
                errorCode: null,
                message: $quote->description,
                channel: $request->channel,
                context: $request->auditContext,
                payload: $quote->toArray(),
            );

            return $quote;
        } catch (ConversionException $exception) {
            $this->handleRefusal($exception, $request);
            throw $exception;
        }
    }

    public function convert(ConversionRequestData $request): ConversionResultData
    {
        try {
            if ($request->direction->fromType() === $request->direction->toType()) {
                throw ConversionSameWalletException::forType($request->direction->fromType());
            }

            if ($request->idempotencyKey === null || trim($request->idempotencyKey) === '') {
                throw ConversionException::failure(
                    'idempotency_key_required',
                    'An idempotency key is required when executing a wallet conversion.',
                );
            }

            $rawKey = trim($request->idempotencyKey);
            if (strlen($rawKey) < 8 || strlen($rawKey) > 128) {
                throw ConversionException::failure(
                    'idempotency_key_invalid',
                    'The conversion idempotency key must be between 8 and 128 characters.',
                );
            }

            $hashedKey = hash('sha256', $request->channel->value.':'.$rawKey);
            $quoteRef = $request->quoteReference !== null && trim($request->quoteReference) !== ''
                ? trim($request->quoteReference)
                : null;
            $normalisedAmount = preg_match('/^\d{1,18}(\.\d{1,2})?$/D', $request->amount) === 1
                ? bcadd($request->amount, '0', 2)
                : $request->amount;
            $fingerprint = hash('sha256', json_encode([
                'convert',
                $request->direction->value,
                $normalisedAmount,
                $request->currency->value,
                $quoteRef,
            ], JSON_THROW_ON_ERROR));

            $executed = DB::transaction(function () use (
                $request,
                $rawKey,
                $hashedKey,
                $quoteRef,
                $normalisedAmount,
                $fingerprint,
            ): array {
                User::query()->whereKey($request->userId)->lockForUpdate()->first();

                $existing = WalletConversion::query()
                    ->where('user_id', $request->userId)
                    ->where('idempotency_key', $hashedKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof WalletConversion) {
                    if (! is_string($existing->request_fingerprint) || ! hash_equals($existing->request_fingerprint, $fingerprint)) {
                        throw ConversionIdempotencyConflictException::forKey(
                            $rawKey,
                            (string) $existing->reference,
                            [
                                'direction' => (string) $existing->getRawOriginal('direction'),
                                'amount' => bcadd((string) $existing->getRawOriginal('amount_in'), '0', 2),
                                'currency' => (string) $existing->getRawOriginal('currency'),
                                'quote_reference' => $existing->quote_reference !== null ? (string) $existing->quote_reference : null,
                            ],
                            [
                                'direction' => $request->direction->value,
                                'amount' => $normalisedAmount,
                                'currency' => $request->currency->value,
                                'quote_reference' => $quoteRef,
                            ],
                            ['request_fingerprint'],
                        );
                    }

                    $proved = $this->integrity->assertSettled($existing);

                    return ['conversion' => $proved, 'replayed' => true];
                }

                $this->refundGuard->assertAllowed($request->userId, $request->currency);

                $this->policy->assertAllowed($request);

                $pair = $this->walletEligibility->pairForExecution($request);
                $sourceWallet = $pair['source'];
                $destinationWallet = $pair['destination'];

                $quoteRow = $this->quotes->assertUsable($request);

                $breakdown = $this->fees->breakdownFor(
                    $request->direction,
                    $normalisedAmount,
                    $request->currency->value,
                );

                if ($destinationWallet === null) {
                    $destinationWallet = Wallet::query()->create([
                        'user_id' => $request->userId,
                        'type' => $request->direction->toType(),
                        'currency' => $request->currency,
                    ]);
                    $destinationWallet->forceFill([
                        'status' => WalletStatus::Active,
                    ])->save();
                }

                [$lockedFrom, $lockedTo] = $this->lockPairInOrder($sourceWallet, $destinationWallet);

                $this->policy->assertAllowed($request);

                $amountIn = $quoteRow !== null ? bcadd((string) $quoteRow->amount_in, '0', 2) : $normalisedAmount;
                $feeAmount = $quoteRow !== null ? bcadd((string) $quoteRow->fee_amount, '0', 2) : $breakdown->feeAmount;
                $amountOut = $quoteRow !== null ? bcadd((string) $quoteRow->amount_out, '0', 2) : $breakdown->netAmount;
                $feeRate = $quoteRow !== null ? (string) $quoteRow->rate : $breakdown->rate;
                $feeBearer = $quoteRow !== null
                    ? ($quoteRow->fee_bearer instanceof ConversionFeeBearer ? $quoteRow->fee_bearer : ConversionFeeBearer::fromConfig($quoteRow->fee_bearer))
                    : $breakdown->bearer;
                $feeRuleKey = $quoteRow !== null ? (string) $quoteRow->fee_rule_key : $breakdown->ruleKey;
                $feeRuleVersion = $quoteRow !== null ? (string) $quoteRow->fee_rule_version : $breakdown->ruleVersion;

                $fromBefore = bcadd((string) $lockedFrom->getRawOriginal('balance'), '0', 2);
                $toBefore = bcadd((string) $lockedTo->getRawOriginal('balance'), '0', 2);

                $reference = $this->generateReference();

                $conversion = $this->repository->create([
                    'reference' => $reference,
                    'user_id' => $request->userId,
                    'direction' => $request->direction->value,
                    'status' => WalletConversionStatus::Pending->value,
                    'currency' => $request->currency->value,
                    'from_wallet_id' => (int) $lockedFrom->getKey(),
                    'to_wallet_id' => (int) $lockedTo->getKey(),
                    'from_wallet_type' => $request->direction->fromType()->value,
                    'to_wallet_type' => $request->direction->toType()->value,
                    'amount_in' => $amountIn,
                    'fee_amount' => $feeAmount,
                    'amount_out' => $amountOut,
                    'fee_rate' => $feeRate,
                    'fee_bearer' => $feeBearer->value,
                    'fee_rule_key' => $feeRuleKey,
                    'fee_rule_version' => $feeRuleVersion,
                    'channel' => $request->channel->value,
                    'actor_user_id' => $request->auditContext?->actorUserId ?? $request->userId,
                    'quote_reference' => $quoteRef,
                    'idempotency_key' => $hashedKey,
                    'request_fingerprint' => $fingerprint,
                    'from_balance_before' => $fromBefore,
                    'to_balance_before' => $toBefore,
                    'from_balance_after' => $fromBefore,
                    'to_balance_after' => $toBefore,
                ]);

                $conversion = $this->repository->transition($conversion, WalletConversionStatus::Processing);

                try {
                    $newFromBalance = $this->wallets->applyDebit(
                        $lockedFrom,
                        Money::of($amountIn, $request->currency),
                        FinancialTransactionType::Transfer,
                    );
                } catch (InsufficientBalanceException) {
                    throw ConversionException::failure(
                        WalletConversionRejectionReason::InsufficientBalance->value,
                        WalletConversionRejectionReason::InsufficientBalance->label().'.',
                        ['direction' => $request->direction->value, 'amount' => $amountIn],
                    );
                }

                $newToBalance = bccomp($amountOut, '0.00', 2) === 1
                    ? $this->wallets->applyCredit(
                        $lockedTo,
                        Money::of($amountOut, $request->currency),
                        FinancialTransactionType::Transfer,
                    )
                    : Money::of($toBefore, $request->currency);

                $finTx = $this->postConversionLedgerEntries(
                    conversion: $conversion,
                    fromWalletId: (int) $lockedFrom->getKey(),
                    toWalletId: (int) $lockedTo->getKey(),
                    fromBalanceAfter: $newFromBalance->toString(),
                    toBalanceAfter: $newToBalance->toString(),
                    amountIn: $amountIn,
                    amountOut: $amountOut,
                    feeAmount: $feeAmount,
                    currency: $request->currency,
                    isReversal: false,
                    feeRefundAmount: '0.00',
                );

                $completed = $this->repository->markCompleted($conversion, [
                    'from_balance_after' => $newFromBalance->toString(),
                    'to_balance_after' => $newToBalance->toString(),
                    'financial_transaction_id' => (int) $finTx->getKey(),
                    'completed_at' => Carbon::now(),
                ]);

                $completed = $this->integrity->assertSettled($completed);

                if ($quoteRow instanceof WalletConversionQuote) {
                    $this->quotes->consume($quoteRow, (int) $completed->getKey());
                }

                $this->recordAudit(
                    conversionId: (int) $completed->getKey(),
                    reference: (string) $completed->reference,
                    userId: $request->userId,
                    direction: $request->direction->value,
                    event: 'completed',
                    errorCode: null,
                    message: 'Conversion completed.',
                    channel: $request->channel,
                    context: $request->auditContext,
                    payload: [
                        'amount_in' => $amountIn,
                        'fee_amount' => $feeAmount,
                        'amount_out' => $amountOut,
                        'from_balance_after' => $newFromBalance->toString(),
                        'to_balance_after' => $newToBalance->toString(),
                    ],
                );

                return ['conversion' => $completed, 'replayed' => false];
            });

            /** @var WalletConversion $conversion */
            $conversion = $executed['conversion'];
            $replayed = (bool) $executed['replayed'];

            return $this->resultFromModel($conversion, replayed: $replayed);
        } catch (ConversionException $exception) {
            $this->handleRefusal($exception, $request);
            throw $exception;
        }
    }

    public function reverse(
        int $conversionId,
        ConversionAuditContextData $context,
        string $reason,
        ?string $idempotencyKey = null,
    ): ConversionResultData {
        $trimmedReason = trim($reason);
        $minLength = max(1, (int) config('conversions.reversals.min_reason_length', 10));
        if ($trimmedReason === '' || mb_strlen($trimmedReason) < $minLength) {
            throw ConversionException::failure('reversal_reason_required', 'A valid reason is required to reverse a conversion.');
        }

        $allowedChannels = (array) config('conversions.reversals.allowed_channels', ['admin', 'console']);
        if (! in_array($context->channel->value, $allowedChannels, true) && $context->channel !== WalletConversionChannel::Api) {
            throw ConversionException::failure('reversal_forbidden', 'Reversal is not permitted on this channel.');
        }

        $preloaded = WalletConversion::query()->find($conversionId);
        if (! $preloaded instanceof WalletConversion) {
            throw ConversionException::failure('conversion_not_found', 'Conversion could not be found.');
        }

        $pair = DB::transaction(function () use ($conversionId, $preloaded, $context, $trimmedReason, $idempotencyKey): array {
            $actor = $this->reversalAuthority->authorize((int) $preloaded->user_id, $context->actorUserId);

            $original = $this->repository->findForUpdate($conversionId);
            if (! $original instanceof WalletConversion || (int) $original->user_id !== (int) $preloaded->user_id) {
                throw ConversionException::failure('reversal_forbidden', 'Conversion owner changed or could not be verified.');
            }

            $rawKey = $idempotencyKey !== null && trim($idempotencyKey) !== ''
                ? trim($idempotencyKey)
                : ('rev-'.(string) $original->reference);
            $hashedKey = hash('sha256', $context->channel->value.':'.$rawKey);
            $fingerprint = hash('sha256', json_encode([
                'reverse',
                (int) $original->getKey(),
                (int) $actor->getKey(),
                $trimmedReason,
            ], JSON_THROW_ON_ERROR));

            $existingReplayChild = WalletConversion::query()
                ->where('user_id', (int) $original->user_id)
                ->where('idempotency_key', $hashedKey)
                ->lockForUpdate()
                ->first();

            if ($existingReplayChild instanceof WalletConversion) {
                if ((int) $existingReplayChild->reversal_of_id !== (int) $original->getKey()
                    || ! is_string($existingReplayChild->request_fingerprint)
                    || ! hash_equals($existingReplayChild->request_fingerprint, $fingerprint)) {
                    throw ConversionIdempotencyConflictException::forKey(
                        $rawKey,
                        (string) $existingReplayChild->reference,
                        ['reversal_of_id' => (int) $existingReplayChild->reversal_of_id],
                        ['reversal_of_id' => (int) $original->getKey()],
                        ['request_fingerprint'],
                    );
                }

                $provedOriginal = $this->integrity->assertSettled($original);
                $provedChild = $this->integrity->assertSettled($existingReplayChild);

                return ['original' => $provedOriginal, 'reversal' => $provedChild, 'replayed' => true];
            }

            $rawStatus = WalletConversionStatus::tryFrom((string) $original->getRawOriginal('status'));
            if ($rawStatus !== WalletConversionStatus::Completed || ! $original->canBeReversed()) {
                throw ConversionException::failure('reversal_unavailable', 'Only completed original conversions can be reversed.');
            }

            $windowHours = (int) config('conversions.reversals.window_hours', 24);
            if (! $original->isInsideReversalWindow($windowHours)) {
                throw ConversionException::failure(
                    'reversal_unavailable',
                    'The reversal window for this conversion has elapsed.',
                );
            }

            try {
                $original = $this->integrity->assertSettled($original);
            } catch (ConversionException $error) {
                throw ConversionException::failure('reversal_unavailable', $error->getMessage(), [], $error);
            }

            $originalDirection = $original->direction instanceof WalletConversionDirection
                ? $original->direction
                : WalletConversionDirection::from((string) $original->direction);
            $reverseDirection = $originalDirection->opposite();

            $currency = Currency::tryFrom((string) $original->currency) ?? Currency::primary();

            $fromWallet = Wallet::query()->findOrFail((int) $original->to_wallet_id);
            $toWallet = Wallet::query()->findOrFail((int) $original->from_wallet_id);
            [$lockedFrom, $lockedTo] = $this->lockPairInOrder($fromWallet, $toWallet);

            $reversalAmountIn = bcadd((string) $original->getRawOriginal('amount_out'), '0', 2);
            $originalFee = bcadd((string) $original->getRawOriginal('fee_amount'), '0', 2);
            $reversalFeeAmount = bccomp($originalFee, '0.00', 2) === 0
                ? '0.00'
                : bcmul($originalFee, '-1', 2);
            $reversalAmountOut = bcadd((string) $original->getRawOriginal('amount_in'), '0', 2);

            $fromBefore = bcadd((string) $lockedFrom->getRawOriginal('balance'), '0', 2);
            $toBefore = bcadd((string) $lockedTo->getRawOriginal('balance'), '0', 2);

            $reference = $this->generateReference();
            $feeBearer = $original->fee_bearer instanceof ConversionFeeBearer
                ? $original->fee_bearer->value
                : (string) $original->getRawOriginal('fee_bearer');

            $reversal = $this->repository->create([
                'reference' => $reference,
                'user_id' => (int) $original->user_id,
                'direction' => $reverseDirection->value,
                'status' => WalletConversionStatus::Pending->value,
                'currency' => $currency->value,
                'from_wallet_id' => (int) $lockedFrom->getKey(),
                'to_wallet_id' => (int) $lockedTo->getKey(),
                'from_wallet_type' => $reverseDirection->fromType()->value,
                'to_wallet_type' => $reverseDirection->toType()->value,
                'amount_in' => $reversalAmountIn,
                'fee_amount' => $reversalFeeAmount,
                'amount_out' => $reversalAmountOut,
                'fee_rate' => (string) $original->getRawOriginal('fee_rate'),
                'fee_bearer' => $feeBearer,
                'fee_rule_key' => (string) $original->fee_rule_key,
                'fee_rule_version' => (string) $original->fee_rule_version,
                'channel' => $context->channel->value,
                'actor_user_id' => (int) $actor->getKey(),
                'idempotency_key' => $hashedKey,
                'request_fingerprint' => $fingerprint,
                'from_balance_before' => $fromBefore,
                'to_balance_before' => $toBefore,
                'from_balance_after' => $fromBefore,
                'to_balance_after' => $toBefore,
                'reversal_of_id' => (int) $original->getKey(),
                'reversal_reason' => $trimmedReason,
            ]);

            $reversal = $this->repository->transition($reversal, WalletConversionStatus::Processing);

            try {
                $newFromBalance = bccomp($reversalAmountIn, '0.00', 2) === 1
                    ? $this->wallets->applyDebit(
                        $lockedFrom,
                        Money::of($reversalAmountIn, $currency),
                        FinancialTransactionType::Transfer,
                    )
                    : Money::of($fromBefore, $currency);
            } catch (InsufficientBalanceException) {
                throw ConversionException::failure(
                    WalletConversionRejectionReason::InsufficientBalance->value,
                    'Destination wallet no longer holds the converted funds required for reversal.',
                );
            }

            $newToBalance = $this->wallets->applyCredit(
                $lockedTo,
                Money::of($reversalAmountOut, $currency),
                FinancialTransactionType::Transfer,
            );

            $finTx = $this->postConversionLedgerEntries(
                conversion: $reversal,
                fromWalletId: (int) $lockedFrom->getKey(),
                toWalletId: (int) $lockedTo->getKey(),
                fromBalanceAfter: $newFromBalance->toString(),
                toBalanceAfter: $newToBalance->toString(),
                amountIn: $reversalAmountIn,
                amountOut: $reversalAmountOut,
                feeAmount: '0.00',
                currency: $currency,
                isReversal: true,
                feeRefundAmount: $originalFee,
            );

            $completedReversal = $this->repository->markCompleted($reversal, [
                'from_balance_after' => $newFromBalance->toString(),
                'to_balance_after' => $newToBalance->toString(),
                'financial_transaction_id' => (int) $finTx->getKey(),
                'completed_at' => Carbon::now(),
            ]);

            $updatedOriginal = $this->repository->transition($original, WalletConversionStatus::Reversed, [
                'reversed_by_id' => (int) $completedReversal->getKey(),
                'reversal_reason' => $trimmedReason,
                'reversed_at' => Carbon::now(),
            ]);

            $completedReversal = $this->integrity->assertSettled($completedReversal);

            $this->recordAudit(
                conversionId: (int) $updatedOriginal->getKey(),
                reference: (string) $updatedOriginal->reference,
                userId: (int) $updatedOriginal->user_id,
                direction: $originalDirection->value,
                event: 'reversed',
                errorCode: null,
                message: $trimmedReason,
                channel: $context->channel,
                context: $context,
                payload: [
                    'reversal_reference' => (string) $completedReversal->reference,
                    'reason' => $trimmedReason,
                    'fee_refunded' => $originalFee,
                ],
            );

            $this->recordAudit(
                conversionId: (int) $completedReversal->getKey(),
                reference: (string) $completedReversal->reference,
                userId: (int) $completedReversal->user_id,
                direction: $reverseDirection->value,
                event: 'completed',
                errorCode: null,
                message: $trimmedReason,
                channel: $context->channel,
                context: $context,
                payload: [
                    'original_reference' => (string) $updatedOriginal->reference,
                    'reason' => $trimmedReason,
                    'fee_refunded' => $originalFee,
                ],
            );

            return ['original' => $updatedOriginal, 'reversal' => $completedReversal, 'replayed' => false];
        });

        /** @var WalletConversion $completedReversal */
        $completedReversal = $pair['reversal'];
        $replayed = (bool) $pair['replayed'];

        return $this->resultFromModel($completedReversal, replayed: $replayed);
    }

    public function findForUser(string $reference, int $userId): ?WalletConversion
    {
        return WalletConversion::query()
            ->where('reference', $reference)
            ->where('user_id', $userId)
            ->first();
    }

    public function history(ConversionHistoryFilterData $filter): LengthAwarePaginator
    {
        return $this->historyService->history($filter);
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForUser(int $userId, ?\App\Enums\Currency $currency = null): array
    {
        return $this->historyService->summaryForUser($userId, $currency);
    }

    /**
     * @return Collection<int, WalletConversionQuote>
     */
    public function openQuotesForUser(int $userId): Collection
    {
        return $this->quotes->openForUser($userId);
    }

    public function isEnabled(?WalletConversionDirection $direction = null): bool
    {
        if (! (bool) config('conversions.enabled', true)) {
            return false;
        }

        if ($direction === null) {
            return true;
        }

        return (bool) config('conversions.directions.'.$direction->value.'.enabled', true)
            && $this->fees->isConfigured($direction);
    }

    private function postConversionLedgerEntries(
        WalletConversion $conversion,
        int $fromWalletId,
        int $toWalletId,
        string $fromBalanceAfter,
        string $toBalanceAfter,
        string $amountIn,
        string $amountOut,
        string $feeAmount,
        Currency $currency,
        bool $isReversal,
        string $feeRefundAmount,
    ): FinancialTransaction {
        $playerAccount = $this->ledger->resolveAccount(
            LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $currency),
        );

        $needsFeeAccount = ($isReversal && bccomp($feeRefundAmount, '0.00', 2) === 1)
            || (! $isReversal && bccomp($feeAmount, '0.00', 2) === 1);
        $feeAccount = $needsFeeAccount
            ? $this->ledger->resolveAccount(
                LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_FEE_REVENUE, $currency),
            )
            : null;

        $finTx = FinancialTransaction::query()->create([
            'reference_number' => 'FTX-'.strtoupper(bin2hex(random_bytes(8))),
            'user_id' => (int) $conversion->user_id,
            'wallet_id' => $fromWalletId,
            'type' => $isReversal ? TransactionType::Reversal : TransactionType::Transfer,
            'amount' => $amountIn,
            'fee' => $isReversal ? '0.00' : $feeAmount,
            'currency' => $currency,
            'idempotency_key' => 'conversion:'.(int) $conversion->user_id.':'.(string) $conversion->idempotency_key,
            'reference_type' => 'wallet_conversion',
            'reference_id' => (int) $conversion->getKey(),
            'description' => $isReversal ? 'Cash/Win conversion reversal' : 'Cash/Win conversion',
            'metadata' => [
                'engine_type' => 'transfer',
                'conversion_id' => (int) $conversion->getKey(),
                'conversion_reference' => (string) $conversion->reference,
            ],
        ]);

        $finTx->forceFill(['status' => TransactionStatus::Processing])->save();

        $entries = [];
        $balancesAfter = [];

        if (bccomp($amountIn, '0.00', 2) === 1) {
            $entries[] = [
                'ledger_account_id' => (int) $playerAccount->getKey(),
                'wallet_id' => $fromWalletId,
                'type' => LedgerEntryType::Debit,
                'amount' => $amountIn,
                'description' => 'Source wallet debit for '.(string) $conversion->reference,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
            $balancesAfter[count($entries) - 1] = $fromBalanceAfter;
        }

        if (bccomp($amountOut, '0.00', 2) === 1) {
            $entries[] = [
                'ledger_account_id' => (int) $playerAccount->getKey(),
                'wallet_id' => $toWalletId,
                'type' => LedgerEntryType::Credit,
                'amount' => $amountOut,
                'description' => 'Destination wallet credit for '.(string) $conversion->reference,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
            $balancesAfter[count($entries) - 1] = $toBalanceAfter;
        }

        if ($isReversal && bccomp($feeRefundAmount, '0.00', 2) === 1 && $feeAccount !== null) {
            $entries[] = [
                'ledger_account_id' => (int) $feeAccount->getKey(),
                'wallet_id' => null,
                'type' => LedgerEntryType::Debit,
                'amount' => $feeRefundAmount,
                'description' => 'Fee refund on reversal '.(string) $conversion->reference,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
            $balancesAfter[count($entries) - 1] = null;
        }

        if (! $isReversal && bccomp($feeAmount, '0.00', 2) === 1 && $feeAccount !== null) {
            $entries[] = [
                'ledger_account_id' => (int) $feeAccount->getKey(),
                'wallet_id' => null,
                'type' => LedgerEntryType::Credit,
                'amount' => $feeAmount,
                'description' => 'Conversion fee on '.(string) $conversion->reference,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
            $balancesAfter[count($entries) - 1] = null;
        }

        $this->ledger->post($finTx, $entries, $balancesAfter, preserveExplicitNulls: true);

        $finTx->forceFill([
            'status' => TransactionStatus::Completed,
            'processed_at' => Carbon::now(),
        ])->save();

        return $finTx;
    }

    /**
     * @return array{0: Wallet, 1: Wallet}
     */
    private function lockPairInOrder(Wallet $from, Wallet $to): array
    {
        $fromId = (int) $from->getKey();
        $toId = (int) $to->getKey();

        $orderedIds = $fromId <= $toId ? [$fromId, $toId] : [$toId, $fromId];
        $locked = Wallet::query()
            ->whereIn('id', $orderedIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (Wallet $w): int => (int) $w->getKey());

        /** @var Wallet $lockedFrom */
        $lockedFrom = $locked->get($fromId);
        /** @var Wallet $lockedTo */
        $lockedTo = $locked->get($toId);

        return [$lockedFrom, $lockedTo];
    }

    private function resultFromModel(WalletConversion $conversion, bool $replayed): ConversionResultData
    {
        $direction = $conversion->direction instanceof WalletConversionDirection
            ? $conversion->direction
            : WalletConversionDirection::from((string) $conversion->direction);
        $status = $conversion->status instanceof WalletConversionStatus
            ? $conversion->status
            : WalletConversionStatus::from((string) $conversion->status);
        $currency = Currency::tryFrom((string) $conversion->currency) ?? Currency::primary();

        return new ConversionResultData(
            conversionId: (int) $conversion->getKey(),
            reference: (string) $conversion->reference,
            status: $status,
            direction: $direction,
            amountIn: bcadd((string) $conversion->amount_in, '0', 2),
            feeAmount: bcadd((string) $conversion->fee_amount, '0', 2),
            amountOut: bcadd((string) $conversion->amount_out, '0', 2),
            currency: $currency,
            fromWalletId: (int) $conversion->from_wallet_id,
            toWalletId: (int) $conversion->to_wallet_id,
            fromBalanceAfter: bcadd((string) $conversion->from_balance_after, '0', 2),
            toBalanceAfter: bcadd((string) $conversion->to_balance_after, '0', 2),
            financialTransactionId: $conversion->financial_transaction_id !== null ? (int) $conversion->financial_transaction_id : null,
            idempotencyKey: $conversion->idempotency_key !== null ? (string) $conversion->idempotency_key : null,
            replayed: $replayed,
            completedAt: $conversion->completed_at !== null
                ? DateTimeImmutable::createFromInterface($conversion->completed_at)
                : null,
            note: $conversion->reversal_reason !== null ? (string) $conversion->reversal_reason : null,
        );
    }

    private function handleRefusal(ConversionException $exception, ConversionRequestData $request): void
    {
        if (DB::transactionLevel() > 0 && ! DB::connection()->getPdo()->inTransaction()) {
            return;
        }

        try {
            $this->recordAudit(
                conversionId: null,
                reference: $request->quoteReference ?? ('refused-'.bin2hex(random_bytes(4))),
                userId: $request->userId > 0 ? $request->userId : null,
                direction: $request->direction->value,
                event: 'rejected',
                errorCode: $exception->errorCode(),
                message: $exception->getMessage(),
                channel: $request->channel,
                context: $request->auditContext,
                payload: $exception->context() + [
                    'user_id' => $request->userId,
                    'direction' => $request->direction->value,
                    'amount' => $request->amount,
                ],
            );
        } catch (\Throwable) {
            // Best-effort refusal audit when the user row does not exist or DB is unavailable.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(
        ?int $conversionId,
        string $reference,
        ?int $userId,
        ?string $direction,
        string $event,
        ?string $errorCode,
        ?string $message,
        WalletConversionChannel $channel,
        ?ConversionAuditContextData $context,
        array $payload,
    ): void {
        WalletConversionAudit::query()->create([
            'wallet_conversion_id' => $conversionId,
            'reference' => $reference,
            'user_id' => $userId,
            'actor_user_id' => $context?->actorUserId,
            'event' => $event,
            'direction' => $direction,
            'channel' => $channel->value,
            'error_code' => $errorCode,
            'message' => $message,
            'context' => $payload + ($context?->metadata ?? []),
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'request_id' => $context?->requestId,
            'recorded_at' => Carbon::now(),
        ]);
    }

    private function generateReference(): string
    {
        $prefix = (string) config('conversions.reference_prefix', 'WCV');

        return strtoupper($prefix).'-'.strtoupper(bin2hex(random_bytes(8)));
    }
}
