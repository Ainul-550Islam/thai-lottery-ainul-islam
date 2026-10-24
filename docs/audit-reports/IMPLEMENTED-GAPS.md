# Implemented gaps — 16 October 2026

## Added

- A dedicated public **Our Services** landing page at `/services` with cards linking to the existing lottery catalogue, results, ticket check, discount rules, How to Play, FAQ, fees and contact pages.
- `/our-services` as a permanent redirect to canonical `/services`.
- SEO title, description, canonical, robots and Open Graph metadata for the new page.
- A plain-language independent-platform disclaimer; the page does not claim government affiliation, guaranteed payouts or unsupported service availability.
- Guest desktop links for Our Services, About and Lotto Discount (shown from the wide-screen breakpoint to avoid crowding the existing navigation).
- Mobile drawer links for Our Services, About and Lotto Discount for guests and signed-in users; signed-in mobile users also get Contact.
- `/services` included among canonical static sitemap URLs.
- Feature tests for anonymous page rendering/metadata, alias redirect, core page links and disclaimer wording.

## Validation performed

- `git diff --check` passed (no whitespace errors).
- A lightweight static scan of route references found no obvious missing bare route names in views/controllers. The two apparent names (`draw`, `number`) are dynamic API route parameters, not named-route references; the scan is heuristic.
- PHP/Laravel tests, route compilation, Blade rendering and asset build **could not be run** in this environment: PHP and `vendor/autoload.php` are absent. The added tests are written but not executed.

## Files changed/added

1. `app/Http/Controllers/PublicPagesController.php` — services action.
2. `routes/web.php` — canonical route and alias.
3. `resources/views/services/index.blade.php` — new complete view.
4. `resources/views/components/navigation/main-nav.blade.php` — desktop links.
5. `resources/views/components/navigation/mobile-nav.blade.php` — mobile links.
6. `app/Http/Controllers/SitemapController.php` — sitemap inclusion.
7. `tests/Feature/PublicPages/PublicServicesLandingTest.php` — new feature coverage.

## Still unverified / next gaps to inspect

This is a focused first implementation, **not a claim that every line in the 2,579-file repository has been audited line by line**. A full exhaustive audit should proceed in batches and include all controllers/services, Blade templates, route groups/middleware, localization, tests, scripts and deployment configuration. The reference site uses verified legacy `.php` paths (`/about.php`, `/vision.php`, `/fees.php`, `/discount.php`, `/national-lottery.php`, `/weekly-lottery.php`, `/account-verify.php`, `/account-grade.php`, `/prize-verify.php`, `/contact-us.php`). The local redirect bridge covers these, but exact visual/content parity is not implied. Run the new tests and full project test/build commands in PHP 8.4 / Laravel 12 environment before deployment.

## Batch 2 — homepage navigation and public discoverability

- **Fixed a real DOM bug:** `home.blade.php` rendered a second `mobile-menu-drawer`, open button and close button in addition to the global layout. Duplicate IDs meant `getElementById()` could bind the site hamburger to the wrong drawer. The homepage now keeps its section navigation but relies on the single shared layout drawer.
- Added a feature test asserting the rendered homepage contains exactly one instance of each shared mobile-menu ID.
- Added the Our Services, How to Play and FAQ links to the shared public footer, where the service/help pages had been absent.
- Added `/services` to the automated competitor/public-route parity provider.
- Homepage's section-navigation data currently contains Home, Results, Ticket Checker, Sales Points and the four result lanes. It is distinct from the shared site navigation and intentionally remains as a secondary section nav.

## Batch 2 validation limits

`git diff --check` passes. The duplicate-ID search over views now finds one owner each for the global open/close/drawer IDs. PHP is still unavailable in the workspace, so feature tests remain unexecuted. Full end-to-end and visual checks still require a PHP 8.4 environment and browser.

## Batch 3 — Bangla locale wiring

- Found a mismatch: `SupportedLocale` already listed `bn`, and a feature test expected Bangla switching, but both the switch route and `SetLocale` middleware still accepted only `en` and `th`. This prevented `/locale/bn` and Bangla query/session/cookie preferences from being applied.
- Replaced the duplicated hard-coded allowlists with `SupportedLocale::isSupported()` in the route and middleware.
- Added a Bangla language option to both desktop and mobile locale selectors; added a feature assertion for the rendered option.
- Static inventory shows `lang/en` and `lang/th` each have 20 top-level translation files, while `lang/bn` currently has only `home.php`. Therefore locale persistence now works, but **the whole application is not translated into Bangla**; keys without Bengali translations will use the configured fallback. Completing Bengali translation files requires reviewed human-quality translations, not copying English strings and calling it translated.
- `git diff --check` passes. PHP and dependencies are unavailable here, so the existing Bangla switch/middleware tests have not been executed.

## Batch 4 — discount-page locale consistency

- Found one additional hard-coded `en`/`th` locale allowlist in `PublicLottoDiscountController`. The app's central locale allowlist includes `bn`; the controller now uses `SupportedLocale::normalise()` so explicit supported locale input is handled consistently.
- Added feature tests for Bengali discount projection selection and for `/lotto-discount` rendering the active shared discount catalogue rather than an unrelated legacy template.
- Static source inspection also found an **unreferenced legacy template** at `resources/views/lotto-discount/index.blade.php` containing `href="#"` social controls, stale hard-coded payout/affiliate/security/official-licensing claims and mismatched hard-coded navigation URLs. The active controller currently returns `discounts.index`, not that legacy view. It has not been deleted in this batch because it is not on the live route path; it remains a maintenance/security-of-content cleanup candidate and should either be safely removed after confirming no external view consumers or rewritten before any future reuse.
- PHP tests could not run; `git diff --check` is the available validation.

## Batch 5 — remove dormant mockups that could publish misleading pages

- Confirmed by repository-wide reference search that neither `resources/views/lotto-discount/index.blade.php` nor `resources/views/contact-us/index.blade.php` was rendered by any controller/route. The live `/lotto-discount` and `/contact-us` routes both use their current shared public-page controllers/views.
- Removed those two dead templates. The old discount mockup contained placeholder `href="#"` social links and unsupported licensed/official-certification, uptime, odds and affiliate claims; the old contact mockup contained hard-coded external support identities/contact details that were not sourced from configured support data.
- Removed the two JavaScript bundles that were only referenced by those deleted templates (`lotto-discount-portal.js`, `contact-portal.js`) and removed their Vite entry points. Active pages use `resources/js/pages/discounts.js` and `resources/js/pages/contact.js` instead.
- Added safety tests asserting the live aliases render the current public views and do not output the identified dead links/mockup copy.
- `git diff --check` passes and repository search shows no remaining direct references to deleted templates/bundles. PHP tests/Vite build remain unexecuted because PHP/vendor/node_modules are unavailable in this workspace.

## Batch 6 — live draw rendering, results completeness, and responsive navigation

- Reconciled existing live-stream tests with the service/view implementation. `GloLiveDrawService` now uses the existing strict provider resolver for YouTube, Facebook and TikTok URLs, refuses unsupported providers and malformed URLs, and returns canonical embed URLs only. Blank provider configuration keeps the legacy YouTube default; explicit unknown provider values fail closed.
- Restored the configured live player on Home using a lazy sandboxed iframe and provider-normalized URL. Unconfigured/invalid sources render no iframe. The page explicitly says an operator-configured stream is not independently verified.
- Fixed live-draw JS status handling: a provider outage now stays `unavailable` rather than being mislabeled `not_configured`; unexpected states fail closed. Added native Node tests for configured, unconfigured, unavailable and unknown states.
- Results page now publishes canonical/Open Graph metadata and displays the already-projected `consolation_prizes` (“Other published prizes”) field, which the prior table silently omitted. Added feature assertions for canonical metadata and prize visibility.
- Changed desktop/mobile navigation breakpoint to XL so tablets continue to receive the mobile drawer, including the About/Services/Discount links, rather than having neither a hamburger nor the full desktop menu.
- Home section navigation now omits missing named routes instead of emitting a dead `#` link. Added a test for no placeholder hash links.
- Validation: `node --test tests/js/home-live-draw.test.mjs` passed 4/4; focused static route scan found 118 route references and zero missing named routes; `git diff --check` passed. PHP/Laravel tests and production Vite build remain unrun because PHP/vendor and node_modules are unavailable.

