# PROMPT 35–44 — THAILOTTO MEGA ARCHIVE + PCSO + GLO L6
# PAGE 35 → PAGE 44
# WORLD-CLASS 3D GLASS + REAL BACKEND + REAL API WHEN REQUIRED
# NO FAKE DATA • NO FAKE PURCHASE • NO SKIP • NO TRUNCATED FILE

---

# MASTER ROLE

You are the senior:

- Product Designer
- UX/UI Engineer
- Laravel/PHP Architect
- Backend Engineer
- API Engineer
- Lottery Domain Engineer
- Financial Workflow Engineer
- Security Engineer
- Accessibility Engineer
- SEO Engineer
- QA/Test Engineer

Implement exactly:

35. Mega Lottery — Year Archive
36. Mega Lottery — Result Detail
37. PCSO Lottery
38. PCSO Lottery — Buy / Ticket Selection
39. PCSO Lottery — Draw Detail
40. PCSO Lottery — Latest Result
41. PCSO Lottery — Historical Results
42. PCSO Lottery — Year Archive
43. PCSO Lottery — Result Detail
44. GLO L6 Home

Do NOT skip any page.

---

# 1. CRITICAL SOURCE-FIRST RULE

Before modifying anything:

inspect the COMPLETE latest repository.

Find existing:

- routes
- controllers
- services
- models
- migrations
- result projections
- history services
- search services
- date/calendar services
- provenance services
- lottery product registry
- ticket models
- bet models
- wallet
- reservation
- ledger
- payment
- responsible gaming
- KYC
- localization
- shared components
- CSS
- JS
- APIs
- tests
- legacy bridge

Reuse canonical implementations.

Do not assume a filename from this prompt exists.

---

# 2. IMPORTANT — PREVIOUS IMPLEMENTATION STATUS

Pages 25–34 already established:

- Weekly canonical result architecture
- Mega/Bingo canonical result architecture
- source/provenance rendering
- leading-zero preservation
- fail-closed Mega purchase
- legacy bridge preservation

The implementation report explicitly states that Mega purchase remained `NOT_CONFIGURED` because no verified product-specific purchase contract was found.

DO NOT reverse that decision without actual source verification.

---

# 3. NON-NEGOTIABLE FILE RULE

Every changed/created file must be returned in FULL.

Never use:

```text id="k2on3p"
# ... existing code ...
// ... existing code ...
/* existing code */
...
TODO
FIXME
unchanged
rest omitted
```

No truncation.

Preserve existing valid logic.

---

# 4. NO FABRICATED DATA

Never invent:

- result numbers
- prize values
- draw dates
- jackpot
- payout
- odds
- prices
- discount
- ticket stock
- draw status
- winner count
- historical rows
- official-source status
- provider
- app link
- contact information

When data is missing:

use an honest state:

`NO_PUBLIC_DATA`

`UNAVAILABLE`

`NOT_CONFIGURED`

`DATA IMPORT REQUIRED`

according to the actual condition.

---

# 5. NUMBER STRING INTEGRITY

All lottery numbers are strings.

Examples:

```text id="6v7z6w"
000001
026531
013361
057
076
03
01
08
```

must NEVER lose leading zeroes.

Never use:

```php id="t3qv7b"
(int) $number
```

for public display.

Never use JavaScript numeric coercion for result values.

---

# 6. PROVENANCE RULE

Each public result must respect its actual source state.

Possible:

```text id="x43qdr"
OFFICIAL_SOURCE_VERIFIED
OFFICIAL_SOURCE_CONFIGURED
INTERNAL_RECONCILED
FIXTURE_ONLY
NOT_CONFIGURED
UNAVAILABLE
```

Only the backend decides the state.

Never write a generic marketing statement like:

`Official result`

unless the actual result's source status supports it.

The existing Mega implementation already uses source-status vocabulary instead of making blanket official claims. Preserve that architecture.

---

# 7. ROUTE COLLISION RULE

Routes using:

`/search`

`/year/{year}`

`/archive/{year}`

`/draw/{draw}`

`/result/{draw}`

must be declared in an order that prevents the wildcard `{draw}` route from capturing literal paths.

Apply this consistently to:

- Mega
- PCSO
- GLO-specific routes where applicable

---

# PAGE 35 — MEGA YEAR ARCHIVE

## OBJECTIVE

Create:

`35. MEGA LOTTERY — YEAR ARCHIVE`

Canonical route family should remain:

```text id="jk1m9v"
/bingo-lottery/year/{year}
```

if that is the actual repository contract.

A compatibility alias may exist:

```text
/bingo-lottery/archive/{year}
```

but the repository's canonical route must remain clearly identified.

---

## BACKEND

Use existing:

`BingoLotteryHistoryService`

and:

`BingoLotteryDateService`

or their actual canonical equivalents.

Validate:

- numeric year
- year bounds
- Gregorian/Buddhist conversion
- actual Mega lane
- actual public rows
- pagination bounds

Do not query another lottery lane.

---

