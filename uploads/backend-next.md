# PROMPT 1 — BACKEND-NEXT: Complete Backend & Financial Logic to a Verified Release Gate

> **Copy/paste this entire prompt into the coding agent with the repository open.**
>
> **Repository:** `thai-lottery-ainul-islam-main`  
> **Phase:** `1 of N — Backend + Financial Integrity ONLY`  
> **Priority:** P0 correctness, money safety, API contracts, migrations, queue/worker behavior, security, and production runtime.  
> **Do not start the frontend redesign in this phase.**

---

## 0. Mission and non-negotiable rules

You are the Principal Laravel/PHP Financial Systems Engineer, Application Security Lead, Database Reliability Engineer, and Rust Settlement Runtime Engineer assigned to finish the backend of this repository.

Your job is to **inspect the actual checked-out repository, repair real defects, implement missing backend business logic, wire the routes and dependencies, add regression tests, execute validation, and report evidence**. This is not a documentation-only audit and not a scaffold-generation task.

### Hard rules

1. **Work on the existing repository, not on a newly imagined project.** Read the real source files before changing them. Preserve sound existing implementation, naming, architecture, authorization, audit logging, business contracts, and tests. Do not blindly replace working code with a fresh implementation.
2. **Backend/financial scope only.** Do not redesign pages, add decorative UI, rewrite Tailwind/CSS, change layouts, rebuild the public brand, or start `frontend-next`. Backend-relevant Blade/API bugs may be fixed only where they block correctness/security; do not spend this phase on appearance.
3. **Never say “100% complete” because files exist.** Completion means all required release gates below have actual passing evidence, no known P0/P1 backend or financial defect remains, and all unverified external/provider/production items are explicitly listed. No fake percentages, fabricated test results, placeholder reports, or “works” claims based only on static inspection.
4. **No placeholders or truncation.** Do not write `...`, `TODO implement later`, pseudocode, empty methods, fake success responses, hardcoded balances, mock financial success in production code, dummy secrets, or “existing code here”. Every new or replaced source file must be complete and runnable.
5. **Do not bypass security to make tests pass.** No disabling authentication, CSRF, authorization, KYC/age/responsible-gaming gates, signature checks, idempotency, ledger balancing, approval controls, migration guards, or concurrency tests. Fix the underlying reason instead.
6. **Money is exact.** Do not use `float`/`double` for currency or prize calculations. Use the established `Money`/BCMath/decimal conventions in PHP and fixed-point decimal in Rust. Explicitly handle currency, scale, rounding policy, integer overflow, and zero/negative amounts.
7. **The ledger is authoritative.** Do not directly mutate wallet balance columns as a substitute for a ledger posting. Do not create a second source of truth. Every financial state change must have one durable business identity, one atomic transaction boundary, balanced journal entries, immutable original evidence, and safe replay behavior.
8. **Every financial write is concurrency-safe.** Design for duplicate requests, provider retries, multiple PHP workers, multiple Rust workers, transaction retries, deadlocks, worker crashes, network timeouts, and out-of-order events. Application-level “check then insert” without a database uniqueness/lock guarantee is not sufficient.
9. **Migrations are forward-safe and honest.** Do not rewrite an already-deployed migration destructively. Add a forward migration for schema fixes unless you prove the migration has never been deployed and the project policy permits editing it. Ensure `up` and `down` are coherent, indexes are portable to the chosen engine, and existing data is preserved.
10. **One change at a time.** Work in the sequence below. After each milestone, run the narrow tests/lints relevant to that change, fix regressions before continuing, and keep a changelog. Do not change frontend code while backend milestones are in progress.
11. **Inspect every reference to missing classes.** Before adding a class, search for all callers, bindings, routes, jobs, migrations, factories, policies, and tests. Avoid duplicate services and competing money paths. Wire one canonical implementation into every intended call path.
12. **Do not invent official lottery or payment facts.** Preserve existing approved business constants only when source/config/tests establish them. If ticket price, prize matrix, withholding/tax, source authority, provider signing algorithm, or settlement rule lacks a trustworthy source of truth, fail closed and document the required owner decision instead of guessing.
13. **No destructive commands without inspecting effects.** Do not run `migrate:fresh`, `db:wipe`, database resets, mass deletion, history rewrites, or force pushes against any non-ephemeral database. Use disposable test DBs for destructive checks.
14. **Do not stop after writing the report.** Make code changes, add tests, run checks available in the environment, and show precise results. If the environment blocks a check, report the exact command and blocker; never mark it passed.

---

## 1. Repository-specific baseline already observed — re-verify before changing

Use these as high-priority leads, not as a substitute for examining current files:

- Repository stack includes Laravel 12 / PHP 8.4, MySQL deployment configuration, Redis-backed queues/cache/locks, a separate Rust settlement worker, payment gateways, wallet/ledger services, withdrawal and payout processes, draw/result services, GLO L6 purchase/settlement services, and a large PHPUnit suite.
- `composer.json` declares PHP `^8.4`, while at least one CI workflow job configures PHP 8.3. Align CI, local instructions, and runtime images to one supported declared platform; do not weaken the composer requirement just to get a green job.
- `lang/en/account_services.php` has parse errors: several adjacent translation entries are missing commas. Repair syntax without redesigning copy, then lint all PHP and translation PHP files.
- The transfer controller references classes that were absent in the supplied archive: `App\Exceptions\BalanceTransferException`, `App\Models\BalanceTransfer`, `App\Services\Transfer\BalanceTransferService`, `TransferLimitService`, `TransferPanelService`, and `BalanceTransferReversalService`. Search for a newer equivalent, all routes, all tests, and all call sites first. If the feature remains part of the product contract, implement it fully instead of hiding/removing the controller.
- The payment webhook design is intentionally split into replay protection and provider-specific signature verification. Do not collapse it into one generic HMAC protocol. Stripe, bKash, Nagad, SCB, KBank, crypto, and bank-transfer integration paths must each be checked against their actual provider contract and current code. The current generic middleware must not claim it verified a provider signature unless it really did.
- `docker/php/Dockerfile` currently starts `docker-php-entrypoint` directly even though `docker/php/laravel-entrypoint.sh` contains the `*_FILE` secret loader. Check and correct the production startup chain so required secrets are loaded before PHP-FPM starts and missing production secrets fail closed.
- The PHP image health check references `php-fpm-healthcheck`, which was not found in the project tree. Replace it with a real available health check or add/install a deterministic script and validate it in a container build.
- `docker-compose.production.yml` explicitly says MySQL is the practical engine to ship on first because migrations/tests and some reconciliation SQL are MySQL-oriented. However, the Rust settlement worker currently declares SQLx `postgres`, while production Compose sets `DB_CONNECTION=mysql`; the worker database protocol, schema, SQL syntax, migrations, lockfile, and deployment configuration must be brought into alignment. Do not silently migrate the whole application to PostgreSQL in this phase. Start with MySQL as the deployment source of truth unless source inspection proves an unavoidable blocker, in which case document the architecture decision and implement/test the full migration consistently.
- `docker/rust/Dockerfile` references `runtime/settlement-worker/Cargo.lock` and a `runtime/settlement-worker/tests` directory that were absent in the archive. Make the worker build reproducible and remove shell redirection/`|| true` tricks from Docker `COPY` logic. Do not generate fake test files just to satisfy a `COPY`.
- Transfer API route registration and the controller call paths need reconciliation. `routes/api.php` includes a profile transfer handler and there is also a dedicated transfer controller; ensure there is one documented canonical endpoint contract and all intended APIs resolve to implemented services.
- Existing source already has many strong financial abstractions/tests, including ledger posting boundaries, idempotency, financial reversals, wallet reservations/holds, payment webhook replay protection, withdrawal approval/completion, GLO four-eyes write-boundary tests, settlement guards, and race tests. Read and preserve these; do not replace them wholesale or mark them absent because an old audit artifact says so.
- Prior checks could not complete the full PHPUnit suite because `vendor/` was absent, and the Vite build failed in the current environment because Vite dependencies were not installed correctly. The PWA harness also had a `cache.addAll is not a function` failure. The frontend build/PWA repair belongs primarily to `frontend-next`; only address it here if it prevents the backend release image from building or serving backend routes.

**Important:** old markdown audit reports are not ground truth. Verify each statement against the current tree, `composer.lock`, runtime configuration, and executable test results. Correct stale claims rather than preserving them.

---

## 2. Work order — execute in this exact sequence

### Milestone A — baseline inventory and safety snapshot

