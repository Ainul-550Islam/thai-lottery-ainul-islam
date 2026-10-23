# ARENA AI — BACKEND NEXT ITERATION: ACTUAL CLOSURE & RELEASE EVIDENCE (ZERO-SKIP)

**Repository:** `https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git`  
**Primary specification:** the complete `backend-next.md` supplied by the user  
**Acceptance input:** the user's latest `BACKEND RELEASE GATE` report covering Sections A–I  
**Mission:** execute the remaining work in the real repository, verify every Backend-owned acceptance item against one identified source revision, fix proven in-scope defects, and rerun. This is an implementation-and-execution task—not a process-report, checklist-only, or intake-packet task.

## 0. Required outcome

Close the actual Backend-owned requirements in `backend-next.md` A–I with reproducible source-bound evidence. Do not stop after repeating the user's PASS table, writing a new report, inventorying commands, or creating another handoff request packet.

Keep these decisions separate:

1. **BACKEND IMPLEMENTATION:** `PASS` only when every applicable Backend-owned requirement is implemented and freshly verified; otherwise `FAIL` or `BLOCKED`.
2. **PLATFORM RELEASE ACCEPTANCE:** `READY FOR HUMAN RELEASE APPROVAL` only when every mandatory platform gate, including external DevOps/CI/container evidence, is accepted for the exact candidate revision. Otherwise `RELEASE BLOCKED`.

Never report 100% based on the supplied report alone. Treat every historical result below as a claim to reproduce, not as a fresh result.

## 1. Scope and ownership lock

Before editing, read `backend-next.md` completely and establish a path-level ownership map for every candidate change.

### Allowed Backend-owned work

- Laravel/PHP application boot, services, controllers, policies, middleware, validation, domain adapters, backend APIs, route registration, queue/job behavior, backend-owned runtime guards, and Backend-owned tests/fixtures.
- Minimal repairs to Backend-owned code and verification scripts directly required to test Backend behavior.
- Regression tests for each confirmed Backend defect.

### Cross-track test execution is allowed; cross-track implementation is not

You may execute Finance/Wallet, Frontend, Security/Rust, DevOps/CI, and Mobile/PWA tests where needed to assess Backend integration. **Do not edit files owned by those tracks.** Route a defect to the owning track with exact paths, test command, raw failure, source revision, and expected interface/contract.

In particular:
- Do not edit Dockerfiles, Compose, Nginx, GitHub workflows, shared dependency pins, Rust/native source, service-worker/browser-worker code, global theme/brand files, or Android source under this Backend task.
- Docker's missing executable in the previous report is a DevOps/environment blocker, not permission to rewrite DevOps files. Run a real Docker build if Docker is genuinely available; otherwise keep that gate `BLOCKED`.
- Do not invent financial policy, ticket/prize/tax rules, transfer fees, ledger account mappings, or owner approvals. Do not change finance-owned logic merely to satisfy a test.
- Never weaken, delete, skip, quarantine, re-label, or rewrite a meaningful assertion/golden to produce green tests.

## 2. Establish the actual candidate before testing

1. Inspect the actual project folder and the supplied `backend-next.md` and latest Backend release-gate report.
2. Record UTC time, repository root, `git remote -v` (redact credentials), branch, full `git rev-parse HEAD`, parent chain, `git status --short`, changed/untracked paths, source archive/base if used, and hashes of `composer.json`, `composer.lock`, `phpunit.xml`, `backend-next.md`, and relevant lock/workflow files.
3. If Git metadata is absent or commands fail, do not synthesize `.git`, branch, commit, base, clean-tree state, remote, or patch provenance. Mark provenance `BLOCKED` and continue only with safely inspectable local code.
4. Preserve all pre-existing modifications. No `git reset --hard`, `git clean`, forced checkout, history rewrite, or overwriting user work.
5. Confirm every proposed edit is Backend-owned before changing it. Record exact before/after hashes for modified and added files.

## 3. Revalidate the existing claims first — do not blindly trust them

The user report claims:

- PHP syntax: 3,140/3,140 passed.
- Composer validation, app preflight, 680 routes, 43 scheduled tasks and workflow/Docker contract tests passed.
- Ledger/money tests, webhook/deposit/refund, withdrawal/payout, transfer/conversion, ticket/GLO L6, result/settlement, database/Rust integration and security/compliance suites passed.
- Fresh MariaDB 11.8 migration: 163/163 migrations plus seeders; database suite 9/9.
- `runtime/settlement-worker` Cargo tests 6/6, weekly native verifier 37/37; live Docker build was blocked because Docker was not installed.

For every claim, find and bind its raw evidence to the exact current candidate commit and actual command. A prose table, test file existing, contract test passing, or historical log is not enough. Do not assume these totals are from one common revision. If only a partial set can be reproduced, list each exact command, exit code, and proof file separately.

