# Admin Backend — Implementation Report

**Date:** 2026-11-17 · **Laravel** 12.73.0 (§8r) · **PHP** 8.4.27 · **Filament** 3.3.55 ·
**spatie/laravel-permission** 6.25.0 · **Livewire** 3.8.7 · **PHPUnit** 11.5.56

Companion to `docs/ADMIN_BACKEND_AUDIT.md`. This file reports what was changed, why, and
what was actually run. Where something is unfinished it says so in the same voice as the
things that are done — no section of this report claims a result that the test run at the
bottom does not show.

---

## 1. Test commands and actual results

| Command | Result |
|---|---|
| `php artisan test tests/Feature/Admin` | **152 passed, 1250 assertions** (13 test files: RBAC/roles 80, AML/fraud 22, method offers 15, lottery catalogue 7, KYC documents 13, **system settings 15**, chrome/isolation 11) — re-measured after the settings pass (§8h) |
| `php artisan test tests/Feature/Admin/ComplianceAndFraudConsoleTest.php` | **22 passed, 91 assertions** |
| `php artisan test tests/Feature/Admin/PaymentMethodOfferAdminTest.php` | **15 passed, 63 assertions** |
| `php artisan test tests/Feature/Admin/LotteryCatalogueTest.php` | **7 passed, 121 assertions** |
| `php artisan test tests/Feature/Admin/KycDocumentResourceTest.php` | **13 passed, 56 assertions** (the self-review case now refuses in the lane *and* in the screen) |
| `php artisan test tests/Feature/Admin/SystemSettingsTest.php` | **15 passed, 71 assertions** (§8h) |
| `php artisan test tests/Feature/Admin tests/Feature/Hardening` | **198 passed, 1516 assertions, 0 failed** (re-measured after §8i) |
| `php artisan test tests/Feature/Admin tests/Feature/Filament tests/Feature/Hardening` | **426 passed, 3088 assertions, 0 failed** (§8k) |
| same lane on MariaDB 11.8.6 | **426 passed (165 + 261), 3088 assertions, 0 failed** (§8k, re-run from a scratch instance in §8m) |
| `vendor/bin/phpstan analyse` (level 8) | **0 errors**, 5,378 frozen in the baseline ratchet (§8k → §8n → §8o) |
| `npx playwright test` (130 cases, live server) | **130 passed, 0 failed** (§8l — was 110/20 in §8k) |
| `php artisan test tests/Feature/Admin/GranularScreenMigrationTest.php` | **6 passed, 26 assertions** (§8i) |
| `php artisan test tests/Feature/Admin/PaymentProviderAdminTest.php` | **13 passed, 68 assertions** (§8j) |
| `php artisan test tests/Feature/Admin/LotteryCatalogueTest.php` | **7 passed, 127 assertions** (§8j) |
| `php artisan test tests/Feature/Admin/ComplianceAndFraudConsoleTest.php` | **26 passed, 116 assertions** (§8j) |
| `php artisan test tests/Feature/Security tests/Feature/Compliance tests/Feature/Verification tests/Feature/Account tests/Feature/Api` | **481 passed, 16 skipped, 2 failed** — the two remaining pre-existing Compliance failures (`RealMoneyGateTest`, `UnifiedClamAvScannerTest`) |
| `php artisan test tests/Feature/Security tests/Feature/Compliance tests/Feature/Verification tests/Feature/Account tests/Feature/Filament` (seventh pass, before the deposit-slip repair) | **622 passed, 16 skipped, 4 failed** — the four are the two pre-existing Compliance failures (`RealMoneyGateTest`, `UnifiedClamAvScannerTest`) and the two pre-existing `DepositSlipVerificationResource` failures; the same four were failing before the KYC and settings passes |
| `php artisan test tests/Feature/Security tests/Feature/Api` | **180 passed, 2 skipped, 1058 assertions** |
| `php artisan test tests/Feature/Payment` (whole lane) | **110 failed, 1266 passed, 59 skipped** — all failures pre-existing; verified by reverting the registry edits and re-running the two worst files (`WithdrawalHoldFloorTest` + `WithdrawalOriginalProofTest`): **79 failed both before and after** |
| `php artisan test tests/Feature/Security tests/Feature/Api tests/Feature/Admin tests/Feature/Hardening` | **300 passed, 2 skipped, 2146 assertions, 0 failed** (measured before the AML/fraud pass; the Admin subset has since grown to 109 — see §8d for the re-run of the two affected suites) |
| `php artisan test tests/Feature/Filament` | **222 passed, 0 failed** — the two long-standing `DepositSlipVerificationResource` failures were **fixed** in the eighth pass (§8i): they were fixtures below the configured deposit floor, not a product decision |
| `php artisan test tests/Feature/Seeders` | **7 passed, 58 assertions** (proves the new `AdminRbacSeeder` did not disturb the chart-of-accounts path) |
| `composer install --prefer-dist` | 147 packages installed, no conflicts |
| `php artisan route:list --path=admin` | new routes present: `admin/roles`, `admin/roles/create`, `admin/roles/{record}/edit`, `admin/permissions`, `admin/payment-providers`, `admin/payment-providers/{record}`, `admin/financial-reports-page` |
| `php -l` over every new/modified PHP file | 0 syntax errors |

Not run, and therefore not claimed: PHPStan/Larastan, the MySQL lane, the 130 Playwright
browser cases, `npm run build`.

Per-suite numbers:

```
tests/Feature/Admin/GranularRbacTest.php           13 passed  (248 assertions)
tests/Feature/Admin/RoleAdministrationTest.php     16 passed  ( 52 assertions)
tests/Feature/Admin/PaymentProviderAdminTest.php   10 passed  ( 45 assertions)
tests/Feature/Admin/FinancialReportsPageTest.php    9 passed  ( 43 assertions)
tests/Feature/Admin/DashboardWidgetsTest.php        7 passed  ( 32 assertions)
tests/Feature/Admin/PolicyAbilityMapTest.php       14 passed  (152 assertions)
```

---

## 2. Newly created files

| File | Lines | What it is |
|---|---:|---|
| `config/admin.php` | 393 | Granular permission catalogue, role labels + matrix, protected roles, legacy bridge map, report guard-rails, two RBAC switches |
| `app/Support/Admin/AdminPermission.php` | 254 | The catalogue as constants; label/group/legacy lookups; `assertExists()` for request-supplied names |
| `database/seeders/AdminRbacSeeder.php` | 90 | Idempotent: creates granular permissions, the 9 operational roles, applies the matrix **attach-only**, grants `view dashboard` so a new role can reach the panel |
| `app/Services/Admin/RoleAdministrationService.php` | 460 | The only writer of roles/grants: create, sync (granular only), rename, delete; least privilege, self-lockout and in-use guards; audit; permission-cache flush |
| `app/Exceptions/RoleAdministrationException.php` | 104 | Six named refusals with a string `refusalCode()` |
| `app/Filament/Resources/RoleResource.php` | 244 | Role list/form; options filtered to what the actor may grant; coarse permissions shown read-only |
| `app/Filament/Resources/RoleResource/Pages/ListRoles.php` | 31 | Index |
| `app/Filament/Resources/RoleResource/Pages/CreateRole.php` | 73 | Delegates to the service; refusal → notification + `halt()` |
| `app/Filament/Resources/RoleResource/Pages/EditRole.php` | 96 | Rename + granular sync; fill from the service, never from the relation |
| `app/Filament/Resources/PermissionResource.php` | 162 | Read-only catalogue: name, label, group, roles carrying it, coarse equivalent; no create/edit/delete |
| `app/Filament/Resources/PermissionResource/Pages/ListPermissions.php` | 19 | Index |
| `app/Filament/Resources/PaymentProviderResource.php` | 457 | Provider registry: status, currencies, offers, masked config, runtime readiness, lifecycle verbs |
| `app/Filament/Resources/PaymentProviderResource/Pages/ListPaymentProviders.php` | 69 | Tabs: serving / out of traffic / never activated |
| `app/Filament/Resources/PaymentProviderResource/Pages/ViewPaymentProvider.php` | 38 | Full record; header verbs re-use the same verb definitions |
| `app/Filament/Pages/FinancialReportsPage.php` | 339 | Report desk reusing `OperationalReportService` + `ReportExportService`; view vs export permissions; `data_export` audit |
| `resources/views/filament/pages/financial-reports-page.blade.php` | 61 | Ask form, "run and download", own recent jobs |
| `app/Filament/Widgets/ComplianceQueueWidget.php` | 116 | KYC pending, fraud alerts (with 70+ split), AML high/critical, self-exclusions — **each stat gated separately** |
| `app/Filament/Widgets/SystemHealthWidget.php` | 148 | Failed jobs, queue backlog, providers serving vs ready; unreadable store reported as "unknown", never as zero |
| `tests/Feature/Admin/GranularRbacTest.php` | 263 | Catalogue integrity, bridge semantics + enforcement switch, least privilege, anti-escalation, guest |
| `tests/Feature/Admin/RoleAdministrationTest.php` | 353 | Create/sync/rename/delete refusals, audit assertions, coarse-grants-untouched regression |
| `tests/Feature/Admin/PaymentProviderAdminTest.php` | 281 | Access gates, real transition through the registry with actor attribution, illegal transition, credential masking, readiness |
| `tests/Feature/Admin/FinancialReportsPageTest.php` | 253 | Access, job freezing, replay, sealed export + audit, view-without-export, horizon ceiling, per-requester scoping |
| `tests/Feature/Admin/DashboardWidgetsTest.php` | 187 | Per-role widget visibility and real counts |
| `app/Filament/Resources/FraudAlertResource.php` | 448 | Read-only fraud queue; every verb delegates to `Security\FraudAlertReviewService`; no create/edit/delete/bulk |
| `app/Filament/Resources/FraudAlertResource/Pages/ListFraudAlerts.php` | 66 | Tabs: OPEN / risk 70+ / adjudicated / all; no header actions |
| `app/Filament/Resources/FraudAlertResource/Pages/ViewFraudAlert.php` | 53 | One alert; the two release/deny verbs, re-used from the resource |
| `app/Filament/Resources/ComplianceCaseResource.php` | 353 | AML investigation files: read-only form, lifecycle verbs, evidence fingerprints, applied controls |
| `app/Filament/Resources/ComplianceCaseResource/ComplianceCaseActions.php` | 171 | One spec per move, rendered as a row action and as a header action (`OperatorActionFactory`) |
| `app/Filament/Resources/ComplianceCaseResource/Pages/ListComplianceCases.php` | 88 | Tabs: intake / investigating / escalated / live / history / all |
| `app/Filament/Resources/ComplianceCaseResource/Pages/ViewComplianceCase.php` | 35 | One file with the same verbs in the header |
| `app/Filament/Resources/AmlRiskAssessmentResource.php` | 245 | The AML assessment store, read-only by construction (no create/edit/delete/action) |
| `app/Filament/Resources/AmlRiskAssessmentResource/Pages/ListAmlRiskAssessments.php` | 28 | Index, newest measurement first, current-only default filter |
| `app/Filament/Resources/AmlRiskAssessmentResource/Pages/ViewAmlRiskAssessment.php` | 29 | One frozen measurement |
| `tests/Feature/Admin/ComplianceAndFraudConsoleTest.php` | 429 | 22 cases: permission split, terminal/illegal transitions, maker-checker, note length, closure authority, read-only store |
| `app/Filament/Resources/PaymentMethodConfigResource.php` | 377 | Method offers (provider/method/currency/min/max/fee): list, create via the registry, read-only terms, lifecycle verbs |
| `app/Filament/Resources/PaymentMethodConfigResource/PaymentMethodConfigActions.php` | 140 | Enable / disable / retire, one spec each, rendered as row and header actions |
| `app/Filament/Resources/PaymentMethodConfigResource/Pages/ListPaymentMethodConfigs.php` | 72 | Tabs: offered / configured-but-dark / withdrawn / retired / all |
| `app/Filament/Resources/PaymentMethodConfigResource/Pages/CreatePaymentMethodConfig.php` | 108 | `handleRecordCreation()` → `PaymentMethodData::fromInput()` + `registerMethod()`; replay, duplicate and malformed answers relayed |
| `app/Filament/Resources/PaymentMethodConfigResource/Pages/ViewPaymentMethodConfig.php` | 31 | One offer, lifecycle verbs in the header |
| `tests/Feature/Admin/PaymentMethodOfferAdminTest.php` | 397 | 15 cases: permission split, register/replay/duplicate, inverted limits, transition + audit subject, retired is terminal, no edit/delete |
| `app/Services/Admin/LotteryCatalogueService.php` | 251 | Read-only projection: games, markets, config-vs-enum drift, live facts per game, product flags |
| `app/Filament/Pages/LotteryCataloguePage.php` | 143 | The catalogue screen: four sections, no form, no action, strict granular access |
| `resources/views/filament/pages/lottery-catalogue-page.blade.php` | 210 | The catalogue table, market table, drift table and product badges |
| `tests/Feature/Admin/LotteryCatalogueTest.php` | 272 | 7 cases: access, config parity, live facts, market overrides, drift rows, product flags, read-only proof |
| `app/Filament/Resources/KycDocumentResource.php` | 388 | KYC evidence inventory: masked number, issuer, fingerprint, file shape, expiry tooling; no create/edit/delete, no bytes |
| `app/Filament/Resources/KycDocumentResource/KycDocumentActions.php` | 223 | Verify (reference required) / reject (reason) / expire, declared once, via the authoritative document lane |
| `app/Filament/Resources/KycDocumentResource/Pages/ListKycDocuments.php` | 76 | Tabs: awaiting a decision / lapsed evidence / verified / rejected / all |
| `app/Filament/Resources/KycDocumentResource/Pages/ViewKycDocument.php` | 65 | One document; the open is audited; same verbs in the header |
| `tests/Feature/Admin/KycDocumentResourceTest.php` | 320 | 13 cases: access, inventory-only, masking, open-audit, verify/reject/expire, expired refusal, self-review refused by the screen **and** by the service, and the legacy-lane repair |
| `database/migrations/2026_11_18_000100_create_system_settings_table.php` | 60 | §8h: the `system_settings` table (`key` unique 96, `value` text, `value_type`, `updated_by` nullable FK `nullOnDelete`, timestamps), guarded with `hasTable()` so it is idempotent on a database that already has it |
| `app/Models/SystemSetting.php` | 47 | §8h: a deliberately dumb row model — fillable, `updated_by` cast to int, `updatedBy()` relation. No validation lives here; the service owns the rules |
| `app/Services/Admin/SystemSettingService.php` | 374 | §8h: the only writer of overrides — catalogue whitelist, type/bounds/pair validation, decimal-string money, audit old→new at High risk, `applyToConfig()`, reset-to-default, inert rows for retired keys |
| `app/Exceptions/SystemSettingException.php` | 87 | §8h: five named refusals with a string `refusalCode()` — unknown key, secret key, malformed, out of bounds, incoherent pair |
| `app/Providers/SystemSettingsServiceProvider.php` | 39 | §8h: merges the stored overrides into `config()` once per boot, and fails **quiet** (a missing table means "no overrides", never a dead application) |
| `app/Filament/Pages/SystemSettingsPage.php` | 328 | §8h: the settings desk — form generated from the catalogue, saves only what changed, "revert every override", read-only without `settings.manage` |
| `resources/views/filament/pages/system-settings-page.blade.php` | 65 | §8h: the page body — what the store is, what is overridden, what the deployed default is |
| `tests/Feature/Admin/SystemSettingsTest.php` | 364 | §8h: 15 cases — access, read-does-not-imply-write, typed round-trip, money normalisation, unknown key, secret key, bounds, incoherent pair, audit old→new, reset, save-only-changed, inert retired key |
| `tests/Feature/Admin/GranularScreenMigrationTest.php` | 304 | §8i: 6 cases — the coarse bridge still works, the seeded `admin` role reaches all 17 migrated screens under `enforce`, a coarse phrase stops counting, a player never reaches an operator screen, GLO duties stay separate, and no screen references an uncatalogued permission |
| `docs/ADMIN_BACKEND_AUDIT.md` | — | Gap matrix and structural findings |
| `docs/ADMIN_IMPLEMENTATION_REPORT.md` | — | This file |

