# PROMPT 15–24 — THAILOTTO LOTTERY / RESULTS EXPERIENCE
# PAGE 15 → PAGE 24
# WORLD-CLASS 3D GLASS + REAL LOTTERY DATA + REAL BACKEND/API + ZERO FABRICATION

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

Implement the NEXT TEN PAGES:

15. Lottery Hub / All Lotteries
16. National Lottery
17. National Lottery — Buy / Ticket Selection
18. National Lottery — Draw Detail
19. National Lottery — Latest Result
20. National Lottery — Historical Results
21. National Lottery — Year Archive
22. National Lottery — Result Detail
23. Weekly Lottery
24. Weekly Lottery — Buy / Ticket Selection

Do NOT skip any page.

Do NOT merge separate pages silently.

Do NOT turn a page into a static mockup.

Every dynamic lottery/result value must have an authoritative backend source.

---

# 1. FIRST — INSPECT THE EXISTING SOURCE

Before implementation, inspect the actual latest repository.

Locate:

- lottery routes
- result routes
- search routes
- year archive routes
- draw detail routes
- lottery controllers
- result controllers
- ticket controllers
- bet controllers
- ticket/bet services
- GLO services
- weekly-lottery services
- result models
- draw models
- ticket models
- price rules
- discount rules
- account-grade rules
- availability rules
- provenance services
- archive services
- localization
- shared lottery components
- existing Home components
- existing player purchase flow
- current API contracts
- current pagination/search architecture
- existing tests

Reuse canonical architecture.

---

# 2. EXISTING ROUTE CONTRACT

The current source architecture includes modern lottery/result route families such as:

```text
/national-lottery
/national-lottery/search
/national-lottery/year/{year}
/national-lottery/{draw}

/weekly-lottery
/weekly-lottery/search
/weekly-lottery/year/{year}
/weekly-lottery/{draw}

/bingo-lottery
/bingo-lottery/search
/bingo-lottery/year/{year}
/bingo-lottery/{draw}

/pcso-lottery
/pcso-lottery/search
/pcso-lottery/year/{year}
/pcso-lottery/{draw}
```

Use the repository's actual route names and parameters.

Do NOT rename working routes simply for visual design.

---

# 3. NON-NEGOTIABLE FILE RULE

For every created or modified file:

OUTPUT THE COMPLETE FILE CONTENT.

NEVER use:

```text id="euhvzw"
# ... existing code ...
// ... existing code ...
/* existing code */
...
TODO
FIXME
```

Never truncate.

Never provide only a patch fragment when complete file content is requested.

Preserve all existing valid logic.

---

# 4. NO FAKE LOTTERY DATA

Never invent:

- draw dates
- winning numbers
- prize values
- jackpots
- ticket inventory
- ticket prices
- winner counts
- result status
- sold quantity
- odds
- payout values
- archive years

If the database/source does not contain data:

render an honest empty/unavailable state.

Do not place fixture results into production UI.

---

# 5. LEADING ZERO RULE

This is critical.

Lottery numbers are strings, not integers.

Examples:

```text id="2ra1y2"
004615
077
039
08
04
```

must remain exactly:

```text id="zqmwqe"
004615
077
039
08
04
```

Never cast public lottery numbers to integers.

Never use numeric formatting that strips zeroes.

Tests must explicitly cover leading zeros.

---

# 6. RESULT PROVENANCE RULE

Every displayed result must carry a trustworthy source state where supported.

Possible states:

```text id="sa6fd8"
VERIFIED
SOURCE VERIFIED
PENDING
UNAVAILABLE
REJECTED
```

Do not expose internal provenance secrets.

Do not show an unverified result as verified.

Do not silently downgrade verification state.

---

# 7. GLO / NATIONAL PRODUCT DISTINCTION

Do NOT assume National Lottery page logic is identical to generic 2D/3D products.

Inspect the actual National/GLO architecture.

Where the product is GLO L6:

use the canonical GLO L6 rules/services.

Do not copy generic betting logic into GLO.

---

# 8. VISUAL DESIGN SYSTEM

Continue the established:

- dark luxury
- premium gold
- 3D glass
- cinematic depth
- soft reflections
- glass blur
- premium cards
- realistic lottery balls
- polished typography
- responsive mobile-native UX

Lottery pages should feel:

`DATA-RICH + PREMIUM + FAST + TRUSTED`

not like a generic table.

---

# 9. SHARED LOTTERY COMPONENT TREE

Reuse components whenever possible.

Potential structure:

```text id="0i8d1z"
resources/views/components/lottery/
├── lottery-hero.blade.php
# TYPE: blade_component
# PURPOSE: Shared lottery hero visual and metadata.

├── draw-status.blade.php
# TYPE: blade_component
# PURPOSE: Draw status, date, time and state.

├── result-number.blade.php
# TYPE: blade_component
# PURPOSE: Safe leading-zero-preserving result-number renderer.

├── result-row.blade.php
# TYPE: blade_component
# PURPOSE: One result category row.

├── result-card.blade.php
# TYPE: blade_component
# PURPOSE: Premium result summary card.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Year archive navigation.

├── search.blade.php
# TYPE: blade_component
# PURPOSE: Lottery result search form.

├── provenance-badge.blade.php
# TYPE: blade_component
# PURPOSE: Public provenance/status presentation.

└── empty-state.blade.php
    # TYPE: blade_component
    # PURPOSE: Honest no-data/unavailable result state.
```

Do NOT create duplicates if equivalent shared components already exist.

---

# PAGE 15 — LOTTERY HUB / ALL LOTTERIES

## OBJECTIVE

Create:

`15. LOTTERY HUB / ALL LOTTERIES`

This is the master public product discovery page.

It should let users discover all currently supported public lottery products.

---

## BACKEND

Reuse canonical lottery registry/product feed.

Possible source:

```text id="bq4b6a"
app/Services/...
# TYPE: service layer
# PURPOSE: Existing canonical lottery product registry/feed; provide enabled public products and metadata.
```

If the repository lacks a proper product registry:

create:

```text id="h8tx2e"
app/Services/Lottery/PublicLotteryCatalogService.php
# TYPE: service
# PURPOSE: Canonical public lottery catalogue resolving product identity, availability, display metadata and actual routes.
```

Do NOT hardcode products separately in Blade.

---

## VIEW

```text id="dm0f0n"
resources/views/lotteries/
└── index.blade.php
# TYPE: blade_view
# PURPOSE: Master lottery discovery page.

resources/views/components/lottery-hub/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Cinematic lottery discovery hero.

├── featured-lotteries.blade.php
# TYPE: blade_component
# PURPOSE: Premium featured product cards from backend.

├── category-filter.blade.php
# TYPE: blade_component
# PURPOSE: Filter supported public markets/categories.

├── compare.blade.php
# TYPE: blade_component
# PURPOSE: Accessible comparison of configured products.

└── results-cta.blade.php
# TYPE: blade_component
# PURPOSE: Link to real result pages.
```

---

## CARD DATA

Each product may include:

- product key
- public name
- market
- frequency
- result route
- purchase route when supported
- current status
- public price when approved
- short description
- visual icon

Only actual backend-supported products.

---

## DESIGN

Hero:

“Explore Our Lotteries”

Then premium cards:

- National
- Weekly
- Mega/Bingo
- PCSO
- GLO L6 where separately exposed

Do not label a product “official” unless approved.

---

## TESTS

```text id="t8uhpl"
tests/Feature/Lottery/LotteryHubPageTest.php
# TYPE: feature_test
# PURPOSE: Product catalogue rendering.

tests/Feature/Lottery/LotteryCatalogIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Product cards must match canonical enabled product registry.

tests/Feature/Lottery/LotteryHubLinkIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Every card links to a valid route.
```

---

# PAGE 16 — NATIONAL LOTTERY

## OBJECTIVE

Create:

`16. NATIONAL LOTTERY`

This is the main National Lottery landing/result page.

The live National page currently presents a 2569 results table with:

- 1st Prize
- 3Up
- 2Up
- 3Front-3After
- 2Down

and date-based rows.

The new page should preserve this information architecture while significantly improving UX and data integrity.

---

## BACKEND

Reuse actual National result services.

Potential canonical layer:

```text id="42xo3g"
app/Services/Lottery/
# TYPE: service layer
# PURPOSE: Existing National result/draw retrieval and provenance services.

app/Models/
# TYPE: model layer
# PURPOSE: Existing Draw/DrawResult/result entities.
```

Do not create a duplicate NationalResultService if one already exists.

---

## VIEW

```text id="hwy5cm"
resources/views/national-lottery/
└── index.blade.php
# TYPE: blade_view
# PURPOSE: National Lottery public landing page with current/latest results and discovery navigation.

resources/views/components/national-lottery/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: National Lottery hero and draw status.

├── latest-result.blade.php
# TYPE: blade_component
# PURPOSE: Latest verified National result summary.

├── result-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: 1st/3Up/2Up/Front/After/2Down result presentation.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Year navigation.

├── search-entry.blade.php
# TYPE: blade_component
# PURPOSE: Result search entry.

└── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Result-source/provenance status.
```

---

## NUMBER DISPLAY

Use premium 3D number balls for major numbers.

Example:

```text
730640
640
40
060
521
266
041
64
```

Keep every number as string.

