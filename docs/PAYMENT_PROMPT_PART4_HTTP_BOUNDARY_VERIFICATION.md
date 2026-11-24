# Payment Master Prompt — Part 4 HTTP, Middleware, and Request Verification

**Verification date:** 2026-11-17  
**Scope:** Every controller, middleware, and request explicitly named in the supplied prompt segment. No named path is omitted.

## File-by-file matrix

| Prompt path | Result | Verification |
|---|---|---|
| `app/Http/Controllers/Api/V1/DepositController.php` | PASS | Exists; 225 lines; syntax passed. Deposit listing/showing is caller-owned; creation uses authenticated wallet, server method/currency eligibility, admission gates, and canonical checkout services. Mutation route retains auth, active-account, amount throttle, adult, and legal-consent middleware. |
| `app/Http/Controllers/Api/V1/DepositWebhookController.php` | PASS | Exists; 106 lines; syntax passed. Explicitly sandbox-only, local/testing-only, disabled under real money, requires configured HMAC, validates optional timestamp window and exact facts, sanitizes payload, and refuses unsigned requests before mutation. |
| `app/Http/Controllers/Api/V1/PaymentController.php` | PASS | Exists; 223 lines; syntax passed. Authenticated payment-method/provider and intent surface; server-owned gateway selection and caller ownership are preserved. |
| `app/Http/Controllers/Api/V1/PaymentWebhookController.php` | BROKEN → FIXED | Exists; syntax passed. Known Stripe/bKash/Nagad requests now reject a missing signature envelope with HTTP 403 before payload/business processing on both legacy and v2 ingress. Provider-specific and v2 envelope headers are recognized without accepting unsigned evidence. |
| `app/Http/Controllers/Api/V1/WithdrawalController.php` | PASS | Exists; 312 lines; syntax passed. Uses authenticated user/wallet, KYC and withdrawal services, ownership checks, exact money, server-side limits, reservation flow, and safe cancellation boundaries. Mutation routes retain auth, active, withdrawal throttle, adult, and legal-consent middleware. |
| `app/Http/Controllers/Api/V1/PayoutController.php` | PASS | Exists; 180 lines; syntax passed. Caller-owned payout index/show/cancel surface with authenticated active-user route floor and server-side cancellation service. |
| `app/Http/Controllers/Api/V1/WalletController.php` | PASS | Exists; 116 lines; syntax passed. Authenticated caller-owned wallet and transaction projections; mutation compatibility routes retain active/adult/legal/throttle gates and delegate to canonical finance paths. |
| `app/Http/Controllers/Api/V1/BankAccountController.php` | EQUIVALENT / PARTIAL | Exact snapshot controller is absent. The current architecture deliberately does not expose mutable customer bank-account CRUD. Withdrawal destinations are validated per method, encrypted into the immutable withdrawal snapshot, hidden from serialization, sanitized in logs, integrity-bound, and independently reviewed. Live beneficiary/same-name verification remains unavailable without an official bank/provider contract; no duplicate controller/model was invented. |
| `app/Http/Controllers/Api/V1/KycController.php` | PASS | Exists; 179 lines; syntax passed. Authenticated active-user status/upload routes; legal consent, upload throttling, private document handling, and canonical KYC service authorization are retained. |
| `app/Http/Controllers/Api/V1/Payment/KBankWebhookController.php` | PASS internally / UNVERIFIED externally | Exists; 66 lines; syntax passed. Webhook route retains throttle and signature/replay middleware; provider-specific verification remains fail-closed pending official contract certification. |
| `app/Http/Controllers/Api/V1/Payment/ScbWebhookController.php` | PASS internally / UNVERIFIED externally | Exists; 66 lines; syntax passed. Webhook route retains throttle and signature/replay middleware; SCB contract and credentials are still required for live use. |
| `app/Http/Controllers/Api/V1/Payment/ThaiBankPayoutWebhookController.php` | PASS internally / UNVERIFIED externally | Exists; 39 lines; syntax passed. Separate SCB/KBANK payout callback routes retain signature/replay middleware and delegate to the verified-evidence payout service. |
| `app/Http/Controllers/Admin/PayoutApprovalIntakeController.php` | PASS | Exists; 146 lines; syntax passed. Admin route stack requires authenticated bound session and `access-admin`; controller/service enforce approval rights and maker/checker workflow. |
| `app/Http/Controllers/Admin/PayoutEvidenceController.php` | PASS | Exists; 57 lines; syntax passed. Private payout evidence listing/upload/download remains behind admin authentication and capability gates. |
| `app/Http/Controllers/Admin/PayoutTransferReviewController.php` | PASS | Exists; 105 lines; syntax passed. Review/open/destination/decision actions remain admin-gated and use canonical transfer access and review services. |
| `app/Http/Controllers/Admin/WithdrawalReconciliationController.php` | PASS | Exists; 123 lines; syntax passed. Reconciliation creation, private evidence/destination access, and decisions remain admin-gated and service-authorized. |
| `app/Http/Middleware/EnsureWalletIsActive.php` | PASS | Exists; 31 lines; syntax passed. Requires authenticated user, caller-owned wallet relation, and a wallet that can transact. |
| `app/Http/Middleware/FraudRiskGateMiddleware.php` | BROKEN → FIXED | Exists; syntax passed. It previously fell back to client `user_id` when unauthenticated. Identity now comes only from the authenticated principal, preventing profile substitution or fraud evidence attributed to another player. Enforce/observe/step-up behavior remains intact. |
| `app/Http/Middleware/VerifyWebhookSignature.php` | PASS | Exists; 69 lines; syntax passed. Provider verification remains in provider-specific services; middleware performs accepted-delivery replay ownership only after signature verification and successful response. Closed bKash/Nagad lanes cannot be reopened by historical local signatures. |
| `app/Http/Requests/Payment/DepositRequest.php` | PASS | Exists; 87 lines; syntax passed. Requires authenticated user, exact decimal string, enum method, bounded currency/idempotency grammar, conflicting-header detection, and prohibits user/wallet/status/balance/approval fields. |
| `app/Http/Requests/Withdrawal/CreateWithdrawalRequest.php` | PASS | Exists; 131 lines; syntax passed. Requires authenticated user, exact decimal string and enum method; prohibits KYC, balance, identity, fee, exchange-rate, provider, status, reference, and approval claims from the client. |
| `app/Http/Requests/Web/WithdrawalRequest.php` | PASS | Exists; 109 lines; syntax passed. Method-specific destination rules, capability/market filtering, crypto syntax refusal, and prohibited incompatible fields remain server-authoritative. |
| `app/Http/Requests/Admin/ReviewPayoutTransferRequest.php` | PASS | Exists; 34 lines; syntax passed. Authorization delegates to canonical payout-transfer access; final outcome requires note and verified evidence, and provider reference is conditional on completion. Validation responses are private/no-store. |

