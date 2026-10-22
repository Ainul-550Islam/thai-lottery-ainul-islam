# Part 18 — bilingual results and persisted publication evidence

Date: 2026-10-14. Pinned base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Delivery: File176–File195, 20 complete files. Current cumulative delivered overlay:188 unique paths; next File196. Four existing paths are revised and16 paths are newly delivered. This is the delivered-file overlay against the pinned repository, not a complete dependency-inclusive repository export.

## Reproduced baseline and actual corrections

The unchanged `PublicResultsAnonymousTest` reproduced **7 tests /19 assertions /2 failures** after restoring the preceding172-file overlay and locked dependencies:

- The results page returned200 but omitted its expected lottery-results topic label.
- The old success fixture supplied a short non-SHA256 fingerprint and no canonical confirmation/import evidence, so the cache-publish command correctly refused it.

The refusal was not bypassed. The successful test now exercises actual ingestion, distinct maker/checker confirmation and the canonical result writer against a synthetic persisted import. Valid SHA256 alone is also explicitly tested as insufficient.

The page had a substantive defect beyond a label mismatch: it promoted provider strings such as `official_api` directly to `OFFICIAL_SOURCE_VERIFIED`. The public GLO service similarly promoted `official`, and treated any finalized draw as internally reconciled. A provider name or a completed status is not source evidence.

## Application changes

### Canonical evidence revalidation

`DrawResultPublicationService::verifiedProvenanceFor()` is a new read-only operation that reuses the writer's existing validation, confirmed-ingestion, bound-payload and persisted-import checks. It refuses a non-held/unpublished/mismatched result, missing or contradictory maker/checker identities, payload/fingerprint disagreement, unrelated or failed imports, stamp/reference mismatches and altered or invented provider tiers. It performs no publication, lifecycle transition, queue dispatch or money mutation.

This is a persisted-evidence consistency check, not independent external verification of the upstream provider. Synthetic official-lane fixtures passing it do not attest the authenticity of a real GLO announcement, endpoint, credentials or merchant agreement.

### Shared public disclosure

`PublishedResultSourceResolver` is used by the results page and the public GLO result service:

- `OFFICIAL_SOURCE_VERIFIED`: matching canonical official import/confirmation evidence, not a provider alias alone.
- `INTERNAL_RECONCILED`: matching controlled operator confirmation, explicitly not an official source.
- `FIXTURE_ONLY`: declared demonstration data in non-production. A legacy fixture declaration is only a demo disclosure, not proof of verification.
- `UNAVAILABLE`: missing/contradictory/unknown evidence; never promoted by status or configuration.

The results page and its composed latest-draw projection omit declared fixture rows when app.env=production. Its current candidate query remains bounded to the latest12 finalized, held draws. This part does not claim a complete historical export or that all unrelated public/lottery source-reader families have been audited.

The separate GLO information API may still return recorded values with an unavailable source state; it is not a settlement/payment authority. Other source-reader families and production fixture-data exposure paths remain subject to their own boundary review.

### Cache-publish behavior

`glo:publish-public-result` retains production fixture refusal, required SHA256, provenance constraints, finalized status, reconciliation conflict refusal and version-stamped keys. It additionally revalidates canonical persisted evidence before publishing or replaying. `--force` cannot override any of those gates.

An identical already-cached version without force is now a real no-op: no second publication audit, cache rewrite or home invalidation. Force refreshes only the same evidence-bound version and records its refresh audit. These are serial replay checks, not a shared-cache/database concurrency proof.

The `cache_ttl_seconds` value now means seconds for both payload and current alias; the old command multiplied it by60. A test advances time61 seconds with TTL60 and proves both expire. The public GLO result body uses a new provenance-v2 cache namespace and rechecks the source badge on every hit, so revoking an import cannot preserve a cached positive verification label. Configured tier metadata keys are respected by page, API version and publisher.

Redis/cache and SQL audit writes are not one distributed transaction. This part does not certify multi-process exactly-once cache publication or atomic cache/audit recovery. No cache alias is a financial settlement authority.

### English/Thai rendering

Both results language files now contain localized titles, description, table/source/empty-state labels, accessible actions/reference labels and a visible independent-platform disclaimer. The English topic mentions Government Lottery results without implying this is a government portal. The canonical view no longer calls the entire projection “verified”; each row carries its own evidence state. Thai is actual Thai content, not a duplicate English language file.

Result references remain trace labels, not proof that a result is genuine or payable. Checking a number does not authorize a claim, wallet credit or payment.

## Regression and fixture integrity

`PublicResultEvidenceTest` adds22 cases covering bilingual rendered DOM, every language key, positive canonical synthetic official evidence,11 evidence-corruption scenarios, provider-name/SHA-only forgery, actual operator confirmation, production fixture refusal, stale cache badge revocation, custom metadata keys, TTL units and serial replay/force audit behavior. Money-table counts remain unchanged through tested reads and cache publication/refusal.

Existing public and history assertions are retained with stronger fixture contracts. The old provider-only official-sheet expectation now requires unavailable status for forged evidence; verified evidence has its own positive test.

The production CLI guard tests exposed a mixed DatabaseTruncation/RefreshDatabase ordering assumption: valid earlier rows existed, so asserting an empty database was not proof of refusal. They now compare complete row-content snapshot hashes for results, winning numbers, financial transactions, ledger and audit before/after every refusal. This strengthens no-write coverage without deleting legitimate preexisting rows.

