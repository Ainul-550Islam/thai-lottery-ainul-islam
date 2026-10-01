# CODING AGENT PROMPT — PAGES 77–100
## ThaiLotto Enterprise Platform
## 2026 Luxury Dark-Gold iGaming UI + Real Backend Integration
## NO-SKIP / FULL-FILE / NO-FAKE-DATA / SECURITY-FIRST / OPERATIONS-GRADE

Continue the existing Laravel Thai Lottery platform.

Pages 44–70 were already implemented/hardened.

This task covers:

```text
77–100
```

Every page below must be addressed independently.

Do not mark a page complete merely because a similar route already exists.

Where the route/controller/service already exists, perform a full review and upgrade the actual implementation.

---

# 1. EXISTING PLATFORM STATE

Preserve:

- Laravel application architecture
- existing route names where referenced by the application
- existing services
- existing security middleware
- existing policies
- existing payment architecture
- existing wallet/ledger architecture
- existing EN/TH localization
- existing design system
- existing legacy redirect bridge
- existing API contracts
- existing audit/logging contracts

Do NOT create duplicate architecture.

---

# 2. ABSOLUTE RULES

## Never fabricate

Never hardcode:

- financial amounts
- transactions
- result numbers
- account balances
- users
- payment statuses
- KYC records
- admin KPIs
- risk scores
- reconciliation totals
- payout records
- provider statuses
- commissions
- settlement values
- audit outcomes

Everything must come from authoritative backend data.

---

# 3. DESIGN SYSTEM

Use the existing luxury system:

```text
#0B0904
#141007
#D4AF37
#F5E6B8
#FFF6D6
```

Reuse existing:

```text
svgtheme.css
svgglass.css
global layouts
glass panels
buttons
inputs
tables
cards
badges
responsive primitives
```

Do NOT create another design system.

---

# 4. VISUAL DIRECTION

Use:

- cinematic dark background
- premium gold lighting
- subtle 3D depth
- glass surfaces
- premium typography
- controlled glow
- layered cards
- professional data tables
- responsive operational dashboards
- restrained animation
- high contrast
- premium empty/error/loading states

Avoid:

- excessive blur
- fake government seals
- fake certifications
- fake provider logos
- fake compliance badges
- fake official endorsements
- fake operational statistics

---

# 5. PUBLIC PAGES 77–85

---

# PAGE 77 — PUBLIC RESULTS HUB

## Route

```text
/results
```

## Route name

Use the existing canonical route name.

## Controller

Use:

```text
ResultsController
```

or the already established canonical results controller.

## Requirements

Create a premium unified results center containing:

```text
Latest results
National
Weekly
Mega
PCSO
GLO L6
Search
Historical results
Year archives
Ticket verification CTA
```

Do not merge product data models incorrectly.

Each lane retains:

```text
own service
own result projection
own provenance
own status vocabulary
own field structure
```

## Security

Never expose:

- internal IDs
- provider credentials
- internal source records
- private reconciliation metadata

## Empty states

Every product must support:

```text
RESULT_FOUND
NO_PUBLIC_DATA
UNAVAILABLE
```

Use backend state, never visual placeholders.

---

# PAGE 78 — PUBLIC TICKET CHECK

## Routes

```text
/check
```

GET:

```text
ticket-check
```

POST:

```text
ticket-check.submit
```

## Backend

Use existing canonical ticket-check service.

Do NOT implement ticket validation in JavaScript.

## UI

```text
Ticket number
Validation action
Result state
Winning/non-winning state
Draw information
Prize information when authoritative
Verification timestamp where available
```

## Security

- throttle
- CSRF
- input bounds
- strict ticket format
- no enumeration leak beyond existing policy
- no internal record IDs
- no hidden backend payload

## IMPORTANT

Never convert:

```text
ticket not found
```

into:

```text
ticket lost
```

unless backend explicitly defines that state.

---

# PAGE 79 — SALES POINTS

## Route

```text
/sales-points
```

## Controller

Use existing:

```text
HomeController::salesPoints
```

or canonical sales-point controller/service.

## UI

Create a premium map/list experience:

```text
search
area filter
distance where supported
sales point name
address
opening information where authoritative
contact information where authoritative
availability state
```

## Rules

Do not fabricate:

