# Part85 — post-callback current financial input witness

## Application change

LedgerPostingService now captures an immutable, pure witness after the existing transaction-root lock, sorted account locks and duplicate-posting admission, before projecting any balance. After preparing and freezing all journal text/JSON payloads, it first runs the existing write-context assertion. Only then does it re-read the same original transaction root followed by the same known, numerically ordered account IDs with current locking reads, including soft-deleted rows. It compares raw persisted attributes against the admitted plan before any service balance update or journal insert. It neither refreshes the caller model nor silently re-projects changed inputs.

Transaction fields: `id`, `user_id`, `wallet_id`, `currency`, `amount`, `status`, `deleted_at`.

Account fields: `id`, `type`, `currency`, `current_balance`, `is_active`, `deleted_at`.

The readonly LedgerPostingInputWitness owns arrays only: no callbacks, models, connection, locks, SQL, transaction or lease. Comparison preserves missing-versus-null, strict raw values and a deterministic account-key order. A missing transaction or missing/extra account set also refuses. The stable domain code is `ledger_posting_inputs_changed`; its context contains only stage `payload_preflight`, entity and field (or `row`/`row_set`), never raw monetary/identity/payload values or a nested exception.

## Why the additional fence is necessary

Trusted JsonSerializable metadata can issue SQL directly without recursively calling the posting service. The Part84 physical-PDO invocation lease cannot stop that SQL. A callback can change a locked account balance, normal-balance type, active flag or currency, or alter the transaction status, amount, ownership or deletion flag, after the old implementation has already validated and projected its rows. A cached plan could overwrite that callback balance or post an admitted-pending plan against a now-terminal transaction. Current locking reads on the already-known row set avoid an old REPEATABLE READ snapshot and refuse the observed drift instead of adopting it.

There is no new wallet lock, absent-journal FOR UPDATE/range lock, global latch, distributed lock, migration or schema widening. Initial root/account order, current-reader observer ownership, pure projection and money encoding, frozen payloads, the Part83 context guard, the Part84 lease and caller transaction ownership remain. The public post signature is unchanged.

## Allowed changes and scope boundary

Unrelated root description, reference annotations, metadata and timestamps, and account name/code/timestamps are deliberately not admission/projection witnesses. Read-only callbacks and a balanced savepoint are allowed when the existing context guard also accepts them. The guard compares observed current values; a value changed and restored before comparison is not a detected transaction-generation event. Raw strict equality may intentionally reject an unexpected type/encoding difference rather than normalize and silently accept it.

This fence does **not** undo callback SQL, earlier wallet writes or other work in the caller transaction. Rejection guarantees no additional posting-service balance updates or journal inserts for observed witnessed drift, not that the entire book remained equal to its pre-callback state. Failed real money operations must normally roll back their whole owned atomic unit, including earlier wallet and callback work. A callback that committed/replaced a handle, restarted the same PDO at the same depth, concealed an engine abort, or changed later hooks is not made safe by equal input values. This is not a callback sandbox, authorization mechanism, historical repair or distributed generation witness.

## Reproducible tests and counterfactual controls

48 pure cases cover all 13 field changes/removals, missing/extra row sets, two currencies and ordering, eight unrelated annotations, input immutability, independent/repeated witnesses, missing/null and restored equal values.

52 posting cases cover 40 deliberate direct-DML mutations across THB/BDT and caller root/savepoint contexts, plus 12 unchanged/annotation/balanced-savepoint cases. Negative cases snapshot the financial tables and query-log offset **after** callback DML, then prove that the service adds no DML and leaves that callback-produced book intact. They preserve caller attributes/depth/physical transaction, and a caller marker demonstrates transaction ownership. Committing a deliberately corrupted disposable callback state is diagnostic, not a production workflow recommendation.

Six exact delivered Part84 baseline controls (SQLite/native MariaDB × balance/active/status changes) use the same unmodified tests, a reflection-audited shadow autoloader and independent committed database reads. Each baseline must fail its healthy assertion rather than setup, with two balanced stale-plan journal rows. Healthy controls produce no journal rows. In the balance profile the baseline overwrites the callback's second-account 123.00 with stale 10.00; the witness preserves 123.00 and adds no posting entries. Active/status callback effects remain observable in both versions. Complete runtime totals, retained case IDs and lock-wait profiles are qualified in acceptance-part-85.json rather than inferred from the planned test count.

## Operator verification

Run the locked runtime prerequisites before the test selections. Run the two new classes on SQLite and native InnoDB, the complete isolated default suite, the retained native financial/concurrency selection and all prior server profiles. The workflow adds a strict 100-unique-case, positive-assertion, zero-skip/error/failure gate and retains its XML. Workflow parsing is local; no remote CI run is implied. No external provider transfer or worldwide legal/regulatory approval is implied by application tests.
