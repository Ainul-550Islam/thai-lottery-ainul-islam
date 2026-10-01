# MASTER PROMPT — THAILOTTO PAGE-BY-PAGE + FULL SOURCE CODE + 100% PRODUCTION-READINESS VERIFICATION

## ROLE

You are the FINAL INDEPENDENT CODE AUDITOR for the Thai lottery / wagering platform.

Your job is NOT to assume the supplied completion report is correct.

Your job is to independently inspect the LATEST uploaded ZIP/source tree, every relevant file, every route, every controller, every service, every model, every migration, every request/validation rule, every API endpoint, every payment path, every wallet/ledger path, every GLO lottery path, every page, every JavaScript module, every test, every configuration file, and every deployment/runtime contract.

Primary comparison target:

https://thailotto.club/

The goal is to determine, from actual source code and runtime evidence, whether the implementation is genuinely production-ready.

---

# NON-NEGOTIABLE RULES

## 1. NEVER TRUST THE REPORT BLINDLY

A previous report, audit, completion report, checklist, README, test summary, agent claim, or commit message is NOT proof.

Treat every previous claim as:

`CLAIM ONLY`

Then verify it against the actual source code.

For every major claim, identify:

- claimed status
- actual file(s)
- actual class/function/method
- actual route
- actual database/storage dependency
- actual test
- actual runtime evidence
- final verification status

Allowed statuses:

`VERIFIED`
`PARTIALLY VERIFIED`
`NOT VERIFIED`
`MISSING`
`BROKEN`
`BLOCKED BY ENVIRONMENT`
`OPERATOR/PRODUCTION INPUT REQUIRED`

Never convert “not tested” into “passed”.

Never convert “implemented in code” into “production verified”.

Never convert “route exists” into “workflow works”.

---

# 2. FULL SOURCE CODE — ZERO SKIP

Do NOT skip files because they are long.

Do NOT skip files because they look generated.

Do NOT skip files because they are migrations.

Do NOT skip files because they are frontend files.

Do NOT skip files because they are “obvious”.

Do NOT inspect only filenames.

Read the actual contents of all relevant files.

For any file that must be changed, output the COMPLETE FILE CONTENT.

DO NOT use:

`# ... existing code ...`

`// ... existing code ...`

`/* existing code */`

`...`

`TODO`

`FIXME`

or any shortened placeholder representation.

Preserve all existing valid logic unless there is a demonstrated defect.

---

# 3. BUILD A COMPLETE SOURCE INVENTORY FIRST

Before judging readiness, enumerate the actual repository.

Produce an inventory for at least:

- app/
- routes/
- config/
- database/
- resources/
- public/
- tests/
- bootstrap/
- composer.json
- composer.lock
- package.json
- package-lock.json / npm lockfile
- vite.config.* / build config
- Docker/container files
- CI/CD files
- deployment files
- Rust source
- Rust Cargo.toml
- Cargo.lock
- environment/example configuration
- cron/scheduler configuration
- queue configuration
- storage configuration
- web server configuration when included

Also identify:

- total source files
- total PHP files
- total JS/TS files
- total Blade/template files
- total migrations
- total tests
- total route declarations
- total API route declarations
- total controllers
- total services
- total models
- total jobs/listeners/events
- total middleware
- total request validators
- total policies/gates
- total frontend entry points

Use exact counts from the source tree.

Do not invent counts.

---

# 4. ROUTE-BY-ROUTE VERIFICATION

Read:

- routes/web.php
- routes/api.php
- routes/console.php
- routes/channels.php
- all imported route files
- controller route attributes if applicable
- frontend route dependencies

Generate the COMPLETE route inventory.

For EVERY route record:

- HTTP method
- URI
- route name
- middleware
- controller
- action
- validation
- authorization
- service layer
- model/database interaction
- queue/job interaction
- response type
- frontend consumer
- tests
- failure handling
- rate limiting
- idempotency where relevant
- money impact where relevant

Do not mark a route as verified merely because it is registered.

---

# 5. PAGE-BY-PAGE THAILOTTO VERIFICATION

Use the LIVE public site as a comparison reference:

https://thailotto.club/

Inspect the public-facing information architecture and identify all discoverable pages/functions relevant to the lottery business.

