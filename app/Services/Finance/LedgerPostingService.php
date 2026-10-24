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
 *
 * INVARIANTS ENFORCED HERE
 * 1. Runs only inside the caller's transaction. It never opens or commits one,
 *    because the account row locks it takes must live until the caller commits.
 * 2. Debits must equal credits (delegated to LedgerBalanceValidator) before any
 *    row is written, and re-verified from the persisted rows afterwards.
 * 3. A transaction may be posted exactly once. The application re-check runs
 *    after account locks are acquired, and the database unique key rejects a
 *    repeated transaction/account/side posting even from another writer.
 * 4. Accounts are locked in ascending id order, so two concurrent postings
 *    touching the same accounts always take them in the same sequence and cannot
 *    deadlock against each other.
 * 5. Posted entries are append-only. This class contains no update and no delete
 *    path for ledger_entries; a mistake is corrected by posting a reversal.
 *
 * The posting identity is financial transaction plus ledger account plus side.
 * It permits the legitimate debit and credit pair and split postings to distinct
 * accounts while rejecting accidental repetition of the same logical line.
 */
final class LedgerPostingService
{
    /**
     * The widest balance `ledger_accounts.current_balance` (DECIMAL(24,2)) holds.
     *
     * A projection past this bound is not a rounding problem the database will
     * absorb: the store either rejects it mid-posting or truncates the magnitude,
     * and a truncated liability balance is an invented one. It is refused while
     * the projection is still in memory, before a single row is written.
     */
    private const MAX_PROJECTED_BALANCE = '9999999999999999999999.99';

    public function __construct(
        private readonly LedgerBalanceValidator $validator,
        private readonly LedgerEntryPayloadPreflight $payloads,
        private readonly LedgerEntryMoneyPreflight $money,
    ) {}

    /**
     * Post a balanced double-entry set for the given transaction.
     *
     * @param  array<int, array<string, mixed>>  $entries  drafts as accepted by LedgerBalanceValidator
     * @param  array<int, string>  $balancesAfter  optional wallet balance snapshot keyed by entry index
     * @param  bool  $preserveExplicitNulls  when true, a null snapshot in $balancesAfter is
     *                                       a statement ("this leg has no wallet balance") rather
     *                                       than an omission to be filled in from the projection
     * @return list<LedgerEntry> the created rows, in the order supplied
     *
     * @throws FinancialException
     */
    public function post(
        FinancialTransaction $transaction,
        array $entries,
        array $balancesAfter = [],
        bool $preserveExplicitNulls = true,
    ): array
    {
        $this->assertInsideTransaction();

        // ADDRESS FIRST. A model whose key is no longer a canonical positive
        // integer — zeroed, negated, suffixed, or re-routed to another table or
        // another connection — is not a journal this service may read, let alone
        // write. Refusing here costs no query at all, so a caller cannot use a
        // malformed address to probe which ids exist.
        $transactionId = FinancialRecordIdentifier::fromModel($transaction, 'ledger_posting_unpersisted_transaction');

        // ONE POSTING AT A TIME per physical write handle. Metadata serialization
        // is extension code, and extension code is allowed to call back into this
        // service; without the fence the nested call would read the account rows
        // before the outer posting's balance roll-forward and both would write.
        // The lease is released in `finally`, so a refusal never strands it.
        $lease = LedgerPostingInvocationLease::acquire(DB::connection()->getRawPdo());

        try {
            return $this->postWithinLease($transactionId, $entries, $balancesAfter, $preserveExplicitNulls);
        } finally {
            $lease->release();
        }
    }

