# CODING AGENT PROMPT — PAGES 100–150
## ThaiLotto Enterprise Platform
## 2026 Luxury Dark-Gold / 3D Glassmorphism / Operations-Grade
## NO-SKIP / FULL-FILE / REAL BACKEND / NO-FAKE-DATA / NO-DUPLICATE-ARCHITECTURE

You are continuing an existing Laravel enterprise Thai Lottery platform.

Pages 44–100 have already been implemented/hardened.

The current implementation report states that Pages 44–100 remain:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

The frontend build has passed, translation parity has passed for the examined namespaces, but Laravel route listing, Blade runtime, PHPUnit, database execution, browser accessibility, and real payment-provider verification still require a complete runtime environment.

DO NOT reinterpret static success as runtime success.

This task covers:

```text
PAGE 100
PAGE 101
PAGE 102
...
PAGE 150
```

Every page MUST be independently addressed.

---

# 0. ABSOLUTE NO-SKIP RULE

Do not skip any page from 100 through 150.

For an existing page:

```text
EXISTING + HARDEN
```

For a missing page:

```text
IMPLEMENT
```

For a capability that is not safely configurable:

```text
NOT_CONFIGURED — FAIL CLOSED
```

For an infrastructure dependency that cannot be verified:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

Never invent a feature simply to fill a page number.

---

# 1. EXISTING ARCHITECTURE MUST BE REUSED

Before creating anything, inspect:

```text
app/Services/
app/Http/Controllers/
app/Http/Requests/
app/Models/
app/Policies/
app/DTOs/
app/Enums/
app/Support/
routes/
resources/views/
resources/js/
resources/css/
lang/en/
lang/th/
tests/
config/
database/
```

Known canonical services already present in the platform include:

```text
GloPrizeClaimService
GloPrizeCalculator
GloStampDutyCalculator
WalletService
WalletReservationService
FinancialReconciliationService
PaymentGatewayManager
PaymentInitiationService
PaymentCallbackService
WithdrawalDisbursementService
AccountVerificationService
ResponsibleGamingService
SelfExclusionService
AccountGradeService
```

Do not create duplicate versions.

---

# 2. MONEY RULE

All financial arithmetic MUST use:

```text
exact decimal strings
explicit Currency
Money
BCMath / canonical money helpers
database transaction
row locking where required
idempotency
ledger proof
```

Never use:

```text
float
double
javascript-only financial calculation
client-supplied final amount
client-supplied payout
client-supplied balance
```

---

# 3. ADMIN SECURITY RULE

Every admin/operator page must use:

```text
admin authentication
access-admin boundary
panel-specific permission
policy/object authorization
audit logging
least privilege
```

Do not rely on frontend hiding.

Do not use a single generic:

```php
is_admin
```

check as the only security layer.

The existing admin system already maps different panels to different `AdminAccess` permissions. Preserve that model.

---

# 4. PAGE 100 — ADMIN COMPLIANCE CENTER

## Route

```text
/admin/compliance
```

## Existing state

Harden existing compliance aggregation.

Use canonical:

```text
KYC
Responsible Gaming
Self Exclusion
Risk
Payment compliance
Withdrawal review
Audit
```

Do not duplicate those engines.

## UI

```text
Compliance Overview
KYC Queue
Responsible Gaming Alerts
Self-Exclusion
Withdrawal Review
Payment Exceptions
Audit Exceptions
Outstanding Cases
```

Every card must have a truthful state:

```text
AVAILABLE
NO_DATA
UNAVAILABLE
NOT_CONFIGURED
```

Never use fake zeroes.

---

# PAGE 101 — GLO PRIZE CLAIMS

## Route

```text
/admin/glo/prize-claims
```

## Canonical service

```text
GloPrizeClaimService
```

## UI

Show:

```text
claim reference
ticket reference
draw
claim state
gross prize where authoritative
net settlement where authoritative
KYC state
age verification state
freeze state
payment-hold state
ledger state
created time
updated time
```

Do NOT expose:

```text
internal ledger IDs
private KYC documents
operator notes
payment credentials
wallet internals
```

## Claim state

Use server-authoritative lifecycle:

```text
PENDING
ELIGIBLE
APPROVED
PAID
REJECTED
```

Do not allow the browser to invent transitions.

---

# PAGE 102 — GLO PRIZE CLAIM DETAIL

