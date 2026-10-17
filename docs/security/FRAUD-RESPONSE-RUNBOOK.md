# 🚨 Financial Fraud Response & Case Management Runbook

## 1. Trigger Conditions & Case States

When financial transactions (Deposits, Purchases, Withdrawals) trigger high-risk velocity or linkage rules:
- **`OPEN`**: Transaction is placed on provisional hold in the ledger; `FraudReviewRequired` event is emitted.
- **`UNDER_INVESTIGATION`**: A compliance officer has acquired a lock on the case.
- **`RESOLVED_ALLOW`**: Transaction is verified by an authorized officer and released to the payment gateway / ledger.
- **`RESOLVED_DENY`**: Transaction is rejected and funds remain in escrow or refunded.
- **`RESOLVED_ACCOUNT_FROZEN`**: User wallet and account are locked for legal compliance.

---

## 2. Review & Release Maker-Checker Workflow

1. Retrieve pending fraud cases:
```bash
GET /api/v1/admin/security/fraud-alerts?status=OPEN
```
2. Inspect canonical transaction payload, device fingerprint, and IP risk.
3. Submit review decision:
```bash
POST /api/v1/admin/security/fraud-alerts/{id}/review
Content-Type: application/json

{
  "action": "RELEASE_ALLOW",
  "decision_reason": "Verified user identity and valid high-volume betting pattern.",
  "metadata": {
    "checker_note": "Phone confirmation completed"
  }
}
```
4. Audit trail is permanently recorded in `fraud_cases` and `security_audit_logs`.
