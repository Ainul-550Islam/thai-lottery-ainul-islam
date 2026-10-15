# Local full regression green — manual checkpoints and deployment boundaries

Verified on **2026-10-15** using PHP 8.4.26 and prepared native runtime. F42 completes the missing
manual cursor API over the existing range engine with atomic checkpoints and frozen witnesses.
**The measured unfiltered local repository is fully passing.** No live deployment, real external
transfer, scheduler/CLI/job activation or production mode change is inferred from that result.

## Measured results

| Run / scope | Tests | Assertions | Errors | Failures | Skipped |
|---|---:|---:|---:|---:|---:|
| Earlier full baseline, `full-f31.log` | 2,266 | 111,276 | 116 | 38 | 2 |
| Runtime/guard follow-up, `full-f34.log` | 2,297 | 111,822 | 46 | 13 | 4 |
| Previous full run, `full-f35.log` | 2,299 | 111,903 | 45 | 6 | 2 |
| Previous full run, `full-f36.log` | 2,302 | 112,306 | 15 | 0 | 2 |
| Previous full run, `full-f37.log` | 2,355 | 112,833 | 15 | 0 | 2 |
| Previous full run, `full-f38.log` | 2,424 | 113,167 | 15 | 0 | 2 |
| Previous full run, `full-f39.log` | 2,450 | 113,293 | 15 | 0 | 2 |
| Previous native-enabled full run, `full-f40.log` | 2,486 | 113,500 | 15 | 0 | 1 |
| Previous no-skip full run, `full-f41.log` | 2,507 | 113,657 | 15 | 0 | 0 |
| **Final unfiltered run, `full-f42.log`** | **2,538** | **113,966** | **0** | **0** | **0** |
| Previous expanded regression, `part3-focused-f41.log` | 1,394 | 106,925 | 0 | 0 | 0 |
| **Current expanded regression, `part3-focused-f42.log`** | **1,441** | **107,236** | **0** | **0** | **0** |
| **Manual checkpoint + inventory scope, `manual-focused-f42.log`** | **51** | **315** | **0** | **0** | **0** |
| Original cursor acceptance class, source unchanged | 16 | 76 | 0 | 0 | 0 |

**All 51 originally failing f35 names now pass**, including all fifteen original cursor/LIVE cases.
The original core test/assertions are unchanged; no error is skipped, renamed or replaced with a
success alias. Both prior runtime skips still genuinely execute. Full/focused/expanded exits are **0**.

The first F42 full run resolved those errors but found one queue-inventory failure after retiring the
old producer. Its complete measurement is retained as `full-f42-prequeue.log` (not final green evidence).
The contract's explicit-deferred path now truthfully lists the permanently refusing job, with a new
runtime refusal assertion; no producer or automatic activation is added to satisfy the inventory scan.

Three extra independent manual concurrency repetitions pass (each 2 tests / 46 assertions), verifying
shared run/cursor contention in disposable accounts. They are stability evidence, not summed new coverage.

Other verified checks:

- The original 717-line cursor test SHA-256 remains
  `66f1514dff139d6c82f54e73240e9fe1deddb04fc1956868b5d92abc9ae31b57`.
- Checkpoint/midrange fault injection proves wallet, payout, journal, bet and progress rollback together.
- Locked native build/29 Rust release tests remain passing runtime preparation.
- Standalone restorer: **10 Python tests pass** (`bundle-tests-f42.log`).
- **125 complete PHP/Blade members** pass syntax (`php-lint-f42.log`).
- Pint: **13 changed/new PHP files pass**; scoped whitespace passes. Native/YAML/Bash prerequisites remain intact.
- PHP preflight, Composer validation/platform requirements, locked installs and frontend build pass.
- No hosted CI, production/backup/operational migration, real provider transfer, load or cross-host certification is claimed.

## F42 missing manual contract closure

Existing `settleBetRange()` and `finalizeChunkedSettlement()` already supplied the payer/finalizer logic.
The missing start/run/runChunk/resume/remaining/default-size APIs now coordinate that engine rather than
introduce another payer. Typed internal callbacks attach DRAW/RUN admission and checkpoint writes
inside the range's owned transaction. Bookkeeping failure cannot leave committed money behind an old
cursor; stale callers acknowledge stored progress before financial work.

