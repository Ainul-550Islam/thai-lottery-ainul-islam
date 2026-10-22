# Part 19 — durable queue settlement, checkpoint handoff and replay proof

Date: 2026-10-14. Pinned base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Delivery: File196–File208, 13 complete bodies. Cumulative delivered overlay:198 unique paths; next File209. Three prior paths are revised,185 prior paths remain byte-identical, and10 paths are newly delivered. This remains a delivered-file overlay against the pinned repository, not a dependency-inclusive full repository export.

## Reproduced blocker

The unchanged `ProductionQueueComprehensiveTest` reproduced **24 tests /64 assertions /1 error**: its duplicate prize-job fixture called the canonical result writer without a persisted confirmed ingestion. Publication correctly refused before the queue job ran. That refusal was not relaxed.

The result fixtures now use actual ingestion, distinct persisted maker/checker confirmation, canonical publication and a held scheduled draw. Internal confirmation is not an official-source authenticity attestation. The duplicated job case now also verifies a pending approval-required payout, one durable run, actual serialized database queue finalization and unchanged wallet balance on replay.

## Actual application fixes

### Compatibility entry job uses the durable run pipeline

`ProcessPrizeSettlementJob` no longer calls the monolithic `settle()` writer. A new draw enters `ChunkedSettlementOrchestrator::start()`, then processes one bounded checkpoint through `SettleDrawChunkJob`; all subsequent work uses the persisted run id and expected cursor. It does not create speculative independent runs or accept client payout amounts.

Its existing draw-id envelope, unique-id vocabulary,180-second timeout,2 tries and15/60-second backoff remain compatible. The legacy injected handle parameters and nullable DTO signature are retained, but null is not a settlement/payment receipt.

- Existing open runs: an actual worker delivery hands off the current persisted cursor to its canonical chunk job. It does not release itself repeatedly while another path owns the draw.
- Direct legacy handle calls: stand aside for an already-owned open run, preserving the old no-overlap contract.
- Paused/aborted runs: remain paused, acknowledge the redundant envelope without exhausting wait retries, and require explicit operator resume. No automatic reopening occurs.
- Completed runs: revalidate durable completion proof instead of trusting a terminal flag alone.
- A continuation-dispatch failure after a committed checkpoint propagates to the worker. A queued retry repairs the handoff from the durable current cursor, rather than paying the checkpoint again.

This eliminates the old pattern where normal ownership deferral could consume the job's two finite attempts and turn a scheduling wait into an apparent permanent settlement failure. Application failure logs/audits record fixed messages, draw id and exception class, not raw exception messages that may contain SQL/provider credentials. Framework `failed_jobs` storage/stack traces and global logging still need their own retention/access/redaction policy; this is not a claim that all framework failure storage is scrubbed.

### Workers actually consume their continuation lane

The standard priority list consumes `financial-critical,webhooks,reconciliation,default,notifications`. Chunk/finalization jobs previously defaulted to the unlisted `settlement` queue, so a standard worker could accept an entry job but never process its children.

`config/lottery.php` now sets the settlement queue to `financial-critical`, and both child constructors use that same fallback. Database and Redis queue `retry_after` defaults are300 seconds, longer than these180-second entry and120-second child timeouts; the old90-second visibility default allowed premature redelivery. This fixes these defaults, not every possible operator-customized worker/queue/lease policy or unrelated longer-running job.

All three envelopes are after-commit and fail-on-timeout. Shared overlap middleware is advisory; database draw/run locks, pinned inputs, checkpoint cursor and deterministic money references remain authoritative. Cache uniqueness alone is never completion or concurrency proof.

### Cursor and completed-proof gates

A cursorless legacy chunk envelope cannot silently take the current cursor and consume another checkpoint. It refuses; trusted producers already supply an expected persisted cursor.

Completed chunk deliveries invoke the finalizer's proof check. `RealPrizeSettlementService::finalizeRecordedRun()` now requires a completed run to match a settled draw and reconciles its pinned input/outcome/payout totals before returning a replay. Altered totals, changed selection outcomes and forged completed markers are refused. No payout is created and no wallet moves during that revalidation.

Existing result integrity, source/confirmation gates, pinned participant/result/approval inputs, atomic checkpoint-and-money commit, one-currency-per-run rule, manual-approval threshold, safety mode and draw finalization authority remain intact. No production LIVE flag or automatic approval threshold was enabled by this delivery.

## Actual worker and regression evidence

`SettlementQueuePipelineTest` adds14 cases. It uses Laravel's registered `queue.worker` and real serialized database jobs against the guarded disposable SQLite store, not only direct job handle calls. Tests cover:

- Entry → chunk → continuation → finalizer consumed by the standard priority list.
- A published-but-not-completed draw while checkpoints remain.
- Approval-required pending9000.00 obligation with no wallet credit.
- Durable run proof before finalization.
- Lost continuation after a committed synthetic below-threshold credit: retry preserves exact9490.00 wallet balance and financial/ledger row counts without a second credit.
- Stale expected-cursor retries not consuming another checkpoint.
- Cursorless envelope refusal.
- Aborted-run no-auto-resume/no-wait-retry behavior.
- Four completed-proof corruption scenarios.
- Application failure audit not copying a synthetic credential marker.
- Commit dispatching and rollback suppressing actual after-commit envelopes.
- Queue priority, timeout/visibility and after-commit defaults.

