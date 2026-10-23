# Backend Implementation Report — Close 35 Backend/Audit Failures (`20261023T095500Z_2c7ab9c_close35`)

## 1. Executive Verdicts (Kept Strictly Separate)

| Verdict Dimension | Status | Basis |
|---|---|---|
| **`BACKEND IMPLEMENTATION`** | **`PASS`** | All **35** Backend/Audit-owned non-green PHPUnit cases (`H1`–`H5`, `H7`) from `20261023T063900Z_2c7ab9c` are closed with fresh, source-bound evidence at candidate revision `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7`. Deduplicated by `(class::name)` test identity across all 17 JUnit XML files: **`5,591` unique test cases — `5,577` passed (`99.75%`), `14` failures, `0` errors, `0` never-executed (`168,911` assertions)**. Every one of the `170` SQLite-only skip markers was executed and passed on MariaDB 11.8.6 + Redis 8.0.2, so **0 required Backend/financial tests remain unexecuted**. Every one of the `14` remaining failures is a **Frontend Blade** assertion (`H6`) owned by another track. |
| **`FINANCIAL LOGIC`** | **`PASS`** | Double-entry ledger balance, exact minor-unit arithmetic (no float/double), idempotent replay and same-key/different-payload conflict refusal, atomic rollback, append-only reversal with compensating entries, wallet/hold/ledger reconciliation, wallet/hold/ledger concurrency lock races, deposit integrity, withdrawal admission/hold/release, transfer/conversion ownership/fees/reversal authority, bet purchase/amendment/cancellation, GLO L6, result publication, chunked settlement, worker crash recovery, duplicate-winner prevention and draw cancellation all pass on **both** SQLite 3.46.1 and MariaDB 11.8.6 (REPEATABLE-READ) with **0** failures and **0** errors. |
| **`PLATFORM RELEASE ACCEPTANCE`** | **`RELEASE BLOCKED`** | Kept `RELEASE BLOCKED` because: (1) no Docker daemon exists in this sandbox (`docker_daemon_available: false`, `docker_executable_present: false` in `environment.json`), so the live production image build and container smoke tests must run on the DevOps CI/staging host; (2) live payment provider/webhook cutover is prohibited by task constraint; and (3) the **14 Frontend Blade** `H6` failures (`resources/views/layouts/admin.blade.php` ×7, `resources/views/player/bet.blade.php` ×5, `resources/views/player/draws/*` ×1, public footer ×1) remain open in the Frontend track and are formally handed off, not resolved here. |

---

## 2. Candidate Source Identity

- **Repository root**: `/home/user`
- **Remote origin**: `https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git`
- **Branch**: `backend-next`
- **Candidate commit (`HEAD`)**: `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7`
- **Evidence directory**: `/home/user/artifacts/backend-acceptance/20261023T095500Z_2c7ab9c_close35/`
- **Machine-readable identity**: `source-identity.json` (UTC capture, `HEAD` SHA, 11-commit parent chain, `git status --short` — 515 lines, SHA-256 of key config/spec files)
- **Environment**: `environment.json` (PHP 8.4.27, Composer 2.10.3, PHPUnit 11.5.56, SQLite 3.46.1, MariaDB 11.8.6-MariaDB REPEATABLE-READ on `/run/mysqld/mysqld.sock`, Redis 8.0.2, rustc/cargo 1.93.1, Python 3.13.16, Node v20.20.2, Debian 13 trixie)

---

## 3. H1–H5 / H7 Case Matrix — Original Failure, Owner, Exact Fix, Regression Test, Result

### H1 — Superseded UUID schema vs integer financial schema (2 cases)

| # | Original failure | Owner | Exact fix | Regression test | Result |
|---|---|---|---|---|---|
| 1 | `Tests\Unit\Ledger\AutomatedBankReconciliationTest::test_reconciles_matched_deposits_and_payouts_with_zero_variance` — `MassAssignmentException: Add fillable property [id, ledger_transaction_id, account_id, direction] to allow mass assignment on [App\Models\LedgerEntry]` | Backend / test fixture | Production fix in `app/Models/LedgerEntry.php`: added `ledger_transaction_id`, `direction`, `sequence` to `$fillable` (the `2026_10_21_000005` migration genuinely added these columns) and a `creating` hook that derives the missing side of the pair — `type` from `direction` and `direction` from `type` — plus defaults `posted_at` to now. Added `transaction()` belongsTo relation so the reconciliation service's `whereHas('transaction', …)` resolves. Test fixture now posts through the real `DoubleEntryLedgerService` (debit = credit = 1000.0000) instead of hand-inserting a single-sided UUID entry, then back-dates `posted_at` to the reconciled day. | `pre-fix-logs/h1-automated-bank-reconciliation-before.log` (1 error) → `post-fix-logs/h1-automated-bank-reconciliation-after.log` (**OK, 1 test, 4 assertions**) | **CLOSED** |
| 2 | `Tests\Unit\Accounting\ThaiRevenueDepartmentTaxReportServiceTest::test_generates_accurate_monthly_withholding_tax_summary` — `MassAssignmentException: Add fillable property [id] to allow mass assignment on [App\Models\Payout]` | Backend / test fixture | Production fix in `app/Models/Payout.php`: `booted()` now generates `reference_number` when absent (previously only mirrored `payout_number`), and falls back to `net_amount`/`net_payout_amount`/`gross_amount`/`gross_payout_amount` for `amount` when it is not supplied; `casts()` gained `paid_at`/`settled_at` datetime casts so `ThaiRevenueDepartmentTaxReportService`'s `whereBetween('paid_at', …)` and `$payout->paid_at` behave. Service fix in `app/Services/Accounting/ThaiRevenueDepartmentTaxReportService.php`: replaced the three `%.4f` float formatters with exact `bcadd(…, '0', 4)` decimal strings in the CSV export — **removing the last float money formatting on the withholding-tax reporting path**. Test fixture now supplies `payout_number` instead of a hand-set UUID `id`. | `pre-fix-logs/h1-tax-report-service-before.log` (1 error) → `post-fix-logs/h1-tax-report-service-after.log` (**OK, 1 test, 6 assertions**) | **CLOSED** |

