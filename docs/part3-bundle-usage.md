# Complete-file delivery, restore and local full-green reproduction

The refreshed **f42** bundle contains **158 complete files**, not patches or excerpts. Membership is
`docs/part3-bundle-files.txt`; explicit removals are in `docs/part3-bundle-deletions.txt`.
Thirteen changed/new PHP files total **3,706 complete lines**. The original **717-line** core acceptance
file is also included unchanged. Earlier full app/runtime/native/test sources remain included.

## Artifacts

- `part3-bundle.tar`: complete source; no caches, compiled target/build, database, live `.env` or credential.
- `part3-bundle.manifest.json`: archive/member hashes, deletions, measured full-green evidence and invocation-boundary checks.
- `restore-part3-bundle.py`: standalone Python 3.10+ verifier/restorer, also under `scripts/`.
- `part3-backup/`: independently verified extraction of the same source bytes.

This updates an existing repository, not replace every unchanged base file as a standalone application.
Checksums detect corruption/reversion, not authenticity of an untrusted distributor.

## Actual final local results

| Scope | Result |
|---|---|
| **Unfiltered full PHP repository** | **2,538 tests, 113,966 assertions; all pass, zero errors/failures/skips, exit 0** |
| Expanded PHP regression | 1,441 tests, 107,236 assertions; all pass, no skips |
| Manual checkpoint/inventory scope | 51 tests, 315 assertions; all pass, no skips |
| Original cursor acceptance class, unchanged | 16 tests, 76 assertions; all pass |
| Extra actual manual concurrency stability | 3 runs; each 2 tests / 46 assertions; all pass |
| Retained locked Rust release tests | 29 pass |
| Standalone restorer | 10 Python tests pass |
| Complete PHP/Blade source | 125 syntax checks pass |

All 51 original f35 failing names now pass, including the remaining fifteen original cursor cases.
No original core assertion is deleted/renamed/skipped. Both old runtime skips still genuinely execute.
An initial F42 inventory failure is retained separately, not used as final green evidence; the retired
job is truthfully declared deferred with runtime refusal, not wired to create test success.

**Local green is not production-ready/live deployment certification.** No actual customer funds,
external gateway transfer, public/scheduled invocation, production mode/threshold change or deployment
was performed. The legacy CLI stays status-only, its job remains refusing, and old dispatch is disabled.
The manual API uses existing payer/approval logic with atomic server-owned checkpoint callbacks.
See `docs/manual-cursor-settlement-status.md`, current CI status and full historical classification.

## Verify without writes

From the directory holding the archive, manifest and standalone script:

```bash
python3 restore-part3-bundle.py verify \
  --archive part3-bundle.tar \
  --manifest part3-bundle.manifest.json \
  --project thai-lottery-ainul-islam
```

## Restore source after reset

```bash
python3 restore-part3-bundle.py restore \
  --archive part3-bundle.tar \
  --manifest part3-bundle.manifest.json \
  --project thai-lottery-ainul-islam
```

The destination must exist. All archive/member hashes are checked before writing. Traversal, links,
duplicate/extra members and destination symlinks are refused; live `.env` is never overwritten. The
retired `app/Services/Lottery/CircuitBreaker.php` is explicitly removed, unlike a plain extraction.
Each file replacement is atomic; interruption can leave a partial set, so rerun restore and verify.

Restore does **not** install toolchains, compile native code, migrate/backfill a database, import
results, provision keys, invoke a queue/scheduler or move money.

## Required local test runtime after reset

PHP **8.4** with all checked extensions, supported Node, Rust/Cargo/Clippy/rustfmt and a host linker:

```bash
php scripts/check-test-runtime.php
```

Debian administrator example:

```bash
sudo apt-get install php8.4-cli php8.4-intl php8.4-gd php8.4-mysql \
  php8.4-sqlite3 php8.4-mbstring php8.4-xml php8.4-curl \
  php8.4-zip php8.4-bcmath rustc cargo rust-clippy rustfmt build-essential
```

Prepare locked dependencies/assets/native before default full tests:

```bash
composer install --no-interaction --prefer-dist
npm ci
npm run build
CARGO_HOME="$HOME/.cache/weekly-cargo" bash scripts/build-weekly-native-verifier.sh
```

Native target output/cache is not bundled or snapshot-persisted; **rebuild after reset**. The script
addresses only the non-monetary integrity crate and runs locked strict release gates. It does not
build the monetary worker or invoke Laravel operations. Public fixture signatures are not provider
signing authority; no production signing key/fallback setting is changed.

Use an approved development environment, not overwrite a deployment `.env`. CI uses `.env.ci` and
production templates retain blank credentials, fixture lanes off and payout automation disabled.
All four explicit labelled fixture flags in `phpunit.xml` are unchanged.

Tests provision their guarded disposable schema. The new additive migration adds server-owned snapshot
fields/witness rows with nullable historical fields: **no backfill or old-run activation**. Any actual
operational migration, rollback, backup and invocation surface still need normal independent review.
Do not clear ownership/idempotency/KYC/approvals to force a historical run through the manual API.

## Reproduce the completed original contract and new safety checks

```bash
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/Draw/ChunkedSettlementTest.php \
  tests/Feature/Draw/ManualSettlementCheckpointSafetyTest.php \
  tests/Feature/Draw/ManualSettlementCheckpointConcurrencyTest.php \
  tests/Feature/Draw/ManualSettlementCheckpointMigrationTest.php \
  tests/Unit/Settlement/ManualSettlementCheckpointContractTest.php \
  tests/Feature/Production/QueueDispatchContractTest.php
```

Use `/usr/bin/php8.4` in this sandbox. The actual unfiltered command remains:

```bash
php -d memory_limit=-1 vendor/bin/phpunit
```

Its final measured exit is 0 with no hidden filter/skip/failure suppression. The complete expanded
command is in `docs/ci-regression-status.md`; all historical original failure details are retained in
`docs/full-suite-failure-classification.json` with verified current passing names/assertions.

## Deployment and evidence limits

The new APIs are explicit manual internal code, not a newly enabled CLI/job/public/scheduled producer.
Under existing LIVE policy they can coordinate local wallet/ledger credit; in all executed tests the
accounts/database are synthetic and disposable. Non-LIVE/high-value obligations remain pending.
Completed metadata/fingerprints are not a real bank/provider transfer certificate or defense against
an administrator rewriting data/seals. Conservative rechecking is not a high-throughput benchmark.

Hosted CI, operational backup/restore/migration/deployment, real source signing, gateway transfer,
server row-lock/cross-host and load/100000-slip-seconds behavior were not certified here.

## Restore-tool tests

```bash
python3 -m unittest discover -s tests/tools -p 'test_restore_part3_bundle.py' -v
```

Ten tests cover complete restore/deletions, verify-only non-mutation, tampering, missing members,
traversal, links, invalid parents and `.env` protection. Older large logs remain losslessly compressed.
