# Manual cursor/checkpoint backend — f42

Verified 2026-10-15. This implements the missing manual service contract behind the fifteen original
cursor/LIVE test errors, using existing `RealPrizeSettlementService::settleBetRange()` and
`finalizeChunkedSettlement()`. It does **not** enable the legacy CLI, queued handler, scheduler,
external gateway payout, production LIVE flags or a deployment. Runtime execution here is restricted
to disposable test databases and synthetic accounts.

## Baseline and original expectations

Before changes the unchanged `Draw\ChunkedSettlementTest` produced **16 tests, 15 errors**
(`cursor-baseline-f42.log`): missing start/run/runChunk/resume/defaultChunkSize APIs. One broad
Throwable negative case was not a meaningful lifecycle proof while those methods were absent.

After the manual backend implementation the **original class passes 16 tests / 76 assertions**.
Its source and every original assertion remain unchanged. Additional exact exception tests now prove
real unpublished-draw refusal rather than accepting a missing-method error.

Focused current checks, including production queue inventory: **51 tests / 315 assertions, all pass,
no skips** after formatting
(`manual-focused-f42.log`, matching JUnit XML). Full/expanded repository measurements are recorded
separately in `docs/ci-regression-status.md` and the failure classification; no whole-suite result is
inferred by subtracting isolated passes.

**Final unfiltered local run: 2,538 tests / 113,966 assertions — all pass, zero errors/failures/skips,
exit 0.** Expanded local regression: **1,441 tests / 107,236 assertions — all pass, no skips**.
Evidence: `full-f42.log`, `part3-focused-f42.log` and matching JUnit XML. All 51 original f35 failing
names now pass, including all fifteen original cursor cases. Local green is not live deployment,
provider-transfer, load/cross-host or production-readiness certification.

## Scope: existing payer, manual coordination only

- The existing range engine retains the six original arguments and owned-transaction guard. Two
  optional typed server closures add admission/checkpoint coordination, never request payload fields.
- Before-range admission runs inside that owned transaction **before any bet/wallet work**.
- After-range checkpoint writes occur **inside the same transaction**. If bookkeeping or validation
  fails, payout, wallet, transaction, journal, selection/bet and checkpoint changes all roll back.
- Existing per-bet payer, approval, wallet resolution, safety audit, reporting/reference/idempotency
  helper logic is unchanged, apart from Pint's formatting/import aliases. This is not a second payer.
- The original batch-dispatch `settle()` entry now explicitly refuses; it does not dispatch the retired
  handler or a missing finalizer. Manual start/run/resume/finalize are separate, explicit APIs.
- The f38 CLI still refuses execution and reads status only. The f39 job still refuses permanently.
  Neither automatically discovers/enables this backend. No route or schedule invokes it.

The manual API can record local wallet credit under the **existing** exact LIVE/approval policy when
called explicitly by trusted server code. In tests this is synthetic local ledger activity, not real
customer funds or a bank/crypto/provider transfer. Under DISABLED/DRY_RUN it records the same existing
pending obligations without credit; approval-required LIVE prizes remain pending too. Those obligations
are financial records, not f37's non-monetary read-only preview.

## Persistent contract and exactly-once boundary

1. Start locks DRAW then RUN, validates published/derived results and creates at most one run per
   draw using the existing unique index. Unpublished starts leave no run row.
2. Chunk size and safety mode are captured once. Later callers cannot reshape/promote them. Default
   configured size clamps to 50..5000; explicit manual size 1..5000 is validated and captured.
3. New nullable server-owned fields pin snapshot version, result/rules/approval-policy fingerprints,
   purchase-input manifest, counts/max id and immutable plan checksum. Historical runs are **not**
   backfilled or silently activated; unsupported snapshots refuse manual execution.
4. `settlement_run_bets` stores one input/output witness per participating bet. No client-fillable
   snapshot fields or cascading FK into source bets are added.
5. The supplied model cursor is an expected checkpoint. A stale cursor returns fresh stored state
   before financial work; a future cursor refuses. The caller token is not mutated in-place.
6. Real separate PHP/PDO tests prove two starters create one run/set and two stale-cursor callers
   commit exactly one payout, one balanced journal credit and one checkpoint.
7. Every chunk checks full captured purchase membership and earlier output witnesses before new work.
   Policy/result are checked after the existing range executes as well, so an in-process change rolls
   back instead of committing against altered approval rules.
8. Stored output is recomputed through the existing pure decision resolver. Payout uniqueness,
   amount/currency/owner, transaction link and balanced existing ledger are verified, not overwritten.
9. Input/status/membership/rule/result/prior journal tampering refuses. Abort metadata stores only a
   safe code after rollback, guarded by the expected cursor so a stale failure cannot poison a later
   successful checkpoint. No compensating credit or forced metadata reset is introduced.
10. Resume looks up the existing run only; it never silently opens one. Unchanged interrupted runs
    finish from their cursor, with the original captured mode/size. Monetary monolithic overlap guards
    continue to see open/aborted ownership and stand aside.
11. Finalization rechecks all source/output witnesses and exact totals/counts/cursor before the existing
    finalizer moves the draw and the run is marked completed in the same transaction. Incomplete or
    inconsistent state never closes the draw. Completed replay does no financial work.
