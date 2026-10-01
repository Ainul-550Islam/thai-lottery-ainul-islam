# PROMPT 04 — THAILOTTO TERMS & CONDITIONS
# WORLD-CLASS 3D GLASS LEGAL / POLICY DESIGN + REAL CONTENT/BACKEND INTEGRATION
# PAGE 04 OF THE MASTER PAGE LIST

## ROLE

You are the senior product designer + Laravel/PHP architect + frontend engineer + legal-content integration engineer.

Implement ONLY:

`04. TERMS & CONDITIONS`

This is a legally sensitive public page.

The visual design may be completely modernized, but the legal/business meaning of approved source content MUST be preserved.

This page must be:

- premium
- modern
- 3D glass
- highly readable
- structured
- searchable
- accessible
- responsive
- localized
- source-driven
- versioned
- auditable

DO NOT turn Terms & Conditions into decorative marketing copy.

---

# 1. PRIMARY OBJECTIVE

Create a premium legal-information experience that makes long Terms & Conditions easy to navigate without altering their substantive meaning.

Required UX:

- clear hierarchy
- sticky contents navigation
- section anchors
- numbered sections
- expandable sections where useful
- last-updated indicator
- effective-date indicator
- version indicator
- print-friendly mode
- mobile-friendly navigation
- search within terms where practical
- accessible anchor navigation

The content itself must come from the approved source of truth.

---

# 2. LIVE REFERENCE

Inspect the current live Terms page:

`https://www.thailotto.club/terms.php`

Use it as an observable reference for:

- information architecture
- section structure
- topic coverage
- terminology
- legal/content categories

IMPORTANT:

The live page is NOT automatically the legal source of truth for the new application.

Do not blindly copy:

- operator identity
- company identity
- address
- license claims
- government affiliation
- payment claims
- liability claims
- regulatory claims

unless the repository's approved content source explicitly authorizes them.

---

# 3. SOURCE-FIRST RULE

Before modifying anything, inspect:

- current Terms route
- legacy `/terms.php`
- modern Terms route
- `PublicPagesController`
- `PublicPageDataService`
- public legal-content source
- config/legal content
- localization
- existing content version system
- cache/version mechanism
- shared public layout
- existing SEO implementation

Reuse the canonical public-page architecture created/retained for About and Vision.

DO NOT create duplicate public-page architecture.

---

# 4. NON-NEGOTIABLE FILE RULE

For every modified or created file:

OUTPUT COMPLETE FILE CONTENT.

NEVER use:

`# ... existing code ...`

`// ... existing code ...`

`/* existing code */`

`...`

`TODO`

`FIXME`

or truncated content.

Preserve unrelated existing logic.

Do not silently alter About, Vision or other legal pages.

---

# 5. RECOMMENDED ARCHITECTURE

Prefer:

```text id="t3psq2"
PublicPagesController
        ↓
PublicPageDataService
        ↓
Approved Legal / Terms Content Source
        ↓
Terms Renderer / View Model
        ↓
Blade
```

If a canonical public legal-content service already exists:

USE IT.

Do not create:

- TermsService2
- NewTermsService
- LegacyTermsService
- Duplicate PublicPageDataService

---

# 6. FILE TREE

Use actual repository paths discovered during implementation.

```text id="fjce6h"
app/
├── Http/
│   └── Controllers/
│       └── PublicPagesController.php
│       # TYPE: controller
│       # PURPOSE: Canonical public controller; retain existing pages and add/retain Terms rendering through the established PublicPages architecture.
│
└── Services/
    └── PublicPages/
        ├── PublicPageDataService.php
        │   # TYPE: service
        │   # PURPOSE: Canonical public legal/content aggregation source; load approved Terms content and metadata.
        │
        └── TermsContentService.php
            # TYPE: service
            # PURPOSE: Create ONLY if the repository does not already have a suitable legal-content normalizer; validates and structures Terms sections, versions and anchors.
```

If `TermsContentService.php` is not necessary:

DO NOT CREATE IT.

---

# 7. ROUTES

```text id="k5x6rj"
routes/
└── web.php
# TYPE: routes
# PURPOSE: Register/retain modern Terms route and preserve legacy /terms.php bridge behavior; no duplicate routes and no redirect loops.
```

Required verification:

```text id="m44b4c"
Modern:
GET /terms

Legacy:
GET /terms.php
```

Confirm:

- expected 301 behavior
- correct canonical target
- no redirect loop
- modern route renders directly
- legacy bridge does not shadow modern route

---

# 8. LOCALIZATION

Use the canonical public-page localization system.

If the project uses:

```text id="c99m2d"
lang/
├── en/
│   └── public_pages.php
│   # TYPE: localization
│   # PURPOSE: English legal-page UI labels and approved Terms content where localization is appropriate.
│
└── th/
    └── public_pages.php
    # TYPE: localization
    # PURPOSE: Thai equivalent with exact key parity.
```

reuse it.

Do NOT create:

`terms_en.php`

`terms_th.php`

if the canonical public-page localization system already handles Terms.

---

# 9. LEGAL CONTENT STORAGE RULE

Determine whether approved Terms content currently lives in:

- translation files
- config
- database/CMS
- dedicated content files
- existing public-page service

Use the existing source.

If the current architecture lacks structured Terms content and long legal text is too large for translation files:

create a clean structured public legal-content source consistent with the existing architecture.

DO NOT introduce a second CMS.

---

# 10. REQUIRED TERMS DATA STRUCTURE

The page should support structured legal sections similar to:

```php id="2v9cg5"
[
    'page_key' => 'terms',
    'title' => '...',
    'subtitle' => '...',
    'version' => '...',
    'effective_date' => '...',
    'last_updated' => '...',

    'sections' => [
        [
            'id' => 'definitions',
            'number' => '1',
            'title' => 'Definitions',
            'body' => '...',
        ],
        [
            'id' => 'eligibility',
            'number' => '2',
            'title' => 'Eligibility',
            'body' => '...',
        ],
    ],

    'contact' => [...],
    'cta' => [...],
]
```

Actual structure may differ if the repository has a canonical schema.

---

# 11. LEGAL CONTENT PRESERVATION RULE

This is critical.

Do NOT rewrite legal provisions as marketing language.

Allowed:

- formatting improvements
- headings
- numbered sections
- paragraphs
- lists
- tables
- visual emphasis
- navigation
- typography
- spacing

Not allowed without explicit source approval:

- changing eligibility
- changing age
- changing payment terms
- changing withdrawal terms
- changing fees
- changing liability
- changing dispute resolution
- changing account rules
- changing prohibited conduct
- changing jurisdiction
- changing privacy obligations
- changing cancellation/refund rules
- changing prize/payment conditions
- changing legal entity identity

The meaning must remain faithful to the approved source.

---

# 12. HERO DESIGN

Create a premium legal/document hero.

Suggested structure:

```text id="kbx8de"
GLASS DOCUMENT ICON

TERMS & CONDITIONS

Clear short descriptor

Version
Effective date
Last updated

Print
Download / Save
Contents
```

Visual concept:

large transparent glass document
+
gold edge light
+
floating numbered clauses
+
subtle legal/data geometry
+
soft cinematic glow

Do not make the hero visually louder than the legal content.

Readability comes first.

---

# 13. PAGE LAYOUT

Desktop:

```text id="egz6qq"
┌───────────────────────────────────────────┐
│ HERO                                      │
├───────────────┬───────────────────────────┤
│ CONTENTS      │ TERMS CONTENT              │
│ Sticky Nav    │ Section 1                  │
│               │ Section 2                  │
│               │ Section 3                  │
│               │ ...                        │
└───────────────┴───────────────────────────┘
```

Tablet:

- collapsible contents
- readable content width
- preserved anchors

Mobile:

- top “Contents” drawer
- section jump controls
- full-width text
- no tiny legal font
- no horizontal overflow

---

# 14. TABLE OF CONTENTS

Create dynamic contents navigation from actual Terms sections.

Each entry:

- section number
- title
- anchor
- active section state

Example:

```text id="xu1rlr"
01 Definitions
02 Eligibility
03 Account
04 Lottery Participation
05 Payments
06 Withdrawals
07 Responsible Gaming
08 Prohibited Activity
09 Privacy
10 Liability
11 Disputes
12 Changes to Terms
13 Contact
```

DO NOT invent these sections if the source does not contain them.

Generate the contents from actual section data.

---

# 15. SECTION COMPONENT

Create reusable component:

```text id="h4g6xy"
resources/
└── views/
    └── components/
        └── terms/
            ├── section.blade.php
            # TYPE: blade_component
            # PURPOSE: Render one structured Terms section with safe escaped content, section anchor and heading hierarchy.
```

Optional only when needed:

```text id="81m9t5"
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Legal hero metadata and document visual.

├── contents.blade.php
# TYPE: blade_component
# PURPOSE: Dynamic sticky/collapsible section navigation.

├── legal-meta.blade.php
# TYPE: blade_component
# PURPOSE: Version/effective-date/last-updated information.

└── footer-legal.blade.php
# TYPE: blade_component
# PURPOSE: Legal CTA/contact/navigation area.
```

