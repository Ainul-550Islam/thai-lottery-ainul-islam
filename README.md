# Thai Lottery Platform

Laravel 11 / PHP 8.3. A lottery results, account and support platform with
four independent public result lanes, an operator side, and a Rust integrity
verifier for one lane.

**This is an independent platform.** It is not the Government Lottery Office,
not an official GLO agent and not a government portal. GLO product rules are
referenced for prize-rule compatibility only.

---

## Requirements

| Tool | Version |
|---|---|
| PHP | 8.3 with `intl`, `pdo_sqlite` (or `pdo_mysql`), `mbstring`, `bcmath`, `zip`, `sodium`, `curl`, `xml`, `gd` |
| Composer | 2.x |
| Node.js | 20+ with npm |
| Rust | 1.98+ — **optional**, only for the Weekly lane's integrity verifier |

---

## Setup

```bash
# 1. PHP dependencies
composer install

# 2. Environment
cp .env.example .env
php artisan key:generate

# 3. Database (SQLite is the quickest start)
touch database/database.sqlite
php artisan migrate --seed

# 4. Front-end assets — REQUIRED
#    Blade pages resolve their CSS/JS through the Vite manifest. Without this
#    step every page returns a 500, because the manifest does not exist yet.
npm install
npm run build

# 5. Run it
php artisan serve
```

