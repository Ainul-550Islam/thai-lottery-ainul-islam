# THAILOTTO ENTERPRISE WAGERING PLATFORM
# PAGES 150–250 — MASTER IMPLEMENTATION / HARDENING PROMPT

## 0. MISSION

Continue the implementation from Page 150 through Page 250 without skipping, merging away, pretending, or inventing functionality.

This phase is NOT primarily a visual redesign phase.

Priority order:

1. Backend/domain correctness
2. API contract correctness
3. Wallet / ledger / payment integrity
4. Security / authorization / ownership isolation
5. Responsible gaming / compliance controls
6. Lottery / draw / ticket / prize integrity
7. Observability / deployment / disaster recovery
8. Rust verification-engine integration and hardening
9. Frontend only after the underlying contract is real

The existing application already contains canonical services, policies, models, controllers, middleware, routes, queues, financial services, GLO L6 services, agent services, result services, health services, and operational projections.

DO NOT create parallel architectures for concepts that already have a canonical implementation.

Before modifying any file:

- inspect the existing implementation
- identify the canonical service / model / policy / enum / DTO / job / API contract
- reuse it when correct
- repair it when incomplete
- add a new abstraction only when the existing architecture cannot safely support the requirement
- document why a new abstraction was required

Do NOT replace existing domain logic merely to make the page easier to render.

---

# 1. ABSOLUTE RULES

## 1.1 NO FAKE FUNCTIONALITY

Never fabricate:

- wallet balances
- transactions
- payment states
- payment provider health
- withdrawal status
- user risk scores
- KYC decisions
- lottery results
- draw states
- ticket inventory
- prize amounts
- commissions
- bonus balances
- deployment state
- infrastructure health
- database replication state
- Rust-engine state
- monitoring metrics
- security findings
- operational KPIs

If the required source is unavailable:

- return `NOT_CONFIGURED`
- or `NOT_VERIFIED`
- or `NO_DATA`
- or `UNAVAILABLE`

Use the most accurate state.

Never convert missing evidence into a green success badge.

---

## 1.2 BROWSER IS NEVER FINANCIAL AUTHORITY

The browser may:

- request
- preview
- display
- filter
- paginate
- submit an operator command to a canonical service

The browser may NEVER directly decide:

- wallet balance
- deposit credit
- withdrawal completion
- payment capture
- refund
- bet acceptance
- ticket issuance
- prize settlement
- commission settlement
- financial ledger posting

Every financial mutation must terminate in the canonical backend domain service / transaction boundary.

---

## 1.3 OWNER SCOPING

For player or agent surfaces:

- derive identity from authenticated session
- never trust `user_id` from query/body/hidden input
- never trust `agent_id` from client input
- never expose another user's private financial records
- use policies and authorization gates where available
- use route-model binding only where it cannot bypass ownership
- return `404` or domain-safe not-found state rather than leaking existence

For operator surfaces:

- authorize the panel
- authorize the exact mutation separately
- do not equate “can access admin” with “can perform every operation”

---

## 1.4 EXACT MONEY

Never use floating-point arithmetic for:

- wallet
- deposits
- withdrawals
- bet stake
- prize
- commission
- fee
- tax
- refund
- bonus liability
- settlement
- financial reports

Use the existing `Money` / exact-decimal architecture.

Preserve:

- currency
- scale
- rounding mode
- sign
- database precision
- transaction atomicity

Never calculate financial totals by summing presentation-formatted strings.

---

## 1.5 IDEMPOTENCY

Every financial or externally triggered state-changing operation must have an explicit replay story.

Where relevant:

- provider event ID
- payment reference
- withdrawal reference
- claim reference
- command ID
- request idempotency key
- unique database constraint
- queue uniqueness
- transactional state transition

Duplicate requests must be harmless.

---

## 1.6 STATE MACHINES

Never use arbitrary string mutation where the domain already has enums or state transition rules.

For each stateful domain:

- enumerate valid states
- enumerate allowed transitions
- reject illegal transitions
- persist transition evidence
- record actor
- record correlation/reference ID
- record timestamp

---

## 1.7 SECURITY

All new endpoints must be reviewed for:

- authentication
- authorization
- CSRF where applicable
- rate limiting
- input validation
- output encoding
- object ownership
- IDOR
- mass assignment
- replay attacks
- duplicate submissions
- information disclosure
- file access
- SSRF
- webhook spoofing
- signature validation
- timing-sensitive comparisons
- log leakage
- PII leakage
- secret leakage
- cache leakage

---

## 1.8 LOCALIZATION

All customer-facing and operator-facing UI introduced in this phase must support:

- English
- Thai

Maintain exact EN/TH translation-key parity.

Never hardcode UI copy into Blade/JS/TS/PHP when a translation key is appropriate.

---

## 1.9 FRONTEND DESIGN

Use the existing Thailotto visual system:

- luxury dark-gold
- glassmorphism
- subtle 3D depth
- restrained gold highlights
- premium iGaming visual hierarchy
- desktop
- laptop
- tablet
- mobile

But do NOT let visual polish hide incomplete backend behavior.

Every state must have a truthful visual representation:

- AVAILABLE
- NO_DATA
- NOT_CONFIGURED
- NOT_VERIFIED
- UNAVAILABLE
- ERROR
- PROCESSING
- COMPLETED
- BLOCKED

Support:

- keyboard navigation
- visible focus
- reduced motion
- screen-reader labels
- no horizontal overflow
- small-screen tables
- accessible dialogs
- semantic headings

---

# 2. EXISTING ARCHITECTURE MUST BE PRESERVED

The current code already establishes:

- `LottoFinExecutiveDashboardController`
- `AgentPortalController`
- `NotificationCenterController`
- `SupportPortalController`
- canonical payment / wallet / withdrawal services
- account verification services
- responsible gaming services
- self-exclusion services
- result projection services
- GLO L6 services
- health / metrics controllers
- AdminAccess authorization
- policy-driven admin permissions
- queue / webhook / payment infrastructure
- `Money`
- `FinancialReconciliationService`
- canonical draw/result architecture
- GLO-specific verification concepts

Do not duplicate these.

The Pages 150–250 phase should turn the existing projection-heavy operations layer into a production-grade operational/control layer.

---

# 3. REQUIRED PRE-WORK

Before implementing Pages 150–250:

Run an architecture inventory.

Generate:

```text
tree -L 4 app bootstrap config database resources routes tests
```

Then produce an annotated implementation tree where EVERY listed file has:

```text
path/to/file.php  # TYPE: ... | ROLE: ... | DOMAIN: ... | USED BY: ...
```

Do not omit files relevant to this phase.

For every existing file being changed, capture and preserve the COMPLETE original file content before editing.

Never use:

```text
// existing logic...
// unchanged...
# ...
/* omitted */
```

in an implementation report.

The final report must reproduce complete changed files.

---

# 4. PAGE MATRIX — 150–250

---

## PAGE 150 — Production Cutover Control Center — SEAL / RE-AUDIT

Treat Page 150 as the closing gate from the previous phase, not as permission to declare production readiness.

Implement:

- release identifier
- artifact checksum field
- environment verification state
- PHP/Laravel/Node requirements
- DB state
- queue state
- cache state
- payment-provider configuration state
- Rust engine configuration state
- DNS/URL state
- security gate state
- migration state
- backup state
- rollback state
- final readiness state

Every gate must show actual evidence or `NOT_VERIFIED`.

Add:

- immutable audit trail
- operator identity
- timestamp
- correlation/reference

Never display “READY” merely because code exists.

---

## PAGE 151 — Release Manifest

Create a release-manifest projection showing:

- commit SHA
- build ID
- artifact hash
- application version
- migration version
- asset build fingerprint
- dependency lock hash
- Rust binary version/hash
- environment identifier
- generated timestamp

Do not calculate a fake checksum in the browser.

Backend must derive release metadata from real deploy artifacts/environment where available.

---

## PAGE 152 — Environment / Configuration Matrix

Create an operator view of:

- application environment
- application URL
- DB engine/version
- queue driver
- cache driver
- mail driver
- payment providers
- KYC providers
- CAPTCHA provider
- storage provider
- CDN/reverse proxy
- Rust engine endpoint/binary mode

Secret values MUST NOT be displayed.

Show:

- configured
- missing
- invalid
- unavailable
- not verified

---

## PAGE 153 — Secrets and Key Management Status

Implement a read-only secret configuration audit.

Show only metadata:

- key exists
- provider configured
- key age if safely derivable
- rotation status
- last verified status

Never expose:

- private keys
- API secrets
- webhook secrets
- DB passwords
- encryption keys

Provide canonical operator verification commands/services rather than revealing values.

---

## PAGE 154 — Database Migration Control

Create migration operations view:

- current schema version
- pending migrations
- last migration
- migration batch
- migration duration where recorded
- destructive-migration warnings
- migration lock state

Mutation should NOT run arbitrary migrations from browser JavaScript.

Use a controlled backend operation or explicit CLI/deployment process.

---

## PAGE 155 — Database Backup Control

Implement:

- latest backup state
- backup timestamp
- backup age
- backup artifact reference
- checksum if available
- retention policy
- encryption state
- verification state

Never claim backup success without actual backup evidence.

---

## PAGE 156 — Backup Restore Verification

Create a restore-verification console.

The browser must not restore production blindly.

Support:

- restore request record
- target environment
- restore artifact
- verification result
- checksum
- operator
- start/end
- outcome

Require server-side guardrails and explicit non-production target validation.

---

## PAGE 157 — Disaster Recovery Center

Implement a DR readiness page showing:

- RPO target
- RTO target
- backup age
- restore verification
- replica state
- queue recovery state
- payment callback recovery considerations
- DNS recovery state
- Rust engine recovery state

Every value needs a source.

---

## PAGE 158 — Failover / High Availability Status

Implement infrastructure-health projection for:

- DB primary
- DB replica if configured
- Redis
- queue workers
- application nodes
- CDN
- external integrations
- Rust engine

Do not invent node counts.

---

## PAGE 159 — Incident Command Center

Create operator incident overview:

- active incidents
- severity
- component
- first observed
- current state
- assigned operator
- reference
- latest event

No fake incidents in production.

---

## PAGE 160 — Incident Detail

Provide:

