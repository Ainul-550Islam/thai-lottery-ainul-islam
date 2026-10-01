# CODING AGENT PROMPT — PAGES 44–70
## ThaiLotto Enterprise Platform — 2026 Luxury iGaming UI + Real Backend/API Integration
## NO-SKIP / NO-FAKE-DATA / FULL-FILE / BACKEND-FIRST / SECURITY-FIRST

You are continuing an existing Laravel enterprise Thai lottery platform.

The previous implementation already completed/hardened Pages 35–44.

**Page 44 already exists and MUST NOT be recreated as a duplicate feature.**
For Page 44, perform a full hardening/integration review and then continue with Pages 45–70.

The implementation must preserve all existing working logic and architecture.

---

# 0. NON-NEGOTIABLE RULES

## 0.1 DO NOT SKIP ANY PAGE

Implement every page from:

**44, 45, 46, 47, 48, 49, 50, 51, 52, 53, 54, 55, 56, 57, 58, 59, 60, 61, 62, 63, 64, 65, 66, 67, 68, 69, 70**

No page may be marked “done” merely because a similar page already exists.

For an already-existing page, perform:

- architecture review
- route review
- controller/service review
- API/data-flow review
- UI redesign/hardening
- localization review
- accessibility review
- security review
- production-data safety review
- test coverage review
- responsive/mobile review

Then record the result.

---

# 1. CORE PRODUCT / DESIGN DIRECTION

The entire frontend must feel like a:

**2026 premium iGaming / lottery platform**
with:

- luxury dark-gold visual language
- deep black/brown background
- premium gold highlights
- 3D glassmorphism
- realistic depth
- layered lighting
- subtle glow
- premium cards
- sophisticated typography
- cinematic hero composition
- realistic lottery-ball visual treatment
- modern dashboard-grade information hierarchy
- professional financial UI
- strong trust/accessibility presentation
- high-quality mobile experience

Primary design tokens remain based on the existing system:

```text
#0B0904
#141007
#D4AF37
#F5E6B8
#FFF6D6
```

Use the existing design system and shared classes.

Do NOT create a second design system.

Reuse:

- `svgtheme.css`
- `svgglass.css`
- existing global layout
- shared glass panels
- shared buttons
- shared forms
- shared cards
- shared badges
- shared typography
- shared responsive primitives
- existing accessibility patterns

---

# 2. IMPORTANT BACKEND SAFETY POLICY

## 2.1 NEVER fabricate production values

Never hardcode or invent:

- lottery numbers
- draw dates
- jackpot amounts
- prize values
- ticket prices
- wallet balances
- payment status
- payment references
- bank account details
- provider capabilities
- withdrawal limits
- transaction IDs
- user identity information
- KYC state
- grade state
- security state
- notification counts
- financial history

All such values MUST come from backend services/config/database/API.

---

# 3. PURCHASE / FINANCIAL FAIL-CLOSED POLICY

For any purchase feature:

Only enable the purchase action when the complete verified contract exists:

```text
product
→ draw
→ selection
→ validation
→ authoritative price
→ responsible-gaming gate
→ wallet authorization
→ reservation
→ ticket issuance
→ ledger entry
→ idempotency
```

If any required element is missing:

```text
NOT_CONFIGURED
enabled=false
```

and the UI must visibly explain that purchase is unavailable.

Never invent a checkout endpoint.

Never create a fake “Buy Now” button that calls a nonexistent route.

Never create fake reservation IDs.

Never create fake wallet debit behavior.

---

# 4. FINANCIAL WEBFLOW RULE — CRITICAL

Pages 59–64 must not merely display attractive forms.

Inspect and repair the real web adapter.

The implementation must ensure:

### Deposit

```text
validated request
→ canonical deposit service
→ payment/order record
→ capability-checked gateway
→ idempotency
→ payment intent / provider redirect where applicable
→ safe provider metadata
→ verified callback/event
→ transactional wallet credit
→ ledger entry
→ visible status
```

### Withdrawal

```text
validated request
→ KYC / responsible-gaming gate
→ payout destination validation
→ wallet hold/reservation
→ withdrawal record
→ approval state
→ payout dispatch
→ provider reference
→ settlement/reconciliation
→ ledger release/settlement
→ visible status
```