- branches
- addresses
- hours
- phone numbers
- GPS coordinates

Only display real backend source data.

Graceful state:

```text
NO_PUBLIC_DATA
UNAVAILABLE
```

---

# PAGE 80 — PRIVACY POLICY

## Route

```text
/privacy
```

Use canonical public legal architecture.

## Requirements

Display:

- privacy policy version
- effective date
- data categories
- purposes
- retention
- sharing
- security
- rights
- contact path
- responsible party identity from legal source

Do not invent legal claims.

Do not claim regulatory registration without verified configuration/source.

## SEO

Public and indexable when approved.

---

# PAGE 81 — CONTACT / SUPPORT CENTER

## GET

```text
/contact
```

## POST

```text
/contact
```

Route names must remain compatible with existing application links.

## UI

Premium support center:

```text
Contact channels
Support categories
Contact form
Response expectations
FAQ CTA
Account help
Payment help
Withdrawal help
Technical help
```

## POST security

Must preserve:

```text
CSRF
throttle:contact-submit
validation
spam protection if configured
sanitized storage
safe mail dispatch
audit/logging
```

Never claim a message was successfully sent unless canonical submission actually succeeds.

---

# PAGE 82 — DOWNLOAD APP

## Routes

```text
/download
/download-app
/app
```

These are presentation variants of the same public app-download capability.

## Backend

Use:

```text
PublicDownloadAppController
PublicAppLinkService
```

## IMPORTANT

Only render a platform button when the URL is:

```text
configured
valid
approved
HTTP(S) or approved root-relative
```

Reject:

```text
javascript:
data:
placeholder:
fake:
```

Do not invent:

- App Store URL
- Google Play URL
- APK URL
- PWA URL

If not configured:

```text
NOT_CONFIGURED
```

with clean premium UX.

---

# PAGE 83 — PUBLIC ACCOUNT GRADE EXPLAINER

## Routes

```text
/account-grades
/account-grade
```

Use:

```text
PublicGradeController
```

## IMPORTANT

This is a PUBLIC explainer.

Do not leak authenticated player grade data.

Show only:

- published grade ladder
- benefits
- qualification methodology
- generic published thresholds where approved
- discount information
- FAQ/support links

Do NOT display:

```text
current_user_grade
current_user_spend
current_user_progress
```

to guests.

---

# PAGE 84 — PUBLIC ACCOUNT VERIFICATION GUIDE

## Routes

```text
/account-verification
/account-verification-guide
```

Use:

```text
PublicVerificationController
```

## UI

```text
Why verification is required
Required information
Accepted document types
Verification process
Expected states
Security/privacy explanation
Login/register CTA
Authenticated verification CTA
Support CTA
```

Do not display real user KYC records.

Do not accept document upload from the public guide page.

Actual KYC submission remains authenticated.

---

# PAGE 85 — PUBLIC SEO / INDEXATION SURFACE

Treat this as the public discovery/indexation layer.

Audit and harden:

```text
canonical URLs
robots directives
OpenGraph
Twitter/X metadata where supported
structured metadata where truthful
sitemap references
language alternate
EN/TH canonical pairing
```

DO NOT index:

```text
/dashboard
/wallet
/deposit
/withdraw
/profile
/account/verification
/account/grade
/admin/*
payment return routes
private search/result query URLs
```

Public result/year pages may be indexable only when:

```text
real data exists
canonical URL exists
source policy allows publication
```

---

# 6. PAYMENT RETURN PAGES 86–89

These are presentation-only payment return pages.

Existing architecture already treats browser return as context, while authoritative payment state comes from internal payment records and verified backend events.

Do not change that model.

---

# PAGE 86 — PAYMENT SUCCESS

## Route

```text
/payment/success
```

Route name:

```text
payment.callback.success
```

## UI states

Possible:

```text
CONFIRMED
PENDING
FAILED
NOT_FOUND
```

A success URL MUST NOT automatically mean payment succeeded.

Only show:

```text
Paid
Confirmed
Completed
```

when the internal payment record proves it.

Never mutate:

- wallet
- payment
- deposit
- ledger

from this page.

---

# PAGE 87 — PAYMENT FAILURE

## Route

```text
/payment/failure
```

Route name:

```text
payment.callback.failure
```

UI:

```text
Payment unsuccessful
Current actual state
Safe reference
Amount
Currency
Next action
Return to wallet
Retry where safely supported
Support
```

Do not invent a failure reason.

Use provider/internal reason only when explicitly marked public-safe.

---

# PAGE 88 — PAYMENT CANCEL

## Route

```text
/payment/cancel
```

Route name:

```text
payment.callback.cancel
```

This is distinct from failure.

Display:

```text
Payment cancelled
Current actual payment state
Safe reference
Next action
Return to deposit
Return to wallet
```

CRITICAL:

Stripe or other gateway cancel URL must land here when configured.

Do not redirect cancellation into failure semantics.

---

# PAGE 89 — PAYMENT PENDING

## Route

```text
/payment/pending
```

Route name:

```text
payment.callback.pending
```

UI must explain:

```text
Payment initiated
Payment not yet confirmed
Do not submit duplicate payment
Wait for provider confirmation
Refresh/status guidance
Wallet credit occurs only after verified completion
```

Never show:

```text
success
paid
credited
```

unless backend status proves it.

---

# 7. OPERATIONS CONSOLE PAGES 90–100

These pages are INTERNAL.

They MUST NOT become public pages.

The `/admin` route prefix is NOT itself a complete authorization control.

Every route must enforce actual:

```text
authentication
authorization
role/policy
least privilege
auditability
```

Never rely on frontend hiding alone.

---

# PAGE 90 — ADMIN EXECUTIVE DASHBOARD

## Routes

```text
/admin
/admin/dashboard
```

## Controller

```text
LottoFinExecutiveDashboardController
```

## UI

Premium operations dashboard:

```text
Draw health
Bet volume
Wallet exposure
Deposit activity
Withdrawal activity
Risk indicators
Reconciliation state
KYC queue
Operational alerts
System health
```

## CRITICAL

No fake numbers.

If source unavailable:

```text
UNAVAILABLE
```

not:

```text
0
```

or sample figures.

---

# PAGE 91 — ADMIN ANALYTICS

## Route

```text
/admin/api/analytics
```

This is an authenticated operational endpoint.

## Requirements

Return only authorized aggregate analytics.

Never expose:

- arbitrary customer PII
- password fields
- raw payment secrets
- raw KYC documents
- unfiltered ledger internals

Apply:

```text
authorization
date bounds
query bounds
pagination/aggregation limits
rate protection
```

Frontend charts must consume backend aggregates.

Do not calculate operational totals from incomplete browser data.

---

# PAGE 92 — ADMIN DRAW OPERATIONS

## Route

```text
/admin/draws
```

## UI

```text
draw schedule
draw status
publication state
result state
source state
pending issues
reconciliation state
```

Operators must clearly distinguish:

```text
scheduled
open
closed
result pending
published
blocked
reconciled
conflict
```

Do not let a UI action publish a result without the canonical backend workflow.

---

# PAGE 93 — ADMIN RISK CONSOLE

## Route

```text
/admin/risk
```

## UI

Show backend-authoritative:

```text
risk alerts
velocity anomalies
wallet risk
betting anomalies
payment anomalies
responsible-gaming alerts
manual review queue
```

Do not invent risk scores.

Do not allow the browser to mark a player “safe” or “high risk”.

Risk state belongs to backend policy/engine.

---

# PAGE 94 — ADMIN BET OPERATIONS

## Route

```text
/admin/bets
```

Show:

```text
bet reference
draw
product
state
amount
currency
settlement state
risk state
```

Do not expose private player information beyond authorized operator scope.

## Actions

Actions must be backed by explicit policies.

Do not create browser-only cancellation, settlement or payout mutations.

Every money-affecting action requires:

```text
authorization
reason
audit log
idempotency where applicable
```

---

# PAGE 95 — ADMIN WALLET OPERATIONS

## Route

```text
/admin/wallets
```

Show:

```text
wallet status
currency
available
held
pending
reconciliation state
```

Do not expose:

- secrets
- private payment credentials
- raw account tokens
- unnecessary wallet internals

## IMPORTANT

Never provide an admin UI action that directly edits a wallet balance.

All money changes must flow through canonical financial services.

---

# PAGE 96 — ADMIN LEDGER

## Route

```text
/admin/ledger
```

## UI

Professional accounting journal:

