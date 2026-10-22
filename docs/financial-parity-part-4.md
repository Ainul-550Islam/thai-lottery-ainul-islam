# Financial parity — installment 4: payment test-contract repairs

Date: 2026-10-14 (Asia/Dhaka). Base commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

## Scope and numbering

This installment continues after File39. It supplies File40–File49 in full, with no code elision. Previous File1–File39 numbering is retained. The combined delivery now contains 49 unique repository-relative paths. Prior installment reports describe their historical snapshots; this report is the current status.

This installment changes seven test files, adds complete validation evidence and a source audit, and records remaining blockers. It does not add a payment driver, change production gateway flags, alter operator fees, implement the missing draw finalizer, or claim production readiness.

| File | Repository-relative path |
|---|---|
| 40 | `tests/Feature/Payment/PlayerDepositApiTest.php` |
| 41 | `tests/Feature/Payment/WebhookReplayProtectionTest.php` |
| 42 | `tests/Feature/Payment/WithdrawalDestinationValidationTest.php` |
| 43 | `tests/Feature/Payment/FixturePublicationSafetyTest.php` |
| 44 | `tests/Feature/BusinessCriticalInvariantTest.php` |
| 45 | `tests/Feature/FinalProductionReadinessTest.php` |
| 46 | `tests/Feature/RealMoneyBusinessReadinessTest.php` |
| 47 | `evidence/payment-validation-part-4.txt` |
| 48 | `evidence/financial_source_preflight-part-4.json` |
| 49 | `docs/financial-parity-part-4.md` |

## What was repaired, and why

### Stripe initiation tests must not contact a real merchant

The player-deposit test class now prevents stray HTTP requests and mocks only `https://api.stripe.com/v1/checkout/sessions`. The fake returns a deterministic test session tied to the document reference and a `.test` checkout URL. There is no real merchant credential, checkout session, or provider settlement behind this response.

The successful initiation test checks that exactly one HTTP request was made, the document reference was sent, and THB 500.00 was represented as 50,000 minor units. Existing fee and idempotency assertions remain. An additional disabled-Stripe test verifies refusal before an HTTP request or deposit/payment document exists.

### Client-supplied money fields are prohibited, not silently accepted

The real API request validator already rejects client `fee`, `fee_amount`, `fee_percentage`, and `net_amount`. The old test incorrectly expected a 201 response and ignored fields. The corrected test requires HTTP 422, the application’s actual `error.details.fields` validation format, no deposit or payment rows, and an unchanged wallet. No validator restriction was removed to make the test pass.

### Signed replay fixtures must agree with the stored deposit

The replay fixture previously claimed 1000.00 for every deposit, including deposits whose actual amounts were 150.00, 250.00, and 500.00. Correct amount reconciliation appropriately refused those requests. The helper now reads the actual persisted deposit amount and currency before signing the payload.

A new negative test explicitly signs a wrong amount using the correct webhook secret. It still requires refusal, an unchanged wallet, a pending deposit, no accepted-delivery observation, and no financial transaction. A valid signature alone is not authority to change the amount on internal paper.

The route assertion now checks Laravel’s resolved parameterized middleware `ThrottleRequests:webhook`, rather than incorrectly expecting the unparameterized class string. The flood limiter remains attached; no route or middleware was removed.

### Bank tests must explicitly configure the test rail

The destination-validation fixture now enables the bank-transfer driver in test configuration only. The production default remains disabled. A complementary test disables the driver and verifies that a syntactically valid bank destination cannot create a withdrawal, reduce the wallet balance, or create a wallet reservation.

### Production fixture safety must not be judged from local test opt-ins

`phpunit.xml` intentionally enables lottery fixture lanes for local tests. The old assertion incorrectly treated that testing configuration as production configuration. Its replacement loads all four actual lottery configuration files in an isolated PHP process with `APP_ENV=production` and every fixture flag explicitly requested as true; each must still resolve to false. The existing production runtime hard-stop and non-official-source checks remain.

### Remove unsafe legacy webhook expectations instead of inventing a money writer

Two master tests referenced the absent `ProcessPaymentWebhookService` and expected an arbitrary `user_id`, `amount`, and `status=completed` payload to credit a wallet without a matching internal payment or signature. That contract is not safe to implement.

The tests now use the existing `PaymentWebhookService::processEnvelope()` and the existing real-HMAC test helper. They prove that authentic but orphan evidence remains verified/deferred, identical replay reuses the same evidence row, sightings increment to two, and neither a wallet credit nor a financial transaction is created. Positive signed, deposit-associated credit and replay behavior remains covered by `WebhookReplayProtectionTest`; it was not replaced by these negative tests.

Unused missing imports for `ReservationStatus` and model `PaymentMethod` were removed, rather than creating redundant classes. Pint also normalized formatting and removed other unused imports in the seven delivered test files.

## Verification results

All PHP suites used the SQLite test database configured in `phpunit.xml`: `database/thai_lottery_test`. The isolated foundation suite uses its own in-memory fixtures. No production migration, wallet operation, deployment, or GitHub push was performed.