1. Record the repository commit/hash, `git status`, PHP version, Composer version, Node version, Rust/Cargo version, DB drivers available, and whether dependencies are installed.
2. Run `git status --short`; do not overwrite uncommitted user changes. If the tree contains pre-existing edits, inventory them and work around them safely.
3. Inventory all backend surfaces: routes; controllers; FormRequests; policies/gates; middleware; services; jobs/listeners/events; models/observers; commands/scheduler; migrations/factories/seeders; payment gateways; financial configuration; Rust worker; Docker/Compose/CI. Search all dependency injection targets and class-string references for unresolved classes.
4. Build a table of `path | finding | severity | evidence | fix | regression test`. Distinguish verified defects, risky design, missing evidence, and stale audit assertions.
5. Save the baseline test output before modifications. If vendor/dependencies cannot be installed, continue with static checks, but explicitly mark runtime checks as blocked.

### Milestone B — restore syntax, autoload, configuration, and app boot

1. Run PHP lint across every tracked project `.php` file, including `lang/`, `resources/`, migrations, tests, routes, config, bootstrap, console, and factories. Do not restrict the scan to `app/` only.
2. Fix every parse error first; specifically check commas in `lang/en/account_services.php`. Verify language files keep parity of required keys across `en`, `bn`, and `th` where those keys exist.
3. Run `composer validate --strict` if compatible with repository policy; run `composer dump-autoload --optimize` after installation. Do not alter dependency constraints without evidence.
4. Resolve PHP 8.3/8.4 drift by aligning `.github/workflows/ci.yml`, `financial-concurrency.yml`, `security.yml`, Docker args, readme/runtime instructions and actual `composer.json` platform. If a matrix intentionally tests unsupported PHP versions, configure Composer platform requirements appropriately and document it; do not lie about production PHP.
5. Verify required extensions in both CI and runtime images (`bcmath`, `intl`, `pdo_mysql`, `redis`, `sodium`, `zip`, and any actually used extensions). Fail early with useful diagnostics when an extension is missing.
6. Verify Laravel app boot, config caching, route cache, event cache, and schedule registration on a clean disposable environment. Do not commit machine-specific cached config or secret-bearing artifacts.

### Milestone C — define one canonical money/ledger architecture

Read and trace these before writing financial code:

- `app/Services/Finance/Money.php`
- `app/Services/Finance/WalletService.php`
- `app/Services/Finance/WalletLockService.php`
- `app/Services/Finance/WalletHoldService.php`
- `app/Services/Finance/WalletReservationService.php`
- `app/Services/Finance/LedgerPostingService.php`
- `app/Services/Finance/LedgerPostingWriteBoundary.php`
- `app/Services/Finance/LedgerPostingInputWitness.php`
- `app/Services/Finance/LedgerEntryMoneyPreflight.php`
- `app/Services/Finance/LedgerEntryPayloadPreflight.php`
- `app/Services/Finance/LedgerBalanceValidator.php`
- `app/Services/Finance/LedgerAccountBalanceProjector.php`
- `app/Services/Finance/FinancialTransactionService.php`
- `app/Services/Finance/FinancialStateTransitionService.php`
- `app/Services/Finance/IdempotencyService.php`
- `app/Services/Finance/FinancialReversalService.php`
- `app/Services/Finance/ReversalIntegrityGuard.php`
- `app/Services/Finance/LedgerReconciliationService.php`
- `app/Services/Finance/FinancialReconciliationService.php`
- `app/Services/Finance/ExactReconciliationReader.php`
- `app/Models/Wallet.php`, `LedgerAccount.php`, `LedgerEntry.php`, `LedgerTransaction.php`, `FinancialTransaction.php`, `IdempotencyRecord.php`, and `WalletLedger.php`
- all migrations that create or evolve wallets, financial transactions, ledger accounts/entries, idempotency identity, and previous-hash uniqueness.

Requirements:

1. Write or preserve an architecture decision record explaining the authoritative money ledger, wallet balance projection semantics, currency model, transaction identity, debit/credit convention, reversal convention, and allowed write boundary.
2. Validate every journal posting: at least the required balanced entries, same currency per journal unless explicitly modelled FX legs, nonzero valid amount, valid account ownership, unique business identity, and database-level prevention of duplicate postings.
3. Preserve append-only financial evidence. Correct mistakes with a linked compensating reversal, never by editing/deleting the original posted journal.
4. Keep wallet available balance, locked/held balance, pending withdrawals, and ledger-derived balances internally consistent. Ensure reservations and holds cannot be spent twice or released twice.
5. Prevent negative balances unless a specific account type/business rule permits it and the rule is explicit, tested, and authorized.
6. Protect all critical reads/writes with an intentional lock order. Document that order and test two simultaneous operations that would otherwise deadlock or overspend.
7. Retry only transient DB errors, with bounded attempts and jitter/backoff as appropriate. Do not retry business rejections. Ensure a retried transaction does not repeat non-transactional external side effects.
8. Never treat Redis as the sole durable source of financial idempotency or webhook replay protection. Redis may reduce load; durable uniqueness belongs in the transactional database.
9. Check decimal precision/scale at HTTP validation, domain service, database schema, ledger posting, Rust DTO, and API serialization boundaries. Do not serialize money as binary floats.
10. Add query/index checks for every high-volume foreign key, status/time scan, provider event identity, settlement identity, and reconciliation path; avoid speculative indexes without examining actual query predicates.

### Milestone D — payment, provider webhooks, deposits, refunds, and reconciliation

Trace the entire lifecycle from request to provider to webhook to wallet ledger to reconciliation. Never rely on a browser redirect as proof of payment.

Inspect and repair at minimum:

- `app/Http/Middleware/VerifyWebhookSignature.php`
- `app/Http/Controllers/Api/V1/PaymentWebhookController.php`
- `app/Http/Controllers/Api/V1/DepositWebhookController.php`
- `app/Http/Controllers/Api/V1/Payment/KBankWebhookController.php`
- `app/Http/Controllers/Api/V1/Payment/ScbWebhookController.php`
- `app/Services/Payment/PaymentWebhookVerificationService.php`
- `app/Services/Payment/PaymentWebhookService.php`
- `app/Services/Payment/ProcessPaymentWebhookService.php`
- `app/Services/Payment/WebhookReplayGuardService.php`
- `app/Services/Payment/PaymentWebhookBindingService.php`
- `app/Services/Payment/Drivers/StripeGateway.php`
- `app/Services/Payment/Drivers/NagadGateway.php`
- `app/Services/Payment/Drivers/BkashGateway.php`
- `app/Services/Payment/Drivers/BankTransferGateway.php`
- `app/Services/Payment/Drivers/CryptoGateway.php`
- `app/Services/Payment/ScbOpenApiWebhookService.php`
- `app/Services/Payment/KasikornBankWebhookService.php`
- `app/Services/Deposit/DepositProcessingService.php`
- `app/Services/Finance/DepositService.php`
- `app/Services/Finance/DepositAdmission.php`
- `app/Services/Finance/DepositIntegrityGuard.php`
- `app/Services/Finance/DepositApprovalService.php`
- `app/Services/Finance/DepositCompletionService.php`
- `app/Services/Finance/RefundService.php`
- `app/Services/Finance/FinancialReversalService.php`
- `app/Services/Payment/PaymentReconciliationService.php`
- webhook/receipt/replay-guard/payment/deposit migrations and related models.

Required behavior:

1. Verify each provider signature using the provider’s actual documented protocol, using the raw request body and required headers exactly as specified. For Stripe, use the official SDK verification mechanism where appropriate instead of a homemade approximation. For each Thai/other gateway, inspect the current authoritative provider contract and code. If network/source verification is not available, do not invent an algorithm: fail closed, isolate the adapter, and record the exact missing protocol evidence.
2. Make verification gateway-specific. No “one HMAC recipe works for all providers” assumptions. Timestamp/nonce rules must apply only when the provider protocol supplies those fields. Compare MACs in constant time.
3. Separate authentication of a webhook from idempotent processing of the event. A legitimately re-delivered, previously processed event should receive the provider-appropriate successful acknowledgement without creating a duplicate credit; malformed/invalid signatures must be rejected and never mutate money.
4. Enforce durable uniqueness on provider + provider event ID / business reference, use an atomic claim, persist a redacted/encrypted audit receipt, and handle concurrent duplicate delivery in two containers. A cache failure must not open the door to duplicate credit.
5. Confirm expected account/customer, payment intent/reference, amount, currency, provider status, and allowed state transition against the database record before crediting. The provider event’s amount alone is not sufficient.
6. Apply financial state transition + ledger post + idempotency receipt transactionally. Queue only after committed durable state, using an outbox or after-commit pattern so a crash cannot lose an obligation silently.
7. Handle out-of-order success/failure/refund/dispute events with a state machine and compensating reversal. A late failure may not overwrite a completed payment; a refund may not credit the wallet again.
8. Validate minimum/maximum deposit, precision, currency, account ownership, KYC/risk/AML gates, rate limits, and authorization server-side. Return stable API error codes without leaking secrets, signatures, raw payloads, or PII.
9. Verify reconciliation can detect “provider says paid, app says pending”, “app credited, provider failed”, mismatched amount/currency, duplicate provider refs, orphaned webhook events, and unclaimed webhook obligations. Provide an auditable repair workflow; do not silently auto-credit an ambiguous payment.

