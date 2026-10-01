# PROMPT 05–14 — THAILOTTO NEXT 10 PUBLIC PAGES
# PAGES 05 → 14
# WORLD-CLASS 3D GLASS + REAL BACKEND + REAL API WHEN JUSTIFIED + ZERO PLACEHOLDER

---

# MASTER ROLE

You are the senior:

- Product Designer
- UX/UI Engineer
- Laravel/PHP Architect
- Backend Engineer
- API Engineer
- Frontend Engineer
- Security Engineer
- Accessibility Engineer
- SEO Engineer
- Test Engineer

Implement the NEXT 10 PUBLIC PAGES in this exact order:

05. Privacy Policy
06. Our Fees
07. Account Verification
08. Account Grade
09. Prize Verification
10. Lotto Discount
11. How to Play
12. FAQ
13. Contact Us
14. Download App

Do not silently skip any page.

Do not merge pages simply because they look similar.

Each page is an independent design/implementation target.

---

# NON-NEGOTIABLE GLOBAL RULES

## RULE 01 — ACTUAL SOURCE FIRST

Before changing anything:

inspect the latest repository/source tree.

Locate:

- current route
- current controller
- current service
- current model
- current config
- current Blade
- current CSS
- current JS
- current localization
- current tests
- current legacy redirect
- current API
- current shared public layout

Reuse canonical existing architecture.

Do not create duplicate systems.

---

# RULE 02 — NO SKIP

Do not skip:

- controllers
- services
- views
- components
- CSS
- JS
- localization
- routes
- tests
- config
- migrations
- APIs
- shared files

If a requested backend capability is genuinely missing:

CREATE IT.

If it already exists:

REUSE IT.

---

# RULE 03 — COMPLETE FILE OUTPUT

For every created or modified file:

OUTPUT THE COMPLETE FILE CONTENT.

Never use:

```text
# ... existing code ...
// ... existing code ...
/* existing code */
...
TODO
FIXME
```

Never truncate a file.

Never replace a real section with a placeholder.

Preserve all existing valid logic.

---

# RULE 04 — NO FAKE DATA

Never invent:

- fees
- discounts
- account-grade thresholds
- contact information
- operator identity
- license information
- government affiliation
- company registration
- phone numbers
- addresses
- app download URLs
- statistics
- user counts
- certificates
- payment methods
- legal claims

Use:

- approved config
- existing canonical content source
- actual database records
- verified public content
- operator-approved values

If unavailable:

`NOT CONFIGURED`
or
`APPROVAL REQUIRED`

Do not fabricate.

---

# RULE 05 — DESIGN + FUNCTION

A page is not complete because it looks beautiful.

Each page must satisfy:

DESIGN
+
ROUTE
+
BACKEND
+
DATA SOURCE
+
VALIDATION
+
SECURITY
+
ACCESSIBILITY
+
SEO
+
TESTING

---

# RULE 06 — API DECISION

Do NOT create an API just because the prompt says “real API”.

For every page determine:

### API REQUIRED

when content/functionality is dynamic, interactive, authenticated, or reused by frontend/app.

### API NOT REQUIRED

when server-side rendering is the correct architecture for static/legal/editorial content.

Document that decision.

---

# RULE 07 — 3D / GLASS VISUAL SYSTEM

All 10 pages must visually continue the existing Home/About/Vision design system:

- dark luxury background
- premium gold accents
- glass surfaces
- frosted blur
- controlled highlights
- depth
- subtle shadows
- realistic 3D objects
- cinematic gradients
- premium typography
- smooth transitions
- responsive layout
- reduced motion support

Do not turn every page into the same template.

Each page needs its own visual identity.

---

# PAGE 05 — PRIVACY POLICY

## PURPOSE

Create:

`05. PRIVACY POLICY`

This is a legal/data-protection page.

Visual priority:

READABILITY > DECORATION

---

## LIVE / SOURCE REFERENCE

Inspect the existing privacy page and live site's privacy surface.

Do not copy unsupported legal identity.

Do not invent:

- controller/data-owner identity
- physical address
- regulator
- jurisdiction
- retention period
- legal basis
- cookie policy
- international transfer statement

unless approved by the source.

---

## BACKEND ARCHITECTURE

```text
app/
├── Http/
│   └── Controllers/
│       └── PublicPagesController.php
│       # TYPE: controller
│       # PURPOSE: Canonical public-page controller; preserve all existing public pages and render Privacy through the established public-page architecture.
│
└── Services/
    └── PublicPages/
        └── PublicPageDataService.php
        # TYPE: service
        # PURPOSE: Canonical public content source for Privacy Policy content and metadata.
```

Do NOT create a duplicate `PrivacyService` if `PublicPageDataService` already supports privacy content.