## VIEW

```text id="6lxf20"
resources/views/bingo-lottery/
└── year.blade.php
# TYPE: blade_view
# PURPOSE: Mega year-specific historical archive.

resources/views/components/mega-year/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Premium year archive header.

├── archive-summary.blade.php
# TYPE: blade_component
# PURPOSE: Actual draw count/year metadata.

├── result-list.blade.php
# TYPE: blade_component
# PURPOSE: Year result rows.

├── pagination.blade.php
# TYPE: blade_component
# PURPOSE: Bounded server pagination.

└── adjacent-years.blade.php
# TYPE: blade_component
# PURPOSE: Navigate among actual available years only.
```

---

## EMPTY YEAR

If no results:

render a truthful empty state.

Never show:

- zeros
- sample results
- fake draw count

---

## SEO

For populated year:

`index,follow`

For unavailable/empty year:

follow the existing project's SEO policy.

Do not create duplicate SEO URLs for year/archive aliases.

---

## TESTS

```text id="9hy3my"
tests/Feature/Lottery/MegaYearArchiveTest.php
# TYPE: feature_test
# PURPOSE: Mega year archive route/rendering.

tests/Feature/Lottery/MegaYearArchiveIsolationTest.php
# TYPE: feature_test
# PURPOSE: Prevent cross-lane/year leakage.

tests/Feature/Lottery/MegaYearArchiveNavigationTest.php
# TYPE: feature_test
# PURPOSE: Adjacent-year correctness.
```

---

# PAGE 36 — MEGA LOTTERY RESULT DETAIL

## OBJECTIVE

Create:

`36. MEGA LOTTERY — RESULT DETAIL`

Use the canonical:

`/bingo-lottery/{draw}`

or existing result-detail route.

Do not build a separate result database.

---

## DATA

The current live Mega archive presents:

- 6 Mega
- 3 Mega
- 2 Mega

as distinct result fields.

Use the actual backend schema.

---

## VIEW

```text id="7v8d8f"
resources/views/bingo-lottery/
└── result-detail.blade.php
# TYPE: blade_view
# PURPOSE: Complete Mega result detail page.

resources/views/components/mega-result/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Main result header.

├── number-grid.blade.php
# TYPE: blade_component
# PURPOSE: 6/3/2 Mega result presentation.

├── draw-meta.blade.php
# TYPE: blade_component
# PURPOSE: Actual date/status/timezone.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Source/provenance state.

├── integrity.blade.php
# TYPE: blade_component
# PURPOSE: Public record integrity information.

└── related-results.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next actual published draws.
```

---

## NUMBER PRESENTATION

Use premium 3D balls/cards.

But:

visual 3D is presentation only.

The number value comes from backend.

---

## TESTS

```text id="64jgg8"
tests/Feature/Lottery/MegaResultDetailTest.php
# TYPE: feature_test
# PURPOSE: Result detail.

tests/Feature/Lottery/MegaResultDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: 6/3/2 Mega string integrity.

tests/Feature/Lottery/MegaResultDetailProvenanceTest.php
# TYPE: feature_test
# PURPOSE: Correct source-state rendering.
```

---

# PAGE 37 — PCSO LOTTERY

# OBJECTIVE

Create:

`37. PCSO LOTTERY`

The live PCSO page currently displays result categories:

- 6 D
- 4 D
- 3 D
- 2 D

with draw date/time rows and “Off” states when a category is unavailable.

The new design must retain this underlying information model while improving visual presentation.

---

# BACKEND

Inspect existing:

- PCSO controller
- PCSO result service
- PCSO history service
- PCSO search
- PCSO date service
- PCSO source/provenance
- product configuration

Do not reuse Mega-specific result fields.

---

## VIEW

```text id="d2xmsu"
resources/views/pcso-lottery/
└── index.blade.php
# TYPE: blade_view
# PURPOSE: PCSO Lottery landing/current-result page.

resources/views/components/pcso-lottery/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Premium PCSO hero.

├── latest-result.blade.php
# TYPE: blade_component
# PURPOSE: Latest PCSO result.

├── result-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: 6D/4D/3D/2D result categories.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Year archive navigation.

├── search-entry.blade.php
# TYPE: blade_component
# PURPOSE: Search entry.

└── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Source/provenance state.
```

---

# PCSO RESULT STATES

Each category can have:

```text id="xv4p0a"
PUBLISHED
OFF
NOT_PUBLISHED
UNAVAILABLE
```

If the backend says `Off`:

render the translated `Off` status.

Do NOT convert it into:

`0`

`000`

or a fake result.

---

# PCSO DESIGN

Visual concept:

large premium result sphere
+
four glass result cards:

```text
6D
4D
3D
2D
```

Use 3D number balls for actual published values.

---

# TESTS

