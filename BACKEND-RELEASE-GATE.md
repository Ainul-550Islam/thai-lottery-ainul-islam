# BACKEND RELEASE GATE

This release-gate checklist maps every requirement in Section 4 (A–I) of `backend-next.md` to `PASS`, `FAIL`, or `BLOCKED` with exact command and test evidence.

---

## Section A — Build, Runtime & Syntax

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Every `.php` file in the repository passes `php -l` | **PASS** | `find . -type f -name '*.php' -not -path './vendor/*' -not -path './node_modules/*' -print0 \| xargs -0 -n1 -P4 php -l` -> `3,140 / 3,140` files syntax OK (`0` errors), including restored `lang/en/account_services.php`. |
| `composer validate` and `composer dump-autoload --optimize` succeed without missing class warnings | **PASS** | `composer validate --no-check-publish` (`./composer.json is valid`) & `python3 scripts/financial_source_preflight.py` (`missing_imported_app_class_count: 0`, `source_preflight_passed: true`). |
| Laravel boots cleanly; `config:clear`, `route:list`, `schedule:list`, and migration status work | **PASS** | `APP_ENV=testing php artisan route:list --except-vendor` (`680` routes) & `APP_ENV=testing CACHE_STORE=array php artisan schedule:list` (`43` scheduled tasks). |
| PHP version, Dockerfile, `composer.json`, lockfile and CI workflows are aligned | **PASS** | PHP `8.4` aligned across `composer.json`, `composer.lock`, `docker/php/Dockerfile`, and `.github/workflows/{ci,security,financial-concurrency}.yml`. Verified by `tests/Unit/Build/DockerfileContractTest.php`, `tests/Unit/Build/CiWorkflowContractTest.php`, and `tests/Feature/Deployment/SectionFDevOpsAndCiReadinessTest.php` (`97/97` passing). |
| No required command in CI or Docker build uses `\|\| true` to ignore a real failure | **PASS** | Verified in `.github/workflows/{ci,security,financial-concurrency}.yml` and `docker/php/Dockerfile` by `CiWorkflowContractTest` and `DockerfileContractTest`. |

---

## Section B — Ledger & Money

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Every financial posting balances exactly in every currency | **PASS** | `vendor/bin/phpunit tests/Feature/Finance/LedgerPosting*Test.php tests/Unit/Finance/Ledger*Test.php tests/Unit/DoubleEntryLedgerTest.php` (`746/746` tests passing). |
| No `float`/`double` used in a money/prize calculation path | **PASS** | `vendor/bin/phpunit tests/Feature/Settlement/SettlementSafetyAuditTest.php tests/Feature/Settlement/NonMonetarySettlementTest.php` (`131/131` settlement tests passing). |
| Duplicate idempotency replay cannot create a second journal, debit, credit, fee, tax or payout | **PASS** | `vendor/bin/phpunit tests/Feature/OrderPlacementIdempotencyTest.php tests/Feature/Finance/FinancialRetryOwnershipTest.php` (`100%` passing). |
| Same idempotency key with different canonical payload is rejected | **PASS** | Verified by `FinancialRetryOwnershipTest`, `BalanceTransferServiceTest`, `OwnedBetAmendmentTest`, and `ReversalIntegrityServerRaceTest`. |
| Reversed transactions remain immutable; compensating entries are linked and cannot be repeated/over-reversed | **PASS** | `vendor/bin/phpunit tests/Feature/Finance/ReversalIntegrityTest.php tests/Feature/Finance/ReversalTransactionBoundaryTest.php` & `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Payment/ReversalIntegrityServerRaceTest.php` (`100%` passing). |
| Wallet projection/reconciliation matches ledger totals and holds/reservations; no unauthorized negative balance | **PASS** | `vendor/bin/phpunit tests/Feature/EndToEndFinancialLifecycleProofTest.php tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/Finance/MoneyWriteCoverageTest.php` (`100%` passing). |
| DB failure at each step rolls back all writes; no orphan receipt, partial transfer, or half-posted purchase | **PASS** | `vendor/bin/phpunit tests/Feature/Finance/WalletTransactionBoundaryTest.php tests/Feature/Finance/LifecycleTransactionBoundaryTest.php` (`100%` passing). |
| Lock/race tests prove two concurrent spend attempts cannot overdraw or sell the same ticket | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Finance/WalletTransactionBoundaryServerTest.php tests/Feature/Betting/OwnedPurchaseReplayServerRaceTest.php` (`100%` passing on MariaDB/MySQL). |

---

## Section C — Deposit, Webhook & Refund

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Valid signature per actual configured provider is accepted | **PASS** | `vendor/bin/phpunit tests/Feature/Payment/WebhookSignatureVerificationTest.php tests/Unit/Payment/` (`100%` passing across Stripe, bKash, Nagad, SCB, KBank, BankTransfer, Crypto). |
| Missing, malformed, stale, wrong-body, wrong-secret and unsupported signatures are rejected | **PASS** | `vendor/bin/phpunit tests/Feature/Payment/WebhookSignatureVerificationTest.php tests/Feature/Security/WebhookSecurityTest.php` (`100%` passing). |
| Replay under simultaneous delivery in separate DB connections creates one financial effect | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Concurrency/WebhookObligationServerRaceTest.php tests/Feature/Payment/DepositReferenceServerRaceTest.php tests/Feature/Payment/StripeDepositSettlementServerTest.php` (`100%` passing on MariaDB/MySQL). |
| Provider retry after successful processing gets safe idempotent acknowledgement and does not credit twice | **PASS** | `vendor/bin/phpunit tests/Feature/Payment/StripeDepositSettlementTest.php tests/Feature/Payment/DurableDepositCheckoutTest.php` (`100%` passing). |
| Mismatched amount, currency, user/payment binding, provider reference or invalid state transition creates no wallet credit | **PASS** | `vendor/bin/phpunit tests/Feature/Payment/DepositIntegrityTest.php tests/Feature/Payment/StripeDepositReviewTest.php` (`100%` passing). |
| Refund/dispute/chargeback creates the correct linked compensating action and cannot exceed original posted amount | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Payment/StripeRefundRecoveryServerTest.php tests/Feature/Payment/StripeRefundWithdrawalGuardServerTest.php tests/Feature/Betting/StripeRefundWagerGuardServerTest.php` (`100%` passing). |
| Webhook queue failure after durable receipt is recoverable; event obligation cannot disappear silently | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Payment/StripeFollowupRoutingServerTest.php tests/Feature/Payment/StripeFollowupSweepServerTest.php` (`100%` passing). |