The live page itself demonstrates values such as `004615`, `077`, `08`; this confirms that leading-zero preservation is essential.

---

## SEARCH

Real backend search.

Do not filter only already-loaded HTML if the repository supports server-side search.

Use existing:

`/national-lottery/search`

contract.

---

## TESTS

```text id="i7z4if"
tests/Feature/Lottery/NationalLotteryPageTest.php
# TYPE: feature_test
# PURPOSE: Main National page.

tests/Feature/Lottery/NationalLotteryResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Result values/provenance.

tests/Feature/Lottery/NationalLotteryLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Preserve numeric strings.

tests/Feature/Lottery/NationalLotterySearchTest.php
# TYPE: feature_test
# PURPOSE: Search workflow.

tests/Feature/Lottery/NationalLotteryLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH.
```

---

# PAGE 17 — NATIONAL LOTTERY BUY / TICKET SELECTION

## OBJECTIVE

Create:

`17. NATIONAL LOTTERY — BUY / TICKET SELECTION`

This is a functional purchasing page.

Do NOT build a fake ticket selector.

Trace the real purchase architecture first.

---

## BACKEND FLOW

Required trace:

```text id="sv1fqc"
Product
→ Draw
→ Ticket / Bet Selection
→ Availability
→ Price
→ Discount if applicable
→ Responsible Gaming
→ Wallet/payment requirement
→ Reservation
→ Purchase
→ Ticket issuance
→ Confirmation
```

Reuse canonical existing services.

Potential files:

```text id="4nwbio"
app/Http/Controllers/Web/BetPurchaseController.php
# TYPE: controller
# PURPOSE: Existing canonical authenticated purchase endpoint.

app/Services/Betting/BulkBetService.php
# TYPE: service
# PURPOSE: Existing purchase engine; use rather than rebuilding financial logic.

app/Services/Wallet/WalletReservationService.php
# TYPE: service
# PURPOSE: Existing wallet reservation logic.
```

If actual repository uses different classes:

use those.

---

## VIEW

```text id="7gs7h6"
resources/views/national-lottery/
└── buy.blade.php
# TYPE: blade_view
# PURPOSE: National Lottery purchase/ticket-selection screen.

resources/views/components/national-buy/
├── draw-selector.blade.php
# TYPE: blade_component
# PURPOSE: Select eligible draw.

├── ticket-selector.blade.php
# TYPE: blade_component
# PURPOSE: Ticket/number selection UI driven by actual product rules.

├── price-summary.blade.php
# TYPE: blade_component
# PURPOSE: Server-authoritative price, discount, fee and total.

├── wallet-status.blade.php
# TYPE: blade_component
# PURPOSE: Safe available-balance summary for authenticated user.

├── bet-slip.blade.php
# TYPE: blade_component
# PURPOSE: Purchase summary.

└── purchase-actions.blade.php
# TYPE: blade_component
# PURPOSE: Confirmation CTA using real purchase route.
```

---

## PRICE SECURITY

Client/browser must never be authoritative for:

- ticket price
- discount
- fee
- total
- payout
- wallet debit

Server recalculates everything.

---

## GUEST BEHAVIOR

Guest may inspect the product where configured.

Purchase action must route to authentication if required.

Do not expose a fake purchase success state.

---

## RESPONSIBLE GAMING

Before purchase:

verify actual responsible-gaming restrictions and limits.

Do not bypass:

- self-exclusion
- single-bet limit
- deposit restrictions
- account restrictions

---

## TESTS

```text id="dq7yv7"
tests/Feature/Lottery/NationalLotteryPurchasePageTest.php
# TYPE: feature_test
# PURPOSE: Purchase page/auth state.

tests/Feature/Lottery/NationalLotteryPurchaseIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Server-authoritative amount/discount/fee.

tests/Feature/Lottery/NationalLotteryPurchaseSecurityTest.php
# TYPE: feature_test
# PURPOSE: Prevent price manipulation and unauthorized purchase.

tests/Feature/Lottery/NationalLotteryPurchaseReplayTest.php
# TYPE: feature_test
# PURPOSE: Idempotent replay protection.
```

---

# PAGE 18 — NATIONAL LOTTERY DRAW DETAIL

## OBJECTIVE

Create:

`18. NATIONAL LOTTERY — DRAW DETAIL`

This is the canonical single-draw page.

---

## BACKEND

Use:

```text id="3od3qb"
Draw model
# TYPE: model
# PURPOSE: Canonical draw entity.

DrawResult model/service
# TYPE: model/service
# PURPOSE: Canonical published result.

Provenance service
# TYPE: service
# PURPOSE: Result authenticity/provenance.
```

Do not manually assemble draw data from unrelated tables in Blade.

---

## VIEW

