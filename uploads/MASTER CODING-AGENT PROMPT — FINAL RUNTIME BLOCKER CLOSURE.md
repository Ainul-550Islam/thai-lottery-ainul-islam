# MASTER CODING-AGENT PROMPT
## FINAL RUNTIME BLOCKER CLOSURE — DO NOT ADD MORE PRODUCT PAGES

The repository has completed Pages 1–550 implementation coverage.

Do NOT continue creating Pages 551–650 product/UI pages.

The current Pages 451–550 evidence says:

`RELEASE BLOCKED`

The purpose of this task is to eliminate the remaining runtime blockers and obtain genuine executable release evidence.

The implementation must not be declared production-ready until the actual runtime gates pass.

============================================================
PRIMARY OBJECTIVE
============================================================

Turn the current repository into an executable release candidate by providing a reproducible runtime environment and executing the already-created release gates.

Do not create fake evidence.

Do not mark blocked commands as passed.

Do not bypass platform requirements.

Do not fabricate:

- payments,
- wallet credits,
- withdrawals,
- lottery results,
- historical results,
- prize payouts,
- GLO purchases,
- provider callbacks,
- KYC approvals.

============================================================
STEP 1 — INSPECT CURRENT ENVIRONMENT
============================================================

Run:

```bash
pwd
git rev-parse --short HEAD
git status --short

php -v
php -m

composer --version

node --version
npm --version

mysql --version || true
mariadb --version || true

redis-cli --version || true

cargo --version
rustc --version

playwright --version || true
```

Also inspect whether these exist:

```text
Docker
docker compose
GitHub Actions configuration
CI workflow
```

Record exact availability.

============================================================
STEP 2 — DO NOT MODIFY APPLICATION LOGIC YET
============================================================

Before changing Laravel business logic:

inspect:

```text
.github/workflows/ci.yml
composer.json
composer.lock
package.json
package-lock.json
security/weekly-result-integrity/Cargo.toml
security/weekly-result-integrity/Cargo.lock
.env.example
docker/
compose/
```

Reuse existing deployment/runtime configuration where present.

Do not introduce a second deployment architecture unless absolutely necessary.

============================================================
STEP 3 — REPRODUCIBLE RUNTIME STRATEGY
============================================================

The current blocker is environmental rather than a missing page implementation.

Create ONE reproducible runtime path.

Preferred order:

1. Existing project Docker/Compose runtime, if already present.
2. Existing CI runtime expanded to execute all required gates.
3. Minimal project-specific container/runtime definitions if neither exists.

The runtime must provide the project's documented requirements, including:

- PHP 8.2+/project-supported version;
- required PHP extensions;
- Composer;
- MySQL/MariaDB;
- Redis;
- Node 20;
- NPM;
- Rust/Cargo;
- browser automation runtime.

Do not put secrets into the repository.

Use environment variables or CI secrets.

============================================================
STEP 4 — PHP / COMPOSER
============================================================

Inside the reproducible runtime execute:

```bash
php -v
php -m

composer validate --no-check-publish
composer install --prefer-dist --no-interaction --no-progress
composer check-platform-reqs
composer audit --no-interaction
composer dump-autoload --optimize
```

Any Composer platform failure is a release blocker.

Do NOT use:

```bash
--ignore-platform-reqs
```

as release evidence.

============================================================
STEP 5 — LARAVEL BOOT
============================================================

Execute:

```bash
php artisan about
php artisan optimize:clear
php artisan config:cache
php artisan route:list
php artisan route:cache
php artisan view:cache
```

Then verify application health.

The health endpoint must be executed against an actually running application.

Do not merely curl an inactive localhost URL.

============================================================
STEP 6 — DATABASE
============================================================

Create an isolated disposable test database.

NEVER use production.

Verify:

```bash
php artisan migrate:status
```

Then:

```bash
php artisan migrate:fresh --force
```

only inside the explicitly controlled test environment.

