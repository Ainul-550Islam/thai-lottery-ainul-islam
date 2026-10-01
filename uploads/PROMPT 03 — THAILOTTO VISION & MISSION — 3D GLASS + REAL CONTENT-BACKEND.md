# PROMPT 03 — THAILOTTO VISION & MISSION
# WORLD-CLASS 3D GLASS EDITORIAL DESIGN + AI-QUALITY VISUALS + REAL BACKEND CONTENT INTEGRATION
# PAGE 03 OF THE MASTER PAGE LIST

## ROLE

You are the senior product designer + Laravel/PHP architect + frontend engineer + content/API integration engineer.

Implement ONLY:

`03. VISION & MISSION`

This must be a real production page.

The page must visually continue the design language established by:

`01. Home`
`02. About Us`

while having a distinct:

`VISION + MISSION + VALUES + GOVERNANCE`

editorial experience.

---

# 1. PRIMARY OBJECTIVE

Create a premium Vision & Mission page that communicates:

- Vision
- Mission
- Core Values
- Governance / Trust principles
- Long-term direction
- Responsible platform philosophy

with:

- luxury 3D glass UI
- dark premium gold visual language
- cinematic AI-quality visual composition
- responsive layout
- accessibility
- EN/TH localization
- real backend-managed content
- real route integration
- SEO
- no fabricated institutional identity
- no fabricated licenses
- no fabricated government affiliation
- no fake certifications
- no static fake business claims

---

# 2. LIVE REFERENCE

Use the live page:

`https://www.thailotto.club/vision.php`

as an observable reference.

The live page currently exposes:

- `Vision`
- `พันธกิจ (Mission)`
- `ค่านิยมหลัก (CORE VALUE)`
- `CLEAR`
- `Collaboration`
- `Learning and growth`
- `Ethics`
- `Accountability`
- `Relationship`
- `Good Governance & Trust`

The live page also contains GLO-style institutional/operator language.

IMPORTANT:

Do NOT automatically copy those institutional claims into the new application.

Only use content that is:

- approved in the repository's public-content source
- explicitly configured for this application
- legally/business approved by the operator

The design must clearly avoid accidentally presenting the platform as a government office or government-operated entity unless that identity is explicitly verified and configured.

---

# 3. FIRST — INSPECT EXISTING SOURCE

Before modifying anything:

Inspect the actual repository.

Locate:

- current Vision route
- legacy `vision.php` route/bridge
- `PublicPagesController`
- `PublicPageDataService`
- existing public-page data structures
- current `resources/views/vision/`
- localization
- shared public layout
- shared components
- CSS entry points
- JS entry points
- SEO implementation
- existing About architecture
- existing content version/cache mechanism

Do NOT create a duplicate content architecture.

Reuse the existing canonical public-page architecture.

---

# 4. NON-NEGOTIABLE FILE RULE

For every created or modified file:

OUTPUT THE FULL FILE CONTENT.

NEVER use:

`# ... existing code ...`

`// ... existing code ...`

`/* existing code */`

`...`

`TODO`

`FIXME`

or truncated sections.

Preserve all valid existing logic.

Do not delete unrelated functionality.

Do not silently alter other public pages.

---

# 5. REQUIRED ARCHITECTURE

Prefer this architecture:

```text id="vxzqj8"
PublicPagesController
        ↓
PublicPageDataService
        ↓
Vision/Mission content source
        ↓
Vision page Blade components
```

If the project already contains a dedicated canonical service for this page, reuse it.

DO NOT create:

`VisionService2`

`NewVisionService`

`LegacyVisionService`

or another duplicate content system.

---

# 6. REQUIRED FILE TREE

Use actual repository paths.

