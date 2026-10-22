# THIRD.md — P0 Settlement Engine, Concurrency Safety, and Financial Queue Recovery

## Mission

Implement and verify the repository's production-grade lottery settlement workflow. This prompt is for a coding agent working inside the existing `thai-lottery-ainul-islam` repository. The goal is to make settlement deterministic, auditable, idempotent, concurrency-safe, and recoverable without double-crediting wallets or publishing incorrect prizes.

**Do not assume the repository has the exact files named below.** First inspect the current repository, its routes, models, migrations, services, jobs, queue configuration, tests, and the results of `first.md` and `second.md`. Map the requested responsibilities onto the actual codebase. Preserve existing behavior outside the approved scope, reuse established architecture, and avoid creating duplicate services or parallel accounting paths.

This prompt covers settlement and financial queue recovery only. It does not replace the GLO result-authority boundary from `first.md`, or the database/ledger hardening from `second.md`. Do not weaken either boundary to make tests pass.

## Non-negotiable rules

1. **Inspect before editing.** Record the current Git status and branch, repository tree, PHP/Laravel version, queue drivers, database drivers, ledger posting API, ticket schema, draw/result publication lifecycle, and existing settlement-related tests.
2. **No invented schema.** Read the actual migrations and models before writing queries. Do not guess table names, status values, monetary precision, ticket identity, draw IDs, or prize rules.
3. **No unsafe balance mutation.** Never settle by directly incrementing/decrementing a wallet balance when the project has a ledger posting service. Use the authoritative, transactional double-entry ledger API established by the repository and `second.md`.
4. **No prize-rule invention.** Use only an existing, documented, approved prize schedule and result interpretation. If a source-of-truth table or signed-off rules are absent, fail closed and report the missing decision; do not manufacture payout amounts, tax rules, rounding policy, or eligibility.
5. **Idempotency is mandatory.** Re-running a settlement, retrying a queue job, redelivering an event, or processing concurrent workers must never duplicate a payout or ticket settlement.
6. **Use database transactions and appropriate locks.** Protect the draw settlement state, ticket rows, settlement records, and ledger idempotency boundary against concurrent execution. Select lock order consistently to reduce deadlocks.
7. **Fail closed.** Do not settle an unpublished, unverified, revoked, superseded, or otherwise ineligible result. Respect the authoritative result publication boundary established in `first.md`.
8. **No destructive shortcuts.** Do not disable tests, delete assertions, bypass middleware, loosen production guards, auto-delete records, or rewrite historical ledger entries to get a green run.
9. **Do not leak secrets or personal data.** Queue payloads and logs must not include credentials, payment tokens, full identity documents, or unnecessary user data.
10. **No placeholders in deliverables.** No `...`, “existing code”, “same as above”, or omitted file bodies in any file you create. In reports, clearly distinguish implemented, tested, blocked, and not implemented.
11. **Keep scope disciplined.** Do not redesign public UI or implement provider fallback/WebSockets or the final Docker/Rust production pass here.
12. **Never claim tests passed unless they were actually run.** Record exact commands, exit codes, environment limitations, and failure counts.

## Phase 1 — Baseline and dependency map

Before editing:

- Run `git status --short --branch` and record whether the working tree was already dirty. Do not overwrite or revert unrelated user changes.
- Inspect `composer.json`, `composer.lock`, `phpunit.xml*`, `.env.example` (never expose `.env` values), queue configuration, database configuration, relevant migrations, and the current CI workflow.
- Find all code paths that publish or revoke draw results; calculate ticket matches and prize tiers; determine eligible/sold/void/refunded tickets; mark tickets won/lost/settled; create payout or settlement records; credit wallets or post ledger entries; dispatch and retry settlement jobs; process failed jobs and dead-letter recovery; and issue notifications or user-visible history.
- Search for duplicate settlement implementations, direct wallet balance writes, unguarded status transitions, and queue jobs that can be dispatched before transaction commit.
- Identify existing service names and use them where appropriate. Do not create a second settlement engine if one already exists.
- Establish a baseline by running the narrowest relevant tests first, then the full available test suite if practical. Preserve baseline output.

