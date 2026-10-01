# PROMPT 4 — FINAL NEXT 30 FILES
## THAILOTTO — FINAL PUBLIC UI / BUSINESS / API / DATA / SEO / OBSERVABILITY / RELEASE PASS

You are the FINAL implementation + verification coding agent.

PROMPT 1 = first critical 30.
PROMPT 2 = next 30.
PROMPT 3 = next 30.
THIS PROMPT 4 = FINAL NEXT 30 HIGH-RISK FILES.

IMPORTANT:
The previous completion reports claim many items are CLOSED.
Do NOT trust those claims automatically.
Inspect CURRENT repository code and verify the actual implementation.

============================================================
# 0. ABSOLUTE NO-SKIP RULE — HARD REQUIREMENT
============================================================

1. DO NOT SKIP ANY FILE.
2. DO NOT SKIP ANY FUNCTION.
3. DO NOT SKIP ANY CLASS.
4. DO NOT SKIP ANY VIEW.
5. DO NOT SKIP ANY API resource.
6. DO NOT SKIP ANY migration.
7. DO NOT SKIP ANY frontend file.
8. DO NOT SKIP ANY test.
9. DO NOT hide skipped tests.
10. DO NOT convert SKIPPED into PASS.
11. DO NOT convert NOT VERIFIED into PASS.
12. DO NOT delete failing tests.
13. DO NOT weaken assertions.
14. DO NOT remove existing security checks.
15. DO NOT claim 100% because syntax is green.
16. DO NOT trust previous completion reports as evidence.
17. Trace actual runtime call paths.

If an environment dependency prevents execution:

NOT VERIFIED:
<exact command>
<exact reason>

That is NOT PASS.

============================================================
# 1. FULL FILE CONTENT — MANDATORY
============================================================

For EVERY modified file:

- Read the ENTIRE original file.
- Preserve all existing logic unless intentionally changed.
- Return the COMPLETE final file content.
- No snippets.
- No patch-only answer.
- No omitted methods.
- No omitted imports.
- No omitted route definitions.
- No omitted configuration.
- No placeholder comments.

FORBIDDEN:

# ... existing code ...
// ... existing code ...
/* existing code */
...
omitted
same as above
rest unchanged

FULL FILE CONTENT means 100% of the final file.

============================================================
# 2. DO NOT CREATE DUPLICATE ARCHITECTURE
============================================================

Before creating a new class/service/controller:

1. Search the repository.
2. Identify existing implementation.
3. Extend the canonical implementation.
4. Remove only truly dead duplicate code when safe and tested.
5. Do not create parallel business logic.

============================================================
# 3. FINAL BUSINESS TRACE
============================================================

PUBLIC PAGE
→ Shared Layout
→ Localized Content
→ Canonical Service
→ Database/API
→ Provenance
→ Correct State

SEARCH
→ Validation
→ Query
→ Published-only filter
→ Pagination
→ Stable response

NOTIFICATION
→ Domain event
→ Queue
→ Idempotency
→ Delivery
→ Retry
→ Final state
→ Audit

DATA
→ Import
→ Validate
→ Provenance
→ Reconcile
→ Publish
→ Archive

RELEASE
→ Dependencies
→ Assets
→ Database
→ Queue
→ Scheduler
→ Secrets
→ Health
→ Smoke test
→ Rollback

============================================================
# 4. FINAL 30 FILE TARGETS
============================================================

------------------------------------------------------------
## GROUP A — SHARED PUBLIC LAYOUT / CONTENT
------------------------------------------------------------

1. resources/views/layouts/app.blade.php
# PUBLIC UI AGENT:
# Audit ENTIRE layout.
# Remove any hardcoded false/ambiguous operational claims.
# Health/version labels must come from real runtime/release metadata.
# Navigation must use canonical route inventory.
# Auth/guest links must respect authorization.
# EN/TH must render correctly.
# Verify canonical URL, meta title, description, OpenGraph, favicon,
# CSP-compatible asset loading and accessibility.
# Do not expose internal routes, provider errors or secrets.

