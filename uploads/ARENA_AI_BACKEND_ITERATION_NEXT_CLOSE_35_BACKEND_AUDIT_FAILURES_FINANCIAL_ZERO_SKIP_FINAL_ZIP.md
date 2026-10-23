# ARENA AI — BACKEND-ONLY ITERATION: CLOSE 35 BACKEND/AUDIT FAILURES, VERIFY FINANCIAL LOGIC, THEN FINAL ZIP (ZERO-SKIP)

## Mission and completion order

Work on **BACKEND + FINANCIAL LOGIC ONLY** in the existing repository. Do not switch to Frontend, DevOps, Mobile/PWA, Security/Rust, or another track. The intended workflow is one track at a time: finish and independently verify Backend, create its final downloadable ZIP, then the owner will handle the GitHub push and move to the next track.

The supplied Backend Implementation & Zero-Skip Release Evidence Report (`20261023T063900Z_2c7ab9c`) marks `BACKEND IMPLEMENTATION: PASS` but explicitly keeps `PLATFORM RELEASE ACCEPTANCE: RELEASE BLOCKED`. It also reports 48 non-green PHPUnit cases: **35 Backend/Audit-owned cases** (H1–H5, H7: 23 AuditAnchorTruthTest/AuditSubjectTruthTest cases and 12 backend legacy UUID/test-fixture alignment cases) plus **13 Frontend Blade assertions** (H6). Do not misstate this as all tests green or Backend fully closed yet.

## Hard track boundaries

### Allowed
- Backend PHP production code, backend-owned DTOs/services/models/requests/policies/middleware/jobs/console scheduling, backend-owned database migrations, and backend-owned tests/fixtures only when the contract genuinely requires them.
- Audit/verifier source and tests **only to the extent that repository ownership manifests and the current authoritative contract confirm they are Backend/Audit-owned**. Prove ownership before editing.
- Financial integrity and evidence: double-entry ledger, transaction atomicity, reconciliation, idempotency, concurrency/row locking, deposit/webhook integrity, withdrawal and payout, wallet transfer/conversion, ticket purchase/amend/cancel, GLO L6, result publication/settlement, and compliance/RG/AML/KYC/self-exclusion.
- Test/evidence tooling necessary to reproduce, verify, and package this Backend track.

### Forbidden
- Do not edit Frontend Blade templates, CSS, JavaScript, Vite assets, translations owned by Frontend, or Frontend tests to make the 13 H6 assertions green.
- Do not edit DevOps Docker/Compose/Nginx/GitHub workflows or alter runtime pins to create a false local pass.
- Do not edit Rust/native worker code unless the ownership manifest explicitly assigns it to Backend and the defect is independently reproduced; preserve the Security/Rust track boundary.
- Do not modify unrelated tests, delete assertions, loosen expected values, mark tests skipped/incomplete, add fake source/stubs, generate synthetic PASS evidence, or claim unavailable Docker/payment-gateway checks passed.
- Do not force-push, rewrite Git history, push to GitHub, or perform deployment. The owner will review/download the final track ZIP and handle the GitHub push afterward.

## Phase 0 — establish the exact candidate before editing

1. Read the complete `BACKEND-IMPLEMENTATION-REPORT.md`, `BACKEND-TEST-RESULTS.md`, `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`, `BACKEND-RELEASE-GATE-MATRIX.csv`, source identity, environment, manifests, raw JUnit XML, and referenced logs under `/home/user/artifacts/backend-acceptance/20261023T063900Z_2c7ab9c/`.
2. Verify current repository root, branch, HEAD, dirty diff, PHP/Composer/database/cache versions, and the exact evidence bundle checksums. Do not assume current `HEAD` still equals `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7`; record what is actually checked out.
3. Before each edit, capture the baseline and check actual owners for every target path. Preserve user changes. Never overwrite a dirty file without understanding and preserving its diff.
4. Create a reproducible mapping of all 35 non-green H1–H5/H7 cases to test file, case name, owning path(s), raw failure/error, expected invariant, and selected fix. Include the 13 H6 Frontend cases in the handoff as **excluded / separately owned**, not in the Backend edit list.
5. If evidence is missing or stale, reproduce the specific test and gather raw output. Do not invent the underlying failure from summary prose alone.

## Phase 1 — close H1–H4 and H7: 12 backend UUID/fixture cases

For every one of the 12 named cases in `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`:

