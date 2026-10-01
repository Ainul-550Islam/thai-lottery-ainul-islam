# PROMPT 7 — NEXT 30 FILES
## THAILOTTO ENTERPRISE WAGERING PLATFORM
## ADMIN / AGENT / COMPLIANCE / KYC / API / NOTIFICATIONS / ACCESSIBILITY / PERFORMANCE / CI

You are the NEXT implementation and verification coding agent.

PROMPT 1–6 have already covered:
- GLO
- wallet
- ledger
- payment
- withdrawal
- betting
- responsible gaming
- Rust
- results
- archive
- auth
- KYC backend
- jobs
- compliance backend
- public frontend
- premium 3D/glass design
- player frontend
- production cutover preparation

THIS PROMPT COVERS THE NEXT 30 FILES.

============================================================
# 0. ABSOLUTE NO-SKIP RULE
============================================================

1. DO NOT SKIP ANY FILE.
2. DO NOT SKIP ANY CODE.
3. DO NOT SKIP ANY FUNCTION.
4. DO NOT SKIP ANY CLASS.
5. DO NOT SKIP ANY VIEW.
6. DO NOT SKIP ANY API resource.
7. DO NOT SKIP ANY form request.
8. DO NOT SKIP any accessibility state.
9. DO NOT SKIP mobile.
10. DO NOT SKIP desktop.
11. DO NOT SKIP EN.
12. DO NOT SKIP TH.
13. DO NOT SKIP any test.
14. DO NOT hide skipped tests.
15. A skipped test is NOT PASS.
16. A tool unavailable is NOT VERIFIED.
17. Do not weaken assertions.
18. Do not delete failing tests.
19. Do not trust previous completion reports as proof.
20. Inspect CURRENT repository state first.

============================================================
# 1. FULL FILE CONTENT — MANDATORY
============================================================

For EVERY modified or newly created file:

- Read the ENTIRE current file.
- Preserve all existing logic.
- Provide COMPLETE final file content.
- Do NOT return snippets.
- Do NOT omit imports.
- Do NOT omit methods.
- Do NOT omit routes.
- Do NOT omit existing validation.
- Do NOT omit existing security controls.

FORBIDDEN:

# ... existing code ...
// ... existing code ...
/* existing code */
...
same as above
omitted
rest unchanged

FULL FILE CONTENT ONLY.

============================================================
# 2. ADMINISTRATION UI + SECURITY
============================================================

1. resources/views/admin/dashboard.blade.php
# ADMIN UI AGENT:
# Build premium enterprise admin dashboard without changing backend authorization semantics.
# Show only real operational metrics.
# Distinguish live/pending/failed/reconciliation states.
# Never invent KPI numbers.
# Do not expose provider secrets or raw KYC data.
# Use responsive glass/metric design consistently.
# EN/TH where admin localization exists.
# Add empty/loading/error states.

2. resources/views/admin/payments/index.blade.php
# ADMIN PAYMENT UI AGENT:
# Display payment lifecycle clearly:
# pending / initiated / captured / failed / cancelled / reconciled / manual-review.
# Provider references must be safe to display.
# Never display credentials or raw webhook payloads.
# Add filtering/pagination/search without leaking other tenant/user data.
# Show reconciliation status from canonical backend state.

3. resources/views/admin/withdrawals/index.blade.php
# ADMIN PAYOUT UI AGENT:
# Build secure withdrawal-review table.
# Show KYC/compliance state, reservation state, provider state and final settlement state.
# Sensitive payout destination information must be masked where appropriate.
# Do not create a “complete” button that bypasses the canonical disbursement service.
# Destructive actions require authorization and confirmation.
# Add responsive admin review UI.

4. resources/views/admin/kyc/index.blade.php
# ADMIN KYC UI AGENT:
# Build secure KYC review surface.
# Do not expose raw document URLs.
# Display authorized metadata only.
# Approval/rejection must go through canonical KYC service.
# Require rejection reason where business rules require it.
# Add audit trail visibility without leaking document contents.

5. resources/views/admin/compliance/index.blade.php
# COMPLIANCE UI AGENT:
# Display AML risk, compliance cases, self-exclusion and restriction state.
# Separate automated risk score from final operator decision.
# Do not permit a UI action to bypass canonical compliance service.
# Ensure privileged actions show actor/reason requirements.
# Add status filters and audit links.

------------------------------------------------------------
# 3. AGENT / COMMISSION UI
------------------------------------------------------------

6. resources/views/agent/dashboard.blade.php
# AGENT UI AGENT:
# Premium agent dashboard.
# Use real commission/wallet/settlement figures.
# Currency must always be explicit.
# Do not mix player and agent funds.
# Show pending/settled/reversed commission separately.
# Never create fake “profit” metrics.
# Responsive glass/metric design.