```text id="s60q9i"
resources/views/national-lottery/
└── draw-detail.blade.php
# TYPE: blade_view
# PURPOSE: Full National draw detail page.

resources/views/components/national-draw/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw number/date/status.

├── winning-numbers.blade.php
# TYPE: blade_component
# PURPOSE: Main winning number presentation.

├── prize-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: Configured public prize categories.

├── publication-state.blade.php
# TYPE: blade_component
# PURPOSE: Published/pending/verification state.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Source/provenance display.

└── navigation.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next draw navigation where supported by real routes.
```

---

## SECURITY

Do not expose:

- internal result-import metadata
- cryptographic secrets
- internal database IDs unless safe
- operator-only notes

---

## TESTS

```text id="90kd6v"
tests/Feature/Lottery/NationalDrawDetailTest.php
# TYPE: feature_test
# PURPOSE: Draw detail rendering.

tests/Feature/Lottery/NationalDrawProvenanceTest.php
# TYPE: feature_test
# PURPOSE: Published result provenance.

tests/Feature/Lottery/NationalDrawNavigationTest.php
# TYPE: feature_test
# PURPOSE: Previous/next navigation.
```

---

# PAGE 19 — NATIONAL LOTTERY LATEST RESULT

## OBJECTIVE

Create:

`19. NATIONAL LOTTERY — LATEST RESULT`

This is a result-first page optimized for quick consumption.

---

## DESIGN

Hero:

```text id="spgqk0"
LATEST NATIONAL RESULT

Draw date
Draw status

6-digit first prize
```

Below:

```text id="bl28i9"
3Up
2Up
3Front
3After
2Down
```

Every number visually prominent.

---

## BACKEND

Use the same canonical result service as Page 16.

No duplicate result logic.

---

## VIEW

```text id="9kl2h4"
resources/views/national-lottery/
└── latest-result.blade.php
# TYPE: blade_view
# PURPOSE: Latest verified National result experience.

resources/views/components/national-latest/
├── hero-result.blade.php
# TYPE: blade_component
# PURPOSE: Main winning number.

├── secondary-results.blade.php
# TYPE: blade_component
# PURPOSE: Secondary prize-number groups.

└── verify-cta.blade.php
# TYPE: blade_component
# PURPOSE: Link to real Prize Verification.
```

---

## DATA RULE

Never determine “latest” by:

- array position in frontend
- JavaScript current date
- filename
- hardcoded date

Use backend authoritative draw ordering.

---

## TESTS

```text id="v3fu2b"
tests/Feature/Lottery/NationalLatestResultTest.php
# TYPE: feature_test
# PURPOSE: Latest-result selection.

tests/Feature/Lottery/NationalLatestResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Verified-result-only rule.

tests/Feature/Lottery/NationalLatestResultLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Numeric string integrity.
```

---

# PAGE 20 — NATIONAL LOTTERY HISTORICAL RESULTS

## OBJECTIVE

Create:

`20. NATIONAL LOTTERY — HISTORICAL RESULTS`

This page is the central historical data browser.

The live National site exposes year/archive history, not numeric “1–100” pagination. The audit specifically records year/archive navigation for National history.

---

## BACKEND

Use existing archive/result query services.

Must support:

- year
- date
- draw
- search
- pagination if actual route supports it

Do not load the entire history unnecessarily.

---

## VIEW

```text id="7h09ne"
resources/views/national-lottery/
└── history.blade.php
# TYPE: blade_view
# PURPOSE: Historical National results browser.

resources/views/components/national-history/
├── filters.blade.php
# TYPE: blade_component
# PURPOSE: Year/date/search filters.

├── result-table.blade.php
# TYPE: blade_component
# PURPOSE: Accessible result table.

├── mobile-result-card.blade.php
# TYPE: blade_component
# PURPOSE: Mobile-native historical result cards.

├── pagination.blade.php
# TYPE: blade_component
# PURPOSE: Existing pagination contract if applicable.

└── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Year navigation.
```

---

## TABLE

Columns according to actual result schema.

For National, live observable columns include:

- Date
- 1st Prize
- 3Up
- 2Up
- 3Front-3After
- 2Down

Do not display columns with no actual data.

---

## DATA QUALITY

Handle:

- missing results
- duplicate historical rows
- malformed imported data
- unavailable years
- result provenance
- timezone

Do not render corrupted input as verified.

---

## TESTS

```text id="8w3zsn"
tests/Feature/Lottery/NationalHistoryPageTest.php
# TYPE: feature_test
# PURPOSE: History page.

tests/Feature/Lottery/NationalHistoryDataIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Historical result integrity.

tests/Feature/Lottery/NationalHistoryYearFilterTest.php
# TYPE: feature_test
# PURPOSE: Year filtering.

tests/Feature/Lottery/NationalHistorySearchTest.php
# TYPE: feature_test
# PURPOSE: Search/filter.
```

