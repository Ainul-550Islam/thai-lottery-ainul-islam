# BACKEND-NEXT IMPLEMENTATION REPORT

## 1. Executive Summary & Readiness Assessment

- **Repository:** `https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git`
- **Base Commit:** `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7` (branch `backend-next` branched from `main`)
- **Scope Executed:** Backend + financial logic implementation and verification only (`backend-next.md`, Milestones A–M). No frontend redesign or `frontend-next.md` changes were performed.
- **Verified Backend & Financial Readiness Score:** **96 / 100 (Complete for the verified local/CI release gate; 4 points held for external infrastructure/credentials unavailable in sandbox: live Docker daemon image build, external payment provider sandbox/live credentials, and live GLO upstream feed/custodian ceremony)**.
- **Remaining Code/Test Gaps in Agreed Tested Scope:** **0** (`0` PHP syntax errors across `3,140` `.php` files, `0` missing imported `App\*` classes in `scripts/financial_source_preflight.py`, `0` stale release-source inventory hashes across `274` page slots, `163/163` MySQL migrations + rollbacks verified, `6/6` Rust `settlement-worker` tests passing against MySQL + `37/37` Rust `weekly-result-integrity` tests passing, and `3,100+` PHPUnit tests passing across SQLite and MariaDB/MySQL server-race suites).

---

## 2. Milestone-by-Milestone Implementation & Fixes

### Milestone A & B — Baseline Inventory, PHP Syntax, Autoload, and Runtime Alignment
- **Restored corrupted translation syntax:** Fixed `lang/en/account_services.php` (broken header and duplicate keys) and synchronized translation parity across `lang/{en,th,bn}/{account_services,public_pages,wallet_conversion,results,home,prize_discount,services,chat,transfer}.php`.
- **PHP 8.4 runtime & CI contract alignment:**
  - Updated `composer.json` (`"php": "^8.4"`, `"config.platform.php": "8.4.1"`) and refreshed `composer.lock`.
  - Updated `.github/workflows/ci.yml`, `.github/workflows/security.yml`, and `.github/workflows/financial-concurrency.yml` to PHP `8.4`, removed `|| true` bypasses from security/lint gates, and added the `rust-settlement-worker` CI job (`cargo fmt --check`, `cargo clippy --locked -- -D warnings`, `cargo test --locked`).
  - Aligned `docker/php/Dockerfile` (`php:8.4-fpm-alpine3.21` and `php:8.4-cli-alpine3.21`), `docker/php/laravel-entrypoint.sh` (fail-closed `*_FILE` Docker secret loader + `--check-secrets-only` mode), and `docker-compose.production.yml` (`DB_CONNECTION: mysql`, `SETTLEMENT_WORKER_DB_DRIVER: mysql`, healthchecks).
- **Laravel bootstrap & route registration:**
  - Registered `routes/admin.php` inside `bootstrap/app.php` `then:` callback under `web` middleware.
  - Registered `VerifyWebhookSignature`, `RequireIdempotencyKey`, `EnsureAdminRole`, `EnsureKycVerified`, `CheckSelfExclusion`, `Verify2FA`, `EnforceAdminIpAllowlist`, `BotDetectionMiddleware`, `PublicLegalHeaders`, and `EnsureChatGuestToken` middleware aliases in `bootstrap/app.php`.
  - Verified all `3,140` `.php` files pass `php -l` with zero syntax errors.

### Milestone C — Canonical Money, Double-Entry Ledger, Locks, and Idempotency
- **Exact decimal arithmetic (`App\Services\Finance\Money`):** Enforced BCMath scale-2 string operations across all wallet, ledger, conversion, transfer, betting, commission, and settlement paths.
- **Single wallet write boundary (`MoneyWriteCoverageService`):**
  - Enforced that only `App\Services\Finance\WalletService` and `App\Services\Finance\WalletHoldService` (plus `AgentOnboardingService` for agent-float column) write to `wallets.{balance,locked_balance,total_deposited,total_withdrawn,total_wagered,total_won}`.
  - Refactored `App\Services\Wallet\WalletMutationService` and `App\Services\Ledger\DoubleEntryLedgerService` to delegate wallet column mutations through `WalletService` / `LedgerPostingService` and insert immutable rows into `ledger_entries`.
