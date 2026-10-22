# Part 17 — bilingual terms disclosure and isolated legal revision

Date: 2026-10-14. Pinned base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Delivery: File164–File175, 12 complete files. Cumulative delivered overlay: 172 unique paths; next File176. This ZIP is the delivered-file overlay against the pinned repository, not a vendor-inclusive complete repository export.

## Actual application changes

1. English and Thai terms explicitly identify the site as independent and not the official GLO website. They deny government ownership, operation, endorsement and agency status. Referencing prize rules does not establish permission to offer wagering or payments in any jurisdiction.
2. Removed the unsupported canonical-view claim of “approved public legal content”. The replacement identity note and home-link accessible label are localized in both supported languages.
3. Terms now have independent metadata: `legal.terms.version=v1.0.1`, fixed effective/updated dates `2026-10-14`, and terms content revision `2`. `TermsPageService` reads that namespace with legacy-configuration fallbacks.
4. `PublicPageDataService` keys terms caches by locale, terms version/content revision/effective/updated dates, and public content revision. Cached, uncached and unavailable outputs report the same terms-scoped `legal_version`.
5. The existing shared privacy version remains `v1.0.0`, with dates `2026-09-24`. No old privacy policy, registration preference, acceptance timestamp or rule version was rewritten.

The old disclaimer already denied affiliation indirectly. Its phrase-only failure was not evidence that the old site claimed government ownership. The unsupported approval wording in the actual view and revision/cache isolation are substantive application corrections, not merely an assertion replacement.

## Operator metadata settings

| Environment variable | Repository default | Purpose |
|---|---|---|
| `LEGAL_TERMS_VERSION` | `v1.0.1` | Terms document revision |
| `LEGAL_TERMS_EFFECTIVE_AT` | `2026-10-14` | Fixed terms effective date |
| `LEGAL_TERMS_UPDATED_AT` | `2026-10-14` | Fixed terms updated date |
| `LEGAL_TERMS_CONTENT_VERSION` | `2` | Terms-only cache invalidation |

The existing `LEGAL_VERSION`, `LEGAL_EFFECTIVE_AT`, `LEGAL_UPDATED_AT` and `LEGAL_CONTENT_VERSION` remain the shared legacy/privacy settings. Do not bump those to invalidate only terms. Do not generate policy dates per request. The dedicated terms settings are optional; neither prior environment template nor any credential was changed in this delivery.

These defaults record a repository content revision. They are not evidence of live publication, operator approval, a legal review, a merchant agreement or permission in any country. Operators must review configured metadata and actual deployed text before publication. Existing production cache/deployment procedures still apply after changing configuration.

## Regression coverage

Seven new `TermsRevisionIsolationTest` cases exercise:

- The actual rendered disclaimer section in English and Thai, API disclosure parity and localized identity note, not merely finding strings somewhere in a page.
- Absence of the unsupported approval statement.
- Terms revision changes while privacy data/version stays unchanged.
- Content-only cache-key invalidation, without changing the display version.
- Effective/updated date cache invalidation and unchanged privacy effective date.
- Zero-TTL and synthetic cache-outage terms metadata.
- Distinct English/Thai cache entries.
- Actual authenticated GETs and the unconfigured acceptance endpoint preserving a stored user's entire raw row, including historical consent/preferences.

`/api/v1/public/terms/accept` and `/api/v1/public/terms/download` remain `501 NOT_CONFIGURED`. GET is not consent. This part does not manufacture a public acceptance/export capability, migrate consent or bind the new public revision to new-registration acceptance. Those require a separately implemented explicit consent contract.

## Recorded checks

All database-writing commands ran serially against the repository's guarded disposable SQLite test database. Standalone static/guard checks do not bootstrap database-writing application tests. Counts overlap and must not be added as unique coverage.

