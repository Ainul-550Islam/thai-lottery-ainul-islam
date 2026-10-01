# MASTER CODING-AGENT PROMPT
## PAGES 451–550 — RUNTIME ACTIVATION → FULL E2E → RELEASE CANDIDATE → FINAL ZIP

You are continuing an existing Laravel enterprise Thai-lottery / wagering platform after Pages 1–450.

This phase is NOT a new frontend-design phase.

This phase exists to close the remaining gap between:
- implemented source code,
- static/source-level hardening,
- frontend build verification,
and
- actual executable production/runtime evidence.

The repository already contains extensive implementation and architecture from Pages 1–450. Preserve all existing canonical logic.

Do NOT create a second architecture.
Do NOT duplicate existing services/controllers/models.
Do NOT replace canonical domain services with simplified alternatives.
Do NOT invent payment success.
Do NOT invent lottery results.
Do NOT fabricate historical official data.
Do NOT activate GLO purchase merely to make a test pass.
Do NOT inject fake credentials.
Do NOT print secrets.
Do NOT claim runtime success when the command did not actually execute.

The current Pages 351–450 report explicitly records the enterprise runtime boundary as:

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

and states that PHP, Composer, Laravel, database, Redis, queues, payment providers, browser E2E, and Rust remain unverified, while the frontend remediation (`npm ci`, `npm audit`, `npm run build`) is verified.

Your job in Pages 451–550 is therefore to create a deterministic, auditable runtime activation and final release process that:

1. discovers the real environment,
2. installs and verifies required runtime dependencies,
3. boots Laravel,
4. proves database/schema integrity,
5. proves Redis/cache/queue/scheduler behavior,
6. proves lottery/result lifecycle,
7. proves payment/wallet/ledger lifecycle,
8. proves betting/ticket/prize lifecycle,
9. proves GLO lifecycle without bypassing capability guards,
10. proves security boundaries,
11. proves browser journeys,
12. proves Rust boundary,
13. proves backup/restore,
14. proves deployment readiness,
15. proves observability,
16. generates machine-readable evidence,
17. blocks release on any unresolved critical failure,
18. creates the final ZIP ONLY after all required release gates are genuinely green.

============================================================
GLOBAL NON-NEGOTIABLE RULES
============================================================

A. NO FALSE VERIFICATION

Every runtime claim must be based on an actually executed command/test.

Allowed states:

- VERIFIED
- PASSED
- FAILED
- BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE
- NOT CONFIGURED
- NOT APPLICABLE
- PARTIALLY VERIFIED

Never convert BLOCKED into PASSED.

Never convert NOT CONFIGURED into PASSED.

Never use source inspection as runtime proof.

Never use a generated JSON artifact as proof that an underlying command succeeded unless the command actually executed.

------------------------------------------------------------

B. PRESERVE EXISTING LOGIC

Before modifying anything:

- inspect the current implementation;
- identify canonical services;
- identify canonical controllers;
- identify canonical models;
- identify canonical repositories;
- identify canonical DTOs;
- identify canonical commands;
- identify canonical events/jobs;
- identify canonical policies;
- identify canonical payment boundaries;
- identify canonical wallet/ledger boundaries;
- identify canonical lottery boundaries;
- identify canonical Rust boundaries.

Do not rewrite a working domain simply to fit this phase.

Reuse existing logic.

------------------------------------------------------------

C. NO SECRET EXPOSURE

Never output:

- APP_KEY
- DB password
- payment secrets
- webhook secret
- API tokens
- private keys
- certificates
- real user credentials
- KYC documents
- session secrets

Runtime evidence may record:

- key exists / absent;
- credential configured / not configured;
- provider enabled / disabled;
- executable path;
- version;
- exit code;
- bounded metadata.

Never record credential values.

------------------------------------------------------------

D. TEST DATABASE ONLY FOR DESTRUCTIVE OPERATIONS

No command may perform:

- migrate:fresh,
- seed destruction,
- destructive fixture reset,
- rollback,
- test payout,
- provider test transaction

against production.

Require an explicit environment guard.

If the environment cannot be positively classified as controlled/test/staging for destructive operations, BLOCK the operation.