New nullable server-owned snapshot fields and per-bet witnesses capture result/rules/approval policy,
mode/size, immutable purchase inputs and output/journal integrity. Historical rows are not backfilled
or activated. Conservative source/prior-output checks run before new work and finalization; exact
counts/totals are verified before the existing finalizer and completed run commit together.

High-value approval and non-LIVE suppression remain the existing logic. Source/journal/policy tampering,
wrong/future cursor, incomplete finalization and caller transactions refuse. Actual PHP/PDO contention
proves one run/set and one credit/journal/checkpoint for shared stale cursors in synthetic test accounts.

Legacy CLI stays status-only, the queued job remains permanently refusing, and the old dispatcher
`settle()` explicitly refuses. No route/schedule/gateway or production LIVE flag is enabled. Manual
local credit code/test availability is not deployed automation or real customer-fund transfer. Conservative
rechecking is deliberately not a 100000-slip/seconds/load claim. See `docs/manual-cursor-settlement-status.md`
for all complete source files, exact limitations and the original unchanged acceptance contract.

## F41 non-monetary concurrency runtime closure

The original guard incorrectly called all SQLite in-memory despite this repository's file-backed
IMMEDIATE default. The replacement admits only the actual shared disposable file, positive applied
wait and correct mode; unsupported configurations remain refused and have negative coverage.

The generated shell/fixed-sleep probe is replaced with two separately booted PHP/PDO workers, stdin
configuration, URL/name normalization, unique token readiness, exact PID/storage identity checks,
non-zero sanitized failure and guaranteed cleanup. The inherited connection-name self-lock discovered
during harness development was fixed in the harness, not worked around in production money code.

Exactly one simulation writes the reporting tables and audit; the other replays without any mutation
SQL. Both report the same fingerprint/9,900.00 simulated total; only two selections/two bets are written,
one audit exists and every financial row stays identical. Raw driver formatting is normalized without
changing the expected amount. This is **SQLite single-writer serialization**, not server row locks.

Only test/harness files change. The existing simulation's reporting-state transition is not f37's
read-only preview, and neither is LIVE wallet credit. See `docs/simulation-concurrency-runtime-status.md`
for five complete files (1,987 lines), the measured old skip and runtime/guard limits.

## F40 native runtime and signature-material closure

The real missing-native skip was observed before provisioning. A locked non-monetary Rust build now
provides the configured executable; the complete unchanged Weekly class runs without a skip. The build
script fixes manifest/host/target, runs formatting, strict Clippy and release tests, verifies the lock
checksum, and atomically installs the real host binary. No application import or money worker runs.

Thirty new PHP runtime cases require the real subprocess; six build/CI contracts enforce preflight
ordering before unfiltered PHP gates. New Rust CLI tests verify exact exits, size/UTF-8 limits, leading
zeroes, numeric JSON refusal, fingerprint mismatch and public synthetic-key signatures.

Execution also exposed a PHP boundary defect: key-only or signature-only requests were stripped to
unsigned HASH_ONLY. Each supplied half is now forwarded so the existing Rust incomplete-material
refusal is preserved. Neither provider signing credentials nor production required/fallback settings
are changed. A fixture SIGNED_VERIFIED is not a real source-authority certificate.

Compiled target/cache artifacts are not bundled or snapshot-persisted. Rebuild before the full/default
PHP suite after a reset; mandatory runtime tests fail rather than hide missing preparation. See
`docs/native-verifier-runtime-status.md`, `RUST-RUNTIME-REPORT.md` and the restore/build usage document.

## F39 queued-job composition and failure-accounting closure

`SettleDrawChunkJob` previously redeclared Laravel's mutable `Batchable::$batchId` as a promoted
readonly string, making class composition fatal. It now preserves the six producer parameter names
while storing producer correlation in readonly `planId`; only the framework owns real `batchId`.
Current payload serialization and a real database-backed batch verify both identities remain separate.

The handler resolves no money dependency and always throws a dedicated permanent refusal. Missing
per-bet/fingerprint callees are retired, not implemented as new payout automation. New payloads carry
one-attempt limits; old queue envelopes are not silently migrated or replayed.

Real database queue and one-job worker tests observe one JobFailed, no JobProcessed success, no released
retry, correct actual-batch failure accounting, no success callback and unchanged rows in 20 business/
preview tables. Queue bookkeeping legitimately changes queue tables. A normal worker exit 0 or a
cancelled batch's finished_at stamp is explicitly not used as proof of a successful job/settlement.

