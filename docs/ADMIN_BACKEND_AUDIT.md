# Admin Backend Audit — Thai Lottery Platform

**Date:** 2026-11-17
**Scope:** the operator panel (`/admin`, Filament 3.3.55), its authorisation model, its
financial surfaces, the admin HTTP console under `/admin/*`, and the tests that guard them.
**Baseline commit:** the tree cloned from `Ainul-550Islam/thai-lottery-ainul-islam` (branch
checked out locally as the working tree; the requested `feat/batch2-lottery-games` branch is
not present in the clone — see *Blockers*).

This audit was produced by reading the source, then running the suite. Nothing below is a
guess about intent: every classification points at a file, and the two "BROKEN" rows are
reproduced by a failing or absent test rather than by inspection alone.

---

## 0. How this audit was produced (so it can be re-run)

```bash
composer install --prefer-dist          # PHP 8.4.27, Composer 2.11.2
cp .env.example .env && php artisan key:generate
touch database/database.sqlite

php artisan test tests/Feature/Filament tests/Feature/Admin tests/Feature/Hardening
#   baseline: 236 passed, 2 failed (1819 assertions)
php artisan test tests/Feature/Admin       # after this work: 80 tests, all green
php artisan test tests/Feature/Seeders     # 7 passed
php artisan route:list --path=admin         # 100+ console routes, resources discover cleanly
```

The two baseline failures were **pre-existing and unrelated to the admin panel**:
`tests/Feature/Filament/DepositSlipVerificationResource…` built deposits of 82.50 THB and
64.00 THB against a configured minimum of 100.00 THB, so `DepositService` refused the fixture.
They failed before any change in this work and kept failing in exactly the same two tests while
this audit was produced.

**They are now fixed (report §8i).** The diagnosis held up under inspection and the "product
decision" framing did not: the limit is right and the test was wrong, because the fixture requested
amounts below whatever floor the deployment happened to configure. The amount is now derived from
`config('finance.deposit.min')`, and a new test asserts that the floor itself is enforced — the
rule had no coverage at all before.

---

## 1. The canonical admin architecture (as found)

| Concern | Implementation | Verdict |
|---|---|---|
| Panel | `app/Providers/Filament/AdminPanelProvider.php` — id `admin`, path `/admin`, resources/pages/widgets discovered | PASS |
| Panel entry rule | `App\Support\Admin\AdminAccess::canAccessPanel()` — operator role **and** `status->canTransact()` **and** `view dashboard` | PASS |
| Session binding | `FilamentAuthenticateBoundSession` middleware | PASS |
| Authorisation vocabulary | coarse phrases in `config/permission.php`, seeded by `RolePermissionSeeder` | PARTIAL (see §2) |
| Per-screen auth | every resource declares `canViewAny()` from `AdminAccess` | PASS (enforced by `FilamentAuthorizationTest`) |
| Domain writes | screens delegate to services (`DepositApprovalService`, `WithdrawalApprovalService`, `KycVerificationService`, `PaymentProviderRegistry`, …) | PASS |
| Audit trail | `AuditLogService` + append-only `audit_logs`; read-only `AuditLogResource` | PASS (one attribution gap, fixed — §6) |
| Admin HTTP console | `app/Http/Controllers/Admin/*` + `routes/web.php` (`/admin/{panel}`), gated by `can:access-admin` + per-panel permission via `AdminConsoleNav` | PASS |

The panel is **not** a decorative UI: 19 resources, 11 pages and 5 widgets, all of them
querying real tables and calling real services. The audit below is therefore about *gaps at
the edges*, not about a missing admin backend.

---

## 2. Gap matrix

Legend: **PASS** (present and verified) · **PARTIAL** (present, incomplete) ·
**MISSING** (no implementation) · **BROKEN** (present, does not work) · **UNVERIFIED**.