---

## VIEW

```text
resources/views/
├── privacy/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Privacy Policy page with premium legal-document presentation.
│
└── components/
    └── privacy/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Privacy hero with document/privacy visual and legal metadata.
        │
        ├── contents.blade.php
        # TYPE: blade_component
        # PURPOSE: Dynamic section navigation generated from actual privacy sections.
        │
        ├── section.blade.php
        # TYPE: blade_component
        # PURPOSE: Safe rendering of individual privacy sections.
        │
        └── data-rights.blade.php
            # TYPE: blade_component
            # PURPOSE: Visual summary of actual user-data rights only when supported by the approved policy source.
```

---

## CSS

```text
resources/css/pages/privacy.css
# TYPE: stylesheet
# PURPOSE: Premium privacy/legal visual design, readable typography, glass sections, responsive behavior and print styling.
```

---

## JS

```text
resources/js/pages/privacy.js
# TYPE: javascript
# PURPOSE: Table-of-contents navigation, section highlighting, optional local search, keyboard navigation and reduced-motion behavior.
```

No polling.

---

## TESTS

```text
tests/Feature/Privacy/PrivacyPageTest.php
# TYPE: feature_test
# PURPOSE: Privacy route/render/public access.

tests/Feature/Privacy/PrivacyContentIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Verify no fabricated legal/data-protection claims.

tests/Feature/Privacy/PrivacyLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.

tests/Feature/Privacy/PrivacyLegacyRouteTest.php
# TYPE: feature_test
# PURPOSE: Legacy privacy URL compatibility when one exists.

tests/Feature/Privacy/PrivacyLinkIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Internal/external link safety and validity.
```

---

## PRIVACY UI

Implement:

- Hero
- last updated
- effective date when sourced
- version
- table of contents
- policy sections
- data categories
- collection/use
- security
- retention
- sharing
- user rights
- cookies only if sourced
- contact/privacy contact only if sourced
- CTA

Every section must come from approved content.

---

# PAGE 06 — OUR FEES

## PURPOSE

Create:

`06. OUR FEES`

This is an economically sensitive page.

The current live Fees page publishes account renewal, verification, referral, affiliation, balance-transfer, withdrawal, cash-in and agent fees.

DO NOT blindly copy the live page's currency/provider semantics.

Use canonical application configuration/source of truth.

---

## BACKEND

```text
app/
├── Http/
│   └── Controllers/
│       └── PublicPagesController.php
│       # TYPE: controller
│       # PURPOSE: Render public Fees page using canonical configured fee data.
│
└── Services/
    └── PublicPages/
        └── PublicPageDataService.php
        # TYPE: service
        # PURPOSE: Provide canonical public fee rows and metadata.
```

Reuse existing:

```text
config/fees.php
# TYPE: configuration
# PURPOSE: Canonical fee definitions; do not create a competing fee source.
```

If an actual fee resolver already exists, use it.

---

## VIEW

```text
resources/views/
├── fees/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Main fee page with category navigation and source-driven fee tables.
│
└── components/
    └── fees/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Premium financial/document hero.
        │
        ├── fee-category.blade.php
        # TYPE: blade_component
        # PURPOSE: Render one fee category from canonical backend data.
        │
        ├── fee-table.blade.php
        # TYPE: blade_component
        # PURPOSE: Responsive fee table with exact decimal/currency presentation.
        │
        ├── important-notes.blade.php
        # TYPE: blade_component
        # PURPOSE: Display fee qualifications and caveats from source.
        │
        └── cta.blade.php
            # TYPE: blade_component
            # PURPOSE: Real links to account/payment/support pages.
```

---

## CSS

```text
resources/css/pages/fees.css
# TYPE: stylesheet
# PURPOSE: Premium financial dashboard/table aesthetic with glass panels, responsive tables and mobile cards.
```

---

## JS

```text
resources/js/pages/fees.js
# TYPE: javascript
# PURPOSE: Category switching, table filtering/search when useful, mobile table enhancement and accessible interactions.
```

No dynamic fee calculation in JS.

---

## SECURITY

Never expose:

- private provider credentials
- merchant IDs
- webhook secrets
- internal pricing formulas
- operator-only settlement rules

---

## FEES DATA RULE

Each fee must have:

- key
- label
- amount/rate
- currency when applicable
- applicability
- effective date when available
- public/private visibility

Use strings/decimal-safe representation.

No floating-point financial arithmetic.

---

## TESTS

```text
tests/Feature/Fees/FeesPageTest.php
# TYPE: feature_test
# PURPOSE: Fees route and rendering.

tests/Feature/Fees/FeesConfigIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Verify page values originate from canonical fee source.

tests/Feature/Fees/FeesLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity and fee terminology.

tests/Feature/Fees/FeesSecurityTest.php
# TYPE: feature_test
# PURPOSE: Verify private payment configuration is never rendered.
```

