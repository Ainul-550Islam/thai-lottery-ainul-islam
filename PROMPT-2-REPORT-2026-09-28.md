# PROMPT 2 DELIVERY REPORT — Account Grade Programme + Discount-of-Game Matrix

**Date:** 2026-09-28 · **Workspace:** `/home/user/thai-lottery` · **Commit:** `eeb7162`
**Final validation:** 1558 passed / 7 skipped / 0 failed / 103,963 assertions

---

## 1. Executive Summary

Prompt 2 closed the Account Grade + Discount-of-Game gap with a complete,
test-verified surface: five benchmark grade tiers over a rolling 30-day
qualifying-spend window, a canonical National/Bangkok-Weekly discount matrix,
an additive grade→game application policy, a self-scoped account-grade API,
append-only grade history with a fingerprint-idempotent rebuild command, and
public page/JS surfaces for all of it.

Mid-delivery, the workspace suffered an infrastructure loss (snapshot file-cap
truncation). The project was restored by merging the GitHub base
(`Ainul-550Islam/thai-lottery-ainul-islam`, the mid-Prompt-1 push of
2026-09-27) back over the surviving local-newest files, then repairing every
incompatibility where an older gap-restored file met newer local views, routes
and tests. The full suite — the local-newest tests, which encode the newest
contracts — is green with **zero failures**, at a higher count than the
pre-loss baseline (1558 vs 1550 tests; 103,963 vs 103,682 assertions).

All binding decisions held end to end: benchmark tiers under rule version `2`;
entitlements gate only the 18-game DiscountGame space; **THB, never USD**;
live bet economics untouched; the calculator serves the matrix/quote surface
only; every money value is a decimal string computed server-side with bcmath.

---

## 2. Deliverables — File Inventory (A–W sections)

Every Prompt-2 file below exists on disk in full (no stubs, no `... existing
code` markers), passes `php -l`, and is covered by the tests in section 4.

| # | Section | File | Role |
|---|---|---|---|
| 1 | A — Config | `config/account_grades.php` | Tier ladder (200/300/400/500/600 THB), rates, `grade_period_days`, `rule_version '2'` |
| 2 | A — Config | `config/lotto_discount_matrix.php` | 18-game matrix rows, label_key → `prize_discount.matrix_*`, `rule_version '1'` |
| 3 | B — Enums | `app/Enums/AccountGradeLevel.php` | bronze + 5 programme levels, `sl()`, `isBase()` |
| 4 | B — Enums | `app/Enums/DiscountGame.php` | 18 game cases; `publicGames()` = national 10 then weekly 8 |
| 5 | B — Enums | `app/Enums/DiscountLottery.php` | National / BangkokWeekly; `hasDrawRegularModes()` = 7 headline games |
| 6 | C — DTOs | `app/DTOs/Account/GradeTier.php` | min spend, rate fraction, entitled games; `discountPercent()` → `'2.00'` |
| 7 | C — DTOs | `app/DTOs/Account/GradeEvaluationResult.php` | window, spend, level, rates, entitlement hash, `ruleVersion '2'`, `sourceVersion '2'` |
| 8 | C — DTOs | `app/DTOs/Account/GradeDiscountEntitlement.php` | one entitled game + its discount rate |
| 9 | C — DTOs | `app/DTOs/Lottery/DiscountRule.php` | headline D/R modes + variants; `discountDisplay()` → `'35.00%'` |
| 10 | C — DTOs | `app/DTOs/Lottery/DiscountMatrix.php` | typed matrix projection with status |
| 11 | D — Rules | `app/Rules/StrictMoneyAmount.php` | decimal-string money validation |
| 12 | D — Rules | `app/Rules/ValidDiscountPercentage.php` | percentage range validation |
| 13 | E — Services | `app/Services/Account/GradeTierCatalog.php` | inclusive thresholds, highest-wins resolution, base bronze |
| 14 | F — Services | `app/Services/Account/AccountGradeEvaluator.php` | the ONE evaluator (exact rolling window; spend delegated to the canonical counter) |
| 15 | G — Services | `app/Services/Account/GradeDiscountEntitlementService.php` | per-tier entitled-game sets; stable `entitlementHash` |
| 16 | H — Services | `app/Services/Account/GradeDiscountApplicationPolicy.php` | STACKED / GAME_ONLY / GRADE_ONLY resolution, `max_total_percentage` ceiling |
| 17 | I — Services | `app/Services/Lottery/CanonicalDiscountMatrixService.php` | single source for matrix rows (labels, states, ×2..×7 variants) |
| 18 | I — Services | `app/Services/Lottery/LottoDiscountCalculator.php` | quote/preview engine; refusal key `GLO_PRICE_IMMUTABLE` for GLO |
| 19 | I — Services | `app/Services/Lottery/DiscountParityProjectionService.php` | page/API projection: matrix, tiers, games panels |
| 20 | J — Models | `app/Models/AccountGradeSnapshot.php` | append-only history; `fingerprint` unique |
| 21 | J — Models | `app/Models/GradeDiscountSnapshot.php` | per-grade snapshot of entitlements |
| 22 | K — Policy | `app/Policies/AccountGradePolicy.php` | self-scope only (suspended → 403) |
| 23 | L — Console | `app/Console/Commands/RebuildAccountGradeSnapshots.php` | `grades:rebuild-snapshots` — see section 3.4 |
| 24 | M — Migrations | `database/migrations/2026_09_27_220001_create_grade_discount_snapshots_table.php` | |
| 25 | M — Migrations | `database/migrations/2026_09_27_220002_create_account_grade_snapshots_table.php` | |
| 26 | N — Views | `resources/views/components/account-grade/grade-tier.blade.php` | one tier card |
| 27 | N — Views | `resources/views/components/account-grade/discount-games.blade.php` | entitled-games panel |
| 28 | N — Views | `resources/views/components/discount/game-rule.blade.php` | one matrix row |
| 29 | O — Controllers | `app/Http/Controllers/Api/V1/AccountGradeApiController.php` | `show` + `entitlements` (sanctum) |
| 30 | P — Controllers | `app/Http/Controllers/AccountGradeController.php` | `show` / `history` / `refresh` (web, auth) |
| 31 | Q — Base | `app/Http/Controllers/Controller.php` | restored base class (rebuild target after the loss) |
| 32 | R — Tests | `tests/Feature/Account/AccountGradeProgrammeTest.php` | 26 tests / 185 assertions |
| 33 | S — Tests | `tests/Feature/Lottery/DiscountMatrixTest.php` | 30 tests / 271 assertions |
| 34 | T — Tests | `tests/Feature/Console/GradesRebuildSnapshotsTest.php` | 10 tests / 55 assertions (written last, this session) |

