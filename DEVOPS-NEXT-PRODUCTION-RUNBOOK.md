# DEVOPS-NEXT: Production Runbook

A step-by-step operations manual for deploying, rolling back, and diagnosing the Thai Lottery application in production. Written for an on-call engineer who knows Docker, MySQL, Redis, and basic Laravel, but did not build the system.

---

## 0. Topology at a glance

```
                ┌────────────┐
   HTTPS ─────▶ │   nginx    │  (docker/nginx/nginx.conf)
                │  :443/:80  │
                └─────┬──────┘
                      │ FastCGI :9000
                ┌─────▼──────┐                ┌────────────────────┐
                │  php-fpm   │──tcp:3306────▶ │       MySQL        │
                │  (app)     │──tcp:6379───▶ │       Redis        │
                │            │                └────────────────────┘
                └─────┬──────┘
                      │ Redis queue
                ┌─────▼──────────────┐
                │ settlement-worker  │  (static Rust binary, scratch image)
                └────────────────────┘
```

Containers are orchestrated by `docker compose -f docker-compose.production.yml up -d`. There are three services: **nginx**, **app**, **worker**. MySQL and Redis are assumed to be external (managed or a separate compose project); they are reached by URL passed via secrets/env.

---

## 1. Deploying a new release

### Prerequisites

- Docker engine 24+ with Compose v2.
- The server already has the `.env.production` file populated (see `.env.production.example` for the key list).
- Secret files mounted under `/run/secrets/` (or bind-mounted paths defined in the compose file):
  - `db_password`, `redis_password`, `app_key`.
- A backup of the MySQL database taken within the last 24 h.

### Step-by-step

1. **Pull the new image** (or build locally):
   ```bash
   docker compose -f docker-compose.production.yml pull
   ```
2. **Put the app into maintenance mode** (graceful, drains in-flight requests):
   ```bash
   docker compose -f docker-compose.production.yml exec app php artisan down --retry=60 --secret="<bypass-token>"
   ```
3. **Apply migrations** (idempotent; duplicate guards are added to every repeatable migration file):
   ```bash
   docker compose -f docker-compose.production.yml exec app php artisan migrate --force
   ```
4. **Export fresh frontend assets** (the entrypoint runs this automatically on boot; only needed manually if you reuse a persistent `public-assets` volume):
   ```bash
   docker compose -f docker-compose.production.yml exec app /usr/local/bin/export-assets.sh
   ```
5. **Restart app + worker** (nginx stays up, serves the down page):
   ```bash
   docker compose -f docker-compose.production.yml up -d --no-deps app worker
   ```
6. **Bring app out of maintenance**:
   ```bash
   docker compose -f docker-compose.production.yml exec app php artisan up
   ```
7. **Run smoke tests** from DEVOPS-NEXT-RELEASE-GATE.md §2.
8. **Record the deploy**: tag the image and SHA in the team runbook.

### Zero-touch deploy (automated path)

CI publishes versioned tags `app:<sha>` and `settlement-worker:<sha>`. Promotion copies that tag to `app:prod` / `settlement-worker:prod` and triggers the steps above via your deployment runner. Nothing in our compose file hardcodes `latest`.

---

## 2. Rollback

Rollback is **always safer** than a hotfix during an incident:

1. Find the last known-good image tag (it is recorded during §1.8 deploy):
   ```bash
   grep IMAGE_TAG /var/log/thai-lottery/deploys.log | tail -5
   ```
2. Re-tag / re-pin to that tag in the compose file or via your deployment system.
3. `docker compose -f docker-compose.production.yml up -d --no-deps app worker nginx`.
4. Run migrations **only if forward**. If the rollback crosses a migration that dropped columns, **do not run `migrate`**; restore MySQL from the pre-deploy backup instead.
5. Run §2 smoke tests again and confirm the canary.

Do **not** attempt to roll back a single worker pod independently of the app; they share queue message shapes.

---

## 3. Health checks & what they mean

| Check | How | Healthy sign | Where it runs |
|-------|-----|--------------|---------------|
| `/up`  | HTTP GET to nginx | 200 `ok` | External LB |
| `/health` | HTTP GET, returns JSON | all sub-checks `"ok":true` | External LB / synthetic |
| php-fpm ping | `/usr/local/bin/php-fpm-healthcheck.sh` inside `app` | exit 0 | Docker `HEALTHCHECK` (every 30s) |
| Worker heartbeat | worker logs `ready` on boot + metrics on `/metrics` | recent log timestamp < 60s | Observability |

If `/up` returns non-200 for 3 consecutive LB probes, the instance is replaced. If `/health` reports `database: fail`, raise a P2 immediately (see §5).

---

## 4. Configuration & secrets

