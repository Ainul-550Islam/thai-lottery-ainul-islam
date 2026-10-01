# PROMPT 3 — NEXT 30 FILES
## THAILOTTO — JOBS / QUEUES / SECURITY / KYC / COMPLIANCE / AGENT / FRONTEND / DB

You are the NEXT implementation + verification agent.

PROMPT 1 covered the first 30 critical GLO / wallet / payment / RG / betting / Rust / release files.

PROMPT 2 covered the next 30 results / archive / auth / KYC / admin / compliance / API / agent files.

THIS PROMPT 3 COVERS THE NEXT 30 HIGH-RISK FILES.

============================================================
# 0. ABSOLUTE NO-SKIP RULE
============================================================

1. DO NOT SKIP ANY FILE.
2. DO NOT SKIP ANY FUNCTION.
3. DO NOT SKIP ANY CLASS.
4. DO NOT SKIP ANY JOB.
5. DO NOT SKIP ANY QUEUE FLOW.
6. DO NOT SKIP ANY SECURITY CHECK.
7. DO NOT SKIP ANY TEST.
8. DO NOT hide skipped tests.
9. DO NOT delete failing tests.
10. DO NOT weaken assertions.
11. DO NOT claim PASS when a test is SKIPPED.
12. DO NOT claim VERIFIED when runtime could not execute.
13. DO NOT copy a previous completion report as proof.
14. Inspect CURRENT repository state first.
15. Trace actual callers/callees before editing.

If something cannot run:

NOT VERIFIED:
<exact command>
<exact reason>

Never convert NOT VERIFIED into PASS.

============================================================
# 1. FULL FILE CONTENT RULE
============================================================

For EVERY modified file:

- Read the complete original file first.
- Preserve all existing working logic.
- Return COMPLETE final file content.
- Do not return snippets.
- Do not omit imports.
- Do not omit methods.
- Do not omit routes/configuration.
- Do not use:
  # ... existing code ...
  // ... existing code ...
  /* existing code */
  ...
  or any other placeholder.
- Do not create fake methods.
- Do not create test-only production shortcuts.
- Do not bypass security to simplify tests.
- Do not duplicate existing canonical services.

FULL FILE CONTENT means the complete resulting file.

============================================================
# 2. CORE TRACE RULE
============================================================

Every asynchronous or frontend action must trace:

REQUEST
→ VALIDATION
→ AUTHORIZATION
→ BUSINESS SERVICE
→ DATABASE TRANSACTION
→ EVENT/JOB
→ RETRY
→ FINAL STATE
→ AUDIT
→ NOTIFICATION
→ RECONCILIATION

Every failure path must be safe.

============================================================
# 3. 30 FILE TARGETS
============================================================

------------------------------------------------------------
## GROUP A — QUEUE / JOB EXECUTION / RETRIES
------------------------------------------------------------

1. app/Jobs/ProcessPaymentWebhookJob.php
# QUEUE AGENT:
# Audit webhook processing end-to-end.
# Job MUST be idempotent.
# Verify duplicate webhook cannot double-credit wallet.
# Verify provider event identity/idempotency.
# Verify retry behavior and final failure state.
# Verify transaction boundaries.
# Verify failed processing does not create fake paid/completed state.
# Ensure secrets/signatures are not logged.
# Add retry, duplicate, timeout, and permanent-failure tests.

2. app/Jobs/Payment/ProcessPaymentWebhookJob.php
# QUEUE/PAYMENT AGENT:
# If this is a domain-specific duplicate of ProcessPaymentWebhookJob,
# determine the canonical implementation and eliminate split-brain logic.
# Do not allow two independent webhook state machines.
# Every provider webhook must converge on one payment state transition path.
# Add duplicate-event and cross-job consistency tests.

3. app/Jobs/Payment/DisburseWithdrawalJob.php
# PAYOUT AGENT:
# Audit withdrawal disbursement execution.
# Verify reservation ownership.
# Verify provider capability.
# Verify idempotency.
# Verify no second payout on retry.
# Verify provider timeout leaves explicit pending state.
# Verify provider rejection restores funds exactly once.
# Verify successful provider settlement creates one ledger result.
# Add concurrency/retry/reversal tests.