------------------------------------------------------------

E. FINANCIAL SOURCE OF TRUTH

Money must remain server authoritative.

Never allow browser/client values to become authoritative for:

- amount,
- fee,
- discount,
- tax,
- wallet balance,
- prize,
- payout,
- commission,
- settlement.

Reuse canonical financial services.

------------------------------------------------------------

F. LOTTERY SOURCE OF TRUTH

Never let frontend JavaScript determine:

- winning ticket,
- winning amount,
- official result,
- prize amount,
- claim eligibility,
- settlement amount.

Those must come from canonical backend/result sources.

------------------------------------------------------------

G. GLO SAFETY

The existing GLO L6 capability guard remains authoritative.

If GLO purchase is `NOT_CONFIGURED`, do not fake-enable it.

You must prove that the application fails closed.

Only execute real GLO runtime purchase tests when the necessary authoritative capability/configuration exists.

============================================================
REPOSITORY INVENTORY REQUIREMENT
============================================================

Before changing files, generate:

`runtime/pages-451-550-inventory.json`

It must include:

- git commit;
- branch;
- dirty/clean working tree;
- PHP executable;
- PHP version;
- required PHP extensions;
- Composer executable/version;
- Node version;
- NPM version;
- MySQL/MariaDB availability;
- Redis availability;
- queue runtime availability;
- browser availability;
- Playwright availability;
- Cargo version;
- Rust version;
- Laravel vendor presence;
- `.env.example` presence;
- `.env` configuration-presence metadata only;
- public/build presence;
- Vite manifest presence.

============================================================
PAGE 451 — Runtime environment activation
============================================================

Establish the actual required runtime environment.

Acceptance:

- PHP available;
- Composer available;
- Node available;
- NPM available;
- required database client available;
- Redis client/server reachable where configured;
- Rust/Cargo available;
- browser automation available where required.

No secret values emitted.

Artifact:

`runtime/page-451-environment.json`

============================================================
PAGE 452 — PHP version gate
============================================================

Execute:

```bash
php -v
php --ini
php -m
```

Verify compatibility with repository requirements.

Do not assume extensions exist.

Record actual loaded extensions.

Artifact:

`runtime/page-452-php.json`

============================================================
PAGE 453 — PHP extension gate
============================================================

Explicitly verify required extensions, including repository-required extensions such as:

- bcmath
- pdo_mysql or required DB driver
- redis where applicable
- sodium
- mbstring
- dom
- fileinfo
- zip
- intl

Missing required extension must fail the runtime gate.

============================================================
PAGE 454 — Composer activation
============================================================

Execute:

```bash
composer validate --no-check-publish
composer install --prefer-dist --no-interaction --no-progress
composer check-platform-reqs
composer audit --no-interaction
```

Do not use:

```bash
composer install --ignore-platform-reqs
```

unless a test-only diagnostic is explicitly needed, and never treat it as acceptance evidence.

============================================================
PAGE 455 — Vendor integrity
============================================================

Verify:

- vendor directory exists;
- autoload file exists;
- Composer package graph resolves;
- no unexpected missing classes;
- no invalid optimized autoloader.

Execute:

```bash
composer dump-autoload --optimize
```

Then boot a minimal Laravel command.

============================================================
PAGE 456 — Laravel application boot
============================================================

Execute:

```bash
php artisan about
```

Capture:

- environment;
- Laravel version;
- PHP version;
- cache driver;
- queue driver;
- session driver;
- database driver.

Do not expose secrets.

============================================================
PAGE 457 — Configuration resolution
============================================================

Execute configuration inspection.

Verify every production-relevant config file resolves successfully.

Check:

- app;
- database;
- cache;
- queue;
- session;
- filesystems;
- services;
- finance;
- lottery lane configs;
- contact;
- security;
- broadcasting where applicable.

No unresolved environment reference may silently produce an unsafe default.

============================================================
PAGE 458 — Configuration cache
============================================================

Execute:

```bash
php artisan optimize:clear
php artisan config:cache
```

Verify the cached configuration is generated successfully.

Then boot the application using cached configuration.

============================================================
PAGE 459 — Route registration
============================================================

Execute:

```bash
php artisan route:list
```

