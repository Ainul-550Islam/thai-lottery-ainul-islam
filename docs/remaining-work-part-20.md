# Part 20 — usable bilingual wallet-conversion pages and currency-safe display

Date: 2026-10-14. Pinned base: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Delivery: File209–File225, 17 complete files; cumulative delivered overlay: 214 unique paths; next File226. One previously delivered path is revised (`WalletConversionActionController.php`); 197 prior bodies remain byte-identical. Sixteen paths are newly delivered, including pre-existing repository files now intentionally changed and delivered in full. The ZIP is a delivered-file overlay against the pinned repository, not the entire repository or its dependencies.

## Reproduced blockers and actual fixes

The unchanged translation-integrity class initially failed: **32 tests / 77 assertions / 1 failure**, with 86 literal references to the missing `wallet_conversion` namespace. Its scanner and assertions were not weakened.

Both English and Thai dictionaries now contain **147 keys**, with identical key order and replacement placeholders. This includes navigation, own-wallet limitations, server-owned fee examples, rolling limits, quote/receipt/refusal messages, JavaScript fallback messages and every current direction, status, wallet, fee-bearer, channel, reconciliation and rejection enum label. Dynamic enum checks explicitly disable English fallback. Thai copy is covered for Thai characters and placeholder parity, not claimed to have received an independent professional translation review.

The changes are not dictionary-only:

- Native web quote, conversion, replay and missing-receipt flash messages are translated. Engine refusal messages use the stable reason key; unknown conversion exceptions receive generic member-facing copy instead of raw exception text. Machine codes and execution outcomes remain unchanged. This is not a global application-log redaction or API-message localization certification.
- Shared amount/direction validation rules and custom quote/execute request messages are translated without changing their amount comparisons, validation bounds or permission checks. Closed requests can fail in validation before the controller runs; those refusals are not mislabeled as an engine financial result.
- Direction-switch labels and disabled titles, receipt wallet/channel labels, and recognized audit events/statuses are localized. Unknown audit vocabulary remains escaped text rather than an invented outcome.
- Open-quote headings use plural selection correctly. Expiry copy describes the recorded expiry timestamp, not an unfilled seconds placeholder. The daily-remaining wording matches the actual `amount` replacement supplied by the form.
- Conversion is explicitly between a member's own wallets in one currency. It is not another-member transfer or FX. Copy does not claim direct Win betting/withdrawal, unconditional Cash withdrawal, country authorization, provider settlement or official affiliation.
- Limits describe the actual rolling 24-hour and 30-day windows; reversing does not reset allowance. The published schedule is not represented as permission to execute a disabled or unpriced lane.

### Verified multi-currency display defect

The original page controller loaded all of the member's wallets and keyed them only by type. A later USD Primary/Win wallet could therefore replace the THB Primary/Win wallet displayed on a THB conversion page. The execution engine already scoped wallets by currency; this defect was in the actual member-facing display.

The page controller now filters wallet reads to its configured conversion currency before indexing by type. History amounts retain each record's explicit currency. The summary sentence displays completed counts only: it no longer formats the legacy all-currency volume aggregate as though it were entirely the currently configured currency.

The new currency regression was run against the controller from the actual pinned base, then restored in `finally`: **2 tests / 8 assertions / 2 failures**, both identifying the wrong `sourceWallet`. The corrected controller passes both locales as part of the final rendering class. The preceding 198-file ZIP did not contain this page controller; the pinned repository was used for that baseline, not an invented archive entry.

No fee rule, balance arithmetic, ledger posting, conversion service, wallet permission enum, member-transfer switch or monetary approval gate was changed. Previously delivered economic sources remain byte-identical. No FX rate or country-specific bank assumption was added.

## Actual HTTP and financial verification

`ConversionRenderingTest` adds **24 cases** using authenticated real Laravel routes, locale middleware/session persistence, real conversion services and a guarded disposable SQLite database. It does not mock the page controller, views, prices or wallet engine.

Coverage includes dictionary/enum resolution without fallback; open pages; master versus direction closure; unverified members; an unpublished live fee rule; preview JSON; localized native quote → execute → receipt → history → retry; request validation and engine refusals; mixed-currency wallet selection; and indistinguishable missing/other-member receipts. Server-rendered pages are checked for raw translation keys and unresolved member-facing placeholders.

The synthetic execution trace is unchanged economically: Cash **1000.00 → 900.00**, Win **0.00 → 99.00**, fee **1.00 THB** for a **100.00 THB** conversion. Retrying the same request leaves financial ledger row counts and balances unchanged. Preview and quote calls are separately checked as non-execution operations. These are synthetic test balances, not customer funds or provider transfers.

