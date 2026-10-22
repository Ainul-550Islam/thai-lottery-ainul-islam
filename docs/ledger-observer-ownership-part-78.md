# Journal observer ownership — Part78

## Application change

`LedgerPostingService` keeps the canonical transaction-root lock, ordered account locks, own-uncommitted-row visibility and read-only public helper behavior from Part77. Its MySQL current journal observation is now delegated to `LedgerPostingObserver`.

The observer is an internal support operation: the posting service must already hold the canonical financial-transaction root lock. An active writer transaction and a positive identifier alone are not new money authorization or independent proof of that lock.

The effective writer configuration still excludes read/write routing arrays and forces nonpersistent PDO creation. Before admitting cleanup ownership, the observer rejects:

- the caller's exact connection object;
- any connection object already registered with the database manager, even idle ones;
- an unexpected read-side PDO or lazy read resolver;
- the caller's exact physical PDO;
- any already-resolved write/read PDO belonging to another registered connection, without evaluating that connection's lazy PDO;
- nonzero framework depth or an active physical observer transaction;
- physical driver types other than MySQL for this MySQL-only observation path.

These refusals do not disconnect the candidate, reset its depth, repair its PDO or commit/roll back another owner's work. Only a distinct, unregistered, unsplit, idle candidate earns cleanup ownership. Once admitted, success and exceptions disconnect only that owned observer in `finally`.

The actual `@@SESSION.autocommit` value must equal one. Framework depth zero and `PDO::inTransaction() === false` do not establish this: a MySQL session can have autocommit disabled while no transaction is currently active. The application refuses that state rather than issuing `SET autocommit`, beginning a transaction or repairing the caller.

Actual database/server hostname, port, socket, datadir and server-ID affinity checks remain; a separate reader endpoint or another store is not accepted. The journal query stays nonlocking and includes tombstones, avoiding the shared empty-gap coupling found and fixed in Part77.

## Evidence and regression coverage

`LedgerPostingObserverOwnershipTest` has 18 cases:

- Twelve borrowed-candidate cases cover caller object, writer-PDO alias, manager-owned idle/active objects, an unregistered active object and a read-side borrowed PDO, each at caller root and savepoint depth.
- Six owned-observer cases cover successful posting, autocommit disabled and an actual injected query exception, each at root/savepoint depth. The application cleanup count is inspected before explicit test teardown cleanup.

SQLite executes the 12 genuine-PDO helper ownership cases directly because its posting path deliberately does not use a secondary observer. Its six native-only cases are reported as skips, not native verification. MariaDB executes all 18 through the actual posting service. Tests verify unchanged caller handle/depth/physical state, preservation of unrelated active temporary-table work, committable caller markers, no journal/account effects after refusal and caller-controlled savepoint rollback after success.

All retained Part77 root-wait and financial retry/deadlock profiles must still pass. The number of genuine contention profiles is unchanged at 112; this part adds ownership/cleanup cases, not new race profiles. The CI workflow adds an exact 18-case zero-skip native gate and retains the prior 21-case current-read gate. Local YAML parsing does not claim remote CI execution.

### Native verifier prerequisite replay

The first complete default run exposed 15 additional prerequisite skips in two unchanged weekly-lottery PHP test files because the excluded Rust release binary was absent in this fresh environment. This is not accepted as completion. The locked verifier is rebuilt and its 27 Rust tests run; both complete affected PHP files are replayed in fresh isolated databases after native financial acceptance. Accepted reports replace those entire file selections only after exact case-ID matching and zero replay skips/errors/failures. Original reports remain diagnostic evidence. Application/test source hashes remain unchanged throughout.

## Qualified omission controls

Two isolated shadow-source controls each require exactly one intended assertion failure with zero setup errors/skips and a corresponding healthy control. Reflection audits the effective observer source file.

1. Replacing owned-only cleanup with unconditional cleanup detaches the caller's original PDO and resets its Laravel depth while the separately held physical PDO is still active. This is an ownership/counter corruption witness, not a fabricated committed payment.
2. Omitting the real autocommit probe allows the autocommit-off observer path to stage two synthetic journal legs. The shadow assertion detects this before test teardown rolls back the caller. No committed forbidden journal or external transfer is claimed for this control.

Application source stays unchanged throughout these controls. Exact control logs and XML are separate from healthy acceptance results.

## Reproduction and boundary

Run `php vendor/bin/phpunit tests/Feature/Finance/LedgerPostingObserverOwnershipTest.php`. For all 18 cases, use the acknowledged disposable MySQL/MariaDB test database `thai_lottery_test` and owned local Unix socket under `APP_ENV=testing`, following the existing native test harness. Borrowed-resource probes are synthetic; no provider or merchant credentials are needed.

An otherwise unregistered idle object with a distinct PDO is accepted under Laravel's trusted factory fresh-instance contract. This is not proof against an arbitrary hostile custom factory or historical handle provenance. The Part77 MySQL-style repeatable-read/MariaDB session-mode boundary remains unchanged. SQLite write serialization remains its existing separate path; other database drivers are not newly certified.

Budget a short-lived extra writer connection per active MySQL posting, allow the identity/autocommit probes and drain old-version workers before deploying the new root-lock protocol. Connection/metadata failures fail closed; availability does not override once-only admission.

No schema migration, historical repair, arbitrary draft authorization, provider transfer, populated production restore or worldwide legal approval is added or asserted. Merchant rollout details remain owner inputs; their absence did not defer this application fix.
