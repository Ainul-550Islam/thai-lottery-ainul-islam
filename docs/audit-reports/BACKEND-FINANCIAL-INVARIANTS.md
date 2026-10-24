# BACKEND FINANCIAL INVARIANTS

This document specifies the enforced financial, accounting, concurrency, idempotency, state-transition, reversal, and reconciliation invariants of the backend.

---

## 1. Canonical Money & Arithmetic Invariants

1. **Exact Decimal Representation (`App\Services\Finance\Money`):**
   - All monetary values are represented as canonical scale-2 decimal strings (`DECIMAL(15,2)` / `DECIMAL(18,2)` in SQL, `rust_decimal::Decimal` in the Rust settlement worker, and BCMath strings in PHP).
   - `Money::assertExactArithmeticIsAvailable()` verifies `ext-bcmath` before every financial operation.
   - Floating-point (`float` / `double`) arithmetic is forbidden in all wallet, ledger, fee, tax, conversion, transfer, betting, commission, and prize calculation paths (`SettlementSafetyAuditTest`, `NonMonetarySettlementTest`).
2. **Currency Isolation:**
   - Every `Money` value carries an explicit `App\Enums\Currency` (`THB`, `BDT`, `USD`).
   - Arithmetic across mismatched currencies throws `CurrencyMismatchException` before any database write.
   - Cross-currency movement is permitted only through `WalletConversionService` using a locked, unexpired `WalletConversionQuote` and balanced per-currency clearing journals.

---

## 2. Single Wallet Write Boundary & Double-Entry Ledger Invariants

1. **Authoritative Ledger (`ledger_entries` & `ledger_accounts`):**
   - Every completed `FinancialTransaction` that changes a wallet's spendable balance posts a balanced set of immutable `LedgerEntry` rows (`SUM(debit) === SUM(credit)` per currency and transaction).
   - `LedgerPostingService` and `LedgerBalanceValidator` verify journal balance and account active status inside the same database transaction before commit.
   - `LedgerEntry` and `LedgerPostingObserver` refuse `UPDATE` and `DELETE` on posted ledger lines (`ImmutableLedgerException`).
2. **Single Wallet Write Boundary (`MoneyWriteCoverageService`):**
   - Direct writes to `wallets.balance`, `wallets.locked_balance`, `wallets.total_deposited`, `wallets.total_withdrawn`, `wallets.total_wagered`, and `wallets.total_won` are restricted exclusively to `App\Services\Finance\WalletService` and `App\Services\Finance\WalletHoldService` (plus `AgentOnboardingService` for `agents.float_balance`), enforced by `MoneyWriteCoverageTest`.
   - `wallets.balance >= 0`, `wallets.locked_balance >= 0`, and `wallets.locked_balance <= wallets.balance` are enforced both at the service layer (`InsufficientBalanceException`) and by database `CHECK` constraints (`2026_03_20_100000_add_financial_check_constraints.php`).
3. **Canonical Writer Routing (`FinancialRecordIdentifier`):**
   - Every `Wallet`, `User`, `Deposit`, `Withdrawal`, and `FinancialTransaction` model passed into a financial boundary is validated by `FinancialRecordIdentifier::fromModel()`, which verifies a positive integer primary key, canonical table name, and attachment to the active default database connection (`DB::connection()`).

---

## 3. Transaction Boundaries & Deterministic Lock Ordering

1. **Physical Transaction Verification (`WalletLockService`):**
   - All financial mutations require both `DB::connection()->transactionLevel() > 0` and `DB::connection()->getPdo()->inTransaction() === true` (`assertConsistentTransactionState()`).
   - Ambient mismatched transaction states (e.g., committed/rolled-back PDO with positive framework depth, or raw PDO transaction with zero framework depth) are refused before any routing SQL (`WalletTransactionBoundaryTest`, `ReversalTransactionBoundaryTest`, `LifecycleTransactionBoundaryTest`).
2. **Deterministic Lock Order:**
   - **Multi-wallet operations** (`BalanceTransferService`, `WalletConversionService`, `BetCancellationService`, `BetAmendmentService`): Always lock all participating `wallets` rows in strictly ascending primary-key order (`WalletLockService::lockMany()`, `ORDER BY id ASC FOR UPDATE`) before reading or mutating any child rows.
   - **Player admission & responsible-gaming gates** (`DepositAdmission`, `BetPurchaseAdmission`, `StripeRefundWagerGuard`, `ResponsibleGamingPlayerLock`, `ConversionLimitService`): Lock the canonical player root row (`SELECT id FROM users WHERE id = ? FOR UPDATE`) after or alongside the wallet lock and before performing current-read (`FOR UPDATE`) rolling-window aggregations over `deposits`, `financial_transactions`, or `wallet_conversions`.
   - **Draw risk & number limits** (`DrawRiskAdmission`, `BetPurchaseRiskService`): Lock the `draws` row (`FOR UPDATE`) and the target `number_limits` rows in deterministic `(draw_id, bet_type, number)` order.
   - **Chunked draw settlement** (`ChunkedSettlementOrchestrator`, `RealPrizeSettlementService`, Rust `settlement-worker`): Serialize per-draw execution via `settlement_runs` (`FOR UPDATE`) and queue mutex `WithoutOverlapping('settlement-draw:'.$drawId)`, claiming winning bets atomically (`WHERE status = 'pending'`).

