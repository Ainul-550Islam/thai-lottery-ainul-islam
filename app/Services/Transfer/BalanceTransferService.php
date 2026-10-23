<?php

declare(strict_types=1);

namespace App\Services\Transfer;

use App\DTOs\Transfer\TransferOutcome;
use App\DTOs\Transfer\TransferQuote;
use App\Enums\AuditAction;
use App\Enums\Currency;
use App\Enums\FinancialTransactionType;
use App\Enums\SelfExclusionStatus;
use App\Enums\TransferDirection;
use App\Enums\TransferStatus;
use App\Enums\UserStatus;
use App\Enums\WalletType;
use App\Exceptions\BalanceTransferException;
use App\Models\AuditLog;
use App\Models\BalanceTransfer;
use App\Models\SelfExclusion;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Finance\Money;
use App\Services\Finance\TransferFeeCalculator;
use App\Services\Finance\WalletLockService;
use App\Services\Finance\WalletService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Canonical player pocket-to-pocket (Cash <-> Win) balance transfer service.
 */
final class BalanceTransferService
{
    private const AMOUNT_PATTERN = '/^\d+(?:\.\d{1,2})?$/';

    public function __construct(
        private readonly WalletService $wallets,
        private readonly WalletLockService $locks,
        private readonly TransferFeeCalculator $feeCalculator,
        private readonly TransferLimitService $limits,
    ) {}

    /**
     * Ensure both Cash (primary) and Win pockets exist for the player in the target currency.
     *
     * @return array{cash: Wallet, win: Wallet}
     */
    public function ensureTransferPockets(User $user, ?Currency $currency = null): array
    {
        $resolvedCurrency = $currency ?? $this->resolveCurrency();

        $cash = Wallet::query()->firstOrCreate([
            'user_id' => (int) $user->id,
            'currency' => $resolvedCurrency,
            'type' => WalletType::Primary,
        ]);

        $win = Wallet::query()->firstOrCreate([
            'user_id' => (int) $user->id,
            'currency' => $resolvedCurrency,
            'type' => WalletType::Win,
        ]);

        return [
            'cash' => $cash,
            'win' => $win,
        ];
    }

    /**
     * Build a read-only quote for a prospective transfer without writing to the database.
     *
     * @throws BalanceTransferException
     */
    public function quote(
        User $user,
        TransferDirection $direction,
        string $amount,
        ?Currency $currency = null,
    ): TransferQuote {
        $this->assertLaneEnabled($direction);
        $this->assertUserEligible($user);

        $resolvedCurrency = $currency ?? $this->resolveCurrency();
        $scaledAmount = $this->normaliseAmountFormatOnly($amount);
        $fee = $this->feeCalculator->calculate($direction, $scaledAmount, $resolvedCurrency);
        [$minimum, $maximum] = $this->boundsForDirection($direction);

        $blockers = [];
        if (bccomp($scaledAmount, $minimum, 2) === -1) {
            $blockers[] = 'transfer_below_minimum';
        }
        if (bccomp($scaledAmount, $maximum, 2) === 1) {
            $blockers[] = 'transfer_above_maximum';
        }

        foreach ($this->limits->quoteBlockers((int) $user->id, $direction, $scaledAmount) as $limitBlocker) {
            $blockers[] = $limitBlocker;
        }

        $sourceWallet = Wallet::query()
            ->where('user_id', (int) $user->id)
            ->where('currency', $resolvedCurrency)
            ->where('type', $direction->sourceWalletType())
            ->first();

        $destinationWallet = Wallet::query()
            ->where('user_id', (int) $user->id)
            ->where('currency', $resolvedCurrency)
            ->where('type', $direction->destinationWalletType())
            ->first();

        if ($sourceWallet instanceof Wallet && ! $sourceWallet->canTransact()) {
            throw BalanceTransferException::walletNotTransactable((int) $sourceWallet->id, 'source');
        }

        if ($destinationWallet instanceof Wallet && ! $destinationWallet->canTransact()) {
            throw BalanceTransferException::walletNotTransactable((int) $destinationWallet->id, 'destination');
        }

        $sourceAvailable = $sourceWallet instanceof Wallet
            ? $sourceWallet->getAvailableBalance()
            : '0.00';

        $destinationBalance = $destinationWallet instanceof Wallet
            ? bcadd((string) $destinationWallet->balance, '0', 2)
            : '0.00';

        return TransferQuote::make(
            direction: $direction,
            currency: $resolvedCurrency,
            amount: $scaledAmount,
            fee: $fee->fee,
            sourceAvailable: $sourceAvailable,
            destinationBalance: $destinationBalance,
            feeCategory: $fee->category,
            feeRate: $fee->rate,
            minimum: $minimum,
            maximum: $maximum,
            blockers: $blockers,
        );
    }