- **All** environment variables must appear in `.env.production.example`. The CI gate `php scripts/check-env-parity.php` fails the build if a new key is added to config/ without updating the example file and `.env.ci`.
- **Secrets are NEVER baked into the image**. They are supplied through either:
  - Docker secrets mounted at `/run/secrets/<name>` (preferred in production), or
  - `<KEY>_FILE` env vars pointing at a file path whose contents are the secret.
- **APP_KEY** is loaded from `/run/secrets/app_key` by `laravel-entrypoint.sh`; if it is missing the container refuses to start (this is intentional — do not bypass it).
- OPS_LANE variables (`OPS_LANE_PRIMARY_WALLET_SLUG`, `OPS_LANE_BONUS_WALLET_SLUG`, `OPS_LANE_COMMISSION_WALLET_SLUG`) must be set consistently across app *and* worker.

---

## 5. Incident playbooks

### 5.1 "502 Bad Gateway" from nginx

- nginx cannot reach php-fpm. `docker compose -f docker-compose.production.yml ps app` → is it `healthy`?
- If unhealthy, check `docker compose logs app --tail=200` for PHP fatals.
- If healthy, check `docker compose exec nginx curl -sS http://app:9000/ping` — it should return `pong`.
- Common cause: OOM kill. Check `dmesg | grep -i oom` on the host.

### 5.2 "Database connection refused" in /health

- `docker compose exec app php artisan tinker --execute="DB::select('select 1');"` fails.
- Confirm the DB host resolves: `getent hosts <db-host>` from inside the container.
- Confirm the secret file `/run/secrets/db_password` is present and matches the DB user password.
- Do NOT redeploy as a fix; restart MySQL (or failover to replica) first.

### 5.3 Queue backlog growing

- `docker compose exec app php artisan queue:monitor redis:default --max=1000` alerts.
- Check worker: `docker compose logs worker --tail=200`. If it is crash-looping, you'll see repeated panics.
- Rust worker panic is a **P1** — rollback first (§2), then capture `RUST_BACKTRACE=full` logs.
- If worker is healthy but consuming slowly, you can scale workers: `docker compose up -d --scale worker=3`.

### 5.4 Wallet totals do not match ledger

- Run `docker compose exec app php artisan finance:reconcile` — the FinancialReconciliationService compares `wallet_balances` against the sum of ledger transactions and reports drift.
- If drift is reported, **stop payouts**:
  ```bash
  docker compose exec app php artisan payouts:pause
  ```
- Page the finance on-call; do NOT manually edit balances.

### 5.5 SSL / HSTS problems

- nginx serves HSTS unconditionally (31536000, includeSubDomains). If you break TLS, clients will refuse to connect for a year. Test certificate renewal 7 days before expiry.
- To terminate TLS upstream (e.g., at CDN/LB), keep HSTS on the CDN and remove the nginx add_header — never lower the max-age below 31536000 in production.

---

## 6. Logging & observability

- All container stdout/stderr is collected as-is. Laravel logs JSON to stdout in production (`config/logging.php` → `stderr` with JsonFormatter).
- The php-fpm healthcheck uses `SCRIPT_FILENAME=/var/www/html/public/ping.php` over FastCGI; if you change the public path, update the healthcheck script.
- Sensitive values (passwords, APP_KEY, API tokens) must never be logged. `laravel-entrypoint.sh` exports `APP_KEY` from file but never echoes it.

---

## 7. Data protection

- `storage/app/public/*` and `storage/app/private/*` are excluded from the image by `.dockerignore`. Persist them via a named volume or host bind mount if user uploads must survive container recreation.
- Daily MySQL backups are taken by an external cron (outside this repo). Verify one restores successfully at least monthly.
- The `payment_webhook_evidence` table is encrypted at rest via the `2026_10_16_000300_encrypt_payment_webhook_evidence` migration; do not alter the application key post-rotation without re-encrypting.

---

## 8. Common commands cheatsheet

```bash
# see status
docker compose -f docker-compose.production.yml ps

# tail app logs
docker compose -f docker-compose.production.yml logs -f app

# run an artisan command in the running container
docker compose -f docker-compose.production.yml exec app php artisan <cmd>

# open a shell in the app container
docker compose -f docker-compose.production.yml exec app bash

# restart one service
docker compose -f docker-compose.production.yml restart worker

# run migrations safely against prod (read-only safe-check first)
docker compose -f docker-compose.production.yml exec app php artisan migrate:status
docker compose -f docker-compose.production.yml exec app php artisan migrate --force

# check env parity between example and config
docker compose -f docker-compose.production.yml exec app php scripts/check-env-parity.php production
```

---

## 9. Who to call

- **P1 (site down / wallet drift / data loss):** page the on-call engineer via the rotation in the team's PagerDuty; if no ack in 5 min, escalate to the tech lead.
- **P2 (degraded / partial outage / queue backlog):** post in #ops-alerts and tag the service owner.
- **P3 (cosmetic / non-blocking bug):** open a GitHub issue with label `prod-minor`.