12. Empty participation has zero counters and a null percentage, not invented winners/denominator.

## What additional tests prove

- Checkpoint persistence failure **after a winner credit** rolls back wallet/payout/journal/bet outputs
  and witness updates, then resume credits/checkpoints once.
- A middle-range resolver failure after the first winner also rolls the range back whole.
- Stale retry's actual mutation SQL set is empty; all source/run/witness rows remain byte-equivalent.
- High-value approval and non-LIVE suppression remain intact. Changing approval rules before or
  during a chunk cannot promote an obligation into unreviewed credit.
- Mutating an earlier processed selection or its journal blocks later chunks, not only finalization.
- Result tampering, missing witnesses, edited mode/size, wrong cursor and corrupted totals refuse.
- Owned-transaction requirements, historical snapshot quarantine, no implicit resume-start and genuine
  lifecycle refusal are tested with exact exception types.
- Migration up/down is executed in an independent memory schema, preserving historical and unrelated
  marker rows. Snapshot fields/witness rows remain server-owned.
- Structural checks confirm no new wallet/gateway implementation, route/scheduler wiring or legacy
  dispatch activation. Real default-mode fixture tests never disable or raise existing risk guards.

## Production inventory follow-up

The first unfiltered run after replacing the obsolete dispatcher resolved all original fifteen errors,
but exposed one inventory failure: `SettleDrawChunkJob` no longer had a producer. The contract already
requires each job to be wired **or explicitly deferred**. The permanently refusing F39 job is now
truthfully listed as deferred, with a substantive reason and an additional runtime refusal assertion.
No fake constructor reference, scheduler or producer is added to satisfy the scan. The inventory's
stale-deferral/no-ghost/reason checks remain intact. That initial one-failure run is retained separately
as `full-f42-prequeue.log`, not reported as final green evidence.

## Deliberate limits

Memory is bounded in keyset/read pages, but conservative input/prior-output rechecking can scan the
active/past set for each manual step and finalization. **No 100,000-slip/seconds or throughput/load
certification is claimed.** Local concurrency evidence is shared-file SQLite IMMEDIATE serialization;
server row-lock/cross-host/MySQL/PostgreSQL behavior and deployed recovery are not certified here.

Fingerprints/checksums detect disagreement, not a malicious database administrator who can rewrite
both data and seals. Stored completed replay is historical state, not today's feed/ledger certificate.
Initial admission refuses pending/cancelled/refunded or already-obligated unowned draws rather than
silently guessing takeover semantics. Historical open runs need reviewed handling; do not drop money
ownership/idempotency or enable flags to force execution.

The optional callbacks are trusted internal code, not public client customization. Deployment still
requires independent security/finance review, approved database migration/backup, authority/mode policy,
operational monitoring and a separately approved invocation surface. No such live deployment/action
was performed here. Existing manual approval thresholds and risk ceilings are not loosened in code.

## Complete-file delivery and reproduction

The thirteen changed/new PHP files below are delivered beginning to end (**3,706 complete lines**):

| Complete path | Lines |
|---|---:|
| `app/Services/Draw/ChunkedSettlementOrchestrator.php` | 567 |
| `app/Services/Draw/RealPrizeSettlementService.php` | 1,278 |
| `app/Services/Draw/ManualSettlementSnapshot.php` | 306 |
| `app/Models/SettlementRun.php` | 171 |
| `app/Models/SettlementRunBet.php` | 29 |
| `app/Exceptions/ChunkedSettlementException.php` | 43 |
| `database/migrations/2026_10_15_000100_complete_manual_settlement_checkpoints.php` | 48 |
| `tests/Feature/Draw/ManualSettlementCheckpointSafetyTest.php` | 473 |
| `tests/Feature/Draw/ManualSettlementCheckpointConcurrencyTest.php` | 202 |
| `tests/Feature/Draw/ManualSettlementCheckpointMigrationTest.php` | 74 |
| `tests/Unit/Settlement/ManualSettlementCheckpointContractTest.php` | 143 |
| `tests/Support/manual-checkpoint-worker.php` | 92 |
| `tests/Feature/Production/QueueDispatchContractTest.php` | 280 |

No method is elided and no original failing assertion is deleted, renamed or replaced by a success
stub. The original **717-line** core test is included unchanged as the acceptance contract; its
SHA-256 remains `66f1514dff139d6c82f54e73240e9fe1deddb04fc1956868b5d92abc9ae31b57`.
Canonical membership and hashes are in the refreshed list/manifest; earlier safe files are retained.

After PHP/dependency/frontend/native prerequisites, run:

```bash
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/Draw/ChunkedSettlementTest.php \
  tests/Feature/Draw/ManualSettlementCheckpointSafetyTest.php \
  tests/Feature/Draw/ManualSettlementCheckpointConcurrencyTest.php \
  tests/Feature/Draw/ManualSettlementCheckpointMigrationTest.php \
  tests/Unit/Settlement/ManualSettlementCheckpointContractTest.php
```

The test worker accepts only APP_ENV=testing, an admitted disposable database and fixed start/chunk
operations. Connection/rules travel on stdin; inherited URL/name are stripped and readiness tokens are
required. It is not a registered application or operator command. Compiled native artifacts/caches,
real credentials and databases remain outside the source bundle; rebuild native after reset.
