# External Audit Response 2 — 2026-09-28

Response to the 50-target page-by-page live-site vs ZIP audit. This pass
delivered the **P0 wallet/payment wiring** plus the remaining URL bridges;
several Section-1 "URL gap" rows were already closed by the previous pass
(commit `620185e`) — the audit ran against the older ZIP, so each row below
states where its fix actually shipped.

**Test proof for this pass: `1698 passed / 7 skipped / 0 failed — 105,152
assertions`** (previous pass: 1677 / 105,075; +21 tests, +77 assertions,
0 regressions).

---

## 1. The P0 item: the web wallet now reaches the real finance engine

| # | Finding | Disposition |
|---|---|---|
| W1 | Web deposit form not connected to the payment engine | ✅ **FIXED** |
| W2 | Web withdrawal form not connected to the withdrawal engine | ✅ **FIXED** |
| W3–W6 | Min/max/processing-time mismatches | ✅ **FIXED** — config-driven |
| W7/W8 | Payment-method mismatches (PromptPay/TrueMoney vs enum) | ✅ **FIXED** — closed config list |
| W9 | Float casting in wallet/withdraw UI | ✅ **FIXED** — exact-decimal formatting |
| W10 | Strong wallet architecture (positive) | ✅ preserved — the web adapter now feeds it |

### What `storeDeposit()` does now (`app/Http/Controllers/Web/PlayerWebController.php`)

1. Validates amount against **`config('payment.deposit.min/max')`** and the
   method against **`payment.deposit.allowed_methods`** (the closed
   `PaymentMethod` vocabulary) — plus a one-time `idempotency_key` rendered
   into the form.
2. Resolves the player's THB wallet.
3. Calls **`DepositService::request()`** — the same service the rest of the
   platform uses: responsible-gaming gate, wallet lock, exact-decimal
   `assertAmountWithinLimits()`, fee/net resolution, persisted `deposits`
   row with a `DP-…` reference number and `pending` status, idempotent
   replay by key.
4. Redirects with the **real reference number, real amount, real status** —
   or the engine's own refusal message.

A double-submit replays the same key and gets the same deposit back: one
form render can never create two orders.

### What `storeWithdraw()` does now

1. Validates amount against **`payment.withdrawal`** config, method against
   its allowed list, destination fields (bank fields required for
   `bank_transfer`), idempotency key.
2. Calls **`WithdrawalService::request()`** — responsible-gaming gate, the
   server-authoritative **KYC gate** (`ComplianceKycGate`, threshold from
   config), sufficiency check, persisted `withdrawals` row with a `WD-…`
   reference and encrypted payout details.
3. Reserves the funds via **`WalletHoldService::hold()`** — the same
   reservation the API controller makes; `locked_balance` rises by exactly
   the amount (asserted in tests).
4. Handles `InsufficientBalanceException`, `WithdrawalKycException` and
   `WithdrawalException`/`FinancialException` as honest error flashes —
   nothing is recorded when the engine refuses.

### Limits, methods, processing time — one source of truth

`deposit()`/`withdraw()` now pass limits and the method list from the
**same config the engine enforces**, and the views render them. Notes:

- The audit's W3/W4 numbers compared the old hard-coded view values against
  the **code defaults** (50/100). The deployed `.env` actually sets
  `FINANCE_MIN_DEPOSIT=100.00` and `FINANCE_MIN_WITHDRAWAL=200.00` — the
  live rule was always env-driven config. The defect was the page
  hard-coding numbers that followed *no* config; now it follows whatever
  the operator configures, at runtime.
- Processing time renders `within {processing_hours} hours` (24 by default)
  — the invented "5–15 mins" promise is gone.
- The deposit page advertises **only** `stripe/bkash/nagad/crypto` (its
  configured list); the withdrawal page **only**
  `bkash/nagad/bank_transfer/crypto`. The legacy marketing line
  "PromptPay QR, TrueMoney" is gone. (`PromptPayPaymentService` — a real
  EMVCo QR implementation — exists in the payment layer but has no
  `PaymentMethod` case and no `allowed_methods` entry, so the deposit UI
  does not advertise it; enabling it is a config + enum decision for the
  operator, not a UI claim.)

### Money display (W9)

All player-facing monetary output now goes through
`Money::of(<decimal string>, Currency::THB)->format()` — BCMath-exact
grouping with the ฿ symbol, never a float cast: the layout wallet pill,
dashboard, wallet, bets, deposit and withdraw views. The withdraw page's
"available" figure is computed with `bcsub()`, not float subtraction.

---

## 2. Security fixes

| # | Finding | Disposition |
|---|---|---|
| S1 | `.env` in ZIP | ✅ carried fix — the new ZIP excludes it (verified: 0 matches); **rotate previously exposed credentials** |
| S2 | Password policy drift (`min:8` on profile surfaces) | ✅ **FIXED** — web `updatePassword` and API `ProfileController` now use the centralised `StrongPasswordRule` (config-driven length + letters/digits + common-password list) |
| S3 | GLO official source defaults to fixture | ✅ **FIXED** — `glo:publish-public-result` Gate 0: in **production** with fixture mode it refuses with an explicit error unless `--allow-fixture-in-production` is passed; test-covered for all four combinations |
| S4 | Dashboard fallback draw/result values | ✅ **FIXED** — and deeper than the audit knew |
| S5/S6 | Positive findings | ✅ preserved untouched |

### The S4 fix uncovered a second bug

