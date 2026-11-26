# Payment Infrastructure Implementation Report

**Date:** 2026-11-17  
**Source commit:** `98ef46e`  
**Working branch:** `main` (the requested `feat/batch2-lottery-games` branch is not present on `origin`)

## 1. Existing functionality discovered

The checkout already implements a substantial production-oriented internal financial platform:

- gateway registry/readiness and market/currency capability checks;
- Stripe, bKash, Nagad, PromptPay, bank-transfer and Thai-bank adapter architecture;
- durable deposit checkout, callback verification, replay guards and settlement;
- withdrawal holds/reservations, approval, disbursement claims, unknown-outcome safety and reconciliation;
- double-entry ledger posting, wallet locks, reversals, idempotency and reference ownership;
- payout maker/checker flows, batches, private evidence and independent reconciliation;
- KYC, AML, fraud, age, legal-consent and responsible-gaming controls;
- admin resources and restricted payment operations;
- extensive payment, finance, compliance and security regression suites.

## 2. Defects and security gaps fixed

### Missing webhook signatures returned the wrong security classification

`PaymentWebhookController` returned HTTP 422 for a bKash request with no signature because the closed partial integration produced a generic processing failure. Missing authentication evidence is now rejected as HTTP 403 before payload processing for Stripe, bKash and Nagad. This does not open either partial provider lane.

### Unsupported crypto deposits could never reach a safe terminal state

The crypto guard correctly refused every success event, but also refused non-success expiry/failure events. Existing pending legacy crypto obligations could remain pending forever. The service now permits only terminal, non-success **deposit** events to close the existing obligation. Crypto success and reversal events remain refused and cannot touch wallet money.

### Unsupported crypto destinations were insufficiently validated

Crypto remains unavailable. The web request now also validates supplied destination syntax and prohibits bank fields before returning the authoritative method-unavailable error. This avoids accepting malformed destination material merely because the rail is disabled.

### Duplicate manual-disbursement implementation

Two concrete `ManualDisbursementQueueService` classes existed under `Payment` and `Withdrawal`. The canonical implementation is `App\Services\Payment\ManualDisbursementQueueService`; the old namespace is already preserved by a container-time class alias. The duplicate implementation was deleted, eliminating drift and a second settlement path.

### Duplicate ClamAV implementation

Two large ClamAV implementations existed. The canonical upload scanner implements both required contracts and is container-bound. The older `App\Services\Antimalware\ClamAvScanner` was deleted. Unified socket-protocol tests pass for clean, infected, unknown and incomplete-stream outcomes.

### Environment templates were materially incomplete

The repository's own generator found 599 missing application/config environment keys per template. All three templates were completed, credential-shaped values were kept blank, lane switches remain off, the PromptPay legacy recipient key is explicitly empty, and parity now reports 919 keys in every template.

### Arbitrary administrative balance and deposit-credit actions

The Filament wallet action originally called generic wallet credit/debit and was then routed through `LedgerAdjustmentService`; fresh review found that an operator-entered amount plus evidence token was still an arbitrary one-operator balance-editing surface. The action has therefore been removed completely. Wallet administration now exposes only lock/unlock, while corrections require a separately reviewed reconciliation/reversal workflow.

The analogous deposit `complete` action accepted an operator-entered provider reference and could credit a wallet without independently authenticated settlement evidence. That generic action has also been removed. Human approval remains non-monetary; only provider-authenticated settlement/reconciliation lanes may complete deposits. Regression tests assert both unsafe actions are absent and create no financial rows.

### Wallet-transfer routes lacked throttling

The authenticated transfer quote and execution routes retained account, adulthood, legal-consent, and service-level idempotency controls but had no route limiter. Both now use the registered `financial-critical` limiter. Route-registry regression coverage checks real financial mutation routes for authentication/signature and throttling while excluding explicit non-mutating compatibility stubs.

### Unknown fraud modes silently became observe mode

The fraud middleware previously enforced only when the configuration string was exactly `enforce`; a typo disabled interception. Only explicit `observe` and `log_only` values are now non-enforcing. Unknown and historical unsupported values fail closed.

### The production-simulation seeder was unsafe and stale

`ProductionSimulationSeeder` contained a known administrator password, stale schema columns, direct invented wallet balance, and no environment guard. It now refuses production unconditionally, requires explicit non-production opt-in, uses current schema, generates a random password and `.test` identity, and creates only a zero-balance wallet without transaction or ledger fabrication.

