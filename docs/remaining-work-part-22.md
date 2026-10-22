# Part22 — Actual InnoDB wallet and monetary settlement contention

Date: 2026-10-15. Repository base: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Continuation: File246–File257, 12 complete bodies. Cumulative delivered overlay: 231 unique paths; next File258.
This is the delivered-file overlay for the same repository, not a replacement for the full repository, locked dependencies or generated assets.

## What is now implemented and exercised

The previous release's main missing financial verification was equivalent shared-server locking. Part22 adds actual own-wallet conversion and actual monetary settlement races. These call the existing application services and serialized database-queue jobs against MariaDB/InnoDB. They do not substitute a fake wallet or the old non-monetary settlement simulation for prize payouts.

Delivered implementation:

- `tests/Support/FinancialRaceProbe.php`: guarded standalone PHP child; separate Laravel bootstrap, separate SQL connection, explicit local test socket/database, restricted synthetic overrides, sanitized outcomes. This is not an application route or registered Artisan command.
- `tests/Support/RunsServerFinancialRaces.php`: two children, parent-held decisive owner/draw row, observed InnoDB contention, safe lock-wait witnesses and cleanup.
- `tests/Feature/Concurrency/WalletConversionServerRaceTest.php`: nine actual wallet races.
- `tests/Feature/Concurrency/SettlementServerRaceTest.php`: four actual settlement/worker races.
- `scripts/run_financial_concurrency.py`: exact `legacy`/`money`/`all` profiles, prepared-schema/InnoDB checks, fail-closed metadata requirements, exact JUnit identities, no missing/skipped cases, per-money-case lock witnesses and bounded repetitions.
- `scripts/test_run_financial_concurrency.py`: 14 offline refusal/evidence guard tests.
- `.github/workflows/financial-concurrency.yml`: dedicated automatic server-SQL CI lane, isolated socket-only MariaDB, fresh ephemeral application key, built frontend assets, three complete native-MariaDB iterations and sanitized artifact upload. The ordinary SQLite CI lane remains intact. YAML and every Bash run block were checked locally; the hosted workflow itself has not been executed in this conversation.

## The contention proof is stronger than process readiness

For each of the 13 new races:

1. Two child processes independently bootstrap Laravel and report different positive `CONNECTION_ID()` values.
2. The parent starts a transaction and locks the exact fixture `users` or `draws` row needed by the financial operation.
3. Both children begin the real service or queue-worker operation.
4. The parent joins `INNODB_LOCK_WAITS` to requesting and blocking `INNODB_TRX` entries. Both child IDs must be blocked by this exact parent's SQL connection, not an unrelated blocker.
5. Only after observing both waits does the parent release the lock. Child results and durable money/queue outcomes must then satisfy the assertions.
6. The guarded runner requires one valid two-child/parent witness for every expected money test, in every iteration, in addition to exact passing JUnit cases.

The parent holds only the selected decisive row lock in this barrier transaction. The witness records test identity, table and connection IDs, not customer details, keys, passwords, amounts or SQL rows.

An early harness run falsely reported zero waits: the children really were waiting, but 20ms polling reused InnoDB's cached metadata snapshot. Polling at 200ms, beyond its 100ms snapshot refresh interval, resolved observation. Attempts to use `ANALYZE TABLE` or warm model reads did not resolve this and were removed. This was a harness defect, not evidence of duplicate application debits. No money assertion was weakened to make the run pass.

A test initially used the nonexistent `DrawStatus::Settled`; its assertion was corrected to the application's existing `Completed` state. Application status semantics were not changed.

## Actual wallet outcomes

All monetary examples below are synthetic fixtures, never customer transfers.