Reuse existing shared components when appropriate.

---

# 16. LEGAL METADATA

Show only metadata that actually exists in the approved source.

Possible:

- Version
- Effective date
- Last updated
- Language
- Publication status

Do NOT fake:

`Updated today`

`Version 2026.1`

`Effective immediately`

unless the source provides those values.

---

# 17. SEARCH WITHIN TERMS

Implement optional client-side search.

Requirements:

- search section headings and visible text
- highlight matches
- jump to matching section
- keyboard accessible
- works without disabling normal reading
- does not send legal text to an external service

Do not create backend search for static legal content unless the repository requires it.

---

# 18. PRINT VIEW

Add print support.

Print should remove:

- decorative 3D backgrounds
- unnecessary navigation
- animations
- floating UI
- sticky elements

Print should retain:

- title
- version
- effective date
- all Terms content
- page numbers where practical

Do not alter legal wording between screen and print.

---

# 19. DOWNLOAD / SAVE

Only provide “Download PDF” when a real PDF-generation/download architecture exists.

Do NOT create a fake download button.

Allowed alternatives:

- browser print
- save/print control
- existing legal document export system

If PDF generation exists:

reuse it.

Do not create an unrelated document pipeline.

---

# 20. FRONTEND CSS

```text id="8qoj4m"
resources/
└── css/
    └── pages/
        └── terms.css
        # TYPE: stylesheet
        # PURPOSE: premium 3D glass legal-page design, readable typography, contents rail, section cards, print styles, responsive behavior, focus and reduced-motion support.
```

Do not duplicate the entire global design system.

Reuse shared variables/tokens.

Legal readability takes priority over decorative effects.

---

# 21. JAVASCRIPT

```text id="6wx5c1"
resources/
└── js/
    └── pages/
        └── terms.js
        # TYPE: javascript
        # PURPOSE: contents navigation, active-section tracking, search/highlight, mobile contents drawer, keyboard navigation and reduced-motion support.
```

No polling.

No unnecessary API calls.

No third-party legal-text processing.

---

# 22. API RULE

A dedicated:

`GET /api/v1/terms`

is OPTIONAL.

Do not create it simply to say “API connected”.

Server-side rendering is completely acceptable for legal content.

Create/use an API only when:

- existing architecture requires it
- legal content is already API-backed
- mobile app needs the content through the same public API
- CMS architecture requires it

Never expose:

- internal legal drafts
- admin notes
- unpublished versions
- internal reviewer metadata
- secret configuration

---

# 23. CONTENT VERSIONING

If the repository already supports public-page content versioning:

reuse it.

Every Terms change should have a clear content revision mechanism.

At minimum support:

- current version
- effective date
- last updated
- stable page key

Do not silently overwrite legal content if the existing system supports historical versions.

---

# 24. CACHE

Use the established public-page cache/versioning mechanism.

Do NOT create a second independent cache.

When Terms content version changes:

invalidate/update the correct cache.

Tests must verify stale cache is not served indefinitely after a content version change where the repository architecture supports cache invalidation.

---

# 25. LEGAL CONTENT SECURITY

Treat all legal content as potentially untrusted input if it can be admin-managed.

Verify:

- XSS
- unsafe HTML
- script injection
- event-handler injection
- unsafe links
- unsafe iframe/embed
- malicious attributes

Prefer plain escaped text.

If limited rich text is required:

sanitize it server-side using the project's canonical sanitization mechanism.

Never trust ` {!! $html !!} ` blindly.

---

# 26. LINK SAFETY

Any external/legal link must be:

- explicitly approved
- sanitized
- valid

Reject unsafe schemes such as:

`javascript:`

`data:`

`vbscript:`

Do not render broken links.

---

# 27. LEGAL IDENTITY SAFETY

Do not fabricate or infer:

- company name
- registration number
- government ownership
- GLO ownership
- official government status
- government license
- gambling license
- regulatory approval
- jurisdiction
- physical office
- official partnership

unless present in the approved source of truth.

The live site may contain such claims; they must remain clearly separated from independently verified application content.

---

# 28. RESPONSIBLE GAMING CROSS-LINK

Where Terms content references responsible gaming:

link to the actual:

`responsible gaming`

page/route if it exists.

Do NOT create dead links.

If the relevant page does not yet exist:

do not create fake routing merely for Terms.

Record it as a dependency.

---

# 29. PAYMENT / FEES CROSS-LINK

Where the Terms references:

- fees
- deposits
- withdrawals
- payments

link to canonical existing pages only.

Do not duplicate fee tables inside Terms unless the approved Terms source actually contains those details.

