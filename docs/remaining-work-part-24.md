# Part24 — Real settlement worker death, bounded retry and durable recovery

Date: 2026-10-15. Repository base: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Continuation: File283–File303, 21 complete bodies. Cumulative delivered overlay: 260 unique paths; next File304.
Apply to the same pinned repository. This is the delivered-source overlay, not the complete repository, dependencies, database, native executable or deployment image.

## Actual application defect and fix

Part23's selected next task was worker termination/restart/retry acceptance. The new real-worker tests exposed a delivery-recovery defect, not a duplicate-money defect:

- Every queue reservation increments Laravel's attempts counter, even when `WithoutOverlapping` cannot acquire the shared lock and merely releases the job.
- Queue visibility is 300 seconds; the overlap lock lives for 300 seconds starting when it is acquired, not when the job was reserved.
- A scheduling stall between reservation and lock acquisition can therefore leave that lock alive after the reservation becomes visible again.
- With only two entry attempts or three chunk/finalizer attempts, releases every 10 seconds can exhaust the envelope **before** the orphaned lock expires. The persisted run remains open and its continuation is lost to `failed_jobs`.

The reproduced stall is 25 seconds. The original reservation is at time T, lock acquisition at T+25, lock expiry T+325. Redelivery at T+300 and subsequent 10-second releases exhaust the old entry/chunk limits before T+325. A sanitized follow-up query confirmed the failure class was `Illuminate\Queue\MaxAttemptsExceededException`.

### Implemented policy

`app/Jobs/Draw/Concerns/HasSettlementRecoveryWindow.php` supplies a **30-minute absolute retry deadline**, evaluated by Laravel when a new envelope is serialized. All three settlement job types use it:

| Job | Actual exception cap | Existing fallback tries | Existing timeout | Fail on timeout |
|---|---:|---:|---:|---|
| `ProcessPrizeSettlementJob` | 2 | 2 | 180s | true |
| `SettleDrawChunkJob` | 3 | 3 | 120s | true |
| `FinalizeDrawSettlementJob` | 3 | 3 | 120s | true |

Laravel uses `retryUntil` instead of the attempt-count ceiling for these newly serialized deliveries. This is intentional: repeated overlap releases may wait for lock expiry without being treated as failed money executions. Actual exceptions are separately capped through `maxExceptions` in the shared cache. The deadline is finite; this is not `tries=0` or an unlimited retry loop.

Releasing an existing delivery preserves its original payload and deadline. Creating a genuine next chunk/finalizer creates a fresh bounded envelope. A queue backlog longer than 30 minutes can expire an envelope before first processing; that is a visible failure requiring inspection/requeue, not a reason to silently extend its deadline forever.

The existing lock key, 300-second lock TTL, 10-second release delay, job timeouts/backoff, hard-timeout failure, canonical run identity, expected cursor and safety gates remain intact. Payout arithmetic, money/ledger transactions and finalization proof were not replaced or weakened.

## Corrected baseline

Before application changes, the valid recovery baseline completed **9 tests / 375 assertions / 3 failures, zero errors**:

- Delayed-lock chunk redelivery failed before the lock expired.
- Delayed-lock entry redelivery failed before it could repair the durable run's missing handoff.
- The new finite-deadline guard found no serialized deadline in the old payload.

The first two are the actual observed recovery defect in two job roles. The third is a new safety requirement accompanying the fix, not a claim that an old missing deadline alone corrupted funds. The other six scenarios already passed, including transactional rollback and ordinary replay after a worker died.

Early harness mistakes used a PHPUnit-reserved helper name and an incorrect Symfony signal-status method. Those were corrected before the authoritative baseline and are not application failures. A payload test initially popped a comma-separated worker priority list as though it were a single database queue and omitted the explicit queue argument on direct push; those test setup errors were corrected without changing application queue routing.

## What the real fault injection executes

The isolated server is MariaDB 11.8.6/InnoDB, accessed through a private Unix socket with networking disabled. Workers use the **shared database cache**, not the per-process array cache used for the earlier SQL-only race barrier. Both the parent and each child have separate SQL connections.

`tests/Support/SettlementRecoveryProbe.php` bootstraps Laravel only in an explicit local `testing` environment. It invokes the actual Artisan `queue:work database` command with the standard priority list, `--max-jobs=1` and `--stop-when-empty`. Therefore the normal queue middleware, reservation/release/delete behavior, exception accounting and failed-job recording execute. It is not a registered application command or route.

Test-only SQL/transaction event observers stop the child at a specified boundary using real `SIGSTOP`. The parent checks the reserved delivery and shared cache lock, confirms the child's independent connection and transaction state, then sends **real SIGKILL (9)**. A money-write stop additionally requires an active InnoDB transaction with modified rows. After death the child must own no remaining InnoDB transaction.

The test clock is accelerated deliberately: it models the 25-second reservation-to-lock stall, 300-second visibility/TTL and 30-minute deadline without waiting those durations in wall time. Queue/cache expiration decisions use the real Laravel code and database rows. SQL commits, rollback, process isolation and POSIX process death are real, not mocked. This is not a claim that each test waited five or thirty real minutes.