1. Read the complete failure trace and relevant production contract. Decide from evidence whether the defect is production behavior, an obsolete fixture, a test contract drift, or a real schema/compatibility problem.
2. Prefer fixing the actual Backend implementation when the contract is valid. If a test fixture is demonstrably stale, update only the Backend-owned fixture to accurately represent the supported contract; document evidence for why it is stale. Never change a fixture solely to make the test pass.
3. For financial/accounting behavior, assert exact persisted state and journal entries as appropriate: debit equals credit, idempotent replay is stable, conflicting replay is rejected, rollback is atomic, reversals are append-only, actor/owner scoping is preserved, and concurrent operations cannot double-spend/double-settle.
4. No floating-point money calculations. Preserve integer minor units or the project's approved decimal Money value object and existing currency/rounding policy. Do not invent fee schedules, prize/tax amounts, exchange rates, or product terms.
5. Add or preserve a targeted regression test for every code fix. Run the focused case immediately, then its entire owning suite.

## Phase 2 — resolve H5: 23 AuditAnchorTruthTest / AuditSubjectTruthTest cases

1. Read the exact tests and the referenced/reverted-commit static audit-source assertions. Verify actual ownership of source and test paths before edits.
2. Establish the current authoritative audit contract from source/specification and commit history. An assertion failing because it references an obsolete commit or stale source marker does not automatically justify modifying the test. Equally, do not dismiss a failing security/audit invariant as merely stale without evidence.
3. Confirm audit records anchor the correct source event/subject, use stable identifiers, preserve actor/subject ownership, avoid forged client-supplied identity/financial authority, and produce truthful durable evidence. Follow the implementation's approved data model and migration history.
4. Fix the owning production implementation when an invariant is broken. Update an assertion only when it is provably obsolete against a newer approved contract and retain equivalent-or-stronger behavioral/security coverage. Document the old assumption, authoritative evidence, and replacement invariant in the regression mapping.
5. Verify that the resulting audit identity is derived from authoritative persisted/server-side data—not caller-controlled fields—and that corruption, ambiguity, replay, missing anchors, and cross-owner references fail closed.
6. Do not restore a reverted commit blindly. Inspect its diff and reason for revert; apply only the required correct behavior, not unrelated content.

## Phase 3 — comprehensive financial/backend zero-skip validation

After the 35 cases are closed, rerun affected focused tests first, then the full existing backend gates. At minimum, attempt all applicable checks from the current evidence contract:

- PHP 8.4 syntax lint for all discovered PHP files; Composer validate, optimized autoload and platform requirements.
- Financial-source preflight, release-source inventory and environment parity; Artisan config/route/schedule/migration checks.
- Ledger/money reconciliation; transaction idempotency and conflict; atomic rollback/reversal; row-lock/race tests.
- Deposit initiation, signature validation, webhook replay, payment/deposit ownership integrity, refund recovery.
- Withdrawal admission, reserved holds, approval, dispatch/recovery, provider references, payout evidence.
- Cash/Win transfers and conversion, ownership, fees, limits, reversal authorization and concurrent access.
- Ticket purchase/amendment/cancellation, GLO L6 invariants, result ingestion/publication and chunked settlement/double-winner prevention.
- Responsible gaming, self-exclusion, KYC/age/AML, admin RBAC/MFA, IDOR, redaction, rate-limit and audit trail suites.
- MariaDB + Redis suites that replace SQLite-only skips. All previously SQLite-skipped InnoDB/Redis tests must have actual executed evidence—never count an SQLite skip as a pass.
- Rust native suites only as verification of their existing evidence if executable here; do not claim to have changed or completed the separate Security/Rust track.

Report exact unique test counts and deduplication rules. Distinguish `passed`, `failed`, `errors`, `skipped`, `not run`, and environment-blocked. **Zero-skip means no required test is left unexecuted—not that every runner can necessarily be green inside this environment.** If an environment block remains, show the exact command, environment fact, and required owner/host.

Do not call Backend 100% complete while any Backend-owned required test fails, errors, or remains unexecuted. Preserve the 13 Frontend H6 failures as a separate handoff. If any of the 35 cases turns out to be owned by another track, do not cross-edit: provide evidence and a precise handoff, and state that Backend-owned scope may be done only if all Backend requirements are independently green.

## Phase 4 — rerun, audit the diff, and prove the result