## Residual items from broader repository scan (not silently treated as fixed)

- Three unresolved Vite references were found in wallet-conversion views: `resources/css/wallet-conversion.css` (two views) and `resources/js/wallet-conversion.js` (one view). These are outside the requested public-page set and were not fabricated in this batch; that financial conversion UI needs a separate implementation review, particularly because its referenced web routes were not found in `routes/web.php` during this pass.
- Bangla translations remain incomplete (`lang/bn` has only `home.php` versus 20 top-level files in each of `lang/en` and `lang/th`). Locale switching works, but full Bangla UX does not yet.
- The reference Results navigation is a set of separate `.php` lottery pages, not a confirmed `/results` hub; exact archive-row/content parity remains unverified. The reference homepage showed a Bangkok result card, while this project currently advertises National/Weekly/Bingo/PCSO lanes; no separate authoritative Bangkok result provider was established, so no synthetic lane was added.
- Automated PHP route compilation, Blade compilation, feature tests and visual/browser tests still need to be run in the required PHP 8.4/Laravel environment. The 2,579-file repository as a whole has not been claimed as manually audited line-by-line; this sequence has focused on the named public surfaces and their direct dependencies.

## Batch 7 — wallet-conversion blocker triage (not enabled)

- Rechecked the earlier missing conversion assets/routes rather than wiring an incomplete financial route group.
- Confirmed the referenced wallet-conversion Blade templates expect `wallet-conversion.css` and `wallet-conversion.js`, neither exists; those are not cosmetic-only because the JS is responsible for the server preview, quote submission and per-form idempotency key.
- Confirmed the conversion route group is absent from `routes/web.php`, while the feature tests expect `/wallet/convert`, `/wallet/convert/quote`, and `/wallet/convert` execution.
- More importantly, core conversion dependencies referenced by the code are absent from this checkout: `app/Services/Wallet/Conversion/` does not exist despite controller imports; `App\\Enums\\WalletConversionDirection`, `WalletConversionStatus`, `WalletConversionChannel`, `WalletConversionRejectionReason`, `WalletConversionReconciliationState`, and `ConversionFeeBearer` are referenced but their enum files are absent; conversion code also expects cash/win wallet types that are not present in the current `WalletType` enum (which only defines primary/bonus/commission).
- I deliberately did **not** expose new authenticated money-moving routes or invent the missing wallet types/engine. Doing so could expose unsafe/unexecutable financial operations. No wallet conversion route, CSS or JS changes from an attempted wiring were retained.
- Required before this lane can be called complete: restore/review the missing domain enums, `WalletType`/wallet migration model, service implementation, request/quote/idempotency behavior and fee policy together; then implement the route/UI assets and run the conversion feature suite in PHP 8.4. This is a P0 domain integrity blocker, not a parity cosmetic.

## Batch 8 — public API lottery catalogue truthfulness

- Line-level inspection of `HomeLotteryFeedService` found that the public home API advertised five hard-coded lottery products/schedules as `OPEN`, marked the feed `VERIFIED`, and labeled one product `Government Official`. Four of those entries were not sourced from the product catalogue service, and no live availability check backed the claims.
- Reworked the feed to project only the configured GLO L6/N3 catalogue returned by `GloPublicHomeService`; it now labels the feed `CATALOGUE_ONLY`, not live verification, and returns `UNAVAILABLE` if no products can be projected.
- Updated `GloPublicHomeService::productSummary()` to distinguish catalogue metadata from external verification/current ticket availability.
- Added an API feature test asserting the public feed exposes only L6/N3 under `CATALOGUE_ONLY` and excludes the prior unsupported product names, rates and jackpot figure.
- PHP/Laravel tests remain unexecuted because PHP/vendor are absent; test is committed in workspace pending runtime verification.

## Batch 9 — retire directly served public HTML prototypes

- Inventory found 27 `public/*-preview.html` files served as real static pages before Laravel could handle the request. These contained stale mockups, hard-coded placeholder data, forms that were not backed by their production handlers, invented schedules/payout figures and, in some cases, unsupported official/licensed/instant-payout claims.
- Moved the complete prototype source set unchanged into `docs/archive/public-ui-previews/`, outside the public document root so the files remain available for internal reference without being served as pages.
- Added permanent redirects from each former public preview URL to the corresponding modern application route. Authenticated destinations (`/deposit`, `/dashboard`, `/profile`, `/wallet`, etc.) retain the existing auth middleware behavior after redirect.
- Added a data-provider test covering all 27 redirects, asserting both 301 target and that the old public file is absent.
- This removes public mockups without copying unsupported competitor identity, fee, payout or government-affiliation claims into the current UI.
- PHP/Laravel test execution remains pending due missing PHP/vendor; `git diff --check` is run separately.

## Batch 10 — payout notification copy and residual trust labels

- Changed the user-facing prize notification copy to report the recorded disbursement status instead of asserting that the amount is already credited and withdrawable. The CTA now targets the real `/bets` page; the old `/player/payouts` target was not a defined route.
- Added currency-code handling that accepts either the supported enum or persisted code safely, with the configured primary currency as fallback.
- Updated the dormant companion Blade email template to avoid asserting settlement/withdrawability and removed the unsupported “Licensed official draw results” footer claim.
- Changed the shared public footer link label from “Official Draw Results” to “Lottery Results” and removed an obsolete Home API fallback that claimed official ingestion, bank-grade security, 24/7 support and immutable provenance without configuration-backed evidence.
- Added a unit test for reserved-state notification wording and its real destination. PHP execution remains pending.
- Follow-up status pass: the public Home API now labels its configured trust-copy list `CONFIGURED`/`NOT_CONFIGURED` rather than `VERIFIED`; the status means the list exists, not that a certification or guarantee was independently audited.

## Batch 11 — dormant catalogue mockup and inert action cleanup

- Confirmed there were two unused home catalogue components with copied hard-coded schedules, prices, odds, prize pools and “official” labels. Replaced the test-covered `lottery-grid` with a data-driven renderer that only shows supplied catalogue fields and removed the unreferenced `lottery-cards` mockup after repository-wide consumer search.
- Replaced the only remaining literal `href="#"` control in the Blade templates (an admin compliance “Investigate” link with no working route) with a non-interactive “Review unavailable” state because the target case projection is not configured.
- A broad active-template scan now finds no exact placeholder `href="#"` links or user-facing “Official GLO” / licensed-result claims in the live view tree. One remaining “Official GLO Jackpot” occurrence is a Blade comment explicitly stating that the label is prohibited, not rendered content.
- PHP tests remain unexecuted; final static whitespace and JavaScript checks are rerun below.
- Shared public footer copy was also toned down during the broader live-template pass: removed unsupported “fintech-grade”/“high-precision” marketing, changed “Authorized Sales Points” to neutral “Sales Points,” and clarified the non-affiliation/result-verification notice. Added a rendered-page feature assertion.
- Shared public footer copy was also toned down during the broader live-template pass: removed unsupported “fintech-grade”/“high-precision” marketing, changed “Authorized Sales Points” to neutral “Sales Points,” and clarified the non-affiliation/result-verification notice. Added a rendered-page feature assertion.

## Batch 12 — sitemap discoverability of service/help pages

- Compared the new Services landing-page links and public route inventory with `SitemapController::staticRoutes()`. Several crawlable public destinations linked from Services were omitted: How to Play, FAQ, Download and Betting.
- Added those four canonical named routes to the sitemap and expanded `SitemapTest` to assert their inclusion alongside Services.
- No legacy preview paths or duplicate `.php` aliases are added to the sitemap.

