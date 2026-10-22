# Financial parity — installment 5: persisted draw settlement

Date: 2026-10-14 (Asia/Dhaka). Base repository commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

## Scope and complete-file numbering

This installment continues after File49 and supplies File50–File63 in full. File1–File49 retain their existing numbering and contents. The combined ZIP contains 63 unique repository-relative paths. Historical reports remain snapshots of their own installments; this report is the current draw-settlement status.

| File | Repository-relative path |
|---|---|
| 50 | `database/migrations/2026_10_14_000200_pin_settlement_run_inputs.php` |
| 51 | `app/Models/SettlementRun.php` |
| 52 | `app/Services/Draw/SettlementRunGuard.php` |
| 53 | `app/Services/Draw/RealPrizeSettlementService.php` |
| 54 | `app/Services/Draw/ChunkedSettlementOrchestrator.php` |
| 55 | `app/Jobs/Draw/SettleDrawChunkJob.php` |
| 56 | `app/Jobs/Draw/FinalizeDrawSettlementJob.php` |
| 57 | `app/Console/Commands/Lottery/SettleDrawChunkedCommand.php` |
| 58 | `tests/Feature/Draw/Concerns/BuildsConfirmedSettlementDraws.php` |
| 59 | `tests/Feature/Draw/ChunkedSettlementTest.php` |
| 60 | `tests/Feature/Draw/RecordedSettlementSafetyTest.php` |
| 61 | `evidence/draw-validation-part-5.txt` |
| 62 | `evidence/financial_source_preflight-part-5.json` |
| 63 | `docs/financial-parity-part-5.md` |

Eleven PHP files are delivered. Changed existing files are supplied in their entirety, not as patches. No environment files, credentials, dependency folders, generated build output, or test database are packaged.

## Selected implementation

Use **one persisted `SettlementRun` per draw, serial keyset chunks, database-owned checkpointing, and a guarded finalizer**. The old speculative parallel batch implementation was incompatible with the existing command and persisted-run tests, referenced a nonexistent enum case and resolver method, and dispatched an absent finalizer with a custom UUID unrelated to a durable completion record. It has been replaced, not cosmetically patched.

Parallel settlement of ranges within the same draw is intentionally not implemented. Multiple deliveries/workers serialize on draw/run database locks and compare the expected cursor. Different draws can have independent jobs, but cross-draw wallet concurrency and real database contention still require staging tests.

No throughput benchmark or claim of processing 100,000 slips within seconds is made. Input-proof checks scan the participant and selection inputs in bounded-memory pages for each chunk. This is deliberately conservative and can be expensive on a large draw.

## What changed

### Durable run input proof

The additive migration stores nullable result and participant fingerprints, the maximum captured bet ID, and the original automatic-approval threshold on `settlement_runs`. New runs capture these facts while holding the draw lock. The existing unique draw-ID constraint remains the one-run-per-draw boundary.

The result digest covers the canonical published primary/optional prize facts. The canonical settlement service still validates published winning-number rows before any chunk. The participant digest covers ordered, non-deleted bet and selection inputs, including ownership, currency, stake, number, metadata, potential payout, and selection multiplier. Mutable settlement outcomes are excluded. Soft deletion, late participation, input changes, or absent proof cause refusal rather than silent re-planning.

Keyset settlement is bounded by the captured maximum bet ID. Later IDs cannot be included in a chunk even if an out-of-band write races a normal input-proof read. Normal application writers must still respect draw locking; this implementation does not add database triggers or protect against an administrator rewriting arbitrary rows and their proof fields.

### Money and checkpoint have one transaction owner

`RealPrizeSettlementService::settleRecordedChunk()` owns the transaction and locks draw then run. The existing canonical selection resolution, payout creation, wallet service, and ledger posting logic are reused through a private shared range executor.

Selections, bet outcomes, payout obligations, any permitted wallet/ledger credit, accumulated totals, cursor, chunk count, and the durable chunk audit commit together. A run-save or audit failure rolls the entire chunk back. The entry point refuses an enclosing transaction so an outer caller cannot swallow an inner failure and commit partial money.

The expected-cursor comparison turns stale retries into no-ops. A retry may enqueue a duplicate continuation, but it cannot count or pay the same chunk again. A participant without readable selections cannot be counted as settled.

### Finalization requires durable outcome proof

The previously missing finalizer now exists. It verifies the draw/run pairing and delegates to `finalizeRecordedRun()`; a job delivery itself is not evidence of completion.

The authoritative finalization transaction locks draw then run, requires an open run with no remaining participant, checks the captured input proof, recomputes each stored selection, validates bet outcomes and currency, checks payout ownership and eligible obligation status, and reconciles exact stake/prize/counter totals. It then transitions the draw, completes the run, and writes a required final audit in the same transaction.