### Milestone E — withdrawals and outgoing payouts

Inspect and harden:

- `app/Http/Controllers/Api/V1/WithdrawalController.php`
- `app/Http/Requests/Withdrawal/CreateWithdrawalRequest.php`
- `app/Http/Requests/Withdrawal/CancelWithdrawalRequest.php`
- `app/Services/Finance/WithdrawalService.php`
- `app/Services/Finance/WithdrawalRequestWithHoldService.php`
- `app/Services/Finance/WithdrawalReservationService.php`
- `app/Services/Finance/WithdrawalInitialHoldService.php`
- `app/Services/Finance/WithdrawalApprovalService.php`
- `app/Services/Finance/WithdrawalCompletionService.php`
- `app/Services/Finance/WithdrawalIntegrityGuard.php`
- `app/Services/Payment/WithdrawalDisbursementService.php`
- `app/Services/Payment/WithdrawalCallbackApplicationService.php`
- `app/Services/Payment/WithdrawalProviderReferenceService.php`
- `app/Services/Payment/WithdrawalReconciliationService.php`
- `app/Jobs/Payment/DisburseWithdrawalJob.php`
- `app/Jobs/VerifyWithdrawalKycJob.php`
- withdrawal/payout/reconciliation migration files and models.

Requirements:

- Validate verified identity/KYC, age/legal eligibility, account ownership, self-exclusion/responsible-gaming restrictions, AML/risk holds, daily/per-transaction limits, destination verification and account-change cooling period where configured.
- Reserve/hold funds atomically before asynchronous disbursement. An accepted request must not spend money twice; a rejected/cancelled request releases a hold once; a completed payout consumes it once.
- Keep maker-checker/two-person approval for high-risk or high-value payouts where the product policy requires it. The creator must not approve their own high-risk payout. Record actor, timestamp, reason, evidence, before/after state and immutable reference.
- Use stable idempotency keys and external provider reference identities. Unknown provider outcome means `pending/reconciliation required`, not automatic success and not automatic second dispatch.
- Add recovery for a worker crash after provider dispatch but before local persistence, and for callback arrival before/after the queue job finishes.
- Prove rollback on failed transfer, refusal of overdraws, no duplicate fee/tax entries, no duplicate debit, and exact reconciliation of provider totals to posted ledger totals.

### Milestone F — implement the missing balance-transfer domain completely

First inspect the dedicated transfer controller, `StoreBalanceTransferRequest`, reversal request, DTOs, existing profile API, transfer tests, ownership policies, and all route registrations. Then do the following if this feature remains in the repository’s advertised/product API contract:

1. Implement the missing service/model/exception/migration/policy files listed in the tree below with complete production code.
2. Define the transfer state machine: quoted → accepted/pending → posted/completed or rejected; reversal must have a separate approved state path. Do not let callers set internal status, fee, ownership or ledger identity.
3. Validate sender identity, recipient identity, recipient eligibility, tenant/account boundaries if applicable, self-transfer policy, verified contact/account status, available funds after existing holds, configured limits, fee schedule, currency and idempotency key.
4. Recalculate the fee and recipient amount server-side when submitting. A client quote is informational and must not be trusted for final money values.
5. Debit sender and credit recipient in one canonical balanced ledger transaction, or use a rigorously modelled pending/clearing account if the product contract requires an asynchronous state. No half-transfer and no sequential wallet balance updates.
6. Add durable uniqueness, row locks, retry safety and same-key replay behavior. Two concurrent transfers against one wallet must not overspend. Reversals must be linked to the original transfer and cannot exceed the unreversed amount.
7. Add route names and middleware only after reconciling `routes/api.php` and existing `PlayerProfilePortalController::transferBalanceApi`. There must be one unambiguous documented API contract; preserve compatibility routes only with tests and secure ownership boundaries.
8. Add the migration, model factory when appropriate, authorization tests, HTTP contract tests, idempotency tests, fee/rounding tests, reversal tests and real-DB race tests. No UI redesign in this phase.
9. If business policy or an authoritative fee schedule is unavailable, implement fail-closed configuration (`NOT_CONFIGURED`) with clear admin diagnostics rather than inventing a fee or zero-fee loophole.

### Milestone G — lottery purchase and wallet interaction

Inspect at minimum:

- `app/Http/Controllers/Api/V1/BetPurchaseController.php`
- `app/Http/Controllers/Web/BetPurchaseController.php`
- `app/Http/Controllers/GloL6Controller.php`
- `app/Services/Betting/BetPurchaseService.php`
- `app/Services/Betting/BetPurchaseValidator.php`
- `app/Services/Betting/BetPurchaseIdempotencyService.php`
- `app/Services/Betting/BetPurchaseReplayService.php`
- `app/Services/Betting/BetPurchaseTransactionService.php`
- `app/Services/Betting/BetPurchaseWalletService.php`
- `app/Services/Betting/BetPurchaseLedgerService.php`
- `app/Services/Betting/BetPurchaseTicketService.php`
- `app/Services/Betting/BetPurchaseAdmission.php`
- `app/Services/Betting/BetPurchaseRiskService.php`
- `app/Services/Betting/BetCancellationService.php`
- `app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php`
- `app/Services/Lottery/GloL6SalesService.php`
- `app/Services/Lottery/GloL6PurchaseCapabilityService.php`
- `app/Services/Lottery/GloL6ProportionalPrizeCalculator.php`
- `app/Services/Lottery/GloTicketFreezeService.php`
- `app/Services/Lottery/GloFrozenWinnerService.php`
- ticket/sale/bet/ownership/receipt migrations and models; the relevant API and web routes.

Requirements:

- Validate server-side ticket/number format, draw eligibility, sale-open/close times in the intended official time zone, ownership, KYC/legal-age eligibility, responsible-gaming limits, account risk gate, price and purchase limits. Do not trust frontend totals or client-supplied status.
- Validate ticket inventory/unique ticket numbers under a database uniqueness constraint and row/transaction lock. A sold or frozen ticket cannot be sold twice under concurrency.
- Debit the wallet, write the balanced ledger journal, create purchase receipt/items/tickets, update inventory and idempotency result inside a single coherent transaction boundary. Queue external notifications only after commit.
- A repeated idempotency key with identical canonical request returns the same receipt without second debit; same key with a different payload is rejected as conflict. Include race tests.
- On insufficient balance, invalid draw, unavailable ticket, risk denial, or DB exception, all writes roll back and no ledger/wallet residue remains.
- Preserve GLO L6 separation from 2D/3D/N3 and other lottery engines. Do not reuse a generic lottery pathway where it breaks official L6 ticket identity, pricing, draw status, result provenance, or prize allocation.
- Do not guess official ticket price, prize matrix, withholding/tax, unsold-ticket treatment, result source, or jurisdiction rules. Trace existing sign-off/config source; if absent, block live-money mode and name the required approval. If the existing project’s approved contract explicitly establishes a fixed price or series size, add regression tests for it rather than silently changing it.
- Ensure public ticket verification is read-only, reveals no private account data, uses non-guessable tokens when applicable, and cannot leak another user’s purchases.

### Milestone H — official draw-result workflow, settlement, prize and reversals

Inspect and preserve the current guarded design around:

- `app/Services/Draw/DrawResultIngestionService.php`
- `app/Services/Draw/DrawResultConfirmationService.php`
- `app/Services/Draw/DrawResultPublicationService.php`
- `app/Services/Draw/DrawPublicationService.php`
- `app/Services/Draw/DrawCertificationService.php`
- `app/Services/Draw/AdminDrawResultWorkflow.php`
- `app/Services/Draw/RealPrizeSettlementService.php`
- `app/Services/Draw/ChunkedSettlementOrchestrator.php`
- `app/Services/Draw/ChunkedSettlementPreviewService.php`
- `app/Services/Draw/SettlementPayoutInvariantService.php`
- `app/Services/Draw/SettlementRunGuard.php`
- `app/Services/Draw/DrawReconciliationService.php`
- `app/Services/Draw/DrawSettlementSimulationService.php`
- `app/Services/Lottery/GloResultImportService.php`
- `app/Services/Lottery/GloOfficialResultProvider.php`
- `app/Services/Lottery/GloResultTrustPolicy.php`
- `app/Services/Lottery/GloResultPublicationService.php`
- `app/Services/Lottery/GloN3SettlementService.php`
- `app/Services/Lottery/BatchWinningSettlementService.php`
- `app/Jobs/` settlement jobs and `app/Console/Commands/` draw/settlement commands.

Requirements:

- Ingestion, confirmation, publication and settlement are separate states with explicit authorization and evidence. Ingestion must not silently publish official results or trigger payout unless the guarded business workflow says so.
- Enforce two-person/four-eyes confirmation wherever currently required; requester/approver identities must be different when policy requires it. Prevent replay, stale reads and tampering with result versions/provenance.
- No payout from fixture/test/untrusted sources in production. Official provider failure must remain unavailable/pending/manual review, not fall back to fake results.
- Each draw is settled at most once logically, but restart/recovery must resume safely. Every payout must be tied to exact draw/version, winning ticket/bet, amount calculation version, ledger transaction, claim if applicable, and idempotency identity.
- Preview and production calculation must use the same calculator and configuration version. Use exact arithmetic. Check prize pool totals, tax/withholding, rounding residuals, unsold tickets and reversal logic against approved policy.
- Avoid one giant transaction for unbounded mass settlement. Use chunked settlement with durable progress/checkpoints and per-chunk idempotency, but define what makes the overall draw “complete” and reconcile totals before publication/closeout.
- A canceled draw or corrected result must reverse previously paid/held amounts with immutable linked compensating entries, never rewrite history or double-pay winners.
- Add tests for concurrency, queue crash/resume, duplicate dispatch, result correction, approval collision, missing source data, partial chunk failure, rounding boundaries, and aggregate reconciliation.

### Milestone I — Rust settlement worker and single database contract

Inspect:

- `runtime/settlement-worker/Cargo.toml`
- `runtime/settlement-worker/src/main.rs`
- worker schema assumptions and every SQL query it executes
- `Cargo.toml`, root `Cargo.lock`, and any workspace members
- `docker/rust/Dockerfile`
- `docker-compose.production.yml`
- the relevant Laravel migration definitions and PHP settlement boundary/service used to publish work to Rust.

Requirements:

1. Align Rust and PHP to **one actually supported database engine and one schema contract**. The project deployment notes currently select MySQL as the first safe engine and call out PostgreSQL-only SQL mismatches, while the Rust worker uses SQLx PostgreSQL and Compose configures MySQL. Unless direct evidence proves otherwise, adapt the Rust SQLx driver and queries to MySQL and validate against the exact MySQL schema used by Laravel. Do not add a hidden separate PostgreSQL ledger or dual-write financial state.
2. Ensure worker job claims are durable and atomic. Define lease/claim owner, expiry, heartbeat or recoverability, attempt count, terminal failure, idempotent commit and dead-letter/manual-recovery process. A crashed worker must not permanently strand or duplicate a settlement.
3. Verify Rust money uses `rust_decimal`/string decimal and serializes without `f64`. Keep rounding and calculation policy identical to PHP; add cross-language test vectors for representative/boundary amounts and payload signatures/fingerprints.
4. Validate internal trigger authentication using the established HMAC scheme, timestamp tolerance and replay prevention, and limit the trigger endpoint to trusted callers. Do not rely on a public unauthenticated HTTP trigger.
5. Provide graceful SIGTERM/SIGINT shutdown, bounded DB/query timeouts, connection pool limits, retry classification and structured logs/metrics with correlation IDs. Avoid logging secret-bearing payloads or full payment details.
6. Fix the Rust lockfile and Docker build path deterministically. Generate/commit the appropriate lockfile from Cargo, and either add the worker to the root workspace consistently or use a real standalone lockfile. Remove `COPY ... tests 2>/dev/null || true`; reference only existing paths. Build and test inside the same supported target architecture used by the production image.
7. Make Docker health checks correspond to actual worker behavior and verify database unavailable/reconnect/termination behavior in tests.

### Milestone J — API routing, authorization, state transitions, tenant boundaries

- Audit every route in `routes/api.php`, `routes/web.php`, `routes/channels.php`, and `routes/console.php` for controller existence, method existence, middleware order, authentication, authorization, throttling, request validation, stable resource/DTO serialization, and route-name collisions.
- For each write endpoint, prove object-level authorization (owner or explicit admin permission), validation, idempotency for non-idempotent money writes, and audit logging. Test IDOR by requesting another user’s IDs/references.
- Verify multi-tenant scoping only where the project actually supports tenants; never invent a tenant column or bypass a missing tenant boundary. If tenant context is present, enforce it at all query/service boundaries and test cross-tenant denial.
- Ensure API errors do not expose stack traces, SQL, HMAC secrets, raw private webhook payloads, internal file paths or account existence details. Keep stable machine-readable error codes and correlation IDs.
- Verify auth/session invalidation, MFA for privileged actions where configured, CSRF policy for browser routes, API token ability scopes, admin route isolation, and high-risk action audit trails.
- Ensure no controller implements financial math/business transitions inline if a canonical service already exists. The controller should perform request validation/authentication/response formatting and delegate transactional logic.

### Milestone K — DB schema, migration ordering, indexes, and data integrity

- Run the full migration sequence from an empty disposable database using the selected engine (MySQL for this phase unless a documented architecture decision changes it).
- Run migration rollback tests for new migrations and targeted schema-upgrade tests for legacy data. Do not assume SQLite proves MySQL locking/index/generated-column/decimal behavior.
- Detect duplicate table/column/index definitions across legacy and newer migrations. Do not delete historic migrations to hide duplicate definitions.
- Verify FK integrity, unique constraints, cascading deletion policy, `decimal` precision/scale, timestamp/time-zone semantics, immutable/audit data retention, generated columns, composite indexes, and status/state constraints where the engine supports them.
- Review query paths with `EXPLAIN` against synthetic but realistic volumes. Add indexes for actual high-cost predicates. Avoid `SELECT *` on hot paths and N+1 query loops in settlement/billing.
- Validate production startup migration strategy: migrate job is single-run, failed migrations stop dependent services, backups exist, and schema version is compatible with old/new app rollout where rolling deploys are supported.

### Milestone L — queues, schedulers, retries, monitoring and recovery

- Audit queue jobs for explicit retry/timeout/backoff/unique-lock behavior, correct terminal statuses, after-commit semantics, encrypted payloads when they contain sensitive data, and safe handling of provider callbacks.
- Make scheduler jobs single-owner/singleton across deployment replicas. Confirm GLO result import/publish and settlement commands are scheduled only when the source-of-truth policy authorizes it; manual commands must be guarded in production.
- Build operational alerts/metrics for stuck pending payments, webhook obligation age, queue lag, failed/retried settlement, ledger imbalance, mismatched balances, deadlocks, repeated signature failures, withdrawal provider uncertainty and missing reconciliation.
- Ensure logs contain stable request/correlation/financial identifiers but no secrets, full card/bank credentials, passwords, authorization headers, raw signing keys or excessive KYC PII.
- Document safe recovery playbooks for worker crash, DB outage, Redis outage, provider timeout, partial settlement and corrupted cache. Recovery must not involve editing posted ledger rows.

### Milestone M — production PHP/Docker/CI alignment (backend gates only)

Inspect and repair, as needed:

- `docker/php/Dockerfile`
- `docker/php/laravel-entrypoint.sh`
- `docker/php/php.ini`
- `docker/php/php-fpm.conf`
- `docker/php-fpm/Dockerfile`
- `docker-compose.production.yml`
- `docker/rust/Dockerfile`
- `docker/nginx/conf.d/app.conf` (only API/security/proxy behavior in this phase; do not redesign static assets)
- `config/database.php`
- `config/queue.php`
- `config/cache.php`
- `config/finance.php`
- `config/payment.php` or the actual payment config path found in the repository
- `.env.example` and `.env.production.example` (examples only; never insert real secrets)
- `.github/workflows/ci.yml`
- `.github/workflows/financial-concurrency.yml`
- `.github/workflows/security.yml`

Requirements:

1. Enforce one PHP version consistent with Composer (`^8.4` in current source) across CI and production. `composer install` must be the same deterministic lockfile-driven operation used for the tested release.
2. Correct the entrypoint chain so Laravel’s secret-file loader actually runs. Test missing secret failure, valid secret-file loading, and normal `exec`/signal propagation. Never print secret values.
3. Replace nonexistent health-check binary references with a functioning health check included in the image or available by design. Validate health check in a built container.
4. Build Rust deterministically with the correct Cargo.lock and target. Do not hide Docker build failures with `|| true`.
5. Verify DB driver/schema/query dialect against Compose environment; ensure only the intended engine is started and no app/worker tries to connect to an absent database.
6. Validate `APP_ENV=production`, `APP_DEBUG=false`, correct trusted proxies, HTTPS/cookie settings, least privilege, service health dependencies, and no default secrets. Check that public app routes and queue workers use the same DB/cache/queue contract.
7. Validate all queue/scheduler replicas and container shutdown/restart behavior. One-off migrations run once and block app startup when failed.
8. Run Docker builds with BuildKit and produce a reproducible artifact. If this environment cannot build Docker, do not label image validation passed; still run Dockerfile/static assertions.

---

## 3. Exact repository tree to inspect / fix / add

