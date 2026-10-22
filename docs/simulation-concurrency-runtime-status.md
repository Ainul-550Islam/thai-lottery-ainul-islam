# Non-monetary simulation concurrency runtime — f41

Historical **f41** checkpoint. F42 subsequently completes the missing manual cursor contract;
current full/expanded runs are green locally, not deployment-certified. See `docs/manual-cursor-settlement-status.md` and
`docs/ci-regression-status.md` for measured local results and unchanged deployment boundaries.

Verified 2026-10-14 on PHP 8.4.26. This addresses the remaining documented simulation-concurrency
runtime prerequisite, not the fifteen missing cursor/LIVE backend expectations. No production money
service, scheduler, approval/KYC policy, result provider or wallet-credit path is changed.

## Baseline was measured

The original point-20 test genuinely skipped (`/home/user/simulation-race-baseline-f41.log`). Its guard
rejected every SQLite driver as “in memory”, even though this project's default is the shared
`database/thai_lottery_test` file with the previously verified `IMMEDIATE` mode and applied 15,000 ms
busy timeout. SQLite memory isolation was a valid reason to refuse a memory database, not a valid
description of the configured file-backed environment.

The old harness also generated a temporary shell-command probe, waited a fixed 400 ms instead of
receiving readiness, inherited implicit child database settings and returned process exit 0 even
for refused results. Those probe limitations are replaced, not hidden behind weaker assertions.

## Measured focused result

- The actual former skipped test now passes **1 test / 48 assertions**, using two independent PHP
  processes and separately booted PDO connections (`simulation-race-green-f41.log`).
- Five additional independent repetitions each pass **1 test / 48 assertions**
  (`simulation-race-repeated-f41.log`). Repetitions are stability evidence, not additional unique coverage.
- Complete settlement feature/unit scopes: **295 tests / 91,058 assertions, all pass, no skips**
  (`simulation-focused-f41.log` and matching JUnit XML).

Expanded regression: **1,394 tests / 106,925 assertions — all pass, no skips**.
Unfiltered repository: **2,507 tests / 113,657 assertions — 15 errors, 0 failures, 0 skips**.
The exact fifteen original backend failing names are unchanged; no new failure is introduced. Both
historical runtime skips now genuinely execute, but zero skips is **not** a green-full-suite claim.
Evidence: `part3-focused-f41.log`, `full-f41.log` and matching JUnit XML. Detailed comparisons are in
`docs/ci-regression-status.md` and `docs/full-suite-failure-classification.json`.

## Admission guard remains

`requiresRealConcurrency()` still refuses unsupported environments. A test-only policy admits:

- the existing allow-listed MySQL/MariaDB/PostgreSQL test profiles; or
- SQLite only when the disposable file actually exists inside the project's database directory,
  is not a symlink/URI/memory store, is named `thai_lottery_test`, uses `IMMEDIATE`, and has a positive
  integer busy timeout. The actual connection PRAGMA is checked too.

Memory/URI stores, production/unknown database names, external/missing test files, deferred mode,
unsupported drivers and missing/invalid waits remain refused. Negative tests exercise those cases;
this is not deletion of the skip guard. Only the supported default file-backed lane now executes.

SQLite evidence here is **single-writer transaction serialization**, not server row-level locking,
parallel money throughput, cross-host database certification or a MySQL/PostgreSQL runtime claim.
The server profiles remain admitted as before but were not exercised locally in this follow-up.

## Genuine process/barrier witness

1. The parent provisions real purchased selections and a published disposable result through the
   existing pipeline, then photographs every financial row.
2. It starts two `Symfony Process` argument-array invocations, not shell command strings. The worker
   is test-only and is not registered as an application/operator command.
3. Database/rule configuration travels on stdin, not in command-line arguments or generated source.
   Only a testing environment and admitted disposable database can pass before Laravel bootstrap.
   Cached deployment configuration is refused. URLs cannot redirect the admitted connection.
4. Each worker opens its own actual connection and acknowledges a unique token after bootstrap.
   The parent releases the barrier only after both acknowledgments, without the old fixed boot sleep.
5. Child PIDs are captured at start and checked against reported PIDs; both must differ from each
   other and the parent. Both report the same shared database identity.
6. Exactly one reports `settled`, the other `already_settled`. Both return the same outcome fingerprint
   and exactly **9,900.00 simulated prize**, not 19,800.00. Only two selections/two bets are written total,
   one audit row exists, and the disposable draw reaches its simulated terminal state.
7. The first worker's observed mutation set is exactly `draws`, `bets`, `bet_items`, `audit_logs`.
   Replay records **zero INSERT/UPDATE/DELETE**, not merely equal row counts.
8. All complete rows in the nine financial tables remain identical. Child errors return non-zero,
   sanitized codes/types and no raw query bindings, exception messages or credentials.
9. Timeout/failure cleanup stops remaining children and removes the unique temporary barrier files.

Simulation here is the **existing** non-monetary service: it records decisions and advances reporting
states on disposable bets/draws. It is not f37's read-only preview, which continues to leave those
source states unchanged. Neither service creates a payable obligation, wallet credit or gateway transfer.

## Harness defect found while enabling the witness

The first new harness attempt correctly failed on a self-lock: `Connection::getConfig()` included
its old runtime `name`. Copying that into a new child connection alias made hydrated models bind a
second PDO while the first PDO held its transaction. The timeout/error was observed, not suppressed
(`simulation-race-debug2-f41.log`).

Normalization now strips inherited `name` and URL before creating the worker alias. The transaction
and hydrated model writes use the same intended connection. A unit regression asserts that the old
name cannot survive normalization. Production transaction/service logic was not changed to make this pass.

Driver money formatting is normalized with the existing BCMath assertion: SQLite's raw numeric sum
9,900 is compared exactly to 9,900.00, rather than requiring the MySQL display spelling. The expected
amount, exact-one audit/write assertions and replay financial protections are retained/strengthened.

## Complete files — no elision

| Complete path | Lines |
|---|---:|
| `tests/Feature/Settlement/SettlementTestCase.php` | 663 |
| `tests/Feature/Settlement/SettlementSimulationTest.php` | 903 |
| `tests/Support/SimulationConcurrencyEnvironment.php` | 99 |
| `tests/Support/settlement-simulation-worker.php` | 123 |
| `tests/Unit/Settlement/SimulationConcurrencyHarnessTest.php` | 199 |
| **Total** | **1,987** |

All five files are delivered beginning to end, with no omitted fixture method or assertion. Earlier
production sources and all original cursor/LIVE tests remain unchanged.

## Reproduce

After the existing PHP/dependency/frontend/native runtime preparation:

```bash
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/Settlement tests/Unit/Settlement
```

For the specific two-process witness:

```bash
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/Settlement/SettlementSimulationTest.php \
  --filter point_20_lets_only_one_of_two_concurrent_settlements_write
```

No deployed `.env`, database schema, source data, permission or financial idempotency is altered by
this source change. Actual test execution uses only the guarded disposable test database. Do not
relabel this local non-monetary race as LIVE payout, bank/gateway, load or cross-host certification.
The original fifteen monetary cursor/LIVE errors remain separately unfinished under the standing boundary.