4. app/Jobs/ReconcilePaymentProviderJob.php
# PAYMENT RECONCILIATION AGENT:
# Reconcile internal payment state against provider state.
# Handle:
# internal pending/provider success
# internal success/provider failure
# provider unknown
# duplicate provider event
# delayed provider event
# amount mismatch
# currency mismatch
# Any mismatch must create an auditable reconciliation record.
# Never silently overwrite financial history.
# Add mismatch and retry tests.

5. app/Jobs/Finance/ProcessFinancialReconciliationJob.php
# FINANCE JOB AGENT:
# Audit scheduled financial reconciliation.
# Verify idempotent execution.
# Verify lock/transaction behavior.
# Ensure partial failure resumes safely.
# Ensure reconciliation exceptions are observable.
# No silent catch-and-ignore.
# Add job retry/failure/duplicate tests.

6. app/Jobs/ReconcileWalletLedgersJob.php
# WALLET JOB AGENT:
# Verify wallet/ledger reconciliation covers every financial source:
# deposits, bets, refunds, withdrawals, prizes, commissions, reversals.
# Detect currency mismatches.
# Detect missing ledger entries.
# Detect duplicate ledger entries.
# Produce auditable discrepancies.
# Never fabricate unexplained balance corrections.
# Add mismatch tests.

------------------------------------------------------------
## GROUP B — NOTIFICATIONS / FINANCIAL EVENTS
------------------------------------------------------------

7. app/Jobs/Notification/SendFinancialAlertJob.php
# NOTIFICATION AGENT:
# Audit alert delivery for failed payments, payout failures, wallet discrepancies,
# suspicious financial events and reconciliation failures.
# Ensure job is idempotent and retry-safe.
# Do not expose secrets, payment credentials, KYC documents or raw provider payloads.
# Add recipient/authorization tests.

8. app/Jobs/SendWinnerNotificationJob.php
# PRIZE NOTIFICATION AGENT:
# Verify notification only occurs after an authoritative settlement state.
# Never notify a player about an unverified or provisional prize as paid.
# Ensure duplicate settlement does not send duplicate financial-success notifications.
# Add pending/rejected/paid tests.

9. app/Jobs/RetryFailedNotificationsJob.php
# NOTIFICATION RETRY AGENT:
# Audit retry policy, maximum attempts, dead-letter/final failure behavior,
# deduplication and recipient safety.
# Ensure retry cannot duplicate financial notifications indefinitely.
# Add retry exhaustion tests.

10. app/Jobs/DispatchPendingNotificationsJob.php
# NOTIFICATION DISPATCH AGENT:
# Verify notification selection, status transitions, locking and concurrency.
# Two workers must not send the same notification twice.
# Verify atomic “claimed for delivery” behavior.
# Add concurrent-worker tests.

------------------------------------------------------------
## GROUP C — AUTHENTICATION / SESSION / SECURITY
------------------------------------------------------------

11. app/Http/Middleware/Authenticate.php
# SECURITY AGENT:
# Audit authentication failure handling.
# Verify unauthenticated browser/API behavior.
# Verify no sensitive redirect leakage.
# Verify session state is safe.
# Verify suspended/blocked accounts cannot continue authenticated financial actions.
# Add auth-boundary tests.

12. app/Http/Middleware/VerifyCsrfToken.php
# SECURITY AGENT:
# Audit every CSRF-exempt route.
# There MUST be no accidental CSRF exemption for browser financial mutation routes.
# Webhooks must use explicit provider signature verification instead of CSRF.
# Add route/middleware regression tests.

13. app/Http/Middleware/ThrottleRequests.php
# SECURITY AGENT:
# Audit rate-limit configuration and critical endpoint coverage.
# Verify login, register, deposit, withdrawal, bet purchase, password reset,
# KYC submission and contact/form abuse limits.
# Ensure limits are not bypassable through alternate route names.
# Add rate-limit tests.

14. app/Services/Security/SecurityEventService.php
# SECURITY AGENT:
# Audit suspicious-login, brute-force, session, payment, withdrawal,
# KYC and admin security events.
# Ensure event severity and actor context are trustworthy.
# Prevent user-controlled metadata from becoming trusted security identity.
# No secrets/tokens/passwords in event payloads.
# Add security event tests.

15. app/Jobs/ReviewHighRiskSecurityEventsJob.php
# SECURITY JOB AGENT:
# Audit detection/review flow.
# Ensure critical events are not silently ignored.
# Ensure repeat execution is idempotent.
# Ensure escalated events retain full audit linkage.
# Add duplicate/retry/failure tests.