**Every file below has a trailing `#` explanation.** The tree is a mandatory work inventory, not permission to duplicate existing code. Mark every path as `FIX`, `ADD`, or `INSPECT/PRESERVE` in your final file manifest. Search for each before creating it. Include any additional backend files you discover in the same manifest; do not limit the audit to this list.

```text
thai-lottery-ainul-islam-main/
├── composer.json                                      # FIX/VERIFY: PHP platform and dependency contract must match PHP 8.4 runtime and CI
├── composer.lock                                      # INSPECT/PRESERVE: exact production dependency versions; never edit manually
├── Cargo.toml                                          # INSPECT/FIX: Rust workspace membership and lockfile authority
├── Cargo.lock                                          # INSPECT/PRESERVE: root Cargo lock; ensure settlement worker dependency lock is deterministic
├── .env.example                                        # FIX/VERIFY: safe documented variable names/defaults; no real credentials
├── .env.production.example                             # FIX/VERIFY: production secret file paths, DB driver, cache, queue and required settings
├── config/
│   ├── database.php                                    # FIX/VERIFY: one actual default DB contract and correct connection options
│   ├── queue.php                                       # FIX/VERIFY: production queue semantics, retries, failed-job storage and timeouts
│   ├── cache.php                                       # FIX/VERIFY: Redis/cache store correctness; durable financial guards cannot rely on cache alone
│   ├── finance.php                                     # FIX/VERIFY: fail-closed financial policies and exact money configuration
│   └── payment.php                                     # FIX/VERIFY: provider-specific webhook secrets/headers/algorithms and timeout policy, if this is the real path
├── lang/
│   ├── en/account_services.php                         # FIX: syntax errors from missing commas; preserve keys/copy except necessary correction
│   ├── bn/account_services.php                         # INSPECT: translation key parity and valid PHP syntax
│   └── th/account_services.php                         # INSPECT: translation key parity and valid PHP syntax
├── routes/
│   ├── api.php                                         # FIX/VERIFY: payment, deposit, withdrawal, transfer, bets, ticket purchase and result endpoints
│   ├── web.php                                         # FIX/VERIFY: authenticated write routes and canonical delegation to backend services
│   ├── channels.php                                    # INSPECT: private channel auth and user/tenant object authorization
│   └── console.php                                     # FIX/VERIFY: scheduled draw/import/reconcile tasks and production guards
├── app/Http/Middleware/
│   └── VerifyWebhookSignature.php                      # FIX/VERIFY: align protocol-specific verification and durable replay claim; no false claims of signature validation
├── app/Http/Controllers/Api/V1/
│   ├── PaymentWebhookController.php                     # FIX/VERIFY: verify per-provider facts, bind payment, acknowledge safe replays, enqueue after commit
│   ├── DepositWebhookController.php                    # FIX/VERIFY: no unauthenticated or duplicate wallet credit
│   ├── WithdrawalController.php                        # FIX/VERIFY: authenticated ownership, KYC/limit gate, safe state transitions
│   └── BetPurchaseController.php                       # FIX/VERIFY: validate API purchase and delegate to one canonical transaction service
├── app/Http/Controllers/Api/V1/Payment/
│   ├── KBankWebhookController.php                      # INSPECT/FIX: KBank protocol-specific verification, event mapping and idempotency
│   └── ScbWebhookController.php                        # INSPECT/FIX: SCB protocol-specific verification and state mapping
├── app/Http/Controllers/Transfer/
│   └── BalanceTransferController.php                   # FIX/VERIFY: remove unresolved dependencies by implementation, not by hiding/removing feature
├── app/Http/Controllers/Player/
│   └── PlayerProfilePortalController.php               # INSPECT/FIX: legacy transfer endpoint must delegate to canonical transfer service or safe compatibility adapter
├── app/Http/Requests/Transfer/
│   ├── StoreBalanceTransferRequest.php                  # INSPECT/FIX: strict validation; clients cannot set fee, owner, status or ledger identity
│   ├── ReverseBalanceTransferRequest.php               # INSPECT/FIX: reversal permission and constraints
│   └── DecideReversalApprovalRequest.php               # INSPECT/FIX: maker-checker approval validation
├── app/DTOs/Transfer/
│   ├── TransferQuote.php                               # INSPECT/FIX: quote values are informational and recalculated server-side
│   └── TransferOutcome.php                             # INSPECT/FIX: stable response outcome and safe replay semantics
├── app/Exceptions/
│   └── BalanceTransferException.php                    # ADD if absent: typed transfer domain rejection with stable code/status and safe message
├── app/Models/
│   ├── Wallet.php                                      # INSPECT/FIX: projection/locking semantics; no uncontrolled direct money mutation
│   ├── WalletLedger.php                                # INSPECT/FIX: legacy/compatibility ledger boundary; prevent dual source of truth
│   ├── LedgerAccount.php                               # INSPECT/FIX: account ownership/currency/type correctness
│   ├── LedgerEntry.php                                 # INSPECT/FIX: immutable debit/credit entries and exact precision
│   ├── LedgerTransaction.php                           # INSPECT/FIX: journal identity/hash/atomic posting and reversals
│   ├── FinancialTransaction.php                        # INSPECT/FIX: business transition identity and uniqueness
│   ├── IdempotencyRecord.php                            # INSPECT/FIX: canonical payload fingerprint and durable uniqueness
│   ├── Deposit.php                                     # INSPECT/FIX: provider/payment identity and validated state machine
│   ├── PaymentTransaction.php                           # INSPECT/FIX: provider reference/amount/currency and idempotent settle identity
│   ├── PaymentWebhook.php                              # INSPECT/FIX: encrypted/redacted event evidence and claim state
│   ├── WebhookReceipt.php                              # INSPECT/FIX: safe receipt audit and replay outcome
│   ├── WebhookReplayGuard.php                           # INSPECT/FIX: durable multi-worker replay protection
│   ├── Withdrawal.php                                  # INSPECT/FIX: correct reserve/approve/dispatch/complete/reconcile transitions
│   ├── WithdrawalRequest.php                           # INSPECT/FIX: compatibility boundary vs canonical withdrawal entity
│   ├── WalletReservation.php                            # INSPECT/FIX: reservation/hold release-once identity and state
│   ├── Bet.php                                         # INSPECT/FIX: purchase/draw/wallet/owner linkage
│   ├── BetItem.php                                     # INSPECT/FIX: exact purchase line identity and price calculation linkage
│   ├── GloL6Sale.php                                   # INSPECT/FIX: ticket series, sales close, price and draw version
│   ├── GloL6Ticket.php                                 # INSPECT/FIX: immutable ticket identity/unique sale status and owner linkage
│   └── BalanceTransfer.php                             # ADD if absent: durable transfer state, owner, amount, fee, currency, idempotency and ledger identity
├── app/Services/Finance/
│   ├── Money.php                                       # INSPECT/FIX: exact currency arithmetic, parsing/formatting and rounding rules
│   ├── WalletService.php                               # INSPECT/FIX: canonical wallet reads/writes and authorization boundary
│   ├── WalletLockService.php                           # INSPECT/FIX: consistent deterministic lock order and concurrency behavior
│   ├── WalletHoldService.php                           # INSPECT/FIX: holds and release-once invariant
│   ├── WalletReservationService.php                    # INSPECT/FIX: idempotent reservations and availability calculation
│   ├── LedgerPostingService.php                        # INSPECT/FIX: only canonical balanced journal posting path
│   ├── LedgerPostingWriteBoundary.php                  # INSPECT/FIX: reject bypass writes and validate authority
│   ├── LedgerPostingInputWitness.php                   # INSPECT/FIX: bind validated input to committed posting to detect drift
│   ├── LedgerEntryMoneyPreflight.php                   # INSPECT/FIX: reject invalid precision, currency and amount
│   ├── LedgerEntryPayloadPreflight.php                 # INSPECT/FIX: schema/domain validation before transaction write
│   ├── LedgerBalanceValidator.php                      # INSPECT/FIX: enforce balanced debit/credit totals
│   ├── LedgerAccountBalanceProjector.php               # INSPECT/FIX: derive/maintain the authorized balance projection
│   ├── FinancialTransactionService.php                 # INSPECT/FIX: canonical financial lifecycle and idempotent identity
│   ├── FinancialStateTransitionService.php              # INSPECT/FIX: legal states only; no silent status overwrite
│   ├── IdempotencyService.php                          # INSPECT/FIX: payload fingerprint, ownership, retry/replay contract
│   ├── FinancialReversalService.php                    # INSPECT/FIX: linked compensating entries only
│   ├── ReversalIntegrityGuard.php                      # INSPECT/FIX: prevent over-reversal and duplicate reversal
│   ├── LedgerReconciliationService.php                 # INSPECT/FIX: ledger vs projection/financial business source reconciliation
│   ├── FinancialReconciliationService.php               # INSPECT/FIX: exact provider/ledger totals without lossy SQL aggregation
│   ├── ExactReconciliationReader.php                    # INSPECT/FIX: exact decimal reads and no silent concatenation truncation
│   ├── DepositService.php                               # INSPECT/FIX: canonical deposit initiation state machine
│   ├── DepositAdmission.php                             # INSPECT/FIX: KYC/risk/limits eligibility gate
│   ├── DepositIntegrityGuard.php                        # INSPECT/FIX: amount/reference/provider binding and duplicate prevention
│   ├── DepositApprovalService.php                       # INSPECT/FIX: privileged/manual approval path with audit evidence
│   ├── DepositCompletionService.php                     # INSPECT/FIX: one verified credit transaction
│   ├── WithdrawalService.php                            # INSPECT/FIX: canonical withdrawal lifecycle
│   ├── WithdrawalRequestWithHoldService.php             # INSPECT/FIX: create + reserve funds atomically
│   ├── WithdrawalReservationService.php                 # INSPECT/FIX: reserve, consume or release exactly once
│   ├── WithdrawalInitialHoldService.php                 # INSPECT/FIX: durable initial hold and recovery boundary
│   ├── WithdrawalApprovalService.php                    # INSPECT/FIX: maker-checker, role, KYC, limit and audit rules
│   ├── WithdrawalCompletionService.php                 # INSPECT/FIX: verified payout completion and ledger consumption
│   ├── WithdrawalIntegrityGuard.php                     # INSPECT/FIX: prevent duplicate/invalid transitions
│   └── PayoutReconciliationService.php                  # INSPECT/FIX: payout batch and provider reconciliation
├── app/Services/Payment/
│   ├── PaymentWebhookVerificationService.php            # FIX/VERIFY: gateway-specific crypto verification and trusted parsed event
│   ├── PaymentWebhookService.php                        # INSPECT/FIX: state transitions, amount binding and durable event identity
│   ├── ProcessPaymentWebhookService.php                 # INSPECT/FIX: orchestration with no duplicate external side effects
│   ├── WebhookReplayGuardService.php                    # INSPECT/FIX: database-backed race-safe event claim
│   ├── PaymentWebhookBindingService.php                 # INSPECT/FIX: bind event to expected payment/user/amount/currency
│   ├── PaymentInitiationService.php                     # INSPECT/FIX: safe provider request identity and timeouts
│   ├── PaymentVerificationService.php                   # INSPECT/FIX: trusted provider status, not browser redirect
│   ├── PaymentReconciliationService.php                 # INSPECT/FIX: provider vs internal money-state reconciliation
│   ├── DepositCallbackApplicationService.php            # INSPECT/FIX: verified event → state transition → ledger posting
│   ├── WithdrawalDisbursementService.php                # INSPECT/FIX: safe external payout dispatch and uncertain outcomes
│   ├── WithdrawalCallbackApplicationService.php         # INSPECT/FIX: verified payout callback/replay handling
│   ├── WithdrawalProviderReferenceService.php           # INSPECT/FIX: uniqueness of outbound provider refs
│   ├── WithdrawalReconciliationService.php              # INSPECT/FIX: resolve uncertain payout outcomes safely
│   ├── Drivers/StripeGateway.php                        # FIX/VERIFY: official Stripe signature/checkout/amount/currency behavior
│   ├── Drivers/NagadGateway.php                         # FIX/VERIFY: current provider signing/verification protocol
│   ├── Drivers/BkashGateway.php                         # FIX/VERIFY: current provider signing/verification protocol
│   ├── Drivers/BankTransferGateway.php                  # INSPECT/FIX: bank proof and confirmation cannot be forged by client
│   └── Drivers/CryptoGateway.php                        # INSPECT/FIX: chain confirmations and duplicate transaction protection
├── app/Services/Transfer/
│   ├── BalanceTransferService.php                       # ADD if absent: atomic transfer use case and durable idempotency
│   ├── TransferLimitService.php                         # ADD if absent: server-side amount/count/velocity limits and fail-closed config
│   ├── TransferPanelService.php                         # ADD if absent: transfer history/quote projection without money writes
│   └── BalanceTransferReversalService.php               # ADD if absent: authorized linked compensating reversal
├── app/Services/Betting/
│   ├── BetPurchaseService.php                           # INSPECT/FIX: canonical purchase use case, no partial money writes
│   ├── BetPurchaseValidator.php                         # INSPECT/FIX: draw, wager, ownership, limits and input validation
│   ├── BetPurchaseIdempotencyService.php                # INSPECT/FIX: replay same receipt vs conflicting payload
│   ├── BetPurchaseReplayService.php                      # INSPECT/FIX: return original purchase on safe duplicate request
│   ├── BetPurchaseTransactionService.php                # INSPECT/FIX: coherent DB transaction and lock boundary
│   ├── BetPurchaseWalletService.php                      # INSPECT/FIX: ledger-based debit only
│   ├── BetPurchaseLedgerService.php                      # INSPECT/FIX: balanced purchase journal and exact identity
│   ├── BetPurchaseTicketService.php                      # INSPECT/FIX: inventory/reservation/ticket persistence atomically
│   ├── BetPurchaseAdmission.php                           # INSPECT/FIX: age/KYC/self-exclusion/responsible-gaming/risk gate
│   ├── BetPurchaseRiskService.php                         # INSPECT/FIX: abuse/limit decisions with auditable reasons
│   └── BetCancellationService.php                         # INSPECT/FIX: cancellation/refund linked to original ledger entries
├── app/Services/Lottery/
│   ├── GloL6AuthoritativeTicketEngineService.php          # INSPECT/FIX: authoritative L6 ticket-series and ticket identity
│   ├── GloL6SalesService.php                              # INSPECT/FIX: sale allocation, uniqueness, sales close and reconciliation
│   ├── GloL6PurchaseCapabilityService.php                 # INSPECT/FIX: server-side eligibility and purchase availability
│   ├── GloL6ProportionalPrizeCalculator.php               # INSPECT/FIX: exact, deterministic prize calculation and rounding
│   ├── GloResultImportService.php                          # INSPECT/FIX: official-source ingestion, provenance and no unsafe fallback
│   ├── GloOfficialResultProvider.php                       # INSPECT/FIX: approved official source adapter
│   ├── GloResultTrustPolicy.php                            # INSPECT/FIX: disallow fixture/untrusted result from production settlement
│   ├── GloResultPublicationService.php                     # INSPECT/FIX: publish only confirmed, immutable result version
│   ├── GloTicketFreezeService.php                          # INSPECT/FIX: immutable ticket/freeze boundary before draw close
│   ├── GloFrozenWinnerService.php                          # INSPECT/FIX: winners based only on authoritative frozen inventory
│   ├── GloN3SettlementService.php                          # INSPECT/FIX: preserve separate N3 engine and invariants
│   └── BatchWinningSettlementService.php                   # INSPECT/FIX: restart-safe batch settlement and reconciliation
├── app/Services/Draw/
│   ├── DrawResultIngestionService.php                      # INSPECT/FIX: ingestion without implicit approval/publication
│   ├── DrawResultConfirmationService.php                   # INSPECT/FIX: two-person/four-eyes authorization
│   ├── DrawResultPublicationService.php                    # INSPECT/FIX: publication only from approved version
│   ├── RealPrizeSettlementService.php                      # INSPECT/FIX: one authoritative settlement and balanced payout postings
│   ├── ChunkedSettlementOrchestrator.php                   # INSPECT/FIX: checkpointed large draw settlement and recovery
│   ├── ChunkedSettlementPreviewService.php                 # INSPECT/FIX: same calculation source as production
│   ├── SettlementPayoutInvariantService.php                # INSPECT/FIX: total payout, per-ticket and journal invariants
│   ├── SettlementRunGuard.php                              # INSPECT/FIX: single-run/lease, idempotent terminal state
│   └── DrawReconciliationService.php                        # INSPECT/FIX: draw totals, ticket totals and payout totals agree
├── app/Jobs/
│   ├── ProcessPaymentWebhookJob.php                         # INSPECT/FIX: idempotent/encrypted/retry-safe job contract
│   ├── Payment/ProcessPaymentWebhookJob.php                 # INSPECT/FIX: prevent duplicate implementations from drifting
│   ├── Payment/DisburseWithdrawalJob.php                    # INSPECT/FIX: safe provider dispatch and unknown-outcome recovery
│   └── settlement jobs (discover actual paths)              # INSPECT/FIX: durable claims, bounded retry, recovery and after-commit rules
├── app/Console/Commands/
│   ├── Finance/ReconcileFinancialRecordsCommand.php         # INSPECT/FIX: operational reconciliation, non-mutating by default
│   ├── Finance/WithdrawalDispatchStatusCommand.php          # INSPECT/FIX: uncertain payout status audit and safe response
│   ├── Finance/WithdrawalReferenceAuditCommand.php          # INSPECT/FIX: provider reference collision/orphan audit
│   └── PurgeWebhookReplayGuards.php                         # INSPECT/FIX: retention cannot delete active replay/financial claims
├── database/
│   ├── migrations/2024_01_02_000100_create_wallets_table.php                    # INSPECT/PRESERVE: original wallet schema and compatibility
│   ├── migrations/2024_01_02_000200_create_ledger_accounts_table.php            # INSPECT/PRESERVE: ledger account schema
│   ├── migrations/2024_01_02_000300_create_financial_transactions_table.php     # INSPECT/PRESERVE: transaction identity schema
│   ├── migrations/2024_01_02_000400_create_ledger_entries_table.php              # INSPECT/PRESERVE: journal precision and debit/credit schema
│   ├── migrations/2024_01_04_000200_create_deposits_table.php                   # INSPECT/PRESERVE: deposit lifecycle schema
│   ├── migrations/2024_01_04_000300_create_withdrawals_table.php                # INSPECT/PRESERVE: withdrawal lifecycle schema
│   ├── migrations/2024_02_01_001700_create_payment_webhooks_table.php            # INSPECT/PRESERVE: event identity/evidence schema
│   ├── migrations/2026_10_06_000200_add_payment_transaction_settlement_identity.php # INSPECT/PRESERVE: durable settlement identity
│   ├── migrations/2026_10_06_000300_add_payment_webhook_claim_state.php          # INSPECT/PRESERVE: webhook claim lifecycle
│   ├── migrations/2026_10_06_000400_add_ledger_posting_identity.php              # INSPECT/PRESERVE: posting uniqueness
│   ├── migrations/2026_10_07_000100_create_webhook_receipts_table.php             # INSPECT/PRESERVE: receipt audit
│   ├── migrations/2026_10_07_000200_create_webhook_replay_guards_table.php        # INSPECT/PRESERVE: durable replay uniqueness
│   ├── migrations/2026_10_13_000200_create_wallet_conversion_tables.php            # INSPECT/PRESERVE: wallet conversion identity
│   ├── migrations/2026_10_16_000001_create_financial_ledger_and_wallets_table.php # INSPECT/PRESERVE: later financial schema boundary and compatibility
│   ├── migrations/2026_10_16_000005_create_deposits_and_withdrawals_table.php     # INSPECT/PRESERVE: newer deposit/withdrawal schema boundary
│   ├── migrations/2026_10_16_000014_create_idempotency_and_security_tables.php   # INSPECT/PRESERVE: idempotency/security schema
│   ├── migrations/2026_10_16_000220_create_withdrawal_reservations_table.php      # INSPECT/PRESERVE: withdrawal reservation/hold identity
│   ├── migrations/2026_09_23_000100_create_glo_sales_and_result_tables.php        # INSPECT/PRESERVE: GLO sales/results provenance
│   ├── migrations/2026_09_30_001060_complete_glo_l6_sales_contract.php            # INSPECT/PRESERVE: GLO L6 sale contract
│   └── [new forward migrations only]                                            # ADD as required: transfer tables, missing indexes/constraints, engine-compatible fixes
├── tests/Feature/Finance/                                                   # FIX/ADD: ledger posting, wallet boundaries, reversal and exact reconciliation tests
├── tests/Feature/Payment/                                                   # FIX/ADD: real provider signature, replay, amount binding and payment lifecycle tests
├── tests/Feature/Transfer/                                                   # FIX/ADD: transfer service/route/fee/idempotency/reversal tests
├── tests/Feature/Betting/                                                    # FIX/ADD: purchase atomicity, ownership, replay and concurrency tests
├── tests/Feature/Glo/                                                        # FIX/ADD: ticket inventory, result provenance, four-eyes and L6 settlement tests
├── tests/Feature/Concurrency/                                                # FIX/ADD: race tests on the real supported DB engine, not SQLite only
├── tests/Feature/EndToEndFinancialLifecycleProofTest.php                     # FIX/ADD: deposit → purchase → official result → settlement/claim/reversal proof
├── tests/Feature/FinalProductionReadinessTest.php                            # FIX/ADD: authoritative production release-gate assertions
├── tests/Feature/FinalWholeSystemNoSkipTest.php                              # FIX/ADD: no hidden skips/fail-open behaviors in required scope
├── runtime/settlement-worker/Cargo.toml                                      # FIX: SQLx driver/features and lockfile contract match chosen DB
├── runtime/settlement-worker/src/main.rs                                     # FIX: claim/settlement transaction, internal authentication and shutdown/recovery behavior
├── runtime/settlement-worker/Cargo.lock                                     # ADD/GENERATE: reproducible lockfile if standalone crate design is retained
├── docker/php/Dockerfile                                                     # FIX: runtime entrypoint, PHP extensions, valid health check
├── docker/php/laravel-entrypoint.sh                                          # FIX/VERIFY: safe *_FILE secret loading and exec/signal propagation
├── docker/php/php.ini                                                        # INSPECT/FIX: production-safe limits and no secret/debug leakage
├── docker/php/php-fpm.conf                                                   # INSPECT/FIX: non-root, process limits, health/ping configuration
├── docker/rust/Dockerfile                                                    # FIX: deterministic Cargo lockfile and valid COPY/build steps
├── docker-compose.production.yml                                             # FIX: same DB contract, secret wiring, healthy dependencies and one scheduler
├── .github/workflows/ci.yml                                                  # FIX: PHP version, install, syntax, migrations, unit/feature tests and manifests
├── .github/workflows/financial-concurrency.yml                               # FIX: real supported DB concurrency tests with deterministic setup
└── .github/workflows/security.yml                                            # FIX/VERIFY: static analysis, dependency audit and security gates
```