```text
entry reference
account
transaction type
debit/credit direction
amount
currency
timestamp
source
status
reconciliation status
```

## Rules

Ledger entries are read-only from this interface unless a dedicated authorized correction workflow already exists.

Do not expose raw internal IDs when a safe reference can be shown.

Never delete immutable ledger entries.

Never rewrite historical ledger records.

---

# PAGE 97 — ADMIN RECONCILIATION

## Routes

```text
/admin/reconciliation
/admin/api/reconciliation
```

## UI

Show:

```text
reconciliation status
matched count
unmatched count
exceptions
financial deltas
payment exceptions
wallet exceptions
ledger exceptions
resolution status
```

Never fabricate reconciliation percentages.

If unavailable:

```text
RECONCILIATION_UNAVAILABLE
```

## Safety

A reconciliation mismatch must remain visible.

Do not make the UI hide mismatches merely to create a green dashboard.

Any halt/block state from the finance engine must remain authoritative.

---

# PAGE 98 — ADMIN AUDIT LOG

## Route

```text
/admin/audits
```

## UI

Display:

```text
event
actor
timestamp
category
resource reference
action
result
correlation ID where approved
```

Do not expose:

```text
passwords
tokens
provider secrets
raw KYC document contents
private payout data
```

Audit logs should be append-only/read-only unless an explicit authorized retention workflow exists.

---

# PAGE 99 — ADMIN KYC / VERIFICATION

## Routes

```text
/admin/kyc
/admin/kyc/{id}/download
/admin/kyc/{id}/approve
/admin/kyc/{id}/reject
```

## SECURITY IS CRITICAL

Require:

```text
authenticated operator
KYC-specific authorization
object-level authorization
audit logging
rate controls
private document storage
secure download
```

## UI

```text
verification queue
status
submitted date
document metadata
review state
approve
reject
secure document access
```

Do not expose raw private documents to unauthorized operators.

Do not embed public document URLs.

Do not reveal encrypted storage paths.

## Approval/rejection

Require explicit backend action and authorization.

Use canonical:

```text
AccountVerificationService
```

and existing policy/service architecture.

Never mark verification approved in the browser only.

---

# PAGE 100 — ADMIN COMPLIANCE CENTER

## Route

```text
/admin/compliance
```

## UI

Create a consolidated compliance operations surface:

```text
KYC status
Responsible gaming
Self-exclusion
Risk alerts
Payment compliance state
Withdrawal review
Audit status
Exception queue
```

## Rules

This page must be an aggregation layer.

Do not duplicate:

```text
KYC engine
Risk engine
ResponsibleGamingService
SelfExclusionService
Payment compliance engine
```

Reuse canonical services.

The UI must only present authoritative state.

---

# 8. ADMIN SECURITY MODEL

Every `/admin/*` route requires explicit authorization.

Prefer:

```text
policy
gate
ability
role
permission
```

as already supported by the platform.

Do NOT use:

```php
if ($user->is_admin) {
```

as the only security boundary.

Never rely on:

```text
CSS hidden
disabled button
frontend route hiding
JavaScript guard
```

for security.

---

# 9. ADMIN API SECURITY

For:

```text
/admin/api/analytics
/admin/api/reconciliation
```

enforce:

```text
authenticated session/API identity
authorization
bounded date range
bounded pagination
safe aggregation
rate limiting
structured JSON
request correlation
audit logging where appropriate
```

Never permit arbitrary SQL-like query parameters from the browser.

---

# 10. PUBLIC/ADMIN DATA SEPARATION

Never reuse a public result projection blindly inside admin pages where sensitive operational metadata is needed.

Never send admin operational metadata into public page responses.

Use separate:

```text
public projection
operator projection
private projection
```

where necessary.

---

# 11. EN/TH LOCALIZATION

Add/update:

```text
lang/en/*
lang/th/*
```

for:

```text
results
ticket check
sales points
privacy
contact
download
payment callback
admin dashboard
analytics
risk
bets
wallet
ledger
reconciliation
audits
KYC
compliance
```

Exact EN/TH key parity required.

No missing nested keys.

No raw translation keys in HTML.

---

# 12. ACCESSIBILITY

All pages:

- semantic headings
- keyboard navigation
- visible focus
- skip links
- accessible tables
- accessible status alerts
- form error association
- screen-reader labels
- reduced-motion support