The legacy `finalizeChunkedSettlement()` entry point now requires the same persisted proof. Caller-supplied totals are ignored as authority; they cannot bypass the finalizer. Completed runs return idempotently without additional payouts, credits, or completion audits.

**Draw completion means settlement evaluation and payout obligations have been recorded and reconciled. It does not mean every obligation has been paid.** Under DRY_RUN, DISABLED, or required manual approval, the payout stays Pending and no wallet is credited. This follows the existing canonical settlement semantics.

### Safety can tighten, not silently become less restrictive

The original run safety mode is recorded. A run opened as DRY_RUN cannot become LIVE because configuration changes. A LIVE run can be suppressed by the current kill switch before a later chunk credits anything; previously committed credits are not reversed by this switch.

The effective automatic-approval threshold is the more restrictive of the original threshold and the current valid threshold. Increasing configuration later cannot make an originally approval-gated prize automatically payable. Invalid threshold input refuses the chunk.

The CLI passes expected cursors for queued work, supports explicit reopening of an aborted run through resume, and rejects `--dry-run` for an existing incomplete run pinned to LIVE, including queued resumes. No global automation setting is enabled by this installment.

### The fixture now exercises the real result approval boundary

The old chunk test fixture attempted to publish without a confirmed result. Its shared replacement ingests a result from the existing trusted internal source, creates distinct persisted maker/checker users, and confirms through the real confirmation service, which publishes atomically. No confirmation or provenance guard was disabled. The original sixteen assertions suites now reach and exercise settlement rather than failing at setup.

The twenty-three additional safety tests include:

- checkpoint-save rollback of wallet, ledger, payout, and outcome writes;
- final audit failure rolling back both draw and run completion;
- result drift, removed/late participants, and changed selection multiplier refusal;
- forged counters/cursor and altered stored outcomes not proving completion;
- paused-run worker behavior and explicit resume;
- foreign job/run ownership refusal;
- stale queued delivery not advancing or paying twice;
- continuation scheduling and repeated finalizer idempotency;
- legacy unpinned run refusal without guessed backfill;
- enclosing-transaction refusal;
- mid-run kill switch and both directions of approval-threshold tightening;
- mixed-currency and empty-selection whole-chunk rollback;
- missing payout obligation blocking completion;
- `--dry-run` refusing existing LIVE queue work;
- inline/chunked obligation and credit parity;
- the legacy finalizer not bypassing persisted completion proof.

Queue tests use fake delivery scheduling and invoke handlers directly; they do not certify Redis, a real queue worker, serialization across deployment versions, or crash-safe queue delivery.

## Verification

All application PHP tests used the SQLite test database `database/thai_lottery_test` from `phpunit.xml`. Dependencies were restored from existing lockfiles and Vite assets rebuilt. The full suite logs, including unsuccessful broader checks, are retained in File61.

| Check | Result |
|---|---|
| Original draw-chunk baseline | 16 tests, 2 assertions, 15 errors |
| Final draw suite: original 16 plus new 23 safety tests | **39 tests / 176 assertions, pass** |
| Payment + Finance regression | **132 tests / 547 assertions, pass** |
| Conversion feature + unit regression | **71 tests / 259 assertions, pass** |
| Isolated conversion foundation | **29 tests / 92 assertions, pass** |
| Targeted signed orphan-evidence master tests | **2 tests / 16 assertions, pass** |
| Python source-scanner regression | **11 tests, pass** |
| Pint final | **11 files, pass** |
| PHP lint | **11 delivered PHP files, pass** |
| Vite rebuild | **Pass** |
| Static source preflight | **Pass: zero unresolved imported App classes** |
| Test-environment routes | **558 routes** |
| Full three-class master readiness | **35 tests / 127 assertions: 13 errors, 5 failures remain** |
| Legacy `tests/Feature/Settlement` baseline and final | **108 tests / 769 assertions: 72 errors and one skip remain in both** |
| Default production boot inspection | **Refused by the existing production safety provider** |

The legacy Settlement comparison temporarily restored the six pre-installment changed files while retaining the added test-schema columns and unused new files. It is a same-test-schema source comparison, not a clean baseline of every repository file. Its failures still occur mostly at the missing confirmed-result fixture boundary. They have not been deleted, skipped by this patch, or treated as passes.

The static preflight now passes because the missing finalizer implementation is present and stale imports were already repaired in installment 4. The report deliberately keeps `runtime_verified` and `live_payment_verified` false: a static pass is not whole-application or real-merchant verification.

Default production boot refuses unsafe defaults involving fixture-source mode and insecure session settings. That refusal was preserved, not bypassed. Setting `APP_ENV=testing` for read-only route inspection was an explicit test-environment action, not a production configuration change.

## Reproduce in a disposable test checkout

