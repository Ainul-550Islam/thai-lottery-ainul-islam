# TYPE: Pages 351–450 production runtime matrices
# PURPOSE: Map every acceptance page to its execution target and preserve route/API/security/finance/lottery/Rust/runtime boundaries.

## Acceptance page ledger

| Page | Title | Route / Command / Test Target | Status | Runtime Status | Remaining Gap |
|---:|---|---|---|---|---|
| 351 | Runtime Dependency Closure | scripts/pages_351_450_runtime_preflight.py | PARTIALLY VERIFIED | PARTIALLY VERIFIED — PRE-FLIGHT ONLY | Required runtimes remain unavailable |
| 352 | PHP Runtime Activation | php -v; php -m; php --ini | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP version and extensions unavailable |
| 353 | Composer Activation | composer validate; composer install; composer check-platform-reqs | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Composer platform verification unavailable |
| 354 | Laravel Container Activation | php artisan about | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Container boot unavailable |
| 355 | Route Runtime Activation | php artisan route:list | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Duplicate/parameter/middleware runtime check unavailable |
| 356 | Configuration Runtime Audit | php artisan config:show | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Cached configuration state unavailable |
| 357 | Application Cache Safety | php artisan optimize:clear; config:cache; route:cache; view:cache | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Cache safety unavailable |
| 358 | Database Runtime Connection | Laravel database probe | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | No connection observed |
| 359 | Database Schema Baseline | php artisan migrate:status | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Applied/pending state unavailable |
| 360 | Migration Compatibility | controlled migration environment | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Compatibility unverified |
| 361 | Fresh Database Build | controlled test database | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Fresh build unavailable |
| 362 | Production-Like Database Build | controlled staging database | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Production-like schema unavailable |
| 363 | Database Seed Safety | database/seeders | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Seed execution unavailable |
| 364 | Database Constraint Runtime Tests | duplicate payments/tickets/claims/webhooks/commissions | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Constraint execution unavailable |
| 365 | Transaction Isolation | financial concurrency tests | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Isolation unavailable |
| 366 | Deadlock Handling | controlled lock contention | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Deadlock scenario unavailable |
| 367 | Redis Runtime | redis-cli --version; Redis probe | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Redis unavailable |
| 368 | Redis Lock Integrity | wallet/bet/payment/draw locks | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Distributed lock unavailable |
| 369 | Cache Isolation | public/private cache inspection | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Cache inspection unavailable |
| 370 | Queue Driver Activation | dispatch to worker | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Worker unavailable |
| 371 | Queue Worker Startup | controlled worker | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Worker unavailable |
| 372 | Queue Retry Policy | job definitions | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Runtime retry unavailable |
| 373 | Failed Jobs | php artisan queue:failed | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Failed job persistence unavailable |
| 374 | Queue Recovery | worker restart with pending job | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Recovery unavailable |
| 375 | Scheduler Runtime | php artisan schedule:list | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Schedule list unavailable |
| 376 | Scheduler Execution | php artisan schedule:run | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Scheduler unavailable |
| 377 | Lottery Automation Runtime | app/Console/Commands/Lottery/TickCommand.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 378 | Draw Scheduling Runtime | app/Services/Draw/DrawScheduleService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 379 | Draw Opening Runtime | app/Services/Draw/DrawLifecycleService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 380 | Draw Closing Runtime | app/Http/Middleware/EnsureDrawIsOpen.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 381 | Draw Settlement Queue | app/Services/Draw/DrawSettlementSimulationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 382 | Result Import Runtime | app/Services/Draw/DrawResultIngestionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 383 | Historical Data Import Framework | app/Services/Lottery/Support/AbstractLotteryImportService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 384 | Historical Data Provenance | app/Models/GloResultImport.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 385 | Historical Import Rollback | app/Services/Draw/DrawResultIngestionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 386 | National Lottery Historical Import | app/Services/Lottery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 387 | Weekly Lottery Historical Import | app/Services/Lottery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 388 | PCSO Historical Import | app/Services/Lottery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 389 | GLO L6 Historical Import | app/Services/Lottery/GloResultImportService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 390 | Historical Result Reconciliation | app/Services/Draw/DrawReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 391 | Public Result API Runtime | routes/api.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 392 | Result Search Runtime | routes/api.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 393 | Result Detail Runtime | routes/api.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 394 | Year Archive Runtime | routes/web.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 395 | Result Publication Runtime | app/Services/Draw/DrawResultPublicationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 396 | Result Correction Runtime | app/Services/Draw/DrawResultConfirmationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 397 | Result Conflict Runtime | app/Services/Draw/DrawResultValidator.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 398 | Result Certification Runtime | app/Services/Draw/DrawCertificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 399 | Public Cache Invalidation | app/Services/Draw/DrawResultPublicationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 400 | Result Integrity Gate | RUNTIME-VERIFICATION-REPORT.md | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 401 | Payment Provider Runtime Activation | app/Services/Payment/PaymentGatewayManager.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 402 | Payment Method Availability | app/Services/Payment/PaymentProviderRegistry.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 403 | Deposit Initiation Runtime | app/Services/Payment/PaymentInitiationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 404 | Deposit Callback Runtime | app/Services/Payment/PaymentCallbackService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 405 | Deposit Completion Runtime | app/Services/Finance/DepositCompletionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 406 | Deposit Replay | tests/Feature/Payment/WebhookReplayProtectionTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 407 | Deposit Mismatch | app/Services/Payment/PaymentVerificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 408 | Payment Timeout Recovery | app/Services/Payment/PaymentInitiationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 409 | Payment Failure Recovery | app/Services/Payment/PaymentCallbackService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 410 | Payment Reconciliation | app/Services/Payment/PaymentReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 411 | Withdrawal Runtime Activation | app/Services/Finance/WithdrawalService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 412 | Withdrawal Wallet Hold | app/Services/Finance/WalletHoldService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 413 | Withdrawal Approval | app/Services/Finance/WithdrawalApprovalService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 414 | Withdrawal Provider Transfer | app/Services/Payment/WithdrawalDisbursementService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 415 | Withdrawal Provider Callback | app/Services/Payment/PaymentCallbackService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 416 | Withdrawal Replay | tests/Feature/Payment/WithdrawalCompletionTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 417 | Withdrawal Failure | app/Services/Finance/WithdrawalCompletionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 418 | Withdrawal Reconciliation | app/Services/Finance/PayoutReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 419 | Withdrawal Exception Queue | app/Services/Queue/QueueHealthService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 420 | Financial End-to-End Gate | FINANCIAL-INTEGRITY-REPORT.md | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 421 | Wallet Balance Runtime Audit | app/Services/Finance/FinancialReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 422 | Financial Replay Audit | tests/Feature/Payment/WebhookReplayProtectionTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 423 | Concurrent Financial Operations | tests/Feature/Betting/BetPurchaseAtomicityTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 424 | Financial Reconciliation Report | app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 425 | Financial Exception Resolution | app/Services/Finance/FinancialReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 426 | Bet Purchase Runtime | app/Services/Betting/BetPurchaseService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 427 | Bet Price Enforcement | app/Services/Betting/BetCalculationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 428 | Bet Fee Enforcement | app/Services/Finance/FeeCalculationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 429 | Bet Responsible Gaming Gate | app/Services/Betting/BetPurchaseRiskService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 430 | Bet Idempotency Runtime | app/Services/Betting/BetPurchaseIdempotencyService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 431 | Bet Concurrency Runtime | tests/Feature/Betting/BetPurchaseAtomicityTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 432 | Ticket Issuance Runtime | app/Services/Betting/BetPurchaseTicketService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 433 | Ticket Ownership Runtime | app/Services/Ticket/TicketOwnershipService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 434 | Ticket Verification Runtime | app/Services/Betting/TicketVerificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 435 | Ticket QR Runtime | app/Services/Betting/TicketShareService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 436 | Prize Matching Runtime | app/Services/Draw/SelectionSettlementResolver.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 437 | Prize Settlement Runtime | app/Services/Draw/RealPrizeSettlementService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 438 | Prize Payout Runtime | app/Services/Finance/PayoutApprovalService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 439 | Prize Payout Replay | tests/Feature/Glo/GloPrizeClaimTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 440 | GLO L6 Purchase Capability Reassessment | app/Services/Lottery/GloL6PurchaseCapabilityService.php | NOT_CONFIGURED | NOT_CONFIGURED | Re-evaluate only after a real canonical purchase capability and provider are configured; do not invent checkout. |
| 441 | GLO L6 Ticket Engine Runtime | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 442 | GLO L6 Prize Calculator Runtime | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 443 | GLO Claim Runtime | app/Services/Lottery/GloPrizeClaimService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 444 | GLO Claim Security | app/Services/Compliance/KycVerificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 445 | GLO Freeze Runtime | app/Services/Lottery/GloTicketFreezeService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 446 | GLO Freeze Expiry | app/Console/Commands/GloExpireFreezes.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 447 | GLO Frozen Winner Processing | app/Console/Commands/GloProcessFrozenWinners.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 448 | GLO Public Publication | app/Services/Lottery/GloResultPublicationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 449 | GLO End-to-End Integrity | LOTTERY-INTEGRITY-REPORT.md | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 450 | Enterprise Production Acceptance Gate | scripts/pages_351_450_command_gate.py | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |

