# Backend Implementation Report — Frontend `H6` Closure

- **Evidence directory:** `artifacts/backend-acceptance/20261023T124608Z_2c7ab9c_close14_h6/`
- **Source:** branch `backend-next` @ `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7`
- **Scope of this iteration:** close all **14** Frontend Blade `H6` failures handed off by `20261023T095500Z_2c7ab9c_close35`.
- **Outcome:** 14 / 14 closed. **0 regressions** (proved by identical-set baseline, `BACKEND-TEST-RESULTS.md` §5).

---

## 1. Scope decision recorded in the prior iteration, reversed here

The prior iteration deliberately changed **no** Frontend-owned file and carried the 14
`H6` assertions as a separate Frontend handoff. For this iteration the owner explicitly
reversed that: Frontend Blade / CSS / translation files **may** be edited to make the
14 assertions green, and the ZIP must be rebuilt afterwards.

**Unchanged constraints.** No assertion was weakened, deleted, skipped, quarantined,
re-labelled or rewritten. No test was marked skipped or incomplete. No stub, fixture or
synthetic PASS was introduced. No `|| true` and no ignored exit code. JS/Vite assets,
DevOps (Docker/Compose/Nginx/workflows/runtime pins) and Rust/native worker code remain
untouched. Docker-hosted smoke tests and the live payment cutover remain prohibited and
are reported as **blocked**, never as passed.

**Files changed this iteration — 5, all Frontend-owned:**

| File | Effect |
|---|---|
| `resources/views/layouts/admin.blade.php` | rewritten to derive the console chrome from `AdminConsoleNav` |
| `resources/css/admin-lottofin.css` | dead rules removed; avatar repaired |
| `resources/views/player/bet.blade.php` | builder + slip always rendered; balance and ceiling corrected |
| `resources/views/player/draw-detail.blade.php` | heading now identifies the draw |
| `lang/en/home.php` | home disclaimer names the GLO |

Verified with `changed-files-manifest.csv`: **5 rows** carry
`iteration_effect = MODIFIED THIS ITERATION (Frontend H6)`; **0** Backend,
Native/Script, DevOps or test files were touched.

---

## 2. `AdminConsoleChromeTest` — 7 cases

**Root causes.** The sidebar was hand-written markup with `active` hardcoded on
Dashboard and no `aria-current` anywhere, so *no* item was ever marked current (and on
a non-dashboard panel nothing was current at all). The header rendered a literal
"Administrator", a chevron `M19 9l-7 7-7-7`, and a notification bell
(`lf-notification-btn` / `lf-notification-badge`) plus `lf-floating-help`, all of which
the suite bans. The stylesheet still carried the dead `.lf-notification-btn`,
`.lf-notification-badge` and `.lf-floating-help` rules, and `.lf-user-avatar` used
`display: inline-flex` with `object-fit` on a non-replaced element.

**Fixes.**

- The sidebar is now generated from `\App\Support\Admin\AdminConsoleNav::visibleFor(auth()->user(), $panel ?? null)`.
  That method already resolves each item's `active` flag and drops items the operator
  may not open by calling the same `AdminAccess::mayOpenPanel()` predicate the
  controller uses, so the navigation and the authorizer are now one implementation of
  one rule rather than two that could drift.
- `$panel ?? null` — deliberately **not** `$panel ?? 'dashboard'`. `release-operations`
  passes no panel from a different controller, and on that screen nothing in the sidebar
  is current. Defaulting the panel would re-introduce the hardcoded-active defect the
  suite exists to catch.
- Group headers come from `AdminConsoleNav::groupsPresent($navItems)`, so the
  `Financial` header appears only when that group actually has a visible item.
- The header now shows the authenticated operator's name, initials derived from that
  name (`Nusrat Jahan → NJ`, `Anwar Hossain → AH`), and the role read from the
  permission tables. The chevron, the notification bell and the floating-help button are
  gone. `id="sidebarToggle"`, `id="adminSidebar"` and `route('logout')` are retained.
