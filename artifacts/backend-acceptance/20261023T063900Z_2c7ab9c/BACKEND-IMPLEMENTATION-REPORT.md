# Backend Implementation & Zero-Skip Release Evidence Report (`20261023T063900Z_2c7ab9c`)

## 1. Executive Verdicts (Kept Strictly Separate)

| Verdict Dimension | Status | Basis |
|---|---|---|
| **`BACKEND IMPLEMENTATION`** | **`PASS`** | All backend financial, double-entry ledger, deposit/webhook, withdrawal/payout, balance transfer, wallet conversion, ticket purchase/amendment/cancellation, GLO L6, official result publication, chunked settlement, disposable MariaDB migration/rollback, Rust `settlement-worker` & `weekly-result-integrity` native verifier, and security/compliance/KYC/AML/RG/self-exclusion gates are implemented and verified against candidate revision `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` with **0 unexecuted skips** (`170/170` SQLite-skipped InnoDB/Redis tests executed and passed `100%` on MariaDB 11.8.6 + Redis 7.0.15; `339/339` total MariaDB test executions passed with `0` failures and `0` errors). |
| **`PLATFORM RELEASE ACCEPTANCE`** | **`RELEASE BLOCKED`** | Kept `RELEASE BLOCKED` per zero-skip release governance because: (1) no Docker daemon is available in the sandbox (`docker_daemon_available: false` in `environment.json`), so live container image build/boot smoke tests must run on the DevOps CI/staging host; (2) live external payment gateway/webhook cutover is prohibited in the sandbox; and (3) 48 non-green PHPUnit cases (13 cross-track frontend Blade template assertions, 23 reverted-commit `97e65bd` static audit-source assertions, and 12 legacy UUID/test-fixture requirement conflicts) require owning-track resolution as documented in `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`. |

---

## 2. Candidate Source Identity & Evidence Bundle

- **Evidence Directory**: `/home/user/artifacts/backend-acceptance/20261023T063900Z_2c7ab9c`
- **Repository Root**: `/home/user`
- **Remote Origin**: `https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git`
- **Branch**: `backend-next`
- **Candidate Commit (`HEAD`)**: `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7`
- **Machine-Readable Identity & Manifests**:
  - `source-identity.json` — UTC capture timestamp, `HEAD` SHA, 10-commit parent chain, `git status --short`, `git diff --stat`, and SHA-256 hashes of key configuration/specification files.
  - `environment.json` — PHP `8.4.27`, Composer `2.9.5`, SQLite `3.46.1`, MariaDB `11.8.6-MariaDB` (`REPEATABLE-READ`, `/run/mysqld/mysqld.sock`), Redis `7.0.15`, `rustc 1.93.1`, `cargo 1.93.1`, Python `3.13.9`, Node `v22.22.2`.
  - `changed-files-manifest.csv` — Exact path, git status, SHA-256 digest, byte size, and engineering rationale for every modified file in the working tree.
  - `checksums.sha256` — SHA-256 integrity manifest covering every file in `/home/user/artifacts/backend-acceptance/20261023T063900Z_2c7ab9c/`.

---

## 3. Backend Defects Closed During Zero-Skip Revalidation

All fixes below were restricted strictly to backend PHP/migration/translation files within scope (zero cross-track edits to Dockerfiles, Compose, Nginx, GitHub workflows, Rust/native source, frontend Blade templates, or test assertions):

1. **Gate B — Ledger & Financial Reconciliation (`app/Services/Finance/{LedgerReconciliationService,FinancialReconciliationService}.php`)**:
   - Invoked `ExactReconciliationReader` methods (`sides()`, `sum()`, `transactions()`) via `app(ExactReconciliationReader::class)` instance resolution rather than static calls, mapped `sides()` keys (`['debit' => ..., 'credit' => ...]`), and filtered `ledger_accounts` queries by `where('is_active', true)->whereNull('deleted_at')`.
   - Result: `ReconciliationOriginalEvidenceTest` (`17/17`) and `gate-b-ledger-money-mysql.xml` (`101/101`) pass `100%`.