The UI must NEVER say:

```text
Deposit created
```

or

```text
Withdrawal submitted
```

unless the canonical backend operation actually succeeded.

---

# 5. MONEY DISPLAY RULE

Never cast monetary values to floating point for financial presentation when exact decimal semantics are required.

Use the platform's exact-money / decimal-string rules.

Do not introduce:

```php
(float) $amount
```

for wallet, deposit, withdrawal, transaction, fee or settlement display.

Preserve currency explicitly.

Do not mix currencies.

---

# 6. AUTHENTICATION / SECURITY RULES

Preserve all existing:

- CSRF
- session regeneration
- login throttling
- CAPTCHA
- reset-token security
- logout session invalidation
- rate limits
- authorization policies
- security headers
- correlation IDs
- audit logging
- password hashing
- KYC policies
- responsible-gaming controls

Do not bypass existing policies for frontend convenience.

Do not expose internal IDs or sensitive backend metadata.

---

# 7. TRANSLATION RULE

Every user-visible string must use translation files.

Maintain:

```text
lang/en/*
lang/th/*
```

in exact key parity.

Check:

- missing keys
- extra keys
- nested key mismatch
- placeholder mismatch
- pluralization mismatch
- validation-message parity
- empty-state parity

No raw English/Thai UI strings directly embedded into Blade/JS unless they are unavoidable technical identifiers.

---

# 8. FILE DISCOVERY / FULL-FILE RULE

Before editing anything:

1. inspect the existing project tree
2. locate every relevant controller
3. locate every service
4. locate every request/validator
5. locate every model
6. locate every policy
7. locate every migration
8. locate current routes
9. locate current Blade templates
10. locate current JS/CSS
11. locate translations
12. locate existing tests

Do NOT assume a file is absent merely because you did not see it initially.

Reuse existing architecture.

When a file already exists, modify the actual file rather than creating a duplicate implementation.

---

# 9. REQUIRED REPORTING FORMAT

At the end, create/update:

```text
audit.md
```

and/or:

```text
svgaudit.md
```

depending on the project's existing convention.

For every page report:

```text
PAGE
ROUTE
ROUTE NAME
CONTROLLER
SERVICE
REQUEST / VALIDATION
MODEL
DATABASE
API
VIEW
JS
CSS
TRANSLATION
SECURITY
DATA SOURCE
FAIL-CLOSED STATE
TESTS
RUNTIME STATUS
KNOWN LIMITATIONS
```

Never write:

```text
... existing code ...
```

Never shorten file contents.

Never use:

```text
TODO
FIXME
implementation omitted
same as above
etc.
```

---

# 10. MANDATORY TREE FORMAT

Every relevant changed file must be listed in a tree.

Every file line MUST include a `# comment`.

Example:

```text
app/
├── Http/
│   └── Controllers/
│       ├── GloL6Controller.php # PHP controller — dedicated GLO L6 public home orchestration
│       ├── MemberAuthController.php # PHP controller — login/register/password-reset orchestration
│       └── PlayerWebController.php # PHP controller — authenticated player portal and wallet entry points
├── Services/
│   ├── Lottery/
│   │   ├── GloL6HomeService.php # PHP service — GLO L6 home composition
│   │   └── GloL6PurchaseCapabilityService.php # PHP service — fail-closed purchase capability
│   └── Payment/
│       └── PaymentGatewayManager.php # PHP service — gateway capability and driver resolution
resources/
├── views/
│   ├── auth/
│   │   └── login.blade.php # Blade view — secure member login
│   └── player/
│       ├── dashboard.blade.php # Blade view — authenticated player dashboard
│       └── wallet.blade.php # Blade view — exact-money wallet summary
```

Do this for the complete changed file set.

---

# 11. PAGE MAP — PAGES 44–70

---

# PAGE 44 — GLO L6 HOME
## Route

```text
/glo-l6
```

## Existing implementation

Use:

```text
app/Http/Controllers/GloL6Controller.php
app/Services/Lottery/GloL6HomeService.php
app/Services/Lottery/GloL6PurchaseCapabilityService.php
resources/views/glo-l6/index.blade.php
resources/css/glo-l6.css
lang/en/glo_l6.php
lang/th/glo_l6.php
```

