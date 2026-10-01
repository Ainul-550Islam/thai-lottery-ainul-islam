# PROMPT 01 — THAILOTTO HOME / LANDING PAGE
# WORLD-CLASS 3D GLASS UI + AI-QUALITY VISUAL DESIGN + REAL API/BACKEND INTEGRATION
# PAGE 01 OF THE MASTER PAGE LIST

## ROLE

You are the senior product designer + Laravel/PHP architect + frontend engineer + API integration engineer.

Implement ONLY:

`01. HOME / LANDING PAGE`

This must be a real production page, not a static mockup.

The finished Home page must look like a premium modern lottery/iGaming product with:

- cinematic visual hierarchy
- high-end 3D/glassmorphism UI
- premium AI-generated-quality hero imagery
- realistic depth
- controlled glow
- subtle reflections
- modern typography
- polished cards
- premium number presentation
- responsive behavior
- accessible interactions
- real backend data
- real API data
- real loading/error/empty states
- no fake production numbers
- no hardcoded dynamic lottery results
- no fake payment confirmation
- no fake jackpot/winner data
- no placeholder text left in production UI

IMPORTANT:

This is a FUNCTIONAL production Home page.

Do not build a screenshot-only frontend.

Every dynamic section must connect to an actual backend source.

---

# 1. FIRST — INSPECT THE EXISTING SOURCE

Before changing anything, inspect the existing repository and locate the CURRENT actual files responsible for:

- home route
- home controller
- home view
- main layout
- navigation
- footer
- lottery/result services
- result models
- draw models
- API routes
- API controllers
- localization
- app-link service
- configuration
- authentication state
- wallet summary
- notification summary
- existing design system/components

Do not create duplicate controllers/services/components when an existing canonical implementation already exists.

Reuse the existing architecture where correct.

If an existing file already performs the required responsibility, MODIFY THAT FILE instead of creating a parallel implementation.

If a required backend capability genuinely does not exist, CREATE the missing backend file/service/controller/test and wire it into the canonical architecture.

---

# 2. NON-NEGOTIABLE FILE RULE

For EVERY file changed or created:

Provide the COMPLETE FILE CONTENT.

NEVER use:

`# ... existing code ...`

`// ... existing code ...`

`/* existing code */`

`...`

`TODO`

`FIXME`

or shortened/truncated content.

Preserve all valid existing logic.

Do not delete unrelated functionality.

Do not silently change existing business rules.

---

# 3. REQUIRED FILE/RESPONSIBILITY TREE

Use the actual repository paths discovered during inspection.

The following tree defines the REQUIRED responsibilities.

If the repository uses a different canonical filename, use the actual existing path and report the mapping.

## BACKEND

```text
app/
├── Http/
│   ├── Controllers/
│   │   ├── HomeController.php
│   │   # CODING AGENT: canonical public Home page controller; aggregate only production-safe data required by the Home page; no duplicated business logic; delegate lottery/result/countdown/business logic to services.
│   │
│   │   └── Api/
│   │       └── V1/
│   │           └── HomeController.php
│   │           # CODING AGENT: canonical Home API endpoint/controller if the project architecture uses a dedicated API controller; return structured JSON for Home widgets and never expose sensitive player/payment data.
│   │
│   └── Requests/
│       # CODING AGENT: only create Home-specific FormRequest files when actual Home interactions require validated input; do not create unnecessary request classes.
│
├── Services/
│   ├── Home/
│   │   ├── HomePageDataService.php
│   │   # CODING AGENT: canonical Home-page orchestration service; compose public-safe lottery, result, draw, countdown, promotional, app-link and feature data from existing services; no direct duplication of financial/domain algorithms.
│   │   ├── HomeLotteryFeedService.php
│   │   # CODING AGENT: fetch live/next lottery products and public availability from canonical lottery services/models; fail safely when data is unavailable.
│   │   ├── HomeResultFeedService.php
│   │   # CODING AGENT: provide latest public results from canonical result services; preserve leading zeros and exact draw metadata.
│   │   └── HomeCountdownService.php
│   │   # CODING AGENT: calculate next eligible draw countdown using authoritative draw timestamps/timezone; never calculate from frontend hardcoded dates.
│   │
│   └── PublicAppLinkService.php
│   # CODING AGENT: reuse existing canonical service for safe app-store/app-download URLs; do not duplicate URL sanitization logic.
│
├── Models/
│   # CODING AGENT: reuse existing Lottery/Draw/Result/Promotion models; create a new model ONLY if a required persisted Home entity genuinely does not exist.
│
└── Providers/
    # CODING AGENT: preserve existing application bindings; register new Home services only when the repository's architecture requires explicit bindings.
```