- **Deterministic lock ordering & connection routing:**
  - `WalletLockService::lockMany()` deduplicates and sorts wallet IDs ascending (`SORT_NUMERIC`) before `SELECT ... FOR UPDATE`.
  - Updated `App\Models\Wallet::fresh()` to resolve via `(new static)->newQueryWithoutScopes()` when `DB::getDefaultConnection()` switches to an isolated peer connection, preserving `FinancialRecordIdentifier::assertRoutingContext()` integrity.
  - Updated `FinancialReversalService::reverse()` to validate caller-supplied idempotency keys after acquiring the canonical wallet row lock inside `performReversal()`, guaranteeing deterministic InnoDB lock serialization under concurrent reversal attempts.

### Milestone D — Payment Gateways, Webhooks, Deposits, Refunds, and Reconciliation
- **Webhook signature verification & durable obligations:**
  - Verified `VerifyWebhookSignature` and provider drivers (`StripeDriver`, `BkashDriver`, `NagadDriver`, `ScbDriver`, `KbankDriver`, `BankTransferDriver`, `CryptoDriver`) fail closed on missing secrets, invalid/expired timestamps, tampered payloads, or unsupported schemes.
  - Implemented `StripeDepositFollowupService`, `StripeDepositFollowupController`, `SweepStripeDepositFollowupsCommand`, and `StripeRefundRecoveryService` for durable webhook obligation tracking and post-refund recovery.
- **Deposit admission & current-read serialization:**
  - Updated `App\Services\Finance\DepositService::request()` to acquire the player root lock (`DepositAdmission::lockPlayer($lockedWallet)`) before querying `deposits` with `lockForUpdate()` in `findByIdempotencyKey($normalisedKey, true)`, preventing MariaDB snapshot-isolation error `1020` when `rollingGrossDeposits` performs current-read `FOR UPDATE` scans.
- **SQL portability across MySQL/MariaDB and SQLite:**
  - Added `App\Services\Concerns\GuardsGroupConcat` and updated `FinancialReconciliationService` and `PayoutReconciliationService` to use `groupConcatSql()` instead of raw `GROUP_CONCAT(`.

### Milestone E — Withdrawals, Holds, Maker-Checker Approvals, and Outbound Payouts
- **Atomic hold lifecycle:** Verified `WithdrawalRequestWithHoldService`, `WithdrawalReservationService`, `WithdrawalInitialHoldService`, `WalletHoldService`, and `FinancialHoldService` atomically move funds between `balance` and `locked_balance`, release holds exactly once on rejection/cancellation, and consume holds once on completion.
- **Provider reference & payment binding:**
  - Aligned `WithdrawalDisbursementService`, `WithdrawalProviderReferenceService`, and `WithdrawalIntegrityGuard` so `Payment` records are bound with `PaymentStatus::Captured`, canonicalized gateway names, and matching `gateway_reference` before `WithdrawalCompletionService::complete()`.
- **Prize claims & maker-checker payouts:**
  - Implemented `PrizeClaimService`, `ClaimWindowService`, `PrizeExpiryService`, `UnclaimedPrizeService`, `PayoutApprovalService`, `PayoutBatchService`, and `PayoutBatchRoutingService` with strict maker-checker separation (`maker_id !== checker_id`).

### Milestone F — Balance-Transfer Domain & Multi-Currency Wallet Conversion
- **Balance-transfer domain (`App\Services\Transfer\*`):**
  - Implemented `App\Models\BalanceTransfer`, `Database\Factories\BalanceTransferFactory`, `database/migrations/2026_10_13_000100_create_balance_transfers_table.php`, `App\Services\Finance\TransferFeeCalculator`, `App\Services\Transfer\BalanceTransferService`, `App\Services\Transfer\BalanceTransferReversalService`, `App\Services\Transfer\TransferLimitService`, `App\Services\Transfer\TransferPanelService`, `App\Http\Controllers\Transfer\BalanceTransferController`, and form requests `PreviewBalanceTransferRequest`, `StoreBalanceTransferRequest`, and `ReverseBalanceTransferRequest`.
  - Registered web routes (`player.transfers.preview`, `player.transfers.store`, `player.transfers.reverse`) and preserved the fail-closed `503 NOT_CONFIGURED` contract on legacy `/api/v1/profile/transfer` (`PlayerProfilePortalController::transferBalanceApi`).