## Required hardening

Do NOT rebuild the page from scratch.

Review and improve:

### Backend

- canonical GLO public data services only
- no legacy `GloResultsPageController` hardcoded result data
- no synthetic result values
- source/provenance visibility
- next-draw state
- live/replay state
- prize state
- product configuration state
- purchase capability state
- graceful `UNAVAILABLE`
- graceful `NOT_CONFIGURED`

### Frontend

Build a premium GLO L6 landing experience:

```text
Hero
→ current result
→ next draw
→ prize spotlight
→ live/replay
→ how L6 works
→ product information
→ ticket-check CTA
→ trust/provenance
→ responsible-gaming disclosure
→ footer
```

Do not imply government affiliation unless explicitly backed by the authorized source/configuration.

---

# PAGE 45 — GLO L6 BUY / TICKET SELECTION

## Route

```text
/glo-l6/buy
```

## Route name

Create/retain:

```text
glo-l6.buy
```

## IMPORTANT

This page MUST remain fail-closed unless a verified public GLO L6 purchase contract exists.

Use:

```text
GloL6PurchaseCapabilityService
```

as the capability boundary.

## UI when NOT_CONFIGURED

Create a premium non-interactive purchase surface:

```text
Product
Draw availability
Ticket information
Capability status
Why purchase is unavailable
Check-result CTA
Return-to-GLO-L6 CTA
```

Do NOT show:

- fake ticket selection
- fake price
- fake balance requirement
- fake checkout
- fake reservation
- fake ticket issuance

## UI when configured later

The page must already support a capability-driven state:

```text
NOT_CONFIGURED
CONFIGURED
AVAILABLE
CLOSED
SUSPENDED
```

Do not rebuild the page architecture again when the product contract becomes available.

---

# PAGE 46 — GLO L6 LATEST RESULT

## Route

```text
/glo-l6/latest
```

Use canonical GLO result services.

The page must include:

- latest result
- draw date
- draw reference
- result categories
- provenance
- source state
- integrity wording
- timestamp where authorized
- no fake values
- unavailable state
- result detail CTA

Preserve leading zeros exactly.

---

# PAGE 47 — GLO L6 HISTORICAL RESULTS

## Route

```text
/glo-l6/history
```

Use:

- canonical GLO history service
- bounded pagination
- real available-year data
- real result rows
- source state
- empty state

No fabricated archive rows.

Never create fake historical records merely to make the table look full.

---

# PAGE 48 — GLO L6 YEAR ARCHIVE

## Route

```text
/glo-l6/year/{year}
```

Optionally support a compatibility archive alias only when it does not conflict with canonical routing.

Requirements:

- strict year validation
- bounded year range
- canonical URL
- localized year label
- pagination
- no arbitrary database scan
- no invalid future-year abuse
- noindex where appropriate for empty/invalid pages
- real archive data only

---

# PAGE 49 — GLO L6 DRAW DETAIL

## Route

```text
/glo-l6/draw/{draw}
```

Display:

- draw reference
- date
- time if authoritative
- result values
- prize information where available
- source/provenance
- status
- unavailable state
- related result/history links

Do not disclose internal provider metadata.

---

# PAGE 50 — GLO L6 RESULT DETAIL

## Route

```text
/glo-l6/result/{draw}
```

This must be an addressable canonical detail page.

Do NOT duplicate result retrieval logic from Page 49.

Reuse the same canonical result projection/service.

Where the two routes are aliases, define a clear canonical URL and metadata strategy.

---

# PAGE 51 — LOGIN

## Route

```text
/login
```

Route name:

```text
login
```

Existing controller:

```text
MemberAuthController
```

Requirements:

- premium auth visual
- glass login card
- secure password field
- CAPTCHA where configured
- rate-limit feedback
- failed-login state
- validation state
- session-safe redirect
- localized errors
- forgot-password CTA
- registration CTA
- mobile-first layout
- no credential leakage
- no account enumeration

Do not weaken existing authentication behavior.

---

# PAGE 52 — REGISTER

## Route

```text
/register
```

Requirements:

- premium registration UI
- all current validated fields
- centralized password policy
- mobile validation
- referral workflow
- CAPTCHA
- Terms acceptance
- Privacy acknowledgement where required
- validation parity with backend
- translated errors

IMPORTANT:

The current live/reference specification has a concrete mobile-length constraint. Do not guess the final rule. Use the application's signed-off canonical validator/configuration.

If the existing web validator is inconsistent with the canonical rule, correct the validator and test it.

---

# PAGE 53 — FORGOT PASSWORD

## Route

```text
/forgot-password
```

Requirements:

- secure recovery UI
- account identifier/email
- CAPTCHA
- throttle
- non-enumerating response
- clear next-step message
- no token disclosure
- localized instructions
- accessibility

No raw mail token in HTML.

---

# PAGE 54 — RESET PASSWORD

## Route

```text
/reset-password/{token}
```

Requirements:

- token-gated form
- password confirmation
- centralized strong password policy
- token expiry feedback
- one-time token behavior
- session invalidation where appropriate
- audit logging
- safe failure message
- no token leakage
- no referrer leakage
- accessible password controls

IMPORTANT:

Centralize password policy so registration/reset/profile do not drift.

---

# PAGE 55 — MEMBER DASHBOARD

## Route

```text
/dashboard
```

Route name:

```text
player.dashboard
```

## CRITICAL

Remove dangerous production-looking fallback result numbers.

The dashboard must never silently fall back to values that look like real draw IDs or real winning numbers.

Use:

```text
AVAILABLE
UNAVAILABLE
NO_DATA
```

instead.

## Dashboard sections

```text
Welcome
Wallet snapshot
Next draw
Recent results
Recent bets
Recent transactions
Responsible gaming summary
Account verification state
Account grade
Security alerts
Quick actions
```

All data must be server-authoritative.

No fake balance.

No fake recent ticket.

No fake result.

---

# PAGE 56 — PLAYER DRAWS

## Route

```text
/draws
```

Display:

- available draws
- product
- date
- status
- purchase capability
- result state
- detail CTA

Use actual draw service.

Do not invent available draw inventory.

---

# PAGE 57 — PLAYER DRAW DETAIL

## Route

```text
/draws/{id}
```

Display:

- draw identity
- product
- schedule
- state
- purchase eligibility
- closure state
- result status
- related bets
- safe actions

Authorization must ensure the user cannot use the ID to access another player's private data.

---

# PAGE 58 — PLAYER BET SLIP

## Route

```text
/bet
```

Requirements:

- premium ticket-selection UX
- product/draw context
- validated selections
- visible pricing from authoritative calculation
- discount/grade calculation from backend
- fee preview from canonical rules
- responsible-gaming limit check
- current wallet capability
- idempotency key
- CSRF
- throttle
- purchase confirmation

## Purchase transport

Use the existing canonical purchase endpoint:

```text
/bet/purchase
```

Do not introduce an unrelated checkout architecture.

Atomicity must cover:

```text
validate
→ price
→ responsible gaming
→ wallet authorization
→ ticket persistence
→ ledger
→ idempotency
```

No client-controlled payout amount.

No client-controlled discount.

No client-controlled wallet balance.

---

# PAGE 59 — BETS / HISTORY

## Route

```text
/bets
```

Display:

- ticket history
- draw
- selection summary
- ticket status
- purchase timestamp
- total
- settlement state
- prize state
- claim state where permitted

Use exact currency.

Never calculate payout on the browser.

Do not reveal internal ledger IDs.

Provide:

- pagination
- filtering
- empty state
- no-result state
- safe detail links

---

# PAGE 60 — WALLET

## Route

```text
/wallet
```

This is the primary financial summary page.

## UI

```text
Available balance
Held balance
Withdrawable balance
Pending deposits
Pending withdrawals
Recent transactions
Deposit CTA
Withdraw CTA
```

All monetary numbers must use exact decimal values.

Use grouped currencies only if the backend supports multiple currencies.

Never combine currencies into one meaningless total.

## Security

- authenticated only
- correct ownership
- no IDOR
- no internal wallet IDs
- no hidden operator metadata

---

# PAGE 61 — DEPOSIT