---

# PAGE 07 — ACCOUNT VERIFICATION

## PURPOSE

Create:

`07. ACCOUNT VERIFICATION`

The live public verification page currently asks for account details and verification request fields including country code, mobile number, document type and document-side information.

The new implementation must separate:

PUBLIC GUIDE

from

AUTHENTICATED PERSONAL VERIFICATION WORKFLOW.

---

## PUBLIC PAGE

```text
resources/views/
├── account/
│   └── verification-guide.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Public explanation of account verification requirements and process; must not submit private verification data.
```

---

## AUTHENTICATED WORKFLOW

Reuse actual existing:

```text
/account/verification
/account/verification/document/{document}
```

Do not duplicate KYC logic.

---

## BACKEND

```text
app/Http/Controllers/
└── Account/
    └── VerificationController.php
    # TYPE: controller
    # PURPOSE: Existing/canonical authenticated verification workflow; reuse or extend rather than creating a duplicate verification controller.
```

If actual source uses another canonical controller:

USE THAT.

```text
app/Services/
└── KYC/
    # TYPE: service layer
    # PURPOSE: Canonical identity/document verification business logic.
```

---

## PUBLIC GUIDE COMPONENTS

```text
resources/views/components/account-verification/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Premium verification hero.

├── requirements.blade.php
# TYPE: blade_component
# PURPOSE: Explain document/account requirements.

├── steps.blade.php
# TYPE: blade_component
# PURPOSE: Visual verification journey.

├── document-guide.blade.php
# TYPE: blade_component
# PURPOSE: Explain accepted document flow only from configured rules.

└── security.blade.php
# TYPE: blade_component
# PURPOSE: Explain actual document-security practices implemented by the platform.
```

---

## SECURITY

Must verify:

- authentication on private submission
- authorization
- document ownership
- private storage
- MIME validation
- file-size limits
- non-public document URLs
- signed/authorized viewing
- audit log
- reviewer identity

Never expose uploaded KYC documents to guests.

---

## PAGE UX

Visual flow:

```text
Why verification
↓
What you need
↓
How verification works
↓
Secure submission
↓
Check verification status
```

Real CTA:

`Start Verification`

must lead to actual authenticated route.

---

## TESTS

```text
tests/Feature/KYC/AccountVerificationGuidePageTest.php
# TYPE: feature_test
# PURPOSE: Public guide rendering.

tests/Feature/KYC/AccountVerificationSecurityTest.php
# TYPE: feature_test
# PURPOSE: Authorization, private document access, ownership.

tests/Feature/KYC/AccountVerificationLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.

tests/Feature/KYC/AccountVerificationPageTest.php
# TYPE: feature_test
# PURPOSE: Authenticated verification workflow integration.
```

---

# PAGE 08 — ACCOUNT GRADE

## PURPOSE

Create:

`08. ACCOUNT GRADE`

Live account-grade information is a published public concept; the audited source architecture uses a public explainer plus authenticated grade/history/refresh functionality.

The source audit also records the live grade ladder:

- Gold Plus
- Platinum
- Platinum Plus
- Diamond
- Diamond Plus

with progressively configured spend/discount rules.

Use canonical:

`config/account_grades.php`

or existing grade service.

---

## BACKEND

```text
app/Http/Controllers/
└── PublicPagesController.php
# TYPE: controller
# PURPOSE: Public Account Grade explainer using canonical grade configuration.

app/Services/
└── Account/
    └── AccountGradeService.php
    # TYPE: service
    # PURPOSE: Canonical account-grade rules, eligibility and grade presentation. Reuse existing implementation if present.
```

Do not duplicate grade calculations.

---

## PUBLIC VIEW

```text
resources/views/
├── account/
│   └── grades.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Public grade-system explainer and transparent comparison table.
│
└── components/
    └── account-grades/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Luxury membership-grade hero.
        │
        ├── grade-orbit.blade.php
        # TYPE: blade_component
        # PURPOSE: 3D visual grade ladder.
        │
        ├── grade-card.blade.php
        # TYPE: blade_component
        # PURPOSE: One source-driven grade card.
        │
        ├── comparison.blade.php
        # TYPE: blade_component
        # PURPOSE: Accessible comparison table.
        │
        └── how-it-works.blade.php
            # TYPE: blade_component
            # PURPOSE: Explain actual grade progression logic.
```

---

## DESIGN

Create:

5-level premium 3D membership journey.

Visual hierarchy:

```text
Gold Plus
↓
Platinum
↓
Platinum Plus
↓
Diamond
↓
Diamond Plus
```

