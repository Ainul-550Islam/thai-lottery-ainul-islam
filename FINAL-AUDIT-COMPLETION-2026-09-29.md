
---

# 23. COMPLETION REPORT — 2026-09-29 (FINAL AUDIT IMPLEMENTATION PASS)

Appended per §22. Baseline before this pass: 1712 tests / 7 skipped / 0
failed / 105,246 assertions (commit `adc90a7`). After this pass:
**1825 passed / 7 skipped / 0 failed / 106,156 assertions**
(`php artisan test`, full suite, 2026-09-29). +113 tests, +909 assertions.

`ITEM-1` (P0 web deposit → orchestrated initiation) # **CLOSED**
- Files: `app/Http/Controllers/Web/PlayerWebController.php` (storeDeposit
  now calls `PaymentInitiationService::initiateDeposit()`, branches on
  gateway response: redirect-away for hosted checkout, operator
  instructions block for manual settlement, honest error flash on
  provider refusal), `app/Services/Payment/PaymentInitiationService.php`
  (capability gate runs BEFORE any row is created), `resources/views/player/deposit.blade.php`
  (manual-instructions block).
- Tests: `tests/Feature/Payment/PaymentInitiationServiceTest.php` (6 tests:
  happy path with provider reference, disabled-gateway/method/currency
  refusals persist NOTHING, idempotent replay returns same deposit+payment);
  `tests/Feature/Web/PlayerWalletWiringTest.php` (deposit tests rewritten:
  hosted-checkout redirect asserted, Payment aggregate asserted,
  instructions flow, fail-closed flow).
- Verified: the API path (`Api/V1/DepositController::store`) was already
  wired through the same service — no duplication introduced.
- Production config: each launched gateway needs `*_ENABLED=true` +
  credentials in env.

`ITEM-2` (P0 browser callback routes) # **CLOSED**
- Files: `app/Http/Controllers/Web/PaymentCallbackController.php` (NEW),
  `app/Services/Payment/PaymentCallbackService.php` (+ read-only
  `browserReturnProjection()`), `routes/web.php` (4 routes registered from
  the same `payment.callback` config block the drivers use, normalized for
  absolute URLs, behind `auth`), `resources/views/payment/callback.blade.php`
  (NEW), `lang/{en,th}/account_services.php` (+24 keys).
- Contract locked: landing route is context only; the displayed state is
  the internal payment record; query params are lookup keys, never proof;
  another player's reference is reported not-found; pages mutate nothing.
- Tests: `tests/Feature/Web/BrowserPaymentCallbackTest.php` (9 tests:
  auth required ×4, pending-on-success-route, captured-only-confirmed,
  forged flags cannot manufacture paid, not-found, cross-player
  not-found, honest cancel/failure, no state mutation);
  `tests/Feature/Payment/PaymentCallbackServiceTest.php` (8 tests incl.
  read-only proof: statuses/balances unchanged after projection).

`ITEM-3` (P0 Stripe cancel URL) # **CLOSED**
- Files: `app/Services/Payment/Drivers/AbstractPaymentGateway.php`
  (+`cancelUrl()`, +`pendingUrl()`), `app/Services/Payment/Drivers/StripeGateway.php`
  (`cancel_url` now uses the CANCEL return), `config/payment.php`
  (callback comment rewritten: routes exist).
- Tests: `tests/Feature/Payment/StripeGatewayCallbackUrlTest.php` (2 tests:
  request payload pinned to `/payment/cancel` and NOT `/payment/failure`;
  missing secret fails closed with zero HTTP calls).

`ITEM-4` (P0 fabricated bank identity) # **CLOSED**
- Files: `app/Services/Payment/Drivers/BankTransferGateway.php`
  (operator-configured settlement only; `supportsDeposit()` fails closed
  when settlement is incomplete, so the capability guard refuses BEFORE a
  deposit row exists; withdrawal capability unaffected — payout details
  are player-supplied), `config/payment.php` (+`gateways.bank_transfer.settlement`
  block, env-driven, empty defaults), `.env.example` (+`BANK_TRANSFER_*`
  vars), `tests/Feature/Web/PlayerWalletWiringTest.php` (neutral test
  account inputs — the old placeholder number removed).
- Acceptance: **zero occurrences** of `123-4-56789-0` /
  `Thai Lottery Official Co.` in app/, config/, database/, resources/,
  routes/, lang/ and non-guard tests — enforced by a live repository scan
  in `tests/Feature/Payment/BankTransferConfigurationTest.php` (7 tests).
  During this pass the scan's own root-path bug was found and fixed (it
  previously scanned a non-existent directory and passed vacuously — it
  now scans for real, 394 assertions in that file pair).