```text id="8r3u4t"
tests/Feature/Lottery/PcsoLotteryPageTest.php
# TYPE: feature_test
# PURPOSE: PCSO landing.

tests/Feature/Lottery/PcsoResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Result categories/status/provenance.

tests/Feature/Lottery/PcsoLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Preserve 6D/4D/3D/2D leading zeros.

tests/Feature/Lottery/PcsoLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 38 — PCSO BUY / TICKET SELECTION

## OBJECTIVE

Create:

`38. PCSO LOTTERY — BUY / TICKET SELECTION`

---

# CRITICAL RULE

Do NOT assume PCSO is purchasable.

First verify the actual source.

Determine:

- product purchase exists?
- draw mapping?
- selection rules?
- price?
- discount?
- wallet reservation?
- ledger?
- responsible gaming?
- ticket issuance?
- idempotency?
- settlement?

---

# FAIL-CLOSED

If any required product purchase contract cannot be proven:

render:

`NOT_CONFIGURED`

and disable purchase.

Do not accept:

- number
- amount
- quantity
- wallet details
- payment request
- fake success

---

## VIEW

```text id="e4ms6k"
resources/views/pcso-lottery/
└── buy.blade.php
# TYPE: blade_view
# PURPOSE: PCSO purchase entry, functional only if a verified PCSO purchase contract exists.

resources/views/components/pcso-buy/
├── draw-selector.blade.php
# TYPE: blade_component
# PURPOSE: Eligible PCSO draw selection.

├── selection-panel.blade.php
# TYPE: blade_component
# PURPOSE: PCSO-specific number/selection UI.

├── price-summary.blade.php
# TYPE: blade_component
# PURPOSE: Server-authoritative pricing.

├── unavailable-state.blade.php
# TYPE: blade_component
# PURPOSE: NOT_CONFIGURED state.

├── bet-slip.blade.php
# TYPE: blade_component
# PURPOSE: Purchase summary when real purchase exists.

└── purchase-actions.blade.php
# TYPE: blade_component
# PURPOSE: Real purchase submission only when backend capability is verified.
```

---

# PURCHASE FLOW

When configured:

```text id="bja54l"
PCSO Product
→ Draw
→ Selection
→ Server Validation
→ Price
→ Discount
→ Responsible Gaming
→ Wallet
→ Reservation
→ Purchase
→ Ledger
→ Ticket/Bet
→ Confirmation
```

When not configured:

```text
PCSO → NOT_CONFIGURED
```

---

# TESTS

```text id="9q66re"
tests/Feature/Lottery/PcsoPurchasePageTest.php
# TYPE: feature_test
# PURPOSE: Purchase-page behavior.

tests/Feature/Lottery/PcsoPurchaseConfigurationTest.php
# TYPE: feature_test
# PURPOSE: Capability/fail-closed logic.

tests/Feature/Lottery/PcsoPurchaseSecurityTest.php
# TYPE: feature_test
# PURPOSE: Authorization/price/input protection.

tests/Feature/Lottery/PcsoPurchaseReplayTest.php
# TYPE: feature_test
# PURPOSE: Replay/idempotency when purchase exists.

tests/Feature/Lottery/PcsoPurchaseWalletTest.php
# TYPE: feature_test
# PURPOSE: Wallet/reservation/ledger behavior when configured.
```

---

# PAGE 39 — PCSO DRAW DETAIL

## OBJECTIVE

Create:

`39. PCSO LOTTERY — DRAW DETAIL`

---

## BACKEND

Use canonical PCSO result projection.

Verify:

- PCSO lane
- draw reference
- draw date
- draw time
- timezone
- publication status
- result fields
- source state

---

## VIEW

```text id="m9h0li"
resources/views/pcso-lottery/
└── draw-detail.blade.php
# TYPE: blade_view
# PURPOSE: Complete PCSO draw-detail page.

resources/views/components/pcso-draw/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw/date/status.

├── result-grid.blade.php
# TYPE: blade_component
# PURPOSE: 6D/4D/3D/2D result grid.

├── publication-state.blade.php
# TYPE: blade_component
# PURPOSE: Published/off/unavailable state.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Source/provenance.

└── navigation.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next actual PCSO draws.
```

---

# TESTS

```text id="f31m0n"
tests/Feature/Lottery/PcsoDrawDetailTest.php
# TYPE: feature_test
# PURPOSE: Draw detail.

tests/Feature/Lottery/PcsoDrawDetailIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Draw/result relationship.

tests/Feature/Lottery/PcsoDrawDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Result string preservation.
```

---

# PAGE 40 — PCSO LATEST RESULT

## OBJECTIVE

Create:

`40. PCSO LOTTERY — LATEST RESULT`

---

# LATEST RULE

Latest result must be selected by canonical backend ordering.

Do NOT use frontend date logic.

Do NOT use current browser date.

Do NOT use static JSON.

---

## VIEW

```text id="7jrqkd"
resources/views/pcso-lottery/
└── latest-result.blade.php
# TYPE: blade_view
# PURPOSE: Latest PCSO result.

resources/views/components/pcso-latest/
├── hero-result.blade.php
# TYPE: blade_component
# PURPOSE: Main latest result.

