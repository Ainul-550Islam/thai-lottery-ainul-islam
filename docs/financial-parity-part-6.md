# Financial parity — installment 6: Settlement confirmation fixtures

Date: 2026-10-14 (Asia/Dhaka). Base repository commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

## Scope and full-file manifest

This installment continues after File63. It supplies File64–File69 in full and retains File1–File63 unchanged. The combined delivery contains 69 unique repository-relative paths; it is an overlay of the delivered work, not a replacement for the entire upstream repository. Historical reports describe their earlier snapshots. This report supersedes the earlier Settlement failure status, not the remaining master-readiness blockers.

| File | Repository-relative path |
|---|---|
| 64 | `tests/Feature/Settlement/SettlementTestCase.php` |
| 65 | `tests/Feature/Settlement/DrawResultPublicationTest.php` |
| 66 | `tests/Feature/Settlement/SettlementConfirmationBoundaryTest.php` |
| 67 | `evidence/settlement-validation-part-6.txt` |
| 68 | `evidence/financial_source_preflight-part-6.json` |
| 69 | `docs/financial-parity-part-6.md` |

This is a **test-only repair installment**. No production application service, route, model, approval policy, merchant configuration, or migration was changed. The existing result-ingestion, confirmation, publication, lifecycle, and settlement services remain authoritative.

## Root cause of the 72 errors

The legacy `SettlementTestCase::publishResult()` called the publication service without first persisting an ingested result and confirming it through a different persisted operator. The real publication guard correctly refused it. That shared setup prevented many tests from reaching selection simulation, replay, exact payout calculations, non-monetary invariants, or result-integrity checks.

The same-environment baseline was run before any installment-6 source edits:

- 108 tests;
- 769 assertions;
- 72 errors;
- one pre-existing concurrency skip.

No production guard was weakened to make these tests pass.

## Correct fixture preparation

The shared fixture now:

1. Advances the real draw lifecycle to ResultPending.
2. Creates distinct persisted maker and checker users.
3. Ingests the exact result card through `DrawResultIngestionService`, using the existing trusted `internal` source.
4. Confirms that card through `DrawResultConfirmationService` with the different checker.
5. Reads the result that confirmation already published inside its transaction.
6. Returns the refreshed draw, persisted result, and independently validated digit-string DTO to the existing tests.

Confirmation already composes publication atomically. Calling publication again during fixture preparation would be a duplicate, not a valid shortcut. The helper no longer does that.

The shared test setup also prevents stray outbound HTTP requests. Test fixture preparation cannot contact a real merchant or result endpoint accidentally.

## Publication assertions now isolate the intended guard

The duplicate-publication tests now submit the original confirmed card. Supplying unrelated numbers would appropriately fail earlier at confirmed-fact validation, rather than exercising the duplicate-result or lifecycle guard those tests intend to verify.

The lifecycle refusal test retains genuine confirmed facts and the original result rows, then deliberately corrupts only the draw state in the test database. It asserts refusal and that no additional result/winning-number rows are written, with the original first-prize digits preserved. This is adversarial fixture setup, not an application path for changing published state.

Different, unconfirmed replacement numbers remain explicitly rejected by a separate new boundary regression. These changes do not convert a refusal into success or remove the duplicate protection.

## Eight additional boundary regressions

`SettlementConfirmationBoundaryTest` verifies:

1. A ResultPending draw without confirmation cannot publish.
2. Pending ingestion is not publication authority.
3. The same persisted operator cannot act as both maker and checker.
4. A checker must confirm the actual ingested prize facts, not different numbers.
5. A distinct checker cannot make an unregistered source trusted; refusal leaves the record Pending.
6. Failure of the required confirmation audit rolls back the result, winning numbers, draw transition, and confirmation stamp together.
7. Real confirmation publishes leading-zero strings and an independently stated bottom-two result, with correct maker/checker attribution.
8. Different publication input cannot replace the existing confirmed card.

Each test compares the relevant financial snapshot and requires unchanged wallets, financial transactions, ledger entries, payouts, deposits, withdrawals, payments, and agent commissions. Result publication is not itself a prize payment.

Refusal tests catch the specific domain exception and check its error code; arbitrary PHP errors do not satisfy them. The audit-failure test checks the exact injected exception and restores the original event dispatcher, preserving the normal audit redaction/correlation listeners.

## Final verification

All application suites used the disposable SQLite test database `database/thai_lottery_test`, as configured by `phpunit.xml`. The isolated foundation tests use their own fixtures. Dependencies were restored from the existing lockfiles and assets rebuilt. Complete logs, including the still-failing master suite, are in File67.