## Batch 13 — full Blade/Vite asset-reference sweep

- Automated scan of every Blade `@vite(...)` declaration found exactly three unresolved source entries: the two wallet-conversion CSS references and one wallet-conversion JS reference. All three belong to the same not-enabled conversion UI lane; no other Blade Vite source is missing.
- Independently scanned all 102 top-level Vite input entries; every configured entry path exists in the workspace after removing the orphaned contact/discount mockup bundles.
- No TODO/FIXME/XXX markers were found in first-party controllers, routes, Blade, JS, CSS or config scanned. This does not replace PHP parsing, Vite compilation or a manual semantic audit of every source line.

## Batch 14 — admin identity placeholder

- Inspection of the active admin layout found a stock external Unsplash portrait and hard-coded operator name “Somchai,” plus a pointer cursor despite no profile dropdown action. Replaced the remote/stale identity with a local inline avatar and the authenticated administrator's actual name; removed the false click affordance.
- This prevents a live admin page from presenting a fictional operator identity or depending on a third-party avatar. PHP/browser rendering remains unverified.

## Batch 15 — Home language-pack claims

- A locale-by-locale pass found the English and Thai Home language packs still carried legacy claims that were no longer supported by the live Home implementation: online licensing, 900x/88-round products, direct Government Lottery Office synchronization, guaranteed payouts, security certifications, zero fees, 24-hour VIP support and native apps.
- Replaced those strings with neutral, source-aware labels in both language packs, including nested old component keys so a future component reuse does not revive the claims. Updated Home Blade fallback headings to avoid “official/verified” wording unless the result payload itself carries that provenance.
- Fixed the dormant app-download component's fallback route (`public.download` did not exist; the canonical route is `download`).
- Extended the copy-neutrality unit test to guard against the removed English and Thai claim phrases. Runtime test execution is pending PHP/vendor availability.

## Batch 16 — route-reference audit follow-up

- Re-ran the static route-reference scan after the fixes. Public `download` reference is now canonical. Remaining unmatched references are mostly route names assembled by Laravel group prefixes or dynamic model/resource routes; the conversion names are part of the known blocked lane.
- One separate orphan was confirmed: `resources/views/admin/ip-access-rules.blade.php` references `admin.ip-access.rules.*` names, but no corresponding route/controller exists in this checkout, and no controller renders the view. Its related `IpAccessRuleManager` is security-sensitive (denylist creation/revocation and policy scope). I did not guess-wire an admin mutation surface; keep this UI unserved until a policy-backed read/write controller, CSRF/idempotency/rate-limit protections, self-lockout safeguards, and route tests are reviewed together.
- This is an internal security-operations gap, not competitor-site parity, and remains deliberately disabled/unwired.

## Batch 17 — stale vision-page certification claims

- A locale scan found unused-but-recoverable English/Thai `vision.php` keys that advertised SEC compliance, ISO 27001, FATF alignment, GDPR readiness, and a licensed/regulatory framework. The active Vision view currently disclaims certifications, but the translation keys could be reused by another component.
- Rewrote the English and Thai structured badge copy to describe ordinary application controls and legal-information links, and neutralized unsupported “trusted/transformation” slogans. Extended the copy-neutrality test to reject the former certification claims.
- No government/regulatory credential was invented or implied; this was copy cleanup only, not a legal review.

## Batch 18 — registration, KYC email, and obsolete Home components

- Replaced registration-page claims of “fast” onboarding, premier verified status and instant payouts with neutral account/eligibility wording.
- Updated both the KYC email notification class and its companion Blade template: removed guaranteed 24-hour review, blanket withdrawal/limit privileges, invented rejection reasons, and unsupported AML/CTF certification claims. Both now direct the member to the real authenticated account-verification route and defer to the recorded status.
- Added KYC notification unit tests for no blanket privileges and no fixed review deadline.
- Removed two unreferenced legacy Home components that hard-coded 500,000 users, ฿150M daily payouts, 99.99% uptime, 24/7 VIP support and a fake 100% deposit match. Reworked the unused app-download component so it renders only validated configured URLs and no synthetic QR code or default store buttons.

## Batch 19 — Bangla Home active-label coverage

- The earlier Bangla file had a reviewed subset but omitted several labels actually requested by `HomePageDataService::text()`, so Home mixed Bengali and English for product, prize, stats, trust, offers, payments, support, app options and live-stream headings.
- Added reviewed Bangla equivalents for every key consumed by the live Home composer and updated the current-result heading to “latest published result.” Added a unit test enumerating the active Home text contract.
- This closes missing composer labels only; it does not claim the whole site is translated into Bangla, and standard Laravel validation/framework strings and other page families still fall back to English.

## Batch 20 — payment notification certainty and destination

- Scanned transaction notification copy. Payment “captured” and “failed” were being translated into stronger wallet/debit assertions than the notification's transaction status alone establishes. The mail now reports the recorded status and tells members to verify the wallet state before retrying; the old Blade email was aligned and its unsupported immutable-ledger footer removed.
- Payment mail links to the canonical `/wallet` page. Added unit tests for captured and failed wording and the destination.
- PHP tests remain pending.

## Batch 21 — withdrawal notice delivery claims

- Reviewed withdrawal status notification class and its companion email. The old template promised bank arrival within minutes, claimed dual authorization/rejection reasons, and stated that funds were already restored; the notification class also asserted destination delivery/refund directly from status labels.
- Notices now report recorded withdrawal state and direct users to check the destination/account record, avoid ETA and unexplained reason assumptions, and use canonical `/withdraw` and `/wallet` links. Added unit tests for no arrival-time and failed-restoration promise.
- The underlying withdrawal state machine documents ledger hold/release semantics, but notification copy remains conservative about provider receipt.
- Follow-up correction: the parity matrix explicitly requires a Home hero and offers section. Rather than leave those component contracts deleted, recreated both as complete data-driven components with no fabricated counts, payouts, bonus match, VIP claims or default offers. The source now renders only configured campaign records and uses the real ticket-check/results routes; this preserves the parity component surface safely.
- Follow-up countdown audit of the restored hero found legacy default draw date/number/time and timer digits in the countdown component. Replaced these with server-provided schedule fields only; unscheduled/unknown values now show the service's unavailable message. Added component-contract regression coverage for the fabricated defaults and configured-only Home content.

## Batch 22 — stub access-page security claims

- Audited the public betting/deposit/withdrawal access stubs. Removed unsubstantiated "secure"/"canonical" branding and unsupported claims that betting controls are enforced in the destination service; the betting page now simply points members to review available options and requirements.
- Added banned-copy regression assertions for those headings and CTA labels. Actual transaction controls were not modified.

## Batch 23 — result feed provenance aggregation

- Continued through Home data services and found two hard-coded verification statuses: the generic hero object was marked `VERIFIED` despite containing only presentation/configuration data, and the aggregate results feed marked itself `VERIFIED` regardless of whether its latest result was a fixture or whether any lane had published data. It also called generation time `verified_at`.
- Changed the hero status to `AVAILABLE`. The aggregate feed now derives `has_results` from the nested result/lane payloads, reports `AVAILABLE`, `NO_PUBLISHED_RESULTS`, or `UNAVAILABLE`, and labels its timestamp `generated_at` rather than `verified_at`.
- A prize summary also classified every non-fixture provider—including unknown/manual values—as `OFFICIAL_SOURCE_VERIFIED`. It now delegates to the central GLO source-state policy; only the explicit verified source state yields `VERIFIED`. Added a feature regression for an unknown provider and unit coverage for fixture aggregate feed semantics.

## Batch 24 — Home metrics and sales-point validity