```text id="gv6hx7"
app/
├── Http/
│   └── Controllers/
│       └── PublicPagesController.php
│       # TYPE: controller
│       # PURPOSE: Canonical public-page controller; retain existing About/Terms/etc. behavior and add/retain Vision page data orchestration through the existing public-page architecture.
│
└── Services/
    └── PublicPages/
        ├── PublicPageDataService.php
        │   # TYPE: service
        │   # PURPOSE: Canonical public content aggregation service; provide approved Vision/Mission/Values/Governance content without duplicating the existing About architecture.
        │
        └── VisionMissionDataService.php
            # TYPE: service
            # PURPOSE: Create ONLY when the repository lacks a clean canonical Vision/Mission data boundary; normalize Vision, Mission, Values and Governance content without inventing facts.
```

IMPORTANT:

If `VisionMissionDataService.php` is unnecessary because `PublicPageDataService` already handles this cleanly, DO NOT create it.

---

# 7. ROUTES

```text id="tf5m90"
routes/
└── web.php
# TYPE: routes
# PURPOSE: Canonical modern Vision route, preserving the existing route name and legacy bridge behavior; do not create duplicate routes or redirect loops.
```

Verify:

- modern Vision route
- legacy `/vision.php`
- 301 behavior
- no loop
- no accidental `.php` bridge shadowing

Based on the existing implementation architecture, the modern Vision view uses the canonical `vision` route name. Preserve that canonical naming.

---

# 8. LOCALIZATION

Use the existing public-page localization namespace if that is the canonical source.

Example:

```text id="m8kdq0"
lang/
├── en/
│   └── public_pages.php
│   # TYPE: localization
│   # PURPOSE: English Vision, Mission, Core Values, Governance, CTA and accessibility strings.
│
└── th/
    └── public_pages.php
    # TYPE: localization
    # PURPOSE: Thai Vision, Mission, Core Values, Governance, CTA and accessibility strings with exact key parity.
```

Do NOT create a second localization namespace if the repository already uses `public_pages.php`.

Verify:

- EN/TH key parity
- no raw translation keys
- no accidental English fallback in TH
- no missing labels
- no untranslated accessibility text

---

# 9. FRONTEND VIEW TREE

```text id="1w6ncb"
resources/
└── views/
    ├── vision/
    │   └── index.blade.php
    │       # TYPE: blade_view
    │       # PURPOSE: Canonical Vision & Mission page composition.
    │
    └── components/
        └── vision/
            ├── hero.blade.php
            │   # TYPE: blade_component
            │   # PURPOSE: Premium Vision/Mission hero with cinematic 3D visual composition.
            │
            ├── vision.blade.php
            │   # TYPE: blade_component
            │   # PURPOSE: Main Vision statement rendered from approved backend/public content.
            │
            ├── mission.blade.php
            │   # TYPE: blade_component
            │   # PURPOSE: Main Mission statement rendered from approved backend/public content.
            │
            ├── values.blade.php
            │   # TYPE: blade_component
            │   # PURPOSE: Core Values/CLEAR presentation with reusable value cards.
            │
            ├── governance.blade.php
            │   # TYPE: blade_component
            │   # PURPOSE: Governance/trust principles section using only approved factual platform content.
            │
            ├── journey.blade.php
            │   # TYPE: blade_component
            │   # PURPOSE: Long-term platform journey/direction visual; no fabricated milestones.
            │
            └── cta.blade.php
                # TYPE: blade_component
                # PURPOSE: Real CTA links to About, Results, Contact, Register or other verified routes.
```

Do not create unnecessary components.

Reuse shared components where appropriate.

---

# 10. FRONTEND CSS

```text id="tbp4x4"
resources/
└── css/
    └── pages/
        └── vision.css
        # TYPE: stylesheet
        # PURPOSE: Vision/Mission-specific premium 3D glass design, animated value cards, timeline/journey visuals, responsive layout, focus states and reduced-motion handling.
```

Do not duplicate Home's entire CSS.

Reuse global design tokens.

---

# 11. FRONTEND JAVASCRIPT

```text id="8g9bbx"
resources/
└── js/
    └── pages/
        └── vision.js
        # TYPE: javascript
        # PURPOSE: Lightweight Vision/Mission interactions, value-card transitions, accessible section navigation, scroll reveal and reduced-motion handling.
```