Verify that testing configuration points only at the disposable SQLite test database before running these commands. Database-truncating tests must never target production.

```bash
composer install --no-interaction --prefer-dist --no-scripts
npm ci --no-audit --no-fund
npm run build
php vendor/bin/phpunit tests/Feature/Draw
php vendor/bin/phpunit tests/Feature/Payment tests/Feature/Finance
php vendor/bin/phpunit tests/Feature/Wallet/Conversion tests/Unit/Wallet/Conversion
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Standalone/ConversionFoundationTest.php
php vendor/bin/phpunit tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php --filter 'idempotent_webhook_processing|payment_webhook_replay_protection'
python3 -m unittest discover -s scripts -p test_financial_source_preflight.py
python3 scripts/financial_source_preflight.py --json evidence/financial_source_preflight-part-5.json
php vendor/bin/pint --test database/migrations/2026_10_14_000200_pin_settlement_run_inputs.php app/Models/SettlementRun.php app/Services/Draw/SettlementRunGuard.php app/Services/Draw/RealPrizeSettlementService.php app/Services/Draw/ChunkedSettlementOrchestrator.php app/Jobs/Draw/SettleDrawChunkJob.php app/Jobs/Draw/FinalizeDrawSettlementJob.php app/Console/Commands/Lottery/SettleDrawChunkedCommand.php tests/Feature/Draw/Concerns/BuildsConfirmedSettlementDraws.php tests/Feature/Draw/ChunkedSettlementTest.php tests/Feature/Draw/RecordedSettlementSafetyTest.php
APP_ENV=testing php artisan route:list --json
```

The following checks are explicitly still unsuccessful:

```bash
php vendor/bin/phpunit tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php tests/Feature/RealMoneyBusinessReadinessTest.php
php vendor/bin/phpunit tests/Feature/Settlement
```

## Staging and compatibility requirements — not performed here

1. Review and apply the additive migration only after a backup and operator-approved staging plan. No production migration was run here.
2. Stop/drain or quarantine legacy six-field range/batch job payloads before updating workers. The new jobs use `(drawId, integer runId, expectedCursor)` and `(drawId, integer runId)`, not the old custom UUID/range format. Do not cast old UUIDs into run IDs or blindly retry incompatible serialized jobs.
3. Existing open/aborted runs get nullable proof fields, not invented historical evidence. They intentionally refuse resume until operator reconciliation establishes a trustworthy migration procedure. Do not delete checkpoints, reset balances, or manufacture new fingerprints from possibly changed current data to make a refusal disappear. Existing completed runs are idempotent no-ops.
4. Deploy matching code and restart workers consistently. The constructor contracts changed. No guarantee is made for mixed old/new workers.
5. Test first with DISABLED or DRY_RUN, confirmed test results, and fake/test-only merchant data. Explicitly check pending obligations and unchanged wallet balances.
6. Exercise MySQL/PostgreSQL deadlocks, concurrent delivery, repeated finalizers, mid-transaction kills, and queue restarts. SQLite tests are not proof of those production behaviors.
7. Queue continuation is at-least-once scheduling after the database commit, **not a transactional outbox**. A crash between chunk commit and next dispatch can leave an open run with no queued successor. Operator `--resume` must recover from the persisted cursor. Automatic guaranteed redispatch/outbox delivery is not implemented.
8. Required chunk/final audits are synchronous transaction participants. Failure rolls back that transaction; earlier committed chunks remain committed and visible.
9. Benchmark full input-proof scans and final recomputation on realistic draw sizes. Memory is bounded by paging, but scan time and lock duration are not certified. There is no per-draw parallel-throughput claim.

## Remaining scope

The broader master suites still have stale wallet/reconciliation/type/health contracts. The broader Settlement fixture requires the same real confirmed-result preparation applied only to the draw fixtures in this installment. Fix those contracts without disabling provenance, maker/checker, wallet/ledger, or approval controls. Whole-repository regression, live provider integration, real browser flows, queue crash recovery, and production SQL concurrency remain unverified.

Bank-transfer/manual approval remains the recommended first merchant rail, subject to operator configuration and staging approval. Payment logos do not establish integrations. Legacy member-to-member transfer remains fail-closed; own-wallet Cash↔Win conversion remains separate and its regression suite is green.

No production credentials were used or changed, no production payout was initiated, no deployment or GitHub push was performed, and no automation flag or production merchant was enabled.

## Artifact integrity

`Full-Code-Continuation-Files-50-63.md` contains all fourteen files in order, including the entire eleven PHP files and complete supplied evidence/documents. `Full-Code-All-63-Files.zip` contains the previous 49 files plus these fourteen. Packaging verifies every ZIP entry byte-for-byte against its source and verifies complete source inclusion in the Markdown continuation. `current-delivery-file-list.txt` records the complete ordered manifest. The next installment begins at File64.