- timeline
- correlated logs
- system state
- operator notes
- affected component
- mitigation state
- resolution state
- postmortem reference

Keep log output sanitized.

---

## PAGE 161 — Deployment Approval Gate

Implement release approval workflow:

- release
- checks
- approver
- approval time
- rejection reason
- blocking findings
- current state

Approval must be a real authorization action, not a client-side toggle.

---

## PAGE 162 — Deployment History

Read-only deployment history:

- version
- commit
- deployed_at
- actor/service
- environment
- status
- rollback reference

Source from deployment metadata where available.

---

## PAGE 163 — Rollback Control

Build a safe rollback request page.

Require:

- target release
- reason
- impact acknowledgement
- authorization
- confirmation
- audit record

Do not let the browser directly run arbitrary shell commands.

---

## PAGE 164 — Feature Flag Operations

Implement:

- feature flag list
- environment
- state
- scope
- rollout percentage if actually configured
- updated by
- updated at

Prevent:

- hidden flags
- client-only financial flags
- unsaved browser state

---

## PAGE 165 — Configuration Change Audit

Create immutable configuration-change history:

- configuration key
- category
- old-state hash/reference
- new-state hash/reference
- actor
- reason
- timestamp

Never expose secret material.

---

## PAGE 166 — Admin Session Control

Implement operator session overview:

- current sessions
- last activity
- IP representation where legally/architecturally supported
- device metadata
- session age
- session state

Allow canonical server-side session revocation only if an existing secure mechanism exists.

---

## PAGE 167 — Operator Access Review

Show each operator's:

- account status
- role
- assigned permissions
- last login
- MFA state
- suspicious-access state

Never expose another operator's credentials.

---

## PAGE 168 — Privileged Access Review

Create a stricter review of:

- wallet-management permission
- payout-management permission
- reconciliation permission
- GLO prize-claim permission
- freeze-review permission
- draw-management permission
- system-setting permission

Show who has privileged access and why.

---

## PAGE 169 — Role / Permission Matrix

Generate a canonical matrix from actual policies/gates.

Do NOT hardcode permissions into Blade.

Display:

```text
role -> panel -> permission -> allowed operation
```

Distinguish:

- view
- create
- approve
- reject
- settle
- freeze
- release
- configure

---

## PAGE 170 — Service Account Operations

Implement service-account inventory:

- service identifier
- environment
- status
- purpose
- last-used metadata where available
- rotation state

Never display credentials.

---

## PAGE 171 — IP / Network Access Controls

Create network-access audit for operator surfaces.

Support configured:

- allowlist
- denylist
- trusted proxy state
- admin-network restriction

Do not implement security by trusting an `X-Forwarded-For` header without trusted-proxy configuration.

---

## PAGE 172 — Device / Session Risk Review

Read-only security view:

- unusual device count
- simultaneous sessions
- recent session changes
- failed authentication indicators
- session revocation state

No health/mental-state inference.

Use documented security signals only.

---

## PAGE 173 — MFA / 2FA Operations

Create MFA status control:

- enabled
- disabled
- enrollment required
- recovery state
- last verification if available

Never display TOTP secrets or recovery codes.

---

## PAGE 174 — Authentication Security Center

Aggregate:

- login attempts
- failed login counts
- password-reset attempts
- account lock events
- CAPTCHA failures
- authentication anomalies

Use existing rate limiters and audit logs.

---

## PAGE 175 — Rate-Limit Operations

Display actual configured rate limiters for:

- login
- password reset
- deposit
- withdrawal
- bet
- webhook
- ticket verification
- contact
- account verification
- account grade
- admin APIs

No client-side rate limiter may replace server-side enforcement.

---

## PAGE 176 — CAPTCHA Operations

Show:

- provider configured
- site key presence
- secret presence
- verification state
- challenge failures
- configuration state

Never reveal the secret.

---

## PAGE 177 — Fraud / Risk Rule Operations

Create backend-driven rule visibility for:

- duplicate account patterns
- payment anomalies
- rapid deposits
- rapid withdrawals
- repeated failed transactions
- suspicious account behaviour defined by explicit rules

Do not invent an opaque “AI fraud score”.

---

## PAGE 178 — Suspicious Activity Case Queue

Create case records with:

- case ID
- reason
- source event
- account reference
- current state
- assigned operator
- created_at
- resolved_at

Use privacy-safe references.

---

## PAGE 179 — Compliance Case Detail

Show:

- evidence references
- related payment references
- related bets
- KYC state
- account restrictions
- operator actions
- resolution

No direct browser-side financial mutation.

---

## PAGE 180 — Sanctions / Watchlist Integration Status

Only implement what the codebase actually supports.

Show:

- provider
- configured state
- last verification
- API health
- failure state

Do not claim live sanctions screening without a configured provider.

---

## PAGE 181 — Identity Verification Provider Operations

Display:

- provider configuration
- health
- last request
- success/failure counters if actually instrumented
- timeout state

No raw sensitive document data.

---

## PAGE 182 — Document Verification Operations

Implement:

- pending documents
- verification status
- document type
- review age
- secure download reference
- decision state

Use existing authorized document service.

---

## PAGE 183 — Age Verification Operations

Create age-verification audit:

- required
- submitted
- verified
- failed
- expired
- manually reviewed

Do not expose unnecessary identity data.

---

## PAGE 184 — Duplicate Account Review

Show explicit duplicate-account detection evidence.

Possible fields:

- matching rule
- evidence reference
- account references
- state
- operator decision

Do not mark users as duplicates without a real rule/evidence record.

---

## PAGE 185 — Account Restriction Operations

Manage/display canonical account restrictions:

- suspended
- payment-restricted
- betting-restricted
- KYC-required
- self-excluded
- compliance-hold

Every mutation must come through the canonical account service.

---

## PAGE 186 — Dormant Account Review

Show:

- last activity
- account status
- wallet state
- pending financial obligations
- KYC state

Do not delete or alter accounts from the browser unless a canonical service exists.

---

## PAGE 187 — Data Retention Operations

Create retention policy view:

- data category
- retention period
- legal basis/config source
- expiration state
- deletion eligibility

Do not auto-delete financial/legal records without a documented canonical policy.

---

## PAGE 188 — Privacy Request Queue

Create DSAR/privacy-request projection:

- request ID
- request type
- submitted_at
- due date
- status
- reviewer
- completion evidence

No direct data export from arbitrary browser parameters.

---

## PAGE 189 — Privacy Request Detail

Show:

- verification state
- requester ownership
- affected systems
- data scope
- export state
- deletion restrictions
- completion audit

Respect financial/legal retention constraints.

---

## PAGE 190 — Player Data Export

Create a secure player export request flow.

Requirements:

- owner authenticated
- request confirmation
- async generation
- signed/private artifact
- expiry
- audit record
- no public URL
- no other-user data

---

## PAGE 191 — Account Deletion Request

Create a canonical deletion-request state.

Never immediately delete a financial account when legally retained records are required.

Show:

- eligibility
- blocking records
- requested_at
- status
- completed_at

---

## PAGE 192 — Consent Management

Implement:

- consent categories
- state
- timestamp
- policy version
- source
- revocation state

Do not infer consent from a page visit.

---

## PAGE 193 — Cookie / Tracking Preferences

Build privacy-safe preferences using actual configured mechanisms.

Do not present fake toggles that do not affect backend behaviour.

---

## PAGE 194 — Legal Version Registry

Create registry for:

- Terms
- Privacy
- Fees
- responsible-gaming wording
- other applicable public policies

Every version must have:

- version
- effective_at
- content/reference
- state

---

## PAGE 195 — Terms Acceptance Audit

Show:

- user reference
- terms version
- accepted_at
- acceptance channel
- current agreement state

Do not allow arbitrary modification of historical acceptance records.

---

## PAGE 196 — Responsible Gaming Analytics

Use actual event/data sources to show:

- deposits
- wagering
- limits
- self-exclusion
- intervention events

Avoid unsupported behavioural conclusions.

---

## PAGE 197 — Self-Exclusion Enforcement

Verify that self-exclusion propagates to:

- betting
- deposits
- withdrawals where applicable by business rule
- promotions
- session access if required

Do not create a display-only self-exclusion layer.

---

## PAGE 198 — Deposit Limit Monitoring

Display actual configured limits:

- daily
- single transaction
- remaining allowance
- breach state

Use canonical limit service.

---

## PAGE 199 — Wagering / Loss Limit Monitoring

Implement exact calculations from canonical financial/betting records.

Never calculate with frontend approximations.

---

## PAGE 200 — Cooldown / Take-a-Break Controls

Implement only where an actual domain concept exists.

Show:

- configured duration
- started_at
- ends_at
- enforcement state

---

## PAGE 201 — Player Protection Intervention Queue

Use recorded interventions only:

- trigger
- action
- operator/system source
- current state
- audit

No fabricated intervention recommendations.

---

## PAGE 202 — Wallet Ledger Integrity

Create a reconciliation projection comparing:

- wallet balance
- ledger-derived balance
- locked balance
- pending holds
- adjustments

Discrepancies must be explicit.

---

## PAGE 203 — Wallet Reconciliation Exceptions

Show:

- exception ID
- wallet
- expected value
- observed value
- delta
- detection source
- status
- operator

Use exact decimal math.

---

## PAGE 204 — Orphan Transaction Detection

Detect transactions without a valid domain linkage where the schema supports detection.

Do NOT auto-repair from browser.

Create:

- detection
- evidence
- remediation status

---

## PAGE 205 — Duplicate Financial Transaction Detection

Detect duplicates using canonical references and database constraints.

Support:

- duplicate event
- provider reference
- amount
- currency
- affected record
- resolution state

---

## PAGE 206 — Payment Provider Health

Provider-by-provider dashboard:

- configured
- reachable
- webhook healthy
- initiation healthy
- callback healthy
- recent error state

Never report a provider as healthy based solely on configuration presence.

---

## PAGE 207 — Payment Provider Configuration

Show safe provider metadata:

- provider name
- enabled
- supported currencies
- supported operations
- environment
- callback routes
- signature-validation mode

No secrets.

---

## PAGE 208 — Webhook Signature Diagnostics

For each configured webhook integration:

- signature mechanism
- verification enabled
- validation result
- timestamp tolerance
- replay protection
- recent failures

