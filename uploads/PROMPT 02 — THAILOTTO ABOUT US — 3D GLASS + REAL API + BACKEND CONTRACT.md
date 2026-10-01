# PROMPT 02 — THAILOTTO ABOUT US
# WORLD-CLASS 3D GLASS INFORMATION PAGE + AI-QUALITY VISUAL DESIGN + REAL API/BACKEND INTEGRATION
# PAGE 02 OF THE MASTER PAGE LIST

## ROLE

You are the senior product designer + Laravel/PHP architect + frontend engineer + API integration engineer.

Implement ONLY:

`02. ABOUT US`

This must be a real production page.

The page must visually match the Home page design language while having its own editorial/storytelling composition.

Required quality:

- premium 3D glass
- cinematic historical storytelling
- AI-quality visual assets
- elegant typography
- responsive desktop/tablet/mobile layout
- real backend content source
- real API where the architecture requires it
- complete EN/TH localization
- accessibility
- SEO
- no fabricated company identity
- no fabricated government affiliation
- no invented legal/licensing information
- no static fake business facts

---

# 1. SOURCE-OF-TRUTH RULE

Inspect the existing repository before creating anything.

Find:

- current About route
- current About controller
- current About Blade/template
- current public content service
- legal/content configuration
- localization files
- shared public layout
- shared components
- SEO implementation
- existing CMS/content models
- API conventions

Reuse the canonical architecture.

Do NOT create duplicate:

- ContentService
- AboutService
- AboutController
- API response architecture
- localization system

If a required backend capability does not exist, create it.

---

# 2. LIVE REFERENCE

Use the current live page:

`https://www.thailotto.club/about.php`

as an observable reference.

The live page currently contains:

- About Us heading
- historical narrative concerning lottery development in Thailand
- historical milestones
- “How to start”
- Choose
- Buy
- Win

The live page also contains institutional wording that MUST NOT automatically be treated as verified legal/operator identity for our implementation.

Preserve factual source-backed content where appropriate.

Do not silently invent or upgrade claims.

---

# 3. NON-NEGOTIABLE FILE RULE

For every created or modified file:

OUTPUT THE COMPLETE FILE CONTENT.

Never use:

`# ... existing code ...`

`// ... existing code ...`

`/* existing code */`

`...`

`TODO`

`FIXME`

or truncated examples.

Preserve all valid existing logic.

---

# 4. REQUIRED FILE TREE

Use the actual existing canonical paths discovered in the repository.

```text
app/
├── Http/
│   └── Controllers/
│       ├── AboutController.php
│       │   # TYPE: controller
│       │   # PURPOSE: Public About page controller; loads approved public About content through the canonical content/service layer.
│       │
│       └── Api/
│           └── V1/
│               └── AboutController.php
│                   # TYPE: api_controller
│                   # PURPOSE: Optional versioned public About API; expose only approved public content and metadata.
│
├── Services/
│   └── About/
│       ├── AboutPageDataService.php
│       │   # TYPE: service
│       │   # PURPOSE: Canonical About-page orchestration service; aggregates approved historical/content/story data and page metadata.
│       │
│       └── AboutTimelineService.php
│           # TYPE: service
│           # PURPOSE: Normalizes About historical milestones into ordered, validated timeline data; no fabricated dates or facts.
│
└── Models/
    # CODING AGENT: reuse existing public-content/CMS models. Create a dedicated About model only if persistent structured About content genuinely requires one.
```

---

# 5. ROUTES

```text
routes/
├── web.php
# CODING AGENT: register/retain the canonical About route; preserve legacy compatibility rules already established; do not create duplicate route definitions.

└── api.php
# CODING AGENT: add a versioned About API route only if the frontend architecture requires API-driven content.
```

The implementation must NOT break the existing legacy `.php` redirect/cutover architecture.

---

# 6. LOCALIZATION

```text
lang/
├── en/
│   └── about.php
│   # TYPE: localization
│   # PURPOSE: Complete English About page copy, UI labels, timeline labels, CTA labels and accessibility labels.
│
└── th/
    └── about.php
    # TYPE: localization
    # PURPOSE: Complete Thai equivalent with exact key parity and natural Thai wording.
```

Do not place large blocks of user-facing text directly in Blade when the project's localization/content architecture expects translation keys.

---

# 7. FRONTEND VIEWS

