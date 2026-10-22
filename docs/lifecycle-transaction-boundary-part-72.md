# Part72 — caller-owned physical lifecycle boundaries

## Application changes

Financial lifecycle labels must not be saved merely because Laravel's transaction counter remains positive after its write PDO has physically committed or rolled back. `FinancialStateTransitionService::insideTransaction()` now delegates to the already-shared `WalletLockService` physical predicate. A valid boundary requires a positive framework nesting level **and** an active physical transaction on the default connection's write PDO.

The following entry points refuse an empty, unmanaged-PDO-only, or counter/PDO-divergent boundary before issuing a SELECT, changing a caller model, opening a savepoint, adopting a transaction, or saving status:

- `lockDeposit()` and `transitionDeposit()`;
- `lockWithdrawal()` and `transitionWithdrawal()`;
- the new `lockFinancialTransaction()`;
- `FinancialTransactionService::transitionTo()`;
- `FinancialTransactionService::markFailed()`, through its fresh-row lock.

The stable refusal code is `financial_state_transition_outside_transaction`. Existing missing/unsaved-row and invalid/terminal-transition errors remain distinct under a healthy boundary. No new database schema, provider credential, API route, permission grant, or migration is needed.

## Recheck after reads, not only before them

Each of the three lifecycle row-lock methods rechecks the physical boundary after its real `FOR UPDATE` SELECT returns. A physical commit/rollback during that read cannot return a model falsely certified as still locked.

Deposit cancellation/failure/rejection retains the existing unresolved-checkout protection. Transition methods recheck before status assignment and again before saving, so loss during the checkout read cannot result in an autocommitted lifecycle label. Attribute allow-lists, forbidden monetary columns, terminal-state maps and wallet-before-aggregate ordering remain intact.

These methods own no root transaction. The caller must keep its wallet lock and the same live transaction throughout the operation. They do not repair counters, silently adopt raw PDO transactions, reopen a replacement transaction, or retry a caller-owned unit of work.

## Fresh failure writes

Previously `markFailed()` changed and saved its supplied Eloquent object. That could overwrite a now-completed database row using an old pending model, or accidentally save unrelated dirty `amount`, metadata, or other caller attributes.

It now locks and re-reads the persisted financial row, validates its **current** status, merges an optional failure reason into its **current** metadata, and saves that fresh model. It returns the fresh model rather than modifying the stale caller instance. Unsaved, missing and soft-deleted rows are refused. A null reason leaves current metadata intact. A current completed transaction cannot be marked failed using an old pending object.

This is not a financial repair API. A failed label is not evidence that an arbitrary historical wallet or journal mutation was compensated. Existing engine posting, original-accounting proof, compensation and caller wallet-lock duties still apply.

## Preparation is not persistence

`FinancialTransactionService::transitionTo()` remains an in-memory enum/status setter: it neither saves nor re-reads the row. Its engine callers still persist the prepared model within their live transaction. The new admission guard runs before even the in-memory status is changed. It does not turn this helper into a generally locked persistence API.

For non-mutating, out-of-transaction advice, `FinancialStateTransitionService::assertTransactionTransition()` remains available. Advisory checks do not acquire financial authority or guarantee the row remains current.

Eloquent instances are not automatically rewound by SQL rollback. Refresh or discard an instance whose staged status was rolled back before using it for another transition.

## Executed verification structure

The dedicated feature class contains 74 cases: seven entry points across five invalid ownership/physical states; healthy root/savepoint ownership and rollback; commit/rollback loss after each real entity lock and the checkout read; a fresh-row failure writer that ignores dirty money and stale metadata; current-terminal refusal; nested rollback followed by a committed new nested write; unavailable-row refusal; null-reason preservation; and pure advisory validation.

The six server profiles use two independent child PHP processes and a third parent MariaDB connection. Both children actually warm pending repeatable-read snapshots before the parent changes the corresponding deposit, withdrawal or financial-transaction label while holding its primary-key row lock. The harness observes both children simultaneously waiting on that **parent**, then releases it. Lock callers return the new current terminal state; transition/failure callers refuse it. Full monetary, ledger, checkout and lifecycle row snapshots show no child persistence beyond the explicitly committed parent fixture change.

These parent terminal labels are deliberate **lifecycle-only test fixtures**, not claims that a synthetic status change posts money. MariaDB's snapshot-isolation mode is disabled and restored only in the disposable child sessions so current-lock behavior, rather than a snapshot-conflict abort, is what these profiles exercise.

The native CI gate requires all 80 dedicated records to execute without skips/errors/failures, with unique identities and assertions, plus six genuine parent/two-child wait receipts and two actual old pending snapshots per receipt. Its separate Python omission tests reject missing cases, skips, setup errors, duplicate IDs, fake sessions, substituted tables and absent snapshots. The previous native gates remain unchanged. Local CI parsing is not a claim of remote workflow execution.

## Reproduction

Restore any deliberately consolidated git-exact assets using the retained workspace recovery tool, install locked Composer/npm dependencies, build real Vite assets, and use the acknowledged disposable test database only.

```bash
php vendor/bin/phpunit tests/Feature/Finance/LifecycleTransactionBoundaryTest.php
```

For the local socket-bound native test server:

```bash
export APP_ENV=testing
export DB_CONNECTION=mysql
export DB_DATABASE=thai_lottery_test
export DB_SOCKET=/absolute/path/to/disposable/test.sock
export DB_USERNAME=root
export DB_PASSWORD=''
export FINANCIAL_RACE_WITNESS=/tmp/financial-concurrency-lifecycle-local.jsonl
php vendor/bin/phpunit tests/Feature/Finance/LifecycleTransactionBoundaryTest.php tests/Feature/Finance/LifecycleTransactionBoundaryServerTest.php --log-junit /home/user/lifecycle-local.xml
python3 scripts/verify_lifecycle_boundary_ci.py /home/user/lifecycle-local.xml "$FINANCIAL_RACE_WITNESS"
python3 -m unittest discover -s scripts -p test_verify_lifecycle_boundary_ci.py -v
```

Use a fresh witness filename; never merge stale receipts with a new acceptance run. Full default regression uses a separate PHP process, named disposable SQLite database and `TEST_TOKEN` for every source test file. Native/full/focused counts overlap and must not be added together.

## Explicit limits

This checks current framework/write-PDO coherence, not the historical identity of a transaction that an arbitrary caller may already have ended and replaced. It does not undo already committed facts, provide a universal Eloquent `save()` observer fence, cover arbitrary custom model connections, or certify every driver, upstream wrapper and direct SQL writer. A caller must not end its transaction between guarded preparation and its subsequent save. Arbitrary model event hooks that themselves end transactions are not made safe by this boundary.

This work does not invoke a provider, create a refund request, transfer live funds, modify worldwide legal approval, adopt historical obligations, restore a populated production database, or execute remote CI. Provider/merchant configuration can be supplied later without deferring these application fixes. Exact executed acceptance and evidence are in `evidence/acceptance-part-72.json` and `evidence/runtime-part-72.txt`.
