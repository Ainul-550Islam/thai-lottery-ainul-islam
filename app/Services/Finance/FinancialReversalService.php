<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Reverses a completed financial transaction by compensation, never by erasure.
 */
final class FinancialReversalService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly WalletLockService $locks,
        private readonly LedgerPostingService $ledger,
        private readonly WalletService $wallets,
        private readonly LedgerBalanceValidator $validator,
        private readonly FinancialTransactionService $transactions,
    ) {}

    /**
     * Reverse a completed transaction.
     *
     * @param  int|null  $actorUserId  the user recorded in reversed_by
     *
     * @throws FinancialException
     */
    public function reverse(
        FinancialTransaction $original,
        ?int $actorUserId = null,
        ?string $idempotencyKey = null,
        ?string $reason = null,
    ): FinancialTransaction {
        Money::assertExactArithmeticIsAvailable();

        $originalId = $this->originalId($original);
        $canonicalKey = $this->idempotency->deterministicKey('reversal', (string) $originalId);

        $this->locks->assertConsistentTransactionState();

        $routedWalletId = FinancialTransaction::withTrashed()
            ->whereKey($originalId)
            ->value('wallet_id');

        return $this->transactions->executeWithDeadlockRetry(
            fn (): FinancialTransaction => $this->locks->withinTransaction(
                fn (): FinancialTransaction => $this->performReversal(
                    $originalId,
                    $routedWalletId === null ? null : (int) $routedWalletId,
                    $actorUserId,
                    $canonicalKey,
                    $reason,
                    $idempotencyKey,
                ),
            ),
        );
    }

    /**
     * Whether this transaction may be reversed right now.
     */
    public function canReverse(FinancialTransaction $transaction): bool
    {
        if (! $transaction->exists) {
            return false;
        }

        try {
            $this->assertReversible($transaction);

            return true;
        } catch (FinancialException) {
            return false;
        }
    }

    /**
     * The reversal that already compensates this transaction, if one exists.
     */
    public function existingReversalFor(FinancialTransaction $original): ?FinancialTransaction
    {
        $originalId = $this->originalId($original);
        $canonicalKey = $this->idempotency->deterministicKey('reversal', (string) $originalId);

        $this->locks->assertConsistentTransactionState();

        $routedWalletId = FinancialTransaction::withTrashed()
            ->whereKey($originalId)
            ->value('wallet_id');

        return $this->locks->withinTransaction(
            fn (): ?FinancialTransaction => $this->lookupExistingReversal(
                $originalId,
                $routedWalletId === null ? null : (int) $routedWalletId,
                $canonicalKey,
            ),
        );
    }

    /**
     * The deterministic idempotency key for reversing this transaction.
     */
    public function reversalKeyFor(FinancialTransaction $original): string
    {
        $originalId = $this->originalId($original);

        return $this->idempotency->deterministicKey('reversal', (string) $originalId);
    }

    /**
     * @throws FinancialException
     */
    public function assertReversible(FinancialTransaction $original): void
    {
        $originalId = $this->originalId($original);

        $current = FinancialTransaction::withTrashed()->whereKey($originalId)->first();

        if (! $current instanceof FinancialTransaction || $current->trashed()) {
            throw FinancialException::withCode(
                'reversal_accounting_conflict',
                'Reversal requires an existing, non-deleted original transaction.',
                ['transaction_id' => $originalId],
            );
        }

        $engineType = FinancialTransactionType::fromTransactionType($current->type);

        if (! $engineType->isReversible()) {
            throw FinancialException::withCode(
                'reversal_not_permitted_for_type',
                sprintf(
                    'A %s transaction cannot be reversed; reversing a reversal would make '
                    .'the audit trail ambiguous.',
                    $current->type->value,
                ),
                [
                    'transaction_id' => $originalId,
                    'transaction_type' => $current->type->value,
                ],
            );
        }

        if ($current->status === TransactionStatus::Reversed || $current->reversed_at !== null) {
            throw FinancialException::withCode(
                'reversal_already_exists',
                sprintf('Financial transaction %d has already been reversed.', $originalId),
                ['transaction_id' => $originalId],
            );
        }

        if ($current->status !== TransactionStatus::Completed) {
            throw FinancialException::withCode(
                'reversal_requires_completed_transaction',
                sprintf(
                    'Financial transaction %d is %s; only a completed transaction can be reversed.',
                    $originalId,
                    $current->status->value,
                ),
                [
                    'transaction_id' => $originalId,
                    'transaction_status' => $current->status->value,
                ],
            );
        }

        $guard = app(ReversalIntegrityGuard::class);
        $wallet = $current->wallet_id !== null
            ? Wallet::withTrashed()->whereKey((int) $current->wallet_id)->first()
            : null;
        $entries = $guard->entries($current);
        $guard->assertOriginal($current, $wallet, $entries);

        $canonicalKey = $this->idempotency->deterministicKey('reversal', (string) $originalId);
        if ($this->candidateQuery($originalId, $canonicalKey)->exists()) {
            throw FinancialException::withCode(
                'reversal_already_exists',
                sprintf('Financial transaction %d has already been reversed.', $originalId),
                ['transaction_id' => $originalId],
            );
        }
    }

    /**
     * @throws FinancialException
     */
    private function performReversal(
        int $originalId,
        ?int $routedWalletId,
        ?int $actorUserId,
        string $key,
        ?string $reason,
        ?string $callerKey = null,
    ): FinancialTransaction {
        $guard = app(ReversalIntegrityGuard::class);

        $wallet = $routedWalletId !== null
            ? Wallet::withTrashed()->whereKey($routedWalletId)->lockForUpdate()->first()
            : null;

        if ($callerKey !== null && $callerKey !== $key) {
            throw FinancialException::withCode(
                'reversal_noncanonical_key',
                'Reversal idempotency key must match the canonical key for this original transaction.',
                ['transaction_id' => $originalId],
            );
        }

        /** @var FinancialTransaction|null $locked */
        $locked = FinancialTransaction::withTrashed()
            ->whereKey($originalId)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof FinancialTransaction) {
            throw FinancialException::withCode(
                'reversal_transaction_not_found',
                sprintf('Financial transaction %d no longer exists.', $originalId),
                ['transaction_id' => $originalId],
            );
        }

        if ($locked->wallet_id !== null && ($wallet === null || (int) $wallet->id !== (int) $locked->wallet_id)) {
            $guard->refuse();
        }

        $originalEntries = $guard->entries($locked);
        $walletSide = $guard->assertOriginal($locked, $wallet, $originalEntries);

        $candidates = $this->candidateQuery((int) $locked->id, $key)
            ->lockForUpdate()
            ->get();

        if ($candidates->count() > 1) {
            $guard->refuse();
        }

        if ($candidates->count() === 1) {
            /** @var FinancialTransaction $existing */
            $existing = $candidates->first();
            if ($locked->status !== TransactionStatus::Reversed || $locked->reversed_at === null) {
                $guard->refuse();
            }
            $guard->assertCompensation($locked, $existing, $originalEntries, $key);

            return $existing;
        }

        if ($locked->status === TransactionStatus::Reversed || $locked->reversed_at !== null) {
            $guard->refuse();
        }

        if ($wallet instanceof Wallet) {
            $eligible = $walletSide === LedgerEntryType::Credit
                ? $wallet->canDebit()
                : $wallet->canCredit();
            if (! $eligible) {
                throw FinancialException::withCode(
                    'reversal_wallet_not_eligible',
                    sprintf('Wallet %d is not eligible for reversal compensation.', (int) $wallet->id),
                    ['wallet_id' => (int) $wallet->id, 'wallet_status' => $wallet->status->value],
                );
            }
        }

        $sourceHash = $guard->fingerprint($locked, $originalEntries);

        $reversal = $this->createReversalTransaction($locked, $key, $reason, $sourceHash);

        $this->transactions->transitionTo($reversal, TransactionStatus::Processing)->save();

        $balanceAfter = null;

        if ($wallet instanceof Wallet && $walletSide !== null) {
            $balanceAfter = $this->wallets->applyReversal(
                $wallet,
                Money::fromDatabase((string) $locked->amount, $locked->currency),
                $walletSide,
            );
        }

        $mirrored = $this->mirrorEntries($originalEntries, $locked);

        $this->ledger->post(
            $reversal,
            $mirrored,
            $balanceAfter === null ? [] : [0 => $balanceAfter->toString()],
        );

        $this->validator->assertTransactionBalanced($reversal);

        $this->transactions->transitionTo($reversal, TransactionStatus::Completed);
        $reversal->processed_at = Carbon::now();
        $reversal->save();

        $this->transactions->transitionTo($locked, TransactionStatus::Reversed);
        $locked->reversed_at = Carbon::now();
        $locked->reversed_by = $actorUserId;
        $locked->save();

        $freshReversal = $reversal->refresh();
        $guard->assertCompensation($locked, $freshReversal, $originalEntries, $key);

        return $freshReversal;
    }

    private function lookupExistingReversal(
        int $originalId,
        ?int $routedWalletId,
        string $key,
    ): ?FinancialTransaction {
        $guard = app(ReversalIntegrityGuard::class);

        $wallet = $routedWalletId !== null
            ? Wallet::withTrashed()->whereKey($routedWalletId)->lockForUpdate()->first()
            : null;

        /** @var FinancialTransaction|null $locked */
        $locked = FinancialTransaction::withTrashed()
            ->whereKey($originalId)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof FinancialTransaction) {
            $guard->refuse();
        }

        if ($locked->wallet_id !== null && ($wallet === null || (int) $wallet->id !== (int) $locked->wallet_id)) {
            $guard->refuse();
        }

        $originalEntries = $guard->entries($locked);
        $guard->assertOriginal($locked, $wallet, $originalEntries);

        $candidates = $this->candidateQuery((int) $locked->id, $key)
            ->lockForUpdate()
            ->get();

        if ($candidates->isEmpty()) {
            if ($locked->status === TransactionStatus::Reversed || $locked->reversed_at !== null) {
                $guard->refuse();
            }

            return null;
        }

        if ($candidates->count() > 1 || $locked->status !== TransactionStatus::Reversed || $locked->reversed_at === null) {
            $guard->refuse();
        }

        /** @var FinancialTransaction $existing */
        $existing = $candidates->first();
        $guard->assertCompensation($locked, $existing, $originalEntries, $key);

        return $existing;
    }

    private function candidateQuery(int $originalId, string $key)
    {
        return FinancialTransaction::withTrashed()
            ->where(function ($query) use ($originalId, $key): void {
                $query->where(function ($ref) use ($originalId): void {
                    $ref->where('reference_type', FinancialTransaction::class)
                        ->where('reference_id', $originalId)
                        ->where('type', FinancialTransactionType::Reversal->toTransactionType());
                })->orWhere('idempotency_key', $key);
            })
            ->orderBy('id');
    }

    private function createReversalTransaction(
        FinancialTransaction $original,
        string $key,
        ?string $reason,
        string $sourceHash,
    ): FinancialTransaction {
        $metadata = [
            'engine_type' => FinancialTransactionType::Reversal->value,
            'reversal_of_transaction_id' => (int) $original->getKey(),
            'reversal_of_reference_number' => $original->reference_number,
            'reversal_of_type' => $original->type->value,
            'reversal_source_hash' => $sourceHash,
        ];

        if ($reason !== null) {
            $metadata['reversal_reason'] = $reason;
        }

        $reversal = new FinancialTransaction;

        $reversal->fill([
            'reference_number' => $this->transactions->generateReferenceNumber(),
            'user_id' => $original->user_id,
            'wallet_id' => $original->wallet_id,
            'type' => FinancialTransactionType::Reversal->toTransactionType(),
            'currency' => $original->currency,
            'amount' => (string) $original->amount,
            'fee' => Money::zero($original->currency)->toString(),
            'description' => sprintf('Reversal of %s', $original->reference_number),
            'metadata' => $metadata,
            'idempotency_key' => $key,
            'reference_type' => FinancialTransaction::class,
            'reference_id' => (int) $original->getKey(),
        ]);

        $reversal->uuid = (string) Str::uuid();
        $reversal->status = TransactionStatus::Pending;

        $reversal->save();

        return $reversal;
    }

    /**
     * @param  Collection<int, LedgerEntry>  $originalEntries
     * @return array<int, array<string, mixed>>
     */
    public function mirrorEntries(Collection $originalEntries, FinancialTransaction $original): array
    {
        $liabilityCode = LedgerPostingService::accountCodeFor(
            WalletService::ACCOUNT_PLAYER_LIABILITY,
            $original->currency,
        );
        $liabilityAccountId = LedgerAccount::withTrashed()
            ->where('code', $liabilityCode)
            ->value('id');

        $mirrored = [];

        foreach ($originalEntries as $entry) {
            $mirrored[] = [
                'ledger_account_id' => (int) $entry->ledger_account_id,
                'type' => $entry->type->opposite(),
                'amount' => Money::fromDatabase((string) $entry->amount, $original->currency),
                'wallet_id' => $entry->wallet_id === null ? null : (int) $entry->wallet_id,
                'description' => sprintf('Reversal of %s', $original->reference_number),
                'reference_type' => FinancialTransaction::class,
                'reference_id' => (int) $original->getKey(),
                'metadata' => ['reversal_of_ledger_entry_id' => (int) $entry->getKey()],
            ];
        }

        usort($mirrored, static function (array $a, array $b) use ($liabilityAccountId): int {
            $aIsLiability = $liabilityAccountId !== null && $a['ledger_account_id'] === (int) $liabilityAccountId ? 1 : 0;
            $bIsLiability = $liabilityAccountId !== null && $b['ledger_account_id'] === (int) $liabilityAccountId ? 1 : 0;
            if ($aIsLiability !== $bIsLiability) {
                return $bIsLiability <=> $aIsLiability;
            }

            return ($b['wallet_id'] === null ? 0 : 1) <=> ($a['wallet_id'] === null ? 0 : 1);
        });

        return $mirrored;
    }

    /**
     * @throws FinancialException
     */
    private function originalId(FinancialTransaction $original): int
    {
        if (! $original->exists) {
            throw FinancialException::withCode(
                'reversal_transaction_not_persisted',
                'Cannot reverse a financial transaction that has not been persisted.',
            );
        }

        return FinancialRecordIdentifier::fromModel($original, 'reversal_transaction_not_persisted');
    }
}