| Check | Result |
|---|---|
| Original same-environment Settlement baseline | 108 tests / 769 assertions; 72 errors; one skip |
| Final Settlement suite, including the eight new tests | **116 tests / 1530 assertions; zero errors/failures; one skip** |
| Draw regression | **39 tests / 176 assertions, pass** |
| Payment + Finance regression | **132 tests / 547 assertions, pass** |
| Conversion feature + unit regression | **71 tests / 259 assertions, pass** |
| Isolated conversion foundation | **29 tests / 92 assertions, pass** |
| Targeted orphan-evidence master tests | **2 tests / 16 assertions, pass** |
| Python source-scanner regression | **11 tests, pass** |
| Static source audit | **Pass; zero unresolved imported App classes** |
| PHP syntax | **Three delivered PHP files, pass** |
| Pint final | **Three files, pass** |
| Vite rebuild | **Pass** |
| Broad three-class master readiness | **35 tests / 127 assertions; 13 errors and 5 failures remain** |

### The concurrency skip is not a pass

The existing test `SettlementSimulationTest::point_20_lets_only_one_of_two_concurrent_settlements_write` is intentionally skipped when the database driver is SQLite. SQLite does not provide the equivalent MySQL/PostgreSQL row-lock protocol, whether the database is file-backed or in-memory.

The original skip is retained, not newly introduced to hide a failure. Its explanation was corrected to describe the actual driver limitation accurately. No MySQL/PostgreSQL multi-process test was run, and this installment does not certify real-server concurrency.

The final suite contains 116 tests, of which 115 execute and one is skipped. PHPUnit's successful exit does not turn the skipped concurrency check into verified behavior.

## Reproduce only in a disposable test checkout

Verify the test database before running database-truncating suites. Never direct this testing configuration at production.

```bash
composer install --no-interaction --prefer-dist --no-scripts
npm ci --no-audit --no-fund
npm run build
php vendor/bin/phpunit tests/Feature/Settlement --display-skipped
php vendor/bin/phpunit tests/Feature/Draw
php vendor/bin/phpunit tests/Feature/Payment tests/Feature/Finance
php vendor/bin/phpunit tests/Feature/Wallet/Conversion tests/Unit/Wallet/Conversion
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Standalone/ConversionFoundationTest.php
php vendor/bin/phpunit tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php --filter 'idempotent_webhook_processing|payment_webhook_replay_protection'
python3 -m unittest discover -s scripts -p test_financial_source_preflight.py
python3 scripts/financial_source_preflight.py --json evidence/financial_source_preflight-part-6.json
php vendor/bin/pint --test tests/Feature/Settlement/SettlementTestCase.php tests/Feature/Settlement/DrawResultPublicationTest.php tests/Feature/Settlement/SettlementConfirmationBoundaryTest.php
```

The following diagnostic remains unsuccessful:

```bash
php vendor/bin/phpunit tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php tests/Feature/RealMoneyBusinessReadinessTest.php
```

## Remaining blockers and next scope

The three master classes still exercise stale contracts, including integer wallet arguments to the canonical wallet-credit service, absent reconciliation and prize/stamp-duty method names, enum-vs-string comparisons, and an outdated health response assertion. This installment does not add unsafe compatibility writers or broadly rewrite those tests. Their 13 errors and five failures are retained in complete evidence.

A subsequent repair must align those tests with real wallet, reservation, withdrawal, claim-approval, reconciliation, and bulk-bet contracts while preserving fail-closed checks. It must not accept arbitrary unsigned payment payloads, relax approval, or report a test suite named “production readiness” as production certification merely because it passes.

The installment-5 staging requirements still apply: drain/quarantine incompatible legacy job payloads, do not guess proof for existing unpinned settlement runs, restart matching workers, validate on a production-like database, and test queue crash recovery. The continuation scheduling is not a transactional outbox. Input-proof scans and finalization have not been benchmarked on large draws.

Whole-repository regression, real browser behavior, live merchants, production SQL concurrency, and production payout correctness remain unverified. Bank-transfer/manual approval remains the recommended first merchant rail, subject to operator configuration and staging approval. Logos do not establish integrations. Legacy member-to-member transfer remains fail-closed and separate from own-wallet Cash↔Win conversion.

No production migration, payout, deployment, credentials change, GitHub push, merchant activation, or automation enablement was performed.

## Artifact integrity

`Full-Code-Continuation-Files-64-69.md` contains all six files above in full and in sequence. `Full-Code-All-69-Files.zip` contains the previous 63 entries unchanged plus these six new paths. Packaging verifies every ZIP entry against its source, verifies prior entries against the previous ZIP, and verifies full source inclusion in the continuation document. Dependencies, environment files, secrets, generated builds, and the test database are excluded.

The next continuation starts at File70.
