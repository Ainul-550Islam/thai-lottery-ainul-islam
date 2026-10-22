# Part 14 — player presentation contracts without weakening money authority

Date: 2026-10-14. Pinned base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File134–File138, all complete without elision. Cumulative archive: 135 unique delivered overlay paths. All prior 130 delivered paths remain byte-identical. Next label: File139. The archive is an overlay, not the full base repository, deployment approval or production verification.

## Reproduced remaining failures

After restoring prior delivered paths before edits and recovering disposable dependencies/frontend assets, the unchanged `PlayerExperienceComprehensiveTest` reproduced both Part13 failures: 41 tests / 164 assertions / two failures.

1. `test_15_web_draws_and_detail_views_render_properly` expected a literal English `Draw #` prefix. The current draw-detail Blade template instead renders the requested draw number as its semantic `<h1 id="draw-detail-heading">`, with a localized page title and surrounding labels. The controller resolves numeric IDs or exact draw numbers. The response was HTTP 200 and contained the correct record, not a missing draw or render failure.
2. `test_26_player_can_fetch_wallet_balance_and_available_amount` expected the storage-field name `locked_balance` in the JSON resource. `WalletResource` deliberately exposes it as `reserved_balance`. The actual response already had correct decimal strings: balance 5000.00, reserved 500.00 and available 4500.00, with the authenticated player's wallet ID/currency/status.

Neither failure justified rewriting the application heading, introducing a duplicate API alias, weakening authentication/owner filters, changing decimal precision, changing monetary fillable fields or bypassing canonical ledger writes. Application controllers, resources, views, models, financial services, routes and provenance controls are **unchanged** in this part.

## Corrected existing assertions

The comprehensive class now requires the actual draw-detail view and requested `Draw` model, localized title, draw number and semantic heading marker. It no longer imposes an obsolete English presentation prefix on a bilingual view.

The wallet check requires the resource's actual `reserved_balance` contract, exact string amounts and correct wallet ID, and confirms the storage-only `locked_balance` field is not leaked as a new alias. This is preservation of the existing public shape, not a new API version or a claim that all legacy/external clients are compatible. Formatting-only Pint changes to the full test class are included.

The test's direct wallet assignments remain synthetic fixture preparation inside the guarded disposable database. They are not a replacement for production wallet-service transactions or proof that real money was credited.

## Fourteen new HTTP regression cases

`PlayerPresentationContractTest` uses the real Laravel routes/middleware/rendered views and JSON serializer:

- Four locale/identifier combinations: English/Thai and numeric draw ID/exact draw number. DOM/XPath checks exactly one semantic heading with the requested number and a matching localized title. A second fixture draw must not be substituted or displayed. The fixture draw remains unchanged and has no result row; the pending-results message is required.
- Missing numeric/string draw identifiers return 404 instead of falling back to another record.
- Guests cannot access authenticated draw-detail pages.
- Five balance scenarios: zero, fractional cents, large decimal strings, fully reserved funds, and a frozen wallet with reservation. Balance/reserved/available fields must be exact strings, not JSON numbers. Available arithmetic remains bcmath/model-derived. A reserved amount is not an administrative lock; a frozen wallet remains non-transactable even with a positive available value.
- Wallet GET leaves the persisted wallet row unchanged and creates no financial transaction/ledger entries in those five scenarios. Snapshots are taken after refreshing fixture rows, so SQLite's persisted decimal representations/order/default columns are compared consistently, not against unreloaded factory attributes.
- Primary wallet selection is checked with secondary Bonus/Win wallets created first. Query-supplied foreign user/wallet IDs cannot change the authenticated owner scope; a foreign wallet ID/balance must not leak into the primary or collection response.
- Missing bearer authentication returns 401 without disclosing wallet data.
- An authenticated account with no wallet returns typed `wallet_not_found`/404 instead of another user's wallet.

These are 14 collected cases through data providers, not 14 unrelated production certifications. DOM assertions inspect server-rendered HTML; they do not certify JavaScript, browser layout, live broadcasts, provider callbacks or official result authenticity. Primary/Bonus/Win are the actual wallet enum cases; no unsupported Cash enum or money lane was introduced.

## Validation

