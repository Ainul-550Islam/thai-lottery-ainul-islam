# Pages 15–24 implementation report

Date: 2026-09-30

## Scope implemented

The batch is now represented by independently addressable public surfaces:

| Page | Surface | Route |
|---|---|---|
| 15 | Lottery Hub / All Lotteries | `lotteries.index` (`/lotteries`) |
| 16 | National Lottery landing/result page | `national-lottery.index` |
| 17 | National Lottery buy / ticket selection entry | `national-lottery.buy` |
| 18 | National Lottery draw detail | `national-lottery.draw-detail` |
| 19 | National Lottery latest result | `national-lottery.latest` |
| 20 | National Lottery historical results | `national-lottery.history` |
| 21 | National Lottery year archive | `national-lottery.year` and compatibility alias `national-lottery.year-archive` |
| 22 | National Lottery result detail | `national-lottery.result-detail`; legacy `national-lottery.show` remains |
| 23 | Weekly Lottery landing/result page | `weekly-lottery.index` |
| 24 | Weekly Lottery buy / ticket selection entry | `weekly-lottery.buy` |

## DESIGN

Implemented the existing dark, gold, glass-like visual language with responsive layouts, semantic headings, responsive tables, keyboard-usable links and forms, and empty/unavailable panels. The hub uses backend-provided product cards rather than Blade-local product arrays. Result cards display fixed-width values as strings and do not render a number grid when no public numbers exist.

The former National and Weekly landing templates contained regional-market cards, schedules, countdowns, odds, payouts, sample results and fallback values. Those fabricated/publicly unbacked sections were removed. The associated JavaScript files were reduced to non-authoritative search enhancement only; they no longer create market, draw, result, price or payout values.

## LOTTERY DATA

National and Weekly pages continue to consume their existing controllers, result services, history services, search services, date services and source services. No result query, latest-draw selection, year list or number normalization was moved into Blade or JavaScript.

Missing values render the established translation states such as `NO_PUBLIC_DATA`, `RESULT_NOT_FOUND`, `RESULT_UNAVAILABLE` or `UNAVAILABLE`. No draw date, winning number, archive year, price, odds, payout, inventory, winner count or schedule is supplied as a fallback.

## RESULT PROVENANCE

The existing public provenance projection remains the only source for provider, source state, source reference, host, fingerprints, parser version, retrieval/import timestamps and public result version. Detail surfaces render those whitelisted fields without internal ids, secrets, tokens, endpoint credentials or operator notes. Correction notices remain visible when a public result supersedes an earlier version.

## BACKEND

`PublicLotteryCatalogService` is the new read-only hub registry. It resolves National, Weekly, Bingo and PCSO entries only when their configured public route and enabled configuration are present, and asks each lane's existing result service for current availability. It owns no result-selection or import logic.

`NationalLotteryController` now exposes the independent latest, historical, draw-detail and result-detail surfaces while preserving the existing index, year, search and wildcard methods. The year archive uses the existing year service and canonical Gregorian route behavior; Buddhist-year input remains accepted by the existing date service.

## API

The existing `/api/v1/national-lottery/*` and `/api/v1/weekly-lottery/*` result, year, search and draw contracts were reused. No duplicate result API or alternate result projection was introduced. The HTML pages still render the same server-side public projection as those APIs.

The hub is server-rendered through the catalog service because no existing catalog API was required by the repository's current route architecture.

## PURCHASE FLOW

The repository was traced through `BetPurchaseController`, `BulkBetService`, `BetPurchaseService`, validation, responsible-gaming enforcement, wallet locking, reservation, ledger and idempotency services. That canonical flow targets the operator `Draw`/bet lane. The National and Weekly result models are separate data lanes and do not expose a verified mapping to that purchase model, selection schema or eligible draw id.

Pages 17 and 24 therefore fail closed with `NOT_CONFIGURED`. They accept no number, price, discount, fee, total, wallet instruction or purchase request and expose no fake confirmation. This is intentional: reusing the operator bet endpoint would violate the National/GLO and Weekly product distinction and could debit a wallet against the wrong draw model. The page copy records the missing product-specific adapter requirement.

## WALLET

No wallet is read or mutated by Pages 17 or 24. No browser value is treated as authoritative. A future product adapter must enter through the existing server-side validation, responsible-gaming, reservation, wallet, ledger, ticket and idempotency pipeline before a purchase control can be enabled.

## SECURITY

Public result references remain bounded by the existing route constraints and canonical service validation. Search continues to use the existing named throttles. Result numbers are output escaped and retained as strings. Client JavaScript cannot produce purchase success or financial values. The buy pages do not submit a mutating form.

## LOCALIZATION

The new `lottery_hub` English and Thai files have matching key structure. Existing National and Weekly translation files were retained and the non-official notice was kept explicit. Dynamic status labels resolve through translation maps rather than exposing raw state keys.

## SEO

The hub, National landing, latest, historical, year, draw and result surfaces provide route-specific canonical/title/description/robots metadata through the existing layout sections. Search remains non-indexable through the existing controller metadata. The sitemap now includes the public Lottery Hub while continuing to generate draw/year entries from public database rows rather than hard-coded years.

## TESTING

Added `tests/Feature/Lottery/Pages15To24Test.php` for route-name presence, empty-data addressability, hub/buy fail-closed behavior and independent National surfaces. Existing National and Weekly public-page tests remain the main integrity suite for leading zeros, provenance, publication filtering, search, year behavior, empty states, localization, SEO, accessibility and legacy route behavior.

`node --check resources/js/national-lottery.js` and `node --check resources/js/weekly-lottery.js` were executed successfully.

PHP/Laravel route compilation, Blade compilation, PHPUnit, database migrations, Vite build and browser verification were not executed because this workspace has no PHP runtime and no `vendor` directory. No test command is being reported as passed.

## LEGACY COMPATIBILITY

The existing `national-lottery.index`, `national-lottery.search`, `national-lottery.year`, `national-lottery.show`, `weekly-lottery.index`, `weekly-lottery.search`, `weekly-lottery.year` and `weekly-lottery.show` names and parameter families remain. Existing legacy redirect handling was not replaced. The new Page 18 and Page 22 routes are additive and the wildcard route remains after literal routes.

## Runtime follow-up required before release

1. Install the repository's PHP dependencies and run the existing National, Weekly, purchase, SEO and route-ordering test suites.
2. Run the application route list and compile every new Blade surface.
3. Run the Vite build and inspect the hub, result, history, year, detail and buy pages at narrow and wide breakpoints.
4. If product owners approve National or Weekly purchasing, implement a product-specific adapter using the traced canonical financial pipeline before changing `NOT_CONFIGURED` to an enabled purchase state.
