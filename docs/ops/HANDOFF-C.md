# HANDOFF-C — what Agent C needs from A and B, and what C did not touch

Agent C owns platform runtime, CI/CD, configuration and operations. This file
lists everything C found that belongs to another agent, with the evidence and the
smallest fix. **C did not edit any of it.**

Branch: `agent/c-platform-ops-ci` · base commit `5a10574e41fc9f8eec7202aa13cfd388f39003cb`

---

## 1. BLOCKER — `wallet_conversions` is created by three migrations (B)

**Owner:** B (`database/migrations/**`)

```
database/migrations/2026_10_13_000100_create_wallet_conversion_tables.php
database/migrations/2026_10_13_000200_create_wallet_conversion_tables.php
database/migrations/2026_10_16_000011_create_wallet_conversion_tables.php
```

The first two are guarded:

```php
if (! Schema::hasTable('wallet_conversions')) {
    Schema::create('wallet_conversions', …);
}
```

`2026_10_16_000011` is **not** guarded:

```php
Schema::create('wallet_conversions', …);   // line 29 of that file
Schema::create('wallet_conversion_quotes', …);
Schema::create('wallet_conversion_audits', …);
```

Because `000100` and `000200` run first, `000011` always finds the table present.

**Reproduced on MySQL 11.8.6** (this is not a SQLite artefact):

```
$ DB_DATABASE=tl_migrate_full php artisan migrate --force
SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'wallet_conversions' already exists
  at database/migrations/2026_10_16_000011_create_wallet_conversion_tables.php:29
```

### Blast radius