Do NOT imply guaranteed monetary benefit beyond configured rules.

Do not create fake status icons.

---

## AUTHENTICATED SIDE

Verify actual:

`/account/grade`

`/account/grade/history`

`/account/grade/refresh`

Use existing backend.

The public page must not leak a player's personal grade/history.

---

## TESTS

```text
tests/Feature/AccountGrade/AccountGradePublicPageTest.php
# TYPE: feature_test
# PURPOSE: Public explainer.

tests/Feature/AccountGrade/AccountGradeRulesTest.php
# TYPE: feature_test
# PURPOSE: Config/backend parity.

tests/Feature/AccountGrade/AccountGradePrivateDataTest.php
# TYPE: feature_test
# PURPOSE: Prevent one user's grade/history from leaking publicly.

tests/Feature/AccountGrade/AccountGradeLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 09 — PRIZE VERIFICATION

## PURPOSE

Create:

`09. PRIZE VERIFICATION`

The live page is largely an informational/manual ticket-verification guide describing ticket numbers, verified numbers, barcode and physical ticket-security characteristics.

The new application already has a functional server-side prize verification concept.

Design BOTH:

1. explanation
2. actual verification tool

---

## BACKEND

Reuse canonical verification service.

Potential architecture:

```text
app/
├── Http/
│   └── Controllers/
│       └── PrizeVerificationController.php
│       # TYPE: controller
│       # PURPOSE: GET/POST public prize verification workflow with throttling and safe result presentation.
│
└── Services/
    └── Lottery/
        └── GloResultPublicationService.php
        # TYPE: service
        # PURPOSE: Verify result provenance and check submitted ticket/result data through canonical lottery result logic.
```

If these already exist:

DO NOT duplicate.

---

## VIEW TREE

```text
resources/views/
├── prize-verification/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Combined premium prize-verification guide + interactive checker.
│
└── components/
    └── prize-verification/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Verification hero.
        │
        ├── checker.blade.php
        # TYPE: blade_component
        # PURPOSE: Ticket number input and verification UI.
        │
        ├── result.blade.php
        # TYPE: blade_component
        # PURPOSE: Verified/not-found/pending/error result states.
        │
        ├── ticket-guide.blade.php
        # TYPE: blade_component
        # PURPOSE: Educational ticket/barcode guidance.
        │
        └── security.blade.php
            # TYPE: blade_component
            # PURPOSE: Explain actual verification/security concepts without fake guarantees.
```

---

## INPUT RULES

Validate:

- exact expected digit length
- numeric format
- leading zeros
- normalization
- rate limit
- abuse prevention

Do not strip leading zeros.

Example:

`004615`

must remain:

`004615`

---

## RESULT STATES

Design:

```text
VERIFYING
WINNER
NOT A WINNER
NOT FOUND
INVALID FORMAT
RATE LIMITED
SERVICE UNAVAILABLE
```

Never reveal internal exceptions.

---

## TESTS

```text
tests/Feature/PrizeVerification/PrizeVerificationPageTest.php
# TYPE: feature_test
# PURPOSE: Public page.

tests/Feature/PrizeVerification/PrizeVerificationFlowTest.php
# TYPE: feature_test
# PURPOSE: GET/POST verification behavior.

tests/Feature/PrizeVerification/PrizeVerificationSecurityTest.php
# TYPE: feature_test
# PURPOSE: Rate limiting, input safety, result privacy.

tests/Feature/PrizeVerification/PrizeVerificationLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Verify leading-zero ticket/result strings remain unchanged.

tests/Feature/PrizeVerification/PrizeVerificationLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 10 — LOTTO DISCOUNT

## PURPOSE

Create:

`10. LOTTO DISCOUNT`

Live discount page publishes separate National and Bangkok Weekly discount rules and commission/game-related values.

The new system must use canonical server-computed rules.

---

## BACKEND

Reuse:

```text
app/Services/
├── Discount/
│   # TYPE: service layer
│   # PURPOSE: Canonical discount calculation/catalogue.
│
└── Promotions/
    # TYPE: service layer
    # PURPOSE: Existing public promotion/offer presentation where applicable.
```

Use actual repository classes.

Do not calculate discounts independently inside Blade/JS.

---

## VIEW

```text
resources/views/
├── discounts/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Public discount catalogue with source-driven game categories.
│
└── components/
    └── discounts/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Premium discount hero.
        │
        ├── market-tabs.blade.php
        # TYPE: blade_component
        # PURPOSE: National / Weekly / other configured market selectors.
        │
        ├── discount-card.blade.php
        # TYPE: blade_component
        # PURPOSE: One discount row/card from backend.
        │
        ├── discount-table.blade.php
        # TYPE: blade_component
        # PURPOSE: Desktop comparison table.
        │
        └── notes.blade.php
            # TYPE: blade_component
            # PURPOSE: Eligibility/qualification notes from canonical rules.
```