Only the legacy job changes existing production behavior; a new RuntimeException carries the safe
refusal. Money services, cursor orchestrator/model, scheduler, original tests and fixture flags are
unchanged. The unsupported planner/finalizer/fingerprint contract remains a release blocker. See
`docs/legacy-chunked-job-status.md` for all four complete PHP files (589 lines), privacy and scope limits.

## F38 legacy CLI status/caller closure

Before changing the command, three new regressions produced one missing-`runFor()` error and two
failures: queue execution reached missing `start()`, and `--all-runs` succeeded without `--status`.
Afterward, all 69 expanded component tests pass. The full initial failure details remain in
`legacy-cli-red-f38.log`; these were observed defects, not hypothetical risk labels.

The existing `lottery:settle-draw-chunked` now supports only explicit read-only status. It never
resolves the cursor orchestrator or monetary service, invokes the separate preview, creates a run,
starts a writer transaction or dispatches an invalid job. Every execution variant is deliberately
non-zero, including under LIVE/automation flags; tests observe zero database queries for those calls.

Status reports come directly from stored rows and actual current counts. Their financial effects are
explicitly **unverified**. Unknown denominators remain null, overrun/invalid counters are not clipped,
invalid raw amounts are not rounded, and sensitive failure/operator data is not exported. Bad timestamp
rows stay visible with issue codes. Lists explicitly report observed totals, a 200-row cap and truncation.

All rows in 23 business/preview/queue tables remain unchanged; real query traces and poisoned monetary
bindings accompany the snapshots. Buffered console output is captured once so privacy negatives cannot
pass by inspecting an already-drained output buffer.

Only the existing legacy console command changes production behavior in f38. Core money services,
legacy orchestrator/job/model/test, scheduler, fixture flags and all f37 delivered PHP sources remain
unchanged. The invalid CLI dispatch caller is retired, **not implemented as a new LIVE path**. The
missing finalizer/per-bet/fingerprint wiring were release blockers at that historical checkpoint.
F42 retires the obsolete dispatcher and completes the manual contract; queued/CLI activation remains
refused. See the historical command document and current manual status for distinct scope.

## F37 safe partial closure — not LIVE orchestration

`ChunkedSettlementPreviewService` and `lottery:preview-settlement` persist only two new audit tables.
They never write wallet, ledger, payable obligation, bet, result or draw state and never dispatch a
job. Real query traces and complete before/after rows across 18 business tables prove that write set.
Mode is a constant, not a configuration switch or argument. The policy fingerprint includes only the
actual multiplier-source field, not the block containing payout automation switches.

Snapshot identities, exact input/result/rule fingerprints, expected-cursor replay, bounded keyset
hydration, mid-chunk rollback, unchanged-context resume, exact totals and final completion checks are
covered. Two genuinely separate PHP processes prove single capture and single commit for a shared
expected cursor. Empty participation reports a null percentage; preview completion leaves the draw
published. Migration up/down is exercised against an independent disposable schema.

Active non-deleted bets alone form the audit scope. A preview is not the unique financial run owner
for its draw and does not replace the old queue/settlement API. Capturing new inputs creates a new
versioned audit snapshot, not an overwrite of an old report. Current/historical status is explicitly
labelled as a stored snapshot rather than live certification.

See `docs/chunked-settlement-preview-status.md` for every complete file, the operator command, all
15 legacy expectation mappings, additional broken queue/fingerprint wiring and performance/privacy
limits. F37 adds twelve complete PHP files (2,220 lines); previous production sources remain intact.

## SQLite concurrency defect closed

`DB_BUSY_TIMEOUT` was already declared for testing but `config/database.php` left SQLite's
`busy_timeout` unset. Its deferred transactions could both read a snapshot, then fail immediately
while upgrading to a writer at `number_limits`, despite the purchase service's bounded retry budget.

SQLite now uses:

- `transaction_mode => 'IMMEDIATE'`: reserve the sole writer at transaction entry, before reads;
- `busy_timeout => max(0, (int) env('DB_BUSY_TIMEOUT', 15000))`: apply the actual configured wait.

Tests verify the configured timeout reaches `PRAGMA busy_timeout`, a new connection honors an explicit
override, and two independent connections contend at `BEGIN` itself before any row is read. Releasing
the first transaction lets the second acquire its writer reservation.