## Nine actual server recovery cases

| Case | Required durable outcome |
|---|---|
| Kill during a ledger insert before checkpoint commit | Uncommitted payout, finance/ledger writes and cursor roll back; fresh workers then settle once |
| Kill after checkpoint commit but before handoff publication | Existing credit/cursor remain; redelivery repairs the missing continuation without paying again |
| Kill after continuation enqueue but before original acknowledgment | Original reserved delivery and continuation both persist; duplicates converge, queue drains and money moves once |
| Kill after finalization commit but before acknowledgment | Completed proof revalidates; replay does not create another payout or change balances |
| Kill after a manual-approval checkpoint | One pending obligation remains; restart/finalization do not credit it |
| Delayed-lock chunk crash | Redelivery survives overlap releases beyond the old tries ceiling and recovers after lock expiry |
| Delayed-lock entry crash after run creation | Entry retry hands off the persisted run instead of failing early or restarting a monolithic writer |
| Delivery past its absolute deadline | Delivery visibly fails without settling a bet or creating a payout |
| Repeated actual job exceptions | Three fresh worker processes exhaust the chunk exception budget; no payout or progress is fabricated |

All recovery money fixtures are synthetic THB; these cases are not a payout-recovery claim for every denomination or jurisdiction. Three bets cost 30.00 from a 500.00 wallet, leaving 470.00. The single winning prize is 9000.00. Recovered LIVE cases finish at **9470.00**, with exactly one payout, one added financial transaction and two added ledger entries. The manual-approval case finishes at **470.00**, with one pending payout and no new payout financial/ledger movement. Chunk-size-one runs complete all three bets in three checkpoints; the entry's persisted default chunk completes all three in one checkpoint.

Seven cases kill actual workers and require one exact validated kill/recovery witness each. The other two intentionally create one failed delivery to prove finite failure policy; those expected failed deliveries are not concealed as an all-zero failed-job result. The seven recovered cases require zero failed jobs and a drained queue.

The separate three-case queue payload test proves that every job type serializes its deadline and exception cap, keeps its timeout/fail-on-timeout policy, and preserves the exact payload/deadline through release and redelivery. These serialization tests are not substituted for the actual server death tests.

## Guarded runner and continuous execution

`scripts/run_settlement_recovery.py` reuses the exact local socket/database/acknowledgment protections of the financial concurrency runner. It requires Linux POSIX/pcntl, prepared InnoDB financial/cache tables, uncached configuration and locked PHP test dependencies. The JSON output must be under the repository's evidence directory.

Success requires all nine exact JUnit identities without skips/errors/failures, plus seven correctly matched kill witnesses. Witness validation rejects missing/duplicated cases, wrong phases, non-SIGKILL signals, array cache, incorrect transaction boundaries, nonpositive/aliased process or SQL connection IDs, and a missing recovered terminal state. Witnesses contain test/phase/process/connection identifiers only, not customer data, amounts, keys, SQL or credentials.

The existing financial runner now accepts an additional prepared-table requirement used by recovery preflight; its original 17-case profile remains intact. The dedicated financial CI workflow runs the old 17 races and the new nine recovery cases **serially**, three times each, on the private socket-only instance. It uploads both JSON reports and still shuts down the dedicated instance. POSIX extensions and offline recovery-evidence guards are declared explicitly.

Workflow YAML, all Bash run blocks, execution order, explicit acknowledgment, evidence-upload and least-permission invariants pass locally. The hosted workflow itself has not been executed in this conversation. MySQL8, Redis/SQS, network partitions, separate-host failover and a production supervisor topology are not established by this local MariaDB/database-cache acceptance.

## Verification results

| Check | Final observed result |
|---|---|
| Complete default regression, native verifier available | 2537 tests / 130480 assertions; zero errors/failures; 23 server-only skips; exit 0 |
| Real worker recovery on MariaDB/shared database cache | Three repetitions, each 9 tests / 444 assertions PASS; zero skips; seven exact kill/recovery witnesses each |
| Refreshed 17-case financial SQL races | Three repetitions, each 17 tests / 347 assertions PASS; zero skips; 13 parent-specific contention witnesses each |
| Three new serialized-payload contracts plus earlier queue pipeline | 17 tests / 127 assertions PASS |
| Offline runner guards | 22 tests PASS |
| All offline Python guards | 48 tests PASS |
| Unchanged native Rust crate | 27 tests PASS again; release binary rebuilt |
| PHP syntax | 1660 files PASS |
| Pint | Seven files PASS |
| Frontend build | PASS |
| Financial source preflight and passive inventory check | PASS; no missing imported application classes or stale inventory outputs |
| CI workflow | YAML/all Bash/serial execution/acknowledgment/POSIX/evidence invariants PASS; hosted execution not performed |

The 23 SQLite skips are the 14 previous server-only cases plus nine new worker recovery cases. All 23 execute separately without skips in this part's MariaDB profiles; the other three purchase races in the 17-case profile also run on the server. These profiles contain 26 distinct selected cases, not 26 newly added tests. Repeated runs are not added to the unique case count. No database-writing suites overlapped.

