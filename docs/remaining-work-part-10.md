# Part 10 — real operator draw workflow and remaining acceptance gates

Date: 2026-10-14. Repository base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File100–File108, complete source without elision. The cumulative archive contains 105 unique delivered paths and is a source overlay on the supplied repository, not a full repository or deployable release. All previously delivered 96 paths are unchanged. Next label: File109.

## Closed: Filament draw-publication blocker

The Part 9 first blocker was not solved by weakening publication requirements. The old Filament action called DrawResultPublicationService directly with raw numbers, so the domain correctly refused a missing Confirmed maker/checker record. The stale test expected one click to publish.

The shared table/header action factory now offers two explicit decisions:

1. **Submit numbers for review:** an active authorized operator stages a Pending manual card through the real ingestion service. No draw_result, winning-number, wallet, ledger or payout writes occur at this step. Re-submitting can replace a Pending manual card; ingestion retains correction history.
2. **Independent confirm and publish:** a different active authorized operator re-enters both independently announced numbers and supplies 5–500 character review notes. Claims are not prefilled. A hidden fingerprint is captured when the review form mounts. Under the same draw lock the workflow compares it with the current Pending card, then delegates to the existing atomic confirmation/publication pipeline.

The new AdminDrawResultWorkflow adapter owns the operator-facing boundary. It reads identity from authentication, reloads the persisted user and checks active panel access plus the existing `manage draws` permission at execution. Form fields cannot choose an actor, source, checker or authority stamp. Visibility is only a pre-filter; execution rechecks authorization, draw state and current card. The domain still enforces distinct attributable maker/checker, matching claims, fingerprint integrity, provenance, lifecycle and publication atomicity.

The fingerprint binds the canonical pending payload. This is not a general optimistic-version protocol for every metadata/history field, a distributed authorization-revocation guarantee, or new server-SQL concurrency certification. Stale changed-number reviews are specifically tested. No new official-source verification is inferred from an operator's notes or a successful publication.

This deliberately limited panel handles only its own `admin-panel` two-number manual cards. CLI/import/provider cards are not silently overwritten or confirmed through a form that cannot show all their source-specific prize fields. They require their dedicated review workflow. Full imported-prize review UI remains a separate integration task.

Manual publication retains `source-known` provenance, not `source-verified` or `OFFICIAL_SOURCE_VERIFIED`. The UI explicitly states this distinction. The settlement action remains a simulation; its label/text now say no payouts, instead of implying that a simulation button executes the canonical financial/bank workflow.

## Closed: canonical bottom-two display

The result screen previously read `result.bottom_two`, although the canonical writer stores this independent number in `draw_results.metadata` under `lottery.results.bottom_two_metadata_key`. The read-only entry now reads that actual storage location. No new database column, model fillable property or mass-assignment exception is introduced.

Tests exercise table intake followed by page confirmation and the reverse direction. They require first prize `003456` to preserve leading zeros and independently announced bottom two `45`, rather than deriving `56`. A direct Infolist component-state assertion verifies the display reads `45` from canonical metadata. The publication tests also require the checking operator attribution and no financial/ledger writes.

## Closed: subsequent GLO console fixture error

The next whole-suite diagnostic stopped at GloConsoleCommandsTest attempting to mass-assign domain-owned `result_published_at` while pretending a shared published fixture was ingestible. That guarded production field remains guarded.

The positive import test now replaces its unused synthetic setup row with a fresh Drawing fixture in the explicitly disposable test database. It keeps the fixture provider's exact draw number rather than relaxing provider draw matching. This is test-data preparation, not a production transition or permission to revert/delete published customer draws. Import must leave the card Pending, attributed to the actual maker, and create no draw_result, winning-number, financial-transaction or ledger rows. Production model, importer, provenance and publication guards are unchanged.

## Validation

| Check | Result |
|---|---|
| Filament resource + new workflow refusal tests | 31 tests / 240 assertions, pass |
| Those tests plus GLO console commands in one serial process | 46 tests / 277 assertions, pass |
| GLO console commands alone | 15 tests / 37 assertions, pass |
| Existing automation + production fixture guard + grade snapshots | 45 tests / 204 assertions, pass |
| Five financial/readiness suites plus Payment and Finance | 209 tests / 991 assertions, pass |
| Betting atomicity + SQLite retry + risk limits | 63 tests / 300 assertions, pass |
| Wallet conversion feature/unit | 71 tests / 259 assertions, pass |
| PDO driver-options tests after environment repair | 5 tests / 20 assertions, pass |
| Source scanner regression guards | 11 tests, pass |
| Offline server runner guards | 8 tests, pass; no server contacted |
| PHP syntax and Pint | Six delivered PHP files, pass |
| Financial source preflight | Pass; static only, not runtime certification |
| Repository collection | 2,295 cases, not proof they executed |
| Final serial repository diagnostic | 1,072 executed / 115,205 assertions / one failure; later cases not executed |