## Route

```text
/deposit
```

## GET

Render only payment methods actually capable under canonical configuration.

Do NOT hardcode unsupported methods.

The UI must derive capability from:

```text
PaymentMethod
PaymentGatewayManager
payment config
currency support
gateway enabled state
```

## POST

The web POST must call the real canonical deposit/payment service.

It must NOT simply redirect with a success flash.

Required flow:

```text
validate
→ amount rule
→ gateway capability
→ supported currency
→ idempotency
→ create deposit/order/payment intent
→ persist
→ provider redirect or QR/payment instruction
→ safe response
```

## Amount rules

Do not duplicate:

- minimum
- maximum
- currency
- fee
- gateway support

inside the Blade template.

Use canonical config/service output.

---

# PAGE 62 — DEPOSIT STATUS / PAYMENT INTENT

Create a clear user-facing deposit-status surface.

Possible route:

```text
/deposit/{deposit}
```

or the application's canonical equivalent.

Requirements:

- pending
- awaiting payment
- processing
- completed
- failed
- cancelled
- expired

The page itself must be presentation/read-only.

Status mutation must come only from verified backend payment events.

Show:

- safe public reference
- amount
- currency
- method
- current status
- created time
- safe next step

Do NOT show provider secrets or signatures.

---

# PAGE 63 — WITHDRAW

## Route

```text
/withdraw
```

The web POST must call the real withdrawal service.

Required:

```text
validate
→ ownership
→ KYC gate
→ responsible-gaming restrictions
→ payout destination validation
→ available balance
→ wallet hold
→ withdrawal record
→ idempotency
→ approval
→ payout dispatch where appropriate
```

Never merely display a success flash.

## Method capability

The UI must only show payment/payout rails actually supported by canonical config.

Do not advertise:

```text
PromptPay
TrueMoney
Bank
Crypto
bKash
Nagad
```

unless each method is actually represented/capable according to the application's source of truth.

## Financial display

Use exact decimal strings.

---

# PAGE 64 — WITHDRAWAL STATUS / HISTORY

Create an addressable withdrawal-state view.

Support:

```text
PENDING
HELD
REVIEW
APPROVED
PROCESSING
PAID
FAILED
REJECTED
CANCELLED
```

Only expose public-safe status vocabulary.

Do not expose:

- operator comments
- provider secret payload
- internal reconciliation IDs
- encrypted destination data
- internal ledger IDs

Include:

- amount
- currency
- masked destination
- safe reference
- status
- created
- updated
- rejection/failure reason when public-safe

---

# PAGE 65 — PROFILE

## Route

```text
/profile
```

Requirements:

- personal details
- account information
- locale
- contact information
- verification state
- grade
- security links
- responsible gaming links

All updates must be:

```text
PUT /profile
```

or the project's canonical route.

Never trust:

```text
user_id
player_id
account_id
```

from hidden form fields for ownership.

Always resolve ownership from authenticated session.

---

# PAGE 66 — PASSWORD + SECURITY SETTINGS

Use existing security route architecture.

Likely routes include:

```text
/profile/password
/player/security
/player/settings
/settings
```

Do not create redundant security architecture.

Build a unified premium security experience covering:

```text
Change password
Session security
Recent security activity
CAPTCHA/security status
Account protection
Logout/session action
```

Password changes must use the centralized strong-password policy.

Do not retain weaker profile/API validation merely for backward compatibility.

---

# PAGE 67 — RESPONSIBLE GAMING

## Route / route family

Use the existing canonical profile-limits architecture:

```text
/profile/limits
```

or:

```text
/player/profile/limits
```

Do not create a second limits engine.

Display:

- daily deposit limit
- single bet limit
- daily wagering limit
- current active state
- effective date
- cooldown/change rules
- localized validation
- saved/active distinction
- audit-safe feedback

All values must come from:

```text
ResponsibleGamingService
```

The page must not locally calculate or override the rules.

---

# PAGE 68 — ACCOUNT VERIFICATION

## Authenticated route

```text
/account/verification
```

Use:

```text
MemberAccountVerificationController
```

and existing verification services/models/policies.

## UI

```text
Verification progress
Identity information
Document upload
Document list
Status
Reviewer decision
Secure document download
Submission history where appropriate
```

