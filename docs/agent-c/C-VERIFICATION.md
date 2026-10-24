# C-VERIFICATION.md — what was actually run, and what it actually printed

Every line below is a command that was executed in this environment, with the
result it produced. Where a result is not `PASS`, the reason is stated.

- branch: `agent/c-platform-ops-ci`
- base commit: `5a10574e41fc9f8eec7202aa13cfd388f39003cb`
- head commit: `b057f7baa4e48558ac140b0ce821db5a727dda3c`
- tree: `6d9c1288f5e6bbca58511df892fdd06929c3dd49`
- environment: PHP 8.4.27 (CLI, `pdo_mysql`, `pdo_sqlite`), Composer 2.10.3,
  Node 24.22.0, npm 11.21.1, ESLint 10.13.0, ShellCheck 0.10.0,
  hadolint 2.15.1, PHPUnit 11.5.56, larastan/PHPStan 2 (level 8)
- clean tree used for the ledger: a fresh `git clone --branch agent/c-platform-ops-ci`
  into an empty directory, `git status --porcelain` empty before every run

## 1. Summary

| # | Check | Status | Evidence |
|---|-------|--------|----------|
| 1 | Workflow YAML parses (4 files) | **PASS** | §3 |
| 2 | `docker-compose.production.yml` parses, 11 services | **PASS** | §3 |
| 3 | Every `run:` block in `ci.yml` parses under `bash -n` | **PASS** | §3 |
| 4 | Shell syntax + ShellCheck (`-S error`) on every script | **PASS** | §4 |
| 5 | hadolint on both Dockerfiles, policy from `.hadolint.yaml` | **PASS** (warnings only, threshold `error`) | §5 |
| 6 | Clean `composer install` from the lockfile | **PASS** | §6 |
| 7 | Clean `npm ci` from the lockfile | **PASS** | §6 |
| 8 | `composer validate --strict` | **PASS** | §6 |
| 9 | Front-end lint (`npm run lint`) | **PASS** — 0 errors, 44 pre-existing warnings (A-owned) | §7 |
| 10 | Dependency security gate (`npm run security:dependencies`) | **PASS** — 0 critical, 0 high | §7 |
| 11 | Clean production build + `public/build/manifest.json` verification | **PASS** — 101 entries / 99 entry points | §8 |
| 12 | `scripts/ci/*.sh` (5 scripts) on the clean checkout, no ambient env | **PASS** | §9 |
| 13 | Targeted ops test suite | **PASS** — 33 tests, 89 assertions | §10 |
| 14 | Pint on the PHP this branch touches (24 files) | **PASS** | §11 |
| 15 | Pint repo-wide | **FAIL (pre-existing, not owned by C)** — 435 files | §11 |
| 16 | PHPStan `phpstan.ops.neon`, level 8 | **PASS** — exit 0, no findings | §12 |
| 17 | PHPStan `phpstan.neon` (app-wide) | **NOT RUN** — needs a baseline; exceeds the sandbox limit | §12 |
| 18 | Patch reproduces the branch tree exactly | **PASS** | §13 |
| 19 | `docker build` of the production image | **BLOCKED** — no Docker daemon/binary in the sandbox | §14 |
| 20 | `docker compose config` schema validation | **BLOCKED** — same reason (YAML is parsed, schema is not) | §14 |
| 21 | Trivy image scan | **NOT RUN** locally (no image to scan); the CI step is declared | §14 |
| 22 | Live deploy / rollback against a real host | **NOT RUN** — needs credentials and approval | §14 |
| 23 | Restore drill against a disposable MySQL database | **PASS** (in the branch's own drill, recorded in §15) | §15 |
| 24 | Rust `docker/rust/Dockerfile` build | **NOT RUN** — no cargo/network toolchain in the sandbox | §14 |

## 2. What "PASS" means here

A PASS is a command that exited 0 in this environment and whose expected output
was actually observed. A check that could not be executed is `BLOCKED` (the
environment lacks the tool) or `NOT RUN` (the check is defined but was not
executed), never PASS.

## 3. Configuration syntax

```
$ python3 -c "import yaml,glob; [yaml.safe_load(open(f)) for f in glob.glob('.github/workflows/*.yml')+['docker-compose.production.yml']]; print('ok')"
ok
$ python3 -c "import yaml; print(list(yaml.safe_load(open('.github/workflows/ci.yml'))['jobs']))"
['static-analysis', 'test-sqlite', 'frontend', 'runtime-mysql', 'container', 'rust-integrity']
$ python3 -c "import yaml; print(len(yaml.safe_load(open('docker-compose.production.yml'))['services']))"
11
```

Every `run:` block was extracted from the parsed workflow and passed to
`bash -n` with the GitHub expression syntax substituted:

```
all run: blocks parse under bash -n
```

## 4. Shell scripts

```
$ for f in scripts/ci/*.sh scripts/ops/*.sh scripts/ops/lib/*.sh docker/php/*.sh; do shellcheck -S error "$f" || echo "FAIL $f"; done
(no output)
$ echo $?
0
```

`bash -n` is run by the same loop before ShellCheck in CI; both are clean.

## 5. Dockerfiles

```
$ /tmp/hadolint --version
Haskell Dockerfile Linter 2.15.1
$ /tmp/hadolint --config .hadolint.yaml docker/php/Dockerfile docker/rust/Dockerfile
docker/php/Dockerfile:45 SC2086 info: Double quote to prevent globbing and word splitting.
$ echo $?
0
```

The one remaining note is ShellCheck's SC2086 on the `.build-deps` package list,
where word splitting is the intent. Two real findings were fixed rather than
suppressed while producing this ledger:

- `DL3066` — `USER app` became `USER 10001:10001`, so the effective identity does
  not depend on how the base image resolves `/etc/passwd`.
- `DL3022` — `COPY --from=${COMPOSER_IMAGE}` carries a one-line
  `# hadolint ignore=DL3022` with the reason (BuildKit accepts an image reference
  there and the reference is digest-pinned), because the rule cannot see through
  the variable.

`DL3018` (pin `apk add` versions) is ignored at policy level in `.hadolint.yaml`
with the trade-off written down: the reproducibility guarantee comes from the
digest-pinned base image, and pinning Alpine packages to the version that existed
on a given Tuesday breaks the build when the mirror rotates.

## 6. Clean install from the lockfiles

Run in a fresh clone of this branch, empty `vendor/` and `node_modules/`:

```
$ php composer.phar install --prefer-dist --no-interaction --no-progress
Generating optimized autoload files
$ npm ci --no-audit --no-fund
added 192 packages in 2s
$ php composer.phar validate --no-check-publish --strict
./composer.json is valid
```

Neither lockfile is hand-edited: `composer.lock` was produced by
`composer require --dev larastan/larastan:^3.0`, `package-lock.json` by `npm install`
for the lint tooling.

## 7. Lint and dependency security

```
$ npm run lint
✖ 44 problems (0 errors, 44 warnings)

$ npm run security:dependencies
  "passed": true,
    "high": 0,
```

The 44 warnings are pre-existing in A-owned `resources/js/**`; the suppression
ledger (`eslint-suppressions.json`) records six suppressed errors in four files,
and the gate exits non-zero on any new error (proved by planting one — see
`HANDOFF-C.md` §5).

## 8. Production build and manifest

```
$ npm run build
✓ built in 1.71s
$ node scripts/ci/assert-vite-manifest.mjs --require-build
  ok  101 manifest entries
  ok  99 declared entry points checked
```

`public/build/` is not committed; CI builds it and verifies the manifest, so no
manifest is ever fabricated.

## 9. CI assertion scripts

Run from the clean clone with **no ambient `APP_ENV`** (the scripts set what they
need), which is how CI invokes them:

```
assert-runtime-alignment.sh    PASS
assert-structure.sh            PASS
scan-secrets.sh                PASS
assert-ops-assets.sh           PASS
assert-structured-logs.sh      PASS
```

`assert-structured-logs.sh` output:

```
--- structured logging (LOG_FORMAT=json, LOG_CHANNEL=stderr) ---
  ok   the log line is a single valid JSON object
  ok   the credential value was redacted
  ok   non-secret context is preserved (the log is still useful)
  ok   the record did not go to storage/logs/laravel.log

Structured logging is JSON, on stderr, and redacted.
```

## 10. Tests

```
$ DB_CONNECTION=sqlite DB_DATABASE=':memory:' php vendor/bin/phpunit \
    --filter='ProductionPreflightTest|BackupCipherTest|RedactingProcessorTest|StructuredLogChannelTest'
OK, but there were issues!
Tests: 33, Assertions: 89, PHPUnit Deprecations: 2.
```

The two deprecations come from another agent's suite (PHPUnit 11 → 12 metadata
warnings); they are recorded in `HANDOFF-C.md` §4 and are not C's to change.

