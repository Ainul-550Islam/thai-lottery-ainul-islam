# Competitor parity — Part 3 and safe remaining-work follow-ups

Verified 2026-10-15 on PHP 8.4.26 with intl, GD/JPEG, PDO MySQL and PDO SQLite.
F36 fixed the SQLite purchase race and stale readiness contracts. F37 implements only the safe,
independent DRY_RUN cursor-preview portion. F38 repairs the legacy command's read-only status and
refuses its invalid execution/queue/resume path. F39 fixes an existing job-composition fatal and
permanently refuses its unsupported monetary handler. F40 supplies the actual native verifier runtime
and fixes incomplete-signature forwarding. F41 closes the final supported non-monetary runtime skip
with real separate processes. No production service or public parity source is changed by f41.

## Current measured results

| Check | Result | Evidence |
|---|---|---|
| Expanded changed-area regression including all new preview tests | **1,441 tests, 107,236 assertions — all pass, no skips** | `/home/user/part3-focused-f42.log` |
| Manual checkpoint/inventory scope | **51 tests, 315 assertions — all pass, no skips** | `/home/user/manual-focused-f42.log` |
| Original cursor class, source/assertions unchanged | **16 tests, 76 assertions — all pass** | `/home/user/full-f42.junit.xml` |
| Actual former skipped simulation witness | **1 test, 48 assertions — pass** | `/home/user/simulation-race-green-f41.log` |
| Rust local release tests | **29 pass** | `/home/user/native-build-gate-f42.log` |
| Retained queue-job refusal | **26 tests, 121 assertions — all pass, no skips** | `/home/user/full-f42.junit.xml` |
| Retained legacy command | **69 tests, 334 assertions — all pass, no skips** | `/home/user/full-f42.junit.xml` |
| Retained DRY_RUN preview | **53 tests, 517 assertions — all pass, no skips** | `/home/user/full-f42.junit.xml` |
| Historical f37 extra two-process repetitions | **3 separate runs pass; 2 tests / 51 assertions per run** | `/home/user/preview-concurrency-repeated-f37.log` |
| Five migrated readiness suites, still passing in the current full run | 58 tests, 518 assertions — all pass | `/home/user/full-f42.junit.xml` |
| Complete Glo group | 262 tests, 1,146 assertions — all pass | `/home/user/full-f42.junit.xml` |
| Complete Payment group | 86 tests, 386 assertions — all pass | `/home/user/full-f42.junit.xml` |
| Complete Production group | 43 tests, 135 assertions — all pass | `/home/user/full-f42.junit.xml` |
| Complete Verification group | 32 tests, 157 assertions — all pass | `/home/user/full-f42.junit.xml` |
| Complete Filament group | 161 tests, 969 assertions — all pass | `/home/user/full-f42.junit.xml` |
| Unfiltered full suite | **2,538 tests, 113,966 assertions — 0 errors, 0 failures, 0 skips** | `/home/user/full-f42.log` |
| Locked frontend install/build | Exit 0 | `/home/user/npm-ci-f42.log`, `/home/user/npm-build-f42.log` |
| Standalone bundle-restorer tests | 10 tests — all pass | `/home/user/bundle-tests-f42.log` |
| Complete-file PHP/Blade syntax | 125 files — all pass | `/home/user/php-lint-f42.log` |

The prepared local repository is now **fully passing**: all 51 originally failing f35 names,
including all fifteen cursor cases, pass without any skip/exclusion/original assertion weakening.
The final unfiltered command exits 0. This is **not production readiness or a LIVE deployment**;
actual credentials, external transfers, hosted CI, load/cross-host and operational review are not
certified. Complete measured history remains in `docs/full-suite-failure-classification.json`.

## Part 3 delivered and retained

- Optional IC No. and WeChat ID are encrypted. IC duplicate lookup uses a keyed hash, not plaintext.
- Online-member counts use authenticated recent presence and real database counts, not competitor
  constants or seeded fabricated activity.
- Unverified English/Thai home claims are removed. Banned-phrase, key-parity, Bangla translation and
  locale tests cover the delivered copy.
