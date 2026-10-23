# Backend Test Results — Close 35 Backend/Audit Failures (`20261023T095500Z_2c7ab9c_close35`)

**Candidate revision:** `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` (`backend-next`)
**Repository root:** `/home/user`
**Evidence directory:** `/home/user/artifacts/backend-acceptance/20261023T095500Z_2c7ab9c_close35/`
**Captured (UTC):** see `environment.json` / `source-identity.json`

---

## 1. Deduplication Rule (stated once, applied everywhere)

A test case is identified by its **`(class, name)`** pair exactly as recorded in the JUnit XML. Where the same case executes on more than one engine (SQLite for the fast lane; MariaDB 11.8.6 + Redis 8.0.2 for the InnoDB/Redis lane), it is counted **once**, and it counts as *passed* when it executed and was green on the engine that actually ran it. Repeated executions are never counted twice.

Raw, non-deduplicated totals across the 17 JUnit XML files: **`14,548`** test executions, **`361,047`** assertions. The release-evidence numbers are the deduplicated ones below.

---

## 2. Headline Totals

| Metric | Count |
|---|---:|
| **Unique PHPUnit test cases** | **5,591** |
| **Passed** | **5,577** (**99.75%**) |
| **Failed** | **14** (all Frontend Blade `H6`) |
| **Errors** | **0** |
| **Never executed / unexecuted required skips** | **0** |
| **Deduplicated assertions** | **168,911** |
| **Backend/Audit cases closed this iteration (`H1`–`H5`, `H7`)** | **35** |

---

## 3. Raw Per-Suite Results (17 JUnit XML files, verbatim from each log)

| JUnit XML file | Runner / engine | Tests | Passed | Failures | Errors | Skipped | Assertions |
|---|---|---:|---:|---:|---:|---:|---:|
| `gate-a-build-deployment-production.xml` | SQLite | 224 | 224 | 0 | 0 | 0 | 2,005 |
| `gate-b-ledger-money-sqlite.xml` | SQLite | 1,260 | 1,220 | 0 | 0 | 40 | 11,681 |
| `gate-c-deposit-webhook-sqlite.xml` | SQLite | 514 | 513 | 0 | 0 | 1 | 4,718 |
| `gate-d-withdrawal-payout-sqlite.xml` | SQLite | 438 | 434 | 0 | 0 | 4 | 1,790 |
| `gate-e-transfer-conversion-sqlite.xml` | SQLite | 468 | 421 | 0 | 0 | 47 | 3,326 |
| `gate-f-ticket-purchase-lanes-sqlite.xml` | SQLite | 990 | 950 | 0 | 0 | 40 | 5,406 |
| `gate-g-settlement-results-sqlite.xml` | SQLite | 543 | 519 | 0 | 0 | 24 | 105,043 |
| `gate-h-database-seeders.xml` | SQLite | 16 | 16 | 0 | 0 | 0 | 204 |
| `gate-i-security-compliance-sqlite.xml` | SQLite | 1,002 | 988 | 14 | 0 | 14 | 27,648 |
| `gate-b-ledger-money-mysql.xml` | MariaDB | 101 | 101 | 0 | 0 | 0 | 1,301 |
| `gate-c-deposit-webhook-mysql.xml` | MariaDB | 27 | 27 | 0 | 0 | 0 | 783 |
| `gate-d-withdrawal-payout-mysql.xml` | MariaDB | 47 | 47 | 0 | 0 | 0 | 715 |
| `gate-e-transfer-conversion-mysql.xml` | MariaDB | 56 | 56 | 0 | 0 | 0 | 1,326 |
| `gate-f-ticket-purchase-lanes-mysql.xml` | MariaDB | 40 | 40 | 0 | 0 | 0 | 823 |
| `gate-g-settlement-results-mysql.xml` | MariaDB + Redis | 43 | 43 | 0 | 0 | 0 | 1,677 |
| `gate-i-security-compliance-mysql.xml` | MariaDB | 14 | 14 | 0 | 0 | 0 | 258 |
| `gate-b-c-g-inline-mariadb-tests-mysql.xml` | MariaDB | 11 | 11 | 0 | 0 | 0 | 153 |
| **Total (17 files, includes re-runs)** | | **14,548** | **14,073** | **14** | **0** | **475** | **361,047** |