- A deeper pass through Home metrics found the page labeled raw user rows as “Registered members,” counted every `draw_results` row as a published result (including unpublished draws), counted future-dated sales-point records as active, and combined `result_published` with `completed` under “Draws completed.” The whole metrics block also labeled database counts `VERIFIED`.
- Metrics now use neutral “Registered accounts” and “Active sales-point records” labels, count only results attached to published/completed draws, apply both `valid_from` and `valid_to` windows, count only completed draws, and report `AVAILABLE` rather than source verification.
- Applied the same `valid_from` filtering to public sales-point search so future locations are not displayed early. Added regression cases for unpublished result exclusion, future sales-point exclusion in stats/search, and the status semantics. PHP execution remains pending.

## Batch 25 — live-stream outage status propagation

- Home service composition mapped every non-configured live-stream state to `NOT_CONFIGURED`; a provider outage was therefore mislabeled as missing setup. The public card now preserves `UNAVAILABLE` separately, fails closed on unknown states, and separately maps replay catalogue state. Added unit regressions for outage versus no configuration.

## Batch 26 — localized Home metric labels and product copy

- Replaced the Home L6 product's “Government Lottery L6” name and unsupported official/flat-tax note with neutral configured-catalogue wording; clarified N3 rule wording as configuration-derived.
- Home metric labels were hard-coded in English even when the page locale was Thai or Bengali. They now use translated catalog keys for English, Thai and Bengali, and the Bangla active-label contract includes the four metrics.

## Batch 27 — countdown source-of-truth and betting-state semantics

- Audited both Home next-draw services. `HomeCountdownService` independently queried only `scheduled` draws, while the canonical `GloNextDrawService` includes scheduled/open/closed future draws and configured calendar fallback. It also set `is_open_for_betting` true solely when more than five minutes remained.
- Replaced the duplicate query with an adapter to `GloNextDrawService`, preserving the legacy output shape while deriving the betting flag from the actual draw status (`open`) and returning unavailable/no-draw states without fabricated targets. Added regression coverage for scheduled-but-not-open state.

## Batch 28 — advertised payment currency projection

- Audited the Home payment-method projection against `PaymentGatewayInterface`. It called a nonexistent `supportedCurrencies()` method on drivers and caught the resulting error by inventing `['THB']`, so advertised currency information was both wrong and could mask capability errors.
- It now intersects each `PaymentMethod`'s declared currency set with the driver's actual `supportsCurrency()` capability. A currency lookup exception or empty intersection fails closed and the method is not advertised. Added parity assertions and a regression that a capability exception cannot fabricate THB support.

## Batch 29 — localized Home section navigation

- Home section-navigation labels were generated as hard-coded English strings and remained English for Thai and Bangla visitors. Replaced the raw labels with locale keys and added EN/TH/BN translations for all eight navigation items. Extended Bangla completeness and Unicode-block checks.

## Batch 30 — promotion status semantics

- The public campaign catalogue labeled date-window/configuration-filtered promotions `VERIFIED`, which could be read as independent verification or approval. Active configured offers now report `AVAILABLE`; missing/expired configuration continues to report `UNAVAILABLE`. Updated service tests.

## Batch 31 — session activity metric parity

- Followed the parity matrix's C17 active-member counter into its existing tests and found a disconnected implementation: `TrackMemberPresence` existed but was not registered, `users.last_seen_at` had no migration, and `GloPublicStatsService` exposed neither the active count nor the expected metric.
- Added the nullable indexed timestamp, datetime cast, and web-only presence middleware. The middleware records authenticated activity at most once per minute and catches tracking failures so they never block a request. The public stats service calculates a rolling 15-minute aggregate on each call (not from the general metrics cache) and translates its label.
- Updated the privacy notice in English and Thai to disclose the activity timestamp and aggregate use. Updated test expectations from a `VERIFIED` status to `AVAILABLE` after the preceding metrics audit.

## Batch 32 — promotion catalogue fail-closed limits

- The campaign cap had an edge case: `campaigns_max = 0` still admitted the first campaign because the limit check happened after insertion. Also, invalid configured date bounds parsed as `null` and could silently turn a bounded offer into an unbounded active offer.
- Zero cap now returns an empty/unavailable catalogue, and malformed non-empty date bounds skip that campaign. Added tests for both cases.

## Batch 33 — activity timestamp serialization boundary

- Because the new last-activity value is a private presence signal, added it to the User model's hidden serialization fields so broad Eloquent serialization cannot accidentally expose timestamps or member activity. Added a regression test for model serialization.

## Batch 34 — Home trust-bullet config validation

- The Home composer treated any non-empty trust-bullets config as `CONFIGURED` and blindly cast its values to strings, so malformed values could become empty text or “Array” in public copy. It now retains only non-empty strings, trims them, and reports `NOT_CONFIGURED` when nothing valid remains. Added configuration-shape regressions.

## Batch 35 — Home trust-section provenance and locale

- The Home footer claimed draw rules and prize tables all followed GLO publications, despite the site containing separate National/Weekly/Bingo/PCSO lanes. Its default trust bullets also made absolute claims about every financial action, idempotent wallet/payout operations, append-only money audits, transaction locks and KYC thresholds without proving that universal scope.
- Removed default technical trust bullets and replaced the inaccurate cross-product statement with an explicit independent-platform disclaimer that says result availability/source labels vary by product. Added EN/TH/BN strings and changed copy regression expectations to reject the old claims.
- This intentionally leaves no default trust bullets; custom operator bullets remain configurable but require review/translation before publication.
- Localization contract count update: the added independent disclaimer brings `HomePageDataService::text()` to 29 keys; together with eight Home navigation labels and five stats labels, the active Home copy contract is now 42 keys across EN/TH/BN. Bangla tests enumerate the full active set.

## Batch 36 — dormant bonus-view contract

- A search of variable-driven `href` attributes found the old dormant `components/home/bonuses.blade.php` still gated active campaigns on `VERIFIED`, while `PublicBonusService` now correctly emits `AVAILABLE`. Updated the stale component contract and added a regression check so a future reuse does not silently hide configured offers.

## Batch 37 — stale Home component status contracts

- The same status-vocabulary scan found dormant stats and trust components still assuming `VERIFIED`: stats would hide data after the new `AVAILABLE` semantics, and trust defaulted missing status to `VERIFIED`. Aligned the stats view to `AVAILABLE`, changed trust fallbacks to `NOT_CONFIGURED`, localized its missing Bengali current-count label, and added parity assertions for the component contracts.

## Batch 38 — Services landing localization

- The dedicated Services landing page was entirely hard-coded in English although EN/TH/BN are supported. Added full page and metadata catalogs in all three languages, routed the controller metadata through the active locale, and added Thai/Bangla render tests. All 33 view keys are present in each catalog.

## Batch 39 — Retired dormant fabricated download portal

- A full audit of the unreferenced `resources/js/download-app-portal.js` found hardcoded fake APK/desktop artifacts, fabricated SHA-256/MD5 strings, a simulated “successful” download that emitted a text file, and false “Official Signed Release” / “Authenticity Verified (100% Match)” claims. Repository-wide reference checks confirmed this file was not used by the current `/download` or `/download-app` view; the active page is the configuration-driven `resources/views/download/index.blade.php` with `resources/js/pages/download.js`. Removed the stale unattached script rather than leaving misleading code available for accidental reactivation.

## Batch 40 — Retired dormant prize-verification portal script

- Inspected the obsolete `resources/js/prize-verification-portal.js`, which was still an explicit Vite entry but was not loaded by the active prize-verification view. It hard-coded an October 1, 2026 draw fallback, described results as an “OFFICIAL WINNER,” claimed synchronization with a GLO ledger/integrity seal without evidence, and asserted statutory payouts with 0% platform commission. The current page uses `resources/js/pages/prize-verification.js`; removed the unattached stale script and its Vite entry so it cannot accidentally ship or be reactivated as trusted output.

## Batch 41 — Removed unattached fee/privacy portal controllers