Create or update `THIRD-SETTLEMENT-BASELINE.md` containing repository revision and dirty-state summary; current settlement flow and relevant paths; current ledger posting entry point; result-publication trust boundary; queue backend and retry configuration; baseline test commands/results; and known blockers/unverified assumptions.

## Phase 2 — Authoritative settlement lifecycle

Implement or harden the existing settlement flow so the state machine is explicit and monotonic. Adapt names to the actual domain, but document transitions and enforce them in code. A typical lifecycle may include `pending`, `processing`, `settled`, `failed`, and, where supported, `reversed` or `corrected`. Do not add statuses that conflict with established enums or constraints. If the state machine differs, preserve it and document the mapping.

Requirements:

1. Settlement may begin only after the authoritative draw result is successfully published and is still eligible for settlement under the project's correction/revocation rules.
2. Capture the immutable result identifier/version or equivalent provenance in the settlement record.
3. Give each settlement a stable business key, such as actual draw/result version plus ticket or ticket-group identity. Use the real schema; do not concatenate ambiguous identifiers.
4. Guard transitions with conditional updates, row locks, or equivalent database-enforced concurrency control.
5. Reject invalid transitions explicitly and audit them.
6. Result publication and settlement dispatch must honor transaction commit boundaries. Use after-commit dispatch or a transactional outbox where supported. Never dispatch work that can run against uncommitted state.
7. Define behavior for zero eligible tickets, fully unsold draws, void tickets, refunded tickets, duplicate tickets, cancelled orders, and partially processed draws using actual business rules. If a policy is missing, block settlement and report the required decision.
8. Retries must resume safely from committed checkpoints and never reapply financial effects.

## Phase 3 — Prize calculation and settlement record

Use the authoritative prize calculator and published rules. Do not copy prize formulas into jobs/controllers if a shared domain service already exists.

- Keep calculation deterministic and side-effect-free where possible.
- Use exact decimal arithmetic appropriate to the project/database. Never use binary floating-point for money.
- Apply approved currency, precision, rounding, withholding/tax, and eligibility rules exactly as documented.
- Separate calculation from ledger posting and status mutation so outcomes can be tested before money is posted.
- Persist enough information to reproduce an outcome: ticket/draw references, result version, a privacy-safe match summary, prize tier/rule version, gross amount, deductions, net amount, currency, and calculation/rule version, as supported by the real schema and data-minimization requirements.
- Do not store sensitive information unnecessarily or duplicate full identity/KYC data into settlement rows.
- Ensure every eligible ticket receives exactly one terminal outcome for a given settlement version, including losing tickets where the domain requires explicit settlement history.
- If the source-of-truth prize schedule is missing or ambiguous, fail closed and document the blocker; do not hardcode guessed payouts.

## Phase 4 — Atomic financial posting and idempotency

Enforce these at database and service layers where supported:

1. Coordinate settlement outcome creation, ticket state transition, payout intent, and ledger posting transactionally. For external side effects, use an outbox or idempotent post-commit delivery pattern.
2. Use a stable payout idempotency key derived from canonical business identifiers and settlement version. Never generate a new random key for every retry.
3. Add or verify unique constraints matching the actual model, such as one outcome per ticket per result version and one ledger posting per payout key. Before adding constraints, detect existing duplicates and provide safe preflight behavior. Never silently delete or merge financial rows.
4. Repeated processing returns the committed outcome instead of posting again.
5. If ledger posting fails, do not mark payout as financially settled. If ledger posting succeeds but a later action fails, retries detect the existing ledger transaction and finish state transitions without a second credit.
6. Avoid holding database locks during non-transactional network calls. Prefer an outbox for notifications and external work.
7. Preserve `second.md` invariants: balanced entries, currency consistency, append-only posted history, temporal fields, and compensating reversals for corrections.
8. Make transaction isolation and lock strategy explicit. Use `lockForUpdate()` or database-native equivalents where appropriate, plus unique constraints as the final guard. An application mutex alone is not enough across multiple servers.
9. Avoid locking unbounded numbers of rows in one transaction. Process bounded chunks in stable order with durable checkpoints.
10. Handle deadlocks/serialization failures with bounded retries and jitter only where supported. Exhausted retries must be observable and recoverable.