## ROUTES

```text
routes/
├── web.php
# CODING AGENT: wire the canonical Home GET route to the existing/new Home controller; preserve all existing routes and middleware; do not create duplicate `/` routes.

└── api.php
# CODING AGENT: add the Home API route only when required by the existing frontend architecture; use versioned API conventions and existing middleware/rate limits.
```

## CONFIGURATION

```text
config/
├── app.php
# CODING AGENT: preserve canonical application URL/timezone/environment configuration; do not hardcode production URLs.
├── lottery.php
# CODING AGENT: reuse existing lottery configuration/source of truth; do not introduce conflicting ticket/prize definitions.
├── glo.php
# CODING AGENT: reuse existing GLO configuration if Home displays GLO information; do not invent values.
└── payment.php
# CODING AGENT: only expose payment METHOD availability/status information that is already authorized for public display; never expose credentials or internal provider secrets.
```

## LOCALIZATION

```text
lang/
├── en/
│   └── home.php
│   # CODING AGENT: all Home page English UI strings; use translation keys, never hardcode user-facing English inside Blade/JS when localization is expected.
│
└── th/
    └── home.php
    # CODING AGENT: complete Thai counterpart with identical key coverage; no English fallback accidentally visible in Thai mode.
```

## FRONTEND VIEWS

```text
resources/
└── views/
    ├── home.blade.php
    # CODING AGENT: canonical Home page composition; keep business logic out of Blade; render real backend-provided data; implement complete SEO, accessibility, loading, empty and error states.

    ├── components/
    │   └── home/
    │       ├── hero.blade.php
    │       # CODING AGENT: premium 3D/glass Hero section with AI-quality visual composition; CTA buttons must point to real routes/auth flows.
    │
    │       ├── next-draw.blade.php
    │       # CODING AGENT: authoritative next-draw countdown UI; consume backend draw timestamp and preserve timezone correctness.
    │
    │       ├── lottery-cards.blade.php
    │       # CODING AGENT: dynamic lottery-category cards sourced from actual backend products/config; no fake categories or hardcoded availability.
    │
    │       ├── latest-results.blade.php
    │       # CODING AGENT: dynamic latest-result cards/table sourced from canonical result data; preserve leading zeros and draw dates.
    │
    │       ├── prize-highlight.blade.php
    │       # CODING AGENT: display only verified/public prize information from the backend; no fabricated jackpot/winner values.
    │
    │       ├── trust-security.blade.php
    │       # CODING AGENT: security/trust feature presentation; copy must be factual and configuration/content driven where claims are business/legal sensitive.
    │
    │       ├── bonus-section.blade.php
    │       # CODING AGENT: display only real configured bonus/offer information; do not invent percentages, monetary amounts, or eligibility.
    │
    │       ├── payment-methods.blade.php
    │       # CODING AGENT: show only payment methods currently configured/advertisable; use backend capability/configuration source.
    │
    │       ├── app-download.blade.php
    │       # CODING AGENT: use PublicAppLinkService or canonical app-link source; fail closed when links are absent/invalid.
    │       │
    │       └── footer.blade.php
    │       # CODING AGENT: complete footer with real navigation/legal/contact links; no fabricated operator identity or address.
    │
    └── layouts/
        # CODING AGENT: reuse the canonical existing public layout; do not create a duplicate application shell.
```

## FRONTEND ASSETS