- Confirmed the live fee and privacy views load `resources/js/pages/fees.js` and `resources/js/pages/privacy.js`; no view referenced the legacy `fees-portal.js` / `privacy-portal.js` globals or DOM hooks. The old explicit Vite entries contained dynamic HTML insertion of API response fields and claimed downloadable “Official” copies with checksums while only displaying an alert. Removed the detached scripts and Vite entries; retained the active page-specific controllers and public APIs.

## Batch 42 — Adjacent public-page localization gaps discovered

- A view-level scan after localizing Services found remaining English-only/static UI in `resources/views/contact/index.blade.php`, `fees/index.blade.php`, `faq/index.blade.php`, and `how-to-play/index.blade.php` (navigation, status labels, search placeholders, section headings and disclaimers). Contact has EN/TH catalogs but no BN catalog; fee/help pages also have hard-coded English UI despite EN/TH dynamic content catalogs. This is an identified open gap, not reported as fixed. It should be handled with full per-locale catalogs and render tests, while preserving server-provided configured fee/support data and not inventing provider details.

## Batch 43 — Contact page locale-complete static interface

- Replaced the Contact page's hard-coded English navigation, support/status headings, note, honeypot label and useful-links eyebrow with locale keys. Added matching English/Thai keys and a Bengali Contact catalog (Bengali values override the complete English base); added Thai/Bengali render assertions and included the new catalog in competitor/endorsement scans. Configured support details remain server-driven.

## Batch 44 — Fees page static copy localization

- Added 44 locale keys for hard-coded Fees UI in EN/TH/BN and replaced its navigation, section/search labels, table headers, empty state, methodology, print/support guidance and accessibility labels. The effective fee rows and their data remain sourced from the existing locale-aware fee service/configuration; no competitor fee values were copied. Added Thai/Bangla rendering assertions.

## Batch 45 — FAQ page static copy localization

- Added EN/TH/BN catalogs for 39 FAQ view keys and replaced hard-coded navigation, metadata strip, question/search labels, empty state and support/privacy guidance. Dynamic approved FAQ question/answer data remains service-sourced. Added Thai/Bangla render assertions.

## Batch 46 — How to Play page static copy localization

- Added EN/TH/BN catalogs for 39 How to Play view keys and localized the navigation, metadata, non-guarantee disclaimer, step map, empty state, privacy guidance and calls to action. The configured instructional steps remain supplied by the existing public page service. Added Thai/Bangla render assertions.

## Batch 47 — About page static copy localization

- Added EN/TH/BN keys for the remaining static About UI and independent-platform notice, replacing English-only navigation, labels and journey-art captions. Existing locale-aware About service content remains intact. Added Thai/Bangla render assertions.

## Batch 48 — Vision page static copy localization

- Added EN/TH/BN keys for the Vision page's hard-coded header, navigation, value-system disclaimer, and decorative labels; retained the existing locale-aware Vision content service. Added Thai/Bangla rendering assertions.

## Batch 49 — Discount catalogue static copy localization

- Added EN/TH/BN translations for the Discount catalogue's static 51 view keys: navigation, server-pricing notice, metadata, search, rules table, empty states, protected-price explanation and support actions. All discount/pricing values remain server/config sourced; no competitor offers were imported. Added Thai/Bangla rendering checks.

## Batch 50 — Prize verification copy and localization

- Localized 57 static Prize Verification view keys in EN/TH/BN and added render assertions. Removed the unsubstantiated “GLO-compatible” claim from the page notice and EN/TH metadata, and replaced the unsupported “fixed by law” price assertion with configuration-based wording; the notice now states independence and explains lookup limitations. Added a Bangla override catalog and revised neutrality tests to reject the compatibility claim. Verification remains source/config-driven and makes no winner/payment certification.

## Batch 51 — Prize verification neutrality and Bangla coverage

- Removed unsupported “GLO-compatible” wording from prize-check metadata and non-affiliation notices; revised immutable-price notes to avoid unsupported legal claims. Added BN overrides for prize-check/discount copy and expanded neutrality tests. The checker now states its independence and limits rather than implying technical compatibility or government authority.

## Batch 52 — Results search localization

- Added EN/TH/BN catalogs for the public results-search view, localized search form, result summary, table headers and empty-result message, and added Thai/Bangla rendering coverage. The source-state value remains data-driven.

## Batch 53 — Results catalog and shared result card localization

- Replaced the accidentally English-only Thai Results catalog with Thai copy, added Bangla Results copy, and extended matching key parity for card labels. The shared result card now translates draw/date/prize labels and source-state names instead of exposing raw enum codes. Added result-card localization tests and refreshed a stale public-results assertion.

## Batch 54 — Bangla substantive FAQ and How to Play content

- The EN-only fallback of `PublicPageTextBag` meant that localizing static FAQ/How to Play UI still left the approved Q&A and instructional steps in English for Bangla visitors. Added `lang/bn/public_pages.php` with Bangla title/meta, five FAQ question-answer pairs, five instructional steps and the disclaimer. Expanded Bangla render assertions to verify dynamic content, not only UI labels.

## Batch 55 — About/Vision platform-compatibility claims

- Removed unsubstantiated GLO-compatibility language from English/Thai About and Vision copy and changed the About product-choice description to refer to currently available platform products. Replaced absolute claims about every result, payout and append-only audit in the About narrative with bounded descriptions of configured sources and published labels. Added regression coverage against “GLO-compatible” and “GLO-style” on both page surfaces.

## Batch 56 — Terms page configuration-source wording

- Removed the unsupported “GLO-compatible configuration source” assertion from the Terms UI. The page now accurately says its configured values come from approved legal and product-configuration sources; added a regression assertion.

## Remaining localization boundary (open)

- The targeted About/Vision service and view-key sweep now has Bangla overrides for the substantive page content, metadata, dynamic values/controls and direct view labels. Do not claim full-site Bangla parity: Terms/Privacy legal content and other page catalogs remain incomplete and untranslated items still fall back to English. Terms/Privacy content should not be machine-translated as binding legal language without an approved localized source. Several Terms legal clauses also retain “GLO-compatible” product wording and need source/owner review before any legal-copy change.

## Batch 57 — Bangla About/Vision substantive page coverage

- Expanded `lang/bn/public_pages.php` beyond page headings/selected copy: added Bangla About timeline milestones, useful links, core values, operational controls, and Vision/Mission principles, CLEAR value descriptions, governance wording, journey sections, hero copy, CTA labels and the shared skip-to-content label. A targeted sweep of About/Vision service keys, dynamic configured keys and direct Blade labels found no remaining missing Bangla overrides. Added locale-key parity/nonempty checks and rendered Bangla assertions for About, Vision and Fees metadata. This does not establish full-site localization; Terms/Privacy legal copy and other unrelated page catalogs remain open for approved translation/review.

## Batch 58 — Bangla public account explainers

- The route-level locale inventory found the signed-out Account Grades and Account Verification explainer pages still loaded 118 EN-only `account_info` strings, even though these public guidance pages are safe to localize independently of account records. Added Bangla overrides for all 118 catalog keys, including navigation, grade-ladder labels, scope notes, verification steps, privacy/security notices, and metadata. Added exact EN/TH/BN key parity, nonempty/difference assertions, and public page render assertions for Bangla.
- This does not translate the authenticated `account_services` workflow or legal terms; those remain separate review tasks. No personal data or account process behavior was changed.

## Batch 59 — localized primary navigation labels

- The previous locale checks covered page content but missed three hard-coded English links in the shared desktop/mobile primary navigation. Added EN/TH/BN `player` catalog entries for Our Services, About and Lotto Discount and switched both navigation layouts to those labels. Extended Services page locale assertions to verify the nav labels in Thai and Bangla. Static scan confirms those three labels are no longer hard-coded in either primary-navigation template.

## Batch 60 — shared public footer localization and disclaimer correction