Machine-readable reports: `evidence/server-recovery-part-24.json` and `evidence/server-races-part-24.json`. Complete baseline/final stdout and scoped static/build results: `evidence/runtime-part-24.txt`.

The passive release inventory now hashes the recovery job/trait/harness/runner sources and points its runtime/financial reports at the separately executed Part24 evidence. It remains a static inventory, not a production certificate. Part23 native-integrity sources/evidence remain unchanged; the native binary was rebuilt for this part's PHP runtime.

## Reproduction — disposable local data only

Restore the pinned repository and cumulative overlay. Install locked Composer/npm dependencies, build assets and the pinned Rust release verifier as in Part23. Use PHP 8.4 with PDO MySQL/SQLite, GD, intl, pcntl, posix and the other Composer-required extensions.

Provision a **fresh dedicated** socket-only MariaDB instance, exact database `thai_lottery_test`, and a test-only account with schema privileges plus PROCESS metadata visibility. The complete provisioning procedure is in the delivered financial CI workflow. Never grant the fixture runner access to an operational/shared production instance. The tests migrate/refresh/truncate their acknowledged test schema.

Export the absolute isolated `DB_SOCKET`, dedicated `DB_USERNAME`, environment-only test `DB_PASSWORD`, `APP_ENV=testing`, `DB_CONNECTION=mariadb`, `DB_DATABASE=thai_lottery_test`, `DB_URL=''` and an ephemeral valid `APP_KEY`. Ensure there is no cached application configuration. Migrate only this disposable schema, then run from the repository root:

```bash
python3 -m unittest discover -s scripts -p 'test_run*py' -v
python3 scripts/run_financial_concurrency.py \
  --suite all \
  --database thai_lottery_test \
  --connection mariadb \
  --socket "$DB_SOCKET" \
  --username financial_test \
  --acknowledge-disposable-database \
  --repeats 3 \
  --json evidence/server-races-local.json
python3 scripts/run_settlement_recovery.py \
  --database thai_lottery_test \
  --connection mariadb \
  --socket "$DB_SOCKET" \
  --username financial_test \
  --acknowledge-disposable-database \
  --repeats 3 \
  --json evidence/server-recovery-local.json
```

Do not overlap database-writing suites. Stop the dedicated server afterward. Passwords are environment-only and no live provider account is needed or called. Runtime SQL files/socket/PID/logs are private cache data, not delivered artifacts.

## Deployment and existing-envelope compatibility

- Stop/restart long-lived workers through the operator's normal safe deployment procedure so they load the new job policy. Configure a shared durable cache; array cache cannot provide cross-process overlap locks or exception counters.
- This source update does **not** rewrite already serialized queue records. Old envelopes retain their old `retryUntil`/`maxExceptions` metadata. Inspect and drain/quarantine them before relying on the new policy.
- Do not blindly run `queue:retry all`: this Laravel version refreshes `retryUntil` from the updated object but does not automatically fill a legacy null `maxExceptions` payload field. A reviewed fresh canonical delivery is preferable to assuming every old field was upgraded.
- The existing `lottery:settle-draw-chunked --draw="$DRAW_ID" --status` is read-only. A fresh `--queue` handoff for a reviewed open run uses its persisted cursor and the new policy. `--resume --queue` explicitly reopens an eligible aborted run through its canonical gates. These latter operations can move money under LIVE and require an authorized operator's deliberate choice; they were not executed against customer data here.
- Preserve failed-job audit history and inspect canonical run, payout and ledger state. Never reset the cursor, delete payout/ledger rows, force-release another worker's live lock, or auto-resume a deliberately paused run to make a queue look clean.
- `failOnTimeout=true` remains intentional. A worker's handled hard-timeout failure still needs inspection/operator recovery; the tested unexpected SIGKILL/reservation-redelivery path is not a claim that every intentional failure is automatically resumed.

## Preserved financial and worldwide boundaries

Own-wallet conversion, disabled member transfers, fee economics, currency handling, manual prize approval, canonical result/participant proofs and jurisdiction/provider permissions remain authoritative. No country, currency or provider was automatically enabled. No merchant call or customer transfer was executed.

This closes the selected local worker-death/restart gap and fixes the stale-lock retry defect. The next independent operational acceptance should exercise the selected deployment's actual shared broker/cache topology (for example Redis with separately supervised workers), restart/visibility behavior and broker failure/reconnection, while preserving these money assertions. A local Unix-socket process test is not multi-host or worldwide operating approval.

Worldwide operation still requires an approved operator/jurisdiction/provider/currency combination, authentic official-source material and authorized live-provider reconciliation. No approval or secret was supplied by these synthetic fixtures.

## Delivery integrity

All 21 bodies are complete. Ten previously delivered paths are revised; the other 239 prior bodies remain byte-identical. Eleven paths are newly delivered. ZIP CRC, unique entries, full workspace equality, complete Markdown body extraction and SHA256 manifests are checked.

The cumulative 260-file ZIP is an overlay against the pinned repository. Runtime environment files, credentials, signing private keys, SQL stores, process diagnostics, dependencies, generated assets, compiler caches and native binaries are excluded. Only prior secret-free environment templates remain in the overlay.