2. resources/views/components/public-page/footer.blade.php
# LEGAL/MARKETING AGENT:
# Audit operator identity, legal disclaimer, copyright, support information,
# GLO references and non-affiliation language.
# No fabricated government ownership.
# No hardcoded fake address/contact.
# All production identity data must come from approved legal configuration.
# Localize user-facing copy.
# Add rendered EN/TH assertions.

3. resources/views/home.blade.php
# HOME AGENT:
# Audit COMPLETE homepage rendering.
# No fake numbers, fake users, fake profit, fake campaign, fake next-draw data.
# Every statistic must have clear provenance.
# Every CTA must have a real configured target.
# No unsupported “official” wording.
# Ensure responsive/accessibility-safe rendering.
# Add homepage smoke tests in EN and TH.

4. resources/views/components/home/glo-products.blade.php
# PRODUCT MARKETING AGENT:
# Verify product names, prices, prize wording, source status and CTA links.
# Never imply official GLO endorsement without verified legal source.
# Product labels must come from approved localization/config.
# No fabricated prize information.
# Add provenance and empty-state tests.

5. resources/views/components/home/prize-highlight.blade.php
# PRIZE CONTENT AGENT:
# Verify all prize amounts are sourced from canonical result/prize service.
# Never display stale/fake/fallback prize data as real.
# Distinguish estimated/configured/published/officially authenticated state.
# Localize all labels.
# Add tests for no-result and unverified-result states.

------------------------------------------------------------
## GROUP B — HOME / SUPPORT / MARKETING SERVICES
------------------------------------------------------------

6. app/Services/Home/HomePageDataService.php
# HOME SERVICE AGENT:
# Audit all calls and data assembly.
# Avoid duplicate DB/API queries.
# Use canonical result/payment/support/product services.
# Cache only data that is safe to cache.
# Invalidate correctly after result publication.
# Remove hardcoded fallback identities/statistics.
# Add call-count and stale-cache tests.

7. app/Services/Lottery/GloPublicHomeService.php
# HOME DATA AGENT:
# Verify every public GLO-related field has authoritative provenance.
# Unknown/unverified source must degrade safely.
# No false “official” status.
# Do not mix fixture data with production data.
# Ensure product/prize data comes from one canonical source.

8. app/Services/Lottery/GloPublicStatsService.php
# STATS AGENT:
# Audit every count and status.
# “Verified” must mean exactly what was verified.
# Replace ambiguous external-certification wording where needed.
# Ensure counts have consistent timezone/date scope.
# Add tests for stale, zero, and unverified data.

9. app/Services/Support/PublicSupportService.php
# SUPPORT AGENT:
# Audit configured support channels.
# Separate available email/phone/chat/hours/form capabilities.
# Fail closed when unconfigured.
# Never show fake contact details.
# Do not expose internal-only contacts.
# Add EN/TH tests and invalid configuration tests.

10. app/Services/Media/PublicAppLinkService.php
# MARKETING SECURITY AGENT:
# Audit every URL source and validation path.
# Allow only safe HTTPS schemes.
# Add production host ownership/allow-list where appropriate.
# Drop malformed, test, javascript:, data:, localhost and unsafe URLs.
# Support iOS/Android/store-specific validation.
# Add full positive/negative tests.

------------------------------------------------------------
## GROUP C — RESULTS / SEARCH / API PRESENTATION
------------------------------------------------------------

11. resources/views/results/index.blade.php
# RESULTS UI AGENT:
# Blade MUST remain presentation-only.
# No DB query in Blade.
# No raw internal API URLs as customer-facing links.
# No sample ticket/demo identifier.
# No “official” inference from provider name.
# Use controller/DTO/service result data.
# Localize all customer-facing copy.
# Add EN/TH render tests and no-raw-key/no-raw-API/no-demo-ID tests.

12. resources/views/results/search.blade.php
# SEARCH UI AGENT:
# Validate query length/type on server.
# Escape display values.
# Never leak unpublished/future results.
# Show honest no-result/error/loading states.
# Localize all copy.
# Ensure search links use named routes.

13. app/Http/Controllers/ResultsController.php
# RESULT CONTROLLER AGENT:
# Remove any remaining presentation/database coupling.
# Use dedicated result service/DTO.
# Enforce published-only/future-safe filtering.
# Validate year/provider/lane parameters.
# Add stable pagination.
# Add unauthorized/internal-field leakage tests.

