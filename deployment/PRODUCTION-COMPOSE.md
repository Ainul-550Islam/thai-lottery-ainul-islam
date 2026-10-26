# Production Compose prerequisites and operating limits

> **Release gate:** this Compose file is a hardened starting point, not an authorization to accept real-money traffic. The repository's acceptance evidence still reports a failing test suite, unverified hosted CI and open money/result job-dispatch gaps. Review `../AUDIT-REVIEW-2026-10-09.md` before any deployment decision.

## Required non-secret variables

Set these in a protected Compose environment file or deployment environment:

```dotenv
APP_URL=https://your-production-host.example
DB_DATABASE=thai_lottery
DB_USERNAME=lottery_app
APP_KEY_SECRET_FILE=/secure/path/app_key
DB_PASSWORD_SECRET_FILE=/secure/path/db_password
DB_ROOT_PASSWORD_SECRET_FILE=/secure/path/db_root_password
REDIS_PASSWORD_SECRET_FILE=/secure/path/redis_password
REDIS_ACL_SECRET_FILE=/secure/path/redis_users.acl
TLS_CERTIFICATE_SECRET_FILE=/secure/path/fullchain.pem
TLS_PRIVATE_KEY_SECRET_FILE=/secure/path/privkey.pem
```

The file above contains only paths and ordinary configuration values, not secret values. Keep it outside version control and restrict its permissions. Use an absolute path for every secret file path.

Secret files must contain:

- `app_key`: a valid Laravel application key, such as the full `base64:...` value;
- `db_password`: the password for `DB_USERNAME`;
- `db_root_password`: a distinct MySQL root password;
- `redis_password`: a high-entropy Redis password used by the PHP clients and health check;
- `redis_users.acl`: a Redis ACL file defining the default user with the same password, e.g. `user default on >REPLACE_WITH_THE_SAME_SECRET ~* &* +@all`; generate it securely from the secret store, never commit it;
- `fullchain.pem`: the current TLS certificate chain;
- `privkey.pem`: its matching private key.

Provision, rotate and back up these files with the organization's secret-management process. Do not copy secret contents into `.env`, Compose YAML, Docker build arguments, image layers or logs.

## Services and deliberate exclusions

- `db`: MySQL 8.4, persistent volume, `READ-COMMITTED`, `innodb_flush_log_at_trx_commit=1`, and `group_concat_max_len=1048576`.
- `redis`: Redis 7 with AOF persistence and `noeviction`; used for cache, session, queue and distributed cache locks.
- `migrate`: one-shot Laravel migration runner; app services wait for its successful completion.
- `app`: PHP-FPM 8.4.
- `worker`: Laravel's built-in `queue:work` on Redis. It is not Horizon; worker concurrency and queue operations require explicit capacity planning.
- `scheduler`: `schedule:work`, constrained to one Compose container.
- `nginx`: the only externally published service; TLS certificates are mounted as Compose secrets.

Horizon and Reverb are not started because the packages are absent from `composer.lock`. Broadcasting stays on `log`, so this configuration has no WebSocket push. The Rust settlement worker is excluded because it uses PostgreSQL while the application deployment uses MySQL, and PHP does not call it. None of these capabilities should be advertised as operational until dependencies, data flow and integration tests are added.

## Preflight and rollout

From the repository root, using the protected environment file:

```sh
docker compose --env-file /secure/path/thai-lottery-compose.env -f docker-compose.production.yml config --quiet
docker compose --env-file /secure/path/thai-lottery-compose.env -f docker-compose.production.yml build --pull
docker compose --env-file /secure/path/thai-lottery-compose.env -f docker-compose.production.yml up -d
```

Before exposing traffic, separately verify the TLS certificate/key pair, health of MySQL and Redis, completion of migrations, queue failure alerts, backup/restore, rate limits from the actual trusted proxy topology, and the application's health endpoint. Compose configuration parsing alone is not a runtime deployment test.

The scheduler has both `deploy.replicas: 1` and a fixed `container_name`, which prevents normal Compose scaling of that service. Do not run a second scheduler outside this Compose project.

The PHP app, queue worker and scheduler join a separate bridge `egress` network because payment providers, mail services and official result feeds require outbound connectivity. Apply host/cloud egress rules to that network; MySQL and Redis remain on the isolated `backend` network only.