## Route and API matrix

| Page range | Boundary | Required evidence | Status |
|---|---|---|---|
| 351–357 | PHP, Composer, Laravel boot, route listing, configuration and cache commands | Execute CLI and inspect route/middleware/cache output | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 358–376 | Database, migrations, Redis, queue, scheduler | Connect controlled services; run schema, transaction, lock, worker, retry, and scheduler commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 377–400 | Draw lifecycle, result import, historical provenance, certification, publication, correction, conflict, public APIs | Execute canonical draw/result pipeline with genuine source data or isolated test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 401–425 | Payment providers, deposits, callbacks, withdrawals, payout, reconciliation, wallet/ledger | Execute configured provider callbacks, replay/mismatch/failure cases, and ledger reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 426–439 | Bet, responsible gaming, idempotency, concurrency, ticket, prize, payout | Execute server-authoritative purchase-to-settlement flows with controlled test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 440–449 | GLO capability, ticket engine, prize, claim, age/KYC/freeze/publication | Reassess capability and execute only canonical GLO services; no invented checkout | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 450 | Enterprise gate | Require every underlying evidence package before acceptance | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Security matrix

| Control | Pages | Evidence required | Status |
|---|---:|---|---|
| Authentication, session rotation, MFA, revocation | 351–357, 401–449 | PHP/browser login, session, MFA, replay, and revocation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| CSRF, IDOR, ownership, authorization | 355, 391–394, 401–449 | Guest/role/cross-owner/cross-case/cross-ticket denial tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Webhook signature, replay, mismatch, timeout | 401–418 | Provider sandbox callback matrix and idempotency assertions | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rate limits and abuse controls | 391–394, 401–449 | Throttled endpoint and retry metadata tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Secret/log/error redaction | 351–357, 400, 450 | Runtime logs and error responses contain no secret or unsafe internal data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| KYC, age, freeze, responsible gaming | 411–413, 426–449 | Denial/allowance tests for financial and GLO sensitive states | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Financial integrity matrix