14. app/Http/Resources/ResultResource.php
# API RESOURCE AGENT:
# Audit every serialized result field.
# Expose only public-safe fields.
# Ensure provenance/status vocabulary is explicit.
# Never expose raw provider credentials, internal IDs where unsafe,
# reconciliation metadata or admin fields.
# Add schema tests.

15. app/Http/Resources/DrawResource.php
# API RESOURCE AGENT:
# Verify draw publication state, date/timezone, lane, result availability,
# canonical URLs and provenance.
# Future/unpublished/retracted draw must not appear as public result.
# Add resource serialization tests.

------------------------------------------------------------
## GROUP D — NOTIFICATION TEMPLATES / DELIVERY
------------------------------------------------------------

16. app/Notifications/PaymentStatusNotification.php
# NOTIFICATION AGENT:
# Verify notifications reflect actual internal/payment-provider state.
# Never say “paid” when provider confirmation is absent.
# Never expose provider secrets or raw webhook payload.
# Localize EN/TH subject/body.
# Ensure duplicate event does not generate duplicate financial-success claims.

17. app/Notifications/WithdrawalStatusNotification.php
# PAYOUT NOTIFICATION AGENT:
# Distinguish requested/pending/manual-review/completed/rejected/reversed.
# Only completed means verified settlement.
# Never notify “completed” from browser return alone.
# Localize and add state-specific tests.

18. app/Notifications/PrizeWonNotification.php
# PRIZE NOTIFICATION AGENT:
# Notify only after authoritative prize settlement eligibility/confirmation.
# Preserve proportional GLO calculation result.
# No premature “winner paid” messaging.
# Add duplicate/retry/state tests.

19. app/Notifications/KycStatusNotification.php
# KYC AGENT:
# Audit approval/rejection/pending wording.
# Never include sensitive document contents.
# Localize all messages.
# Ensure notification follows actual KYC state.
# Add tests for pending/approved/rejected.

------------------------------------------------------------
## GROUP E — API / SECURITY BOUNDARY
------------------------------------------------------------

20. app/Http/Controllers/Api/V1/MeController.php
# API SECURITY AGENT:
# Audit current-user data exposure.
# Prevent another user's wallet/KYC/payment/internal data exposure.
# Serialize only public-safe fields.
# Add authorization and sensitive-field tests.

21. app/Http/Controllers/Api/V1/WalletController.php
# WALLET API AGENT:
# GET endpoints must be read-only.
# POST/mutation operations must use canonical WalletService.
# Verify currency isolation.
# Verify available/locked/reserved semantics.
# No client-provided balance can become authoritative.
# Add API concurrency/read-consistency tests.

22. app/Http/Middleware/TrustProxies.php
# INFRA SECURITY AGENT:
# Audit trusted proxy configuration.
# Ensure forwarded protocol/host/IP cannot be spoofed by untrusted clients.
# Verify HTTPS enforcement, secure cookies, rate-limiter IP identity,
# absolute URL generation and webhook security.
# Add proxy-header regression tests.

23. app/Exceptions/Handler.php
# ERROR SECURITY AGENT:
# Audit production exception handling.
# Never expose stack traces, SQL, file paths, provider secrets or tokens.
# Return stable API error envelopes.
# Preserve request correlation IDs.
# Ensure financial operations fail safely.
# Add production-style exception tests.

24. app/Http/Requests/Web/ContactRequest.php
# PUBLIC INPUT AGENT:
# Audit contact-form validation, spam/rate limiting, field length,
# URL/header injection, HTML/script payloads and localization.
# Preserve user content safely.
# Do not allow contact form to become email/header injection.
# Add abuse/security tests.

------------------------------------------------------------
## GROUP F — DATABASE / DATA RETENTION / PERFORMANCE
------------------------------------------------------------