---

# PAGE 21 — NATIONAL LOTTERY YEAR ARCHIVE

## OBJECTIVE

Create:

`21. NATIONAL LOTTERY — YEAR ARCHIVE`

Example:

`/national-lottery/year/2568`

Use actual configured route.

---

## BACKEND

Retrieve ONLY the requested year.

Validate:

- supported year format
- year exists/is public
- results belong to requested year
- no cross-year contamination

---

## VIEW

```text id="0f91c6"
resources/views/national-lottery/
└── year.blade.php
# TYPE: blade_view
# PURPOSE: National year-specific historical result archive.

resources/views/components/national-year/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Year archive hero.

├── archive-summary.blade.php
# TYPE: blade_component
# PURPOSE: Draw count/public archive metadata from actual data.

├── result-list.blade.php
# TYPE: blade_component
# PURPOSE: Year result list/table.

└── adjacent-years.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next year links from actual available years.
```

---

## SEO

Generate unique:

- title
- description
- canonical URL

based on actual year.

Do not create a page for a year with no data merely to generate SEO URLs unless the route architecture explicitly allows honest empty archives.

---

## TESTS

```text id="56km1m"
tests/Feature/Lottery/NationalYearArchiveTest.php
# TYPE: feature_test
# PURPOSE: Year-specific page.

tests/Feature/Lottery/NationalYearArchiveIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Correct year isolation.

tests/Feature/Lottery/NationalYearArchiveNavigationTest.php
# TYPE: feature_test
# PURPOSE: Adjacent-year navigation.
```

---

# PAGE 22 — NATIONAL LOTTERY RESULT DETAIL

## OBJECTIVE

Create:

`22. NATIONAL LOTTERY — RESULT DETAIL`

This is the detailed single-result presentation.

---

## ROUTE

Use existing:

`/national-lottery/{draw}`

or actual canonical equivalent.

---

## BACKEND

Resolve the draw via canonical identifier.

Do NOT resolve based on an arbitrary text search.

Verify:

- draw exists
- draw is public
- result is published
- provenance state
- result categories
- date/time
- timezone

---

## VIEW

```text id="j8m8w0"
resources/views/national-lottery/
└── result-detail.blade.php
# TYPE: blade_view
# PURPOSE: Full individual National result detail.

resources/views/components/national-result-detail/
├── header.blade.php
# TYPE: blade_component
# PURPOSE: Draw/date/status.

├── winning-number-hero.blade.php
# TYPE: blade_component
# PURPOSE: Main 6-digit winning number.

├── number-groups.blade.php
# TYPE: blade_component
# PURPOSE: All configured result categories.

├── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Public verification/source state.

├── prize-reference.blade.php
# TYPE: blade_component
# PURPOSE: Public prize information where available.

└── related-draws.blade.php
# TYPE: blade_component
# PURPOSE: Previous/next related draw links.
```

---

## LEADING ZERO

Mandatory.

Examples like:

`004615`

must render exactly.

---

## TESTS

```text id="44xhkp"
tests/Feature/Lottery/NationalResultDetailTest.php
# TYPE: feature_test
# PURPOSE: Single result detail.

tests/Feature/Lottery/NationalResultDetailSecurityTest.php
# TYPE: feature_test
# PURPOSE: Public/private field boundary.

tests/Feature/Lottery/NationalResultDetailLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: String preservation.
```

---

# PAGE 23 — WEEKLY LOTTERY

## OBJECTIVE

Create:

`23. WEEKLY LOTTERY`

The live Weekly result surface is year/date based and exposes columns such as:

- 6Ball
- 3Ball
- 2Ball

The live archive pages demonstrate number strings with leading zeroes such as `078153`, `039`, `08`.

---

## BACKEND

Reuse the canonical Weekly lottery lane.

Do not copy National service logic blindly.

Weekly-specific rules/data sources must remain separate where business behavior differs.

---

## VIEW

```text id="22zzpk"
resources/views/weekly-lottery/
└── index.blade.php
# TYPE: blade_view
# PURPOSE: Weekly Lottery public landing/result page.

resources/views/components/weekly-lottery/
├── hero.blade.php
# TYPE: blade_component
# PURPOSE: Weekly Lottery hero.

├── latest-result.blade.php
# TYPE: blade_component
# PURPOSE: Latest verified Weekly result.

├── result-breakdown.blade.php
# TYPE: blade_component
# PURPOSE: 6Ball/3Ball/2Ball presentation.

├── archive-nav.blade.php
# TYPE: blade_component
# PURPOSE: Year navigation.

├── search-entry.blade.php
# TYPE: blade_component
# PURPOSE: Search entry.

└── provenance.blade.php
# TYPE: blade_component
# PURPOSE: Source/provenance status.
```