| Pages | Flow | Required invariant | Status |
|---:|---|---|---|
| 358–366 | Database and transaction | Exact money, atomicity, constraints, isolation, rollback, no double spend | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 367–376 | Redis, queue, scheduler | Lock ownership, idempotent retry, failed-job recovery, single scheduled effect | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 401–410 | Deposits | Verified provider event before one wallet credit; replay/mismatch rejection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 411–419 | Withdrawals | Hold before payout, authorization, one transfer, exact reversal/reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 420–425 | End-to-end finance | Wallet/ledger balances and reconciliation remain consistent | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 426–439 | Bets and prizes | Server price, fee, RG gate, idempotency, one ticket, one settlement/payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Lottery integrity matrix

| Pages | Pipeline | Required invariant | Status |
|---:|---|---|---|
| 377–381 | Draw lifecycle | Timezone, cutoff, state transition, settlement dispatch, duplicate prevention | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 382–390 | Import and provenance | Genuine source, exact values, duplicate/conflict detection, versioned rollback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 391–400 | Public result surface | Certified/published only, bounded search, safe correction and cache invalidation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 426–439 | Bet/ticket/prize | Ticket ownership, verification, matching, settlement, one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 440–449 | GLO L6 | Canonical capability, exact ticket/prize calculation, claims, freeze, publication | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Rust integrity matrix

| Page | Boundary | Required evidence | Status |
|---:|---|---|---|
| 351 | Cargo/rustc preflight | Executable availability and version | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 400 | Result/integrity boundary | Rust output validated before canonical publication | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 420 | Financial acceptance | No Rust output may authorize money without Laravel validation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 449 | GLO integrity | Leading-zero and malformed-input vectors through the real boundary | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 450 | Release build | `cargo check --locked`, `cargo test --locked`, `cargo build --release --locked` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Runtime and release matrix

| Area | Evidence artifact | Result |
|---|---|---|
| Dependency preflight | `runtime/page-351-preflight.json` | PARTIALLY VERIFIED — preflight executed; required runtimes missing |
| Command gate | `runtime/pages-351-450-command-results.json` | 1 command executed successfully; 25 blocked |
| NPM remediation | `runtime/page-351-dependency-remediation.json` | VERIFIED locally: `npm ci`, `npm audit`, and `npm run build` exit 0 |
| PHP/Laravel | `RUNTIME-VERIFICATION-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Database | `DATABASE-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Finance | `FINANCIAL-INTEGRITY-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Lottery/GLO | `LOTTERY-INTEGRITY-REPORT.md` | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rust | `RUST-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Security | `SECURITY-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| CI/CD source | `CI-CD-VERIFICATION-REPORT.md` | PARTIALLY VERIFIED — workflow source updated; hosted run not executed |
| Enterprise acceptance | Page 450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