The dashboard referenced `$openDraw->betting_closes_at` — an attribute that
**does not exist** (the real column is `betting_close_at`). So the
fabricated countdown (`04 : 18 : 32`, `2026-09-16T15:00:00+07:00`) was shown
**always**, even with a live open draw. Both are fixed: the page reads the
real column, and every fabricated fallback is replaced by an explicit
unavailable state (`Not scheduled`, `TBA`, `—`). The pre-existing
`PlayerFrontendModulesTest` countdown test was itself satisfied by the fake
instant — it now seeds a real open draw and asserts the rendered ISO
instant comes from it.

Also aligned with the codebase's own identity policy: the dashboard tagline
dropped its "Official Government Lottery" wording (three existing
public-page tests assert `assertDontSee('Official Government')` — the
dashboard now matches the policy those tests encode).

---

## 3. Section 1 matrix — the 50 targets

| Rows | Status |
|---|---|
| #2 `/index.php` | ✅ **added this pass** → 301 → `/` |
| #9 `/prize-verify.php` | ✅ **added this pass** (both spellings mapped) |
| #10 `/discount.php` | ✅ **added this pass** (both spellings mapped) |
| #44/#45 profile, profile-password | ✅ **added this pass** → 301 → `/profile` |
| #50 logout.php | ✅ **added this pass** → 301 → `/` (deliberately **not** the logout action: modern logout is a CSRF-protected POST and a redirected legacy GET must never be able to log anybody out) |
| #3,4,6,11,20,27,32,37 static pages; #12–19, 21–26, 28–31, 33–36 archives; #46–49 broken member URLs; login/register/forgot_password | ✅ shipped in the **previous pass** (commit `620185e`) — the audit's ZIP predated it. Legacy archive redirect test now covers 9 archive forms, 16 static pages, 6 broken member URLs |
| #5 terms/prize content | ⛔ business sign-off required — see §4 |
| #7/#8 account-verify / account-grade | ✅ URL bridged (previous pass); the public-guide + authenticated-workflow split is intentional (IDOR posture) |
| #38–43 myaccount/secure subdomains | ⚠️ **infrastructure step**: a Laravel route can only see the host it serves. Making `myaccount.`/`secure.` 301 to the canonical domain is a DNS/webserver redirect to configure at cutover (documented); the path-level `.php` bridges inside the app are all in place |
| Data gaps (#12–36 "DB parity") | ✅ wired via `ResultArchiveSeeder` (previous pass) — populating production history from your system of record remains the operational step |

## 4. Intentional — not changed without your instruction

- **O3 / matrix #5 (Terms, ticket price, prize structure):** the standing
  constraint is *"Do NOT break: GLO L6/N3 immutable pricing."* The live
  Terms' 40-THB wording is that operator's own product claim. A migration
  needs your signed-off source-of-truth table (price, tiers, tax,
  claim rules) — then it's a deliberate change, not a parity patch.
- **O7 (GLO tax config):** same category — `tax_withheld` flags must be
  reconciled against authoritative policy by whoever owns the money; code
  changes there are not made silently.
- **O2 (identity):** independent-platform positioning stays (the codebase's
  own tests enforce it); the dashboard tagline was aligned to it this pass.

## 5. Files changed this pass

| File | Change |
|---|---|
| `app/Http/Controllers/Web/PlayerWebController.php` | real wiring: `DepositService`, `WithdrawalService`, `WalletHoldService`, config-driven validation, idempotency keys, honest error handling; `methodOptions()`; `StrongPasswordRule` on password update |
| `resources/views/player/deposit.blade.php` | rewritten: config-driven methods/limits, idempotency key, flash feedback, recent deposits, no invented gateways |
| `resources/views/player/withdraw.blade.php` | rewritten: config-driven methods/limits/processing window, bcsub available-balance, method-aware destination fields, no-JS safe |
| `resources/views/player/dashboard.blade.php` | fake fallbacks → explicit unavailable states; real `betting_close_at` column; Money::format; identity-neutral tagline |
| `resources/views/player/wallet.blade.php`, `bets.blade.php`, `layouts/app.blade.php` | exact-decimal money display (no float casts) |
| `app/Http/Controllers/LegacyRedirectController.php` | + `index.php`, `prize-verify.php`, `discount.php`, member `profile.php`/`profile-password.php`/`logout.php` |
| `app/Console/Commands/GloPublicResultPublishCommand.php` | Gate 0 production/fixture refusal (+ `--allow-fixture-in-production`) |
| `app/Http/Controllers/Api/V1/ProfileController.php` | `StrongPasswordRule` (no `min:8` drift) |
| `tests/Feature/Web/PlayerWalletWiringTest.php` | new — 11 tests / 49 assertions (page config-rendering, real deposit row, idempotent replay, method/limit refusals, withdrawal row + hold, insufficient balance, password policy, dashboard unavailable state) |
| `tests/Feature/Console/GloPublishProductionGuardTest.php` | new — 4 gate combinations |
| `tests/Feature/LegacyRedirectTest.php` | + 6 URL targets (39 tests total) |
| `tests/Feature/Player/PlayerFrontendModulesTest.php` | countdown test now seeds a real draw (was satisfied by the fabricated instant) |
| `thai-lottery-clean-build-2026-09-28.zip` (repo root, outside git) | rebuilt: fresh `public/build`, no `.env` |

## 6. Proof

- `PlayerWalletWiringTest` — **11 passed, 49 assertions**
- `LegacyRedirectTest` — **39 passed**; `GloPublishProductionGuardTest` — **4 passed**
- `PlayerFrontendModulesTest` — **11 passed, 127 assertions**
- Full suite — **1698 passed / 7 skipped / 0 failed / 105,152 assertions**
- Clean ZIP verified: `public/build/manifest.json` present, `.env` absent