The original AB2 assertion is stronger, not weaker: exactly one `purchased` and one `replayed`, the
same bet/ticket/transaction identities, one bet item, one ticket, one transaction, one balanced ledger
pair, one wallet debit and one risk-capacity increment. All 50 purchase-atomicity tests pass.

This is SQLite writer serialization on the supported PHP 8.4/Laravel runtime. It is not a throughput
benchmark, a cross-host guarantee or certification of PostgreSQL/MySQL/MariaDB money concurrency.
The MySQL, MariaDB and PostgreSQL connection behavior is unchanged.

## Readiness contracts corrected without rewriting money services

| Area | Current supported contract / fixture |
|---|---|
| Wallet credit/debit | `Wallet`, exact `Money`, `FinancialTransactionType`, idempotency key and options; not old integer/string overloads |
| Deposit/webhook replay | Real pending deposit plus normalized `WebhookPayload`; assert unchanged transaction and ledger counts on replay |
| Signed payment integration | bKash uses a BDT wallet, faked token/checkout HTTP and real HMAC verification on the webhook route |
| Reconciliation | `reconcile()` and its actual report; exact totals, ten checks, no discrepancies where a clean report is expected |
| Stamp duty | `breakdown()`, `dutyFor()` and `netAfterDuty()`; exact ceiling rule and explicit zero income tax |
| Claims | Actual private KYC evidence, published-result fixture and `submit/reviewEligible/approve`; canonical manual payment recording is separately tested |
| Reservations | `WalletReservationData`, `locked_balance`, real consumption and no second debit on replay |
| Withdrawal failure | Request does not pre-debit; approval reserves; failure releases the hold without creating a compensating credit |
| Bank-transfer dispatch | Existing/default-disabled rail refuses before an outgoing request; no invented successful bank payout |
| Bulk slips | Canonical market keys, an open window and explicit number limits; valid items after a refused item still purchase, then replay without charging |
| KYC | Real private upload, separate reviewer and protected `kyc_status`; no mass-assignment exception or gate bypass |
| Single-agent journal | Explicit wallet, legitimate funded/purchased player bet, already-accrued fixture and typed commission status; no multi-level accrual or scheduler |
| Self-exclusion | `request/activate/hasActiveExclusion`; the actual enforcement facade refuses betting |
| Health/legal | Application liveness is `healthy`; legal versions/effective dates remain stable when the test clock advances |
| Historical lanes | All National, Weekly, Bingo and PCSO references are independently provisioned; no fabricated result version |

`CanonicalReadinessTestCase` uses disposable-database truncation so services own real transaction
boundaries. It blocks stray provider HTTP and uses fake private/public storage. It does not make
sensitive attributes fillable or add compatibility methods to production services.

Only **`config/database.php` changed existing production runtime behavior in f36**. F37 adds the
isolated audit service/models/schema/CLI; existing purchase, wallet, withdrawal, KYC, settlement,
payout and commission service implementations remain unchanged.
Existing manual settlement methods are exercised in test-only data; this does not implement or enable
a public claim-to-wallet bridge, deployed payout automation or a new permission bypass.

## Existing CI and safety closures retained

1. PHP workflows request 8.4, matching Composer `^8.4`, and declare intl, GD/JPEG and database drivers.
2. The standalone runtime preflight catches missing extensions and GD without an actual JPEG encoder.
3. CI copies `.env.ci`, not the development template; key generation follows Composer and failures
   are not hidden with `|| true`.
4. `.env.production.example` keeps credentials blank, fixture lanes off and payout/commission
   automation disabled. It is a provisioning checklist, not a working deployment.
5. Production rejects normalized fixture-priority entries as well as fixture mode/fall-through.
6. Provider health mirrors the actual result-source order without fetching or consuming breaker probes.
7. Webhook routes require the named `webhook` throttle, including the Redis-backed middleware variant.
8. Open-draw player fixtures, translated titles, public `reserved_balance`, independent maker/checker
   bottom-two `09`, and distinct Filament/legacy user-list routes remain covered.
9. The four explicit labelled-fixture flags in `phpunit.xml` are unchanged; production guards are not disabled.

Hosted CI, its MySQL/Redis lanes, dependency-audit services and operational backup/restore were not
executed here. The isolated native Rust build/runtime was executed locally in f40. Local contract tests are not a completed hosted CI run.

## Full-suite issue groups — all previous rows retained