Verify expected public, authenticated, admin, API, webhook, health, lottery, finance, support, KYC, and security routes exist.

Detect duplicate/conflicting routes.

Do not silently change route names merely to satisfy a test.

============================================================
PAGE 460 — Route cache
============================================================

Execute:

```bash
php artisan route:cache
```

Verify no serialization failure.

Then execute a route smoke test with cached routes enabled.

============================================================
PAGE 461 — View compilation
============================================================

Execute:

```bash
php artisan view:cache
```

Verify all Blade templates compile.

No placeholder view may be introduced.

============================================================
PAGE 462 — Application health endpoint
============================================================

Execute the canonical health endpoint.

Verify:

- application boot;
- dependency status;
- safe failure state;
- no secret leakage;
- correct HTTP status.

============================================================
PAGE 463 — Database connection
============================================================

Connect to controlled database.

Verify:

- connection;
- server version;
- timezone;
- charset;
- collation;
- strict mode where expected.

No financial mutation yet.

============================================================
PAGE 464 — Migration status
============================================================

Execute:

```bash
php artisan migrate:status
```

Verify:

- all required migrations present;
- no unexpected pending migrations;
- no duplicate migration identity;
- no failed migration state.

============================================================
PAGE 465 — Fresh schema build
============================================================

Against a dedicated disposable test database:

```bash
php artisan migrate:fresh --force
```

Verify the full schema builds.

Never use production database.

============================================================
PAGE 466 — Seed safety
============================================================

Execute:

```bash
php artisan db:seed --force
```

only against the disposable controlled database.

Verify production-critical seeders do not invent:

- wallet balances;
- successful payments;
- real lottery results;
- fake winning claims.

============================================================
PAGE 467 — Schema constraint validation
============================================================

Inspect and execute DB constraints.

Verify:

- foreign keys;
- uniqueness;
- indexes;
- monetary precision;
- nullable semantics;
- ownership relations;
- deletion behavior.

============================================================
PAGE 468 — Transaction rollback
============================================================

Execute controlled transaction test.

Verify that intentional exception causes:

- DB mutation rollback;
- wallet rollback where applicable;
- ledger rollback where applicable;
- no orphaned financial event.

============================================================
PAGE 469 — Transaction commit
============================================================

Execute successful transaction path.

Verify all expected records commit atomically.

============================================================
PAGE 470 — Concurrent transaction test
============================================================

Run controlled concurrency test.

Verify:

- no duplicate financial mutation;
- no lost update;
- expected lock behavior;
- deterministic final state.

============================================================
PAGE 471 — Deadlock handling
============================================================

Trigger controlled deadlock conditions where safe.

Verify:

- retry policy;
- bounded retry count;
- no double application;
- final consistent state.

============================================================
PAGE 472 — Redis connection
============================================================

Verify actual Redis connectivity.

Check:

- ping;
- namespace;
- serialization;
- expiration;
- connection failure behavior.

============================================================
PAGE 473 — Cache isolation
============================================================

Verify player/admin/public cache isolation.

Confirm one player's cached resource cannot appear under another owner.

============================================================
PAGE 474 — Distributed lock
============================================================

Execute canonical locks.

Test:

- same draw;
- same webhook;
- same wallet action;
- same payout.

Verify only one canonical mutation proceeds.

============================================================
PAGE 475 — Queue connection
============================================================

Verify configured queue backend.

Record:

- driver;
- connection;
- queue names;
- worker availability.

============================================================
PAGE 476 — Queue worker boot
============================================================

Start controlled worker.

Verify:

- worker boots;
- job resolves dependencies;
- successful job completes;
- failure goes to expected state.

============================================================
PAGE 477 — Queue retry policy
============================================================

Execute failing synthetic job.

Verify:

- retry count;
- backoff;
- terminal state;
- no duplicate financial action.

============================================================
PAGE 478 — Failed jobs
============================================================

Verify:

```bash
php artisan queue:failed
```

Review controlled test queue failures.

============================================================
PAGE 479 — Failed-job recovery
============================================================

Execute canonical retry/recovery mechanism.

Verify no duplicate financial mutation.

