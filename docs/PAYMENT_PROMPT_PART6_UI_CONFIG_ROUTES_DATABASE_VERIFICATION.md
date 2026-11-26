# Payment Master Prompt — Part 6 UI, Configuration, Routes, and Database Verification

**Verification date:** 2026-11-17  
**Scope:** Every named Filament resource, configuration file, route file, and every file recursively under `database/migrations`, `database/seeders`, and `database/factories`.

## Filament resources

| Prompt path | Disposition | Verification |
|---|---|---|
| `app/Filament/Resources/DepositResource.php` | BROKEN → FIXED | Exact file exists and passes syntax. Read access and mutation permissions are separated; create/edit/delete/bulk mutation are disabled. The reference-only generic completion action was an unsafe one-operator credit path and has been removed. Human approval never credits a wallet. Credit-capable UI operations are limited to independently authenticated provider settlement/reconciliation lanes; regression coverage confirms the generic `complete` action is absent and writes no financial rows. |
| `app/Filament/Resources/WithdrawalResource.php` | PASS | Exact file exists and passes syntax. No beneficiary payload is rendered, and create/edit/delete/bulk approval are absent. Approval, reservation, dispatch, failure, and reconciliation actions delegate to canonical services. Unknown provider outcomes retain the hold and route to evidence review. |
| `app/Filament/Resources/WalletResource.php` | BROKEN → FIXED BY REMOVAL | Exact file exists and passes syntax. Resource CRUD remains disabled. The prior amount-and-evidence adjustment form was still an arbitrary one-operator balance-editing action even after delegation to `LedgerAdjustmentService`; it has been removed completely. Only lock/unlock remain. Corrections require a separately reviewed reconciliation/reversal workflow, and regression coverage confirms no adjustment action or financial write is available. |
| `app/Filament/Resources/FinancialTransactionResource.php` | PASS | Exact file exists and passes syntax. Read-only transaction history; no create/edit/delete/bulk mutation. Debit/credit totals use database decimal aggregation and BCMath strings rather than floats. |
| `app/Filament/Resources/LedgerAccountResource.php` | PASS | Exact file exists and passes syntax. Seeded chart of accounts is read-only. Displayed balances are computed from grouped ledger-entry aggregates with exact decimal arithmetic; cached balances are comparison-only. |
| `app/Filament/Resources/MemberBankAccountResource/` | EQUIVALENT / ABSENT | Exact directory is absent, matching the absence of a mutable `MemberBankAccount` model/table/controller/policy. The current design validates and encrypts an immutable destination snapshot per withdrawal. No duplicate reusable bank-account UI was invented. |

The directly coupled decision/action/page classes under the Deposit, Withdrawal, Wallet, FinancialTransaction, and LedgerAccount resource directories were also traced. Filament routes retain authenticated, bound-session, CSRF, and panel middleware.

## Configuration files

| Prompt path | Disposition | Verification |
|---|---|---|
| `config/payment.php` | PASS internally / EXTERNALLY UNVERIFIED rails | Exact file exists and passes syntax/config-cache loading. Provider capabilities, market/currency restrictions, exact decimal limits, idempotency, webhook controls, and disabled-by-default credentials are represented. Live bKash/Nagad/SCB/KBANK contracts remain external blockers. |
| `config/payment_ops.php` | PASS | Exact file exists and passes syntax. Production readiness defaults to boot refusal when real-money dependencies are incomplete; manual-disbursement SLA values contain no secrets. |
| `config/finance.php` | PASS | Exact file exists and passes syntax. Money values are decimal strings; negative balances are disabled; pessimistic locking, double-entry requirements, idempotency, KYC gate, reconciliation halt, and prize safety switch remain enabled. |
| `config/services.php` | PASS | Exact file exists and passes syntax. Credentials are environment-only; no real secret was found. External GLO and CAPTCHA requests are bounded and server-side. |
| `config/aml.php` | PASS | Exact file exists and passes syntax. Production defaults to enforce; per-flow overrides and assessment freshness are explicit. |
| `config/fraud.php` | BROKEN DOCUMENTATION/RUNTIME → FIXED | Exact file exists and passes syntax. Runtime previously treated every misspelled mode as non-enforcing because only exact `enforce` blocked. Only explicit `observe`/`log_only` now disable interception; unknown modes fail closed. Comments now describe actual behavior. |
| `config/thai_payout.php` | PASS internally / EXTERNALLY UNVERIFIED | Exact file exists and passes syntax. Global/bank switches and contract acknowledgements default false, credentials default empty, endpoints are bounded, and callback/header/idempotency configuration is explicit. |
| `config/truemoney.php` | PASS internally / EXTERNALLY UNVERIFIED | Exact file exists and passes syntax. Disabled by default and additionally requires explicit unofficial-API acknowledgement; host, limits, timeout, and daily velocity are bounded. |
| `config/slip_verification.php` | PASS internally / EXTERNALLY UNVERIFIED | Exact file exists and passes syntax. Disabled and non-auto-crediting by default; receiving accounts must be configured, provider credentials are environment-only, and timeouts are bounded. |

Configuration cache creation and clearing passed. Environment-template parity remains exact at **919 keys in each template**.

