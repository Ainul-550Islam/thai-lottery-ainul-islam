# Operations runbook — Thai Lottery platform (Agent C)

Scope: everything an operator, an on-call engineer or a release pipeline does
**outside** a request: containers, deploy, rollback, backups, restore, queue and
scheduler lanes, health and alerting, environment validation.

Everything below is implemented in this branch. Where something is **not**
implemented, it says so and says why — that is deliberate. A runbook that
describes a capability the repository does not have is worse than no runbook.

- Branch: `agent/c-platform-ops-ci`
- Base commit: `5a10574e41fc9f8eec7202aa13cfd388f39003cb`
- Runtime: PHP 8.4, Laravel 12, Node 24 (`.nvmrc` 24.22.0), npm 11, MySQL 8.4, Redis 7

---

## 1. What this branch makes true

| Property | How it is enforced | Verified how |
|---|---|---|
| Production refuses to boot misconfigured | `app/Providers/ProductionSafetyServiceProvider.php` — `exit(2)` in console, exception over HTTP | ran `APP_ENV=production APP_DEBUG=true php artisan --version` → exit 2, violations listed, no stack trace |
| Release gate before deploy | `php artisan ops:preflight --json` | 19 PASS / 0 FAIL against a fully-configured production-like env; 2 FAIL against a deliberately broken one |
| Backups are encrypted, and only ciphertext is retained | `backup:execute-encrypted-worm` + `scripts/ops/assert-ciphertext-only.sh` | live drill on MariaDB 11.8.6: `.enc` + manifest + sha256 only, plaintext staging dir removed |
| A backup is proven restorable, not assumed | `backup:verify` (3 layers) + `scripts/ops/restore.sh` | restore drill into a scratch DB, row counts and ledger SUM compared |
| Destructive migrations stop a deploy | `scripts/ops/migration-audit.php` | injected a `dropColumn` migration → `DESTRUCTIVE_PENDING`, exit 1; removed again |
| Rollback cannot target a non-release | `scripts/ops/rollback.sh` | refuses a plain checkout; simulated release layout resolves correctly |
| Logs never contain credentials | `app/Logging/RedactSensitiveData.php` + `RedactingProcessor.php` | 8 unit tests, 23 assertions |
| CI matches the real runtime and is pinned | `.github/workflows/*.yml` | 4 files YAML-parse, 0 unpinned `uses:`, `assert-runtime-alignment.sh` passes |
| Front-end lint exists and can fail | `eslint.config.mjs`, `npm run lint` | planted an undefined variable → exit 1; repository state → exit 0 with 44 A-owned warnings |

---

## 2. Health and alerting

### Endpoints (existing application architecture, extended not replaced)

| Endpoint | Meaning | Auth |
|---|---|---|
| `/up/live` | process is alive; does no I/O | none |
| `/up/ready` | database, cache and queue reachable | none |
| `/up/health` | detailed dependency report | `HEALTH_DETAIL_TOKEN` (≥32 chars) for detail |
| `/api/health`, `/api/v1/health` | API-facing summary | none |

### Command lanes

```
php artisan ops:health --json          # 0 OK · 1 DEGRADED · 2 CRITICAL
php artisan ops:preflight --json       # release gate; non-zero blocks a deploy
php artisan ops:heartbeat              # written by the scheduler every minute
php artisan ops:heartbeat --show        # how stale the heartbeat is
scripts/ops/healthcheck.sh --json      # wrapper; same exit-code contract
```

`ops:health` reports `NOT_CONFIGURED` (and does **not** degrade the aggregate)
for Redis or the scheduler heartbeat when the application does not use them —
a local run or a CI run is not "unhealthy" because Redis is absent, and an
operator must be able to trust a DEGRADED.

### Suggested alerts

| Signal | Condition | Severity |
|---|---|---|
| `ops:health` exit code | `2` | page |
| `ops:health` exit code | `1` for 15 min | ticket |
| scheduler heartbeat age | > 5 min | page |
| newest verified backup age | > 26 h | page |
| failed jobs | > 10 | ticket |
| disk free | < 15 % | ticket |

`ops:health` already encodes all six; wire the exit code, not the prose.

---

## 3. Backups

### Take one

```bash
BACKUP_ENCRYPTION_KEY_FILE=/run/secrets/backup_key php artisan backup:execute-encrypted-worm --json
```

- Refuses to run with **no key** and refuses a **placeholder** key (exit 1,
  machine-readable JSON on stdout).
- Dumps → compresses → encrypts → **re-opens the archive and verifies it**
  before declaring success.
- Plaintext is staged in a private `0700` directory (`backup.staging_path`,
  default the system temp dir) and removed in a `finally` block. The output
  directory receives **only** `.sql.gz.enc`, `.enc.sha256`,
  `.enc.manifest.json`, `latest.json`.
- Manifest fields: archive, sha256, bytes, `dump_bytes`, `plaintext_retained:
  false`, format_version, cipher, connection, app_env, created_at, duration.
  No hostname, no database name, no credentials.