DO NOT add API polling.

Vision/Mission content is not inherently real-time.

Use server-side data or API retrieval only according to the existing architecture.

---

# 12. TESTS

```text id="rz6d5m"
tests/
├── Feature/
│   └── Vision/
│       ├── VisionPageTest.php
│       # TYPE: feature_test
│       # PURPOSE: Vision route/render/public access/canonical response verification.
│
│       ├── VisionContentIntegrityTest.php
│       # TYPE: feature_test
│       # PURPOSE: Verify approved-content sourcing and prevent fabricated institutional/legal claims.
│
│       ├── VisionLocalizationTest.php
│       # TYPE: feature_test
│       # PURPOSE: EN/TH translation parity and rendering checks.
│       │
│       └── VisionLegacyRouteTest.php
│           # TYPE: feature_test
│           # PURPOSE: Verify legacy /vision.php compatibility and 301 behavior without loops or route collisions.
│
└── Unit/
    └── Services/
        └── PublicPages/
            └── VisionMissionDataServiceTest.php
            # TYPE: unit_test
            # PURPOSE: Validate Vision/Mission/Values/Governance normalization and fail-safe handling when dedicated service exists.
```

If the existing `PublicPageDataService` is sufficient and no new service is created:

extend the appropriate existing service test instead of creating an unnecessary test class.

---

# 13. VISUAL DESIGN LANGUAGE

The page should feel like a high-end strategic brand manifesto.

Design direction:

`DARK LUXURY`
+
`3D GLASS`
+
`GOLD`
+
`CINEMATIC LIGHT`
+
`FUTURE TECHNOLOGY`
+
`EDITORIAL TYPOGRAPHY`

Use:

- deep dark background
- transparent glass
- soft reflections
- layered depth
- gold edge lighting
- subtle 3D spheres
- glowing geometric forms
- premium typography
- restrained particle effects
- soft gradients
- spatial composition

Avoid:

- generic corporate template
- flat white business page
- excessive neon
- childish lottery graphics
- cheap casino styling
- huge animated clutter

---

# 14. AI-QUALITY HERO VISUAL

The hero should look comparable to a professionally generated AI marketing image.

Suggested visual concept:

A central translucent glass sphere/orb representing the future.

Inside or around it:

- six-digit lottery balls
- connected data lines
- subtle Thai-inspired geometric pattern
- premium gold circuitry
- floating glass cards
- soft volumetric light
- abstract digital horizon

Theme:

`VISION OF THE FUTURE`

DO NOT include:

- fake government seal
- fake license
- fake official certificate
- fake government logo
- fake state institution building
- fabricated official partnership

The visual is conceptual, not documentary.

---

# 15. HERO STRUCTURE

```text id="28z30x"
Eyebrow
VISION & MISSION

Main headline
A clear future-facing statement

Supporting paragraph

Primary CTA
Explore Our Platform

Secondary CTA
View Results

3D Hero Visual

Scroll Indicator
```

All CTA destinations must be real routes.

Never use:

`href="#"`

---

# 16. VISION SECTION

Create a visually dominant Vision section.

Structure:

```text id="vvk2vn"
VISION

Large statement
Supporting explanation
Three visual principles
```

The actual statement must come from approved project content.

Do NOT silently replace the source content with marketing copy that changes its meaning.

You may improve:

- visual hierarchy
- sentence segmentation
- presentation
- spacing
- cards

but do not invent factual claims.

---

# 17. MISSION SECTION

Create a visually contrasting Mission section.

Recommended structure:

```text id="53jzry"
MISSION

01
Service / Customer Experience

02
Technology / Innovation

03
Responsible Growth

04
Public / Social Responsibility
```

Only show a principle when it is supported by approved source content.

The live page's Mission describes production/sales/draw/prize operations, continued new business forms, state revenue, public benefit, organizational and technology development, and governance.

Because those are live-page statements, do NOT automatically rewrite them as this site's own institutional claims.

---

# 18. CORE VALUES — CLEAR

The current live page presents:

`CLEAR`