Dependencies were restored from the existing lockfiles. Built assets were regenerated before conversion and final master-readiness checks. The following results are recorded in full in File47:

| Check | Result |
|---|---|
| Same-environment original Payment + Finance baseline | 129 tests, 469 assertions, 14 failures |
| Final Payment + Finance | **132 tests, 547 assertions, pass** |
| Two corrected orphan-evidence master tests | **2 tests, 16 assertions, pass** |
| Conversion feature + unit regression | **71 tests, 259 assertions, pass** |
| Isolated conversion foundation | **29 tests, 92 assertions, pass** |
| Python source-scanner regression | **11 tests, pass** |
| Pint final check | **7 files, pass** |
| PHP syntax | **7 delivered PHP files, pass** |
| Vite asset rebuild | **Pass** |
| Full three-class master-readiness baseline, with built assets | 35 tests, 111 assertions, 15 errors, 5 failures |
| Full three-class master-readiness after patch, with built assets | 35 tests, 127 assertions, **13 errors, 5 failures remain** |
| Draw chunk diagnostic suite | 16 tests, 2 assertions, **15 errors remain** |
| Static source audit | **Fail: one unresolved imported application class remains** |

The 132 Payment + Finance tests include the three new refusal regressions. The two targeted master tests are outside those directories. Passing focused suites do not make the full application ready for real money.

### Reproduce the passing checks

Only run these commands in a disposable checkout with a verified test database. Install locked dependencies and build assets first. Do not point testing configuration at a production database.

```bash
composer install --no-interaction --prefer-dist --no-scripts
npm ci --no-audit --no-fund
npm run build
php vendor/bin/phpunit tests/Feature/Payment tests/Feature/Finance
php vendor/bin/phpunit tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php --filter 'idempotent_webhook_processing|payment_webhook_replay_protection'
php vendor/bin/phpunit tests/Feature/Wallet/Conversion tests/Unit/Wallet/Conversion
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Standalone/ConversionFoundationTest.php
python3 -m unittest discover -s scripts -p test_financial_source_preflight.py
php vendor/bin/pint --test tests/Feature/Payment/PlayerDepositApiTest.php tests/Feature/Payment/WebhookReplayProtectionTest.php tests/Feature/Payment/WithdrawalDestinationValidationTest.php tests/Feature/Payment/FixturePublicationSafetyTest.php tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php tests/Feature/RealMoneyBusinessReadinessTest.php
```

### Reproduce the explicitly failing diagnostic checks

```bash
php vendor/bin/phpunit tests/Feature/BusinessCriticalInvariantTest.php tests/Feature/FinalProductionReadinessTest.php tests/Feature/RealMoneyBusinessReadinessTest.php
php vendor/bin/phpunit tests/Feature/Draw/ChunkedSettlementTest.php
python3 scripts/financial_source_preflight.py --json evidence/financial_source_preflight-part-4.json
```

## Remaining blockers and next repair scope

1. `App\Jobs\Draw\FinalizeDrawSettlementJob` remains absent. It is an actual batch-callback dependency, not an unused import. The preflight reports it and intentionally returns a failing status.
2. The current batch orchestrator, persisted settlement-run tests, and chunk worker do not share a complete compatible contract. Tests reference orchestrator `start()` and other persisted-run operations not implemented by the delivered orchestrator; the range job calls `SelectionSettlementResolver::settleOneBet()`, which is absent. The batch plan uses a custom UUID rather than a clearly persisted, owned Laravel batch proof, and its `allowFailures()` call contradicts its accompanying fail-stop comment. Static import resolution alone cannot certify these method-level requirements.
3. Fifteen draw tests currently stop earlier because their fixture attempts to publish a draw without first storing the confirmed result required by `DrawResultPublicationService`. This run therefore does not exercise or certify chunk-worker finalization. Fix the fixture before interpreting downstream failures.
4. The broader master suites still use stale method and type contracts, including integer wallet arguments to `WalletService::credit()`, absent reconciliation methods, enum-vs-string comparisons, and an outdated health response assertion. The complete errors and failures are retained in File47. They were not silently deleted or broadly rewritten in this installment.
5. The next draw repair should use the existing persisted `SettlementRun` and canonical `RealPrizeSettlementService` contract, pin result identity, prove participant coverage and settlement completion, and finalize only after an owned successful run. Do not implement a stub that blindly marks a draw settled, enables payouts, or credits wallets just to satisfy class resolution.

Live merchant integration, production secrets, multi-process SQL concurrency, end-to-end browser behavior, and whole-repository correctness remain unverified. Bank-transfer/manual approval remains the recommended first production rail, subject to operator configuration and staging validation. Logos still do not prove integrations. Legacy member-to-member balance transfer remains fail-closed; own-wallet Cash↔Win conversion is separate and its regression suites remain green.

## Delivery integrity

`Full-Code-Continuation-Files-40-49.md` contains each of the ten files in full, in the order above. `Full-Code-All-49-Files.zip` contains all previous 39 files plus these ten repository-relative paths. The packaging step verifies ZIP bytes against every source file. `current-delivery-file-list.txt` records the complete ordered manifest. No dependency folders, environment files, secrets, generated builds, or test database are included.