## Route

```text
/admin/glo/prize-claims/{claim}
```

Use:

```text
GloPrizeClaimService
```

## Detail sections

```text
Claim
Ticket
Draw
Eligibility
Prize calculation
Stamp duty
KYC
Freeze / hold
Approval
Settlement
Ledger reference
Audit history
```

Use exact calculation output.

If an unsold-ticket proportional calculation applies, read the canonical calculator/settlement result.

NEVER fallback to a static configured prize amount when proportional settlement is required.

---

# PAGE 103 — GLO TICKET FREEZE CONSOLE

## Route

```text
/admin/glo/ticket-freezes
```

Use existing:

```text
GloTicketFreeze
GloTicketFreezePolicy
```

## UI

```text
ticket reference
freeze state
reason
created
expires
claim relation
review state
```

Any action that freezes/unfreezes must require:

```text
permission
reason
audit
idempotency where applicable
```

Never implement a browser-only freeze.

---

# PAGE 104 — GLO TICKET FREEZE DETAIL

## Route

```text
/admin/glo/ticket-freezes/{token}
```

Use an opaque, authorization-safe reference.

Display:

```text
freeze status
public ticket reference
draw
reason
effective window
related claim
audit events
```

Do not expose hidden internal ticket identifiers.

Do not permit arbitrary object lookup by database ID without policy enforcement.

---

# PAGE 105 — PRIZE SETTLEMENT REVIEW

## Route

```text
/admin/glo/settlements
```

This is a read/review surface.

Display:

```text
eligible claims
approved claims
pending payout
paid claims
settlement exceptions
ledger state
reconciliation state
```

Do not provide a generic:

```text
PAY NOW
```

button.

Any legitimate payout action MUST invoke the canonical prize/payment service with authorization and audit logging.

---

# PAGE 106 — WALLET OPERATIONS CENTER

## Route

```text
/admin/wallet-operations
```

Create only if no canonical equivalent already exists.

Use existing wallet/account projections.

Display:

```text
wallet count
active wallets
held balances
pending financial activity
reconciliation status
currency breakdown
```

Currency must be explicit.

Never sum different currencies together.

No direct balance editing.

---

# PAGE 107 — PAYMENT METHODS MANAGEMENT

## Route

```text
/admin/payment-methods
```

Use:

```text
PaymentGatewayManager
payment config
PaymentMethod
```

## UI

Display each method:

```text
configured
enabled
deposit capable
withdrawal capable
supported currencies
provider state
```

Do NOT expose:

```text
API secret
private key
webhook secret
merchant password
token
```

Mask credentials entirely rather than showing partial secrets unless an existing secure admin convention already exists.

---

# PAGE 108 — WITHDRAWAL METHODS MANAGEMENT

## Route

```text
/admin/withdrawal-methods
```

Display:

```text
method
configured
enabled
withdrawal capability
supported currencies
approval requirement
provider state
```

Never show unsupported methods as selectable.

Never infer capability because a driver class exists.

---

# PAGE 109 — PAYMENT OPERATIONS

## Route

```text
/admin/payments
```

Use canonical Payment model/service.

Display:

```text
payment reference
type
method
currency
amount
state
gateway reference where safe
created
updated
```

Use read-only projections for normal review.

Any payment mutation must require an explicit canonical service contract.

---

# PAGE 110 — PAYMENT DETAIL

## Route

```text
/admin/payments/{payment}
```

Display:

```text
payment
deposit/withdrawal relation
internal status
provider status normalized to internal state
amount
currency
safe references
timeline
reconciliation state
```

Never let query parameters define payment status.

Never show:

```text
raw webhook body
signature
secret
access token
private gateway metadata
```

---

# PAGE 111 — PAYMENT EVENT / WEBHOOK AUDIT

## Route

```text
/admin/payments/events
```

Purpose:

Operational read-only view of verified payment events.

Display only safe metadata:

```text
event reference
provider
event type
normalized state
received at
processed at
idempotency state
processing result
```

Never display raw secret-bearing payloads.

Do not allow replay from browser unless a dedicated audited replay service exists.

---

# PAGE 112 — PAYMENT EXCEPTIONS

## Route

```text
/admin/payments/exceptions
```

Display:

```text
amount mismatch
currency mismatch
reference mismatch
provider refusal
duplicate event
replayed event
unknown payment
unmatched callback
```

