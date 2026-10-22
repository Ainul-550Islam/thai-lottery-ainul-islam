# Part 13 — honest source inventory and static boundary contracts

Date: 2026-10-14. Base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File121–File133, complete without elision. Cumulative archive: 130 unique delivered paths. All previously delivered 117 paths remain byte-identical. Next label: File134. This is an overlay on the pinned original repository, not a complete repository, deployment or production certification.

## Resolved: incorrect dashboard importer assertion

The old Pages150To250 test required `GloResultImportService` to appear in `LottoFinExecutiveDashboardController`, even though its result/import projections intentionally do not import or publish results. An unused import or classname comment would have greened the substring without establishing a valid write boundary; neither was added.

Source inspection identified the actual boundaries:

- The dashboard has guarded, authorized KYC review and reconciliation delegates. It is **not globally read-only**. Its result/import projections stay separate from write/import authority, and unsupported mutations return explicit NOT_CONFIGURED/409.
- `GloImportResult` is the dedicated CLI intake, with actor resolution and draw-permission checks before import attribution. It stages Pending records rather than publishing.
- `DrawResultController` delegates the controlled API pipeline to ingestion, confirmation and publication services. Domain maker/checker, lifecycle and provenance authority remain intact.

The static test now asserts these real structural relationships, rejects a dashboard publication dependency, and requires dedicated CLI/API delegation. This is still lexical/source evidence, not runtime authorization or financial certification. No application controller, role, request, financial service, provenance rule or live write path was modified in this part.

## Resolved: missing audit/report files without fabricated verification

The root `audit.md` and three report files were absent. Git history shows `audit.md` was deleted by `6603da4`; its predecessor contains many page-number placeholders and obsolete `NOT VERIFIED — RUNTIME UNAVAILABLE` statuses. That historical file was inspected but not copied as current evidence.

`scripts/release_source_inventory.py` reconstructs deterministic, truthful inventory artifacts:

- Explicit allow-listed source paths, existence, byte counts and SHA-256 hashes.
- Requested slots 77–350: **not a claim of implemented/deployed pages**.
- Slots 77–250: no authoritative page-to-route mapping established here, so NOT_CONFIGURED.
- Slots 251–350: only titles are extracted as AST literals from the old generator recipe, marked DECLARED_RECIPE_ONLY. The generator is never imported/executed, and its old route/runtime/financial claims are not inherited.
- `runtime_verified`, `production_verified`, `live_payment_verified`, `official_source_verified`, page-mapping and per-slot execution flags remain false.
- Reports describe current source/evidence limitations, not successful Cargo tests, live finance, official authenticity or deployment approval.

The tool does not bootstrap Laravel, read .env/credentials, access a database/network or run subprocesses. Without --write it performs no document writes. --write produces only five fixed outputs. --check is read-only and fails for stale/missing documents or source inventory gaps. Escaping/symlink paths are refused. No arbitrary output path is accepted.

```bash
python3 scripts/release_source_inventory.py
python3 scripts/release_source_inventory.py --write
python3 scripts/release_source_inventory.py --check
python3 -m unittest discover -s scripts -p 'test_release_source_inventory.py'
```

Generated artifacts: `audit.md`, `RUNTIME-VERIFICATION-REPORT.md`, `FINANCIAL-INTEGRITY-REPORT.md`, `RUST-RUNTIME-REPORT.md`, and `evidence/release-source-inventory-part-13.json`. The Rust report explicitly states Cargo was not executed by this passive inventory; the crate is under `security/weekly-result-integrity`, not assumed to be a root workspace.

Nine offline regression tests cover no recipe execution, incomplete/duplicate recipe refusal, no executable AST values, deterministic hashes/false flags, changed/missing sources, no .env or subprocess use, traversal/symlink denial and allow-listed output/report scope.

These artifacts close an evidence-file absence, **not** page implementation, provider configuration, operational inventories or runtime acceptance. The audit retains explicit missing mappings instead of inventing page titles or source-to-route links.

## Resolved: static tests unnecessarily booted/seeding Laravel

All three page-static suites now extend plain PHPUnit TestCase and resolve source paths from their test directory. They can execute without Laravel bootstrap, database/schema/seeder, credentials or runtime configuration. The inventory tests verify source hashes and false attestation flags, rather than requiring outdated claims that PHP/runtime is unavailable.

The Pages77To100 raw route assertion also contradicted a documented collision fix: Filament owns `/admin/users`; the legacy dashboard list is `/admin/legacy/users`. The test now requires the actual legacy declaration and Filament ListUsers registration, and prohibits a duplicate raw `/users` registration. No route or application permission was changed to satisfy this static assertion.

