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
| PHP | 8.3 with `intl`, `pdo_sqlite` (or `pdo_mysql`), `mbstring`, `bcmath`, `zip`, `sodium`, `curl`, `xml` |
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

## Tests

```bash
php artisan test
```

Expected: **1448 tests, 0 failures, 5 skipped.**

The five skips are honest, not broken:

- 1 asserts a `Pdo\Mysql` constant that only exists on PHP 8.4+;
- 3 need real multi-process row locking, which SQLite in memory cannot give;
- 1 needs the compiled Rust verifier (below).

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