Security:

- authorization policy
- ownership
- upload validation
- MIME validation
- size validation
- encrypted/private storage
- no document path leakage
- no public file URL
- audit logging
- rate limits

Never display another user's KYC state.

---

# PAGE 69 — ACCOUNT GRADE

## Route

```text
/account/grade
```

Requirements:

- current grade
- grade benefits
- 30-day spend summary if canonical
- discount entitlement
- progress toward next grade
- effective state
- refresh state
- localized thresholds

Do not duplicate grade calculations in Blade.

Use the canonical grade service/configuration.

The known grade ladder must only be displayed from canonical configuration.

Do not copy a static legacy table into the view.

---

# PAGE 70 — ACCOUNT GRADE HISTORY

## Route

```text
/account/grade/history
```

Display:

- historical grade
- effective period
- reason/state where public-safe
- discount entitlement
- current vs previous state
- pagination
- empty state

No internal audit identifiers.

No operator notes.

Do not let the client select arbitrary user/account IDs.

Ownership is always the authenticated session user.

---

# 12. SHARED NAVIGATION FOR PAGES 44–70

Update authenticated navigation so it becomes a professional application shell:

```text
Home
Lotteries
Results
My Dashboard
Draws
Bet
Bets
Wallet
Deposit
Withdraw
Profile
Verification
Grade
Responsible Gaming
Security
Support
Logout
```

The exact final menu should respect existing route names and authorization.

Do not create dead links.

Do not use:

```html
href="#"
```

Do not use fake URLs.

---

# 13. RESPONSIVE DESIGN

Every page must work cleanly at:

```text
Desktop
Laptop
Tablet
Mobile
Small mobile
```

Required:

- no horizontal overflow
- touch-friendly controls
- stacked cards where appropriate
- sticky actions only when safe
- readable financial values
- responsive tables
- horizontal table scrolling only where necessary
- large tap targets
- correct focus order

---

# 14. ACCESSIBILITY

Every page requires:

- skip link
- semantic headings
- keyboard navigation
- focus-visible states
- form labels
- validation association
- aria-describedby where relevant
- accessible error messages
- accessible status messaging
- adequate contrast
- reduced-motion support

Use:

```css
prefers-reduced-motion
```

where animation exists.

---

# 15. 3D / GLASS VISUAL IMPLEMENTATION

Use 3D effects only where they improve usability.

Recommended:

```text
glass panel
soft depth shadow
inner highlight
gold edge glow
subtle perspective
layered background lighting
soft particles
realistic result balls
elevated action cards
```

Do not use giant uncontrolled blur effects that reduce text readability.

Do not use animation that blocks interaction.

Do not cause GPU-heavy continuous animation on every component.

---

# 16. SECURITY / DATA-EXPOSURE CHECK

For Pages 44–70 inspect all rendered variables.

Reject any view data containing:

```text
password
password hash
reset secret
captcha secret
payment secret
provider private key
wallet internal ID
ledger internal ID
operator note
private KYC metadata
internal storage path
unsigned webhook payload
authorization token
```

Mask:

- payout destination
- phone
- email where appropriate
- sensitive references

Use existing backend serializers/projections where available.

---

# 17. ROUTE COLLISION SAFETY

Pay special attention to:

```text
/glo-l6
/glo-l6/buy
/glo-l6/latest
/glo-l6/history
/glo-l6/year/{year}
/glo-l6/draw/{draw}
/glo-l6/result/{draw}
```

and existing wildcard routes.

Static route declarations MUST come before dynamic wildcard routes when Laravel matching requires it.

Do not accidentally capture:

```text
latest
history
buy
result
year
draw
```

as a draw reference.

---

# 18. SEO

Every public GLO L6 result page must have:

```text
title
description
canonical
robots
og:title
og:description
og:url
```

Authenticated pages generally should not be indexable.

Search/query pages should generally use:

```text
noindex,follow
```

where appropriate.

Never index private dashboard/wallet/account pages.

---

# 19. LEGACY COMPATIBILITY

Do not delete the existing `LegacyRedirectController`.

Preserve known legacy mappings.

Do not add a catch-all:

```text
/{anything}.php
```

that blindly redirects unknown URLs.

Known `.php` routes must remain explicitly mapped.

Unknown `.php` paths remain 404 unless explicitly approved.

---

# 20. TESTING REQUIREMENTS

Create/update tests for every page family.

At minimum:

## GLO L6

```text
GloL6PageTest
GloL6LatestTest
GloL6HistoryTest
GloL6YearTest
GloL6DrawTest
GloL6ResultTest
GloL6PurchaseCapabilityTest
```

## Auth

```text
LoginPageTest
RegisterPageTest
ForgotPasswordPageTest
ResetPasswordPageTest
```

## Player

```text
PlayerDashboardTest
PlayerDrawsTest
PlayerDrawDetailTest
PlayerBetPageTest
PlayerBetsHistoryTest
PlayerWalletTest
PlayerProfileTest
```

## Finance

```text
WebDepositIntegrationTest
DepositStatusPageTest
WebWithdrawalIntegrationTest
WithdrawalStatusPageTest
PaymentCapabilityParityTest
```

## Security / account

```text
PasswordSecurityTest
ResponsibleGamingPageTest
AccountVerificationPageTest
AccountGradePageTest
AccountGradeHistoryTest
```

---

# 21. MANDATORY FINANCIAL TEST CASES

Test:

### Deposit

- valid amount
- invalid amount
- unsupported gateway
- disabled gateway
- unsupported currency
- duplicate idempotency key
- provider redirect
- provider reference persistence
- webhook completion
- replayed webhook
- failed payment
- cancelled payment
- no wallet credit before verified settlement
- exactly-once wallet credit

### Withdrawal

- valid withdrawal
- insufficient balance
- KYC block
- responsible-gaming block
- unsupported payout rail
- invalid payout destination
- duplicate idempotency key
- concurrent withdrawals
- hold created
- payout failure
- payout cancellation
- hold release
- successful settlement
- ledger reconciliation

---

# 22. CONCURRENCY / MONEY SAFETY

Test concurrent:

```text
same-user deposits
same-user withdrawals
same-user bet purchases
withdrawal + bet
deposit + bet
duplicate POST
duplicate webhook
```

Ensure:

```text
no double spend
no double credit
no double debit
no duplicate ticket
no duplicate ledger posting
no negative wallet through race
```

Use existing pessimistic locking / transaction / idempotency architecture.

Do not replace it with client-side protection.

---

# 23. JAPAN / THAILAND / LOCALE-SPECIFIC DATE SAFETY

For Thai lottery pages:

- preserve Thai Buddhist year where configured
- preserve Gregorian canonical route behavior where established
- preserve Asia/Bangkok timezone where authoritative
- never let server timezone silently shift draw dates
- never mutate date strings in JS when server-provided localized values exist

---

# 24. NO DUPLICATE SERVICE ARCHITECTURE

Before creating any class, search for existing:

```text
ResultService
HistoryService
DrawService
WalletService
DepositService
WithdrawalService
PaymentGatewayManager
GradeService
ResponsibleGamingService
VerificationService
```

If one already provides the behavior, extend/reuse it.

Do not create:

```text
AnotherWalletService
AnotherDepositService
AnotherResultService
AnotherGradeService
AnotherAuthService
```

merely for one page.

---

# 25. JAVASCRIPT RULE

JavaScript may:

- enhance interaction
- control UI state
- submit forms
- display backend-provided data
- poll approved read-only endpoints

JavaScript may NOT become the financial source of truth.

Never calculate:

- wallet balance
- ticket price
- discount
- tax
- payout
- withdrawal eligibility
- responsible-gaming eligibility

as authoritative values in browser code.

---

# 26. ERROR / EMPTY STATES

Every page must support:

```text
loading
available
empty
unavailable
not configured
validation error
authorization error
rate limited
server error
```

Use translated human-readable messages.

Never render zeroes or fake placeholder values to make the design look complete.

---

# 27. VISUAL QUALITY ACCEPTANCE

A page is NOT complete merely because the data renders.

Each page must be visually checked for:

```text
spacing
typography
hierarchy
glass depth
gold contrast
card consistency
button hierarchy
responsive behavior
mobile navigation
table usability
empty state quality
form quality
error quality
loading state quality
accessibility
```