- Production config: `BANK_TRANSFER_BANK_NAME` / `BANK_TRANSFER_ACCOUNT_NUMBER`
  / `BANK_TRANSFER_ACCOUNT_NAME` must be set from the approved source
  before the lane is offered.

`ITEM-5` (P1 gateway capability guards) # **CLOSED**
- Files: `app/Services/Payment/PaymentGatewayManager.php`
  (+`isEnabled`/`isDepositCapable`/`isWithdrawalCapable` honouring the
  enabled flag AND integration maturity; +`depositDriver()`/
  `withdrawalDriver()` fail-closed resolvers with stable codes
  `payment_gateway_disabled`, `payment_gateway_not_deposit_capable`,
  `payment_gateway_not_withdrawal_capable`), `PaymentInitiationService`
  (+`payment_method_not_allowed` for the configured deposit list),
  `WithdrawalDisbursementService` (payouts resolve via `withdrawalDriver()`).
- Tests: `tests/Feature/Payment/PaymentGatewayManagerTest.php` (8 tests,
  including the bank-transfer lane's settlement-gated deposit capability
  and unaffected withdrawal capability).

`ITEM-6` (P1 stale payment docs) # **CLOSED**
- Files: `config/payment.php` (header rewritten: drivers ARE implemented
  and where they live), `.env.example` (payment section rewritten: real
  drivers, callback URLs, bank-transfer settlement, PromptPay
  future-only), `README.md` (new "Payment integration" section: drivers,
  env vars, fail-closed codes, webhook-only money movement, explicit
  "green suite ≠ configured provider" warning).

`ITEM-7` (P1 real historical result data) # **OPEN — OPERATIONAL**
- Code-side contract CLOSED: `database/seeders/data/results/README.md`
  (import contract: canonical widths, leading zeros, malformed-date
  rejection, duplicate/conflict policy, provenance + checksum +
  reconciliation procedure, no fabrication), pinned by
  `tests/Feature/Lottery/LaneResultImportContractTest.php` (6 tests) and
  `tests/Feature/Lottery/ArchiveInventoryParityTest.php` (9 tests: every
  lane's advertised year links resolve and carry rows; every detail link
  resolves; malformed year links cannot appear; empty years render honest
  empty states).
- The approved payload bundle itself is an operator deliverable and
  intentionally absent — no fabricated history will be committed.

`ITEM-8` (P1 malformed data acceptance) # **CLOSED** (code-side; data side is ITEM-7)
- Same test files as ITEM-7: malformed first-prize width, one-digit
  values (never padded), malformed dates — all refused with nothing
  persisted; conflicting re-delivery cannot replace the current result.

`ITEM-9` (P1 cutover / legacy URL deployment) # **CLOSED** (repo-side)
- Files: `deployment/CUTOVER-CHECKLIST.md` (NEW — backup, migrations,
  cache/config clear, build artifact, queue workers, scheduler, storage
  link, session/cookie domain, TLS, APP_URL, webhook + callback URLs,
  real result import, legal/support config, app links, smoke tests,
  rollback plan, and the live-side issues that resolve at cutover);
  `tests/Feature/LegacyRedirectTest.php` expanded (full §9 matrix: all
  four lanes' numbered archives 1/2/3, typo-form years 2564-2568,
  correctly-spelled bingo/pcso archives, closed-map suffixes 4/0/9 → 404,
  payment callback routes never captured by the bridge, legacy logout GET
  cannot mutate). Domain cutover itself is operational — in the checklist.

`ITEM-10` (P1 legal/operator content source of truth) # **CLOSED** (code-side)
- Files: `tests/Feature/Legal/PublicContentSourceTest.php` (NEW, 7 tests:
  blank legal config fails closed without inventing identity; approved
  config renders verbatim; production guard requires non-blank operator
  fields when APP_ENV=production; banned fabricated-identity sweep across
  18 public pages in BOTH configurations; GLO references must carry the
  non-affiliation disclaimer).
- Production values remain operator-supplied (approved source of truth) —
  flagged in `deployment/CUTOVER-CHECKLIST.md` §5.

`ITEM-11` (P1 auth/member host cutover consistency) # **CLOSED**
- Verified `MemberAuthController` is fully self-contained (CSRF, throttle,
  session regeneration, intended URL — covered by existing
  `tests/Feature/Auth/*`, 131 Auth+Api tests green in this pass).