    /**
     * Execute an atomic, idempotent balance transfer between a player's Cash and Win pockets.
     *
     * @param  array{ip?: string|null, user_agent?: string|null}  $requestContext
     *
     * @throws BalanceTransferException
     */
    public function transfer(
        User $user,
        TransferDirection $direction,
        string $amount,
        ?string $idempotencyKey = null,
        array $requestContext = [],
        ?Currency $currency = null,
    ): TransferOutcome {
        $this->assertLaneEnabled($direction);
        $this->assertUserEligible($user);

        $resolvedCurrency = $currency ?? $this->resolveCurrency();
        $scaledAmount = $this->normaliseAndValidateAmount($direction, $amount, $resolvedCurrency);
        $fee = $this->feeCalculator->calculate($direction, $scaledAmount, $resolvedCurrency);
        $cleanKey = $this->normaliseIdempotencyKey($idempotencyKey);
        $context = $requestContext;

        $pockets = $this->ensureTransferPockets($user, $resolvedCurrency);
        $sourceWallet = $direction === TransferDirection::CashToWin ? $pockets['cash'] : $pockets['win'];
        $destinationWallet = $direction === TransferDirection::CashToWin ? $pockets['win'] : $pockets['cash'];

        try {
            return $this->locks->withinTransaction(function () use (
                $user,
                $direction,
                $scaledAmount,
                $fee,
                $cleanKey,
                $context,
                $resolvedCurrency,
                $sourceWallet,
                $destinationWallet,
            ): TransferOutcome {
                $lockedWallets = $this->locks->lockMany([
                    (int) $sourceWallet->id,
                    (int) $destinationWallet->id,
                ]);

                $lockedSource = $lockedWallets[(int) $sourceWallet->id];
                $lockedDest = $lockedWallets[(int) $destinationWallet->id];

                $freshUser = User::query()->lockForUpdate()->findOrFail((int) $user->id);
                $this->assertUserEligible($freshUser);

                if ($cleanKey !== null) {
                    $existing = BalanceTransfer::query()
                        ->where('user_id', (int) $freshUser->id)
                        ->where('idempotency_key', $cleanKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing instanceof BalanceTransfer) {
                        return $this->resolveIdempotentReplay(
                            $existing,
                            $direction,
                            $scaledAmount,
                            $resolvedCurrency,
                            $cleanKey,
                            $lockedSource,
                            $lockedDest,
                        );
                    }
                }

                if (! $lockedSource->canTransact()) {
                    throw BalanceTransferException::walletNotTransactable((int) $lockedSource->id, 'source');
                }

                if (! $lockedDest->canTransact()) {
                    throw BalanceTransferException::walletNotTransactable((int) $lockedDest->id, 'destination');
                }

                $this->limits->assertWithinLimits((int) $freshUser->id, $direction, $scaledAmount, $resolvedCurrency);

                $sourceAvailable = $lockedSource->getAvailableBalance();
                $totalDebited = $fee->totalDebited();
                if (bccomp($sourceAvailable, $totalDebited, 2) === -1) {
                    throw BalanceTransferException::insufficientFunds(
                        $totalDebited,
                        $sourceAvailable,
                        $resolvedCurrency->value,
                    );
                }

                $sourceBefore = bcadd((string) $lockedSource->balance, '0', 2);
                $destBefore = bcadd((string) $lockedDest->balance, '0', 2);

                $transfer = new BalanceTransfer([
                    'user_id' => (int) $freshUser->id,
                    'direction' => $direction,
                    'currency' => $resolvedCurrency,
                    'fee_category' => $fee->category,
                    'idempotency_key' => $cleanKey,
                    'request_ip' => isset($context['ip']) && is_string($context['ip'])
                        ? substr($context['ip'], 0, 45)
                        : null,
                    'user_agent' => isset($context['user_agent']) && is_string($context['user_agent'])
                        ? substr($context['user_agent'], 0, 255)
                        : null,
                    'metadata' => [
                        'from_pocket' => $direction->pocketNames()['from'],
                        'to_pocket' => $direction->pocketNames()['to'],
                    ],
                ]);

                $transfer->uuid = (string) Str::uuid();
                $transfer->reference_number = BalanceTransfer::generateReference();
                $transfer->source_wallet_id = (int) $lockedSource->id;
                $transfer->destination_wallet_id = (int) $lockedDest->id;
                $transfer->amount = $scaledAmount;
                $transfer->fee_amount = $fee->fee;
                $transfer->total_debited = $totalDebited;
                $transfer->fee_rate = $fee->rate;
                $transfer->source_balance_before = $sourceBefore;
                $transfer->destination_balance_before = $destBefore;
                $transfer->status = TransferStatus::Pending;
                $transfer->save();

                $debitTx = $this->wallets->debit(
                    wallet: $lockedSource,
                    amount: Money::of($scaledAmount, $resolvedCurrency),
                    type: FinancialTransactionType::Transfer,
                    idempotencyKey: 'transfer-debit:'.$transfer->uuid,
                    options: [
                        'description' => sprintf('Balance transfer (%s) debit %s', $direction->label(), $transfer->reference_number),
                        'metadata' => [
                            'transfer_id' => (int) $transfer->id,
                            'transfer_reference' => (string) $transfer->reference_number,
                            'direction' => $direction->value,
                            'leg' => 'debit',
                        ],
                    ],
                );

                $creditTx = $this->wallets->credit(
                    wallet: $lockedDest,
                    amount: Money::of($scaledAmount, $resolvedCurrency),
                    type: FinancialTransactionType::Transfer,
                    idempotencyKey: 'transfer-credit:'.$transfer->uuid,
                    options: [
                        'description' => sprintf('Balance transfer (%s) credit %s', $direction->label(), $transfer->reference_number),
                        'metadata' => [
                            'transfer_id' => (int) $transfer->id,
                            'transfer_reference' => (string) $transfer->reference_number,
                            'direction' => $direction->value,
                            'leg' => 'credit',
                        ],
                    ],
                );

                $feeTx = null;
                if ($fee->isCharged()) {
                    $feeTx = $this->wallets->debit(
                        wallet: $lockedSource->fresh(),
                        amount: Money::of($fee->fee, $resolvedCurrency),
                        type: FinancialTransactionType::Fee,
                        idempotencyKey: 'transfer-fee:'.$transfer->uuid,
                        options: [
                            'description' => sprintf('Balance transfer (%s) fee %s', $direction->label(), $transfer->reference_number),
                            'metadata' => [
                                'transfer_id' => (int) $transfer->id,
                                'transfer_reference' => (string) $transfer->reference_number,
                                'direction' => $direction->value,
                                'fee_category' => $fee->category,
                                'fee_rate' => $fee->rate,
                                'leg' => 'fee',
                            ],
                        ],
                    );
                }

                $sourceAfter = bcadd((string) $lockedSource->fresh()->balance, '0', 2);
                $destAfter = bcadd((string) $lockedDest->fresh()->balance, '0', 2);

                $transfer->source_balance_after = $sourceAfter;
                $transfer->destination_balance_after = $destAfter;
                $transfer->debit_transaction_id = (int) $debitTx->id;
                $transfer->credit_transaction_id = (int) $creditTx->id;
                $transfer->fee_transaction_id = $feeTx !== null ? (int) $feeTx->id : null;
                $transfer->status = TransferStatus::Completed;
                $transfer->completed_at = Carbon::now();
                $transfer->save();

                AuditLog::create([
                    'user_id' => (int) $freshUser->id,
                    'action' => AuditAction::Create,
                    'auditable_type' => BalanceTransfer::class,
                    'auditable_id' => (int) $transfer->id,
                    'old_values' => [
                        'source_balance' => $sourceBefore,
                        'destination_balance' => $destBefore,
                    ],
                    'new_values' => [
                        'reference' => (string) $transfer->reference_number,
                        'direction' => $direction->value,
                        'amount' => $scaledAmount,
                        'fee' => $fee->fee,
                        'total_debited' => $totalDebited,
                        'source_balance' => $sourceAfter,
                        'destination_balance' => $destAfter,
                    ],
                ]);

                return TransferOutcome::executed($transfer->fresh());
            });
        } catch (UniqueConstraintViolationException|QueryException $exception) {
            if ($cleanKey !== null) {
                $existing = BalanceTransfer::query()
                    ->where('user_id', (int) $user->id)
                    ->where('idempotency_key', $cleanKey)
                    ->first();

                if ($existing instanceof BalanceTransfer) {
                    return $this->resolveIdempotentReplay(
                        $existing,
                        $direction,
                        $scaledAmount,
                        $resolvedCurrency,
                        $cleanKey,
                        $sourceWallet->fresh() ?? $sourceWallet,
                        $destinationWallet->fresh() ?? $destinationWallet,
                    );
                }
            }

            throw $exception;
        }
    }

