# Part 21 — denomination-safe conversion APIs and strict currency boundaries

Date: 2026-10-15. Pinned repository HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Delivery: File226–File245, 20 complete bodies. Cumulative delivered overlay: 221 unique paths; next File246. Thirteen prior paths are intentionally revised, 201 prior bodies remain byte-identical, and seven paths are newly delivered. The new paths include two existing repository DTOs now changed and delivered in full. The cumulative ZIP is an overlay against the pinned repository, not a complete repository/dependency export.

## Reproduced application defects

Before application edits, the new currency-boundary tests with corrected positive-owner DTO fixtures reproduced **23 tests / 48 assertions / 22 failures** against the preceding delivery and pinned repository. The one already-passing case was a valid configured USD HTTP conversion; that behavior was retained.

The reproduced defects included:

- Invalid or missing currency configuration being represented as a primary-currency page instead of unavailable configuration.
- Explicit null/empty currency falling through to the configured denomination rather than being rejected.
- A preview accepting a different supported currency from the configured conversion currency.
- Conversion DTO parsing silently replacing an invalid or omitted currency with THB, even when USD was configured.
- Summary monetary fields combining a member's THB and USD records into one unlabelled monetary total.
- An open THB quote being formatted in USD after configuration changed.

An earlier exploratory attempt also used owner id zero in DTO fixtures; its InvalidArgumentException is not counted as currency-defect proof. The corrected baseline above ran before application changes. Existing translation, financial and rendering assertions were not weakened.

## Application fixes

### One strict currency resolver

`App\Support\Wallet\ConversionCurrency` separates denomination parsing, configuration resolution and the HTTP adapter:

- Supported denomination values are explicit Currency cases or canonical supported codes. Unsupported values, wrong types and malformed codes are refused; there is no primary-currency guess.
- Configuration must contain a valid supported string code. Missing, null, malformed or non-string configuration raises the stable `currency_not_configured` reason.
- At the HTTP boundary, invalid configuration returns **503**, including a safe localized API envelope, instead of a successful THB preview/page or an unrelated amount-validation error.
- An omitted request field uses the valid deployment configuration. An explicitly supplied malformed/null/empty field receives a **422 validation error**.
- A valid supported code different from the configured execution denomination is refused before preview/quote/execution. JSON retains the existing **`currency_mismatch` / 422** vocabulary rather than changing it to a generic validation code.

Quote and execution FormRequests use the same resolver. DTO parsing, fee-breakdown currency resolution, fee-calculator denomination validation, policy configuration checks and implicit daily/monthly-limit denomination resolution no longer use `Currency::primary()` fallbacks in this conversion lane. Direct typed service calls remain protected by the policy, not only HTTP validation.

The rejection enum now explicitly represents `currency_not_configured` and the already-used `currency_mismatch`. Configuration failures are not user-correctable. English and Thai dictionaries now have **150 keys per locale**, preserving all prior wording and adding three symmetric messages. The existing fallback-disabled enum/placeholder tests cover the new vocabulary.

These guards do not authorize a country, payment provider or FX operation. Existing identity, account-standing, wallet, quote, idempotency and financial-approval gates remain authoritative.

### Currency-scoped summaries, without an implicit exchange rate

`ConversionHistoryService` accumulates exact decimal strings into separate currency buckets. It reads the member's completed non-reversal records, validates each recorded denomination and uses `bcadd` rather than a potentially floating-point SQL SUM for monetary fields. It does not round or convert one denomination into another.

The original exclusion policy is retained: reversed originals and the opposite completed reversal rows are not counted as fresh completed conversion volume. An actual operator-authorized synthetic reversal tests this behavior and exact restoration of the member's balances.

#### API contract

`GET /api/v1/wallet/conversions/summary` remains authenticated and owner-scoped.

| Field | Meaning |
|---|---|
| Existing status counts and `total` | Counts across currencies for this authenticated member, not across members |
| `counts_scope` | Literal `all_currencies`, making the count scope explicit |
| `currency` | The denomination of the three top-level monetary fields |
| `completed_volume_in` | Completed input volume in that denomination only, decimal string |
| `completed_volume_out` | Completed output volume in that denomination only, decimal string |
| `fee_total` | Completed fees in that denomination only, decimal string |
| `by_currency` | Separate THB/USD/BDT objects with their own `currency` and the same three monetary fields; empty buckets contain `0.00` strings |

With no currency filter, the top-level monetary fields use the valid configured conversion currency. An explicit supported `?currency=USD`, `THB` or `BDT` selects historical monetary totals in that denomination; it does not change deployment configuration or authorize execution in that currency. Counts and the full bucket map retain their stated scopes. A supported explicit historical filter can be used independently of a broken default configuration; an omitted filter never guesses THB.

The endpoint and original scalar field names remain, but their incorrect mixed-denomination semantics are intentionally corrected. Clients must read `currency` and `counts_scope`; they must not treat all-currency counts as the number of records in the selected money bucket or add different currency buckets into a financial total. The read scans completed history to preserve exact model decimal values; production-volume performance and consistent snapshot requirements still need deployment workload measurement.

### Open quotes retain recorded currency and cannot populate the wrong form

An open quote is formatted using its own stored denomination, with its ISO code displayed explicitly. A THB quote therefore remains a THB informational record when the operator switches to USD.

The active form reference is selected only from the current member's still-open quotes matching the displayed direction and configured currency. A stale cross-currency session reference is not copied into the USD execution form. The old quote is not consumed or rewritten merely by viewing the page; execution still performs its locked quote checks. Currency validation errors also have an explicit member-facing error location in the quote form.

## Financial behavior preserved

