# THAILOTTO ENTERPRISE WAGERING PLATFORM
# PAGES 251–350 — RUNTIME ACTIVATION / BACKEND COMPLETION / PRODUCTION HARDENING PROMPT

## 0. MISSION

Continue from the completed Pages 150–250 static phase.

This phase is intentionally different from Pages 150–250.

Pages 150–250 already created:

- operational page coverage
- route matrices
- security matrices
- financial-integrity matrices
- Rust matrices
- static contract coverage
- fail-closed projections
- EN/TH localization coverage
- architecture inventory
- explicit runtime boundary

Pages 251–350 must now move from:

```text
STATIC STRUCTURE
        ↓
RUNTIME-VERIFIED CANONICAL IMPLEMENTATION
        ↓
REAL DATABASE / QUEUE / PROVIDER / RUST EXECUTION
        ↓
END-TO-END FINANCIAL + LOTTERY INTEGRITY
        ↓
PRODUCTION ACCEPTANCE
```

Do NOT simply add another 100 presentation-only pages.

Use the existing architecture and activate the real backend contracts.

---

# 1. SOURCE-OF-TRUTH RULE FOR THIS PHASE

The Pages 150–250 implementation already identified canonical DTOs, enums, events, exceptions, services, commands and domain models across:

- Finance
- Payment
- Payout
- Lottery
- Draw
- Prize
- Ticket
- Agent
- Compliance
- Responsible Gaming
- Security
- Notification
- Queue
- Operations

Reuse those existing objects.

Do not create parallel replacements.

Before adding a class search for the existing canonical implementation.

Examples of existing architectural objects that must be reused where applicable include:

```text
app/DTOs/Finance/*
app/DTOs/Payment/*
app/DTOs/Payout/*
app/DTOs/Prize/*
app/DTOs/Draw/*
app/DTOs/Ticket/*
app/DTOs/Betting/*
app/DTOs/Compliance/*
app/DTOs/Security/*
app/DTOs/Notification/*
app/DTOs/ResponsibleGaming/*
app/DTOs/Operations/*
app/Enums/*
app/Events/*
app/Exceptions/*
app/Services/*
app/Console/Commands/*
```

The architecture inventory already records these domains; do not rebuild them under new namespaces merely to satisfy a page.

---

# 2. ABSOLUTE RUNTIME RULE

The previous phase explicitly stopped at:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

This phase must attempt to remove that boundary.

If the execution environment still lacks:

- PHP
- Composer
- Laravel vendor
- database
- Redis/queue
- browser runner
- Cargo/Rust
- configured external providers

then do NOT fake verification.

Instead:

```text
BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE
```

and continue with every independent test/implementation that is actually executable.

Never downgrade a missing runtime dependency into a passing static check.

---

# 3. PAGE 251 — RUNTIME ENVIRONMENT BOOTSTRAP

Create a deterministic runtime preflight.

Verify:

- PHP version
- required extensions
- Composer
- Node
- NPM
- Laravel framework
- database driver
- Redis
- queue dependencies
- storage
- browser tooling
- Rust/Cargo

Produce machine-readable output.

No secrets.

---

# 4. PAGE 252 — DEPENDENCY INSTALLATION VERIFICATION

Execute:

```bash
composer validate
composer install --no-interaction
npm ci
```

Verify:

- lockfile consistency
- vendor availability
- package resolution
- PHP extension compatibility
- frontend dependency resolution

Record exact command result.

---

# 5. PAGE 253 — LARAVEL BOOT VERIFICATION

Execute:

```bash
php artisan about
php artisan route:list
php artisan config:show
```

Verify:

- container boots
- routes resolve
- configuration loads
- service providers load
- no fatal dependency error

---

# 6. PAGE 254 — DATABASE CONNECTION VERIFICATION

Verify:

- configured database
- connection
- charset
- collation
- strict mode
- transaction support
- timezone behavior

No production connection strings in reports.

---

# 7. PAGE 255 — MIGRATION BASELINE

Run:

```bash
php artisan migrate:status
```

Compare:

- migration files
- applied migrations
- pending migrations
- batch numbers
- production-safe status

Do not automatically migrate production.

---

# 8. PAGE 256 — SEEDER SAFETY AUDIT

Inspect all seeders.

Classify:

```text
development-only
test-only
fixture-only
reference data
production-safe
```

Ensure test fixtures cannot silently populate production lottery results.