```text
resources/
├── css/
│   └── pages/
│       └── home.css
│       # CODING AGENT: Home-only visual system including 3D depth, glass surfaces, responsive grids, animations, reduced-motion behavior, hover/focus states and mobile layout.
│
└── js/
    └── pages/
        └── home.js
        # CODING AGENT: Home runtime interactions, API fetching where architecture requires it, countdown synchronization, carousel behavior, lazy loading, retry/error behavior and accessible interactions.
```

## TESTS

```text
tests/
├── Feature/
│   ├── Home/
│   │   ├── HomePageTest.php
│   │   # CODING AGENT: Home route/render/auth-state/locale/basic data contract tests.
│   │
│   │   ├── HomeApiTest.php
│   │   # CODING AGENT: API response schema/auth/rate-limit/error/empty-state tests when Home API exists.
│   │
│   │   ├── HomeDataIntegrityTest.php
│   │   # CODING AGENT: verify Home cannot render fabricated or malformed public lottery/result data.
│   │   │
│   │   └── HomeLocalizationTest.php
│   │   # CODING AGENT: verify EN/TH symmetry and absence of raw translation keys.
│   │
│   └── SEO/
│       # CODING AGENT: reuse/extend existing SEO tests for Home canonical URL/title/meta/structured data.
│
└── Unit/
    └── Services/
        └── Home/
            ├── HomePageDataServiceTest.php
            # CODING AGENT: unit tests for Home aggregation/service behavior.
            └── HomeCountdownServiceTest.php
            # CODING AGENT: exact countdown/timezone/boundary tests.
```

---

# 4. HOME PAGE VISUAL DIRECTION

Build the Home page as a premium 2026-level product interface.

DESIGN LANGUAGE:

- dark luxury background
- deep spatial gradient
- translucent glass panels
- frosted glass
- controlled blur
- layered depth
- subtle 3D shadows
- soft edge lighting
- premium metallic details
- high contrast numbers
- cinematic hero composition
- realistic lottery-ball/ticket elements
- floating glass cards
- premium micro-animations
- subtle parallax
- responsive motion
- strong mobile composition

Do NOT make it look like:

- a generic Bootstrap website
- a plain admin dashboard
- a basic casino template
- a flat landing page
- an over-glowing neon page
- a copy of another brand

The design should feel like a real premium digital lottery product.

---

# 5. AI-QUALITY HERO VISUAL

Hero must contain a premium visual composition comparable to a professionally generated AI marketing image.

Use one of these approaches depending on the project asset pipeline:

1. existing approved image asset
2. generated production-safe image asset
3. CSS/HTML 3D composition
4. SVG/3D illustration pipeline already present

Do NOT hotlink random third-party images.

Do NOT use copyrighted brand imagery without authorization.

Do NOT create fake government seals, fake official certificates, or fabricated official affiliation.

Hero imagery should communicate:

- lottery
- numbers
- winning/result anticipation
- premium digital experience
- trust
- modern technology

Recommended visual composition:

large transparent lottery sphere / floating numbered balls
+
premium ticket/card
+
soft volumetric lighting
+
glass layers
+
depth blur
+
floating numeric particles
+
subtle gold/silver accent material

The visual must remain performant on desktop and mobile.

---

# 6. HERO CONTENT

Hero structure:

```text
HOME HERO

Eyebrow / category
Main headline
Supporting text
Primary CTA
Secondary CTA
Visual illustration
Trust microcopy
Live/next draw indicator
```

Primary CTA must resolve to a real existing lottery/buy flow.

Secondary CTA must resolve to a real results/explore flow.

NEVER use:

`href="#"`

for production CTAs.

---

# 7. NEXT DRAW EXPERIENCE

Create a premium “Next Draw” module.

Display:

- lottery name
- draw date
- draw timezone
- countdown
- status
- available action
- draw-detail link

Countdown must be derived from the backend's authoritative timestamp.

Frontend may animate the countdown but must NOT be the source of truth.

Handle:

- already drawn
- expired
- no next draw
- malformed timestamp
- API unavailable
- loading state

---

# 8. LOTTERY CATEGORY SECTION