- CSS: the three dead rule sets were deleted; `.lf-user-avatar` is now `display: flex`
  with `justify-content: center` and the design tokens `--lf-bg-card-hover` /
  `--lf-text-primary`, with no image-fit declaration; `.lf-user-profile` no longer
  claims `cursor: pointer` (there is no dropdown behind it).

**A defect found while doing this.** Blade's `{{ }}` escapes quotes, so emitting
`aria-current="page"` through an echo produced `aria-current=&quot;page&quot;` and the
sidebar still parsed as having no current item. The attribute is now emitted with
`@if`, which is why the fix needed two attempts — the first passed 6 of 7 and failed on
exactly the `aria-current` count.

**Result:** `AdminConsoleChromeTest` — **10 / 10 tests, 212 assertions, OK.**

---

## 3. `BrowserBetFinancialBoundaryTest` (2) and `PlayerFrontendModulesTest` (3) — 5 cases

**Root causes.** `resources/views/player/bet.blade.php` wrapped the whole bet builder
and the slip inside `@if ($openDraw) … @else`, so with no open draw the page rendered
**zero** `<option>` tags — the market vocabulary and the slip ceiling were invisible to
the browser exactly when there was nothing to wager on. `data-max-items` echoed
`config('lottery.bulk.max_items')` raw, so a configured value of 500 published a
ceiling the `BulkBetRequest` `max:50` rule refuses. The balance shown was
`$wallet->balance`, overstating spendable money by the held amount.

**Fixes.**

- The builder and the slip now render whether or not a draw is open. Only the wrapper
  changes: a real `<form data-bet-form>` when a draw is open, a plain `<div>` when it is
  not — so `assertDontSee('data-bet-form')` still holds. The "no open draw" notice is
  kept, and the purchase controls are disabled in that state.
- `data-max-items` is now `min((int) config('lottery.bulk.max_items', 50), 50)`. The
  browser can no longer be told a ceiling the API will reject: `500 → 50`, `3 → 3`.
- The balance shown is the wallet's **available** balance
  (`Wallet::getAvailableBalance()` = `bcsub(balance, locked_balance, 2)` → `24.75` for a
  `500.00` wallet holding `475.25`), not the raw `500.00`. The win wallet's `9,999.00`
  never renders — the controller already selects `WalletType::Primary` first.
- `data-draw-id` is null-safe (`{{ $openDraw?->id }}`). `data-digits`,
  `data-multiplier` and the option values already matched the controller's
  `(int) digits` / `(float) payout_multiplier` / market key and were left alone.

**Result:** `BrowserBetFinancialBoundaryTest` — **20 / 20, 114 assertions, OK.**
`PlayerFrontendModulesTest` — **11 / 11, 127 assertions, OK.**

---

## 4. `PlayerExperienceComprehensiveTest::test_15` — 1 case

**Root cause.** `player/draw-detail` rendered only the bare draw number; the page never
contained the string `Draw #{draw_number}`.

**Fix.** The chip above the board now reads `Draw #{{ $draw->draw_number ?? $draw->id }}`.

**A constraint that had to be respected.** The first attempt prefixed the `<h1
id="draw-detail-heading">` instead, which broke
`PlayerPresentationContractTest::test_draw_heading_and_localized_title_identify_the_requested_record`
— that suite asserts the `<h1>` contains **exactly** the draw number, with the comment
"not merely a string elsewhere in the response or an English prefix that contradicts the
Thai view". The prefix therefore went on the chip, and the `<h1>` was restored. Both
suites now pass.

**Result:** `test_15` — OK. `PlayerExperienceComprehensiveTest` — **41 / 41, 164
assertions, OK.** `PlayerPresentationContractTest` — **14 / 14, 143 assertions, OK.**

---

## 5. `PublicContentSourceTest` — 1 case

**Root cause.** `/` contained neither `Government Lottery Office` nor
`not the official GLO website`.

**Fix, and the constraint that shaped it.** The shared footer
(`components/public-page/footer.blade.php`) renders on **every** public page, including
`/contact`, where `ContactPageTest::test_34_no_government_endorsement_is_claimed`
asserts `Government Lottery Office` must **not** appear. Putting the statement in the
shared footer therefore satisfied one suite and broke another. The statement was placed
on the home page instead, by extending the existing
`lang/en/home.php` `independent_disclaimer` string (which the trust section already
renders) rather than adding a new key, and the shared footer disclaimer was left
byte-identical to its prior content.

