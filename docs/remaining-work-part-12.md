# Part 12 — maker identity intake guard and GLO write-boundary repair

Date: 2026-10-14. Base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File115–File120, complete without elision. Cumulative overlay: 117 unique delivered paths. All 111 previous delivered paths remain byte-identical. Next label: File121. This is not a complete repository, standalone deployment or production certification.

## Fixed: invalid supplied maker failed at audit foreign key

Previously `DrawResultIngestionService::ingest(..., actorUserId: 0)` staged metadata then attempted audit attribution to a nonexistent user zero. The transaction rolled back, but the caller received a database constraint exception rather than an intentional domain refusal. Foreign keys were doing their job and remain enabled.

The service now validates any **non-null supplied identity** after the locked draw/lifecycle check, before canonicalization, replay or metadata/audit writes. The id must be positive and resolve to a persisted active user. Invalid, unknown, deleted or suspended makers receive typed `RESULT_CONFIRMATION_FORBIDDEN` with draw/operator context. A suspended maker cannot exploit the exact-replay no-op branch to avoid the identity check.

This guard checks persisted identity/status; it does not grant a role or replace operator-facing permission checks. Panel/CLI/API authorization remains separate. It does not certify concurrent user deletion/revocation races or production database lock topology.

Null is intentionally distinct from a supplied invalid id: the existing low-level legacy/feed contract may stage an explicitly **unattributed Pending** record and a nullable audit actor. Such a record cannot be confirmed or published by any checker. The authenticated importer and operator boundaries still require attributable authorized makers. Null does not authorize an unattended live feed or official publication.

The new six-case identity suite verifies zero/negative/unknown ids, suspended replay and replacement, invalid replacement retention, deleted identity, null/unconfirmable feed, and valid replay. Assertions require unchanged Pending metadata/history and audit counts on refusals, and no result, winning-number, financial-transaction or ledger writes. No fake user zero is created and no foreign key or actor check is relaxed.

## Fixed: stale write-boundary fixtures/read shape

Four other boundary errors/failures used numbers from the top-level/other fixture draw (`042042` / `58`) while explicitly fetching `GLO-WRITE-BOUNDARY`. The unchanged matched synthetic entry actually announces `123456` / independent bottom two `77`. The relevant fixture-based confirmation tests now use that exact named entry. Official-provider HTTP fake tests retain their separate `042042` / `58` contract.

This matters especially for the production-fixture refusal test: matching claims now reach the intended provenance refusal, rather than failing earlier at claim mismatch. Production fixture provenance remains forbidden, and a successful internal manual confirmation is not official-source verification.

`currentIngestion()` returns the persisted metadata record with canonical numbers under `payload`; it is not the flat result row returned by `ingest()`. The independent-bottom-two test now asserts the actual nested payload and still requires announced `48`, never derived `84`.

The legacy zero/malformed-maker confirmation test is now prepared correctly: first create a valid attributed Pending intake, then deliberately corrupt only its disposable test metadata to simulate a legacy record. The confirmation guard must reject both zero and malformed attribution. This is separate from the new-intake tests, which require invalid supplied identity to be refused before audit writes.

Unmocked HTTP requests are prohibited in the boundary test class. Import, confirmation, publication, provenance registry, monetary authority and schema protections are otherwise unchanged.

## Validation

| Check | Recorded result |
|---|---|
| Original boundary baseline | 22 tests / 57 assertions / four errors and two failures, preserved |
| Repaired boundary suite | 22 tests / 78 assertions, pass |
| Boundary + new identity regressions | 28 tests / 152 assertions, pass |
| Boundary/identity/import/list-contract/GLO console/Filament matrix | 111 tests / 683 assertions, pass |
| Additional console regression | 45 tests / 204 assertions, pass |
| Broad financial/readiness/Payment/Finance regression | 209 tests / 991 assertions, pass |
| Betting/Risk/SQLite retry regression | 63 tests / 300 assertions, pass |
| Wallet conversion regression | 71 tests / 259 assertions, pass |
| Source scanner regression guards | 11 tests, pass |
| Offline server-runner guards | 8 tests, pass; no SQL server contacted |
| PHP lint / Pint | Three delivered PHP files, pass |
| Static source preflight | Pass, runtime_verified=false; not runtime approval |
| Repository collection | 2,316 cases, not proof of execution |
| Final serial repository diagnostic | 1,507 executed / 117,712 assertions / one failure; later cases not executed |