Never expose raw secrets.

---

## PAGE 209 — Webhook Retry / Dead-Letter Queue

Implement operational view for:

- pending
- retrying
- failed
- dead-letter
- permanently rejected

Respect existing unique jobs and retry policies.

---

## PAGE 210 — Payment Event Replay Review

Create safe replay request workflow.

Replay must:

- revalidate signature where applicable
- maintain idempotency
- never double-credit wallet
- create audit evidence

---

## PAGE 211 — Deposit Dispute / Refund Operations

Only use actual domain concepts already present.

Show:

- reference
- provider state
- internal state
- dispute state
- refund state
- amount/currency
- audit

---

## PAGE 212 — Chargeback Operations

Create chargeback case projection where supported.

Never deduct money directly from the browser.

All accounting changes must use canonical ledger services.

---

## PAGE 213 — Withdrawal Risk Review

Create operator review queue:

- withdrawal reference
- amount
- currency
- status
- risk/compliance hold
- KYC state
- age verification state
- created_at

No unsupported risk score.

---

## PAGE 214 — Withdrawal Payout Queue

Show:

- pending
- approved
- processing
- completed
- failed
- held

Every transition is canonical.

---

## PAGE 215 — Withdrawal Reversal Operations

Implement reversal workflow only if the existing withdrawal domain supports it.

Require:

- valid reversible state
- reason
- operator permission
- transaction-safe balance treatment
- audit trail

---

## PAGE 216 — Treasury / Settlement Summary

Build exact aggregate views:

- deposits
- withdrawals
- settlement movements
- fees
- refunds

Do not present “profit” unless defined and sourced.

---

## PAGE 217 — Financial Reporting

Create period-bounded reports with:

- filters
- currency
- exact totals
- source records
- export metadata

Avoid floating-point summaries.

---

## PAGE 218 — Tax / Statutory Reporting

Only report fields supported by the actual accounting model.

Show:

- taxable categories
- period
- gross
- deductions
- net
- tax state

Do not invent statutory rates.

---

## PAGE 219 — Commission Rule Administration

Build canonical agent commission rule projection.

Show:

- rule
- effective date
- eligible products
- rate
- status
- source

Any mutation must use a canonical service.

---

## PAGE 220 — Agent Onboarding

Create agent onboarding state:

- applicant
- verification
- approval
- activation
- rejection
- suspension

Do not create agent records without domain validation.

---

## PAGE 221 — Agent Verification

Use existing account/KYC architecture where applicable.

Agent verification must not bypass normal authorization.

---

## PAGE 222 — Agent Risk / Compliance

Show actual agent-level exceptions:

- suspicious referrals
- settlement exceptions
- commission anomalies
- account violations

No invented score.

---

## PAGE 223 — Agent Settlement Exceptions

Show:

- commission reference
- settlement reference
- amount
- currency
- exception
- resolution

Exact money required.

---

## PAGE 224 — Referral Attribution Audit

Use canonical referral linkage only.

Verify:

- owner agent
- referred user
- attribution source
- created_at
- current state

Do not use arbitrary JSON fields when a canonical relation exists.

---

## PAGE 225 — Promotion / Bonus Rule Administration

Create rule projection for existing bonus infrastructure:

- product
- rule
- eligibility
- amount/rate
- effective period
- status

No fake bonus availability.

---

## PAGE 226 — Bonus Liability

Exact-decimal aggregate:

- issued
- active
- redeemed
- expired
- cancelled

Only from canonical records.

---

## PAGE 227 — Bonus Abuse Review

Use actual rule violations and audit evidence.

Do not build an opaque “AI abuse score”.

---

## PAGE 228 — Lottery Product Lifecycle

Administer product state:

- draft
- configured
- active
- suspended
- retired

Never allow activation unless mandatory product requirements are valid.

---

## PAGE 229 — Draw Template Administration

Create draw template management:

- lottery/product
- draw frequency
- timezone
- sale opening
- sale closing
- draw schedule
- publication policy

Use canonical draw services.

---

## PAGE 230 — Draw Calendar

Calendar projection for:

- scheduled draws
- completed draws
- pending publication
- blocked draws
- cancelled draws

No browser-side draw creation unless canonical service exists.

---

## PAGE 231 — Ticket Sales Window

Show:

- open
- closing
- closed
- suspended
- cutoff
- timezone

Use authoritative server time.

---

## PAGE 232 — Ticket Inventory / Availability

Only implement if the product actually has finite inventory.

Do not create fake “stock” for an unlimited-number product.

---

## PAGE 233 — Ticket Reservation Expiry

Show:

- reservation
- ticket selection
- owner
- expiry
- state

Never expose other users' reservations.

---

## PAGE 234 — Ticket Issuance Reconciliation

Compare:

- reserved
- issued
- paid
- failed
- cancelled

Tie every record to canonical ticket references.

---

## PAGE 235 — Bet Validation Audit

Trace:

```text
request
-> validation
-> eligibility
-> price
-> responsible gaming
-> wallet
-> reservation
-> ticket/bet
-> ledger
```

Show exactly where a transaction stopped.

---

## PAGE 236 — Bet State Machine Audit

Document and enforce:

- pending
- accepted
- rejected
- cancelled
- settled
- refunded
- failed