---

# 9. PAGE 257 — FACTORY / FIXTURE AUDIT

Audit factories for:

- fabricated user identities
- fake wallet balances
- fake financial transactions
- fake official lottery results

Fixtures must be explicitly labeled and isolated from production flows.

---

# 10. PAGE 258 — DATABASE CONSTRAINT AUDIT

Review:

- foreign keys
- unique indexes
- composite indexes
- money precision
- enum-compatible state storage
- timestamps
- nullable fields
- cascade behaviour

Identify every missing domain invariant.

---

# 11. PAGE 259 — DATABASE TRANSACTION AUDIT

Trace financial operations and verify transaction boundaries.

At minimum:

```text
deposit credit
withdrawal hold
withdrawal completion
bet purchase
refund
prize settlement
commission settlement
```

Each must have an atomicity story.

---

# 12. PAGE 260 — CONCURRENCY TEST HARNESS

Create runtime tests for simultaneous:

- bet submissions
- deposits
- withdrawals
- payment webhooks
- prize claims
- ticket reservations

Verify no double-spend / double-credit / duplicate issuance.

---

# 13. PAGE 261 — WALLET INTEGRITY ACTIVATION

Connect the wallet UI to the actual canonical wallet service.

Verify:

- available balance
- locked balance
- currency
- pending holds
- ledger relation

No presentation query may become the source of truth.

---

# 14. PAGE 262 — WALLET LEDGER BALANCE REBUILD

Implement a controlled balance-audit command/service.

Compare:

```text
wallet balance
vs
ledger-derived balance
vs
locked balance
```

Return explicit discrepancies.

---

# 15. PAGE 263 — WALLET DOUBLE-SPEND TEST

Run concurrent debit tests.

Cases:

- one balance
- two simultaneous bets
- one sufficient balance

Expected:

- no negative balance
- no duplicate debit
- deterministic outcome

---

# 16. PAGE 264 — WALLET HOLD / RELEASE

Verify hold lifecycle:

```text
create
-> active
-> capture/release
-> terminal
```

Ensure stale holds are not silently released.

---

# 17. PAGE 265 — WALLET RESERVATION EXPIRY

Activate canonical reservation-expiry processing.

Verify:

- expired reservation
- release
- audit
- idempotency

---

# 18. PAGE 266 — FINANCIAL TRANSACTION IDEMPOTENCY

Every financial mutation must have:

- stable reference
- uniqueness
- replay safety
- duplicate handling

Add runtime duplicate tests.

---

# 19. PAGE 267 — LEDGER POSTING CONTRACT

Trace every wallet-changing service to ledger posting.

No wallet change without corresponding ledger/audit treatment where required by the domain.

---

# 20. PAGE 268 — LEDGER REVERSAL CONTRACT

Verify reversal behavior.

A reversal must:

- reference original transaction
- preserve immutable history
- never overwrite historical financial evidence

---

# 21. PAGE 269 — FINANCIAL RECONCILIATION EXECUTION

Run:

```bash
php artisan finance:reconcile
```

or the exact repository command.

Verify real:

- records
- discrepancies
- status
- report persistence

---

# 22. PAGE 270 — RECONCILIATION EXCEPTION LIFECYCLE

Implement:

```text
detected
-> reviewed
-> accepted / corrected
-> resolved
```

No silent disappearance.

---

# 23. PAGE 271 — DEPOSIT RUNTIME FLOW

Execute real test:

```text
player
-> deposit request
-> PaymentInitiationService
-> provider adapter
-> callback
-> verified payment
-> wallet credit
-> ledger
```

Verify no credit before canonical callback.

---

# 24. PAGE 272 — DEPOSIT DUPLICATE CALLBACK

Send same callback multiple times.

Expected:

```text
one financial effect
many harmless replays
```

---

# 25. PAGE 273 — PAYMENT CALLBACK OWNERSHIP

Verify one player cannot query another player's callback projection.

Query parameters alone must never establish ownership.

---

# 26. PAGE 274 — PAYMENT SIGNATURE VALIDATION

Runtime-test:

- valid
- invalid
- tampered
- replayed
- stale
- malformed

---

# 27. PAGE 275 — PAYMENT PROVIDER ERROR MATRIX

Exercise:

- timeout
- provider 4xx
- provider 5xx
- malformed response
- missing transaction
- duplicate transaction
- currency mismatch

