# Part 11 — GLO import-contract repair and remaining acceptance gates

Date: 2026-10-14. Base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

Delivery: File109–File114, complete without elision. Cumulative archive: 111 unique delivered paths. All 105 previously delivered paths remain byte-identical. This is a source overlay on the pinned original repository, not a full repository, standalone deployment or production certification. Next label: File115.

## Resolved: the two observed GloResultImportTest failures

The verified synthetic `resources/glo/fixtures/lottery_result.json` contains five second-prize values and ten third-prize values, both in its top-level body and in the explicitly matched `GLO-2026-09-16` draw entry:

- Second: `112233`, `445566`, `778899`, `001122`, `334455`.
- Third: `600001` through `600010`, in that order.
- First: `042042`; independently announced bottom two: `58`.

Fixture SHA-256: `159af378484a4aea5d0978214204d3c6d16e2a35f8a29e82c764ff4518838241`.

The fixture itself is unchanged, and is now delivered in full so its actual shape can be inspected. These are synthetic test vectors, not an authentic official announcement or government affiliation evidence.

The old import test expected a second-prize count of one, contradicting this matched fixture. It now requires the exact second/third lists and counts in the provider outcome and persisted Pending payload, including order and the leading-zero value `001122`. It verifies the complete canonical payload fingerprint, the provider fingerprint in the source lane, maker attribution, and zero result/winning-number/financial/ledger publication at intake.

The published-draw refusal test already received the correct refused status and no ingestion; its failed assertion required the literal word `refused` in human exception prose. It now checks the actual published lifecycle state and refusal clause, unchanged draw state, absent Pending card and zero publication/financial rows. An independent direct ingestion attempt must throw typed `RESULT_CONFIRMATION_FORBIDDEN`, with the correct draw id and `result_published` context. The domain exception text, model guards, importer and publication authority are unchanged.

Unmocked network access is prohibited in this test class with `Http::preventStrayRequests()`. Official-provider HTTP tests still use explicit fakes: they do not authenticate a live source.

## Added: canonical prize-list boundary regressions

`GloPrizeListIngestionContractTest` executes the real ingestion service under guarded disposable-database truncation:

- Maximum supported lists of five second and ten third prizes preserve exact strings, order, leading zeros and fingerprint.
- Twelve invalid replacements cover over-limit lists, associative/sparse arrays, integers, short/long digit strings, nonnumeric values, nested values, null and scalar lists.
- Every invalid replacement must return typed `RESULT_MALFORMED` with the correct field and leave the entire previous Pending metadata/history unchanged. No result, winning-number, financial-transaction or ledger row may leak.
- A third-prize correction changes the canonical fingerprint and retains the superseded previous payload/history.
- A separate test accurately records that the **current** intake contract permits short or empty lists up to the maxima. That is not proof of a complete official announcement and does not grant official-source verification.

No production PHP file, schema, monetary operation, role permission, provenance registry or prize validator was weakened or changed in this part. The unchanged validator requires list-shaped arrays, at most five/ten items and exactly six-digit strings. Raw-provider normalization/filtering is not exhaustively certified by these direct-ingestion tests. Full official inventory completeness, applicability of tiers and authentic-source certification remain separate acceptance gates; this part does not silently tighten or loosen that policy.

## Validation

| Scoped check | Recorded result |
|---|---|
| Original import-test baseline | 22 tests / 112 assertions / two failures, preserved |
| Repaired import tests | 22 tests / 145 assertions, pass |
| Import + new list-contract regressions | 37 tests / 254 assertions, pass |
| Final broader GLO/operator regression | 83 tests / 531 assertions, pass |
| Console automation/guard/grade regression | 45 tests / 204 assertions, pass |
| Five financial/readiness suites + Payment/Finance | 209 tests / 991 assertions, pass |
| Betting/Risk + SQLite retry | 63 tests / 300 assertions, pass |
| Wallet conversion feature/unit | 71 tests / 259 assertions, pass |
| PHP lint / Pint | Both delivered PHP files, pass |
| Static source-preflight regression guards | 11 tests, pass |
| Offline server-runner guards | 8 tests, pass; no SQL server contacted |
| Financial source preflight | Pass; static only, runtime_verified=false |
| Repository test collection | 2,310 cases; collection is not execution |
| Final serial repository stop-first diagnostic | 1,176 executed / 115,651 assertions / one error; later cases not executed |