| Module | Existing | Missing | Broken | Security risk | Required fix | Result |
|---|---|---|---|---|---|---|
| Panel authentication & session binding | `AdminAccess::canAccessPanel`, `FilamentAuthenticateBoundSession`, `AdminPanelAccessTest` (14 cases) | — | — | — | none | **PASS** |
| Coarse RBAC (5 roles / 40 phrases) | `config/permission.php`, `RolePermissionSeeder` | — | role grants only writable by a developer with tinker | least privilege impossible per screen | granular layer | **PARTIAL → fixed (§4)** |
| Granular permissions (`users.suspend`, `deposits.approve`, …) | nothing | entire vocabulary | — | a "KYC officer" had to hold `manage users` (and with it suspend powers) | catalogue + seeder + bridge | **MISSING → implemented** |
| Role management UI | nothing | create/edit/delete roles | — | role composition was a shell command | Filament resource + service | **MISSING → implemented** |
| Permission catalogue UI | nothing | read-only catalogue | — | operators could not see what a permission means | Filament resource | **MISSING → implemented** |
| Dashboard — platform stats | `PlatformStatsWidget` (players, draws, stake today, pending deposits/withdrawals) | compliance queues, queue health, provider readiness | — | — | 2 widgets | **PARTIAL → extended** |
| Dashboard — compliance queues | nothing on the dashboard (KYC page only) | KYC pending, fraud alerts, AML high-risk, self-exclusions | — | queues grow unwatched | `ComplianceQueueWidget`, per-stat gating | **MISSING → implemented** |
| Dashboard — system health | nothing | failed jobs, queue backlog, provider readiness | — | silent failures | `SystemHealthWidget` | **MISSING → implemented** |
| User management | `UserResource` list/view/edit, relation managers (bets, wallets), `UserAccountActions` | admin notes field, full activity timeline | — | — | none in this pass | **PARTIAL** |
| Deposit administration | `DepositResource` + `DepositDecisionActions` (via `DepositApprovalService`/`DepositCompletionService`), queue tabs | provider re-query, reversal | — | — | none in this pass | **PASS** |
| Withdrawal administration | `WithdrawalResource` + `WithdrawalDecisionActions`, KYC/risk columns, payout evidence | external reconciliation UI | — | — | none in this pass | **PASS** |
| Wallet & ledger inspection | `WalletResource` (+ `WalletControlActions`), `LedgerAccountResource`, `FinancialTransactionResource` | ledger drill-down by account | — | — | none in this pass | **PASS** |
| Payment provider administration | registry service only (`PaymentProviderRegistry` with lifecycle + audit) | **entire UI** | — | nobody but a developer could take a gateway out of traffic | resource + lifecycle actions + masked config | **MISSING → implemented** |
| Payment method offers (min/max/fee) | model + registry `registerMethod`/`transitionMethod`/`resolveOffer` | no panel surface | registry's duplicate check keyed only on the derived `method_key`, so a clashing row surfaced as a raw `UniqueConstraintViolationException` (500 + INSERT in the log) instead of the domain's "duplicate" | the offerable menu was frozen at whatever the seeder wrote: `resolveOffer()` serves only `enabled` rows and nobody could enable one | `PaymentMethodConfigResource` (+3 pages, lifecycle verbs) and a triple-keyed duplicate lookup in the registry | **MISSING → implemented (§8e)** |
| KYC administration | `KycReviewPage` (approve/reject/resubmit via `Security\KycVerificationService`), `Compliance\KycDocumentService` (authoritative per-document lane), `KycDocumentVerificationStatus` | **entire document inventory** (no expiry view, no fingerprints, no refusal reasons); the authoritative lane had no screen at all | **the two lanes disagreed**: `reviewDocument()` wrote only the legacy `status` column while `pronounce()` reads `verification_status`, so an approval taken from the panel never counted as evidence; `KycDocument` also never cast that column to its own enum | identity documents with no expiry tooling and an approval path that silently did nothing | `KycDocumentResource` (+2 pages, three decision verbs via the document lane) and a both-columns fix in the legacy lane | **PARTIAL → implemented (§8g)** |
| AML administration | `AmlRiskAssessment`, `ComplianceCase`, `ComplianceCaseStatus`, `AmlRiskAssessmentService`/`AmlRiskService`, `ComplianceCaseService` | **entire UI** | — | an AML file could be opened and never moved: escalation, resolution and closure were tinker-only | `ComplianceCaseResource` (+2 pages, lifecycle verbs) and `AmlRiskAssessmentResource` (read-only store) | **MISSING → implemented (§8d)** |
| Fraud administration | `FraudAlert`/`FraudCase`, `FraudAlertPolicy`, `Security\FraudAlertReviewService` (lock, terminal-status guard, maker-checker, audit, `FraudCase`) | **entire UI** (only a dashboard count existed) | — | alerts accumulated with no adjudication path, so a hold could only be released by a developer | `FraudAlertResource` (+2 pages) delegating every verb to the canonical service | **MISSING → implemented (§8d)** |
| Lottery / draw administration | `DrawResource` (+ lifecycle actions, relation managers), `OperatorResultEntryPage`, GLO pages (freeze, claims, dealers, sales points, result dashboard), `NumberLimitResource`, `TicketResource`, `BetResource`, `config/lottery.php` (`types`, `markets`) | **no games table exists**, so "game/category CRUD" is not implementable as a screen; nothing showed the catalogue, its payouts or the config-vs-enum drift | `DrawType::digits()/maxNumber()` disagree with configuration for Tod and Run and no screen surfaced it | a read-only catalogue (or none at all): nobody could see what is sold, at what multiplier, or why a bet is refused | `LotteryCataloguePage` + `LotteryCatalogueService` (games, markets, drift, live facts, product flags) — **read-only by design**, DB-backed catalogue recorded as a blocker | **PARTIAL → catalogue built (§8f)** |
| Financial reporting & export | full lane: `OperationalReportService` (ask/run/rows), `ReportExportService` (sealed artifact + checksum), API in `Api/V1/Admin/OperationsController`, `FinancialReconciliationPage` | **no panel UI** — operators needed an API token + curl | — | — | panel page reusing the lane | **MISSING → implemented** |
| System settings management | read-only projection at `/admin/configuration` (explicitly refuses to show secrets or claim unverified state) | **no write surface** (correctly refused `.env` editing, but that left operators unable to tune anything without a deployment) | — | a settings editor that writes `.env` is a privilege-escalation surface, and a free-form key/value table is the same gift with a nicer UI | `system_settings` table + `SystemSettingService` (whitelist, bounds, pair coherence, decimal-string money, audit old→new) + `/admin/system-settings`, merging overrides into `config()` at boot | **MISSING (deliberate) → implemented (§8h)** |
| Audit logging | `AuditLogService` (redaction list, 30+ keys), `AuditLogResource` (append-only, no edit/export), role/permission/ledger observers | operator attribution on provider transitions | — | a transition with `user_id = null` | attribute the acting operator | **PARTIAL → fixed** |
| Policies (`app/Policies`) | 27 policies, `BasePolicy` with dot-ability vocabulary | those abilities are **never seeded** | policies denied everyone except super-admin (fail-closed) | not an escalation, but API authorisation was effectively super-admin-only | map every policy ability to a granular permission | **BROKEN → fixed (§7)** |
| Admin tests | `AdminPanelAccessTest`, `FilamentAuthorizationTest`, `FinanceResourcesTest`, `AdminConsoleChromeTest`, `AdminUserRouteIsolationTest`, `AdminDrawResultWorkflowTest`, `AdminSecurityApiTest` | granular RBAC, role admin, provider admin, reports, widgets | — | — | +69 tests | **PARTIAL → extended** |

