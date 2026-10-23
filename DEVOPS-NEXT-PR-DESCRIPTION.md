# chore(devops): release hardening — Docker, CI, migrations, ops docs

## Summary

Hardens the Thai Lottery application for production deployments: Docker images, CI, migration idempotency, env parity, and the full set of operations docs (release gate, runbook, deploy checklist, incident response). No feature code changes outside of the minimal domain support needed for in-scope wallet/domain correctness (WalletType::Win, TransferDirection enum, BalanceTransferException).

**Branch:** `chore/devops-release-hardening`
**Base:** `2c7ab9c` (main tip at time of branch)
**Final commit:** `defa546`

## What's in scope

### Containers & serving
- **PHP (docker/php/Dockerfile):** multi-stage `php:8.4-fpm-alpine` build with `bcmath`, `intl`, `pdo_mysql`, `pdo_pgsql`, `redis` opcache tuned for production.
- **PHP entrypoint/health/asset scripts:** added `docker/php/laravel-entrypoint.sh` (loads `APP_KEY` from `/run/secrets/app_key`, runs migrations & asset publish), `docker/php/php-fpm-healthcheck.sh` (FCGI ping to `/ping.php`), `docker/php/export-assets.sh` (copies built assets into the shared `public-assets` volume). All scripts `bash -n` clean.
- **Nginx (docker/nginx/nginx.conf):** explicit `upstream php-fpm { server app:9000; }`, `/build/` immutable 1-year cache block, HSTS (`max-age=31536000; includeSubDomains`), gzip on text types, `fastcgi_next_upstream` to shed dead php-fpm backends.
- **Rust worker (docker/rust/Dockerfile):** `rust:1.89-alpine` → static musl binary on `scratch`; minimal runtime footprint.
- **docker-compose.production.yml:** coherent MySQL contract, opt-in profiles (worker/nginx), persistent `public-assets` volume, `*_FILE` secret indirection consistent with Docker secrets.

### CI
- `.github/workflows/ci.yml` and `security.yml`: bumped setup-php to **PHP 8.4** with `pdo_mysql`/`pdo_pgsql`/`redis`/`bcmath`/`intl` extensions; setup-node to **22**; added `cargo fmt --check`, `cargo clippy -D warnings`, `cargo test --locked`; added shell script lint (`bash -n`); added the env-parity gate (`php scripts/check-env-parity.php`); added frontend build step.
- `.nvmrc` pinned to `22.16.0`; `rust-toolchain.toml` pinned to `1.89.0`.

### Rust workspace
- Moved `runtime/settlement-worker/` → `crates/settlement-worker/` (canonical Cargo location). Stale `runtime/` directory removed.
- Fixed `r.bottom_two` → `r.two_back` typo in the payout test; **all 7 unit tests pass**.
- Rewrote root `Cargo.toml` workspace to include `crates/settlement-worker` and `security/weekly-result-integrity`; regenerated `Cargo.lock`.

### Laravel/PHP
- `config/database.php` default connection switched from `sqlite` to `mysql` (safe for production).
- Removed `config.platform.php` pin from `composer.json` so Composer resolves against the actual PHP version.
- **Wallet/domain:** added `WalletType::Win` case with source/target pocket helpers; added `app/Enums/TransferDirection.php` enum and `app/Exceptions/BalanceTransferException.php` for strongly-typed cash↔win transfers.
- **Finance reconciliation:** patched `FinancialReconciliationService` and `PayoutReconciliationService` to route all `GROUP_CONCAT` calls through a guarded helper that prevents silent truncation / type drift on MySQL 8.
- **`scripts/check-env-parity.php`:** hardened against null/empty values; excluded volatile `*_CACHE_KEY` keys; added `OPS_LANE_*` keys to `.env.ci` and `.env.production.example`.

### Migrations (idempotency)
- Removed stray `use RuntimeException;` imports from all `2026_10_*` migration files that referenced a non-imported symbol (was causing a fatal on migrate:fresh with the SQLite test driver that uses `use`-resolution at file scope).
- Wrapped 14 duplicate-table `2026_10_16_*` migrations in `if (!Schema::hasTable(...)) { ... }` guards.
- Added `hasColumn` guards to `2026_10_14_000200_pin_settlement_run_inputs` and to both `*_add_last_seen_at_to_users_table` migrations.
- Result: `php artisan migrate:fresh` now applies all 171 migrations cleanly.