============================================================
PAGE 480 — Scheduler registration
============================================================

Inspect Laravel scheduler.

Verify expected operational commands are registered.

============================================================
PAGE 481 — Scheduler execution
============================================================

Execute scheduler in controlled mode.

Verify:

- commands run;
- locks behave;
- duplicate schedule execution is safe.

============================================================
PAGE 482 — Draw scheduler
============================================================

Test canonical draw opening/closing automation.

No fake official result.

============================================================
PAGE 483 — Draw opening
============================================================

Create controlled draw.

Verify:

- opening state;
- purchase cutoff;
- timestamps;
- timezone;
- audit event.

============================================================
PAGE 484 — Draw closing
============================================================

Verify cutoff transition.

Attempt late purchase.

Expected result must come from canonical service, not frontend.

============================================================
PAGE 485 — Draw settlement queue
============================================================

Queue a controlled settlement.

Verify:

- job creation;
- ownership;
- locking;
- completion;
- audit.

============================================================
PAGE 486 — Result import pipeline
============================================================

Use controlled test source data.

Verify:

- parser;
- validation;
- leading-zero preservation;
- provenance;
- duplicate detection.

============================================================
PAGE 487 — Result provenance
============================================================

Verify source identity and parser metadata.

No fabricated “official” source.

============================================================
PAGE 488 — Result conflict detection
============================================================

Create two controlled conflicting records.

Verify the application refuses silent overwrite.

============================================================
PAGE 489 — Result correction
============================================================

Exercise canonical correction workflow.

Verify:

- audit trail;
- prior state retained;
- corrected state explicit;
- cache invalidation.

============================================================
PAGE 490 — Result certification
============================================================

Execute certification gate.

Verify untrusted/unapproved result cannot become publicly authoritative.

============================================================
PAGE 491 — Result publication
============================================================

Publish only controlled approved result data.

Verify:

- state transition;
- event;
- cache;
- public API.

============================================================
PAGE 492 — Public result API
============================================================

Call public result endpoint.

Verify:

- leading zeros;
- stable schema;
- pagination/filtering;
- no internal fields.

============================================================
PAGE 493 — Result search
============================================================

Test search.

Verify:

- validation;
- pagination;
- rate limiting;
- no cross-lane leakage.

============================================================
PAGE 494 — Result detail
============================================================

Test public detail route.

Verify invalid identifiers return safe response.

============================================================
PAGE 495 — Archive route
============================================================

Verify year archive behavior.

Do not invent year records.

============================================================
PAGE 496 — Historical data activation
============================================================

Verify repository behavior when historical official data is absent.

Expected behavior must be:

- NO_DATA,
- unavailable,
or configured-data state,

not fabricated results.

============================================================
PAGE 497 — Payment provider configuration
============================================================

Inspect provider activation state.

Verify:

- enabled/disabled;
- sandbox/live distinction;
- credential presence only;
- callback URL;
- signature configuration.

Never emit secrets.

============================================================
PAGE 498 — Payment provider connectivity
============================================================

Against provider sandbox only:

- provider health;
- authentication;
- reachable endpoint;
- safe timeout.

No real financial payment unless explicitly controlled.

============================================================
PAGE 499 — Deposit initiation
============================================================

Execute canonical deposit initiation.

Verify:

- authenticated owner;
- server amount;
- fee calculation;
- idempotency;
- provider reference;
- pending state.

============================================================
PAGE 500 — Deposit idempotency
============================================================

Submit identical idempotency request twice.

Expected:

- one financial intent;
- one canonical payment operation;
- no duplicate wallet credit.

============================================================
PAGE 501 — Payment callback
============================================================

Send valid sandbox callback.

Verify:

- signature;
- external ID;
- ownership mapping;
- amount reconciliation;
- status transition.

============================================================
PAGE 502 — Invalid callback
============================================================

Test:

- invalid signature;
- malformed payload;
- wrong amount;
- wrong player;
- unknown external ID.

All must fail closed.

============================================================
PAGE 503 — Webhook replay
============================================================

Replay valid callback.

Verify:

- no second wallet credit;
- no duplicate ledger entry;
- idempotent final state.

============================================================
PAGE 504 — Webhook mismatch
============================================================