### File-manifest rule

For **every** file in the tree and every additional file discovered, create an end-of-run manifest with one of these states: `FIXED`, `ADDED`, `INSPECTED/PRESERVED`, or `BLOCKED`. `BLOCKED` must include an exact reason. Do not omit a file because it was inconvenient, large, unfamiliar, or appeared unrelated. If a file does not exist and is not needed after trace-based analysis, state that and why instead of creating an unnecessary duplicate.

---

## 4. Mandatory regression and acceptance matrix

Add or repair targeted tests for each invariant; reuse current tests where they already cover it, but read the assertions to ensure they prove the required behavior.

### A. Static / boot / dependency

- [ ] `composer validate` passes under the chosen PHP platform.
- [ ] PHP lint has zero parse errors for all project PHP files including `lang/`, `resources/`, migrations, tests and scripts.
- [ ] autoload generation passes; all controller/action/service/model/exception references resolve.
- [ ] Laravel config/route/event caches build and app boot succeeds in a clean disposable environment.
- [ ] no unresolved class or missing migration dependency remains in backend call paths.

### B. Ledger / money

- [ ] every financial posting balances exactly in every currency.
- [ ] no `float`/`double` used in a money/prize calculation path.
- [ ] duplicate idempotency replay cannot create a second journal, debit, credit, fee, tax or payout.
- [ ] same idempotency key with different canonical payload is rejected.
- [ ] reversed transactions remain immutable; compensating entries are linked and cannot be repeated/over-reversed.
- [ ] wallet projection/reconciliation matches ledger totals and holds/reservations; no unauthorized negative balance.
- [ ] DB failure at each step rolls back all writes; no orphan receipt, partial transfer, or half-posted purchase.
- [ ] lock/race tests prove two concurrent spend attempts cannot overdraw or sell the same ticket.

