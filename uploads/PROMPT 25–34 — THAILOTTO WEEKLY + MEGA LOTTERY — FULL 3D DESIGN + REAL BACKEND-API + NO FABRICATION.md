# PROMPT 25–34 — THAILOTTO WEEKLY + MEGA LOTTERY
# PAGE 25 → PAGE 34
# WORLD-CLASS 3D GLASS + REAL LOTTERY DATA + REAL BACKEND/API
# NO FAKE RESULTS • NO FAKE PURCHASE • NO PLACEHOLDER • NO SKIP

---

# MASTER ROLE

You are the senior:

- Product Designer
- UX/UI Engineer
- Laravel/PHP Architect
- Backend Engineer
- API Engineer
- Lottery Domain Engineer
- Frontend Engineer
- Security Engineer
- Accessibility Engineer
- SEO Engineer
- QA/Test Engineer

Implement exactly these ten pages:

25. Weekly Lottery — Draw Detail
26. Weekly Lottery — Latest Result
27. Weekly Lottery — Historical Results
28. Weekly Lottery — Year Archive
29. Weekly Lottery — Result Detail
30. Mega Lottery
31. Mega Lottery — Buy / Ticket Selection
32. Mega Lottery — Draw Detail
33. Mega Lottery — Latest Result
34. Mega Lottery — Historical Results

Do NOT skip any page.

Do NOT merge pages silently.

Do NOT replace a functional page with a static visual mockup.

Do NOT invent unsupported lottery business rules.

---

# 1. SOURCE-FIRST REQUIREMENT

Before implementation inspect the actual latest repository.

Locate the existing:

- Weekly routes
- Weekly controllers
- Weekly services
- Weekly models
- Weekly result services
- Weekly search
- Weekly history
- Weekly archive
- Weekly draw detail
- Weekly result detail
- Weekly purchase infrastructure
- wallet
- reservation
- ledger
- discount
- account-grade
- responsible-gaming
- provenance
- API
- localization

Then inspect Mega/Bingo:

- `/bingo-lottery`
- existing Bingo/Mega controller
- product registry
- result services
- history services
- archive services
- draw/detail services
- purchase services
- pricing
- discount
- wallet
- ledger
- provenance
- API
- localization

Reuse the canonical architecture.

---

# 2. NO DUPLICATE ARCHITECTURE

Do NOT create:

```text
WeeklyResultService2
MegaResultService2
NewWeeklyController
NewBingoController
NewMegaController
AlternativeLotteryService
SecondWalletService
SecondPurchaseService
SecondLedgerService
```

If equivalent existing logic exists:

REUSE IT.

If a truly missing backend capability is discovered:

CREATE the missing file and integrate it into the canonical architecture.

---

# 3. COMPLETE FILE RULE

Every created/modified file MUST be shown in FULL.

Never use:

```text
# ... existing code ...
// ... existing code ...
/* existing code */
...
TODO
FIXME
unchanged
rest omitted
```

Preserve all existing valid logic.

---

# 4. NO FABRICATED DATA

Never invent:

- winning numbers
- draw dates
- jackpot values
- payout values
- odds
- ticket price
- bet price
- discount rate
- ticket inventory
- winner count
- result state
- archive rows
- draw number
- provider
- payment status
- availability

If source data does not exist:

show:

`NO RESULT DATA`

or

`NOT AVAILABLE`

or

`NOT CONFIGURED`

as appropriate.

Never insert fake data for visual demonstration.

---

# 5. LEADING-ZERO REQUIREMENT

All lottery numbers remain strings.

Examples:

```text
078153
039
08
004615
000001
```

must render exactly as stored.

NEVER:

```php
(int) $number
```

for public display.

NEVER apply numeric formatting that strips leading zeros.

This rule must be enforced in:

- backend serializer
- Blade
- API
- JavaScript
- tests

---

# 6. RESULT PROVENANCE

Every public result must respect the actual source/provenance state.

Possible states:

```text
VERIFIED
SOURCE VERIFIED
PENDING
UNAVAILABLE
REJECTED
```

Never show:

`VERIFIED`

when the backend state is unknown.

Do not expose internal provenance implementation secrets.

---

# 7. WEEKLY / MEGA PRODUCT SEPARATION

Weekly and Mega are NOT automatically the same product.

Do not copy:

- Weekly number rules into Mega
- Mega number rules into Weekly
- National prize rules into Weekly
- National purchase rules into Mega

Determine actual product contracts from the source.

---

# PAGE 25 — WEEKLY LOTTERY DRAW DETAIL

## OBJECTIVE

Create:

`25. WEEKLY LOTTERY — DRAW DETAIL`

This is the canonical single Weekly draw page.

---

## ROUTE

Use the existing canonical route:

```text
/weekly-lottery/{draw}
```

or the actual current route contract.

Do NOT rename the route only because the page is redesigned.

---

## BACKEND

Trace:

```text id="a0a6a3"
Route
→ Controller
→ Draw resolver
→ Weekly result source
→ Publication/provenance
→ ViewModel/API resource
→ Blade
```

The resolver must ensure:

- draw belongs to Weekly lane
- draw exists
- requested draw is public
- result state is correct
- no cross-lane result leak

---

## VIEW TREE

```text id="4yz8kh"
resources/views/weekly-lottery/
└── draw-detail.blade.php
# TYPE: blade_view
# PURPOSE: Full Weekly draw detail page with premium result presentation.

resources/views/components/weekly-draw/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw identifier, draw date, status and timezone.

├── winning-numbers.blade.php
# TYPE: blade_component
# PURPOSE: Main Weekly winning numbers with leading-zero preservation.

├── prize-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: Actual published Weekly result/prize categories.

├── publication-state.blade.php
# TYPE: blade_component
# PURPOSE: Public result publication status.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Public source/provenance indicator.

└── navigation.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next Weekly draw navigation.
```

---

## DESIGN

Hero:

```text
WEEKLY LOTTERY

Draw Date
Draw Number
Publication Status
```

Then large result balls/cards.

Use actual result categories only.

---

## SECURITY

Do not expose:

- internal IDs unnecessarily
- result import metadata
- private operator notes
- internal source credentials

---

## TESTS

```text id="25m6d4"
tests/Feature/Lottery/WeeklyDrawDetailTest.php
# TYPE: feature_test
# PURPOSE: Weekly draw detail route/rendering.

tests/Feature/Lottery/WeeklyDrawDetailIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Correct lane/draw/result relationship.

tests/Feature/Lottery/WeeklyDrawDetailProvenanceTest.php
# TYPE: feature_test
# PURPOSE: Public provenance behavior.

tests/Feature/Lottery/WeeklyDrawDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Leading-zero preservation.
```

---

# PAGE 26 — WEEKLY LOTTERY LATEST RESULT

## OBJECTIVE

Create:

`26. WEEKLY LOTTERY — LATEST RESULT`

This page must identify the latest eligible Weekly result using backend draw ordering.

Never use:

- frontend array position
- system current date
- hardcoded date
- static JS value

---

## VIEW

```text id="x3a5zn"
resources/views/weekly-lottery/
└── latest-result.blade.php
# TYPE: blade_view
# PURPOSE: Latest Weekly result experience.

resources/views/components/weekly-latest/
├── hero-result.blade.php
# TYPE: blade_component
# PURPOSE: Main/latest result presentation.

├── secondary-results.blade.php
# TYPE: blade_component
# PURPOSE: Secondary result groups such as 3Ball and 2Ball when actually available.

├── draw-summary.blade.php
# TYPE: blade_component
# PURPOSE: Draw date/status/source.

└── verify-cta.blade.php
# TYPE: blade_component
# PURPOSE: Real Prize Verification route link.
```

---

## DATA

Use actual Weekly categories.

Do not assume categories from National.

---

## TESTS