------------------------------------------------------------
## GROUP D — KYC DOCUMENT SECURITY
------------------------------------------------------------

16. app/Models/AccountVerificationDocument.php
# KYC SECURITY AGENT:
# Audit file metadata, ownership, storage path, MIME/type handling,
# checksum, upload state, deletion policy and retention.
# Never trust client MIME type alone.
# Do not expose private storage URLs directly.
# Prevent cross-user document access.
# Add authorization and malicious-file tests.

17. app/Services/Account/AccountVerificationDocumentService.php
# KYC FILE AGENT:
# Centralize secure document upload/read/delete.
# Validate size, extension, actual content type, storage isolation,
# filename normalization and retention.
# Prevent path traversal.
# Prevent executable/polyglot upload.
# Log only safe metadata.
# Add positive/negative/security tests.

18. app/Http/Controllers/Web/AccountVerificationController.php
# KYC WEB AGENT:
# Audit submit/view/document routes.
# Authorization must use the current authenticated user.
# Users must not read another user's documents.
# Verify CSRF, validation, rate limiting and immutable-submission policy.
# Add unauthorized-access tests.

------------------------------------------------------------
## GROUP E — AGENT / COMMISSION / FINANCIAL ACCOUNTING
------------------------------------------------------------

19. app/Services/Agent/AgentCommissionService.php
# AGENT ACCOUNTING AGENT:
# Trace:
# player bet
# → eligible settlement
# → commission calculation
# → commission ledger
# → agent balance
# → reversal/refund
# Verify commission is calculated only from authoritative settled events.
# Prevent duplicate commission.
# Prevent commission on rejected/unsettled bets.
# Ensure currency isolation.
# Add replay/reversal tests.

20. app/Services/Agent/AgentSettlementService.php
# AGENT SETTLEMENT AGENT:
# Audit agent balance settlement and transfers.
# Verify transaction locking.
# Verify idempotency.
# Verify commission reversal.
# Verify insufficient available agent balance.
# Verify no player wallet can be used as an agent settlement shortcut.
# Add concurrency tests.

21. app/Models/Agent.php
# MODEL AGENT:
# Audit agent account state, status, currency, commission configuration,
# ownership/user relation and activation/deactivation behavior.
# Prevent unauthorized status changes.
# Add DB/model constraints where necessary.
# Test disabled agent cannot receive new commission.

------------------------------------------------------------
## GROUP F — COMPLIANCE / SELF-EXCLUSION / RISK
------------------------------------------------------------

22. app/Services/Compliance/AmlRiskService.php
# COMPLIANCE AGENT:
# Audit AML/risk scoring and block/hold decisions.
# Risk checks must not be bypassed by web/API alternate paths.
# Distinguish automated score from final compliance action.
# Ensure financial restrictions propagate to bet/deposit/withdraw/prize paths.
# Add block/hold/release tests.

23. app/Services/Compliance/SelfExclusionService.php
# RESPONSIBLE GAMING AGENT:
# Audit self-exclusion lifecycle.
# Ensure effective-from/effective-until semantics are deterministic.
# A self-excluded user must not place bets or execute restricted money actions.
# Prevent alternate API/web bypass.
# Ensure expiration is handled safely.
# Add exact-boundary and concurrent tests.

24. app/Jobs/ExpireSelfExclusionsJob.php
# RESPONSIBLE GAMING JOB AGENT:
# Audit expiration handling.
# Ensure job is idempotent.
# Ensure it cannot incorrectly reopen a still-active exclusion.
# Ensure state changes are auditable.
# Add date-boundary/retry tests.

------------------------------------------------------------
## GROUP G — FRONTEND SECURITY / BUSINESS INTEGRATION
------------------------------------------------------------

25. resources/js/svgbet-slip.js
# FRONTEND BET AGENT:
# Audit the entire bet-slip client flow.
# No client-side value may be trusted for price/balance/RG/draw state.
# Verify CSRF token handling.
# Verify duplicate-submit lock.
# Verify idempotency key generation/preservation.
# Verify correct handling of server rejection/pending/success.
# Never display “bet placed” before server confirmation.
# Add browser/feature tests for duplicate clicks and stale draw state.