25. database/migrations/*financial*.php
# DATABASE AGENT:
# Audit ALL financial migrations line-by-line.
# Verify:
# wallet foreign keys
# wallet currency uniqueness
# ledger indexes
# payment idempotency indexes
# provider event uniqueness
# withdrawal idempotency
# bet idempotency
# prize claim uniqueness
# transaction references
# decimal precision
# status indexes
# timestamps
# Do not mutate already-applied production migrations unsafely.
# Create forward migrations when necessary.
# Add fresh-database migration tests.

26. database/migrations/*result*.php
# DATABASE/LOTTERY AGENT:
# Audit result/draw/archive schema.
# Verify lane + draw date + result identity uniqueness.
# Verify publication status indexing.
# Verify provenance/checksum/source metadata storage.
# Verify future/unpublished/retracted filtering can be indexed efficiently.
# Add migration/data-integrity tests.

27. app/Services/Privacy/PrivacyPolicyService.php
# LEGAL/PRIVACY AGENT:
# If this service is absent, CREATE it.
# Make privacy content versioned and date-stable.
# Do not use daily runtime `date()` as a legal policy effective/update date.
# Keep operator/legal configuration centralized.
# Localize EN/TH.
# Add version/effective-date tests.

------------------------------------------------------------
## GROUP G — OBSERVABILITY / RELEASE
------------------------------------------------------------

28. app/Services/Monitoring/HealthCheckService.php
# OBSERVABILITY AGENT:
# If absent, CREATE it.
# Provide real checks for:
# database
# queue
# cache
# storage
# payment configuration
# result freshness
# reconciliation status
# Do not claim “healthy” when dependencies are unavailable.
# Separate liveness from readiness.
# Do not expose sensitive infrastructure details publicly.
# Add health/readiness tests.

29. deployment/CUTOVER-CHECKLIST.md
# RELEASE AGENT:
# Make this the executable production gate.
# Include:
# exact release commit
# exact artifact checksum
# dependency install
# asset build
# migration backup
# DB restore test
# queue workers
# scheduler
# storage link
# HTTPS
# session/cookie domain
# APP_URL
# payment webhooks
# real provider credentials
# GLO approved data import
# legal/operator source
# support/app links
# monitoring
# smoke test
# rollback
# post-cutover crawl
# no secret leakage
# No “green tests = production ready” shortcut.

30. tests/Feature/FinalProductionReadinessTest.php
# NEW MASTER TEST — TEST AGENT:
# Create the FINAL integration gate.
# Test actual application wiring for:
# public routes
# auth/member routes
# result publication
# result provenance
# historical archive state
# wallet isolation
# payment capability
# deposit initiation
# withdrawal state
# webhook replay
# bet purchase
# responsible gaming
# GLO proportional prize
# prize claim
# KYC
# agent commission
# notification state
# sitemap
# legal content
# locale parity
# fixture hard-stop
# deployment manifest
# No fabricated data.
# No fake provider success.
# No double settlement.
# No cross-user access.

============================================================
# 5. PUBLIC PAGE NO-SKIP MATRIX
============================================================

Test ALL public pages in EN and TH:

/
about
vision
terms
privacy
fees
discounts
account-verification-guide
account-grades
prize-verification
national-lottery
weekly-lottery
bingo-lottery
pcso-lottery
results
contact
sitemap.xml

For every page verify:

[ ] HTTP success/expected status
[ ] correct canonical route
[ ] correct title/meta
[ ] EN rendering
[ ] TH rendering
[ ] no raw translation key
[ ] no fake identity
[ ] no fake result
[ ] no fake payment claim
[ ] no unsafe legacy wording
[ ] no internal API leak
[ ] no demo identifiers
[ ] correct CTA
[ ] correct empty/error state

============================================================
# 6. PLAYER PAGE NO-SKIP MATRIX
============================================================

Test:

member-login
member-register
forgot-password
dashboard
draws
draw-detail
bets
bet slip
wallet
deposit
withdraw
profile
responsible gaming
verification
payment return/cancel/failure

For EVERY page:

[ ] auth rule
[ ] authorization
[ ] CSRF
[ ] throttle
[ ] locale
[ ] no cross-user data
[ ] no fake balance
[ ] no fake payment status
[ ] no duplicate submission
[ ] correct error handling
[ ] correct success handling

============================================================
# 7. DATA COMPLETENESS GATE
============================================================

Historical result data is NOT considered complete merely because:

- route exists
- test fixture exists
- empty page renders
- archive link resolves

Production completeness requires:

source-of-record payload
→ validation
→ checksum
→ provenance
→ import
→ reconciliation
→ publication
→ archive
→ public page

Every lane:

National
Weekly
Mega/Bingo
PCSO

must be checked separately.

============================================================
# 8. PAYMENT COMPLETENESS GATE
============================================================

For every launched gateway:

[ ] credentials
[ ] enabled flag
[ ] supported currency
[ ] deposit capability
[ ] withdrawal capability
[ ] real API integration
[ ] timeout
[ ] retry
[ ] idempotency
[ ] signed webhook
[ ] replay protection
[ ] reconciliation
[ ] failure reversal
[ ] operator monitoring
[ ] production smoke test

A gateway class existing is NOT provider verification.

============================================================
# 9. RUST COMPLETENESS GATE
============================================================

Run:

cargo fmt --check
cargo check
cargo test
cargo clippy --all-targets --all-features -- -D warnings

Verify:

[ ] bounded input before allocation
[ ] malformed input rejection
[ ] canonical serialization
[ ] checksum verification
[ ] authenticity distinction
[ ] unsafe code prohibited
[ ] dependency lock verified

If Cargo/Rust is unavailable:

NOT VERIFIED

Do not mark it PASS.

============================================================
# 10. TEST NO-SKIP POLICY
============================================================

For each critical test suite report:

TOTAL
PASSED
FAILED
SKIPPED
NOT VERIFIED

Example:

TOTAL: 40
PASSED: 38
FAILED: 0
SKIPPED: 1
NOT VERIFIED: 1

That is NOT 40/40 verified.

Do not write:

“40 tests green”

when one test is skipped/unverified.

============================================================
# 11. FULL-FILE OUTPUT
============================================================

At completion provide:

1. Every changed file.
2. Every NEW file.
3. COMPLETE final content of every changed/new file.
4. Every migration changed/created.
5. Every test changed/created.
6. Exact commands executed.
7. Exact PASS count.
8. Exact FAIL count.
9. Exact SKIP count.
10. Exact NOT VERIFIED count.
11. Reason for every skip/unverified item.
12. Exact remaining OPEN findings.
13. Exact deployment prerequisites.
14. Exact files not modified and why.

FORBIDDEN:

...
omitted
same as above
existing code
unchanged section

============================================================
# 12. FINAL ACCEPTANCE GATE
============================================================

DO NOT declare PROMPT 4 COMPLETE unless:

[ ] all 30 target files inspected
[ ] all required fixes implemented
[ ] existing logic preserved
[ ] no placeholders
[ ] no fake business data
[ ] no fake government/GLO affiliation
[ ] no fake payment success
[ ] no fake payout completion
[ ] no fake wallet balance
[ ] no fake prize settlement
[ ] no raw internal API leakage
[ ] no raw translation keys
[ ] no cross-user data access
[ ] no cross-currency wallet contamination
[ ] no double financial settlement
[ ] no webhook replay double-credit
[ ] no frontend trust-boundary violations
[ ] all critical DB uniqueness constraints verified
[ ] all public pages checked
[ ] all player pages checked
[ ] all four lottery lanes checked
[ ] historical data status explicitly proven
[ ] provider status explicitly proven
[ ] Rust status explicitly proven
[ ] deployment artifact verified
[ ] monitoring/readiness verified
[ ] rollback procedure verified
[ ] tests actually executed
[ ] skipped tests explicitly reported
[ ] unverified tests explicitly reported
[ ] full changed-file contents supplied

============================================================
# FINAL COMMAND
============================================================

START NOW.

FIRST inspect CURRENT repository state.

DO NOT trust previous completion reports.

DO NOT skip any target.

DO NOT skip code.

DO NOT skip tests.

DO NOT hide skipped tests.

DO NOT weaken tests.

DO NOT delete failures.

DO NOT use placeholders.

DO NOT replace existing logic with demos.

IMPLEMENT every required fix.

TRACE every actual call path.

TEST every fix.

RETURN COMPLETE FILE CONTENT.

REPORT EXACT VERIFIED STATUS.

ONLY THEN report remaining OPEN gaps.