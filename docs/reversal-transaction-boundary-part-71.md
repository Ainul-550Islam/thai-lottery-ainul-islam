# Part71 — physical transaction admission for reversal and proved lookup

## Completed application change

`FinancialReversalService::reverse()` and `existingReversalFor()` now call the shared current framework/write-PDO admission **before their preliminary wallet-routing SELECT**. Either direction of divergence refuses with the established `wallet_transaction_state_invalid` error before SQL. This applies to new compensation, exact proved replay, an empty proved lookup and a completed lookup, including legitimate walletless system journals.

`WalletLockService::assertConsistentTransactionState()` is a non-owning admission primitive. It permits coherent empty state; it does not create a transaction, take a caller's unmanaged PDO transaction, repair a counter or execute a callback. The same connection-state implementation is reused by `withinTransaction()`, which checks the exact connection it will use.

Both decisive reversal paths now use `withinTransaction()` instead of direct `DB::transaction()`. The entry check and wrapper check are distinct: a physical transaction can end during the real routing SELECT after entry admission. The wrapper must reject before a nested SAVEPOINT can start a replacement SQLite transaction. No current flag check can reconstruct previously committed work or attest an original transaction's identity/history.

Routing remains outside a newly owned root. Moving that SELECT into the root would introduce an unnecessary repeatable-read snapshot before waiting on the wallet. Existing wallet-first locking, current original/journal/candidate proof, canonical key, exact opposite-side compensation, original linkage, managed-obligation refusal and read-only replay remain intact. A caller-owned transaction still gets a savepoint, not a stolen commit; whole-root deadlock retry remains limited to a service-owned root.

## Source-backed tests

The 122-case feature matrix uses real canonical originals in both wallet directions and legitimate walletless system journals:

- 48 combinations reject raw PDO commit, raw rollback, nested stale counters and unmanaged PDO transactions before routing SQL, without changing physical/framework state or financial rows.
- 32 cases end the actual physical transaction in a real routing-query event at caller depth one or two. They require exactly that admitted routing SELECT and no replacement savepoint, subsequent read or mutation.
- 36 root/nested success cases cover new reversal, exact reversal replay, empty proved lookup and completed lookup. They prove balance/journal/linkage, unchanged replay rows, counter/physical ownership and complete caller rollback.
- Two unmanaged caller-write cases retain the caller's own final commit/rollback decision.
- Four injected post-journal failures observe the real changed wallet and four real journal legs before failure, then prove compensation rollback and preservation/commit of unrelated caller work.

The existing original-integrity suite is rerun on both SQLite and actual InnoDB, including THB, BDT and USD originals, mirror/replay proofs, managed obligations, walletless journals, historical faults and failure atomicity. No assertion is removed to make transaction admission pass.

Nine dedicated native profiles use two real PHP sessions, actual old original/replay snapshots and a parent wallet-row mutex. Six physically end the caller transaction after acquiring a current wallet lock and must refuse new reversal/lookup/replay with zero subsequent SQL. Two managed healthy profiles prove whole-caller rollback or one committed compensation with proved duplicate replay despite old original snapshots. The owned-root profile drops its observational old snapshot before invocation: the actual reversal service's wallet lock must produce both witnessed waits, and only one compensation may commit. Fixture balance changes made by the parent are not deposit receipts.

MariaDB's optional snapshot-conflict mode is disabled and restored only inside disposable child sessions, ensuring a server snapshot abort does not masquerade as application protection. SQLite is not InnoDB row-lock evidence. CI requires all 131 dedicated native cases and nine genuine simultaneous parent/two-child waits; local parsing is not a remote-CI claim.

## Limits

This change protects these two decisive reversal entrypoints, not all application transaction wrappers. Advisory `canReverse()`/`assertReversible()` remain advisory and cannot authorize mutation. Source accounting and supported obligation coordinators remain authoritative. Other status-only wrappers, arbitrary direct SQL, original transaction identity, all isolation modes/drivers, distributed failover and live payment approval are not newly certified. Low-level mutation callers still need complete atomic journal/lifecycle orchestration.

No migration, historical repair, provider refund request or live bank transfer is performed by this local compensating-accounting change. Owner-supplied merchant/provider configuration remains separate; its absence did not defer the implementation.