26. resources/js/svgdeposit.js
# FRONTEND PAYMENT AGENT:
# Audit deposit initiation UX.
# Client must distinguish:
# hosted checkout
# manual settlement instructions
# pending
# refusal
# completed
# Never assume success from redirect.
# Never trust query-string success flags.
# Ensure CSRF/idempotency support where applicable.
# Add UI behavior tests.

27. resources/js/svgwithdraw.js
# FRONTEND WITHDRAWAL AGENT:
# Audit withdrawal form and state handling.
# Canonical bank code/mobile/crypto input validation must match server rules.
# Client validation is advisory only.
# Never expose internal provider errors/secrets.
# Prevent double submission.
# Show pending/manual-review/rejected states accurately.
# Add negative and replay behavior tests.

------------------------------------------------------------
## GROUP H — DATABASE INTEGRITY / MIGRATIONS
------------------------------------------------------------

28. database/migrations/2026_*.php
# DATABASE AGENT:
# Audit ALL 2026 migrations together.
# Verify every wallet/payment/bet/withdrawal/prize/commission/KYC/result table has
# correct unique indexes, foreign keys, decimal precision, status fields and timestamps.
# Look specifically for missing:
# idempotency unique keys
# provider event uniqueness
# wallet currency uniqueness
# ticket identity uniqueness
# claim uniqueness
# ledger immutability constraints
# Do not modify historical migrations if already applied in production;
# create safe forward migrations where required.
# Add migration tests using a fresh database.

29. app/Providers/AppServiceProvider.php
# APPLICATION BOOT AGENT:
# Audit bindings, singleton scopes, validation rules, event listeners,
# global macros, security configuration and environment-specific behavior.
# Ensure canonical wallet/payment/RG services are not accidentally duplicated
# through conflicting bindings.
# Ensure production-only guards are actually applied.
# Add boot/config regression tests.

30. tests/Feature/BusinessCriticalInvariantTest.php
# NEW MASTER REGRESSION TEST:
# Create a high-value invariant suite that proves:
# 1. wallet cannot go negative
# 2. wallet currencies never mix
# 3. duplicate deposit cannot double-credit
# 4. duplicate withdrawal cannot double-pay
# 5. duplicate bet cannot double-charge
# 6. duplicate prize claim cannot double-credit
# 7. duplicate webhook cannot double-settle
# 8. self-excluded player cannot bet
# 9. KYC-restricted player cannot bypass withdrawal controls
# 10. unpublished/future result cannot be public
# 11. fixture cannot become official
# 12. agent commission cannot duplicate
# 13. audit events do not leak secrets
# 14. browser/API financial paths share canonical services
# 15. all final financial states reconcile to ledger.
# This is NOT a superficial endpoint test.
# Use real service/database integration.
# Include replay and concurrent scenarios.

============================================================
# 4. SECURITY TEST REQUIREMENTS
============================================================

For all modified security/financial files, test:

[ ] unauthenticated
[ ] authenticated wrong user
[ ] wrong role
[ ] expired session
[ ] CSRF failure
[ ] rate limit
[ ] malformed payload
[ ] oversized payload
[ ] duplicate request
[ ] concurrent request
[ ] stale request
[ ] retry
[ ] replay
[ ] provider timeout
[ ] provider refusal
[ ] database rollback
[ ] successful completion

============================================================
# 5. QUEUE TEST REQUIREMENTS
============================================================

For every modified Job:

[ ] first execution
[ ] duplicate execution
[ ] retry
[ ] exception
[ ] permanent failure
[ ] timeout
[ ] concurrency
[ ] final-state correctness
[ ] audit event
[ ] notification side effect
[ ] no duplicate financial mutation

A green first-run test alone is insufficient.

============================================================
# 6. FRONTEND TRUST BOUNDARY
============================================================

Frontend values MUST NEVER be authoritative for:

- wallet balance
- available balance
- ticket price
- tax
- fee
- result
- draw status
- RG limit
- provider success
- payout state
- prize amount

Server-side canonical services remain authoritative.

============================================================
# 7. DATABASE SAFETY
============================================================

Do not “fix” uniqueness only in PHP.

For critical money identity:

APP LOGIC
+
DATABASE CONSTRAINT

must both exist where appropriate.

Examples:

wallet owner + currency
payment provider event ID
withdrawal idempotency key
bet purchase idempotency key
prize claim identity
ticket serial/reference
ledger transaction reference
agent commission source event

============================================================
# 8. FULL-FILE OUTPUT REQUIREMENT
============================================================