---

## 3. The two structural findings

### 3.1 One vocabulary could not express least privilege (**BROKEN → bridged**)

`config/permission.php` holds one phrase per *screen* (`manage users`, `manage payouts`).
There is no way to say "may review KYC but may not suspend an account" — the phrase that
opens the KYC screen also carries suspension. `AdminAccess` says so itself in a docblock and
records the consequence: the only way to add a KYC officer was to hand them `admin`.

This work adds the second vocabulary (`config/admin.php`, `App\Support\Admin\AdminPermission`)
and a **switched bridge** in `AdminAccess::permits()`:

* `admin.granular_rbac.enforce = false`, `fallback_to_legacy = true` (default): a granular
  check passes if the operator holds the granular grant **or** the coarse phrase it replaces.
  Behaviour of the five existing roles is unchanged — proven by
  `GranularRbacTest::test_the_legacy_fallback_still_authorises_the_seeded_admin`.
* `enforce = true`: only granular grants count. Proven by
  `test_the_fallback_can_be_switched_off_without_touching_code`.

The fallback is a bridge, not a destination: existing screens still ask the coarse question,
and migrating them is listed in *Remaining work*.

### 3.2 Policies ask abilities nobody grants (**BROKEN → FIXED**)

`App\Policies\BasePolicy::can()` asks for `wallet.view`-style abilities. No seeder created
`*.view`/`*.update` rows; `RolePermissionSeeder` creates phrases with spaces. Therefore:

