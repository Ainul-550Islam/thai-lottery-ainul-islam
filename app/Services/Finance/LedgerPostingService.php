<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Enums\Currency;
use App\Enums\LedgerAccountStatus;
use App\Enums\LedgerEntryType;
use App\Enums\TransactionStatus;
use App\Exceptions\FinancialException;
use App\Models\FinancialTransaction;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of ledger_entries.
 *
 * RESPONSIBILITY
 * Turn a validated set of entry drafts into immutable ledger rows for one
 * financial transaction, and roll the affected account balances forward in the
 * same atomic step.
 */
final class LedgerPostingService
{
    public function __construct(
        private readonly LedgerBalanceValidator $validator,
    ) {}

    /**
     * Post a balanced double-entry set for the given transaction.
     *
     * @param  array<int, array<string, mixed>>  $entries  drafts as accepted by LedgerBalanceValidator
     * @param  array<int, string>  $balancesAfter  optional wallet balance snapshot keyed by entry index
     * @return list<LedgerEntry> the created rows, in the order supplied
     *
     * @throws FinancialException
     */
    public function post(
        FinancialTransaction $transaction,
        array $entries,
        array $balancesAfter = [],
        bool $preserveExplicitNulls = false,
    ): array {
        $this->assertInsideTransaction();
        $lease = LedgerPostingInvocationLease::acquire(DB::connection()->getPdo());

        try {
            return $this->postInContext($transaction, $entries, $balancesAfter, $preserveExplicitNulls);
        } finally {
            $lease->release();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, mixed>  $balancesAfter
     * @return list<LedgerEntry>
     *
     * @throws FinancialException
     */
    private function postInContext(
        FinancialTransaction $transaction,
        array $entries,
        array $balancesAfter,
        bool $preserveExplicitNulls,
    ): array {
        $canonical = $this->lockPostableTransaction($transaction);

        $normalised = $this->validator->validate($entries, $canonical->currency);
        $this->assertDistinctEntryIdentities($normalised);

        $accountIds = $this->accountIdsFor($normalised);
        $accounts = $this->lockAccounts($accountIds, $canonical);
        $this->assertNotAlreadyPosted($canonical);

        $witness = LedgerPostingInputWitness::capture(
            $canonical->getAttributes(),
            $this->accountAttributes($accounts),
        );
        $projectedBalances = (new LedgerAccountBalanceProjector)->project($accounts, $normalised);
        $moneyPlan = (new LedgerEntryMoneyPreflight)->prepare(
            $normalised,
            $projectedBalances,
            $balancesAfter,
            $preserveExplicitNulls,
        );

        $boundary = LedgerPostingWriteBoundary::capture();
        $payloadPlan = (new LedgerEntryPayloadPreflight)->prepare(
            $normalised,
            $canonical->currency,
            $canonical->description,
            $canonical->reference_type,
        );
        $boundary->assertUnchanged();
        $this->assertPlanningInputsUnchanged($witness, (int) $canonical->getKey(), $accountIds);
        $this->assertNotAlreadyPosted($canonical, false);

        $rawEntries = array_values($entries);
        $postedAt = Carbon::now();
        $created = [];

        foreach ($normalised as $index => $entry) {
            /** @var LedgerAccount $account */
            $account = $accounts[$entry['ledger_account_id']];

            $this->persistAccountBalance($account, $projectedBalances[$index]);

            $created[] = $this->writeEntry(
                $canonical,
                $entry,
                $moneyPlan[$index],
                $payloadPlan[$index],
                $postedAt,
                $preserveExplicitNulls && array_key_exists('wallet_id', $rawEntries[$index] ?? []),
            );
        }

        $this->validator->assertTransactionBalanced($canonical);

        return $created;
    }

    /**
     * @param  list<array{ledger_account_id: int, type: LedgerEntryType}>  $entries
     *
     * @throws FinancialException
     */
    private function assertDistinctEntryIdentities(array $entries): void
    {
        $seen = [];

        foreach ($entries as $index => $entry) {
            $accountId = $entry['ledger_account_id'];
            $side = $entry['type']->value;
            $key = $accountId.':'.$side;

            if (isset($seen[$key])) {
                throw FinancialException::withCode(
                    'ledger_duplicate_entry_identity',
                    'A single financial transaction posting cannot repeat the same ledger account and side.',
                    [
                        'ledger_account_id' => $accountId,
                        'type' => $side,
                        'first_entry_index' => $seen[$key],
                        'entry_index' => $index,
                    ],
                );
            }

            $seen[$key] = $index;
        }
    }

    /**
     * Return the currency-specific chart-of-accounts code.
     */
    public static function accountCodeFor(string $baseCode, Currency $currency): string
    {
        $baseCode = trim($baseCode);

        if ($baseCode === '') {
            throw FinancialException::withCode(
                'ledger_account_code_empty',
                'Ledger account code cannot be empty.',
            );
        }

        return $currency === Currency::primary()
            ? $baseCode
            : $baseCode.':'.$currency->value;
    }

    /**
     * Look up a chart-of-accounts entry by its code.
     *
     * @throws FinancialException
     */
    public function resolveAccount(string $code): LedgerAccount
    {
        $account = LedgerAccount::query()->where('code', $code)->first();

        if (! $account instanceof LedgerAccount) {
            throw FinancialException::withCode(
                'ledger_account_missing',
                sprintf(
                    'Ledger account "%s" is not present in the chart of accounts. '
                    .'Seed the chart of accounts before posting financial transactions.',
                    $code,
                ),
                ['ledger_account_code' => $code],
            );
        }

        if (! $this->statusOf($account)->canPost()) {
            throw FinancialException::withCode(
                'ledger_account_not_postable',
                sprintf('Ledger account "%s" is %s and cannot receive postings.', $code, $this->statusOf($account)->value),
                ['ledger_account_code' => $code, 'ledger_account_status' => $this->statusOf($account)->value],
            );
        }

        return $account;
    }

    /**
     * Whether the transaction already has ledger entries.
     */
    public function hasPosting(FinancialTransaction $transaction): bool
    {
        $transactionId = FinancialRecordIdentifier::fromModel($transaction, 'financial_entity_not_persisted');

        return LedgerEntry::query()
            ->withTrashed()
            ->where('financial_transaction_id', $transactionId)
            ->exists();
    }

    /**
     * The entries already posted for a transaction, oldest first.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function postedEntriesFor(FinancialTransaction $transaction)
    {
        $transactionId = FinancialRecordIdentifier::fromModel($transaction, 'financial_entity_not_persisted');

        return LedgerEntry::query()
            ->where('financial_transaction_id', $transactionId)
            ->orderBy('id')
            ->get();
    }

    /**
     * Derived status of an account, from the is_active flag and soft delete.
     */
    public function statusOf(LedgerAccount $account): LedgerAccountStatus
    {
        return LedgerAccountStatus::fromFlags(
            (bool) $account->is_active,
            $account->trashed(),
        );
    }

    /**
     * @param  list<array{ledger_account_id: int}>  $entries
     * @return list<int>
     */
    private function accountIdsFor(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $ids[$entry['ledger_account_id']] = true;
        }

        return array_map('intval', array_keys($ids));
    }

    /**
     * Lock every affected account row in ascending id order.
     *
     * @param  list<int>  $accountIds
     * @return array<int, LedgerAccount> keyed by account id
     *
     * @throws FinancialException
     */
    private function lockAccounts(array $accountIds, FinancialTransaction $transaction): array
    {
        sort($accountIds, SORT_NUMERIC);

        /** @var array<int, LedgerAccount> $accounts */
        $accounts = LedgerAccount::query()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        foreach ($accountIds as $id) {
            if (! isset($accounts[$id])) {
                throw FinancialException::withCode(
                    'ledger_account_missing',
                    sprintf('Ledger account %d does not exist or has been deleted.', $id),
                    ['ledger_account_id' => $id],
                );
            }

            $account = $accounts[$id];

            if (! $this->statusOf($account)->canPost()) {
                throw FinancialException::withCode(
                    'ledger_account_not_postable',
                    sprintf(
                        'Ledger account %s (%d) is %s and cannot receive postings.',
                        $account->code,
                        $id,
                        $this->statusOf($account)->value,
                    ),
                    ['ledger_account_id' => $id, 'ledger_account_code' => $account->code],
                );
            }

            if ($account->currency !== $transaction->currency) {
                throw FinancialException::withCode(
                    'ledger_account_currency_mismatch',
                    sprintf(
                        'Ledger account %s is denominated in %s but the transaction is in %s.',
                        $account->code,
                        $account->currency->value,
                        $transaction->currency->value,
                    ),
                    [
                        'ledger_account_id' => $id,
                        'account_currency' => $account->currency->value,
                        'transaction_currency' => $transaction->currency->value,
                    ],
                );
            }
        }

        return $accounts;
    }

    /**
     * @param  array<int, LedgerAccount>  $accounts
     * @return array<int, array<string, mixed>>
     */
    private function accountAttributes(array $accounts): array
    {
        $rows = [];

        foreach ($accounts as $id => $account) {
            $rows[(int) $id] = $account->getAttributes();
        }

        return $rows;
    }

    /**
     * @param  list<int>  $accountIds
     *
     * @throws FinancialException
     */
    private function assertPlanningInputsUnchanged(
        LedgerPostingInputWitness $witness,
        int $transactionId,
        array $accountIds,
    ): void {
        sort($accountIds, SORT_NUMERIC);

        $currentTransaction = FinancialTransaction::withTrashed()
            ->whereKey($transactionId)
            ->lockForUpdate()
            ->first();

        /** @var array<int, LedgerAccount> $currentAccounts */
        $currentAccounts = LedgerAccount::withTrashed()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id')
            ->all();

        $witness->assertUnchanged(
            $currentTransaction?->getAttributes(),
            $this->accountAttributes($currentAccounts),
        );
    }

    /**
     * Persist an already-projected account balance.
     *
     * @throws FinancialException
     */
    private function persistAccountBalance(LedgerAccount $account, Money $nextBalance): void
    {
        $accountId = (int) $account->getKey();
        $account->current_balance = $nextBalance->toString();

        if ($account->save() !== true) {
            throw FinancialException::withCode(
                'ledger_account_write_refused',
                'Refusing to continue ledger posting after an account balance write was vetoed.',
                [
                    'stage' => 'account_balance_write',
                    'ledger_account_id' => $accountId,
                ],
            );
        }
    }

    /**
     * Persist one immutable ledger row.
     *
     * @param  array{
     *     ledger_account_id: int,
     *     type: LedgerEntryType,
     *     amount: Money,
     *     wallet_id: int|null,
     *     description: string|null,
     *     reference_type: string|null,
     *     reference_id: int|null,
     *     metadata: array<string, mixed>|null
     * }  $entry
     * @param  array{amount: string, balance_after: string|null}  $moneyPlan
     * @param  array{description: string|null, reference_type: string|null, metadata: string|null}  $payloadPlan
     *
     * @throws FinancialException
     */
    private function writeEntry(
        FinancialTransaction $transaction,
        array $entry,
        array $moneyPlan,
        array $payloadPlan,
        Carbon $postedAt,
        bool $preserveNullWalletId = false,
    ): LedgerEntry {
        $ledgerEntry = new LedgerEntry;

        $ledgerEntry->fill([
            'ledger_account_id' => $entry['ledger_account_id'],
            'financial_transaction_id' => (int) $transaction->getKey(),
            'wallet_id' => $preserveNullWalletId ? $entry['wallet_id'] : ($entry['wallet_id'] ?? $transaction->wallet_id),
            'type' => $entry['type'],
            'amount' => $moneyPlan['amount'],
            'currency' => $transaction->currency,
            'description' => $payloadPlan['description'],
            'reference_type' => $payloadPlan['reference_type'],
            'reference_id' => $entry['reference_id'] ?? $transaction->reference_id,
            'posted_at' => $postedAt,
        ]);

        $ledgerEntry->setRawAttributes(
            array_replace(
                $ledgerEntry->getAttributes(),
                ['metadata' => $payloadPlan['metadata']],
            ),
        );
        $ledgerEntry->balance_after = $moneyPlan['balance_after'];

        if ($ledgerEntry->save() !== true) {
            throw FinancialException::withCode(
                'ledger_entry_write_refused',
                'Refusing to continue ledger posting after a ledger entry write was vetoed.',
                [
                    'stage' => 'ledger_entry_write',
                    'ledger_account_id' => $entry['ledger_account_id'],
                ],
            );
        }

        return $ledgerEntry;
    }

    /**
     * @throws FinancialException
     */
    private function assertInsideTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw FinancialException::withCode(
                'ledger_posting_outside_transaction',
                'Refusing to post ledger entries outside a database transaction: entry '
                .'creation and account balance updates must commit or roll back together.',
            );
        }