    /**
     * Reverse a settled transfer by posting a compensating transfer in the opposite direction.
     *
     * @throws BalanceTransferException
     */
    public function reverse(
        BalanceTransfer $original,
        User $operator,
        string $reason,
    ): TransferOutcome {
        $trimmedReason = trim($reason);
        $minReasonLength = max(1, (int) config('transfer.reversal.reason_min_length', 10));

        if (mb_strlen($trimmedReason) < $minReasonLength) {
            throw BalanceTransferException::notReversible(
                'transfer_reversal_reason_required',
                sprintf('A reversal reason of at least %d characters is required.', $minReasonLength),
                422,
            );
        }

        return $this->locks->withinTransaction(function () use ($original, $operator, $trimmedReason): TransferOutcome {
            $lockedOriginal = BalanceTransfer::query()
                ->lockForUpdate()
                ->findOrFail((int) $original->id);

            $originalMeta = is_array($lockedOriginal->metadata) ? $lockedOriginal->metadata : [];

            if ((bool) ($originalMeta['is_reversal'] ?? false)) {
                throw BalanceTransferException::notReversible(
                    'transfer_cannot_reverse_a_reversal',
                    'A reversal transfer cannot itself be reversed.',
                    409,
                );
            }

            if (isset($originalMeta['reversed_by_transfer_id']) || isset($originalMeta['reversed_by_reference'])) {
                throw BalanceTransferException::notReversible(
                    'transfer_already_reversed',
                    'This balance transfer has already been reversed.',
                    409,
                );
            }

            if ($lockedOriginal->status !== TransferStatus::Completed) {
                throw BalanceTransferException::notReversible(
                    'transfer_not_settled',
                    'Only completed balance transfers can be reversed.',
                    409,
                );
            }

            $maxAgeHours = (int) config('transfer.reversal.max_age_hours', 72);
            if ($maxAgeHours > 0
                && $lockedOriginal->completed_at !== null
                && $lockedOriginal->completed_at->lt(Carbon::now()->subHours($maxAgeHours))) {
                throw BalanceTransferException::notReversible(
                    'transfer_reversal_window_expired',
                    sprintf('Transfers older than %d hours cannot be reversed from the operator desk.', $maxAgeHours),
                    409,
                );
            }

            $compDirection = $lockedOriginal->direction->opposite();
            $currency = $lockedOriginal->currency instanceof Currency
                ? $lockedOriginal->currency
                : Currency::from((string) $lockedOriginal->currency);
            $principalAmount = bcadd((string) $lockedOriginal->amount, '0', 2);

            $compSourceId = (int) $lockedOriginal->destination_wallet_id;
            $compDestId = (int) $lockedOriginal->source_wallet_id;

            $lockedWallets = $this->locks->lockMany([$compSourceId, $compDestId]);
            $compSource = $lockedWallets[$compSourceId];
            $compDest = $lockedWallets[$compDestId];

            if (! $compSource->canTransact()) {
                throw BalanceTransferException::walletNotTransactable((int) $compSource->id, 'source');
            }

            if (! $compDest->canTransact()) {
                throw BalanceTransferException::walletNotTransactable((int) $compDest->id, 'destination');
            }

            $compSourceAvailable = $compSource->getAvailableBalance();
            if (bccomp($compSourceAvailable, $principalAmount, 2) === -1) {
                throw BalanceTransferException::insufficientFunds(
                    $principalAmount,
                    $compSourceAvailable,
                    $currency->value,
                );
            }

            $sourceBefore = bcadd((string) $compSource->balance, '0', 2);
            $destBefore = bcadd((string) $compDest->balance, '0', 2);

            $compensating = new BalanceTransfer([
                'user_id' => (int) $lockedOriginal->user_id,
                'direction' => $compDirection,
                'currency' => $currency,
                'fee_category' => null,
                'idempotency_key' => 'reversal-of:'.$lockedOriginal->uuid,
                'metadata' => [
                    'is_reversal' => true,
                    'reverses_transfer_id' => (int) $lockedOriginal->id,
                    'reverses_reference' => (string) $lockedOriginal->reference_number,
                    'reversal_operator_id' => (int) $operator->id,
                    'reversal_reason' => $trimmedReason,
                ],
            ]);

            $compensating->uuid = (string) Str::uuid();
            $compensating->reference_number = BalanceTransfer::generateReference();
            $compensating->source_wallet_id = (int) $compSource->id;
            $compensating->destination_wallet_id = (int) $compDest->id;
            $compensating->amount = $principalAmount;
            $compensating->fee_amount = '0.00';
            $compensating->total_debited = $principalAmount;
            $compensating->fee_rate = '0.000000';
            $compensating->source_balance_before = $sourceBefore;
            $compensating->destination_balance_before = $destBefore;
            $compensating->status = TransferStatus::Pending;
            $compensating->save();

            $debitTx = $this->wallets->debit(
                wallet: $compSource,
                amount: Money::of($principalAmount, $currency),
                type: FinancialTransactionType::Transfer,
                idempotencyKey: 'reversal-debit:'.$compensating->uuid,
                options: [
                    'description' => sprintf(
                        'Reversal of transfer %s (%s) debit %s',
                        $lockedOriginal->reference_number,
                        $compDirection->label(),
                        $compensating->reference_number,
                    ),
                    'metadata' => [
                        'transfer_id' => (int) $compensating->id,
                        'transfer_reference' => (string) $compensating->reference_number,
                        'reverses_transfer_id' => (int) $lockedOriginal->id,
                        'reverses_reference' => (string) $lockedOriginal->reference_number,
                        'direction' => $compDirection->value,
                        'leg' => 'reversal_debit',
                    ],
                ],
            );

            $creditTx = $this->wallets->credit(
                wallet: $compDest,
                amount: Money::of($principalAmount, $currency),
                type: FinancialTransactionType::Transfer,
                idempotencyKey: 'reversal-credit:'.$compensating->uuid,
                options: [
                    'description' => sprintf(
                        'Reversal of transfer %s (%s) credit %s',
                        $lockedOriginal->reference_number,
                        $compDirection->label(),
                        $compensating->reference_number,
                    ),
                    'metadata' => [
                        'transfer_id' => (int) $compensating->id,
                        'transfer_reference' => (string) $compensating->reference_number,
                        'reverses_transfer_id' => (int) $lockedOriginal->id,
                        'reverses_reference' => (string) $lockedOriginal->reference_number,
                        'direction' => $compDirection->value,
                        'leg' => 'reversal_credit',
                    ],
                ],
            );

            $sourceAfter = bcadd((string) $compSource->fresh()->balance, '0', 2);
            $destAfter = bcadd((string) $compDest->fresh()->balance, '0', 2);

            $compensating->source_balance_after = $sourceAfter;
            $compensating->destination_balance_after = $destAfter;
            $compensating->debit_transaction_id = (int) $debitTx->id;
            $compensating->credit_transaction_id = (int) $creditTx->id;
            $compensating->fee_transaction_id = null;
            $compensating->status = TransferStatus::Completed;
            $compensating->completed_at = Carbon::now();
            $compensating->save();

            $originalMeta['reversed_by_transfer_id'] = (int) $compensating->id;
            $originalMeta['reversed_by_reference'] = (string) $compensating->reference_number;
            $originalMeta['reversed_by_operator_id'] = (int) $operator->id;
            $originalMeta['reversal_reason'] = $trimmedReason;
            $originalMeta['reversed_at'] = Carbon::now()->toIso8601String();
            $lockedOriginal->metadata = $originalMeta;
            $lockedOriginal->save();

            AuditLog::create([
                'user_id' => (int) $operator->id,
                'action' => AuditAction::Update,
                'auditable_type' => BalanceTransfer::class,
                'auditable_id' => (int) $lockedOriginal->id,
                'old_values' => [
                    'reversed_by_transfer_id' => null,
                ],
                'new_values' => [
                    'original_reference' => (string) $lockedOriginal->reference_number,
                    'compensating_transfer_id' => (int) $compensating->id,
                    'compensating_reference' => (string) $compensating->reference_number,
                    'amount' => $principalAmount,
                    'operator_id' => (int) $operator->id,
                    'reason' => $trimmedReason,
                ],
            ]);

            return TransferOutcome::executed($compensating->fresh());
        });
    }