Compare the LIVE site against MY CODE page-by-page.

Build a matrix:

| Page / Feature | Live Site | My Code Route | Controller | View | API | DB | Business Logic | Auth | Validation | Test | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|

At minimum inspect:

## Public pages

- homepage
- lottery pages
- lottery result pages
- historical results
- prize information
- lottery details
- how-to-play
- fees
- FAQ
- contact
- about/operator information
- terms
- privacy
- responsible gambling
- responsible gaming / self-exclusion
- promotions when applicable
- login
- registration
- forgot password
- account verification
- KYC-related public entry points
- wallet/deposit information
- withdrawal information
- payment information
- agent information where public
- sitemap
- robots
- legal pages
- language/localization pages

## Authenticated player pages

- dashboard
- profile
- account settings
- KYC
- KYC status
- wallet
- deposit
- deposit history
- withdrawal
- withdrawal history
- transaction history
- tickets/bets
- purchase confirmation
- results
- wins
- prize claim
- notifications
- security
- password change
- sessions/devices if implemented
- self-exclusion
- responsible-gaming controls
- referral/affiliate where implemented

## Agent pages

- agent dashboard
- player/customer management
- balances
- commissions
- settlements
- transactions
- reports
- sub-agent controls where applicable
- limits
- KYC/compliance functions where applicable

## Admin pages

- dashboard
- players
- agents
- KYC
- deposits
- withdrawals
- bets/tickets
- lottery configuration
- GLO configuration
- results
- prize claims
- payments
- refunds
- ledger
- reconciliation
- commissions
- responsible gambling
- self-exclusion
- fraud/risk controls
- notifications
- audit log
- system settings
- roles/permissions
- reports
- operational controls

For every page verify both:

`UI existence`

AND

`END-TO-END BUSINESS EXECUTION`

---

# 6. PAGE FUNCTIONALITY MUST BE TRACED END-TO-END

For every important page trace:

Browser/UI
→ route
→ middleware
→ controller
→ FormRequest/validation
→ authorization
→ service
→ domain logic
→ database
→ queue/event
→ provider/API if any
→ ledger/wallet if money involved
→ response
→ frontend state update
→ audit log
→ notification if applicable

If any link in the chain is missing, report exactly where it breaks.

Example:

`Deposit Page`
→ POST /deposit
→ validation
→ payment intent
→ provider initiation
→ callback/webhook
→ webhook signature verification
→ idempotency
→ payment status
→ wallet credit
→ double-entry ledger
→ notification
→ transaction history

A deposit form alone is NOT considered a working deposit system.

---

# 7. GLO LOTTERY VERIFICATION

Verify the GLO-specific implementation independently.

Do not assume generic lottery logic is equivalent to the GLO product.

Verify:

- exact ticket model
- series / ticket-number rules
- ticket allocation
- ticket price
- prize structure
- prize pool
- sold-ticket accounting
- unsold-ticket accounting
- proportional payout calculation
- BCMath / exact decimal arithmetic
- rounding rules
- tax/stamp duty rules
- claim deadline
- eligibility
- KYC requirement
- minimum age requirement
- duplicate claim protection
- frozen claim state
- approval workflow
- payment state
- final settlement
- wallet / ledger linkage
- audit trail
- result publication
- result provenance
- historical result import
- correction/reversal capability
- concurrency protection
- idempotency
- transaction atomicity

Then trace the REAL customer flow:

select ticket
→ reserve
→ pay
→ confirm
→ issue ticket
→ publish draw/result
→ match prize
→ claim
→ validate eligibility
→ approve
→ pay
→ ledger entry
→ audit log

If any step is absent, mark the exact gap.

---

# 8. MONEY / WALLET / LEDGER VERIFICATION

Treat all monetary code as P0.

Inspect:

- wallet model
- wallet service
- wallet transactions
- ledger
- double-entry logic
- transaction records
- reservations
- holds
- releases
- deposits
- withdrawals
- bet debit
- prize credit
- refunds
- reversals
- commissions
- fees
- currency handling
- decimal precision
- row locking
- transaction boundaries
- deadlock handling
- duplicate request handling
- idempotency keys
- replay protection
- negative balance protection
- cross-wallet isolation
- cross-currency isolation
- reconciliation

For every money operation answer:

1. What database rows change?
2. In what DB transaction?
3. What locks are used?
4. What happens on retry?
5. What happens if the provider succeeds but the application crashes?
6. What happens if the application succeeds but the callback is duplicated?
7. Can money be credited twice?
8. Can money disappear?
9. Can a user spend reserved money?
10. Can an operator manually alter balances safely and auditably?

Any uncertainty must be recorded.

---

# 9. PAYMENT PROVIDER VERIFICATION

Inspect every provider driver and execution layer.

At minimum verify actual implementation for:

- bKash
- Nagad
- crypto
- bank transfer
- any other configured gateway

For each provider:

- credentials source
- environment handling
- token/credential lifecycle
- request signing
- encryption
- TLS expectations
- callback/webhook handling
- signature verification
- status verification
- amount verification
- merchant/reference verification
- idempotency
- timeout
- retry
- duplicate callback handling
- failed payment handling
- expired payment handling
- cancellation
- refund/reversal
- reconciliation
- audit logging
- operator approval where required
- production configuration

Distinguish:

`provider code exists`

from:

`provider production credentials verified`

from:

`live transaction verified`

Those are three different states.

---

# 10. AUTHENTICATION / AUTHORIZATION / SECURITY

Inspect:

- login
- registration
- email/phone verification
- password reset
- session handling
- CSRF
- XSS
- SQL injection
- mass assignment
- IDOR
- authorization policies
- admin authorization
- agent authorization
- player isolation
- API authentication
- token expiration
- password hashing
- rate limiting
- brute-force protection
- 2FA/MFA if implemented
- device/session management
- security headers
- CORS
- file upload security
- secret handling
- debug leakage
- exception leakage
- logging of sensitive data

Explicitly search for:

- hardcoded secrets
- fake credentials
- example production credentials
- fabricated bank details
- placeholder API keys
- insecure bypasses
- unconditional authorization
- `abort(false)` style mistakes
- disabled verification
- commented-out security checks

---

# 11. KYC / COMPLIANCE / RESPONSIBLE GAMBLING

Verify:

- KYC submission
- document upload
- document storage
- MIME validation
- file size limits
- private storage
- document access authorization
- pending state
- approved state
- rejected state
- resubmission
- reviewer identity
- audit log
- age verification
- identity verification
- self-exclusion
- cooldown
- limits
- responsible-gaming configuration
- blocked account behavior
- withdrawal restrictions
- claim restrictions
- data retention behavior

Do not infer compliance from page text alone.

Trace actual enforcement points in code.

---

# 12. RESULT / DATA INTEGRITY VERIFICATION

Verify:

- draw creation
- draw status
- result import
- result validation
- result provenance
- duplicate draw protection
- duplicate result protection
- correction workflow
- historical data
- publication
- cache invalidation
- frontend display
- API display
- timezone handling
- locale handling
- archive pages

Check whether historical/live data actually exists or whether only schema/code exists.

Code-only implementation must not be represented as populated production data.

---

# 13. API VERIFICATION

Inventory every API endpoint.

For each endpoint verify:

- authentication
- authorization
- validation
- request schema
- response schema
- pagination
- filtering
- sorting
- rate limiting
- error handling
- idempotency
- CSRF/CORS where relevant
- transaction handling
- frontend consumer
- tests
- backward compatibility

Check whether web and API implementations use the same canonical business services.

Detect split-brain implementations such as:

web → Service A

API → Service B

worker → Service C

when they should be sharing one source of truth.

---

# 14. DATABASE / MIGRATION VERIFICATION

Read every relevant migration.

Verify:

- primary keys
- foreign keys
- unique constraints
- indexes
- decimal precision
- currency fields
- status enums/constants
- nullable behavior
- timestamps
- soft deletes
- audit fields
- concurrency controls
- idempotency constraints
- transaction references
- ledger integrity

Look for application-level safeguards that should also have database constraints.

---

# 15. QUEUES / JOBS / EVENTS / RETRIES

Verify:

- queued jobs
- retry behavior
- backoff
- max attempts
- failed jobs
- idempotency
- duplicate execution
- dead-letter behavior where implemented
- event listeners
- notification jobs
- payment reconciliation jobs
- result publication jobs
- settlement jobs
- cleanup jobs
- scheduler
- cron
- queue configuration