├── result-groups.blade.php
# TYPE: blade_component
# PURPOSE: 6D/4D/3D/2D groups.

├── draw-summary.blade.php
# TYPE: blade_component
# PURPOSE: Actual date/time/status/source.

└── verify-cta.blade.php
# TYPE: blade_component
# PURPOSE: Prize Verification route.
```

---

# OFF HANDLING

Some PCSO draws can have categories marked `Off`.

Preserve the actual state.

Never replace `Off` with fake numeric data.

---

# TESTS

```text id="f93wqz"
tests/Feature/Lottery/PcsoLatestResultTest.php
# TYPE: feature_test
# PURPOSE: Latest-result selection.

tests/Feature/Lottery/PcsoLatestResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Verified/public result state.

tests/Feature/Lottery/PcsoLatestResultLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Leading-zero preservation.
```

---

# PAGE 41 — PCSO HISTORICAL RESULTS

## OBJECTIVE

Create:

`41. PCSO LOTTERY — HISTORICAL RESULTS`

---

# BACKEND

Use actual PCSO history service.

Support:

- available years
- pagination
- search where existing
- date filters
- result state
- provenance

---

## VIEW

```text id="gh0i7k"
resources/views/pcso-lottery/
└── history.blade.php
# TYPE: blade_view
# PURPOSE: PCSO historical result browser.

resources/views/components/pcso-history/
├── filters.blade.php
# TYPE: blade_component
# PURPOSE: Year/date/search filters.

├── result-table.blade.php
# TYPE: blade_component
# PURPOSE: Desktop historical table.

├── mobile-result-card.blade.php
# TYPE: blade_component
# PURPOSE: Mobile-native result cards.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Available-year navigation.

└── empty-state.blade.php
# TYPE: blade_component
# PURPOSE: Honest empty/unavailable state.
```

---

# TABLE

Recommended data structure based on the live PCSO surface:

```text id="5c0c48"
Date/Time
6D
4D
3D
2D
Source
Detail
```

But only display fields actually present in the backend.

The live page currently shows date/time plus 6D, 4D, 3D and 2D.

---

# DATA INTEGRITY

Handle:

- `Off`
- missing field
- unavailable result
- malformed imported result
- leading zeros
- source state

---

# TESTS

```text id="5ygxw9"
tests/Feature/Lottery/PcsoHistoryPageTest.php
# TYPE: feature_test
# PURPOSE: Historical results.

tests/Feature/Lottery/PcsoHistoryIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Source/result integrity.

tests/Feature/Lottery/PcsoHistorySearchTest.php
# TYPE: feature_test
# PURPOSE: Search/date filtering.

tests/Feature/Lottery/PcsoHistoryLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: String preservation.
```

---

# PAGE 42 — PCSO YEAR ARCHIVE

## OBJECTIVE

Create:

`42. PCSO LOTTERY — YEAR ARCHIVE`

Canonical route:

```text id="7ncw9j"
/pcso-lottery/year/{year}
```

if that is the current source route.

---

# BACKEND

Retrieve ONLY:

requested year
+
PCSO lane

Validate:

- year shape
- calendar normalization
- available year
- public data
- pagination bounds

---

## VIEW

```text id="2v1kc8"
resources/views/pcso-lottery/
└── year.blade.php
# TYPE: blade_view
# PURPOSE: PCSO year archive.

resources/views/components/pcso-year/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Year archive hero.

├── summary.blade.php
# TYPE: blade_component
# PURPOSE: Actual year summary.

├── result-list.blade.php
# TYPE: blade_component
# PURPOSE: Year result list.

└── adjacent-years.blade.php
# TYPE: blade_component
# PURPOSE: Actual adjacent-year links.
```

---

# EMPTY YEAR

No data:

`NO_PUBLIC_DATA`

Do not insert demo rows.

---

# SEO

Canonical must resolve to the actual canonical year route.

Do not index duplicate alias URLs.

---

# TESTS

```text id="0n9o9c"
tests/Feature/Lottery/PcsoYearArchiveTest.php
# TYPE: feature_test
# PURPOSE: Year archive.

tests/Feature/Lottery/PcsoYearArchiveIsolationTest.php
# TYPE: feature_test
# PURPOSE: Year/lane isolation.

tests/Feature/Lottery/PcsoYearArchiveNavigationTest.php
# TYPE: feature_test
# PURPOSE: Adjacent-year navigation.
```

---

# PAGE 43 — PCSO RESULT DETAIL

## OBJECTIVE

Create:

`43. PCSO LOTTERY — RESULT DETAIL`

Use the actual canonical draw/result identifier.

---

## VIEW

```text id="kz4f9d"
resources/views/pcso-lottery/
└── result-detail.blade.php
# TYPE: blade_view
# PURPOSE: Individual PCSO result-detail experience.

resources/views/components/pcso-result/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw/date/time/status.

├── result-hero.blade.php
# TYPE: blade_component
# PURPOSE: Main result visual.