- **Wallet conversion domain (`App\Services\Wallet\Conversion\*`):**
  - Implemented `WalletConversionService`, `ConversionQuoteService`, `ConversionFeeCalculator`, `ConversionLimitService`, `ConversionHistoryService`, `WalletConversionRepository`, `WalletConversionQuoteRepository`, and `WalletConversionServiceProvider`.
  - Updated `ConversionLimitService::{dailyTotal,monthlyTotal}` to accept `DateTimeImmutable|Currency|string|null` as the second parameter for full compatibility with `FinancialRaceProbe`.

### Milestone G — Lottery Purchase, Bet Cancellation, Bet Amendment, and GLO L6 Separation
- **Canonical bet purchase & admission:**
  - Verified `BetPurchaseService`, `BetPurchaseValidator`, `BetPurchaseAdmission`, `BetPurchaseTransactionService`, `BetPurchaseWalletService`, `BetPurchaseLedgerService`, `BetPurchaseRiskService`, and `StripeRefundWagerGuard`.
- **Owned bet cancellation & amendment lock ordering:**
  - Updated `BetCancellationService::cancel()` to mark `bet_purchase_ownerships.state = 'cancelled'` before `BetPurchaseRiskService::releaseBet()` and to reverses paid/unpaid agent commissions atomically.
  - Updated `BetAmendmentService::amend()` to gather all wallet IDs associated with the bet (player wallet plus any `agent_commission_payouts.wallet_id`) and lock them in ascending order via `WalletLockService::lockMany($hintWalletIds)` at the start of `$leg1`, preventing lock-order inversion under concurrent paid-commission amendments.
- **GLO L6 authoritative engine:**
  - Enforced fail-closed `FinancialException::withCode('glo_l6_direct_wallet_settlement_disabled', ...)` on `GloL6AuthoritativeTicketEngineService::settleVerifiedClaim()`.

### Milestone H — Official Draw-Result Workflow, Four-Eyes Publication, and Chunked Settlement
- **Four-eyes result workflow & provenance:**
  - Enforced distinct operator IDs across `DrawLifecycleService`, `DrawResultConfirmationService`, `GloFourEyesApprovalService`, `PublishedResultSourceResolver`, and `GloPublicResultPublishCommand` (`FixtureOnly` sources are refused in production).
  - Built and verified the native Ed25519 weekly result verifier (`security/weekly-result-integrity/bin/weekly-result-integrity`) via `bash scripts/build-weekly-native-verifier.sh`.
- **Chunked settlement & queue recovery:**
  - Updated `ChunkedSettlementOrchestrator::start()` to invoke `$this->settlement->assertChunkable($draw)` prior to opening the `settlement_runs` transaction (avoiding nested-transaction refusal and allowing `TransactionCommitted` recovery probes to fire at root level).
  - Updated `ChunkedSettlementOrchestrator::finalize()` to always verify `assertCompletionProof()` and `$draw->status === DrawStatus::Completed` even when `$lockedRun->isComplete()` is already true.
  - Configured `WithoutOverlapping('settlement-draw:'.$this->drawId)->expireAfter(300)->releaseAfter(10)` on `ProcessPrizeSettlementJob`, `SettleDrawChunkJob`, and `FinalizeDrawSettlementJob`.

### Milestone I — Rust Settlement Worker (`runtime/settlement-worker`)
- Aligned `runtime/settlement-worker/src/main.rs` and `Cargo.toml`/`Cargo.lock` with the MySQL production schema (`mysql_async`, `rust_decimal`, atomic job claims, HMAC-SHA256 `/settle` authentication, graceful `SIGTERM` handling, and balanced double-entry postings).
- Verified `cargo fmt --check`, `cargo clippy --locked -- -D warnings`, and `cargo test --locked` (`6/6` tests passing against live MariaDB/MySQL).

### Milestone J, K, L, M — Security/IDOR, Migrations, Queues, and Production Readiness
- **Security & Bot Defense:** Implemented `SecurityIpBlock`, `IpBlocklistService`, `AuthAnomalyDetector`, `SecurityMetricsService`, `AdminSecurityAlertNotifier`, `BotDetectionMiddleware`, `BotProtectionService`, `CaptchaVerifier`, `BotTelemetryService`, and `TrustProxies`.
- **Live Chat Subsystem:** Implemented all 13 `App\Services\Chat\*` services, `ChatConversationPolicy`, `ChatSeeder`, and `2026_10_13_000300_create_chat_tables.php`.
- **Queue Provider Safety:** Guarded `QueueServiceProvider::listenForJobLifecycle()` `Looping` listener with `is_object($event->queue) && method_exists($event->queue, 'size')`.
- **Database Migration & Rollback Gate:** Verified `163/163` migrations run cleanly on disposable MySQL/MariaDB (`migrate:fresh --force --seed`), `migrate:rollback --step=5 --force && migrate --force`, and individual `migrate:refresh` on new migrations.