## Phase 5 — Queue job reliability, retry, and dead-letter recovery

Harden existing queue jobs rather than creating overlapping jobs.

- Define explicit `tries`, `timeout`, `backoff`, and uniqueness/overlap policy consistent with the installed Laravel version and queue backend.
- Ensure uniqueness keys identify the real settlement scope and do not deduplicate different draws/result versions.
- Use `ShouldBeUnique`, `WithoutOverlapping`, or equivalent middleware only when compatible with the actual queue driver and lock backend. Document driver limitations.
- Ensure locks have safe expiry and are released on success, exception, timeout, and worker termination as far as the framework permits.
- Keep job payloads minimal: pass durable IDs rather than serialized ORM graphs or secrets.
- Use `afterCommit` dispatch where applicable.
- Do not swallow exceptions or mark a job successful while required ledger effects are missing.
- Log correlation IDs, job ID, draw/result version, attempt number, and sanitized error classification without exposing personal or financial secrets.
- Distinguish transient failures (deadlocks, temporary connection issues) from terminal domain failures (unpublished result, missing prize rules, invalid state).
- Do not endlessly retry terminal failures. Route them to a controlled failed state/dead-letter process with a safe operator workflow.

Dead-letter and recovery requirements:

1. Inventory the failed-jobs table, queue dashboard, alerting, retry commands, and operator access controls.
2. Implement controlled recovery using existing framework conventions. Validate current draw/result eligibility and idempotency before replay.
3. Record who initiated manual replay, when, why, which job/settlement scope was selected, and replay outcome, using the existing audit system.
4. Prevent user-facing endpoints from triggering privileged settlement replay.
5. Never automatically replay all failed financial jobs without bounded batches, rate limits, eligibility checks, and an audit trail.
6. Document safe inspect/retry procedures and the checks required before replaying payout work.
7. If no authorized operator/permission model or audit path exists, do not add an unauthenticated endpoint; report the missing prerequisite.

## Phase 6 — Correction, cancellation, and recovery semantics

Use only correction policy already established by the application and authoritative result workflow.

- A result correction/revocation after settlement must not overwrite historical settlement rows or posted ledger entries.
- Use a compensating settlement/reversal tied to the original settlement and ledger transaction, with authorization and an audit reason.
- Recalculate only after the corrected result has been validly published and policy allows it.
- Ensure correction cannot race an in-flight settlement. Use explicit transitions, locks, and versioned business keys.
- If correction policy is undefined, do not invent it. Add a fail-closed guard and document the precise decision needed from the product/compliance owner.
- Repeated reversal commands must be idempotent and cannot reverse the same payout twice.
- Never delete a payout, settlement, or ledger entry to fix an outcome.

## Phase 7 — API, authorization, and user history

Inspect all existing settlement-related routes and controllers.

- Keep public/user routes read-only for settlement status/history unless the current product explicitly supports another safe operation.
- Require existing authorization policies/roles/permissions for admin settlement actions and manual replay.
- Validate identifiers and state transitions server-side; never trust a user-supplied prize amount, ticket outcome, draw result, ledger ID, or idempotency key.
- Return stable, non-sensitive API errors for pending, processing, settled, and unavailable states without leaking stack traces.
- Ensure users can read only their own ticket/settlement/payout history unless a properly authorized staff role is used.
- Bound pagination and use deterministic ordering.
- Keep financial history consistent with the ledger source of truth. Do not display a payout as completed until its ledger outcome is committed.
- Preserve API compatibility where possible; document response-contract changes and add contract tests if needed.
- Do not redesign the UI in this prompt.

## Phase 8 — Observability and reconciliation

Use existing logging, metrics, health checks, and alerting conventions. Add structured events/metrics where supported for settlement queued/started/completed/skipped/failed; eligible and processed ticket counts; payout counts and totals by currency without per-user leakage; queue age/retry/dead-letter counts; idempotent replay detection; ledger-posting failures; result-version mismatch/revocation; and reconciliation mismatches.