├── result-groups.blade.php
# TYPE: blade_component
# PURPOSE: 6D/4D/3D/2D groups.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Source/provenance.

├── integrity.blade.php
# TYPE: blade_component
# PURPOSE: Record integrity state.

└── related-draws.blade.php
# TYPE: blade_component
# PURPOSE: Related published results.
```

---

# RESULT STATE

Possible:

```text id="zt7m21"
PUBLISHED
OFF
NOT_PUBLISHED
UNAVAILABLE
```

Render exact backend state.

---

# TESTS

```text id="fzc9xh"
tests/Feature/Lottery/PcsoResultDetailTest.php
# TYPE: feature_test
# PURPOSE: Result detail.

tests/Feature/Lottery/PcsoResultDetailSecurityTest.php
# TYPE: feature_test
# PURPOSE: Public/private data boundary.

tests/Feature/Lottery/PcsoResultDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Numeric string integrity.

tests/Feature/Lottery/PcsoResultDetailProvenanceTest.php
# TYPE: feature_test
# PURPOSE: Provenance state.
```

---

# PAGE 44 — GLO L6 HOME

# IMPORTANT — THIS IS A DISTINCT PRODUCT EXPERIENCE

Do NOT treat:

`GLO L6`

as merely another generic lottery lane.

The project architecture must keep GLO L6-specific rules separate from:

- National generic result pages
- Weekly
- Mega
- PCSO
- generic 2D/3D betting

---

# OBJECTIVE

Create:

`44. GLO L6 HOME`

This is the main dedicated GLO L6 product experience.

---

# FIRST — INSPECT ACTUAL GLO ARCHITECTURE

Locate all existing:

- GLO L6 service
- GLO L6 ticket engine
- GLO L6 calculator
- GLO result service
- GLO draw service
- GLO ticket model
- GLO prize claim
- GLO prize publication
- GLO configuration
- wallet integration
- reservation
- ledger
- KYC
- age verification
- responsible gaming
- ticket purchase
- ticket check
- prize claim
- historical result

Reuse canonical implementations.

Do NOT create duplicate GLO engines.

---

# REQUIRED BUSINESS TRACE

Trace the actual flow:

```text id="x1t5g8"
GLO L6 Home
→ Eligible Draw
→ Ticket Availability
→ Ticket Selection
→ Ticket Price
→ User Grade/Discount if applicable
→ Responsible Gaming
→ Wallet
→ Reservation
→ Purchase
→ Ticket Issuance
→ Result
→ Prize Matching
→ Prize Claim
→ Eligibility/KYC
→ Approval
→ Settlement
→ Ledger
```

If any capability is missing:

DO NOT fabricate it.

---

# GLO PURCHASE RULE

If verified GLO purchase flow exists:

connect to it.

If not verified:

show:

`NOT_CONFIGURED`

and disable purchase.

Never build an imitation purchase flow.

---

# GLO PRICE RULE

Do not hardcode the price in Blade/JS.

Use the authoritative GLO configuration/service.

The prior audit identified GLO L6 as a distinct 6-digit product with specific ticket/prize configuration, so the authoritative backend must remain the source of truth.

---

# GLO RESULT RULE

GLO result values must come from:

canonical GLO result/publication services.

No fake:

- first prize
- second prize
- draw date
- jackpot
- winner count

---

# GLO CLAIM RULE

If prize claims are exposed from this Home page:

link to the existing canonical claim flow.

Do not create a second claim service.

---

# VIEW TREE

```text id="0v7e2z"
resources/views/glo/
└── index.blade.php
# TYPE: blade_view
# PURPOSE: Dedicated GLO L6 product home.

resources/views/components/glo/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Premium GLO L6 cinematic hero.

├── next-draw.blade.php
# TYPE: blade_component
# PURPOSE: Authoritative next-draw information.

├── ticket-experience.blade.php
# TYPE: blade_component
# PURPOSE: Real ticket-selection entry when configured.

├── ticket-example.blade.php
# TYPE: blade_component
# PURPOSE: Visual ticket explanation ONLY; must never present fabricated purchase/result data as real.

├── prize-structure.blade.php
# TYPE: blade_component
# PURPOSE: Actual configured/public prize structure.

├── latest-result.blade.php
# TYPE: blade_component
# PURPOSE: Latest verified GLO result.

├── result-checker.blade.php
# TYPE: blade_component
# PURPOSE: Real GLO ticket/result verification entry.

├── claim-info.blade.php
# TYPE: blade_component
# PURPOSE: Actual public prize-claim requirements.

├── security.blade.php
# TYPE: blade_component
# PURPOSE: Actual security/KYC/RG capabilities.

├── responsible-gaming.blade.php
# TYPE: blade_component
# PURPOSE: Responsible-gaming information and route.