For `.env`, set at minimum:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database/database.sqlite
```

---

## Payment integration

The gateway drivers live in `app/Services/Payment/Drivers/` and are bound by
`PaymentGatewayManager` (**Stripe**, **bKash**, **Nagad**, **Crypto**,
**Bank Transfer**). Two invariants hold everywhere:

1. **Fail closed.** A gateway is inert until its `*_ENABLED` flag AND its
   credentials are set. Deposit and withdrawal paths resolve drivers through
   the capability guard (`depositDriver()` / `withdrawalDriver()`), which
   refuses disabled, non-capable or misconfigured providers **before any
   record is created** — the stable refusal codes are `payment_gateway_disabled`,
   `payment_gateway_not_deposit_capable`, `payment_gateway_not_withdrawal_capable`,
   `payment_method_not_allowed`, `unsupported_gateway_currency`.
2. **Only webhooks move money.** Browser returns are presentation only:
   `/payment/success|failure|cancel|pending` render the AUTHORITATIVE internal
   payment status and never credit anything. Provider state changes arrive at
   `POST /api/payment/webhooks/{gateway}` and are signature-verified.

Key environment variables (all empty by default — see `.env.example`):

- `STRIPE_ENABLED` / `STRIPE_KEY` / `STRIPE_SECRET` / `STRIPE_WEBHOOK_SECRET`
- `BKASH_*`, `NAGAD_*`, `CRYPTO_*` (credentials per provider)
- `PAYMENT_SUCCESS_URL` / `PAYMENT_FAILURE_URL` / `PAYMENT_CANCEL_URL` —
  the browser-return paths registered in `routes/web.php` from the same
  config block the drivers build provider URLs from
- `BANK_TRANSFER_ENABLED` + `BANK_TRANSFER_BANK_NAME` /
  `BANK_TRANSFER_ACCOUNT_NUMBER` / `BANK_TRANSFER_ACCOUNT_NAME` — the REAL
  manual settlement account. Missing any of them makes the bank-transfer
  gateway refuse deposits (fail closed); the code contains no placeholder
  bank identity.

Currency support is driver-honest: Stripe settles THB/USD, bKash/Nagad are
BDT rails, Crypto is USD, manual bank transfer is THB. A THB deposit through
a BDT-only rail is refused, not silently converted.

**A green test suite does NOT mean a real provider account is configured** —
tests fake the provider HTTP layer. Before launch, register the real webhook
endpoints with each provider and run the cutover checklist in
`deployment/CUTOVER-CHECKLIST.md`.

PromptPay is **future-only**: configuration is on file, but no driver is
bound, the `PaymentMethod` enum has no case for it, and it can never be
selected or advertised. `PromptPayIntegrationTest` locks that state.

---

## Tests

```bash
php artisan test
```

Expected: **1825 tests, 0 failures** (verified 2026-09-29: 1825 passed /
7 skipped / 0 failed — 106,156 assertions). The historical baselines recorded
in the audit trail were 1550 → 1558 → 1640/1643 → 1677 → 1698 → 1712 as
prompts and audit fixes landed; this README now tracks the latest verified
run instead of the original delivery count. The FINAL-audit pass added the
payment capability guards, browser callback surface, bank-transfer
configuration contract, sitemap, legacy-bridge coverage and the player-view
localization tests. The skip count depends on the build, and
every skip is honest, not broken:

| Skips | Environment |
|---|---|
| 4 | PHP 8.4 with `pdo_mysql` loaded |
| 5 | PHP 8.2/8.3 with `pdo_mysql` loaded (reference setup) |
| 7 | no `pdo_mysql` extension (e.g. a bare sandbox) — both `DatabaseDriverOptions` tests skip |

Regardless of build, 3 skips need real multi-process row locking (SQLite in
memory cannot give it) and 1 needs the compiled Rust verifier (below); the
remaining 0–2 are the `Pdo\Mysql`/`PDO::MYSQL_ATTR_SSL_CA` availability tests
above.

`npm run build` must have been run first, or page tests fail with 500s.

### The optional Rust verifier

```bash
cd security/weekly-result-integrity
cargo test --locked
cargo build --release --locked
```

Without the compiled binary the Weekly lane still works: it reports
`VERIFIER_UNAVAILABLE` and says so, rather than claiming a verification it did
not perform.

---

## What is in here

### Public result lanes

Four separate lanes. They share an engine but not a table, and none of them
touches a wallet, a stake or a payout.

| Lane | Path | Fields | Notes |
|---|---|---|---|
| National | `/national-lottery` | 1st Prize, 3 Up, 2 Up, 3 Front, 3 After, 2 Down | 3 Front / 3 After are lists |
| Weekly | `/weekly-lottery` | 6 Ball, 3 Ball, 2 Ball | has the Rust integrity verifier |
| Mega | `/bingo-lottery` | 6 Mega, 3 Mega, 2 Mega | code says `bingo`, the page says Mega |
| PCSO | `/pcso-lottery` | 6D, 4D, 3D, 2D | **several draws per date**, each with its own time |

Each lane has a landing page with the latest year's results, a year archive,
an exact-match search and a per-draw detail page, plus a JSON surface under
`/api/v1/<lane>/`.

### Other public surfaces

`/` · `/about` · `/vision` · `/terms` · `/fees` · `/prize-verification` ·
`/discounts` · `/results` · `/check` · `/sales-points` ·
`/account-grades` · `/account-verification-guide` · `/contact`

### Fees

`/fees` renders the catalogue in `config/fees.php` as six grouped tables
(account, transfers, withdrawal, cash-in, agent, maintenance) with per-provider
rows (bank / Skrill / Neteller / PayPal / Perfect Money). The same projection
is served as JSON at `GET /api/v1/fees`, and `POST /api/v1/fees/preview`
calculates a fee for a whitelisted category/provider + `base_amount` —
rate-limited, server-authoritative, no fee field accepted from the client.

`FeesPageService` is the only place that builds the public projection and the
only place that calculates a preview fee: bcmath only, scale-2 half-up, never a
float.

---

## Things worth knowing before you change anything

**Lottery numbers are strings, everywhere.** `'004615'` is not `4615`, and
`'09'` is not `9`. There is no `(int)`, `intval()`, `parseInt()` or numeric
cast anywhere on a result value, and tests assert this at the database, the
API and the rendered page separately — a single end-to-end assertion would
pass even if one layer repaired the value.

**Absent is not zero.** A draw that published nothing stores `NULL` and
renders a translated "not published"; a PCSO category that did not run renders
"Off". `000000`, `000` and `00` are results a real draw can produce, so they
are never used to mean absence.

**Nothing claims to be official unless it is.** A fixture import is
`FIXTURE_ONLY` on every page that shows it, and configuring an endpoint does
not promote it. `OFFICIAL_SOURCE_VERIFIED` requires a configured provider that
actually delivered the payload.

**"Stored" and "emailed" are different facts.** The contact form reports
`SENT` only when a configured mail provider accepted the message. Otherwise it
says the message was received and a reply may take longer — which is true.

**A lane describes itself.** `App\Lottery\Schema\LaneSchema`, built from each
lane's config, tells the shared engine the field list, the widths, which
fields are searchable and whether a date alone identifies a draw. Add a field
in config, not in the engine.

**Money is BCMath or decimal strings.** No floats.

**The published fee schedule cannot drift from the charged fee.** The generic
and bank withdrawal/cash-in rows on `/fees` do not carry their own numbers —
they mirror `finance.withdrawal.fee_percentage` /
`finance.deposit.fee_percentage` (env: `FINANCE_WITHDRAWAL_FEE_PERCENTAGE`,
`FINANCE_DEPOSIT_FEE_PERCENTAGE`) at render time, and a test fails if the page
and the engine disagree. Provider rows with no live execution lane (Skrill,
Neteller, PayPal, Perfect Money) are display-only configuration; PayPal stays
`NOT_CONFIGURED` until a percentage is actually decided — nothing guesses a
value. Change display-only values in `config/fees.php`; never hand-edit a
Blade template to "fix" a fee.

---

## Layout

```
app/
  Contracts/Lottery/      shared lane interfaces
  Lottery/Schema/         lane schema value objects + factory
  Models/Support/         shared draw / result / version base models
  Services/Lottery/       one thin adapter per lane
    Support/              the shared result / history / search / import engine
  Services/Support/       contact workflow, delivery, spam, privacy
config/                   one file per lane: national_, weekly_, bingo_, pcso_
security/
  weekly-result-integrity/  Rust crate: canonicalise + SHA-256, no network
tests/Feature/            one suite per lane, plus seam and parity suites
```

---

## Pushing to GitHub

This archive has no `.git` directory, so start a fresh history:

```bash
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin git@github.com:<you>/<repo>.git
git push -u origin main
```

`vendor/`, `node_modules/`, `.env`, `public/build/`, `storage` caches and the
Rust `target/` are all covered by `.gitignore` and are rebuilt by the setup
steps above.