    /**
     * The posting itself. Every fact it needs is read from the database, in the
     * order the locks require, and never from the caller's model.
     *
     * WHY THE MODEL IS NOT TRUSTED FOR ANY DEFAULT
     * The caller may be holding a model it loaded before somebody else changed
     * the row — a status that has since gone terminal, a currency that has since
     * been corrected, a wallet or reference that no longer applies. Writing the
     * model's values would record a journal that disagrees with the transaction it
     * belongs to. The locked row is the authority for every default (currency,
     * wallet, reference, description) and for postability; the model is only an
     * address.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int, string>  $balancesAfter
     * @return list<LedgerEntry>
     */
    private function postWithinLease(
        int $transactionId,
        array $entries,
        array $balancesAfter,
        bool $preserveExplicitNulls,
    ): array
    {
        // q0 — the canonical root read, locked. Every later read happens after it,
        // so the account locks below can never be taken before the owner row.
        // A soft-deleted row is not a postable transaction: it is a record the
        // book has withdrawn, and writing entries against it would resurrect it
        // through the back door.
        $root = DB::table('financial_transactions')
            ->where('id', $transactionId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if ($root === null) {
            throw FinancialException::withCode(
                'ledger_posting_transaction_missing',
                sprintf('Financial transaction %d does not exist or has been deleted.', $transactionId),
                ['transaction_id' => $transactionId],
            );
        }

        $this->assertRootPostable($root, $transactionId);

        $currency = $this->rootCurrency($root, $transactionId);

        // Structural and monetary validation. This runs before the account read
        // because it needs no database at all, and a malformed draft should never
        // cause a lock to be taken.
        $normalised = $this->validator->validate($entries, $currency);

        // The arithmetic validator deliberately knows nothing about the storage
        // identity: it answers "does this draft balance" and returns every line it
        // was given. Repetition of (account, side) is a posting rule, so it is
        // enforced here — after the balance proof, because an unbalanced draft has
        // a more important thing wrong with it than a repeated line.
        $this->assertUniqueEntryIdentity($normalised);

        // q1 — every affected account, locked in ascending id order.
        $accountIds = $this->accountIdsFor($normalised);
        $accounts = $this->lockAccountRows($accountIds, $currency);

        // The witnesses are captured while the rows are still exactly as read.
        // They are the only record of what the posting was authorized against
        // once extension code has had a chance to change it.
        $witness = LedgerPostingInputWitness::capture((array) $root, $this->witnessRows($accounts));
        $boundary = LedgerPostingWriteBoundary::capture();

        // The payload freeze is the ONE place a metadata encoder runs. It returns
        // the encoded bytes, so the model cast never runs a second time — which is
        // what keeps a caller's serializer from executing after the write began.
        $payloads = $this->payloads->prepare($normalised, $currency, $root->description, $root->reference_type);

        if ($this->serializedExtensionPayload($normalised)) {
            // Extension code ran between the reads above. Re-read the facts it
            // could have changed, and refuse rather than post against a plan that
            // no longer describes the books. The re-read is deliberately skipped
            // when no metadata existed: nothing external ran, so nothing can have
            // drifted, and a needless re-read would only add latency.
            // The write context is checked FIRST, and it is checked without a
            // query: a callback that repointed the default connection or replaced
            // the PDO must be refused before anything asks the (now different)
            // writer for rows — asking would either hit another database or throw a
            // raw connection error, and neither is the truthful answer.
            $boundary->assertUnchanged();

            $currentRoot = DB::table('financial_transactions')->where('id', $transactionId)->first();
            $witness->assertUnchanged(
                $currentRoot === null ? null : (array) $currentRoot,
                $this->currentAccountRows($accountIds),
            );
        }

        $this->assertNotAlreadyPosted($transactionId);

        [$projected, $plan] = $this->moneyPlan($normalised, $accounts, $balancesAfter, $currency, $preserveExplicitNulls);

        $postedAt = Carbon::now();
        $created = [];

        foreach ($normalised as $index => $entry) {
            $accountId = $entry['ledger_account_id'];

            /** @var LedgerAccount $account */
            $account = $accounts[$accountId];

            // The balance moves FIRST, through the model, so that an observer can
            // refuse the write. A refused balance write must stop the posting where
            // it stands: the caller's rollback then discards the earlier lines, and
            // the journal is never half-written with a balance that disagrees with
            // its entries.
            $account->current_balance = $projected[$index]->toString();

            if ($account->save() === false) {
                throw FinancialException::withCode(
                    'ledger_account_write_refused',
                    sprintf('The balance write for ledger account %d was refused by the application.', $accountId),
                    ['stage' => 'account_balance_write', 'ledger_account_id' => $accountId],
                );
            }

            $created[] = $this->writeEntry($transactionId, $root, $entry, $payloads[$index], $plan[$index], $postedAt);
        }

        // Re-verify the invariant on the rows that actually reached the database,
        // read back fresh so a stale in-memory currency cannot make an unbalanced
        // journal look balanced.
        $persisted = FinancialTransaction::query()->find($transactionId);

        if ($persisted === null) {
            throw FinancialException::withCode(
                'ledger_posting_transaction_missing',
                sprintf('Financial transaction %d disappeared while it was being posted.', $transactionId),
                ['transaction_id' => $transactionId],
            );
        }

        $this->validator->assertTransactionBalanced($persisted);

        return $created;
    }

    /**
     * Return the currency-specific chart-of-accounts code.
     *
     * The primary currency retains the stable base code used by existing books.
     * Every other currency receives an ISO suffix so a posting can never resolve
     * an account denominated in another currency.
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
     * The chart of accounts is seeded outside this phase, so a missing account is
     * reported as an explicit, actionable failure rather than being created on
     * the fly. Auto-creating accounts would let a typo invent a real account and
     * silently absorb money.
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
        FinancialRecordIdentifier::fromModel($transaction, 'financial_entity_not_persisted');

        return LedgerEntry::query()
            ->withTrashed()
            ->where('financial_transaction_id', $transaction->getKey())
            ->exists();
    }

    /**
     * The entries already posted for a transaction, oldest first.
     *
     * @return Collection<int, LedgerEntry>
     */
    public function postedEntriesFor(FinancialTransaction $transaction)
    {
        FinancialRecordIdentifier::fromModel($transaction, 'financial_entity_not_persisted');

        return LedgerEntry::query()
            ->where('financial_transaction_id', $transaction->getKey())
            ->orderBy('id')
            ->get();
    }

    /**
     * Derived status of an account, from the is_active flag and soft delete.
     *
     * The audited ledger_accounts table has no status column; this keeps the
     * posting rules expressed in one vocabulary without changing the schema.
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
     * Every affected account, read under lock in ascending id order.
     *
     * The accounts are returned as MODELS because their balance is written back
     * through the model: an application observer that refuses a balance write is
     * a refusal this service must honour, and a raw UPDATE would bypass it
     * silently. The witness is fed `getAttributes()` — the raw, uncast values —
     * so the comparison after extension code runs is byte-for-byte against a
     * fresh read rather than a cast-on-one-side comparison that would report
     * drift on every posting.
     *
     * @param  list<int>  $accountIds
     * @return array<int, LedgerAccount> keyed by account id, ascending
     *
     * @throws FinancialException
     */
    private function lockAccountRows(array $accountIds, Currency $currency): array
    {
        if ($accountIds === []) {
            return [];
        }

        sort($accountIds, SORT_NUMERIC);

        // withTrashed: a soft-deleted account must be visible here so that it is
        // reported as "missing", not silently absent from a lock set.
        $locked = LedgerAccount::query()
            ->withTrashed()
            ->whereIn('id', $accountIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $accounts = [];

        foreach ($accountIds as $id) {
            /** @var LedgerAccount|null $account */
            $account = $locked->get($id);

            if (! $account instanceof LedgerAccount || $account->trashed()) {
                throw FinancialException::withCode(
                    'ledger_account_missing',
                    sprintf('Ledger account %d does not exist or has been deleted.', $id),
                    ['ledger_account_id' => $id],
                );
            }

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

            if ($account->currency !== $currency) {
                throw FinancialException::withCode(
                    'ledger_account_currency_mismatch',
                    sprintf(
                        'Ledger account %s is denominated in %s but the transaction is in %s.',
                        $account->code,
                        $account->currency->value,
                        $currency->value,
                    ),
                    [
                        'ledger_account_id' => $id,
                        'account_currency' => $account->currency->value,
                        'transaction_currency' => $currency->value,
                    ],
                );
            }

            $accounts[$id] = $account;
        }

        return $accounts;
    }

    /**
     * The current raw shape of the affected accounts, keyed by id.
     *
     * Soft-deleted rows are INCLUDED: `deleted_at` is one of the witnessed fields,
     * so a row withdrawn after the lock must be reported as that exact change — not
     * as a row that never existed. Only a physically deleted row disappears from
     * this map, and that is what the witness reports as a changed row set.
     *
     * @param  list<int>  $accountIds
     * @return array<int, array<string, mixed>>
     */
    private function currentAccountRows(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }

        $rows = [];

        foreach (LedgerAccount::query()->withTrashed()->whereIn('id', $accountIds)->orderBy('id')->get() as $account) {
            /** @var LedgerAccount $account */
            $rows[(int) $account->getKey()] = $account->getAttributes();
        }

        ksort($rows, SORT_NUMERIC);

        return $rows;
    }

    /**
     * The witnessed shape of the locked accounts.
     *
     * @param  array<int, LedgerAccount>  $accounts
     * @return array<int, array<string, mixed>>
     */
    private function witnessRows(array $accounts): array
    {
        $rows = [];

        foreach ($accounts as $id => $account) {
            $rows[(int) $id] = $account->getAttributes();
        }

        ksort($rows, SORT_NUMERIC);

        return $rows;
    }

    /**
     * Roll each entry onto its account in memory and freeze the encoded amounts.
     *
     * Nothing is written here. The projection is what the caller's balance
     * snapshots are validated against, and the plan is the exact pair of decimal
     * strings that will reach the columns — so a store that would round, or a
     * platform that would lose a cent, is refused rather than silently trusted.
     *
     * @param  list<array<string, mixed>>  $normalised
     * @param  array<int, array<string, mixed>>  $accounts
     * @param  array<int, string>  $balancesAfter
     * @return array{0: array<int, Money>, 1: list<array{amount: string, balance_after: string|null}>}
     */
    private function moneyPlan(
        array $normalised,
        array $accounts,
        array $balancesAfter,
        Currency $currency,
        bool $preserveExplicitNulls,
    ): array
    {
        $running = [];
        $projected = [];

        foreach ($normalised as $index => $entry) {
            $accountId = $entry['ledger_account_id'];

            /** @var LedgerAccount $account */
            $account = $accounts[$accountId];

            $current = $running[$accountId]
                ?? Money::fromDatabase((string) $account->current_balance, $currency);

            $next = $account->type->increasesWith($entry['type'])
                ? $current->plus($entry['amount'])
                : $current->minus($entry['amount']);

            if ($next->absolute()->isGreaterThan(Money::of(self::MAX_PROJECTED_BALANCE, $currency, 2))) {
                throw FinancialException::withCode(
                    'ledger_account_balance_out_of_range',
                    sprintf(
                        'Entry #%d would take ledger account %d outside the range its balance column can hold.',
                        $index,
                        $accountId,
                    ),
                    [
                        'ledger_account_id' => $accountId,
                        'entry_index' => $index,
                        'currency' => $currency->value,
                        'balance_phase' => 'projected',
                    ],
                );
            }

            $running[$accountId] = $next;
            $projected[$index] = $next;
        }

        // Explicit nulls are honoured only when the caller asked for it: a fee leg
        // that lives in no wallet has no wallet snapshot, and inventing the account
        // balance there would put a number in a column that means "this wallet's
        // balance after". A caller that passes a sparse array instead has said
        // nothing about the missing indices, and those are filled from the plan.
        $plan = $this->money->prepare($normalised, $projected, $balancesAfter, $preserveExplicitNulls);

        return [$projected, $plan];
    }

    /**
     * Whether preparing this payload will run caller-supplied code.
     *
     * A JsonSerializable in metadata is extension code, and extension code can do
     * anything the request can do — including changing the very rows this posting
     * was authorized against.
     *
     * @param  list<array<string, mixed>>  $normalised
     */
    private function serializedExtensionPayload(array $normalised): bool
    {
        foreach ($normalised as $entry) {
            if (($entry['metadata'] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The posting identity is (account, side) and may appear only once.
     *
     * WHY THIS IS A REFUSAL AND NOT A MERGE
     * Two drafts against the same account on the same side are not "the same line
     * twice" in any useful sense — they may carry different amounts, descriptions
     * or metadata, and each would become a separate immutable row. The database's
     * unique key would reject the second one, but only after the first was
     * inserted and the account balance had moved, so the caller would have to
     * unwind a half-posted journal to discover a mistake it could have been told
     * about up front.
     *
     * Indices are POSITIONAL — the order the caller supplied — not the array keys:
     * a sparse draft [9, 40, 400] reports the duplicate as the third line, which
     * is what a human reading their own payload counts.
     *
     * @param  list<array<string, mixed>>  $normalised
     *
     * @throws FinancialException
     */
    private function assertUniqueEntryIdentity(array $normalised): void
    {
        $seen = [];

        foreach ($normalised as $index => $entry) {
            $key = $entry['ledger_account_id'].':'.$entry['type']->value;

            if (array_key_exists($key, $seen)) {
                throw FinancialException::withCode(
                    'ledger_duplicate_entry_identity',
                    sprintf(
                        'Ledger entries #%d and #%d post to the same account (%d) on the same side (%s); '
                        .'a posting identity may appear only once.',
                        $seen[$key],
                        $index,
                        $entry['ledger_account_id'],
                        $entry['type']->value,
                    ),
                    [
                        'ledger_account_id' => $entry['ledger_account_id'],
                        'type' => $entry['type']->value,
                        'first_entry_index' => $seen[$key],
                        'entry_index' => $index,
                    ],
                );
            }

            $seen[$key] = $index;
        }
    }

    /**
     * The transaction must still be in flight, judged by the locked row.
     *
     * @throws FinancialException
     */
    private function assertRootPostable(object $root, int $transactionId): void
    {
        $status = (string) $root->status;

        $allowed = [TransactionStatus::Pending->value, TransactionStatus::Processing->value];

        if (! in_array($status, $allowed, true)) {
            throw FinancialException::withCode(
                'ledger_posting_invalid_transaction_status',
                sprintf(
                    'Financial transaction %d is %s; ledger entries may only be posted '
                    .'while a transaction is pending or processing.',
                    $transactionId,
                    $status,
                ),
                [
                    'transaction_id' => $transactionId,
                    'transaction_status' => $status,
                ],
            );
        }
    }

    /**
     * The denomination of the journal, taken from the row rather than the model.
     *
     * @throws FinancialException
     */
    private function rootCurrency(object $root, int $transactionId): Currency
    {
        $currency = Currency::tryFrom((string) $root->currency);

        if (! $currency instanceof Currency) {
            throw FinancialException::withCode(
                'ledger_posting_invalid_transaction_currency',
                sprintf('Financial transaction %d is stored with a currency this book does not know.', $transactionId),
                ['transaction_id' => $transactionId, 'currency' => (string) $root->currency],
            );
        }

        return $currency;
    }

    /**
     * Persist one immutable ledger row.
     *
     * The payload and the money fields arrive already frozen: `$payload` carries
     * encoded JSON bytes and `$plan` the exact decimal strings. Writing them as
     * given is what guarantees the caller's encoder ran once, before the write,
     * and that nothing re-encodes, rounds or truncates a value on its way to the
     * column.
     *
     * @param  array<string, mixed>  $entry  normalised draft
     * @param  array{description: string|null, reference_type: string|null, metadata: string|null}  $payload
     * @param  array{amount: string, balance_after: string|null}  $plan
     */
    private function writeEntry(
        int $transactionId,
        object $root,
        array $entry,
        array $payload,
        array $plan,
        Carbon $postedAt,
    ): LedgerEntry {
        $ledgerEntry = new LedgerEntry;

        $ledgerEntry->fill([
            'ledger_account_id' => $entry['ledger_account_id'],
            'financial_transaction_id' => $transactionId,
            // Same distinction one line down: an explicit null wallet means "this leg
            // is not in a wallet", and must not be replaced by the transaction's wallet.
            'wallet_id' => array_key_exists('wallet_id', $entry) ? $entry['wallet_id'] : $root->wallet_id,
            'type' => $entry['type'],
            'amount' => $plan['amount'],
            'currency' => $root->currency,
            'description' => $payload['description'],
            'reference_type' => $payload['reference_type'],
            'reference_id' => $entry['reference_id'] ?? $root->reference_id,
            'metadata' => null,
            'posted_at' => $postedAt,
        ]);

        // The JSON bytes are written RAW. Assigning through the array cast would
        // encode them a second time — a stored object would become a quoted
        // string — and would also re-run a caller's serializer.
        if ($payload['metadata'] !== null) {
            $ledgerEntry->setRawAttributes(
                array_merge($ledgerEntry->getAttributes(), ['metadata' => $payload['metadata']]),
            );
        }

        // balance_after is deliberately excluded from the model's $fillable so
        // that no request payload can reach it; only this service sets it.
        $ledgerEntry->balance_after = $plan['balance_after'];

        if ($ledgerEntry->save() === false) {
            throw FinancialException::withCode(
                'ledger_entry_write_refused',
                sprintf(
                    'The write of a ledger entry for account %d was refused by the application.',
                    $entry['ledger_account_id'],
                ),
                ['stage' => 'ledger_entry_write', 'ledger_account_id' => $entry['ledger_account_id']],
            );
        }

        return $ledgerEntry;
    }

    /**
     * The transaction may be posted exactly once.
     *
     * Checked inside the caller's transaction and after the account locks, so a
     * concurrent second posting either sees the committed rows or is stopped by
     * the database's own unique key.
     *
     * @throws FinancialException
     */
    private function assertNotAlreadyPosted(int $transactionId): void
    {
        if (LedgerEntry::query()->withTrashed()->where('financial_transaction_id', $transactionId)->exists()) {
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
    private function assertInsideTransaction(): void
    {
        // TWO DIFFERENT LIES, TWO DIFFERENT CODES.
        //
        // Level zero means the caller never opened a transaction at all, so the
        // entries and the balance roll-forward could commit separately: that is
        // `ledger_posting_outside_transaction`.
        //
        // A non-zero level with no physical transaction is worse. The framework
        // believes it is nested inside an open unit of work while the driver has
        // already committed or rolled back underneath it — somebody reached past
        // the connection and ended it. Posting there would write rows that nothing
        // can roll back and lock rows that nothing holds, so the honest code is the
        // wallet boundary's: the transaction state is invalid.
        if (DB::transactionLevel() >= 1 && ! DB::connection()->getPdo()->inTransaction()) {
            throw FinancialException::withCode(
                'wallet_transaction_state_invalid',
                'The framework transaction counter is set but no physical database transaction is open.',
                ['stage' => 'ledger_posting_admission', 'transaction_level' => DB::transactionLevel()],
            );
        }

        if (DB::transactionLevel() < 1) {
            throw FinancialException::withCode(
                'ledger_posting_outside_transaction',
                'Refusing to post ledger entries outside a database transaction: entry '
                .'creation and account balance updates must commit or roll back together.',
            );
        }
    }

    /**
     * @throws FinancialException
     */
}
