# MASTER CODING-AGENT PROMPT
## FINAL DEFECT CLOSURE — 2,027 TESTS → ZERO ERRORS → ZERO FAILURES → PINT GREEN → CI GREEN → ZIP

The repository has now crossed the infrastructure/runtime-availability barrier.

The latest runtime blocker closure established an executable environment containing:

- PHP
- Composer
- MariaDB
- Redis
- Node/NPM
- Rust/Cargo
- Playwright/Chromium

The application can now boot through Laravel and the main runtime stack.

DO NOT create Pages 551–650.

DO NOT create new product pages.

DO NOT perform another broad architecture rewrite.

This phase is exclusively for closing genuine runtime defects and obtaining a green release candidate.

============================================================
CURRENT RELEASE BLOCKERS
============================================================

Latest runtime execution recorded:

- Full PHPUnit suite: 2,027 tests
- Assertions: 107,928
- Errors: 49
- Failures: 113
- Skipped: 4
- Exit code: 2
- Pint: FAILED
- Hosted CI: no real hosted run identifier

Therefore:

`RELEASE BLOCKED`

The objective of this phase is:

`2,027 tests → 0 errors → 0 failures`

and:

`Pint → PASS`

and:

`Hosted CI → GREEN`

Only then may the production ZIP be created.

============================================================
GLOBAL RULE — FIX ROOT CAUSES, NOT SYMPTOMS
============================================================

For every failing test:

1. reproduce it;
2. identify the real root cause;
3. inspect existing canonical architecture;
4. fix the smallest correct layer;
5. rerun the specific test;
6. rerun the affected test group;
7. rerun the complete suite periodically;
8. do not weaken assertions merely to make tests pass.

NEVER:

- delete failing tests;
- comment out assertions;
- add broad `try/catch` to hide errors;
- return fake data;
- add fake classes only because a test expects them;
- bypass authorization;
- disable CSRF;
- disable mass-assignment protection;
- disable database constraints;
- change financial precision;
- change GLO capability merely for tests;
- mark skipped tests as passed;
- change production behavior only to satisfy stale test text without determining the actual contract.

============================================================
STEP 1 — FREEZE BASELINE
============================================================

Record:

```bash
git rev-parse HEAD
git status --short
php artisan test
composer lint
```

Create:

`runtime/final-defect-closure-baseline.json`

Record:

- commit;
- test count;
- assertion count;
- errors;
- failures;
- skipped;
- Pint status;
- timestamp.

============================================================
STEP 2 — BUILD FAILURE INVENTORY
============================================================

Produce a structured failure inventory.

Create:

`runtime/final-defect-failure-inventory.json`

Group failures into categories:

1. mass assignment;
2. missing classes;
3. missing methods;
4. missing bindings;
5. wrong constructor signatures;
6. route/view mismatch;
7. Blade compilation/rendering;
8. sitemap;
9. SQLite schema mismatch;
10. MySQL/MariaDB compatibility;
11. factory/fixture mismatch;
12. expected-content mismatch;
13. authentication/session;
14. authorization/IDOR;
15. payment;
16. wallet/ledger;
17. betting;
18. lottery;
19. GLO;
20. security;
21. configuration;
22. translation;
23. CSS/frontend assertions;
24. stale tests;
25. actual implementation defects.

Do not guess categories from names alone.

Use actual exception/error messages.

============================================================
STEP 3 — MASS-ASSIGNMENT FAILURES
============================================================

Locate every:

- `MassAssignmentException`
- guarded/fillable mismatch
- model creation failure caused by unfillable attributes.

For each model:

1. inspect migration;
2. inspect `$fillable` / `$guarded`;
3. inspect factory;
4. inspect production service;
5. inspect controller/request;
6. inspect tests;
7. determine authoritative writable fields.

Fix the correct model/service boundary.

DO NOT use:

```php
protected $guarded = [];
```

as a blanket fix.

DO NOT make sensitive attributes mass assignable merely to silence tests.

Sensitive fields must remain explicitly controlled.

Rerun only the affected tests first.