Run controlled seed.

Verify:

- foreign keys;
- unique constraints;
- monetary precision;
- indexes;
- ownership;
- rollback;
- commit;
- concurrency;
- deadlock behavior.

============================================================
STEP 7 — REDIS / QUEUE / SCHEDULER
============================================================

Verify:

```bash
redis-cli ping
php artisan schedule:list
php artisan queue:failed
```

Execute controlled:

- queue worker;
- retry;
- failed job;
- recovery;
- distributed lock;
- scheduler.

Verify duplicate-safe behavior.

============================================================
STEP 8 — FULL PHP TEST SUITE
============================================================

Run:

```bash
php artisan test
```

Do not convert skipped/environment-dependent tests into success without documenting the exact reason.

Record:

- test count;
- passed;
- failed;
- skipped;
- duration.

Critical financial/security tests must execute.

============================================================
STEP 9 — FINANCE RUNTIME
============================================================

Execute controlled test flows using test users and test balances only.

Verify:

- deposit initiation;
- callback;
- signature verification;
- replay protection;
- mismatch handling;
- timeout handling;
- wallet credit;
- wallet debit;
- wallet hold;
- withdrawal;
- approval;
- completion;
- failure recovery;
- reconciliation.

Verify:

`wallet state == ledger-derived state`

No real money.

No production provider credentials.

============================================================
STEP 10 — BET / TICKET / PRIZE
============================================================

Execute controlled:

- bet purchase;
- duplicate purchase;
- concurrent purchase;
- responsible gaming limit;
- self-exclusion;
- ticket issuance;
- ownership isolation;
- ticket verification;
- prize matching;
- exact-money calculation;
- settlement;
- payout idempotency;
- payout failure.

Verify no client-supplied amount becomes authoritative.

============================================================
STEP 11 — LOTTERY / RESULT
============================================================

Use controlled test fixtures only.

Execute:

- draw opening;
- draw closing;
- result import;
- provenance;
- conflict;
- correction;
- certification;
- publication;
- public API;
- archive;
- no-data behavior.

Do not create fake official historical results.

============================================================
STEP 12 — GLO
============================================================

Execute capability check first.

If:

`NOT_CONFIGURED`

then keep it fail-closed.

Do not activate checkout simply to make the test pass.

Where legitimate canonical test capability exists, execute:

- ticket engine;
- prize calculator;
- result import;
- claim;
- duplicate claim;
- age gate;
- KYC gate;
- payment hold;
- freeze;
- freeze expiry;
- frozen winner;
- publication.

============================================================
STEP 13 — SECURITY
============================================================

Execute runtime security tests for:

- login;
- registration;
- password reset;
- session rotation;
- MFA;
- revocation;
- authorization;
- IDOR;
- CSRF;
- rate limits;
- webhook signatures;
- replay;
- KYC document ownership;
- support ownership;
- error handling;
- log redaction.

Create at least two controlled users and explicitly test cross-owner access.

============================================================
STEP 14 — BROWSER E2E
============================================================

Start the actual application server.

Then run browser tests.

Required journeys:

PUBLIC:
- Home
- Results
- Lottery
- Result detail
- FAQ
- Contact

AUTH:
- Register
- Login
- Password reset
- Dashboard
- Profile
- Security
- Responsible gaming
- KYC

FINANCE:
- Deposit
- Wallet
- Withdrawal
- status pages

LOTTERY:
- purchase;
- ticket;
- result;
- prize verification;
- claim

ADMIN:
- dashboard;
- draws;
- wallets;
- ledger;
- reconciliation;
- KYC;
- audits.

Test:

- desktop;
- mobile viewport;
- validation failure;
- unauthorized state;
- keyboard-critical interactions.

============================================================
STEP 15 — ACCESSIBILITY
============================================================

Execute automated browser accessibility smoke checks.

Verify:

- labels;
- focus;
- keyboard navigation;
- errors;
- heading hierarchy;
- responsive layout;
- no critical horizontal overflow;
- reduced-motion behavior.

============================================================
STEP 16 — RUST
============================================================

Execute:

```bash
cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

For the weekly integrity crate verify:

- deterministic vectors;
- malformed input;
- leading zeros;
- expected exit codes;
- no network;
- no database dependency.

============================================================
STEP 17 — BACKUP / RESTORE
============================================================

Against controlled staging/test database:

1. create backup;
2. checksum;
3. restore into fresh database;
4. boot Laravel;
5. verify schema;
6. compare wallet/ledger;
7. compare lottery records;
8. compare audit records.

A successful backup without successful restore does NOT satisfy the gate.

============================================================
STEP 18 — CI
============================================================

Push the release candidate to the configured repository/branch so hosted CI actually executes.

The hosted pipeline must produce genuine evidence for:

- PHP;
- Composer;
- tests;
- NPM;
- audit;
- build;
- Rust;
- structural gates.

Record the CI run identifier.

Do not claim “CI verified” based solely on workflow source inspection.

============================================================
STEP 19 — FINAL AGGREGATION
============================================================

Run:

```bash
python3 scripts/pages_451_550_final_acceptance.py
```

Then inspect:

```text
runtime/pages-451-550-final-acceptance.json
```

Do not modify the acceptance script merely to downgrade blockers.

The final result must be calculated from real evidence.

============================================================
STEP 20 — EXPAND FINAL ACCEPTANCE EVIDENCE
============================================================

If required gates are now executable, create:

```text
runtime/final-runtime-activation.json
runtime/final-financial-evidence.json
runtime/final-lottery-evidence.json
runtime/final-security-evidence.json
runtime/final-browser-evidence.json
runtime/final-rust-evidence.json
runtime/final-backup-restore-evidence.json
runtime/final-ci-evidence.json
```

All artifacts must be secret-free.

============================================================
STEP 21 — RELEASE DECISION
============================================================

Use exactly:

`RELEASE READY`

only when ALL critical gates have:

- executed;
- passed;
- evidence recorded.

Otherwise:

`RELEASE BLOCKED`

List every blocker by domain.

============================================================
STEP 22 — ONLY AFTER GREEN: FINAL ZIP
============================================================

Only if the final decision is:

`RELEASE READY`

create the production ZIP.

Before creating it:

```bash
git status --short
git diff --check
```

Ensure:

- `.env` excluded;
- secrets excluded;
- private keys excluded;
- node_modules excluded;
- vendor excluded if deployment installs Composer dependencies;
- temporary files excluded;
- caches excluded;
- `public/build` included;
- migrations included;
- tests included;
- CI included;
- Rust source/lockfiles included;
- runtime reports included.

Run a ZIP secret scan.

Generate:

`RELEASE-ZIP-MANIFEST.md`

Include:

- git commit;
- timestamp;
- PHP;
- Laravel;
- Node;
- Rust;
- DB;
- test result;
- security result;
- browser result;
- backup/restore result;
- CI run;
- final acceptance;
- SHA-256.

============================================================
IMPORTANT
============================================================

Do NOT create a production ZIP while any critical runtime family remains:

- BLOCKED
- FAILED
- NOT_VERIFIED
- NOT_CONFIGURED where required.

A code snapshot may be created separately for backup, but it must be explicitly named as a snapshot and never as a production release.

============================================================
FINAL OUTPUT
============================================================

Report:

- runtime provisioned or not;
- PHP result;
- Composer result;
- Laravel result;
- database result;
- Redis result;
- queue result;
- scheduler result;
- test result;
- finance result;
- lottery result;
- GLO result;
- security result;
- browser result;
- accessibility result;
- Rust result;
- backup/restore result;
- hosted CI result;
- final decision;
- exact blockers;
- exact ZIP path and SHA-256 only when `RELEASE READY`.

The objective is now evidence closure, not increasing the page count.