---

## RULES

Do not let client-side input modify:

- discount percentage
- base price
- payout
- commission
- eligibility

These must be server-authoritative.

---

## DESIGN

Create:

large premium glass discount cards.

Possible visual:

percentage number
+
ticket
+
gold discount seal
+
rule details

No fake “up to” percentage.

Use exact actual configured values.

---

## TESTS

```text
tests/Feature/Discounts/DiscountsPageTest.php
# TYPE: feature_test
# PURPOSE: Public page rendering.

tests/Feature/Discounts/DiscountRulesIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Published page values must match canonical rules.

tests/Feature/Discounts/DiscountSecurityTest.php
# TYPE: feature_test
# PURPOSE: Client input cannot manipulate published discount rules.

tests/Feature/Discounts/DiscountLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 11 — HOW TO PLAY

## PURPOSE

Create:

`11. HOW TO PLAY`

This is an educational page.

Use actual product flows.

Do not describe unsupported wagering mechanics.

---

## BACKEND

Prefer:

```text
app/Services/PublicPages/PublicPageDataService.php
# TYPE: service
# PURPOSE: Source-driven public how-to-play content.
```

Only create:

```text
app/Services/PublicPages/HowToPlayPageService.php
# TYPE: service
# PURPOSE: Structured how-to-play content, ONLY if the canonical public-page service cannot cleanly support the required sections.
```

---

## VIEW

```text
resources/views/
├── how-to-play/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Educational how-to-play page.
│
└── components/
    └── how-to-play/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Visual education hero.
        │
        ├── choose.blade.php
        # TYPE: blade_component
        # PURPOSE: Explain selecting a supported lottery/product.
        │
        ├── ticket.blade.php
        # TYPE: blade_component
        # PURPOSE: Explain ticket/bet selection using actual product rules.
        │
        ├── payment.blade.php
        # TYPE: blade_component
        # PURPOSE: Explain actual supported payment workflow.
        │
        ├── result.blade.php
        # TYPE: blade_component
        # PURPOSE: Explain result-checking.
        │
        ├── claim.blade.php
        # TYPE: blade_component
        # PURPOSE: Explain actual prize-claim workflow.
        │
        └── responsible-play.blade.php
            # TYPE: blade_component
            # PURPOSE: Responsible gaming information using actual platform capabilities.
```

---

## IMPORTANT

Never promise:

- winning
- guaranteed profit
- guaranteed payout
- guaranteed jackpot
- guaranteed approval

Educational text must explain process, not outcome.

---

## DESIGN

Create:

interactive vertical journey:

```text
01 CHOOSE
02 REVIEW
03 PURCHASE
04 RESULT
05 CLAIM
```

Each stage:

- 3D icon/object
- short explanation
- actual CTA
- progress line

---

## TESTS

```text
tests/Feature/HowToPlay/HowToPlayPageTest.php
# TYPE: feature_test
# PURPOSE: Page and section rendering.

tests/Feature/HowToPlay/HowToPlayLinkIntegrityTest.php
# TYPE: feature_test
# PURPOSE: All CTA destinations are real routes.

tests/Feature/HowToPlay/HowToPlayLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 12 — FAQ

## PURPOSE

Create:

`12. FAQ`

This page must be:

- searchable
- category-based
- keyboard accessible
- mobile friendly
- source-driven

---

## BACKEND

First inspect whether FAQ already exists in:

- PublicPageDataService
- database/CMS
- localization
- config

Reuse it.

Only if genuinely absent:

```text
app/Services/PublicPages/FaqPageService.php
# TYPE: service
# PURPOSE: Canonical FAQ data normalization and category ordering.
```

Do not create duplicate FAQ systems.

---

## VIEW

```text
resources/views/
├── faq/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Searchable categorized FAQ page.
│
└── components/
    └── faq/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: FAQ hero and search.
        │
        ├── category-tabs.blade.php
        # TYPE: blade_component
        # PURPOSE: Category filter.
        │
        ├── question.blade.php
        # TYPE: blade_component
        # PURPOSE: Accessible accordion question/answer.
        │
        └── contact-cta.blade.php
            # TYPE: blade_component
            # PURPOSE: Real support/contact destination.
```

---

## FAQ CATEGORIES

Use categories only when actual content supports them.

Possible categories:

- Account
- Verification
- Lottery
- Results
- Payment
- Withdrawal
- Prize
- Responsible Gaming
- Technical

Do not render empty categories.

---

## DESIGN

Create:

large glass question cards.

Accordion behavior:

- keyboard accessible
- one/all open according to UX
- URL hash deep-link when useful
- reduced-motion compatible