## 4. Execute a source-bound Backend verification run

Create a new unique evidence directory tied to the actual tested source identity. Never overwrite prior reports. Store sanitized stdout/stderr, actual exit status, duration, tool versions, source identity, and raw machine-readable reports.

### Gate A — PHP/runtime/bootstrap/build contract

- Run a complete repository PHP syntax scan excluding dependency/generated directories only when that exclusion is explicit and justified. Count discovered files and parsed files from the command output.
- Run `composer validate --no-check-publish`, `composer dump-autoload --optimize`, and `composer check-platform-reqs` where dependencies/platform permit.
- Run the actual `scripts/financial_source_preflight.py` and inspect its source/coverage so its `PASS` reflects the real files and import edges rather than a misleading narrow inventory.
- Run `php artisan config:clear` only in a safe isolated testing environment, then `APP_ENV=testing php artisan route:list --except-vendor`, `APP_ENV=testing CACHE_STORE=array php artisan schedule:list`, and `php artisan migrate:status` using the isolated database configuration. If an operation is unsafe due to unverified environment/database identity, do not execute it; record the exact blocker.
- Run all Backend-owned Dockerfile/CI contract tests without modifying DevOps-owned files.
- Reconcile PHP version requirements in `composer.json`, lock metadata and relevant code; do not change version pins merely to appease an incompatible runner.

### Gate B — Ledger and money behavior

Execute the exact relevant suites named in Section B of `backend-next.md` and the user report, including LedgerPosting, Ledger, DoubleEntryLedger, settlement safety, order-placement idempotency, financial retry ownership, reversal integrity, lifecycle proof, business-critical invariant, money-write coverage and transaction-boundary suites.

Verify raw test output supports:
- Exact debit/credit balancing per currency and immutable journal history.
- No float/double on money/prize calculation paths.
- Same-key same-payload idempotent replay; same-key different-payload conflict.
- Atomic rollback of every write and no orphan receipt/half-posted purchase/partial transfer.
- Authorized reversal with linked compensating entries and no repeated/over-reversal.
- Wallet projection, reserved/held amounts, ledger and reconciliation totals agree.
- No unauthorized negative balance or double spend.

Do not manufacture account mappings, formulas, policy or owner approvals. If a business rule is absent, stop that implementation branch and mark it blocked for the authorized Finance/Wallet owner.

### Gate C — Deposit, webhook and refund

Run the relevant provider signature, webhook security, replay/retry, deposit integrity/review, durable checkout, refund/dispute/chargeback and follow-up recovery suites. Use configured test secrets and isolated fake/sandbox adapters only. Never contact live payment providers or use real customer/provider payloads.

### Gate D — Withdrawal and payout

Run admission/eligibility, balance reservation, release/consume exactly-once, maker-checker/audit, reconciliation, retry/recovery, unique external reference, callback replay and payout evidence suites. Never create an actual payout.

### Gate E — Transfers/conversions

Run transfer/conversion tests, idempotency/replay, receiver/sender ownership, limits, fee routing, reversal authorization and route/middleware checks. Because the previous project history had conflicting transfer and conversion contracts, do not merge or normalize competing behavior without an approved canonical owner decision. If approved policy is missing, record `BLOCKED`, do not guess.

### Gate F — Purchase and GLO L6

Run all relevant betting/lottery/national/weekly/Bingo/PCSO and GLO suites, including admission, inventory uniqueness, sold/frozen/closed draw rejection, price mismatch, idempotency, ticket identity under concurrency, exact arithmetic, unsold-ticket rules and public-data privacy. Keep L6 distinct from 2D/3D/N3. Do not invent ticket prices, prize/tax formulas or government policy; only use a committed, approved source of truth.

### Gate G — Result and settlement

Run trust/source publication guard, four-eyes approval, result immutability, correction audit trail, chunked settlement, partial-worker-crash recovery, duplicate-winner prevention, reconciliation and draw cancellation/reversal suites. Use isolated disposable resources only. Prove invariants with raw output/query results, not source inspection alone.

### Gate H — Database and Rust integration

- Confirm DB engine/version and test database name before any destructive operation.
- On a disposable database only, run fresh migrations/seeding, migration status, the supported upgrade path, rollback/re-migrate tests, indexes/constraints and representative data-preservation checks.
- Do not run `migrate:fresh` or rollback on shared/production databases.
- For Rust/worker integration, execute the Backend-owned integration suites against the configured isolated database. Read-only inspection of Rust is allowed; changes to Rust/native source are not.
- Cargo tests/builds may be run as cross-track acceptance if toolchain and dependencies exist; Rust failures belong to Security/Rust ownership and must not be patched from this track.
- A passing SQLite test does not prove MariaDB/MySQL locking, concurrency or dialect compatibility. A claim of MariaDB 11.8 acceptance needs actual database output.