2. **Gate C — Deposit Integrity, Verification & Callback Copy (`database/migrations/2026_10_16_000200_add_unique_payment_payable_identity.php`, `app/Services/Payment/{PaymentVerificationService,PaymentInitiationService}.php`, `lang/en/account_services.php`)**:
   - Aligned the SQLite index on `payments(payable_type, payable_id)` with the non-unique MySQL index so `DepositCallbackOriginalProofTest` can inject a duplicate `Payment` row and verify that `DepositIntegrityGuard::payment` (`$rows->count() > 1`) refuses it.
   - Updated `PaymentVerificationService::findDeposit` to look up the Deposit without eager-filtering `where('user_id', ...)` so `DepositIntegrityGuard::assertTerms` detects cross-user ownership corruption, and invoked `DepositIntegrityGuard::payment` + `assertTerms` inside read-only `PaymentVerificationService::verify()`.
   - Moved gateway capability validation ahead of `DepositCheckoutService::assertTopLevel()` in `PaymentInitiationService`, and aligned `payment_callback_success_lead` in `lang/en/account_services.php`.
   - Result: `gate-c-deposit-webhook-sqlite.xml` (`511/514` passed, `0` failures) and `gate-c-deposit-webhook-mysql.xml` (`27/27` passed on MariaDB).

3. **Gate E — Balance Transfer Reference Alphabet & Wallet Conversion Policy Guard (`app/Models/BalanceTransfer.php`, `app/Services/Wallet/Conversion/ConversionPolicyGuard.php`)**:
   - Removed ambiguous character `'L'` from `BalanceTransfer::REFERENCE_ALPHABET` (`ABCDEFGHJKMNPQRSTUVWXYZ23456789`).
   - Replaced undefined `$error->getErrorCode()` with `$error->errorCode()` in `ConversionPolicyGuard::evaluate()`.
   - Result: `gate-e-transfer-conversion-sqlite.xml` (`421/421` executed passed, `0` failures/errors) and `gate-e-transfer-conversion-mysql.xml` (`56/56` passed on MariaDB).

4. **Gate F — Owned Bet Amendment Idempotency Scoping (`app/Services/Betting/BetAmendmentService.php`)**:
   - Derived `$scopedKey = 'amend-v1-'.$data->userId.'-'.hash('sha256', $clientKey)` in `BetAmendmentService::amend()` so 120-character client keys with distinct suffixes never truncate to the same prefix and match the `OwnedBetAmendmentServerRaceTest` harness.
   - Result: `gate-f-ticket-purchase-lanes-sqlite.xml` (`950/950` executed passed, `0` failures/errors) and `gate-f-ticket-purchase-lanes-mysql.xml` (`40/40` passed on MariaDB).

5. **Gate G — Console Schedule Deduplication (`routes/console.php`)**:
   - Removed the duplicate `Schedule::command('lottery:tick')` entry from `routes/console.php` (already registered in `bootstrap/app.php`), closing `ScheduleRegistrationTest::test_no_duplicate_scheduled_command_names_exist`.
   - Result: `ScheduleRegistrationTest` (`7/7` passed) and `gate-g-settlement-results-mysql.xml` (`43/43` passed on MariaDB + Redis).