---

## JS

```text
resources/js/pages/faq.js
# TYPE: javascript
# PURPOSE: Search, filtering, accordion behavior, deep-linking and accessibility.
```

No external search service.

---

## TESTS

```text
tests/Feature/FAQ/FaqPageTest.php
# TYPE: feature_test
# PURPOSE: FAQ rendering.

tests/Feature/FAQ/FaqSearchTest.php
# TYPE: feature_test
# PURPOSE: Search/filter correctness.

tests/Feature/FAQ/FaqLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH content parity.

tests/Feature/FAQ/FaqLinkIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Validate all links.
```

---

# PAGE 13 — CONTACT US

## PURPOSE

Create:

`13. CONTACT US`

The current live contact surface publishes email, website and a physical location.

IMPORTANT:

Do not automatically reproduce live identity/address claims unless they are approved for the new application.

---

## BACKEND

Reuse/create canonical contact workflow.

```text
app/Http/Controllers/
└── ContactController.php
# TYPE: controller
# PURPOSE: GET contact page + POST contact form using canonical validation and delivery flow.
```

If an existing canonical PublicPages/contact controller exists:

reuse it.

---

## REQUEST

```text
app/Http/Requests/
└── ContactRequest.php
# TYPE: form_request
# PURPOSE: Validate contact form safely with spam/rate-limit-compatible validation.
```

Reuse existing request if already present.

---

## SERVICE

```text
app/Services/
└── Support/
    └── ContactSubmissionService.php
    # TYPE: service
    # PURPOSE: Canonical contact submission delivery, audit and failure handling.
```

Create only if genuinely missing.

---

## VIEW

```text
resources/views/
├── contact/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Premium Contact page combining support channels and secure contact form.
│
└── components/
    └── contact/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Contact hero.
        │
        ├── contact-methods.blade.php
        # TYPE: blade_component
        # PURPOSE: Real configured support channels.
        │
        ├── contact-form.blade.php
        # TYPE: blade_component
        # PURPOSE: Accessible validated contact form.
        │
        ├── support-hours.blade.php
        # TYPE: blade_component
        # PURPOSE: Render support hours only when configured.
        │
        ├── location.blade.php
        # TYPE: blade_component
        # PURPOSE: Render approved business location only when available.
        │
        └── success-state.blade.php
            # TYPE: blade_component
            # PURPOSE: Honest submission confirmation without claiming delivery if email/ticket creation failed.
```

---

## CONTACT SECURITY

Verify:

- CSRF
- rate limiting
- spam protection
- input validation
- HTML sanitization
- email header safety
- PII minimization
- safe logging
- no secret disclosure

---

## STATUS FLOW

Form must distinguish:

```text
IDLE
SUBMITTING
SUCCESS
VALIDATION ERROR
RATE LIMITED
DELIVERY FAILED
SERVICE UNAVAILABLE
```

Do not tell the user “message sent” if delivery failed.

---

## TESTS

```text
tests/Feature/Contact/ContactPageTest.php
# TYPE: feature_test
# PURPOSE: GET page.

tests/Feature/Contact/ContactSubmissionTest.php
# TYPE: feature_test
# PURPOSE: Valid/invalid submission workflow.

tests/Feature/Contact/ContactSecurityTest.php
# TYPE: feature_test
# PURPOSE: CSRF, throttling, injection and spam controls.

tests/Feature/Contact/ContactLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 14 — DOWNLOAD APP

## PURPOSE

Create:

`14. DOWNLOAD APP`

This is a public app/download page.

The page must use real configured destinations.

The previous source architecture already requires app links to fail closed when not configured.

---

## BACKEND

Reuse:

```text
app/Services/Media/PublicAppLinkService.php
# TYPE: service
# PURPOSE: Canonical sanitized app-link provider.
```

Do not create another app-link service.

If it is located elsewhere:

reuse the actual canonical service.

---

## VIEW

```text
resources/views/
├── download/
│   └── index.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Premium app download landing page with platform-aware real links.
│
└── components/
    └── download/
        ├── hero.blade.php
        # TYPE: blade_component
        # PURPOSE: Cinematic app hero with device mockup/3D visual.
        │
        ├── platform-cards.blade.php
        # TYPE: blade_component
        # PURPOSE: iOS/Android/PWA cards rendered only for configured destinations.
        │
        ├── qr-code.blade.php
        # TYPE: blade_component
        # PURPOSE: QR code for a real validated destination only.
        │
        ├── features.blade.php
        # TYPE: blade_component
        # PURPOSE: Show actual app/platform capabilities, not invented features.
        │
        └── security.blade.php
            # TYPE: blade_component
            # PURPOSE: Explain actual account/security capabilities.