Send callback where provider amount/status conflicts with internal state.

Verify no unsafe settlement.

============================================================
PAGE 505 — Payment timeout
============================================================

Exercise provider timeout.

Verify:

- pending/failed state;
- retry behavior;
- no false success.

============================================================
PAGE 506 — Payment reconciliation
============================================================

Execute reconciliation.

Verify:

- internal records;
- provider records;
- mismatch classification;
- audit.

============================================================
PAGE 507 — Wallet opening balance
============================================================

Create controlled test account.

Verify:

- initial balance;
- ledger state;
- reservation state.

============================================================
PAGE 508 — Wallet credit
============================================================

Apply controlled legitimate credit.

Verify:

- wallet;
- ledger;
- audit;
- balance equation.

============================================================
PAGE 509 — Wallet debit
============================================================

Apply controlled debit.

Verify:

- sufficient balance gate;
- atomicity;
- ledger.

============================================================
PAGE 510 — Wallet reservation
============================================================

Create and release controlled hold.

Verify:

- available balance;
- held balance;
- release;
- expiration.

============================================================
PAGE 511 — Withdrawal request
============================================================

Execute canonical withdrawal.

Verify:

- KYC/eligibility;
- balance hold;
- idempotency;
- audit.

============================================================
PAGE 512 — Withdrawal approval
============================================================

Execute controlled approval workflow.

Verify authorization.

============================================================
PAGE 513 — Withdrawal provider transfer
============================================================

Use provider sandbox/mock boundary that is already canonical.

Verify:

- provider operation reference;
- state transition;
- no double payout.

============================================================
PAGE 514 — Withdrawal callback
============================================================

Test valid completion/failure callback.

============================================================
PAGE 515 — Withdrawal replay
============================================================

Replay callback.

Verify no double settlement.

============================================================
PAGE 516 — Withdrawal failure recovery
============================================================

Force controlled failure.

Verify funds are recoverable and state is auditable.

============================================================
PAGE 517 — Financial reconciliation
============================================================

Run:

`FinancialReconciliationService`

and canonical command if available.

Verify:

`wallet = ledger-derived balance`

and all controlled test transactions reconcile.

============================================================
PAGE 518 — Financial audit
============================================================

Verify every controlled financial mutation has appropriate audit evidence.

============================================================
PAGE 519 — Bet purchase activation
============================================================

Execute controlled server-authoritative purchase.

Verify:

- player identity;
- draw validity;
- price;
- fee;
- RG;
- idempotency;
- wallet reservation/debit;
- ticket creation.

============================================================
PAGE 520 — Duplicate bet prevention
============================================================

Repeat identical purchase.

Verify no duplicate purchase.

============================================================
PAGE 521 — Bet concurrency
============================================================

Run simultaneous purchase attempts against same account/draw.

Verify no overspend or duplicate issuance.

============================================================
PAGE 522 — Responsible gaming enforcement
============================================================

Test configured limits.

Verify the canonical server-side gate rejects over-limit behavior.

============================================================
PAGE 523 — Self-exclusion
============================================================

Test excluded account attempting purchase.

Must be rejected.

============================================================
PAGE 524 — Ticket issuance
============================================================

Verify:

- unique ticket reference;
- owner;
- draw;
- price;
- status;
- audit.

============================================================
PAGE 525 — Ticket ownership
============================================================

Player A must not read Player B's ticket.

Test both API and web route.

============================================================
PAGE 526 — Ticket verification
============================================================

Verify public/private ticket verification boundaries.

Opaque references must not allow unauthorized data extraction.

============================================================
PAGE 527 — Prize matching
============================================================

Use controlled deterministic result/ticket fixtures.

Verify backend-authoritative prize matching.

============================================================
PAGE 528 — Prize calculation
============================================================

Verify exact money arithmetic.

No floating-point authoritative settlement.

============================================================
PAGE 529 — Prize settlement
============================================================

Execute controlled settlement.

Verify:

- gross;
- deductions;
- net;
- wallet/ledger;
- claim state;
- audit.

============================================================
PAGE 530 — Prize payout idempotency
============================================================

Repeat payout operation.

Verify no double payment.