7. resources/views/agent/commissions.blade.php
# AGENT COMMISSION UI AGENT:
# Display commission records from canonical service/data.
# Show source bet/settlement references where permitted.
# Clearly distinguish accrued/settled/reversed.
# Never let agent-supplied values determine commission.
# Provide pagination and filters.

8. resources/views/agent/settlements.blade.php
# AGENT SETTLEMENT UI AGENT:
# Show settlement state machine:
# requested / approved / processing / completed / rejected / reversed.
# No UI action may directly modify wallet balance.
# Every mutation must use canonical settlement service.
# Add confirmation and authorization state.

------------------------------------------------------------
# 4. COMPLIANCE / RESPONSIBLE GAMING FRONTEND
------------------------------------------------------------

9. resources/views/player/responsible-gaming.blade.php
# RG UI AGENT:
# Build premium responsible-gaming control center.
# Display current effective limits, pending increases, immediate decreases,
# self-exclusion state and cooling-off countdown.
# Never imply an increase is active before its effective timestamp.
# Never let client-side state override server state.
# EN/TH complete localization.
# Add accessible form feedback.

10. resources/views/player/verification.blade.php
# KYC PLAYER UI AGENT:
# Show verification status and document submission state.
# Do not expose private storage paths.
# Show upload errors safely.
# Use accessible file controls.
# Never display approved status unless backend confirms it.
# Add retry/pending/rejected state.

------------------------------------------------------------
# 5. API RESOURCES / DATA CONTRACTS
------------------------------------------------------------

11. app/Http/Resources/PaymentResource.php
# API CONTRACT AGENT:
# Audit every serialized field.
# Expose only player-safe payment information.
# Do not serialize secrets, raw provider payloads, internal signatures,
# credentials, webhook metadata or unnecessary internal IDs.
# Use explicit status vocabulary.
# Add contract/schema tests.

12. app/Http/Resources/WithdrawalResource.php
# API CONTRACT AGENT:
# Expose safe withdrawal state, amount, currency, timestamps and masked destination.
# Never expose private beneficiary data beyond authorized requirements.
# Ensure internal provider state does not leak.
# Add serialization tests for every lifecycle state.

13. app/Http/Resources/WalletResource.php
# WALLET API AGENT:
# Expose exact currency-aware balances.
# Separate available/locked/reserved/pending.
# Never use floats.
# Never merge currencies.
# Verify current-user authorization.
# Add zero/large/negative-protection serialization tests.

14. app/Http/Resources/BetResource.php
# BET API AGENT:
# Expose only canonical bet data.
# Verify draw status, selection, stake, settlement and payout states.
# No client-generated values.
# Add stable schema tests.

15. app/Http/Resources/UserResource.php
# USER API AGENT:
# Expose only safe account fields.
# Never expose password hashes, MFA secrets, internal risk data,
# KYC documents, provider credentials or security tokens.
# Verify role/account-status semantics.
# Add sensitive-field regression tests.

------------------------------------------------------------
# 6. FORM REQUEST / VALIDATION HARDENING
------------------------------------------------------------

16. app/Http/Requests/Web/DepositRequest.php
# REQUEST AGENT:
# Validate amount, currency, payment method, idempotency and permitted limits.
# Ensure client-provided provider parameters cannot override canonical backend config.
# Reject unexpected fields.
# Ensure EN/TH validation messages exist.
# Add boundary/abuse tests.

17. app/Http/Requests/Web/WithdrawRequest.php
# REQUEST AGENT:
# Validate canonical withdrawal method and destination contract.
# Enforce method-specific schemas.
# Reject unexpected fields.
# Validate decimal precision safely.
# Do not trust client-side validation.
# Add all positive/negative method tests.

18. app/Http/Requests/Web/BetPurchaseRequest.php
# BET REQUEST AGENT:
# Validate every selected number/draw/stake field.
# Enforce item count and payload size.
# Reject duplicate/malformed selections.
# Do not calculate authoritative price from client values.
# Add malformed/oversized/concurrent request tests.

------------------------------------------------------------
# 7. NOTIFICATIONS / TEMPLATE PRESENTATION
------------------------------------------------------------

19. resources/views/notifications/payment-status.blade.php
# NOTIFICATION UI AGENT:
# Render actual payment state only.
# Do not say “paid” without verified settlement.
# Mask provider references as appropriate.
# Fully localize EN/TH.
# No secret/raw webhook payload rendering.

20. resources/views/notifications/withdrawal-status.blade.php
# NOTIFICATION UI AGENT:
# Distinguish pending/manual-review/completed/rejected/reversed.
# Completed must correspond to authoritative provider/internal settlement state.
# No fake instant-payout wording.
# EN/TH.