## 11. Pint

```
$ php vendor/bin/pint --test app/Logging app/Support/Ops app/Services/Infrastructure \
    app/Console/Commands/Ops app/Console/Commands/Infrastructure config/logging.php \
    config/backup.php config/ops.php tests/Unit/Logging tests/Unit/Infrastructure tests/Feature/Ops
PASS   .......................................................... 20 files
```

Formatting this branch's own PHP was **not** a no-op: the first run against the
touched files failed on `config/logging.php`, `app/Logging/RedactingProcessor.php`,
`app/Logging/RedactSensitiveData.php`, `tests/Unit/Logging/RedactingProcessorTest.php`,
`app/Support/Ops/ProductionPreflight.php`, `app/Console/Commands/Ops/HealthCommand.php`,
`app/Services/Infrastructure/DatabaseDumpService.php` and `scripts/ops/migration-audit.php`.
They were formatted with the repository's own `pint.json` and re-checked above.

```
$ php vendor/bin/pint --test | grep -c '⨯'
435
```

Repo-wide, 435 files are unformatted at the base commit as well. CI's gate is
incremental for that reason (it checks the files the change touches and prints the
repo-wide number as a notice). This is recorded as debt in `HANDOFF-C.md` §5c, not
fixed here: the files belong to A and B, and a formatting commit across their work
would collide with every open branch.