└── cta.blade.php
# TYPE: blade_component
# PURPOSE: Real purchase/result/check/claim CTA destinations.
```

---

# GLO HERO DESIGN

Create a premium signature visual.

Concept:

```text id="d4l7h0"
DARK SPACE
+
TRANSPARENT GOLD ORB
+
SIX DIGIT LOTTERY BALLS
+
GLASS TICKET
+
GOLD PEDESTAL
+
SOFT VOLUMETRIC LIGHT
+
SUBTLE DATA PARTICLES
```

This should be more sophisticated than a normal lottery card.

No fake government emblem.

No fake government-office building.

No false “official portal” claim.

---

# GLO HOME LAYOUT

```text id="tb8g8v"
1. HERO
2. NEXT DRAW
3. GLO L6 PRODUCT INTRO
4. TICKET EXPERIENCE
5. PRIZE STRUCTURE
6. LATEST RESULT
7. CHECK TICKET
8. PRIZE CLAIM
9. SECURITY / KYC
10. RESPONSIBLE GAMING
11. FAQ/HELP
12. CTA
```

Only render a section when its backend content/capability is real.

---

# GLO NEXT DRAW

Use authoritative draw service.

Frontend countdown is presentation only.

Handle:

- no draw
- draw passed
- unavailable
- timezone
- API/service failure

Never hardcode:

`October 16`

or another calendar date.

---

# GLO TICKET VISUAL

A visual ticket mockup may be used.

But clearly distinguish:

`ILLUSTRATION`

from:

`REAL PURCHASED TICKET`

Never make an illustration look like an issued ticket.

---

# GLO PRICE DISPLAY

The price must come from:

- GLO configuration
- canonical pricing service
- or actual product contract

Do not calculate price in JS.

---

# GLO PRIZE DISPLAY

Use backend-configured public prize values.

For any proportional/unsold-ticket logic:

do not reproduce the calculation inside frontend code.

Display the final backend-authorized result only.

---

# GLO LATEST RESULT

Use:

`GloResultPublicationService`

or actual canonical equivalent.

Verify:

- publication state
- provenance
- result integrity
- draw date
- result number

---

# GLO CHECKER

If the project has a real GLO ticket checker:

connect to it.

Potential flow:

```text id="g1f2z1"
Input Ticket Number
→ Server Validation
→ Result Lookup
→ Verified Result
→ Prize State
```

Rate-limit public requests.

Do not expose internal result lookup details.

---

# GLO CLAIM CTA

Where configured:

`Check Prize`

→ actual checker

`Claim Prize`

→ actual authenticated claim flow

`My Tickets`

→ actual player route

No fake destinations.

---

# GLO KYC / AGE

If actual product rules require:

- KYC
- age verification
- account verification
- self-exclusion

the Home purchase flow must respect those restrictions.

The Home page must never bypass them.

---

# GLO RESPONSIBLE GAMING

Show actual:

- limits
- self-exclusion
- support
- safer-play links

Only when the corresponding capability/page exists.

---

# GLO BACKEND FILE REQUIREMENT

If a required capability genuinely does not exist:

CREATE it.

Examples:

```text id="d3lp9g"
app/Http/Controllers/GloL6Controller.php
# TYPE: controller
# PURPOSE: GLO L6 Home/page orchestration.

app/Services/Lottery/GloL6HomeDataService.php
# TYPE: service
# PURPOSE: Aggregate public GLO L6 Home data from canonical GLO services.
```

BUT:

Before creating them, search for existing equivalents.

Do not create duplicates.

---

# GLO ROUTE

Use an actual clean route, for example:

```text id="sr3kbr"
/glo-l6
```

ONLY if it is the repository's canonical route.

If another route already exists:

reuse it.

---

# GLO API

If Home requires API-driven dynamic data and an equivalent API exists:

reuse it.

If no suitable API exists and the project architecture genuinely requires one:

create:

```text
GET /api/v1/glo-l6/home
```

only when justified.

Response may contain:

```json id="d2p9sy"
{
  "success": true,
  "data": {
    "product": {},
    "draw": {},
    "ticket": {},
    "prizes": {},
    "latest_result": {},
    "claim": {},
    "security": {},
    "responsible_gaming": {}
  }
}
```

Every field must come from the actual backend.

---

# GLO HOME TESTS

Create/extend:

```text id="j3zqtm"
tests/Feature/Glo/GloL6HomePageTest.php
# TYPE: feature_test
# PURPOSE: GLO L6 Home route/render/public behavior.

tests/Feature/Glo/GloL6HomeDataIntegrityTest.php
# TYPE: feature_test
# PURPOSE: No fabricated product/draw/prize data.

tests/Feature/Glo/GloL6HomeResultTest.php
# TYPE: feature_test
# PURPOSE: Latest-result/provenance behavior.

tests/Feature/Glo/GloL6HomePurchaseConfigurationTest.php
# TYPE: feature_test
# PURPOSE: Verify purchase capability and NOT_CONFIGURED fail-closed state.

tests/Feature/Glo/GloL6HomeSecurityTest.php
# TYPE: feature_test
# PURPOSE: PII/secret/private-data isolation.

tests/Feature/Glo/GloL6HomeLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# GLOBAL CSS