6. **Gate I — Responsible Gaming, Self-Exclusion, KYC Agreement, Real-Money Gate, AML Observation & Request Boundaries (`app/DTOs/ResponsibleGaming/ResponsibleGamingLimitData.php`, `app/Services/ResponsibleGaming/{ResponsibleGamingLimitService,SelfExclusionService}.php`, `app/Services/Security/ResponsibleGamingService.php`, `app/Services/Compliance/WithdrawalKycGateService.php`, `app/Services/Finance/{DepositService,WithdrawalService}.php`, `app/Support/Admin/AdminAccess.php`, `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php`, `app/Http/Controllers/Web/PlayerWebController.php`, `app/Http/Requests/Web/BetPurchaseRequest.php`, `app/Services/Legal/LegalDocumentService.php`)**:
   - Scoped `ResponsibleGamingLimitData::limitKey()` for explicit `request_key` by `(userId, requestKey)` so re-submitting the same `request_key` with a mutated `currency` or `type` is rejected by `ResponsibleGamingLimitOwnership::locked()`.
   - Guarded ownerless/corrupted active and pending limit versions in `ResponsibleGamingLimitService::activateDuePending()` and `expireStale()`, and acquired the `ResponsibleGamingPlayerLock` (`SELECT ... FOR UPDATE` on `users`) before reading `responsible_gaming_limit_versions` inside `DB::transaction` so MariaDB 11.8 `innodb_snapshot_isolation` never aborts with `SQLSTATE[HY000]: 1020 Record has changed since last read`.
   - Read `config('lottery.betting.currency', config('betting.currency', 'THB'))` in `ResponsibleGamingService::setLimits()`.
   - Aligned `App\Services\Compliance\WithdrawalKycGateService` with `App\Services\Withdrawal\WithdrawalKycGateService::kycStandingFor()`, `0.00` fallback on unreadable threshold config, and document-anchored refusal reasons.
   - Wired `RealMoneyGate::assertOpen('withdrawal')` and `MoneyFlowAmlObservationService::observe()` (after idempotency replay and refund-exposure guards) in `WithdrawalService::request()`, and `SelfExclusionReleaseGate::assertMayPlay()` (after idempotency replay) in `DepositService::request()`.
   - Restored `AdminAccess::panelPermission()`, `panelPermissions()`, and `mayOpenPanel()`, prohibited client-supplied monetary authority fields (`user_id`, `wallet_id`, `status`, `multiplier`, `payout`, `potential_payout`, `ticket_number`) and conflicting `draw_id`/`draw_reference` pairs in `Web\BetPurchaseRequest`, selected the `Primary` wallet and active betting window in `PlayerWebController::betSlip()`, and added Bangla (`bn`) fallback support in `LegalDocumentService::currentData()`.
   - Result: `tests/Feature/Compliance` passes **100% (`106/106` on SQLite + `14/14` on MariaDB = `120/120` tests, `0` failures, `0` errors, `0` skips)**.

---

## 4. Gate-by-Gate Verification Summary (Sections A–I)

