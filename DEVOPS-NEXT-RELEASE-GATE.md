# DEVOPS-NEXT: Release Gate & Smoke Test Plan

This document describes the **mandatory pre-release checks** that must pass before any image built from this repo is promoted to production. All items below are automated in CI where possible; items marked **manual** require a human eyeball on the day of release.

## 1. CI Gate (must be green on the release SHA)

| Step | Command / Job | Target |
|------|---------------|--------|
| Composer validate | `composer validate --strict` | exit 0, no warnings |
| PHP lint | `php -l` over every `.php` in `app/`, `config/`, `database/`, `routes/`, `scripts/` | exit 0 |
| PHPStan | `./vendor/bin/phpstan analyse --memory-limit=1G` | zero errors at declared level |
| PHPUnit – Infrastructure/Transfer/Deployment/Health | `php artisan test tests/Feature/Deployment/ tests/Feature/HealthCheckTest.php tests/Unit/Infrastructure/ tests/Unit/Transfer/` | **33/33 pass (177 assertions)** |
| Rust unit tests | `cargo test --locked --all` (in `crates/settlement-worker/`) | **7/7 pass** |
| Rust fmt | `cargo fmt --all -- --check` | exit 0 |
| Rust clippy | `cargo clippy --all-targets -- -D warnings` | exit 0 |
| Frontend build | `npm ci && npm run build` | `public/build/manifest.json` present, ≥1 entry |
| Shell syntax | `bash -n docker/php/*.sh docker/rust/*.sh` | exit 0 |
| Env parity | `php scripts/check-env-parity.php` | exit 0; `.env.ci`, `.env.production.example`, and `config/*` agree |
| Docker build (PHP) | `docker build -f docker/php/Dockerfile -t app:rc .` | builds, healthcheck returns 200 |
| Docker build (Rust) | `docker build -f docker/rust/Dockerfile -t settlement-worker:rc crates/settlement-worker/` | builds, static binary produced |

## 2. Pre-deploy Smoke (run against staging after deploy, before traffic)

Run these against the staging URL/host before switching DNS or LB weight:

1. **`GET /up`** → HTTP 200, body `ok`. (Laravel `up` health route.)
2. **`GET /health`** → HTTP 200, JSON includes `app`, `database`, `redis`, `storage` keys, all `ok: true`.
3. **FCGI healthcheck inside container** → `docker exec $APP /usr/local/bin/php-fpm-healthcheck.sh` exits 0 (used by Docker `HEALTHCHECK`).
4. **Static asset cache headers** → `curl -I https://staging/build/assets/app-*.js` returns `Cache-Control: public, max-age=31536000, immutable`.
5. **Nginx HSTS** → `curl -I https://staging/` returns `Strict-Transport-Security: max-age=31536000; includeSubDomains`.
6. **Login end-to-end** → POST to `/login` with a seeded QA account returns 302 to `/dashboard`.
7. **Wallet smoke** → GET `/api/wallet/balance` returns `200`, JSON shape `{ cash: "...string...", win: "...string..." }`.
8. **Settlement worker** → one worker pod boots, logs "settlement-worker ready", and consumes the first queue message without panicking.
9. **Scheduled jobs** → `php artisan schedule:test --name=...` (any registered scheduled task) exits 0.
10. **Logs to stdout** → `docker logs $APP` shows access + Laravel lines in the first 30 seconds post-start; no `ERROR` lines unless expected.

## 3. Post-deploy Canary (first 10 minutes after traffic cutover)

- Error-rate SLO: < 0.5% 5xx across the load balancer.
- p95 response time on `/` < 800 ms.
- Queue backlog (default tube) returns to ≤ 100 within 5 minutes.
- Redis evictions / MySQL replication lag both flat in Grafana.
- No new entries in `security_audit_logs` with `severity ≥ high` that are not explained by the deploy.

## 4. Rollback Trigger

Any of the following within the canary window mandates an **immediate rollback** to the previous image tag; investigation is performed on the failed release *after* rollback:

- p95 latency doubles vs. baseline for ≥ 2 consecutive minutes.
- 5xx rate exceeds 2% for ≥ 1 minute.
- Settlement worker crashes more than once in 10 minutes.
- A security-audit entry fires at `critical` severity.
- Wallet balance totals drift vs. the ledger (detected by `php artisan finance:reconcile`).

## 5. Sign-off

A release is considered **promoted** only when:

1. All CI gates (§1) are green on the same SHA.
2. All pre-deploy smoke checks (§2) are green against that SHA in staging.
3. One developer + one ops engineer have both clicked "Approve" on the GitHub deployment environment.
4. The canary window (§3) has elapsed without a rollback trigger (§4).

The release tag and SHA are then written to the runbook log.