- Public labels/navigation/titles/registration do not imply official affiliation. Required source and
  non-affiliation disclaimers remain.
- “GLO-compatible” wording retains its non-affiliation notice and is not presented as government
  authorization or a verified live integration.
- Campaigns remain empty by default. No invented offer, payout, statistic or support contact is added.
- Durable webhook replay protection, named-throttle assertions and read-only intake/health remain covered.

## F36 reliability/readiness work retained

The SQLite writer-upgrade race is closed by immediate transactions and an actually applied busy
timeout. Exactly one purchase/replay, debit, journal pair and risk increment are asserted. Its entire
50-test class still passes; the eight separate f36 repetitions are historical evidence, not a new
cross-host certification.

The five readiness suites use canonical typed money, wallet, reservation, reconciliation, calculator,
webhook, claim, bulk and self-exclusion APIs. KYC/private review and manual approval remain protected;
withdrawal failure releases a hold without inventing a compensating credit. Signed bKash tests use
BDT/faked provider HTTP. The disabled bank-transfer rail stays fail-closed. Existing single-agent and
manual settlement fixtures do not enable deployed commission/payout automation.

See the explicitly historical `docs/sqlite-concurrency-readiness-status.md` for f36's nine complete
PHP files and 3,500 lines. Those production sources remain unchanged in f37.

## F37 safe partial closure delivered

Twelve new complete PHP files (2,220 lines) provide an isolated audit-only preview:

- only `settlement_previews` and `settlement_preview_bets` are written;
- the exact rows in 18 business tables stay unchanged, independently checked by an actual write trace;
- active-bet identities and result/rule/input fingerprints are captured in bounded keyset pages;
- stale expected-cursor replay writes nothing, including under two genuine PHP workers;
- mid-chunk failure rolls back every entry and resumes under the identical captured context;
- exact totals and whole-source/outcome integrity are verified before completing only the preview;
- draw, bet, wallet, payout, ledger, money-run ownership and scheduler behavior are not changed;
- an explicit unscheduled CLI supports bounded work, stored read-only status and guarded completion.

No LIVE method/flag, financial compensation path or legacy compatibility alias exists in that component.
See `docs/chunked-settlement-preview-status.md` for all files, commands, legacy expectation mappings,
additional broken legacy queue wiring, privacy limits and the absence of a load/throughput certification.

## F38 legacy command/caller closure delivered

Three complete PHP files (1,026 lines) replace the invalid legacy CLI caller and add 69 passing tests.
`--status` reads the stored record directly without missing orchestrator methods. Execution/queue/
resume/dry-run variants explicitly refuse before querying anything, even under LIVE automation flags;
no preview alias, new payout job or missing-backend compatibility implementation is introduced.

Snapshots of 23 tables, real read traces, poisoned monetary bindings, strict raw metadata/decimal
checks, redaction and explicit 200-row truncation cover the change. Stored LIVE/completed records
are never represented as verified wallet payment. Existing money services, orchestrator, job/model,
scheduler, fixture flags and original tests remain unchanged. See `docs/legacy-chunked-command-status.md`.

## F39 queued-job hardening delivered

Four complete PHP files (589 lines) repair the previously fatal Batchable/batchId composition and
keep immutable plan correlation separate from actual Laravel batch membership. The six producer
argument names remain compatible; payload serialization and a real database batch are tested.

The invalid per-bet/fingerprint callees are retired behind a dedicated permanent refusal. Newly
created payloads fail once even when a worker allows more attempts. Actual database queue/one-job
worker tests prove failed-job recording, no JobProcessed success, correct native-batch failure and
no success callback, with twenty business/preview tables unchanged. Framework queue bookkeeping is
not misrepresented as unchanged or financially successful. Older envelopes are not auto-migrated.

Core money services/orchestrator/model/scheduler/original tests remain unchanged. The unsupported
planner/finalizer/fingerprint and fifteen errors were still open at that checkpoint. F42 completes the
manual contract and retires the obsolete dispatcher; queue activation stays disabled. See the historical
job document and current manual status for the separate scopes.