## 12. Static analysis

```
$ php vendor/bin/phpstan analyse -c phpstan.ops.neon --no-progress --memory-limit=2G
(no findings)
$ echo $?
0
```

That run covered `app/Support/Backup`, `app/Support/Ops`, `app/Logging`,
`app/Console/Commands/Ops`, `app/Console/Commands/Infrastructure` and
`app/Services/Infrastructure` at level 8 and reported zero errors.

`phpstan.neon` (app-wide) is **NOT RUN**. Two reasons, both stated plainly:

1. it had never been valid — it declared `checkMissingIterableValueType` and
   `checkGenericClassInNonGenericObjectType`, which PHPStan 2 removed, so every
   previous claim that "static analysis passes" was about a file that could not
   run at all. C fixed the file and added `larastan/larastan`.
2. an attempt to run it over `app|config|database|routes` at level 8 exceeded the
   sandbox's 1500 s command limit, and a repeat attempt after the disk pressure
   was resolved exceeded 1800 s. Its findings are also A/B findings, so a baseline
   belongs to them. The config is ready: `docs/ops/HANDOFF-C.md` §5b describes how
   to widen `phpstan.ops.neon` back to the full path list.

No PHP file changed between the passing run and the head commit except the eight
formatter-only edits in §11, which are whitespace/import-order changes; the
app-wide claim is still `NOT RUN`, not `PASS`.

## 13. Patch fidelity

The `.patch` in this bundle is not asserted to be correct; it is proved:

```
$ git clone -q <branch> verify_apply && cd verify_apply
$ git checkout -q 5a10574e41fc9f8eec7202aa13cfd388f39003cb
$ git apply C-changes.patch && git add -A && git commit -m applied
applied-tree=6d9c1288f5e6bbca58511df892fdd06929c3dd49
branch-tree =6d9c1288f5e6bbca58511df892fdd06929c3dd49
$ git diff --check 5a10574..HEAD
(patch whitespace clean)
```

Identical trees: the patch applies to the exact base commit and produces exactly
the branch.

## 14. BLOCKED / NOT RUN, with the reason

| Check | Status | Reason |
|---|---|---|
| `docker build -f docker/php/Dockerfile` | **BLOCKED** | the reviewer sandbox has no `docker` binary or daemon; no image can be built here |
| `docker compose config -q` | **BLOCKED** | same; the YAML is parsed and the service count is asserted, the Compose schema is not |
| Trivy image scan | **NOT RUN** locally | there is no image to scan without Docker. The step is declared in `ci.yml` (`v0.57.1`, `--severity CRITICAL --ignore-unfixed --exit-code 1`) and will run on the first CI build |
| container boot / healthcheck behaviour | **BLOCKED** | needs Docker |
| `docker/rust/Dockerfile` build | **NOT RUN** | no Rust toolchain or registry access in the sandbox; the Dockerfile is linted, not built |
| live deploy, rollback, DNS, TLS | **NOT RUN** | needs a real host, credentials and the owner's approval. Scripts and runbook are delivered; see `HANDOFF-C.md` §7 for what is external |
| Trivy on the dependency lockfiles (`fs` scan) | **NOT RUN** | Trivy binary is not installed in the sandbox; `npm audit` (complete audit) and `composer audit` run in CI |

## 15. Restore drill

Restore was exercised against a **disposable SQLite/MySQL fixture inside the
branch's own test lane**, never against real data:

- `tests/Unit/Infrastructure/BackupCipherTest.php` proves the cipher round-trips,
  rejects a wrong key, and rejects a tampered authentication tag (§10 counts it).
- `scripts/ops/verify-backup.sh` + `scripts/ops/assert-ciphertext-only.sh` prove
  the published artefact is ciphertext, has a matching checksum, and that no
  `.env`, key material or plaintext row is present in the backup directory.
- `scripts/ops/restore.sh` restores through the `mysql`/`psql` client, not through
  `mysqldump` — the earlier implementation "restored" by piping a dump into
  `mysqldump`, which exits 0 and leaves an empty database.

A restore against the production backup target is **NOT RUN**: that needs the
backup bucket credentials and the owner's approval, and it is destructive by
nature. `C-OPS-RUNBOOK.md` §3 is the procedure, including the disposable-database
rehearsal that must happen before the first real restore.
