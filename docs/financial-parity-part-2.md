# Financial parity — Part 2: conversion foundations

## Continuation and boundaries

Part 1 supplied Files 1–4. This part supplies Files 5–21, each in full. There was no numeric value for the literal `N`, so continuation follows the prior file manifest, not an invented line offset.

Base commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. The new files and the WalletType/scanner changes are local working-tree changes, not pushed commits.

This part implements conversion enums, three persistence models, an additive migration and isolated foundation tests. It does not implement the money-moving engine, API controller, provider binding, member-to-member transfers or a live gateway. No production database was accessed. No balance, existing fee, ledger row or gateway flag was changed.

## Full file manifest

| File | Path | Change |
|---:|---|---|
| 5 | app/Enums/WalletType.php | Adds Win identity and labelKey; preserves existing deposit, withdrawal and betting permissions |
| 6 | app/Enums/WalletConversionDirection.php | Adds direction mapping and existing DTO/view helpers |
| 7 | app/Enums/WalletConversionChannel.php | Adds transport provenance; does not grant permission |
| 8 | app/Enums/WalletConversionStatus.php | Adds explicit one-way transitions |
| 9 | app/Enums/WalletConversionRejectionReason.php | Adds stable refusal codes and HTTP mappings |
| 10 | app/Enums/WalletConversionReconciliationState.php | Adds reconciliation states |
| 11 | app/Enums/ConversionFeeBearer.php | Adds fee ownership metadata |
| 12 | app/Models/WalletConversion.php | Adds decimal casts, lifecycle checks, immutable settled facts and relationships |
| 13 | app/Models/WalletConversionQuote.php | Adds immutable quote terms and one-way consumption/expiry lifecycle |
| 14 | app/Models/WalletConversionAudit.php | Adds append-only Eloquent audit records |
| 15 | database/migrations/2026_10_13_000100_create_wallet_conversion_tables.php | Creates the conversion, quote and audit tables; refuses rollback when any contains evidence |
| 16 | tests/Standalone/ConversionFoundationTest.php | Adds 29 isolated SQLite tests |
| 17 | scripts/financial_source_preflight.py | Improves class-versus-child-namespace detection |
| 18 | scripts/test_financial_source_preflight.py | Includes the additional scanner regression test |
| 19 | evidence/financial_source_preflight-part-2.json | Full updated missing-source findings |
| 20 | evidence/conversion-foundation-part-2-tests.txt | Actual PHPUnit and scanner test output |
| 21 | docs/financial-parity-part-2.md | This complete implementation note |

## Important implementation choices

- Primary is the Cash wallet. Win has its own `win` identity. Win is not given direct deposit, withdrawal or betting permission in this part; cash conversion must eventually pass through the engine.
- Status transitions are explicit. Pending cannot jump directly to completed. Completed can only transition to reversed with linked reversal metadata. Failed, expired and reversed cannot return to active processing.
- Money is represented as decimal strings at the application boundary and DECIMAL columns in the schema. Models validate precision and input/output/fee conservation. These are persistence checks, not proof of ledger posting or sufficient funds.
- Reversal rows may record a negative fee to express a fee refund, linked to their original row. Ordinary conversion rows cannot have negative fees. The reversal engine itself is not implemented here.
- A quote closes once. Its owner, price, tariff version, issue time and expiry cannot be edited through model instances. Expiry is inclusive at the cutoff. The lifecycle `open` scope includes overdue unconsumed rows so the existing expiry sweep can find them; `isOpen()` also checks the clock.
- Unique `(user_id, idempotency_key)` and reversal links provide database backstops. Replay/fingerprint comparison, key normalization, concurrency locks and transaction rollback still belong to the unfinished engine.
- Foreign keys restrict deletion of owners, wallets and linked financial records. Rollback refuses to erase populated conversion tables.
- Audit/model instance writes are guarded by Eloquent events. Raw SQL and bulk query-builder writes bypass those guards. Deployment database permissions and the engine's controlled write boundary are still necessary; this part does not install database immutability triggers or cross-wallet ownership constraints.
- The migration is intentionally not idempotent through `hasTable` skipping in `up()`: a conflicting pre-existing schema must be investigated, not silently accepted. Laravel's migration history is responsible for running it once.

## Actual validation

The workspace was provisioned with PHP 8.4 and Composer for verification. Dependencies were installed using `composer install --no-interaction --prefer-dist --no-scripts --no-progress`, so installation did not run application deployment hooks.

Executed:

```bash
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Standalone/ConversionFoundationTest.php
python scripts/test_financial_source_preflight.py
python scripts/financial_source_preflight.py --json evidence/financial_source_preflight-part-2.json
```

Results:

- PHP foundation suite: **29 tests, 91 assertions passed**.
- Python scanner suite: **11 tests passed**.
- Laravel Pint: **12 PHP files passed** after formatting.
- PHP syntax checks: all 12 supplied PHP files passed.
- Static source preflight: **exit 1**, still correctly refusing the incomplete checkout.
- `APP_ENV=testing APP_DEBUG=false php artisan route:list --path=wallet`: **exit 1**, reporting the missing `App\Http\Controllers\Api\V1\WalletConversionController`. Route declaration is still not a working endpoint.

The PHP suite uses a fresh in-memory SQLite database and minimal parent-table fixtures per test. It does not boot the full application. It tests the actual added migration and models, including duplicate owner keys, restricted deletion, state transitions, decimal validation, quote expiry/immutability, fee-refund shape and safe rollback. It does not test concurrent financial movements or live payment settlement. SQLite does not offer the same DECIMAL storage semantics as MySQL/PostgreSQL, so production-engine database testing is still required.

## Updated missing-source result

The scanner now distinguishes a namespace alias such as `Pages\ListRecords` from a class reference such as `WalletConversionResource::class`. An existing child directory no longer hides the missing parent class. This corrects a limitation of the Part 1 scanner; its original JSON is retained as historical evidence.

The revised scanner finds **12 remaining imported App symbols with missing PHP files**. The required conversion models/enums added in this part now exist and all three table creation declarations are present. The remaining list is supplied without omission in File 19. Counts are not a production readiness score: some imported symbols may be unused, and dynamic references can still escape a source scan.

Directly relevant remaining work:

1. ConversionFeeCalculator and ConversionLimitService, matching the public fee source and configured envelopes.
2. ConversionQuoteService and ConversionHistoryService, preserving ownership and expiry rules.
3. WalletConversionService and the policy implementation, with decimal accounting, paired-wallet locking, durable idempotency, locked rolling-limit checks and balanced ledger postings.
4. WalletConversionServiceProvider and the API controller.
5. The missing admin WalletConversionResource.
6. Full application regression tests, production-database tests and operational review before enabling this lane.

Other remaining imports include ReservationStatus, FinalizeDrawSettlementJob, PaymentMethod and ProcessPaymentWebhookService. They are not fabricated or removed in this part merely to make the scanner pass.

## Deployment warning

Do not treat the archive as a live-payment deployment. Do not run the new migration against an unexplored production database while the module remains incomplete. Review existing schemas and migration history first, back up the database, complete the engine and run full financial regression tests in staging. Merchant credentials remain private deployment inputs, not source files or chat messages.

The next code installment should implement pricing, limits, quote/history services and the guarded engine/controller/provider path. Bank transfer with manual approval remains the selected first external payment lane; automated PromptPay still needs the real acquiring-bank/aggregator contract.