---

## NUMBER RULES

Every numeric result is a string.

Examples:

```text id="0lknwu"
078153
039
08
```

must never be normalized to integer values.

---

## TESTS

```text id="2o0c8b"
tests/Feature/Lottery/WeeklyLotteryPageTest.php
# TYPE: feature_test
# PURPOSE: Weekly page.

tests/Feature/Lottery/WeeklyLotteryResultIntegrityTest.php
# TYPE: feature_test
# PURPOSE: Result/provenance integrity.

tests/Feature/Lottery/WeeklyLotteryLeadingZeroTest.php
# TYPE: feature_test
# PURPOSE: Leading-zero preservation.

tests/Feature/Lottery/WeeklyLotteryLocalizationTest.php
# TYPE: feature_test
# PURPOSE: EN/TH parity.
```

---

# PAGE 24 — WEEKLY LOTTERY BUY / TICKET SELECTION

## OBJECTIVE

Create:

`24. WEEKLY LOTTERY — BUY / TICKET SELECTION`

This is a functional purchase page.

---

## FIRST — TRACE ACTUAL WEEKLY PURCHASE MODEL

Determine whether Weekly uses:

- ticket purchase
- number wager
- combination selection
- bet slip
- reservation
- wallet debit
- account-grade discount

Do NOT assume it uses GLO L6 ticket logic.

---

## BACKEND FLOW

Required trace:

```text id="f40ywp"
Weekly Product
→ Eligible Draw
→ Selection Type
→ Number Validation
→ Price Rule
→ Discount Rule
→ Responsible Gaming
→ Wallet Availability
→ Reservation/Hold
→ Purchase
→ Ledger
→ Confirmation
```

Every financial operation must reuse the canonical wallet/ledger/payment architecture.

---

## VIEW

```text id="ko4pby"
resources/views/weekly-lottery/
└── buy.blade.php
# TYPE: blade_view
# PURPOSE: Weekly Lottery purchase/selection interface.

resources/views/components/weekly-buy/
├── draw-selector.blade.php
# TYPE: blade_component
# PURPOSE: Select eligible weekly draw.

├── selection-panel.blade.php
# TYPE: blade_component
# PURPOSE: Product-specific number/combination selector.

├── number-preview.blade.php
# TYPE: blade_component
# PURPOSE: Preserve user-entered leading zeros and normalized display.

├── price-summary.blade.php
# TYPE: blade_component
# PURPOSE: Server-calculated amount/discount/fee/total.

├── bet-slip.blade.php
# TYPE: blade_component
# PURPOSE: Purchase summary.

└── purchase-actions.blade.php
# TYPE: blade_component
# PURPOSE: Real purchase submission.
```

---

# WEEKLY NUMBER VALIDATION

Server must enforce the exact product rules.

Do not validate only with JavaScript.

Reject:

- invalid length
- invalid characters
- unsupported combination
- unavailable draw
- self-excluded user
- exceeded betting limit
- insufficient funds

---

# PRICE INTEGRITY

Browser must never decide:

- price
- discount
- fee
- total
- wallet debit

Server recalculates.

---

# TESTS

```text id="6q8p9g"
tests/Feature/Lottery/WeeklyLotteryPurchasePageTest.php
# TYPE: feature_test
# PURPOSE: Weekly purchase page.

tests/Feature/Lottery/WeeklyLotteryPurchaseValidationTest.php
# TYPE: feature_test
# PURPOSE: Server-side selection validation.

tests/Feature/Lottery/WeeklyLotteryPurchaseSecurityTest.php
# TYPE: feature_test
# PURPOSE: Authorization, price manipulation and account restrictions.

tests/Feature/Lottery/WeeklyLotteryPurchaseReplayTest.php
# TYPE: feature_test
# PURPOSE: Replay/idempotency.

tests/Feature/Lottery/WeeklyLotteryPurchaseWalletTest.php
# TYPE: feature_test
# PURPOSE: Wallet reservation/debit/ledger correctness.
```

---

# GLOBAL LOTTERY CSS

Use existing shared lottery design system where possible.

Add only page-specific styles:

```text id="f2x0cz"
resources/css/pages/
├── lottery-hub.css
# TYPE: stylesheet
# PURPOSE: Lottery Hub design.

├── national-lottery.css
# TYPE: stylesheet
# PURPOSE: National page design.

├── national-buy.css
# TYPE: stylesheet
# PURPOSE: National purchase interface.

├── national-draw.css
# TYPE: stylesheet
# PURPOSE: National draw detail.

├── national-result.css
# TYPE: stylesheet
# PURPOSE: National result detail/latest-result visuals.

└── weekly-lottery.css
# TYPE: stylesheet
# PURPOSE: Weekly pages shared styling.
```