| Concurrent operation | Required durable result |
|---|---|
| Two different requests competing for the last 100.00 cash | One completion, one insufficient-balance refusal; cash 0.00, win 99.00; exactly one conversion/financial movement |
| Identical THB, USD and BDT requests | One completion and one replay of the same reference; one debit, one conversion, balanced ledger |
| One quote used with two different idempotency keys | One completion, one consumed-quote refusal; only one movement |
| Two requests competing for a rolling 100.00 limit | One completion, one daily-limit refusal after owner locking |
| Opposite cash-to-win and win-to-cash operations | Both complete with the configured fees; from 100.00 in each wallet, each finishes at 99.00 |
| Duplicate authorized reversal retries | One completed reversal plus one replay; original 1000.00 cash restored once, win 0.00 |
| Same key with conflicting amount intents | One completion and one idempotency-conflict refusal; no second money movement |

The three denomination cases test own-wallet money within the selected currency. They are not foreign exchange, cross-border transfers or authorization to operate in every country.

## Actual monetary settlement and queue outcomes

| Concurrent operation | Required durable result |
|---|---|
| Two starts for the same published draw | One durable run identity; no premature payout or wallet credit |
| Duplicate monetary checkpoint | Two bets settled once, one chunk, one completed payout, one added financial transaction and two ledger entries; wallet 9470.00 after the synthetic 9000.00 prize |
| Duplicate checkpoint under mandatory approval | One pending payout, wallet stays 480.00, no payout financial transaction or ledger credit |
| Two database queue workers consuming duplicate serialized chunk jobs | Two child workers really contend on the draw; one payout and one processed chunk; real finalizer jobs drain, jobs/failed_jobs both zero, run complete and draw `Completed` |

Canonical per-draw identity, stored cursor/checkpoint behavior, manual approval and official-result gates remain authoritative. The new monetary tests complement the older simulation, not the other way around.

## Verification results

| Check | Final observed result |
|---|---|
| Whole default regression | 2488 tests / 130295 assertions; zero errors/failures; 15 explicit skips; process exit 0 |
| MariaDB via Laravel `mysql` connection | Three iterations, each 17 tests / 347 assertions PASS, zero skips, 13 valid money lock-wait witnesses |
| MariaDB via native Laravel `mariadb` connection | Three iterations, each 17 tests / 347 assertions PASS, zero skips, 13 valid money lock-wait witnesses |
| Runner's offline refusal/evidence guards | 14 tests PASS |
| All offline Python guard suites | 40 tests PASS; no database or provider operations |
| New PHP style checks | Pint PASS, four files |
| Project PHP syntax | 1654 files PASS |
| Frontend build | PASS |
| Static financial source preflight | PASS; zero missing imported application classes |
| Existing release source inventory | Read-only check PASS; no stale documents or missing sources |
| New CI workflow | YAML/structure/guard checks and every Bash block syntax PASS; hosted execution remains unperformed |

Detailed final stdout is in `evidence/runtime-part-22.txt`; machine-readable server outcomes, exact connection profiles and per-case witnesses are in `evidence/server-races-part-22.json` and `evidence/server-races-native-driver-part-22.json`. Final server runs were repeated after the complete SQLite regression and after adding the explicit connection-profile field to the runner's report. No database-writing suites overlapped.

Both Laravel connection names were exercised against the same MariaDB engine; these are two connection profiles, not two independent database engine vendors. The native `mariadb` configuration was suspected missing from the project file, but focused boot and full races proved Laravel supplies it through framework defaults. No unnecessary application configuration patch was made.

The final whole-suite run uses the repository's guarded SQLite test store. Its 13 new money-race skips plus the old shared-server settlement skip are intentional there, and all 14 of those server-only cases run separately without skips in the MariaDB profile. The other skipped case is the unbuilt native Rust verifier. Skipped tests are not counted as passed. The normal suite's three purchase races also run in the separate server profile.

No new application monetary defect was established by the observed races. Existing money engines, fees, permissions and approval/provider gates were not changed merely to manufacture an application fix. Of the 221 prior delivered bodies, only the two concurrency runner files are revised; the other 219 remain byte-identical.

## Safe reproduction and prerequisites