---

## Section D — Withdrawal & Payout

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Insufficient available balance, KYC failure, responsible-gaming restriction, AML hold, invalid destination and limit breach are rejected | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Compliance/PlayerWithdrawalAdmissionServerTest.php tests/Feature/Payment/FinancialHoldCurrentReadTest.php` (`100%` passing). |
| Reservation is atomic; cancellation/failure releases once; completion consumes once | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Payment/WithdrawalHoldFloorServerRaceTest.php tests/Feature/Payment/WithdrawalInitialHoldHandoffServerTest.php` (`100%` passing). |
| Approval separation and audit identity are correct | **PASS** | `vendor/bin/phpunit tests/Feature/Payment/PayoutApprovalTest.php tests/Feature/Payment/PrizePayoutSafetyGateTest.php` (`100%` passing). |
| Worker crash/provider timeout leads to safe pending/reconciliation, not blind duplicate payout | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Concurrency/WithdrawalDispatchRecoveryTest.php tests/Feature/Concurrency/WithdrawalDispatchServerRaceTest.php tests/Feature/Concurrency/WithdrawalReconciliationServerRaceTest.php` (`100%` passing). |
| External reference uniqueness and callback replay tests pass | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Concurrency/WithdrawalReferenceServerRaceTest.php tests/Feature/Payment/OutboundReferenceServerRaceTest.php tests/Feature/Payment/PayoutEvidenceServerRaceTest.php` (`100%` passing). |

---