### Prove the archive is good

```bash
php artisan backup:verify storage/app/backups/<archive> --json
bash scripts/ops/verify-backup.sh --json          # every archive in the dir
bash scripts/ops/assert-ciphertext-only.sh        # nothing in the clear
```

Three layers, each catching a different failure: `.sha256` sidecar (truncation,
partial copy, swapped file) → HMAC in the envelope (tamper, wrong key) → dump
banner + completion marker (dump cut short before encryption).

### Restore

```bash
CONFIRM=<target_db> scripts/ops/restore.sh <archive> --database <target_db> --recreate
```

Guards, all verified:

| Situation | Behaviour | Exit |
|---|---|---|
| no `--database` | refuse: there is deliberately no default target | 2 |
| no `CONFIRM=<db>` | refuse and print the exact command | 2 |
| target is the app's own database | refuse, suggest a scratch DB + swap | 2 |
| `APP_ENV=production` + live DB | also require `--i-understand-this-overwrites-data` | 2 |
| `--recreate` against live production | refuse | 2 |
| archive fails verification | refuse; nothing is imported | 1 |
| import fails midway | exit 1 and **warn that the DB may be partially imported** | 1 |

Exit code 2 means *refused, nothing happened*; 1 means *attempted and failed*.
Alert on 1; 2 is a guard working.

### Retention

`backup.retention_days` (14) and `backup.minimum_keep` (7). Pruning removes the
archive **and** its `.sha256` and manifest together, so a prune can never leave
a manifest pointing at a file that is gone.

---

## 4. Deploy

```bash
scripts/ops/deploy.sh --preflight-only     # gate only, changes nothing
scripts/ops/deploy.sh --dry-run            # print the plan
scripts/ops/deploy.sh --yes                # deploy (add CONFIRM=<APP_ENV> in production)
```

Order, and why:

1. `ops:preflight` — refuse a misconfigured release before anything is cached.
2. encrypted backup + verify — so "restore the last good state" exists for the
   rest of the deploy.
3. `migration-audit.php` — pending migrations that drop/rename/truncate stop the
   deploy unless `--allow-destructive`.
4. maintenance mode (production only, unless overridden).
5. `migrate --force`.
6. `config:cache`, `route:cache`, `view:cache`, `event:cache` + build-manifest assert.
7. `queue:restart` (+ `horizon:terminate`).
8. HTTP smoke test.
9. `up`.

On failure the script prints the exact rollback command. It never restores the
database automatically: a silent auto-restore turns a bad deploy into a
data-loss incident, because the deploy is recoverable and an overwritten ledger
is not.

### Migration safety audit

`scripts/ops/migration-audit.php` reads the **pending** migration files and
scans **`up()` only** (a `down()` that drops a column is a rollback, not a
deploy risk). Findings look like:

```
{"status":"DESTRUCTIVE_PENDING","pending_count":1,
 "destructive":[{"migration":"…","operation":"dropColumn","scope":"up()"}]}
```

Raw SQL is judged by the statement's verb, so `ALTER TABLE … ADD CONSTRAINT` is
not flagged; `DROP`, `TRUNCATE` and `DELETE` are.

---

## 5. Rollback

```bash
scripts/ops/rollback.sh --list
scripts/ops/rollback.sh --dry-run
scripts/ops/rollback.sh --to <release> --yes
```

A directory only counts as a release if it contains `artisan`. The script
refuses when:

- the host is not a release-directory layout (a plain checkout offers no
  releases — it will not "roll back" to `vendor/`),
- `--to` names a directory without `artisan`,
- the target is the release that is already live.

It stops queue workers and re-warms caches, then runs the smoke test. It does
**not** revert migrations; the runbook's database decision is printed with the
exact `restore.sh` invocation, requiring a human to type it.

---

## 6. Queue and scheduler

| Lane | Where | Notes |
|---|---|---|
| `ops:heartbeat` | every minute, `onOneServer`, `withoutOverlapping(180)` | live/ready does not depend on it; alerting does |
| `backup:execute-encrypted-worm` | daily at `OPS_BACKUP_AT` (default `02:20`), `onOneServer` | verified inline; failure is loud |
| workers | `queue:work` in the `queue` service, `scheduler` service runs `schedule:work` | single scheduler container — `deploy.replicas` is a no-op outside Swarm |

Preflight fails a **production** release when `QUEUE_CONNECTION=sync`; outside
production it warns. `retry_after` must exceed the longest worker `timeout`,
otherwise a slow-but-alive job is handed to a second worker and runs twice — for
a payout job that is a double payout.

---

## 7. Containers

- `docker/php/Dockerfile`: 3 stages, Node 24 build stage, runtime has no
  composer/npm/toolchain, `USER 10001`, `STOPSIGNAL SIGQUIT`, FPM-ping
  `HEALTHCHECK`, base images pinned by digest.
- `docker/php-fpm/` deleted — it compiled PHP 8.3 against a `^8.4` requirement
  and copied files its own `.dockerignore` excluded, so it could never build.