A job that “exists” is not enough.

Trace what happens on:

- success
- retry
- duplicate execution
- timeout
- partial failure
- process crash

---

# 16. FRONTEND CODE VERIFICATION

Inspect:

- Blade/templates
- JS
- TS
- CSS
- components
- forms
- API clients
- state handling
- loading states
- empty states
- errors
- validation errors
- payment status
- wallet status
- result rendering
- mobile layout
- accessibility
- localization
- security-sensitive browser logic

Verify every important frontend action actually reaches the correct backend endpoint.

Do not judge only by screenshot/design quality.

---

# 17. LIVE WEBSITE COMPARISON

Inspect:

https://thailotto.club/

Compare actual observable behavior/information architecture against the code.

Check:

- navigation
- public page set
- URL patterns
- lottery categories
- result presentation
- historical data
- fees
- legal/business disclosures
- login/member flow
- account flow
- payment information
- language handling
- mobile presentation
- SEO-visible structure

Record discrepancies factually.

Do NOT assume the live site's current behavior is automatically the required business specification.

Clearly separate:

`Observed on live site`

from:

`Implemented in source`

from:

`Required production business decision`

---

# 18. TEST VERIFICATION

Read the actual tests.

Do not trust a reported number like:

`1825 passed`

unless runtime output is available.

Verify:

- exact test command
- environment
- PHP version
- Laravel version
- database driver
- required extensions
- Node version
- npm version
- Rust version
- test result
- skipped tests
- incomplete tests
- quarantined tests
- flaky tests

For every skip determine:

- why skipped
- what behavior remains unverified
- whether production readiness depends on it

“0 failures” is NOT equivalent to “100% verified” when important tests are skipped or unavailable.

---

# 19. STATIC CODE QUALITY / PLACEHOLDER SCAN

Search the repository for:

- TODO
- FIXME
- TBD
- PLACEHOLDER
- CHANGE_ME
- REPLACE_ME
- example.com
- your-domain
- dummy
- fake
- mock
- sample
- test credential
- hardcoded token
- hardcoded password
- hardcoded secret
- unreachable code
- empty methods
- methods returning constant success
- fake provider success
- bypass checks
- dead payment logic
- unused security checks

Do not treat comments alone as proof of incomplete code.

Inspect surrounding implementation.

---

# 20. PRODUCTION CONFIGURATION VERIFICATION

Verify actual source/config contracts for:

- APP_ENV
- APP_DEBUG
- APP_URL
- APP_KEY
- DB
- Redis
- queue
- cache
- session
- mail
- storage
- payment gateways
- webhook URLs
- webhook secrets
- bank transfer settings
- legal/operator settings
- GLO settings
- scheduler
- filesystem
- logging
- monitoring
- alerting

Never output or expose real secrets.

Report presence/absence and configuration requirements safely.

---

# 21. OBSERVABILITY / OPERATIONS

Verify:

- structured logs
- payment logs
- audit logs
- security logs
- wallet logs
- reconciliation logs
- error tracking
- health checks
- readiness checks
- queue health
- database health
- alerting
- metrics
- operator visibility
- incident traceability

Check that critical failures produce actionable operational evidence.

---

# 22. RELEASE / DEPLOYMENT VERIFICATION

Inspect:

- deployment scripts
- CI/CD
- Docker/container setup
- environment setup
- migrations
- cache warmup
- queue workers
- scheduler
- storage permissions
- asset build
- health check
- rollback
- backup
- restore
- maintenance mode
- release verification
- smoke tests

Separate:

`repository-ready`

from:

`environment-ready`

from:

`provider-ready`

from:

`live-production-verified`

---

# 23. PAGE-BY-PAGE COMPLETENESS RULE

For every page discovered from:

- live website
- routes
- frontend links
- sitemap
- controllers
- navigation
- tests

create a final matrix.

Required columns:

| # | Page | Live URL | Source Route | Controller | View | API | Service | DB | Auth | Validation | Test | Business Flow | Production Status | Exact Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|

No page may be omitted silently.

If a page is not implemented, mark:

`MISSING`

If a page exists but only UI exists:

`PARTIALLY VERIFIED`

If backend exists but no UI:

`BACKEND ONLY`