- Retired the duplicate legacy surface: unrouted `Web/AuthController` and
  unused `auth/login.blade.php` + `auth/register.blade.php` DELETED;
  `member-login`/`member-register`/`forgot-password` are the single
  source of truth (all already localized).
- Host cutover (secure.thailotto.club) is operational — in the checklist.

`ITEM-12` (P2 PromptPay orphaned state) # **CLOSED — EXPLICIT FUTURE-ONLY**
- Files: `config/payment.php` (explicit "FUTURE-ONLY, no driver bound"
  block), `tests/Feature/Payment/PromptPayIntegrationTest.php` (NEW, 5
  tests: no enum case, no bound driver, never in allowed lists, web form
  rejects it, config documents the state). It cannot be selected or
  advertised.

`ITEM-13` (P2 player/auth UI localization) # **CLOSED**
- Files: `lang/en/player.php` + `lang/th/player.php` (NEW, 88 keys,
  symmetric); `resources/views/player/{dashboard,draws,draw-detail,bets,wallet,deposit,withdraw,profile}.blade.php`
  localized (bank-name payout options remain proper nouns by design);
  auth views were already localized (verified, no duplicate retired view
  remained).
- Tests: `tests/Feature/TranslationKeyIntegrityTest.php` extended to 23
  tests: the new `player` namespace joins the literal scanner + EN/TH
  symmetry check automatically, plus NEW player-page render assertions
  (7 pages EN, spot-check TH, zero raw keys).

`ITEM-14` (P2 app links fail closed) # **CLOSED**
- Files: `tests/Feature/Home/AppLinksTest.php` (NEW, 5 tests:
  NOT_CONFIGURED renders no store buttons, configured links render as
  real anchors, javascript:/data: URLs dropped, partial config renders
  only configured platforms, empty/whitespace values dropped).
  `PublicAppLinkService` already failed closed — no service change needed.

`ITEM-15` (P2 sitemap) # **CLOSED**
- Files: `app/Http/Controllers/SitemapController.php` (NEW — canonical
  public routes + real year archives (from draw_year data) + draw detail
  pages; excludes auth/admin/API/search/payment-return/legacy .php),
  `resources/views/sitemap.blade.php` (NEW, valid XML), `routes/web.php`
  (`/sitemap.xml`), `public/robots.txt` (Sitemap line).
- Tests: `tests/Feature/SEO/SitemapTest.php` (NEW, 8 tests).

`§16/§17` (route-by-route / page-by-page) # **CLOSED**
- §16: every public, auth/member and internal route named in the audit is
  covered by the existing page/feature suites plus this pass's additions
  (callback routes, sitemap, deposit/withdraw flows); full suite green.
- §17: the complete legacy matrix is asserted in
  `tests/Feature/LegacyRedirectTest.php` (static, member, typo-form,
  numbered-archive for all four lanes, unknown-.php 404, bridge never
  shadows real routes).

`§18` (live observations) # **OPEN — OPERATIONAL**
- Live-side issues (stale home snapshot, 2564 chain gaps, corrupted 2563
  entries, Results nav misroute, spelling defects, member host login)
  resolve when the legacy host stops serving traffic; recorded in
  `deployment/CUTOVER-CHECKLIST.md` with re-check steps.

`§19` (closed findings) # **RESPECTED** — fees keys, TranslationKeyIntegrity
scanner, README baseline, ZIP, .env exclusion, Vite manifest,
LegacyRedirectController were verified against HEAD and NOT redone.

`§20` (static regression baseline) # **CLOSED** — `php -l` clean on every
changed PHP file; view files render (page tests green); full suite
**1825 passed / 7 skipped / 0 failed / 106,156 assertions** (the skip
count is the documented build-dependent set, unchanged).

`§21` (go-live acceptance checklist) # **OPEN — OPERATIONAL** — see
`deployment/CUTOVER-CHECKLIST.md`, which mirrors it as an executable
runbook (data import, provider credentials, webhook registration, legal
config, smoke tests, rollback).

## Required production configuration (summary)

1. `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, TLS, session/cookie
   domain, fresh `APP_KEY`.
2. Per launched gateway: `*_ENABLED=true` + real credentials; register
   webhook endpoints `APP_URL/api/payment/webhooks/{gateway}`.
3. `BANK_TRANSFER_BANK_NAME`/`_ACCOUNT_NUMBER`/`_ACCOUNT_NAME` from the
   approved source (or the lane stays closed).
4. `LEGAL_*` operator identity fields from the approved source.
5. Approved historical result bundles imported per lane (checksums kept).
6. Queue workers + scheduler + `storage:link` + config/route/view caches.