### Resource policies bypassed active-account and ledger-schema boundaries

Payment, wallet, and payout ownership shortcuts admitted inactive owners at policy level. Ledger ownership incorrectly looked for `user_id` on `LedgerEntry`, although ownership is represented by its wallet, and its CRUD abilities contradicted append-only history. Owner access now requires an active principal, ledger reads follow the wallet owner, and ledger create/update/delete is denied even through the super-admin Gate shortcut. `PayoutPolicy` is explicitly registered.

### Thai-bank status polls could drift to the generic queue

`PollThaiBankPayoutStatusJob` did not declare a queue even though it is reconciliation work. It now targets the declared `reconciliation` worker and dispatches after commit, covering both delayed gateway polls and the recovery sweep.

### Fraud middleware accepted client-selected identity when authentication was absent

`FraudRiskGateMiddleware` fell back to request-body `user_id` when no authenticated principal existed. That could evaluate or attribute fraud evidence to another account. The fallback was removed: financial risk identity now comes only from the authenticated user. Regression coverage proves a spoofed body identity never reaches the risk engine.

### Fraud alert replay could replace original case identity and evidence

`FraudEventRecorder` used `updateOrCreate` while supplying a fresh UUID in the update payload. Replaying the same fraud event could therefore replace the alert primary key and overwrite its original amount/evidence. Fraud keys also used randomness when an event ID was available. Fraud event identity is now a bounded SHA-256 key derived from event type, user, and authoritative reference/event identity; the recorder uses the unique key with `firstOrCreate`; and review/suspicion events dispatch only for a newly created alert. Regression coverage proves changed replay input preserves the original row, UUID, amount, and one-time review event.

### Security middleware regression tests targeted a removed enforcement contract

The feature test directly instantiated `BotDetectionMiddleware` with obsolete service arguments and expected the old middleware to block bots. The current production middleware is intentionally an opt-in observation adapter: log mode records immutable security telemetry without changing authentication outcomes, while unsupported enforcement configuration fails closed with HTTP 503. The feature test now resolves the real container wiring and verifies durable telemetry, explicit partial-observation state, fail-closed enforcement configuration, no downstream invocation, and no fabricated blocked event. Production middleware behavior was preserved.

### A destination-validation fixture incorrectly treated syntax as provider authority

The bKash destination test expected a withdrawal to succeed on a THB/Thailand site even though bKash is authoritatively restricted to BDT/Bangladesh and its payout capability is unverified. The test now proves the intended boundary: a valid Bangladeshi mobile number has no destination-field error, but the method remains unavailable, no withdrawal or hold is created, and an incompatible bank field is still rejected. Production eligibility logic was not weakened.

### The ledger hash-chain tip lock was not a stable InnoDB mutex

The initial multi-class MariaDB race run exposed a real timing defect: two distinct ledger writers could both select the same anti-join chain tip, and one then failed the unique `previous_hash` constraint instead of posting. An isolated rerun happened to pass, but the complete race batch reproduced the defect twice, so it was not dismissed as test noise. A migration-owned singleton `ledger_chain_state` row now serializes every append, including the empty-ledger case, and publishes the new tip in the same database transaction. The server race test now witnesses both child connections blocked on that exact InnoDB row. The complete 30-class server batch passes after the fix.

## 3. Modified files

- `.env.example`
- `.env.ci`
- `.env.production.example`
- `app/Filament/Resources/DepositResource.php`
- `app/Filament/Resources/DepositResource/DepositDecisionActions.php`
- `app/Filament/Resources/WalletResource.php`
- `app/Filament/Resources/WalletResource/WalletControlActions.php`
- `app/Http/Controllers/Api/V1/PaymentWebhookController.php`
- `app/Http/Middleware/FraudRiskGateMiddleware.php`
- `app/Http/Requests/Web/WithdrawalRequest.php`
- `app/Jobs/PollThaiBankPayoutStatusJob.php`
- `app/Policies/LedgerPolicy.php`
- `app/Policies/PaymentPolicy.php`
- `app/Policies/PayoutPolicy.php`
- `app/Policies/WalletPolicy.php`
- `app/Providers/AuthServiceProvider.php`
- `config/fraud.php`
- `routes/web.php`
- `database/seeders/ProductionSimulationSeeder.php`
- `app/Services/Fraud/FraudDetectionService.php`
- `app/Services/Fraud/FraudEventRecorder.php`
- `app/Services/Ledger/DoubleEntryLedgerService.php`
- `app/Services/Payment/PaymentWebhookService.php`
- `tests/Feature/Ledger/DoubleEntryLedgerServerRaceTest.php`
- `tests/Feature/Operations/PromptOperationsAndPayoutCoverageTest.php`
- `tests/Feature/Payment/WithdrawalDestinationValidationTest.php`
- `tests/Feature/Security/PromptHttpPaymentBoundaryCoverageTest.php`
- `tests/Feature/Security/PromptPolicyAndPaymentJobCoverageTest.php`
- `tests/Feature/Security/PromptConfigurationRoutesAndSeederCoverageTest.php`
- `tests/Feature/Filament/FinanceResourcesTest.php`
- `tests/Security/SecurityNextHardeningVerificationTest.php`
- `tests/Feature/Security/BotDetectionMiddlewareTest.php`
- `tests/Feature/Security/FraudDetectionServiceTest.php`
- `tests/TestCase.php`