Add or extend a read-only reconciliation command/service. It must report at minimum:

- settlements without matching ledger postings;
- ledger payout postings without matching settlement records;
- duplicate business keys/idempotency keys;
- mismatched amounts/currencies;
- tickets marked settled without a committed financial outcome where payout is required;
- payouts posted against an ineligible or mismatched result version.

The reconciliation tool must not auto-repair financial records. Repairs require a separately authorized, reviewed, auditable operation.

## Phase 9 — Tests that prove the invariants

Use the actual repository architecture and database capabilities. Do not replace meaningful integration tests with mocks alone.

### Domain and lifecycle
- unpublished result cannot settle;
- authoritative published result can settle;
- revoked/superseded/ineligible result cannot begin new settlement;
- unknown/missing prize schedule fails closed;
- invalid state transition is rejected and audited;
- repeated settlement request returns the existing outcome.

### Financial correctness
- winning ticket creates the correct ledger-backed payout using approved rules;
- losing ticket gets its correct terminal outcome if required;
- ledger failure does not mark payout completed;
- retry after successful ledger posting does not double-credit;
- duplicate ticket/job/event cannot duplicate payout;
- result correction uses compensating reversal, never mutates posted history;
- currency and decimal precision are preserved;
- user history does not show a completed payout before ledger commit.

### Concurrency and queue
- two workers settling the same draw/ticket concurrently produce one settlement and at most one payout;
- duplicate requests hit the database uniqueness guard safely;
- deadlock/serialization retry is bounded;
- after-commit dispatch does not run before commit;
- transient errors retry according to policy;
- terminal domain errors do not retry indefinitely;
- failed-job replay checks eligibility and remains idempotent;
- lock expiry/release behavior is tested as supported by the actual driver.

### Authorization and reconciliation
- unauthorized users/staff cannot trigger settlement or replay;
- a user cannot access another user's settlement history;
- reconciliation detects injected inconsistencies without mutating data;
- correction/reversal cannot be performed twice;
- audit records capture operator-triggered recovery.

Run integration tests against the production database engine (PostgreSQL if configured) where available. SQLite-only success does not prove PostgreSQL row locking, constraints, or isolation behavior. If PostgreSQL or required extensions are unavailable, state that explicitly and do not claim concurrency is fully verified.

## Phase 10 — Migration and deployment safety

- Review every migration against actual schema and production-data risks.
- Add preflight checks for duplicate business keys or invalid legacy states before unique constraints.
- Do not silently normalize amounts or rewrite historical outcomes.
- Prefer additive, backward-compatible migrations and staged rollout when needed.
- Rollback guidance must not imply posted financial history can safely be deleted or reversed by schema rollback.
- Verify worker restart/deploy instructions follow repository conventions.
- Confirm queue serialization remains compatible during rolling deploys or document the required drain/restart sequence.
- Update runbooks for worker concurrency, retry policy, failed-job review, reconciliation, and result correction.

## Required deliverables

Create or update these artifacts with complete usable content:

1. `THIRD-SETTLEMENT-BASELINE.md` — baseline and dependency map.
2. `THIRD-SETTLEMENT-IMPLEMENTATION-REPORT.md` — implementation details, actual files changed, invariant mapping, tests, exact commands/results, migration/deployment notes, unresolved business decisions.
3. A settlement recovery runbook in the repository's existing documentation location, following its naming conventions. Cover safe inspection, reconciliation, manual replay, reversal/correction escalation, and rollback limitations.
4. Automated tests for the required scenarios in the established test structure.
5. Required implementation/migrations/config changes, limited to this prompt's scope.

## Required report format

The final report must include:

- **Baseline:** commit/revision, pre-existing dirty state, baseline test status.
- **Flow map:** actual result-publication → settlement → ledger-posting → history path, with exact file paths.
- **Changed files:** every modified/created file and why it changed.
- **Idempotency design:** exact business key and database constraint/service boundary.
- **Concurrency design:** locks, unique constraints, transaction boundaries, lock order, retry behavior.
- **Financial invariants:** authoritative ledger use and preservation of posted history.
- **Queue reliability:** retry/backoff/uniqueness, dead-letter handling, operator authorization/audit.
- **Reconciliation:** command/entry point, checks performed, and confirmation it is read-only.
- **Tests:** exact commands, exit codes, counts, skips, and environment limitations.
- **Migration safety:** preflight checks, deployment order, rollback cautions.
- **Open decisions:** exact missing product/compliance decisions.
- **Readiness assessment:** separate percentages for code implemented, automated-test coverage, and production verification. State each percentage's denominator/method; do not use one unsupported “production-ready” percentage.
- **Known limitations:** anything not implemented or not verified.

## Required tree output

Include a copy-pasteable tree of actual files changed or added. Every file and directory line must have a trailing `#` comment explaining its role. Replace the illustrative paths below with the real repository paths and real files; do not create these exact names merely because they appear here.

```text
app/
└── Services/
    └── Settlement/
        ├── SettlementOrchestrator.php  # Coordinates the authoritative settlement lifecycle
        └── SettlementReconciliationService.php  # Performs read-only consistency checks
tests/
└── Feature/
    └── Settlement/
        └── SettlementIdempotencyTest.php  # Proves repeated and concurrent execution cannot double-pay
docs/
└── runbooks/
    └── settlement-recovery.md  # Safe operator recovery and reconciliation procedures
```

Every real file in the submitted tree must have its own `#` explanation. Do not omit tests, migrations, config files, commands, or documentation that changed.

## Final verification sequence

1. Run configured formatting/static analysis commands.
2. Run all new or changed tests.
3. Run relevant result-publication, ticket-purchase, wallet/ledger, and queue regression suites.
4. Run the full suite if practical.
5. Run database-specific integration tests against PostgreSQL where available.
6. Run migrations against a disposable database matching production; never run destructive migration commands against shared/production data.
7. Run reconciliation against test fixtures and prove it reports inconsistencies without modifying records.
8. Inspect `git diff --check`, the full diff, and `git status --short`.
9. Search for bypasses: direct wallet balance mutation in settlement code, floating-point money calculations, unguarded payout dispatch, bypassable result-status checks, and duplicate settlement writers.
10. For every unrun test/check, record the exact command and reason. Do not call an unrun check “passed.”

Useful search examples (adapt to available tools and do not treat search output alone as proof):

```bash
git status --short --branch
git diff --check
rg -n "settle|settlement|payout|prize|ShouldBeUnique|WithoutOverlapping" app routes database tests
rg -n "balance\s*[+\-]?=|increment\(|decrement\(|float|double" app
rg -n "dispatch\(|afterCommit|DB::transaction|lockForUpdate" app
```

## Completion gate

Do not declare this prompt complete unless all of the following are true:

- settlement cannot run against an ineligible or unpublished result;
- payout calculations use an approved authoritative rule source;
- every payout uses the authoritative ledger API and stable idempotency;
- duplicate and concurrent execution cannot create a second payout;
- retries safely resume after partial failure;
- posted ledger history is never overwritten to correct an outcome;
- privileged replay/reversal actions are authorized and audited;
- reconciliation identifies important settlement/ledger mismatches without modifying data;
- tests cover the invariants and execution limitations are honestly reported;
- the report and commented file tree are complete.

If a missing source-of-truth rule, schema constraint, operator permission, or correction policy prevents safe implementation, implement the safe fail-closed guard where possible and mark the task **BLOCKED** for that exact decision. Do not fake completion, invent policy, or weaken the financial boundary.

## Scope boundary for the five-prompt sequence

- `first.md`: authoritative GLO result publication and maker-checker boundary.
- `second.md`: database financial integrity, bi-temporal ledger, and append-only audit.
- **`third.md` (this prompt): settlement, payout idempotency, queue recovery, reconciliation.**
- `fourth.md`: live results, provider fallback, WebSocket/Reverb event delivery.
- `fifth.md`: production Docker/Redis/PostgreSQL/Rust worker setup, CI, load, and security verification.

Proceed incrementally. Inspect first, make the smallest safe changes, test each invariant, and report the truth.