- Continued the cross-locale navigation sweep into the shared public footer (used by the app layout and multiple public lottery/account pages). Its entire link grid and footer copy had been hard-coded in English. Added 27 EN/TH/BN translation keys and switched the complete shared footer to the locale catalog; expanded render assertions and public-page catalog key parity.
- The separate Home footer contained a contradictory claim that “Government Lottery Office rules apply.” Replaced it with the existing locale-aware independent-platform disclaimer and added a regression assertion. Static scan confirms all 27 shared-footer keys exist in EN/TH/BN and the stale claim is gone.
- Validation: `git diff --check` passed; home live-draw JS tests passed 4/4. PHP/Laravel/Blade runtime validation is still pending because PHP and vendor dependencies are unavailable.

## Batch 61 — account verification catalog key collision

- A deeper scan of the next account-services catalog found eight duplicate `verification_*` keys in both EN and TH. PHP's later-key-wins array semantics silently replaced earlier document-upload wording; notably, the authenticated verification page displayed “Verify your identity to unlock the full member experience,” which overstates what the configured document-review step guarantees.
- Split the member-form labels into distinct `member_verification_*` keys in EN/TH, updated the two consuming Blade surfaces, and rewrote the member-page lead to state that this is document-upload review—not government e-KYC or an official GLO e-service. Added an explicit duplicate-key guard and EN/TH rendering assertions.
- Static parser now finds 215 unique keys in each EN/TH account-services catalog. PHP/Laravel tests remain unavailable; the JS suite and whitespace checks are not substitutes for those tests.

## Batch 62 — Bangla authenticated verification UI and status label

- Extended the account-services locale sweep into the authenticated document-upload workflow: added Bangla overrides for the account-verification form's active labels, status values, document types, upload guidance, privacy/reviewer notes and errors. EN/TH/BN key parity and the member-form translation assertions now cover this subset; other account-services copy still falls back to English.
- The same view rendered a raw `account_services.verification_status_VERIFIED` key for an approved document because that document-level state had no catalog entry. Added the explicit EN/TH/BN “Verified” label and a reviewer-approval render regression assertion.
- Removed unsupported “we will review shortly” timing promises from the open-request message in EN/TH/BN; it now points members to the recorded status/history instead. PHP/Laravel tests remain unrun; whitespace/static checks and the 4/4 JavaScript suite pass.

## Batch 63 — Bangla dynamic Fees catalog

- Found that the separate `fees_page.php` translation covered the visible shell, but the 61 `account_services.fees_*` strings used for dynamic fee categories, provider labels, calculation states and calculator messages still fell back to English. Added Bangla overrides for all 61 and extended the Fees feature test to check a live category label plus catalog parity/non-fallback assertions.
- Static scan confirms all 61 fee keys are overridden in Bangla and no placeholder tokens were lost. Authenticated grade/payment sections of `account_services` remain outside this batch. PHP feature tests are still pending; `git diff --check` and the 4/4 JS suite pass.

## Batch 64 — Bangla account-grade UI

- Added Bangla overrides for all 41 static grade-dashboard strings in `account_services`, covering current grade, spend window, history, eligible discounts, entitlement table and refresh controls. Added the remaining literal account-grade view labels and expanded rendering/non-fallback assertions to English, Thai and Bangla.
- A targeted scan of account and account-verification views now finds Bangla overrides for all 73 literal `account_services.*` references; dynamically constructed status/document keys are covered by the matching status/document catalogs. Config-provided tier/game labels and other transaction/status surfaces still require separate review. PHP feature tests remain unavailable; `git diff --check` and the JS suite pass 4/4.

## Batch 65 — Bangla payment-return and manual-deposit UI

- Closed the remaining 26 literal account-services keys found in active Blade views: payment callback states/messages/navigation plus manual bank-transfer labels/instructions. Bangla account-services overrides now cover all 110 literal `account_services.*` keys scanned across the view tree.
- The payment-return page has no polling/auto-refresh script, but EN/TH copy claimed its status updated automatically. Changed it to state that the displayed status is a snapshot and a refresh is needed. Also softened pending/failure/cancel/not-found guidance so it does not promise automatic credit, claim no provider-side charge, or encourage a retry without checking. Added EN/TH/BN route-render assertions for the refresh instruction and Bangla not-found state.
- Static scan reports 110/110 literal view-key overrides, zero duplicate EN/TH catalog keys; `git diff --check` passed and JS tests passed 4/4. PHP/Laravel execution remains unavailable.

## Batch 66 — Bangkok countdown date era

- Rechecked the reference-specific countdown requirement after the surrounding Home work. The draw instant and countdown correctly use `Asia/Bangkok`, but the absolute date label in `GloNextDrawService` was always formatted with a Gregorian year, so Thai visitors could see `2026` instead of Buddhist Era `2569` even though the archive pages use BE.
- Reused the shared National Lottery calendar formatter for locale-aware labels on scheduled rows and calendar fallback dates: Thai gets Thai month names and BE year; English/Bangla retain Gregorian year. Added a fixed-date Home regression test for Thai 19 October 2569 and English 19 October 2026.
- `git diff --check` passed and the JS suite passed 4/4. PHP feature tests remain unrun; countdown timezone/instant logic was not changed.

## Batch 67 — localized countdown reached state

- The countdown's zero-time branch used a hard-coded English “Draw time reached” string even on Thai/Bangla pages. Added locale keys in EN/TH/BN, passed the label through both countdown roots as a data attribute, and changed the JS display branch to use it. Added home render assertions for Thai and Bangla plus the JS data-contract assertion.
- `node --check resources/js/home/countdown.js`, `git diff --check`, and the existing home live-draw JavaScript tests passed (4/4). PHP feature tests remain unrun.

## Batch 68 — Home countdown unit/status localization

- Continued the locale audit through both countdown layouts. The main Home hero used untranslated generic keys for time remaining, schedule context, timezone and day/hour/minute/second units; status badges and unavailable-message fallback also exposed raw English/service text. Rewired these to the existing Home catalog and added explicit EN/TH/BN copy for scheduled/unavailable status and unavailable guidance.
- Added Thai/Bangla render assertions for countdown labels and unavailable states. Static scan finds no raw countdown labels in either template; EN/TH/BN keys are present. `node --check` for countdown JS, `git diff --check`, and existing JS tests (4/4) pass. PHP render tests remain unrun.

## Batch 69 — Home footer locale parity

- Continued the rendered Home scan and found a hard-coded English footer nav accessibility label plus authenticated Dashboard/Log out labels. Replaced them with locale keys and supplied missing Bangla player labels; added an EN/TH/BN footer aria-label regression assertion.
- Static locale coverage passes 3/3, `git diff --check` and JS syntax check pass, JS suite remains 4/4. Laravel/PHP tests remain unavailable in this environment.

## Batch 70 — Home component catalog parity

- Continued the template-by-template translation-key scan beyond the main hero. Filled Bangla copy for the Home app-access and trust/security cards plus empty/unavailable and product-note fallbacks; replaced hard-coded English app-download and catalogue fallback sentences with catalog keys. Added explicit countdown accessible-name/label copy and a unit regression that guards Bangla copy against English fallback.
- Static scan of Blade `home.*` references finds no missing locale roots across EN/TH/BN; Bangla feature keys are present. `git diff --check`, countdown JS syntax and JS suite pass (4/4). PHP unit/feature tests remain unavailable.

## Batch 71 — Main Home status and fallback copy

- Continued through the actual `home.blade.php` render path, translating the primary navigation label and generic result/ticket/product/prize/support/app labels that previously fell back to English. Machine status values are kept in `data-*` attributes, while visible result/source/live badges now use localized human-readable labels; unavailable result/live/replay messages and iframe accessible title are localized as well.
- Updated Home regression coverage and the Bangla catalog test for the newly rendered copy. Static Blade translation-root scan passes for EN/TH/BN; main Home countdown templates no longer use generic Laravel `__()` fallbacks. JS syntax/tests and `git diff --check` pass. PHP/Laravel tests remain unrun due to missing PHP/vendor.

## Batch 72 — Home section state and catalogue localization