Operational dashboards must not rely only on color.

For example:

```text
GREEN = healthy
```

must also have text such as:

```text
HEALTHY
```

---

# 13. RESPONSIVE ADMIN UI

Admin pages must remain usable on:

```text
desktop
laptop
tablet
mobile
```

For dense tables:

- responsive wrapping
- safe horizontal scroll
- sticky headers only where usable
- readable numeric columns
- no clipped buttons
- no inaccessible dropdowns

---

# 14. REAL-TIME / POLLING

Do not create uncontrolled polling.

Only poll backend endpoints that are explicitly designed for:

```text
read-only updates
bounded responses
safe frequency
```

Use server-authoritative timestamps/status.

No client-created fake “live” indicators.

---

# 15. ERROR STATES

Public pages:

```text
UNAVAILABLE
NO_PUBLIC_DATA
NOT_CONFIGURED
```

Payment pages:

```text
PENDING
FAILED
CANCELLED
NOT_FOUND
```

Admin pages:

```text
UNAVAILABLE
DEGRADED
RECONCILIATION_MISMATCH
ACCESS_DENIED
```

Never silently convert an error into an empty green dashboard.

---

# 16. NO FAKE ADMIN KPIs

Never write:

```text
users = 12450
revenue = 892341
bets = 443221
risk = 2.4
```

unless these values are actually returned by the backend.

No seeded demo metrics in production-facing templates.

No random KPI generation.

No fallback sample charts.

---

# 17. TESTS REQUIRED

Create/update:

```text
ResultsHubPageTest
TicketCheckPageTest
SalesPointsPageTest
PrivacyPageTest
ContactPageTest
DownloadPageTest
PublicGradePageTest
PublicVerificationPageTest
PaymentSuccessPageTest
PaymentFailurePageTest
PaymentCancelPageTest
PaymentPendingPageTest
AdminDashboardTest
AdminAnalyticsTest
AdminDrawsTest
AdminRiskTest
AdminBetsTest
AdminWalletsTest
AdminLedgerTest
AdminReconciliationTest
AdminAuditLogTest
AdminKycTest
AdminComplianceTest
```

---

# 18. PAYMENT RETURN TESTS

Must prove:

```text
success route + pending payment = pending
success route + captured payment = confirmed
success=1 forged parameter = does not create confirmation
unknown reference = not found
cross-user reference = not found
failure route = actual internal state
cancel route = actual internal state
pending route = actual internal state
browser callback = zero wallet mutation
browser callback = zero ledger mutation
browser callback = zero payment mutation
```

---

# 19. ADMIN AUTHORIZATION TESTS

Test:

```text
guest denied
authenticated non-admin denied
wrong role denied
correct role allowed
object-level authorization enforced
KYC document access restricted
approve action restricted
reject action restricted
analytics scope restricted
reconciliation scope restricted
```

---

# 20. ADMIN MONEY-SAFETY TESTS

Prove that dashboard pages cannot:

```text
edit balance
delete ledger
rewrite settlement
approve withdrawal without permission
change wallet amount
force payment completion
mark payment successful
bypass KYC
bypass responsible gaming
```

Any legitimate money action must flow through the canonical service layer.

---

# 21. ROUTE COLLISION TEST

Verify:

```text
/results
/check
/sales-points
/privacy
/contact
/download
/app

/payment/success
/payment/failure
/payment/cancel
/payment/pending

/admin
/admin/dashboard
/admin/draws
/admin/risk
/admin/bets
/admin/wallets
/admin/ledger
/admin/reconciliation
/admin/audits
/admin/kyc
/admin/compliance
```

are never captured by:

```text
wildcards
legacy .php route
draw route
generic fallback
```

---

# 22. LEGACY URL SAFETY

Preserve the existing explicit legacy bridge.

Do not create:

```text
catch-all redirect everything
```

Unknown `.php` must remain 404 unless explicitly mapped.

Never let `/admin/*.php` or arbitrary `.php` become an accidental public alias.

---

# 23. SEO RULES

Public indexable:

```text
/results
/check
/sales-points
/privacy
/contact
/download
public account explainers
public result pages
```

Do not index:

```text
payment callbacks
admin
authenticated account
wallet
deposit
withdraw
profile
private history
query-generated admin data
```

---