| Path | Effect |
|---|---|
| Every fresh database (dev, staging, CI, production bootstrap) | migration run aborts part-way |
| `tests/TestCase.php:103` (migrate on boot) | every `RefreshDatabase` suite errors before the first assertion |
| CI job `runtime-mysql` (`migrate --force`) | red |
| CI job `test-sqlite`, `financial-concurrency` | red |
| C's own end-to-end smoke test | `/results` returns 500 (`Table 'tl_drill.draws' doesn't exist`) because the schema never got built |

### Smallest fix (B's call)

Either delete the two `2026_10_13_*` duplicates, or wrap `2026_10_16_000011` in
the same `hasTable()` guards the earlier pair uses. The guarded form is the
safer of the two: it is idempotent on databases that were migrated by an earlier
ordering.

C did **not** apply this. `tests/Feature/Ops/ProductionPreflightTest.php` was
made schema-independent instead (it boots the framework's base test case and
passes `withConnectivity: false`), so the preflight gate is covered today
without hiding the schema problem.

---

## 2. BLOCKER — competitor brand strings in production source (A)

**Owner:** A (`resources/views/**`, `resources/css/**`, `resources/js/**`)

```
$ bash scripts/ci/assert-result-lane-seams.sh
resources/css/theme.css:2: * THAILOTTO ENTERPRISE DESIGN SYSTEM — THEME TOKENS
resources/js/components/PlayerSettings.tsx:103:  … THAILOTTO MEMBER …
resources/js/home-keypad.js:58:  window.thaiLottoKeypad = {
resources/js/_unused/components/PlayerSettings.tsx:123: … THAILOTTO MEMBER …
::error::a competitor reference reached production source
```

Provenance doctrine says a competitor's brand must not appear in shipped source.
The `static-analysis` CI job is red until A removes the strings or the seam
policy is amended deliberately (which must be a decision, not a silent allowlist
entry).

---

## 3. GAP — the settlement worker mounts an HMAC secret it never loads (B)

**Owner:** B (`runtime/settlement-worker/src/main.rs`)

`docker-compose.production.yml` mounts `settlement_worker_hmac_secret` and the
worker verifies request signatures with HMAC-SHA256 — but the only `env::var`
calls in the worker are `DATABASE_URL` and `WORKER_BIND`:

```
$ grep -rn "env::var" runtime/settlement-worker/src/*.rs
485:    let database_url = std::env::var("DATABASE_URL")
492:    let bind: SocketAddr = std::env::var("WORKER_BIND")
```

So the secret is mounted and unused, and the signature check is unreachable in
production. C removed the misleading `SETTLEMENT_WORKER_HMAC_SECRET_FILE` /
`DATABASE_URL_FILE` variables from compose (nothing read them) and kept the
mount, commented. **B needs to load the secret** (`SETTLEMENT_WORKER_HMAC_SECRET`
env or `/run/secrets/settlement_worker_hmac_secret`) and wire the verification.

---

## 4. NOTICE — PHPUnit deprecations in another agent's suite

```
1) Metadata found in doc-comment for Tests\Unit\Chat\ChatExceptionContractTest::test_machine_code_and_status_are_the_contract()
2) Metadata found in doc-comment for Tests\Unit\Chat\ChatExceptionContractTest::test_wire_envelope_is_stable()
```

PHPUnit 12 will drop doc-comment metadata; these two tests need attributes.
Their file is not C's. They do not fail the suite today, they only add noise to
every run.

---

## 5. A-OWNED LINT DEBT — 6 suppressed errors, 44 warnings

C added the missing front-end lint gate (`eslint.config.mjs`, `npm run lint`,
wired into the `frontend` and `runtime-mysql` CI jobs). The first honest run
found:

```
✖ 50 problems (6 errors, 44 warnings)
```

All 50 are in A-owned `resources/js/**`. C did not edit those files and did not
lower a rule to make them disappear. Instead:

- the **6 errors** (`no-useless-assignment` — a computed value written and then
  immediately overwritten) are recorded in `eslint-suppressions.json`, which
  ESLint checks by count. The list is in the repository, enumerable, and CI
  fails if a *new* error appears anywhere.
- the **44 warnings** (unused variables/parameters, `any` in TS) are reported by
  every run but do not block. `npm run lint:strict` (`--max-warnings=0`) is the
  command to adopt once they are cleared.

Files named in the suppressions file:

| File | Rule | Count |
|---|---|---:|
| `resources/js/account-verification-portal.js` | `no-useless-assignment` | 1 |
| `resources/js/auth-portal.js` | `no-useless-assignment` | 2 |
| `resources/js/glo-results-checker.js` | `no-useless-assignment` | 2 |
| `resources/js/prize-verification-portal.js` | `no-useless-assignment` | 1 |

To clear an entry: fix the assignment, run `npm run lint:suppress` (prunes stale
entries), commit the smaller file. CI's lint step also refuses a suppressions
file that names anything outside `resources/js/`, so C cannot use it for its own
code.

Formatting rules (indentation, quotes, semicolons) are deliberately **not**
enabled: this gate exists to catch bugs, not to re-litigate style in another
agent's components.

---

## 5b. NOTICE — PHPStan analyses the platform code only, for now

`phpstan.neon` (app-wide, level 8) has existed in the repository but never ran:
it listed `checkMissingIterableValueType` and
`checkGenericClassInNonGenericObjectType`, which PHPStan 2 rejects, so the whole
configuration was invalid. C fixed the file, added `larastan/larastan` as a dev
dependency, and added `phpstan.ops.neon` — the same rules narrowed to the
platform/operations directories — because a full analysis of `app/`, `config/`,
`database/` and `routes/` at level 8 needs a baseline of the existing findings
first, and takes long enough that CI would time out.

CI runs `vendor/bin/phpstan analyse -c phpstan.ops.neon`. Widening the paths in
`phpstan.ops.neon` back to the full set is a follow-up that belongs to whoever
owns the resulting findings (A and B); the config is ready for it.

## 5c. STYLE — a repo-wide Pint run is red before this branch arrives

`vendor/bin/pint --test` reports **435 files** that are not formatted to the
project's own `pint.json`. That is the state of `main`, not something this branch
introduced: C's own 24 touched PHP files are formatted and pass.

Two things follow:

1. CI's Pint gate is **incremental**. It computes the merge base against the pull
   request target and checks only the PHP files the change touches, then prints
   the repo-wide count as a `::notice::`. A gate that is red before you land is a
   gate people learn to bypass; a gate that is green for your change and reports
   the debt is one that keeps working.
2. Somebody still has to burn that debt down. The 435 files are almost entirely
   A-owned (`app/Http`, `resources/`-adjacent controllers) and B-owned (`app/Models`,
   `app/Services`), so C does not reformat them: a formatting commit from the
   platform agent would collide with every open branch and obscure real diffs.
   Suggested owner action: one `vendor/bin/pint` commit per area, on a quiet day.

## 6. What C changed outside the pure "ops" directories (and why)

These are the only non-ops files C touched. Each is a platform concern, not a
business or presentation one.

| File | Change | Why it is C's |
|---|---|---|
| `app/Http/Middleware/VerifyCsrfToken.php` | exposed `public const WEBHOOK_EXCEPTIONS` | the constant was private and unreachable; CSRF exemption for webhooks is a platform/edge concern |
| `app/Providers/ProductionSafetyServiceProvider.php` | console failures now `exit(2)` with a clean message instead of an unhandled exception with a stack trace | a deploy pipeline greps stdout, not a PHP trace |
| `app/Console/Commands/Ops/HealthCommand.php` | Redis → `NOT_CONFIGURED` when unused; heartbeat severity follows `APP_ENV` | an always-DEGRADED health check trains operators to ignore it |
| `app/Support/Ops/ProductionPreflight.php` | `QUEUE_CONNECTION=sync` is FAIL only in production | same reason |
| `routes/console.php` | heartbeat + daily backup schedule entries | scheduler wiring is operations |
| `config/{backup,logging,ops}.php` | new/edited operations config: `LOG_FORMAT` (text/json), explicit `php://stderr` stream, redaction tap added to `slack` and `papertrail` | configuration |
| `phpstan.neon`, `phpstan.ops.neon` | made the existing config valid for PHPStan 2, added a scoped config | static analysis is a CI concern |
| `eslint.config.mjs`, `eslint-suppressions.json`, `package.json` scripts | front-end lint gate (see §5) | tooling, not front-end implementation |
| `.env.example`, `.env.production.example`, `.env.ci` | placeholders, no secrets | environment templates |

No model, migration, service, controller, view, CSS or JS file owned by A or B
was modified.

---

## 7. Repo-implementable vs external

**Implemented in this branch:** everything in `docs/ops/OPS-RUNBOOK.md`.

**External — not implementable in a repository, must be done by the operator:**

| Item | Why it cannot be code |
|---|---|
| DNS records and TLS issuance | needs registrar and ACME/CA interaction; the repo can only ship `docker/nginx/certs/bootstrap.sh` and document the expectation |
| Payment provider credentials (Stripe, provider keys) | secrets live in the operator's secret store, never in source |
| GLO official source authorisation | a data-sharing/legal agreement with the operator; `GLO_OFFICIAL_SOURCE_MODE=official` only asserts intent |
| Legal authorisation to sell lottery tickets in the operating jurisdiction | not a software artefact; a licence is required before `PRIZE_PAYOUT_SAFETY_MODE=LIVE` |
| Off-host backup storage (WORM bucket, retention lock) | needs a bucket, credentials and an immutability policy; the lane supports an upload disk but the policy must be provisioned |
| Secret manager seeding (`backup_key`, `app_key`, provider keys) | must be created by the operator and never printed |
| Container registry + image signing | registry credentials and a signing identity are operator-held |