## 4. Deleted duplicate files

- `app/Services/Withdrawal/ManualDisbursementQueueService.php`
- `app/Services/Antimalware/ClamAvScanner.php`

Both deletions consolidate existing canonical implementations; they do not remove the public contract used by callers.

## 5. New files

- `docs/PAYMENT_PROVIDER_AUDIT.md`
- `docs/PAYMENT_IMPLEMENTATION_REPORT.md`
- `docs/PAYMENT_PROMPT_PART1_VERIFICATION.md`
- `docs/PAYMENT_PROMPT_PART2_COMPLIANCE_FRAUD_VERIFICATION.md`
- `docs/PAYMENT_PROMPT_PART3_OPERATIONS_PAYOUT_VERIFICATION.md`
- `docs/PAYMENT_PROMPT_PART4_HTTP_BOUNDARY_VERIFICATION.md`
- `docs/PAYMENT_PROMPT_PART5_POLICIES_JOBS_VERIFICATION.md`
- `docs/PAYMENT_PROMPT_PART6_UI_CONFIG_ROUTES_DATABASE_VERIFICATION.md`
- `docs/PAYMENT_CHANGED_FILES_FULL_CONTENT.txt` (generated complete-content appendix; it excludes only itself to avoid recursion)
- `database/migrations/2026_10_28_000400_create_ledger_chain_state_table.php`

The additive migration creates and initializes one ledger chain mutex/tip row. It refuses to initialize if historical data already has multiple tips; it does not rewrite or destroy financial history.

## 6. Provider integration status

| Provider | Internal status | Production status |
|---|---|---|
| Stripe | Fully implemented status in readiness service; internal tests cover checkout/signatures/replay/settlement | Disabled and unconfigured; requires account keys, webhook secret and sandbox/live certification |
| bKash | Partial tokenized-checkout/query architecture | Not live; callback authentication contract and credentials are not verified; BDT/BD only |
| Nagad | Partial adapter/signing architecture | Not live; merchant callback/disbursement contract and credentials are not verified; BDT/BD only |
| PromptPay | Deposit QR architecture | Disabled; requires real merchant identity and bank notification contracts |
| Bank transfer | Configured-only manual rail | Disabled; requires real settlement account, SOP and reconciliation ownership |
| Thai bank SCB/KBANK | Partial automated payout/poll/webhook architecture | Disabled; requires bank-confirmed endpoints, credentials, source account and certification |
| TrueMoney | Opt-in voucher architecture | Disabled; unofficial API risk must be explicitly and legally accepted; no live claim made |
| EasySlip/SlipOK | Verification adapters | Disabled; requires provider credentials and configured receiving accounts |
| Crypto | Unsupported and fail-closed | Not available |
| Skrill/Neteller/PayPal/Perfect Money | Fee display entries only | Not executable and correctly not represented as live gateways |

## 7. API/admin impact

- No new duplicate API endpoint was added.
- Existing webhook URI contracts remain unchanged.
- Missing signature responses are now consistently authentication failures.
- Existing admin maker/checker, evidence and reconciliation workflows are preserved.
- No secret-editing admin surface or arbitrary balance adjustment was introduced.

## 8. Commands executed

Environment/discovery:

```bash
git clone --depth 1 https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git
find ...                         # project/payment inventory
rg ...                           # balance writes, float-money candidates, markers, routes, bindings
sudo apt-get install php8.4-cli php8.4-bcmath php8.4-sqlite3 php8.4-mysql \
  php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-gd composer ripgrep
composer install --no-interaction --prefer-dist
```

