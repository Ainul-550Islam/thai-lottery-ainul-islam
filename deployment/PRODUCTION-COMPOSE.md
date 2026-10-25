# Production Compose operations

Docker Compose is an operating option for a single controlled host or a staging mirror, not a substitute for high-availability orchestration. It runs the application with MySQL, Redis, Nginx, Horizon, Reverb, one Laravel scheduler, a migration service, and the Rust settlement worker.

## Configuration

Set `APP_HOST`, `APP_IMAGE_TAG` (the immutable `sha-<commit>` tag), `DB_DATABASE`, `DB_USERNAME`, `PHP_BASE_DIGEST`, and any non-secret deployment controls through a protected external environment file. Compose derives `APP_URL=https://${APP_HOST}` from the required host. The required digest must identify the approved PHP 8.4 Alpine image manifest. Application, DB, Redis and worker credentials must be delivered through external Compose secrets where supported. `FIELD_ENCRYPTION_KEY` is read by the current Laravel configuration from the process environment, so provide it through the protected, permission-restricted `.env.production` file from the secret manager; never commit that file. No public DB/Redis ports are published. DB and Redis remain on the internal network; restrict the `provider-egress` bridge outbound traffic at the Docker host firewall to operator-verified provider CIDRs. The named `app-storage` volume contains private application files and must be included in encrypted backups. Never place secret values in version control or command history.

Provide external secrets named `app_key`, `db_password`, `db_root_password`, `redis_password`, `database_url`, and `settlement_worker_hmac_secret` via the host's secret mechanism before starting the stack. Ensure each secret is independently backed up and access-controlled.

## Validate and deploy

```sh
docker compose --env-file /secure/path/${COMPOSE_ENV_FILE} -f docker-compose.production.yml config --quiet
docker compose --env-file /secure/path/${COMPOSE_ENV_FILE} -f docker-compose.production.yml pull
docker compose --env-file /secure/path/${COMPOSE_ENV_FILE} -f docker-compose.production.yml up -d --wait
```

Use the exact release image digest selected by the approved release; do not build an unreviewed image directly on the production host. Check `docker compose ps`, the migration service exit status, application health, Horizon status, Reverb connectivity and scheduler liveness. Review `docker compose logs` without exporting secrets or personal data.

## Runtime roles

- `nginx` is the only service published on the host and terminates HTTPS. It forwards PHP requests to `app:9000` and `/app` or `/apps` WebSocket connections to Reverb.
- `app` is the PHP-FPM runtime. Its writable storage and temporary directories are explicitly mounted; root filesystem remains read-only.
- `migrate` performs `php artisan migrate --force --isolated` once before application startup.
- `horizon` processes queued jobs with Horizon's configured limits.
- `scheduler` executes `php artisan schedule:work` and must remain a single replica.
- `reverb` serves WebSocket traffic. Scale only after verifying Redis pub/sub broadcasting is configured.
- `settlement-worker` is a separately built Rust runtime with database URL and HMAC secret mounted as files.
- `db` and `redis` are private services and must never be exposed through host-published ports.

## Operational boundaries

Run `php artisan ops:preflight --environment=production --strict`, `php artisan thai-rails:preflight`, and `php artisan realtime:check` before enabling provider lanes. Follow the lane enable sequence in `README.md`. Take a database and private-storage backup before each migration, and verify restoreability. For payment incidents use `RUNBOOK-payments-incidents.md`; for key rotation use `RUNBOOK-secrets-and-key-rotation.md`.

Compose restart or rollback does not reverse an external bank transfer or a committed database migration. For uncertain payouts, do not retry or resend. Pause the lane and follow the incident runbook.