Every exception must come from canonical reconciliation/evidence.

Do not create synthetic exceptions.

---

# PAGE 113 — DRAW LIFECYCLE OPERATIONS

## Route

```text
/admin/draw-lifecycle
```

Use canonical draw lifecycle services.

Display:

```text
scheduled
open
closed
awaiting result
result received
published
settling
settled
blocked
```

If draw automation already exists, show actual state.

Do not create client-side lifecycle transitions.

---

# PAGE 114 — RESULT PUBLICATION CONTROL

## Route

```text
/admin/result-publication
```

Use existing:

```text
GloResultPublicationService
result provenance services
```

UI:

```text
candidate result
source
provenance
verification state
publication state
integrity
published at
```

The browser must not mark an unverified result as published.

---

# PAGE 115 — RESULT IMPORT / PROVENANCE

## Route

```text
/admin/result-imports
```

Purpose:

Monitor/import approved National/Weekly/Mega/PCSO/GLO result bundles.

Display:

```text
lane
year
source
bundle reference
SHA-256/provenance
validation state
import state
row count
error count
last imported
```

Never display fake import counts.

Never activate fixture data as production data.

Fixture state must remain explicit.

---

# PAGE 116 — RESULT SOURCE HEALTH

## Route

```text
/admin/result-sources
```

Display:

```text
source
lane
configured
reachable where verified
freshness
last success
last failure
provenance
```

Do not perform uncontrolled live polling from the browser.

Use backend health snapshots.

---

# PAGE 117 — LOTTERY PRODUCT CATALOG

## Route

```text
/admin/lotteries
```

Display canonical product catalogue:

```text
product
code
lane
status
result capability
purchase capability
currency
ticket model
```

Do not expose products marked inactive as purchasable.

Do not invent product prices.

---

# PAGE 118 — LOTTERY RULES / PRICING

## Route

```text
/admin/lottery-rules
```

Display server-authoritative configuration:

```text
ticket price
fee
discount
prize rules
currency
validity/version
effective date
```

Rules must be versioned where the backend supports it.

Never silently mutate live economics from frontend controls.

For GLO/L6, business/legal source-of-truth must remain explicit.

---

# PAGE 119 — FEE SCHEDULE MANAGEMENT

## Route

```text
/admin/fees
```

Use:

```text
config/fees.php
canonical fee/rule service
fee versioning
```

Display:

```text
fee category
method
currency
amount/percentage
effective version
enabled state
```

Do not allow presentation values to diverge from the fee preview/charge logic.

---

# PAGE 120 — ACCOUNT GRADE ADMIN

## Route

```text
/admin/account-grades
```

Use:

```text
AccountGradeService
grade configuration
grade evaluator
discount projection
```

Display:

```text
grade
qualification
discount
entitlements
effective state
version
```

Do not directly edit a user's grade here.

---

# PAGE 121 — ACCOUNT VERIFICATION OPERATIONS

## Route

```text
/admin/account-verification
```

Use canonical KYC services.

Display:

```text
verification queue
status
submitted
review state
document count
risk flags where public-safe
```

Object access must be policy protected.

---

# PAGE 122 — RESPONSIBLE GAMING OPERATIONS

## Route

```text
/admin/responsible-gaming
```

Display aggregated operational states:

```text
active limits
pending changes
self-exclusions
cooldowns
review alerts
```

Never provide a browser bypass for RG rules.

---

# PAGE 123 — SELF-EXCLUSION OPERATIONS

## Route

```text
/admin/self-exclusion
```

Use:

```text
Compliance\SelfExclusionService
ResponsibleGaming\SelfExclusionService
```

Display:

```text
status
requested
effective
expiry if applicable
source
enforcement state
```

Any operator action must be logged.

Do not disable self-exclusion from a browser-only mutation.

---

# PAGE 124 — USER OPERATIONS

## Route

```text
/admin/users
```

Display an authorized, privacy-safe user list:

```text
user reference
account state
verification state
grade
created
last activity
```

Do not expose unnecessary PII.

Use masked contact fields when possible.

---

# PAGE 125 — USER DETAIL

## Route

```text
/admin/users/{user}
```

Sections:

```text
account
verification
grade
responsible gaming
wallet summary
recent bets
recent payments
audit history
```

Apply object-level authorization.

Do not expose:

```text
password
password hash
reset token
MFA secret
private KYC data
full payout credentials
```

---

# PAGE 126 — USER FINANCIAL PROFILE

## Route

```text
/admin/users/{user}/finance
```

Display:

```text
currency
available
held
deposit summary
withdrawal summary
bet summary
claim summary
reconciliation state
```

Use exact money.

Do not combine currencies.

No direct financial mutation.

---

# PAGE 127 — BET DETAIL

## Route

```text
/admin/bets/{bet}
```

Display:

```text
bet reference
user-safe reference
draw
product
selection
stake
currency
state
settlement
risk state
timestamps
```

Never allow payout modification from the view.

---

# PAGE 128 — TICKET DETAIL

## Route

```text
/admin/tickets/{ticket}
```

Display:

```text
ticket reference
draw
product
number/selection where policy permits
status
freeze state
claim state
verification state
```

Do not expose internal raw IDs unnecessarily.

---

# PAGE 129 — TICKET VERIFICATION OPERATIONS

## Route

```text
/admin/ticket-verification
```

Use canonical:

```text
LotteryTicketVerification
TicketBarcodeService
approved parser
```

Display:

```text
verification request
result
source
status
failure reason where safe
```

Never perform ticket parsing using arbitrary client JavaScript as the authority.

---

# PAGE 130 — PRIZE CLAIM REVIEW QUEUE

## Route

```text
/admin/prize-claim-review
```

Display:

```text
pending claims
KYC gate
freeze gate
age gate
eligibility
calculated settlement
approval requirement
```

No browser-side final approval.

---

# PAGE 131 — COMMISSION OPERATIONS

## Route

```text
/admin/commissions
```

Use canonical agent/referral data.

Display:

```text
agent
commission period
basis
amount
currency
status
settlement state
```

Never compute commissions in JS.

Never allow client-supplied commission values.

---

# PAGE 132 — AGENT DASHBOARD

## Route

```text
/agent
```

The existing project has agent routes.

Replace inline route closures with a real controller/service architecture where necessary.

Use:

```text
Agent
AgentService
commission service
settlement service
```

UI:

```text
agent status
commission snapshot
settlement state
referral activity
support
```

No fake metrics.

---

# PAGE 133 — AGENT COMMISSIONS

## Route

```text
/agent/commissions
```

Display:

```text
period
source
basis
commission
currency
status
```

Use exact server-calculated values.

---

# PAGE 134 — AGENT SETTLEMENTS

## Route

```text
/agent/settlements
```

Display:

```text
settlement reference
period
gross
fees
net
currency
status
paid at
```

No client-side settlement calculation.

---

# PAGE 135 — AGENT STATEMENT

## Route

```text
/agent/statement
```

Display a chronological financial statement:

```text
date
reference
type
amount
currency
running balance where canonical
status
```

Exact money only.

If multiple currencies exist, group the statement by currency.

---

# PAGE 136 — REFERRAL OVERVIEW

## Route

```text
/agent/referrals
```

Display:

```text
referral reference
status
joined
qualified state
commission eligibility
```

Do not leak private information from referred users beyond the approved referral projection.

---

# PAGE 137 — REFERRAL DETAIL

## Route

```text
/agent/referrals/{referral}
```

Show only permitted referral information.

Do not expose:

```text
password
private wallet amount
full payment history
KYC documents
private address
operator notes
```

---

# PAGE 138 — SUPPORT CENTER / INBOX

## Route

```text
/support
```

or the existing canonical equivalent.

Use existing contact/support architecture.

Display:

```text
open requests
pending requests
resolved requests
category
reference
updated
```

Do not expose another user's support record.

---

# PAGE 139 — SUPPORT REQUEST DETAIL

## Route

```text
/support/{reference}
```

Display:

```text
support reference
category
messages
status
created
updated
```

Messages must be owner-scoped.

Do not trust user ID from the URL for ownership.

---

# PAGE 140 — NOTIFICATION CENTER

## Route

```text
/notifications
```

Use an existing notification architecture where present.

Display:

```text
unread
read
category
created
safe action link
```

Do not use fake notification counts.

If notification write/read API exists, use it.

Otherwise the view remains read-only.

---

# PAGE 141 — SYSTEM HEALTH

## Route

```text
/health
```

Use existing:

```text
HealthController
```

Separate:

```text
liveness
readiness
dependency health
```