After implementation provide:

- complete content for every modified file
- complete content for every NEW file
- migration content
- test content
- exact commands executed
- pass count
- fail count
- skipped count
- unverified count
- exact reasons for every skipped/unverified test
- exact files NOT changed and why
- exact remaining OPEN findings

Never use:

...
existing code
same as above
omitted
unchanged section

============================================================
# 9. REQUIRED VALIDATION
============================================================

Run where available:

php artisan test
composer test
php -l on ALL changed PHP files
node --check on ALL changed JS
npm run build
cargo test
cargo check
cargo clippy --all-targets --all-features -- -D warnings

Also run:

fresh migration test
wallet concurrency test
payment replay test
withdrawal reversal test
webhook replay test
self-exclusion boundary test
KYC authorization test
agent commission reversal test
master invariant suite

============================================================
# 10. NO-SKIP COMPLETION RULE
============================================================

Do NOT declare PROMPT 3 complete unless:

[ ] all 30 target files inspected
[ ] all required implementation changes completed
[ ] all existing logic preserved
[ ] no placeholder code/comments
[ ] no fake financial success
[ ] no fake notification success
[ ] no duplicate financial settlement
[ ] no cross-user KYC document access
[ ] no self-exclusion bypass
[ ] no agent commission duplication
[ ] async jobs are idempotent
[ ] queue retries are safe
[ ] frontend cannot bypass server rules
[ ] database constraints protect critical identities
[ ] master invariant suite exists and runs
[ ] every test result is honestly reported
[ ] skipped tests remain explicitly SKIPPED
[ ] unavailable tools remain NOT VERIFIED
[ ] every modified file's complete content is supplied

============================================================
# 11. FINAL MONEY / SECURITY TRACE
============================================================

Verify all these actual call chains:

PAYMENT

Browser
→ Controller
→ FormRequest
→ PaymentInitiationService
→ GatewayManager
→ Provider
→ Webhook
→ PaymentCallback/Processing
→ Wallet/Ledger
→ Reconciliation

WITHDRAWAL

Browser
→ Controller
→ Validation
→ KYC
→ Compliance
→ Wallet Reservation
→ Disbursement Job
→ Provider
→ Webhook/Reconciliation
→ Wallet/Ledger
→ Audit

BET

Browser
→ BetPurchaseController
→ BulkBetService
→ RG
→ Wallet Reservation
→ Draw State
→ Bet
→ Settlement
→ Ledger

PRIZE

Result
→ Provenance
→ Publication
→ Prize Match
→ GLO Calculator
→ Claim
→ Settlement
→ Wallet
→ Ledger
→ Notification

KYC

Register
→ Verification
→ Document
→ Review
→ Approval/Rejection
→ Restricted Actions

AGENT

Bet Settlement
→ Commission
→ Agent Ledger
→ Reversal
→ Reconciliation

SECURITY

Request
→ Authentication
→ Authorization
→ CSRF
→ Throttle
→ Validation
→ Business Rule
→ Audit

Every path must have:

SUCCESS
FAILURE
RETRY
REPLAY
CONCURRENCY
ROLLBACK

============================================================
# 12. FINAL STATUS VOCABULARY
============================================================

Use ONLY:

CODE COMPLETE
TEST VERIFIED
RUNTIME VERIFIED
DATA VERIFIED
PROVIDER VERIFIED
PRODUCTION VERIFIED
NOT VERIFIED
OPEN

Never use:

"done"
"works"
"fully complete"
"100%"
"production ready"

unless the corresponding evidence actually exists.

============================================================
# FINAL INSTRUCTION
============================================================

START BY READING THE CURRENT CODE.

DO NOT ASSUME PROMPT 1 OR PROMPT 2 CLAIMS ARE TRUE.

VERIFY CURRENT STATE.

IMPLEMENT THE FIXES.

TEST THE FIXES.

TRACE THE REAL CALL GRAPH.

PROVIDE COMPLETE MODIFIED FILE CONTENT.

DO NOT SKIP.

DO NOT HIDE SKIPPED TESTS.

DO NOT HIDE UNVERIFIED TESTS.

DO NOT REMOVE EXISTING LOGIC.

DO NOT USE PLACEHOLDER COMMENTS.

DO NOT DECLARE COMPLETION UNTIL EVERY REQUIRED CHECK IS ACTUALLY SATISFIED.