Create premium 3D cards for the available lottery products.

Each card should support:

- logo/icon
- lottery name
- category
- next draw
- status
- price/entry information ONLY if the backend marks it publicly displayable
- CTA
- result link
- subtle 3D hover

Possible existing categories may include:

- National Lottery
- Weekly Lottery
- Mega Lottery
- PCSO Lottery
- GLO L6

Do not assume every product is active.

Render ONLY backend-authorized products.

---

# 9. LIVE / LATEST RESULTS

Create a premium results section.

Each result card should show:

- lottery name
- draw date
- primary winning number
- other public result categories when available
- result status
- link to full result
- link to history

Important:

preserve leading zeroes.

Examples:

`004615`

must NEVER become:

`4615`

No result may be generated by frontend JavaScript.

No mock result may appear when the database/API is empty.

---

# 10. PRIZE HIGHLIGHTS

Create a visual prize section.

Possible presentation:

large prize figure
+
floating ticket cards
+
winner-related aggregate metrics
+
prize categories

But only render data that actually exists.

Do not invent:

- winners
- jackpot totals
- number of winners
- payouts
- prize pool
- guaranteed prizes

When a backend value is unavailable, show an honest empty/availability state.

---

# 11. TRUST + SECURITY SECTION

Create premium feature cards for factual platform capabilities.

Examples:

- Secure transactions
- Account protection
- Verified result process
- Support
- Responsible gaming

Important:

Do not claim:

“100% secure”

“government guaranteed”

“official government lottery”

“instant guaranteed payout”

unless the backend/legal source of truth explicitly authorizes that exact claim.

The current live site contains strong security/payout language, but our implementation must not convert externally observed marketing claims into unverified legal/business assertions.

---

# 12. BONUS SECTION

Create a high-end bonus/offer section.

Possible cards:

- Referral Bonus
- Current Offer
- Affiliate Commission

These categories are visible on the current live homepage.

BUT:

All amounts/rates/eligibility must come from the actual backend/configuration.

No invented numbers.

If no active offer exists:

show a truthful “No active offers” state.

---

# 13. PAYMENT METHODS

Create a premium payment-method strip/cards.

Show only methods that are:

- configured
- enabled
- publicly advertizable
- supported for the relevant user flow

Do not expose:

- API keys
- merchant IDs
- webhook secrets
- provider internal status
- private bank credentials
- internal failure reasons

The payment capability layer already has a fail-closed contract; reuse it instead of creating a second capability matrix.

---

# 14. APP DOWNLOAD

Build the app-download section.

Requirements:

- iOS link
- Android link
- QR code when a real destination exists
- mobile CTA

Use existing `PublicAppLinkService`.

Invalid:

`javascript:`

`data:`

blank

whitespace-only

URLs

must never render as production links.

The existing audit already requires app links to fail closed when not configured.

---

# 15. FOOTER

Footer must contain real links.

Sections:

```text
Lottery
Results
Account
Support
Legal
Company
Apps
Social
```

All URLs must resolve to actual routes.

Footer must not fabricate:

- operator identity
- government affiliation
- address
- license number
- registration number
- phone number
- legal entity

Use approved configuration/content source.

---

# 16. AUTH-AWARE HOME

Home must behave correctly for both:

guest

and

authenticated user.

Guest:

- Login
- Register
- Explore lottery
- View results

Authenticated:

- Dashboard
- Wallet summary where appropriate
- My Tickets
- Notifications
- Account

Do not expose private wallet/bet/KYC data in public HTML to guests.

Do not solve auth-aware rendering by duplicating business logic.

---

# 17. REAL API CONTRACT

If the frontend uses an API, define/use a stable response shape similar to:

```json
{
  "data": {
    "hero": {},
    "next_draw": {},
    "lotteries": [],
    "latest_results": [],
    "prizes": [],
    "bonuses": [],
    "payment_methods": [],
    "app_links": []
  },
  "meta": {
    "generated_at": "...",
    "timezone": "Asia/Bangkok"
  }
}
```

Use the project's real response conventions if they already exist.