Avoid two competing sources of truth.

---

# 30. ACCOUNT CROSS-LINKS

Where Terms references:

- verification
- account
- password
- security
- self-exclusion
- support

link to actual routes.

All links must be tested.

---

# 31. ACCESSIBILITY

Required:

- one logical `h1`
- ordered `h2/h3`
- semantic sections
- keyboard-accessible contents
- visible focus
- accessible search
- skip navigation
- readable line length
- adequate contrast
- no essential content hidden behind animation
- screen-reader-friendly anchors
- reduced motion

---

# 32. RESPONSIVE DESIGN

## Desktop

- premium split layout
- sticky contents
- elegant legal document card
- section numbering

## Tablet

- collapsible navigation
- readable 2-column/1-column transition

## Mobile

- contents drawer
- sticky section control
- readable typography
- large touch targets
- no horizontal overflow

---

# 33. SEO

Implement:

- canonical URL
- title
- description
- robots behavior consistent with public legal pages
- Open Graph where appropriate
- semantic headings

Do not put legal content into misleading structured-data types.

Do not create fake Organization schema.

---

# 34. TEST TREE

Use actual repository conventions.

```text id="g4jgdq"
tests/
├── Feature/
│   └── Terms/
│       ├── TermsPageTest.php
│       # TYPE: feature_test
│       # PURPOSE: Modern Terms route/render/public access verification.
│
│       ├── TermsContentIntegrityTest.php
│       # TYPE: feature_test
│       # PURPOSE: Verify approved legal-content source and prevent fabricated legal identity/claims.
│
│       ├── TermsLocalizationTest.php
│       # TYPE: feature_test
│       # PURPOSE: EN/TH parity and rendered language verification.
│
│       ├── TermsLegacyRouteTest.php
│       # TYPE: feature_test
│       # PURPOSE: Legacy /terms.php redirect/compatibility verification.
│
│       └── TermsLinkIntegrityTest.php
│           # TYPE: feature_test
│           # PURPOSE: Verify all internal Terms links point to existing valid routes and no unsafe schemes are rendered.
│
└── Unit/
    └── Services/
        └── PublicPages/
            └── TermsContentServiceTest.php
            # TYPE: unit_test
            # PURPOSE: Validate section normalization, ordering, metadata and fail-safe behavior when a dedicated service exists.
```

---

# 35. TEST CASES

At minimum verify:

### Routing

- `/terms` → 200
- `/terms.php` → expected 301
- redirect target correct
- no loop
- no duplicate modern route

### Content

- title
- sections
- ordering
- anchors
- version
- effective date
- last updated
- empty content behavior
- malformed section handling

### Security

- no unsafe HTML
- no script injection
- unsafe external URLs rejected
- no private metadata leakage

### Legal identity

- no fabricated government affiliation
- no fake license
- no fake registration
- no fabricated legal entity

### Localization

- EN works
- TH works
- key parity
- no raw keys

### Links

- all internal links resolve
- responsible gaming link when present
- fees link when present
- contact link when present
- login/account links when present
- no `href="#"`

### Frontend

- contents navigation
- search
- keyboard support
- mobile layout
- reduced motion
- print layout

---

# 36. LIVE COMPARISON MATRIX

Produce:

| Topic | Live `/terms.php` | Approved Source | New Page | Status |
|---|---|---|---|---|
| Account rules | observed | verified/unverified | implemented | ... |
| Eligibility | observed | verified/unverified | implemented | ... |
| User obligations | observed | verified/unverified | implemented | ... |
| Payments | observed | verified/unverified | implemented | ... |
| Withdrawals | observed | verified/unverified | implemented | ... |
| Fees | observed | verified/unverified | linked/source-based | ... |
| Prohibited actions | observed | verified/unverified | implemented | ... |
| Responsible gaming | observed | verified/unverified | linked/implemented | ... |
| Liability | observed | verified/unverified | implemented | ... |
| Changes to Terms | observed | verified/unverified | implemented | ... |
| Contact | observed | verified/unverified | implemented | ... |

Never treat “exists on live site” as legal verification.

---

# 37. NO LEGAL VALUE FABRICATION

Never hardcode unsupported values such as:

```php id="qzm6m8"
$minimumAge = 18;
$withdrawalFee = 0;
$refundWindow = 24;
$jurisdiction = 'Thailand';
```

unless the approved Terms source explicitly establishes them.

Where the business/legal value is unknown:

use the approved source or mark it:

`OPERATOR / LEGAL APPROVAL REQUIRED`

Do not guess.

---

# 38. CROSS-PAGE CONSISTENCY