## F40 native runtime closure delivered

The actual locked Rust host executable is built and exercised, with 29 passing release tests and
strict Clippy/format/build gates. The complete unchanged Weekly PHP class now runs 63 tests without
a skip; thirty new real-subprocess PHP cases and six build/CI contracts accompany the work. No fake
binary or disabled native guard is used.

PHP now forwards each supplied key/signature half, allowing Rust to reject incomplete material rather
than receive an unsigned HASH_ONLY request. Public test-vector signatures are not provider authority.
Native/production key/fallback settings and all money services remain unchanged. CI definitions build
native before full PHP tests; hosted execution is not claimed. Rebuild excluded target output after a
reset. See `docs/native-verifier-runtime-status.md` and `RUST-RUNTIME-REPORT.md` for full evidence/limits.

## F41 non-monetary concurrency runtime delivered

Five complete test/harness files (1,987 lines) admit only a verified shared disposable transaction
boundary. Memory/URI/external/deferred/no-wait configurations remain guarded. Two independently
booted PHP/PDO workers acknowledge readiness before a token barrier; distinct PIDs/shared storage,
one writer/one replay, an identical fingerprint, exact 9,900.00 simulated total, one audit and unchanged
complete financial rows are asserted. Replay issues no mutation SQL.

This tests the existing reporting-state simulation, not LIVE credit or f37's read-only preview.
Production sources remain unchanged. Five extra repeated races pass as stability checks, not new
coverage. SQLite is single-writer serialization, not server row-level/cross-host/load certification.
See `docs/simulation-concurrency-runtime-status.md` for guarded limitations and exact evidence.

## F42 manual cursor contract closure delivered

The missing manual API delegates to the existing range/finalizer rather than a new payer. Typed
internal callbacks make range changes and admitted checkpoint commit atomic. Frozen input/result/
rules/mode/size and output/journal witnesses reject stale/corrupt/changed state, approval promotion,
incomplete finalization and caller transactions. Real fixture-only PHP/PDO concurrency and rollback
faults accompany the unchanged original sixteen acceptance tests.

The thirteen changed/new PHP files total 3,706 full lines; the original 717-line core contract is also
included unchanged. Existing wallet/payout/approval helper semantics remain, legacy CLI/job/dispatcher
execution remains refused, and no route, scheduler, gateway or production flag is activated. The
production inventory truthfully defers the retired job with runtime evidence, not a fake producer.
See `docs/manual-cursor-settlement-status.md` for exact code scope and deployment/performance limits.

## CI/runtime and earlier regression closures retained

PHP workflows match Composer `^8.4`, run the extension/JPEG preflight, use `.env.ci`, and generate a
temporary key after Composer. Production templates keep credentials blank, fixture lanes off and
automation disabled. Production rejects normalized fixture-priority entries. The four explicit
labelled-fixture flags in `phpunit.xml` are unchanged.

Open-draw player fixtures, translated draw titles, public `reserved_balance`, independent maker/checker
bottom-two data and distinct Filament/legacy user routes remain covered. Hosted CI and live providers
were not run. `docs/ci-regression-status.md` contains the full expanded regression command and measured
history; its passing subset does not replace the still-nonzero unfiltered gate.

## Delivery integrity and remaining boundary

`part3-bundle.tar` contains complete files, not patches or shortened excerpts. Per-member hashes and
the standalone restorer verify all bytes and apply intentional deletions, including the retired
top-level breaker. Dependencies, builds, databases, live `.env` and credentials are excluded.
See `docs/part3-bundle-usage.md` and the canonical list.

The manual cursor/run/resume/finalize contract is locally implemented/tested. Automatic queue/CLI
invocation and live deployment remain deliberately disabled; operational review and real-world
certification are separate, not silently represented as completed by local green tests.

No deployed/unattended payout automation, multi-level referral/affiliate commission, fabricated campaign/counter,
government-affiliation claim or unverified marketing promise is introduced. Manual code and disposable tests do not authorize deployment, production flags or actual transfers.
