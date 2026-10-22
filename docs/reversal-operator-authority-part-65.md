# Part65 — current conversion reversal operator authority

## The real write boundary

`WalletConversionService::reverse()` no longer authorizes money or settled reversal replay using a plain actor lookup plus cached policy relations. `ConversionReversalAuthority` is called inside the same physical service transaction, before the current conversion lookup, exact reversal-key replay, original accounting proof and wallet mutation. It reads current persisted authority using locking SQL reads, not Spatie's permission registrar, a loaded principal relation, `Gate::before`, or an ambient repeatable-read snapshot.

A revoked operator cannot use an existing reversal key to bypass this check. Ordinary member replay of the original conversion remains observational and governed by Part64 original accounting proof; the old reversal actor becoming inactive does not rewrite historical accounting or force another debit.

## Principal and grant proof

1. Require positive owner/actor IDs and a physical SQL transaction. Team-scoped permission configuration is refused rather than interpreted as global authority; this application's qualified permission layout has teams disabled.
2. Lock owner and actor user rows in ascending numeric ID order, deduplicating a self-actor. Both must exist and not be soft-deleted. The actor must be currently Active. Invalid persisted status values fail closed rather than becoming an enum-cast error or an active cached principal.
3. Read the actor's current role memberships using its actual model morph identity and the configured Spatie pivot keys/table names. Lock corresponding role rows and require their identities to exist.
4. Only current `web` role identities participate. Preserve the established web super-admin bypass. Preserve the auditor-only veto, including a direct wallet permission on an auditor; admin plus auditor remains eligible, as before. An API-guard role named super-admin is not a web financial bypass.
5. Read and lock current direct-user grants, inherited grants from current web roles, and their actual permission catalogue rows. A current web `manage wallet` or `reconcile ledger` grant is required unless the current web super-admin bypass applies. No permission, role, membership or cache entry is created or rewritten by this guard.
6. Lock/re-read the conversion and require its owner to match the owner whose principal boundary was acquired. A preload-to-lock owner change refuses and rolls back; it cannot substitute a new owner after taking somebody else's mutex.

Current user, membership, role, grant and catalogue rows used by the decision stay inside the service transaction. The evaluated local server engine is InnoDB with repeatable-read isolation; its current reads and row/range locking, not a cache invalidation assumption, underpin the native evidence. SQLite feature tests prove domain behavior and rollback but are not row-lock evidence. Other isolation modes/engines and all administrative permission writers are not thereby certified. Production must preserve the reviewed isolation/locking contract and separately qualify permission-mutator ordering and deadlock/retry behavior; noncooperating arbitrary SQL writers are not granted safety by this report.

## Lock order and compatibility

Reversal now acquires sorted owner/operator principal rows before the conversion and sorted wallet pair. This removes the owner-A/actor-B versus owner-B/actor-A cycle between covered reversal requests. Ordinary conversion keeps its existing owner mutex before wallet locks. Database/deadlock exceptions are not caught and relabelled as authorization failures; the existing service transaction/retry boundary remains responsible for them. A surrounding caller that catches a refusal and commits still cannot commit reversal money, markers or success audits.

The controller/form policy remains an early presentation/request check. It may carry a stale allow or deny; it is not the final financial authority. If it admits a stale allow, the real service refuses it using `reversal_forbidden`, and the API maps that code to HTTP403. A stale early deny can conservatively deny an otherwise newly granted request; the service-level native positive test establishes that the write boundary itself uses the new current grant, not that every UI cache is automatically refreshed.

Existing original transaction/journal/snapshot proof, inverse linkage, fee accounting, quote admission and the Part63 known-refund boundary remain mandatory. No generic debit, commission, withdrawal, provider/refund, broad admin-session or other service authorization hook is changed here. There is no schema or historical backfill.

## Executed verification design

The feature class contains 40 cases: inherited/direct wallet or ledger permission, web super-admin and mixed-role semantics, self-actor and morph aliases, read-only/wrong-guard/wrong-morph/missing actors, missing physical transaction, raw SQL revocations with an actually retained stale policy allow, first reversal and terminal reversal retry, original member replay, API403, ambient catch-and-commit, and an injected owner change between preload and lock.

Six native profiles use two independent child PHP processes. Each child starts an actual old repeatable-read transaction and warms the old policy/permission cache before the parent's committed change. Both children must simultaneously wait on the parent's owner mutex. Five profiles revoke status, role membership, inherited grant, direct grant on reversal retry, or the permission catalogue name after an old allow. The sixth commits a new grant after two old denies and proves exactly one financial reversal plus one replay. MariaDB's optional snapshot-conflict abort is disabled only in these disposable child sessions and restored afterward, so an engine abort cannot impersonate the intended current-read check.

The mandatory focused CI gate requires all 46 cases with no skip/error/failure, six actual two-child wait records, five exact old-allow snapshots and one exact old-deny pair. All preceding financial gates remain. Local workflow parsing/execution does not imply a remote CI run. Shadow-source omission controls exercise the integration hook, active status, auditor and guard rules, plus current principal result binding and individual membership/inherited/direct/catalogue current reads. The principal control retains the real locks but substitutes an old snapshot principal result, so missing contention cannot fake an authority assertion. An early diagnostic that removed the principal locks was rejected for lacking two direct parent waits; it is not an accepted omission witness. Parent and both real child source paths are audited for the native controls.

Exact current counts, retained prior case IDs, conditional platform-only skips, frozen source hashes and overlap boundaries are in `evidence/acceptance-part-65.json` and `evidence/runtime-part-65.txt`. Source inventory reports remain passive, not financial execution or deployment approval.
