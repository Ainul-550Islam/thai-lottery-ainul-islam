# THAILOTTO ENTERPRISE WAGERING PLATFORM — AUTHORITATIVE PRODUCTION CUTOVER RUNBOOK & CHECKLIST

**Execution Status Legend:**
- `[x] CODE VERIFIED` — Code implementation, security boundaries, and static checks passed.
- `[ ] NOT VERIFIED — OPERATOR INPUT REQUIRED` — Live production parameter / credential / operator execution pending.
- `[!] BLOCKED` — Cutover blocker identified.

---

## 1. Release Specification & Environment Provenance

| Specification Field | Current Production Value / Target | Verification Status |
| :--- | :--- | :--- |
| **Release Commit SHA** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Pending Git Release Tag |
| **Artifact SHA-256 Checksum** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Pending Build Pipeline |
| **Target PHP Version** | `PHP 8.2+` (with `bcmath`, `pdo_mysql`, `redis`, `sodium`) | `[x] CODE VERIFIED` |
| **Laravel Framework Version** | `Laravel 11.x` | `[x] CODE VERIFIED` |
| **Node.js & NPM Version** | `Node.js 20.x` / `NPM 10.x` | `[x] CODE VERIFIED` |
| **Rust / Cargo Engine** | `Cargo 1.75+` (GLO verification engine) | `[ ] NOT VERIFIED — HOST ENVIRONMENT` |
| **Database Engine & Version** | `MySQL 8.0.16+` / `MariaDB 10.6+` (InnoDB, strict mode) | `[x] CODE VERIFIED` |
| **Queue Backend & Driver** | `Redis` / `database` (Supervisor workers: `high`, `default`, `low`) | `[x] CODE VERIFIED` |
| **Cache Backend & Driver** | `Redis` (isolated database / prefix) | `[x] CODE VERIFIED` |
| **Production APP_URL** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Pending Live Domain DNS |
| **Session Cookie Domain** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Pending Live Domain DNS |
| **CDN & Reverse Proxy** | Cloudflare / AWS CloudFront / Nginx Ingress | `[ ] NOT VERIFIED — OPERATOR INPUT REQUIRED` |
| **Pre-Cutover Backup ID** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Pending Pre-Deploy Dump |
| **Pre-Cutover Backup Checksum** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Pending Dump Generation |
| **Rollback Target Artifact** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Previous Release Tag |
| **Cutover Deploy Timestamp** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Recorded at Execution |
| **Lead Operator Sign-off** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Operator Signature |
| **Post-Cutover Verification Time** | `NOT VERIFIED — OPERATOR INPUT REQUIRED` | `[ ]` Post-Crawl Sign-off |

---

## 2. Release Artifact & Dependency Verification

- [x] **Reproducible Dependency Strategy**:
  ```bash
  composer install --no-dev --optimize-autoloader --no-interaction
  npm ci
  npm run build
  ```
- [x] **Vite Manifest Production Check**: Verify `public/build/manifest.json` exists and maps compiled bundles.
- [x] **Sensitive Secrets Isolation**: Confirm no `.env` files, debug tokens, or private credentials are included in the deployed repository artifact.
- [x] **Fixture Hard-Stop**: Confirm no fixture data or artificial results are activated for production lanes.

---

## 3. Environment Security & Storage Isolation