### Gate I — Security/compliance and server-side eligibility

Run Backend-owned IDOR/authorization, webhook security, parameterization, output/log privacy, rate limiting, age/KYC/AML, responsible gaming, self-exclusion, fraud/risk holds, upload or chat admission where in scope. Confirm enforcement exists at the server-side transaction boundary, not only in UI. Use synthetic fixtures and redact secrets/PII in all evidence.

## 5. Required handling of failures

For every command/test failure:

1. Save raw sanitized output and exact exit code.
2. Identify the first meaningful exception/assertion and relevant code owner.
3. Classify as Backend source defect, test/fixture defect, configuration/environment problem, cross-track contract mismatch, infrastructure prerequisite, or external approval dependency.
4. If Backend-owned and supported by a clear contract, implement the minimal root-cause fix and add/retain a useful regression test.
5. Rerun the focused test, the containing suite, and relevant regression tests. Preserve before/after evidence.
6. If owned by another track, do not edit it. Produce a concise owner handoff with exact evidence and expected contract; keep overall platform release blocked until accepted.
7. Do not count a command that was not run as a pass. Do not count repeated tests twice. Derive counts from raw JUnit/actual runner output.

## 6. Required artifacts — evidence, not a substitute for work

Create a new output folder such as `artifacts/backend-acceptance/<UTC>_<actual-short-sha-or-SNAPSHOT>/` containing:

- `BACKEND-IMPLEMENTATION-REPORT.md` — actual fixes, changed paths, architecture/ownership decisions.
- `BACKEND-RELEASE-GATE-MATRIX.csv` — every Section A–I requirement, owner, exact command, status, exit code and evidence path.
- `BACKEND-TEST-RESULTS.md` — fresh counts derived from raw results; no manually invented totals.
- `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md` — unresolved and cross-track blockers with proof.
- `environment.json` — sanitized PHP/Composer/DB/Redis/Rust/tool versions actually observed.
- `source-identity.json` — full source/Git identity or explicit `BLOCKED`; never guessed.
- `changed-files-manifest.csv` — every added/modified/deleted file, owner, rationale, before/after hash.
- Raw JUnit or native runner outputs for all actually executed suites.
- `checksums.sha256` for final immutable evidence files.

If a verified source handoff ZIP is requested/needed, package only genuine source changes plus the manifest and evidence. Never call a report-only packet or evidence-only archive an application release. ZIP it safely, verify CRC, names/path traversal, every embedded SHA, detached ZIP SHA, and fresh extraction equality.

## 7. Definition of Backend done

`BACKEND IMPLEMENTATION = PASS` only if:
- All applicable Backend-owned items from `backend-next.md` are mapped and implemented.
- Every mandatory Backend-owned gate has fresh evidence tied to the same actual source identity.
- All required Backend tests pass without skipped/disabled/softened assertions.
- Failures introduced by this change are zero, and pre-existing failures are proven against a matching baseline or remain explicit blockers.
- Database behavior is verified on the claimed engine, not inferred from another engine.
- Changed files are within scope, reviewed, hashed and fully documented.

`BACKEND IMPLEMENTATION = FAIL` if a confirmed Backend defect/test failure remains. Use `BLOCKED` when source provenance, toolchain, database or external authority makes verification impossible. Do not call either state 100% complete.

`PLATFORM RELEASE = RELEASE BLOCKED` if a mandatory DevOps Docker build, hosted CI, Security/Rust, Frontend/Mobile/PWA or other platform gate is missing or blocked. A Backend PASS does not waive another track's gate.

## 8. Explicitly forbidden

- Report-only or checklist-only completion.
- Fabricated commands, output, timestamps, run IDs, branches, commits, clean status, checksums, database versions, test counts or signatures.
- `|| true`, ignored return codes, weakened/deleted tests, artificial skips, changed goldens just to pass.
- Invented transfer fee/prize/tax/ledger policy or unsanctioned financial-owner approval.
- Editing Finance/Wallet, Frontend, Security/Rust, DevOps/CI, or Mobile/PWA-owned source.
- Network calls, remote Git operations, install/downloads, commits, pushes, tags, publishes, deployment, signing, or real-money transactions unless separately and explicitly authorized.
- Calling source inspection, contract tests, or a successful ZIP checksum a replacement for runtime acceptance.

## FINAL COMMAND

Read all of `backend-next.md`, inspect the actual repository, and execute this task now. Make real minimal Backend-owned code/test fixes, run and rerun all available mandatory Backend gates, preserve exact evidence and checksums, and deliver source-bound results. Do not stop at a progress report or create another process packet. Keep separate-track code untouched. If any gate cannot genuinely run, identify the exact blocker and leave it `BLOCKED`; do not claim 100% done. Report both the Backend implementation verdict and the separate platform release verdict.