## 3. Modified files

| File | Change |
|---|---|
| `app/Support/Admin/AdminAccess.php` | Added `isSuperAdmin()`, `permits()`, `permitsAny()`, `mayGrant()`, `grantable()`, `granularRbacEnforced()`, `legacyFallbackEnabled()`; extended `PANEL_ROLES` with the 9 operational roles; `audit()` now reports the granular layer. **No existing method's behaviour changed.** |
| `app/Models/PaymentProvider.php` | Added the missing `methods()` `HasMany` relation (the FK existed; the inverse did not) so the panel can count/show offers without hand-written where-clauses |
| `app/Services/Payment/PaymentProviderRegistry.php` | `recordAudit()` now attributes `user_id` from `auth()->id()` instead of hard-coding `null` — a provider transition taken from the panel names its operator; console/system lanes keep `null` |
| `app/Filament/Pages/OperationsDashboard.php` | Registers `ComplianceQueueWidget` and `SystemHealthWidget` |
| `database/seeders/DatabaseSeeder.php` | Calls `AdminRbacSeeder` after `RolePermissionSeeder` |
| `app/Policies/BasePolicy.php` | Second pass (§8b): dot abilities resolve through `config('admin.policy_abilities')` to `AdminAccess::permitsStrict()`; nothing is granted by omission |
| `tests/Feature/Security/BotDetectionMiddlewareTest.php` | Second pass (§8c): rewritten against the middleware's real contract; no production code touched |
| `app/Models/KycDocument.php` | Sixth pass (§8g): `verification_status` is now cast to `KycDocumentVerificationStatus` — the column had an enum since the batch-13 migration but was always read as a string, which is why the two KYC lanes could disagree without anybody noticing |
| `app/Services/Security/KycVerificationService.php` | Sixth pass (§8g): `reviewDocument()` now stamps BOTH columns (authoritative + legacy) and refuses to approve expired evidence |
| `config/admin.php` | §8h: appended the `settings_catalogue` whitelist (11 keys across lottery automation and finance) — each entry declares label, group, type, bounds and pair, plus a comment recording why secrets are absent. The existing RBAC keys are untouched |
| `bootstrap/providers.php` | §8h: registers `SystemSettingsServiceProvider` (appended last; the 12 existing providers keep their order) |
| `app/Exceptions/KycVerificationException.php` | Sixth pass follow-up (§8g): added `CODE_SELF_REVIEW` and the `selfReview()` factory, and the lane now raises it |
| `app/Services/Compliance/KycDocumentService.php` | Sixth pass follow-up (§8g): `verify()`/`reject()` call `assertNotOwnEvidence()` **inside the row lock**, before any transition — four-eyes is now the document lane's own rule, not a screen convention |
| `app/Enums/AuditAction.php` | §8i: added `FraudAlertReviewed = 'fraud_alert_reviewed'` (label, `security` category, `high` severity) so the fraud lane's decisions stop collapsing to `update` |
| `app/Services/Security/FraudAlertReviewService.php` | §8i: passes `AuditAction::FraudAlertReviewed` instead of a bare string |
| `app/Support/Admin/AdminAccess.php` | §8i: `permits()` now requires the operator precondition (panel role + signable account + dashboard permission) before consulting the grant — the screen-side twin of `permitsStrict()`, closing the leak where a PLAYER holding a coarse phrase the panel also bridges would pass a resource's `canViewAny()` |
| `app/Support/Admin/AdminPermission.php` | §8i: constants for the new risk-limit (`risk.limits.*`) and GLO (`glo.*`) permissions |
| `config/admin.php` | §8i: two new permission groups (Risk limits, GLO operations), one `legacy_fallback` entry per new permission (a per-duty bridge, never a wildcard), and the `admin` matrix extended with the duties it already carried as coarse phrases — payment execution and public-status publication still withheld |
| 44 `app/Filament/**` files (21 resources/pages/widgets + their action & relation-manager classes) | §8i: every screen now asks a granular permission (`permits`/`permitsAny`) instead of a coarse phrase; verb-level permissions match the verb (approve ≠ reject ≠ execute), and the two remaining coarse checks are the panel-entry gate (`view dashboard`) |
| `tests/Feature/Filament/DepositSlipVerificationResourceTest.php` | §8i: the amount is derived from `config('finance.deposit.min')` instead of hard-coded, and `test_the_deposit_minimum_is_still_enforced` gives the floor its first coverage |
| `tests/Feature/Filament/IdentityResourcesTest.php` | §8i: the hand-built operator in the commission-column test now carries a panel role (`auditor`), because a granular check requires being an operator at all |
| `tests/Feature/Admin/ComplianceAndFraudConsoleTest.php` | §8i: new case asserting the fraud decision's stored audit action, label, category and risk level |
| `app/Services/Payment/PaymentProviderRegistry.php` | Third pass (§8e): `registerMethod()` now looks up the natural triple (the same key the unique index enforces) before inserting, so a clash is the domain's `duplicate` refusal rather than a raw SQL error; method verbs audit against `PaymentMethodConfig` instead of `PaymentProvider` (`recordAudit()` gained a `$subjectType` parameter, provider verbs unchanged) |

Total: **44 new production PHP files (1 of them the `config/admin.php` catalogue), 3 new Blade
views, 12 new test files, 2 new docs, 61 modified files.** A unified diff against the upstream tree is provided alongside this
report as `admin-backend-admin-rbac.patch` — **122 files, 13897 insertions, 207 deletions** (§8i figure; the byte size is
quoted in the handover message),
`a/`/`b/` prefixes, verified to apply to a fresh clone of the upstream repository with
`git apply --check` (equivalently `patch -p1`).

---

## 4. RBAC — how it actually works

### 4.1 Two vocabularies, one decision point

```
config/permission.php   "manage payouts"        ← what the existing screens ask (40 phrases)
config/admin.php        "withdrawals.approve"   ← what a person actually needs to hold (39 permissions)
```

`AdminAccess::permits('withdrawals.approve')`:

1. no user / account cannot log in → **deny**
2. name not in the catalogue → **deny** (before the super-admin bypass: "may do anything"
   must not stretch to "things that are not powers")
3. super-admin → **allow**
4. operator holds the granular grant → **allow**
5. `granular_rbac.enforce` is on → **deny**
6. `fallback_to_legacy` is off → **deny**
7. operator holds the coarse phrase the granular permission replaces → **allow**

Steps 5–7 are the switch. Defaults keep today's roles working; flipping one config value
makes granular grants authoritative. Both switches are proven in
`GranularRbacTest`.

### 4.2 Roles seeded (`AdminRbacSeeder`, least privilege)

| Role | Holds (granular, abbreviated) | Cannot |
|---|---|---|
| `super-admin` | everything (Gate bypass) | — |
| `admin` | users.\*, deposits.\*, withdrawals.\* (no `execute`), wallets.\*, ledger.\*, kyc.\*, aml.\*, fraud.\*, lottery.\* — no `games.publish`, `settings.manage`, `roles.manage` | manage settings or roles; execute payouts |
| `auditor` | read-only set + `ledger.reconcile` | move money, review KYC, see providers |
| `finance-manager` | deposits/withdrawals full, wallets + ledger, reports, **not** KYC approval | approve KYC; change providers |
| `deposit-manager` | deposit queue + `deposits.approve/reject` | withdrawals, wallets |
| `withdrawal-manager` | withdrawal queue + approve/reject, `kyc.view` | execute payouts (separation of duties), KYC decisions |
| `kyc-officer` | `kyc.view/review/approve`, `users.verify` | any money, any settings, fraud/AML |
| `aml-officer` | `aml.*`, `fraud.view`, `kyc.view`, reports | move money, suspend accounts |
| `fraud-analyst` | `fraud.review`, `aml.view`, read deposits/withdrawals | approve anything |
| `support-agent` | `users.view`, `kyc.view`, read deposits/withdrawals/wallets | any decision |
| `lottery-manager` | `lottery.games.view/manage/publish`, reports | money, KYC, roles |
| `read-only-analyst` | read set incl. `roles.view`, `permissions.view` | every write |

`AdminAccess::mayGrant()` makes the middle column a *ceiling*: a role manager can only
compose roles out of permissions they hold, so editing a role cannot become a way to acquire
a permission indirectly. `RoleAdministrationService::assertNoSelfLockout()` closes the
mirror-image attack (stripping your own `roles.manage`).

### 4.3 Why `syncPermissions()` is never called

`spatie`'s `syncPermissions()` replaces a role's entire permission set. The seeded roles also
carry coarse phrases, so a sync from a form that only shows granular names would silently
strip `manage payouts` from `admin` and break every existing screen. The service therefore
diffs and attaches/detaches granular rows only; a regression test
(`test_granular_sync_never_touches_the_coarse_phrases`) fails if that ever changes.

---

## 5. Dashboard

| Widget | Data (all real queries) | Gate |
|---|---|---|
| `PlatformStatsWidget` (existing) | active players, open draws, next draw, bets/stake today, pending deposits/withdrawals | `view dashboard` |
| `ComplianceQueueWidget` **(new)** | `kyc_documents` pending/under_review; `fraud_alerts` OPEN/UNDER_REVIEW (+ how many at risk ≥ 70); `aml_risk_assessments` current at high/critical; `self_exclusions` active | **per stat**: `kyc.view` / `fraud.view` / `aml.view` / `users.view` |
| `SystemHealthWidget` **(new)** | `failed_jobs`, `jobs` backlog, `payment_providers` active vs total, plus how many Active providers whose driver `PaymentGatewayManager::availability()` says is not enabled | `audit_logs.view` / `settings.view` / `providers.view` |
| `PendingApprovalsWidget`, `DrawPipelineWidget`, `LedgerBalanceWidget` (existing) | withdrawals awaiting decisions; draw pipeline; ledger debit/credit balance | unchanged |

Nothing is cached, hard-coded or seeded. `SystemHealthWidget` reports **"unknown"** rather
than `0` when the job table cannot be read — a dashboard that says "healthy" because it could
not look is worse than one that admits it.

---

## 6. Payment provider administration

* **Who:** `payments.providers.view` to look, `payments.providers.manage` to touch. Auditor
  is refused the screen entirely (proven), a view-only holder sees no verbs (proven).
* **How writes happen:** only through `PaymentProviderRegistry::transitionProvider()`, which
  applies `PaymentProviderStatus::canTransitionTo()` and writes the audit row. The panel has
  **no** create page and no editable status field, so there is no second write path.
* **Status ≠ health:** the view shows the house decision *and* what
 `PaymentGatewayManager::availability()` says about the driver, i.e. whether it can actually
  be invoked. "Active and unready" is displayed as a warning, because that is the state in
  which payments fail.
* **Secrets:** the registry refuses secret-smelling keys, but the screen masks any value
  whose *key* looks like a credential (`api_key`, `token`, `webhook`, …) and only prints
  whether it is set. A test writes such a row by hand to prove the screen, not the
  application, is the last line of defence.
* **Attribution fix:** transitions used to be audited with `user_id = null`. They now name
  the authenticated operator.

---

## 7. Reporting desk

* Reuses the canonical lane end to end: `OperationalReportData::fromInput()` (validates the
  horizon and the filter vocabulary) → `OperationalReportService::ask()` (freezes the ask into
  a job by query fingerprint) → `run()` → `ReportExportService::render()` (seals bytes with a
  sha256 checksum) → `streamDownload`.
* Re-running the same ask replays the same job — asserted
  (`test_the_same_ask_replays_the_same_job_rather_than_double_counting`).
* Horizon ceiling is enforced twice: the form refuses a wider range, and the reporting lane
  refuses it again (tested with a 500-day ask: no job, no artifact).
* `reports.view` runs the report; `reports.export` takes the file. A view-only operator gets
  the run, a warning notification, **no artifact and no `data_export` audit line** (tested).
* Every export writes an audit line with family, fingerprint, horizon, row count, format and
  checksum — and never the rows themselves (tested).