* super-admin passed (Gate bypass),
* **everybody else failed every policy**.

It failed *closed* (no privilege escalation), but the API's policy layer authorised only
super-admins — which is not authorisation, it is an outage wearing authorisation's clothes.

**Fixed** by mapping each ability to a granular permission in
`config/admin.php → policy_abilities` and asking `AdminAccess::permitsStrict()`. Two things
had to be true for that to be safe, and both are now pinned by tests:

* **No bridge to the coarse catalogue for record-level checks.** The coarse phrases include
  ones players hold (`manage wallet`, `view transaction history`). Bridging `wallet.viewAny`
  to `manage wallet` would have handed every player a blanket listing right over the wallet
  table — an escalation, not a fix. Hence `permitsStrict()`: policy checks are granular-only.
* **Completeness.** `PolicyAbilityMapTest` walks every prefixed policy method and fails if it
  has no entry in the map (and if the map has entries nothing asks about), so the divergence
  cannot silently return.

Writes that belong to a domain service (`ledger.create`, `draw.delete`, `wallet.delete`,
`privacy-request.forceDelete`, …) are mapped to the `never` sentinel: 18 abilities stay
refused at the policy level, super-admin included.

---

## 4. What was implemented in this pass (summary)

| Surface | Files |
|---|---|
| Granular catalogue + bridge | `config/admin.php`, `app/Support/Admin/AdminPermission.php`, `app/Support/Admin/AdminAccess.php` (edited), `database/seeders/AdminRbacSeeder.php`, `database/seeders/DatabaseSeeder.php` (edited) |
| Role administration | `app/Services/Admin/RoleAdministrationService.php`, `app/Exceptions/RoleAdministrationException.php`, `app/Filament/Resources/RoleResource.php` (+3 pages) |
| Permission catalogue | `app/Filament/Resources/PermissionResource.php` (+1 page) |
| Payment providers | `app/Filament/Resources/PaymentProviderResource.php` (+2 pages), `app/Models/PaymentProvider.php` (relation added), `app/Services/Payment/PaymentProviderRegistry.php` (operator attribution) |
| Reports | `app/Filament/Pages/FinancialReportsPage.php` + `resources/views/filament/pages/financial-reports-page.blade.php` |
| Dashboard | `app/Filament/Widgets/ComplianceQueueWidget.php`, `app/Filament/Widgets/SystemHealthWidget.php`, `app/Filament/Pages/OperationsDashboard.php` (edited) |
| Tests | `tests/Feature/Admin/{GranularRbacTest,RoleAdministrationTest,PaymentProviderAdminTest,FinancialReportsPageTest,DashboardWidgetsTest}.php` |

Detail, including the refusals each screen performs, is in
`docs/ADMIN_IMPLEMENTATION_REPORT.md`.

---

## 5. Security posture after this pass