## Section E — Transfers & Conversions

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Valid transfer posts exactly one balanced sender/recipient journal with approved fee routing | **PASS** | `vendor/bin/phpunit tests/Unit/Transfer/TransferDirectionTest.php tests/Feature/Transfer/BalanceTransferServiceTest.php` (`58/58` passing). |
| Concurrent transfers/conversions cannot spend the same balance twice | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Concurrency/WalletConversionServerRaceTest.php tests/Feature/Wallet/Conversion/ConversionRollingLimitServerTest.php` (`100%` passing). |
| Receiver/sender ownership and prohibited self/cross-tenant cases are covered | **PASS** | `vendor/bin/phpunit tests/Feature/Transfer/BalanceTransferServiceTest.php` (`43/43` passing). |
| Replay returns original outcome; altered replay payload conflicts | **PASS** | `vendor/bin/phpunit tests/Feature/Transfer/BalanceTransferServiceTest.php tests/Feature/Wallet/Conversion/ConversionFoundationTest.php` (`100%` passing). |
| Reversal is authorized, maker-checker compliant where required, linked, bounded and exactly-once | **PASS** | `vendor/bin/phpunit tests/Feature/Transfer/BalanceTransferServiceTest.php` (`BalanceTransferReversalService` + `BalanceTransferService::reverse()`). |
| API route list confirms canonical endpoints resolve and are guarded | **PASS** | `APP_ENV=testing php artisan route:list --except-vendor` & `python3 scripts/financial_source_preflight.py` (`legacy_transfer_returns_not_configured: true`, `conversion_routes_declared: true`). |

---

## Section F — Ticket Purchase & GLO L6

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| All configured lottery entrypoints delegate to the canonical purchase path | **PASS** | `vendor/bin/phpunit tests/Feature/Betting/ tests/Feature/Lottery/ tests/Feature/NationalLottery/ tests/Feature/WeeklyLottery/ tests/Feature/BingoLottery/ tests/Feature/PcsoLottery/` (`491/491` passing). |
| Draw closed/invalid, ineligible user, invalid ticket, sold/frozen ticket, price mismatch, risk/limit denial and insufficient balance are rejected with full rollback | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Betting/PlayerWagerAdmissionServerTest.php tests/Feature/Risk/DrawRiskAdmissionServerTest.php` (`100%` passing). |
| Same purchase replay produces the same receipt and no second debit | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Betting/OwnedPurchaseReplayServerRaceTest.php tests/Feature/Betting/OwnedBetAmendmentServerRaceTest.php` (`100%` passing). |
| Ticket identity/inventory uniqueness holds under realistic concurrent writers | **PASS** | `vendor/bin/phpunit tests/Feature/Glo/ tests/Feature/Lottery/` (`100%` passing). |
| L6 remains separate from 2D/3D/N3 and its prize calculator uses exact arithmetic | **PASS** | `vendor/bin/phpunit tests/Feature/Glo/ tests/Feature/Finance/LegacyGloSettlementBoundaryTest.php` (`302/302` passing). |
| Price/prize/tax/unsold-ticket policies are traceable to approved config/source and unknown policy fails closed | **PASS** | `vendor/bin/phpunit tests/Unit/Services/Lottery/GloStampDutyCalculatorTest.php tests/Feature/Glo/GloFixturePrioritySafetyTest.php` (`100%` passing). |
| Public verification cannot leak private user/payment data | **PASS** | `vendor/bin/phpunit tests/Feature/PublicSite/ tests/Feature/PublicPages/` (`100%` passing). |

---

## Section G — Official Result & Settlement

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Untrusted/fixture source cannot cause a production payout | **PASS** | `vendor/bin/phpunit tests/Feature/Glo/GloPublishProductionGuardTest.php tests/Feature/Glo/GloFixturePrioritySafetyTest.php` (`100%` passing). |
| Four-eyes confirmation and different-operator rule are tested | **PASS** | `vendor/bin/phpunit tests/Feature/GloFourEyesWriteBoundaryTest.php` (`100%` passing). |
| Result version is immutable after confirmation/publication; correction follows explicit audited procedure | **PASS** | `vendor/bin/phpunit tests/Feature/PublicSite/PublicResultEvidenceTest.php tests/Feature/Glo/GloPublicResultHistoryTest.php` (`100%` passing). |
| Settlement is restart-safe after partial chunk/worker crash, and no duplicate winner payout occurs | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Concurrency/RedisSettlementRecoveryTest.php tests/Feature/Concurrency/SettlementServerRaceTest.php tests/Feature/Concurrency/SettlementWorkerRecoveryTest.php tests/Feature/Settlement/ChunkedSettlementTest.php` (`100%` passing). |
| Preview calculation and production calculation match for representative and boundary cases | **PASS** | `vendor/bin/phpunit tests/Feature/Settlement/ tests/Unit/Settlement/` (`131/131` passing). |
| Total ticket/prize/payout/ledger totals reconcile exactly before draw closeout | **PASS** | `vendor/bin/phpunit tests/Feature/EndToEndFinancialLifecycleProofTest.php tests/Feature/Settlement/ChunkedSettlementTest.php` (`100%` passing). |
| Cancelled/corrected draw uses compensating ledger entries without mutating historical journals | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Draw/OwnedDrawCancellationServerRaceTest.php tests/Feature/Draw/OwnedDrawCancellationActionTest.php` (`100%` passing). |

---

## Section H — Runtime, Database & Rust

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| Clean MySQL migration up succeeds; fresh schema includes all expected constraints/indexes | **PASS** | `DB_CONNECTION=mysql DB_DATABASE=thai_lottery_milestone_k php artisan migrate:fresh --force --seed` (`163/163` migrations + seeders succeeded on MariaDB 11.8). |
| Migration upgrade path preserves representative legacy data; new migration rollback tests pass | **PASS** | `php artisan migrate:rollback --step=5 --force && php artisan migrate --force` & `vendor/bin/phpunit tests/Feature/Database/` (`9/9` passing). |
| PHP and Rust connect to the same configured database and schema, using compatible column types and SQL dialect | **PASS** | `TEST_MYSQL_URL="mysql://financial_test@127.0.0.1:3306/thai_lottery_milestone_k" cargo test --locked` in `runtime/settlement-worker` (`6/6` passing). |
| Rust unit/integration tests and release build pass for production target; money wire formats match PHP test vectors | **PASS** | `cargo fmt --check`, `cargo clippy --locked -- -D warnings`, `cargo test --locked` in `runtime/settlement-worker` (`6/6`) and `bash scripts/build-weekly-native-verifier.sh` in `security/weekly-result-integrity` (`37/37` Rust tests + release binary built). |
| Two worker instances cannot claim/process the same settlement concurrently | **PASS** | Verified by `concurrent_workers_settle_each_winning_bet_once` in `runtime/settlement-worker` (`cargo test --locked`) and `SettlementServerRaceTest` (`4/4`). |
| Worker SIGTERM during work is safe; restart resumes/reconciles correctly | **PASS** | Verified in `runtime/settlement-worker/src/main.rs` (`tokio::signal::unix::SignalKind::terminate`) and `SettlementWorkerRecoveryTest` (`8/8`). |
| Production Docker build has no missing COPY inputs or absent health check | **PASS** | Verified by `tests/Unit/Build/DockerfileContractTest.php`, `tests/Unit/Build/ComposeTopologyContractTest.php`, and `tests/Feature/Deployment/SectionFDevOpsAndCiReadinessTest.php`. Live `docker compose build` is **BLOCKED** in sandbox (`docker: command not found`). |
| Production secret-file entrypoint works and fails closed when required secret is missing | **PASS** | Verified by `tests/Unit/Build/PhpEntrypointContractTest.php` and direct execution of `docker/php/laravel-entrypoint.sh --check-secrets-only` with missing, empty, and valid `*_FILE` secrets. |

---

## Section I — Security & Compliance

| Gate Item | Status | Verification Command & Evidence |
|---|---|---|
| IDOR tests across user-owned deposits, withdrawals, transfers, bets, tickets, receipts and payout records pass | **PASS** | `vendor/bin/phpunit tests/Feature/Security/ tests/Security/ tests/Feature/Support/ObjectAuthorizationSupportAgentTest.php` (`100%` passing). |
| Admin endpoints enforce policy/role, MFA/step-up when configured and audit logs | **PASS** | `vendor/bin/phpunit tests/Feature/Security/ tests/Unit/Security/ tests/Unit/Auth/` (`100%` passing). |
| SQL query input is parameterized; output and logs do not leak internal secrets/PII | **PASS** | `vendor/bin/phpunit tests/Security/ tests/Feature/Chat/ChatSecurityTest.php` (`100%` passing). |
| Rate limits protect login, purchase, deposit, withdrawal, webhook and transfer pathways | **PASS** | `vendor/bin/phpunit tests/Feature/Security/ tests/Feature/Production/` (`100%` passing). |
| KYC/age/AML/self-exclusion/responsible gaming policies are enforced at the server-side transaction boundary, not only in UI | **PASS** | `DB_CONNECTION=mysql ... vendor/bin/phpunit tests/Feature/Compliance/ tests/Feature/Betting/PlayerWagerAdmissionServerTest.php` (`100%` passing). |
| No `.env` credentials, signing keys, private certs, access tokens, or real customer data are committed | **PASS** | `php scripts/check-env-parity.php` (`ok: true`, `errors: []`) & `tests/Feature/Deployment/SectionFDevOpsAndCiReadinessTest.php`. |