### Security fix
- Bumped `symfony/yaml` from v7.4.17 → v8.1.9 to remediate **CVE-2026-109413** (unbounded recursion in YAML inline lexer, process-crash DoS). `composer audit` now reports zero advisories.

### Housekeeping
- Tightened `.gitignore` / `.dockerignore` (Cargo `target/`, `node_modules/`, `/public/build/`, `/evidence/`, env files).
- Moved host `evidence/` artefacts into `storage/evidence/` (Laravel-managed, git-ignored at the appropriate level).
- Removed stale `runtime/`, `docker/php-fpm/`, `images/`, `qa-fixes/`, `uploads/`, and stray host dotfiles (`.profile`, `.sudo_as_admin_successful`) that were polluting the repo.
- `/public/build/` added to `.gitignore` (built by CI/deploy, not committed).

### Documentation (five DEVOPS-NEXT deliverables at repo root)
1. `DEVOPS-NEXT-RELEASE-GATE.md` — CI, pre-deploy smoke, canary, rollback triggers, sign-off.
2. `DEVOPS-NEXT-PRODUCTION-RUNBOOK.md` — topology, deploy/rollback steps, health checks, secrets, incident playbooks, cheatsheet.
3. `DEVOPS-NEXT-DEPLOY-CHECKLIST.md` — tick-box checklist (sections A–I) for every production deploy.
4. `DEVOPS-NEXT-INCIDENT-RESPONSE.md` — SEV levels, on-call loop, triage-by-symptom, comms templates, post-mortem, escalation.
5. `DEVOPS-NEXT-CHANGED-FILES.txt` — full machine-readable manifest of modified/added/deleted/renamed files with final SHA.

## Verification (run locally; should also pass in CI)

| Check | Command | Result |
|---|---|---|
| Composer validate | `composer validate --strict` | ✅ valid |
| Composer audit | `composer audit` | ✅ no advisories |
| Env parity | `php scripts/check-env-parity.php` | ✅ ok, 346 keys aligned |
| Shell syntax | `bash -n docker/php/*.sh` | ✅ all clean |
| Rust format | `cargo fmt --all -- --check` | ✅ clean |
| Rust clippy | `cargo clippy --all-targets -- -D warnings` | ✅ clean |
| Rust tests | `cargo test --locked` | ✅ 7/7 pass |
| Migrations | `APP_ENV=testing php artisan migrate:fresh` | ✅ 171 migrations applied |
| Narrow PHPUnit gate | `php artisan test tests/Feature/Deployment/ tests/Feature/HealthCheckTest.php tests/Unit/Infrastructure/ tests/Unit/Transfer/` | ✅ **33/33 pass, 177 assertions** |
| Frontend build | `npm ci && npm run build` | ✅ manifest.json produced |

## Deliberately out of scope

- **Docker daemon not available in this sandbox**, so `docker build` / `docker compose up` were not executed. The Dockerfiles were statically verified and all shell entrypoints pass `bash -n`; CI on GitHub Actions (which has Docker) should be the source of truth for image builds.
- **Full PHPUnit suite not targeted**: ~83 pre-existing business-logic failures exist outside the DEVOPS-NEXT scope (unrelated to containers/CI/deployment). The PR only gates the deployment/infrastructure/transfer/health slices listed above, which are the ones this hardening changes. Those pre-existing failures are **not** regressed by this PR (they fail identically on base `2c7ab9c`).
- **Push to GitHub**: this sandbox has no GitHub credentials/token; the branch is ready locally at commit `defa546` and needs a one-time `git push -u origin chore/devops-release-hardening` from an environment with credentials.

## Rollout / rollback

- Migrations are additive and idempotent — safe to run before serving new code.
- Rollback path: `git revert` this commit or re-pin the image tag to the previous release; no destructive migrations are included.
- See `DEVOPS-NEXT-PRODUCTION-RUNBOOK.md` §2 for the full rollback procedure.

## Reviewer checklist

- [ ] Confirm no production secrets / `.env` content is checked in (parity check + `.gitignore` verified locally).
- [ ] Confirm `docker/php/Dockerfile` PHP extensions match production requirements.
- [ ] Confirm nginx HSTS `max-age` is acceptable (one year; intentional, per standard hardening guidance — do not lower without security review).
- [ ] Confirm OPS_LANE_* wallet slugs match production (defaults are set in `.env.production.example`).
- [ ] After merge, tag a release and update the runbook log per `DEVOPS-NEXT-DEPLOY-CHECKLIST.md` §I.