| Attack | Result |
|---|---|
| Unauthenticated `/admin` | 302 → `/admin/login` (existing test) |
| Player / agent / role-less user | 403 (existing tests) |
| Suspended operator, or `PendingVerification` operator | 403 (existing tests) |
| Operator without a screen's permission | resource `canViewAny()` false → 403; service re-checks |
| Self-escalation through the role form | **blocked**: `AdminAccess::mayGrant()` filters the options, `RoleAdministrationService::assertGrantable()` refuses a forged payload |
| Self-lockout (stripping your own `roles.manage`) | **blocked**: `assertNoSelfLockout()` |
| Deleting a protected or in-use role | **blocked**: `mayDelete()` + `roleInUse()` |
| Editing `super-admin`/`admin`/`auditor` as a non-super-admin | **blocked**: `protectedRole()` |
| Illegal provider transition (revive a disabled gateway) | **blocked**: the enum's transition map, exercised in the test |
| Credentials rendered in the provider view | **blocked**: key-name masking, proven with a hand-written `api_key` row |
| Export without `reports.export` | **blocked**: run completes, artifact is never produced, no `data_export` audit line |
| Report job owned by somebody else | **blocked**: `recentJobs()` is scoped to the requester; the export lane also re-checks ownership |
| Audit line for a provider transition with no actor | **fixed**: `auth()->id()` attribution |

---

## 6. Blockers and remaining work (honest list)

1. ~~**Branch.** The clone has no `feat/batch2-lottery-games` branch; work was applied to the
   default `main`/`master` tree.~~ **CLOSED (§8k).** The branch exists, based on a
   `pristine pre-work tree` commit; the delivered patch is the diff from that commit, verified to
   reproduce the working tree byte for byte.
2. **Two pre-existing test failures** (`DepositSlipVerificationResource…`) — product decision
   needed (raise the fixture amounts, or lower the configured minimum for tests). A third
   pre-existing failure class (`BotDetectionMiddlewareTest`, stale since the middleware
   changed signature and stopped blocking) was repaired — see the implementation report §11.
3. ~~`BasePolicy` dot-vocabulary divergence~~ — **fixed** in this pass (§3.2,
   `PolicyAbilityMapTest`, 14 tests).
4. ~~**Existing screens still ask the coarse question.**~~ **Migrated (§8i of the report).** Every
   panel screen, widget and relation manager now asks a granular permission, including the
   risk-limit and GLO lanes which required new vocabulary with per-duty fallbacks. `enforce`
   remains off by default and is now a deployment order (seed `AdminRbacSeeder`, then set
   `ADMIN_GRANULAR_RBAC_ENFORCE=true`), with the panel proven under enforcement by
   `GranularScreenMigrationTest`.
5. **No UI yet for**: nothing on the settings side any more — the store is built (§8h of the
   report): whitelisted keys only, bounds declared beside the label, audit old→new, secrets
   impossible by policy. The KYC document
   inventory is built (§8g). The lottery catalogue is
   now *visible* (§8f) but remains configuration-as-code — a DB-backed games table would move
   payout multipliers into a writable table, which is a schema/money decision. AML cases, the risk-assessment store, the fraud queue (§8d) and method offers
   (§8e) **are now built** — read §8d's note about the legacy `admin/compliance-cases` route.
6. ~~**Not run in this pass**: PHPStan/Larastan, the 130 Playwright browser cases, the MySQL
   lane.~~ **All three were run (§8k).** PHPStan first refused to start at all (three parameters
   removed in PHPStan 2.x made the config invalid); once fixed it reported **5,777** pre-existing
   level-8 errors, so `larastan ^3.0` was added, the 62 files this work added were brought to zero
   errors, and the rest is a **baseline ratchet**. MariaDB 11.8.6 took every migration
   (`migrate:fresh`) and the whole 426-test lane — and its first run caught `GLOB` (SQLite-only) in
   this work's own fraud-alert migration, now a portable regex. Playwright ran 130 cases against a
   live server: 110 passed, 20 failed, all inside `bet-slip-transport` and `frontend-build`, whose
   specs describe a different frontend build than this repository carries. **Superseded by §8l of
   the report:** the bet-slip transport was the real gap (the backend already returned a per-item
   report; the client threw it away and could not tell a refusal from a lost response), and it has
   been rewritten — the browser suite is now 130/130.