21. resources/views/notifications/prize-won.blade.php
# PRIZE NOTIFICATION AGENT:
# Render prize notification only after authoritative state.
# Use canonical prize amount.
# Distinguish eligible/approved/paid where needed.
# No false “official GLO” wording.
# No unverified result claim.

22. resources/views/notifications/kyc-status.blade.php
# KYC NOTIFICATION AGENT:
# Show actual verification state.
# No sensitive document content.
# No fabricated approval.
# EN/TH localized.

------------------------------------------------------------
# 8. ACCESSIBILITY / PERFORMANCE
------------------------------------------------------------

23. resources/js/accessibility.js
# NEW ACCESSIBILITY AGENT:
# Centralize reusable accessibility helpers if absent:
# focus management
# modal focus trapping
# escape handling
# reduced-motion detection
# keyboard navigation
# live-region announcements
# Do not duplicate logic already implemented in mobile-nav.
# Add browser-compatible behavior.

24. resources/js/app.js
# FRONTEND CORE AGENT:
# Audit global JS boot process.
# Prevent duplicate event listener registration.
# Preserve existing CSRF/auth/error handling.
# Ensure lazy components initialize safely.
# Avoid global state pollution.
# Respect reduced-motion preference.
# No inline secrets/config exposure.

25. resources/css/accessibility.css
# ACCESSIBILITY CSS AGENT:
# Add canonical focus-visible, skip-link, reduced-motion,
# high-contrast-safe and form-error styles.
# Do not create contrast failures with glass surfaces.
# Ensure touch targets remain usable.
# Test keyboard-visible states.

------------------------------------------------------------
# 9. OBSERVABILITY / CI / RELEASE VERIFICATION
------------------------------------------------------------

26. app/Services/Monitoring/HealthCheckService.php
# OBSERVABILITY AGENT:
# If not already implemented, create canonical health/readiness service.
# Separate liveness from readiness.
# Verify database, cache, queue, storage, result freshness and financial reconciliation state.
# Do not expose secrets/internal paths.
# Readiness must fail when a critical dependency is unavailable.
# Add unit/integration tests.

27. tests/Feature/Observability/HealthEndpointTest.php
# NEW TEST AGENT:
# Verify:
# /up
# /health
# live/readiness distinction
# DB failure
# cache failure
# queue failure
# storage failure
# reconciliation unhealthy state
# sensitive information is never returned.

28. .github/workflows/ci.yml
# CI AGENT:
# Audit/create deterministic CI pipeline.
# Run:
# composer install
# PHP syntax
# PHPUnit
# JS syntax
# npm build
# Rust fmt/check/test/clippy when Rust toolchain exists.
# Run translation integrity.
# Run critical invariants.
# Never hide failures.
# Record skipped/unavailable toolchains separately.
# Pin supported runtime versions.

29. .github/workflows/security.yml
# SECURITY CI AGENT:
# Add dependency/security scanning.
# Verify composer audit or equivalent.
# Verify npm dependency audit.
# Verify Rust dependency audit where tooling exists.
# Scan for secrets.
# Scan for forbidden placeholder comments in production source.
# Do not fail because test fixtures contain safe sample secrets if explicitly allowlisted;
# but production paths must contain none.

30. tests/Feature/FinalWholeSystemNoSkipTest.php
# NEW FINAL MASTER TEST:
# This test suite is the FINAL APPLICATION-LEVEL regression gate.
# Verify:
# PUBLIC
# AUTH
# KYC
# RG
# BET
# RESULT
# GLO
# WALLET
# PAYMENT
# WITHDRAWAL
# AGENT
# COMPLIANCE
# ADMIN
# NOTIFICATION
# API
# SITEMAP
# LEGAL
# LOCALE
# HEALTH
# SECURITY
# DEPLOYMENT CONTRACT
#
# It must detect:
# fake data
# duplicate money movement
# cross-currency contamination
# cross-user access
# missing authorization
# unpublished result leakage
# fixture publication
# raw translation keys
# secret leakage
# incorrect payout state
# incorrect notification state
# API/web divergence
#
# Use real service/database integration where appropriate.
# Do not make this a superficial filename/string test.

============================================================
# 10. REQUIRED END-TO-END TEST MATRIX
============================================================

AUTH:

register
→ verify
→ login
→ session
→ authorization

KYC:

submit
→ store private document
→ review
→ approve/reject
→ notification

RG:

set limit
→ cooling-off
→ enforcement
→ block/allow

BET:

browser/API
→ validation
→ RG
→ reservation
→ bet
→ settlement
→ ledger

RESULT:

source
→ integrity
→ provenance
→ import
→ publication
→ archive
→ API
→ UI

PAYMENT:

deposit
→ provider
→ webhook
→ payment
→ wallet
→ ledger
→ reconciliation

WITHDRAWAL:

request
→ KYC
→ compliance
→ reservation
→ provider/manual
→ webhook/reconciliation
→ final state
→ ledger

PRIZE:

result
→ prize match
→ GLO calculation
→ claim
→ approval
→ payout
→ wallet
→ ledger
→ notification

AGENT:

settled bet
→ commission
→ settlement
→ reversal
→ reconciliation

ADMIN:

login
→ role/permission
→ operation
→ audit
→ final state

============================================================
# 11. FRONTEND QUALITY GATE
============================================================

Every UI modified in this prompt must satisfy:

[ ] Desktop
[ ] Tablet
[ ] Mobile
[ ] EN
[ ] TH
[ ] Keyboard navigation
[ ] Focus visible
[ ] Screen-reader labels
[ ] Reduced motion
[ ] Error state
[ ] Empty state
[ ] Loading state
[ ] Success state
[ ] Failure state

Never use animation as the only source of information.

============================================================
# 12. SECURITY GATE
============================================================

Verify:

[ ] CSRF
[ ] authentication
[ ] authorization
[ ] rate limiting
[ ] input validation
[ ] output escaping
[ ] private storage
[ ] secret redaction
[ ] webhook replay protection
[ ] idempotency
[ ] concurrency
[ ] cross-user isolation
[ ] cross-currency isolation

============================================================
# 13. CI / TOOLCHAIN GATE
============================================================

Run where available:

php artisan test
composer test
php -l changed PHP files
npm ci
npm run build
node --check changed JS
cargo fmt --check
cargo check
cargo test
cargo clippy --all-targets --all-features -- -D warnings

For unavailable tools:

NOT VERIFIED

Do NOT convert it to PASS.

============================================================
# 14. FULL-FILE OUTPUT REQUIREMENT
============================================================

For EVERY changed/new file provide:

FILE PATH:
STATUS:
COMPLETE FINAL FILE CONTENT:

Then provide:

TEST COMMAND:
RESULT:
PASSED:
FAILED:
SKIPPED:
NOT VERIFIED:

Do NOT use:

...
existing code
same as above
omitted
unchanged section

============================================================
# 15. FINAL NO-SKIP ACCEPTANCE
============================================================

Do NOT declare PROMPT 7 complete unless:

[ ] all 30 files inspected
[ ] all required implementation completed
[ ] existing logic preserved
[ ] no placeholder comments
[ ] no fake data
[ ] no fake financial state
[ ] no fake notification
[ ] no fake official claim
[ ] no cross-user leak
[ ] no cross-currency leak
[ ] no duplicate financial mutation
[ ] admin authorization verified
[ ] KYC privacy verified
[ ] compliance enforcement verified
[ ] agent accounting verified
[ ] API serialization verified
[ ] accessibility verified
[ ] responsive UI verified
[ ] performance checked
[ ] health/readiness verified
[ ] CI created/verified
[ ] security scanning configured
[ ] master whole-system test created
[ ] every test actually executed OR explicitly marked NOT VERIFIED
[ ] every skipped test explicitly listed
[ ] complete changed-file content supplied

============================================================
# FINAL INSTRUCTION
============================================================

START BY INSPECTING THE CURRENT REPOSITORY.

DO NOT TRUST PREVIOUS REPORTS.

TRACE THE REAL IMPLEMENTATION.

DO NOT SKIP ANY CODE.

DO NOT SKIP ANY FILE.

DO NOT SKIP ANY TEST.

DO NOT HIDE SKIPPED TESTS.

DO NOT WEAKEN ASSERTIONS.

DO NOT DELETE FAILING TESTS.

DO NOT USE PLACEHOLDER COMMENTS.

DO NOT USE:
# ... existing code ...

PRESERVE ALL EXISTING BUSINESS LOGIC.

ONLY CHANGE BACKEND WHEN REQUIRED FOR A CORRECT FRONTEND/SECURITY CONTRACT.

VERIFY THE ACTUAL CALL GRAPH.

VERIFY THE ACTUAL DATABASE EFFECT.

VERIFY THE ACTUAL USER EXPERIENCE.

VERIFY THE ACTUAL SECURITY BOUNDARY.

PROVIDE COMPLETE FINAL FILE CONTENT FOR ALL MODIFIED/NEW FILES.

REPORT EXACT VERIFIED / NOT VERIFIED / OPEN STATUS.

DO NOT DECLARE 100% COMPLETE UNTIL EVERY GATE IS VERIFIED.