A whole-suite run also exposed the shared financial master fixture's future scheduled draw. That fixture opened future sales, then called it a published result without advancing to a held draw. Its result helper now models a past held schedule before confirmation. The public held-draw gate remains strict; it was not removed to make an internal-source assertion pass. The89-test master/finance group passed after that correction.

## Validation record

Database-writing suites ran serially against the guarded disposable repository SQLite store. Pure static/CI guard commands ran without application database bootstrap. Counts overlap and are not additive unique coverage.

| Check | Result |
|---|---|
| Unchanged anonymous-results baseline | 7 tests /19 assertions /2 failures |
| First repaired existing public/results/history classes | 99 tests /1690 assertions, pass |
| First dedicated evidence regressions | 20 tests /160 assertions, pass;2 additional cases added before final scoped/full runs |
| Final public-site/public-pages/history/production-publish group | 125 tests /1871 assertions, pass |
| Shared master/finance group after held-fixture correction | 89 tests /400 assertions, pass |
| Standalone static page contracts after inventory refresh | 15 tests /991 assertions, pass |
| Pure CI unit class | 19 tests /53 assertions, pass |
| Actual CLI guard /inventory /scanner /offline-runner guards | 6 /9 /11 /8 tests respectively, pass |
| Static financial preflight | Pass; no missing imported app class; not runtime/live approval |
| Inventory freshness /frontend build /14 PHP lints /Pint /whitespace | Pass |

The first whole-suite attempt hit an environment recovery issue: GD was missing after the workspace restart (**215 tests /113860 assertions /1 error /3 skips**). GD/GMP/MySQL/Redis PHP extensions were restored; this was not an application assertion repair. A second full diagnostic reached **1518 tests /121187 assertions /1 failure**, revealing the future master fixture described above. Both failure logs are retained, not replaced by a passing-only report.

### Final serial diagnostic and post-diagnostic checks

| Check | Recorded result |
|---|---|
| Full stop-on-error/failure after environment and held-fixture corrections | 2409 collected;1962 tests /125502 assertions /1 error;447 later cases not reached by this run |
| Isolated next queue class | 24 tests /64 assertions /1 error; same unconfirmed-publication setup |
| Final dedicated public evidence class | 22 tests /169 assertions, pass |
| Final production/CI unit group | 50 tests /123 assertions, pass |
| Final finance/settlement/conversion group | 214 tests /1934 assertions, no errors/failures,1 skipped |

The one scoped finance skip is `SettlementSimulationTest::point_20_lets_only_one_of_two_concurrent_settlements_write`: SQLite cannot prove the shared-server row-lock protocol. It is not a passed concurrency test.

The full run passes the historical terms/results blockers, the revised master fixture, and the new public evidence tests, then stops at `ProductionQueueComprehensiveTest::test_07_duplicate_prize_settlement_job_creates_exactly_one_payout`. That setup calls the canonical result writer directly without a persisted confirmed ingestion, so publication correctly refuses before its job is exercised. The old test then intends to call `ProcessPrizeSettlementJob` twice and expect one payout; those downstream assertions were not reached.

The next implementation investigation must exercise that queue path with a real confirmed, held fixture and the canonical per-draw approval/run/keyset/cursor/finalization contract. Do not weaken the source gate or assume a duplicate legacy job itself establishes exactly-once money settlement. No assertion from the failing queue class was deleted or waived here. No whole-suite green, queue-money acceptance or live-provider certification is claimed.

## Inventory and delivery integrity

Only four prior delivered paths are revised here: the production CLI guard test, the shared financial master test fixture, `audit.md`, and `evidence/release-source-inventory-part-13.json`. The latter two are deterministically regenerated current static source inventories; their historical filename is retained by the existing regression contract, not represented as immutable old evidence. They still declare STATIC_ONLY and deny runtime, production, live-payment and official-source certification. The other168 prior paths are byte-identical.

The inventory's hash mismatch was reproduced before regeneration, then freshness and15 static tests passed. The five-document generator was run explicitly; only those two output files changed. The scanner is likewise static-only.

Every numbered delivered file is included in full in the continuation Markdown and current188-path overlay ZIP. Package checks compare every ZIP body and Markdown body to source bytes, verify ZIP CRC and uniqueness, and validate SHA256 for ZIP/Markdown/registry. Dependencies, generated builds, databases, runtime secrets/environment and cached configuration are excluded. The two previously reviewed credential-empty environment templates remain the only `.env*` archive entries.

## Worldwide real-money boundary

The delegated worldwide/multi-country direction remains in force. This part improves real application disclosure and publication/cache behavior; it does not select or authorize an operator/provider/country/currency combination. Explicit jurisdiction eligibility, approved merchant-specific inbound/webhook/refund/outbound contracts, explicit terms-consent revision binding, durable settlement/reconciliation and shared-server/browser-to-ledger/provider acceptance remain release work.

Existing per-draw run/keyset/cursor-proof finalization, manual approval first, own-wallet conversion separation, fail-closed legacy member transfer/direct GLO writer and financial source gates are preserved. A public result badge, synthetic test, fixture fingerprint, successful cache command or CLI actor string cannot authorize customer funds.

Install locked dependencies and required PHP extensions/build assets before reproducing tests. Never retarget these guarded disposable-database suites to production. Full diagnostic failures/skips and any newly found next blocker are recorded below rather than waived as “real-money ready”.