---

# 28. FULL FILE CONTENT REQUIREMENT

For every changed/created file in the final implementation report:

```text
### FILE: path/to/file
# TYPE: ...
# PURPOSE: ...
```

Then include the FULL file content.

Never write:

```text
// existing implementation omitted
```

or:

```text
...
```

or:

```text
same content as previous file
```

Every file must be complete.

---

# 29. VALIDATION COMMANDS

Run everything that the environment supports.

## PHP

```bash
php -l <all PHP files>
```

## PHPUnit

```bash
php artisan test
```

or the repository's official full-suite command.

## Laravel

```bash
php artisan route:list
php artisan view:cache
```

where environment permits.

## JavaScript

```bash
node --check <all changed JS files>
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

exists.

## Rust

If Rust code is touched:

```bash
cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

Do not claim runtime success when a required executable or dependency is unavailable.

---

# 30. RUNTIME STATUS REPORTING

If PHP/vendor/Vite/browser/runtime is unavailable, write exactly:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

Do not convert syntax-only checks into Laravel runtime claims.

Distinguish:

```text
PASS — actually executed
NOT VERIFIED — environment unavailable
BLOCKED — implementation dependency missing
DATA IMPORT REQUIRED
EXTERNAL VERIFICATION REQUIRED
NOT_CONFIGURED — FAIL CLOSED
```

---

# 31. REQUIRED FINAL IMPLEMENTATION SUMMARY

At the end of `audit.md`/`svgaudit.md`, provide:

```text
Pages 44–70
Implemented:
Partially implemented:
Existing + hardened:
Blocked:
NOT_CONFIGURED:
Runtime unavailable:
Data import required:
External verification required:
Security findings:
Financial integration findings:
Frontend findings:
Remaining gaps:
```

Then provide:

```text
PAGE-BY-PAGE MATRIX
```

with exactly one row per page.

Example columns:

```text
Page
Name
Route
Controller
Service
View
API
Data Source
Security
Purchase State
Tests
Validation
Status
Remaining Gap
```

Do not merge pages into one row.

---

# 32. FINAL GATE

Do NOT declare Pages 44–70 “production ready” unless all of the following are true:

```text
real backend data connected
real routes connected
financial writes connected
idempotency verified
wallet/ledger verified
payment callback verified
responsible gaming verified
KYC verified
authorization verified
EN/TH parity verified
responsive UI verified
accessibility verified
PHP syntax verified
JS syntax verified
Laravel runtime verified
full tests verified
production build verified
```

Otherwise use the exact state that applies:

```text
IMPLEMENTED — RUNTIME NOT VERIFIED
IMPLEMENTED — DATA IMPORT REQUIRED
IMPLEMENTED — EXTERNAL VERIFICATION REQUIRED
NOT_CONFIGURED — FAIL CLOSED
BLOCKED
```

---

# 33. FINAL INSTRUCTION TO THE CODING AGENT

Do not stop after creating Blade files.

Trace every page through:

```text
route
→ controller
→ request/validation
→ service
→ model/repository
→ database
→ API/provider
→ ledger/wallet
→ audit/security
→ Blade
→ JS
→ CSS
→ translation
→ tests
```

For Pages 60–64 in particular, prove that the web layer actually reaches the finance engine rather than returning a presentation-only success message.

For Pages 44–50, prove that GLO L6 uses canonical public result architecture and does not reintroduce hardcoded result data.

For Pages 51–58, preserve the existing authentication and purchase security architecture.

For Pages 65–70, use the existing account/KYC/grade/responsible-gaming architecture rather than inventing parallel systems.

The result must be a coherent, production-oriented, 2026 luxury dark-gold iGaming frontend whose visible states are backed by the actual Laravel application state.

NO FAKE DATA.
NO FAKE API.
NO FAKE PURCHASE.
NO FAKE WALLET.
NO FAKE PAYMENT.
NO DUPLICATE ARCHITECTURE.
NO PLACEHOLDER FILE CONTENT.
NO SKIPPED PAGE.
NO SILENT FAILURE.
NO UNSAFE FALLBACK.