These tests verify actual server-side rendering and HTTP contracts. They do not execute JavaScript in a browser or establish browser-to-provider/live-money acceptance. The browser preview remains server-priced; its script and financial calculations were not changed.

### Final results

Database-writing runs were serial; no production database was used. Standalone static/Python guards ran independently without bootstrapping application database tests. Counts overlap and must not be summed as unique coverage.

| Check | Recorded result |
|---|---|
| Full serial diagnostic, all collected cases reached | **2448 tests / 130031 assertions / no failures or errors / 2 skipped**, exit 0; 08:47.870 |
| Final bilingual rendering class | **24 tests / 1060 assertions, pass** |
| Final conversion and translation group | **97 tests / 1307 assertions, pass** |
| Unchanged translation-integrity class | **33 tests / 79 assertions, pass** |
| Final finance / settlement / conversion / checkpoint / pipeline / safety group | **296 tests / 3282 assertions / no failures or errors / 1 skipped** |
| Final production and CI group | **63 tests / 168 assertions, pass** |
| Standalone static page contracts | **15 tests / 991 assertions, pass** |
| Standalone CI unit class | **19 tests / 53 assertions, pass** |
| Python CLI / inventory / scanner / runner guards | **6 / 9 / 11 / 8 tests respectively, pass** |
| Financial source preflight and inventory freshness | Pass; static-only findings, no live-payment certification |
| Frontend build / 9 PHP lints / Pint / whitespace | Pass |

The full run occurred before Pint's three style-only normalizations (qualified class imports and operator spacing). The final 97-case group, 24-case class, 33-case integrity class, 296-case financial group and 63-case production group were rerun after formatting. No subsequent financial behavior edits were made. Exploratory rendering attempts exposed incorrect harness assumptions and missing replacement wiring; final assertions still cover actual policy closures, exact financial outcomes and retry safety. Their exploratory logs remain in the workspace; delivery runtime evidence includes the final results and the reproduced baseline/currency failures.

### Two explicit skips, not two passes

1. `SettlementSimulationTest::point_20_lets_only_one_of_two_concurrent_settlements_write`: SQLite does not establish the equivalent shared-server row-lock protocol. The scoped financial run records the full skip reason. This still needs an authorized isolated shared-server concurrency run.
2. `WeeklyLotteryPublicPageTest::test_51_php_and_the_native_verifier_agree_or_the_test_is_skipped`: the Rust verifier binary is not built in this environment. An isolated rerun records **1 test / 0 assertions / 1 skipped**. PHP/Rust runtime agreement has not been established by this delivery.

Unlike Part19's stopped run, the 224 cases beyond its stopping point are now reached in the complete diagnostic, alongside the added translation namespace case and 24 rendering cases. There is no newly observed failing class in that complete run; it is nevertheless not an all-cases-passed or production approval claim because of the two skips and deployment acceptance work below.

## Worldwide architecture: next selected work, starting File226

The next source-verified monetary correctness gap is denomination handling in conversion read APIs and invalid-currency configuration/request handling:

- `ConversionHistoryService::summaryForUser()` still sums input/output/fee money across every currency for a member without returning a currency breakdown. The page no longer displays that sum with a false single-currency label, but API aggregates still require an explicit per-currency contract. No implicit FX or mixed-denomination monetary total should be introduced.
- Quote/execute requests and the page still contain `Currency::primary()` fallback paths. Unsupported or malformed currency input/configuration should be explicitly refused rather than silently represented as a different denomination. Open-quote displays after an operator currency change also need explicit recorded-currency handling. Exact execution currency, fee-rule compatibility and approval gates must remain authoritative.
- The current enum contains THB, USD and BDT. This delivery does not claim every world currency, language or country is enabled. Adding approved country/provider/currency combinations requires explicit operator policy, settlement capability, jurisdiction eligibility and provider credentials; unapproved combinations must stay closed.

Also outstanding: the two skipped runtime proofs; approved official-source authenticity; worker/deployment acceptance; and authorized live provider/customer-funds reconciliation. Existing merchant/operator/country approvals were not supplied and are not manufactured by publishing a bilingual page. Own-wallet conversion stays separate from disabled member transfers; direct legacy GLO money writers and result/confirmation/settlement approval gates remain protected.

## Delivery integrity

All 17 delivered bodies are complete, with no elision. The cumulative ZIP, Markdown, registry and SHA256 manifest are checked for entry uniqueness, ZIP CRC, exact body equality and preservation of all 197 untouched prior bodies. Runtime `.env`, cached configuration, databases, dependencies, generated frontend artifacts and credentials are not packaged. Only the already-approved environment templates from prior deliveries remain in the cumulative overlay.