Readiness should reflect canonical:

```text
database
cache
storage
queue
```

Do not expose sensitive infrastructure information publicly.

---

# PAGE 142 — OPERATOR METRICS

## Route

```text
/metrics
```

Use existing metrics route.

This is operator-only.

Do not make:

```text
/metrics
```

public.

Do not expose financial/private dimensions unnecessarily.

Preserve the existing `access-metrics` gate.

---

# PAGE 143 — QUEUE / WORKER HEALTH

## Route

```text
/admin/queues
```

Display:

```text
queue
worker state
pending
failed
last processed
latency
```

Use backend telemetry.

Do not allow arbitrary queue command execution from the browser.

---

# PAGE 144 — SCHEDULED TASKS

## Route

```text
/admin/scheduler
```

Display:

```text
task
schedule
last run
next run
last result
failure state
```

Use canonical scheduler metadata.

Do not allow users to inject arbitrary artisan commands.

---

# PAGE 145 — CACHE / SESSION OPERATIONS

## Route

```text
/admin/runtime
```

Display only safe operational state:

```text
cache availability
session backend
queue backend
storage availability
application environment
version
```

Never expose:

```text
APP_KEY
database password
Redis password
SMTP password
payment keys
environment secrets
```

Do not provide generic cache flush/database shell actions.

---

# PAGE 146 — API STATUS CENTER

## Route

```text
/admin/api-status
```

Display approved API integrations:

```text
integration
purpose
configured
enabled
health
last success
last failure
```

Never expose credentials.

Do not probe external APIs continuously from the browser.

---

# PAGE 147 — WEBHOOK AUDIT CENTER

## Route

```text
/admin/webhooks
```

Show:

```text
provider
event ID
received
verified
normalized
processed
duplicate/replay
failure state
```

Never show raw secrets.

Never display signature material.

Do not create a “trust webhook” browser action.

---

# PAGE 148 — SECURITY AUDIT CENTER

## Route

```text
/admin/security
```

Show:

```text
authentication events
permission denials
security alerts
rate-limit events
KYC access
financial critical actions
self-exclusion actions
```

Use existing AuditLog/security events.

No password/reset-token data.

---

# PAGE 149 — RELEASE / DEPLOYMENT STATUS

## Route

```text
/admin/release
```

Display:

```text
application version
git commit/reference
build state
asset manifest state
runtime state
database migration state
queue state
configuration readiness
```

Do not expose:

```text
server secrets
private filesystem paths
credentials
environment dumps
```

The page may show:

```text
READY
BLOCKED
DEGRADED
NOT VERIFIED
```

based on real checks.

---

# PAGE 150 — PRODUCTION CUTOVER CONTROL CENTER

## Route

```text
/admin/cutover
```

This is the final operational dashboard.

Do NOT turn deployment actions into arbitrary shell execution.

Display checklist state:

```text
release artifact
Composer dependencies
Vite manifest
PHP runtime
database backup
database migration
real lottery data
payment provider configuration
webhook verification
wallet/ledger reconciliation
KYC storage isolation
queue workers
scheduler
legacy redirects
robots/sitemap
security headers
EN/TH parity
browser smoke checks
rollback readiness
```

Each item must have:

```text
PASS
BLOCKED
NOT VERIFIED
REQUIRED
```

No fake green checks.

---

# 6. PRODUCTION CUTOVER SAFETY

Do not create browser buttons like:

```text
Run migration
Restore database
Delete database
Flush all cache
Restart server
Rotate key
Change APP_KEY
```

unless the repository already contains an explicit authorized operational service for that exact action.

Prefer:

```text
display status
generate command/runbook instruction
link to documented operational procedure
```

over arbitrary remote command execution.

---

# 7. GLO CLAIM FINANCIAL RULE

For every GLO prize claim:

```text
ticket
→ draw
→ eligibility
→ KYC/age
→ freeze/hold
→ authoritative proportional prize calculation
→ stamp duty
→ approval
→ wallet/ledger settlement
→ reconciliation
```

Do not use a fixed configured prize amount where unsold-ticket proportional settlement applies.

Do not create a second settlement calculator.

---

# 8. ADMIN ACTION IDEMPOTENCY

Any state-changing admin action must be protected against duplicate submission.

At minimum test:

```text
approve twice
reject twice
freeze twice
unfreeze twice
settlement retry
claim payout retry
webhook replay
```

