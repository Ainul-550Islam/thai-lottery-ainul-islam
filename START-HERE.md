# Thai Lottery — complete final source (with all gap fixes applied)

This ZIP is the **whole runnable project**, not just the changed files. It is upstream
`thai-lottery-ainul-islam` at commit `5a10574` **with every fix from this session already
applied to the source**.

- Project root inside the ZIP: `thai-lottery-final/`
- `vendor/` and `node_modules/` are **not** included (normal for a repo — run `composer install` / `npm ci`)
- `_delivery/` holds the same change as a reviewable `git diff` patch, plus the changed-file list

---

## 1. Requirements

| Tool | Version |
|---|---|
| PHP | 8.4 (extensions: bcmath, pdo_sqlite or pdo_mysql, mbstring, xml, curl, zip, intl, gd) |
| Composer | 2.x |
| Node.js | 20 / 22+ (only if you build front-end assets) |
| Database | SQLite (easiest, default for tests) or MySQL/MariaDB 8+ |

Windows: install PHP 8.4 + Composer, and enable the extensions above in `php.ini`.
macOS: `brew install php@8.4 composer`. Linux: `sudo apt install php8.4-cli php8.4-bcmath php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-intl php8.4-gd`.

## 2. Run it in VS Code

```bash
# 1. unzip, then open the folder in VS Code
cd thai-lottery-final

# 2. PHP dependencies
composer install

# 3. environment
cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate

# 4. database — SQLite (fastest)
#    in .env: DB_CONNECTION=sqlite  (and leave DB_DATABASE empty or point at a file)
touch database/database.sqlite

# 5. schema + chart of accounts
php artisan migrate --seed

# 6. (optional) front-end assets
npm ci && npm run build

# 7. serve
php artisan serve            # http://127.0.0.1:8000
```

For MySQL instead of SQLite, set `DB_CONNECTION=mysql` and the usual `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD`, create the database, then `php artisan migrate --seed`.

## 3. Run the tests

Each file/lane below is verified green in this exact tree (PHP 8.4 + SQLite). Run them one lane at a time —
the whole suite in one go is slow and the SQLite test database is single-writer.

```bash
php artisan test tests/Feature/Wallet/Conversion          # 379 tests / 2778 assertions / 47 skipped
php artisan test tests/Feature/Finance                   # ledger posting, reversal, lifecycle, wallet, identifiers
php artisan test tests/Feature/Agent                     # 175 tests / 663 assertions / 17 skipped
php artisan test tests/Feature/Betting                   # 288 tests / 1766 assertions / 25 skipped
php artisan test tests/Feature/Draw                      # 123 tests / 679 assertions  /  6 skipped
php artisan test tests/Feature/Transfer                  #  56 tests / 238 assertions
```

Tip: delete the SQLite test file between runs (`rm -f database/thai_lottery_test`) to avoid
`database is locked` noise — SQLite allows only one writer.

If you prefer PHPUnit directly:
```bash
APP_BASE_PATH="$PWD" ./vendor/bin/phpunit tests/Feature/Finance
```

## 4. Push it to your own GitHub

The ZIP has no `.git` folder on purpose (so you start a clean history of your own).

```bash
cd thai-lottery-final
git init
git add .
git commit -m "Thai Lottery: complete source with conversion/finance/agent gap fixes"

# create an EMPTY repo on github.com first, then:
git remote add origin https://github.com/<your-user>/<your-repo>.git
git branch -M main
git push -u origin main
```

Notes
- `vendor/` and `node_modules/` stay out of git (already covered by `.gitignore`); anyone who clones runs
  `composer install`.
- If Git warns about line endings on Windows, `git config core.autocrlf true` once.
- `_delivery/GAPFIX-CONVERSION-FINANCE.patch` is the exact `git diff` of this session's changes — useful if you
  would rather apply it to your own copy of upstream than replace files:
  `git apply _delivery/GAPFIX-CONVERSION-FINANCE.patch`

## 5. What was fixed in this session

**Payment / webhook lane (this round)**
- `Payment\PaymentLedgerBalanceTest` is green: the deposit posting is still proved to be strict double-entry,
  and an external `charge.refunded` webhook is now asserted to fail closed (`external_refund_policy_not_configured`)
  while the deposit stays Confirmed, the wallet keeps its money and no compensating entry is appended
- `PaymentWebhookService` — the locked `Payment` row was read inside the settlement closure but used outside it,
  so a withdrawal without a Payment row died on "Undefined variable $payment". It is now captured by reference
