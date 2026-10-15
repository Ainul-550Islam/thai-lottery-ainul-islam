# Financial Integrity Report

**Repository:** `thai-lottery-ainul-islam`
**Status:** controls implemented and unit-proven; **not runtime-verified**

---

## Scope

Financial integrity here means a specific, checkable claim: **every change to a
balance is expressed as a movement of a positive amount in a known direction,
recorded in a ledger, and reconcilable afterwards.** This document records
which of the controls supporting that claim exist in code, and what has and has
not been established about them.

## Controls that exist and are proven by the local suite

| Control | Where | What it prevents |
|---|---|---|
| No arbitrary balance writes | `App\Services\Finance\WalletService` exposes `credit()`, `debit()`, `lockFunds()`, `unlockFunds()`, `lockWallet()`, `unlockWallet()` — there is no `setBalance()` and no `forceBalance()` | A balance being set to a value that no movement explains, which is unreconcilable by construction |
| Financial state is not mass assignable | `Wallet::$fillable` carries only `user_id`, `type`, `currency`; the status, the six running totals and `version` are set by `Wallet::booted()` | Balances and totals being written from any array that reaches `create()` or `update()` |
| Optimistic locking is 1-based everywhere | `Wallet::INITIAL_VERSION`, plus migration `2026_10_01_120000_set_wallets_version_default_to_one` | A wallet born at version 0, against which the first compare-and-swap matches no rows — a lost update that reports success |
| Exact decimal arithmetic | `App\Services\Finance\Money` and BCMath throughout; `GloStampDutyCalculator` uses `bcdiv`/`bcsub` at fixed scale | Float rounding entering a payout |
| Idempotent money-in | `ProcessPaymentWebhookService` derives `whk:{provider}:{externalId}` from provider-supplied values only, backed by a UNIQUE index on `financial_transactions.idempotency_key` | A retried provider delivery crediting a wallet twice |
| Unknown webhook statuses fail closed | `ProcessPaymentWebhookService::CREDIT_STATUSES` is an explicit allow-list | Money being created on the strength of a status string nobody has read |
| Transaction types are never guessed | `WalletService::resolveTypeAndOptions()` records unrecognised free text as a description against an `Adjustment`, never as a `Deposit` | A human description being recorded on the ledger as an assertion that external money arrived |
| Reversals cannot be improvised | `FinancialTransactionService::execute()` rejects `FinancialTransactionType::Reversal` outright | A reversal that does not mirror its original transaction and entries |
| Withdrawal references and net amounts always exist | `Withdrawal::booted()` generates `reference_number` and computes `net_amount = amount − fee` with `bcsub` | Unreferenceable payouts, and NOT NULL failures that previously made the model unusable |
| Database-level invariants | CHECK constraints `locked_balance >= 0`, `locked_balance <= balance`, `net_amount >= 0` | An application bug writing a state the domain forbids |

## What has **not** been established

| Gap | Why it matters |
|---|---|
| No runtime verification | Every statement above is proven against SQLite under the test kernel. Concurrency behaviour, lock contention and deadlock retry under MariaDB have not been observed. See `RUNTIME-VERIFICATION-REPORT.md`. |
| No hosted CI success | 11 workflow runs, 0 green. No independent reproduction. |
| Reconciliation is unscheduled | `finance:reconcile` exists and `FinancialReconciliationService::reconcileSystem()` works, but nothing runs it on a schedule, so no discrepancy would be noticed on its own. |
| Real-money payout path is deferred | 11 jobs are correctly deferred rather than implemented. The payout lane is gated by `finance.prize_payout.safety_mode`. |
| `GROUP_CONCAT` truncation risk | MySQL truncates `GROUP_CONCAT` silently at `group_concat_max_len` (default 1024 bytes). A reconciliation query relying on it returns incomplete data **without failing**. Documented in the audit; not yet guarded. |

## Open test defects affecting this area

Four assertions in the money suites were reported rather than accommodated,
because satisfying them would have weakened a control:

1. Six calls pass a string where **both** `WalletService` classes declare
   `array $options`. There is no safe coercion; the signature was not loosened.
2. `GloPrizeClaimService::approveClaim($id, $note)` supplies **no approver
   identity**. Implementing it would destroy the four-eyes control on prize
   payouts.
3. Four calls attempt to mass-assign `kyc_status` and `created_at` — KYC state
   and audit timestamps. Both are deliberately not fillable.
4. Several assertions compare a backed enum against a raw string; casting
   `status` to an enum is the correct behaviour.

## Conclusion

The financial controls described above are implemented, documented and proven
by the local suite. **That is not a statement that the system is safe to take
real money with.** It cannot be, until the runtime gaps in
`RUNTIME-VERIFICATION-REPORT.md` are closed and reconciliation runs on a
schedule.