Repeated actions must be deterministic.

---

# 9. AUDIT LOGGING

For every money/compliance/security action include:

```text
actor
action
resource
timestamp
reason
result
correlation ID where appropriate
```

Never log:

```text
password
secret
private key
full KYC document
raw payment token
raw authorization header
```

---

# 10. AGENT SECURITY

Agent users must never access:

```text
/admin/*
other agents' private financial data
private KYC
operator notes
global reconciliation
internal ledger
payment secrets
```

Enforce object ownership in backend.

---

# 11. SUPPORT SECURITY

Support staff must only access cases allowed by role.

Players may only access their own support records.

Do not expose support conversations through predictable sequential IDs without authorization.

Prefer opaque public references where the existing architecture supports them.

---

# 12. TRANSLATIONS

Add/update exact EN/TH parity for:

```text
glo_claims
admin_operations
admin_payments
admin_draws
admin_results
admin_wallets
admin_ledger
admin_reconciliation
admin_kyc
admin_compliance
admin_users
admin_security
admin_release
agent
support
notifications
runtime
```

Every user-visible string must be translated.

No raw translation keys in rendered HTML.

---

# 13. VISUAL SYSTEM

All operational pages must use the existing luxury dark-gold system without becoming visually noisy.

Use:

```text
dark layered background
glass panels
gold accent
clear hierarchy
data density with readability
premium tables
status badges
elevated KPI cards
```

For operational health:

Do not rely on red/green color alone.

Always pair visual state with text.

---

# 14. DATA TABLE RULES

Tables must support:

```text
responsive overflow
sticky header where appropriate
safe pagination
empty state
loading state
error state
accessible headings
```

Never load unbounded records.

Use backend limits.

---

# 15. ADMIN QUERY BOUNDS

Every admin query must have bounded:

```text
date range
page size
result count
filter values
sort options
```

Do not allow arbitrary database column names from the browser.

Do not allow arbitrary SQL expressions.

---

# 16. RATE LIMITING

Use the existing rate-limit architecture.

Relevant operational categories include:

```text
financial-critical
admin-analytics
withdrawal
deposit
player-api
webhook
```

Do not create a second limiter registry.

For any new write endpoint, determine whether an existing named limiter can be reused.

---

# 17. API / JSON RULE

Any admin JSON API must return a stable structured response.

Never return:

```text
stack trace
SQL
file path
exception class
secret
wallet internal ID
```

Errors must use the project's existing API envelope.

---

# 18. SEO

Admin/agent/support/private pages:

```text
noindex
```

Do not add private pages to sitemap.

Public pages only enter sitemap through existing canonical `SitemapController`.

---

# 19. LEGACY URL BRIDGE

Preserve the explicit legacy `.php` bridge.

Do not touch it with a broad wildcard rewrite that captures:

```text
/admin
/admin/*
/agent
/payment
/support
```

Unknown `.php` remains 404 unless explicitly mapped.

---

# 20. TEST SUITE

Create/update at least:

```text
GloPrizeClaimsPageTest
GloPrizeClaimDetailTest
GloTicketFreezePageTest
GloTicketFreezeDetailTest
GloSettlementReviewTest

WalletOperationsPageTest
PaymentMethodsAdminTest
WithdrawalMethodsAdminTest
AdminPaymentsTest
PaymentDetailTest
PaymentEventAuditTest
PaymentExceptionsTest

DrawLifecycleAdminTest
ResultPublicationAdminTest
ResultImportAdminTest
ResultSourceHealthTest

LotteryCatalogAdminTest
LotteryRulesAdminTest
FeeScheduleAdminTest
AccountGradeAdminTest
AccountVerificationAdminTest
ResponsibleGamingAdminTest
SelfExclusionAdminTest

AdminUsersTest
AdminUserDetailTest
AdminUserFinanceTest
AdminBetDetailTest
AdminTicketDetailTest
AdminTicketVerificationTest
AdminPrizeClaimReviewTest

AdminCommissionTest
AgentDashboardTest
AgentCommissionTest
AgentSettlementTest
AgentStatementTest
ReferralOverviewTest
ReferralDetailTest

SupportCenterTest
SupportDetailTest
NotificationCenterTest

HealthPageTest
MetricsAccessTest
QueueHealthTest
SchedulerHealthTest
RuntimeOperationsTest
ApiStatusTest
WebhookAuditTest
SecurityAuditTest
ReleaseStatusTest
CutoverControlTest
```

