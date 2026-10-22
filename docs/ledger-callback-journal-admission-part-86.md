# Part86 — journal admission after payload callbacks

## Money-writing defect fixed

The initial duplicate-posting check ran before journal metadata serialization. A trusted JsonSerializable callback could bypass the posting service and insert ledger rows directly on the caller's PDO. It did not need to recurse into post(), change a witnessed transaction/account field, or change the transaction depth. Thus the Part84 invocation lease and Part85 financial-input witness correctly remained satisfied, but the posting service's earlier empty-journal observation was no longer true.

A callback row sharing the first draft identity caused the old implementation to update the first cached balance before its insert hit a unique constraint. A row sharing the last identity allowed an earlier service row and both cached balances to be staged before the duplicate failed. A balanced callback pair on disjoint accounts escaped that constraint altogether: the old implementation added a second balanced journal for the same transaction and its final arithmetic check accepted the combined 13.00 debit/credit totals. Catching either late error and committing could retain additional service DML.

## Application change

After complete payload freezing, the existing write-context assertion and the Part85 current financial-input revalidation, postInContext now repeats duplicate-posting admission for **own visible rows** before the first posting balance update or insert. The predicate includes soft-deleted rows and all accounts/sides for the original financial transaction, not only overlapping draft identities. Any such row raises the existing `ledger_duplicate_posting` FinancialException and existing safe transaction-ID context. It does not merge, delete, repair or reclassify journals.

The private admission helpers accept `includeCommitted`, defaulting to true for the unchanged initial admission. The late call explicitly sets it false: it checks the writer's own view and returns without creating a second observer. Own uncommitted inserts remain visible under an existing InnoDB REPEATABLE READ snapshot, including rows inserted and released through a savepoint. This is the callback SQL being fenced.

The original current committed-reader admission and transaction-root lock remain in place. On qualified InnoDB, a conforming competing posting must acquire that root; ordinary FK-enforced insertions on another connection cannot commit a referenced journal past the retained root lock. SQLite retains its existing write serialization and fail-closed obsolete read-to-write upgrade behavior rather than claiming InnoDB row locks or a secondary observer. The late check is not a new global committed-store observation or a promise to sandbox nonconforming writers with foreign keys disabled. Replacing/restarting the same PDO while concealing context drift remains outside the existing transaction-generation guarantees.

There is no absent-journal FOR UPDATE, LOCK IN SHARE MODE, SKIP LOCKED, index-gap lock, new wallet lock, distributed latch, migration, schema widening, new journal writer, or observer ownership change. The initial current-reader algorithm is unchanged when includeCommitted is true. No borrowed connection is disconnected or repaired, no autocommit is changed and no second observer is acquired merely to inspect same-PDO callback rows. The public post signature and caller transaction ownership remain unchanged.

## Allowed callbacks

Read-only callbacks, journals for another transaction and a journal insertion rolled back to a balanced savepoint before the callback returns do not by themselves block the outer valid post. The Part83 context guard and Part85 input witness must still pass. A surviving journal for the current transaction is not made admissible by being balanced, disjoint, soft-deleted or inserted inside a released savepoint.

## What this does not do

A trusted callback has already executed its SQL before this guard runs. Rejection prevents additional posting-service balance updates and ledger inserts; it does not erase the callback row, restore earlier wallet writes, or make the callback-produced book valid. Whole-operation rollback is normally required. The disposable tests intentionally commit caller markers and synthetic callback effects to demonstrate ownership and distinguish callback DML from service DML; they are not examples of an acceptable production error-handling workflow.

The guard is not authorization, a callback sandbox, historical repair, distributed generation detection, complete money-operation atomicity enforcement, or immunity from arbitrary later model/query hooks. It observes surviving rows, not attempted-and-rolled-back insertions or insertions restored to an equal final state. A failed owned operation must still handle all its earlier work coherently.

## Tests and controls

72 new feature cases run six refusal profiles and three allowed profiles across THB/BDT, caller root/savepoint depth and callback placement on the first/last draft. Refusals cover first/last identity collision, disjoint balanced/single rows, soft-deleted rows and savepoint-retained rows. Each refusal captures the book and query-log offset after callback DML, proves no additional service DML or journal locking query, preserves caller attributes/depth/physical state, and independently checks the committed callback-only rows and zero selected-account balances. Allowed cases preserve normal two-row 10.00 posting.

Six exact delivered Part85 counterfactual controls (SQLite/native MariaDB × same-first/same-last/disjoint-pair) use unchanged tests, reflection-audited implementation loading and independent post-process database reads. Healthy controls preserve only callback rows. Baseline controls must fail assertions rather than setup: first collision leaves cache 10.00 before duplicate failure; last collision leaves both caches 10.00 and an extra debit row, with net debit 7.00; disjoint balanced callback rows allow four rows and both selected caches 10.00. The imbalance of a callback-only single row is deliberate synthetic input, not a claim that rejection restored a valid whole book.

Complete measured counts and retention proofs are recorded in evidence/acceptance-part-86.json. All prior test case IDs and 112 genuine contention profiles are retained; no new contention profile is claimed. The new workflow requires exactly 72 unique positive-assertion cases and zero skips/errors/failures, retains its XML and leaves prior gates in place. Workflow parsing is local, not a remote CI run. No live provider transfer, populated-production restore or worldwide legal approval is inferred from these tests.