Use actual enum/state definitions.

---

## PAGE 237 — Bet Cancellation / Refund Operations

Build controlled operator action only where supported.

Require:

- valid state
- reason
- authorization
- transaction safety
- audit
- idempotency

---

## PAGE 238 — Winning Calculation Audit

For every settled result:

- input result
- winning rule
- matched ticket/bet
- gross prize
- deductions if any
- net
- settlement state

Never calculate official results in Blade/JS.

---

## PAGE 239 — Prize Liability Projection

Create exact liability summary:

- prize category
- winners
- gross liability
- settled
- pending
- held
- outstanding

---

## PAGE 240 — Prize Payout Queue

Show:

- claim
- verification
- hold
- approved
- payment
- completed
- failed

Reuse GLO claim services for GLO L6.

---

## PAGE 241 — Prize Evidence Chain

Build evidence-linked claim history:

```text
ticket
-> draw
-> official result
-> winning rule
-> claim
-> verification
-> hold
-> approval
-> payment
```

Every link must come from a real record.

---

## PAGE 242 — Ticket Freeze Evidence Chain

For every freeze:

- authority
- case reference
- evidence reference
- ticket
- draw
- request time
- effective time
- expiry
- release state

Never make a freeze merely a UI status.

---

## PAGE 243 — Result Import Job Operations

Show:

- source
- job
- start
- end
- records
- accepted
- rejected
- duplicate
- error
- provenance

Do not treat “job ran” as “result is official”.

---

## PAGE 244 — Result Provenance Comparison

For each result:

- source
- retrieved_at
- source reference
- normalized value
- verification state
- publication state

Preserve leading zeros.

---

# 5. RUST ENGINE / VERIFICATION PHASE

## PAGE 245 — Rust Verification Engine Health

Implement a real operational projection of the Rust engine.

Show:

- binary/version
- commit/build hash if available
- availability
- startup state
- request capability
- timeout state
- verification state

If unavailable:

```text
NOT_VERIFIED
```

not “healthy”.

---

## PAGE 246 — Rust Contract Boundary

Document and test the Rust boundary used by the application.

Define:

```text
Laravel request
-> DTO / serialized contract
-> Rust engine
-> deterministic response
-> validation
-> Laravel domain decision
```

Do not allow unvalidated Rust output to directly credit money or settle a claim.

---

## PAGE 247 — Rust Deterministic Test Vectors

Create a deterministic fixture/test-vector suite covering:

- ticket input
- draw input
- result input
- winning output
- invalid input
- malformed input
- boundary values
- leading-zero numbers
- repeated execution

Same input must produce same result.

---

## PAGE 248 — Rust API / FFI Security Boundary

Audit:

- serialization
- input length
- character set
- timeout
- process isolation
- panic/error handling
- malformed output handling
- resource exhaustion
- version mismatch

Never trust Rust output blindly.

---

## PAGE 249 — Rust Performance / Concurrency

Measure only with real runtime evidence:

- verification latency
- throughput
- concurrency
- memory
- queue depth
- timeout rate

No invented performance numbers.

Add regression thresholds only after real measurements exist.

---

## PAGE 250 — Final Enterprise Integrity Audit

Page 250 is the phase gate.

Create one consolidated audit for Pages 150–250.

Sections:

### A. Backend

Verify:

- controllers
- services
- DTOs
- models
- repositories if present
- jobs
- events
- listeners
- policies
- middleware

### B. API

Verify:

- authentication
- authorization
- validation
- throttling
- ownership
- idempotency
- response schema
- error schema

### C. Finance

Verify:

- wallet
- ledger
- deposits
- withdrawals
- payments
- refunds
- commissions
- prize settlement
- exact money

### D. Lottery

Verify:

- product
- draw
- ticket
- bet
- result
- publication
- prize
- claim
- freeze
- provenance

### E. Security

Verify:

- sessions
- admin access
- MFA
- CAPTCHA
- rate limits
- webhook signatures
- secret handling
- file authorization
- PII isolation
- IDOR protection

### F. Responsible Gaming

Verify:

- limits
- self-exclusion
- cooldown
- intervention
- enforcement

### G. Rust

Verify:

- build
- version
- contract
- deterministic vectors
- malformed-input handling
- runtime availability
- performance evidence

### H. Operations

Verify:

- health
- queue
- scheduler
- cache
- database
- backup
- deployment
- rollback
- DR
- incident process

### I. Frontend

Verify:

- truthful states
- EN/TH parity
- accessibility
- keyboard
- reduced motion
- responsive design
- no overflow
- no fake KPIs
- no fake financial data

---

# 6. ROUTE RULES

Every page requiring an HTTP route must:

- use a named route
- use strict parameter constraints
- declare static routes before wildcard routes
- avoid ambiguous wildcard capture
- use appropriate middleware
- use `public.legal` for guest legal/read-only surfaces where appropriate
- use `auth` for authenticated player/agent surfaces
- use admin authorization for operator surfaces
- use existing rate limiters where appropriate
- avoid duplicate aliases that implement independent logic

Compatibility aliases are allowed only when they redirect/delegate to the same canonical implementation.

---

# 7. API RULES

For each API introduced:

Define:

```text
method
path
authentication
authorization
request schema
validation
idempotency
success schema
error schema
status codes
rate limiter
audit behavior
ownership rule
source of truth
```

Do not return raw Eloquent model payloads.

Use explicit projection DTOs/resources.

Never expose:

- password hashes
- tokens
- private storage paths
- payment secrets
- webhook secrets
- internal stack traces
- unnecessary PII

---

# 8. DATABASE RULES

For every new migration:

- explain why the table/column/index is needed
- use foreign keys where appropriate
- use uniqueness where domain invariants require it
- define indexes for lookup paths
- preserve money precision
- preserve timezone semantics
- avoid destructive changes without migration strategy
- do not create duplicate tables representing an existing domain object

For state transitions requiring concurrency protection:

- use DB transaction
- use row locks where needed
- enforce unique constraints
- re-check current state inside transaction

---

# 9. QUEUE RULES

For every new queue job:

- define retry policy
- define backoff
- define uniqueness where required
- define encryption where sensitive
- sanitize payload
- define failure path
- define terminal state
- define idempotency
- define timeout

Never put full secrets or unnecessary PII into queued payloads.

---

# 10. AUDIT LOGGING

Every sensitive operator action must record:

```text
actor
action
domain object
reference
before-state/reference
after-state/reference
reason
correlation ID
timestamp
```

Do not write secrets into logs.

For financial changes, audit logging must not replace ledger accounting.

---

# 11. TEST MATRIX

For Pages 150–250, add or update tests for every implemented domain.

Minimum matrix:

## Authentication

- guest denied
- authenticated allowed
- wrong user denied
- wrong role denied
- privileged permission denied where appropriate

## IDOR

For every resource:

```text
User A cannot read User B
Agent A cannot read Agent B
Operator without permission cannot access privileged panel
```

## Financial

Test:

- duplicate request
- concurrent request
- replay
- wrong currency
- invalid amount
- zero/negative amount
- insufficient funds
- stale state
- already completed state

## Webhooks

Test:

- valid signature
- invalid signature
- stale signature
- duplicate event
- malformed payload
- unknown provider event
- replay

## Lottery

Test:

- invalid ticket
- wrong draw
- closed sales window
- duplicate issuance
- result not published
- published result
- winning ticket
- losing ticket
- claim duplication
- freeze
- release

## Responsible Gaming

Test:

- limit set
- limit reached
- self-exclusion active
- restricted operation
- cooldown active

## Rust

Test:

- deterministic result
- malformed request
- malformed response
- timeout
- engine unavailable
- version mismatch

---

# 12. STATIC VALIDATION

At minimum run, where the runtime exists:

```bash
php -l <changed php files>
composer validate
composer install --no-interaction
npm ci
npm run build
```

Then run the relevant test suite.

Do not claim Laravel verification if PHP/Composer/vendor/runtime/database/browser is unavailable.

Use exact wording:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

when necessary.

---

# 13. RUNTIME VALIDATION

When runtime becomes available, execute:

```bash
php artisan about
php artisan route:list
php artisan config:show
php artisan migrate:status
php artisan queue:failed
php artisan test
npm run build
```

Then perform authenticated browser smoke tests.

Minimum browser flows:

```text
Register
-> Login
-> Dashboard
-> Wallet
-> Deposit
-> Verified payment callback
-> Balance update
-> Bet
-> Bets history
-> Withdrawal
-> Withdrawal status
```

Admin:

```text
Admin Login
-> Dashboard
-> Wallet Operations
-> Payment Operations
-> Withdrawal Operations
-> Reconciliation
-> KYC
-> Prize Claims
-> Ticket Freeze
-> Result Operations
-> Security
-> Release
-> Cutover
```

Agent:

```text
Agent Login
-> Dashboard
-> Commissions
-> Settlements
-> Statement
-> Referrals
-> Referral Detail
```

Rust:

```text
Laravel
-> Rust Engine
-> deterministic verification
-> result returned
-> domain validation
-> no unintended financial mutation
```

---

# 14. SECURITY NEGATIVE TESTS

Explicitly test:

```text
Unauthenticated admin URL
Unauthenticated wallet URL
Cross-player wallet access
Cross-player deposit-status access
Cross-player withdrawal-status access
Cross-agent referral access
Admin without permission
Expired session
Invalid CSRF token
Invalid webhook signature
Replay webhook
Duplicate payment
Duplicate withdrawal submission
Duplicate bet submission
Malformed draw identifier
Malformed ticket identifier
Unauthorized KYC file download
Unauthorized prize claim
Unauthorized ticket freeze
Unauthorized configuration change
```

Expected outcome must be safe denial / not-found / validation failure depending on domain contract.

---

# 15. UI IMPLEMENTATION RULES

Every page must have:

### Loading

Truthful loading state only.

### Empty

```text
NO_DATA
```

### Not configured

```text
NOT_CONFIGURED
```

### Runtime unavailable

```text
NOT_VERIFIED — RUNTIME UNAVAILABLE
```

### Error

Human-readable explanation plus safe technical reference.

### Success

Only after backend confirmation.

Never change the button into “Success” merely because the fetch request returned HTTP 200.

---

# 16. NO DUPLICATE ARCHITECTURE