The LIVE/below-threshold case is an explicit synthetic test policy. It does not authorize that threshold or auto-credit policy for an operator. Pending approval cases use a zero synthetic threshold and require a separate approved execution before funds can move.

The original safety-gate fixture also bypassed confirmation; it now uses the same actual confirmed-result helper, preserving DISABLED/DRY_RUN/no-credit and LIVE threshold behavior. Existing assertions were not deleted to make publication succeed.

## Recorded validation

All database-writing suites run serially on the guarded disposable repository SQLite test store. Standalone static/CI guards do not bootstrap application database tests. Counts overlap and must not be added as unique coverage.

| Check | Result |
|---|---|
| Unchanged original queue class | 24 tests /64 assertions /1 error |
| First repaired queue/safety/chunk/recorded-proof group | 68 tests /272 assertions, pass |
| First new pipeline cases after harness fixes | 12 tests /80 assertions, pass |
| Final queue/safety/chunk/recorded-proof group including14 new cases | 82 tests /363 assertions, pass |
| Standalone CI unit class | 19 tests /53 assertions, pass |
| Standalone static page contracts | 15 tests /991 assertions, pass |
| Actual CLI /inventory /scanner /offline-runner guards | 6 /9 /11 /8 tests respectively, pass |
| Static financial source preflight | Pass; no missing imported app classes; not live/runtime certification |
| Inventory freshness /frontend build /10 PHP lints /Pint /whitespace | Pass |

The first new-test attempt exposed test-harness mistakes, not application regressions: resolving `Worker::class` bypassed Laravel's registered maintenance callback binding, and spaces in synthetic idempotency suffixes were invalid. The harness now uses `queue.worker` and valid suffixes. The initial **12 tests /39 assertions /6 errors** log is retained. The proof/tampering assertions remain, and the final group passes.

### Final serial whole-suite and post-diagnostic results

| Check | Recorded result |
|---|---|
| Full stop-on-error/failure diagnostic | 2423 collected;2199 tests /127619 assertions /1 failure /1 skipped;224 later cases not reached by that run |
| Final dedicated serialized-worker pipeline class | 14 tests /91 assertions, pass |
| Final production/CI unit group | 50 tests /123 assertions, pass |
| Final finance/settlement/conversion group | 214 tests /1934 assertions, no errors/failures,1 skipped |
| Isolated next translation integrity class | 32 tests /77 assertions /1 failure |

The queue error from Part18 is resolved and the full run proceeds beyond its1962-test stopping point through queue and safety-gate cases. It now stops at `TranslationKeyIntegrityTest::test_every_literal_translation_reference_resolves_in_both_locales`: wallet-conversion views/navigation reference a `wallet_conversion` language namespace that has no English/Thai file. That test remains unchanged. The next delivery must implement complete bilingual dictionaries and verify actual authenticated conversion rendering, fee/limitation copy and key parity, without changing transfer/conversion economics or enabling member transfers.

The one skipped case in both full and scoped finance runs is `SettlementSimulationTest::point_20_lets_only_one_of_two_concurrent_settlements_write`. SQLite cannot establish the equivalent shared-server row-lock protocol. It is not a passed server concurrency test. The224 later cases are not claimed as covered by the full diagnostic; scoped results overlap only selected portions.

No whole-suite green, external provider execution, operator/jurisdiction approval or live customer-money acceptance is claimed.

## Delivery integrity and remaining scope

Only three previously delivered paths change: `RealPrizeSettlementService.php`, `SettleDrawChunkJob.php`, and `FinalizeDrawSettlementJob.php`.185 prior paths remain byte-identical. The passive five-document source inventory was explicitly regenerated, but its bytes did not change; no unchanged generated inventory is redundantly delivered here.

All13 numbered bodies are complete in the continuation Markdown and198-path overlay ZIP. ZIP CRC/uniqueness/body equality, Markdown-body equality and SHA256 for ZIP/Markdown/registry are verified. Dependencies, generated builds, databases, runtime environment/cache and secrets are excluded. The only environment-template entries remain the two previously reviewed credential-empty templates.

The global/multi-country direction is retained. Queue routing uses business priority rather than a Bangladesh-only bank assumption. No new payment provider, currency economic rule, country authorization, consent acceptance, official source or customer funds operation is manufactured.

This is local serialized-worker and injected-failure evidence, not separate-process Redis/MySQL worker performance or a live merchant/provider/browser-money acceptance. The shared-server row-lock concurrency lane, operator jurisdiction/provider approval, global eligibility policy, explicit terms revision acceptance, reliable outbox/reconciliation and staged payment contracts remain release requirements. A queue delivery cannot approve a prize or change the canonical per-draw/manual-approval/own-wallet-versus-member-transfer authority.

Install locked dependencies and build assets before reproducing tests. Do not retarget these guarded disposable-database commands at a production database. Full failures/skips and the next blocker are included in runtime evidence, not hidden behind a “real-money ready” claim.
