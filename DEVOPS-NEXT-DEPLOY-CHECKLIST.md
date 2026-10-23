# DEVOPS-NEXT: Deployment Checklist

Tick every box for every production deploy. Copy this template into the deploy ticket and paste the completed form into the runbook log after the deploy.

## A. Release candidate

- [ ] CI is green on the SHA being deployed (see Release Gate §1).
- [ ] `composer validate --strict` passes on the release image.
- [ ] `php -l` is clean over app/config/database/routes.
- [ ] Rust 7/7 unit tests pass; `cargo clippy` and `cargo fmt --check` clean.
- [ ] Frontend build succeeded, `public/build/manifest.json` present with ≥1 entry.
- [ ] Shell scripts pass `bash -n`.
- [ ] `php scripts/check-env-parity.php` reports no drift.
- [ ] CHANGELOG / release notes updated for the version being deployed.

## B. Configuration

- [ ] `.env.production` (or the orchestrator env) has every key listed in `.env.production.example`.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] `APP_KEY` loaded from secret file, not hard-coded.
- [ ] `DB_CONNECTION=mysql`, host/user/password reachable from app container.
- [ ] `REDIS_*` point to a production Redis instance (not localhost).
- [ ] `OPS_LANE_*` variables match the canonical wallet slugs.
- [ ] Any new env vars from this release are reflected in the example file, CI env, and the actual deploy (parity check will enforce this).
- [ ] HSTS `max-age` is 31536000 (one year) — never ship a lower value to prod.

## C. Data & migrations

- [ ] A MySQL backup has been taken in the last 24 h and at least one is retained offsite.
- [ ] All migrations have been reviewed; none are destructive (no `DROP COLUMN`/`DROP TABLE` without a backfill plan).
- [ ] Migrations are idempotent (duplicate `Schema::create` calls are wrapped in `hasTable` guards).
- [ ] Plan to run migrations **before** the new app containers serve traffic (or during the maintenance window).

## D. Infrastructure

- [ ] nginx config passes `nginx -t` inside the image/container.
- [ ] nginx has `upstream php-fpm { server app:9000; }` defined.
- [ ] `/build/` location block has `immutable` + `max-age=31536000` cache control.
- [ ] HSTS header is added on HTTPS responses.
- [ ] The dead-php-fpm / zero-size-response issue is mitigated (nginx uses `proxy_next_upstream` / `fastcgi_next_upstream` to drop unresponsive upstreams).
- [ ] Compression is enabled (gzip/brotli on text types).
- [ ] Docker `HEALTHCHECK` is defined for the app container using `php-fpm-healthcheck.sh`.
- [ ] PHP image is multi-stage; final stage does not include dev/build dependencies.
- [ ] Rust worker is a static musl binary in a `scratch` image.

## E. Rollback plan

- [ ] The previous known-good image tag is recorded before starting the deploy.
- [ ] The rollback procedure (Runbook §2) is understood by the deployer.
- [ ] A way to revert migrations (if any were destructive) is documented — if none, the backup is the rollback path.
- [ ] The canary window length is agreed (default 10 minutes).
- [ ] Rollback triggers (Release Gate §4) are posted in the deploy channel.

## F. Pre-deploy in staging

- [ ] The release SHA is deployed to staging.
- [ ] `/up` returns 200.
- [ ] `/health` returns all-ok JSON.
- [ ] Frontend loads; static assets are served with immutable cache headers.
- [ ] A QA user can log in and see `/dashboard`.
- [ ] A test bet can be placed (stripe/webhook sandbox).
- [ ] A payout can be simulated through the settlement worker without panics.
- [ ] Scheduler (`php artisan schedule:run`) executes without errors.

## G. During production deploy

- [ ] Maintenance mode is enabled (if downtime deploy) OR canary 1% traffic is routed (if zero-downtime).
- [ ] Migrations are applied with `--force` and complete without error.
- [ ] New app + worker containers come up healthy in Docker (`healthy` in `docker ps`).
- [ ] Logs show no ERROR/CRITICAL lines in the first 60 seconds post-restart.
- [ ] Maintenance mode lifted / traffic cut over.

## H. Post-deploy canary (10 min)

- [ ] 5xx rate < 0.5%, p95 latency within 2× of baseline.
- [ ] Queue backlog drains to ≤100 within 5 minutes.
- [ ] Redis/MySQL metrics (CPU, connections, replication lag) nominal.
- [ ] `/health` returns all-ok from the LB.
- [ ] Spot-check one bet placement + one withdrawal end-to-end from a real account.
- [ ] No new CRITICAL security audit log entries.

## I. Wrap-up

- [ ] Deploy SHA + tag + time + deployer recorded in the runbook log.
- [ ] Incident/rollback (if any) documented within 24 h.
- [ ] Staging matches the production SHA (or is ahead on a forward-only branch).
- [ ] Related tickets / CHANGELOG marked as released.

---

If **any** unchecked box remains at section G, halt the deploy and resolve the issue or roll back. Do not "ship now, fix later" for production.