### C. Deposit / webhook / refund

- [ ] valid signature per actual configured provider is accepted.
- [ ] missing, malformed, stale, wrong-body, wrong-secret and unsupported signatures are rejected.
- [ ] a replay under simultaneous delivery in separate DB connections creates one financial effect.
- [ ] a provider retry after successful processing gets a safe idempotent acknowledgement and does not credit twice.
- [ ] mismatched amount, currency, user/payment binding, provider reference or invalid state transition creates no wallet credit.
- [ ] refund/dispute/chargeback creates the correct linked compensating action and cannot exceed original posted amount.
- [ ] webhook queue failure after durable receipt is recoverable; event obligation cannot disappear silently.

### D. Withdrawal / payout

- [ ] insufficient available balance, KYC failure, responsible-gaming restriction, AML hold, invalid destination and limit breach are rejected.
- [ ] reservation is atomic; cancellation/failure releases once; completion consumes once.
- [ ] approval separation and audit identity are correct.
- [ ] worker crash/provider timeout leads to safe pending/reconciliation, not blind duplicate payout.
- [ ] external reference uniqueness and callback replay tests pass.

### E. Transfers

- [ ] valid transfer posts exactly one balanced sender/recipient journal with approved fee routing.
- [ ] concurrent transfers cannot spend the same balance twice.
- [ ] receiver/sender ownership and prohibited self/cross-tenant cases are covered.
- [ ] replay returns original outcome; altered replay payload conflicts.
- [ ] reversal is authorized, maker-checker compliant where required, linked, bounded and exactly-once.
- [ ] API route list confirms canonical endpoints resolve and are guarded.