## Security defects corrected

### Client identity substitution in fraud middleware

The middleware previously used request-body `user_id` when no authenticated user existed. A caller could make the risk engine evaluate or record evidence against a different account. The fallback was removed. Unauthenticated identity is never derived from submitted financial data.

### Missing known-provider signature envelope classification

Known Stripe/bKash/Nagad webhook requests with no signature could fall through to provider/business handling and return a generic 422. Both webhook controller entry points now reject a missing known-provider signature envelope with HTTP 403 before payload processing. Cryptographic validity remains provider-specific; presence alone never authorizes money.

## New direct boundary coverage

Added:

`tests/Feature/Security/PromptHttpPaymentBoundaryCoverageTest.php`

It proves:

1. A submitted `user_id` cannot select a fraud profile without authenticated identity.
2. Deposit, withdrawal, wallet, and admin routes retain their authentication, account, age/consent, session, and capability middleware.
3. Legacy/v2 payment webhooks and SCB/KBANK payment/payout webhooks retain signature and replay middleware.
4. The legacy sandbox deposit endpoint rejects an unsigned request and creates no deposit or ledger entry.

## Executed evidence

```text
Named existing PHP files: 22/22 syntax passed
HTTP boundary + security-hardening selection: 11 passed, 81 assertions
Controller/webhook/admin/KYC selection after fixes: 71 passed, 411 assertions
Pint on changed controller, middleware, and regression test: passed
```

## Part-4 conclusion

Every prompt-named path is represented above. Twenty-two exact files exist and pass syntax checks. The exact `BankAccountController.php` snapshot path is absent, but the secure current architecture intentionally uses encrypted per-withdrawal destination snapshots rather than mutable bank-account CRUD. No security gate was removed or bypassed, and no fake beneficiary-verification integration was created.