7. ~~**`payment_providers.driver` is free text.**~~ **CLOSED.** `PaymentProviderRegistry::register()`
   refuses a driver no class implements (`PAYMENT_PROVIDER_UNSUPPORTED_DRIVER`), naming the drivers
   that do exist; the driver list is one constant on `PaymentGatewayManager` (three copies had
   already drifted), and pre-existing rows are still reported honestly rather than rewritten.
8. ~~**`fraud_alerts.user_id` is a `uuid` column while `users.id` is `bigint`.**~~ **CLOSED,
   additively.** A nullable `player_user_id` (indexed, `nullOnDelete` where the driver allows an FK)
   sits beside the untouched evidence column; it is backfilled only where the subject is a numeric
   string that resolves to a real account, filled on write by `FraudEventRecorder`, and the queue
   links the account only when it exists. Retyping `user_id` was rejected because it would rewrite
   evidence the detection lane stamped.
9. ~~**`audit_logs.action` falls back to `update` for the fraud lane.**~~ **CLOSED.** The case
   `AuditAction::FraudAlertReviewed = 'fraud_alert_reviewed'` exists (category `security`,
   severity `high`) and `FraudAlertReviewService` passes it. Proved by
   `ComplianceAndFraudConsoleTest::test_the_fraud_decision_is_recorded_under_its_own_audit_action`,
   which asserts the stored value rather than the intention.
10. **`payments.providers.manage` is held by nobody but super-admin.** The provider and
    method-offer screens therefore gate their write verbs on a permission that only the
    Gate-bypassed role actually carries. That is deliberate (a narrow lane) but it means a
    non-super-admin finance manager can read the menu and never change it; widening it is a
    `config/admin.php` `role_matrix` decision, not a code change.
11. ~~**`config/lottery.php` and `DrawType` disagree for Tod and Run.**~~ **CLOSED.** `DrawType`'s
    helpers now READ the configuration rather than carrying a second copy of the numbers (the
    historical literals survive only as a no-container fallback), so there is one source. The
    catalogue's drift report is kept as a watchdog and reports nothing today; its test proves the
    delegation by moving a configured value.
12. ~~`Compliance\KycDocumentService` does not enforce four-eyes.~~ **CLOSED.** `verify()` and
    `reject()` now call `assertNotOwnEvidence()` inside the row lock, before any transition, and
    raise `KycVerificationException::selfReview()` (`CODE_SELF_REVIEW`). The screen's `visible`
    closures still refuse first so a doomed button is never drawn, but the rule no longer depends
    on the screen being the only door — which is what "the lane would allow it" meant.
13. ~~A writable system settings store remains deliberately unbuilt.~~ **CLOSED, by building the
    thing that was actually safe.** The refusal was of `.env` editors and free-form key/value
    tables; the delivered store is a whitelist declared in `config/admin.php → settings_catalogue`
    (11 keys, each with type, bounds, group and pair), validated in
    `SystemSettingService`, audited old→new at High risk, and merged into `config()` at boot. No
    secret can enter it, an unknown key is refused by name, and an incoherent money pair cannot be
    stored. See report §8h. The residual, honest note: only `super-admin` holds
    `settings.manage`, the same deliberate narrowness as blocker #10 — widening it is a
    `role_matrix` decision.
14. ~~**Legacy `admin/compliance-cases` route stays.**~~ **CLOSED.** That URI now redirects to the
    real AML console (`admin/aml-cases`); the route itself is kept so existing bookmarks do not
    404 and the static route-contract test still passes. `ReleaseOperationsController` continues to
    serve every other surface.
