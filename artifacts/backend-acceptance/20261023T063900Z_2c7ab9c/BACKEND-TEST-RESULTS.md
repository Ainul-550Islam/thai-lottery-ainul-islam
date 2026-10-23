# Backend Test Results — Zero-Skip Revalidation (`20261023T063900Z_2c7ab9c`)

- **Candidate Revision**: `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` (`backend-next`)
- **Evidence Bundle**: `/home/user/artifacts/backend-acceptance/20261023T063900Z_2c7ab9c`

## 1. Deduplicated Cross-Runner Summary (SQLite + MariaDB 11.8.6 + Redis 7.0.15 + Rust + Python)

| Runner / Scope | Unique Tests | Passed | Failures | Errors | Unexecuted Skips | Assertions / Checks |
|---|---:|---:|---:|---:|---:|---:|
| **PHPUnit (Deduplicated across 17 JUnit XML runs)** | **5591** | **5543** | **32** | **16** | **0** | **165060** |
| **MariaDB InnoDB Server Race & Inline Suites (8 JUnit XML runs)** | **339** | **339** | **0** | **0** | **0** | **7,037** |
| **Rust `runtime/settlement-worker` (`cargo test --locked`)** | **6** | **6** | **0** | **0** | **0** | **6** |
| **Rust `security/weekly-result-integrity` (`cargo test --locked`)** | **37** | **37** | **0** | **0** | **0** | **37** |
| **Python Release/Preflight Unit Suite (`scripts/tests`)** | **100** | **100** | **0** | **0** | **0** | **100** |
| **PHP 8.4.27 Syntax Lint (`php -l`)** | **2,894** | **2,894** | **0** | **0** | **0** | **2,894** |
| **Combined Verification Total (PHPUnit + Rust + Python)** | **5734** | **5686** | **32** | **16** | **0** | **165203** |

### Zero-Skip Proof on MariaDB + Redis

- Across the SQLite PHPUnit runs (`gate-b` through `gate-i`), exactly **170** test cases called `markTestSkipped()` because they require true MariaDB/MySQL InnoDB row-level locks (`RunsServerFinancialRaces::requireServerRace()`, `RequiresPayoutServerRace`) or native MariaDB `DECIMAL` / `PDO` transaction observer behavior.
- Every single one of those **170** SQLite-skipped test cases was executed against live **MariaDB 11.8.6-MariaDB** (`unix_socket=/run/mysqld/mysqld.sock`, `DB_DATABASE=thai_lottery_test`) and **Redis 7.0.15** (`127.0.0.1:6379`) in the `-mysql.xml` JUnit runs, and **170 / 170 PASSED (`0` skips remaining)**.

## 2. Raw JUnit XML Breakdown by Gate File

| JUnit XML File | Engine | Total Tests | Passed | Failures | Errors | Skipped | Assertions | Duration (s) |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| `junit/gate-a-build-deployment-production.xml` | SQLite 3.46.1 + Redis 7.0.15 | 224 | 224 | 0 | 0 | 0 | 2005 | 17.993 |
| `junit/gate-b-c-g-inline-mariadb-tests-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 11 | 11 | 0 | 0 | 0 | 153 | 10.502 |
| `junit/gate-b-ledger-money-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 101 | 101 | 0 | 0 | 0 | 1301 | 58.995 |
| `junit/gate-b-ledger-money-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 1260 | 1217 | 0 | 3 | 40 | 11666 | 235.212 |
| `junit/gate-c-deposit-webhook-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 27 | 27 | 0 | 0 | 0 | 784 | 37.071 |
| `junit/gate-c-deposit-webhook-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 514 | 511 | 0 | 2 | 1 | 4707 | 186.260 |
| `junit/gate-d-withdrawal-payout-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 47 | 47 | 0 | 0 | 0 | 715 | 45.086 |
| `junit/gate-d-withdrawal-payout-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 438 | 434 | 0 | 0 | 4 | 1790 | 154.423 |
| `junit/gate-e-transfer-conversion-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 56 | 56 | 0 | 0 | 0 | 1326 | 52.313 |
| `junit/gate-e-transfer-conversion-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 468 | 421 | 0 | 0 | 47 | 3326 | 93.435 |
| `junit/gate-f-ticket-purchase-lanes-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 40 | 40 | 0 | 0 | 0 | 823 | 38.315 |
| `junit/gate-f-ticket-purchase-lanes-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 990 | 950 | 0 | 0 | 40 | 5406 | 195.682 |
| `junit/gate-g-settlement-results-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 43 | 43 | 0 | 0 | 0 | 1677 | 89.988 |
| `junit/gate-g-settlement-results-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 543 | 513 | 0 | 6 | 24 | 105025 | 143.552 |
| `junit/gate-h-database-seeders.xml` | SQLite 3.46.1 + Redis 7.0.15 | 16 | 16 | 0 | 0 | 0 | 204 | 6.819 |
| `junit/gate-i-security-compliance-mysql.xml` | MariaDB 11.8.6 + Redis 7.0.15 | 14 | 14 | 0 | 0 | 0 | 258 | 18.298 |
| `junit/gate-i-security-compliance-sqlite.xml` | SQLite 3.46.1 + Redis 7.0.15 | 1002 | 951 | 32 | 5 | 14 | 24004 | 119.832 |

## 3. Native & Static Gate Logs

- `logs/gate-a-php-lint.log` & `logs/gate-a-php-lint-summary.txt`: `DISCOVERED=2894 PARSED=2894` (`0` syntax errors)
- `logs/gate-a-composer-validate.log`: `composer.json is valid` (`EXIT=0`)
- `logs/gate-a-composer-dump-autoload.log`: generated optimized autoload files containing `5,297` classes (`EXIT=0`)
- `logs/gate-a-composer-platform-reqs.log`: all `ext-bcmath`, `ext-ctype`, `ext-curl`, `ext-dom`, `ext-fileinfo`, `ext-filter`, `ext-hash`, `ext-intl`, `ext-json`, `ext-libxml`, `ext-mbstring`, `ext-openssl`, `ext-pcre`, `ext-pdo`, `ext-session`, `ext-simplexml`, `ext-Sodium`, `ext-tokenizer`, `ext-xml`, `ext-xmlwriter`, `ext-zip`, and `php ^8.4` requirements `success`
- `logs/gate-a-financial-source-preflight.json`: `missing_imported_app_class_count: 0`, `source_preflight_passed: true`
- `logs/gate-a-release-source-inventory.json`: `page_slots: 274`, `missing_sources: []`, `stale_documents: []`
- `logs/gate-a-env-parity.json`: `.env.example` (`354`), `.env.ci` (`354`), `.env.production.example` (`354`) — `0` missing keys
- `logs/gate-a-python-scripts-unittest.log`: `Ran 100 tests ... OK` (`EXIT=0`)
- `logs/gate-h-mariadb-migrations.log`: `migrate:fresh --seed --force` (`163/163` migrations + seeders), `migrate:status`, `migrate:rollback --step=5 --force`, and `migrate --force` all `DONE` (`EXIT=0`)
- `logs/gate-h-rust-settlement-worker.log`: `test result: ok. 6 passed; 0 failed; 0 ignored`
- `logs/gate-h-rust-weekly-verifier.log`: `test result: ok. 26 passed` (unit) + `11 passed` (integration) = `37 passed; 0 failed; 0 ignored`