**Result:** `PublicContentSourceTest` — **7 / 7, 377 assertions, OK.**
`ContactPageTest` — OK. `GloCopyNeutralityTest` — OK. `TranslationKeyIntegrityTest` — OK.

---

## 6. Zero-regression verification

The five edited files were reverted to their pre-edit content and the failing suites
re-run. Every comparison below is a **byte-identical failing set**:

| Baseline | True baseline (pre-edit) | After the fixes |
|---|---|---|
| 35 failing test classes | 92 failures, 24 errors | 92 failures, 24 errors — identical set |
| `Home/Contact/About/Glo/Locale/Unit.Lang` | 513 tests, 21 failures, 3 errors | identical set |
| `gate-a` scope | 227 tests, 3 failures | identical set |
| `gate-h-database-seeders` | 16 tests, OK | OK |

The 117 remaining failures/errors are all pre-existing and none is an `H6` case —
see `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`.

---

## 7. Deliverables in this evidence directory

| File | Contents |
|---|---|
| `BACKEND-IMPLEMENTATION-REPORT.md` | this document — the 14 fixes and their root causes |
| `BACKEND-TEST-RESULTS.md` | per-run and deduplicated counts, regression proof |
| `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md` | the 117 pre-existing failures by owning track |
| `BACKEND-RELEASE-GATE-MATRIX.csv` | gate-by-gate commands, evidence paths, exit codes |
| `ZIP-VERIFICATION.md` | archive integrity, checksums, exclusions |
| `source-identity.json`, `environment.json` | source tree hashes and runtime versions |
| `changed-files-manifest.csv` | 1,456 rows with `iteration_effect` ownership column |
| `checksums.sha256` | SHA-256 of every file in this directory |
| `junit/*.xml` (19) | raw JUnit evidence |
| `logs/*.log` (37) | raw command output |
| `pre-fix-logs/`, `post-fix-logs/` | paired before/after reproduction for every `H6` case |
| `changed-source/` (6 files) | the five modified source files, byte-identical to the working tree, plus an ownership rationale — satisfies the Phase 5 requirement that the bundle carry the changed source |

## 8. Verdicts

| Verdict | Result |
|---|---|
| **FRONTEND `H6` CLOSURE** | **PASS — 14 / 14 closed, 0 regressions** |
| **BACKEND IMPLEMENTATION** | **PASS** (unchanged from the prior iteration; no Backend file touched) |
| **FINANCIAL LOGIC** | **PASS** (unchanged) |
| **PLATFORM RELEASE ACCEPTANCE** | **RELEASE BLOCKED** — Docker-hosted smoke tests unavailable, live payment-gateway cutover prohibited, and 117 pre-existing failures (51 Frontend, 65 Backend, 1 DevOps) remain open |

### This bundle is **NOT** a FINAL / 100%-complete ZIP

Phase 5 forbids packaging a `FINAL / 100% COMPLETE` ZIP while any required
Backend-owned test fails, errors or remains unexecuted. **117 pre-existing
failures/errors remain open, 65 of them Backend-owned** (see
`BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`). The archive is therefore named
`BACKEND-H6-CLOSE-14-NOT-FINAL-BACKEND-NOT-100PCT-2c7ab9c.zip` and labelled
`NOT FINAL — BACKEND NOT 100%` in `ZIP-VERIFICATION.md`.

**Exact closure that remains:** the 65 Backend-owned cases — 22 Filament admin resource
cases, 21 operations-reporting cases, 8 realtime money-chain cases, 5 architecture/API
cases, 5 static-page-contract cases, 3 HTTP trusted-proxy cases, and 1 integration
concurrency case (`PasswordLifecycleRealEngineTest`, a genuine security defect in which a
login succeeds with an already-superseded password). None of these is an `H6` case and
none was introduced by this iteration.

GitHub push status: **NOT PUSHED — owner will review/download and push.** No commit,
tag, push or deploy was performed; `HEAD` remains `2c7ab9c`.
