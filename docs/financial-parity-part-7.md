# Financial parity — installment 7: master contract repairs and bulk selection runtime fix

Date: 2026-10-14 (Asia/Dhaka). Base repository commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

## Sequential full-file delivery

This installment continues after File69 and supplies File70–File78 in full. Three files are revisions of already-delivered paths, not three additional distinct repository files. The combined current-source overlay therefore contains **75 unique paths**, with **78 sequential delivery labels** across the installments. It is not a full upstream repository, dependency bundle, or deployable production release.

| Delivery | Repository-relative path | Relationship to earlier delivery |
|---|---|---|
| File70 | `tests/Feature/Finance/FinancialMasterTestCase.php` | New test fixture |
| File71 | `app/DTOs/Betting/BulkBetSelectionData.php` | Newly delivered existing upstream application file |
| File72 | `tests/Feature/BusinessCriticalInvariantTest.php` | Replaces File44 at the same path |
| File73 | `tests/Feature/FinalProductionReadinessTest.php` | Replaces File45 at the same path |
| File74 | `tests/Feature/RealMoneyBusinessReadinessTest.php` | Replaces File46 at the same path |
| File75 | `tests/Feature/Finance/FinancialMasterBoundaryTest.php` | New boundary regressions |
| File76 | `evidence/master-contract-validation-part-7.txt` | New baseline/final verification evidence |
| File77 | `evidence/financial_source_preflight-part-7.json` | New static source report |
| File78 | `docs/financial-parity-part-7.md` | This scope, verification and limitations report |

The current ZIP replaces the three old master-test entries with their latest full versions. The other 66 paths from the 69-file ZIP remain byte-for-byte unchanged. Previous installment artifacts remain historical snapshots. File72–File74 supersede their earlier test contents; this report supersedes the earlier 13-error/5-failure status for the three named master suites only. Next delivery label: **File79**.

## Reproduced baseline and repair boundaries

Before edits, the original three master suites reproduced **35 tests / 127 assertions / 13 errors / 5 failures** in the disposable SQLite environment. The baseline log is preserved in File76.

Most blockers were stale test contracts:

- Integer wallet IDs and decimal strings passed to a service requiring `Wallet` and typed `Money`.
- Missing required financial idempotency keys and chart-of-accounts fixtures.
- Calls to nonexistent `reconcileSystem`, `calculateDuty`, `approveClaim` and `rejectWithdrawal` methods.
- A reservation named argument that did not match the current signature, and a nonexistent `reserved_balance` field instead of `locked_balance`.
- Raw withdrawal rows missing canonical required fields and a fictional debit/refund lifecycle.
- Legacy claim rows without the actual ticket, claimant, verified identity, result and workflow contracts.
- Assertions comparing enum-cast model values to strings, and expecting `UP` where the public health service returns `healthy`.

No compatibility money writer, approval bypass, result-confirmation bypass, or blind settlement finalization was added. No application route, migration, merchant configuration, economics, or finance/claim/withdrawal guard changed in this installment.

There was also **one genuine application runtime defect**, described below. This is therefore not a test-only installment.

## Bulk selection runtime repair

The existing `BulkBetSelectionData` constructor called `BetMarket::canonicalKey()`, but that method does not exist on the market-family enum. A valid bulk selection could throw a PHP error before it reached quote or purchase validation.

The constructor now performs spelling normalization directly with `strtolower(trim(...))`. It keeps the existing legacy constructor field `market`, while not inventing translations for unknown product values. In particular, `top_3`, ambiguous family name `3d`, nonexistent `3d_bottom` and arbitrary unknown names remain refused by the configured quote/purchase pipeline. The master fixtures use the actual configured keys `3d_direct` and `2d_bottom`.

The repair does not add sellable markets, infer a side, alter digit rules or multipliers, or trust client payout values. Leading-zero numbers stay strings. Client `potentialPayout` remains discarded; the real purchase pipeline prices the accepted selection from server configuration.

## Shared disposable financial fixtures

`FinancialMasterTestCase` reuses the existing guarded `PaymentTestCase`: database truncation, allowed-test-database checking, chart-of-accounts seeding, and explicit dummy gateway configuration. It also prevents stray outbound HTTP requests.

Fixture funding calls the actual journal-backed wallet service with a `Wallet`, currency-aware `Money`, and direction-specific deterministic idempotency keys. These are protected test helpers, not new production interfaces. Seed credits are not evidence of real bank deposits or merchant authorization.

Bulk fixtures explicitly prepare an open betting window and provision the existing canonical per-number limits. Claim fixtures advance through the real close/result-pending lifecycle, ingest an `internal` card through a persisted maker, and confirm it through a different persisted checker. The confirmation service performs publication; the helper does not fabricate a publication stamp or run publication twice.

The claim fixture also creates the matching owned ticket, an adult claimant with test KYC evidence, and a role-seeded admin reviewer. Submission, eligible review and approval call the existing claim service. **Internal confirmation is not external official-result verification.** Physical-ticket evidence flags and identity documents are fixtures; no government affiliation, upstream authority or physical examination is certified. The fixture does not finalize settlement or execute prize payment.

## Correct withdrawal semantics

