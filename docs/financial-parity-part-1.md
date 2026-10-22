# Financial parity — Part 1: source preflight

## Scope and status

This part adds an offline diagnostic tool and its tests. It does not implement transfers, enable a gateway, change fees, credit wallets, repair the missing conversion engine, or deploy anything. The application is not production-approved.

Repository: https://github.com/Ainul-550Islam/thai-lottery-ainul-islam

Inspected commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`

Reference pages inspected on 2026-10-13:

- https://www.thailotto.club/
- https://www.thailotto.club/fees.php
- https://myaccount.thailotto.club/login.php

The reference's public site contains six payment-brand image links. This establishes that images are displayed, not that any gateway is operational. The member login page was inspected without signing in; authenticated transfers, settlement, PIN requirements, recipient fields and API behavior were not verified. Do not copy government branding or claim official affiliation on the basis of that site's wording.

## Selected implementation direction

1. Start payment acceptance with the repository's existing bank-transfer/manual-review lane, after the operator provisions a genuine settlement account and the application passes its runtime tests. It is a practical initial THB collection path, not instant automatic settlement.
2. Keep member-to-member Cash balance transfer, member-to-member Win balance transfer, and own-wallet Cash/Win conversion as separate operations. Public fee labels establish different categories, not a verified recipient contract.
3. Repair missing conversion sources and migrations before connecting the legacy transfer adapter. Do not point it at a nonexistent service or remove safeguards merely to stop returning 503.
4. Keep unsupported rails unavailable. PromptPay requires an acquiring-bank or aggregator notification specification; no notification contract is invented here.
5. Keep the existing quote, idempotency, wallet ownership, KYC, account-standing, transaction locking and ledger safeguards in the eventual implementation.

## Public reference fee schedule

| Operation | Publicly displayed reference fee | Implementation interpretation |
|---|---:|---|
| Cash Balance Transfer | 3.00% | Separate transfer category; recipient semantics unverified |
| Win Balance Transfer | 2.00% | Separate transfer category; recipient semantics unverified |
| Cash To Win Transfer | 1.00% | Own-wallet conversion candidate |
| Win To Cash Transfer | 1.00% | Own-wallet conversion candidate |
| Bank Cash In | 0.00% | Displayed schedule only; not authorization to change economics |
| Bank Withdrawal | 8.00% | Displayed schedule only; payout execution not verified |

The reference has blank percentages for PayPal. These are unspecified, not zero. Existing repository conversion fees default to 1.00%; no production fee was changed in this part. Other public provider labels must not become executable methods without real integrations.

## Source findings

- `routes/api.php` declares quote, execution, summary, history, detail and reversal conversion routes.
- `routes/web.php` declares the conversion page, fee preview, quote, execution and detail routes.
- These declarations are not proof of a working lane: the imported API conversion controller is absent.
- `bootstrap/providers.php` registers `WalletConversionServiceProvider`, but its file is absent. This is an expected application-boot blocker; the boot failure was not executed in this environment.
- Conversion enums, models and service implementations are absent. Existing request, DTO, repository and view files reference them.
- No `Schema::create` declarations for `wallet_conversions`, `wallet_conversion_quotes` or `wallet_conversion_audits` were found in the committed migration directory. This does not establish the state of a deployed database.
- `PlayerProfilePortalController::transferBalanceApi()` still returns `NOT_CONFIGURED` with HTTP 503. This part deliberately leaves it fail-closed.
- `PaymentGatewayManager` resolves Stripe, bKash, Nagad, Crypto and manual Bank Transfer drivers. Its PromptPay branch explicitly reports an unimplemented lane.
- Gateway configuration reads credentials from environment variables. No deployment environment was accessed, so actual merchant-key presence and live gateway status are unknown.
- Existing evidence text claiming passed conversion tests is historical material, not test evidence for this checkout.

The generated JSON enumerates every missing imported App symbol found by this scan, including all referencing files. At the inspected checkout, 20 imported App symbols lack corresponding PHP files. Some may be unused imports rather than runtime dependencies; the registered missing provider and missing routed controller are direct blockers.

## Deliverable manifest

File 1: `scripts/financial_source_preflight.py`

File 2: `scripts/test_financial_source_preflight.py`

File 3: `evidence/financial_source_preflight.json`

File 4: `docs/financial-parity-part-1.md`

All four files are supplied in full. No application financial source was modified in this part.

## Running the diagnostic

Requires Python 3.10 or newer; no third-party dependencies.

```bash
python scripts/test_financial_source_preflight.py
python scripts/financial_source_preflight.py --json evidence/financial_source_preflight.json
```

Exit codes:

- `0`: the scanner found no missing imported App files and the required conversion sources and migration declarations exist. This is not a runtime or production guarantee.
- `1`: source-completeness blockers were found.
- `2`: invalid arguments or repository-root error.

The tool never reads `.env`, boots Laravel, contacts payment providers or accesses a database. It checks simple and grouped App imports, PSR-4 paths, declared route strings and table-creation declarations. Existing directories are treated as namespace aliases, which avoids reporting Filament `Pages` imports as missing classes. It is not a PHP parser and may miss dynamically constructed classes, fully qualified non-import references, differently formatted schema declarations, or declaration/path mismatches. Runtime verification remains essential.

## Executed tests and remaining verification

- Scanner unit tests: 10 passed.
- Source preflight: exit 1, correctly identifying the incomplete checkout.
- Laravel/PHP feature tests: not run. This workspace has no PHP or Composer executable.
- Database migrations: not run.
- Live deposits, payout callbacks and financial transfers: not run.
- GitHub push and production deployment: not performed.

## Next implementation sequence

Part 2 should restore or implement the missing conversion enums, models, migrations, service provider, fee/quote/limit/history services, money-moving engine and API controller as full files. Recover original sources from the project maintainer's backup where available; no source for these paths was found in this repository's accessible git history during inspection.

Only after those sources exist should runtime tests prove decimal arithmetic, double-entry balance, quote expiry, concurrent requests, duplicate replay, conflicting keys, owner scoping, KYC refusal, insufficient funds, daily/monthly limits, rollback and reversal rules.

Part 3 should implement member-to-member transfers as their own contract and connect the compatibility endpoint with explicit request semantics. Part 4 should perform credential provisioning and provider-specific integration verification in the operator's deployment environment. These later parts are not supplied or claimed complete here.