### H2 — Direct deposit reversal vs Part 39 `ManagedRefundBoundaryTest` (1 case)

| # | Original failure | Owner | Exact fix | Regression test | Result |
|---|---|---|---|---|---|
| 3 | `Tests\Feature\Finance\FinancialReconciliationComprehensiveTest::test_15_reversal_uses_compensating_transaction` — `FinancialException: Reversal requires unambiguous original accounting and a matching canonical compensation; controlled review is required.` | Backend / test fixture | The production behaviour is **correct and must not be weakened**: `ReversalIntegrityGuard::assertUnmanaged()` refuses `FinancialReversalService::reverse()` on any `Deposit`-referenced transaction (`reversal_requires_domain_workflow`) so a deposit reversal can never bypass `StripeRefundRecoveryService`. The test fixture was calling `reverse()` directly on the player's *deposit* transaction — a stale fixture that predates the Part 39 managed-refund boundary. The test now creates an **unmanaged** wallet credit through `WalletService::credit(..., FinancialTransactionType::Deposit, 'recon-unmanaged-credit-15')` — a wallet-movement header with exactly one full-amount liability leg and its canonical counterpart, which is the shape `assertAccounting()` proves — and reverses *that*, asserting `originalTx->status === Reversed`, `reversalTx->status === Completed`, `reversalTx->type === Reversal`, the wallet is restored to `500.00`, and the post-reversal reconciliation still reports `ReconciliationStatus::Pass`. | `pre-fix-logs/h2-financial-reconciliation-comprehensive-before.log` (1 error, 36 tests) → `post-fix-logs/h2-financial-reconciliation-comprehensive-after.log` (**OK, 36 tests, 109 assertions**); `tests/Feature/Payment/ManagedRefundBoundaryTest.php` still **OK (11 tests, 42 assertions)** proving the guard is untouched | **CLOSED** |

### H3 — `RefreshDatabase` transaction wrapper vs Part 53 `checkout_requires_top_level` (2 cases)

| # | Original failure | Owner | Exact fix | Regression test | Result |
|---|---|---|---|---|---|
| 4 | `PaymentInitiationServiceTest::test_happy_path_creates_deposit_and_payment_with_provider_reference` — `FinancialException: Checkout dispatch cannot run inside a caller transaction.` | Backend / test fixture | `RefreshDatabase` wraps every test in an outer transaction (`DB::transactionLevel() === 1`), which is precisely the condition `DepositCheckoutService::assertTopLevel()` refuses, because durable checkout must commit before the external gateway dispatch. The fixture was stale relative to `DurableDepositCheckoutTest`, which already uses `DatabaseTruncation`. Changed `use RefreshDatabase;` → `use DatabaseTruncation;` in `tests/Feature/Payment/PaymentInitiationServiceTest.php`. No production code changed. | `pre-fix-logs/h3-payment-initiation-service-before.log` (2 errors) → `post-fix-logs/h3-payment-initiation-service-after.log` (**OK, 5 tests, 20 assertions**); `tests/Feature/Payment/DurableDepositCheckoutTest.php` still **OK** (29 tests, 145 assertions, 1 MariaDB skip) | **CLOSED** |

### H4 — Uncertified draw publication in `DrawAutomationTest` (6 cases)

| # | Original failure | Owner | Exact fix | Regression test | Result |
|---|---|---|---|---|---|
| 5–10 | Six `DrawAutomationTest` cases erroring with `DrawResultException: The result of draw #1 is missing; only a confirmed result may be published to players.` | Backend / test fixture | Production behaviour is **correct and must not be weakened**: `DrawResultPublicationService::publish()` requires a persisted four-eyes-confirmed ingestion record (`assertConfirmedIngestion` — `ingested_by ≥ 1`, `confirmed_by ≥ 1`, `confirmed_by ≠ ingested_by`, non-empty `confirmed_at`). The `publishedDraw()` helper called `publish()` directly without creating a `DrawResult`/confirmed record. The helper now runs the real four-eyes pipeline: `DrawResultIngestionService::ingest(...)` with the maker, then `DrawResultConfirmationService::confirm(...)` with a *different* checker operator and the same claimed payload — the same validated, transactional path every other publication takes. No assertion was removed; the six tests still prove settlement-delay behaviour, single-settlement, unattended-switch isolation and one-bad-draw batch isolation. | `pre-fix-logs/h4-draw-automation-before.log` (6 errors) → `post-fix-logs/h4-draw-automation-after.log` (**OK, 28 tests, 119 assertions**); `tests/Feature/Console/ScheduleRegistrationTest.php` **OK (7 tests, 271 assertions)** | **CLOSED** |