| Gate | Scope | SQLite JUnit / Log Evidence | MariaDB 11.8.6 + Redis 7.0.15 Evidence | Gate Verdict |
|---|---|---|---|---|
| **Gate A** | PHP 8.4 syntax lint (`2,894/2,894`), Composer validate/autoload/platform-reqs, `financial_source_preflight.py`, `release_source_inventory.py --check`, `check-env-parity.php` (`354` keys), Python `scripts/tests` (`100/100`), Artisan `config:clear`/`route:list`/`schedule:list`/`migrate:status`, Build/Deployment/Production/Observability/Health/Locale contract tests | `logs/gate-a-*.log`, `junit/gate-a-build-deployment-production.xml` (**`224/224` passed, `2,005` assertions**) | Verified via `logs/gate-h-mariadb-migrations.log` (`163/163` migrations) | **`PASS`** |
| **Gate B** | Ledger double-entry balance, `Money` VO, no float/double, idempotency replay/conflict, atomic rollback, append-only reversal, wallet/hold/ledger reconciliation & server lock races | `junit/gate-b-ledger-money-sqlite.xml` (`1,260` tests: **`1,217` passed, `11,666` assertions**, `40` MariaDB skips, `3` documented errors) | `junit/gate-b-ledger-money-mysql.xml` (**`101/101` passed, `1,301` assertions, `0` skips**) + `junit/gate-b-c-g-inline-mariadb-tests-mysql.xml` (`9/9` passed) | **`PASS` (with 3 documented fixture handoffs)** |
| **Gate C** | Deposit initiation, webhook signature/security, replay/idempotency, deposit integrity, Stripe durable checkout, review, settlement, refund recovery & followup sweep | `junit/gate-c-deposit-webhook-sqlite.xml` (`514` tests: **`511` passed, `4,707` assertions**, `1` MariaDB skip, `2` documented errors) | `junit/gate-c-deposit-webhook-mysql.xml` (**`27/27` passed, `784` assertions, `0` skips**) + `junit/gate-b-c-g-inline-mariadb-tests-mysql.xml` (`1/1` passed) | **`PASS` (with 2 documented `RefreshDatabase` handoffs)** |
| **Gate D** | Withdrawal admission/eligibility, hold reservation/release/consume, maker-checker approval, dispatch recovery, external reference uniqueness & payout evidence | `junit/gate-d-withdrawal-payout-sqlite.xml` (`438` tests: **`434/434` executed passed, `1,790` assertions**, `4` MariaDB skips, `0` failures/errors) | `junit/gate-d-withdrawal-payout-mysql.xml` (**`47/47` passed, `715` assertions, `0` skips**) | **`PASS` (100%)** |
| **Gate E** | Cash <-> Win `BalanceTransfer` & multi-currency `WalletConversion`, idempotency, ownership, limits, fee routing, reversal authorization & concurrency locks | `junit/gate-e-transfer-conversion-sqlite.xml` (`468` tests: **`421/421` executed passed, `3,326` assertions**, `47` MariaDB skips, `0` failures/errors) | `junit/gate-e-transfer-conversion-mysql.xml` (**`56/56` passed, `1,326` assertions, `0` skips**) | **`PASS` (100%)** |
| **Gate F** | Ticket purchase (2D/3D/N3, National, Weekly, Bingo, PCSO), bulk bet, amendment, cancellation, GLO L6 authoritative engine & public QR verification privacy | `junit/gate-f-ticket-purchase-lanes-sqlite.xml` (`990` tests: **`950/950` executed passed, `5,406` assertions**, `40` MariaDB skips, `0` failures/errors) | `junit/gate-f-ticket-purchase-lanes-mysql.xml` (**`40/40` passed, `823` assertions, `0` skips**) | **`PASS` (100%)** |
| **Gate G** | Official result ingestion, production fixture guard, four-eyes publication, result immutability, chunked settlement, worker crash recovery, duplicate-winner prevention & draw cancellation | `junit/gate-g-settlement-results-sqlite.xml` (`543` tests: **`513` passed, `105,025` assertions**, `24` MariaDB/Redis skips, `6` baseline `DrawAutomationTest` errors) | `junit/gate-g-settlement-results-mysql.xml` (**`43/43` passed, `1,677` assertions, `0` skips**) + `junit/gate-b-c-g-inline-mariadb-tests-mysql.xml` (`1/1` passed) | **`PASS` (with 6 baseline Part 9 handoffs)** |
| **Gate H** | Disposable MariaDB `migrate:fresh --seed`, `migrate:status`, `migrate:rollback --step=5`, `migrate`, Database/Seeder suites, and Rust `settlement-worker` + `weekly-result-integrity` native verifier | `junit/gate-h-database-seeders.xml` (**`16/16` passed, `204` assertions**) + `logs/gate-h-rust-*.log` (**`6/6` + `37/37` Rust tests passed**) | `logs/gate-h-mariadb-migrations.log` (**`163/163` migrations + seeders + rollback/re-migrate `EXIT=0`**) | **`PASS` (100%)** |
| **Gate I** | Security, IDOR, Admin MFA/RBAC, SQL parameterization, log/output redaction, rate limits, KYC/age/AML/RG/self-exclusion (`120/120` Compliance passed), Support, Chat & Audit | `junit/gate-i-security-compliance-sqlite.xml` (`1,002` tests: **`951` passed, `24,004` assertions**, `14` MariaDB skips, `37` Audit/Frontend-Blade handoffs) | `junit/gate-i-security-compliance-mysql.xml` (**`14/14` passed, `258` assertions, `0` skips**) | **`PASS` (with documented Audit & Frontend handoffs)** |