Before creating:

```text
Controller
Service
Model
Repository
DTO
Policy
Job
Event
Migration
API endpoint
JS state manager
```

search the codebase for an equivalent.

Examples:

Do NOT create:

```text
NewWalletService
AlternatePaymentService
SecondWithdrawalService
FakePrizeSettlementService
RustLotteryService2
AdminFinancialController2
```

when a canonical equivalent already exists.

Extend existing architecture instead.

---

# 17. SOURCE-OF-TRUTH RULE

For every displayed value, document:

```text
UI field
-> controller
-> service
-> model/query
-> database/source
```

For example:

```text
Wallet balance
-> PlayerWebController
-> canonical wallet service / projection
-> Wallet
-> database
```

Never:

```text
UI balance
-> random query
-> browser arithmetic
```

---

# 18. REQUIRED FILE CHANGE REPORT

At the end, produce:

## A. Changed-file manifest

For EVERY changed file:

```text
path/to/file.php
# TYPE: ...
# ROLE: ...
# DOMAIN: ...
# WHY CHANGED: ...
# DEPENDENCIES: ...
# SECURITY IMPACT: ...
# TEST COVERAGE: ...
```

## B. Complete changed files

Print every changed file from first line to last line.

No omissions.

No placeholders.

No:

```text
// unchanged
// existing logic preserved
# ...
```

## C. Route matrix

For Pages 150–250:

```text
page
route
HTTP method
controller
middleware
authorization
service
state
```

## D. API matrix

```text
endpoint
method
auth
rate limiter
idempotency
source of truth
mutation
```

## E. Security matrix

```text
surface
auth
permission
owner scope
rate limit
CSRF
IDOR defense
audit
```

## F. Financial integrity matrix

```text
operation
Money class
ledger impact
transaction boundary
idempotency
rollback/recovery
```

## G. Rust matrix

```text
component
contract
input
output
validation
timeout
failure mode
test vector
runtime evidence
```

---

# 19. AUDIT FILE

Update:

```text
audit.md
```

with ONE row for every page:

```text
| 150 | Production Cutover Control Center | ... |
| 151 | Release Manifest | ... |
...
| 250 | Final Enterprise Integrity Audit | ... |
```

Never skip page numbers.

Each row must identify:

- implementation state
- source of truth
- security state
- test state
- runtime state
- remaining gap

Use only factual states.

---

# 20. IMPLEMENTATION STATES

Use these consistently:

```text
IMPLEMENTED
HARDENED
AVAILABLE
NO_DATA
NOT_CONFIGURED
NOT_VERIFIED
UNAVAILABLE
BLOCKED
FAILED
```

Do not invent optimistic synonyms such as:

```text
READY
LIVE
PRODUCTION-GRADE
SECURE
FULLY VERIFIED
```

unless the actual acceptance evidence supports them.

---

# 21. DEFINITION OF DONE

Pages 150–250 are NOT done merely because:

- routes exist
- Blade renders
- controller compiles
- JS builds
- fake data appears
- tests only check HTTP 200

The phase is complete only when each applicable page has:

```text
real route
real authorization
real backend source
real state model
real validation
real error handling
real audit behaviour
real tests
real localization
responsive UI
accessibility
runtime evidence
```

For unavailable external infrastructure, mark:

```text
NOT_VERIFIED
```

rather than pretending.

---

# 22. FINAL COMMAND TO THE CODING AGENT

Execute Pages 150–250 sequentially.

Do NOT skip a page because another page seems similar.

Do NOT merge two pages into one audit row.

Do NOT create presentation-only financial pages.

Do NOT create fake APIs.

Do NOT create browser-only security.

Do NOT fabricate runtime evidence.

Do NOT invent lottery results.

Do NOT invent payment-provider health.

Do NOT invent Rust performance.

Do NOT bypass canonical services.

Do NOT weaken authorization to make a screen accessible.

Do NOT remove working legacy compatibility unless the canonical target is proven and the audit explicitly records the migration.

Preserve all existing working logic.

Repair incomplete architecture instead of duplicating it.

Close backend/API/security/wallet/Rust gaps first.

Only after each domain contract is correct, finish the premium 2026 dark-gold glass/3D frontend.

At the end, return:

```text
1. COMPLETE PAGES 150–250 MATRIX
2. COMPLETE CHANGED-FILE MANIFEST
3. COMPLETE CHANGED FILE CONTENTS
4. COMPLETE ROUTE MATRIX
5. COMPLETE API MATRIX
6. COMPLETE SECURITY MATRIX
7. COMPLETE FINANCIAL-INTEGRITY MATRIX
8. COMPLETE RUST MATRIX
9. TEST RESULTS
10. BUILD RESULTS
11. RUNTIME VERIFICATION RESULTS
12. REMAINING REAL GAPS
13. audit.md
14. FINAL NOT-VERIFIED / VERIFIED BOUNDARY
```

The final report must be honest.

A missing production dependency is a gap.

A missing database is a gap.

A missing provider is a gap.

A missing Rust runtime is a gap.

A missing browser test is a gap.

A passing static parser is NOT equivalent to application runtime verification.

The goal is not to make the report look complete.

The goal is to make the platform actually complete.