- Followed visible Home status rendering across result lanes, product/prize, statistics, payment, support and app-access sections. Machine states remain in DOM data attributes, but users now see translated labels; payment-rail and lane-result status chips are localized too. Replaced section service English messages with localized neutral copy and localized L6/N3 product names/notes and first-prize label.
- Extended Bangla unit and Home render regression assertions. All referenced Home catalog roots are present in EN/TH/BN, raw visible status/name/note projections were removed from the Home template, `git diff --check` passes, and the JS suite remains 4/4. PHP/Laravel tests remain unavailable.

## Batch 73 — Home lane dates and payment names

- Continued checking each visible Home section after the status pass. Bangla lane-result cards were using the English date display; added strict ISO-to-Bangla-month formatting while preserving the machine ISO field and existing Thai Buddhist-era display. Added a fixed-date regression assertion for `1 অক্টোবর 2026`.
- Payment rail names now use locale keys (provider trademarks retained, generic rail names translated). Static Home locale-root and rendering scans pass, `git diff --check` and JS suite pass (4/4). PHP/Laravel tests remain unavailable.

## Batch 74 — Shared support partial and deep catalog scan

- Extended the audit to the shared Home support/contact partial used by Home subpages: localized the page title, status chip, contact labels, help copy and not-configured state. Completed Bangla translations for the dormant latest-results leaf keys as well.
- A recursive exact dotted-key scan across all PHP/Blade Home catalog references now finds no missing EN/TH/BN paths (excluding the intentional dynamic payment-method key prefix, whose concrete method keys are present). JS suite remains 4/4; whitespace and syntax checks pass. PHP/Laravel runtime tests remain unavailable.

## Batch 75 — Bangla Home result-lane labels

- The Home lane cards translate headings/field labels using each lane's own catalog. A path check found the four Bangla lane catalogs were absent, so the Home showed English headings and result fields. Added focused Bangla catalogs for National, Weekly, Bingo/Mega and PCSO headings plus every configured Home scalar field; test now asserts those rendered labels in Bangla.
- Static scan confirms all configured lane-field label keys exist in EN/TH/BN. JS suite 4/4, `git diff --check`, and countdown JS syntax pass. PHP/Laravel test execution remains unavailable.

## Batch 76 — Bangla National Lottery public catalog

- The national-results route still fell back to English for most page copy even though the Home card was translated. Added a complete Bangla National Lottery catalog covering all 94 EN keys, including search, history, pagination, provenance, source-state hints, status copy, and correction/conflict notices. Added full key/placeholder parity unit coverage and a Bangla page-render assertion.
- Static key and placeholder parity passes for 94 keys; `git diff --check`, countdown JS syntax, and JS tests (4/4) pass. PHP/Laravel tests remain unavailable.

## Batch 77 — Bangla Weekly Lottery public catalog

- The Weekly Results routes had the same English-fallback gap across headings, search/history, integrity/provenance explanations, status labels and pagination. Added a complete Bangla catalog matching all 114 English keys, and extended the public-page assertion to check Bangla heading/skip-link output.
- Static key/placeholder parity passes (114 keys); `git diff --check`, JS syntax and JS tests (4/4) pass. PHP/Laravel tests remain unavailable.

## Batch 78 — Bangla Bingo/Mega Lottery public catalog

- Added the complete Bangla catalog for the Mega/Bingo result routes, including their narrower integrity-state vocabulary and the explicitly unconfigured purchase state. This avoids English fallback without adding purchase actions or suggesting a configured product contract.
- All 118 English keys and placeholders now match Bangla; public-page locale and catalog-parity tests were added. Static key checks, `git diff --check`, JS syntax and JS tests (4/4) pass; PHP/Laravel tests remain unavailable.

## Batch 79 — Bangla PCSO public catalog

- Completed the fourth public result-lane catalog in Bangla (131 keys), including the 6D/4D/3D/2D fields, local draw-time copy, archive/search, provenance, integrity and explicit no-purchase-contract notices. Added public-page locale and catalog parity tests.
- Static key/placeholder scan passes for all four lane catalogs: National 94, Weekly 114, Mega/Bingo 118, PCSO 131. `git diff --check`, JS syntax and JS tests (4/4) pass. PHP/Laravel tests remain unavailable.

## Batch 80 — Bangla contact validation and honeypot labels

- A more precise all-app scan confirmed two contact validation messages lacked Bangla overrides. Added translations, corrected a duplicate `honeypot_label` override that incorrectly replaced “leave this field empty” with “Website,” and retained the separate field-name label.
- Added explicit translation assertions to the existing contact locale regression. PHP/Laravel execution remains pending.

## Batch 81 — Bangla verification and discount surfaces

- The precise catalog scan confirmed English fallback across the public verification verdicts, authenticity and barcode states, and discount table labels. Added Bangla overrides for all 41 missing statically referenced keys and the complete dynamic status/authenticity/barcode/reason vocabularies, preserving the “digital record only” and independent-platform caveats.
- Extended locale coverage to the Bangla verification page and added a key-by-key no-English-fallback regression for the relevant verification and discount copy. Static scan now finds no missing static `prize_discount` references; override key and placeholder checks pass. PHP/Laravel runtime tests remain unrun.

## Batch 82 — Live reference comparison: home, verification, discounts, fees

