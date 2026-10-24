<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\FinancialTransactionType;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerEntry;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reverses a completed financial transaction by compensation, never by erasure.
 *
 * THE RULE
 * A posted ledger entry is history. This service never updates and never deletes
 * an original entry, and never rewrites the original transaction's monetary
 * fields. It creates a NEW transaction of type reversal whose entries are the
 * exact mirror of the original - every debit becomes a credit of the same amount
 * against the same account, and vice versa - so both movements remain visible and
 * the ledger still balances when the two are summed.
 *
 * The only change made to the original record is its lifecycle marking:
 * status becomes `reversed`, and reversed_at / reversed_by are stamped. Those are
 * exactly the fields the audited schema provides for this purpose.
 *
 * GUARDS
 * - only a persisted, `completed` transaction may be reversed;
 * - a transaction may be reversed at most once;
 * - a reversal itself can never be reversed (FinancialTransactionType::Reversal
 *   is not reversible), so there is no way to build a chain of compensations
 *   that cancel each other into ambiguity.
 *
 * ATOMICITY AND IDEMPOTENCY
 * The whole reversal runs in one transaction under a row lock on the wallet. Its
 * idempotency key is derived deterministically from the original transaction id,
 * so a retried reversal collides with itself on the unique index and returns the
 * reversal that already exists rather than reversing twice.
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
    ) {
    }

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

        // Entry admission before the preliminary wallet-routing read: a divergent
        // framework/physical transaction state must refuse without any SQL.
        $this->locks->assertConsistentTransactionState('reversal_entry');

        // The identity set is proved before any routing SQL, so a coerced or
        // rerouted key can never address another transaction.
        FinancialRecordIdentifier::fromModel($original, 'reversal_transaction_not_persisted');

        $key = $idempotencyKey ?? $this->reversalKeyFor($original);
        if ($idempotencyKey !== null && $key !== $this->reversalKeyFor($original)) {
            throw FinancialException::withCode(
                'reversal_noncanonical_key',
                'Reversal idempotency key must equal the canonical reversal key derived from the original transaction.',
                ['transaction_id' => (int) $original->getKey()],
            );
        }

        // The preliminary wallet-routing read stays OUTSIDE the owned root: it must
        // not open a repeatable-read snapshot before we wait on the wallet row.
        $walletId = $this->routeWalletId($original);

        return $this->transactions->executeWithDeadlockRetry(
            // withinTransaction re-samples the transaction state immediately before
            // the wrapper. A physical commit/rollback can happen during the routing
            // read above, and a nested SAVEPOINT would otherwise silently start a
            // replacement transaction that nothing can roll back.
            fn (): FinancialTransaction => $this->locks->withinTransaction(
                fn (): FinancialTransaction => $this->performReversal($original, $actorUserId, $key, $reason, $walletId),
            ),
        );
    }

    /**
     * The wallet the persisted original routes to, read before any lock is taken.
     *
     * Deliberately a narrow SELECT: the caller may end the physical transaction
     * during it, and the boundary is re-checked immediately afterwards.
     */
    private function routeWalletId(FinancialTransaction $original): ?int
    {
        $walletId = FinancialTransaction::withTrashed()
            ->whereKey($original->getKey())
            ->value('wallet_id');

        return $walletId === null ? null : (int) $walletId;
    }

    /**
     * Whether this transaction may be reversed right now.
     */
    public function canReverse(FinancialTransaction $transaction): bool
    {
        if (! $transaction->exists) {
            return false;
        }

        // The caller's instance may be stale or may have been edited in memory
        // after it was loaded. Permission is proved from what is persisted, so a
        // caller cannot widen its own authority by changing attributes.
        $persisted = FinancialTransaction::withTrashed()->find($transaction->getKey());
        if (! $persisted instanceof FinancialTransaction) {
            return false;
        }

        if ($persisted->status !== TransactionStatus::Completed) {
            return false;
        }

        if (! FinancialTransactionType::fromTransactionType($persisted->type)->isReversible()) {
            return false;
        }

        try {
            $wallet = $persisted->wallet_id !== null ? Wallet::withTrashed()->find($persisted->wallet_id) : null;
            app(ReversalIntegrityGuard::class)->assertOriginal(
                $persisted,
                $wallet,
                app(ReversalIntegrityGuard::class)->entries($persisted),
            );

            return ! $this->hasBeenReversed($persisted);
        } catch (FinancialException) {
            return false;
        }
    }

    /**
     * The reversal that already compensates this transaction, if one exists.
     *
     * The audited schema has no reversal_of column, so the link is the
     * polymorphic reference: the reversal points at the original transaction
     * through reference_type / reference_id.
     */
    public function existingReversalFor(FinancialTransaction $original): ?FinancialTransaction
    {
        FinancialRecordIdentifier::fromModel($original, 'reversal_transaction_not_persisted');
        $this->locks->assertConsistentTransactionState('reversal_lookup');

        $walletId = $this->routeWalletId($original);

        return $this->locks->withinTransaction(
            fn (): ?FinancialTransaction => $this->lookupReversal($original, $walletId),
        );
    }

    /**
     * The proved lookup itself, always inside a sampled, consistent boundary.
     */
    private function lookupReversal(FinancialTransaction $original, ?int $walletId): ?FinancialTransaction
    {
        $guard = app(ReversalIntegrityGuard::class);

        if ($walletId !== null) {
            // Same wallet-first order as a new compensation, so a lookup racing an
            // actual reversal cannot pass the proof while money is moving.
            $this->locks->lock($walletId);
        }

        // Both halves of the pair are read from persistence. The caller's instance
        // may be stale or edited in memory, and neither may decide whether a
        // compensation exists or whether it is the canonical one.
        $persisted = FinancialTransaction::withTrashed()->whereKey($original->getKey());
        if (DB::transactionLevel() > 0) {
            $persisted->lockForUpdate();
        }

        $persisted = $persisted->first();
        if (! $persisted instanceof FinancialTransaction) {
            $guard->refuse();
        }

        $query = FinancialTransaction::withTrashed()
            ->where('reference_type', FinancialTransaction::class)
            ->where('reference_id', (int) $persisted->getKey())
            ->where('type', FinancialTransactionType::Reversal->toTransactionType())
            ->orderBy('id');

        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $rows = $query->limit(2)->get();
        if ($rows->count() > 1) {
            $guard->refuse();
        }

        /** @var FinancialTransaction|null $reversal */
        $reversal = $rows->first();

        // "Marked reversed" and "a compensation exists" are two statements of the
        // same fact. A ledger that says only one of them is ambiguous, and an
        // ambiguous pair is never reported as a clean, replayable reversal.
        $claimsReversed = $persisted->status === TransactionStatus::Reversed || $persisted->reversed_at !== null;
        if ($claimsReversed !== ($reversal instanceof FinancialTransaction)) {
            $guard->refuse();
        }

        if ($reversal instanceof FinancialTransaction) {
            // A completed compensation must be accompanied by the original's
            // reversal lifecycle label. A relabelled original ("completed" again)
            // makes the pair ambiguous, however canonical the compensation is.
            if ($persisted->status !== TransactionStatus::Reversed || $persisted->reversed_at === null) {
                $guard->refuse();
            }

            $guard->assertCompensation($persisted, $reversal, $guard->entries($persisted), $this->reversalKeyFor($persisted));
        }

        return $reversal;
    }

    /**
     * The deterministic idempotency key for reversing this transaction.
     */
    public function reversalKeyFor(FinancialTransaction $original): string
    {
        return $this->idempotency->deterministicKey('reversal', (string) $original->getKey());
    }

    /**
     * @throws FinancialException
     */
    public function assertReversible(FinancialTransaction $original): void
    {
        // The identity set is proved before any routing SQL, so a coerced or
        // rerouted key can never address another transaction.
        FinancialRecordIdentifier::fromModel($original, 'reversal_transaction_not_persisted');

        $engineType = FinancialTransactionType::fromTransactionType($original->type);

        if (! $engineType->isReversible()) {
            throw FinancialException::withCode(
                'reversal_not_permitted_for_type',
                sprintf(
                    'A %s transaction cannot be reversed; reversing a reversal would make '
                    .'the audit trail ambiguous.',
                    $original->type->value,
                ),
                [
                    'transaction_id' => (int) $original->getKey(),
                    'transaction_type' => $original->type->value,
                ],
            );
        }

        if ($original->status !== TransactionStatus::Completed) {
            throw FinancialException::withCode(
                'reversal_requires_completed_transaction',
                sprintf(
                    'Financial transaction %d is %s; only a completed transaction can be reversed.',
                    (int) $original->getKey(),
                    $original->status->value,
                ),
                [
                    'transaction_id' => (int) $original->getKey(),
                    'transaction_status' => $original->status->value,
                ],
            );
        }

        if ($this->hasBeenReversed($original)) {
            throw FinancialException::withCode(
                'reversal_already_exists',
                sprintf('Financial transaction %d has already been reversed.', (int) $original->getKey()),
                ['transaction_id' => (int) $original->getKey()],
            );
        }
    }

    /**
     * Body of the reversal, always inside a transaction.
     *
     * @throws FinancialException
     */
    private function performReversal(
        FinancialTransaction $original,
        ?int $actorUserId,
        string $key,
        ?string $reason,
        ?int $walletId,
    ): FinancialTransaction {
        // Canonical lock order: WALLET first, FINANCIAL TRANSACTION second. The id
        // comes from the persisted routing read, not from the caller's instance: an
        // instance edited in memory must never choose which wallet gets locked.
        $wallet = $walletId !== null ? $this->locks->lock($walletId) : null;

        // The row is read WITH its soft-delete marker so a deleted header is refused
        // by the accounting proof as ambiguous, rather than reported as missing.
        /** @var FinancialTransaction|null $locked */
        $locked = FinancialTransaction::withTrashed()
            ->whereKey($original->getKey())
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof FinancialTransaction) {
            throw FinancialException::withCode(
                'reversal_transaction_not_found',
                sprintf('Financial transaction %d no longer exists.', (int) $original->getKey()),
                ['transaction_id' => (int) $original->getKey()],
            );
        }

        if ($wallet === null && $locked->wallet_id !== null) {
            $wallet = $this->locks->lock((int) $locked->wallet_id);
        }

        $guard = app(ReversalIntegrityGuard::class);
        $originalEntries = $guard->entries($locked);
        $walletSide = $guard->assertOriginal($locked, $wallet, $originalEntries);

        if ($this->hasBeenReversed($locked)) {
            $existing = $this->existingReversalFor($locked);
            if (! $existing instanceof FinancialTransaction) {
                $guard->refuse();
            }
            $guard->assertCompensation($locked, $existing, $originalEntries, $key);

            return $existing;
        }

        if ($locked->status !== TransactionStatus::Completed) {
            throw FinancialException::withCode(
                'reversal_requires_completed_transaction',
                sprintf(
                    'Financial transaction %d is %s; only a completed transaction can be reversed.',
                    (int) $locked->getKey(),
                    $locked->status->value,
                ),
                [
                    'transaction_id' => (int) $locked->getKey(),
                    'transaction_status' => $locked->status->value,
                ],
            );
        }

        if ($wallet instanceof Wallet && ! $wallet->status->canTransact()) {
            // Reacting to a frozen wallet by moving money again would be a second
            // decision taken on top of the freeze. Only a compensation that already
            // exists and proves itself may be replayed.
            throw FinancialException::withCode(
                'reversal_wallet_not_eligible',
                sprintf('Wallet %d is %s; a new compensation cannot be posted against it.', (int) $wallet->getKey(), $wallet->status->value),
                ['wallet_id' => (int) $wallet->getKey(), 'wallet_status' => $wallet->status->value],
            );
        }

        $expectation = [
            'type' => FinancialTransactionType::Reversal,
            'amount' => (string) $locked->amount,
            'currency' => $locked->currency->value,
            'wallet_id' => $locked->wallet_id,
            'user_id' => $locked->user_id,
        ];

        $claim = $this->idempotency->claim(
            $key,
            $expectation,
            fn (string $claimedKey): FinancialTransaction => $this->createReversalTransaction(
                $locked,
                $originalEntries,
                $claimedKey,
                $reason,
            ),
        );

        /** @var FinancialTransaction $reversal */
        $reversal = $claim['transaction'];

        if ($claim['replayed'] === true) {
            $guard->assertCompensation($locked, $reversal, $originalEntries, $key);

            return $reversal;
        }

        $this->transactions->transitionTo($reversal, TransactionStatus::Processing)->save();

        $walletSide = $wallet instanceof Wallet
            ? $this->walletSideOf($originalEntries, (int) $wallet->getKey())
            : null;

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

        // Mark the original. Only lifecycle fields change: amount, fee, type,
        // currency and every original ledger entry are left byte-for-byte intact.
        $this->transactions->transitionTo($locked, TransactionStatus::Reversed);
        $locked->reversed_at = Carbon::now();
        $locked->reversed_by = $actorUserId;
        $locked->save();

        return $reversal->refresh();
    }

    /**
     * Create the compensating transaction record.
     *
     * reference_type / reference_id point back at the original, which is how the
     * pair is discoverable in both directions without a schema change.
     */
    private function createReversalTransaction(
        FinancialTransaction $original,
        \Illuminate\Database\Eloquent\Collection $originalEntries,
        string $key,
        ?string $reason,
    ): FinancialTransaction {
        $metadata = [
            'engine_type' => FinancialTransactionType::Reversal->value,
            'reversal_of_transaction_id' => (int) $original->getKey(),
            'reversal_of_reference_number' => $original->reference_number,
            'reversal_of_type' => $original->type->value,
            'reversal_source_hash' => app(ReversalIntegrityGuard::class)->fingerprint($original, $originalEntries),
        ];

        if ($reason !== null) {
            $metadata['reversal_reason'] = $reason;
        }

        $reversal = new FinancialTransaction();

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
     * Build the mirror of the original posting.
     *
     * Each original entry produces one entry against the SAME account for the
     * SAME amount on the OPPOSITE side. Because the original set balanced, the
     * mirror balances too, and the two sets sum to zero per account.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LedgerEntry>  $originalEntries
     * @return array<int, array<string, mixed>>
     */
    /**
     * The mirrored posting of an original transaction. Public because a compensation
     * written by a domain coordinator has to post the same mirrored legs inside its own
     * transaction; it must not re-implement (and quietly drift from) this mapping.
     */
    public function mirrorEntries($originalEntries, FinancialTransaction $original): array
    {
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

        // Keep the wallet-facing entry first so a balance snapshot supplied by the
        // caller lines up with index 0, exactly as in a forward posting.
        usort($mirrored, static fn (array $a, array $b): int => ($b['wallet_id'] === null ? 0 : 1) <=> ($a['wallet_id'] === null ? 0 : 1));

        return $mirrored;
    }

    /**
     * Which side the original posting applied to the wallet.
     *
     * Read from the persisted entries rather than inferred from the type, so the
     * compensation always matches what actually happened.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, LedgerEntry>  $entries
     */
    private function walletSideOf($entries, int $walletId): ?LedgerEntryType
    {
        foreach ($entries as $entry) {
            if ((int) $entry->wallet_id === $walletId) {
                return $entry->type;
            }
        }

        return null;
    }

    /**
     * A transaction is considered reversed when it is marked as such or when a
     * compensating transaction already points at it. Both are checked, so a
     * partially recorded reversal can never be duplicated.
     */
    private function hasBeenReversed(FinancialTransaction $transaction): bool
    {
        if ($transaction->status === TransactionStatus::Reversed || $transaction->reversed_at !== null) {
            return true;
        }

        return $this->existingReversalFor($transaction) !== null;
    }
}