### F. Ticket purchase and GLO L6

- [ ] all configured lottery entrypoints delegate to the canonical purchase path.
- [ ] draw closed/invalid, ineligible user, invalid ticket, sold/frozen ticket, price mismatch, risk/limit denial and insufficient balance are rejected with full rollback.
- [ ] same purchase replay produces the same receipt and no second debit.
- [ ] ticket identity/inventory uniqueness holds under realistic concurrent writers.
- [ ] L6 remains separate from 2D/3D/N3 and its prize calculator uses exact arithmetic.
- [ ] price/prize/tax/unsold-ticket policies are traceable to approved config/source and unknown policy fails closed.
- [ ] public verification cannot leak private user/payment data.

### G. Official result and settlement

- [ ] untrusted/fixture source cannot cause a production payout.
- [ ] four-eyes confirmation and different-operator rule are tested.
- [ ] result version is immutable after confirmation/publication; correction follows explicit audited procedure.
- [ ] settlement is restart-safe after partial chunk/worker crash, and no duplicate winner payout occurs.
- [ ] preview calculation and production calculation match for representative and boundary cases.
- [ ] total ticket/prize/payout/ledger totals reconcile exactly before draw closeout.
- [ ] cancelled/corrected draw uses compensating ledger entries without mutating historical journals.

### H. Runtime / database / Rust

- [ ] clean MySQL migration up succeeds; fresh schema includes all expected constraints/indexes.
- [ ] migration upgrade path preserves representative legacy data; new migration rollback tests pass.
- [ ] PHP and Rust connect to the same configured database and schema, using compatible column types and SQL dialect.
- [ ] Rust unit/integration tests and release build pass for production target; money wire formats match PHP test vectors.
- [ ] two worker instances cannot claim/process the same settlement concurrently.
- [ ] worker SIGTERM during work is safe; restart resumes/reconciles correctly.
- [ ] production Docker build has no missing COPY inputs or absent health check.
- [ ] production secret-file entrypoint works and fails closed when required secret is missing.

### I. Security / compliance

- [ ] IDOR tests across user-owned deposits, withdrawals, transfers, bets, tickets, receipts and payout records pass.
- [ ] admin endpoints enforce policy/role, MFA/step-up when configured and audit logs.
- [ ] SQL query input is parameterized; output and logs do not leak internal secrets/PII.
- [ ] rate limits protect login, purchase, deposit, withdrawal, webhook and transfer pathways.
- [ ] KYC/age/AML/self-exclusion/responsible gaming policies are enforced at the server-side transaction boundary, not only in UI.
- [ ] no `.env` credentials, signing keys, private certs, access tokens, or real customer data are committed.

---

## 5. Commands to run and evidence to capture

Run commands that match installed tool versions and the project's scripts; adapt only when necessary and record any adaptation.

```bash
# 1) Baseline / dependency
 git status --short
 php -v
 composer --version
 composer validate --no-check-publish
 composer install --prefer-dist --no-interaction --no-progress
 composer dump-autoload --optimize

# 2) PHP syntax — include language files and resources, not just app/
 find . -type f -name '*.php' \
   -not -path './vendor/*' -not -path './node_modules/*' \
   -print0 | xargs -0 -n1 php -l

# 3) Static analysis / formatting — use repo-installed tools and config
 vendor/bin/phpstan analyse --no-progress
 vendor/bin/pint --test

# 4) Migrations/tests in an isolated disposable test database
 php artisan config:clear
 php artisan route:clear
 php artisan event:clear
 php artisan migrate --env=testing --force
 php artisan test --testsuite=Unit
 php artisan test --testsuite=Feature
 php artisan test --filter=Ledger
 php artisan test --filter=Webhook
 php artisan test --filter=Transfer
 php artisan test --filter=BetPurchase
 php artisan test --filter=Glo
 php artisan test --filter=Settlement
 php artisan test --filter=Concurrency

# 5) Route and scheduler contracts
 php artisan route:list --except-vendor
 php artisan schedule:list

# 6) Rust — run in the chosen crate/workspace and target
 cargo fmt --all -- --check
 cargo clippy --all-targets --all-features -- -D warnings
 cargo test --locked
 cargo build --release --locked

# 7) Runtime packaging — only with local Docker available
 docker compose -f docker-compose.production.yml config --quiet
 docker compose -f docker-compose.production.yml build --no-cache app settlement-worker
```

**Never copy/paste destructive commands against a shared database.** `migrate --env=testing` is safe only after confirming the testing connection points to a disposable database. If command names differ in the installed Laravel/PHPUnit version, inspect `php artisan list` and document the real equivalent instead of silently skipping.

### Test execution policy

- Run focused tests after every patch group and the full required suite after all patches.
- Never change an expected assertion merely to make a test green without proving the old assertion was wrong.
- Do not use `markTestSkipped`, `@doesNotPerformAssertions`, `withoutExceptionHandling` as a blanket bypass, fake successful provider clients in production bindings, or SQLite-only behavior to disguise locking failures.
- For DB concurrency/transaction behavior, run against the same DB engine/major version selected for deployment (MySQL 8.4 under the current production Compose contract). SQLite can remain for fast unit tests but cannot substitute for release-critical MySQL race tests.
- Capture totals for passed/failed/skipped tests. For every skip, state exact reason and whether it is acceptable for release. Required financial/concurrency tests may not be skipped for launch sign-off.

---

## 6. Definition of “backend and financial logic complete”

You may report **“Backend & Financial Logic: complete for the verified release gate”** only if every condition below is true:

1. All P0/P1 backend and financial defects found in the repository are fixed or explicitly blocked by an external decision that disables the affected live-money capability by default.
2. No unresolved PHP syntax error, missing class, unbound service, dead route, invalid migration dependency, or hidden runtime configuration mismatch remains in an advertised backend API path.
3. Payment/deposit/withdrawal/transfer/bet purchase/refund/reversal/settlement all have one canonical transaction boundary, exact amount validation, durable idempotency, authorization, audit trail, and safe concurrency behavior.
4. Ledger posting, wallet projection, held/reserved funds, provider records and business states reconcile without unexplained discrepancy in the test fixtures and representative seeded reconciliation scenarios.
5. GLO result ingestion/confirmation/publication/settlement respects official-source provenance and the current four-eyes write boundary; there is no fixture fallback for production payouts.
6. Full PHP tests and required MySQL concurrency/financial tests pass. Rust tests/build pass if the worker remains in the production path. Production Docker builds pass if the build system is available.
7. CI matches the chosen PHP/runtime/database/worker contract and fails when an important acceptance gate fails.
8. New migrations are tested against fresh schema and legacy upgrade fixtures. Recovery/runbooks explain uncertain external money outcomes without manual ledger mutation.
9. All new/changed files are listed with exact paths and purpose; all acceptance matrix items have actual command/result evidence; every known limitation has an owner/action and fail-closed behavior.
10. No “100%” claim is based on static inspection alone. If external provider credentials, official source access, a test payment terminal, a real multi-worker environment, or production-like DB is unavailable, explicitly list the missing verification and **do not** claim that portion is verified.

“100%” here means **zero known and unaddressed backend/financial gaps in the agreed tested scope**, not a mathematically provable absence of all possible defects.

---

## 7. Required final response and artifacts

When the work is actually complete, produce these repository artifacts with full content, not summaries that hide missing evidence:

1. `BACKEND-NEXT-IMPLEMENTATION-REPORT.md` — summary, exact fixes, changed paths, migration effects, test commands/results, risks and unverified gates.
2. `BACKEND-FINANCIAL-INVARIANTS.md` — money/ledger invariants, idempotency contract, lock order, states, reversal policy and reconciliation operations.
3. `BACKEND-RELEASE-GATE.md` — checklist with `PASS`, `FAIL`, or `BLOCKED` only; every pass links to real test/command evidence.
4. Update existing README/runbook documents only where the new runtime contract changes; do not overwrite historical audit reports to make the project appear healthier.

Final answer format:

- **Backend/financial readiness:** a defensible score plus scope and evidence (no made-up 100%).
- **Fixed:** each critical issue mapped to file and test.
- **Added:** every new file and why.
- **Database changes:** migration names, indexes/constraints and legacy upgrade results.
- **Validation:** command, exact pass/fail/skip counts and DB engine.
- **Blocked/unverified:** exact missing credentials/environment/provider contract and safe fail-closed state.
- **Remaining backend gaps:** must be zero to claim release-gate completion; if not zero, state the exact blocker and do not start `frontend-next` yet.

## LAST INSTRUCTION

Do not switch to frontend work when this prompt is finished. Finish the backend/financial milestone, execute all available verification, create the reports and changed code in the actual repository, and return the truthful evidence. If the environment prevents a check, keep the affected live-money capability disabled or clearly blocked; do not fabricate success.