- [ ] **Environment Configuration (.env)**:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_KEY=<generated fresh 32-byte base64 key>`
  - `SESSION_SECURE_COOKIE=true`
  - `SESSION_HTTP_ONLY=true`
  - `SESSION_SAME_SITE=lax`
- [x] **Private KYC Directory Isolation**: Verify `/storage/app/kyc_private/` is strictly inaccessible via public HTTP.
- [x] **TLS & HSTS Headers**: `SecurityHeaders` middleware enforces HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, and strict Referrer-Policy.
- [x] **Trusted Proxies Configuration**: `TrustProxies` middleware configured to parse `X-Forwarded-For` and `X-Forwarded-Proto`.

---

## 4. Database Pre-Cutover Backup & Migration Protocol

- [ ] **Step 1: Execute Full Pre-Deployment Database Dump**:
  ```bash
  mysqldump --single-transaction --quick --lock-tables=false -u $DB_USER -p $DB_NAME | gzip > /backups/db-pre-cutover-$(date +%s).sql.gz
  sha256sum /backups/db-pre-cutover-*.sql.gz
  ```
- [ ] **Step 2: Test Database Restore on Staging Mirror**: Verify backup can be imported cleanly before running migrations.
- [ ] **Step 3: Execute Database Migrations**:
  ```bash
  php artisan migrate --force
  ```
- [x] **Step 4: Seed Canonical Reference Data ONLY**:
  ```bash
  php artisan db:seed --class=RoleAndPermissionSeeder --force
  php artisan db:seed --class=LedgerAccountSeeder --force
  ```
  *(Never seed synthetic results, fake users, or mock wallets into production).*

---

## 5. Real Lottery Data & GLO Invariants Verification

- [ ] **Historical Data Provenance (National / Weekly / Mega / PCSO)**:
  - Import verified result bundles with SHA-256 provenance checksums.
  - Verify draw dates, winning number digit strings, and timezone integrity (`Asia/Bangkok`).
- [x] **GLO L6 Proportional Arithmetic**:
  - Full-sale allocation: 80.00 THB ticket price, 60% prize pool.
  - Stamp duty: $\lceil \text{Gross} / 200 \rceil$ THB (1 THB per 200 THB or fraction); Income Tax exempt.
  - Verified proportional calculation on unsold tickets (never fixed-prize fallback).

---

## 6. Payment Providers Real-World Gateway Checks

| Provider Lane | Production Webhook URL | Withdrawal Capability | Replay Protection | Live Check Status |
| :--- | :--- | :--- | :--- | :--- |
| **bKash** | `/api/payment/webhooks/bkash` | Automated B2C Payout / Manual Fallback | Concurrency Lock + Idempotency Table | `[ ] NOT VERIFIED — PROVIDER ACCESS REQUIRED` |
| **Nagad** | `/api/payment/webhooks/nagad` | Manual Disbursement Review | Concurrency Lock + Idempotency Table | `[ ] NOT VERIFIED — PROVIDER ACCESS REQUIRED` |
| **Crypto** | `/api/payment/webhooks/crypto` | Manual Cold-Wallet Review | Concurrency Lock + Idempotency Table | `[ ] NOT VERIFIED — PROVIDER ACCESS REQUIRED` |
| **Bank Wire** | N/A (Manual Slip / Bank Settlement) | Manual Operator Wire Approval | Single-submission reservation lock | `[ ] NOT VERIFIED — OPERATOR INPUT REQUIRED` |

*(Note: Live gateway verification requires operator entry of real merchant credentials).*

---

## 7. Queue Workers & Crontab Scheduler

- [ ] **Supervisor Queue Worker Configuration**:
  ```ini
  [program:thailotto-worker]
  process_name=%(program_name)s_%(process_num)02d
  command=php /var/www/thailotto/artisan queue:work --queue=high,default,low --tries=3 --timeout=90 --sleep=3
  autostart=true
  autorestart=true
  numprocs=4
  user=www-data
  redirect_stderr=true
  stdout_logfile=/var/log/supervisor/thailotto-worker.log
  ```
- [ ] **System Cron Entry**:
  ```cron
  * * * * * cd /var/www/thailotto && php artisan schedule:run >> /dev/null 2>&1
  ```
- [x] **Optimization Caches**:
  ```bash
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  ```

---

## 8. Health, Observability & Live Probes

- [x] **Liveness Probe**: `GET /up` and `GET /health` (`isLive()`) -> returns `HTTP 200` with `status: "UP"`.
- [x] **Readiness Probe**: `GET /health` (`isReady()`) -> verifies Database, Cache, Storage, and Queue connectivity. Returns `HTTP 503` if any core dependency fails.

---

## 9. Live Smoke Test Checklist

- [ ] **Public Site Smoke Test**: Crawl `/`, `/results`, `/ticket-check`, `/about`, `/terms`, `/privacy`, `/contact`, `/sitemap.xml`, `/robots.txt` in both EN and TH.
- [ ] **Member Flow**: Register -> Login -> View Dashboard -> Deposit -> Place Bulk Bet -> View Bets -> Withdraw.
- [ ] **Legacy 301 Redirect Bridge**: Verify legacy `.php` paths redirect with HTTP 301 to modern canonical routes.
- [ ] **Webhook Idempotency Test**: Post duplicate signed webhook payload -> verify zero duplicate wallet credit.

---

## 10. Executable Rollback Runbook (In Case of Abort)

If an unrecoverable P0 issue occurs during cutover, execute the rollback immediately:

1. **DNS Reversion**:
   - Revert DNS A/AAAA records or CDN Origin back to legacy server IP (Record DNS TTL: `___` seconds).
2. **Halt Worker Processes**:
   ```bash
   supervisorctl stop thailotto-worker:*
   ```
3. **Database Rollback (If Destructive Migrations Occurred)**:
   ```bash
   gunzip < /backups/db-pre-cutover-*.sql.gz | mysql -u $DB_USER -p $DB_NAME
   ```
4. **Restore Previous Release Artifact**:
   - Redeploy known good release ZIP / previous Git commit tag.
5. **Clear & Rebuild Caches**:
   ```bash
   php artisan optimize:clear
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
6. **Restart Services & Validate Health**:
   ```bash
   supervisorctl start thailotto-worker:*
   curl -f http://127.0.0.1/up
   ```