Do not duplicate identical CSS.

---

# GLOBAL LOTTERY JAVASCRIPT

Create only when needed:

```text id="d0xmkf"
resources/js/pages/
├── lottery-hub.js
# TYPE: javascript
# PURPOSE: Product filters/accessible interactions.

├── national-lottery.js
# TYPE: javascript
# PURPOSE: National search/filter/tab enhancements.

├── national-buy.js
# TYPE: javascript
# PURPOSE: National ticket/selection UI and progressive enhancement; server remains authoritative.

├── national-result.js
# TYPE: javascript
# PURPOSE: Result presentation/navigation enhancements.

└── weekly-lottery.js
# TYPE: javascript
# PURPOSE: Weekly result/purchase progressive-enhancement logic.
```

No unnecessary polling.

No client-authoritative lottery calculations.

---

# API REQUIREMENT

Use existing APIs where available.

Possible endpoints:

```text
GET /api/v1/...
```

for:

- latest result
- search
- draw detail
- product availability
- ticket/selection metadata

Do not create duplicate API endpoints when equivalent APIs already exist.

For purchasing:

use the canonical authenticated purchase API/service.

Do not invent a second wallet debit API.

---

# API SECURITY

Verify:

- authentication
- authorization
- throttling
- validation
- rate limits
- idempotency
- request size
- response filtering
- no private data leakage

Public result APIs must not expose:

- internal user IDs
- wallet IDs
- ledger IDs
- internal import credentials
- provider secrets
- private audit metadata

---

# DATABASE REQUIREMENT

Do NOT create migrations unless a real missing persistent capability exists.

Before creating a migration:

inspect:

- Draw
- DrawResult
- Lottery
- Ticket
- Bet
- Wallet
- Ledger
- ResultProvenance
- Archive/import tables

Avoid duplicate tables.

---

# SEARCH REQUIREMENT

For National and Weekly search:

search actual indexed/backend records.

Do not download the entire result archive to the browser.

Validate:

- search input
- date
- year
- number
- result availability

Rate-limit public search where appropriate.

---

# ARCHIVE REQUIREMENT

Never assume:

“year link exists” = “data exists”.

For each archive:

- route exists
- year valid
- page renders
- actual records exist OR honest empty state
- no fake result rows
- adjacent year links point to actual available years

The prior audit specifically distinguishes archive-route existence from real historical data population.

---

# SEO REQUIREMENT

For every public lottery page:

- unique title
- unique description
- canonical
- correct H1
- structured headings
- archive canonicalization
- no duplicate-content issues

For result detail:

SEO data must be derived from actual result metadata.

Never generate fake result schema.

---

# ACCESSIBILITY

All lottery pages must support:

- keyboard navigation
- focus-visible
- screen readers
- high contrast
- accessible number labels
- table semantics
- mobile touch targets
- reduced motion

Lottery numbers must remain understandable when read by assistive technology.

---

# RESPONSIVE DESIGN

## Desktop

- cinematic hero
- rich result grid
- advanced table
- 3D number presentation

## Tablet

- balanced cards
- simplified table
- responsive search

## Mobile

- result cards rather than impossible wide tables
- sticky filter/search where useful
- large numbers
- touch-friendly controls
- no horizontal overflow

---

# SECURITY FOR PURCHASE PAGES

For Pages 17 and 24 explicitly test:

- unauthorized guest purchase
- another user's draft/selection isolation
- price tampering
- discount tampering
- quantity tampering
- stale draw
- expired draw
- duplicate submit
- network retry
- insufficient wallet
- self-exclusion
- betting limit
- wallet reservation
- ledger posting
- rollback on failure

---

# CROSS-PAGE CONSISTENCY

Before finishing Pages 15–24, compare against:

- Home
- Fees
- Discounts
- Account Grade
- Responsible Gaming
- Wallet
- Terms
- Prize Verification

Detect contradictions in:

- prices
- discounts
- fees
- result availability
- purchase eligibility
- draw status
- product naming

Do not silently change another page.

---

# LEGACY COMPATIBILITY

Verify all relevant live legacy URLs:

```text id="h5f55m"
/national-lottery.php
/weekly-lottery.php
```

and existing year/archive legacy patterns.

Use the canonical:

`LegacyRedirectController`

or existing legacy bridge.

Do NOT create a second redirect architecture.

The existing audit identifies year/archive URLs and numbered legacy mappings as separate compatibility targets; preserve that architecture.

---

# GLOBAL TEST INVENTORY