Before completing the Terms page, compare referenced values against existing canonical pages:

- Fees
- Privacy
- Responsible Gaming
- Account Verification
- Contact
- About
- Vision

Detect contradictions.

Do NOT silently change another page to make Terms fit.

Report contradictions as a content-source conflict requiring resolution.

---

# 39. SHARED FILE SAFETY

If modifying:

- `PublicPagesController.php`
- `PublicPageDataService.php`
- `public_pages.php`
- shared layout
- Vite config

preserve all existing behavior.

No regressions to:

- Home
- About
- Vision
- other public pages
- localization
- caching

---

# 40. DEFINITION OF DONE

Page 04 is NOT complete until:

[ ] modern Terms route verified
[ ] legacy `/terms.php` verified
[ ] expected 301 verified
[ ] canonical controller verified
[ ] canonical public-page service verified
[ ] legal content source identified
[ ] content meaning preserved
[ ] section structure implemented
[ ] contents navigation implemented
[ ] section anchors implemented
[ ] version metadata sourced
[ ] effective date sourced
[ ] last-updated sourced
[ ] EN localization verified
[ ] TH localization verified
[ ] 3D/glass visual design complete
[ ] AI-quality visual composition complete
[ ] search implemented or intentionally omitted with reason
[ ] print mode implemented
[ ] responsive desktop verified
[ ] responsive tablet verified
[ ] responsive mobile verified
[ ] accessibility verified
[ ] reduced motion verified
[ ] SEO verified
[ ] link integrity verified
[ ] XSS/content-security review complete
[ ] no fabricated legal identity
[ ] no fabricated licensing
[ ] no fabricated government affiliation
[ ] no fabricated fees
[ ] no fabricated eligibility
[ ] no fake download button
[ ] no dead links
[ ] no `href="#"`
[ ] tests created/updated
[ ] tests actually executed where runtime permits
[ ] no placeholder code
[ ] no truncated file content
[ ] no duplicate architecture

---

# 41. FINAL OUTPUT REQUIRED

Return:

## A. IMPLEMENTATION SUMMARY

## B. COMPLETE FILE TREE

For each file:

```text id="v7b3w4"
path/to/file
# TYPE: controller/service/view/component/css/js/test/config/etc.
# PURPOSE: exact responsibility.
```

## C. COMPLETE MODIFIED FILE CONTENT

Every modified/created file in FULL.

## D. LEGAL CONTENT DATA MAP

```text id="m2xj2q"
UI Section
→ Controller
→ PublicPageDataService
→ Approved Content Source
→ View Component
```

## E. LIVE COMPARISON

Clearly distinguish:

`OBSERVED LIVE`

`APPROVED SOURCE`

`IMPLEMENTED`

`NOT VERIFIED`

`LEGAL APPROVAL REQUIRED`

## F. TEST RESULTS

Exact command and exact runtime output.

Never state “passed” without execution.

## G. CROSS-PAGE CONTRADICTIONS

List any conflict with:

- Fees
- Privacy
- Responsible Gaming
- Account Verification
- Contact
- About
- Vision

## H. REMAINING BLOCKERS

Only actual blockers.

---

# 42. FINAL HONESTY RULE

Do NOT call this page:

`100% legally approved`

unless actual legal approval exists.

Do NOT call it:

`production ready`

merely because the design is complete.

Report separately:

`DESIGN STATUS`

`CONTENT SOURCE STATUS`

`LEGAL CONTENT STATUS`

`BACKEND STATUS`

`ROUTE STATUS`

`SECURITY STATUS`

`LOCALIZATION STATUS`

`TEST STATUS`

`LIVE COMPATIBILITY STATUS`

When legal/source/runtime evidence is unavailable:

`NOT VERIFIED`

---

# 43. PAGE SCOPE

This prompt implements ONLY:

`04. TERMS & CONDITIONS`

Do NOT redesign:

- Home
- About
- Vision & Mission
- Privacy Policy
- Fees
- Account Verification
- Account Grade
- Prize Verification
- Lotto Discount
- How to Play
- FAQ
- Contact
- Download App

Shared infrastructure may be modified only when necessary.

Preserve all existing behavior.

---

# 44. FINAL QUALITY BAR

The page should feel like:

`PREMIUM LEGAL DOCUMENT`
+
`MODERN DIGITAL PRODUCT`
+
`3D GLASS DESIGN`
+
`EXCELLENT READABILITY`
+
`SOURCE-OF-TRUTH CONTENT`
+
`AUDITABLE VERSIONING`

The visual design may be luxurious.

The legal meaning must remain precise.