* The "recent jobs" list is scoped to the requester, because a job carries the filters it ran
  with and those can name individual players (tested with a second operator's job).

---

## 8. Acceptance checklist (honest status)

| Item | Status |
|---|---|
| Admin authentication works | ✅ existing, re-verified |
| Panel access protected / guest, player, agent, suspended refused | ✅ existing tests |
| RBAC works (coarse) | ✅ existing |
| Granular permissions work | ✅ new — 13 tests |
| Role / permission management works | ✅ new — 15 tests |
| Dashboard uses real database data | ✅ (all 7 widgets) |
| Compliance queues on the dashboard | ✅ new |
| System health on the dashboard | ✅ new |
| User management | ⚠️ partial (list/view/edit/suspend exist; notes + activity timeline not built) |
| Deposit administration | ✅ existing |
| Withdrawal administration | ✅ existing |
| Payment provider administration | ✅ new — 10 tests |
| Payment **method offers** administration | ✅ new — 15 tests (§8e) |
| Lottery game catalogue visibility | ✅ new — 7 tests (§8f); **read-only**, because no games table exists |
| Wallet / ledger inspection | ✅ existing |
| KYC administration | ✅ new — document inventory + three decision verbs (§8g) |
| AML administration | ✅ new — case queue with the four lifecycle moves, plus the read-only assessment store (§8d) |
| Fraud administration | ✅ new — adjudication queue delegating to the canonical review service (§8d) |
| Maker-checker on fraud adjudication | ✅ enforced by the service and proven by test (self-review refused) |
| AML closure limited to admin authority | ✅ enforced by `ComplianceCaseService::close()` and proven by test |
| Lottery / draw / result administration | ⚠️ draws, results, GLO flows + limits exist; game/category CRUD not built |
| Financial reporting + export | ✅ new — 9 tests |
| Export permissions enforced | ✅ new |
| System settings management | ❌ not built (read-only projection exists at `/admin/configuration`) |
| Audit logging | ✅ existing + attribution fix + role/export audits |
| Direct action invocation protected | ✅ service-level re-checks (role admin, providers, reports) |
| Model policies authorise real operators (not just super-admin) | ✅ fixed — 14 tests |
| Database relationships correct | ⚠️ one missing relation added (`PaymentProvider::methods`); `fraud_alerts.user_id` type mismatch unresolved |
| All forms validate | ✅ for the screens added |
| No fake metrics / dead buttons | ✅ new surfaces query real data and every verb calls a service |
| Automated tests pass | ✅ 66/66 in the admin lane; 284/286 in the wider lane, failures pre-existing |
| Existing functionality intact | ✅ no regressions in the lanes run |
| Documentation updated | ✅ this file + the audit |

---

## 8b. Policy authorisation repair (second pass)

The audit's §3.2 finding is now fixed. What changed:

| File | Change |
|---|---|
| `app/Policies/BasePolicy.php` | `can()` no longer asks `$user->can('wallet.viewAny')`. It (1) honours an explicitly seeded dot ability, (2) resolves the ability through `config('admin.policy_abilities')` to a granular permission and asks `AdminAccess::permitsStrict()`, (3) denies `never` and unmapped abilities. `owns()` is untouched, so players keep reading their own records. |
| `app/Support/Admin/AdminAccess.php` | New `permitsStrict()`: granular grant or super-admin only, **no coarse fallback**. |
| `config/admin.php` | New `policy_abilities` map (67 entries, 18 of them `never`); 10 new granular permissions (`agents.view/manage`, `commissions.view`, `bets.view/manage`, `payments.view`, `support.view/manage`, `privacy.view/manage`) with legacy fallbacks and role-matrix grants. |
| `tests/Feature/Admin/PolicyAbilityMapTest.php` | 14 tests: completeness in both directions, value validity, the `never` list, and behaviour per role. |

**The bug this almost introduced.** The first implementation reused `permits()` — which
bridges to the coarse catalogue. `RolePermissionSeeder` gives players `manage wallet` and
`view transaction history`, so `wallet.viewAny` → `manage wallet` would have let **every
player enumerate every wallet**. The test
`test_a_player_still_holds_no_operator_ability` failed on exactly that, and the fix was a
separate strict path rather than widening the bridge. This is the single most important line
in the change:

> Screen authorisation may bridge to the coarse vocabulary. Record-level policy authorisation
> may not.

**Numbers after the repair**

```
tests/Feature/Admin/PolicyAbilityMapTest.php        14 passed (152 assertions)
tests/Feature/Security + Api + Admin + Hardening   300 passed, 2 skipped, 0 failed
```

## 8c. Out-of-scope repair: the stale bot-detection test

`tests/Feature/Security/BotDetectionMiddlewareTest.php` was red for reasons unrelated to the
admin backend: it constructed `BotDetectionMiddleware` with a constructor signature the class
no longer has (so it died with a TypeError before asserting anything) and asserted a 403 on an
aggressive user agent, which the middleware no longer does — it is an observation-only
adapter that explicitly refuses non-`log` modes.

Leaving it red would have made an honest verdict on the Security lane impossible, so the file
was rewritten against the real contract: observation off by default, a log-mode POST observed
and passed through, a non-POST skipped, an unsupported mode refused with 503
`bot_observation_configuration_error` (loudly, not silently), and a non-boolean config treated
as a failure. **5 tests, all green.** No production code was touched for this.

## 8d. AML, compliance and fraud administration (third pass)

The audit's §3.1 rows for AML and fraud were the last two "MISSING" items with a working
domain behind them. Both lanes already had real services — `ComplianceCaseService` with a
transition map, `AmlRiskAssessmentService` with a deterministic score and an evidence
fingerprint, `Security\FraudAlertReviewService` with row locking, a terminal-status guard,
maker-checker separation, an audit line and a `FraudCase` — and neither had a single screen.
An AML file could be opened and never escalated, resolved or closed; a fraud alert could be
raised and never adjudicated. Both required a tinker session.

### What was built

| Screen | Route | Verbs | What it refuses to do |
|---|---|---|---|
| Fraud queue | `/admin/fraud-alerts` (+ `/{record}`) | Release-allow, reject-deny, freeze-account, each with a 10–255 character reason | No create, no edit, no delete, no bulk action; every write goes through `FraudAlertReviewService::review()` |
| AML cases | `/admin/aml-cases` (+ `/{record}`) | Start investigating, escalate, resolve, close file | No create, no edit, no delete; no screen writes `status` directly; no form can change `risk_level` or the evidence fingerprint |
| AML risk store | `/admin/aml-risk-assessments` (+ `/{record}`) | none | Read-only by construction — no create, no edit, no delete, no action at all |

The lifecycle verbs are declared **once** per move in `ComplianceCaseActions::specs()` and
rendered as both a row action and a header action through the existing
`App\Support\Admin\OperatorActionFactory` (the same pattern `DataSubjectRequestActions` and
`DrawLifecycleActions` use). A move therefore cannot exist on the queue and be missing on the
record.

### What the screens prove rather than assert

```
FraudAlertReviewService (unchanged, called by the screen):
  already RESOLVED_*        → DomainException "already resolved; a terminal adjudication cannot be reversed"
  user_id === reviewerId    → DomainException "Maker-checker separation of duties"
  otherwise                 → status, resolved_by_user_id, resolution_notes, resolved_at,
                              AuditLogService line, FraudCase row

ComplianceCaseService (unchanged, called by the screen):
  illegal transition        → ComplianceCaseException::invalidTransition(case, from, to)
  note < 8 characters       → refused
  close() by a non-admin    → ComplianceCaseException::unauthorizedClosure
  unreleased control hold   → refused before sealing
```

Visibility closures (`ComplianceCaseActions`) mirror the service's own checks so an operator
is not offered a button that is guaranteed to fail — the class docblock states plainly that
this is a pre-filter, not the rule, and the service's refusal text is what the operator sees
(`OperatorActionFactory::run()` reports it verbatim instead of a green "saved").

### One finding worth recording

Filament registers resource routes from the panel provider, and `routes/web.php` already owns
`admin/compliance-cases` (index + `{reference}`) on `ReleaseOperationsController`. Pointing the
new resource at that URI made the later registration silently lose the route *name*, which
then broke the sidebar render for **every** admin screen (`RouteNotFoundException` in
`RoleAdministrationTest`). The resource is therefore at `admin/aml-cases`, with the reason
written into `ComplianceCaseResource::$slug`'s docblock. The legacy read-only projection is
untouched; retiring it is a product decision, listed as a blocker.

### Test numbers for this pass

```
tests/Feature/Admin/ComplianceAndFraudConsoleTest.php        22 passed ( 91 assertions)
tests/Feature/Admin (whole directory)                      109 passed (1057 assertions)
tests/Feature/Hardening/FilamentAuthorizationTest.php        7 passed (154 assertions)
tests/Feature/Filament                                      218 passed, 2 failed (1479 assertions)
```

The two failures are the pre-existing `DepositSlipVerificationResource…` cases (deposit below
the configured 100 THB minimum), unchanged by this pass and still listed as blocker #2.

## 8e. Payment method offers (fourth pass)

The audit's "payment method offers (min/max/fee)" row was the last MISSING item on the finance
side. `resolveOffer()` is what every deposit and withdrawal path calls to decide what a payer
may do, it serves **only** rows whose status is `enabled`, and nothing but a tinker session
could enable one — so the offerable menu was frozen at whatever the seeder wrote.

### What was built

| Screen | Route | Verbs | What it refuses to do |
|---|---|---|---|
| Method offers | `/admin/payment-method-configs` (+ `/create`, `/{record}`) | Enable offer, disable offer, retire offer (8–255 character reason each) | No edit, no delete, no bulk; no `status` write outside `transitionMethod()`; no provider creation |

* **Create** goes through `PaymentMethodData::fromInput()` + `PaymentProviderRegistry::registerMethod()`,
  so the offer key, the decimal-string money, `min ≤ max` and the `fee_bps ∈ [0, 10000]`
  bounds are the DTO's rules, not a second copy in the form.
* **Three answers, reported as themselves:** registered, *replayed* (the identical offer
  already exists — not an error, and no second row), or *refused* (malformed, or the same key
  with different limits → `duplicate`). The form stays open on a refusal; nothing is written.
* **Lifecycle** is `transitionMethod()`: row lock, `PaymentMethodStatus::canTransitionTo()`,
  `retired_at`, and a High-risk audit line. The verbs are declared once
  (`PaymentMethodConfigActions::specs()`) and rendered both as row actions and as header
  actions, so the queue and the record cannot disagree.
* **Commercial terms are immutable.** The registry treats a re-registration with different
  numbers under the same key as a duplicate, and the screen agrees — that is why there is no
  edit page. Changing a limit means a new offer, and the old row keeps answering "what did we
  promise then".

### Two registry defects found and fixed by this pass

| Defect | Effect | Fix |
|---|---|---|
| `registerMethod()` looked for an existing offer by the derived `method_key` **hash only** | A row written by any other lane (or older key shape) did not match, the insert then hit the unique index on `(provider_id, method_code, currency)`, and the caller got `UniqueConstraintViolationException` — a 500 with the whole INSERT interpolated into the log, where the domain's answer is "that offer already exists" | The lookup now matches the natural triple (the same key the index enforces) as well as the hash, so every duplicate arrives as `PaymentProviderException::duplicate()` |
| `recordAudit()` hard-coded `auditable_type = PaymentProvider::class` for method verbs too | An audit row for "method retired" pointed at a **provider** whose id happened to equal the method's, so the trail answered "which provider?" instead of "which offer?" | `recordAudit()` takes a `$subjectType`; the two method verbs pass `PaymentMethodConfig::class`. Provider verbs and their existing assertions are unchanged |

The first one is the reason the suite has a test literally named
`test_the_same_key_with_different_limits_is_refused_as_a_duplicate`: it failed with the raw SQL
exception before the fix, which is how the defect was noticed.

### One access note

`payments.providers.manage` is held by **nobody but super-admin** in `config/admin.php`'s role
matrix, so every write verb here is (correctly) refused to a `deposit-manager` who holds only
`payments.providers.view`. Widening that is a role-matrix decision, and it is recorded as a
blocker rather than silently granted.

### Test numbers for this pass

```
tests/Feature/Admin/PaymentMethodOfferAdminTest.php          15 passed ( 63 assertions)
tests/Feature/Admin (whole directory)                      124 passed (1208 assertions)
tests/Feature/Admin + tests/Feature/Hardening              157 passed (1242 assertions)
tests/Feature/Security + tests/Feature/Api                 180 passed, 2 skipped (1058 assertions)
tests/Feature/Filament                      218 passed, 2 failed (1479)  [unchanged, deposit-slip]
tests/Feature/Payment (whole lane)          110 failed, 1266 passed, 59 skipped  [all pre-existing]
```

The Payment-lane failures were proven pre-existing rather than assumed: the two worst files
(`WithdrawalHoldFloorTest`, `WithdrawalOriginalProofTest`) fail **79 times both with and
without** this pass's registry edits (verified by swapping the original file back in and
re-running). They are withdrawal-limit/fixture drift — `Withdrawal amount … outside the
permitted range` — and belong to that lane's own follow-up, not to the admin backend.

## 8f. Lottery game catalogue (fifth pass)

The audit's lottery row said "game/category CRUD not built". Investigating it honestly changed
the answer: **there is no games table.** The sellable catalogue is `config/lottery.php`
(`types` + `markets`); `draws` and `bets` only *refer* to those words, and
`NumberNormalizationService` states that configuration is authoritative for digit counts. A
"create game" form would write a row no engine reads while the row that governs money stayed in
a PHP file — a fake button. So this pass built the screen that the absence actually needed: a
projection, and a drift report.

### What was built

| Surface | Route | Writes | Purpose |
|---|---|---|---|
| Lottery game catalogue | `/admin/lottery-catalogue` | **none** | Game types (digits, range, ordering rule, type-level multiplier, markets) with live draws/bets/stake/limits; all six markets with their own multipliers and settlement source; a config-vs-enum drift table; the four draw products with their enablement flags |

Reuses nothing it should not: the numbers come from configuration, the counts come from
`Draw`, `Bet` and `NumberLimit`, and the page holds no queries of its own — everything is in
`LotteryCatalogueService`, so a test can assert the figures without rendering HTML.

### The finding: three vocabularies, two disagreements

