# TYPE: Financial integrity report
# PURPOSE: Map Pages 259–294 and Pages 319–337 to existing canonical finance, payment, betting, prize, and commission architecture without inventing runtime evidence.

## Boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

The PHP/Laravel test runtime, database, queue, and external payment providers are unavailable in this workspace. The report below distinguishes source-level architecture from executed financial evidence.

## Canonical financial architecture

| Concern | Canonical implementation | Source-level observation | Runtime result |
|---|---|---|---|
| Money arithmetic | `app/Services/Finance/Money.php` | Exact decimal arithmetic is the existing money authority. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Wallet operations | `app/Services/Finance/WalletService.php`, `WalletHoldService.php`, `WalletReservationService.php` | Existing wallet, hold, reservation, and owner-scoped services are reused. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Ledger posting | `app/Services/Finance/LedgerPostingService.php`, `LedgerBalanceValidator.php` | Existing double-entry validator and posting services remain authoritative. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Reconciliation | `app/Services/Finance/FinancialReconciliationService.php`, `app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php` | Existing read-only reconciliation command has bounded period, currency, batch, JSON, and dry-run options. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit | `DepositService.php`, `DepositApprovalService.php`, `DepositCompletionService.php` | Deposit credit is intended to follow verified payment state and ledger posting. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Payment callbacks | `PaymentCallbackService.php`, `PaymentWebhookService.php`, `PaymentWebhookVerificationService.php` | Signature, replay, and callback services exist; no provider success is inferred here. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal | `WithdrawalService.php`, `WithdrawalApprovalService.php`, `WithdrawalCompletionService.php`, `WithdrawalKycGateService.php` | Hold, KYC gate, approval, provider, completion, and ledger architecture exists. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Betting | `BetPurchaseService.php` and the canonical betting service family | Purchase pipeline and atomicity tests exist. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Prize settlement | `RealPrizeSettlementService.php`, `GloPrizeClaimService.php`, `PayoutApprovalService.php`, `PayoutBatchService.php` | Prize and payout paths remain canonical; no result or payout is fabricated. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Commission | `AgentCommissionService.php`, `CommissionCalculationService.php`, `AgentCommissionSettlementService.php` | Commission and settlement services/tests already exist. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Financial mutation matrix

| Page | Operation | Source of truth | Atomicity / idempotency requirement | Runtime evidence |
|---:|---|---|---|---|
| 259 | Transaction boundary audit | Canonical finance/payment/betting services | DB transaction plus ledger treatment must be observed in execution. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 260 | Concurrent submissions | Existing atomicity/idempotency tests and canonical services | No double debit, double credit, duplicate issuance, or duplicate payout. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 261 | Wallet activation | `WalletService`, wallet models, ledger | Presentation must not become balance authority. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 262 | Balance rebuild/audit | `FinancialReconciliationService`, `LedgerBalanceValidator` | Wallet, ledger-derived, and locked values must be compared without silent repair. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 263 | Concurrent debit | Wallet locking/reservation and bet purchase services | One sufficient balance cannot fund two successful debits. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 264 | Hold/release | `WalletHoldService`, `WalletReservationService` | Holds require explicit terminal state and audit. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 265 | Reservation expiry | `WalletReservationService` | Expiry/release must be idempotent and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 266 | Financial idempotency | `IdempotencyService`, payment/betting idempotency services | Stable references and duplicate-safe replay. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 267 | Ledger posting | `LedgerPostingService`, `LedgerBalanceValidator` | No wallet change without required ledger treatment. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 268 | Ledger reversal | `FinancialReversalService` | Original history remains immutable and reversal references original. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 269 | Reconciliation execution | `finance:reconcile` | Read-only reconciliation report with persisted audit evidence. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 270 | Reconciliation lifecycle | `FinancialReconciliationService` and discrepancy DTOs/enums | Detected, reviewed, accepted/corrected, and resolved states must remain auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 271 | Deposit flow | Payment initiation, callback verification, deposit completion, wallet, ledger | No credit before verified callback. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 272 | Duplicate callback | Webhook verification/idempotency services | Replays have one financial effect. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 273 | Callback ownership | Authenticated payment projection | Session/ownership, not query parameters, controls access. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 274 | Signature validation | `PaymentWebhookVerificationService` | Valid, invalid, tampered, replayed, stale, and malformed cases. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 275 | Provider errors | Gateway manager and adapters | Timeout, provider errors, malformed response, missing transaction, duplicate, and currency mismatch. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 276 | Payment states | Canonical payment enums and services | Only legal enum transitions. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 277 | Payment events | Payment webhook/event models and audit path | Every callback persists the expected internal event state. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 278 | Webhook queue | Queue/job architecture | Queued, retry, success, failure, and terminal failure are observable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 279 | Dead-letter handling | Failed-job and provider-operation architecture | Permanent failures cannot silently disappear. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 280 | Payment replay | Idempotency and webhook services | Safe replay cannot duplicate wallet effects. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 281 | Withdrawal flow | Withdrawal, KYC gate, hold, approval, disbursement, ledger | Hold and payout are one canonical lifecycle. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 282 | Duplicate withdrawal | Withdrawal idempotency and wallet hold | No double hold or payout. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 283 | Withdrawal KYC | `WithdrawalKycGateService` | Verified, pending, failed, and expired states are tested against real records. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 284 | Restriction behavior | Responsible gaming, self-exclusion, compliance services | Only domain-supported restrictions are enforced. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 285 | Withdrawal failure | Completion/reversal/hold services | Provider failure has exact recovery and audit. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 286 | Withdrawal reconciliation | Payout reconciliation and ledger | Provider success must reconcile internally. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 287 | Bet purchase | Canonical purchase pipeline | Selection through ticket, wallet, ledger, and confirmation is atomic. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 288 | Price authority | Server-side betting calculation | Browser price cannot override server price. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 289 | Currency authority | `Currency` enum and payment/betting validators | Unsupported currency is rejected before mutation. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 290 | Betting limits | Responsible gaming and risk services | Single bet, daily wager, restriction, and self-exclusion rules. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 291 | Concurrent betting | Purchase transaction, wallet lock/reservation | One balance cannot satisfy two incompatible successful purchases. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 292 | Bet idempotency | Bet purchase idempotency service | Same idempotency key produces one purchase. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 293 | Bet rollback | Transaction and reservation services | Failure after reservation cannot strand funds. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 294 | Ticket issuance | Bet purchase ticket service | Successful purchase has canonical ticket ownership and no orphan ticket. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 319 | Claim creation | GLO claim service and official result state | Only valid winning ticket/result creates a claim. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 320 | Claim duplicate | GLO claim idempotency/state architecture | One canonical claim. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 321 | Age gate | GLO claim service and date-of-birth migration | Actual age state is required. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 322 | KYC claim gate | KYC verification service | Actual KYC status controls eligibility. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 323 | Payment hold | GLO payment hold model/service | Hold blocks payout until conditions are met. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 324 | Ticket freeze | GLO freeze service/command | Freeze lifecycle is explicit and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 325 | Freeze release | GLO freeze expiry/release command | Release follows canonical domain rules. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 326 | Frozen winner processing | `glo:process-frozen-winners` | Command must be executed against controlled records. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 327 | Public result publication | GLO result publication service | Published state derives from canonical publication. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 328 | Prize matching | Result, winning-rule, ticket, and prize-match services | Frontend never calculates winning outcome. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 329 | Prize settlement | Prize settlement, payout, and ledger services | Gross, deductions, net, settlement, and ledger agree. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 330 | Payout idempotency | Payout approval/batch/payment services | Duplicate payout cannot pay twice. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 331 | Payout failure | Claim state, reversal, provider operation, audit | Claim remains recoverable and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 332 | Commission accrual | Agent commission service | Qualifying event creates one canonical accrual. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 333 | Commission duplicate | Commission idempotency and reversal services | Duplicate event cannot accrue twice. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 334 | Commission settlement | Agent settlement service | Commission payment/settlement is traceable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 335 | Referral attribution | Agent referral service | Actual canonical referral relation is authoritative. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 336 | Agent owner isolation | Agent portal and policy | Cross-agent access is denied. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## No unsupported financial claims