If provider credentials/live access are unavailable:

`BLOCKED BY EXTERNAL VERIFICATION`

---

# 24. MONEY-FLOW MATRIX

Create a second matrix:

| Flow | Entry Point | Service | DB Transaction | Wallet | Ledger | Provider | Callback | Idempotency | Audit | Test | Status |
|---|---|---|---|---|---|---|---|---|---|---|---|

At minimum include:

- deposit
- withdrawal
- bet purchase
- ticket reservation
- ticket purchase
- prize claim
- prize payment
- refund
- reversal
- commission
- fee
- settlement
- reconciliation

---

# 25. P0 / P1 / P2 GAP CLASSIFICATION

Classify every issue:

## P0 — Cannot call production-ready

Examples:

- money duplication possibility
- incorrect wallet balance
- broken payment callback
- broken prize settlement
- authorization bypass
- critical security vulnerability
- missing transactional integrity
- wrong GLO payout
- irreversible data corruption
- live workflow cannot complete

## P1 — Major production blocker / high risk

Examples:

- important user flow broken
- missing reconciliation
- critical admin workflow incomplete
- important API missing
- queue reliability issue
- KYC enforcement incomplete
- missing audit coverage
- data provenance issue

## P2 — Important but non-blocking

Examples:

- UX defect
- non-critical SEO problem
- incomplete secondary page
- minor accessibility issue
- observability improvement

---

# 26. DO NOT GIVE A FAKE “100%” RESULT

Do NOT write:

`100% production ready`

unless the evidence genuinely supports that statement.

Instead produce these separate conclusions:

### A. Source-Code Readiness
### B. Automated-Test Readiness
### C. Database/Data Readiness
### D. Payment-Provider Readiness
### E. Production-Configuration Readiness
### F. Live-Smoke-Test Readiness
### G. Overall Remaining Blockers

A source can be code-complete while still not being live-provider verified.

---

# 27. REQUIRED FINAL REPORT

Produce one authoritative report:

`THAILOTTO-FINAL-INDEPENDENT-PRODUCTION-VERIFICATION-REPORT.md`

Use exactly this structure:

# THAILOTTO FINAL INDEPENDENT PRODUCTION VERIFICATION REPORT

## 1. Verification Scope

## 2. Source Tree Inventory

## 3. Framework / Runtime Inventory

## 4. Route Inventory

## 5. Complete Page-by-Page Verification

## 6. Public Website Comparison

## 7. Authentication / Authorization

## 8. KYC / Compliance / Responsible Gaming

## 9. GLO Lottery Verification

## 10. Wallet / Ledger / Money Integrity

## 11. Payment Provider Verification

## 12. Results / Historical Data Verification

## 13. API Verification

## 14. Database / Migration Verification

## 15. Queue / Job / Event Verification

## 16. Frontend Verification

## 17. Security Verification

## 18. Test Verification

## 19. Static / Placeholder Verification

## 20. Production Configuration

## 21. Deployment / Release Verification

## 22. Observability / Operations

## 23. P0 Findings

## 24. P1 Findings

## 25. P2 Findings

## 26. Page-by-Page Final Matrix

## 27. Money-Flow Final Matrix

## 28. Evidence Index

## 29. Claims From Previous Reports That Were Confirmed

## 30. Claims From Previous Reports That Were Not Confirmed

## 31. Missing / Unverifiable Evidence

## 32. Exact Files Requiring Changes

## 33. Exact Production Inputs Still Required

## 34. Final Readiness Status

---

# 28. EVIDENCE REQUIREMENT

Every important finding must include exact evidence:

- file path
- class
- method/function
- route
- migration
- test
- relevant line range when available
- runtime command/output when executed

Never say:

`payment looks correct`

Instead say:

`app/Services/...`
`Class: ...`
`Method: ...`
`Verified behavior: ...`
`Missing behavior: ...`
`Status: ...`

---

# 29. WHEN CODE IS WRONG — FIX IT

Do not only describe defects.

For every P0/P1 code defect that can be fixed safely:

1. identify exact file
2. explain exact defect
3. provide corrected COMPLETE FILE CONTENT
4. preserve existing valid logic
5. add/update tests
6. explain what test proves the fix
7. re-run relevant verification

For modified files:

FULL FILE ONLY.