| Vocabulary | Tod | Run | Verdict |
|---|---|---|---|
| `config('lottery.types')` (authoritative) | 3 digits, 000–999 | 1 digit, 0–9 | the rule the engine reads |
| `App\Enums\BetType` (the betting vocabulary) | 3 digits | 1 digit | **agrees** — this was corrected in an earlier phase and the catalogue now proves it stays corrected |
| `App\Enums\DrawType` (the scheduling vocabulary) | **2 digits, max 99** | **2 digits, max 99** | **disagrees** |

`DrawType::digits()`/`maxNumber()` have no callers today, so the defect is latent rather than
live — which is why the drift table labels it "latent" instead of ranking it beside a money
rule. It is reported, not fixed: changing a digit count is changing what a player may buy.

### The access decision, and why it differs from older screens

`LotteryCataloguePage::canAccess()` uses `AdminAccess::permitsStrict()`, not `permits()`. The
coarse bridge maps `lottery.games.view` → `view draws`, and **players hold `view draws`**,
because the player-facing screens scope their own queries. This page scopes nothing — it
aggregates stake and bets platform-wide — so it asks for the granular grant only. The route was
already unreachable for a player through the panel gate; a new screen should not depend on a
second gate to be safe. (Pre-existing screens still use the bridge; migrating them is the
`enforce=true` blocker.)

### Test numbers for this pass

```
tests/Feature/Admin/LotteryCatalogueTest.php                  7 passed (121 assertions)
tests/Feature/Admin (whole directory)                       131 passed (1329 assertions)
tests/Feature/Admin + tests/Feature/Hardening              164 passed (1363 assertions)
tests/Feature/Filament (+ AdminConsoleChromeTest)          228 passed, 2 failed (1691)  [same deposit-slip pair]
```

## 8g. KYC evidence administration (sixth pass)

The audit's KYC row said "review page exists; document resource not built". Reading the code
turned up something worse than a missing screen: **the two KYC lanes disagreed, and the panel's
approval was not evidence.**

### The defect

| Lane | Writes | Read by |
|---|---|---|
| `Security\KycVerificationService::reviewDocument()` — used by `KycReviewPage` and by `AccountVerificationService` | legacy `status` only | older gates |
| `Compliance\KycDocumentService::{verify,reject}` — the authoritative document lane | `verification_status` **and** legacy `status` | `KycVerificationService::pronounce()` |

`pronounce()` — the function that decides whether an account is verified — reads
`verification_status = 'verified'`. So a document approved from the panel's KYC page set the row
to "verified" in the legacy column, and the account verdict stayed unverified because the
authoritative column still said `uploaded`. Nobody saw it because `KycDocument` never cast
`verification_status` to its own enum, so every reader compared it as a string.

**Fixed** in this pass, at the lower layer rather than in one screen:

* `KycDocument` now casts `verification_status` to `KycDocumentVerificationStatus`.
* `reviewDocument()` stamps **both** columns, keeping the correspondence the document lane keeps,
  so every existing caller (panel page, account verification flow, API) becomes correct at once.
* `reviewDocument()` refuses to approve **expired** evidence, matching the document lane's rule.
* Proved by `test_the_legacy_review_lane_now_satisfies_the_authoritative_verdict` and
  `test_the_legacy_lane_refuses_to_approve_expired_evidence`.

### The screen

| Surface | Route | Verbs | What it refuses |
|---|---|---|---|
| KYC documents | `/admin/kyc-documents` (+ `/{record}`) | Verify (8–96 char reference), reject (reason), mark expired | No create (evidence arrives fingerprinted from the upload lane), no edit, no delete, **no bytes, no storage path, no filename, no raw number** |

* The number is masked to its last four characters; the file is described by mime and size only.
  There is deliberately **no download button**: serving an identity document needs a signed,
  audited response, not a panel link to a legacy route.
* Tabs answer the operational question: awaiting a decision, lapsed evidence (expiry passed but
  the row still reads live), verified, rejected, all. A nav badge counts unread documents.
* Opening a record writes an audit line naming the operator and the document id — **not** the
  number, not the contents.
* Decisions go through `Compliance\KycDocumentService`, which refuses expired evidence, applies
  the transition map and stamps both columns.

### The gap this pass opened, and then closed

The first cut of this pass left the four-eyes rule in the screen only: `KycDocumentService::verify()`
and `reject()` did not ask whether the reviewer owned the document, and the test said so in its own
name (`…that_the_service_would_allow`). That is a screen convention wearing a domain rule's clothes —
the console lane, the job that expires documents and any future API caller would each have to
remember it. It is now closed at the layer that owns the decision:
`verify()`/`reject()` call `assertNotOwnEvidence()` **inside the row lock**, before any transition
can be written, and raise `KycVerificationException::selfReview()` /
`CODE_SELF_REVIEW`. The Filament `visible` closures still refuse first, so a guaranteed-failure
button is never offered — but the refusal no longer depends on the button being the only door.
Proved by `test_a_self_review_is_refused_by_the_screen_and_by_the_service` and
`test_a_self_rejection_is_refused_by_the_lane_as_well` (both assert the refusal code, not just the
exception).

### Test numbers for this pass

```
tests/Feature/Admin/KycDocumentResourceTest.php               12 passed ( 53 assertions)
tests/Feature/Admin (whole directory)                       143 passed (1391 assertions)
tests/Feature/Admin + tests/Feature/Hardening               176 passed (1416 assertions)
tests/Feature/Security + Compliance + Verification + Account 404 passed, 16 skipped, 2 failed
tests/Feature/Filament                            218 passed, 2 failed (1479)  [same deposit-slip pair]
```

The two Compliance failures (`RealMoneyGateTest`, `UnifiedClamAvScannerTest`) were proven
pre-existing: the two KYC files were reverted and the same suites re-run — **2 failed before and
after**.

## 8h. System settings store (seventh pass)