The failure regression now follows the real workflow:

1. Request a withdrawal through `WithdrawalService` with typed money and test KYC evidence.
2. Approve through `WithdrawalApprovalService`, holding 200.00 from a 500.00 balance.
3. Assert balance remains 500.00, locked balance becomes 200.00 and available balance becomes 300.00.
4. Run the real disbursement service with a mocked provider that returns a failed gateway response.
5. Assert the provider invocation occurs outside the framework database transaction, with withdrawal state Processing.
6. Assert state Failed, original balance 500.00, locked balance zero and available balance 500.00.
7. Assert no additional financial transaction/refund credit, and that a retry is refused before another provider invocation.

This is release of a hold, **not refund of an earlier direct debit**. No live provider is contacted. The separate terminal-state regression constructs an explicitly adversarial Completed test row and requires refusal, unchanged financial rows, no Payment aggregate, and no gateway resolution. It is a replay/refusal fixture, not a simulated completed bank payout.

## Ten new boundary cases

File75 contributes ten executed cases, including four data-provider variants:

1. Bulk selection accepts the legacy field name, normalizes canonical spelling, preserves `007`, discards client payout authority, and purchases at the configured server multiplier.
2–5. Four unknown/ambiguous/nonexistent market labels are refused without a wallet charge, bet or extra financial transaction.
6. A mixed valid/invalid cart purchases only its valid item; replay neither repurchases nor debits it again.
7. USD reservation consumption and replay leave the THB wallet untouched and do not create a second consumption transaction.
8. Claim approval rechecks a tampered net prize after eligible review, refuses with the expected domain error and leaves the claim unapproved/unpaid.
9. A claimant without operator permission cannot approve through the real HTTP route.
10. A seeded admin can review and approve through HTTP, but approval does not grant the separate prize-payment permission; payment remains forbidden and no financial transaction is created.

The earlier pending-prize test now compares enum objects and is explicitly labeled a **status-only** test. It does not execute or certify winner-notification delivery. Driver-registration assertions likewise do not certify merchant credentials or live capability. These limitations are not hidden behind master-suite names.

## Final verification matrix

Application tests use disposable SQLite `database/thai_lottery_test`; foundation tests use their isolated setup. Dependencies came from the existing lockfiles, and the assets were rebuilt. File76 contains complete baseline and selected final command output, not shortened excerpts.

| Check | Final result |
|---|---|
| Three original named master suites | **35 tests / 215 assertions; zero errors/failures** |
| New master boundary suite, separately | **10 tests / 74 assertions; zero errors/failures** |
| Payment + Finance, including the new boundary suite | **142 tests / 621 assertions; zero errors/failures** |
| Settlement | **116 tests / 1530 assertions; zero errors/failures; one skip** |
| Draw | **39 tests / 176 assertions; zero errors/failures** |
| BetPurchaseAtomicity + NumberLimitProvisioning | **57 tests / 223 assertions; zero errors/failures** |
| Wallet conversion feature + unit tests | **71 tests / 259 assertions; zero errors/failures** |
| Isolated conversion foundation | **29 tests / 92 assertions; zero errors/failures** |
| Targeted orphan-webhook replay cases | **2 tests / 16 assertions; zero errors/failures** |
| PHP syntax / scoped Pint | **All six delivered PHP files pass** |
| Source scanner tests | **11 tests pass** |
| Static source preflight | **Pass; zero missing imported App classes** |
| Vite build / repository whitespace check | **Pass** |

The separately listed boundary and orphan checks overlap other rows. Do not sum this table as a unique-test count. The SQLite row-lock concurrency test remains skipped; it is not a pass or server-SQL validation.

## Application and continued limitations

Apply this current-source overlay on the stated upstream base with the preceding delivered work. Keep the existing lockfiles. Restore dependencies with the repository's documented process and build assets before running browser-rendering tests. Never run database-truncating tests against a production or non-test database. The overlay excludes `.env`, credentials, dependencies, generated assets and the test database.

These results are **scoped regression evidence, not repository-wide or production certification**. The other enterprise/whole-system test files were not rerun or certified here. Real MySQL/PostgreSQL locking, queue workers, crash recovery, deployment, actual provider credentials, licensed operation, authenticated browser behavior, live merchant transactions and real payouts remain unverified. No live money movement, production migration, deployment or repository push was performed.

Previously documented settlement limitations remain: transport is faked in focused tests, commit-before-dispatch recovery has no transactional outbox, incompatible legacy queued jobs must be drained/quarantined, unpinned historical incomplete runs fail closed, and authoritative finalization is not a guarantee that every wallet has received payment. Manual/disabled/dry obligations may remain Pending.

Own-wallet Cash↔Win is still distinct from member transfer. Legacy member transfer remains fail-closed rather than inventing a compatibility endpoint. Merchant logos still do not constitute integrations. This installment does not activate payment methods or change reference-site fee economics.

The static report deliberately retains `runtime_verified=false` and `live_payment_verified=false`; its own scope is offline source inspection. Selected runtime evidence is recorded separately above and in File76. The repaired master suites are no longer the earlier 13-error/5-failure blocker, but their green result alone is not permission to activate payouts.