All database-writing tests run serially against guarded disposable sandbox data, without another PHPUnit seeder/truncation process sharing the SQLite file. Scoped matrices overlap and cannot be summed as unique coverage. Dependencies and generated frontend assets were restored before execution. Full logs and baseline failures are retained in `evidence/runtime-part-12.txt`.

Historical MariaDB race evidence from Part 9 remains scoped evidence for that release. It was not rerun or extended to certify this new maker check or production deployment behavior.

## Immediate next work — File121 onward

The first current failure is
`Tests\Feature\Pages150To250StaticContractTest::test_canonical_finance_lottery_and_rust_boundaries_are_present`, line 119.
The static test requires the read-only `LottoFinExecutiveDashboardController` source to contain `GloResultImportService`, but it does not.

The isolated class has five tests / 72 assertions, one failure and one error. The additional error is missing repository-root `audit.md`, required by the release-audit test. That path is absent and is not tracked at the pinned base HEAD; it is not restored by the cumulative overlay. Do not fabricate a release audit, copy unsupported certification statements or add an unused service import/comment just to satisfy a substring assertion.

Next investigate the actual read-only dashboard and dedicated write/import boundaries, correct the static contract to reflect proven architecture if appropriate, and reconstruct a truthful audit artifact only from verified repository/page evidence. Then rerun the class and the complete repository serially. These static-contract failures are distinct from the GLO runtime maker/audit issues resolved in this part.

Do not infer that later unexecuted cases passed. Diagnose production behavior and outdated test expectations separately. Never turn off foreign keys, falsify maker/checker identity, forge official provenance, bypass lifecycle/proof checks, re-enable the alternate direct wallet writer or weaken protected model fields just to green a stale assertion.

## Remaining production acceptance gates

- Verify authentic official-source integration and complete prize/inventory applicability; synthetic vectors and fake HTTP responses do not prove official authenticity. Internal confirmation is not `OFFICIAL_SOURCE_VERIFIED`.
- Finish source-specific imported-prize review UI and real operator/member browser acceptance, including session/CSRF, denials, bank-evidence review, quote expiry/replay/history and recovery.
- Certify intended production database version/topology, isolation, pooling, workers, replicas/failover, contention and maker-status/deletion concurrency. SQLite cases and historical MariaDB races do not certify MySQL 8/PostgreSQL production.
- Exercise durable queues and transactional outbox/recovery: crash boundaries, abandoned persisted runs, serial keyset cursor ownership, expected-cursor retry and proof-required finalization.
- Authorize and certify real merchant/bank execution, provider/currency eligibility, signed callbacks, reconciliation, refunds/reversals, credentials and operational controls. No live payout was activated. Approved/Paid database labels alone do not prove bank delivery.
- Obtain qualified licensing, jurisdiction, age/KYC/AML, privacy/retention and responsible-operation review before real customer funds.
- Validate separate least-privilege test/staging accounts/databases, secrets, monitoring, backups, migration/recovery planning and reproducible deployment. A test database name alone does not prove physical isolation.

Legacy member-to-member transfer stays fail-closed; own-wallet Cash↔Win conversion is distinct. The alternate GLO direct wallet writer stays disabled with `glo_l6_direct_wallet_settlement_disabled`. Canonical monetary permissions, quarantine and proof requirements remain unchanged.

The archive overlays 117 delivered paths on the pinned original repository. It excludes .env, credentials, uploads, test databases, disposable server data, vendor/node_modules and generated frontend assets. Every File115–File120 body is provided in `Full-Code-Continuation-Files-115-120.md`; archive CRC, unique paths, byte equality and SHA-256 are checked before delivery. This is not deployment approval.