============================================================
PAGE 531 — Payout failure
============================================================

Force controlled provider failure.

Verify:

- recoverable state;
- no phantom success;
- audit;
- retry/reconciliation path.

============================================================
PAGE 532 — GLO capability check
============================================================

Inspect current GLO capability.

If `NOT_CONFIGURED`:

- prove fail-closed behavior;
- do not fabricate checkout;
- do not change capability only for tests.

============================================================
PAGE 533 — GLO ticket engine
============================================================

Where executable under current configured capability/test boundary:

verify canonical:

- six-digit range;
- leading zero preservation;
- uniqueness;
- ownership;
- price source of truth.

============================================================
PAGE 534 — GLO prize calculator
============================================================

Execute controlled prize calculation.

Verify exact BCMath behavior and canonical rule source.

============================================================
PAGE 535 — GLO result import
============================================================

Use controlled source fixture.

Verify result validation and publication gate.

============================================================
PAGE 536 — GLO claim
============================================================

Execute only with controlled legitimate winning fixture.

Verify:

- winning ticket;
- owner;
- result;
- claim state.

============================================================
PAGE 537 — GLO duplicate claim
============================================================

Repeat claim.

Verify one canonical claim only.

============================================================
PAGE 538 — GLO age gate
============================================================

Test below/above configured eligibility boundary.

No claim bypass.

============================================================
PAGE 539 — GLO KYC gate
============================================================

Test:

- unverified;
- pending;
- verified;
- rejected.

Only valid configured state may proceed.

============================================================
PAGE 540 — GLO payment hold
============================================================

Verify payment hold blocks payout where required.

============================================================
PAGE 541 — GLO ticket freeze
============================================================

Execute controlled freeze.

Verify ticket becomes correctly restricted.

============================================================
PAGE 542 — GLO freeze expiry
============================================================

Execute expiry/release command.

Verify exact state transitions.

============================================================
PAGE 543 — GLO frozen winner processing
============================================================

Run canonical frozen-winner processing.

No duplicate claim/payout.

============================================================
PAGE 544 — GLO public publication
============================================================

Verify only certified result becomes public.

============================================================
PAGE 545 — Authentication runtime
============================================================

Browser/API test:

- register;
- login;
- logout;
- session rotation.

============================================================
PAGE 546 — Password reset
============================================================

Test:

- valid token;
- expired token;
- reused token;
- cross-account token.

Verify safe responses.

============================================================
PAGE 547 — Authorization / IDOR
============================================================

Create multiple controlled users.

Attempt cross-owner access for:

- wallet;
- deposits;
- withdrawals;
- tickets;
- claims;
- support cases;
- KYC documents;
- profile data.

Every unauthorized access must fail.

============================================================
PAGE 548 — CSRF / security controls
============================================================

Browser POST/PATCH/DELETE actions must enforce expected CSRF behavior.

Verify security middleware.

============================================================
PAGE 549 — MFA and session revocation
============================================================

Test:

- enrollment;
- valid challenge;
- invalid challenge;
- replay;
- session revocation;
- revoked session access.

============================================================
PAGE 550 — FINAL ENTERPRISE RELEASE CANDIDATE GATE
============================================================

This is the decisive gate.

Do NOT call the project production-ready unless the required runtime gates genuinely pass.

Create:

`runtime/pages-451-550-final-acceptance.json`

and:

`FINAL-RELEASE-REPORT.md`

The final acceptance gate MUST aggregate:

1. PHP
2. Composer
3. dependencies
4. Laravel boot
5. config
6. routes
7. views
8. database
9. migrations
10. constraints
11. transactions
12. concurrency
13. Redis
14. locks
15. queues
16. retries
17. scheduler
18. draw lifecycle
19. result lifecycle
20. payment
21. webhook
22. wallet
23. withdrawal
24. reconciliation
25. betting
26. RG
27. ticket
28. prize
29. payout
30. GLO
31. authentication
32. authorization
33. CSRF
34. MFA
35. session security
36. browser E2E
37. accessibility smoke
38. Rust
39. backup
40. restore
41. CI/CD
42. deployment configuration
43. monitoring
44. logging/redaction
45. final audit