- `docker-compose.production.yml`: read-only roots, `cap_drop: ALL`,
  `no-new-privileges`, internal network, no published database port, secrets via
  `*_FILE`, `stop_grace_period: 120s`, healthchecks on `app`/`nginx`/`db`/`redis`.
- Every `*_FILE` variable in compose is asserted to have a consumer
  (`assert-ops-assets.sh`); a variable nothing reads is a lie about wiring.

**Not verifiable in this environment:** `docker` is not installed here, so
`docker build` and `docker compose config` are `NOT RUN`/`BLOCKED`. Local
proxies used instead: `hadolint` (0 findings) and a YAML parse of the compose
file (labelled as *not* schema validation).

---

## 8. Logs

| Setting | Value in production | Why |
|---|---|---|
| `LOG_CHANNEL` | `stderr` | containers are collected from stdout/stderr, not from a file inside the image |
| `LOG_FORMAT` | `json` | one JSON object per line, so a log pipeline indexes fields instead of grepping prose |
| `LOG_REDACT_SENSITIVE` | `true` | preflight fails a production release when it is off |

```
$ LOG_CHANNEL=stderr LOG_FORMAT=json php -r '…'   # what a container does
{"message":"…","context":{"password":"[REDACTED]","amount":12.5},"level":200,…,"extra":{"process_id":…}}

$ LOG_CHANNEL=stderr LOG_FORMAT=text …            # readable on a laptop
[2026-10-24T12:59:00+07:00] local.INFO: … {"password":"[REDACTED]","amount":12.5} {"process_id":…}
```

Every channel that emits a record carries the redaction tap — including `slack`
and `papertrail`, which leave the building. `emergency` is the one deliberate
exception and is documented in `config/logging.php`.

`scripts/ci/assert-structured-logs.sh` boots the app the way a container does,
logs a fake credential, and fails the build if the line is not JSON, is not on
stderr, or the credential survived.

### Static analysis

`phpstan.neon` (app-wide, level 8) and `phpstan.ops.neon` (the platform/ops
directories) are both valid PHPStan 2 configurations; larastan is a declared dev
dependency. CI runs the scoped one. The app-wide configuration needs a baseline
of the pre-existing findings before it can gate a release — see `HANDOFF-C.md`.

## 9. Environment and secrets

- `.env.example` / `.env.production.example` / `.env.ci` contain placeholders
  only; `scripts/ci/scan-secrets.sh` enforces it and fails the build otherwise.
- Secrets come from Actions secrets in CI and from `*_FILE` (Docker secrets) in
  production. `BACKUP_ENCRYPTION_KEY` has **no default anywhere**; the backup
  lane refuses to write an unencrypted dump.
- The preflight validates configuration **without printing values** — it reports
  length and presence, never content, so a preflight transcript pasted into a
  ticket is not a credential disclosure.
- Log redaction: `LOG_REDACT_SENSITIVE` (default on) taps 8 channels.

---

## 10. What is BLOCKED and by whom

| Item | Owner | Effect |
|---|---|---|
| `wallet_conversions` created by three migrations (`2026_10_13_000100`, `2026_10_13_000200`, `2026_10_16_000011`) | B | `php artisan migrate` on any fresh database fails with `Table 'wallet_conversions' already exists` — verified on MySQL 11.8.6 and SQLite. Blocks every `RefreshDatabase` suite, the runtime CI job, and therefore the end-to-end smoke check. |
| THAILOTTO strings in `resources/views|css|js` | A | `scripts/ci/assert-result-lane-seams.sh` fails; the `static-analysis` CI job is red until A removes or allow-lists them. |
| `settlement_worker_hmac_secret` mounted but never loaded by the worker | B | The container is configured to verify request signatures; the worker source does not read the secret. Mounted-but-unused, recorded in `HANDOFF-C.md`. |
| `docker build` / `compose up` | environment | No Docker binary in this sandbox. `hadolint` + YAML parse only. |
| PHPStan level 8 | environment | `vendor/bin/phpstan` is not installed; CI emits `::warning::` and reports `NOT RUN`. |
| A-owned lint debt | A | 6 `no-useless-assignment` errors are suppressed by count in `eslint-suppressions.json`; new errors fail CI. See `HANDOFF-C.md` §5. |

---

## 11. First hour on call

1. `scripts/ops/healthcheck.sh --json` — exit 0/1/2 tells you the page severity.
2. `php artisan ops:heartbeat --show` — is the scheduler alive?
3. `ps aux | grep 'queue:work'` / container logs — are workers consuming?
4. Newest archive age and `assert-ciphertext-only.sh` — is the backup lane healthy?
5. Recent deploys: `git -C /srv/thai-lottery/current log -1 --oneline` and `rollback.sh --list`.
6. If a release is at fault: `scripts/ops/rollback.sh --list`, then `--dry-run`,
   then `--to <previous> --yes`. Read the database decision before restoring
   anything.
