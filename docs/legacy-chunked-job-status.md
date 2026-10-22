# Legacy chunk job — f39 composition repair and permanent execution refusal

Historical **f39** checkpoint. F42 subsequently completes the manual cursor service and retires the
old dispatcher while this queued handler stays refused. See `docs/manual-cursor-settlement-status.md`
and current `docs/ci-regression-status.md`; earlier counts below remain historical.

Verified 2026-10-14 on PHP 8.4.26. No LIVE wallet-credit scope was approved. This closes a real
**existing job-class loading defect** and retires its invalid execution callees; it does not implement
the original cursor/LIVE backend or represent its fifteen failing tests as passing.

## Reproduced before changing code

An isolated PHP process loading `SettleDrawChunkJob` exited **255**: the class's promoted readonly
`batchId` property conflicted with Laravel `Batchable::$batchId`, which is mutable and framework-owned.
This is a composition fatal, not a catchable missing-method exception.

- Exact isolated error: `/home/user/legacy-job-load-red-f39.log`.
- The new child-process PHPUnit regression failed once against that code:
  `/home/user/legacy-job-unit-red-f39.log`.
- After repair the real independent-process class-load probe passes.

The earlier repository's fifteen cursor errors occurred before this job was loaded, which is why
those measurements did not expose this additional defect.

## Measured component result

**26 tests / 121 assertions — all pass, no skips** after Pint.
Evidence: `/home/user/legacy-job-focused-f39.log` and matching JUnit XML.
Expanded regression: **1,166 tests / 104,700 assertions — all pass, no skips**.
Unfiltered repository: **2,450 tests / 113,293 assertions — 15 errors, 0 failures, 2 existing skips**.
The exact fifteen backend failing names are unchanged from f38; no new failing name was introduced.
Evidence: `/home/user/part3-focused-f39.log`, `/home/user/full-f39.log` and matching JUnit XML.
Detailed comparisons are in `docs/ci-regression-status.md` and
`docs/full-suite-failure-classification.json`.

## What changed

1. **Separate identities:** the existing six constructor parameter names remain accepted. The
   producer's `batchId` argument is stored as readonly `planId`. Laravel's trait alone owns `batchId`
   and assigns the actual queue batch membership through `withBatchId()`.
2. **Serializable payload:** current payload round trips preserve both identities, queue and range
   metadata. A real database-backed batch proves its stored job contains the actual native batch id,
   not the unrelated plan correlation id.
3. **No invalid monetary callee:** the handler no longer injects money services, calls missing
   `SelectionSettlementResolver::settleOneBet()` or queries `draw_results.result_fingerprint`.
4. **Permanent refusal, not success:** `handle(): never` always throws the dedicated
   `LEGACY_CHUNKED_BACKEND_UNAVAILABLE` exception before business-table work. There is no estate flag,
   mode parameter or future backend-method discovery that enables it silently.
5. **One attempt:** job-level `tries = 1` and `maxExceptions = 1` prevent transient retry churn for a
   permanently unavailable capability. Existing overlap middleware retains only safe coordinates.
6. **Minimal disclosure:** exception text and tags omit plan ids, framework batch ids, fingerprints,
   source payloads and personal fields. Private queue storage still necessarily carries the serialized
   metadata; it is not a public export.

Constructor checks validate positive range coordinates and identifier/hash **shape only**. Accepting
a fingerprint-shaped string is not verification of a published result or permission to pay anyone.

## What real worker tests prove

Tests use Laravel's actual database queue, real `jobs` / `job_batches` / `failed_jobs` rows and an
explicit **one-job** `queue:work --once` invocation, not a Queue fake for the worker path.

- Worker-level `--tries=5` cannot override this job's permanent one-attempt refusal.
- A `JobFailed` event occurs once, with the dedicated refusal and attempt count one.
- No `JobProcessed` success event occurs, no retry job is released, and one failed-job row remains.
- A second empty worker pass creates no additional failure or business effect.
- A native batch under the default no-failure-allowed policy records the failure on its **actual**
  batch id and cancels; its success callback never runs. A temporary-file sentinel verifies callback
  non-execution without placing any financial operation in the callback.
- Laravel may stamp `finished_at` when cancelling a batch. That bookkeeping field and a normal
  worker-process exit **0** do not mean the job or settlement succeeded. Tests inspect failure events,
  failed/cancelled batch data and absence of the success callback instead.
- All rows in **20 business/preview tables** remain unchanged. Framework queue bookkeeping is allowed
  to change the three queue tables and is asserted as failure, not hidden as a financial no-op success.

The fixture queue is isolated and non-money-classified. Existing production operational-alert transport
on configured money queues is not disabled or certified by these tests. The handler itself has no
provider/payment dependency. HTTP/financial-service negative controls additionally exercise direct
invocation under estate `DISABLED`, `DRY_RUN` and `LIVE` settings, observing **zero database queries**
and zero monetary-service resolutions.

## Deliberately unsupported execution

This job is a loadable, inspectable, **retired execution payload**, not a working settlement processor.
The f38 legacy CLI remains read-only and refuses queue/resume/start attempts; it is not re-enabled by
the composition fix. The independent f37 preview remains the non-monetary audit path.

The original fifteen cursor/run/resume/finalize/LIVE expectations remain untouched. The planner still
has its own unsupported result-fingerprint assumptions and missing finalizer contract. Removing the
job's invalid callees does not implement those backend properties or certify the planner's separate
`allowFailures()` lifecycle. No finalizer stub, success alias or test exclusion is added.

The one-attempt worker guarantee above is for newly enqueued repaired payloads. An older persisted
queue envelope may retain an older `maxTries` budget even though this handler still refuses; no
historical queue metadata is silently rewritten. Persisted payloads are not automatically migrated,
replayed or deleted. A partially initialized
older-shaped object also refuses before reading business state. Review unsupported queued work and
failed-job metadata operationally; do not clear money-run ownership, idempotency, KYC or approvals to
force a replay. Turning payout flags on cannot recover this retired handler.

## Complete files — no elision

| Complete path | Lines |
|---|---:|
| `app/Jobs/Draw/SettleDrawChunkJob.php` | 91 |
| `app/Exceptions/LegacyChunkedSettlementUnavailable.php` | 24 |
| `tests/Feature/Settlement/LegacyChunkedJobRefusalTest.php` | 254 |
| `tests/Unit/Settlement/LegacyChunkedJobSafetyTest.php` | 220 |
| **Total** | **589** |

Every file is delivered beginning to end. Invalid monetary loops are removed rather than retained
behind a misleading success branch. The child-process fatal probe, exact refusal types, real worker
failure, serialization/identity tests and structural negative controls accompany the change.

Existing wallet/purchase/payout/commission/KYC services, cursor orchestrator, `SettlementRun`,
scheduler, original legacy tests and labelled fixture flags remain unchanged. No migration, public
route, gateway transfer, LIVE credit, multi-level commission, invented offer/counter or affiliation
claim is introduced. This component's passing tests are not a green-full-suite claim.