## Route files

| Prompt path | Disposition | Verification |
|---|---|---|
| `routes/api.php` | PASS | Exact file exists and passes syntax. Actual route registry confirms authenticated/active/adult/legal/throttle stacks on financial mutations and signature/replay middleware on production webhooks. Compatibility bank-binding ingress is fail-closed with 503 and performs no storage mutation. |
| `routes/web.php` | BROKEN → FIXED | Exact file exists and passes syntax. Deposit, withdrawal, and conversion mutations retained their controls, but wallet-transfer quote/execution lacked throttling. Both now use the registered `financial-critical` limiter; transfer execution also retains authenticated bound-session, active/adult/legal gates and service-level idempotency. |
| `routes/payment_ops.php` | PASS | Exact file exists and passes syntax. Entire operations surface requires web auth, bound session, `manage-payment-disbursements`, and throttling; responses are private/no-store. Manual completion still requires independently applied reconciliation evidence in its service. |
| `routes/thai_rails.php` | PASS internally / EXTERNALLY UNVERIFIED rails | Exact file exists and passes syntax. Player requests require Sanctum/active and mutation gates; SCB/KBANK payout callbacks require webhook signature/replay middleware and throttling. |
| `routes/public_services.php` | PASS | Exact file exists and passes syntax. The public verification link is signed and throttled; account privacy routes require auth, bound session, active account, throttling, ownership, and signed export links. |

Actual route registry loaded **727 routes**. Part 4 and new Part 6 regression coverage pin the critical route middleware.

## Recursive database directories

| Prompt directory | Inventory | Disposition |
|---|---:|---|
| `database/migrations/` | 195 files: 194 PHP + `.gitkeep` | PASS. Every PHP file passed syntax. Fresh SQLite migration executed all 194 migrations successfully; all 194 then reported `Ran`. No float/double monetary column declaration was found. Duplicate timestamp prefixes are deterministic filename-ordered anonymous migrations, not overwritten classes. |
| `database/seeders/` | 11 files: 9 PHP + `.gitkeep` + result-data README | BROKEN → FIXED. All PHP files pass syntax. `ProductionSimulationSeeder` contained a known password, stale columns, invented balance, and no environment/opt-in guard. It now refuses production unconditionally, requires explicit non-production opt-in, uses current schema, creates a random-password `.test` operator, and creates only a zero-balance wallet with no transaction/ledger fabrication. Default `DatabaseSeeder` remains limited to roles, chart of accounts, and data-driven result archives. |
| `database/factories/` | 56 files: 55 PHP + `.gitkeep` | PASS. Every PHP file passed syntax. Model-factory integrity tests pass. Fixtures use synthetic data; no live provider key/private-key signature was found. Factory balances are test fixtures and are not wired into production seeders. |

## Defects corrected

1. **Admin wallet adjustment was arbitrary balance editing.** The amount/evidence form was removed completely; only lock/unlock remain, and corrections require separately reviewed reconciliation/reversal.
2. **Admin deposit completion trusted a typed reference.** The generic `complete` action was removed; approval remains non-monetary and authenticated provider lanes retain completion authority.
3. **Unknown fraud modes failed open.** Only explicit observe spellings are non-enforcing; typos and unsupported values enforce.
4. **The historical “production simulation” seeder was unsafe and stale.** Hard-coded credentials, direct invented money, stale schema fields, and unguarded execution were removed.
5. **Wallet-transfer endpoints lacked route throttling.** Quote and execution now use the registered `financial-critical` limiter without weakening their existing authorization, legal, or service-level idempotency controls.

## New and updated coverage

- Added `tests/Feature/Security/PromptConfigurationRoutesAndSeederCoverageTest.php`.
- Extended `FinanceResourcesTest` to prove the arbitrary wallet adjustment and reference-only deposit completion actions are absent and perform no financial writes.
- Extended `SecurityNextHardeningVerificationTest` to prove a misspelled fraud mode fails closed.

## Executed evidence

```text
Named files + recursive database PHP files linted: 277, syntax errors: 0
Fresh SQLite migration: 194/194 executed; 194 status rows Ran
Route registry: 727 routes
Environment parity: 919 / 919 / 919, ok=true
Baseline UI/schema/factory/route selection: 78 passed, 756 assertions
Final Part 6 broad selection: 90 passed, 849 assertions
Post-Pint changed-lane selection: 65 passed, 522 assertions
Pint on all changed Part 6 PHP files: passed
Final arbitrary-money/admin-boundary regression: 46 passed, 321 assertions
Restored cross-part operations/HTTP/policy/config regressions: 8 passed, 103 assertions
Combined final administrative-finance and cross-part lane: 54 passed, 424 assertions
Final checkout-wide PHP syntax lane: 2,926 files, 0 syntax errors
```

## Conclusion

Every named Part 6 path and every recursively contained database file has a verified disposition. The missing member-bank-account resource is architecture-equivalent rather than an omitted CRUD capability. Internal UI, configuration, route, migration, seeder, and factory defects found in this segment were fixed without enabling any provider, inventing external certification, directly editing ledger history, or adding a competing financial engine.