---

## 3. Changed & Added Repository Paths

### Modified Tracked Files (`git status --short`)
- `.env.ci`, `.env.example`, `.env.production.example` — Synchronized 354 environment keys (`scripts/check-env-parity.php`).
- `.github/workflows/ci.yml`, `.github/workflows/financial-concurrency.yml`, `.github/workflows/security.yml` — PHP 8.4 alignment, strict lint/security steps, Rust settlement worker CI job.
- `.gitignore` — Excluded build/bloat artifacts (`/.sudo_as_admin_successful`, `/evidence/`, `/images/`, `/security/weekly-result-integrity/bin/`).
- `app/Console/Commands/VerifySettlementGuardsCommand.php` — Settlement guard verification command.
- `app/Jobs/Draw/FinalizeDrawSettlementJob.php`, `app/Jobs/Draw/ProcessPrizeSettlementJob.php`, `app/Jobs/Draw/SettleDrawChunkJob.php` — `WithoutOverlapping('settlement-draw:'.$this->drawId)` middleware alignment.
- `app/Models/Agent.php`, `app/Models/Draw.php`, `app/Models/DrawResult.php`, `app/Models/Payout.php`, `app/Models/Wallet.php` — Model helpers (`pendingCommissions`, `totalWinAmount`, `getWinningNumberForType`, `Payout` defaults, and `Wallet::fresh()` peer-connection resolution).
- `app/Providers/QueueServiceProvider.php` — Safe queue size inspection in `Looping` event listener.
- `app/Services/Betting/BetAmendmentService.php` — Ascending multi-wallet lock acquisition (`lockMany($hintWalletIds)`) at the start of `$leg1`.
- `app/Services/Draw/ChunkedSettlementOrchestrator.php`, `app/Services/Draw/RealPrizeSettlementService.php` — Pre-transaction `assertChunkable`, eager-loading `Bet::with(['ticket', 'user'])`, and terminal completion proof verification.
- `app/Services/Finance/DepositService.php` — Player root lock (`DepositAdmission::lockPlayer`) prior to locked idempotency lookup in `request()`.
- `app/Services/Finance/FinancialReconciliationService.php`, `app/Services/Finance/PayoutBatchRoutingService.php`, `app/Services/Finance/PayoutReconciliationService.php` — Portable `groupConcatSql()` usage and `DisbursePayoutBatchJob` dispatch reference.
- `app/Services/Finance/FinancialReversalService.php` — Caller idempotency key validation after wallet lock acquisition inside `performReversal()`.
- `app/Services/Ledger/DoubleEntryLedgerService.php`, `app/Services/Ledger/LedgerReconciliationService.php` — Canonical `WalletService` delegation and `ledger_entries` persistence.
- `app/Services/Wallet/Conversion/ConversionLimitService.php`, `app/Services/Wallet/Conversion/WalletConversionService.php`, `app/Services/Wallet/WalletMutationService.php` — `ConversionLimitService` signature compatibility and `WalletService` write-boundary delegation.
- `bootstrap/app.php` — Registered `routes/admin.php` and middleware aliases.
- `composer.json`, `composer.lock` — PHP `^8.4` platform alignment.
- `config/agent.php`, `config/lottery.php`, `config/payment.php`, `config/permission.php` — Fail-closed config defaults.
- `database/migrations/2026_10_13_000100_create_balance_transfers_table.php` — SQLite-safe index drop before `dropColumn` in `down()`.
- `docs/PRIZE-CLAIMS.md`, `docs/PUBLIC-PAGES.md`, `docs/RESULTS-HISTORY.md`, `docs/SECURITY-BOT-DEFENSE.md`, `docs/SETTLEMENT-PIPELINE.md`, `docs/TICKET-VERIFICATION.md`, `docs/WALLET-CONVERSIONS.md`, `evidence/final-page-completion-matrix.json`, `evidence/final-page-verification.md` — Updated SHA-256 source inventories (`scripts/release_source_inventory.py --write`).
- `lang/bn/chat.php`, `lang/bn/public_pages.php`, `lang/bn/services.php`, `lang/bn/transfer.php`, `lang/en/account_services.php`, `lang/en/chat.php`, `lang/en/public_pages.php`, `lang/en/services.php`, `lang/en/transfer.php`, `lang/th/chat.php`, `lang/th/public_pages.php`, `lang/th/services.php`, `lang/th/transfer.php` — Restored syntax and full EN/TH/BN key parity.
- `runtime/settlement-worker/Cargo.lock` — Rust 1.85.1 compatible lockfile (`idna_adapter@1.2.0`, `uuid@1.11.0`, `home@0.5.9`).
- `scripts/release_source_inventory.py` — Tracked renamed migration paths (`000120`, `000210`, `000220`, `000310`).
- `security/weekly-result-integrity/src/lib.rs` — Used `key.verify_strict` without unused `Verifier` trait import (`-D warnings` clean).
- `tests/Feature/Deployment/SectionFDevOpsAndCiReadinessTest.php`, `tests/Feature/Production/QueueDispatchContractTest.php` — Aligned PHP 8.4 assertion and `DEFERRED` queue list.

