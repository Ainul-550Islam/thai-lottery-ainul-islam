# Part88 — refuse false wallet persistence success

## Application defect

WalletService ignored the boolean returned by seven existing model save calls. Eloquent saving/updating listeners may return false to veto persistence without throwing. The service could therefore return a computed balance, a changed reserve or a frozen/unfrozen wallet model even though the persisted wallet was unchanged.

The credit/debit consequence is more severe than a stale response. FinancialTransactionService creates/claims the transaction, applies the wallet movement, posts the journal and marks the transaction completed. When applyCredit/applyDebit silently ignored a refused wallet save, that ordinary orchestrator could commit a completed transaction and balanced ledger without moving the wallet balance or lifetime counters. Part87's ledger-save guard did not cover wallet persistence.

## Implementation

All seven WalletService mutation save sites now delegate to one private persistWallet helper:

- lockFunds: reserve;
- unlockFunds: release;
- lockWallet: freeze;
- unlockWallet: unfreeze;
- applyCredit: credit;
- applyDebit: debit;
- applyReversal: reversal, for both original sides.

The helper captures the admitted canonical wallet ID before model events, invokes the real save once and requires exactly true. Otherwise it throws FinancialException with code `wallet_write_refused` and safe context `{wallet_id: admitted_id, operation: internal_literal}`. No raw payload, balance, reason, listener message or exception chain is exposed. It honors the veto; it does not force a save, suppress events, silently retry, create replacement rows, repair state or return the unpersisted result.

Each existing mutation keeps its exact currency/availability/affordability/max-balance/withdrawal-floor checks, decimal arithmetic, version increment, running-total policy and status/reason/timestamp assignments. Public signatures remain unchanged. Public credit/debit orchestration, account-code routing, WalletLockService root/savepoint policy and every ledger guard remain unchanged. There is no migration, new lock, transaction management or provider integration in the helper.

## Ownership and scope

The helper raises a failure; existing orchestration decides rollback. The original FinancialTransactionService/WalletLockService boundary rolls back its owned root or joined savepoint, so a refused wallet movement leaves no completed target transaction, journal or consumed idempotency claim. A caller's earlier ambient marker remains caller-owned and committable. Direct applyCredit/applyDebit/applyReversal remain internal operations requiring an already-owned live transaction. Reserve/release/freeze/unfreeze keep their pre-existing wrappers.

This does not undo arbitrary listener SQL or earlier caller work outside the owned unit. A direct caller must handle its complete failed money operation, including earlier wallet/financial work. A true Model::save return is not an affected-row witness, an authorization decision, a transaction-generation proof or a sandbox for malicious hooks. Other services writing wallets directly, getOrCreateWallet creation semantics, non-halting event results and arbitrary hooks returning true without intended store state are not changed by this seven-site fix. Existing provider and country-specific deployment gates are not relaxed.

## Verification design

100 feature cases exercise the frozen implementation:

- 64 direct refusals: eight operation branches, saving/updating vetoes, THB/BDT and caller root/savepoint. They require the exact domain code/context, no returned computed amount or altered wallet, unchanged persisted financial book and caller model, one veto, retained caller depth/physical transaction and restored dispatcher. A synthetic caller marker commits independently; no listener SQL is introduced by these tests.
- 16 orchestrated refusals: credit/debit, both veto events, both currencies and owned-root/ambient-savepoint policy. Opening funds are created through the ordinary wallet+ledger API before installing the listener. Rejection must roll back the attempted transaction/journal/idempotency claim while preserving the original funded book and caller control.
- 16 allowed operations: every branch in both currencies, true/null nonrefusing listeners, exact resulting balances, reserve, status, lifetime counters and one increment from the actual initialized wallet version.
- Four retry/replay cases: after the veto is removed, the rolled-back idempotency key remains reusable; credit/debit succeeds once and a replay does not move money or create another journal.

Six exact-original Part87 controls (SQLite/native MariaDB × credit, debit, reserve) run unchanged tests with audited shadow source loading and independent committed reads. The original credit/debit controls falsely complete a target transaction and leave four total journal rows including opening funds, while the wallet remains 100.00 and player-liability ledger balance becomes 110.00/90.00. Healthy controls retain the two opening entries and no target transaction. The original reserve control returns a model claiming 30.00 reserved while storage remains 20.00; the healthy implementation throws instead. All probes are disposable synthetic books, not provider funding or live transfers.

The first pre-freeze focused run incorrectly assumed WalletFactory could initialize version to seven. Wallet's normal creation hook initializes version to one. The test was corrected to assert exactly one increment from the actual admitted version, without changing application arithmetic or weakening the invariant. The initial failed diagnostics are retained separately; the corrected focused run and full suites execute the frozen source. A fresh runtime also required genuine PHP/Composer/MariaDB/Rust and locked Node/npm restoration; installation and real build/test logs are retained, not substituted with generated inventory claims. The initial independent control reader also assumed debit used system cash; the unchanged withdrawal route uses withdrawal-clearing. The reader was corrected to qualify all three chart accounts (cash, clearing and player liability), without changing application or test assertions; that initial harness diagnostic is retained.

All previous case IDs and 112 genuine contention profiles must remain. Measured runtime results and frozen hashes belong to evidence/acceptance-part-88.json. The workflow adds an exact 100-unique-case, positive-assertion, zero-skip/error/failure gate and retains its XML alongside prior gates. Workflow parsing is local, not remote CI. Root reports remain generator-owned STATIC_ONLY. No production restore, external provider transfer, worldwide licensing approval or elimination of all platform defects is claimed.
