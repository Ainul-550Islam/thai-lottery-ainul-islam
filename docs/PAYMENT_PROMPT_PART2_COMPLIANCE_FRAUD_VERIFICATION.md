# Payment Master Prompt — Part 2 Compliance and Fraud Verification

**Verification date:** 2026-11-17  
**Scope:** Every compliance and fraud service explicitly named in the supplied prompt segment. No named file is omitted.

## File-by-file matrix

| Prompt path | Result | Wiring and behavior verified |
|---|---|---|
| `app/Services/Compliance/AmlRiskAssessmentService.php` | PASS | Exists; 294 lines; syntax passed. Measures evidence separately from the durable verdict. Pronouncement uses a database transaction and locks the user, current assessment, and evidence ownership rows. Current-evidence fingerprint verification is exposed through `assertCurrent`. Used by `AmlRiskService` and `ReassessAmlRiskJob`. |
| `app/Services/Compliance/AmlRiskService.php` | PASS | Exists; 70 lines; syntax passed. Canonical façade for measure, pronounce, current-evidence assertion, high-risk, and prohibited decisions. Used by money-flow AML observation. |
| `app/Services/Compliance/AmlScreeningService.php` | PASS | Exists; 269 lines; syntax passed. Implements explicit `off`, `monitor`, and `enforce` policies with per-flow resolution. Registered as a singleton by `FrontendOpsServiceProvider`; covered by enforce-mode regression tests. |
| `app/Services/Compliance/KycVerificationService.php` | PASS | Exists; 281 lines; syntax passed. Pronouncement and expiry use transactions and row locks; creates durable verification evidence and exposes live verdict lookup. Integrated into `FinancialComplianceGateService`. |
| `app/Services/Compliance/KycDocumentService.php` | PASS | Exists; 273 lines; syntax passed. Registration, verification, rejection, and expiry are transaction- and lock-protected. Used by `VerifyPendingKycDocumentsJob`. |
| `app/Services/Compliance/WithdrawalKycGateService.php` | PASS | Exists; 197 lines; syntax passed. Exact-decimal threshold gate delegates standing decisions to the canonical withdrawal KYC service and is called by `Finance\WithdrawalService` and `FinancialComplianceGateService`. Request-time and approval-time agreement is covered by tests. |
| `app/Services/Fraud/FraudDetectionService.php` | BROKEN → FIXED | Exists; syntax passed. Implements `SecurityRiskEngine`, deterministic rule evaluation, threshold decisions, alert recording, and security events. Event identity is now bounded to a SHA-256 idempotency key using reference ID or event ID; replay no longer emits duplicate review/suspicion events. Used by TrueMoney, slip verification, and the guarded crypto service. |
| `app/Services/Fraud/FraudEventRecorder.php` | BROKEN → FIXED | Exists; syntax passed. `updateOrCreate` previously replaced an existing alert UUID and mutable evidence on replay. It now uses race-safe `firstOrCreate`, preserving the first alert identity and evidence. |
| `app/Services/Fraud/FraudRuleEngine.php` | PASS | Exists; 66 lines; syntax passed. Deterministically evaluates duplicate-reference, payout-change, rapid-flow, account-linkage, KYC-mismatch, and velocity rules; caps aggregate score at 100. |
| `app/Services/Fraud/FraudAlertReviewService.php` | PASS | Exists; 28 lines; syntax passed. Compatibility namespace delegates to the canonical maker/checker-hardened `Security\FraudAlertReviewService`; no competing review engine exists. |

## Root-cause correction implemented

The fraud recorder claimed idempotency but used `updateOrCreate` with a newly generated UUID in the update payload. A replay of the same financial event could therefore change the primary alert identity, overwrite the original amount/evidence, and damage references to the original case. In addition, events without a provider reference used a random key even when a stable event ID was available.

Corrections:

1. Event keys are now `FRAUD-` plus a SHA-256 digest of event type, user identity, and the authoritative reference/event identity, keeping the key within the database limit.
2. The first alert is immutable on replay through `firstOrCreate` and the unique `idempotency_event_key` constraint.
3. Review/suspicion events are emitted only when the alert was newly created.
4. Regression coverage proves a replay with a changed amount retains the original alert UUID and amount, creates only one row, and dispatches only one review event.

## Executed evidence

```text
Named service syntax: 10/10 passed
Focused fraud replay/rules: 5 passed, 22 assertions
Full Feature/Security: 100 passed, 2 skipped, 565 assertions, 0 failures/errors
Compliance + KYC + fraud + security-hardening selection:
  225 total
  211 passed
  14 skipped by explicit infrastructure/profile guards
  902 assertions
  0 failures/errors
```

Commands:

```bash
xargs -a /tmp/part2-files.txt -n1 -P8 php -l
APP_ENV=testing php vendor/bin/phpunit \
  tests/Feature/Compliance \
  tests/Feature/Security/FraudDetectionServiceTest.php \
  tests/Feature/Security/KycVerificationTest.php \
  tests/Unit/Security/FraudRulesTest.php \
  tests/Security/SecurityNextHardeningVerificationTest.php \
  --log-junit=/tmp/prompt-part2-compliance-fraud-final.xml
php vendor/bin/pint --test \
  app/Services/Fraud/FraudDetectionService.php \
  app/Services/Fraud/FraudEventRecorder.php \
  tests/Feature/Security/FraudDetectionServiceTest.php
```

## Part-2 conclusion

All ten prompt-named files exist and were individually verified. The fraud replay defect was corrected at its source without weakening KYC, AML, fraud, maker/checker, or financial controls. External sanctions, source-of-funds, device-intelligence, and beneficiary-name services remain deployment/provider dependencies and are not represented as live integrations.