Do NOT invent a parallel API architecture.

Every field must correspond to an actual backend source.

---

# 18. BACKEND CREATION RULE

If Home needs a backend service that does not exist:

CREATE IT.

Examples:

- HomePageDataService
- HomeLotteryFeedService
- HomeResultFeedService
- HomeCountdownService
- Home API controller
- Home-specific tests

But before creating anything:

search the repository for equivalent existing functionality.

DO NOT create:

`HomeService2`

`NewHomeService`

`LegacyHomeService`

or duplicate business logic.

There must be one canonical source for each responsibility.

---

# 19. NO STATIC FAKE DATA

Reject implementation containing hardcoded production-style dynamic values such as:

```php
$jackpot = 12000000;
$winners = 4;
$nextDraw = '2026-...';
$latestResult = '123456';
```

unless those values are explicitly constants/business configuration with a documented reason.

Dynamic lottery/result data must come from the real application source.

---

# 20. ERROR / EMPTY / LOADING STATES

Every dynamic Home section must have:

### Loading

premium skeleton/glass shimmer.

### Empty

honest empty-state message.

### API failure

non-destructive retry state.

### Partial failure

other independent sections remain usable.

Example:

results API fails

BUT

hero and static navigation still render.

Do not allow one failed widget to blank the entire Home page.

---

# 21. PERFORMANCE

Implement:

- lazy loading below the fold
- image optimization
- responsive image sizes
- modern formats where supported
- no unnecessary JavaScript
- no blocking large animation library unless already required
- API caching where appropriate
- backend query efficiency
- no N+1 queries
- no repeated result queries per card

The page must remain performant on:

- desktop
- tablet
- mobile
- slower mobile networks

---

# 22. ACCESSIBILITY

Verify:

- semantic headings
- keyboard navigation
- visible focus
- proper labels
- button semantics
- alt text
- contrast
- reduced-motion support
- accessible countdown representation
- screen-reader-friendly result numbers

Do not make critical information dependent on animation alone.

---

# 23. RESPONSIVE DESIGN

Design and test:

### Desktop
wide cinematic hero + multi-column cards.

### Laptop
balanced hero + reduced visual density.

### Tablet
2-column content strategy.

### Mobile
single-column premium stacked layout.

Mobile must NOT be a compressed desktop version.

Do not allow:

- horizontal page overflow
- clipped buttons
- unreadable result numbers
- oversized hero image
- broken glass cards
- overlapping countdown text

---

# 24. MICRO-INTERACTIONS

Use restrained motion:

- card hover elevation
- glass reflection movement
- number glow
- subtle floating elements
- button depth
- countdown pulse
- section reveal

Do not animate every element.

Respect:

`prefers-reduced-motion`.

---

# 25. SEO

Home must have:

- canonical URL
- title
- meta description
- Open Graph
- Twitter card where supported
- semantic structure
- valid headings
- JSON-LD only for factual supported entities/data
- no fabricated organization claims

Do not invent structured data fields.

---

# 26. SECURITY

Review Home for:

- XSS
- unsafe HTML
- unsanitized API rendering
- unsafe URL output
- private data leakage
- authentication-state leakage
- CSRF requirements for any state-changing action
- secret leakage
- debug information
- exception leakage

Home is public.

Assume an attacker can inspect every response.

---

# 27. DATABASE QUERY RULE

The Home page must not directly embed complex domain/business logic in Blade.

Use:

Controller
→ Service
→ Repository/model/query layer where appropriate
→ View/API resource

Do not query the same database records repeatedly from independent Home sections.

Aggregate efficiently.

---

# 28. TEST CASES

At minimum create/extend tests for:

1. guest Home loads
2. authenticated Home loads
3. English Home loads
4. Thai Home loads
5. next draw renders correctly
6. countdown boundary
7. timezone handling
8. result leading-zero preservation
9. empty results state
10. result service failure
11. invalid app-link filtering
12. payment method visibility
13. no private data leakage
14. CTA routes resolve
15. footer routes resolve
16. API schema
17. API unauthorized/private-field protection
18. API failure response
19. Home has no fabricated dynamic values
20. responsive-critical markup where test architecture permits