with:

- Collaboration
- Learning and Growth
- Ethics
- Accountability
- Relationship

Present the values as a premium 5-card 3D system:

```text id="f22x4a"
C
COLLABORATION

L
LEARNING & GROWTH

E
ETHICS

A
ACCOUNTABILITY

R
RELATIONSHIP
```

Each card must contain:

- abbreviation
- title
- short description
- icon
- visual accent
- accessibility label

Do not modify source meaning without approved editorial content.

---

# 19. VALUES VISUAL SYSTEM

Create five floating glass value cards.

Interaction:

- desktop hover elevation
- mobile tap/focus
- active state
- keyboard focus
- reduced-motion fallback

Use restrained 3D geometry.

Example visual representations:

C → connected nodes

L → rising geometric staircase

E → balanced geometric structure

A → protected/checkmarked structure

R → interconnected rings

Do NOT create fake organizational badges or certifications.

---

# 20. GOVERNANCE & TRUST

The live page includes:

`GOOD GOVERNANCE & TRUST`

and describes transparency and public trust.

For our implementation:

Only render governance claims supported by approved project content.

Possible visual:

large transparent glass shield
+
five surrounding principles
+
subtle data-grid background

Potential factual categories:

- transparency
- accountability
- secure operations
- responsible gaming
- auditability

Only use categories that correspond to actual platform behavior/configuration.

---

# 21. PLATFORM-DIRECTION SECTION

Create a forward-looking “Where We’re Going” visual section.

DO NOT invent:

- launch dates
- future products
- government partnerships
- office expansion
- customer numbers
- investment amounts
- licenses
- certifications

Instead use a content-driven roadmap.

Supported structure:

```text id="dk9hgl"
NOW
Current platform

NEXT
Approved planned improvements

FUTURE
Configured long-term direction
```

When no approved roadmap data exists:

render a concise neutral statement rather than fake milestones.

---

# 22. CONTENT DATA CONTRACT

The backend data should support a structure like:

```php id="1njpdy"
[
    'vision' => [
        'title' => ...,
        'body' => ...,
        'principles' => [...],
    ],

    'mission' => [
        'title' => ...,
        'body' => ...,
        'principles' => [...],
    ],

    'values' => [
        [
            'key' => 'C',
            'title' => ...,
            'description' => ...,
        ],
        [
            'key' => 'L',
            'title' => ...,
            'description' => ...,
        ],
        [
            'key' => 'E',
            'title' => ...,
            'description' => ...,
        ],
        [
            'key' => 'A',
            'title' => ...,
            'description' => ...,
        ],
        [
            'key' => 'R',
            'title' => ...,
            'description' => ...,
        ],
    ],

    'governance' => [
        'title' => ...,
        'body' => ...,
        'principles' => [...],
    ],

    'cta' => [...],

    'seo' => [...],
]
```

Use existing repository contracts when available.

Do not force this exact structure if the project's canonical data model differs.

---

# 23. API RULE

A dedicated Vision API is NOT automatically required.

Do NOT create an API merely to satisfy the phrase “real API”.

Because Vision/Mission is primarily editorial/static public content, server-side rendering is acceptable.

Use API only when:

- existing frontend architecture requires it
- CMS content is already API-driven
- page content is managed asynchronously

Otherwise:

`PublicPagesController → PublicPageDataService → Blade`

is sufficient.

---

# 24. CACHING

If the existing PublicPages content architecture uses cache/versioning:

reuse it.

Do NOT introduce a second cache mechanism.

When content changes, invalidate the correct canonical cache.

Do not allow stale cached content to survive a configured content-version update.

---

# 25. SECURITY

Vision page is public.

Verify:

- XSS safety
- escaped content
- safe URLs
- no secret leakage
- no admin-only content
- no unpublished content
- no environment variable exposure
- no private metadata

If rich text comes from admin/CMS:

sanitize it appropriately before rendering.

---

# 26. LEGAL / IDENTITY SAFETY

Do not render as facts unless verified by approved source:

- “Government Office”
- “Government Owned”
- “Official Government Platform”
- “Government Authorized”
- “Government Licensed”
- “Official Partner”
- license number
- certification number
- regulatory approval
- government address
- official seal

The live page contains such institutional presentation, but this is an observable live-site claim, not independent proof of the new application's legal identity.

Use neutral platform language when approved identity information is unavailable.

---

# 27. ACCESSIBILITY

Verify:

- semantic `h1`
- `h2` hierarchy
- keyboard navigation
- keyboard activation
- visible focus
- ARIA labels where needed
- sufficient contrast
- screen-reader-readable value cards
- reduced-motion support
- no text hidden only behind animation
- mobile readability

---

# 28. RESPONSIVE DESIGN

## Desktop

Hero split composition.

Vision and Mission alternating layouts.

Five value cards in a premium 5-column or responsive grid.

Governance visual centerpiece.

## Tablet

2-column value system.

Compressed hero.

Readable content widths.

## Mobile

Single-column flow:

Hero
→ Vision
→ Mission
→ CLEAR
→ Governance
→ Journey
→ CTA

No horizontal overflow.

---

# 29. MOTION SYSTEM

Use restrained motion:

- floating orb
- card elevation
- soft light movement
- timeline/roadmap reveal
- value-card focus
- section reveal

Respect:

`prefers-reduced-motion: reduce`

When reduced motion is enabled:

- remove parallax
- remove looping decorative motion
- preserve content and interaction

---

# 30. PERFORMANCE

Do not load a heavy 3D framework unless the repository already requires it.

Prefer:

- CSS 3D
- transforms
- gradients
- SVG
- optimized image assets
- lazy loading
- lightweight JS

No blocking hero animation.

No giant uncompressed image.

---

# 31. SEO

Implement:

- title
- meta description
- canonical URL
- OG title
- OG description
- OG image where available
- semantic heading structure
- breadcrumb support where existing architecture provides it

Do not create fake Organization structured data.

Do not inject unsupported legal identity into structured data.

---

# 32. LEGACY COMPATIBILITY

Verify:

`/vision.php`

against the canonical modern Vision route.

Must ensure:

- correct 301 target
- no redirect loop
- no duplicate rendering
- no `.php` page accidentally remaining active when bridge policy expects redirect
- modern route remains reachable directly

Use the existing legacy bridge architecture.

Do NOT create a second redirect controller/system.

---

# 33. TEST REQUIREMENTS

At minimum:

### Route

- modern Vision route returns 200
- legacy `/vision.php` follows expected redirect
- no redirect loop

### Content

- Vision renders
- Mission renders
- CLEAR renders
- Governance renders when configured
- empty content fails safely
- malformed content does not crash page

### Identity

- no fabricated government claim
- no fake license
- no fake certification
- no fake partnership
- no unsupported operator identity

### Localization

- EN works
- TH works
- exact translation key parity
- no raw translation keys

### Frontend

- responsive markup
- accessibility semantics
- reduced motion
- keyboard interaction

### SEO

- canonical
- title
- meta
- headings

---

# 34. LIVE PAGE COMPARISON MATRIX

Produce:

| Section | Live `/vision.php` | Existing Source | New Design | Status |
|---|---|---|---|---|
| Vision | observed | source | implemented | ... |
| Mission | observed | source | implemented | ... |
| CLEAR | observed | source | implemented | ... |
| Collaboration | observed | source | implemented | ... |
| Learning & Growth | observed | source | implemented | ... |
| Ethics | observed | source | implemented | ... |
| Accountability | observed | source | implemented | ... |
| Relationship | observed | source | implemented | ... |
| Governance & Trust | observed | source | implemented | ... |
| Useful Links | observed | source | implemented | ... |

Do not mark `source verified` merely because it exists on the live website.

---

# 35. NO STATIC FAKE BUSINESS DATA

Never hardcode:

```php id="bz7rpj"
$customers = 250000;
$years = 20;
$partners = 40;
$licenses = 12;
```