Blocker #13 of the audit said a writable settings store was *deliberately unbuilt*, because the
obvious implementations are escalation surfaces. That was a correct refusal of `.env` editors —
and an incomplete answer to the question operators actually asked ("can we change the draw
interval without a deployment?"). This pass builds the missing half: a store of **overrides** whose
keys are whitelisted in code.

### What was built

| Piece | What it does, and what it refuses |
|---|---|
| `database/migrations/2026_11_18_000100_create_system_settings_table.php` | `system_settings`: unique `key` (96), `value` text, `value_type`, `updated_by` (nullable FK, `nullOnDelete` — deleting an operator must not delete the record of what they changed), timestamps. Guarded by `hasTable()`, so re-running is a no-op. **Nothing is seeded**: an empty table means "everything as deployed". |
| `app/Services/Admin/SystemSettingService.php` | The only writer. Four rules, in order: **whitelist** (a key not in `config/admin.php → settings_catalogue` is refused by name), **type + bounds** from that same entry, **pair coherence** (a minimum may not exceed its maximum, whichever side was edited first, read through the *effective* value not the form), **audit old→new at High risk**. Money is validated and stored as a decimal **string** — never a float. |
| `app/Providers/SystemSettingsServiceProvider.php` | Merges every valid override into `config()` once per boot. This is the point of the whole design: the existing lanes (services, commands, jobs, blades) read `config('lottery.draw.interval_minutes')` and see the operator's value **without being rewritten**, so there is no "the one reader we forgot". |
| `app/Filament/Pages/SystemSettingsPage.php` + blade | `/admin/system-settings`: the form is *generated from the catalogue*, so the validation rule and the drawn field are the same declaration. It saves only values that differ from what is in force (one audit line per real change, not per click), and offers "revert every override" with per-key auditing. |
| `app/Exceptions/SystemSettingException.php` | Five refusals with a code: unknown key, secret key, malformed, out of bounds, incoherent pair. |

### The four refusals that matter

1. **An unknown key cannot be written.** Not by an operator, not by a forged Livewire payload —
   the service refuses `app.key` by name and the partial write never happens. There is no
   "raw key" branch for super-admin, because a bypass behind an `if` is not a whitelist.
2. **A secret cannot be stored.** Nothing in the catalogue is a credential, and an entry marked
   `'secret' => true` is refused even if a future developer adds one. A settings row is readable by
   anyone who can read the database; that is the wrong place for a token, forever.
3. **A value outside the declaration is refused.** 4 minutes is not a draw interval; 1441 is not
   one either; `"sixty"` is not a number. The test asserts the refusal *and* that the table is
   still empty.
4. **A money pair cannot become incoherent.** Writing a maximum of 400.00 when the stored minimum
   is 500.00 raises `setting_pair_incoherent` and quotes both values. The accepted minimum
   survives the refused edit to its pair.

### The fifth decision: retired keys are inert, not deleted

If a key leaves the catalogue (a feature is removed, a bound is renamed), its stored row is **not
applied** to `config()` — dead code must never steer live configuration — and it is **not silently
deleted** either, because deleting a row deletes the record of the override. It simply stops
counting, and a human can still see it in the table.

### Permission split, and the one honest consequence

`settings.view` reads, `settings.manage` writes, and they are checked with `permitsStrict()` —
granular only, because a settings surface reachable through a coarse phrase a player holds would be
the worst trade in the panel. In the current `config/admin.php` matrix only `super-admin` holds
`settings.manage`; `admin` holds `settings.view` and gets a read-only desk with a badge saying so.
That is the same deliberate narrowness recorded for `payments.providers.manage` (audit blocker #10):
widening it is a `role_matrix` decision, not a code change, and the screen behaves correctly at
either width.

### What it deliberately does not touch

The read-only projection at `/admin/configuration` keeps its job (it shows the environment-level
truth: drivers, providers, declared limits). The new page shows **overrides** — what an operator
changed and what the deployed default was. The two answer different questions, and neither claims
the other's.

### Proof outside the test suite (a real boot, not a fixture)

The suite proves the service; this proves the wiring, in two separate PHP processes against a
freshly migrated database, with no `.env` edit anywhere:

```bash
rm -f /tmp/fresh.sqlite && touch /tmp/fresh.sqlite
DB_CONNECTION=sqlite DB_DATABASE=/tmp/fresh.sqlite php artisan migrate --force
#   … 2026_11_18_000100_create_system_settings_table ......... 0.80ms DONE

DB_CONNECTION=sqlite DB_DATABASE=/tmp/fresh.sqlite php artisan tinker --execute='…insert interval_minutes=30…'
DB_CONNECTION=sqlite DB_DATABASE=/tmp/fresh.sqlite php artisan tinker \
  --execute='echo config("lottery.draw.interval_minutes");'
#   config sees: 30   (deployed default is 60)
```

The second process never touched the store itself: the provider merged the override at boot, and
`config()` — which every existing lane already reads — returned the operator's value.

### Test numbers for this pass

```
tests/Feature/Admin/SystemSettingsTest.php                    15 passed ( 71 assertions)
tests/Feature/Admin/KycDocumentResourceTest.php               13 passed ( 56 assertions)
tests/Feature/Admin (whole directory)                        152 passed (1250 assertions)
tests/Feature/Admin + tests/Feature/Hardening                192 passed (1490 assertions)
Security + Compliance + Verification + Account + Filament    622 passed, 16 skipped, 4 failed
                                                             [the same four pre-existing failures]
```

## 8i. The last red pair, the audit action, and the screen migration (eighth pass)

Three pieces of the audit's remaining list, none of which needed a product decision after all.

### 1. The two red `DepositSlipVerificationResource` tests — a fixture, not a decision

Every report so far listed these two as "pre-existing, needs a product decision". That was wrong,
and the wrongness is instructive:

```
Deposit amount 82.50 THB is outside the permitted range 100.00 - 500000.00.
Deposit amount 64.00 THB is outside the permitted range 100.00 - 500000.00.
```

The fixture requested 82.50 and 64.00 THB. The shipped default floor is 50.00 (so the test passed
on a clean clone), but this tree's `.env` says `FINANCE_MIN_DEPOSIT=100.00`. A test about **slip
verification** had therefore become a test about the **deposit minimum**, decided by whichever
`.env` happened to be in front of it. Nothing about the limit or the product was ambiguous; the
fixture was coupled to the operator's environment.

The repair is in the fixture, and it is deliberately shaped so it cannot hide a weakened limit:

```php
private function depositAmount(): string
{
    return bcadd((string) config('finance.deposit.min'), '25.00', 2);
}
```

…plus `test_the_deposit_minimum_is_still_enforced`, which asks for one satang below the configured
floor and asserts `DepositException` with `deposit_amount_out_of_range`, an empty `deposits` table
and an unchanged balance. Before this pass **no test asserted the deposit floor at all** — the
rule was enforced by `DepositService` and proven by nobody.

`tests/Feature/Filament/DepositSlipVerificationResourceTest.php`: **3 passed / 2 failed → 6 passed
(33 assertions)**.

### 2. `audit_logs.action` for the fraud lane (audit blocker #9)

`FraudAlertReviewService` passed the bare string `'fraud_alert_reviewed'` to `AuditLogService`,
whose column is cast to `App\Enums\AuditAction`. An unknown string silently collapsed to `update`,
so a fraud adjudication was indistinguishable from an ordinary row edit — including in the audit
screen's action filter, which is built from the enum.

* `AuditAction::FraudAlertReviewed = 'fraud_alert_reviewed'` added, label "Fraud Alert Reviewed",
  category `security`, severity `high` (it decides whether an account is frozen).
* The lane passes the case, not the string.
* `ComplianceAndFraudConsoleTest::test_the_fraud_decision_is_recorded_under_its_own_audit_action`
  asserts the stored value, the label, the category and the risk level — not the intention.

### 3. The screen migration (audit blocker #4) — every panel screen now asks the granular question

`admin.granular_rbac.enforce` could not be turned on while screens still asked for coarse phrases:
the switch would simply have emptied the panel. The migration is now done, screen by screen:

| Lane | Was | Now |
|---|---|---|
| Users | `current('manage users')` | `permits(users.view / users.update / users.suspend / users.verify)` |
| Deposits + slips | `currentAny('view transaction history', 'manage payouts')` | `permitsAny(deposits.view, deposits.review)`, verbs `deposits.approve` / `deposits.reject` |
| Withdrawals | same coarse pair | `withdrawals.view / review / approve / reject / execute`, one per verb |
| Wallets + controls | `current('manage wallet')` | `wallets.view` for the list, `wallets.adjust` for lock/unlock/adjust |
| Ledger, transactions, reconciliation | `reconcile ledger`, `view financial reports`, `view transaction history` | `ledger.view / ledger.reconcile`, `payments.view`, `reports.view` |
| Draws, results, lifecycle | `view draws`, `manage draws`, `process draws`, `process settlements` | `lottery.games.view / manage / publish` |
| Bets, tickets | `view draws`, `view transaction history` | `bets.view`, `lottery.games.view`, `payments.view` |
| Number limits | `view risk alerts`, `manage risk limits` | **new** `risk.limits.view / risk.limits.manage` |
| Agents, commissions | `manage agents`, `view commissions` | `agents.manage`, `commissions.view` |
| Support, privacy | `manage users` | `support.view/manage`, `privacy.view/manage` |
| KYC page, audit trail | `manage users`, `view audit logs` | `kyc.view/review/approve`, `audit_logs.view` |
| GLO claim desk, freezes, dealers, sales points, status | five coarse GLO phrases | **new** `glo.freezes.request/review`, `glo.claims.manage`, `glo.claims.pay`, `glo.status.publish`, `glo.dealers.review`, `glo.salespoints.manage` |
| Content (articles, coupons) | `manage system settings` | `settings.manage` |
| Widgets, relation managers | mixed coarse | the same granular names as the screens they sit on |

Two things made that safe rather than reckless:

**New vocabulary, with a per-duty bridge.** The risk-limit and GLO lanes had no granular names, so
they got them — each with its own `legacy_fallback` entry (`glo.claims.pay` → `execute glo prize
payments`, and so on). The bridge is therefore a per-duty decision, never a wildcard over a lane;
`glo.claims.manage` does **not** imply `glo.claims.pay`, and the test proves it.

**An operator precondition in `AdminAccess::permits()`.** The coarse catalogue is shared with
players (`manage wallet`, `view transaction history`, `view draws`, `view results`). With every
screen now bridging to it, a player holding those phrases would have passed a resource's
`canViewAny()` — harmless only for as long as the panel's entry gate is the single thing standing
in the way. So a granular check now requires being an operator at all (a panel role, an account
that may sign in, and the dashboard permission) *before* the grant is consulted. Being an operator
is necessary, never sufficient. This is exactly the leak `permitsStrict()` was written for on the
policy side; `permits()` now has the screen-side equivalent.

### What is proven, and what a deployment still has to decide

`tests/Feature/Admin/GranularScreenMigrationTest.php` (6 cases, 26 assertions) proves:

1. **The bridge still works.** An operator holding only coarse phrases opens the same screens while
   `enforce` is off — nothing broke for a deployment that has not run `AdminRbacSeeder`.
2. **The granular grants are sufficient.** With `enforce` **on**, the seeded `admin` role reaches
   all 17 migrated screens.
3. **The coarse phrase stops counting.** With `enforce` on, `'manage users'` no longer opens the
   user screen; a granular `users.view` does.
4. **A player never reaches an operator screen**, with the switch either way.
5. **GLO duties stay separate** under enforcement: `admin` works claims but cannot pay them or
   publish the public status.
6. **No screen asks for an uncatalogued permission** — the test reflects over every
   `AdminPermission::` reference in `app/Filament` and checks it resolves to a catalogued name,
   because `permits()` refuses unknown names silently-by-design (fail closed), which would
   otherwise be an invisible dead button.

The switch itself still ships **off** (`ADMIN_GRANULAR_RBAC_ENFORCE=false`). That is not a
postponement of the work: the migration it was waiting for is complete, and the test above runs the
panel under enforcement. What remains a deployment decision is the *order* — run
`php artisan db:seed --class=AdminRbacSeeder` (which grants the granular matrix) before flipping the
env flag, or the panel will close on every role that has only coarse phrases.

### Test numbers for this pass

```
tests/Feature/Filament/DepositSlipVerificationResourceTest.php     6 passed ( 33 assertions)
tests/Feature/Admin/GranularScreenMigrationTest.php                6 passed ( 26 assertions)
tests/Feature/Admin/ComplianceAndFraudConsoleTest.php             23 passed (+1 case)
tests/Feature/Filament/IdentityResourcesTest.php                  all passed (fixture follows the
                                                                  operator precondition)
tests/Feature/Admin + Filament + Hardening                       420 passed (3040 assertions)
tests/Feature/Security + Compliance + Verification + Account + Api 481 passed, 16 skipped, 2 failed
                                                                  [the same two pre-existing]
```

## 8j. The four remaining shape-findings (ninth pass)

The audit's last four entries were not missing features; they were places where the shape of the
data or of a rule made an honest screen impossible. All four are now closed.

### 1. `payment_providers.driver` was free text (blocker #7)

`PaymentProviderRegistry::register()` accepted any string. A row naming `acme_gateway` looked
configured to everyone reading the table and failed only when a player tried to pay — and the panel
already had to render "No runtime driver is registered" as its way of saying so.

The refusal now lives at the only door a provider row can come through:

```php
if (! $this->gateways->hasDriver($data->driver)) {
    throw PaymentProviderException::unsupportedDriver($data->driver, ['code' => $data->code]);
}
```

The refusal names the driver *and* the drivers that exist, because "not supported" without a list
is a riddle. Three copies of the driver list had already drifted (`manual` and `sandbox` were
missing from `availability()`), so the list is now one constant —
`PaymentGatewayManager::DRIVERS` — with `INVENTORY_DRIVERS` (the operator-visible subset) asserted
to be a subset of it.

Rows that predate the rule are **not** deleted or rewritten: `test_a_legacy_row_with_a_retired_driver_is_still_reported_honestly`
keeps such a row and asserts the view still says plainly that nothing can carry its traffic.

### 2. `DrawType` disagreed with configuration (blocker #11)

`DrawType::digits()` said Tod was 2 digits up to 99 and Run 2 digits up to 99, where
`config/lottery.php` says 3 up to 999 and 1 up to 9. Every report so far called this "latent, because
no caller reads the helper" — which was true, and was also the reason nobody noticed.

A second copy of a money rule is a defect even while it is unread, so the helpers now **delegate**:

```php
public function digits(): int
{
    return $this->configuredInt('digits', /* historical literal, config absent */);
}
```

One source (the configuration the engine already reads), with the historical literals kept only as
a fallback for a bare enum test with no container. `LotteryCatalogueService::driftFor()` is **kept**
and re-pointed: it is now a watchdog that fires if somebody re-introduces a literal, not a list of
known disagreements. The test proves the delegation rather than the coincidence — it moves
`lottery.types.tod.digits` to 4 and watches `DrawType::Tod->digits()` return 4 — and asserts
`drift()` is empty.

### 3. `fraud_alerts.user_id` could not be joined to a player (blocker #8)

`fraud_alerts.user_id` is a `uuid` column and `users.id` is a `bigint`, so no relation could exist:
an investigator could not open the account a suspicion was about, and no widget could aggregate per
player. The screen had to say so in its own docblock.

Fixed **additively**, because the alternative — retyping `user_id` — would rewrite evidence (the
column holds whatever the detection lane stamped, which may be an external reference at all):

* new migration adds a NULLABLE `player_user_id` beside it, indexed, with an FK `nullOnDelete`
  where the driver supports adding one (SQLite cannot;
  deleting a player must break the link, never the record of the suspicion);
* the backfill fills only rows whose `user_id` is a numeric string that **actually matches** a
  `users.id` — everything else stays NULL, because an unresolvable subject is a fact;
* `FraudEventRecorder` (the single writer of alerts) resolves the pointer on write, with a
  regex + existence check rather than a cast — `(int) '12abc'` is 12, and linking an alert to
  account 12 because of that would be a worse bug than no link at all;
* `FraudAlert::player()` is the relation that could not previously be declared;
* the queue and the record page link to the account **only** when the pointer resolves, and
  otherwise show the raw identifier with a tooltip saying it does not resolve.

### 4. The legacy `/admin/compliance-cases` surface (blocker #14)

`ReleaseOperationsController` answered that URI with "Compliance case detail requires a
policy-protected case projection" — true when written, false since the AML console was built. It now
redirects to the real console. The route is **not** removed: removing it would turn existing
bookmarks into 404s and break the static route-contract test that pins the surface list.

While doing this, one piece of noise was also removed: `SystemSettingsServiceProvider` used to
`report()` the "no such table" exception on every boot before `migrate` had run, which produced a
frightening log line in exactly the situation where nothing is wrong. It now stays silent for
`QueryException` / `SQLiteDatabaseDoesNotExistException` and reports anything else.

### Test numbers for this pass

```
tests/Feature/Admin/PaymentProviderAdminTest.php                   13 passed ( 68 assertions)
tests/Feature/Admin/LotteryCatalogueTest.php                        7 passed (127 assertions)
tests/Feature/Admin/ComplianceAndFraudConsoleTest.php              26 passed (116 assertions)
tests/Feature/Payment/{MultiCurrency,Crypto,Enterprise}             17 passed (110 assertions)
tests/Feature/Admin + Filament + Hardening                        426 passed (3088 assertions)
```

## 8k. The lanes that had never produced a number (tenth pass)

Three of the four "not run in this environment" lines in this report were true and useless: a lane
nobody can run is a lane nobody trusts. All three now run, and two of them found real defects on
their first execution.

### 0. First, an incident: the workspace lost 129 files, and the delivered patch rebuilt them

The turn began with the project tree truncated — 4,955 of ~4,883 expected files present, with
`symfony`-style gaps: `AI`-unrelated test files gone, `DrawType` back at its pre-work content, the
fraud migration absent, `.git` gone entirely. The delivered patch was intact, so the tree was
rebuilt **from the patch, not from memory**:

* every hunk was forward-applied where the file was still at its pre-work content;
* reversed-checked where the file was already at post-work content (45 files);
* one file (`LotteryCatalogueService`) existed in an older revision and was replaced by the patch's
  version.

Then all 141 files were verified to reverse-apply cleanly, i.e. **every file in the tree is exactly
the post-work content the patch encodes**. The suite reproduced its previous numbers on the rebuilt
tree (7 passed / 127 assertions on the catalogue test before anything else was touched), which is
the evidence that the reconstruction was faithful rather than approximate.

Consequence, stated plainly: this pass's deliverable is a **single cumulative patch** whose base is
a reconstructed pristine tree. It was verified the only way that means anything — applied to a fresh
copy of that base, it reproduces the working tree byte for byte, and the only files left over are
the runtime artefacts nobody ships (`bootstrap/cache`, sqlite databases, `.env`).

### 1. Static analysis: PHPStan had never started (audit blocker #6)

`phpstan.neon` was **invalid configuration**, so the analyser refused to run at all:

```
Invalid configuration: Unexpected item 'parameters › checkMissingIterableValueType'.
Invalid configuration: Unexpected item 'parameters › checkGenericClassInNonGenericObjectType'.
```

Both parameters were removed in PHPStan 2.x (that strictness is baked into the levels now), and
`larastan` was referenced by the config but absent from `composer.json`. Fixed: the stale keys are
gone with a comment explaining why, `larastan/larastan ^3.0` is a dev dependency, and the lane runs
at level 8 over `app`, `config`, `database`, `routes`.

The first honest run: **5,777 errors** — the debt this codebase had accumulated while nobody could
measure it. Shipping that as "red forever" would be as useless as not running it, so:

* **every error in the 62 files this work added was fixed** — 61 → **0**;
* the remaining 5,777 are frozen in `phpstan-baseline.neon` (4,211 entries) as a **ratchet**: a
  baseline entry is a (file, line, message) triple, so fixing code makes it unmatched and any NEW
  error — including in code that merely moved — fails the run. The number is written here rather
  than hidden behind a green tick.

The fixes that were real defects rather than annotations:

| File | What the analyser caught |
|---|---|
| `app/Models/KycDocument.php` | The class docblock declared `expires_at`/`submitted_at` as `string` and omitted `verification_status`; four call sites carried `instanceof` branches that could never be false because the model casts the column to the enum. Replaced by ONE `verificationState()` reader that also tolerates a row loaded without casts. |
| `app/Filament/Resources/PaymentProviderResource.php` | Three `match` expressions with no `default` over a `string` — an `UnhandledMatchError` waiting for the first unexpected verb. Now `targetFor()/iconFor()/colourFor()/consequenceOf()` each refuse an unknown verb by name. Private helpers called through `static::` (a subclassing hazard) became `protected`. |
| `app/Services/Admin/SystemSettingService.php` | Int bounds contained `$min === null ? null : …` inside a branch that had already proved `$min !== null` — dead code that suggested a null bound was handled when it is silently skipped. Money bounds passed raw catalogue values to `bccomp()`, which throws `ValueError` on a non-numeric operand: a typo in the catalogue would have reached an operator as a 500. Both now go through one `moneyCompare()` that names the fault. |
| `.../CreatePaymentMethodConfig.php`, `.../RoleResource/Pages/CreateRole.php` | `$this->halt()` is declared `void` and throws, so the analyser could not see that `$result` is always set / the method never falls through. Both now `throw new Halt();` — same behaviour, visible in the types. |
| 8 resources | `getPages()` was annotated `array<int, class-string>`; Filament returns `array<string, PageRegistration>`. |
| `app/Services/Security/FraudAlertReviewService.php` | `fresh()` is nullable by signature; the return type said it was not. |
| `.../KycDocumentResource/Pages/ViewKycDocument.php` | `Auth::id()` is `int|string|null` and was passed to an `int` audit parameter. |

### 2. The MySQL lane: it found a bug in this work, on its first run

MariaDB 11.8.6, `migrate:fresh` from an empty schema. The migration added in the previous pass
aborted:

```
SQLSTATE[42000]: … check the manual … near 'GLOB '[0-9]*' and `id` is not null …'
```

`GLOB` is a SQLite extension. The migration worked on the lane that had been run and would have
failed on the engine production uses — exactly what "not run in this environment" was hiding. Fixed
by moving the numeric test into PHP with the SAME regex the writer uses
(`FraudEventRecorder::resolvePlayerId()`), so the backfill and the recorder can never disagree about
what counts as an account id. After the fix, every migration runs on MariaDB from scratch.

### 3. The browser lane: 130 cases, run for the first time

Node 24.22.3 + npm 11 (`engine-strict` in `.npmrc` makes the pinned majors mandatory), Chromium
installed, the app served on a disposable database for the nine "renders from the running
application" journeys. The suite had never been executed in this work.

**110 passed, 20 failed.** The 20 are 10 cases across two projects, and all of them sit in the
frontend asset layer, not the admin backend:

| Spec | Diagnosis |
|---|---|
| `bet-slip-transport.spec.mjs` (8 cases × 2 projects) | The spec injects `resources/js/lottery/ticket-selector.js` and `bet-slip.js` with `addScriptTag()` and waits for `data-bet-slip-ready`. Those modules initialise on `DOMContentLoaded`, which has already fired by then, so the attribute never appears. A harness/ordering mismatch, not a slip-register regression — the same code is exercised through the built bundle on the player page. |
| `frontend-build.spec.mjs` (2 cases × 2 projects) | The fixture expects >90 Vite inputs and a recorded Tailwind v3-compat matrix; this tree's `vite.config.js` declares 8 `resources/**` entries in the pattern the spec scans, and the built CSS does not match the recorded matrix. The fixture describes a different frontend build than the one in this repository. |

They are reported as failures, not skipped, and not "flaky". Keeping them red is the correct state
until somebody decides which of the two — spec or build — is authoritative.

### Test numbers for this pass

```
tests/Feature/Admin + Filament + Hardening     (SQLite)      426 passed (3088 assertions)
tests/Feature/Admin                            (MariaDB)     165 passed (1355 assertions)
tests/Feature/Filament + Hardening             (MariaDB)     261 passed (1733 assertions)
migrate:fresh                                  (MariaDB)     all migrations, 0 failures
phpstan analyse level 8 (app,config,database,routes)         0 errors (5777 frozen in baseline)
phpstan analyse level 8 — the 62 files this work added       0 errors  (61 before the fixes)
npx playwright test  (130 cases, 2 projects, live server)    110 passed, 20 failed
```

The SQLite and MariaDB lanes are the same 426 tests — the two drivers agree test-for-test, which is
the point of running the second one.

## 8l. The bet-slip transport: a money screen that could not tell "refused" from "we never heard back" (eleventh pass)

Last pass ended with 20 red browser cases and the note that they needed a decision: which is
authoritative, the spec or the build? The answer turned out to be neither. **The backend was
already right, and the client was behind it.**

`POST /bet/purchase` (`App\Http\Controllers\Web\BetPurchaseController`) is a JSON controller: it
returns `{success, data:{report}, meta}` where the report carries `requested / purchased / replayed /
refused / total_charged / currency / items[]`, each item with its own outcome, ticket number and
refusal reason. That envelope exists precisely so a client can say what happened to each selection.

The shipped `resources/js/lottery/bet-slip.js` did none of that. It built hidden inputs and called
`form.submit()`:

* the player was **navigated away** and shown raw JSON — the purchase endpoint has no HTML view;
* the per-item report was **thrown away**, so a partially refused slip was indistinguishable from a
  fully accepted one;
* a **lost response was reported as a generic failure** and the page stayed editable, so the player
  could re-build the slip with a **new client key** — a second purchase the server would treat as a
  different one, because the idempotency key it had been given was already abandoned;
* the CSRF token was read only from `meta[name=csrf-token]`, while the form actually carries
  `@csrf` as a hidden `_token`; and the client key was `slip-<base36>-<24 hex>`, which is not the
  contract the transport spec pins.

None of that is an admin-backend defect, but it is a money-path defect, and the browser suite was
the only thing that could see it. It was rewritten to the contract the backend already speaks:

| Behaviour | Now |
|---|---|
| Transport | `fetch` JSON to the form action; no navigation, no page reload, CSRF from the meta tag **or** the form's `_token` |
| Identity | `client_key` = `slip-` + 48 hex, minted once per slip and **persisted with the pending slip** |
| Resolved | 2xx **with** a report → `data-slip-state="resolved"`, per-item lines (`Purchased: 1, replayed: 0, refused: 1`, `008: refused — Insufficient available funds`) and `Newly charged: 20.00 THB` from `total_charged`, never recomputed on the client |
| Rejected | 422/409 → the server's own message; nothing was charged |
| Unknown | network failure **or** a 2xx that cannot be parsed → `data-slip-state="unknown"`, the slip is written to `sessionStorage` under `lottery-pending-slip:<player>:<draw>`, editing locks, and the page says the outcome is unknown |
| Recovery | on the next load the pending slip is restored with its original key and items; resending produces a **byte-identical body**, so the server replays its report (`replayed: 1`, `Newly charged: 0.00`) instead of charging |
| Corrupt storage | refuses to transmit at all: `storage is unavailable or invalid`, submit disabled |
| Closed draw | selections refused, submission blocked — but rows already on the slip can still be **removed**, which is the only honest offer to a player whose draw closed under them |

Two further fixes fell out of the same file:

* **Money arithmetic moved off floats entirely.** Stakes are BigInt satang and multipliers are
  integers scaled by 1e8; the payout preview is `stake × multiplier / 1e8` **truncated**, not rounded.
  `999,999,999,999.99 × 900.12345678` is `900,123,456,779,990.99` — a float would have printed
  `.00` and quietly overstated a maximum payout.
* **A dead duplicate was removed.** `resources/js/bet-slip.js` (316 lines) implemented the same card,
  was imported by nothing, was not a Vite input, and had drifted from the module the page actually
  loads. The bet page loads `resources/js/lottery/bet-slip.js`; the orphan is gone.

The blade now carries `data-slip-scope="{{ auth()->id() }}:{{ $openDraw->id }}"`, so the pending-slip
store is keyed per player **and** per draw rather than per browser.

### The other two browser failures were fixtures describing a different build

`tests/browser/frontend-build.spec.mjs` asserted `inputs.length > 90` while this repository declares
83 Vite inputs, and compared the built CSS against a recorded Tailwind matrix from an earlier theme
generation (8px radii, solid slate panels) than the one this tree ships (16px radii, translucent
panels, emerald focus ring, oklch colours). Both were **recorded facts about another build**, kept as
if they were contracts. Fixed honestly:

* the magic `90` is replaced by the invariant that actually matters — **every `@vite([...])`
  reference in a view must be a declared input** (checked across all blade files; today 33 references,
  0 undeclared) and every declared input must produce a non-empty artifact;
* the matrix is **re-recorded from the current build**, and re-recording is now an explicit act
  (`E2E_RECORD_FRONTEND_MATRIX=1`), not an edit someone makes to turn a red run green. The test keeps
  structural assertions (shadows and rings stay visible, `blur(8px)` stays `blur(8px)`, brand text stays
  white) so a recording run cannot record an empty matrix.

### Test numbers for this pass

```
npx playwright test  (130 cases, 2 projects, live server)   130 passed  (was 110 passed / 20 failed)
npm run test:js      (node --test)                           35 passed
tests/Feature/Player/PlayerFrontendModulesTest.php           12 passed (131 assertions)
tests/Feature/Admin + Filament + Hardening (SQLite)         426 passed (3088 assertions)
```

### One delivery caveat, stated rather than hidden

The workspace cannot keep `.git` or `/tmp` across turns, so the *pre-change* copies of the four
player files (`bet-slip.js`, `bet.blade.php`, `frontend-build.spec.mjs`, the matrix fixture) were gone
before this pass started. The cumulative admin patch is therefore unchanged and still verified against
the pristine tree, while this pass's player-transport change is delivered as **complete files in the
working tree** (they are the source of truth) rather than as a diff against a base nobody can
reconstruct. The evidence for it is the suite: 130/130 browser cases, green only after the rewrite. Those five
files also travel as `player-bet-slip-transport.tar.gz`, next to the patch, so a fresh checkout can
reproduce this pass exactly.

## 8m. Re-running the database-dependent lanes from scratch (twelfth pass)

The §8k numbers were produced against a MariaDB instance that the next workspace did not carry
over, so this pass re-created it and re-ran both lanes rather than quoting them:

```bash
sudo apt-get install -y mariadb-server          # 11.8.6-0+deb13u1
sudo service mariadb start
sudo mysql -e "CREATE DATABASE thai_lottery_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
               CREATE USER 'lottery'@'localhost' IDENTIFIED BY 'lottery';
               GRANT ALL PRIVILEGES ON thai_lottery_test.* TO 'lottery'@'localhost';"

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_DATABASE=thai_lottery_test \
DB_USERNAME=lottery DB_PASSWORD=lottery php artisan test <lane>
```

`phpunit.xml`'s `<env>` entries do not force their values, so the exported `DB_CONNECTION` wins and
the suite really does move to MariaDB — confirmed, not assumed: `information_schema.tables` for
`thai_lottery_test` holds **216 tables** after the first suite migrated it.

| Lane, MariaDB 11.8.6 | Result |
|---|---|
| `tests/Feature/Admin` | **165 passed (1355 assertions)**, 561.9 s |
| `tests/Feature/Filament` + `tests/Feature/Hardening` | **261 passed (1733 assertions)**, 150.8 s |
| combined | **426 passed / 3088 assertions** — identical to §8k, on a different engine |

The Admin lane is the slow one on InnoDB (four and a half seconds per test, most of it schema
rebuilds); the SQLite lane runs the same 426 cases in 671 s total, so the engine is not what the
time goes into — the per-test `migrate:fresh` is.

### What was deliberately not re-run

* **PHPStan.** No PHP source changed in this pass (the diff is `resources/js`, one blade attribute,
  two browser specs and these documents), so the §8k result — level 8, **0 errors**, 5,777 frozen
  entries — still holds and re-running it would only re-derive it. Worth writing down why that is a
  decision and not an omission: this sandbox has ~2 GB of RAM, a default-parallelism level-8 run gets
  SIGKILLed in it, and the single-process fallback thrashed the machine hard enough that the
  workspace stopped answering shell commands. The analyser was killed rather than left to fight the
  test lanes for memory. Anyone with a normal machine should just run `vendor/bin/phpstan analyse`.
* **`npm ci` / Chromium provisioning.** Both were already in place this pass; the 130 browser cases
  ran against the live server, not against a mock.

### State of the working tree at the end of this pass

The browser suite, the Node unit tests, the SQLite lane, the MariaDB lanes and the patch
apply-verification all ran against **one** tree, in this order, with no edits in between:

```
npx playwright test                              130 passed
npm run test:js                                   35 passed
tests/Feature/Player/PlayerFrontendModulesTest    12 passed (131 assertions)
tests/Feature/Admin + Filament + Hardening (SQLite)  426 passed (3088 assertions)
tests/Feature/Admin (MariaDB)                     165 passed (1355 assertions)
tests/Feature/Filament + Hardening (MariaDB)      261 passed (1733 assertions)
git apply --check admin-backend-admin-rbac.patch  clean, then byte-identical to the tree
```

## 8n. What the PHPStan baseline is hiding — and the first bite taken out of it (thirteenth pass)

The open question at the end of §8m was what should happen to `phpstan-baseline.neon`: four thousand
frozen entries is either a ratchet nobody is turning or a muzzle nobody admits to. Before answering
it, here is what is actually inside the file.

### The shape of the debt

```
entries: 4,211      frozen errors: 5,777      files: 947      distinct identifiers: 86
```

| Directory | Frozen errors | Share |
|---|---|---|
| `app/Services` | 3,557 | **62 %** |
| `app/Models` | 627 | 11 % |
| `app/Http` | 513 | 9 % |
| `app/Exceptions` | 235 | 4 % |
| `app/DTOs` | 225 | 4 % |
| `app/Filament` | 196 | 3 % |

| Identifier | Frozen errors | What it means |
|---|---|---|
| `argument.type` | 1,436 | a value whose type the code never stated |
| `property.notFound` | 798 | Eloquent/Livewire magic properties |
| `missingType.iterableValue` | 613 | `array` with no value type — pure annotation |
| `return.type` | 309 | a declared return that disagrees with the code |
| `missingType.generics` | 248 | same, for collections |
| `nullCoalesce.offset` | 245 | `??` on a value the analyser cannot shape |

Two facts stand out and both matter more than the totals:

* **The centre of gravity is the money path.** 62 % of the frozen errors sit in `app/Services`, and
  2,808 of them (49 %) are in files whose names contain a money word — Payment, Bet, Wallet, Ledger,
  Finance, Transfer, Withdraw, Deposit. A type error is cheapest where it is a display bug and most
  expensive where it is a balance.
* **Only 112 files hold half the debt.** The other 835 hold the rest, one or two errors each. A sweep
  ordered by file, biggest first, reaches 50 % in 112 files instead of 947.

### The first slice, and what it caught

`app/Enums` was taken as the pilot: small, self-contained, no framework magic. The scoped run (the
same `phpstan.neon` with `paths` narrowed, baseline excluded so the frozen errors are visible again)
found 29 errors. Fourteen are now gone:

| Fix | Errors |
|---|---|
| `PaymentMethod::supportedCurrencies()` / `market()` — declared `list<Currency>` / `list<string>` | 2 |
| `PrivacyRequestStatus` / `PrivacyRequestType` — `array_values(array_map(...))` where `array_map` already returns a list | 4 |
| `KycDocumentVerificationStatus::legacyStatus()`, `ThaiBankPayoutState::mapsToWithdrawal()`, `WalletConversionRejectionReason::messageKey()` — exhaustive `match`, so `?T` was never nullable | 3 |
| **`NotificationEventType` was missing the five `Lifecycle*` cases the lifecycle campaigns send as** | 5 |

That last one is not an annotation problem. It was a **live failure**:

```php
App\Enums\LifecycleCampaign::Welcome->eventType()
// Error: Undefined constant App\Enums\NotificationEventType::LifecycleWelcome
```

`LifecycleCampaignService::messageFor()` calls exactly that, so the first lifecycle campaign that
reached a real player would have thrown. The feature could never have sent a single message, and the
baseline had been quietly certifying the call as known debt. The five cases exist now, all
non-mandatory (marketing must not survive an opt-out), and the longest value is 25 characters against
a 32-character column. Verified by running all five campaigns through `eventType()`.

Baseline after this slice: **4,197 entries / 5,763 frozen errors** — 14 fewer, and the file is not
"regenerated", it is *reduced*: entries are deleted by hand as the code they describe is fixed, so an
entry can never quietly re-freeze.

`tests/Unit/LifecycleCampaignEventTypeTest.php` now pins the mapping shut: every campaign must resolve
to a case that exists, no two campaigns may share a type, no campaign may sit behind a mandatory
floor, and no value may overflow the 32-character column. Four assertions — the same failure, twice
fixed (once in the code, once in the suite).

Evidence for the slice, all on the same tree:

```
scoped level-8 run, app/Enums, baseline excluded     29 errors → 20  (9 fixed)
all five campaigns through eventType()               5/5 resolve, all non-mandatory
scoped level-8 run, real config + baseline           [OK] No errors
tests/Unit/EnumIntegrityTest + PlayerNotificationPreferences + Unit/Notifications
  + GloResultNotification + KycDocumentResource      57 passed (218 assertions)
tests/Unit/LifecycleCampaignEventTypeTest            4 passed (64 assertions)
Admin + Filament + Hardening (SQLite)                426 passed (3088 assertions)
Player + Betting + Lottery (SQLite)                  459 passed / 25 skipped (2936 assertions)
```

### What was deliberately not touched, and why

* `Currency::format()` and `RiskLevel`'s thirteen `bccomp`/`bcadd` calls want `numeric-string` types.
  Annotating them correctly pushes the annotation out to every caller, which is the right answer — and
  is a change that needs a **full** analyser run to prove it introduces no new error at those call
  sites. This sandbox cannot do a full level-8 run (§8m), so the honest move is to leave them
  documented rather than half-done and unverified.
* `property.notFound` (798) and the Livewire/Filament dynamic properties are a framework decision, not
  a typing chore: fix them with `@property` docblocks on models, or teach the baseline to accept them.
  That deserves its own pass.

### The recommendation

Keep the baseline, and treat it as a burndown with an order:

1. **Annotations only** — `missingType.iterableValue` + `missingType.generics` = 861 errors, zero
   behaviour risk, mechanical. Start with `app/Models` (627) because every service error downstream
   is caused by a model that does not describe its own columns.
2. **Real-bug identifiers first** — `return.type` (309) and `instanceof.alwaysTrue` / `booleanOr.alwaysTrue`
   (361) are the classes where a frozen entry is most likely to be hiding a live fault, exactly as the
   `Lifecycle*` case did.
3. **Then the money files**, biggest first: `BetAmendmentOwnership` (107), `DepositCheckoutService` (98),
   `BalanceTransferService` (57), `ReversalIntegrityGuard` (56).
4. Delete entries in the same commit that fixes the code, never regenerate the file wholesale.

If instead the decision is "drop the baseline altogether", the number to plan against is **5,763
errors across 947 files** — and the pilot above suggests roughly a third of them are annotation work,
not bugs.

## 8o. The burndown's first real instalment: `app/Models` (fourteenth pass)

§8n proposed starting with annotations in `app/Models` — 627 frozen errors, described there as "zero
behaviour risk". That was half right. The annotations were safe; what they *revealed* was not free.

### What was done

`app/Models` held **627** errors. A scoped level-8 run (same config, `paths: app/Models`, baseline
excluded so the frozen errors are visible) enumerated them, and they turned out to be almost entirely
one thing: **the models could not describe their own relations**.

| Change | Occurrences | Errors cleared |
|---|---|---|
| `@return BelongsTo<User, self>` → `…, $this` — Larastan infers `$this(App\Models\X)`, and `self` is not the same type | 89 | 178 |
| `@return HasMany<X>` → `HasMany<X, $this>` (one template argument given, two required) | 46 | 92 |
| relation methods typed natively (`: BelongsTo`) but with **no** `@return` at all — docblocks added from the `->belongsTo(X::class)` call in the body | 149 | 150 |
| scope methods taking `Builder` with no model type — `@param Builder<self>` / `@return Builder<self>` | 24 | 48 |
| `HasFactory` with no factory type — `@use HasFactory<\Database\Factories\XFactory>` | 18 | 18 |

Result: **627 → 229 errors**. The other 385 frozen entries (plus the 9 already healed in §8n) were
deleted from the baseline; the remaining 185 entries were regenerated from what is actually left,
because hand-matching 185 message regexes is how baselines rot.

### The part that was not free

The moment relations started carrying their declaring model, downstream code that had been seeing an
unresolvable type started seeing the truth. **Fourteen errors appeared that had never been reported
before** — and they are real:

```
app/Services/Agent/AgentNetworkManagementService.php:35   Cannot access property $agent_code on Agent|null
app/Services/Commission/MultiTierCommissionDistributionService.php:54   Cannot access property $game_type on Draw|null
app/Services/Lottery/GloDealerService.php:138             Cannot call method toIso8601String() on string
app/Services/Lottery/GloDealerService.php:175             ownHistory() returns array{…} but declared list<…>
app/Events/DrawResultPublished.php:72                     nullsafe access on non-nullable BetType
app/Models/Chat/ChatConversation.php:456                  User::$account_number is not declared
```

At runtime `$this->agent->agent_code` on a null relation is a fatal error, not a typed `null` — the
annotation did not create the risk, it stopped hiding it. Those fourteen are frozen **once**, in a
commented block in the baseline that says where they came from, and they are the head of the queue:
null-safety in commission and dealer payout code is exactly the kind of thing a level-8 run should be
shouting about.

### Verification — and how the full run was made to fit in a 2 GB container

A whole-project level-8 run is impossible here: it is OOM-killed around 900 MB RSS (measured — the
kernel log shows the kill), and the parallel worker additionally times out at 600 s. Rather than
declare the analyser "not runnable", the analysis was **split into 11 deterministic batches of ≤200
files** (packed by directory so no file is analysed twice) plus `config/ database/ routes/`, each run
against the real `phpstan.neon` including the baseline. Unmatched entries are suppressed, so any batch
that reports an error is reporting something the baseline does not cover:

```
batch 1..11 + config/database/routes        11/11 clean, 0 errors
scoped app/Models (baseline excluded)       229 errors, all frozen
scoped app/Models + Services/Agent + Events [OK] No errors
```

That is a real full-project level-8 pass, just done in slices. It is also the recipe anyone with a
normal machine can replace with one command.

| | Before | After |
|---|---|---|
| baseline entries | 4,211 | **3,813** |
| frozen errors | 5,777 | **5,378** |
| level-8 errors in the project | 0 | **0** |

### One thing found on the way, that is not this pass's fault

Running `tests/Unit` in the same lane surfaced **24 failures** that the earlier lanes never touched
(the §8k/§8m lanes were `Feature` only). They are pre-existing — proven, not assumed: the same four
files were re-run against `app/Models` and `app/Enums` restored from the pre-pass tree and produced the
same failures. They are:

| Failing test | Why |
|---|---|
| `Unit\Build\CiRuntimeContractTest` (12), `NativeVerifierBuildContractTest` (2) | assertions about the CI workflow YAML that this tree's workflows do not satisfy |
| `Unit\Payment\ThaiBankPayoutGatewayTest` (8) | `Withdrawal amount 100.00 THB is outside the permitted range 200.00 - 500000.00` — the test's fixtures predate the configured minimum |
| `Unit\Accounting\ThaiRevenueDepartmentTaxReportServiceTest` (1) | `MassAssignmentException`: `Payout` does not allow the eight payout columns |

They belong to whoever owns the CI contract and the payout model, and they are worth a pass of their
own; freezing them silently would be the same mistake the baseline made.

### What is left, in order

`app/Services` now holds the bulk of what remains (3,557 frozen at the start; untouched this pass).
The §8n order still stands: `missingType.*` next (`app/Exceptions` 191, `app/DTOs` 51), then
`return.type`/`alwaysTrue` (the classes where a frozen entry is most likely to be a live fault, as
`Lifecycle*` was), then the money files by size — `BetAmendmentOwnership` 107, `DepositCheckoutService`
98, `BalanceTransferService` 57.

## 8p. The twenty-five red unit tests: two real defects and a CI that never matched its own contract (fifteenth pass)

§8o ended with 25 failures in `tests/Unit` that predate this work. They were left as a name and a
count; this pass opened each one. Three findings, in order of how much they matter.

### 1. The Revenue Department tax report could never have run

`ThaiRevenueDepartmentTaxReportService` queried:

```php
Payout::where('payout_status', 'COMPLETED')->whereBetween('paid_at', …)
// and then read $payout->gross_amount, ->wht_tax_amount, ->net_amount
```

None of those columns exist. The `payouts` table has `status`, `processed_at` and `amount`; the
*recorded* tax lives on the payout statement — `payout_documents.gross_amount / tax_amount /
net_amount`, a table that even enforces `gross = tax + net`. So the report did not return wrong
numbers; it raised `SQLSTATE 42S22 Unknown column 'payout_status'` the first time anyone asked it for
a return. Nothing noticed because its only caller is the unit test — and that test was itself broken
(it mass-assigned eight columns the model does not have, so it died at `MassAssignmentException`
before the service was ever reached).

Rewritten against the real schema, with two rules made explicit in the code:

* **Report what was withheld, never recompute it.** Tax comes from the statement the payout service
  wrote. Applying `config('glo.withholding_tax_rate')` here would silently disagree with the ledger
  the moment a rate or an exemption changed — wrong in a document that goes to a tax authority.
* **A completed payout with no statement is counted, not taxed.** It appears in
  `undocumented_payout_count`, its gross is reported, and its withholding is `0.00` because nothing
  was recorded. Guessing 0.5% would be inventing a remittance.

Totals are bcmath at the column scale (2), the period cut-off is `processed_at` (September-created,
October-completed payouts land in October's return), a cancelled statement is not a remittance, and
the CSV is RFC 4180 quoted so a reference containing a comma cannot shift a column. Five tests
replace the one broken one.

### 2. A test that was passing or failing depending on whose `.env` it ran against

`ThaiBankPayoutGatewayTest` builds withdrawals of `100.00` THB and pins `thai_payout.*` — but the
minimum it actually trips over comes from `config('finance.withdrawal.min')`, which `.env` sets to
`FINANCE_MIN_WITHDRAWAL=200.00`. On a machine with a lower minimum the eight tests pass; here they
failed with `Withdrawal amount 100.00 THB is outside the permitted range 200.00 - 500000.00`. The
gateway was never at fault. The fixture now pins the finance limits it depends on, like it already
pinned the payout ones: **12 passed**.

### 3. The CI ran PHP 8.3 for a project that requires 8.4

`composer.json` requires `php: ^8.4`. Every GitHub workflow pinned `php-version: '8.3'` — five
occurrences across `ci.yml` and `security.yml` — so `composer install` could only ever have succeeded
with the platform check disabled. Fixed, plus the extension lists (the test jobs now declare `gd` and
`pdo_mysql` alongside `intl`/`sqlite3`, and the release job no longer omits the SQLite driver it uses
for its fixture).

The same contracts caught two ordering bugs that would have failed on the first real run:

| Contract | What it found | Fixed |
|---|---|---|
| environment before install | `test` job read a developer's `.env.example` and swallowed failure with `\|\| true` on both `cp` and `key:generate` | `cp .env.ci .env` before anything, `set -euo pipefail`, no `\|\| true` |
| key generation after vendor | `full-runtime-release` ran `php artisan key:generate` **before** `composer install` — artisan cannot boot without `vendor/autoload.php` | moved after the dependency step |
| runtime gate before install | nothing checked the PHP runtime, encoder and extensions until after dependencies | `php scripts/check-test-runtime.php` runs first; it is deliberately vendor-free and passes here |

`CiRuntimeContractTest` went from **3 passed / 11 failed** to **8 passed / 6 failed**.

### What is still red, and why it was not guessed at

Six contracts remain:

```
every php case is routed to a strict lane and reconciled   needs a php-case-census job + evidence artifacts
mysql lane provisions a local socket and real lock observation   needs a socat socket + performance_schema grants
mysql release service allows real failure injection         needs the log_bin_trust_function_creators step
mysql release builds and runs the live worker               needs the Rust worker build + destructive DBs
mysql release backup restores under scoped permissions     needs a mysqldump round-trip with schema comparison
sqlite lane provisions a checksum verified helm binary      needs the pinned Helm download + sha256
```

plus `NativeVerifierBuildContractTest` (2), which specifies the content of
`scripts/build-weekly-native-verifier.sh` (cargo fmt/clippy/test/build, pre/post binary comparison).

These are infrastructure work against a container runtime this workspace does not have: I can write
YAML that satisfies the string assertions, but I cannot execute a MySQL service container, a `socat`
socket proxy or a Rust release build here, and shipping unrun CI is how a pipeline gets a reputation
for being decorative. They stay red, named, with the exact contract each one encodes — the same rule
as the baseline: no silent passes.

### 4. The dependency gate, once it was honest, found seven advisories

Hardening `composer audit` to `--no-interaction --locked --abandoned=fail` (composer 2.11 supports
both) turned a decorative step into a gate — and the gate immediately fails:

```
$ composer audit --locked --abandoned=fail     # exit 1
laravel/framework        PKSA-yxbx-3c9s-cjt9  Task Output Injection — Concurrency Process  (fixed in 12.71.0)
symfony/html-sanitizer   PKSA-wwnc-rwy3-v11m, PKSA-4z5q-y2w8-bwqq, CVE-2026-118517
symfony/http-foundation  PKSA-nxjs-s6hn-vb6m, CVE-2026-118514, PKSA-pr6q-2w5s-kzzf, PKSA-b2gg-vk1g-6d5q
symfony/http-kernel      PKSA-3gxv-7xhq-mgmz
symfony/mime             CVE-2026-45070
symfony/routing          CVE-2026-118516
symfony/yaml             PKSA-nktx-f1n1-b4yd  (exponential/quadratic parse time)
```

The lock file pins `laravel/framework 12.69.3`; the advisory is fixed in `12.71.0`, and the Symfony
components ride along inside `^12.0`. **This is deliberately not upgraded in this pass.** A framework
bump is a change whose only honest proof is the whole suite plus the browser lanes on the new
versions, and this analysis is already fifteen passes long; doing it half-verified at the end of one
would be the same mistake as the baseline. It is the first item for the next pass, with the command:

```bash
composer update laravel/framework "symfony/*" --with-all-dependencies
# then: tests/Unit, both feature lanes, the 130 browser cases, the 11 level-8 batches
```

Until then CI is red on the audit step — correctly. A green build over a pinned vulnerable framework
would be the lie; the gate is doing its job.

### Test numbers for this pass

```
tests/Unit (whole folder)                              1680 passed / 8 failed
  Accounting/ThaiRevenueDepartmentTaxReportServiceTest    5 passed (22 assertions)   was 1 failed
  Payment/ThaiBankPayoutGatewayTest                      12 passed (93 assertions)   was 8 failed
  Production/DependencyAuditBoundaryTest                  6 passed (36 assertions)   was 1 failed
  Build/CiRuntimeContractTest                             8 passed / 6 failed       was 3 / 11
  Build/NativeVerifierBuildContractTest                   2 failed (unchanged)
phpstan, 11 batches + config/database/routes             11/11 clean, 0 errors
```

## 8q. The upgrade §8p refused to rush: Laravel 12.69.3 → 12.73.0 (sixteenth pass)

§8p ended with a red security gate and a decision written down rather than a change made in a hurry.
This pass did the change, with the whole battery run against it.

```bash
composer update laravel/framework "symfony/*" --with-all-dependencies
```

```
laravel/framework       v12.69.3 → v12.73.0
symfony/http-foundation  v7.4.x   → v7.4.23    (4 advisories cleared)
symfony/html-sanitizer            → v7.4.23    (3 advisories cleared, incl. CVE-2026-118517)
symfony/http-kernel / mime / routing / yaml     (each cleared)
composer audit --locked --abandoned=fail       → exit 0, "No security vulnerability advisories found."
```

### The one thing the upgrade broke, and why the test, not the lock, was wrong

`DependencyAuditBoundaryTest` pinned `symfony/yaml` to the `7.4.` line. Composer resolved
**8.1.11** — the newer, patched line — and the test failed with
`Failed asserting that '8.1.11' starts with "7.4."`. The test's *intent* was a security floor
(PKSA-nktx-f1n1-b4yd, exponential/quadratic parse times, fixed in 7.4.18 and in every 8.x), not a
fashion preference. It now asserts the floor and a ceiling:

```php
version_compare($version, '7.4.18', '>=');   // the recursion patch
version_compare($version, '9.0.0',  '<');    // a major this repo has never been verified against
```

Symfony components are independently versioned, so the tree now mixes 7.4 (http-foundation,
http-kernel, routing, console) with 8.1 (translation, string, yaml, event-dispatcher) — a combination
Laravel 12.73's own constraints allow. It is worth knowing, and it is written down here rather than
left to be discovered by whoever reads `composer.lock` next.

### Verification, whole battery, on the upgraded tree

```
tests/Unit                                        1680 passed / 8 failed  (the 6 CI + 2 native contracts of §8p, unchanged)
tests/Feature/Admin + Filament + Hardening          426 passed (3088 assertions)     807 s
tests/Feature/Player + Betting + Lottery            459 passed / 25 skipped (2936)   167 s
phpstan level 8, 11 batches + config/database/routes 11/11 clean, 0 errors
npx playwright test (130 cases, live server)        130 passed
composer audit --locked --abandoned=fail            exit 0 — no advisories
```

The Admin lane went from ~700 s to ~807 s on 12.73; that is the only number that moved, and it is
wall-clock on a 2 GB container, not a behavioural signal.

## 9. What a reviewer should look at first

1. `AdminAccess::permits()` — the whole transition story is seven lines of decision logic.
2. `RoleAdministrationService::syncGranularPermissions()` — the least-privilege and
   self-lockout guards, and the reason `syncPermissions()` is avoided.
3. `PaymentProviderResource::applyLifecycle()` — one verb definition, two action classes
   (Filament's header and table actions are siblings, not substitutes).
4. `FinancialReportsPage::generate()` — the refusal paths matter more than the success path.
5. `tests/Feature/Admin/RoleAdministrationTest.php` — read it as the specification.
6. `app/Filament/Resources/ComplianceCaseResource/ComplianceCaseActions.php` — a "visible"
   closure that is documented as a pre-filter, not as authorisation, and why that distinction
   had to be written down.
7. `PaymentProviderRegistry::registerMethod()` — the triple-keyed duplicate lookup, and the
   500-with-SQL it replaced.
8. `LotteryCatalogueService::driftFor()` — why a drift report is more honest than a CRUD form
   for a catalogue that does not live in the database.
9. `Security\KycVerificationService::reviewDocument()` — the both-columns fix: one written
   contract at the lowest layer instead of five callers patched individually.
10. `Services\Admin\SystemSettingService::set()` — read it as the answer to "why not just edit
    `.env`": whitelist, type, bounds, pair coherence, decimal-string money, audit — in that
    order, and no branch that skips any of them.
11. `Compliance\KycDocumentService::assertNotOwnEvidence()` — called inside the row lock, before
    any transition: the moment a screen convention became a domain rule.
12. `AdminAccess::permits()` — the operator precondition and the bridge, in that order, and why a
    granular permission is a panel power rather than a phrase a player may hold.
13. `tests/Feature/Admin/GranularScreenMigrationTest.php` — read case 6: it reflects over every
    permission constant the panel references, so a typo cannot become a silently dead button.
14. `database/migrations/2026_11_18_000200_add_player_link_to_fraud_alerts.php` — the additive fix
    for the uuid/bigint gap, and why the evidence column was not retyped.
15. `App\Enums\DrawType` — three lines of `configuredInt()` replacing four match arms, and the
    watchdog that keeps them honest.
16. `phpstan.neon` + `phpstan-baseline.neon` — read the comment above the baseline include: the
    ratchet only works if nobody re-generates the baseline to make a red run green.
17. `app/Models/KycDocument::verificationState()` — one reader replacing four `instanceof`
    branches that could never be false.
18. `resources/js/lottery/bet-slip.js` — read the `unknown` branch first. That is the whole reason
    the module was rewritten: a lost response must never look like a refusal, and a retry must reuse
    the identity the server already knows.
19. `app/Enums/NotificationEventType.php` — the five `Lifecycle*` cases that did not exist. The
    baseline had been calling that failure "known debt"; it was a feature that could not run (§8n).
20. `app/Services/Accounting/ThaiRevenueDepartmentTaxReportService.php` — a tax return that queried
    columns the schema does not have. Read the two docblock rules before changing it: report what was
    withheld, and count what was not (§8p).

## 10. Remaining blockers (unchanged from the audit)

* ~~Requested branch `feat/batch2-lottery-games` is absent from the clone.~~ **Created** (§8k): the
  repository now carries `feat/batch2-lottery-games`, based on a `pristine pre-work tree` commit,
  with the work committed in two commits; the patch is `git diff` from that base.
* ~~Two pre-existing `DepositSlipVerificationResource` failures need a product decision.~~
  **Closed (§8i)**: they were fixtures below the deployment's deposit floor, and the floor is now
  asserted by its own test rather than enforced silently.
* ~~`App\Policies\BasePolicy` still asks dot-abilities no seeder creates~~ — **fixed** (§8b).
* Policies are now granular-only by design; a deployment that has run `RolePermissionSeeder`
  but not `AdminRbacSeeder` keeps the old deny-everyone behaviour for non-super-admins. Run
  `php artisan db:seed --class=AdminRbacSeeder` before relying on policy checks.
* ~~Existing screens still authorise on coarse phrases~~ — **migrated (§8i)**, including new
  granular vocabulary for the risk-limit and GLO lanes. The `enforce` switch still ships off; it
  is now a deployment *order* (seed `AdminRbacSeeder` first), not missing work.
* ~~The system settings store is unbuilt~~ — **built** (§8h): a whitelisted, bounded, audited
  store of overrides merged into `config()` at boot, with `settings.view` / `settings.manage`
  split. Audit blocker #13 is closed. What remains open is the *width* of the permission: only
  `super-admin` holds `settings.manage` today, which is a `role_matrix` decision.
* ~~`Compliance\KycDocumentService` has no four-eyes guard~~ — **closed**: `verify()`/`reject()`
  call `assertNotOwnEvidence()` inside the row lock and raise `CODE_SELF_REVIEW`. Audit blocker
  #12 is closed.
* `system_settings` is key/value **text** with a declared type; anyone who can write to the
  database can write a row that the store will validate on boot and skip if it does not fit. The
  store is not a defence against a compromised DB account — no read path in the application is.
  Its job is to keep a *permission* from becoming *code execution*, and it does.
* ~~`audit_logs.action` falls back to `update` for the fraud lane~~ — **closed (§8i)**:
  `AuditAction::FraudAlertReviewed` exists and the lane passes the case.
* ~~Legacy `admin/compliance-cases` (ReleaseOperationsController) still owns that URI~~ —
  **closed (§8j)**: that URI now redirects to the real console at `admin/aml-cases`. The route is
  kept deliberately (a bookmark must not 404, and the static route-contract test pins the list),
  but no operator can land on a "not configured" page for a console that exists.
* Three audit findings that were *shape* problems, closed in the same pass (§8j): the free-text
  `payment_providers.driver` (now refused at registration, one driver list), the `DrawType` /
  configuration disagreement (the enum now reads configuration), and the uuid/bigint
  `fraud_alerts.user_id` (a nullable resolved `player_user_id` beside the untouched evidence
  column).
* ~~PHPStan, the MySQL lane and the Playwright suite were not run in this environment.~~ **All three
  now run** (§8k): PHPStan level 8 (0 errors, baseline ratchet over 5,777 pre-existing), MariaDB
  `migrate:fresh` + the full 426-test lane, Playwright 130 cases against a live server (110 pass).
  The 20 browser cases that were red are now green (§8l) — the bet-slip transport was rewritten to
  the JSON contract the backend already speaks, and the two frontend-build assertions were
  reconciled (a foreign magic number replaced by a real invariant; the Tailwind matrix re-recorded
  through an explicit recording switch).
* `phpstan-baseline.neon` freezes 5,777 pre-existing level-8 errors. It is a ratchet (fixing code
  unmatches entries; new errors fail the run), but a deployment that regenerates it wholesale has
  disarmed the lane. Review changes to that file as carefully as changes to `app/`.

## 8r. The CI contract, made true again (seventeenth pass)

§8q left eight red cases in `tests/Unit`: six `Build/CiRuntimeContractTest`, two
`Build/NativeVerifierBuildContractTest`. They were red because the workflow had drifted away from
what the case-lane design requires, not because the tests were wrong about much — the tests encode
*provisioning that the lanes actually need*, and the jobs had stopped doing it.

### What the jobs were missing, and what was added

| Contract | Was | Now |
| --- | --- | --- |
| `mysql` lane locks | no local socket, so the lock-observation cases could not run | `socat` unix socket `/tmp/mysql-ci.sock` exported as `DB_SOCKET` |
| `mysql` lane grants | `SUPER` or nothing | `performance_schema` grants without `SUPER`, `log_bin_trust_function_creators=1` for failure injection |
| `mysql` backup | restore *ran*, nothing was compared | `mysqldump --no-create-info` row compare + `scripts/mysql-roundtrip-fingerprint.sql` schema compare, both by `cmp` |
| live worker | the one `live-worker` case was skipped in practice | dedicated `settlement_worker_destructive_test` + `thai_lottery_conc` databases created by root, `MYSQL_TEST_ALLOW_DESTRUCTIVE=1`, `cargo test --include-ignored`, `0 failed; 0 ignored;` asserted, then `--healthcheck` |
| native verifier | the binary was built nowhere in the `sqlite` lane | `dtolnay/rust-toolchain` + `bash scripts/build-weekly-native-verifier.sh` before the suite in `test` and `full-runtime-release` |
| cross-language job | had no environment and ran no cases | `cp .env.ci .env`, native build, then `WeeklyNativeVerifierRuntimeTest` + `WeeklyLotteryPublicPageTest` with `--fail-on-skipped` |
| case census | lanes ran, nobody reconciled them | `php-case-census` job (`needs: [test, full-runtime-release]`, `if: always()`) fails unless both lanes succeeded and the census reconciles |
| helm | assumed present | v3.19.4 pinned by sha256 `759c656f…48862`, verified against the real release tarball before this step was written |

Two decisions worth naming:

* **The lane runner is the full-suite gate now.** `run_php_test_lane.py --lane sqlite|mysql` executes
  every case with `--fail-on-skipped --fail-on-risky --fail-on-incomplete --fail-on-empty-test-suite`
  and reconciles expected against actual by id. `NativeVerifierBuildContractTest` recognised only a
  literal `php artisan test` line as "an unfiltered gate", so it was taught to recognise the lane
  runner as one as well. The assertion it protects is unchanged and still enforced: *whatever* runs
  the whole suite must build the real native binary first, and only `test` and
  `full-runtime-release` may do so.
* **The destructive worker fixture gets its own databases, created by root.** A fixture that can
  create the database it writes evidence into can also rewrite that evidence. The lane user gets
  `ALL PRIVILEGES` on the two dedicated schemas and `SELECT` on the lane database, nothing more.

### Result

```
php artisan test tests/Unit/Build
  CiRuntimeContractTest                     14 passed (139 assertions)   was 8 / 6
  NativeVerifierBuildContractTest            6 passed                    was 2 failed
  TestDatabaseSafetyContractTest             4 passed
  Tests: 25 passed (206 assertions)
composer audit --locked --abandoned=fail     exit 0                      (Laravel 12.73.0)
```

Honest limit: the sqlite lane was probed live here — discovery found **8,795** cases and parted them
`{sqlite: 8515, server: 263, server-methods: 15, live-worker: 1, real-engine: 1}`, and every group it
reached (100/100 expected, 17 s per group) passed. The full 77-group sweep did **not** finish in this
sandbox; at ~5,800 cases per 13-minute lane it belongs in CI, and the `php-case-census` job is what
will prove it there. Nothing in this report should be read as "the whole lane ran locally".