All database-writing PHPUnit commands are executed serially; a background repository diagnostic is not overlapped with another schema/seeder/truncation command on the same SQLite file. Scoped suites overlap and cannot be summed as unique coverage. Locked dependencies and generated frontend assets were restored for this sandbox. Runtime outputs and failed baselines are preserved in `evidence/runtime-part-11.txt`.

Part 9 MariaDB race evidence remains historical scoped server-SQL evidence. It was not rerun or expanded into Part 11 production topology, official provider or payout execution certification. The new tests validate rollback of canonical intake metadata, not every financial writer or queue failure mode.

## Immediate remaining work — File115 onward

The next observed blocker is
`Tests\Feature\Glo\GloWriteBoundaryTest::test_zero_or_malformed_maker_identity_cannot_be_confirmed`.
It attempts intake with maker id zero, and hits an audit_logs.user_id foreign-key error before the intended confirmation refusal.

An isolated run of that entire unchanged class records 22 tests / 57 assertions, four errors and two failures. The full-suite first error is therefore not the only failure in that class. The complete isolated output is preserved in the runtime evidence.

Next inspect invalid/unattributable maker intake, audit attribution and rollback contracts separately from the legacy-record confirmation tests. Determine whether invalid intake should be explicitly refused or whether a test should create a valid unattributed legacy fixture before checking confirmation denial. Do not disable foreign keys, create a fake user with id zero, falsify maker/checker attribution or convert an invalid identity into an authorized operator. Also investigate the other isolated failures against the actual matched synthetic fixture and structured domain contracts before changing expectations.

Do not infer that later unexecuted cases are green. Distinguish outdated fixtures/assertions from actual production defects. Keep real lifecycle, maker/checker, canonical prize, source provenance and no-money-at-intake guards. Do not fake official stamps, re-enable alternate direct wallet settlement or loosen guarded model fields to satisfy old assertions.

## Still incomplete or uncertified for production

1. Authentic official-source integration and complete announcement/inventory schema. Five/ten synthetic values and successful fake HTTP tests do not prove the authority, completeness or correctness of live GLO data. Internal maker/checker confirmation remains distinct from `OFFICIAL_SOURCE_VERIFIED`.
2. Source-specific imported-prize review UI, full member/operator browser acceptance, session/CSRF, quote/expiry/replay/history, bank-evidence review and recovery. Livewire tests and a frontend build are not full browser certification.
3. Intended production database version/topology, real contention/isolation, connection pools, replicas/failover and worker counts. SQLite rollback tests and historical MariaDB socket races do not certify MySQL 8 or PostgreSQL deployment behavior.
4. Durable real queue workers and transactional outbox/recovery: crash boundaries, abandoned persisted runs, serial keyset cursor ownership, expected-cursor retry and proof-required finalization.
5. Live merchant/bank execution, credentials, provider/currency eligibility, signed callbacks, reconciliation, refunds/reversals and explicit operational approval. No live payment, bank transfer or payout was activated. Approved/Paid labels alone do not prove bank execution.
6. Licensing, permitted jurisdictions, age/KYC/AML, privacy/retention and responsible-operation controls reviewed by qualified people before handling real funds.
7. Separate staging/test accounts and databases, least privilege, secrets, monitoring, backups, migration/recovery planning and reproducible deployment. A test database name alone does not prove physical isolation.

Legacy member-to-member transfer stays fail-closed; own-wallet Cash↔Win conversion is distinct. The alternate GLO direct wallet writer stays unconditionally disabled with `glo_l6_direct_wallet_settlement_disabled`. Canonical financial authority, quarantine and proof requirements remain unchanged.

## Package boundary

The archive overlays 111 delivered paths on the original repository. It excludes .env, credentials, uploads, test databases, disposable server data, vendor/node_modules and generated frontend assets. All File109–File114 bodies appear in full in `Full-Code-Continuation-Files-109-114.md`. ZIP CRC, unique paths, body byte equality and SHA-256 are verified before delivery. This is not deployment approval.