No deposit, payment, wallet credit, bet, withdrawal, refund, prize, claim, payout, commission, treasury balance, or provider settlement is reported as successful by this phase.

## Pages 351–450 financial runtime continuation

### Pages 358–366 — Database and concurrency

Database connectivity, isolation, constraint execution, deadlock handling, wallet locks, and concurrent payout tests were not executable because PHP, Laravel, and the controlled database are unavailable.

The source architecture continues to use the existing exact-money, transaction, lock, reservation, ledger, and idempotency services. No new financial authority was introduced.

### Pages 367–376 — Redis, queue, scheduler

Redis CLI, worker startup, queue retry, failed-job persistence, queue recovery, scheduler listing, and scheduler execution are blocked. No queue acknowledgement, retry, failed-job recovery, or scheduled financial command is reported as successful.

### Pages 401–425 — Provider, finance, and reconciliation activation

The following flows remain unexecuted:

| Flow | Required observation | Status |
|---|---|---|
| Payment provider method matrix | Enabled provider, currency, amount bounds, and operation capability | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit initiation | Server validation, provider initiation, and pending state | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit callback | Verified callback before wallet credit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit replay | One financial effect across repeated callbacks | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit mismatch | Amount, currency, reference, and owner rejection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal request | Validation, KYC/RG gate, and wallet hold | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal approval | Canonical operator policy and audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Provider payout | Controlled transfer and callback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal replay/failure | One payout and exact recovery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Financial end-to-end | Deposit, wallet, bet, result, prize, withdrawal, ledger | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Wallet audit | Wallet, holds, and ledger-derived amount | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Reconciliation | Real persisted reconciliation report and discrepancy lifecycle | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Bet purchase | Server pricing, fee, RG, wallet, reservation, ticket, ledger | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Prize payout | Canonical settlement and one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| GLO L6 capability | Real configured purchase contract or retained NOT_CONFIGURED state | NOT_CONFIGURED unless canonical capability is enabled |

No payment, deposit, credit, debit, bet, withdrawal, payout, reconciliation, or GLO purchase completion is claimed.

## Pages 451–550 financial runtime continuation

The command gate attempted the provider, deposit, callback, wallet, withdrawal, reconciliation, betting, prize, and payout test targets listed for Pages 497–531. PHP, Laravel, database, queue, provider, and Rust dependencies were unavailable, so these flows remain:

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

No payment provider sandbox call, deposit, wallet credit/debit, withdrawal, bet, ticket, prize settlement, payout, commission, or reconciliation mutation was fabricated.

The destructive database gate for Pages 465–466 returned `NOT_APPLICABLE` because the controlled-environment flag was not enabled. No production database was touched.
