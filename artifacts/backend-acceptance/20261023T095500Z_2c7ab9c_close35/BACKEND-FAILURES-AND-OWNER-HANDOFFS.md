# Backend Failures & Cross-Track Owner Handoffs (`20261023T095500Z_2c7ab9c_close35`)

- **Candidate revision:** `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` (`backend-next`)
- **Evidence directory:** `/home/user/artifacts/backend-acceptance/20261023T095500Z_2c7ab9c_close35/`
- **Zero-weakening guarantee:** No test assertion, golden expectation, or skip condition was weakened, deleted, quarantined, re-labelled, or rewritten to make a test green. Every one of the **35** Backend/Audit-owned non-green cases (`H1`–`H5`, `H7`) from `20261023T063900Z_2c7ab9c` was closed by fixing the owning production implementation or by updating a demonstrably stale Backend-owned fixture to the authoritative contract, with paired before/after evidence. The **14** remaining failures are all Frontend Blade assertions (`H6`) owned by another track and are handed off below, **not** resolved here.
- **Final deduplicated totals:** `5,591` unique PHPUnit test cases — `5,577` passed, `14` failed, `0` errors, `0` never-executed (`168,911` assertions).

---

## 1. What Was Closed This Iteration

| Group | Cases | Outcome | Where proven |
|---|---:|---|---|
| `H1` Superseded UUID schema vs integer financial schema | 2 | **CLOSED** | `post-fix-logs/h1-*-after.log` |
| `H2` Direct deposit reversal vs Part 39 `ManagedRefundBoundaryTest` | 1 | **CLOSED** | `post-fix-logs/h2-financial-reconciliation-comprehensive-after.log` |
| `H3` `RefreshDatabase` wrapper vs Part 53 `checkout_requires_top_level` | 2 | **CLOSED** | `post-fix-logs/h3-payment-initiation-service-after.log` |
| `H4` Uncertified draw publication in `DrawAutomationTest` | 6 | **CLOSED** | `post-fix-logs/h4-draw-automation-after.log` |
| `H5` Reverted commit `97e65bd` audit anchor/subject refactor | 23 | **CLOSED** | `post-fix-logs/h5-audit-*-after.log` |
| `H7` `WalletResource` `reserved_balance` vs `locked_balance` contract | 1 | **CLOSED** | `post-fix-logs/h7-player-experience-test26-after.log` |
| **Total** | **35** | **35 / 35 CLOSED** | |

Two of these groups were **production defects**, not stale fixtures, and were fixed in production code:

1. **`AuditLog::creating` enforced nothing.** Sixteen writers were putting lane labels into the `auditable_type` morph column with `0` as the id, or pointing a job's own failure row at `AuditLog::class` + id `0`. Nothing crashed because nothing eager-loaded the morph, so those rows were invisible to exactly the query an auditor uses. `app/Models/AuditLog.php` now refuses all of those shapes at write time (fail closed), and 12 lane writers moved their identity to `metadata['lane']`.
2. **`SendFinancialAlertJob` filed itself as a reconciliation.** Every alert it wrote used `AuditAction::Reconcile`, and the stale-reconciliation freshness check selects the newest `action = reconcile` row — so the alarm was resetting the clock it was watching, which let `/api/health` report `pass` about a ledger nobody had checked. It now writes `AuditAction::SecurityAlert`.

A third, smaller production fix: the Thai Revenue Department withholding-tax CSV export formatted money with `%.4f` (a float), which is now exact `bcadd(…, '0', 4)` decimal arithmetic.

---

## 2. REMAINING FAILURES — 14 Frontend Blade assertions (`H6`), Frontend Track

**These are the only non-green cases left in the entire `5,591`-test PHPUnit suite. None is Backend-owned, and none was edited in this iteration. They are the `H6` set the task brief describes as "13 Frontend Blade failures" (the public-footer case is catalogued separately from the four Blade-template cases).**