---

# 28. PAGE 276 — PAYMENT STATE MACHINE

Verify exact transitions among:

- initiated
- pending
- completed
- failed
- cancelled
- refunded
- disputed

Use canonical enums.

---

# 29. PAGE 277 — PAYMENT EVENT PERSISTENCE

Ensure every provider callback creates the proper internal event/audit record.

---

# 30. PAGE 278 — PAYMENT WEBHOOK QUEUE

Verify queue dispatch.

Test:

- queued
- retry
- success
- failure
- terminal failure

---

# 31. PAGE 279 — PAYMENT DEAD-LETTER PROCESSING

Create operational handling for permanently failed webhook events.

No silent drop.

---

# 32. PAGE 280 — PAYMENT REPLAY

Runtime-test safe replay.

Verify idempotency prevents duplicate wallet effects.

---

# 33. PAGE 281 — WITHDRAWAL RUNTIME FLOW

Test:

```text
withdraw request
-> validation
-> RG/KYC
-> hold
-> approval
-> provider
-> callback
-> payout
-> ledger
```

---

# 34. PAGE 282 — WITHDRAWAL DUPLICATE SUBMISSION

Concurrent duplicate withdrawals must not create double holds or double payouts.

---

# 35. PAGE 283 — WITHDRAWAL KYC GATE

Test:

- verified
- pending
- failed
- expired

---

# 36. PAGE 284 — WITHDRAWAL SELF-EXCLUSION / RESTRICTION BEHAVIOR

Verify business-rule-specific withdrawal restrictions without inventing unsupported rules.

---

# 37. PAGE 285 — WITHDRAWAL FAILURE RECOVERY

Test provider failure after wallet hold.

Verify exact recovery:

- hold remains/released according to domain rule
- no duplicate credit/debit
- audit preserved

---

# 38. PAGE 286 — WITHDRAWAL COMPLETION RECONCILIATION

Provider success must reconcile with internal payout and ledger state.

---

# 39. PAGE 287 — BET PURCHASE RUNTIME ACTIVATION

Execute the canonical purchase pipeline:

```text
selection
-> draw
-> product
-> eligibility
-> RG
-> pricing
-> fee
-> wallet
-> reservation
-> ticket/bet
-> ledger
-> confirmation
```

---

# 40. PAGE 288 — BET PRICE AUTHORITY

Verify browser-submitted price cannot override server price.

---

# 41. PAGE 289 — BET CURRENCY AUTHORITY

Verify unsupported currencies are rejected before financial mutation.

---

# 42. PAGE 290 — BET LIMIT ENFORCEMENT

Verify:

- single bet
- daily wagering
- account restrictions
- self-exclusion

using canonical responsible-gaming services.

---

# 43. PAGE 291 — BET CONCURRENCY

Run simultaneous purchases against same wallet.

---

# 44. PAGE 292 — BET IDEMPOTENCY

Repeat same request/idempotency key.

Expected one financial purchase.

---

# 45. PAGE 293 — BET FAILURE ROLLBACK

Inject failure after wallet reservation.

Verify no stranded funds.

---

# 46. PAGE 294 — TICKET ISSUANCE RUNTIME

Verify successful bet generates the canonical ticket/ownership state.

No orphan ticket.

---

# 47. PAGE 295 — TICKET OWNERSHIP

Cross-user access test.

---

# 48. PAGE 296 — TICKET SHARE / QR SECURITY

Where canonical ticket-share/QR architecture exists:

- signed/opaque reference
- expiry
- ownership
- replay

---

# 49. PAGE 297 — TICKET VERIFICATION RUNTIME

Connect public/private verification to canonical result state.

Verify:

- not found
- invalid
- pending
- winning
- losing
- frozen
- claimed

---

# 50. PAGE 298 — DRAW OPEN/CLOSE AUTOMATION

Execute real scheduler command path.

Verify timezone and cutoff.

---

# 51. PAGE 299 — DRAW STATE MACHINE

Runtime-test:

```text
scheduled
-> open
-> closing
-> closed
-> pending result
-> certified
-> published
-> settled
```

Reject illegal transitions.

---

# 52. PAGE 300 — DRAW LOCKING / CUTOFF

Verify a closed draw cannot accept new betting.

---

# 53. PAGE 301 — RESULT IMPORT RUNTIME

Run actual import commands against a controlled source/fixture.

Mark fixture sources explicitly.