> `475` raw skips = the `170` unique SQLite-only skip markers plus the `305` cases that also ran in the MariaDB lane where they execute (a case that is skipped on SQLite and executed on MariaDB appears once as a skip and once as a pass across the two files).

---

## 4. Native / Non-PHPUnit Runners

| Runner | Command | Result |
|---|---|---|
| Rust `runtime/settlement-worker` | `cargo test --locked` | **6 / 6 passed** (`0` failed, `0` ignored) — `logs/gate-h-rust-settlement-worker.log` |
| Rust `security/weekly-result-integrity` | `cargo test --locked` | **37 / 37 passed** (`0` failed, `0` ignored) — `logs/gate-h-rust-weekly-verifier.log` |
| Python `scripts/tests` | `python3 -m unittest discover -s scripts -p 'test_*.py' -v` | **100 / 100 passed** (`0` failed, `0` skipped) — `logs/gate-a-python-scripts-unittest.log` |
| PHP 8.4.27 syntax lint | `find . -type f -name '*.php' … \| xargs -n1 php -l` | **2,892 / 2,892 parsed**, `0` syntax errors — `logs/gate-a-php-lint.log`, inventory in `logs/gate-a-php-discovered-files.txt` |
| Composer validate | `composer validate --no-check-publish` | `./composer.json is valid` — `logs/gate-a-composer-validate.log` |
| Composer platform reqs | `composer check-platform-reqs` | all `success` — `logs/gate-a-composer-platform-reqs.log` |
| Financial source preflight | `python3 scripts/financial_source_preflight.py` | PASS — `logs/gate-a-financial-source-preflight.json` |
| Release source inventory | `python3 scripts/release_source_inventory.py --check` | PASS — `logs/gate-a-release-source-inventory.json` |
| Environment parity | `php scripts/check-env-parity.php` | PASS — `logs/gate-a-env-parity.json` |
| Artisan `config:clear` / `about` / `route:list` / `schedule:list` / `migrate:status` | `APP_ENV=testing php artisan …` | all `EXIT=0` — `logs/gate-a-artisan-*.log` |
| MariaDB disposable migrations | `migrate:fresh --seed --force` → `migrate:status` → `migrate:rollback --step=5 --force` → `migrate --force` → `migrate:status` | **`163 / 163`** migrations + seeders, every step `EXIT=0` — `logs/gate-h-mariadb-migrations.log` |

---

## 5. The 170 Unexecuted-on-SQLite Tests — All Executed on MariaDB 11.8.6 + Redis 8.0.2

These are the tests the fast SQLite lane explicitly marks "requires the separately acknowledged local InnoDB test server". **Every one was executed and passed on MariaDB 11.8.6 (REPEATABLE-READ, `/run/mysqld/mysqld.sock`) + Redis 8.0.2.** None is counted as a pass on SQLite.

| Suite | SQLite skips | Executed & passed on MariaDB/Redis | Evidence |
|---|---:|---:|---|
| Gate B — Ledger & Money | 40 | 40 | `junit/gate-b-ledger-money-mysql.xml` (101 tests, `0` skips) + `junit/gate-b-c-g-inline-mariadb-tests-mysql.xml` (11 tests, `0` skips) |
| Gate C — Deposit & Webhooks | 1 | 1 | `junit/gate-c-deposit-webhook-mysql.xml` (27 tests, `0` skips) |
| Gate D — Withdrawal & Payout | 4 | 4 | `junit/gate-d-withdrawal-payout-mysql.xml` (47 tests, `0` skips) |
| Gate E — Transfer & Conversion | 47 | 47 | `junit/gate-e-transfer-conversion-mysql.xml` (56 tests, `0` skips) |
| Gate F — Ticket Purchase & Lanes | 40 | 40 | `junit/gate-f-ticket-purchase-lanes-mysql.xml` (40 tests, `0` skips) |
| Gate G — Result & Settlement | 24 | 24 | `junit/gate-g-settlement-results-mysql.xml` (43 tests, `0` skips) |
| Gate I — Security & Compliance | 14 | 14 | `junit/gate-i-security-compliance-mysql.xml` (14 tests, `0` skips) |
| **Total** | **170** | **170** | **8 MariaDB JUnit files, `339 / 339` passed, `0` skips** |