        app(WalletLockService::class)->assertConsistentTransactionState();
    }

    /**
     * Lock and reload the canonical transaction root.
     *
     * @throws FinancialException
     */
    private function lockPostableTransaction(FinancialTransaction $transaction): FinancialTransaction
    {
        $transactionId = FinancialRecordIdentifier::fromModel(
            $transaction,
            'ledger_posting_unpersisted_transaction',
        );

        $locked = FinancialTransaction::query()
            ->whereKey($transactionId)
            ->lockForUpdate()
            ->first();

        if (! $locked instanceof FinancialTransaction) {
            throw FinancialException::withCode(
                'ledger_posting_transaction_missing',
                sprintf('Financial transaction %d no longer exists or has been deleted.', $transactionId),
                ['transaction_id' => $transactionId],
            );
        }

        $allowed = [TransactionStatus::Pending, TransactionStatus::Processing];

        if (! in_array($locked->status, $allowed, true)) {
            throw FinancialException::withCode(
                'ledger_posting_invalid_transaction_status',
                sprintf(
                    'Financial transaction %d is %s; ledger entries may only be posted '
                    .'while a transaction is pending or processing.',
                    $transactionId,
                    $locked->status->value,
                ),
                [
                    'transaction_id' => $transactionId,
                    'transaction_status' => $locked->status->value,
                ],
            );
        }

        return $locked;
    }

    /**
     * @throws FinancialException
     */
    private function assertNotAlreadyPosted(FinancialTransaction $transaction, bool $includeCommitted = true): void
    {
        $transactionId = (int) $transaction->getKey();

        if ($this->hasCurrentPosting($transactionId, $includeCommitted)) {
            throw FinancialException::withCode(
                'ledger_duplicate_posting',
                sprintf(
                    'Financial transaction %d already has ledger entries; posting twice '
                    .'would double the money movement.',
                    $transactionId,
                ),
                ['transaction_id' => $transactionId],
            );
        }
    }

    /**
     * @throws FinancialException
     */
    private function hasCurrentPosting(int $transactionId, bool $includeCommitted = true): bool
    {
        if (LedgerEntry::withTrashed()->where('financial_transaction_id', $transactionId)->exists()) {
            return true;
        }

        if (! $includeCommitted) {
            return false;
        }

        $writer = DB::connection();
        $driver = $writer->getDriverName();

        if ($driver === 'sqlite') {
            return false;
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            return app(LedgerPostingObserver::class)->hasPosting($writer, $transactionId);
        }

        throw FinancialException::withCode(
            'ledger_posting_current_reader_invalid',
            sprintf('Current journal observation is not certified for database driver "%s".', $driver),
            ['driver' => $driver],
        );
    }
}
