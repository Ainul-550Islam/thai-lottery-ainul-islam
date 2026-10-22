# Financial parity — runtime implementation and consolidated full-code delivery

## What is implemented

This installment implements the missing conversion pricing/limits/policy/quote/history services, the money-moving conversion engine, its API controller and service provider. It also supplies the missing read-only admin resource and browser assets, repairs quote-form amount preservation and fixes the Tailwind 3 build entry.

The base is repository commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be` plus the previous two installments. These are local changes, not a pushed commit or deployed application.

The prior foundation sources were restored from the complete Part-2-Files.zip artifact before verification. The consolidated archives include their current full versions, so no earlier artifact needs to be reconstructed by copying fragments.

## Numbering and complete delivery

For the requested 30-file grouping, this delivery consolidates the earlier files with the new runtime files:

- **Part 1: Files 1–30**.
- **Part 2: Files 31–39**. File 39 is the last file in this scope; there are no missing Files 40–60.

Every listed file is supplied in full. The archive contains source files at repository-relative paths. The Markdown parts contain the same complete text, not patches or abbreviated snippets. Unchanged files from the base repository and generated vendor/node_modules/build directories are not repeated.

| File | Repository-relative path |
|---:|---|
| 1 | scripts/financial_source_preflight.py |
| 2 | scripts/test_financial_source_preflight.py |
| 3 | evidence/financial_source_preflight.json |
| 4 | docs/financial-parity-part-1.md |
| 5 | app/Enums/WalletType.php |
| 6 | app/Enums/WalletConversionDirection.php |
| 7 | app/Enums/WalletConversionChannel.php |
| 8 | app/Enums/WalletConversionStatus.php |
| 9 | app/Enums/WalletConversionRejectionReason.php |
| 10 | app/Enums/WalletConversionReconciliationState.php |
| 11 | app/Enums/ConversionFeeBearer.php |
| 12 | app/Models/WalletConversion.php |
| 13 | app/Models/WalletConversionQuote.php |
| 14 | app/Models/WalletConversionAudit.php |
| 15 | database/migrations/2026_10_13_000100_create_wallet_conversion_tables.php |
| 16 | tests/Standalone/ConversionFoundationTest.php |
| 17 | evidence/financial_source_preflight-part-2.json |
| 18 | evidence/conversion-foundation-part-2-tests.txt |
| 19 | docs/financial-parity-part-2.md |
| 20 | app/Services/Wallet/Conversion/ConversionFeeCalculator.php |
| 21 | app/Services/Wallet/Conversion/ConversionLimitService.php |
| 22 | app/Services/Wallet/Conversion/ConversionPolicyGuard.php |
| 23 | app/Services/Wallet/Conversion/ConversionQuoteService.php |
| 24 | app/Services/Wallet/Conversion/ConversionHistoryService.php |
| 25 | app/Services/Wallet/Conversion/WalletConversionService.php |
| 26 | app/Http/Controllers/Api/V1/WalletConversionController.php |
| 27 | app/Providers/WalletConversionServiceProvider.php |
| 28 | app/Filament/Resources/WalletConversionResource.php |
| 29 | tests/Unit/Wallet/Conversion/ConversionFeeCalculatorTest.php |
| 30 | tests/Feature/Wallet/Conversion/ConversionFeeParityTest.php |
| 31 | tests/Feature/Wallet/Conversion/ConversionEngineSafetyTest.php |
| 32 | app/Http/Controllers/Wallet/WalletConversionActionController.php |
| 33 | resources/css/wallet-conversion.css |
| 34 | resources/js/wallet-conversion.js |
| 35 | resources/css/app.css |
| 36 | evidence/conversion-runtime-validation-part-3.txt |
| 37 | evidence/financial_source_preflight-part-3.json |
| 38 | evidence/conversion-wallet-routes-part-3.json |
| 39 | docs/financial-parity-part-3.md |

This is fresh consolidated numbering with one entry per unique path. It replaces the earlier installment numbering. Historical evidence and notes from Parts 1 and 2 describe those earlier states; current results below supersede them.

## Atomic conversion design

1. The server derives the owner and API/Web channel from authentication and the route; client wallet, owner, actor, output and fee fields remain prohibited by the existing FormRequests.
2. A stable idempotency key is mandatory at the service boundary, even if transport middleware is bypassed. Its stored value is a SHA-256 hash of the channel and exact client key. This preserves case-sensitive client identities even under case-insensitive SQL collations and avoids persisting raw keys.
3. A locked owner row serializes this owner's idempotency and rolling-limit decisions. The existing wallet lock service locks both wallets in ascending id order. Active, KYC, self-exclusion, lock, currency, available-balance and rolling-limit checks run before moving money; balance-sensitive checks are repeated under the wallet locks.
4. A quote is read under lock and compared for owner, direction, currency, amount, expiry, consumption, current rate, fee amount, output, fee bearer and tariff version. A same-version rate change is still detected.
5. The conversion row, financial transaction, both wallet mutations, balanced ledger entries, quote consumption and feature audit evidence are one database transaction. A failure anywhere rolls all of them back.
6. The canonical WalletService applies debit/credit updates; LedgerPostingService is the ledger writer. The posting debits the source player liability, credits the destination liability and credits fee revenue. Canonical account codes are 2000 and 4100, resolved per currency; no chart-of-accounts entry is invented automatically.
7. An identical retry returns the captured original outcome, without another movement, even if the old quote subsequently expires or the tariff changes. A changed request under the same key conflicts.

The windows are rolling 24 hours and rolling 30 days. Reversed originals still consume their historical allowance; reversal rows do not create additional conversion allowance. Exact available balance excludes reserved funds. A missing destination wallet can be created inside the successful conversion transaction; failure rolls that creation back as well.

## Reversal design

A channel string never grants permission. Reversal requires a persisted active operator whom WalletConversionPolicy permits to manage/reconcile the wallet. A null console actor is not privileged. The server enforces a meaningful reason, configured channel availability, the reversal window, original completion state and original ledger shape.

The reversal posts a new completed conversion with opposite wallet directions and an explicit negative fee refund. It links the original and reversal, restores the original source amount, removes only the original destination amount and reverses the fee's accounting effect. A retry replays; a second independent reversal is refused. Spent or reserved destination funds cannot be overdrawn to satisfy a reversal. Existing posted financial/ledger rows are retained; the original conversion receives reversal markers without changing its original money facts.

## Fees and deliberately revised tests

FeesPageService::resolveRate already returns null if a declared live fee rule is absent or invalid. Some old conversion tests instead demanded a static-rate fallback, which would make execution disagree with that public page. Those assertions and their descriptions were changed to demand the existing fail-closed public behavior, and additional assertions prove that no balances move when the price is missing. The test for an entirely missing price now expects the specific `fee_rule_invalid` code, not the unrelated master-switch code.

No production fee was edited. The existing default 1% conversion tariff remains a configuration input, not a hardcoded rate in the engine. The calculator refuses unpublished, disabled, ineffective, currency-mismatched or invalid fee rules. It uses exact decimal arithmetic and the public page's rounding/clamp convention.

## API and browser integration

Existing API routes now resolve:

```text
POST /api/v1/wallet/conversions/quote
POST /api/v1/wallet/conversions
GET /api/v1/wallet/conversions
GET /api/v1/wallet/conversions/summary
GET /api/v1/wallet/conversions/{reference}
POST /api/v1/wallet/conversions/{reference}/reverse
```

Quote and initial execution return HTTP 201. Execution replay returns 200. Owner-scoped reads return 404 for another member's reference. Reversal additionally requires the policy-authorized operator. Request validation and the configured quote/execute throttle remain in front of the engine. Raw request keys and arbitrary financial exception context are not returned by this controller.

The web quote redirect now preserves its amount. The browser script generates one secure request key for a form's lifetime, never replaces it immediately before submission, fetches server-priced previews, invalidates quotes after amount edits and uses native CSRF-protected POST/redirect behavior. It does not calculate fees, automatically retry money movements or substitute Math.random for secure randomness.

The missing Vite entries are supplied. The existing app.css used Tailwind 4 entry syntax against the repository's Tailwind 3 dependency; it now uses Tailwind 3 directives. Dependencies and lockfiles were not upgraded to hide that mismatch. The build passes.

## Actual verification

Executed in this workspace with PHP 8.4 and isolated test data:

| Verification | Result |
|---|---|
| Conversion unit + HTTP/engine regression suites | **71 passed; 259 assertions** |
| Prior isolated foundation suite | **29 passed; 92 assertions** |
| Python source checker regression suite | **11 passed** |
| Current-installment PHP formatting | **14 files passed** |
| JavaScript syntax check | Passed |
| Vite production asset build | Passed |
| Full application route:list --json | Passed; **558 routes** |
| Wallet subset of full route JSON | **25 routes**, supplied in full |
| Broader Payment + Finance suites | **129 tests; 14 failures remain** |
| Source-only whole-repository preflight | Exit 1; **four unresolved imported App symbols** |

The conversion suites include fault injection after ledger/balance work: an audit insert failure aborts the complete movement. They also cover inactive ledger accounts, destination balance ceilings, funds spent after quoting, reserved balances, expired/foreign/mismatched quotes, changed-key conflicts, durable audit creation, both wallet deltas, fee revenue, balanced postings, operator authorization, null console actor refusal, reversal retry and refusal after winnings were spent.

The complete broader-suite failure output is included in File 36. Failures concern fixture-publication defaults, Stripe deposit API tests, webhook replay protection and bank-withdrawal destination validation. They were not suppressed or represented as passing. A clean-baseline comparison was not performed, so this delivery does not assert that every broader failure is pre-existing.

The source checker still lists these unresolved imports outside the restored conversion module:

```text
app/Enums/ReservationStatus.php
app/Jobs/Draw/FinalizeDrawSettlementJob.php
app/Models/PaymentMethod.php
app/Services/Payment/ProcessPaymentWebhookService.php
```

Route discovery passing does not prove that all 558 endpoints work. The source checker is also not a complete PHP semantic analyser. No MySQL/PostgreSQL concurrent-process financial test, browser automation session or full-repository regression run is claimed. SQLite transactions and HTTP tests establish the tested behaviors, not production-database concurrency certification.

## Not implemented or not activated by this delivery

- No live merchant keys were provisioned, and no external payment or withdrawal was executed.
- No GitHub push, server deployment or production migration was performed.
- The legacy profile transfer endpoint still returns NOT_CONFIGURED. Member-to-member Cash/Win balance transfer is a separate product from own-wallet conversion and has not been silently mapped to this engine.
- PromptPay still needs the real acquiring-bank/aggregator contract and its integration. A logo or a QR payload does not prove settlement.
- The provider binds the runtime services, policy and throttle; it does not activate a new scheduler, gateway or cross-module notification/metrics listener. Feature audit records are written synchronously and atomically by the engine. No event-outbox delivery guarantee is claimed.
- Existing localization coverage and unrelated payment gaps are not declared complete.

## Applying and checking in staging

Keep `CONVERSION_ENABLED=false` in the deployment environment while integrating. Preserve the existing secret store and never overwrite .env with this archive. Apply all unique source files from both parts to the exact base checkout, review the new schema against the actual database and take a backup before any migration. Rebuild dependencies and assets in the deployment environment; generated vendor/node_modules/public-build output is not included.

Useful verification commands in a properly configured staging/test environment:

```bash
composer install --no-interaction --prefer-dist --no-scripts
composer dump-autoload --no-scripts
npm ci --no-audit --no-fund
npm run build
php artisan route:list --json
php vendor/bin/phpunit tests/Unit/Wallet/Conversion tests/Feature/Wallet/Conversion
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php tests/Standalone/ConversionFoundationTest.php
python scripts/test_financial_source_preflight.py
python scripts/financial_source_preflight.py --json evidence/financial_source_preflight-part-3.json
```

The feature suite uses the repository's guarded test-schema bootstrap; it must never be pointed at production. A failed broad suite or unresolved source reference is not a reason to delete a safety check. Address the remaining payment failures and run production-database locking/concurrency, permissions and operational reviews before an operator explicitly enables this lane. This package implements and verifies the listed conversion scope, not a 100% production-readiness claim.