    /**
     * Find a transfer by reference number scoped strictly to the owning user.
     */
    public function forUserAndReference(int $userId, string $reference): ?BalanceTransfer
    {
        $trimmed = trim($reference);
        if ($trimmed === '') {
            return null;
        }

        return BalanceTransfer::query()
            ->forUser($userId)
            ->where('reference_number', $trimmed)
            ->first();
    }

    /**
     * Return the most recent transfers for a player, newest first.
     *
     * @return Collection<int, BalanceTransfer>
     */
    public function recentForUser(int $userId, int $limit = 10): Collection
    {
        return BalanceTransfer::query()
            ->forUser($userId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->get();
    }

    private function normaliseIdempotencyKey(?string $idempotencyKey): ?string
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return null;
        }

        $trimmed = trim($idempotencyKey);
        $maxLength = max(64, (int) config('transfer.idempotency.max_key_length', 128));

        if (strlen($trimmed) > $maxLength) {
            return 'sha256:'.hash('sha256', $trimmed);
        }

        return $trimmed;
    }

    /**
     * @throws BalanceTransferException
     */
    private function resolveIdempotentReplay(
        BalanceTransfer $existing,
        TransferDirection $direction,
        string $scaledAmount,
        Currency $currency,
        string $idempotencyKey,
        Wallet $currentSource,
        Wallet $currentDest,
    ): TransferOutcome {
        $existingDirection = $existing->direction instanceof TransferDirection
            ? $existing->direction
            : TransferDirection::tryFromInput((string) $existing->getRawOriginal('direction'));
        $existingCurrency = $existing->currency instanceof Currency
            ? $existing->currency
            : Currency::tryFrom((string) $existing->getRawOriginal('currency'));

        if ($existingDirection !== $direction
            || $existingCurrency !== $currency
            || bccomp(bcadd((string) $existing->amount, '0', 2), $scaledAmount, 2) !== 0) {
            throw BalanceTransferException::idempotencyConflict($idempotencyKey);
        }

        return TransferOutcome::replayed($existing);
    }

    /**
     * @throws BalanceTransferException
     */
    private function assertLaneEnabled(TransferDirection $direction): void
    {
        if (! (bool) config('transfer.enabled', true)) {
            throw BalanceTransferException::disabled();
        }

        if (! (bool) config("transfer.directions.{$direction->value}.enabled", true)) {
            throw BalanceTransferException::directionDisabled($direction->value);
        }
    }

    /**
     * @throws BalanceTransferException
     */
    private function assertUserEligible(User $user): void
    {
        $status = $user->status instanceof UserStatus
            ? $user->status
            : UserStatus::tryFrom((string) $user->status);

        if ($status !== null && ! $status->canTransact()) {
            throw BalanceTransferException::accountNotEligible((int) $user->id, 'user_status_'.$status->value);
        }

        if ($user->self_excluded_until !== null && $user->self_excluded_until->isFuture()) {
            throw BalanceTransferException::accountNotEligible((int) $user->id, 'self_excluded');
        }

        $hasActiveExclusion = SelfExclusion::query()
            ->where('user_id', (int) $user->id)
            ->where('status', SelfExclusionStatus::Active->value)
            ->where('ends_at', '>', Carbon::now())
            ->exists();

        if ($hasActiveExclusion) {
            throw BalanceTransferException::accountNotEligible((int) $user->id, 'self_excluded');
        }
    }

    /**
     * @throws BalanceTransferException
     */
    private function normaliseAmountFormatOnly(string $amount): string
    {
        if ($amount === '' || ! preg_match(self::AMOUNT_PATTERN, $amount)) {
            throw BalanceTransferException::malformedAmount($amount);
        }

        $scaled = bcadd($amount, '0', 2);

        if (bccomp($scaled, '0.00', 2) <= 0) {
            throw BalanceTransferException::malformedAmount($amount);
        }

        return $scaled;
    }

    /**
     * @throws BalanceTransferException
     */
    private function normaliseAndValidateAmount(
        TransferDirection $direction,
        string $amount,
        Currency $currency,
    ): string {
        $scaled = $this->normaliseAmountFormatOnly($amount);
        [$minimum, $maximum] = $this->boundsForDirection($direction);

        if (bccomp($scaled, $minimum, 2) === -1) {
            throw BalanceTransferException::belowMinimum($minimum, $currency->value);
        }

        if (bccomp($scaled, $maximum, 2) === 1) {
            throw BalanceTransferException::aboveMaximum($maximum, $currency->value);
        }

        return $scaled;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function boundsForDirection(TransferDirection $direction): array
    {
        $minRaw = (string) config("transfer.directions.{$direction->value}.min_amount", '20.00');
        $maxRaw = (string) config("transfer.directions.{$direction->value}.max_amount", '500000.00');

        $minimum = preg_match(self::AMOUNT_PATTERN, trim($minRaw))
            ? bcadd(trim($minRaw), '0', 2)
            : '20.00';

        $maximum = preg_match(self::AMOUNT_PATTERN, trim($maxRaw))
            ? bcadd(trim($maxRaw), '0', 2)
            : '500000.00';

        return [$minimum, $maximum];
    }

    private function resolveCurrency(): Currency
    {
        $configured = (string) config('transfer.currency', 'THB');

        return Currency::tryFrom(strtoupper(trim($configured))) ?? Currency::primary();
    }
}