Configuration and route verification:

```bash
python3 scripts/complete-env-templates.py config .
php scripts/check-env-parity.php
APP_ENV=testing php artisan payments:readiness --json
APP_ENV=testing php artisan route:list --json
APP_ENV=testing php artisan migrate:status --no-ansi
composer validate --strict --no-check-publish
```

Static checks:

```bash
find app config routes database/migrations tests -name '*.php' -print0 \
  | xargs -0 -n1 -P8 php -l
php vendor/bin/pint --test \
  app/Http/Controllers/Api/V1/PaymentWebhookController.php \
  app/Http/Requests/Web/WithdrawalRequest.php \
  app/Services/Payment/PaymentWebhookService.php
```

Tests:

```bash
APP_ENV=testing php artisan test tests/Feature/Payment --log-junit=/tmp/payment-final.xml
APP_ENV=testing php artisan test tests/Feature/Finance tests/Feature/Compliance tests/Feature/Security \
  --log-junit=/tmp/finance-security.xml
APP_ENV=testing php artisan test \
  tests/Feature/Payment/ExpiredPaymentTest.php \
  tests/Feature/Payment/CryptoIngressSafetyTest.php \
  tests/Feature/Payment/InvalidWebhookSignatureTest.php
APP_ENV=testing php artisan test tests/Feature/Compliance/UnifiedClamAvScannerTest.php

FINANCIAL_PAYOUT_RACE=1 DB_CONNECTION=mysql DB_HOST=localhost \
DB_SOCKET=/run/mysqld/mysqld.sock DB_DATABASE=thai_lottery_test \
DB_USERNAME=lotto DB_PASSWORD='[local test secret]' \
APP_ENV=testing vendor/bin/phpunit $(cat /tmp/server-tests.txt) \
  --log-junit=/tmp/mysql-server-tests-final.xml

APP_ENV=testing vendor/bin/phpunit tests/Feature/Ledger tests/Feature/Payment \
  --log-junit=/tmp/ledger-payment-after-chain.xml
```

The MySQL command used MariaDB 11.8.6, an absolute Unix socket, global `READ-COMMITTED`, and a disposable test user with the `PROCESS` privilege required by the read-only InnoDB lock witness. Additional focused payment regressions were run for payout approval, financial hold ownership, dispatch uncertainty, provider-reference ownership, TrueMoney, PromptPay compatibility, manual disbursement and destination validation.

## 9. Actual results

### Passed

- Final PHP syntax sweep: **2,863 files passed**.
- Route load: **727 routes**, no duplicate route name or identical method/URI pair detected by the audit script.
- Composer validation: **passed**.
- Final Pint check on the seven selected changed production, migration, and regression files: **passed**.
- Environment parity: **passed**, 919 keys in each template.
- Focused security fixes: **6 tests passed, 21 assertions**.
- Unified ClamAV: **4 tests passed, 46 assertions**.
- Final post-change Payment run: **1,367 passed, 0 failed, 59 skipped, 16,196 assertions**. The corrected bKash destination fixture passes without weakening market or provider-capability rules.
- Final Finance + Compliance + Security run: **1,512 passed, 0 failed/errors, 56 skipped, 12,996 assertions**. The ClamAV consolidation and current bot-observation middleware contract are both covered and green.
- Focused feature + unit bot middleware contract run: **13 passed, 46 assertions**.
- Final Feature/Security run after the fraud replay regression addition: **100 passed, 0 failed/errors, 2 skipped, 565 assertions** (102 total tests).
- Compliance + KYC + fraud + security-hardening selection after the replay fix: **211 passed, 0 failed/errors, 14 skipped, 902 assertions** (225 total tests).
- Focused fraud replay and rule run: **5 passed, 22 assertions**.
- Operations/readiness + Thai-bank adapter/webhook/poller selection: **140 passed, 776 assertions**.
- New direct provider-operation/health, route-gate, and payout-statement coverage: **5 passed, 32 assertions**.
- Part 4 HTTP boundary/security-hardening selection: **11 passed, 81 assertions**.
- Part 4 controller/webhook/admin/KYC selection after fixes: **71 passed, 411 assertions**.
- Part 5 policy/job focused regression selection: **59 passed, 317 assertions**.
- The broader Part 5 selection that included `ProductionQueueComprehensiveTest` exposed 2 errors and 7 failures in stale provider/draw fixtures; it is recorded as non-passing and was not “fixed” by reopening disabled provider lanes or removing current prerequisites.
- Part 6 baseline UI/schema/factory/route selection: **78 passed, 756 assertions**.
- Part 6 broad UI/config/route/database selection before the final unsafe-action removals: **90 passed, 849 assertions**.
- Final administrative-finance plus restored cross-part regression lane after those removals: **54 passed, 424 assertions**.
- Final checkout-wide syntax lane: **2,926 PHP files, 0 syntax errors**.
- Part 6 post-Pint changed-lane selection: **65 passed, 522 assertions**.
- Part 6 recursive syntax/config/schema evidence: **277 PHP files syntax-pass**, **727 routes loaded**, **194/194 SQLite migrations executed**, and environment parity **919/919/919**.
- MariaDB/InnoDB `WithdrawalHoldFloorServerRaceTest`: **7 passed, 154 assertions**.
- Final guarded 30-class server financial/concurrency batch: **123 passed, 0 skipped, 3,065 assertions**, including ledger, settlement, wallet conversion, webhook obligation, withdrawal, payout evidence, prize claims, reconciliation, Stripe follow-up/refund, lifecycle boundary and compliance races.
- Isolated post-fix `DoubleEntryLedgerServerRaceTest`: **2 passed, 56 assertions**. The same class also passed inside the final full server batch.
- MySQL migration status: all migrations through `2026_10_28_000400_create_ledger_chain_state_table` report **Ran**.