# 24. REQUIRED TREE

Generate a complete tree for all changed/created files.

Every path MUST include a comment:

```text
app/
├── Http/
│   └── Controllers/
│       ├── ResultsController.php # PHP controller — public unified results hub
│       ├── PaymentCallbackController.php # PHP controller — read-only payment browser return
│       └── Admin/
│           └── LottoFinExecutiveDashboardController.php # PHP controller — authorized operations console
```

Continue for every changed file.

---

# 25. FULL FILE CONTENT

For every changed/created file:

```text
### FILE: exact/path
# TYPE: exact file type
# PURPOSE: exact role
```

Then provide the COMPLETE file.

Never abbreviate.

Never use:

```text
...
existing code...
same as above
implementation unchanged
omitted
```

---

# 26. AUDIT REPORT

Update:

```text
audit.md
```

Include exactly:

```text
PAGE
ROUTE
ROUTE NAME
CONTROLLER
SERVICE
REQUEST
MODEL
DATABASE
API
VIEW
JS
CSS
TRANSLATION
SECURITY
DATA SOURCE
AUTHORIZATION
STATUS
TESTS
RUNTIME STATUS
REMAINING GAP
```

One row per page:

```text
77
78
79
80
81
82
83
84
85
86
87
88
89
90
91
92
93
94
95
96
97
98
99
100
```

No grouped row.

---

# 27. VALIDATION

Run all environment-supported checks.

## PHP

```bash
php -l <changed PHP files>
```

## Laravel

```bash
php artisan route:list
php artisan view:cache
```

## Tests

```bash
php artisan test
```

## JS

```bash
node --check <changed JS>
```

## Build

```bash
npm ci
npm run build
```

Verify:

```text
public/build/manifest.json
```

## Rust

Only where touched:

```bash
cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

---

# 28. STATUS REPORTING

Use only truthful states:

```text
PASS
IMPLEMENTED — RUNTIME NOT VERIFIED
NOT VERIFIED — RUNTIME UNAVAILABLE
BLOCKED
NOT_CONFIGURED — FAIL CLOSED
DATA IMPORT REQUIRED
EXTERNAL VERIFICATION REQUIRED
ACCESS CONTROL VERIFICATION REQUIRED
```

Never claim Laravel execution from static parsing.

Never claim payment-provider execution without a real configured provider/test environment.

Never claim browser accessibility pass without an actual browser run.

---

# 29. FINAL QUALITY GATE

Pages 77–100 are complete only when:

```text
routes work
controllers resolve
services resolve
authorization works
data source is real
public/admin data separation works
payment return is read-only
admin mutations are policy-controlled
KYC access is secure
audit trail exists
EN/TH parity is exact
responsive UI is complete
accessibility is complete
PHP checks pass
JS checks pass
Vite build passes
Laravel runtime is verified
tests pass
```

Otherwise record the exact unmet condition.

---

# 30. FINAL AGENT INSTRUCTION

Do NOT treat Pages 77–100 as a simple UI exercise.

The implementation must trace:

```text
route
→ middleware
→ authorization
→ controller
→ request
→ service
→ repository/model
→ database
→ API/provider
→ projection
→ Blade
→ JS
→ CSS
→ localization
→ audit
→ tests
```

For payment-return pages:

```text
browser return
→ safe lookup
→ authoritative payment state
→ read-only projection
```

For admin:

```text
authenticated operator
→ policy/permission
→ authorized projection
→ audited operation
```

For KYC:

```text
authenticated operator
→ object authorization
→ private document service
→ audit trail
```

For reconciliation:

```text
canonical finance state
→ reconciliation service
→ immutable exception visibility
```

For public pages:

```text
public-safe projection
→ no private metadata
→ no fake data
→ no hidden business logic
```

FINAL RULES:

```text
NO FAKE DATA
NO FAKE KPI
NO FAKE PAYMENT SUCCESS
NO FAKE ADMIN ACCESS
NO FAKE KYC
NO FAKE RECONCILIATION
NO DUPLICATE SERVICE
NO DIRECT WALLET EDIT
NO CLIENT-SIDE FINANCIAL AUTHORITY
NO RAW SECRETS
NO PUBLIC PRIVATE DATA
NO PLACEHOLDER FILE CONTENT
NO SKIPPED PAGE
```