```

---

## LINK SAFETY

Reject:

```text
javascript:
data:
vbscript:
blank
whitespace
malformed
```

Only render valid HTTPS/app-store destinations where appropriate.

Existing fail-closed app-link behavior must remain intact.

---

## QR

Do not render a QR code if there is no real destination.

Do not encode:

- placeholder URL
- localhost
- example.com
- fake app store URL

---

## VISUAL DESIGN

Create:

large floating smartphone/device mockup
+
3D glass UI
+
premium gold highlights
+
QR card
+
platform cards
+
download CTA

Do not create fake App Store/Google Play badges if real destinations are unavailable.

---

## OPTIONAL API

No polling required.

Server-side app-link rendering is acceptable.

API only if the existing architecture/app client needs:

`GET /api/v1/app-links`

Otherwise do not create a pointless endpoint.

---

## TESTS

```text
tests/Feature/Download/DownloadPageTest.php
# TYPE: feature_test
# PURPOSE: Public download page rendering.

tests/Feature/Download/AppLinkSafetyTest.php
# TYPE: feature_test
# PURPOSE: Validate only safe configured app links render.

tests/Feature/Download/DownloadLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.

tests/Feature/Download/QrDestinationTest.php
# TYPE: feature_test
# PURPOSE: QR codes are only generated from valid real destinations.
```

---

# GLOBAL CSS REQUIREMENT

Create/extend:

```text
resources/css/pages/
├── privacy.css
├── fees.css
├── account-verification.css
├── account-grades.css
├── prize-verification.css
├── discounts.css
├── how-to-play.css
├── faq.css
├── contact.css
└── download.css
```

Every file MUST have:

```text
# TYPE:
# PURPOSE:
```

as the header comment.

Use shared design tokens.

Do not copy the same 500-line stylesheet into every page.

---

# GLOBAL JS REQUIREMENT

Create only:

```text
resources/js/pages/
├── privacy.js
├── fees.js
├── account-verification.js
├── account-grades.js
├── prize-verification.js
├── discounts.js
├── how-to-play.js
├── faq.js
├── contact.js
└── download.js
```

Only include JavaScript when the page actually needs it.

Every file MUST have:

```text
/**
 * TYPE:
 * PURPOSE:
 */