============================================================
MANDATORY FINAL VALIDATION COMMANDS
============================================================

Where available and applicable, execute:

```bash
php -v
php -m

composer validate --no-check-publish
composer install --prefer-dist --no-interaction --no-progress
composer check-platform-reqs
composer audit --no-interaction

npm ci
npm audit
npm run build

php artisan about
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache

php artisan migrate:status
php artisan test

php artisan queue:failed
php artisan schedule:list
php artisan route:list

cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

Use project-specific canonical commands already present in the repository as additional gates.

============================================================
BROWSER E2E REQUIREMENT
============================================================

When browser runtime is available, test real journeys:

PUBLIC:
- Home
- Results
- Lottery lane
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
- Deposit pending
- Deposit callback result
- Wallet
- Withdrawal
- Withdrawal status

LOTTERY:
- Buy
- ticket selection
- purchase confirmation
- ticket history
- result
- prize verification
- claim

ADMIN:
- dashboard
- draws
- wallets
- ledger
- reconciliation
- bets
- KYC
- audits
- GLO controls

For every journey test:

- desktop;
- mobile viewport;
- keyboard navigation for critical forms;
- validation errors;
- unauthorized state;
- expired state;
- loading state;
- failure state.

============================================================
ACCESSIBILITY GATE
============================================================

Run browser accessibility smoke tests.

Verify:

- focus visibility;
- keyboard access;
- labels;
- form errors;
- heading hierarchy;
- contrast where automated tooling supports it;
- reduced-motion behavior;
- no horizontal overflow in critical pages.

Do not replace semantic HTML with visual-only elements merely to satisfy appearance.

============================================================
SECURITY RUNTIME GATE
============================================================

Test:

- authentication;
- authorization;
- IDOR;
- CSRF;
- session rotation;
- revocation;
- MFA;
- webhook signature;
- replay;
- rate limiting;
- KYC document access;
- support ownership;
- error redaction;
- log redaction.

Search runtime logs for:

- password;
- APP_KEY;
- token;
- secret;
- authorization header;
- payment credential;
- webhook secret;
- private key;
- KYC document body.

Any real secret leakage is a release blocker.

============================================================
RUST GATE
============================================================

For:

`security/weekly-result-integrity`

require:

```bash
cargo fmt --check
cargo check --locked --all-targets
cargo test --locked
cargo build --release --locked
```

Verify:

- deterministic vectors;
- malformed input rejection;
- leading-zero preservation;
- bounded stdin/stdout contract;
- no network dependency;
- no database dependency;
- expected exit codes.

Do not claim Rust verified without Cargo execution.

============================================================
BACKUP / RESTORE GATE
============================================================

Against controlled staging/test database:

1. create backup;
2. record checksum;
3. restore into fresh DB;
4. boot Laravel against restored DB;
5. verify schema;
6. verify selected controlled financial records;
7. verify lottery records;
8. verify audit records;
9. reconcile wallet/ledger before and after restore.

Artifact:

`runtime/backup-restore-evidence.json`

A backup that cannot be restored is not accepted as release evidence.

============================================================
DEPLOYMENT GATE
============================================================

Validate production deployment instructions.

Verify:

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm ci
npm run build
```

Verify:

- `APP_ENV=production`
- `APP_DEBUG=false`
- manifest exists;
- writable directories exist;
- cache build works;
- required services are declared;
- queue worker is declared;
- scheduler is declared;
- health endpoint exists.

Do not place real production credentials in repository files.

============================================================
CI/CD GATE
============================================================

Execute hosted CI where the repository's CI provider is configured.

The release must NOT rely only on local source inspection.

Required CI evidence includes:

- Composer;
- PHP;
- tests;
- NPM;
- audit;
- build;
- Rust;
- structural gates;
- release gate.

Save CI run identifier and status into:

`runtime/ci-release-evidence.json`

============================================================
FINAL AUDIT UPDATE
============================================================

Update:

`audit.md`

Add Pages 451–550.

Every page must have exactly one factual row.

Each row must include at minimum:

- page;
- title;
- route/command/test;
- controller/service target;
- source of truth;
- security impact;
- financial impact;
- test;
- runtime status;
- external dependency;
- remaining gap.