The thirteen new workflow tests cover actor/source spoofing, unauthenticated intake, roleless checking, role revocation, suspension, self-confirmation, mismatched claims, changed-card stale review, missing notes, invalid fingerprints, missing pending card, dedicated-source isolation and changed lifecycle. They include explicit no-partial-publication/no-financial-write assertions rather than accepting a swallowed notification as success.

Suites overlap: do not sum them as unique coverage. Actual server-SQL evidence from Part 9 remains historical MariaDB evidence for its four races; it was not rerun or extended into a Part 10 UI/backend topology certification.

Environment recovery restored the verified Part 9 overlay, PHP extensions, locked Composer dependencies and frontend assets. An intermediate diagnostic was contaminated by inadvertently running another schema/seeder-using test process on the same SQLite file. Its foreign-key/unique errors and automation failure are preserved and explicitly excluded from application-regression conclusions. All final scoped matrices were rerun serially, and the final repository diagnostic ran alone. No assertions, lifecycle guards or model fillable protections were relaxed to conceal that interference.

Full outputs and historical failures are in `evidence/runtime-part-10.txt`; static findings are separately in `evidence/financial_source_preflight-part-10.json`.

## Immediate remaining work — File109 onward

The final uncontaminated serial diagnostic stops at
`Tests\Feature\Glo\GloResultImportTest::test_import_stages_pending_ingestion_and_writes_no_draw_result`,
line 259: payload_summary.second_prize_count is **5**, while the test expects **1**.

An isolated run of that whole class confirms two failures (22 tests / 112 assertions):
1. The same second-prize cardinality mismatch.
2. `test_import_into_a_published_draw_is_refused_by_the_lifecycle_guard`, line 334: the actual lifecycle failure text says the published row cannot be ingested, but the test expects the substring `refused`.

Next investigate fixture/provider canonicalization, the validated second/third-prize list contract, summary reporting and the structured lifecycle error contract. Do not simply change expected cardinality or relax prize validation without proving what the imported fixture actually contains and what the canonical writer requires. Do not permit importing into a published draw to satisfy a wording assertion. These observed failures do not prove the later unexecuted cases pass.

Do not declare the remaining collected cases green from a stop-first diagnostic or from broad suite names. Preserve the failures, diagnose fixtures and production behavior independently, and use the real authorized domain workflow rather than restoring bypasses. Rerun serially against separate named disposable databases; never share a writable SQLite test file between independent test processes.

## Still not production-certified

- Intended production MySQL 8/PostgreSQL version, isolation, pooling, worker counts, replica/failover and deployment topology. Existing MariaDB sandbox races do not certify those.
- Real durable queues, transactional outbox/recovery, crash boundaries, abandoned serial settlement runs, expected-cursor replay and proof-required finalization.
- Full browser/session/CSRF and operator/member acceptance: imported-source review UI, bank evidence upload, wallet quote/expiry/replay/history, denials and recovery. Livewire server tests and a frontend build are not a real browser test.
- Authentic official draw-source verification. A manual card and two internal operators cannot create official authenticity. Provider/fixture result fields must not be elevated by labels or configuration.
- Real merchant/bank execution, provider/currency eligibility, signed callbacks, reconciliation, refunds/reversals, credentials and explicit operational authorization. No live payout or bank transfer was activated in this part; Approved/Paid database labels do not prove bank delivery.
- Licensing, age/KYC/AML, jurisdictions, privacy/retention and responsible-operation controls reviewed by qualified people before real funds.
- Least-privilege production/staging configuration, migration planning, secret management, monitoring, backups, reproducible builds and separate test accounts/databases. Database names alone do not prove physical isolation.

Legacy member-to-member transfer stays fail-closed; own-wallet Cash↔Win is distinct. The alternate GLO direct wallet writer stays unconditionally disabled with `glo_l6_direct_wallet_settlement_disabled`. Canonical financial permissions, quarantine and proof requirements remain intact.

The ZIP excludes .env, credentials, uploads, test databases, disposable server data, vendor/node_modules and generated frontend assets. Install the locked dependencies and build assets on an isolated environment after overlaying the full source on the pinned original repository. Do not treat this archive as deployment approval.