- Rechecked the live reference home page plus [`prize-verify.php`](https://www.thailotto.club/prize-verify.php), [`discount.php`](https://www.thailotto.club/discount.php), and [`fees.php`](https://www.thailotto.club/fees.php). The reference presents National and Bangkok Weekly results, a live video, metrics, bonus/promotional claims, payment brands, physical-ticket guidance, discounts, and a fee schedule.
- The project already has matching components/routes for results, optional configured live media, countdown, stats, configured campaigns/payment methods, prize verification, discount rules, and fees. No new feature gap was substantiated in these surfaces: project values remain config/source-driven and do not copy the reference's figures, official identity, or stream URL. The physical-ticket guidance remains conservative rather than reproducing its chemical-test instructions.

## Batch 83 — Live reference comparison: member entry and registration

- Compared the reference login and registration pages (`myaccount.thailotto.club/login.php` and `/members/register.php`) with local auth routes and the existing member-auth parity tests. Both provide account/email login, CAPTCHA, registration, and password recovery; the project also covers its registration fields and app-download route.
- The reference asks for an IC number and WeChat ID at registration. No equivalent pre-KYC collection was added: identity information belongs in the project's separate verification flow, avoiding unnecessary collection at account creation. No login/register route gap was found.

## Batch 84 — Bangla auth, privacy, and app-download surfaces

- The precise app-wide catalog audit found English fallback on the guest login/register/password-recovery screens, privacy UI, and app-download page. Added Bangla overrides for all statically referenced copy, including CAPTCHA instructions, password-reset email text, privacy search/status labels, download safety guidance, and the dynamic registration gender labels.
- Added a render regression for the five guest pages and a scanner-based regression that checks every static `public_pages.*` reference for a Bangla value different from English. Static scan now reports zero missing overrides across 193 references, excluding the intentional dynamic gender/timeline prefixes and machine-state `not_configured` token; no duplicate overrides or placeholder mismatches were found. PHP/Laravel execution remains pending.

## Batch 85 — Bangla authenticated player surfaces and status labels

- The next all-app scan found 236 missing Bangla player overrides across the dashboard, draws/results, bets, wallet, deposit/withdrawal, profile, security, responsible-gaming and shared navigation/error copy. Added those translations plus the explicit dynamic payment-method labels, preserving placeholders and provider brand names.
- The dashboard also derived account labels from `UserStatus` values but English/Thai catalogs lacked suspended, banned, and pending-verification labels, causing the fallback “Not configured.” Added these three status labels in EN/TH/BN. Added a static-reference scan regression, dashboard render assertion, and dynamic status/payment-key assertions. Static checks find no missing Bangla player overrides or placeholder mismatches; PHP/Laravel execution remains pending.

## Batch 86 — Bangla lottery hub and GLO L6 pages

- Added Bangla catalogs for the public lottery hub and dedicated GLO L6 pages. This closes English fallbacks across 29 and 79 statically referenced keys, plus the dynamic product/state/source-state/result-label vocabulary. Product cards, archive labels, purchase safeguards, and source messages are translated without enabling purchase or inventing results.
- Added render tests for the hub and GLO L6 page plus explicit checks for dynamic product, source-state, and result labels. Static key and placeholder scans report no missing references or mismatches. PHP/Laravel execution remains pending.

## Batch 87 — Localized support case states

- The authenticated support portal displayed database status, priority, and category codes verbatim. Replaced those raw values with allow-listed translation lookups and a translated unknown-value fallback on both case lists and detail pages.
- Replaced the Thai support catalog’s English copy with Thai translations and added a full Bangla support catalog, including open/in-progress/resolved/closed states. Added a Thai/Bangla detail-page regression. Catalog key and placeholder scans pass; PHP/Laravel execution remains pending.

## Batch 88 — Localized agent referral and commission portal

- The agent portal had no Bangla catalog, and its Thai catalog contained English text. Added complete Thai and Bangla copy for the authenticated dashboard, referral, commission, settlement, and statement surfaces. Dynamic portal/report states and commission/user status values now map to translated labels; unknown values use a neutral localized fallback instead of exposing raw enum codes.
- Added Thai/Bangla portal-render and static-reference regressions. EN/TH/BN key sets match (54 entries) and placeholder parity passes. PHP/Laravel execution remains pending.

## Batch 89 — Localized notification center and lifecycle labels

- The Thai notification catalog was English-only and Bangla had no catalog. Added full Thai/Bangla interface copy, translated all 19 notification-event and seven lifecycle-state labels, and changed the view to render mapped event/status/state labels rather than raw enum strings. Unknown values now use a neutral localized fallback.
- Added Thai/Bangla notification-center rendering and static/dynamic translation assertions. EN/TH/BN key sets match (48 entries) with no placeholder mismatches. PHP/Laravel execution remains pending.

## Batch 90 — Reference comparison: About and Vision & Mission

- Compared the live [`about.php`](https://www.thailotto.club/about.php) and [`vision.php`](https://www.thailotto.club/vision.php) pages with the local About/Vision views. The reference covers a choose/buy/win path, Thai-lottery history, a Vision/Mission statement, CLEAR values, and governance claims.
- The project already provides a four-step choose/buy/result/claim explainer, a source-labelled historical timeline, Vision/Mission and values sections, and explicit independent-platform caveats. No missing component was substantiated; government identity, official association, and unsupported governance claims were not copied.

## Batch 91 — Reference comparison: Contact

- The live [`contact.php`](https://www.thailotto.club/contact.php) lists an email, website, government-office location/address, and a submit form. The local `/contact` page already offers a secure contact form, privacy notice, delivery-configuration status, and only displays support identity/hours when configured.
- No component gap was found. The local page intentionally does not reproduce the competitor's government-office identity or address, which would be misleading for an independent platform.

## Batch 92 — Complete internal admin Bangla catalog coverage

- The all-app static-reference sweep found the last two missing Bangla catalog files: `admin` and `admin_release`. Added translations for their full English key sets (89 and 56 entries), preserving placeholders and machine-state contracts.
- Extended the existing admin release catalog parity test and added admin key/placeholder parity coverage. These are internal operations surfaces, not public competitor-parity components; the additions close the discovered locale fallback gap without importing competitor claims.

## Batch 93 — Live reference check: fees, discount, verification, grades, and results

- Rechecked the live [`fees.php`](https://www.thailotto.club/fees.php), [`discount.php`](https://www.thailotto.club/discount.php), [`account-verify.php`](https://www.thailotto.club/account-verify.php), [`account-grade.php`](https://www.thailotto.club/account-grade.php), [`prize-verify.php`](https://www.thailotto.club/prize-verify.php), [`national-lottery.php`](https://www.thailotto.club/national-lottery.php), and [`weekly-lottery.php`](https://www.thailotto.club/weekly-lottery.php) pages.
- Local coverage is present: fee/discount catalogues use this platform's own configuration, authenticated verification handles account/document workflow, grade tables are data-backed, and result lanes expose draw/history/archive routes. Do not copy the reference's fee/rate tiers, dollar amounts, government identity, or result values as business rules.
- The reference Prize Verification page includes advice to use Benzene/bleach-like chemicals on ticket paper. The local guidance deliberately avoids this hazardous test and emphasizes conservative verification. No new fee, discount, verification, grade, or result feature gap was found; validate live result freshness only against authorized trusted sources.

## Batch 94 — Restore published affiliate bands to the active discount page

- The reference homepage promotes referral, offer, and affiliate categories. A code-to-route trace found that the active discount route used `PublicLottoDiscountController`, which omitted affiliate data from its view and JSON response even though a public-safe, config-gated `AffiliateCommissionDisplayService` and an older controller integration already existed. Thus the commission catalogue was effectively unreachable from the current public page.
- Injected the safe service into the active controller, passed its published-only projection to the HTML and public API, and added an affiliate band table on `/discounts`. It lists only enabled `public_visible` bands, states that approval/eligibility applies, localizes EN/TH/BN labels, and displays a localized unpublished state when publication is off. Internal pilot bands and individual earnings remain hidden.
- Added a contact-inquiry CTA to the affiliate section because the existing agent-onboarding service has no public application route. This routes prospective partners to the configured contact workflow without auto-creating agents or collecting face/identity images. Regressions cover Thai/Bangla rendering, publication-off behavior, API output filtering, and affiliate labels; PHP/Laravel execution remains pending.

## Batch 95 — Home discovery for referral codes and affiliate terms

- The reference homepage highlights referral, current-offer, and affiliate categories. The local home page correctly showed configured campaigns only, but offered no direct route to the existing referral-code field or the newly restored affiliate terms.
- Added a compact, localized information strip in the campaign section: guests can reach registration to enter a referral code, with no promised reward; signed-in users see that attribution is collected at registration rather than a misleading signup link. Affiliate terms link to the published-only table, note approval requirements, and remain accurate when publication is disabled. Added EN/TH/BN labels and guest/authenticated render regressions.

## Batch 96 — Cross-catalog static reference sweep

- Re-scanned all English-backed Bangla catalogs after the new admin and affiliate/home work. No missing Bangla catalog files or uncovered static translation leaves were found in the checked namespaces (`home`, `prize_discount`, `discounts_page`, `admin`, `admin_release`, `notifications`, `agent`, `support`). Dynamic event/state prefixes have explicit tests and fallback behavior.
- `git diff --check` and JavaScript checks pass; PHP/Laravel tests remain unexecuted because the workspace has no PHP CLI or Composer vendor tree.

## Batch 97 — Live reference check: login and signup

- The live [`login.php`](https://www.thailotto.club/login.php) exposes password login, a remember-me option, password-recovery link, and social sign-in labels whose extracted links point to `#`. Local login and password recovery are functional routes; no unsupported social-login buttons were added.
- Signup is linked to a separate member-registration host and asks for referral ID, mobile, address/location, DOB, nationality, active email, IC number, and WeChat ID. The local flow separates registration from authenticated document verification and avoids copying the competitor's collection of IC/WeChat or other unnecessary data at signup. This is a privacy-preserving scope decision, not a missing-feature finding.

## Batch 98 — Partner inquiry path without public agent self-onboarding

- Search indexed a competitor agent-registration page, but a direct fetch redirected to the homepage, so its full workflow could not be confirmed. In the local app, the agent portal requires an active agent record; `AgentOnboardingService` needs an operator-controlled commission rate, currency, hierarchy, and approval state, and there is no public application route.
- Added a localized contact-inquiry CTA beside published affiliate bands. It gives prospective partners a reachable next step without turning a public form into automatic agent creation or copying the competitor's apparent face-image/ID collection. A self-service agent application remains a deliberate business/compliance decision, not a guessed feature.