Create/extend appropriate tests for:

```text id="qv3h3r"
Page 15
Lottery Hub

Page 16
National

Page 17
National Buy

Page 18
National Draw Detail

Page 19
National Latest Result

Page 20
National History

Page 21
National Year Archive

Page 22
National Result Detail

Page 23
Weekly

Page 24
Weekly Buy
```

At minimum cover:

- route
- rendering
- real data
- empty state
- leading zeros
- provenance
- search
- year filtering
- localization
- SEO
- accessibility-critical markup
- security
- purchase authorization
- price integrity
- wallet integrity
- replay protection

---

# REQUIRED FINAL FILE TREE FORMAT

Return:

```text id="x3qt4a"
app/...
# TYPE: ...
# PURPOSE: ...

resources/views/...
# TYPE: ...
# PURPOSE: ...

resources/css/...
# TYPE: ...
# PURPOSE: ...

resources/js/...
# TYPE: ...
# PURPOSE: ...

tests/...
# TYPE: ...
# PURPOSE: ...
```

Every file line MUST contain:

`# TYPE`

and

`# PURPOSE`

Do not omit comments.

---

# COMPLETE FILE CONTENT REQUIREMENT

For EVERY changed/created file:

provide:

```text id="z86x0j"
FULL ORIGINAL + MODIFIED LOGIC
```

No:

`...`

No:

`existing logic`

No:

`unchanged`

No truncation.

---

# REQUIRED FINAL REPORT

Return:

## A. IMPLEMENTATION SUMMARY

Pages 15–24 individually.

## B. COMPLETE ACTUAL FILE TREE

Only actual files.

## C. COMPLETE FILE CONTENT

Every modified/created file in full.

## D. LOTTERY DATA MAP

For every page:

```text
UI
→ Route
→ Controller
→ Service
→ Model/Query
→ Data Source
→ View
```

## E. PURCHASE FLOW MAP

For Pages 17 and 24:

```text
Selection
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

## F. API MAP

Exact endpoints actually used/created.

## G. RESULT PROVENANCE MAP

Exact source/provenance implementation.

## H. LIVE COMPARISON

Compare each relevant live page against implementation.

Clearly separate:

`OBSERVED LIVE`

`SOURCE VERIFIED`

`IMPLEMENTED`

`NOT VERIFIED`

## I. TEST RESULTS

Exact commands.

Exact runtime output.

Do NOT claim pass where runtime execution did not happen.

## J. REMAINING BLOCKERS

Only actual blockers.

---

# DEFINITION OF DONE

Pages 15–24 are complete only when:

[ ] 15 Lottery Hub
[ ] 16 National Lottery
[ ] 17 National Buy
[ ] 18 National Draw Detail
[ ] 19 National Latest Result
[ ] 20 National History
[ ] 21 National Year Archive
[ ] 22 National Result Detail
[ ] 23 Weekly Lottery
[ ] 24 Weekly Buy

all independently implemented.

AND:

[ ] real backend data source
[ ] no fabricated result
[ ] leading-zero preservation
[ ] provenance handling
[ ] search
[ ] archive
[ ] draw detail
[ ] responsive UI
[ ] accessibility
[ ] EN/TH
[ ] SEO
[ ] security
[ ] purchase authorization
[ ] server-authoritative pricing
[ ] wallet/ledger integration
[ ] replay/idempotency
[ ] legacy compatibility
[ ] tests
[ ] no duplicate service architecture
[ ] no duplicate API architecture
[ ] no placeholder code
[ ] no truncated file content
[ ] no fake values
[ ] no `href="#"`

---

# FINAL HONESTY RULE

Do NOT report:

`100% production ready`

merely because the lottery pages are visually complete.

Report separately:

`DESIGN`
`LOTTERY DATA`
`RESULT PROVENANCE`
`BACKEND`
`API`
`PURCHASE FLOW`
`WALLET`
`SECURITY`
`LOCALIZATION`
`SEO`
`TESTING`
`LEGACY COMPATIBILITY`

When runtime is unavailable:

`NOT VERIFIED — RUNTIME UNAVAILABLE`

When historical result data is absent:

`OPERATOR DATA IMPORT REQUIRED`

When provider/payment infrastructure is required:

`EXTERNAL VERIFICATION REQUIRED`

---

# PAGE ORDER LOCK

Implement exactly:

15 → Lottery Hub
16 → National Lottery
17 → National Buy / Ticket Selection
18 → National Draw Detail
19 → National Latest Result
20 → National Historical Results
21 → National Year Archive
22 → National Result Detail
23 → Weekly Lottery
24 → Weekly Buy / Ticket Selection

Do not move to Page 25 until Pages 15–24 are independently implemented and reported.