---

# 21. AUTHORIZATION TEST MATRIX

For every admin page:

```text
guest -> 401/redirect
player -> 403
agent -> 403
wrong admin role -> 403
correct operator -> allowed
```

For object-level resources:

```text
wrong object -> 404/403 according to existing policy
correct object + correct permission -> allowed
```

For KYC:

```text
unauthorized document -> inaccessible
authorized reviewer -> allowed
```

---

# 22. FINANCIAL TEST MATRIX

Test:

```text
deposit replay
withdrawal replay
claim payout replay
settlement replay
webhook replay
admin duplicate action
concurrent action
wallet lock
ledger balance
reconciliation after action
```

Required invariants:

```text
no double credit
no double debit
no duplicate claim payout
no duplicate ledger posting
no negative available balance
no hidden cross-currency transfer
```

---

# 23. GLO TEST MATRIX

Verify:

```text
80 THB single ticket model where canonical configuration requires it
14,168 prize allocation
48,000,000 THB full allocation
unsold proportional settlement
stamp duty
age >= 20
KYC
freeze/hold
claim state transitions
ledger settlement
reconciliation
provenance
```

Do not override existing business/legal source-of-truth decisions.

---

# 24. OPERATIONAL HEALTH TEST MATRIX

Verify:

```text
database unavailable
cache unavailable
queue unavailable
storage unavailable
payment gateway unavailable
KYC storage unavailable
result source unavailable
```

Every page must fail safely.

Do not transform operational failure into:

```text
0
healthy
complete
paid
settled
verified
```

without evidence.

---

# 25. BROWSER / UX REQUIREMENTS

Every page requires:

```text
desktop
laptop
tablet
mobile
small mobile
```

Test:

```text
keyboard
focus
screen reader labels
loading
errors
empty state
reduced motion
horizontal overflow
```

---

# 26. JAVASCRIPT

JavaScript may handle:

```text
tabs
filters
modal
pagination UI
polling
form enhancement
charts
```

JavaScript must NOT become authority for:

```text
balance
price
commission
payout
claim
reconciliation
KYC decision
payment completion
risk decision
```

---

# 27. FULL FILE CONTENT REQUIREMENT

For every changed/created file, produce:

```text
### FILE: exact/path
# TYPE: ...
# PURPOSE: ...
```

Then the COMPLETE file content.

Never write:

```text
...
existing content...
same as above
omitted
TODO
FIXME
```

---

# 28. COMPLETE TREE REQUIREMENT

Every changed path must have a comment.

Example:

```text
app/
├── Http/
│   └── Controllers/
│       ├── Admin/
│       │   ├── PrizeClaimController.php # PHP controller — authorized GLO prize claim operations
│       │   ├── PaymentOperationsController.php # PHP controller — payment operations projection
│       │   └── CutoverController.php # PHP controller — release/cutover status projection
│       └── Agent/
│           └── AgentPortalController.php # PHP controller — authenticated agent portal
├── Services/
│   ├── Lottery/
│   │   └── GloPrizeClaimService.php # PHP service — canonical GLO prize claim lifecycle
│   ├── Payment/
│   │   └── PaymentGatewayManager.php # PHP service — canonical payment capability
│   └── Support/
│       └── SupportService.php # PHP service — owner-scoped support workflow
```

Continue for the entire changed file set.

---

# 29. AUDIT REPORT

Update:

```text
audit.md
svgaudit.md
```

as appropriate.

Add a complete page matrix:

```text
100
101
102
103
104
105
106
107
108
109
110
111
112
113
114
115
116
117
118
119
120
121
122
123
124
125
126
127
128
129
130
131
132
133
134
135
136
137
138
139
140
141
142
143
144
145
146
147
148
149
150
```

Required columns:

```text
Page
Title
Route
Route Name
Middleware
Authorization
Controller
Request
Service
Model
Database
API
View
JS
CSS
Translation
Data Source
Financial Impact
Security
Audit Log
Status
Tests
Runtime Status
Remaining Gap
```

One row per page.

---

# 30. VALIDATION COMMANDS

Run all commands available in the current environment.

```bash
php -l ...
php artisan route:list
php artisan view:cache
php artisan test
npm ci
npm run build
node --check ...
```