The three source-contract suites pass standalone with 15 tests / 991 assertions. Source-only checks remain separate from real HTTP/browser/runtime acceptance.

## Validation

| Scoped check | Recorded result |
|---|---|
| Initial two-class static baseline | 8 tests / 95 assertions / two missing-file errors and one boundary failure |
| Three repaired static suites, no Laravel configuration/bootstrap | 15 tests / 991 assertions, pass |
| Inventory offline guards | 9 tests, pass |
| Inventory --check | Current hashes/documents match; all allow-listed sources present |
| Existing source-preflight guards | 11 tests, pass |
| Offline server-runner guards | 8 tests, pass; no SQL server contacted |
| PHP syntax/Pint | Three delivered PHP test files, pass |
| Python compile checks | Both delivered Python files, pass |
| Additional operator/write matrix | 111 tests / 683 assertions, pass |
| Console regression | 45 tests / 204 assertions, pass |
| Broad financial/readiness/Payment/Finance | 209 tests / 991 assertions, pass |
| Betting/Risk/SQLite retry | 63 tests / 300 assertions, pass |
| Wallet conversion | 71 tests / 259 assertions, pass |
| Static financial preflight | Pass; runtime_verified=false |
| Repository collection | 2,316 cases, not proof of execution |
| Final serial repository diagnostic | 1,668 executed / 119,357 assertions / one failure; later cases not executed |

Database-writing PHPUnit runs are serial, with no separate schema/seeder/truncation process sharing the test SQLite file. Pure static and offline checks do not access that file. Scoped matrices overlap: do not sum their counts as unique coverage. Full outputs and intermediate static failures are preserved in `evidence/runtime-part-13.txt`.

## Actual next remaining work — File134 onward

The next observed failure is
`Tests\Feature\Player\PlayerExperienceComprehensiveTest::test_15_web_draws_and_detail_views_render_properly`, line 425.
The detail response is HTTP 200 but does not contain the expected heading `Draw #` plus the draw number.

An isolated run of that whole unchanged class records 41 tests / 164 assertions / two failures: the same draw-detail heading mismatch and `test_26_player_can_fetch_wallet_balance_and_available_amount`. Complete output is preserved in the runtime evidence.

Next compare actual player detail view/route and wallet API presentation with the canonical draw/wallet contracts. Determine whether views, serialization or stale assertions need repair. Do not copy a heading just to satisfy a string match without checking the correct record/context; do not weaken wallet money precision, ownership or ledger authority to match an old payload expectation. Rerun the class and repository serially after proof-based fixes.

Do not infer later unexecuted cases passed. Diagnose stale assertions and real production defects separately. Do not add dummy imports, fake audit reports, government/source verification stamps, role bypasses, disabled foreign keys, weakened protected model fields or alternate direct wallet writes to green a test.

## Production gates remain uncertified

- Authentic official draw source and full announcement/inventory schema; internal confirmation and synthetic/fake HTTP responses are not `OFFICIAL_SOURCE_VERIFIED`.
- Complete source-specific imported-prize review UI, real member/operator browser sessions/CSRF, denials, quote expiry/replay/history, bank evidence and recovery.
- Intended MySQL8/PostgreSQL version/topology, isolation, pooling, workers, replicas/failover, contention, status revocation/deletion and deployment recovery. Historical Part9 MariaDB races are scoped evidence only.
- Durable queues, transactional outbox/recovery, crash boundaries, abandoned persisted settlement runs, serial cursor ownership, expected-cursor replay and proof-required finalization.
- Authorized real merchant/bank execution, provider/currency eligibility, signed callbacks, reconciliation, refunds/reversals, credentials and operating controls. Approved/Paid labels alone do not prove bank delivery. No live payout was activated.
- Qualified licensing, jurisdiction, age/KYC/AML, privacy/retention and responsible-operation review before real funds.
- Staging isolation, least privilege, secret management, monitoring, backups, migration planning, reproducible builds and actual Cargo crate/binary verification where applicable.

Legacy member transfer stays fail-closed; own-wallet Cash↔Win is distinct. Alternate GLO direct wallet settlement remains disabled with `glo_l6_direct_wallet_settlement_disabled`. Canonical proof/quarantine and monetary permissions are unchanged.

The ZIP overlays 130 delivered paths on the pinned original repository and excludes .env, credentials, uploads, test databases, disposable server data, vendor/node_modules and generated frontend assets. Every File121–File133 body is complete in `Full-Code-Continuation-Files-121-133.md`; CRC, unique paths, byte equality and SHA-256 are checked. This is not deployment approval.