```

---

# LOCALIZATION REQUIREMENT

Use the existing canonical public-page localization system.

Do not create duplicate translation namespaces when `public_pages.php` already exists.

Ensure:

```text
English
↕
Thai
```

have exact key parity.

No raw keys such as:

`public_pages.foo`

may appear in rendered production pages.

---

# VITE REQUIREMENT

Update the existing Vite configuration only as required.

Preserve:

- Home CSS/JS
- About CSS/JS
- Vision CSS/JS
- all existing assets

Add these 10 page assets to the established build architecture.

Do not create a second bundler.

---

# SHARED LAYOUT REQUIREMENT

Preserve:

- navbar
- footer
- global typography
- language selector
- authentication state
- existing Home/About/Vision behavior

Do not accidentally break earlier pages.

---

# SEO REQUIREMENT FOR ALL 10 PAGES

Each page must have:

- unique title
- unique meta description
- canonical URL
- correct heading structure
- Open Graph where supported
- accessible semantic markup

Do not fabricate legal/company structured data.

---

# RESPONSIVE REQUIREMENT FOR ALL 10 PAGES

Verify:

## Desktop

premium full-width experience.

## Tablet

compressed but readable.

## Mobile

single-column or mobile-native layout.

No:

- horizontal overflow
- clipped cards
- tiny legal text
- broken tables
- inaccessible accordions
- overlapping 3D visuals
- impossible touch targets

---

# ACCESSIBILITY REQUIREMENT FOR ALL 10 PAGES

Must include:

- semantic headings
- visible focus
- keyboard support
- aria labels where appropriate
- sufficient contrast
- screen-reader support
- reduced motion
- large touch targets
- meaningful link names
- accessible form errors

Do not make information dependent on animation.

---

# SECURITY REQUIREMENT FOR ALL 10 PAGES

Review:

- XSS
- unsafe HTML
- unsafe external links
- CSRF
- IDOR
- PII leakage
- secret leakage
- debug leakage
- unauthorized data
- rate limiting
- input validation

---

# LIVE / CODE COMPARISON REQUIREMENT

For each of these 10 pages:

Compare:

`LIVE PAGE`

vs

`CURRENT SOURCE`

vs

`NEW DESIGN`

Use:

```text
OBSERVED LIVE
SOURCE VERIFIED
IMPLEMENTED
NOT VERIFIED
APPROVAL REQUIRED
```

Do not treat live content as automatically authoritative.

---

# CROSS-PAGE CONSISTENCY

Before completing the batch, compare:

Privacy
Fees
Account Verification
Account Grade
Prize Verification
Discount
How to Play
FAQ
Contact
Download App

against:

- Terms
- About
- Vision
- Home
- payment configuration
- legal configuration
- public-page content source

Detect:

- contradictory fees
- contradictory eligibility
- contradictory support contacts
- contradictory legal identity
- contradictory payment methods
- contradictory app links
- contradictory account rules

Do not silently resolve conflicts.

Report the conflict.

---

# REQUIRED FINAL FILE INVENTORY

Return the complete actual file tree for all files changed/created.

Every file:

```text
path/to/file
# TYPE: controller/service/model/view/component/css/js/config/test/etc.
# PURPOSE: exact responsibility.
```

Do not invent files just because they are listed above.

If an existing canonical file was reused:

state:

`REUSED — NO DUPLICATE CREATED`

---

# REQUIRED FINAL REPORT

Return:

## 1. IMPLEMENTATION SUMMARY

## 2. PAGE 05 STATUS — PRIVACY

## 3. PAGE 06 STATUS — FEES

## 4. PAGE 07 STATUS — ACCOUNT VERIFICATION

## 5. PAGE 08 STATUS — ACCOUNT GRADE

## 6. PAGE 09 STATUS — PRIZE VERIFICATION

## 7. PAGE 10 STATUS — DISCOUNT

## 8. PAGE 11 STATUS — HOW TO PLAY

## 9. PAGE 12 STATUS — FAQ

## 10. PAGE 13 STATUS — CONTACT

## 11. PAGE 14 STATUS — DOWNLOAD APP

## 12. COMPLETE FILE TREE

## 13. COMPLETE MODIFIED FILE CONTENT

Every changed file in FULL.

## 14. BACKEND DATA MAP

For every dynamic section:

```text
UI
→ Route
→ Controller
→ Service
→ Model/Config/Content Source
→ View
```

## 15. API MAP

For every API actually added/used:

```text
Endpoint
Method
Auth
Request
Response
Rate Limit
Source
Frontend Consumer
```

## 16. LIVE COMPARISON

All 10 pages.

## 17. SECURITY FINDINGS

## 18. ACCESSIBILITY FINDINGS

## 19. SEO FINDINGS

## 20. TEST RESULTS

Exact commands.

Exact output.

Never claim a test passed unless executed.

## 21. REMAINING BLOCKERS

Only actual blockers.

## 22. EXTERNAL INPUTS REQUIRED

Only genuine operator/legal/provider inputs.

---

# FINAL DEFINITION OF DONE

All 10 pages are complete only when:

[ ] Page 05 complete
[ ] Page 06 complete
[ ] Page 07 complete
[ ] Page 08 complete
[ ] Page 09 complete
[ ] Page 10 complete
[ ] Page 11 complete
[ ] Page 12 complete
[ ] Page 13 complete
[ ] Page 14 complete

[ ] routes verified
[ ] backend architecture verified
[ ] canonical service reuse verified
[ ] missing backend capabilities created where genuinely required
[ ] no duplicate architecture
[ ] real data/content source verified
[ ] EN verified
[ ] TH verified
[ ] responsive verified
[ ] accessibility verified
[ ] SEO verified
[ ] security verified
[ ] legacy compatibility checked
[ ] tests added/updated
[ ] available tests actually executed
[ ] no fabricated data
[ ] no fake identity
[ ] no fake license
[ ] no fake government affiliation
[ ] no fake app URLs
[ ] no unsafe URLs
[ ] no placeholder content
[ ] no truncated file content
[ ] no `href="#"`
[ ] no unnecessary API
[ ] no unnecessary polling
[ ] existing Home/About/Vision behavior preserved

---

# FINAL HONESTY REQUIREMENT

Do NOT say:

`100% production ready`

just because the 10 visual pages are complete.

Report separately:

`DESIGN`
`CONTENT`
`BACKEND`
`API`
`SECURITY`
`LOCALIZATION`
`SEO`
`TESTING`
`LEGACY COMPATIBILITY`
`PRODUCTION CONFIGURATION`

For anything not actually proven:

`NOT VERIFIED`

For legal/business values requiring approval:

`APPROVAL REQUIRED`

For provider/infrastructure dependency:

`EXTERNAL VERIFICATION REQUIRED`

---

# PAGE ORDER LOCK

Implement exactly:

05 → Privacy Policy

06 → Our Fees

07 → Account Verification

08 → Account Grade

09 → Prize Verification

10 → Lotto Discount

11 → How to Play

12 → FAQ

13 → Contact Us

14 → Download App

Do not move to Page 15 until all 10 pages in this batch have been independently implemented, tested where possible, and reported.