# Part84 — physical-writer posting reentrancy fence

## Implemented behavior

The public `LedgerPostingService::post` first retains the existing live-caller transaction admission, then acquires an invocation lease keyed by the actual write PDO. It executes the existing posting pipeline in a private method and releases its own lease in `finally`, including validation, callback, storage and ordinary PHP Throwable exits. The public signature and caller transaction ownership are unchanged.

`LedgerPostingInvocationLease` uses a private process-local WeakMap keyed by PDO identity. Another posting on the same handle is refused with `ledger_posting_reentrant` before transaction-root/account queries or posting DML. Different service instances and connection wrappers sharing that PDO cannot bypass the fence. Failed acquisition does not own, release or modify the current lease. Release is idempotent and a retained old lease cannot clear a later owner's token. Independent PDOs are not serialized by a global busy flag.

The lease performs no SQL, transaction-state inspection, begin/commit/rollback, reconnect, disconnect or permission decision. Existing transaction, routing, current-read, account-lock, monetary, payload and Part83 observable-context guards remain required. Sequential postings after a prior invocation returns remain allowed and acquire a fresh lease.

## Financial defect addressed

A metadata serializer previously could recursively post a second financial transaction on shared ledger accounts. The inner posting committed/staged 3.00 after the outer projection had already calculated 10.00 from zero. Both journals could balance, but the outer writer then overwrote account caches with 10.00 while stored journal totals were 13.00. The caller's connection, PDO, depth and active state all remained unchanged, so the Part83 context witness correctly was not a reentrancy detector.

Now a propagated nested refusal aborts payload preparation before posting DML; the existing payload codec returns `ledger_entry_metadata_invalid`. If trusted extension code catches the nested refusal and returns valid metadata, the outer posting may finish normally, but the nested journal has no rows. Catching the refusal twice cannot accidentally clear the outer lease. Recursive use of the same financial transaction is also refused at invocation admission.

## Verification

48 pure cases cover normal/exception/Error cleanup, repeated failed acquisitions, stale release ownership, independent handles and release order, weak-key garbage collection, and multiple connection wrappers sharing one physical PDO. Mocks require no PDO SQL or transaction operations.

Forty feature cases cover propagated/caught/repeated and same-transaction recursion, the same versus a cloned service instance, THB/BDT and root/savepoint depth. They verify zero nested queries, stable owner transaction state and model attributes, caller marker commits, expected zero-or-outer-only journals, and correct caches. Sequential 10.00 then 3.00 postings must produce 13.00; payload/reentrant failures must release the lease so later valid calls succeed.

Six exact-original Part83 shadow controls pair propagated/caught/repeated callback recursion on SQLite and native InnoDB with six healthy controls. Reflection source hashes and unchanged feature assertions are audited. Independent database reads qualify four balanced original rows and two accounts cached at 10.00 despite stored journal totals of 13.00. Healthy controls have no nested journal; either no rows or only the outer balanced 10.00 journal remains. Synthetic disposable books only.

## Deliberate limits

This is a synchronous same-PDO reentrancy policy, not cross-process serialization, a distributed lock, a sandbox, rollback ownership or authorization. The existing real concurrency profiles remain required. Independent PDOs can post independently; arbitrary callbacks must not route new money operations to a replacement handle or mutate transaction ownership. Part83 same-PDO restart and stale-driver-state limitations are not solved by this fence.

A callback or later model hook may perform unrelated DML before attempting a nested post. The lease does not undo that work or earlier wallet operations. An outer storage/hook failure still needs caller whole-operation rollback; no generic safe-to-catch-and-commit guarantee. A fatal process failure that bypasses PHP finally is outside the cleanup guarantee: discard the failed worker rather than forcibly clearing a live lease. No migration, schema widening, account arithmetic/projection or root-lock change, external transfer, remote CI execution, populated-production restore or worldwide legal approval is claimed.