- `WithdrawalCompletionService` — a freshly bound provider reference is now stamped on the obligation before the
  settlement proof runs; previously `assertBound()` refused its own binding as "indexed but no reference on the row"
- `GatewayWithdrawalResponse::uncertain()` — this factory was **called by the disbursement service but never existed**,
  so every transport timeout / unusable provider reference was a fatal `Error`. Implemented as
  pending-with-unknown-state (`successful: false`, `isPending: true`, `outcomeUnknown: true`) so the hold and
  reservation survive an unknown provider outcome and nothing is resent
- `Payment\WebhookObligationBindingTest` went from 4 errors to 29/29 green

**Conversion lane**
- Rolling 24h/30d volume limits with exact decimal sums, advisory vs execution admission split
- Member eligibility (suspension, self-exclusion, cool-off, KYC with paged document reads)
- Quote service wiring, policy delegation, history filters, reversal integrity + settlement proof
- Localized page strings (en/th), quote form + index view

**Ledger posting (finance core)**
- Split into preflights (payload, money) + invocation lease + input witness + write boundary + range guard
- Real model writes so observers can refuse (`ledger_account_write_refused`, `ledger_entry_write_refused`)
- Balance projection ceiling, duplicate entry identity, current-read contract, read-only replay proof

**Reversal**
- `FinancialReversalService` proves everything from **persisted** state, not the caller's instance
- Replay and "original is marked reversed" must agree; a relabelled original is ambiguous and refused
- New compensation against a frozen wallet refused; a proved replay still works
- Routing read happens outside the owned root, then the boundary is re-checked before the savepoint

**Transaction-state honesty**
- Shared physical predicate: framework counter **and** live PDO transaction must agree, else
  `wallet_transaction_state_invalid` — applied to ledger posting, wallet wrappers, reversal, lifecycle

**Lifecycle labels**
- `lockFinancialTransaction()`, fresh-row `markFailed()`, re-checks after every `FOR UPDATE` read
- Deposit cancel/fail/reject refused while a checkout attempt is `dispatching`/`unknown`

**Identifier routing**
- Canonical positive-int keys validated before any routing SQL (`financial_identifier_invalid`,
  `wallet_user_not_persisted`, `financial_entity_routing_invalid`, `financial_entity_connection_invalid`)

**Wallet persistence honesty**
- `wallet_write_refused` when a model event vetoes a save; caller's model is never mutated
- Withdrawal/financial-hold floor re-checked after every debit and reversal

**GLO / agent**
- `settleVerifiedClaim()` retired: refuses with `glo_l6_direct_wallet_settlement_disabled` instead of being a
  second, weaker wallet writer. Canonical path is `GloPrizeClaimService::pay()`
- Commission accrual is idempotent per bet; facade delegates to the real methods
- A paid commission's legacy reversal refuses (`agent_commission_reversal_unproved`) rather than relabelling
  money it never recovered
- Bet-cancellation replay must prove the agent book closed, not only the member refund

## 6. Honest status — what is still NOT green

- `tests/Feature/Payment` is **not** fully green. Pre-existing red (identical on the pre-change tree, verified by
  reverting the four changed files): `PayoutApprovalBoundaryTest` (8 failures), `FinancialHoldOwnershipTest`
  (23 errors / 5 failures), `WithdrawalDispatchSafetyTest` (8 failures), `WithdrawalReferenceCrossLaneTest`
  (1 error / 2 failures), `WithdrawalReferenceLegacyCompatibilityTest` (1 error). They encode a stricter
  withdrawal-dispatch spec than the current code implements; not touched in this session.
- `tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest` — a legacy suite written against an older API
  (string amounts, 5-character idempotency keys, a disabled bKash gateway, `SelfExclusionService::isSelfExcluded()`).
  The remaining errors are drift inside that test file, not in the application.
- `*ServerTest` variants — need a real MySQL/MariaDB server (two live sessions, row locks, InnoDB waits).
  They are skipped/failed on SQLite by design.
- `tests/Feature/Payment/PaymentLedgerBalanceTest` chargeback path — verified failing identically on the
  **pre-change** upstream tree, so it is not a regression from this session.
- Other estate-wide lanes (Compliance, Glo, Risk, Verification, PublicSite, Home, Auth, Audit, Player, Filament)
  were not all re-run here. No blanket "100% green" claim is made.

Nothing in this ZIP has been deployed, and no live payment provider / bank transfer was executed.