Create only page-specific styles:

```text id="qz4a7f"
resources/css/pages/
├── mega-year.css
# TYPE: stylesheet
# PURPOSE: Mega year archive.

├── mega-result.css
# TYPE: stylesheet
# PURPOSE: Mega result detail.

├── pcso-lottery.css
# TYPE: stylesheet
# PURPOSE: PCSO landing/current results.

├── pcso-buy.css
# TYPE: stylesheet
# PURPOSE: PCSO purchase/fail-closed interface.

├── pcso-result.css
# TYPE: stylesheet
# PURPOSE: PCSO draw/latest/detail pages.

└── glo-l6.css
# TYPE: stylesheet
# PURPOSE: Dedicated GLO L6 premium product experience.
```

Reuse shared:

- glass tokens
- typography
- 3D primitives
- buttons
- cards
- accessibility variables

Do not duplicate the global Home design system.

---

# GLOBAL JAVASCRIPT

Create only when required:

```text id="v9h1nd"
resources/js/pages/
├── mega-year.js
# TYPE: javascript
# PURPOSE: Archive filtering/navigation enhancement.

├── mega-result.js
# TYPE: javascript
# PURPOSE: Result-detail progressive enhancement.

├── pcso-lottery.js
# TYPE: javascript
# PURPOSE: PCSO filters/result presentation.

├── pcso-buy.js
# TYPE: javascript
# PURPOSE: PCSO selection UX only when purchase is configured.

└── glo-l6.js
# TYPE: javascript
# PURPOSE: GLO L6 interactive UI/countdown/progressive enhancement; never source-of-truth for financial/result values.
```

No unnecessary polling.

---

# LOCALIZATION

Use canonical existing namespaces.

For example:

```text id="o2f7fl"
lang/en/
lang/th/
```

Reuse:

- `bingo_lottery`
- `pcso_lottery`

when already present.

Create:

`glo_l6.php`

ONLY if no existing canonical GLO namespace exists.

Both languages MUST have identical key structures.

---

# API SECURITY

All public APIs:

- validate inputs
- rate-limit search/checker endpoints
- use safe serialization
- hide private identifiers
- hide secrets
- hide ledger IDs
- hide wallet IDs
- hide internal notes

---

# SEARCH SECURITY

Search endpoints must resist:

- enumeration abuse
- giant terms
- wildcard abuse
- malformed year
- invalid draw references

Use the existing configured throttling architecture.

---

# DATABASE RULE

Do not add migrations unless actual missing persistence is proven.

Before migration creation inspect:

- Draw
- DrawResult
- PCSO result tables
- Mega/Bingo result tables
- GLO ticket tables
- GLO claim tables
- Wallet
- Ledger
- reservations
- provenance

Avoid duplicate data stores.

---

# LEGACY URL COMPATIBILITY

Verify:

```text id="5alrhb"
/bingo-lottery.php
/pcso-lottery.php
```

and all related yearly/numbered legacy archive mappings.

Use existing:

`LegacyRedirectController`

Do NOT create a new redirect system.

---

# SEO

All public result pages:

- unique title
- unique description
- canonical
- correct locale
- semantic H1
- breadcrumb where supported
- archive canonicalization
- no duplicate alias indexing

For GLO L6:

use only approved product naming.

Do not place unsupported government/offical affiliation in structured metadata.

---

# ACCESSIBILITY

Every page:

- semantic headings
- keyboard navigation
- visible focus
- screen-reader labels
- accessible result tables
- large touch targets
- sufficient contrast
- reduced motion
- no critical information hidden behind animation

---

# RESPONSIVE DESIGN

## Desktop

Premium 3D result presentation.

## Laptop

Balanced cards and content density.

## Tablet

Responsive grids.

## Mobile

Native stacked result cards.

Never force users to zoom a 10-column table.

No horizontal page overflow.

---

# CROSS-PAGE CONSISTENCY

Before marking Pages 35–44 complete compare against:

- Fees
- Discounts
- Account Grade
- Prize Verification
- How to Play
- Responsible Gaming
- Wallet
- Terms
- Home
- Pages 15–34

Detect:

- product naming mismatch
- price mismatch
- result mismatch
- availability mismatch
- discount mismatch
- purchase capability mismatch
- provenance mismatch

Do not silently alter another page.

---

# PURCHASE SAFETY FOR PAGES 38 AND 44

A purchase screen is considered functional ONLY when all of these can be traced:

```text id="dxcm9f"
Product
→ Draw
→ Selection
→ Validation
→ Price
→ Discount
→ RG
→ Wallet
→ Reservation
→ Purchase
→ Ledger
→ Ticket/Bet
→ Confirmation
```

If any critical layer is missing:

`NOT_CONFIGURED`

No exceptions.

---

# TEST MATRIX

Create/extend tests covering:

## Mega

[ ] Page 35
[ ] Page 36

## PCSO

[ ] Page 37
[ ] Page 38
[ ] Page 39
[ ] Page 40
[ ] Page 41
[ ] Page 42
[ ] Page 43