```text
resources/views/
├── about.blade.php
│   # TYPE: blade_view
│   # PURPOSE: Main About page composition; renders backend-provided content using reusable sections.
│
└── components/
    └── about/
        ├── hero.blade.php
        │   # TYPE: blade_component
        │   # PURPOSE: Premium About hero with cinematic 3D visual, page title, subtitle and contextual breadcrumb.
        │
        ├── story.blade.php
        │   # TYPE: blade_component
        │   # PURPOSE: Main About narrative section; renders approved public story/history content.
        │
        ├── timeline.blade.php
        │   # TYPE: blade_component
        │   # PURPOSE: Interactive historical milestone timeline sourced from validated backend data.
        │
        ├── how-to-start.blade.php
        │   # TYPE: blade_component
        │   # PURPOSE: “Choose → Buy → Win” visual journey adapted from the live public information architecture.
        │
        ├── values.blade.php
        │   # TYPE: blade_component
        │   # PURPOSE: Approved platform values/mission-style principles; only show claims supported by configured content.
        │
        ├── trust.blade.php
        │   # TYPE: blade_component
        │   # PURPOSE: Public trust information using verified/configured platform capabilities, not fabricated certifications.
        │
        └── cta.blade.php
            # TYPE: blade_component
            # PURPOSE: Real CTA links to Contact, Results, Lottery and/or Registration according to actual application routes.
```

---

# 8. FRONTEND ASSETS

```text
resources/
├── css/
│   └── pages/
│       └── about.css
│       # TYPE: stylesheet
│       # PURPOSE: About page 3D glass styling, timeline depth, historical visual cards, responsive layouts, animation and reduced-motion support.
│
└── js/
    └── pages/
        └── about.js
        # TYPE: javascript
        # PURPOSE: Timeline interaction, scroll reveal, active milestone navigation and accessible client-side enhancement.
```

Do not introduce heavy JavaScript merely for decoration.

---

# 9. TESTS

```text
tests/
├── Feature/
│   └── About/
│       ├── AboutPageTest.php
│       # TYPE: feature_test
│       # PURPOSE: About route, render, response, public access and basic content assertions.
│
│       ├── AboutApiTest.php
│       # TYPE: feature_test
│       # PURPOSE: API status/schema/public-field/error behavior when About API exists.
│
│       ├── AboutContentIntegrityTest.php
│       # TYPE: feature_test
│       # PURPOSE: Verify About page does not expose fabricated operator/legal/licensing claims.
│
│       ├── AboutLocalizationTest.php
│       # TYPE: feature_test
│       # PURPOSE: EN/TH translation key parity and rendered-language verification.
│       │
│       └── AboutLegacyRouteTest.php
│           # TYPE: feature_test
│           # PURPOSE: Verify legacy /about.php behavior remains compatible with the repository cutover strategy.
│
└── Unit/
    └── Services/
        └── About/
            ├── AboutPageDataServiceTest.php
            # TYPE: unit_test
            # PURPOSE: About data aggregation and fail-closed behavior.
            │
            └── AboutTimelineServiceTest.php
                # TYPE: unit_test
                # PURPOSE: Timeline ordering, validation, malformed-entry rejection and empty-state behavior.
```

---

# 10. VISUAL DESIGN DIRECTION

The About page should feel like a premium editorial experience.

Use the existing Home design system:

- dark luxury background
- gold accents
- transparent glass panels
- layered depth
- controlled blur
- premium typography
- subtle 3D shadows
- cinematic lighting
- soft reflections
- elegant transitions

But differentiate About visually from Home.

About should feel:

`EDITORIAL + HISTORICAL + PREMIUM + TRUST-CENTERED`

rather than:

`SALES-FIRST`

---

# 11. HERO SECTION

Hero structure:

```text
Breadcrumb
About Us
Short supporting statement
3D visual
Scroll indicator
```

Use a cinematic visual representing:

- lottery history
- numbered balls/tickets
- Thai-inspired visual motifs
- timeline/history
- modern digital lottery interface

DO NOT generate:

- fake government seal
- fake official building certification
- fake license
- fake government logo
- fake “official partner” badge

Any official-looking imagery must be removed unless explicitly provided as an approved asset.

---

# 12. HISTORY STORY SECTION

Present the historical material as a visually rich editorial timeline/story.

The current live page contains a long historical narrative beginning with the origins of lottery activity in Thailand and continuing through multiple historical periods.

Do NOT blindly dump a giant paragraph.

Transform approved source content into:

```text
Era
↓
Year / Buddhist Era
↓
Milestone
↓
Short explanation
↓
Visual marker
```

The source text must remain factually faithful.

Do not add unsupported historical claims.

---

# 13. TIMELINE ENGINE

Timeline data must come from:

`AboutTimelineService`

or an existing canonical public-content source.

Each timeline record should support:

```json
{
  "year": "....",
  "era": "....",
  "title": "....",
  "description": "....",
  "display_order": 1
}
```

The backend must validate:

- non-empty title
- valid display order
- valid year representation
- safe HTML/text rendering

Never allow malformed timeline records to break the entire page.

---

# 14. “HOW TO START” SECTION

The current live About page exposes:

`Choose`

`Buy`

`Win`

as the basic journey.

Design this as a premium 3-step 3D flow:

```text
01
CHOOSE

02
BUY

03
WIN
```

Each card must have:

- number
- icon/3D object
- title
- short description
- real CTA where applicable

Do not make “Win” a guaranteed outcome.

Use wording that communicates eligibility/chance rather than certainty.

---

# 15. VALUES SECTION

Create a values section only from approved application/business content.

Possible structural categories:

- transparency
- responsible gaming
- account security
- customer support
- reliable results
- payment clarity

These are content categories, not permission to claim certifications or government endorsement.

Any exact public claims should come from:

- approved config
- approved content source
- existing localized content
- verified product capability

---

# 16. TRUST SECTION

Trust section must distinguish:

### Platform capability

from

### Legal/institutional identity

For example:

“Encrypted account sessions” can be displayed only when that capability exists.

But:

“Government-owned”

“Government-operated”

“Official Government Lottery Office”

“Licensed by X”

must NOT be rendered unless an approved source in the project explicitly authorizes it.

Do not infer legal status from the current live website.

---

# 17. CTA SECTION

Bottom CTA should guide the user to real application destinations.

Possible CTA destinations:

- Lottery
- Latest Results
- Prize Verification
- Contact
- Register

Before using any CTA:

verify its route exists.

Never use:

`href="#"`

Never use fake routes.

---

# 18. REAL BACKEND CONTENT CONTRACT

If the project already has a public content source:

reuse it.

Otherwise create:

`AboutPageDataService`

with a structure similar to:

```php
[
    'title' => ...,
    'subtitle' => ...,
    'story' => ...,
    'timeline' => [...],
    'how_to_start' => [...],
    'values' => [...],
    'trust' => [...],
    'cta' => [...],
    'seo' => [...],
]
```

Every dynamic field must have an identifiable source.

---

# 19. API CONTRACT

Only create/use an API when it fits the existing frontend architecture.

Suggested endpoint:

`GET /api/v1/about`

Suggested response:

```json
{
  "success": true,
  "data": {
    "title": "...",
    "subtitle": "...",
    "story": [],
    "timeline": [],
    "how_to_start": [],
    "values": [],
    "trust": [],
    "cta": [],
    "seo": {}
  }
}
```

Do not expose:

- internal configuration secrets
- operator-only content
- admin notes
- private database fields
- audit metadata
- unpublished drafts

---

# 20. REAL-TIME API IS NOT REQUIRED FOR STATIC HISTORY

Do NOT poll the About API every minute merely to claim “real API integration”.

About historical content is not inherently live data.

Use:

- server-side rendering
- API retrieval where architecture requires it
- caching
- content versioning

Only use polling for content that is genuinely expected to change live.

---

# 21. CACHE

Public About content may be cached when appropriate.

Requirements:

- deterministic cache key
- sensible TTL
- invalidation when content changes if CMS/content management exists
- no stale sensitive/admin data
- no cache poisoning from request input

---

# 22. SEO

Implement:

- canonical URL
- page title
- meta description
- Open Graph
- appropriate article/content metadata
- semantic heading hierarchy
- structured data only when factual and supported

Do not generate fake Organization structured data.

Do not claim an official government identity merely because the current live page uses that branding.

---

# 23. ACCESSIBILITY

Verify:

- semantic headings
- keyboard timeline navigation
- focus states
- sufficient contrast
- alt text
- readable long-form text
- accessible timeline semantics
- reduced-motion support
- screen-reader meaningful milestone labels

Do not hide essential history only inside hover effects.

---

# 24. RESPONSIVE DESIGN

Desktop:

- split-screen hero
- wide cinematic timeline
- large visual milestones

Tablet:

- condensed timeline
- 2-column editorial sections

Mobile:

- vertical timeline
- stacked cards
- readable body text
- no horizontal overflow
- sticky/floating navigation only where it improves usability

---

# 25. PERFORMANCE

Optimize:

- historical images
- 3D assets
- fonts
- CSS
- JavaScript
- lazy loading
- content rendering

Do not ship a huge 3D library for a few visual effects.

Use CSS transforms and lightweight JS whenever practical.

---

# 26. SECURITY

Review all About content for:

- XSS
- unsafe HTML
- unescaped admin content
- unsafe URLs
- accidental secret exposure
- internal configuration leakage
- private metadata exposure

If rich text is supported:

sanitize it server-side before rendering.

---

# 27. DATA-INTEGRITY RULE

Never fabricate:

- company history
- license numbers
- registration numbers
- government affiliations
- office addresses
- executives
- certifications
- official partnerships

When approved business identity data is unavailable, display only neutral/public-safe copy.

---

# 28. LEGACY URL COMPATIBILITY

Because the current live ecosystem uses:

`/about.php`

the implementation must verify the repository's existing legacy bridge behavior.

Expected behavior must follow the project's canonical cutover policy.

Do not create a second redirect system.

Do not let an old `.php` bridge accidentally capture a real modern About route.

---

# 29. TEST MATRIX

At minimum verify:

### Public

- About page returns 200
- guest can view
- correct language
- correct canonical URL

### Content

- timeline ordered
- malformed timeline data rejected
- empty timeline handled
- no fabricated operator identity
- no fabricated license
- no unsafe HTML

### Localization

- EN works
- TH works
- key parity

### API

- API works when enabled
- response is valid JSON
- no private fields
- proper errors
- rate limiting according to project convention

### Legacy

- `/about.php` compatibility
- no duplicate route
- no redirect loop

### Frontend

- mobile
- tablet
- desktop
- reduced motion
- keyboard navigation

---

# 30. DEFINITION OF DONE

Page 02 is NOT complete until:

[ ] canonical About route verified
[ ] legacy `/about.php` behavior verified
[ ] About controller verified
[ ] About service verified
[ ] timeline service verified
[ ] approved history/content source verified
[ ] EN localization verified
[ ] TH localization verified
[ ] 3D/glass visual design complete
[ ] AI-quality visual asset strategy implemented
[ ] responsive layout verified
[ ] accessibility verified
[ ] SEO verified
[ ] error state verified
[ ] empty state verified
[ ] no fabricated identity
[ ] no fabricated legal claim
[ ] no fabricated license
[ ] no unsafe HTML
[ ] real CTA routes
[ ] tests created/updated
[ ] tests actually executed where runtime is available
[ ] no placeholder code
[ ] no truncated files
[ ] no duplicate architecture

---

# 31. FINAL OUTPUT REQUIRED

Return:

## A. IMPLEMENTATION SUMMARY

## B. COMPLETE FILE TREE

For every file:

```text
path/to/file
# TYPE: controller/service/view/model/test/config/etc.
# PURPOSE: exact implementation responsibility.
```

## C. COMPLETE MODIFIED FILE CONTENT

Every modified/created file in FULL.

## D. BACKEND DATA MAP

```text
UI Section
→ Controller
→ Service
→ Content Source
→ API
→ View
```

## E. API CONTRACT

Exact endpoint and response shape actually implemented.

## F. LIVE PAGE COMPARISON

Compare:

`Live /about.php`

vs

`New About page`

Clearly separate:

`OBSERVED`

`IMPLEMENTED`

`NOT VERIFIED`

`NOT COPIED BECAUSE UNSUPPORTED`

## G. TEST RESULTS

Exact commands and exact runtime outputs.

Never claim tests passed without execution.

## H. REMAINING BLOCKERS

Only real blockers.

## I. EXTERNAL INPUTS REQUIRED

Only actual external/operator requirements.

---

# 32. FINAL HONESTY RULE

Do not report:

`100% production ready`

merely because the visual design is complete.

The page must be assessed separately as:

`DESIGN COMPLETE`

`BACKEND COMPLETE`

`API COMPLETE`

`CONTENT VERIFIED`

`SECURITY VERIFIED`

`TEST VERIFIED`

`LIVE-COMPATIBILITY VERIFIED`

When runtime evidence is unavailable, mark it:

`NOT VERIFIED`

---

# 33. PAGE SCOPE

This prompt modifies ONLY:

`02. ABOUT US`

Do NOT redesign:

- Home
- Vision & Mission
- Terms
- Privacy
- Fees
- Account Verification
- Account Grade
- Prize Verification
- Discount
- How to Play
- FAQ
- Contact
- Download App

Shared components may be updated only when necessary for About integration, and all existing behavior must remain intact.

---

# 34. FINAL QUALITY BAR

The finished About page must feel like:

`PREMIUM DIGITAL LOTTERY BRAND`
+
`HISTORICAL EDITORIAL EXPERIENCE`
+
`TRUSTED INFORMATION ARCHIVE`
+
`MODERN 3D GLASS DESIGN`

while remaining factual, source-driven, accessible and technically connected to the real Laravel application.