============================================================
STEP 4 — MISSING CLASSES
============================================================

For every:

`Class "... " not found`

determine whether the cause is:

A. missing production class;

B. wrong namespace;

C. wrong Composer autoload;

D. stale test;

E. wrong class name;

F. duplicated/renamed architecture.

Fix only the real issue.

If the class is genuinely part of the intended canonical architecture:

- create the proper class;
- put it in the correct namespace;
- wire it into the existing architecture;
- add focused tests.

Do NOT create meaningless compatibility shells just to satisfy a test.

============================================================
STEP 5 — MISSING METHODS
============================================================

For every:

`Call to undefined method`

inspect the canonical service/interface/contract.

Determine whether:

- implementation is incomplete;
- caller is stale;
- interface drift occurred;
- compatibility adapter is needed.

Preserve domain semantics.

Do not add methods that return fake success.

============================================================
STEP 6 — SERVICE-CONTAINER / BINDING FAILURES
============================================================

Locate:

- `BindingResolutionException`
- unresolved interfaces;
- constructor dependencies;
- missing providers;
- incorrect bindings.

Verify:

- service provider registration;
- singleton/scoped behavior;
- interface implementation;
- constructor type compatibility.

Run:

```bash
php artisan about
php artisan test --filter='...affected test...'
```

Then the affected group.

============================================================
STEP 7 — BLADE / VIEW FAILURES
============================================================

Fix:

- missing components;
- wrong component names;
- undefined variables;
- incorrect route names;
- missing translation keys;
- invalid Blade expressions;
- stale view assumptions.

The earlier closure already added:

`national-lottery.detail-content`

Do not duplicate it.

Search all references before adding anything new.

Run:

```bash
php artisan view:cache
```

Then affected view tests.

============================================================
STEP 8 — SITEMAP / PUBLIC SURFACE FAILURES
============================================================

Inspect sitemap-related failures.

Verify:

- canonical public URLs only;
- no auth URLs;
- no private/account URLs;
- no duplicate URLs;
- no competitor/reference-site strings;
- no fake URLs;
- no unavailable pages incorrectly advertised.

Check:

`/sitemap.xml`

against actual route registration.

============================================================
STEP 9 — SQLITE TEST SCHEMA MISMATCHES
============================================================

The test suite uses SQLite in the CI PHP job while the full runtime uses MySQL/MariaDB.

Find every SQLite-specific failure.

Determine whether the issue is:

- migration incompatible with SQLite;
- test-only schema mismatch;
- JSON/enum/date behavior;
- unsupported SQL syntax;
- index definition;
- foreign-key semantics;
- production migration bug.

Do NOT destroy MySQL/MariaDB correctness merely to satisfy SQLite.

Where appropriate:

- improve portable migration code;
- isolate DB-specific assertions;
- explicitly document unavoidable engine differences;
- test both SQLite and MySQL where the domain requires it.

============================================================
STEP 10 — MYSQL / MARIADB PARITY
============================================================

Because real runtime now uses MariaDB, execute:

```bash
php artisan migrate:fresh --database=mysql --force
php artisan test
```

in the controlled database.

Verify:

- foreign keys;
- indexes;
- unique constraints;
- money fields;
- JSON fields;
- timestamps;
- enum/state fields;
- long identifiers.

Do not rely exclusively on SQLite.

============================================================
STEP 11 — EXPECTED-CONTENT MISMATCHES
============================================================

For every assertion such as:

- expected text not found;
- wrong label;
- expected route;
- wrong translation;
- missing section;
- stale content;

determine whether:

1. implementation is wrong;
2. test is stale;
3. source-of-truth contract changed.

Do NOT blindly modify production copy to satisfy an outdated assertion.

Preserve approved current product/legal/business source of truth.

============================================================
STEP 12 — FACTORY / FIXTURE CORRECTION
============================================================

Review failing factories and fixtures.

Factories must:

- create valid records;
- satisfy current DB constraints;
- never create impossible financial state;
- never imply successful external payment unless explicitly synthetic;
- never create fake official lottery result state;
- never bypass KYC/RG/ownership invariants.

