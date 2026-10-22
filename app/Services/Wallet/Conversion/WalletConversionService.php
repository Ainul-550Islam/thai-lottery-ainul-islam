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
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\LedgerAccountType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Enums\WalletConversionChannel;
use App\Enums\WalletConversionDirection;
use App\Enums\WalletConversionRejectionReason;
use App\Enums\WalletConversionStatus;
use App\Enums\WalletStatus;
use App\Enums\WalletType;
use App\Events\Wallet\ConversionCompleted;
use App\Events\Wallet\ConversionRejected;
use App\Events\Wallet\ConversionRequested;
use App\Events\Wallet\ConversionReversed;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\Wallet\ConversionException;
use App\Exceptions\Wallet\ConversionIdempotencyConflictException;
use App\Exceptions\Wallet\ConversionQuoteExpiredException;
use App\Exceptions\Wallet\ConversionSameWalletException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
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
    ) {}

    public function quote(ConversionRequestData $request): ConversionQuoteData
    {
        try {
            $quote = $this->quotes->issue($request);

            $this->recordAudit(
                conversionId: null,
                reference: $quote->reference,
                event: 'quote_issued',
                errorCode: null,
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
        event(new ConversionRequested(
            userId: $request->userId,
            direction: $request->direction,
            amount: bcadd($request->amount, '0', 2),
            currency: $request->currency,
            channel: $request->channel,
            quoteReference: $request->quoteReference,
            idempotencyKey: $request->idempotencyKey,
        ));

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

            $existing = WalletConversion::query()
                ->where('user_id', $request->userId)
                ->where('idempotency_key', $request->idempotencyKey)
                ->first();

            if ($existing instanceof WalletConversion) {
                $this->assertIdempotencyMatch($existing, $request);

                return $this->resultFromModel($existing, replayed: true);
            }

            $this->policy->assertAllowed($request);

            $breakdown = $this->fees->breakdownFor(
                $request->direction,
                $request->amount,
                $request->currency->value,
            );

            $executed = DB::transaction(function () use ($request, $breakdown): array {
                $raced = WalletConversion::query()
                    ->where('user_id', $request->userId)
                    ->where('idempotency_key', $request->idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($raced instanceof WalletConversion) {
                    $this->assertIdempotencyMatch($raced, $request);

                    return ['conversion' => $raced, 'replayed' => true];
                }

                $quoteRow = null;
                if ($request->quoteReference !== null && trim($request->quoteReference) !== '') {
                    $quoteRow = WalletConversionQuote::query()
                        ->where('reference', $request->quoteReference)
                        ->lockForUpdate()
                        ->first();

                    $this->validateQuoteForExecution($quoteRow, $request, $breakdown->ruleVersion);
                }

                $limits = $this->limits->limits($request->currency);
                $violations = array_merge(
                    $limits->transactionViolations($request->amount),
                    $limits->rollingViolations(
                        $request->amount,
                        $this->limits->dailyTotal($request->userId),
                        $this->limits->monthlyTotal($request->userId),
                    ),
                );
                if ($violations !== []) {
                    throw ConversionException::failure(
                        $violations[0]->value,
                        $violations[0]->label().'.',
                        ['direction' => $request->direction->value, 'amount' => $request->amount],
                    );
                }

                $fromWallet = $this->findOrCreateDestinationOrSourceWallet(
                    userId: $request->userId,
                    type: $request->direction->fromType(),
                    currency: $request->currency,
                    mustExist: true,
                );
                $toWallet = $this->findOrCreateDestinationOrSourceWallet(
                    userId: $request->userId,
                    type: $request->direction->toType(),
                    currency: $request->currency,
                    mustExist: false,
                );

                [$lockedFrom, $lockedTo] = $this->lockPairInOrder($fromWallet, $toWallet);

                $amountIn = bcadd($request->amount, '0', 2);
                $feeAmount = $breakdown->feeAmount;
                $amountOut = $breakdown->netAmount;

                $reference = $this->generateReference();

                $conversion = $this->repository->create([
                    'reference' => $reference,
                    'user_id' => $request->userId,
                    'direction' => $request->direction->value,
                    'status' => WalletConversionStatus::Pending->value,
                    'currency' => $request->currency->value,
                    'amount_in' => $amountIn,
                    'fee_amount' => $feeAmount,
                    'amount_out' => $amountOut,
                    'fee_rate' => $breakdown->rate,
                    'fee_rule_key' => $breakdown->ruleKey,
                    'fee_rule_version' => $breakdown->ruleVersion,
                    'from_wallet_id' => (int) $lockedFrom->getKey(),
                    'to_wallet_id' => (int) $lockedTo->getKey(),
                    'from_wallet_type' => $request->direction->fromType()->value,
                    'to_wallet_type' => $request->direction->toType()->value,
                    'from_balance_after' => (string) $lockedFrom->balance,
                    'to_balance_after' => (string) $lockedTo->balance,
                    'channel' => $request->channel->value,
                    'quote_reference' => $request->quoteReference,
                    'idempotency_key' => $request->idempotencyKey,
                ]);

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
                    : Money::of((string) $lockedTo->balance, $request->currency);

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
                ]);

                if ($quoteRow instanceof WalletConversionQuote) {
                    $quoteRow->fill([
                        'status' => WalletConversionStatus::Completed->value,
                        'converted_id' => (int) $completed->getKey(),
                        'consumed_at' => Carbon::now(),
                    ])->save();
                }

                $this->recordAudit(
                    conversionId: (int) $completed->getKey(),
                    reference: (string) $completed->reference,
                    event: 'conversion_completed',
                    errorCode: null,
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

            if (! $replayed) {
                event(ConversionCompleted::fromConversion($conversion));
            }

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
        if ($trimmedReason === '') {
            throw ConversionException::failure('reversal_reason_required', 'A reason is required to reverse a conversion.');
        }

        if ($idempotencyKey !== null && trim($idempotencyKey) !== '') {
            $existingReversal = WalletConversion::query()
                ->where('reversal_of_id', $conversionId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingReversal instanceof WalletConversion) {
                return $this->resultFromModel($existingReversal, replayed: true);
            }
        }

        $pair = DB::transaction(function () use ($conversionId, $context, $trimmedReason, $idempotencyKey): array {
            $original = $this->repository->findForUpdate($conversionId);
            if (! $original instanceof WalletConversion) {
                throw ConversionException::failure('conversion_not_found', 'Conversion could not be found.');
            }

            if ($original->status === WalletConversionStatus::Reversed && $original->reversed_by_id !== null) {
                $priorReversal = WalletConversion::query()->find((int) $original->reversed_by_id);
                if ($priorReversal instanceof WalletConversion && $idempotencyKey !== null && $priorReversal->idempotency_key === $idempotencyKey) {
                    return ['original' => $original, 'reversal' => $priorReversal, 'replayed' => true];
                }

                throw ConversionException::failure('conversion_already_reversed', 'That conversion has already been reversed.');
            }

            if ($original->status !== WalletConversionStatus::Completed) {
                throw ConversionException::failure('conversion_not_reversible', 'Only completed conversions can be reversed.');
            }

            $originalDirection = $original->direction instanceof WalletConversionDirection
                ? $original->direction
                : WalletConversionDirection::from((string) $original->direction);
            $reverseDirection = $originalDirection === WalletConversionDirection::CashToWin
                ? WalletConversionDirection::WinToCash
                : WalletConversionDirection::CashToWin;

            $currency = Currency::tryFrom((string) $original->currency) ?? Currency::primary();

            $fromWallet = Wallet::query()->findOrFail((int) $original->to_wallet_id);
            $toWallet = Wallet::query()->findOrFail((int) $original->from_wallet_id);
            [$lockedFrom, $lockedTo] = $this->lockPairInOrder($fromWallet, $toWallet);

            $reversalAmountIn = bcadd((string) $original->amount_out, '0', 2);
            $refundedFee = bcadd((string) $original->fee_amount, '0', 2);
            $reversalAmountOut = bcadd((string) $original->amount_in, '0', 2);

            $reference = $this->generateReference();

            $reversal = $this->repository->create([
                'reference' => $reference,
                'user_id' => (int) $original->user_id,
                'direction' => $reverseDirection->value,
                'status' => WalletConversionStatus::Pending->value,
                'currency' => $currency->value,
                'amount_in' => $reversalAmountIn,
                'fee_amount' => '0.00',
                'amount_out' => $reversalAmountOut,
                'fee_rate' => '0.0000',
                'fee_rule_key' => (string) $original->fee_rule_key,
                'fee_rule_version' => (string) $original->fee_rule_version,
                'from_wallet_id' => (int) $lockedFrom->getKey(),
                'to_wallet_id' => (int) $lockedTo->getKey(),
                'from_wallet_type' => $reverseDirection->fromType()->value,
                'to_wallet_type' => $reverseDirection->toType()->value,
                'from_balance_after' => (string) $lockedFrom->balance,
                'to_balance_after' => (string) $lockedTo->balance,
                'channel' => $context->channel->value,
                'idempotency_key' => $idempotencyKey ?: ('rev-'.$reference),
                'reversal_of_id' => (int) $original->getKey(),
                'note' => $trimmedReason,
            ]);

            try {
                $newFromBalance = bccomp($reversalAmountIn, '0.00', 2) === 1
                    ? $this->wallets->applyDebit(
                        $lockedFrom,
                        Money::of($reversalAmountIn, $currency),
                        FinancialTransactionType::Transfer,
                    )
                    : Money::of((string) $lockedFrom->balance, $currency);
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
                feeRefundAmount: $refundedFee,
            );

            $completedReversal = $this->repository->markCompleted($reversal, [
                'from_balance_after' => $newFromBalance->toString(),
                'to_balance_after' => $newToBalance->toString(),
                'financial_transaction_id' => (int) $finTx->getKey(),
            ]);

            $updatedOriginal = $this->repository->transition($original, WalletConversionStatus::Reversed, [
                'reversed_by_id' => (int) $completedReversal->getKey(),
                'reversal_reason' => $trimmedReason,
                'reversed_at' => Carbon::now(),
            ]);

            $this->recordAudit(
                conversionId: (int) $completedReversal->getKey(),
                reference: (string) $completedReversal->reference,
                event: 'conversion_reversed',
                errorCode: null,
                channel: $context->channel,
                context: $context,
                payload: [
                    'original_reference' => (string) $updatedOriginal->reference,
                    'reason' => $trimmedReason,
                    'fee_refunded' => $refundedFee,
                ],
            );

            return ['original' => $updatedOriginal, 'reversal' => $completedReversal, 'replayed' => false];
        });

        /** @var WalletConversion $updatedOriginal */
        $updatedOriginal = $pair['original'];
        /** @var WalletConversion $completedReversal */
        $completedReversal = $pair['reversal'];
        $replayed = (bool) $pair['replayed'];

        if (! $replayed) {
            event(ConversionReversed::fromConversions($updatedOriginal, $completedReversal, $trimmedReason));
        }

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
    public function summaryForUser(int $userId): array
    {
        return $this->historyService->summaryForUser($userId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function openQuotesForUser(int $userId): array
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
        $playerAccount = $this->ensureChartAccount(
            LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_PLAYER_LIABILITY, $currency),
            'Player Wallet Liability',
            LedgerAccountType::Liability,
            $currency,
        );
        $feeAccount = $this->ensureChartAccount(
            LedgerPostingService::accountCodeFor(WalletService::ACCOUNT_FEE_REVENUE, $currency),
            'Conversion Fee Revenue',
            LedgerAccountType::Revenue,
            $currency,
        );

        $grossTotal = $isReversal ? $amountOut : $amountIn;

        $finTx = FinancialTransaction::query()->create([
            'reference_number' => 'FTX-'.strtoupper(bin2hex(random_bytes(8))),
            'user_id' => (int) $conversion->user_id,
            'wallet_id' => $fromWalletId,
            'type' => 'transfer',
            'amount' => $grossTotal,
            'fee' => $feeAmount,
            'currency' => $currency,
            'idempotency_key' => 'conv-ftx-'.(string) $conversion->reference,
            'description' => ($isReversal ? 'Wallet conversion reversal ' : 'Wallet conversion ').(string) $conversion->reference,
            'metadata' => [
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

        if ($isReversal && bccomp($feeRefundAmount, '0.00', 2) === 1) {
            $entries[] = [
                'ledger_account_id' => (int) $feeAccount->getKey(),
                'wallet_id' => null,
                'type' => LedgerEntryType::Debit,
                'amount' => $feeRefundAmount,
                'description' => 'Fee refund on reversal '.(string) $conversion->reference,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
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

        if (! $isReversal && bccomp($feeAmount, '0.00', 2) === 1) {
            $entries[] = [
                'ledger_account_id' => (int) $feeAccount->getKey(),
                'wallet_id' => null,
                'type' => LedgerEntryType::Credit,
                'amount' => $feeAmount,
                'description' => 'Conversion fee on '.(string) $conversion->reference,
                'reference_type' => 'wallet_conversion',
                'reference_id' => (int) $conversion->getKey(),
            ];
        }

        $this->ledger->post($finTx, $entries, $balancesAfter);

        $finTx->forceFill([
            'status' => TransactionStatus::Completed,
            'processed_at' => Carbon::now(),
        ])->save();

        return $finTx;
    }

    private function ensureChartAccount(
        string $code,
        string $name,
        LedgerAccountType $type,
        Currency $currency,
    ): LedgerAccount {
        $existing = LedgerAccount::query()->where('code', $code)->first();
        if ($existing instanceof LedgerAccount) {
            return $existing;
        }

        $account = LedgerAccount::query()->create([
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'currency' => $currency,
            'is_active' => true,
        ]);

        return $account;
    }

    private function findOrCreateDestinationOrSourceWallet(
        int $userId,
        WalletType $type,
        Currency $currency,
        bool $mustExist,
    ): Wallet {
        $wallet = Wallet::query()
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->where('currency', $currency->value)
            ->first();

        if ($wallet instanceof Wallet) {
            return $wallet;
        }

        if ($mustExist) {
            throw ConversionException::failure(
                WalletConversionRejectionReason::WalletLocked->value,
                WalletConversionRejectionReason::WalletLocked->label().'.',
            );
        }

        $created = Wallet::query()->create([
            'user_id' => $userId,
            'type' => $type,
            'currency' => $currency,
        ]);
        $created->forceFill(['status' => WalletStatus::Active])->save();

        return $created;
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

    private function validateQuoteForExecution(
        ?WalletConversionQuote $quoteRow,
        ConversionRequestData $request,
        string $currentRuleVersion,
    ): void {
        if (! $quoteRow instanceof WalletConversionQuote || (int) $quoteRow->user_id !== $request->userId) {
            throw ConversionQuoteExpiredException::forReference(
                (string) $request->quoteReference,
                Carbon::now()->subSecond(),
            );
        }

        if ((string) $quoteRow->status !== WalletConversionStatus::Quoted->value || $quoteRow->isExpired()) {
            throw ConversionQuoteExpiredException::forReference(
                (string) $quoteRow->reference,
                $quoteRow->expires_at ?? Carbon::now()->subSecond(),
            );
        }

        $quotedDirection = $quoteRow->direction instanceof WalletConversionDirection
            ? $quoteRow->direction
            : WalletConversionDirection::tryFrom((string) $quoteRow->direction);

        if ($quotedDirection !== $request->direction || bccomp((string) $quoteRow->amount_in, $request->amount, 2) !== 0) {
            throw ConversionException::failure(
                'quote_mismatch',
                'The conversion parameters do not match the quoted offer.',
            );
        }

        if ((string) $quoteRow->fee_rule_version !== $currentRuleVersion) {
            throw ConversionQuoteExpiredException::forSupersededRule(
                (string) $quoteRow->reference,
                $quoteRow->expires_at ?? Carbon::now(),
                (string) $quoteRow->fee_rule_version,
                $currentRuleVersion,
            );
        }
    }

    private function assertIdempotencyMatch(WalletConversion $existing, ConversionRequestData $request): void
    {
        $existingDir = $existing->direction instanceof WalletConversionDirection
            ? $existing->direction
            : WalletConversionDirection::tryFrom((string) $existing->direction);

        if ($existingDir !== $request->direction || bccomp((string) $existing->amount_in, $request->amount, 2) !== 0) {
            throw new ConversionIdempotencyConflictException(
                (string) $request->idempotencyKey,
                (string) $existing->reference,
            );
        }
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
            note: $conversion->note !== null ? (string) $conversion->note : null,
        );
    }

    private function handleRefusal(ConversionException $exception, ConversionRequestData $request): void
    {
        $this->recordAudit(
            conversionId: null,
            reference: $request->quoteReference ?? ('refused-'.bin2hex(random_bytes(4))),
            event: 'conversion_rejected',
            errorCode: $exception->errorCode(),
            channel: $request->channel,
            context: $request->auditContext,
            payload: $exception->context() + [
                'user_id' => $request->userId,
                'direction' => $request->direction->value,
                'amount' => $request->amount,
            ],
        );

        event(ConversionRejected::fromException(
            $exception,
            $request->userId,
            $request->direction,
            bcadd($request->amount, '0', 2),
            $request->currency,
            $request->channel,
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordAudit(
        ?int $conversionId,
        string $reference,
        string $event,
        ?string $errorCode,
        WalletConversionChannel $channel,
        ?ConversionAuditContextData $context,
        array $payload,
    ): void {
        WalletConversionAudit::query()->create([
            'wallet_conversion_id' => $conversionId,
            'reference' => $reference,
            'event' => $event,
            'error_code' => $errorCode,
            'channel' => $channel->value,
            'actor_user_id' => $context?->actorUserId,
            'ip_address' => $context?->ipAddress,
            'user_agent' => $context?->userAgent,
            'request_id' => $context?->requestId,
            'context' => $payload + ($context?->metadata ?? []),
            'recorded_at' => Carbon::now(),
        ]);
    }

    private function generateReference(): string
    {
        $prefix = (string) config('conversions.reference_prefix', 'WCV');

        return strtoupper($prefix).'-'.strtoupper(bin2hex(random_bytes(8)));
    }
}