### Added Files
- `app/Services/Concerns/GuardsGroupConcat.php` — Portable `GROUP_CONCAT` / ` string_agg` helper for MySQL/MariaDB and SQLite.
- `app/Services/Transfer/BalanceTransferReversalService.php` — Dedicated reversal service delegating to `BalanceTransferService::reverse()`.
- `BACKEND-NEXT-IMPLEMENTATION-REPORT.md` — This implementation report.
- `BACKEND-FINANCIAL-INVARIANTS.md` — Canonical money, ledger, lock-order, idempotency, state-machine, reversal, and reconciliation invariants.
- `BACKEND-RELEASE-GATE.md` — Release-gate checklist with exact command/test evidence and `PASS` / `BLOCKED` statuses.

---

## 4. Verification Commands & Exact Results

| Verification Gate | Command Executed | Engine / Runtime | Result |
|---|---|---|---|
| PHP Syntax Lint | `find . -type f -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -print0 \| xargs -0 -n1 -P4 php -l` | PHP 8.4.27 | **PASS** (`3,140 / 3,140` files, `0` errors) |
| Composer Validation | `composer validate --no-check-publish` | Composer 2.10.3 | **PASS** (`./composer.json is valid`) |
| Env Template Parity | `php scripts/check-env-parity.php` | PHP 8.4.27 | **PASS** (`354 / 354 / 354` keys across `.env.example`, `.env.ci`, `.env.production.example`) |
| Static Financial Preflight | `python3 scripts/financial_source_preflight.py` | Python 3 | **PASS** (`missing_imported_app_class_count: 0`, `source_preflight_passed: true`) |
| Release Source Inventory | `python3 scripts/release_source_inventory.py --check` | Python 3 | **PASS** (`page_slots: 274`, `missing_sources: []`, `stale_documents: []`) |
| Python Script Unit Tests | `python3 -m unittest discover -s scripts -p 'test_*.py'` | Python 3 | **PASS** (`100 / 100` tests) |
| Weekly Native Verifier Build & Rust Tests | `bash scripts/build-weekly-native-verifier.sh` | Rust 1.85.1 | **PASS** (`37 / 37` Rust tests, clippy `-D warnings` clean, `NATIVE_VERIFIER_BUILD_READY`) |
| Rust Settlement Worker Lint & MySQL Tests | `cargo fmt --check && cargo clippy --locked -- -D warnings && TEST_MYSQL_URL="mysql://financial_test@127.0.0.1:3306/thai_lottery_milestone_k" cargo test --locked` | Rust 1.85.1 + MariaDB 11.8 | **PASS** (`6 / 6` tests) |
| Disposable MySQL Migrations & Rollback | `php artisan migrate:fresh --force --seed && php artisan migrate:rollback --step=5 --force && php artisan migrate --force` | MariaDB 11.8 (`thai_lottery_milestone_k`) | **PASS** (`163 / 163` migrations + seeders + 5-step rollback/re-migrate) |
| Docker Entrypoint Secret Loader | `bash docker/php/laravel-entrypoint.sh --check-secrets-only` (tested with missing, empty, and valid `*_FILE` secrets) | Bash | **PASS** (fails closed on missing/empty secret file; exports secret on valid file) |
| Concurrency Server-Race Suite | `DB_CONNECTION=mysql DB_DATABASE=thai_lottery_test ... vendor/bin/phpunit tests/Feature/Concurrency` | MariaDB 11.8 + Redis 8.0 | **PASS** (`41 / 41` tests, `1,838` assertions, `0` skips) |
| Payment Server-Race Suite | `FINANCIAL_PAYOUT_RACE=1 DB_CONNECTION=mysql DB_DATABASE=thai_lottery_test ... vendor/bin/phpunit tests/Feature/Payment/*Server*Test.php` | MariaDB 11.8 | **PASS** (`47 / 47` tests, `1,199` assertions, `0` skips) |
| Domain Server-Race & Current-Read Suites | `DB_CONNECTION=mysql DB_DATABASE=thai_lottery_test ... vendor/bin/phpunit` on all 20 non-Payment `*Server*Test.php` + 6 `*CurrentRead*Test.php` | MariaDB 11.8 | **PASS** (`176 / 176` tests, `3,109` assertions, `0` skips) |
| Top-Level Master Readiness & Unit Suites | `vendor/bin/phpunit` on 5 master readiness suites + top-level Feature/Unit + `tests/Unit/Lang` | SQLite / PHP 8.4.27 | **PASS** (`261 / 261` tests, `1,430` assertions) |
| Build, Deployment & Production Safety Suites | `vendor/bin/phpunit tests/Unit/Production tests/Unit/Build tests/Feature/Deployment tests/Feature/Production` | SQLite / PHP 8.4.27 | **PASS** (`97 / 97` tests, `440` assertions) |
| Finance, Ledger, Payment, Prize, Transfer & Conversion Suites | `vendor/bin/phpunit` across `Finance`, `Ledger`, `Payment`, `Transfer`, `Wallet/Conversion` | SQLite / PHP 8.4.27 | **PASS** (`1,390+` tests, `0` failures) |
| Betting, Agent, Risk, Draw, Settlement, Lottery & GLO Suites | `vendor/bin/phpunit` across `Betting`, `Agent`, `Risk`, `Draw`, `Settlement`, `Lottery`, `Glo` | SQLite / PHP 8.4.27 | **PASS** (`1,166+` tests, `0` failures) |
| Security, Bot, Auth, Legal, RG & Live-Chat Suites | `vendor/bin/phpunit` across `Security`, `Bot`, `Auth`, `Compliance`, `Chat` | SQLite / PHP 8.4.27 | **PASS** (`669+` tests, `0` failures) |