```text id="g48r56"
tests/Feature/Lottery/WeeklyLatestResultTest.php
# TYPE: feature_test
# PURPOSE: Correct latest-result selection.

tests/Feature/Lottery/WeeklyLatestResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Verified/public-only result.

tests/Feature/Lottery/WeeklyLatestResultLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: String preservation.
```

---

# PAGE 27 — WEEKLY HISTORICAL RESULTS

## OBJECTIVE

Create:

`27. WEEKLY LOTTERY — HISTORICAL RESULTS`

Use actual archive/history data.

The live Weekly surface is year/archive-oriented and includes leading-zero values such as `078153`, `039`, `08`. ([thailotto.club](https://www.thailotto.club/weekly-lottery1.php?utm_source=chatgpt.com))

---

## BACKEND

Use existing:

- Weekly history query
- result archive
- search
- year resolver
- pagination

Do not fetch the complete history table into the browser.

---

## VIEW

```text id="5e10xu"
resources/views/weekly-lottery/
└── history.blade.php
# TYPE: blade_view
# PURPOSE: Weekly historical result browser.

resources/views/components/weekly-history/
├── filters.blade.php
# TYPE: blade_component
# PURPOSE: Year/date/search controls.

├── result-table.blade.php
# TYPE: blade_component
# PURPOSE: Desktop result table.

├── mobile-result-card.blade.php
# TYPE: blade_component
# PURPOSE: Mobile-native result presentation.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Available-year navigation.

└── empty-state.blade.php
# TYPE: blade_component
# PURPOSE: Honest empty/unavailable archive state.
```

---

## DATA INTEGRITY

Each result row must verify:

- Weekly lane
- valid date
- result provenance
- number string formatting
- publication status

---

## TESTS

```text id="ub3v94"
tests/Feature/Lottery/WeeklyHistoryPageTest.php
# TYPE: feature_test
# PURPOSE: Historical Weekly result page.

tests/Feature/Lottery/WeeklyHistoryDataIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Row/result/source integrity.

tests/Feature/Lottery/WeeklyHistorySearchTest.php
# TYPE: feature_test
# PURPOSE: Search/filter behavior.

tests/Feature/Lottery/WeeklyHistoryLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Leading-zero protection.
```

---

# PAGE 28 — WEEKLY YEAR ARCHIVE

## OBJECTIVE

Create:

`28. WEEKLY LOTTERY — YEAR ARCHIVE`

Example:

```text
/weekly-lottery/year/2568
```

Use actual route naming.

---

## BACKEND

Only return the requested year.

Validate:

- year format
- supported year
- Weekly lane
- actual rows
- publication state

Never mix National/Mega/PCSO records.

---

## VIEW

```text id="66ns5v"
resources/views/weekly-lottery/
└── year.blade.php
# TYPE: blade_view
# PURPOSE: Weekly year-specific result archive.

resources/views/components/weekly-year/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Year archive header.

├── summary.blade.php
# TYPE: blade_component
# PURPOSE: Actual count/metadata for that year.

├── result-list.blade.php
# TYPE: blade_component
# PURPOSE: Year result listing.

└── adjacent-years.blade.php
# TYPE: blade_component
# PURPOSE: Navigate only among actual available years.
```

---

## IMPORTANT

Do not assume every year has populated data.

Route exists:

does NOT mean data exists.

If no rows:

render:

`No published results available for this year.`

Do not fabricate.

---

## TESTS

```text id="md8yid"
tests/Feature/Lottery/WeeklyYearArchiveTest.php
# TYPE: feature_test
# PURPOSE: Year page.

tests/Feature/Lottery/WeeklyYearArchiveIsolationTest.php
# TYPE: feature_test
# PURPOSE: Ensure requested year cannot leak rows from another year.

tests/Feature/Lottery/WeeklyYearArchiveNavigationTest.php
# TYPE: feature_test
# PURPOSE: Adjacent-year links.
```

---

# PAGE 29 — WEEKLY RESULT DETAIL

## OBJECTIVE

Create:

`29. WEEKLY LOTTERY — RESULT DETAIL`

This is the detailed public result record.

---

## ROUTE

Use:

```text
/weekly-lottery/result/{draw}
```

only if this exists in the repository.

Otherwise use the canonical existing detail route.

Do not create duplicate resolution systems.

---

## VIEW

```text id="pxw2e8"
resources/views/weekly-lottery/
└── result-detail.blade.php
# TYPE: blade_view
# PURPOSE: Individual Weekly result detail experience.

resources/views/components/weekly-result-detail/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw/date/status.

├── winning-number-hero.blade.php
# TYPE: blade_component
# PURPOSE: Main winning result.

├── number-groups.blade.php
# TYPE: blade_component
# PURPOSE: All actual Weekly result groups.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Result provenance/status.

├── prize-reference.blade.php
# TYPE: blade_component
# PURPOSE: Public prize information where configured.

└── related-draws.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next related results.
```

---

## NUMBER SAFETY

Every result remains a string.

---

## TESTS

```text id="sf0e1v"
tests/Feature/Lottery/WeeklyResultDetailTest.php
# TYPE: feature_test
# PURPOSE: Result detail.

tests/Feature/Lottery/WeeklyResultDetailSecurityTest.php
# TYPE: feature_test
# PURPOSE: Public/private field boundary.

tests/Feature/Lottery/WeeklyResultDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Number formatting integrity.
```

---

# PAGE 30 — MEGA LOTTERY

# IMPORTANT NAMING RULE

The live website exposes the product as:

`Mega Lottery`

while the legacy route family is:

`/bingo-lottery.php`

and the modern application route family is:

`/bingo-lottery`

The audit explicitly identifies this naming/URL difference.

Therefore:

- URL architecture may remain `bingo-lottery`
- PUBLIC DISPLAY LABEL should be the approved product name
- do not silently rename working routes
- do not break legacy aliases
- do not invent a second Mega route family

---

## OBJECTIVE

Create:

`30. MEGA LOTTERY`

---

## BACKEND

Use existing Bingo/Mega lane architecture.

Inspect:

- product registry
- draw service
- result service
- archive service
- search
- provenance
- configuration

Do not copy Weekly rules.

---

## VIEW

```text id="czf7cp"
resources/views/bingo-lottery/
└── index.blade.php
# TYPE: blade_view
# PURPOSE: Public Mega Lottery landing/result page using canonical bingo-lottery route family.

resources/views/components/mega-lottery/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Premium Mega Lottery hero.

├── latest-result.blade.php
# TYPE: blade_component
# PURPOSE: Latest actual published Mega result.

├── result-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: Actual Mega result categories.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Year/archive navigation.

├── search-entry.blade.php
# TYPE: blade_component
# PURPOSE: Result search.

└── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Public result provenance.
```

---

## DESIGN

Mega should have its own visual identity:

- large celestial/glass orb
- dark spatial background
- premium gold
- floating result balls
- deep 3D depth
- jackpot visual only when real source provides one

Never display fake jackpot values.

---

## TESTS

```text id="3zjj56"
tests/Feature/Lottery/MegaLotteryPageTest.php
# TYPE: feature_test
# PURPOSE: Mega landing page.

tests/Feature/Lottery/MegaLotteryIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Product/result-source integrity.

tests/Feature/Lottery/MegaLotteryLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH.
```

---

# PAGE 31 — MEGA LOTTERY BUY / TICKET SELECTION

## OBJECTIVE

Create:

`31. MEGA LOTTERY — BUY / TICKET SELECTION`

This is a potentially financial workflow.

Do NOT create a purchase system just for UI.

---

# FIRST — VERIFY PRODUCT PURCHASE CONTRACT

Determine from actual source:

- Is Mega purchasable?
- What is the selection model?
- Is there a ticket model?
- Is there a bet model?
- What is the price rule?
- Is there a draw-specific mapping?
- Is discount applicable?
- Is wallet reservation supported?
- Is responsible gaming enforced?
- Is idempotency implemented?
- Is ledger linkage implemented?

---

# FAIL-CLOSED RULE

If these cannot be proven:

render:

`NOT_CONFIGURED`

and disable purchase.

Do NOT accept:

- numbers
- price
- quantity
- wallet instructions
- fake purchase request
- fake payment
- fake success

This is mandatory.

---

## VIEW

```text id="t8nq9u"
resources/views/bingo-lottery/
└── buy.blade.php
# TYPE: blade_view
# PURPOSE: Mega purchase entry; functional only when canonical backend purchase contract is verified.

resources/views/components/mega-buy/
├── draw-selector.blade.php
# TYPE: blade_component
# PURPOSE: Eligible Mega draw selection.

├── selection-panel.blade.php
# TYPE: blade_component
# PURPOSE: Product-specific selection interface.

├── unavailable-state.blade.php
# TYPE: blade_component
# PURPOSE: NOT_CONFIGURED fail-closed purchase state.

├── price-summary.blade.php
# TYPE: blade_component
# PURPOSE: Server-authoritative amount display.

├── bet-slip.blade.php
# TYPE: blade_component
# PURPOSE: Purchase summary when workflow exists.

└── purchase-actions.blade.php
# TYPE: blade_component
# PURPOSE: Real purchase submission only when backend supports it.
```

---

# IF VERIFIED PURCHASE EXISTS

Required flow:

```text
Selection
→ Server validation
→ Product rules
→ Price
→ Discount
→ Responsible Gaming
→ Wallet availability
→ Reservation
→ Purchase
→ Ledger
→ Ticket/Bet
→ Confirmation
```

The browser must never be authoritative.

---

# TESTS

```text id="1f17hp"
tests/Feature/Lottery/MegaPurchasePageTest.php
# TYPE: feature_test
# PURPOSE: Mega purchase page behavior.

tests/Feature/Lottery/MegaPurchaseConfigurationTest.php
# TYPE: feature_test
# PURPOSE: Verify verified/configured vs NOT_CONFIGURED behavior.

tests/Feature/Lottery/MegaPurchaseSecurityTest.php
# TYPE: feature_test
# PURPOSE: Authorization, price manipulation and account restrictions.

tests/Feature/Lottery/MegaPurchaseReplayTest.php
# TYPE: feature_test
# PURPOSE: Duplicate/replay protection when purchase exists.

tests/Feature/Lottery/MegaPurchaseWalletTest.php
# TYPE: feature_test
# PURPOSE: Wallet reservation/debit/ledger integrity when purchase exists.
```

---

# PAGE 32 — MEGA LOTTERY DRAW DETAIL

## OBJECTIVE

Create:

`32. MEGA LOTTERY — DRAW DETAIL`

Use:

```text
/bingo-lottery/{draw}
```

or the actual canonical route.

---

## VIEW

```text id="2ov7dl"
resources/views/bingo-lottery/
└── draw-detail.blade.php
# TYPE: blade_view
# PURPOSE: Mega single-draw detail.

resources/views/components/mega-draw/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw/date/status.

├── winning-numbers.blade.php
# TYPE: blade_component
# PURPOSE: Mega winning numbers.

├── prize-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: Actual prize/result categories.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Result-source state.

└── navigation.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next actual Mega draw navigation.
```

---

## TESTS

```text id="0ts33h"
tests/Feature/Lottery/MegaDrawDetailTest.php
# TYPE: feature_test
# PURPOSE: Draw detail.

tests/Feature/Lottery/MegaDrawDetailIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Lane/draw/result integrity.

tests/Feature/Lottery/MegaDrawDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Number string preservation.
```

---

# PAGE 33 — MEGA LOTTERY LATEST RESULT

## OBJECTIVE

Create:

`33. MEGA LOTTERY — LATEST RESULT`

Latest must be backend-selected.

---

## VIEW

```text id="l9zjnn"
resources/views/bingo-lottery/
└── latest-result.blade.php
# TYPE: blade_view
# PURPOSE: Latest Mega result page.

resources/views/components/mega-latest/
├── hero-result.blade.php
# TYPE: blade_component
# PURPOSE: Main latest Mega result.

├── secondary-results.blade.php
# TYPE: blade_component
# PURPOSE: Additional result categories.

├── draw-summary.blade.php
# TYPE: blade_component
# PURPOSE: Date/status/source.

└── verify-cta.blade.php
# TYPE: blade_component
# PURPOSE: Real Prize Verification destination.
```

---

## DATA RULE

Only show verified/publicly published results.

Do not use fixture/demo data.

---

## TESTS

```text id="w2r28p"
tests/Feature/Lottery/MegaLatestResultTest.php
# TYPE: feature_test
# PURPOSE: Latest-result selection.

tests/Feature/Lottery/MegaLatestResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Publication/provenance.

tests/Feature/Lottery/MegaLatestResultLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Leading-zero integrity.
```

---

# PAGE 34 — MEGA LOTTERY HISTORICAL RESULTS

## OBJECTIVE

Create:

`34. MEGA LOTTERY — HISTORICAL RESULTS`

The live Mega/Bingo archive architecture is year based.

The source audit identifies the live Mega/Bingo chain around:

- 2568
- 2567
- 2566
- 2565

Use only years actually supported by the backend/import data.

Do not create fake historical rows to fill the UI.

---

## BACKEND

Use existing Bingo/Mega:

- history
- archive
- search
- year filtering
- provenance

Do not duplicate Weekly history logic if a shared generic result-history service already exists.

---

## VIEW

```text id="e5ne52"
resources/views/bingo-lottery/
└── history.blade.php
# TYPE: blade_view
# PURPOSE: Mega historical result browser.

resources/views/components/mega-history/
├── filters.blade.php
# TYPE: blade_component
# PURPOSE: Search/year/date filters.

├── result-table.blade.php
# TYPE: blade_component
# PURPOSE: Desktop historical table.

├── mobile-result-card.blade.php
# TYPE: blade_component
# PURPOSE: Mobile result cards.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Actual year navigation.

└── empty-state.blade.php
# TYPE: blade_component
# PURPOSE: Honest empty-state rendering.
```

---

## TABLE RULE

Only show columns supported by the actual Mega result schema.

Never copy National/Weekly columns blindly.

---

## TESTS

```text id="h10x0e"
tests/Feature/Lottery/MegaHistoryPageTest.php
# TYPE: feature_test
# PURPOSE: Historical Mega page.

tests/Feature/Lottery/MegaHistoryIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Historical row/result/provenance integrity.

tests/Feature/Lottery/MegaHistoryYearFilterTest.php
# TYPE: feature_test
# PURPOSE: Year filtering.

tests/Feature/Lottery/MegaHistoryLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: String-number integrity.
```

---

# GLOBAL WEEKLY + MEGA DESIGN SYSTEM

Use shared lottery primitives where possible.

Create/extend only actual required styles:

```text id="u4ms4i"
resources/css/pages/
├── weekly-draw.css
# TYPE: stylesheet
# PURPOSE: Weekly draw-detail visual system.

├── weekly-result.css
# TYPE: stylesheet
# PURPOSE: Weekly latest/result detail visuals.

├── weekly-history.css
# TYPE: stylesheet
# PURPOSE: Weekly archive/history visuals.

├── mega-lottery.css
# TYPE: stylesheet
# PURPOSE: Mega landing visual system.

├── mega-buy.css
# TYPE: stylesheet
# PURPOSE: Mega purchase/fail-closed visual system.

└── mega-result.css
# TYPE: stylesheet
# PURPOSE: Mega draw/result/history visuals.
```

Do not duplicate global design tokens from Home.

---

# GLOBAL JAVASCRIPT

Only add JavaScript that is necessary.

```text id="v9c0db"
resources/js/pages/
├── weekly-lottery.js
# TYPE: javascript
# PURPOSE: Weekly filters, search enhancements and progressive result interactions.

├── weekly-buy.js
# TYPE: javascript
# PURPOSE: Weekly purchase-selection UI only; never source of financial truth.

├── mega-lottery.js
# TYPE: javascript
# PURPOSE: Mega filtering/navigation/progressive enhancement.

└── mega-buy.js
# TYPE: javascript
# PURPOSE: Mega purchase UI only when backend purchase is genuinely configured.
```

No unnecessary polling.

No client-side financial calculations.

---

# API REQUIREMENT

Reuse existing result APIs.

Potential APIs may include:

```text
GET /api/v1/...
```

for:

- latest results
- draw detail
- history
- search
- product information

Do not create duplicate APIs when an equivalent canonical endpoint exists.

---

# API RESPONSE RULE

Public response must contain only:

- public draw data
- public result data
- public dates
- public product information
- public status

Never expose:

- internal user IDs
- wallet IDs
- ledger IDs
- private operator notes
- import secrets
- webhook secrets
- provider credentials

---

# PURCHASE API RULE

For Pages 24/31 and any other purchase screen:

if canonical purchase capability is missing:

DO NOT add a fake API.

Return/display:

`NOT_CONFIGURED`

and keep the purchase action disabled.

---

# RESULT SEARCH RULE

Search must query actual backend/indexed data.

Do not fetch all history into JavaScript.

Apply:

- input validation
- rate limiting where appropriate
- safe query building
- exact number handling

---

# ARCHIVE RULE

For every displayed year:

verify:

```text
route
+
lane
+
year
+
actual records
+
publication state
```

A missing data bundle should result in:

`EMPTY / DATA IMPORT REQUIRED`

not fabricated rows.

---

# SEO RULE

Every page:

- unique title
- unique description
- canonical
- valid H1
- correct breadcrumbs where supported
- no duplicate archive metadata

Result detail:

derive SEO from actual result metadata.

---

# ACCESSIBILITY RULE

All ten pages:

- keyboard accessible
- focus-visible
- semantic headings
- accessible number presentation
- screen-reader friendly result values
- responsive tables
- reduced-motion
- sufficient contrast
- touch-friendly mobile controls

---

# RESPONSIVE RULE

## Desktop

Rich 3D result presentation.

## Tablet

Responsive cards + simplified tables.

## Mobile

Never force an unreadable wide result table.

Use:

- result cards
- stacked sections
- horizontal scroll only where absolutely necessary
- large number rendering
- touch-friendly search/filter

No overflow.

---

# SECURITY REQUIREMENT

Inspect all ten pages for:

- XSS
- unsafe result HTML
- SQL injection
- IDOR
- unauthorized draw access
- unauthorized purchase
- price manipulation
- discount manipulation
- quantity manipulation
- replay
- CSRF
- rate-limit bypass
- private data leakage

---

# CROSS-PAGE CONSISTENCY

Compare these pages against:

- Page 16 National
- Page 23 Weekly
- Page 30 Mega
- Fees
- Discounts
- Account Grade
- Responsible Gaming
- Prize Verification
- Wallet
- Terms

Detect contradictions in:

- pricing
- discount
- product name
- result status
- eligibility
- purchase availability
- fees

Do not silently resolve contradictions.

---

# LEGACY URL REQUIREMENT

Verify:

```text
/weekly-lottery.php
/bingo-lottery.php
```

and relevant year/archive aliases.

Use existing:

`LegacyRedirectController`

or canonical legacy bridge.

Do NOT create a second redirect architecture.

The existing audit identifies numbered/archive and typo-form compatibility as explicit targets; preserve those mappings.

---

# TEST INVENTORY

At minimum the implementation must cover:

## Weekly

[ ] Page 25 Draw Detail
[ ] Page 26 Latest Result
[ ] Page 27 Historical Results
[ ] Page 28 Year Archive
[ ] Page 29 Result Detail

## Mega

[ ] Page 30 Mega Landing
[ ] Page 31 Mega Buy
[ ] Page 32 Mega Draw Detail
[ ] Page 33 Mega Latest Result
[ ] Page 34 Mega Historical Results

For all:

[ ] route
[ ] real backend source
[ ] no fabricated data
[ ] provenance
[ ] leading zero
[ ] empty state
[ ] localization
[ ] accessibility
[ ] SEO
[ ] security
[ ] mobile
[ ] desktop
[ ] legacy compatibility

For purchase:

[ ] backend capability check
[ ] fail closed
[ ] server-authoritative price
[ ] wallet
[ ] reservation
[ ] ledger
[ ] idempotency
[ ] responsible gaming

Only test these purchase items when the actual source has the corresponding capability.

---

# FILE TREE OUTPUT RULE

For every actual created/modified file return:

```text id="z1o1l5"
path/to/file
# TYPE: controller/service/model/view/component/css/js/test/config/etc.
# PURPOSE: exact responsibility.
```

Every file MUST have the `# TYPE` and `# PURPOSE` comments.

---

# COMPLETE FILE CONTENT RULE

For every modified/created file:

return FULL CONTENT.

No:

`...`

No:

`existing code`

No:

`unchanged`

No:

`omitted`

---

# REQUIRED FINAL REPORT

Return:

## A. IMPLEMENTATION SUMMARY

Separate Pages 25–34.

## B. COMPLETE ACTUAL FILE TREE

Only files actually touched.

## C. COMPLETE FILE CONTENT

Every changed/created file in full.

## D. WEEKLY DATA FLOW

```text id="7j7b3w"
Page
→ Route
→ Controller
→ Service
→ Query/Model
→ Result Source
→ Provenance
→ View
```

## E. MEGA DATA FLOW

Same structure.

## F. PURCHASE FLOW

For Page 31:

```text
UI
→ Capability Check
→ Selection
→ Server Validation
→ Price
→ Discount
→ RG
→ Wallet
→ Reservation
→ Purchase
→ Ledger
→ Confirmation
```

OR:

`NOT_CONFIGURED`

when the actual purchase mapping cannot be proven.

## G. API MAP

Every actual endpoint used/created.

## H. LIVE COMPARISON

For:

- Weekly
- Mega

Clearly separate:

`OBSERVED LIVE`

`SOURCE VERIFIED`

`IMPLEMENTED`

`NOT VERIFIED`

`DATA IMPORT REQUIRED`

`NOT_CONFIGURED`

## I. TEST RESULTS

Provide exact commands and exact runtime output.

Do NOT claim tests passed if they were not executed.

## J. REMAINING BLOCKERS

Only actual blockers.

---

# FINAL HONESTY RULE

Do NOT report:

`100% production ready`

just because Pages 25–34 look complete.

Report independently:

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

When PHP/runtime/vendor is unavailable:

`NOT VERIFIED — RUNTIME UNAVAILABLE`

When historical data is absent:

`DATA IMPORT REQUIRED`

When a purchase mapping cannot be established:

`NOT_CONFIGURED — FAIL CLOSED`

When external payment/provider verification is required:

`EXTERNAL VERIFICATION REQUIRED`

---

# DEFINITION OF DONE

Pages 25–34 are complete only when every page is independently implemented and:

[ ] no page skipped
[ ] no duplicate architecture
[ ] no fake result
[ ] no fake jackpot
[ ] no fake price
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
[ ] tests added/updated
[ ] available tests executed
[ ] legacy compatibility reviewed
[ ] complete file contents provided
[ ] no placeholder code
[ ] no truncated code
[ ] no fake purchase flow

---

# PAGE ORDER LOCK

Implement exactly:

25 → Weekly Draw Detail

26 → Weekly Latest Result

27 → Weekly Historical Results

28 → Weekly Year Archive

29 → Weekly Result Detail

30 → Mega Lottery

31 → Mega Buy / Ticket Selection

32 → Mega Draw Detail

33 → Mega Latest Result

34 → Mega Historical Results

DO NOT move to Page 35 until ALL ten pages are implemented and reported.