Never run this harness against production or customer data. Its fixtures truncate data. Use a fresh dedicated local database instance, not a shared operational server. The exact database name is deliberately not configurable beyond `thai_lottery_test`.

1. Restore the same repository plus this cumulative overlay. Install locked Composer/npm dependencies and build assets. PHP 8.4 with `pdo_mysql`, `pdo_sqlite`, `gd`, `intl`, `bcmath` and the other Composer-required extensions is the locally verified profile.
2. Prepare an isolated MariaDB instance with a private directory, a local Unix socket and networking disabled. The delivered CI workflow contains the complete provisioning commands. Its account has all privileges only on `thai_lottery_test` and global `PROCESS` solely to inspect locks inside that isolated instance. Do not grant this account privileges against a shared production server.
3. Export an absolute `DB_SOCKET` pointing to that isolated socket, `DB_USERNAME=financial_test`, an appropriate test-only `DB_PASSWORD`, `DB_DATABASE=thai_lottery_test`, `DB_CONNECTION=mariadb`, `DB_URL=''` and `APP_ENV=testing`. Use an ephemeral valid `APP_KEY`. Do not copy production environment files or secrets.
4. Ensure `bootstrap/cache/config.php` is absent. Migrate only this acknowledged disposable schema using `php artisan migrate --force --no-interaction`. The runner checks the prepared financial tables are InnoDB before launching tests.
5. Run the offline guards, then the following exact profile from the repository root:

```bash
python3 -m unittest discover -s scripts -p test_run_financial_concurrency.py -v
python3 scripts/run_financial_concurrency.py \
  --suite all \
  --database thai_lottery_test \
  --connection mariadb \
  --socket "$DB_SOCKET" \
  --username financial_test \
  --acknowledge-disposable-database \
  --repeats 3 \
  --json evidence/server-races-local.json
```

Use `--suite money` for the 13 new monetary races, or `--suite legacy` for the older four. The latter includes a non-monetary simulation and is not monetary payout proof. `--suite all` is the default and strongest delivered profile. Repetitions are restricted to 1–10; JSON output is restricted to the repository's evidence directory. Passwords are environment-only, never command arguments.

MariaDB 11.8.6 is the actual locally verified engine. The new money profile requires `information_schema.INNODB_LOCK_WAITS`/`INNODB_TRX` and appropriate metadata permissions. MySQL 8 removed the former table, so the profile refuses unsupported metadata rather than silently calling readiness a lock proof. Neither MySQL 8 compatibility nor hosted CI execution is claimed here.

Run database-writing suites serially. The local server runs in an excluded private cache directory and is not delivered; re-provision it when restoring the source snapshot. Stop the dedicated instance after verification.

## Remaining selected work

The primary previous shared-server monetary verification gap is now covered locally. The next independent runtime task is building/testing the pinned Rust weekly-result verifier and checking its PHP/native agreement. Then exercise the target deployment's real distributed-worker/restart/retry topology, including failure injection; this local two-child proof is not a substitute for that operational acceptance.

Worldwide use continues to require authorized operator/jurisdiction/provider/currency combinations, official-source authenticity and authorized live payment reconciliation. No merchant account, provider secret, live transfer or country approval was supplied. The architecture remains multi-country and fail-closed for unapproved combinations; Bangladesh-only bank assumptions and universal live-payment claims were not introduced.

## Delivery integrity

All 12 bodies are complete, with no code elision. Packaging verifies unique paths, ZIP CRC, full workspace byte equality, extraction of every Markdown code body, the 219 untouched prior bodies and SHA256 manifests. Ten paths are newly delivered and two prior paths are revised. The cumulative 231-file ZIP is an overlay against the pinned repository, not a full repository export.

Runtime environment files, cached configuration, SQL data/socket/PID/logs, raw diagnostic InnoDB dumps, credentials, dependencies, generated frontend assets and native binaries are excluded. The raw early server diagnostic is deliberately not included because it contains synthetic fixture password hashes. Only previously delivered secret-free environment templates remain in the overlay.