**Surviving pre-existing files Prompt 2 extended** (all present, all green):
`routes/api.php` (v1/account grade group + `POST /api/v1/fees/preview`),
`routes/web.php`, `AuthServiceProvider`, `PublicAccountInfoService`,
`resources/views/account-info/grades.blade.php`, `resources/views/account/grade.blade.php`,
`resources/js/public-pages.js` (grade games panels + fee-preview handler),
lang `en/th` (`account_info` +42, `account_services` +8, `prize_discount` +178 keys),
CSS blocks on 3 stylesheets, `.env`/`.env.example` (`ACCOUNT_GRADES_RULE_VERSION=2`).

**Restoration-compatibility fixes** (older gap-restored files brought up to the
local-newest contracts): `PublicServicePagesController` (groups-shaped fees
page + server-authoritative `feesPreview`), `LottoDiscountController`
(matrix projection re-wired), `FinancialReconciliationService` (fee-aware
journal basis), `AccountServicesPagesTest` (benchmark ladder edits #33/35/36/37),
`GradeTier::discountPercent()` (plain `'2.00'`, no `%` suffix),
`LottoDiscountCalculator` (DTO import + `roundHalfUp` unit bug `'0.01'`).

---

## 3. Business Rules & Binding Decisions (all test-verified)

### 3.1 The benchmark ladder (rule_version `'2'`)

| Tier | Min qualifying spend (30-day rolling, THB) | Discount fraction | Entitled games |
|---|---|---|---|
| bronze (base) | 0.00 (below 200.00) | — (no advertised rate) | — |
| gold_plus | 200.00 | 0.0200 | 4 |
| platinum | 300.00 | 0.0300 | 6 |
| platinum_plus | 400.00 | 0.0400 | 10 |
| diamond | 500.00 | 0.0500 | 13 |
| diamond_plus | 600.00 | 0.0600 | 18 |

Thresholds are **inclusive**; the **highest** matching tier wins. Spend =
Completed financial transactions with `processed_at` inside the exact window
(`asOf − 30d … asOf`). Every monetary boundary is a decimal-string comparison
(`bccomp`), never a float. The ladder is config-driven
(`config/account_grades.php`), nothing hardcoded in Blade or controllers.

### 3.2 The discount matrix (rule_version `'1'`)

- National: `six_digit` D3000/R500 35%, `3_up` D500/R100 30%,
  `2_up`+`2_down` D80/R40 20%.
- Bangkok Weekly: `6_ball` D1000/R500 35%, `3_ball` D200/R60 20%,
  `2_ball` D50/R25 15%.
- 11 single-multiplier variants (×2..×7) are `null` → **NOT_CONFIGURED**
  states, never invented numbers.
- Affiliate rows 2%/2%; `base_stake` 1.00 **THB** (no USD anywhere).
- Labels resolve through `prize_discount.matrix_*` (178/178 keys en+th).

### 3.3 Application policy (grade × game)

`resolve(base, game, tier)` is **additive** with an honest ceiling:

- `resolve('100.00', six_digit, diamond_plus)` → game 35.00 + grade 3.90 =
  total 38.90, net 61.10 (**STACKED**).
- Anonymous (no grade), D-mode 100.00 six_digit → 35.00 discount, 65.00 net
  (**GAME_ONLY**).
- Grade entitled but game is a variant → **GRADE_ONLY**.
- Malformed amount (`'100.123'`) → `InvalidArgumentException` — never a
  silent coercion.
- Rounding is **half-up on decimal strings**: 33.33 × 35% = 11.67, net 21.66.
- Ceiling: `discounts.limits.max_total_percentage` (default `'60'`).
- Entitlements gate **only** the 18-game DiscountGame space — never GLO
  L6/N3 immutable pricing, never the result lanes, never live bet economics
  (`AccountDiscountService` caps at `max_discount_rate` 0.2500, untouched).
- GLO quotes refuse with key `'refused' => 'GLO_PRICE_IMMUTABLE'`.

### 3.4 `grades:rebuild-snapshots` (history builder)

Signature: `grades:rebuild-snapshots {--account=} {--as-of=} {--dry-run} {--chunk=200}`.

- **Idempotent by fingerprint** — sha256 over
  `user|windowStart|windowEnd|qualifyingSpend|level|ruleVersion`; the column
  is UNIQUE, so a duplicate row is impossible *by constraint*. A re-run of
  an unchanged evaluation writes nothing ("0 created, 1 unchanged").
- **Append-only** — changed evaluations append a NEW row; existing rows are
  never rewritten or deleted.
- **`--dry-run`** evaluates and reports ("would be created") but writes nothing.
- **`--as-of`** pins the exact window; an unparsable value **fails the
  command** (FAILURE exit) instead of writing a wrong window.
- **`--account`** scopes to one user; unknown id → FAILURE.
- **`--chunk`** clamped to 1..1000 (default 200).
- **There is no `--force`** — append-only history cannot be forced. The
  no-force contract is itself test-audited via reflection on the signature.

### 3.5 API + page contracts

- `GET /api/v1/account/grade` and `/entitlements` — sanctum-authenticated,
  self-scoped only: unauthenticated 401, suspended 403, `?user_id=…` ignored.
  Entitlements return every public game exactly once
  (game/label/lottery/state/rate_percent).
- `POST /api/v1/fees/preview` — **server-authoritative**: the request may
  carry only `category`, optional `provider`, `base_amount`; any
  client-supplied fee figure is ignored before the service is reached. The
  response (`fee_amount`, `currency`, `state`, `fee_display`, …) is
  exclusively the server's bcmath result via `FeesPageService::calculateResult`.
  Unknown provider or malformed base → 422.
- `GET /fees` renders grouped provider-aware rows (`publicFeeGroups`);
  `GET /api/v1/fees` returns the same rows + groups as JSON. Unpriced-but-
  enabled rows (PayPal, agent commission) render **NOT_CONFIGURED** — a
  state, never an invented number.

### 3.6 Fee-bearing ledger invariants (repaired during restoration)

Deposits journal the **NET** (`amount − fee`; the fee never reaches the
wallet — one credit, never two postings). Withdrawals journal the **GROSS**
debit with the fee as a column on that single transaction (fee comes out of
the beneficiary's payout). The reconciliation journal-match now compares on
this basis; fee-bearing flows reconcile clean (debits = credits, wallet =
ledger).

---

## 4. Test Matrix Coverage

The 86-item matrix is covered by **66 new test methods / 511 assertions**
(several methods assert multiple matrix items):

| Suite | Tests | Assertions | Covers |
|---|---|---|---|
| `AccountGradeProgrammeTest` | 26 | 185 | ladder thresholds (incl. 199.99/200.00 boundary), entitlement sets (4/6/10/13/18), evaluator window + spend counting, policy STACKED/GAME_ONLY/GRADE_ONLY + ceiling + rounding + malformed input, API auth/self-scope/403/401, page rendering, localization |
| `DiscountMatrixTest` | 30 | 271 | matrix structure (6 headline rules, 11 variants NOT_CONFIGURED), label keys en+th, calculator quotes + GLO refusal, parity projection (page = API = service), public surfaces leak no identifiers/emails, no competitor values (no `thailotto`, no `$3`, no USD), config-driven values only |
| `GradesRebuildSnapshotsTest` | 10 | 55 | dry-run writes nothing; run creates from the canonical evaluator; fingerprint idempotency; append-only (old row untouched); `--account` scoping; unknown account fails; `--as-of` pins exact window (outside-window spend excluded); invalid `--as-of` fails + writes nothing; chunk clamp (1 and 1000 observed); no `--force` |

Regression protection (Prompt-1 suites, all green): fees page/API/preview,
GLO L6/N3 immutability, National/Weekly/Bingo-Mega/PCSO result lanes,
settlement, KYC/verification, security headers, reconciliation.

---

## 5. Validation

| Check | Result |
|---|---|
| `php -l` on every touched file | clean |
| `php artisan route:list` | 260 routes; grade + fees routes registered |
| Focused suites | 26/26, 30/30, 10/10 |
| **Full suite (`php artisan test`)** | **1558 passed, 7 skipped, 0 failed, 103,963 assertions** (≈212s) |
| Pre-loss baseline comparison | 1550 tests / 103,682 assertions / 0F / 7S → now +8 tests, +281 assertions, still 0F |
| `npm run build` (Vite) | green — manifest present for view tests |
| Git | single clean commit `eeb7162` of the restored + Prompt-2 state |

---

## 6. Pre-existing Findings (carryover, report-only)

1. **`config/risk.php` vs `.env.example` divergence — RESOLVED.** After the
   GitHub merge, every `env()` key referenced by `config/risk.php` exists in
   `.env.example` (re-verified programmatically: zero missing keys).
2. **Pint style failures (~446 pre-existing).** Carried from before Prompt 2;
   policy unchanged — reported, not fixed, pending explicit instruction.
   (Not re-measured this turn: `vendor/` is archived as `vendor.tar.gz` to
   stay under the workspace snapshot file cap.)
3. **Workspace snapshot cap discipline (infrastructure, not code).** The
   workspace now keeps `vendor/` only as `vendor.tar.gz` (34 MB, complete —
   the previous archive was itself truncated and has been replaced from a
   fresh `composer install`). Never leave `vendor/` extracted at turn end.

---

## 7. Operations Notes

**Environment keys Prompt 2 relies on** (all in `.env` / `.env.example`):
`ACCOUNT_GRADES_RULE_VERSION=2`, `FEES_CURRENCY=THB` (+ fees schedule keys),
`FINANCE_DEPOSIT_FEE_PERCENTAGE` / `FINANCE_WITHDRAWAL_FEE_PERCENTAGE`
(defaults `0.00`), `DISCOUNTS_LIMITS_MAX_TOTAL_PERCENTAGE` (default `60`).

**Rebuild history (append-only, idempotent):**
```bash
php artisan grades:rebuild-snapshots                 # everybody, as of now
php artisan grades:rebuild-snapshots --account=42    # one account
php artisan grades:rebuild-snapshots --as-of="2026-09-01 00:00:00"  # pinned window
php artisan grades:rebuild-snapshots --dry-run       # evaluate, write nothing
```

**Next-turn test recipe** (fresh sandbox):
```bash
sudo apt-get install -y php8.4-cli php8.4-mbstring php8.4-xml php8.4-sqlite3 \
  php8.4-bcmath php8.4-curl php8.4-zip php8.4-intl
cd /home/user/thai-lottery && tar -xzf vendor.tar.gz
php artisan test          # 1558 passed / 7 skipped / 0 failed
# ... then: rm -rf vendor   (keep the tarball; snapshot file cap)
```

**Restoration provenance:** base = `github.com/Ainul-550Islam/thai-lottery-ainul-islam`
(main, 2026-09-27 push); merge outcome 867 copied / 606 identical / 25
local-kept (local verified newer in all 25). Details:
`RESTORATION-STATUS-2026-09-28.md`.