---

# 29. LIVE SITE COMPARISON REQUIREMENT

Compare the finished Home page against:

`https://thailotto.club/`

Document:

### KEEP / PARITY

Real information architecture/features that are useful and applicable.

### IMPROVE

Visual hierarchy, responsiveness, accessibility, clarity and interaction.

### DO NOT COPY BLINDLY

Unverified legal claims, stale data, fabricated identity, broken legacy URLs, or unsupported business values.

The live homepage currently exposes National and Bangkok lottery result areas, next-draw data, security/support/payout/bonus sections and contact information.

Use those as observable reference points, not as permission to fabricate backend data.

---

# 30. DEFINITION OF DONE

Page 01 is NOT complete until:

[ ] Home route verified
[ ] Home controller verified
[ ] Home backend service verified
[ ] Home API verified if used
[ ] real lottery data connected
[ ] real result data connected
[ ] real countdown connected
[ ] real CTA routes connected
[ ] authenticated/guest state verified
[ ] payment-method visibility verified
[ ] app links verified
[ ] EN verified
[ ] TH verified
[ ] mobile verified
[ ] tablet verified
[ ] desktop verified
[ ] SEO verified
[ ] accessibility verified
[ ] loading states verified
[ ] empty states verified
[ ] API error states verified
[ ] security review completed
[ ] tests added/updated
[ ] available tests executed
[ ] no fabricated dynamic values
[ ] no placeholder production content
[ ] no duplicate backend service architecture
[ ] no duplicate Home route
[ ] no `href="#"`
[ ] no unsafe third-party image hotlinks
[ ] no real secrets exposed
[ ] complete files provided for every modification

---

# 31. FINAL OUTPUT REQUIRED FROM THE CODING AGENT

Return exactly:

## A. IMPLEMENTATION SUMMARY

What was built.

## B. COMPLETE FILE TREE

Every created/modified file.

For every file:

```text
path/to/file.php
# TYPE: controller/service/model/view/test/config/etc.
# PURPOSE: exact responsibility implemented.
```

## C. COMPLETE MODIFIED FILE CONTENT

Every modified file in FULL.

No truncation.

No placeholders.

## D. API CONTRACT

Exact endpoint(s), request/response schema and authentication requirements.

## E. BACKEND DATA MAP

For every Home dynamic field:

`UI → API/service → model/query → source`

## F. TEST RESULTS

Exact command and exact output.

Do not claim tests passed unless they were actually executed.

## G. REMAINING BLOCKERS

Only real blockers.

## H. EXTERNAL PRODUCTION INPUTS

Provider credentials, operator-approved legal values, real historical/live data, infrastructure values, etc.

Do not pretend these are verified unless they were actually verified.

---

# 32. FINAL HONESTY RULE

Do NOT say:

`100% complete`

just because the UI looks finished.

Do NOT say:

`production ready`

just because tests were written.

The Home page is complete only when:

VISUAL DESIGN
+
BACKEND DATA
+
API
+
SECURITY
+
RESPONSIVENESS
+
TESTING

are all supported by actual evidence.

If a dependency cannot be verified in the current environment, mark it:

`NOT VERIFIED`

and explain exactly what evidence is missing.

---

# 33. IMPORTANT — PAGE SCOPE

Do NOT redesign Pages 02–14 in this prompt.

ONLY:

`01. HOME / LANDING PAGE`

However, shared files may be modified when necessary for the Home implementation, such as:

- public layout
- navigation
- global design tokens
- global localization
- shared components
- API infrastructure

When modifying a shared file, preserve all existing non-Home behavior.

---

# 34. FINAL QUALITY BAR

The finished Home page should visually communicate:

PREMIUM
TRUSTED
FAST
MODERN
LOTTERY-FIRST
DATA-DRIVEN
MOBILE-FIRST
PRODUCTION-GRADE

without making unsupported legal or official-affiliation claims.

Do not merely make the page “beautiful”.

Make it a real working production interface connected to the real application.