Do not delete historical rows.

Do not overwrite historical evidence with new unsupported claims.

============================================================
FINAL REPORT FILES
============================================================

Create/update:

- `audit.md`
- `RUNTIME-VERIFICATION-REPORT.md`
- `FINANCIAL-INTEGRITY-REPORT.md`
- `LOTTERY-INTEGRITY-REPORT.md`
- `SECURITY-RUNTIME-REPORT.md`
- `DATABASE-RUNTIME-REPORT.md`
- `RUST-RUNTIME-REPORT.md`
- `CI-CD-VERIFICATION-REPORT.md`
- `FINAL-RELEASE-REPORT.md`
- `PAGES-451-550-MATRICES.md`

Create machine-readable evidence:

- `runtime/pages-451-550-inventory.json`
- `runtime/pages-451-550-final-acceptance.json`
- `runtime/ci-release-evidence.json`
- `runtime/backup-restore-evidence.json`
- all relevant page-scoped runtime evidence files.

============================================================
FINAL RELEASE DECISION RULE
============================================================

Use only one of:

`RELEASE READY`

or

`RELEASE BLOCKED`

Rules:

If any critical gate is:

- BLOCKED,
- FAILED,
- NOT CONFIGURED where required,
- NOT VERIFIED,
- or missing evidence,

the final result MUST be:

`RELEASE BLOCKED`

Do not soften the wording.

Do not estimate that a blocked component “should work”.

Do not convert source implementation into runtime proof.

============================================================
ZIP CREATION RULE
============================================================

Create the final production ZIP ONLY when the required final release gate is green.

Before ZIP:

```bash
git status --short
```

Confirm no unintended files.

Exclude:

- `.env`
- secrets
- private keys
- local credentials
- runtime dumps containing sensitive data
- temporary caches
- node_modules
- vendor
- local database dumps unless explicitly required as a safe artifact
- browser temporary files

Include:

- application source;
- migrations;
- tests;
- scripts;
- configuration examples;
- package manifests;
- lockfiles;
- `public/build`;
- reports;
- runtime evidence;
- CI workflow;
- Rust source/lockfile;
- release documentation.

Run a final secret scan against the ZIP contents.

============================================================
MANDATORY ZIP MANIFEST
============================================================

Generate:

`RELEASE-ZIP-MANIFEST.md`

Include:

- git commit;
- build timestamp;
- application version if existing;
- PHP target;
- Laravel version;
- Node target;
- Rust target;
- DB target;
- Redis requirement;
- test count;
- test result;
- browser E2E count/result;
- security test result;
- Rust result;
- backup/restore result;
- final release decision;
- ZIP SHA-256.

============================================================
VERY IMPORTANT — NO PREMATURE ZIP
============================================================

If final acceptance is still:

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

then DO NOT call the artifact production-ready.

You MAY create a clearly labeled:

`CODE-SNAPSHOT`

ZIP only as a backup snapshot.

Do not name it:

- production-release;
- production-ready;
- final-release;
- stable-release

unless the final acceptance gate genuinely passes.

============================================================
OUTPUT REQUIREMENT
============================================================

At the end of this task report:

1. files changed;
2. files created;
3. commands actually executed;
4. commands blocked;
5. commands failed;
6. tests passed;
7. tests failed;
8. browser tests passed/failed;
9. Rust tests passed/failed;
10. database evidence;
11. finance evidence;
12. lottery evidence;
13. security evidence;
14. backup/restore evidence;
15. CI evidence;
16. final acceptance state;
17. exact remaining blockers;
18. exact ZIP path ONLY if the release gate is green.

Never omit a blocker.

Never fabricate an execution result.

Never summarize a blocked command as “implemented”.

============================================================
SUCCESS CONDITION
============================================================

Pages 451–550 are complete only when the repository has either:

A. genuine runtime evidence proving the relevant release gates, or

B. a precise machine-readable blocker report identifying exactly which external environment/dependency prevents completion.

The ultimate objective is not to increase the page count.

The objective is to turn Pages 1–450's implementation into a genuinely executable, testable, auditable release candidate and, only after passing the final gate, a final production ZIP.