unless those values are genuinely approved configuration/content.

Never fabricate a future roadmap.

Never fabricate institutional success metrics.

---

# 36. SHARED FILE SAFETY

If modifying:

- `PublicPagesController.php`
- `PublicPageDataService.php`
- `public_pages.php`
- shared layout
- shared navigation
- Vite config

preserve all existing:

- About behavior
- Home behavior
- Terms behavior
- Fees behavior
- Contact behavior
- localization
- cache behavior

No regression.

---

# 37. DEFINITION OF DONE

Page 03 is NOT complete until:

[ ] modern Vision route verified
[ ] legacy vision.php verified
[ ] PublicPagesController integration verified
[ ] PublicPageDataService integration verified
[ ] no duplicate service architecture
[ ] Vision content source verified
[ ] Mission content source verified
[ ] CLEAR values verified
[ ] Governance content verified
[ ] EN localization verified
[ ] TH localization verified
[ ] 3D glass design complete
[ ] AI-quality visual composition complete
[ ] responsive desktop complete
[ ] responsive tablet complete
[ ] responsive mobile complete
[ ] accessibility complete
[ ] reduced-motion complete
[ ] SEO complete
[ ] security review complete
[ ] no fabricated identity
[ ] no fake licenses
[ ] no fake certifications
[ ] no fake government affiliation
[ ] no fake metrics
[ ] no fake roadmap
[ ] real CTA routes
[ ] tests created/updated
[ ] tests actually executed where runtime permits
[ ] no placeholder code
[ ] no truncated files
[ ] no duplicate architecture

---

# 38. FINAL OUTPUT REQUIRED

Return:

## A. IMPLEMENTATION SUMMARY

## B. COMPLETE FILE TREE

Every file with:

```text id="nqth6k"
path/to/file
# TYPE: controller/service/view/component/css/js/test/config/etc.
# PURPOSE: exact responsibility.
```

## C. COMPLETE MODIFIED FILE CONTENT

Every modified/created file in FULL.

No truncation.

## D. BACKEND DATA MAP

```text id="fh8j9x"
UI
→ Controller
→ PublicPageDataService
→ Content Source
→ View
```

## E. LIVE COMPARISON

Compare current:

`/vision.php`

against the implemented modern page.

Clearly separate:

`OBSERVED LIVE`

`SOURCE VERIFIED`

`IMPLEMENTED`

`NOT VERIFIED`

## F. TEST RESULTS

Exact commands + actual output.

Never claim tests passed without execution.

## G. REMAINING BLOCKERS

Only real blockers.

## H. EXTERNAL INPUTS

Only actual operator/content/legal inputs still required.

---

# 39. FINAL HONESTY RULE

Do NOT report:

`100% production ready`

merely because the page looks complete.

Report independently:

`DESIGN STATUS`

`CONTENT STATUS`

`BACKEND STATUS`

`ROUTE STATUS`

`LOCALIZATION STATUS`

`SECURITY STATUS`

`TEST STATUS`

`LIVE COMPATIBILITY STATUS`

When runtime cannot be executed:

`NOT VERIFIED — ENVIRONMENT LIMITATION`

---

# 40. PAGE SCOPE

THIS PROMPT IMPLEMENTS ONLY:

`03. VISION & MISSION`

Do NOT redesign:

- Home
- About Us
- Terms & Conditions
- Privacy Policy
- Our Fees
- Account Verification
- Account Grade
- Prize Verification
- Lotto Discount
- How to Play
- FAQ
- Contact Us
- Download App

Shared files may only be modified when necessary for this page.

Preserve all other page behavior.

---

# 41. FINAL QUALITY BAR

The final page should feel like:

`STRATEGIC BRAND MANIFESTO`
+
`PREMIUM DIGITAL LOTTERY`
+
`3D GLASS EXPERIENCE`
+
`FUTURE TECHNOLOGY`
+
`CLEAR VALUES`
+
`RESPONSIBLE TRUST`

while remaining source-driven, technically correct, accessible, and free from unsupported institutional claims.