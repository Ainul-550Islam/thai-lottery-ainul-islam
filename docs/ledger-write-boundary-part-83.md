# Part83 — caller write context after metadata callbacks

## Implemented boundary

Ledger posting captures an invocation-local `LedgerPostingWriteBoundary` immediately before complete payload preparation and checks it immediately afterwards, before the first account update or journal insert. Metadata uses the Part82 single-encoding plan unchanged. The snapshot is not stored in a singleton, model, database or global variable.

The check requires the same DatabaseManager facade root, unchanged default connection name, the same registered default Connection object, the identical raw write PDO, the original positive Laravel depth, and an active transaction as reported by PDO. A missing registration, a null/lazy/replaced PDO, changed depth, inactive transaction or unavailable PDO state refuses posting with `ledger_posting_write_context_changed`.

The guard inspects the manager registration map and `getRawPdo`, never `connection()` or `getPdo()`. It does not connect to a callback-selected host, invoke a PDO resolver, reconnect, begin, commit, roll back, release a savepoint, disconnect a borrowed handle or repair counters. Context contains only the fixed stage and reason; PDO messages, credentials, hostnames and exception chains are not exposed.

## Compatibility

The public post signature, account ordering and transaction-root locks, arithmetic validation, projection, monetary preflight, JSON encoding and writer behavior are unchanged. A callback that only reads, or opens and closes its own balanced savepoint while leaving the original root alive, is permitted. Both THB and BDT and genuine root/savepoint callers are exercised. No schema change or migration.

## Proof design

48 isolated unit cases cover manager/name/registration changes; null, lazy and replacement PDOs; changed framework depth; inactive/throwing physical checks; valid repeated invocation-local snapshots; and invalid capture. Mocks require no SQL, resolution, reconnect or transaction management.

Forty feature cases use real serialization callbacks: raw/framework commit and rollback, an unbalanced nested begin, replacement with another active PDO at the same visible depth, and a default switch to an unconfigured writer. Each runs in THB/BDT at root/savepoint depth. Twelve positive combinations retain a live context with no change, read-only work or a balanced savepoint.

Refusal assertions observe the callback's state before explicit test-owner cleanup, compare financial tables and caller model attributes, and require zero posting DML. The service must not undo callback effects. A raw rollback or PDO replacement already loses the earlier caller marker; a raw commit already commits it. Tests explicitly distinguish these outcomes rather than falsely claiming the guard can restore prior work.

Six exact-original Part82 controls pair raw commit, unbalanced nested begin and active PDO replacement on SQLite and native InnoDB with six healthy controls. Reflection/source hashes and unchanged feature tests are audited; independent post-process database reads qualify the result. The original writer can commit a balanced two-row journal after the original lock-owning context has changed. Healthy guards write zero journal rows. A balanced journal alone does not prove it used the authorized caller transaction.

## Important limits

This is an observable write-context witness, not a database transaction-generation token. PDO-reported state is not a server-generation proof: stale driver flags or an engine abort swallowed by extension code are not certified by this queryless check. Raw commit/rollback followed by a raw rebegin on the same PDO at the same framework depth can leave all observed values unchanged and is NOT detected. Nor does this certify the continued existence of every savepoint, prevent callback DML, sandbox trusted extension code, or protect arbitrary hooks after this check. Trusted callbacks must not manipulate caller transaction ownership. A caller must still roll back its whole failed money operation where possible; already committed callback effects cannot be uncommitted by this guard.

Earlier Part82 text/JSON, Part81 money, account projection, current-read and observer guards remain required. Existing real contention profiles are retained; no new race profile is claimed. No external transfer, historical rewrite, populated-production restore, remote CI execution or worldwide legal approval is claimed.