If Rust is touched:

```bash
cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

---

# 31. BUILD ARTIFACT

Verify:

```text
public/build/manifest.json
```

exists after build.

Do not claim the build artifact is present in the persisted delivery snapshot unless it actually is.

---

# 32. RUNTIME STATUS

Use exact wording:

```text
PASS
NOT VERIFIED — RUNTIME UNAVAILABLE
BLOCKED
NOT_CONFIGURED — FAIL CLOSED
DATA IMPORT REQUIRED
EXTERNAL VERIFICATION REQUIRED
ACCESS CONTROL VERIFICATION REQUIRED
```

Never say:

```text
production ready
fully verified
100% complete
```

unless actual production-equivalent evidence exists.

---

# 33. RELEASE / CUTOVER CHECKS

Before marking Page 150 complete, verify the repository's documented cutover requirements:

```text
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
APP_ENV=production
APP_DEBUG=false
public/build/manifest.json
no .env
no secrets
real result imports
real payment provider credentials configured
webhook signatures verified
queue workers running
scheduler running
database backup
rollback plan
legacy redirects
sitemap
robots
KYC private storage
security headers
```

Never copy real credentials into source.

---

# 34. NO-SKIP FINAL ACCEPTANCE

Pages 100–150 are not complete until every page is either:

```text
IMPLEMENTED
IMPLEMENTED + HARDENED
NOT_CONFIGURED — FAIL CLOSED
DATA IMPORT REQUIRED
EXTERNAL VERIFICATION REQUIRED
NOT VERIFIED — RUNTIME UNAVAILABLE
```

Each status must be backed by actual evidence.

---

# 35. FINAL IMPLEMENTATION SUMMARY

At the end of the report write:

```text
PAGES 100–150

TOTAL PAGES:
IMPLEMENTED:
HARDENED:
NOT_CONFIGURED:
DATA IMPORT REQUIRED:
EXTERNAL VERIFICATION REQUIRED:
ACCESS CONTROL VERIFICATION REQUIRED:
RUNTIME UNAVAILABLE:
BLOCKED:

FINANCIAL FINDINGS:
KYC FINDINGS:
RESPONSIBLE GAMING FINDINGS:
GLO CLAIM FINDINGS:
AGENT FINDINGS:
SUPPORT FINDINGS:
OBSERVABILITY FINDINGS:
DEPLOYMENT FINDINGS:
SECURITY FINDINGS:
REMAINING GAPS:
```

---

# 36. FINAL AGENT DIRECTIVE

Do not treat Pages 100–150 as “additional frontend pages.”

These pages complete the operational layer around the existing:

```text
lottery
results
ticket
purchase
wallet
payment
withdrawal
KYC
responsible gaming
grade
claims
ledger
reconciliation
agent
support
observability
deployment
```

Every page MUST preserve this architecture:

```text
UI
→ route
→ middleware
→ authorization
→ controller
→ request
→ canonical service
→ model/repository
→ database/provider
→ authoritative projection
→ audit
→ test
```

Never move business authority into Blade or JavaScript.

Never create parallel financial engines.

Never create parallel KYC engines.

Never create parallel responsible-gaming engines.

Never create parallel grade engines.

Never create parallel payment engines.

Never create browser-only admin mutations.

Never fabricate results.

Never fabricate KPI.

Never fabricate wallet balances.

Never fabricate payment status.

Never fabricate prize claims.

Never fabricate commissions.

Never fabricate compliance state.

Never fabricate operational health.

Never expose secrets.

Never expose private KYC.

Never expose internal ledger data unnecessarily.

Never expose another player's private records.

Never bypass idempotency.

Never bypass authorization.

Never bypass responsible gaming.

Never bypass KYC.

Never mark money as paid from a browser redirect.

Never let a payment callback mutate financial state.

Never let an operator page directly edit a wallet.

Never let the frontend decide reconciliation truth.

NO FAKE DATA.
NO FAKE MONEY.
NO FAKE PAYMENT.
NO FAKE CLAIM.
NO FAKE ADMIN STATE.
NO FAKE KPI.
NO DUPLICATE SERVICE.
NO DUPLICATE CONTROLLER LOGIC.
NO RAW SECRETS.
NO PLACEHOLDER FILE CONTENT.
NO SKIPPED PAGE.