Correct factory dependencies in the smallest valid way.

============================================================
STEP 13 — AUTH / SECURITY FAILURES
============================================================

For authentication/security failures verify:

- session rotation;
- password reset;
- MFA;
- revocation;
- authorization;
- IDOR;
- CSRF;
- throttling;
- KYC document access;
- support owner isolation;
- secure errors;
- redaction.

Do not weaken security to repair a failing assertion.

============================================================
STEP 14 — FINANCE FAILURES
============================================================

For every payment/wallet/withdrawal failure verify:

- exact money arithmetic;
- transaction boundary;
- idempotency;
- ownership;
- webhook verification;
- replay protection;
- amount mismatch rejection;
- wallet/ledger consistency;
- withdrawal holds;
- reconciliation.

No test may pass because the financial mutation was removed.

============================================================
STEP 15 — BETTING FAILURES
============================================================

For betting failures verify:

- authoritative draw;
- price;
- fees;
- RG limits;
- self-exclusion;
- wallet balance;
- reservation;
- idempotency;
- concurrency;
- ticket ownership.

No frontend value becomes authoritative.

============================================================
STEP 16 — LOTTERY FAILURES
============================================================

For lottery/result failures verify:

- draw state;
- cutoff;
- timezone;
- leading zeros;
- provenance;
- certification;
- publication;
- correction;
- conflict detection;
- archive;
- no-data behavior.

Do not fabricate official results.

============================================================
STEP 17 — GLO FAILURES
============================================================

Current GLO capability must remain:

`NOT_CONFIGURED`

unless an authoritative configuration actually exists.

A passing test must NOT be obtained by:

- enabling fake checkout;
- fabricating provider data;
- fabricating official results;
- bypassing age gate;
- bypassing KYC;
- bypassing freeze;
- bypassing payment hold.

Test fail-closed behavior exactly as intended.

============================================================
STEP 18 — RUN TARGETED TEST GROUPS
============================================================

After each family of fixes run the relevant filter.

Examples:

```bash
php artisan test --filter='Authentication'
php artisan test --filter='Payment'
php artisan test --filter='Wallet'
php artisan test --filter='Bet'
php artisan test --filter='Lottery'
php artisan test --filter='Glo'
php artisan test --filter='Support'
php artisan test --filter='Security'
```

Use exact existing test names when available.

Record each iteration.

============================================================
STEP 19 — FULL SUITE
============================================================

When the defect count has materially dropped:

```bash
php artisan test
```

Acceptance:

- 0 errors;
- 0 failures.

Skipped tests must be explicitly classified.

A skipped test may remain only when genuinely environment-dependent and documented.

============================================================
STEP 20 — PINT
============================================================

Run:

```bash
composer lint
```

or:

```bash
vendor/bin/pint --test
```

Fix all style violations.

Do not disable Pint.

Do not exclude application files merely to obtain green status.

Use the repository's existing style conventions.

Run:

```bash
vendor/bin/pint --test
```

again until clean.

============================================================
STEP 21 — PHP SYNTAX
============================================================

Run:

```bash
find app bootstrap config database routes tests -name '*.php' -print0 |
while IFS= read -r -d '' f; do
    php -l "$f" || exit 1
done
```

Must be zero syntax errors.

============================================================
STEP 22 — LARAVEL CACHE / ROUTES / VIEWS
============================================================

Run:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

All must pass.

============================================================
STEP 23 — FRONTEND
============================================================

Run:

```bash
npm ci
npm audit
npm run build
```

Acceptance:

- install PASS;
- audit zero vulnerabilities;
- build PASS.

============================================================
STEP 24 — RUST
============================================================

Run:

```bash
cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

All must pass.

============================================================
STEP 25 — PLAYWRIGHT
============================================================

Run existing browser suite.

Verify:

- public pages;
- auth;
- mobile;
- critical forms;
- result pages;
- wallet surfaces;
- failure states;
- GLO fail-closed surface.

Do not fabricate browser results.

============================================================
STEP 26 — FULL RUNTIME E2E
============================================================

With controlled MariaDB + Redis:

verify:

- Laravel boot;
- DB;
- Redis;
- queue;
- scheduler;
- public health;
- route dispatch;
- key authenticated journeys.

============================================================
STEP 27 — BACKUP / RESTORE
============================================================

Repeat the proven backup/restore process.

Verify:

- dump;
- checksum;
- restore;
- Laravel boot;
- migration status;
- selected data consistency.

============================================================
STEP 28 — SECURITY LOG REVIEW
============================================================

Inspect logs generated during test execution.

Confirm no:

- passwords;
- APP_KEY;
- provider secrets;
- tokens;
- private keys;
- KYC body;
- unauthorized financial internals

appear.

============================================================
STEP 29 — HOSTED CI
============================================================

This is mandatory.

Push the release candidate to the actual repository.

Execute hosted CI.

Required green jobs:

- PHP tests;
- frontend;
- security;
- Rust;
- full runtime release candidate.

Capture:

- workflow name;
- run ID;
- commit SHA;
- job results;
- timestamp.

Create:

`runtime/hosted-ci-final-evidence.json`

Do not say “CI passed” without a real run identifier.

============================================================
STEP 30 — FINAL ACCEPTANCE
============================================================

Generate:

`runtime/final-release-acceptance.json`

The acceptance evaluator must require:

```text
PHP = VERIFIED
Composer = VERIFIED
Laravel = VERIFIED
Database = VERIFIED
Redis = VERIFIED
Queue = VERIFIED
Scheduler = VERIFIED
PHPUnit = VERIFIED
Pint = VERIFIED
Frontend = VERIFIED
NPM audit = VERIFIED
Rust = VERIFIED
Browser E2E = VERIFIED
Security = VERIFIED
Backup = VERIFIED
Restore = VERIFIED
Hosted CI = VERIFIED
GLO = NOT_CONFIGURED / FAIL-CLOSED VERIFIED
```

GLO `NOT_CONFIGURED` is acceptable only where the release policy intentionally keeps GLO purchase disabled and the fail-closed behavior has been genuinely tested.

============================================================
RELEASE DECISION
============================================================

Only:

`RELEASE READY`

when every required critical gate passes.

Otherwise:

`RELEASE BLOCKED`

Never use:

- “almost ready”;
- “should be fine”;
- “production-ready except...”;
- invented percentage completion.

============================================================
FINAL REPORT
============================================================

Create:

`FINAL-RELEASE-REPORT.md`

Include:

### Baseline
- 2,027 tests
- 49 errors
- 113 failures
- 4 skipped

### Final
- exact test count;
- exact assertion count;
- exact errors;
- exact failures;
- exact skips;
- Pint result;
- browser result;
- Rust result;
- security result;
- DB result;
- Redis result;
- backup/restore;
- hosted CI run ID.

### Defect closure
For every defect category:

- count before;
- fixes made;
- count after;
- remaining.

### Final blockers
Only list actual remaining blockers.

============================================================
ZIP RULE
============================================================

DO NOT create the production ZIP until:

`RELEASE READY`

has been produced by the actual final acceptance evidence.

When green:

1. verify clean Git state;
2. verify no `.env`;
3. verify no secrets;
4. verify `public/build`;
5. verify lockfiles;
6. verify migrations;
7. verify tests;
8. verify CI;
9. create ZIP;
10. SHA-256 the ZIP;
11. write `RELEASE-ZIP-MANIFEST.md`.

The ZIP must be the artifact of the green release candidate, not a workaround for unresolved failures.

============================================================
FINAL SUCCESS CONDITION
============================================================

This phase is complete only when:

```text
2,027-test baseline
        ↓
0 errors
0 failures
        ↓
Pint PASS
        ↓
Browser PASS
Rust PASS
Security PASS
Backup/Restore PASS
        ↓
Hosted CI GREEN
        ↓
RELEASE READY
        ↓
FINAL PRODUCTION ZIP
```

Do not add more pages until this chain is genuinely green.