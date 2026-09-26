# Thai Lottery — VS Code + GitHub Setup (source only)

Final clean zip: **`/home/user/thai-lottery-source.zip`**  
(1,479 files — no `vendor/`, no `node_modules/`, no `.env`, no test DB, no build cache)

---

## 1. Unzip

```bash
unzip thai-lottery-source.zip -d thai-lottery
cd thai-lottery
```

Open the folder in **VS Code**: `code .`

---

## 2. Requirements

| Tool | Version |
|------|---------|
| PHP | 8.2+ (8.3 recommended) with extensions: bcmath, sqlite3/pdo_sqlite, mbstring, openssl, tokenizer, xml, curl, zip, fileinfo |
| Composer | 2.x |
| Node.js | 20+ |
| npm | 10+ |

---

## 3. Install

```bash
# PHP dependencies
composer install

# Frontend dependencies + production assets
npm install
npm run build

# Environment
cp .env.example .env
php artisan key:generate
```

### Default .env for local SQLite (simplest)

```env
APP_ENV=local
APP_URL=http://127.0.0.1:8000
APP_LOCALE=en

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite

CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
```

```bash
touch database/database.sqlite
php artisan migrate --seed
```

> MySQL also works — set `DB_CONNECTION=mysql` + credentials in `.env`.

---

## 4. Run (VS Code terminal)

```bash
# Terminal 1 — Laravel
php artisan serve

# Terminal 2 — Vite (optional hot reload)
npm run dev
```

Open: http://127.0.0.1:8000

**Public pages:** `/` · `/results` · `/check` · `/sales-points` · `/about` · `/vision` · `/terms` · `/fees`  
**Auth pages:** `/register` · `/login` · `/dashboard` · `/account/verification` · `/account/grade`

---

## 5. Tests

```bash
php artisan test
```

Expected (as of PROMPT 3): **1112 passed, 5 skipped**, ~99.5k assertions.

Run one file:

```bash
php artisan test tests/Feature/Account/AccountServicesPagesTest.php
```

> Never run two `php artisan test` processes at the same time (SQLite file DB).

---

## 6. GitHub push (fresh repo)

```bash
cd thai-lottery
git init
git add .
git commit -m "Thai Lottery: Home + Public pages + Fees/Verify/Grade"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/YOUR_REPO.git
git push -u origin main
```

`.gitignore` already excludes: `vendor/`, `node_modules/`, `.env`, test DBs, logs, caches.

**Never commit:** `.env`, `vendor/`, `node_modules/`, `database/thai_lottery_test*`, real secrets.

---

## 7. Project map (what was built)

| Wave | Surface |
|------|---------|
| PROMPT 1 | Public Home (results, check, sales points, prizes, stats, bonuses, payments, support, trust, i18n) |
| PROMPT 2 | `/about` `/vision` `/terms` — versioned legal, en/th, PublicLegalHeaders |
| PROMPT 3 | `/fees` public + `/account/verification` + `/account/grade` (single KYC stack) |

Configs of interest:

- `config/fees.php` — fee categories (own values, not competitor)
- `config/account_grades.php` — Bronze→Platinum tiers (GLO L6/N3 excluded from discounts)
- `config/legal.php` — terms version/effective dates
- `config/glo.php` — L6 80.00 / N3 20.00 official-rule prices

---

## 8. Backup seals (workspace)

| File | Purpose |
|------|---------|
| `/home/user/thai-lottery-source.zip` | **Final clean source for GitHub** |
| `/home/user/thai-lottery-src-backup.tar.gz` | Workspace restore (tar) |
| `/home/user/PROMPT3_ACCOUNT_SERVICES_FINAL_RESPONSE.md` | A–H report PROMPT 3 |
| `/home/user/account_services_full_file_contents.txt` | Full file contents |