---

## 4. Database-Backed Idempotency Contract

1. **Required Idempotency Keys (`IdempotencyService`):**
   - Every external or retryable financial mutation (`Deposit`, `Withdrawal`, `BetDebit`, `BetRefund`, `PrizeCredit`, `CommissionCredit`, `BalanceTransfer`, `WalletConversion`, `Reversal`) requires a normalised idempotency key of 16–128 ASCII characters (`IdempotencyService::normaliseKey()`), enforced by `UNIQUE` database indexes on `financial_transactions.idempotency_key`, `deposits.idempotency_key`, `withdrawals.idempotency_key`, `balance_transfers.idempotency_key`, `wallet_conversions.idempotency_key`, `bets.idempotency_key`, and `bet_amendments.idempotency_key`.
2. **Replay vs. Conflict Semantics:**
   - Replaying the same idempotency key with identical canonical parameters (wallet, user, currency, amount, type, and request fingerprint) returns the original committed record without creating a second debit, credit, journal, or side effect.
   - Replaying the same idempotency key with any differing canonical parameter throws `IdempotencyConflictException` (or domain-specific `*_request_conflict`) without mutating state.
3. **Provider Reference Uniqueness:**
   - Inbound and outbound provider references are bound once in `financial_provider_reference_owners`, `withdrawal_provider_references`, `outbound_provider_references`, and `payout_transfer_references` under unique `(provider, account_scope, reference)` keys (`DepositReferenceServerRaceTest`, `WithdrawalReferenceServerRaceTest`, `OutboundReferenceServerRaceTest`).

---

## 5. Domain State Machines & Reversal Policy

1. **Deposits (`DepositStatus`):**
   - `Pending -> Approved -> Confirmed` (credits wallet once via `DepositCompletionService` and binds `financial_transaction_id`) or `Pending/Approved -> Rejected/Failed/Cancelled`.
   - Post-settlement refunds/chargebacks execute through `PaymentRefundService` and `StripeRefundRecoveryService`, creating linked compensating debits/holds without erasing the original deposit.
2. **Withdrawals & Holds (`WithdrawalStatus` & `FinancialHoldStatus`):**
   - Requesting a withdrawal atomically places a hold (`locked_balance += amount`) via `WithdrawalRequestWithHoldService` / `WalletHoldService`.
   - `Pending -> Approved -> Processing -> Completed` consumes the hold (`balance -= amount`, `locked_balance -= amount`) and posts the withdrawal debit journal once.
   - `Pending/Approved/Processing -> Rejected/Cancelled/Failed` releases the hold (`locked_balance -= amount`) once.
   - Uncertain provider dispatch outcomes enter `WithdrawalReconciliation` (`pending/reconciliation required`) and never auto-redispatch or auto-release holds until resolved.
3. **Balance Transfers (`BalanceTransferStatus`) & Conversions (`WalletConversionStatus`):**
   - `BalanceTransfer`: `Completed` on atomic sender-debit + recipient-credit + fee routing, and `Reversed` via `BalanceTransferReversalService` / `BalanceTransferService::reverse()` (bounded to once per transfer, linking `reversal_debit_transaction_id` and `reversal_credit_transaction_id`).
   - `WalletConversion`: `Quoted -> Completed` (or `Rejected`/`Expired`), locking the quote slot (`FOR UPDATE`) so a quote can be executed at most once.
4. **Bets, Cancellations, Amendments & Commissions:**
   - Every bet purchase records a sealed `bet_purchase_ownerships` row binding the bet, wallet, number limit, original debit transaction, and SHA-256 terms hash.
   - `BetCancellationService` transitions `bet_purchase_ownerships.state = 'cancelled'`, releases `number_limits.current_amount`, credits the player refund (`bet-refund-{betId}`), and compensates unpaid/paid agent commissions (`agent_commission_compensations`).
   - `BetAmendmentService` composes owned cancellation + replacement purchase under a single non-expiring `bet_amendment_ownerships` claim (`attempting -> applied` or `failed`).
5. **Append-Only Reversal Policy (`FinancialReversalService`):**
   - Completed transactions are never deleted or overwritten.
   - `FinancialReversalService::reverse()` locks the target wallet and original `FinancialTransaction`, verifies `ReversalIntegrityGuard::assertOriginal()`, derives the canonical key `reversal:{originalId}`, posts exact mirror `LedgerEntry` lines, marks the original `TransactionStatus::Reversed`, and persists a new `TransactionType::Reversal` row referencing the original transaction.

---

## 6. Reconciliation & Operational Verification

- **`FinancialReconciliationService` & `LedgerReconciliationService`:** Reconcile `wallets.balance` and `wallets.locked_balance` against `SUM(ledger_entries)` and active `financial_holds` / `wallet_reservations`.
- **`PaymentReconciliationService` & `WithdrawalReconciliationService`:** Detect mismatches between gateway webhook/settlement facts and local `deposits` / `withdrawals` / `payments` rows without silently auto-crediting or auto-releasing disputed funds.
- **`PayoutReconciliationService` & `settlement:verify-guards`:** Verify draw prize totals, `settlement_runs` chunk progress, and payout batch states before draw closeout.
