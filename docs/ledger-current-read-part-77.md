# Transaction-root journal serialization and current-read admission — Part77

## Application change

`LedgerPostingService::post()` still requires the caller to own a coherent managed physical write-PDO transaction. It now admits the supplied model's existing raw-identity/table/key-column/writer context first, then locks and reloads that canonical financial transaction with `FOR UPDATE`. A missing or soft-deleted row is refused. Only the persisted `Pending` or `Processing` state is postable. In-memory stale attributes do not decide postability.

The canonical model is local to this invocation: the supplied caller model is not refreshed or dirtied. Persisted currency, wallet ID, reference type and reference ID supply entry defaults. Explicit draft overrides still follow the existing draft API; this change does not grant new authorization or verify that every arbitrary balanced draft equals the financial transaction amount.

Lock order is transaction root, existing numerically ordered ledger accounts, then current duplicate admission. This serializes two callers of the same financial transaction even if all their account IDs are disjoint. Duplicate admission first checks caller-visible rows with `LedgerEntry::withTrashed()` so its own uncommitted journal is visible. For MySQL/MariaDB it then uses a fresh, nonpersistent, default-autocommit read-only connection to the effective writer configuration, explicitly not a read replica or manager alias. It compares the actual database, server hostname/port/socket/datadir/server ID tuple with the caller writer and fails closed if the observer reaches another store. The caller keeps the root lock throughout: prior same-root posters must have ended before admission, and a later same-root poster cannot commit during observation. SQLite uses its write serialization and fails closed on stale read-to-write upgrades. Other drivers are refused rather than silently certified. Soft-deleting a posting does not authorize another posting.

`hasPosting()` remains a read-only snapshot observation; it deliberately does not become an implicit transaction-only lock operation. Stored-journal balancing and raw model addressing admission retain their existing contracts. No schema or historical row is rewritten.

### Why the absent journal is not range-locked

The first full native regression exposed a genuine regression in a candidate `FOR UPDATE` journal lookup: two independent currency postings acquired compatible next-key locks on the same empty high-end journal index gap, then deadlocked inserting their distinct transactions before reaching their intended business-lock barriers. That candidate is not delivered. The final implementation uses the fresh same-writer observation under the canonical root lock instead. The four retained actual deadlock/timeout ownership profiles pass again, proving unrelated currency postings can both stage before their genuine business contention. The original failed run remains diagnostic evidence, not accepted execution.

The extra connection only observes; it does not begin/commit the caller transaction, reserve funds, or write a journal. Failure to connect or prove affinity refuses posting; availability does not override once-only admission. Budget one short-lived extra database connection per active MySQL posting and allow the identity-variable probes; connection saturation or restricted metadata access fails closed. Drain old-version workers before deploying the new root-lock protocol; arbitrary concurrent SQL writers are not certified.

## Ownership and failure semantics

The service does not begin/commit a caller transaction or add a universal local savepoint. The caller must roll back a later posting failure. The new root refusal and duplicate checks do not add journal/account writes before refusal. Root locks remain held by the caller's outer database transaction. A successful post can still be undone by caller rollback, including rollback to a caller-created savepoint.

Existing exact arithmetic, account ordering, currencies, normal-balance updates, ledger validation and upstream business permissions are unchanged. This low-level current-state proof is not tenant authorization or historical model provenance.

## Focused feature coverage

The 17 feature cases cover eight stale Pending/Processing versus four terminal persisted states; canonical currency/wallet/reference/status defaults; missing/deleted roots; disjoint-account duplicate and tombstoned posting refusal; root-before-account query order; and caller savepoint rollback without mutating the input model. Unrelated caller work can commit after a pre-write refusal.

## Three genuine native profiles and one store-affinity refusal

`LedgerPostingCurrentReadServerTest` starts two distinct PHP children using `LedgerPostingProbe.php`. Each child begins its own repeatable-read transaction, reads the pending root and a zero journal count, signals its distinct database connection ID, and waits. The parent locks the financial transaction PRIMARY record and releases both children. The test directly joins InnoDB wait/transaction/lock tables and requires both children waiting on that exact parent and table/index before committing.

* `new-winner`: children use disjoint account pairs; exactly one commits two legs and the other refuses a duplicate.
* `already-posted`: the parent posts on a third disjoint pair after both children established empty snapshots; both children must refuse that current posting.
* `terminal`: the parent changes the persisted root to Failed after the children read Pending; both must refuse the current terminal state.

A separate native case redirects the observer to the actual `information_schema` database and requires a store-affinity refusal, zero journal/account writes and a committable caller marker. It also verifies a nonpersistent writer configuration without read/write routing arrays.

Each child commits or rolls back its own transaction and reports zero framework depth and no physical transaction left open. The worker is guarded for testing, the named disposable database, a real absolute Unix socket, and command files below its private testing directory. Commands contain synthetic identifiers/drafts, not merchant secrets. Temporary files and child processes are cleaned in `finally`.

### Engine-mode boundary

The profiles exercise MySQL-style repeatable-read current reads. Like the retained race probes, disposable MariaDB child sessions temporarily switch `innodb_snapshot_isolation` OFF and restore the original setting before reporting success. This is a test-session setting, not an application/global server configuration change. MariaDB snapshot-isolation ON can raise error 1020 on changed old-snapshot rows; that fails closed, and caller-owned rollback is still required. No automatic retry or correctness certification for every MariaDB/MySQL session mode is implied.

## Qualified omission controls

Isolated shadow loaders audit the effective application source paths, including native child processes. The production source stays byte-identical throughout controls.

1. Omitting canonical row reload allows a stale pending model to commit two journal legs against a now-failed persisted root; the intended feature assertion fails while setup succeeds.
2. Replacing the current duplicate lock read with public snapshot `hasPosting()` allows both old-snapshot children to commit their own disjoint pairs after the parent's pair: six real committed journal rows for one financial transaction. The intended native assertion fails with no setup errors/skips. The same shadow harness with healthy source passes and leaves only the parent pair.

Independent database inspection confirms committed row counts and the corresponding synthetic account balances. These are disposable adjustment journals, not provider receipts, wallet funding or external transfers.

## Reproduction and rollout

Run `php vendor/bin/phpunit tests/Feature/Finance/LedgerPostingCurrentReadTest.php` for SQLite feature coverage. For all 21 focused native cases, configure `APP_ENV=testing`, `DB_CONNECTION=mysql`, disposable `DB_DATABASE=thai_lottery_test`, the owned local Unix `DB_SOCKET`, and appropriate local test credentials; run both `LedgerPostingCurrentReadTest.php` and `LedgerPostingCurrentReadServerTest.php`. Optional witness output must start with the system temporary directory plus `/financial-ledger-posting-`.

The CI workflow contains the focused 21-case gate and validates three actual PRIMARY-root wait witnesses; local YAML parsing is not remote CI execution. Acceptance totals and all caveats are in `evidence/acceptance-part-77.json` and `evidence/runtime-part-77.txt`. Overlapping suites must not be added together.

This application implementation was not deferred for merchant credentials. It does not assert external money movement, populated production upgrade/restore, arbitrary draft authorization, global live certification or country-specific legal approval. Those deployment inputs and operational rehearsals remain distinct.