---

## 6. The 14 Remaining Failures — All Frontend Blade (`H6`), All Other Track

| # | Test | File under test | Raw failure | Owning track |
|---:|---|---|---|---|
| 1 | `PublicContentSourceTest::test_glo_references_carry_the_non_affiliation_disclaimer` | public Blade footer | `Failed asserting that '<!DOCTYPE html>\n…'` | Frontend |
| 2 | `AdminConsoleChromeTest::the_sidebar_marks_only_the_panel_the_operator_is_actually_on` | `resources/views/layouts/admin.blade.php` | `On admin.dashboard exactly one item must carry aria-current="page".` | Frontend |
| 3 | `AdminConsoleChromeTest::the_sidebar_marks_nothing_as_current_on_a_screen_outside_the_panel_vocabulary` | `resources/views/layouts/admin.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | Frontend |
| 4 | `AdminConsoleChromeTest::an_operator_is_never_offered_a_panel_the_controller_would_refuse` | `resources/views/layouts/admin.blade.php` | `An operator without view draws was still offered Draw Lifecycle.` | Frontend |
| 5 | `AdminConsoleChromeTest::the_console_names_the_operator_who_is_logged_in` | `resources/views/layouts/admin.blade.php` | `The avatar initials were not derived from the operator name.` | Frontend |
| 6 | `AdminConsoleChromeTest::the_chrome_carries_no_control_that_does_nothing` | `resources/views/layouts/admin.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | Frontend |
| 7 | `AdminConsoleChromeTest::the_source_of_the_layout_contains_no_hardcoded_identity_or_count` | `resources/views/layouts/admin.blade.php` | `lf-notification-badge is still present in the layout outside a comment.` | Frontend |
| 8 | `AdminConsoleChromeTest::the_dead_styles_are_gone_and_the_avatar_style_suits_an_element_that_is_not_an_image` | `resources/views/layouts/admin.blade.php` | `.lf-notification-btn styles a control that no longer exists.` | Frontend |
| 9 | `BrowserBetFinancialBoundaryTest::test_page_uses_primary_available_balance_not_win_or_reserved_funds` | `resources/views/player/bet.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | Frontend |
| 10 | `BrowserBetFinancialBoundaryTest::test_page_item_ceiling_cannot_exceed_the_http_cap_but_can_be_lower` | `resources/views/player/bet.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | Frontend |
| 11 | `PlayerExperienceComprehensiveTest::test_15_web_draws_and_detail_views_render_properly` | `resources/views/player/draws/*` | `Failed asserting that '<!DOCTYPE html>\n…'` | Frontend |
| 12 | `PlayerFrontendModulesTest::test_bet_page_offers_only_market_keys_the_api_accepts` | `resources/views/player/bet.blade.php` | `The bet page rendered no market options.` | Frontend |
| 13 | `PlayerFrontendModulesTest::test_bet_page_publishes_the_configured_digits_and_multipliers` | `resources/views/player/bet.blade.php` | `Market 3d_direct is not offered.` | Frontend |
| 14 | `PlayerFrontendModulesTest::test_bet_slip_container_mirrors_the_server_side_item_ceiling` | `resources/views/player/bet.blade.php` | `The slip no longer mirrors the 50-item ceiling enforced by BulkBetRequest.` | Frontend |

Raw evidence: `logs/gate-i-security-compliance-sqlite.log` (the `There were 14 failures:` block). Full owner handoff with expected contracts: `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`.

**No Frontend-owned file was changed in this iteration** — confirmed by SHA-256 comparison of every `resources/views/**` path against `20261023T063900Z_2c7ab9c/changed-files-manifest.csv` (see `changed-files-manifest.csv`, `iteration_effect` column: zero Frontend/DevOps/Rust/Android rows read `MODIFIED THIS ITERATION`).