| # | Test | File under test | Raw failure line | Expected contract |
|---:|---|---|---|---|
| 1 | `Tests\Feature\Legal\PublicContentSourceTest::test_glo_references_carry_the_non_affiliation_disclaimer` | public Blade footer (`resources/views/components/public-page/footer.blade.php` and siblings) | `Failed asserting that '<!DOCTYPE html>\n…'` | Every GLO reference in public copy must carry the non-affiliation disclaimer string the test searches for. |
| 2 | `Tests\Feature\Admin\AdminConsoleChromeTest::the_sidebar_marks_only_the_panel_the_operator_is_actually_on` | `resources/views/layouts/admin.blade.php` | `On admin.dashboard exactly one item must carry aria-current="page".` | Exactly one sidebar item carries `aria-current="page"`, and it is the item for the panel the operator is actually on. |
| 3 | `Tests\Feature\Admin\AdminConsoleChromeTest::the_sidebar_marks_nothing_as_current_on_a_screen_outside_the_panel_vocabulary` | `resources/views/layouts/admin.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | On a screen outside the panel vocabulary, no sidebar item may be marked current. |
| 4 | `Tests\Feature\Admin\AdminConsoleChromeTest::an_operator_is_never_offered_a_panel_the_controller_would_refuse` | `resources/views/layouts/admin.blade.php` | `An operator without view draws was still offered Draw Lifecycle.` | The sidebar must render only panels the operator's permissions allow — i.e. drive it from `\App\Support\Admin\AdminConsoleNav::visibleFor(auth()->user(), $panel ?? null)`. |
| 5 | `Tests\Feature\Admin\AdminConsoleChromeTest::the_console_names_the_operator_who_is_logged_in` | `resources/views/layouts/admin.blade.php` | `The avatar initials were not derived from the operator name.` | The avatar initials must be derived from the logged-in operator's name, not hardcoded. |
| 6 | `Tests\Feature\Admin\AdminConsoleChromeTest::the_chrome_carries_no_control_that_does_nothing` | `resources/views/layouts/admin.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | No chrome control may be a no-op placeholder. |
| 7 | `Tests\Feature\Admin\AdminConsoleChromeTest::the_source_of_the_layout_contains_no_hardcoded_identity_or_count` | `resources/views/layouts/admin.blade.php` | `lf-notification-badge is still present in the layout outside a comment.` | The layout source must contain no hardcoded identity, badge count, or dead `lf-notification-*` markup. |
| 8 | `Tests\Feature\Admin\AdminConsoleChromeTest::the_dead_styles_are_gone_and_the_avatar_style_suits_an_element_that_is_not_an_image` | `resources/views/layouts/admin.blade.php` | `.lf-notification-btn styles a control that no longer exists.` | Dead `.lf-notification-btn` / `.lf-floating-help` styles must be removed; the avatar style must suit a non-image element. |
| 9 | `Tests\Feature\Player\BrowserBetFinancialBoundaryTest::test_page_uses_primary_available_balance_not_win_or_reserved_funds` | `resources/views/player/bet.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | The bet page must render the **primary wallet's available balance** in `#player-balance-display` — never a win-wallet or reserved figure. |
| 10 | `Tests\Feature\Player\BrowserBetFinancialBoundaryTest::test_page_item_ceiling_cannot_exceed_the_http_cap_but_can_be_lower` | `resources/views/player/bet.blade.php` | `Failed asserting that '<!DOCTYPE html>\n…'` | `data-max-items` must be clamped to `min((int) config('lottery.bulk.max_items', 50), 50)`. |
| 11 | `Tests\Feature\Player\PlayerExperienceComprehensiveTest::test_15_web_draws_and_detail_views_render_properly` | `resources/views/player/draws/*` | `Failed asserting that '<!DOCTYPE html>\n…'` | The web draws list and draw detail views must render properly. |
| 12 | `Tests\Feature\Player\PlayerFrontendModulesTest::test_bet_page_offers_only_market_keys_the_api_accepts` | `resources/views/player/bet.blade.php` | `The bet page rendered no market options.` | The bet page must render `data-market-key` attributes for exactly the market keys the API accepts. |
| 13 | `Tests\Feature\Player\PlayerFrontendModulesTest::test_bet_page_publishes_the_configured_digits_and_multipliers` | `resources/views/player/bet.blade.php` | `Market 3d_direct is not offered.` | The bet page must publish the configured digits and multipliers per market (e.g. `3d_direct`). |
| 14 | `Tests\Feature\Player\PlayerFrontendModulesTest::test_bet_slip_container_mirrors_the_server_side_item_ceiling` | `resources/views/player/bet.blade.php` | `The slip no longer mirrors the 50-item ceiling enforced by BulkBetRequest.` | The bet-slip container must mirror the server-side item ceiling enforced by `BulkBetRequest`. |

> All **14** rows above are Frontend Blade assertions and together account for **all 14** deduplicated failures in the entire `5,591`-test PHPUnit suite: 7 `AdminConsoleChromeTest` + 2 `BrowserBetFinancialBoundaryTest` + 1 `PlayerExperienceComprehensiveTest` + 3 `PlayerFrontendModulesTest` + 1 `PublicContentSourceTest`. The task brief calls this the "13 Frontend Blade failures (`H6`)" set because the public-footer case is catalogued separately from the four Blade-template cases; either way, **every remaining failure is Frontend-owned and none is Backend-owned**.

**Raw evidence:** `logs/gate-i-security-compliance-sqlite.log`, block `There were 14 failures:`.
**Command to reproduce:** `vendor/bin/phpunit -c phpunit.xml tests/Feature/Admin/AdminConsoleChromeTest.php tests/Feature/Player/BrowserBetFinancialBoundaryTest.php tests/Feature/Player/PlayerFrontendModulesTest.php tests/Feature/Player/PlayerExperienceComprehensiveTest.php tests/Feature/Legal/PublicContentSourceTest.php`

**Owner handoff — Frontend Track:**