## GLO

[ ] Page 44

Across all:

[ ] routing
[ ] data integrity
[ ] provenance
[ ] leading zeros
[ ] empty states
[ ] year isolation
[ ] search
[ ] localization
[ ] security
[ ] accessibility-critical markup
[ ] SEO

Purchase pages:

[ ] capability check
[ ] fail-closed
[ ] server pricing
[ ] wallet
[ ] reservation
[ ] ledger
[ ] idempotency
[ ] RG

---

# COMPLETE FILE TREE OUTPUT

For every actual changed/created file:

```text id="n9mq3n"
path/to/file
# TYPE: controller/service/model/view/component/css/js/test/config/etc.
# PURPOSE: exact responsibility.
```

No file without comments.

Do not list imaginary files.

If existing file reused:

`REUSED — NO DUPLICATE CREATED`

---

# COMPLETE FILE CONTENT

Every created/modified file:

FULL CONTENT.

No truncation.

No placeholders.

No:

`existing code`

`unchanged`

`...`

---

# REQUIRED FINAL REPORT

Return:

## A. IMPLEMENTATION SUMMARY

Separate:

35
36
37
38
39
40
41
42
43
44

## B. ACTUAL COMPLETE FILE TREE

Only files actually changed/created.

## C. COMPLETE FILE CONTENT

All changed files in full.

## D. MEGA DATA MAP

```text id="9qmt2r"
UI
→ Route
→ Controller
→ Service
→ Model/Query
→ Source
→ View
```

## E. PCSO DATA MAP

Same.

## F. GLO L6 DATA MAP

```text id="nkx7ul"
Home
→ Controller
→ GLO L6 Home Service
→ Canonical GLO services
→ Draw/Ticket/Result/Claim sources
→ View
```

## G. PURCHASE CAPABILITY MAP

For Pages 38/44:

`CONFIGURED`

or:

`NOT_CONFIGURED`

with exact missing component.

## H. API MAP

Exact endpoints actually used/created.

## I. LIVE COMPARISON

Compare actual:

- Mega archive
- PCSO
- GLO-related observable surfaces

Clearly label:

`OBSERVED LIVE`

`SOURCE VERIFIED`

`IMPLEMENTED`

`NOT VERIFIED`

`DATA IMPORT REQUIRED`

`NOT_CONFIGURED`

## J. TEST RESULTS

Exact commands.

Exact outputs.

Never say a test passed unless it actually ran.

## K. REMAINING BLOCKERS

Only real blockers.

---

# FINAL HONESTY RULE

Never report:

`100% production ready`

only because the UI is beautiful.

Report separately:

`DESIGN`
`DATA`
`BACKEND`
`API`
`RESULT PROVENANCE`
`PURCHASE`
`WALLET`
`SECURITY`
`LOCALIZATION`
`SEO`
`TESTING`
`LEGACY COMPATIBILITY`

If PHP/runtime is unavailable:

`NOT VERIFIED — PHP RUNTIME UNAVAILABLE`

If Vite cannot execute:

`NOT VERIFIED — VITE BUILD UNAVAILABLE`

If historical data is missing:

`DATA IMPORT REQUIRED`

If PCSO/Mega/GLO purchase contract cannot be proven:

`NOT_CONFIGURED — FAIL CLOSED`

---

# DEFINITION OF DONE

Pages 35–44 are complete only when:

[ ] Page 35 complete
[ ] Page 36 complete
[ ] Page 37 complete
[ ] Page 38 complete
[ ] Page 39 complete
[ ] Page 40 complete
[ ] Page 41 complete
[ ] Page 42 complete
[ ] Page 43 complete
[ ] Page 44 complete

[ ] no skipped page
[ ] no fake result
[ ] no fake prize
[ ] no fake price
[ ] no fake jackpot
[ ] no fake ticket
[ ] leading zeros preserved
[ ] provenance preserved
[ ] backend source traced
[ ] route verified
[ ] EN verified
[ ] TH verified
[ ] desktop verified
[ ] tablet verified
[ ] mobile verified
[ ] accessibility verified
[ ] SEO verified
[ ] security reviewed
[ ] legacy compatibility reviewed
[ ] purchase fail-closed where necessary
[ ] tests created/updated
[ ] tests actually executed where runtime exists
[ ] no duplicate architecture
[ ] no duplicate API
[ ] no placeholder code
[ ] no truncated file content

---

# PAGE ORDER LOCK

Implement EXACTLY:

35 → Mega Lottery Year Archive

36 → Mega Lottery Result Detail

37 → PCSO Lottery

38 → PCSO Lottery Buy / Ticket Selection

39 → PCSO Lottery Draw Detail

40 → PCSO Lottery Latest Result

41 → PCSO Lottery Historical Results

42 → PCSO Lottery Year Archive

43 → PCSO Lottery Result Detail

44 → GLO L6 Home

DO NOT move to Page 45 until ALL ten pages have been implemented and independently reported.