1. Run each changed test against the pre-fix baseline when feasible, observe the regression test fail before the fix, then pass after the fix. Preserve logs.
2. Rerun all affected suites and the full prescribed Backend suite at the current candidate revision. Record exact exit codes, versions, timestamps, command lines, test counts and assertions.
3. Run `git diff --check`; review every changed line; scan changed paths against the ownership manifests. Verify there are no unrelated Frontend/DevOps/Mobile/PWA or unauthorized Security/Rust edits.
4. Recompute the current source identity and checksums after all fixes. Never reuse stale JUnit XML or logs as evidence for changed source; evidence must bind to the exact final candidate revision/hash.
5. If a check cannot run because Docker daemon or a live external payment provider is unavailable, label the exact release gate `BLOCKED`, keep it separate from Backend implementation verdict, and do not fake or simulate an integration pass.

## Phase 5 — final Backend handoff ZIP (only when Backend criteria are satisfied)

Do not package a `FINAL / 100% COMPLETE` ZIP before the following conditions hold:

- All 35 Backend/Audit-owned H1–H5/H7 cases are resolved or any proven cross-track cases have been formally handed off with evidence and are not falsely represented as Backend complete.
- All required Backend/financial suites pass with zero failures, zero errors and zero unexecuted required skips on the environments required by the specification.
- Backend/financial regression and security invariants remain intact; frontend-owned files remain untouched in this iteration.
- Final source identity, test evidence and file manifests match the exact candidate tree.

When criteria pass, create a **Backend-only, complete and reproducible ZIP**, containing the approved changed Backend source/tests plus the necessary current Backend implementation/evidence documents and raw test outputs. Include, at minimum:

- `BACKEND-IMPLEMENTATION-REPORT.md` with implementation verdict separated from platform release verdict.
- `BACKEND-TEST-RESULTS.md`, `BACKEND-RELEASE-GATE-MATRIX.csv`, and updated `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`.
- `source-identity.json`, `environment.json`, a changed-files manifest with hashes/sizes/ownership rationale, raw JUnit XML and raw command logs, and `checksums.sha256` covering the evidence bundle.
- A detached ZIP SHA-256 file.
- A ZIP integrity check (CRC), traversal/symlink/path validation, duplicate-name/case-collision check, and a fresh extraction verification proving that extracted bytes match the manifest. Document commands and their real exit codes.

Use a new unique evidence directory for this run; never overwrite historic evidence. Ensure the ZIP does not accidentally include `.env`, secrets, payment credentials, private keys, generated caches (`vendor/`, `node_modules/` unless explicitly required), unrelated tracks, or unreviewed dirty files. Do not omit a required source file merely to reduce ZIP size.

If any criterion fails, **do not produce or label a final complete ZIP**. You may provide a clearly labeled interim diagnostic bundle only if useful, with `NOT FINAL — BACKEND NOT 100%` in its filename and report. Keep working on remaining in-scope defects instead of stopping at a report.

## Required final response format

Return only after the work performed in this iteration, with evidence-backed facts:

1. `BACKEND IMPLEMENTATION: PASS` or `NOT COMPLETE`.
2. `FINANCIAL LOGIC: PASS` or `NOT COMPLETE`.
3. `PLATFORM RELEASE ACCEPTANCE: ACCEPTED` or `RELEASE BLOCKED` (never merge this with the implementation verdict).
4. H1–H5/H7 case matrix: original failure, owner, exact fix or justified handoff, regression test, result.
5. Final unique counts for passed/failures/errors/skipped/not-run/blocked, with the suite/runner definitions.
6. Exact current branch, source revision/hash, change summary and list of every modified file.
7. Final ZIP path and detached SHA-256 **only if** all required Backend/financial ZIP criteria passed; otherwise explicitly say no final ZIP was created and what exact closure remains.
8. Keep H6's 13 Frontend Blade failures as a separate owner handoff. Explicitly confirm whether any Frontend-owned file changed; expected answer is `NO`.
9. GitHub push status: `NOT PUSHED — owner will review/download and push`. Do not push or deploy.

## Final non-negotiable rule

**No fake 100%, no invented evidence, no hidden skips, no test weakening, no placeholders, no cross-track edits.** Continue coding and testing the actual Backend-owned defects until the exact requirements are satisfied. A report is not a substitute for source changes and reproducible green tests. Conversely, do not label platform release accepted while the Docker-hosted smoke tests, prohibited live payment cutover, or separate Frontend failures remain blocked.