| Item | Detail |
|---|---|
| Owning track | Frontend (Blade templates) |
| Exact paths | `resources/views/layouts/admin.blade.php`, `resources/views/player/bet.blade.php`, `resources/views/player/draws/*`, `resources/views/components/public-page/*` |
| Source revision | `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` |
| Reproduction command | see above |
| Expected interface / contract | admin sidebar driven by `\App\Support\Admin\AdminConsoleNav::visibleFor(auth()->user(), $panel ?? null)` with exactly one `aria-current="page"`; bet page renders primary available balance in `#player-balance-display`, `data-max-items = min(config('lottery.bulk.max_items', 50), 50)`, `data-market-key` / `data-digits` / `data-multiplier` attributes; public copy carries the GLO non-affiliation disclaimer |
| Backend-side impact | **None.** No backend route, controller, API contract, service or resource change is required to make these green; the backend contracts they assert against are already implemented and passing (`PlayerPresentationContractTest` 14/14, `BetPurchaseWebTest` 6/6, `PublicPaymentCapabilityParityTest` 5/5) |
| Status | `RELEASE BLOCKED` for platform acceptance until the Frontend track resolves them |

**Confirmation that no Frontend-owned file changed in this iteration:** every `resources/views/**`, `public/(js|css|build)/**`, `resources/js/**`, `resources/css/**`, `package.json` and `composer.json`/`composer.lock` path in the working tree was SHA-256-compared against `20261023T063900Z_2c7ab9c/changed-files-manifest.csv`. **All are byte-identical.** See `changed-files-manifest.csv`, column `iteration_effect`.

---

## 3. External & Cross-Track Release Blockers

### 3.1 Live Docker image build & container runtime verification — DevOps Track

- **Status:** `BLOCKED`. `environment.json` records `docker_daemon_available: false` and `docker_executable_present: false`; there is no `dockerd`/`containerd` and no `docker` executable in this sandbox.
- **What was verified locally instead:** every Backend-owned Dockerfile, `docker-compose.production.yml`, Nginx security-header, PHP-FPM entrypoint and CI-workflow contract test passed — `junit/gate-a-build-deployment-production.xml`, **`224 / 224`** tests, `2,005` assertions, `0` failures, `0` errors, `0` skips.
- **Required action:** run `docker compose -f docker-compose.production.yml config --quiet` and `docker compose -f docker-compose.production.yml build --no-cache app settlement-worker` plus container smoke tests in the CI/staging runner with a live Docker daemon.
- **Consequence:** `PLATFORM RELEASE ACCEPTANCE` remains `RELEASE BLOCKED`.

### 3.2 Live payment gateway & webhook provider sandbox cutover — Payments / DevOps Track

- **Status:** `BLOCKED` by explicit task constraint: no live payment provider calls and no real-money transactions.
- **What was verified locally instead:** all Stripe, PromptPay, SCB, TrueMoney, bKash, Nagad and Crypto USDT signature verification, replay protection, durable checkout, deposit integrity/review, refund recovery, follow-up sweep and withdrawal dispatch/reconciliation flows passed on SQLite and on MariaDB — `junit/gate-c-deposit-webhook-sqlite.xml` (`513 / 514` executed, `0` failures, `0` errors) and `junit/gate-c-deposit-webhook-mysql.xml` (`27 / 27`, `0` skips).
- **Required action:** perform the provider sandbox cutover with live credentials on the Payments/DevOps host.

### 3.3 Rust / native worker verification — Security/Rust Track (not blocked, but boundary preserved)

- **Status:** `PASS` — `runtime/settlement-worker` **`6 / 6`** and `security/weekly-result-integrity` **`37 / 37`**, both `cargo test --locked` at `rustc`/`cargo` `1.93.1`.
- **Boundary preserved:** no Rust or native source file was modified in this iteration. The Rust paths present in the working tree are byte-identical to the prior evidence run (verified by SHA-256 comparison in `changed-files-manifest.csv`).

---

## 4. Explicit Statements Required by the Task

1. **`BACKEND IMPLEMENTATION`:** `PASS`. All 35 Backend/Audit-owned non-green cases are closed with fresh, source-bound evidence at `2c7ab9c`; `0` Backend-owned required tests fail, error, or remain unexecuted.
2. **`FINANCIAL LOGIC`:** `PASS`. Double-entry balance, exact arithmetic, idempotency/conflict, atomic rollback, append-only reversal, reconciliation, hold lifecycle, transfer/conversion economics, bet purchase/amendment/cancellation, GLO L6, result publication and chunked settlement are all green on SQLite **and** MariaDB with `0` failures and `0` errors.
3. **`PLATFORM RELEASE ACCEPTANCE`:** `RELEASE BLOCKED` — kept separate from the implementation verdict, because the live Docker build, the live payment cutover, and the 14 Frontend Blade `H6` assertions are all still open in their owning tracks.
4. **Did any Frontend-owned file change?** **`NO`.** Verified by SHA-256 comparison against the prior evidence bundle; `changed-files-manifest.csv` shows zero Frontend rows marked `MODIFIED THIS ITERATION`.
5. **Did any DevOps/Rust/Android-owned file change?** **`NO`.** Same verification; `docker/**`, `.github/workflows/**`, `security/**`, `runtime/**`, `android/**` are all byte-identical to the prior run.
6. **GitHub push status:** `NOT PUSHED — owner will review/download and push`. No commit, tag, push, deploy or network operation was performed.