| Class | f35 errors / failures | f42 errors / failures | Current state |
|---|---:|---:|---|
| `Draw\ChunkedSettlementTest` | 15 / 0 | **0 / 0** | All 16 original tests pass; manual service contract implemented |
| `P0P1P2ComprehensiveEnterpriseSuiteTest` | 9 / 0 | **0 / 0** | All 13 tests pass |
| `BusinessCriticalInvariantTest` | 5 / 3 | **0 / 0** | All 15 tests pass |
| `FinalProductionReadinessTest` | 4 / 2 | **0 / 0** | All 12 tests pass |
| `FinalWholeSystemNoSkipTest` | 6 / 0 | **0 / 0** | All 10 tests pass |
| `RealMoneyBusinessReadinessTest` | 6 / 0 | **0 / 0** | All 8 tests pass |
| `Betting\BetPurchaseAtomicityTest` | 0 / 1 | **0 / 0** | All 50 tests pass; AB2 separately repeated eight times |
| **Total** | **45 / 6** | **0 / 0** | **All 51 originally failing names resolved** |

`docs/full-suite-failure-classification.json` records an empty current error/skip set and all **51**
resolved original cases with complete old details, current assertions and both resolved runtime skips.
No original failing row is omitted or relabelled fixed by an unsupported contract.

There is no failing/skipped local test remaining in the final measured run. The historical remaining
cursor class expected real unique ownership, exactly-once credit, pinned mode, cursor resumption and
incomplete-finalization refusal; those are now implemented/tested without changing its assertions.
A new exact lifecycle negative test also eliminates the old broad-Throwable missing-method ambiguity.

Native and non-monetary simulation runtime witnesses remain passing. Operational review, hosted CI,
backup/deployment/load/real-provider/cross-host evidence are separate and not represented as complete.

## Reproduce the expanded passing regression

After PHP preflight, locked dependencies/frontend build and
`bash scripts/build-weekly-native-verifier.sh`, run from the project root:

```bash
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Unit \
  tests/Feature/Auth \
  tests/Feature/Home \
  tests/Feature/PublicSite \
  tests/Feature/PublicPages \
  tests/Feature/Payment \
  tests/Feature/Glo \
  tests/Feature/Production \
  tests/Feature/Locale \
  tests/Feature/Parity \
  tests/Feature/WeeklyLottery \
  tests/Feature/Settlement \
  tests/Feature/Draw \
  tests/Feature/Admin/AdminUserRouteIsolationTest.php \
  tests/Feature/Api/V1/Batch7ApiSurfaceTest.php \
  tests/Feature/Player/PlayerFrontendModulesTest.php \
  tests/Feature/Player/PlayerExperienceComprehensiveTest.php \
  tests/Feature/Pages77To100StaticContractTest.php \
  tests/Feature/Pages150To250StaticContractTest.php \
  tests/Feature/Betting/BetPurchaseAtomicityTest.php \
  tests/Feature/BusinessCriticalInvariantTest.php \
  tests/Feature/RealMoneyBusinessReadinessTest.php \
  tests/Feature/FinalProductionReadinessTest.php \
  tests/Feature/FinalWholeSystemNoSkipTest.php \
  tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php
```

The unfiltered command remains `php -d memory_limit=-1 vendor/bin/phpunit`; its final measured exit
is 0, without suppressing failures or selecting away tests. Use `/usr/bin/php8.4` in this sandbox rather than the old static binary.

## Scope and complete-file delivery

No deployed/unattended payout automation, external transfer, multi-level referral commission, fabricated
campaign/counter or affiliation/marketing claim is introduced. Manual code and disposable tests do not
authorize deployment, production flags, real funds or a new public/scheduled invocation surface.

The refreshed bundle contains complete files, not patches or shortened excerpts. See
`docs/manual-cursor-settlement-status.md`, the historical
`docs/simulation-concurrency-runtime-status.md` and
`docs/native-verifier-runtime-status.md` and
`docs/legacy-chunked-job-status.md` and
`docs/legacy-chunked-command-status.md` and
`docs/chunked-settlement-preview-status.md` and
`docs/sqlite-concurrency-readiness-status.md`, `docs/part3-bundle-files.txt` and
`docs/part3-bundle-usage.md`. Per-file hashes and explicit deletions protect restoration after resets;
dependencies, builds, databases and deployment `.env` remain outside the archive.