---

# 54. PAGE 302 — RESULT PROVENANCE PERSISTENCE

Every imported result must persist:

- source
- source reference
- retrieved time
- version
- normalized value
- verification state

---

# 55. PAGE 303 — RESULT DUPLICATE IMPORT

Re-import same result.

Expected no duplicate official record.

---

# 56. PAGE 304 — RESULT CONFLICT DETECTION

When two sources disagree:

- do not auto-publish
- create conflict state
- preserve both source references
- require canonical decision

---

# 57. PAGE 305 — RESULT CERTIFICATION

Execute canonical certification workflow.

Verify:

- operator
- evidence
- checksum/reference where supported
- certification state

---

# 58. PAGE 306 — RESULT PUBLICATION GATE

Publish only certified/eligible results.

---

# 59. PAGE 307 — RESULT UNPUBLISH / CORRECTION POLICY

If supported, corrections must create a new version/evidence trail.

Do not overwrite historical result.

---

# 60. PAGE 308 — RESULT LEADING-ZERO INTEGRITY

Runtime-test:

```text
000001
001234
049
```

through:

- DB
- API
- Blade
- Rust boundary
- verification

---

# 61. PAGE 309 — NATIONAL LOTTERY DATA LANE

Verify separate product source.

No GLO/N3 crossover.

---

# 62. PAGE 310 — WEEKLY LOTTERY DATA LANE

Verify separate source/result model.

---

# 63. PAGE 311 — MEGA / OTHER PRODUCT DATA LANE

Verify product-specific result isolation.

---

# 64. PAGE 312 — PCSO DATA LANE

Verify 6D/4D/3D/2D result handling and leading zeros.

---

# 65. PAGE 313 — GLO L6 DATA LANE

Verify the dedicated GLO L6 services remain authoritative.

No legacy result controller substitution.

---

# 66. PAGE 314 — GLO L6 PURCHASE CONTRACT

Because prior architecture deliberately kept public GLO L6 purchase fail-closed, determine whether a real purchase contract now exists.

If yes:

- connect it end-to-end.

If not:

```text
NOT_CONFIGURED
```

Do not invent checkout.

---

# 67. PAGE 315 — GLO L6 TICKET RANGE

Runtime-test:

```text
000000
999999
```

and invalid values.

---

# 68. PAGE 316 — GLO L6 PRICING AUTHORITY

Verify the configured price is sourced from canonical configuration/rules.

No browser price authority.

---

# 69. PAGE 317 — GLO PRIZE ALLOCATION

Verify prize structures using canonical GLO rules.

No hardcoded display-only prize values.

---

# 70. PAGE 318 — GLO UNSOLD-TICKET PRIZE SCALING

Runtime-test exact decimal scaling.

Use BCMath/existing exact arithmetic.

---

# 71. PAGE 319 — GLO CLAIM WINDOW

Verify:

- eligible
- open
- expired
- frozen
- claimed

---

# 72. PAGE 320 — GLO PRIZE CLAIM CREATION

Create a real claim only from a valid winning ticket/result.

---

# 73. PAGE 321 — GLO CLAIM DUPLICATE

Repeat claim creation.

Expected one canonical claim.

---

# 74. PAGE 322 — GLO CLAIM AGE VERIFICATION

Verify the age rule using the canonical service.

---

# 75. PAGE 323 — GLO CLAIM KYC

Verify claim/payment gates by actual KYC state.

---

# 76. PAGE 324 — GLO PAYMENT HOLD

Verify claim hold state prevents payout until conditions are satisfied.

---

# 77. PAGE 325 — GLO TICKET FREEZE RUNTIME

Run freeze flow using the canonical service/command.

---

# 78. PAGE 326 — GLO FREEZE RELEASE

Verify lawful expiry/release according to the existing domain contract.

---

# 79. PAGE 327 — GLO FROZEN WINNER PROCESSING

Run:

```bash
php artisan glo:process-frozen-winners
```

or exact project command.

---

# 80. PAGE 328 — GLO PUBLIC RESULT PUBLICATION

Verify published state is derived from the canonical publication service.

---

# 81. PAGE 329 — PRIZE MATCHING ENGINE

Trace:

```text
official result
-> winning rule
-> ticket
-> prize match
```

No frontend calculations.

---

# 82. PAGE 330 — PRIZE SETTLEMENT ENGINE

Verify:

- gross
- deductions
- net
- settlement
- ledger

---

# 83. PAGE 331 — PRIZE PAYOUT IDEMPOTENCY

Duplicate payout attempts must not pay twice.

---

# 84. PAGE 332 — PRIZE PAYOUT FAILURE RECOVERY

Inject payment failure.

Verify claim remains recoverable and auditable.

---

# 85. PAGE 333 — AGENT COMMISSION RUNTIME

Activate canonical commission calculation for an actual test transaction.

---

# 86. PAGE 334 — AGENT COMMISSION DUPLICATE

Duplicate qualifying event must not accrue commission twice.

---

# 87. PAGE 335 — AGENT SETTLEMENT RUNTIME

Verify commission payment/settlement path.

---

# 88. PAGE 336 — AGENT REFERRAL ATTRIBUTION

Verify actual referral source.

Do not rely solely on presentation JSON when canonical relation exists.

---

# 89. PAGE 337 — AGENT OWNER ISOLATION

Cross-agent access tests.

---

# 90. PAGE 338 — NOTIFICATION DELIVERY RUNTIME

Verify:

```text
domain event
-> notification message
-> delivery
-> receipt
```

---

# 91. PAGE 339 — NOTIFICATION IDEMPOTENCY

Ensure repeated domain events do not create unintended duplicate notifications.

---

# 92. PAGE 340 — NOTIFICATION PREFERENCE ENFORCEMENT

Preferences must actually affect delivery.

No browser-only toggles.

---

# 93. PAGE 341 — SUPPORT CASE CONTRACT

The previous architecture explicitly failed closed because anonymous `ContactMessage` rows lacked owner-scoped case semantics.

Now inspect whether a canonical support-case model/service exists.

If absent:

- implement a real owner-scoped support case contract.

Required concepts:

```text
support_case
support_message
owner_user_id
status
priority
category
audit
```

Do not retrofit unsafe ownership onto anonymous legacy messages.

---

# 94. PAGE 342 — SUPPORT CASE CREATION

Authenticated player:

```text
create case
-> validate
-> persist
-> reference
```

---

# 95. PAGE 343 — SUPPORT CASE OWNER ISOLATION

Cross-player security test.

---

# 96. PAGE 344 — SUPPORT CASE REPLY

Implement canonical response lifecycle.

---

# 97. PAGE 345 — SUPPORT CASE ESCALATION

Use existing `ComplianceCaseEscalated` / related architecture only where applicable.

---

# 98. PAGE 346 — SUPPORT SLA / AGE PROJECTION

Only calculate SLA from actual timestamps/configuration.

No fabricated operational KPI.

---

# 99. PAGE 347 — SECURITY EVENT PERSISTENCE

Activate the existing security event architecture.

Verify events for:

- login failure
- suspicious authentication
- MFA
- session changes
- privileged operations

---

# 100. PAGE 348 — MFA RUNTIME

Runtime-test:

- enrollment
- challenge
- verification
- invalid code
- replay
- recovery

Never expose secrets.

---

# 101. PAGE 349 — SESSION REVOCATION

Verify:

```text
session A
-> revoke
-> old credential/token/session denied
```

---

# 102. PAGE 350 — FINAL RUNTIME ACCEPTANCE GATE

Page 350 must become the first genuine runtime gate after the static phases.

Produce:

## Runtime

- PHP
- Composer
- Laravel
- DB
- Redis
- queues
- browser
- Rust

## Financial

- deposit
- payment callback
- wallet
- bet
- withdrawal
- refund
- prize
- commission

## Lottery

- draw
- ticket
- result import
- result certification
- publication
- settlement

## Security

- auth
- admin auth
- ownership
- MFA
- IDOR
- CSRF
- webhook signature
- rate limiting

## Compliance

- KYC
- age
- responsible gaming
- self-exclusion
- privacy

## Rust

- build
- execution
- deterministic vectors
- malformed input
- timeout
- concurrency

---

# 103. PAGE 350 ACCEPTANCE STATES

Use:

```text
VERIFIED
PARTIALLY VERIFIED
BLOCKED
NOT_CONFIGURED
NOT_VERIFIED
FAILED
```

Do not call the platform:

```text
PRODUCTION READY
FULLY SECURE
FULLY VERIFIED
LIVE
```

unless the evidence genuinely supports that statement.

---

# 104. RUNTIME COMMANDS

Where the environment permits, execute:

```bash
php -v
composer --version
php artisan about
php artisan route:list
php artisan migrate:status
php artisan queue:failed
php artisan test
npm ci
npm run build
cargo --version
cargo test --workspace
```

Then run the project-specific tests.

---

# 105. DATABASE TESTS

Run real tests for:

```text
wallet
ledger
payment
deposit
withdrawal
bet
ticket
draw
result
prize
claim
freeze
commission
support
notification
security
```

---

# 106. BROWSER TESTS

Minimum complete browser journey:

```text
Guest
-> Home
-> Lottery
-> Result
-> Verification

Member
-> Register
-> Login
-> Dashboard
-> Deposit
-> callback
-> Wallet
-> Bet
-> Bet History
-> Withdrawal
-> Withdrawal Status
-> Profile
-> Responsible Gaming
-> Notifications
-> Support

Agent
-> Login
-> Dashboard
-> Commissions
-> Settlements
-> Referrals

Admin
-> Login
-> Dashboard
-> Payments
-> Wallets
-> Withdrawals
-> Reconciliation
-> KYC
-> Claims
-> Freeze
-> Results
-> Security
-> Runtime
-> Release
```

---

# 107. NEGATIVE BROWSER TESTS

Verify:

```text
guest -> admin
player A -> player B wallet
player A -> player B withdrawal
player A -> player B deposit
agent A -> agent B referral
unauthorized admin -> payout
unauthorized admin -> wallet
tampered payment callback
duplicate payment callback
duplicate bet request
duplicate withdrawal request
expired session
invalid CSRF
```

---

# 108. ACCESSIBILITY

Runtime-test:

- keyboard navigation
- focus
- labels
- headings
- error announcements
- modal focus trapping
- reduced-motion
- mobile layout
- table usability
- screen reader semantics

Do not mark accessibility verified from source inspection alone.

---

# 109. PERFORMANCE

Measure only actual runtime data.

Capture:

- route latency
- DB query count where instrumentation exists
- queue latency
- payment callback latency
- bet purchase latency
- Rust verification latency

No invented benchmark numbers.

---

# 110. OBSERVABILITY

Verify:

- correlation IDs
- structured errors
- logs
- metrics
- failed jobs
- alerts

Ensure financial failures are observable.

---

# 111. ERROR HANDLING

Runtime-test:

- validation errors
- authentication errors
- authorization errors
- provider errors
- DB exceptions
- queue failures
- Rust failures

Production responses must be safe and sanitized.

---

# 112. API CONTRACT TESTING

For every API touched in this phase:

- request schema
- response schema
- status code
- authentication
- authorization
- rate limiter
- ownership
- idempotency

Add contract tests.

---

# 113. RUST BUILD

Locate the canonical Rust crate/binary.

Execute:

```bash
cargo check
cargo test
cargo build --release
```

Record:

- toolchain
- crate version
- commit/hash if available
- build result
- test result

---

# 114. RUST DETERMINISM

Run identical test vectors multiple times.

Verify identical:

- input normalization
- result
- error

---

# 115. RUST MALFORMED INPUT

Test:

- empty
- oversized
- malformed JSON
- invalid numbers
- invalid character sets
- unexpected fields
- invalid output

---

# 116. RUST PROCESS BOUNDARY

Verify:

- timeout
- process exit code
- stderr handling
- resource limits
- output validation
- no arbitrary command execution
- no network requirement unless explicitly canonical

---

# 117. RUST FINANCIAL ISOLATION

Prove:

```text
Rust output
-> Laravel validation
-> domain decision
```

and NEVER:

```text
Rust output
-> direct wallet mutation
```

---

# 118. SECURITY STATIC + RUNTIME COMBINATION

Repeat static checks after runtime changes.

No security claim may rely exclusively on either:

- static scanning
- runtime testing

Both are required where applicable.

---

# 119. FINAL CHANGE CONTROL

Any newly added migration/service/job/model/event must have:

```text
TYPE
PURPOSE
DOMAIN
DEPENDENCIES
SECURITY IMPACT
FINANCIAL IMPACT
TESTS
```

---

# 120. FINAL REPORT ARTIFACTS

Create/update:

```text
PAGES-251-350-IMPLEMENTATION-REPORT-PART-1.md
PAGES-251-350-MATRICES.md
audit.md
```

Also produce when needed:

```text
RUNTIME-VERIFICATION-REPORT.md
FINANCIAL-INTEGRITY-REPORT.md
RUST-RUNTIME-REPORT.md
```

---

# 121. COMPLETE FILE CONTENT RULE

Every changed file in the final report must be reproduced completely.

Every file must start with:

```text
# TYPE: ...
# PURPOSE: ...
```

For source files, preserve the actual first-line code as required by the repository format.

Never write:

```text
// ...
// existing logic
// unchanged
# omitted
```

No shortening markers.

---

# 122. AUDIT MATRIX

Create one row for every page:

```text
| 251 | ... |
| 252 | ... |
...
| 350 | ... |
```

Exactly 100 page rows.

Never merge pages.

---

# 123. REQUIRED PAGE MATRIX COLUMNS

Use:

```text
Page
Title
Route
Route Name
HTTP Method
Middleware
Authorization
Controller
Request
Service
DTO
Model
Database
API
Job/Event
View
JS
CSS
Translation
Source of Truth
Financial Impact
Security
Audit
Status
Tests
Runtime Status
Remaining Gap
```

---

# 124. FINANCIAL-INTEGRITY MATRIX

For every financial mutation:

```text
operation
source
Money implementation
transaction boundary
ledger impact
idempotency
concurrency
rollback/recovery
audit
runtime evidence
```

---

# 125. RUST MATRIX

For every Rust component:

```text
binary/crate
version
build
input
output
validation
timeout
determinism
security
runtime evidence
remaining gap
```

---

# 126. DEFINITION OF DONE

A page can only be marked:

```text
VERIFIED
```

when the underlying path was actually executed and observed.

A page can be:

```text
IMPLEMENTED + STATIC ONLY
```

when implementation exists but runtime execution was impossible.

A page must be:

```text
NOT_CONFIGURED
```

when no canonical business contract exists.

A page must be:

```text
BLOCKED
```

when a required implementation or dependency prevents safe completion.

---

# 127. CRITICAL ANTI-FABRICATION RULE

Do NOT generate fake runtime output such as:

```text
200 OK
payment completed
wallet credited
Rust verified
database healthy
queue healthy
KYC passed
result published
withdrawal completed
```

unless an actual runtime test produced that result.

Static source inspection is not runtime evidence.

---

# 128. FINAL ENTERPRISE QUESTION

At Page 350 answer only with evidence:

```text
What is actually implemented?
What is actually runtime verified?
What remains NOT_CONFIGURED?
What remains BLOCKED?
What remains externally dependent?
What finance flows are truly executable?
What lottery flows are truly executable?
What Rust functionality is truly executable?
What security controls are runtime tested?
```

Do not provide a cosmetic “completion percentage” based on page count.

Report actual implementation and verification boundaries.

---

# 129. FINAL AGENT DIRECTIVE

Execute Pages 251–350 sequentially.

Do not skip.

Do not create parallel domain architectures.

Do not replace canonical services.

Do not fabricate runtime results.

Do not expose secrets.

Do not bypass authorization.

Do not let browser code become financial authority.

Do not let Rust become untrusted financial authority.

Do not publish unverified lottery results.

Do not convert fixtures into production truth.

Do not declare production readiness merely because the page matrix is complete.

The objective of Pages 251–350 is:

```text
TURN THE STATIC ENTERPRISE SHELL INTO A RUNTIME-VERIFIED SYSTEM.
```

At the end, return:

```text
1. PAGES 251–350 COMPLETE MATRIX
2. COMPLETE CHANGED-FILE MANIFEST
3. COMPLETE CHANGED FILE CONTENTS
4. ROUTE MATRIX
5. API CONTRACT MATRIX
6. SECURITY MATRIX
7. FINANCIAL-INTEGRITY MATRIX
8. LOTTERY-INTEGRITY MATRIX
9. RUST RUNTIME MATRIX
10. DATABASE VERIFICATION
11. QUEUE VERIFICATION
12. PAYMENT PROVIDER VERIFICATION
13. BROWSER VERIFICATION
14. ACCESSIBILITY VERIFICATION
15. TEST RESULTS
16. BUILD RESULTS
17. RUNTIME RESULTS
18. BLOCKERS
19. NOT_CONFIGURED ITEMS
20. REAL REMAINING GAPS
21. audit.md
22. FINAL ACCEPTANCE BOUNDARY
```

The final conclusion must follow evidence, not page count.