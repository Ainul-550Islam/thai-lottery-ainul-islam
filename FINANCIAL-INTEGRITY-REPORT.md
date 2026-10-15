# Financial integrity report

**Status:** NOT SIGNED OFF. No production accounting approval is implied by this document.

## Acceptance invariants

- Every wallet mutation is performed through the canonical wallet/financial-transaction services and produces balanced ledger entries.
- Required money-moving transaction types use durable idempotency keys; retries cannot duplicate credits, debits, or payouts.
- Settlement runs are durable and resumable, persist payout provenance and policy fingerprints, roll back failed chunks, and require an authorized actor plus an audit reason for recovery.
- Reconciliation is read-only with respect to accounting history and detects ledger imbalance, payout amount tampering, and settlement-run provenance tampering.
- Result provenance remains bound to the official GLO source; fixture-only results are never promoted to official output or paid as verified results.

## Verification state

The most recent recorded full suite before this remediation pass contained 34 errors and 18 failures, with 2 skipped tests. It did not meet the requested 100% passing threshold. The settlement acceptance criteria remain release-blocking until the full suite and focused settlement/reconciliation regressions pass on the current source tree.