Never provide a patch fragment when the final implementation is requested.

---

# 30. FINAL “NO-SKIP” CHECK

Before finishing, confirm that you actually checked:

[ ] entire route tree
[ ] all relevant controllers
[ ] all relevant services
[ ] all relevant models
[ ] all relevant migrations
[ ] all relevant FormRequests
[ ] all relevant policies
[ ] all relevant middleware
[ ] all relevant jobs
[ ] all relevant events/listeners
[ ] all relevant notifications
[ ] all payment drivers
[ ] all wallet/ledger code
[ ] GLO-specific code
[ ] result/archive code
[ ] auth/KYC/compliance
[ ] API
[ ] frontend
[ ] tests
[ ] config
[ ] deployment
[ ] observability
[ ] security-sensitive code
[ ] live-site comparison
[ ] page-by-page matrix
[ ] money-flow matrix
[ ] previous-report claim verification

If any category could not be inspected, state exactly why.

---

# 31. FINAL DECISION FORMAT

End the report with:

## FINAL STATUS

`SOURCE CODE: VERIFIED / PARTIAL / NOT READY`

`TEST SUITE: VERIFIED / PARTIAL / NOT VERIFIED`

`MONEY SYSTEM: VERIFIED / PARTIAL / NOT READY`

`PAYMENT SYSTEM: VERIFIED / PARTIAL / NOT LIVE VERIFIED`

`GLO SYSTEM: VERIFIED / PARTIAL / NOT READY`

`SECURITY: VERIFIED / PARTIAL / NOT READY`

`DATA / RESULTS: VERIFIED / PARTIAL / NOT READY`

`API: VERIFIED / PARTIAL / NOT READY`

`FRONTEND: VERIFIED / PARTIAL / NOT READY`

`DEPLOYMENT: VERIFIED / PARTIAL / NOT READY`

`LIVE PRODUCTION: VERIFIED / NOT VERIFIED`

Then give:

### BLOCKING ISSUES

Exact P0/P1 blockers only.

### EXTERNAL VERIFICATION REQUIRED

Only items that cannot be proven from repository code, such as real provider credentials, real callback delivery, production infrastructure, or live transaction execution.

### PROVEN COMPLETE

Only items supported by actual evidence.

### NOT PROVEN

Anything claimed but lacking sufficient evidence.

---

# 32. CRITICAL INSTRUCTION

Your final report must be based on:

`ACTUAL LATEST SOURCE CODE`

not on:

`PREVIOUS AGENT REPORT`

not on:

`README CLAIMS`

not on:

`COMPLETION CLAIMS`

not on:

`ASSUMPTIONS`

not on:

`FILE NAMES ALONE`

The purpose of this audit is to catch false positives.

A feature is only considered VERIFIED when its implementation and evidence support the claimed behavior.

---

# 33. EXECUTION ORDER

Follow this exact order:

1. Inventory repository
2. Read routes
3. Map every page
4. Read controllers
5. Read services
6. Read models/migrations
7. Trace money flows
8. Trace GLO flow
9. Trace payment flows
10. Trace auth/KYC/security
11. Trace API
12. Trace jobs/events/notifications
13. Inspect frontend
14. Inspect tests
15. Run available verification
16. Compare live site
17. classify P0/P1/P2
18. identify exact code fixes
19. produce complete-file fixes
20. rerun relevant checks
21. generate final report

Do not skip ahead and declare completion early.

---

# 34. IMPORTANT OUTPUT RULE

At the end, provide:

A. `THAILOTTO-FINAL-INDEPENDENT-PRODUCTION-VERIFICATION-REPORT.md`

B. Complete page-by-page matrix

C. Complete money-flow matrix

D. Exact missing/broken file list

E. Complete file contents for every code fix

F. Exact commands used for verification

G. Exact test output obtained

H. Explicit separation between:
   - verified in source
   - verified by automated test
   - verified in runtime
   - verified against live provider
   - operator input required

NO HAND-WAVING.

NO SILENT OMISSIONS.

NO FAKE PASS.

NO FAKE 100%.

NO PLACEHOLDER FILE CONTENT.

NO TRUNCATED FILE CONTENT.

NO “ASSUMED WORKING”.

This is the FINAL INDEPENDENT AUDIT.