---

## 7. Before / After Evidence for the 35 Closed Cases

Every closed case has a paired baseline (pre-fix) and post-fix log in this bundle:

| Case group | Baseline log (`pre-fix-logs/`) | Post-fix log (`post-fix-logs/`) | Baseline | After |
|---|---|---|---:|---:|
| H1 (2) | `h1-automated-bank-reconciliation-before.log`, `h1-tax-report-service-before.log` | `h1-automated-bank-reconciliation-after.log`, `h1-tax-report-service-after.log` | 2 errors | **OK (1 test, 4 assertions)**, **OK (1 test, 6 assertions)** |
| H2 (1) | `h2-financial-reconciliation-comprehensive-before.log` | `h2-financial-reconciliation-comprehensive-after.log` | 1 error / 36 tests | **OK (36 tests, 109 assertions)** |
| H3 (2) | `h3-payment-initiation-service-before.log` | `h3-payment-initiation-service-after.log` | 2 errors / 5 tests | **OK (5 tests, 20 assertions)** |
| H4 (6) | `h4-draw-automation-before.log` | `h4-draw-automation-after.log` | 6 errors / 28 tests | **OK (28 tests, 119 assertions)** |
| H5 (23) | `h5-audit-anchor-truth-before.log`, `h5-audit-subject-truth-before.log` | `h5-audit-anchor-truth-after.log`, `h5-audit-subject-truth-after.log` | 5 errors + 4 failures (11 tests); 13 failures (17 tests) | **OK (11 tests, 81 assertions)**, **OK (17 tests, 68 assertions)** |
| H7 (1) | `h7-player-experience-test26-before.log` | `h7-player-experience-test26-after.log` | 1 failure | **OK (1 test, 2 assertions)** |

Regression suites re-run after the fixes and still green: `tests/Feature/Payment/ManagedRefundBoundaryTest.php` (11 tests, 42 assertions), `tests/Feature/Payment/DurableDepositCheckoutTest.php` (29 tests, 145 assertions, 1 MariaDB skip executed in the MariaDB lane), `tests/Feature/Console/ScheduleRegistrationTest.php` (7 tests, 271 assertions), `tests/Feature/Player/PlayerPresentationContractTest.php` (14 tests, 143 assertions), `tests/Feature/Account/AccountGradeProgrammeTest.php` (26 tests, 185 assertions), `tests/Feature/Queue/ProductionQueueComprehensiveTest.php` (24 tests, 119 assertions), `tests/Unit/Lottery/ProviderAttemptRecorderTest.php` (7 tests), `tests/Feature/Observability/HealthDisclosureTest.php` (6 tests, 14 assertions).

---

## 8. Environment Actually Observed

| Component | Value |
|---|---|
| PHP | `8.4.27` (cli) |
| Composer | `2.10.3` |
| PHPUnit | `11.5.56` |
| SQLite | `3.46.1` |
| MariaDB | `11.8.6-MariaDB-0+deb13u1`, `REPEATABLE-READ`, socket `/run/mysqld/mysqld.sock` |
| Redis server | `8.0.2` (`PONG`) |
| rustc / cargo | `1.93.1` / `1.93.1` |
| Python | `3.13.16` |
| Node | `v20.20.2` |
| OS / kernel | `Debian GNU/Linux 13 (trixie)` / `6.1.158+` |
| Docker daemon | **not available** (`docker_daemon_available: false`, `docker_executable_present: false`) |

---

## 9. Environment-Blocked Gates (not counted as passes)

| Gate | Blocker | Required owner / host |
|---|---|---|
| Production Docker image build + container smoke tests | No Docker daemon in this sandbox (`environment.json`) | DevOps CI/staging runner with a live Docker daemon |
| Live payment provider / webhook sandbox cutover | Prohibited by task constraint (no live provider calls, no real-money transactions) | Payments / DevOps |
| Frontend Blade `H6` assertions (13) | Frontend-owned files; cross-track edits forbidden | Frontend track |