| Scoped check | Result |
|---|---|
| Unchanged player baseline | 41 tests / 164 assertions / two reproduced failures |
| Corrected comprehensive + new presentation suite | 55 tests / 316 assertions, pass |
| Standalone static page contracts, no Laravel bootstrap | 15 tests / 991 assertions, pass |
| Passive source inventory --check | Source hashes and documents match; false attestation flags preserved |
| Inventory offline guards | 9 tests, pass |
| Existing source-preflight guards | 11 tests, pass |
| Offline SQL runner guards | 8 tests, pass; no SQL server contacted |
| PHP lint/Pint | Both delivered PHP test files, pass |
| Financial source preflight | Pass; runtime_verified=false |
| Draw/GLO/operator write matrix | 111 tests / 683 assertions, pass |
| Console regression | 45 tests / 204 assertions, pass |
| Broad financial/readiness/Payment/Finance | 209 tests / 991 assertions, pass |
| Betting/Risk/SQLite retry | 63 tests / 300 assertions, pass |
| Wallet conversion | 71 tests / 259 assertions, pass |
| Current collection | 2,330 cases, not proof of execution |
| Authoritative serial repository diagnostic | 1,700 executed / 119,547 assertions / one frontend market-options failure; later cases unexecuted |

Full logs, including intermediate mistakes in new test fixture expectations, are preserved without elision in `evidence/runtime-part-14.txt`. The initial new-suite diagnostic used an invalid Cash enum and compared unreloaded factory attributes to persisted rows; the fixture assertions were corrected against actual enum/schema, never by changing application behavior. Scoped counts overlap and must not be summed as unique coverage.

All database-writing PHPUnit commands are serial. The pure static/offline checks do not share or mutate the guarded test database. No merchant, bank, customer database or live payout endpoint was contacted.

## Next actual remaining work — File139 onward

The authoritative repository run stopped at
`Tests\Feature\Player\PlayerFrontendModulesTest::test_bet_page_offers_only_market_keys_the_api_accepts`, line 179: the regex extraction found no `<option value="...">` market options.

The unchanged class was then isolated: **11 tests / 104 assertions / three failures**:

1. `test_bet_page_offers_only_market_keys_the_api_accepts`.
2. `test_bet_page_publishes_the_configured_digits_and_multipliers`.
3. `test_bet_slip_container_mirrors_the_server_side_item_ceiling`.

Next inspect the current bet view, frontend module selectors/data attributes, enabled config markets, canonical BetMarket values and BulkBetRequest server validation together. Determine whether these expectations are obsolete or the UI is no longer supplying usable metadata. Do not blindly reintroduce `<option>` elements, fake data attributes, loosen server validation or change operator multipliers/ceilings merely to green the tests. Preserve actual market eligibility, server-authoritative quotes, price arithmetic, ownership and canonical purchase transaction boundaries.

The new 14 presentation cases passed in the isolated 55-case matrix. The repository stop-first run reached PlayerFrontendModules before the later PlayerPresentation class; its remainder is not claimed as executed. There are 630 collected cases beyond the observed stop, not 630 known passes.

No unexecuted case is assumed green. Do not patch expected failures using fake source claims, role bypasses, disabled foreign keys, weaker protected fields, unsafe monetary casts or alternate direct wallet writers.

## Production gates still unverified

- Authentic official draw source, complete source-specific announcement/inventory schema and imported-prize review UX. Synthetic fixtures and internal checker approval do not establish `OFFICIAL_SOURCE_VERIFIED`.
- Actual member/operator browser sessions, JavaScript rendering/realtime behavior, CSRF/denial/replay/expiry/history, destination evidence and recovery. These HTTP tests are not browser/provider certification.
- Intended MySQL8/PostgreSQL version and deployment topology, isolation, pooling, workers, failover/replicas, contention, actor revocation/deletion and recovery. Historical Part9 MariaDB races are limited evidence, not current production certification.
- Durable queue/outbox retries and crash boundaries, abandoned persisted settlement runs, serial keyset cursor ownership and proof-required finalization in the intended runtime.
- Authorized merchant/bank execution, eligibility, signed callbacks, reconciliation/refunds/reversals, credentials and operational controls. Approved/Paid labels do not prove external bank delivery; no live payout was enabled.
- Qualified jurisdiction/licensing, age/KYC/AML, privacy/retention and responsible-operation approval before real funds.
- Staging isolation, least privilege/secrets, backups, reproducible builds/migrations, monitoring and actual Rust crate/binary verification where applicable. The Part13 source inventory still does not execute Cargo or certify runtime.

Legacy member transfer stays fail-closed; own-wallet Cash↔Win conversion remains a distinct canonical workflow. Alternate GLO direct settlement remains disabled. Maker/checker, source/provenance, settlement proof/quarantine and monetary permissions are unchanged.

The ZIP overlays 135 delivered paths on the pinned base and excludes .env, credentials, uploads, test databases, disposable server data, vendor/node_modules and generated assets. Every File134–File138 body is complete in `Full-Code-Continuation-Files-134-138.md`; unique paths, CRC, byte equality and SHA-256 are checked. This is not deployment approval.