### Remaining non-passing outcomes

The historical complete Payment, Finance, Compliance, Security, ClamAV, and guarded MariaDB financial-race lanes listed above passed. In Part 5, a broader mixed queue selection exposed 2 errors and 7 failures in `ProductionQueueComprehensiveTest`: its fixtures expect superseded provider-account, draw-result, and disabled normalized-callback behavior. The focused current policy/job, queue-routing, Thai-bank polling, payout-transfer, and security suites pass. The stale class remains a recorded non-passing outcome and was not made green by weakening production controls.

### Skips / infrastructure limits

A transient MariaDB 11.8.6 server became available late in the audit, so the earlier MySQL-unavailable limitation was superseded. The first selected batch left 10 payout-contention tests behind their explicit opt-in guard; rerunning with `FINANCIAL_PAYOUT_RACE=1` executed the complete profile and passed all 123 tests without skips. The transient database process was stopped after final migration and race verification. MariaDB is valuable InnoDB evidence but is not a substitute for the deployment target's required MySQL 8 certification lane; Redis- and provider-dependent lanes also still require their own infrastructure.

### Commands that could not complete

- PHPStan configuration files exist, but PHPStan is not present in `composer.lock`/`vendor`; no dependency was invented or added merely to claim a static-analysis pass.
- Prompt-named `demo-check.sh`, `setup-docker-test-lane.sh`, and `docker-compose.test-docker.yml` do not exist in this checkout.

## 10. Remaining external dependencies

Before real-money production enablement:

1. Licensed-market and gambling/payment legal approval.
2. Provider merchant agreements and production approval.
3. Stripe keys and endpoint signing secret.
4. bKash current official merchant callback/IPN and B2C specifications, credentials and sandbox certification.
5. Nagad current official merchant PGW/disbursement specification, keys, IP whitelist and sandbox certification.
6. SCB/KBANK bank-approved transfer/status paths, OAuth credentials, source account, callback signing details and sandbox certification.
7. PromptPay merchant proxy/biller and bank notification agreement.
8. Slip-provider credentials and an authoritative receiving-account allowlist.
9. Production KYC/AML/sanctions/source-of-funds vendors or documented manual processes.
10. MySQL 8/InnoDB and Redis integration/race test environments.
11. Secret-manager provisioning, queue workers, TLS, monitoring, backups, reconciliation schedules and incident runbooks.

## 11. Production readiness assessment

**Assessment: NOT READY FOR REAL-MONEY PRODUCTION.**

The internal financial boundaries are strong and the audited fixes improve fail-closed behavior, eliminate duplicate implementations, make configuration auditable, and now include successful MariaDB/InnoDB race evidence. However, all providers are disabled/unconfigured, several local-provider contracts are unverified, and deployment-target MySQL 8, Redis, and provider sandbox certification remain outstanding. `REAL_MONEY_ENABLED` should remain false until every selected provider, legal market, operational control and concurrency test is independently certified.