### H5 — Reverted commit `97e65bd` Audit anchor/subject refactor (23 cases)

The authoritative audit contract is the one `97e65bd` established and `a6e87a4` reverted in `app/` while leaving both test files behind. Rather than blind-reverting or blindly trusting the reverted state, each invariant was inspected, the production writer was fixed where an invariant was genuinely broken, and the tests were left standing unmodified.

| # | Original failure | Owner | Exact fix | Regression test | Result |
|---|---|---|---|---|---|
| 11 | `AuditAnchorTruthTest::every_audit_writer_stamps_the_key_from_the_model` — `app/Listeners/RecordAdminOperationAudit.php spells the metadata key by hand instead of naming it once` | Backend / Audit | `app/Models/AuditLog.php` gained `public const ANCHOR_KEY = 'audit_anchor';` and `public const LEGACY_ANCHOR_KEY = 'anchor';`. All 19 anchor writers and readers across `app/Listeners/Record*Audit.php` (13 listeners), `app/Services/Operations/AdminOperationService.php`, `app/Services/Operations/AdminAuditQueryService.php`, `app/Services/ResponsibleGaming/ResponsibleGamingLimitService.php` and `app/Services/Lottery/ProviderAttemptRecorder.php` now name the constant instead of spelling the literal. | `AuditAnchorTruthTest::every_audit_writer_stamps_the_key_from_the_model` | **CLOSED** |
| 12 | `AuditAnchorTruthTest::no_reader_spells_an_anchor_json_path_by_hand` — 18 readers spelling `metadata->audit_anchor` / `metadata->anchor` / `json_extract(metadata, '$.anchor')` | Backend / Audit | `app/Models/AuditLog.php` gained `scopeAnchoredBy(Builder $query, string $anchor)` which ORs both the canonical and legacy JSON paths. All 18 readers now call `->anchoredBy($anchor)`; `AdminAuditQueryService`'s `audit_anchor` filter also routes through it, so the operator desk matches **both** spellings. | `AuditAnchorTruthTest::no_reader_spells_an_anchor_json_path_by_hand` | **CLOSED** |
| 13 | `AuditAnchorTruthTest::the_two_spellings_are_named_once_and_the_old_one_is_never_written` — `Error: Undefined constant App\Models\AuditLog::ANCHOR_KEY` | Backend / Audit | Constants added (see #11); no file writes `AuditLog::LEGACY_ANCHOR_KEY`. | `AuditAnchorTruthTest::the_two_spellings_are_named_once_and_the_old_one_is_never_written` | **CLOSED** |
| 14 | `AuditAnchorTruthTest::every_anchor_key_literal_outside_the_model_is_an_agreed_non_audit_key` — 22 files spelling `'audit_anchor' =>` / `'anchor' =>` | Backend / Audit | All audit metadata literals replaced with `AuditLog::ANCHOR_KEY =>`; only the four agreed non-audit consumers (`WithdrawalKycApproved` event payload, `AdminAuditQueryService` filter name, `TicketQrVerificationService`, `WithdrawalKycGateService`) remain, exactly as the test's `NOT_AUDIT_METADATA` allowlist expects. | `AuditAnchorTruthTest::every_anchor_key_literal_outside_the_model_is_an_agreed_non_audit_key` | **CLOSED** |
| 15–18 | `the_desk_finds_a_kyc_pass_row_by_its_anchor`, `the_desk_finds_a_prize_disbursement_row_by_its_anchor`, `a_lane_that_already_had_the_right_name_is_still_found_and_still_dedupes`, `the_scope_matches_an_anchor_and_nothing_else` — all `Error: Undefined constant App\Models\AuditLog::ANCHOR_KEY` | Backend / Audit | Constants added; `RecordWithdrawalKycAudit`, `RecordPrizeDisbursementAudit` and `RecordSecurityEventAudit` now write and dedupe under `AuditLog::ANCHOR_KEY`, and `anchoredBy()` reads both spellings, so the desk finds the KYC lane, the prize-disbursement lane and the security-event lane by the anchor their own guards dedupe on. | The four behavioural anchor tests | **CLOSED** |
| 19 | `a_row_written_under_the_old_spelling_is_still_findable` — `Failed asserting that 0 is identical to 1.` | Backend / Audit | `anchoredBy()` ORs `metadata->audit_anchor` and `metadata->anchor`, so a pre-rename row written under the old spelling is still found by the desk and still dedupes its lane — no backfill, history not rewritten. | `AuditAnchorTruthTest::a_row_written_under_the_old_spelling_is_still_findable` and `…_still_dedupes_its_lane` | **CLOSED** |
| 20 | `AuditSubjectTruthTest::every_lane_writer_records_a_run_with_a_null_subject` — `app/Jobs/GeneratePayoutStatementJob.php writes no null-subject row at all` | Backend / Audit | 12 lane writers converted from a label-in-`auditable_type` + `0`-id subject to a genuine null subject plus `'lane' => '<label>'`: `GeneratePayoutStatementJob` (`payout_statement_generation`), `CalculatePrizeTaxJob` (`prize_tax_sweep`), `ExecutePayoutTransferJob` (`payout_transfer_run`), `CloseExpiredClaimWindowsJob` (`claim_window_sweep`), `ExpireUnclaimedPrizesJob` (`prize_claim_sweep`), `SweepUnclaimedPrizesJob` (`unclaimed_prize_disposal`), `GeneratePayoutBatchJob` (`payout_batch_generation`, with a new `recordSummary()` writing the run row), `ProcessPayoutBatchJob` (`payout_batch`, in `failed()`), `ProcessPaymentWebhookJob` (`payment_webhook`), `ProcessFinancialReconciliationJob` (`ledger_reconciliation`), `SendFinancialAlertJob` (`financial_alert`), `FinancialReconciliationService::recordAudit()` (`reconciliation`). | `AuditSubjectTruthTest::every_lane_writer_records_a_run_with_a_null_subject` (asserts `nulls === lanes` per file) | **CLOSED** |
| 21 | `no_writer_invents_a_subject_label` — 8 writers | Backend / Audit | All quoted labels removed from `auditable_type` (see #20 plus `RecordConversionAuditTrail` and `FinancialReconciliationService`). | `AuditSubjectTruthTest::no_writer_invents_a_subject_label` | **CLOSED** |
| 22 | `no_writer_uses_zero_as_a_subject_id` — 12 writers | Backend / Audit | Every `'auditable_id' => 0` and `?? 0` fallback replaced with a null subject (see #20); `RecordPrizeDisbursementAudit::from()` and `PayoutStatementService::recordAudit()` now resolve the real subject (disbursement → payout → document → null) instead of `0`. | `AuditSubjectTruthTest::no_writer_uses_zero_as_a_subject_id` | **CLOSED** |
| 23 | `no_writer_names_a_subject_it_cannot_resolve` — `AccountGradeService.php: AccountGradeSnapshot::class with no id` | Backend / Audit | `app/Services/Account/AccountGradeService.php` captures the snapshot it just inserted (`$snapshot = AccountGradeSnapshot::create([...])`) and writes `'auditable_type' => AccountGradeSnapshot::class, 'auditable_id' => (int) $snapshot->getKey()` — a subject that actually resolves, instead of the class with a null id. | `AuditSubjectTruthTest::no_writer_names_a_subject_it_cannot_resolve`; `tests/Feature/Account/AccountGradeProgrammeTest.php` **OK (26 tests, 185 assertions)** | **CLOSED** |
| 24 | `no_audit_row_is_about_the_audit_table_itself` — 3 jobs writing `'auditable_type' => AuditLog::class` | Backend / Audit | `ProcessPaymentWebhookJob`, `SendFinancialAlertJob` and `ProcessFinancialReconciliationJob` now write a null subject — the row is evidence about the run, not about audit row 0. | `AuditSubjectTruthTest::no_audit_row_is_about_the_audit_table_itself` | **CLOSED** |
| 25–28 | `the_guard_refuses_a_label_as_a_subject`, `the_guard_refuses_zero_as_no_subject`, `the_guard_refuses_an_id_that_names_no_subject`, `the_guard_refuses_a_subject_with_no_id` — all failing because `AuditLog::creating` did not refuse | Backend / Audit | `app/Models/AuditLog.php` `booted()` now enforces the four refusals **inside `creating`**, after the existing correlation/scrub guarantees: an id with no type (`names no subject`), a type that is neither a model class nor a morph alias (`is neither a model class nor a morph alias`), a type with no id (`has no id`), and an id ≤ 0 (`is not a primary key`). The guard fails closed on every write path, not just the shared service. | The four guard tests; `AuditAnchorTruthTest` (11) and `AuditSubjectTruthTest` (17) | **CLOSED** |
| 29 | `the_alert_lane_row_is_found_by_its_lane_label` — expected `AuditAction::SecurityAlert`, got `Reconcile` | Backend / Audit | `app/Jobs/Notification/SendFinancialAlertJob.php` now writes `AuditAction::SecurityAlert` instead of `AuditAction::Reconcile`. This is a genuine control-plane defect, not cosmetics: the stale-reconciliation freshness check selects the newest `action = reconcile` row, so every alert was writing the evidence that a reconciliation had *just* happened — the alarm reset the clock it was watching, which let `/api/health` report `pass` about a ledger nobody had checked. | `AuditSubjectTruthTest::the_alert_lane_row_is_found_by_its_lane_label`; `tests/Feature/Observability/HealthDisclosureTest.php` **OK (6 tests, 14 assertions)** | **CLOSED** |
| 30 | `the_disbursement_dedupe_filters_metadata_because_the_table_has_no_lane_column` — `'App\Models\PrizeDisbursement' is null` | Backend / Audit | `RecordPrizeDisbursementAudit::from()` resolves the subject as disbursement id → payout key → **null** (previously `Payout::class` + `0` when neither existed), and its dedupe branch filters `metadata->lane` via `anchoredBy()` instead of the non-existent top-level `lane` column that would have raised an unknown-column error. | `AuditSubjectTruthTest::the_disbursement_dedupe_filters_metadata_because_the_table_has_no_lane_column` | **CLOSED** |
| 31 | `the_shared_audit_door_takes_a_model_or_nothing` — `ReflectionUnionType` not `ReflectionNamedType` | Backend / Audit | `app/Services/Audit/AuditLogService.php::log()` signature narrowed from `Model|string|null $auditable` to `?Model $auditable`, and the body resolves `$auditableType`/`$auditableId` only from a persisted model key (null otherwise) — a string can no longer be written straight into `auditable_type`. | `AuditSubjectTruthTest::the_shared_audit_door_takes_a_model_or_nothing` | **CLOSED** |
| 32 | `an_unsaved_model_becomes_a_run_row_instead_of_subject_zero` — `'App\Models\User' is null` | Backend / Audit | `AuditLogService::log()` treats an unsaved model (no key) as a run row: null type and null id. | `AuditSubjectTruthTest::an_unsaved_model_becomes_a_run_row_instead_of_subject_zero` | **CLOSED** |

Supporting regression coverage retained: `tests/Unit/Lottery/ProviderAttemptRecorderTest.php` **OK (7 tests)**, `tests/Feature/Queue/ProductionQueueComprehensiveTest.php` **OK (24 tests, 119 assertions)** — its `test_24_financial_alert_notification_job_dispatches_and_logs` was updated to locate the run row by its null subject + `lane` marker (the fake `AuditLog::class` + `0` subject it used to search for is exactly the shape now refused), and `tests/Feature/Payment/ApprovedWalletPayoutTest.php` / `PayoutTransferJobTest.php` **OK**.

### H7 — `WalletResource` `reserved_balance` vs `locked_balance` key contract conflict (1 case)

| # | Original failure | Owner | Exact fix | Regression test | Result |
|---|---|---|---|---|---|
| 33 | `PlayerExperienceComprehensiveTest::test_26_player_can_fetch_wallet_balance_and_available_amount` — `Unable to find JSON:` (expected `locked_balance`, response carries `reserved_balance`) | Backend / API contract | `PlayerPresentationContractTest::test_wallet_resource_preserves_decimal_strings_and_distinguishes_hold_from_admin_lock` is the **authoritative** contract: it asserts `assertSame($reserved, $payload['reserved_balance'])` and `assertArrayNotHasKey('locked_balance', $payload)`. The distinction is deliberate — `locked_balance` is the internal column, `reserved_balance` is the public name for the player-visible hold, and publishing the internal name would expose the admin-lock column to players. `WalletResource` is unchanged; the stale assertion in `PlayerExperienceComprehensiveTest::test_26` now asserts `reserved_balance` while keeping the exact `balance`, `available_balance` and `currency` expectations. | `pre-fix-logs/h7-player-experience-test26-before.log` (1 failure) → `post-fix-logs/h7-player-experience-test26-after.log` (**OK, 1 test, 2 assertions**); `tests/Feature/Player/PlayerPresentationContractTest.php` **OK (14 tests, 143 assertions)** | **CLOSED** |

---

## 4. Files Modified This Iteration (51 total, all Backend-owned)

**Production source (26 files, all Backend-owned):**

| Path | Invariant restored |
|---|---|
| `app/Models/AuditLog.php` | `ANCHOR_KEY` / `LEGACY_ANCHOR_KEY` constants, `scopeAnchoredBy()` (reads both spellings), `creating` guard refusing a label / zero / half / unresolvable subject |
| `app/Models/LedgerEntry.php` | `ledger_transaction_id`, `direction`, `sequence` fillable; `type`↔`direction` derivation; `transaction()` relation |
| `app/Models/Payout.php` | `reference_number` and `amount` creation fallbacks; `paid_at` / `settled_at` casts |
| `app/Services/Audit/AuditLogService.php` | `?Model` subject door; unsaved model becomes a run row |
| `app/Services/Lottery/ProviderAttemptRecorder.php` | Anchor stamped from `AuditLog::ANCHOR_KEY` |
| `app/Services/Operations/AdminAuditQueryService.php` | `audit_anchor` filter routes through `anchoredBy()` |
| `app/Services/Operations/AdminOperationService.php` | Anchor stamped from the constant |
| `app/Services/ResponsibleGaming/ResponsibleGamingLimitService.php` | Anchor stamped from the constant |
| `app/Services/Accounting/ThaiRevenueDepartmentTaxReportService.php` | Exact `bcadd` CSV money formatting (no float) |
| `app/Services/Finance/FinancialReconciliationService.php` | Reconciliation run row: null subject + `lane: reconciliation` |
| `app/Services/Payout/PayoutStatementService.php` | Real subject (document → payout → null), never `0` |
| `app/Services/Account/AccountGradeService.php` | Names the snapshot it actually inserted |
| `app/Listeners/Wallet/RecordConversionAuditTrail.php` | Resolves the real `WalletConversion` subject or null; lane metadata |
| `app/Listeners/RecordAdminOperationAudit.php`, `RecordComplianceActionAudit.php`, `RecordDrawCertificationAudit.php`, `RecordFinancialReconciliationAudit.php`, `RecordMfaVerificationAudit.php`, `RecordNotificationAudit.php`, `RecordNotificationReceiptAudit.php`, `RecordPaymentReconciliationAudit.php`, `RecordPaymentWebhookAudit.php`, `RecordPlayerProtectionActionAudit.php`, `RecordProviderOperationAudit.php`, `RecordSelfExclusionAudit.php`, `RecordSecurityEventAudit.php`, `RecordPrizeDisbursementAudit.php`, `RecordRetailTicketAllocationAudit.php`, `RecordWithdrawalKycAudit.php` | Anchor named once via `AuditLog::ANCHOR_KEY`; dedupe via `anchoredBy()` |
| `app/Jobs/CalculatePrizeTaxJob.php`, `CloseExpiredClaimWindowsJob.php`, `ExecutePayoutTransferJob.php`, `ExpireUnclaimedPrizesJob.php`, `GeneratePayoutBatchJob.php`, `GeneratePayoutStatementJob.php`, `ProcessPayoutBatchJob.php`, `SweepUnclaimedPrizesJob.php` | Null subject + `'lane' => <label>` run rows |
| `app/Jobs/Finance/ProcessFinancialReconciliationJob.php`, `Payment/ProcessPaymentWebhookJob.php`, `Notification/SendFinancialAlertJob.php` | Null subject + `'lane' => <label>`; alert lane writes `SecurityAlert`, not `Reconcile` |

**Tests (9 files, Backend-owned fixtures only — no assertion weakened, removed or skipped):**

| Path | Change |
|---|---|
| `tests/Unit/Ledger/AutomatedBankReconciliationTest.php` | Posts through `DoubleEntryLedgerService` (balanced debit/credit) instead of a single-sided UUID insert |
| `tests/Unit/Accounting/ThaiRevenueDepartmentTaxReportServiceTest.php` | Uses `payout_number` instead of a hand-set UUID `id` |
| `tests/Feature/Finance/FinancialReconciliationComprehensiveTest.php` | `test_15` reverses an unmanaged wallet credit, not the managed deposit |
| `tests/Feature/Payment/PaymentInitiationServiceTest.php` | `RefreshDatabase` → `DatabaseTruncation` (matches `DurableDepositCheckoutTest`) |
| `tests/Feature/Console/DrawAutomationTest.php` | `publishedDraw()` runs the real four-eyes ingest + confirm pipeline |
| `tests/Feature/Player/PlayerExperienceComprehensiveTest.php` | `test_26` asserts `reserved_balance` per the authoritative `PlayerPresentationContractTest` |
| `tests/Feature/Queue/ProductionQueueComprehensiveTest.php` | `test_24` locates the run row by null subject + lane marker |

**Generated by the verification run itself (2 files):** `audit.md` and `evidence/release-source-inventory-part-13.json` are regenerated by `scripts/release_source_inventory.py --write`, which is part of the mandated Gate A command set. They are static inventories (no runtime claim) and their content follows deterministically from the current source hashes.

**Explicitly NOT modified (verified by hash comparison against `20261023T063900Z_2c7ab9c/changed-files-manifest.csv`):** `resources/views/**` (all Frontend Blade), `docker/**`, `.github/workflows/**`, `android/**`, `security/weekly-result-integrity/**`, `runtime/settlement-worker/**`, `public/(js|css|build)/**`, `resources/js/**`, `package.json`, `composer.json`, `composer.lock`. The 9 Blade, 7 Docker/CI and 6 Rust paths that appear in the working tree are **pre-existing modifications carried in from earlier backend work**, byte-identical to the prior evidence run. Full evidence: `changed-files-manifest.csv` (1,388 rows, with an `iteration_effect` column).

---

## 5. Architecture / Ownership Decisions Recorded

1. **The audit anchor has exactly one name.** `AuditLog::ANCHOR_KEY` is the single source of truth; `LEGACY_ANCHOR_KEY` exists only so pre-rename rows stay findable in an append-only table. No backfill is performed and none is permitted.
2. **The audit subject is a row or nothing.** A label, a zero id, a class with no id, or an id with no class all fail closed in `AuditLog::creating`. Lane identity moved to `metadata['lane']`, where the house's own filters already look.
3. **Managed obligations cannot be reversed directly.** The Part 39 `ManagedRefundBoundaryTest` boundary stands; only unmanaged wallet-movement headers are reversible through `FinancialReversalService`.
4. **Durable checkout requires a top-level transaction.** Fixtures must use `DatabaseTruncation`, never `RefreshDatabase`.
5. **Four-eyes publication is the only door to players.** Fixtures must run ingest → confirm with two distinct operators.
6. **`reserved_balance` is the public wallet key.** `locked_balance` stays internal; `PlayerPresentationContractTest` is authoritative.
7. **The alert lane does not file itself as a reconciliation.** `SendFinancialAlertJob` writes `SecurityAlert` so the freshness control can actually stay breached.
8. **No float money formatting.** The Thai Revenue Department withholding-tax CSV now emits exact `bcadd(…, 4)` decimal strings.

---

## 6. Gate-by-Gate Verification Summary (Sections A–I, all re-run at `2c7ab9c`)

| Gate | SQLite evidence | MariaDB 11.8.6 + Redis 8.0.2 evidence | Verdict |
|---|---|---|---|
| **Gate A** | `logs/gate-a-php-lint.log` — **`2,892` PHP files discovered, `2,892` parsed, `0` syntax errors** (`logs/gate-a-php-lint-summary.txt`, inventory in `logs/gate-a-php-discovered-files.txt`); `logs/gate-a-composer-validate.log` — `./composer.json is valid`; `logs/gate-a-composer-platform-reqs.log` — all reqs `success`; `logs/gate-a-financial-source-preflight.json` — PASS; `logs/gate-a-release-source-inventory.json` — PASS; `logs/gate-a-env-parity.json` — PASS; `logs/gate-a-python-scripts-unittest.log` — **`100 / 100` passed**; `logs/gate-a-artisan-config-clear.log`, `-about.log`, `-route-list.log`, `-schedule-list.log`, `-migrate-status.log` — all `EXIT=0`; `junit/gate-a-build-deployment-production.xml` — **`224 / 224` passed (`2,005` assertions)** | `logs/gate-h-mariadb-migrations.log` — `migrate:fresh --seed` **`163 / 163`** + `migrate:status` + `migrate:rollback --step=5` + re-`migrate`, all `EXIT=0` | **PASS** |
| **Gate B** | `junit/gate-b-ledger-money-sqlite.xml` — **`1,260` tests, `1,220` passed, `0` failures, `0` errors, `40` MariaDB skips (`11,681` assertions)** | `junit/gate-b-ledger-money-mysql.xml` — **`101 / 101` passed (`1,301` assertions, `0` failures/errors/skips)**; `junit/gate-b-c-g-inline-mariadb-tests-mysql.xml` — **`11 / 11`** of the previously SQLite-skipped inline tests passed | **PASS** |
| **Gate C** | `junit/gate-c-deposit-webhook-sqlite.xml` — **`514` tests, `513` passed, `0` failures, `0` errors, `1` MariaDB skip (`4,718` assertions)** | `junit/gate-c-deposit-webhook-mysql.xml` — **`27 / 27` passed (`783` assertions, `0` failures/errors/skips)** | **PASS** |
| **Gate D** | `junit/gate-d-withdrawal-payout-sqlite.xml` — **`438` tests, `434 / 434` executed passed, `0` failures/errors, `4` MariaDB skips (`1,790` assertions)** | `junit/gate-d-withdrawal-payout-mysql.xml` — **`47 / 47` passed (`715` assertions, `0` skips)** | **PASS** |
| **Gate E** | `junit/gate-e-transfer-conversion-sqlite.xml` — **`468` tests, `421 / 421` executed passed, `0` failures/errors, `47` MariaDB skips (`3,326` assertions)** | `junit/gate-e-transfer-conversion-mysql.xml` — **`56 / 56` passed (`1,326` assertions, `0` skips)** | **PASS** |
| **Gate F** | `junit/gate-f-ticket-purchase-lanes-sqlite.xml` — **`990` tests, `950 / 950` executed passed, `0` failures/errors, `40` MariaDB skips (`5,406` assertions)** | `junit/gate-f-ticket-purchase-lanes-mysql.xml` — **`40 / 40` passed (`823` assertions, `0` skips)** | **PASS** |
| **Gate G** | `junit/gate-g-settlement-results-sqlite.xml` — **`543` tests, `519` passed, `0` failures, `0` errors, `24` MariaDB/Redis skips (`105,043` assertions)** | `junit/gate-g-settlement-results-mysql.xml` — **`43 / 43` passed (`1,677` assertions, `0` skips)**; `logs/gate-h-rust-settlement-worker.log` — **`6 / 6`**; `logs/gate-h-rust-weekly-verifier.log` — **`37 / 37`** | **PASS** |
| **Gate H** | `junit/gate-h-database-seeders.xml` — **`16 / 16` passed (`204` assertions)** | `logs/gate-h-mariadb-migrations.log` — **`163 / 163`** fresh migrations + seeders + status + rollback `--step=5` + re-migrate, `EXIT=0`; `logs/gate-h-rust-settlement-worker.log` — **`6 / 6`** (`0` failed, `0` ignored); `logs/gate-h-rust-weekly-verifier.log` — **`37 / 37`** (`0` failed, `0` ignored) | **PASS** |
| **Gate I** | `junit/gate-i-security-compliance-sqlite.xml` — **`1,002` tests, `988` passed, `14` failures, `0` errors, `14` MariaDB skips (`27,648` assertions)** — every one of the `14` failures is an `H6` Frontend Blade assertion | `junit/gate-i-security-compliance-mysql.xml` — **`14 / 14` passed (`258` assertions, `0` skips)** | **PASS (with 14 Frontend Blade `H6` handoffs, all other-track)** |

---

## 7. Zero-Skip Accounting

**Deduplication rule.** A test case is identified by its `(class, name)` pair as recorded in the JUnit XML. Where the same case was executed on more than one engine (SQLite for the fast lane, MariaDB/Redis for the InnoDB lane), it is counted **once**, and it counts as *passed* if it executed and was green on the engine that actually ran it. Raw, non-deduplicated totals across the 17 JUnit files are `14,548` test executions / `361,047` assertions; the deduplicated totals below are the release-evidence numbers.

| Runner | Unique test cases | Passed | Failed | Errors | Never executed | Assertions (deduplicated) |
|---|---:|---:|---:|---:|---:|---:|
| **PHPUnit — all 17 JUnit XML files, deduplicated** | **5,591** | **5,577** | **14** | **0** | **0** | **168,911** |
| ├─ of which Frontend Blade `H6` failures | 13 | 0 | 13 | 0 | 0 | — |
| └─ of which public-footer `H6` failure | 1 | 0 | 1 | 0 | 0 | — |
| **MariaDB 11.8.6 + Redis 8.0.2 lane (8 `-mysql.xml` files)** | **339** | **339** | **0** | **0** | **0** | **7,037** |
| Rust `runtime/settlement-worker` (`cargo test --locked`) | **6** | **6** | **0** | — | **0** | — |
| Rust `security/weekly-result-integrity` (`cargo test --locked`) | **37** | **37** | **0** | — | **0** | — |
| Python `scripts/tests` | **100** | **100** | **0** | — | **0** | — |
| PHP 8.4.27 syntax lint (`php -l`) | **2,892** | **2,892** | **0** | **0** | — | — |

**Zero-skip rule satisfied**: no required Backend/financial test is left unexecuted. The `170` SQLite skip markers are explicit "requires the separately acknowledged local InnoDB test server" guards, and **every one of them was executed on MariaDB 11.8.6 (REPEATABLE-READ, `/run/mysqld/mysqld.sock`) + Redis 8.0.2** in the eight `-mysql.xml` JUnit files, all passing. Breakdown of the 170 by suite: Gate B `40`, Gate C `1`, Gate D `4`, Gate E `47`, Gate F `40`, Gate G `24`, Gate I `14`.

---

## 8. Unresolved Blockers and Owner Handoffs

1. **14 Frontend Blade assertions (`H6`) — Frontend Track.** `AdminConsoleChromeTest` (7 on `resources/views/layouts/admin.blade.php`), `BrowserBetFinancialBoundaryTest` (2 on `resources/views/player/bet.blade.php`), `PlayerFrontendModulesTest` (3 on `resources/views/player/bet.blade.php`), `PlayerExperienceComprehensiveTest::test_15` (1 on `resources/views/player/draws`), `PublicContentSourceTest::test_glo_references_carry_the_non_affiliation_disclaimer` (1 on the public footer). Expected contracts, exact failing assertions and raw evidence are catalogued in `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`. **No Frontend-owned file was changed in this iteration** — verified by SHA-256 comparison of every Frontend path against the prior evidence bundle.
2. **Live Docker image build & container smoke tests — DevOps Track.** `BLOCKED` in this sandbox: `environment.json` records `docker_daemon_available: false` and `docker_executable_present: false`. Required action: `docker compose -f docker-compose.production.yml build` plus container smoke tests in a CI/staging runner with a live Docker daemon.
3. **Live payment gateway & webhook provider sandbox cutover — Payments / DevOps Track.** `BLOCKED` by task constraint (no live payment provider calls, no real-money transactions). All Stripe/PromptPay/SCB/TrueMoney/bKash/Nagad/Crypto signature, replay, durable-checkout and refund-recovery flows were verified locally with isolated fake adapters and configured test secrets.

---

## 9. Deliverables in This Evidence Bundle

`BACKEND-IMPLEMENTATION-REPORT.md` (this file) · `BACKEND-RELEASE-GATE-MATRIX.csv` · `BACKEND-TEST-RESULTS.md` · `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md` · `ZIP-VERIFICATION.md` · `environment.json` · `source-identity.json` · `changed-files-manifest.csv` · `junit/*.xml` (17 raw JUnit files) · `logs/*.log` (35 raw command outputs) · `pre-fix-logs/*.log` (8 baseline failures) · `post-fix-logs/*.log` (8 post-fix passes) · `checksums.sha256`.

The distributable archive built from this bundle is `BACKEND-CLOSE-35-FINAL-2c7ab9c.zip` (one directory up, in `/home/user/artifacts/backend-acceptance/`), with its authoritative digest in the detached `BACKEND-CLOSE-35-FINAL-2c7ab9c.zip.sha256`. Its integrity record is `ZIP-VERIFICATION.md` above. The archive's own digest is intentionally **not** embedded inside it — see `ZIP-VERIFICATION.md` §5 for why.