No FX rule, fee percentage, provider/country approval, member-transfer switch or auto-credit threshold was enabled. The fee configuration, conversion configuration, wallet permission enum, wallet model and wallet/ledger mutation engine were not changed by this delivery. The fee arithmetic body is verified unchanged after ignoring style-only whitespace normalization; the new calculator statement is a denomination guard before arithmetic.

The synthetic HTTP tests establish:

- Configured USD and BDT omission cases debit the configured denomination's own Primary wallet from **1000.00 to 900.00** and return **99.00** net after **1.00** fee; the member's THB wallets remain unchanged.
- A completed **100.00 THB** conversion and **200.00 USD** conversion yield separate **100.00 / 99.00 / 1.00 THB** and **200.00 / 198.00 / 2.00 USD** summary buckets, not an invented `300.00 THB` total.
- Another member's actual conversion does not contaminate either bucket.
- Fractional **10.03 + 10.07** input volumes produce exact **20.10** input, **19.90** output and **0.20** fee strings.
- Invalid configuration/input does not create a quote, conversion or ledger entry through the tested HTTP surfaces and does not debit the member. Direct service refusal auditing may record a refusal, but never an executed conversion.
- Historical quote rendering does not consume the quote, and stale session references do not become executable references for another denomination.

Balances are synthetic fixtures, not live customer funds or provider transfers. Native HTTP/server-rendered form and API contracts are exercised; browser JavaScript execution and provider/customer-funds acceptance are not certified by these tests.

## Final verification

All database-writing suites ran serially on the guarded disposable repository SQLite store. Static and Python checks did not run concurrent application database suites. Counts overlap and must not be added as unique coverage.

| Check | Final result |
|---|---|
| Full serial diagnostic, all 2475 collected cases reached | **2475 tests / 130294 assertions / no failures or errors / 2 skipped**, exit 0; 08:33.127 |
| New currency-boundary class | **27 tests / 243 assertions, pass** |
| Conversion and translation group | **124 tests / 1570 assertions, pass** |
| Unchanged translation-integrity class | **33 tests / 79 assertions, pass** |
| Finance / settlement / conversion / checkpoint / queue pipeline / safety group | **323 tests / 3545 assertions / no failures or errors / 1 skipped** |
| Production and CI group | **63 tests / 168 assertions, pass** |
| Runtime-recovery classes | **88 tests / 830 assertions, pass** |
| Standalone static page contracts | **15 tests / 991 assertions, pass** |
| Standalone CI unit class | **19 tests / 53 assertions, pass** |
| Python CLI / inventory / scanner / runner guards | **6 / 9 / 11 / 8 tests respectively, pass** |
| Source preflight and inventory freshness | Pass; static only, no missing imported app class |
| Frontend build / 15 PHP lints / Pint / whitespace | Pass |

The final complete run used the final formatted application/test source. No subsequent application behavior edits were made. The final currency, translation, financial and production groups were additionally rerun afterward.

### Runtime restoration, not test weakening

This turn's sandbox lacked PHP/Composer initially and required restoration of PHP 8.4, locked Composer dependencies and frontend dependencies. The initial restored PHP profile still lacked GD and PDO MySQL.

A first full attempt was deliberately interrupted while preserving the mismatch response contract; its partial log is not a completed result. A subsequent completed diagnostic reached all cases but reported **2475 tests / 130122 assertions / 40 errors / 5 skipped**. All 40 errors were the exact Laravel image-fixture failure `GD extension is not installed`. Three additional skips came from the absent MySQL PDO driver.

Installing the missing GD and SQL-driver extensions resolved these runtime prerequisites without changing the upload or database-driver assertions. The affected classes passed **88 tests / 830 assertions**. A fresh full run then produced the final result in the table. Raw baseline, interrupted, missing-extension and final logs are retained in runtime evidence rather than calling the faulty runtime green.

### Two remaining skips are not passes

- `SettlementSimulationTest::point_20_lets_only_one_of_two_concurrent_settlements_write`: SQLite does not establish an equivalent shared-server row-lock protocol.
- `WeeklyLotteryPublicPageTest::test_51_php_and_the_native_verifier_agree_or_the_test_is_skipped`: the Rust verifier binary is not built in this environment.

There is no newly observed failing application class in the final complete diagnostic. This is local verification with two explicit gaps, not unrestricted production or live-money approval.

## Next selected work — starting File246

The next priority is actual isolated server-SQL financial concurrency, using a separately provisioned disposable local MariaDB/InnoDB store and the existing guarded runner. Its allowlist requires the exact database `thai_lottery_test`, a dedicated local socket/account, explicit disposable-database acknowledgment and InnoDB financial tables; it cannot be retargeted to arbitrary or production data.

Run the existing last-balance purchase, last-capacity purchase, identical-request replay and non-monetary settlement races, then cover actual own-wallet conversion/queued settlement contention where appropriate. Fix concrete application defects if server locking exposes them; do not replace an actual monetary race with a sequential or mock proof. The non-monetary settlement race alone is not proof of exactly-once monetary prize payout. Native PHP/Rust agreement remains a separate pending runtime task.

Worldwide deployment also still requires approved operator/jurisdiction/provider/currency combinations, official-source authenticity and authorized live provider reconciliation. The supported denomination enum remains THB/USD/BDT; adding country coverage or other currencies must not silently enable unapproved execution. This delivery improves denomination correctness without claiming universal legality or live integration.

## Package integrity

All 20 delivered bodies are complete, with no elision. ZIP CRC, entry uniqueness, exact workspace body equality, Markdown body extraction, 201 untouched prior bodies and SHA256 manifests are checked. Runtime `.env`, cached configuration, databases, local SQL server files, dependencies, generated frontend artifacts and credentials are not packaged. Only previously approved environment templates remain in the cumulative overlay.