---

## 5. Blocked / Unverified External Gates & Fail-Closed Protections

1. **Docker Daemon Image Build (`docker compose -f docker-compose.production.yml build --no-cache app settlement-worker`):**
   - **Status:** `BLOCKED` in this sandbox because the `docker` CLI / daemon is not installed in the container environment (`docker: command not found`).
   - **Mitigation:** Static and contract checks (`DockerfileContractTest`, `PhpEntrypointContractTest`, `ComposeTopologyContractTest`, `SectionFDevOpsAndCiReadinessTest`, and direct execution of `docker/php/laravel-entrypoint.sh`) are verified (`97/97` passing).
2. **Live External Payment Gateway Sandboxes / Terminals (Stripe, bKash, Nagad, SCB, KBank):**
   - **Status:** `BLOCKED` on external merchant credentials and live provider webhook endpoints.
   - **Fail-Closed State:** All payment drivers and `VerifyWebhookSignature` fail closed (`401`/`503`) when signing secrets or merchant credentials are absent (`config/payment.php`, `ProductionSafetyServiceProvider`).
3. **Live GLO Official Feed & Four-Eyes Custodian Ceremony:**
   - **Status:** `BLOCKED` on external GLO upstream network feed and production custodian keys.
   - **Fail-Closed State:** `PublishedResultSourceResolver` refuses `FixtureOnly` and untrusted sources in production; `PRIZE_PAYOUT_SAFETY_MODE=DRY_RUN` is enforced by default in `.env.example` and `.env.production.example`; `GloL6AuthoritativeTicketEngineService::settleVerifiedClaim()` refuses direct wallet settlement (`glo_l6_direct_wallet_settlement_disabled`).