| Check | Result |
|---|---|
| Unchanged terms/UI baseline | 23 tests /127 assertions /1 failure |
| First repaired existing terms/UI classes | 23 tests /137 assertions, pass |
| Final complete public-page directory | 74 tests /1610 assertions, pass |
| Final dedicated revision/isolation class | 7 tests /61 assertions, pass |
| Final public-page/auth/API-auth/auth-gate group | 142 tests /1958 assertions, pass |
| Production feature + CI pure unit group | 50 tests /123 assertions, pass |
| Serial finance/settlement/conversion group | 214 tests /1934 assertions, 1 skipped, no errors/failures |
| Settlement skip diagnosis | 116 tests /1530 assertions, 1 skipped; real server row-lock concurrency cannot be proved by SQLite |
| Full stop-on-error/failure diagnostic | 2387 collected; stopped after1928 tests /121784 assertions /1 failure;459 later cases not reached by that run |
| Isolated next-blocker class | 7 tests /19 assertions /2 failures |
| Standalone production CI unit | 19 tests /53 assertions, pass |
| Standalone page static contracts | 15 tests /991 assertions, pass |
| Actual CLI guard /inventory /scanner /offline-runner tests | 6 /9 /11 /8 tests respectively, pass |
| Static financial preflight | Pass; no missing imported app class; not live payment proof |
| Inventory freshness /frontend build /8 PHP lints /Pint /whitespace | Pass |
| Prior overlay preservation | All160 previously delivered paths byte-identical; no runtime `.env` or cached config |

The full diagnostic ran before the final content-only/outage assertions were added to the existing isolation methods; the test count stayed2387. The final 74-test and142-test runs execute those final assertions. No final whole-suite green result is claimed.

## Actual next blockers, not waived

The full diagnostic now passes the historical terms blocker and reaches `PublicResultsAnonymousTest::test_results_page_is_public_without_login`. HTTP200 is returned, but the result view does not contain the old exact label `Government Lottery results`. Its current English “Public Results Hub”/projection wording requires a meaningful content/provenance review, not simply inserting an old phrase.

Running the entire next-blocker class separately also reveals `test_publish_command_publishes_fingerprinted_result_once`: an old fixture-based setup expects publication success, but the command returns1. A fingerprint alone must not bypass canonical source/publication authority. Investigate the actual refusal and update implementation/fixture contracts without weakening provenance, manual approval, held-draw or financial settlement gates. No failing assertion was deleted in this part.

The Thai results translation file also visibly contains English labels. That is a real localization follow-up, independent of the exact English phrase failure. Results source labels must not imply official verification merely because a provider is named “official” or because a row was published internally.

## Worldwide money work still requiring implementation/evidence

The delegated global/multi-country direction remains in force, not a Bangladesh-only bank assumption. No authorized operator/jurisdiction/currency/provider combination was supplied or established by this terms revision. Unsupported/unapproved combinations must stay closed; choosing official-compatible wording does not authorize payments.

Remaining substantive release work includes explicit jurisdiction/currency/provider eligibility, consent revision binding, approved merchant-specific inbound/webhook/refund/outbound contracts, durable audit/replay/reconciliation controls across the selected providers, shared-server financial concurrency, and authorized staged browser-to-ledger/payment acceptance. Existing canonical per-draw run/keyset/cursor-proof finalization, manual approval first, own-wallet conversion separation, disabled legacy member transfer/direct GLO writer, and official provenance/settlement gates remain intact.

The214-case finance group includes one real row-lock concurrency skip. It is not a passed server concurrency test. No production database, live customer money, merchant credentials, official-feed authenticity or universal legality was certified.

## Delivery and reproducibility

`evidence/runtime-part-17.txt` includes baseline, final scoped, skipped, full-failure and isolated-next-blocker logs rather than reporting only passing subsets. `evidence/financial_source_preflight-part-17.json` contains the actual scanner result.

Install the repository's locked Composer/npm dependencies and build frontend assets before framework tests. Run database-writing suites serially against the explicitly guarded disposable store; do not retarget those commands to production. The archive excludes dependencies, build products, databases, secrets and generated runtime environment/cache. Every numbered file is provided from first byte to last byte in the continuation Markdown and current overlay ZIP. SHA256 hashes and ZIP/body byte comparisons accompany the delivery.
