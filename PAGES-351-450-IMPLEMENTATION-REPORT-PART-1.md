# TYPE: Pages 351–450 implementation report
# PURPOSE: Complete changed-file manifest and complete changed-file contents for Pages 351–450, with factual runtime evidence and no substitute content.
## Scope and evidence boundary
This report contains every file changed or created for the Pages 351–450 continuation. It does not claim PHP, Composer, Laravel, database, Redis, queue, scheduler, provider, browser, or Rust success without execution. The final enterprise runtime boundary is `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`; the isolated frontend dependency remediation was verified locally.
## Complete changed-file manifest
| Path | # TYPE | # PURPOSE |
|---|---|---|
| `.github/workflows/ci.yml` | YAML workflow | Existing CI workflow extended with Composer, NPM, security, Rust, and release build gates. |
| `package.json` | JSON manifest | Frontend dependency manifest updated to the compatible Vite 8 and Laravel Vite plugin toolchain. |
| `package-lock.json` | NPM lockfile | Reproducible lockfile for the remediated frontend dependency graph. |
| `scripts/runtime_preflight.py` | Python script | Prior preflight updated to emit an observed UTC timestamp and allowed status values. |
| `scripts/pages_251_350_runtime_gate.py` | Python script | Prior acceptance gate updated to emit start and finish UTC timestamps and allowed status values. |
| `scripts/pages_351_450_runtime_preflight.py` | Python script | Secret-free, timestamped inventory of required production runtimes and configuration presence. |
| `scripts/pages_351_450_command_gate.py` | Python script | Independent attempt of the Pages 351–450 runtime commands with machine-readable status. |
| `scripts/pages_351_450_audit_matrix.py` | Python script | Generator for exactly one factual audit row for every Page 351–450. |
| `scripts/pages_351_450_matrices.py` | Python script | Generator for the Pages 351–450 acceptance and integrity matrices. |
| `audit.md` | Markdown audit | Cumulative audit retaining historical coverage, adding one row for each Page 351–450, and recording the Pages 1–450 coverage register. |
| `PAGES-351-450-MATRICES.md` | Markdown matrices | Acceptance ledger plus route/API/security/finance/lottery/Rust/runtime matrices. |
| `RUNTIME-VERIFICATION-REPORT.md` | Markdown report | Updated runtime evidence and explicit Pages 351–450 blocker boundary. |
| `FINANCIAL-INTEGRITY-REPORT.md` | Markdown report | Updated financial activation, provider, replay, reconciliation, and GLO evidence boundary. |
| `RUST-RUNTIME-REPORT.md` | Markdown report | Updated Cargo/Rust command attempts and Laravel trust-boundary evidence. |
| `LOTTERY-INTEGRITY-REPORT.md` | Markdown report | Draw, import, provenance, publication, ticket, prize, claim, freeze, and GLO integrity boundary. |
| `SECURITY-RUNTIME-REPORT.md` | Markdown report | Authentication, authorization, IDOR, CSRF, MFA, webhook, rate-limit, KYC, support, and redaction boundary. |
| `DATABASE-RUNTIME-REPORT.md` | Markdown report | Database, migration, transaction, backup, restore, and data-activation boundary. |
| `CI-CD-VERIFICATION-REPORT.md` | Markdown report | CI/security source inspection, new release gates, and dependency-remediation evidence. |
| `runtime/page-251-preflight.json` | JSON evidence | Timestamped prior-phase preflight rerun evidence. |
| `runtime/page-350-acceptance.json` | JSON evidence | Timestamped prior-phase Page 350 acceptance-gate rerun evidence. |
| `runtime/page-351-preflight.json` | JSON evidence | Timestamped Page 351–450 dependency and environment preflight evidence. |
| `runtime/pages-351-450-command-results.json` | JSON evidence | Machine-readable results of all attempted Page 351–450 commands. |
| `runtime/page-351-dependency-remediation.json` | JSON evidence | NPM vulnerability investigation, compatible upgrade, audit, and build evidence. |
## Complete changed-file contents
### `.github/workflows/ci.yml`
# TYPE: YAML workflow
# PURPOSE: Existing CI workflow extended with Composer, NPM, security, Rust, and release build gates.

````yaml
name: CI

on:
  push:
  pull_request:

jobs:
  test:
    name: PHP tests
    runs-on: ubuntu-latest

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          # intl is required by filament/support (a transitive composer
          # requirement) and by the Filament resource render assertions. It is
          # already present on the runner image; naming it here makes that a
          # declared dependency rather than a lucky default.
          extensions: mbstring, dom, fileinfo, sqlite3, pdo_sqlite, bcmath, sodium, zip, intl
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Validate composer.json
        run: composer validate --no-check-publish

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Install front-end dependencies
        run: npm ci

      - name: Build front-end assets
        run: npm run build

      - name: Assert the Vite manifest and every registered entry were built
        run: |
          set -euo pipefail
          test -f public/build/manifest.json
          # Every input declared in vite.config.js must have produced an asset.
          # An empty or missing module still "builds", so the manifest is
          # checked entry by entry rather than as a whole.
          node -e '
            const fs = require("fs");
            const manifest = JSON.parse(fs.readFileSync("public/build/manifest.json", "utf8"));
            const config = fs.readFileSync("vite.config.js", "utf8");
            const inputs = [...config.matchAll(/["'"'"'](resources\/(?:js|css)\/[^"'"'"']+)["'"'"']/g)].map(m => m[1]);
            let failed = false;
            for (const input of new Set(inputs)) {
              const record = manifest[input];
              if (!record || !record.file || !fs.existsSync("public/build/" + record.file)) {
                console.error("::error::vite entry not built: " + input);
                failed = true;
                continue;
              }
              if (fs.statSync("resources/" + input.slice("resources/".length)).size === 0) {
                console.error("::error::vite entry is an empty file: " + input);
                failed = true;
              }
            }
            process.exit(failed ? 1 : 0);
          '

      - name: PHP syntax check (php -l on all project PHP files)
        run: |
          set -euo pipefail
          fail=0
          while IFS= read -r -d '' f; do
            if ! php -l "$f" > /dev/null; then
              echo "::error file=$f::syntax error"
              fail=1
            fi
          done < <(find app bootstrap config database routes tests -name '*.php' -print0)
          # Required structural files — fail the build if any are missing
          for req in \
            app/Models/User.php \
            app/Models/Agent.php \
            app/Services/Draw/RealPrizeSettlementService.php \
            app/Services/Finance/FinancialReconciliationService.php \
            app/Services/Finance/WithdrawalService.php \
            app/Console/Commands/GloPublicResultPublishCommand.php \
            app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php \
            app/Filament/Pages/KycReviewPage.php \
            resources/views/filament/pages/kyc-review-page.blade.php \
            resources/views/home.blade.php \
            resources/views/results/index.blade.php \
            routes/api.php \
            routes/web.php \
            config/finance.php \
            .github/workflows/ci.yml
          do
            if [ ! -f "$req" ]; then
              echo "::error::missing required file: $req"
              fail=1
            fi
          done
          # At least one test file and one migration must exist
          if [ -z "$(find tests -name '*Test.php' -print -quit)" ]; then
            echo "::error::no test files found"
            fail=1
          fi
          if [ -z "$(find database/migrations -name '*.php' -print -quit)" ]; then
            echo "::error::no migrations found"
            fail=1
          fi
          exit $fail

      - name: Assert the writable runtime directories are present in the checkout
        run: |
          set -euo pipefail
          fail=0
          # These are the directories Laravel writes to at runtime. Git does not
          # track empty directories, so each one needs its own .gitignore marker
          # to survive a clone. A missing marker only shows up as a confusing
          # "Please provide a valid cache path" much later.
          for dir in \
            bootstrap/cache \
            storage/app/private \
            storage/app/public \
            storage/framework/cache/data \
            storage/framework/sessions \
            storage/framework/testing \
            storage/framework/views \
            storage/logs
          do
            if [ ! -d "$dir" ]; then
              echo "::error::missing runtime directory: $dir"
              fail=1
            fi
          done
          exit $fail

      - name: Assert every model with HasFactory has a factory
        run: |
          set -euo pipefail
          fail=0
          while IFS= read -r -d '' model; do
            name="$(basename "$model" .php)"
            if grep -q 'HasFactory' "$model" && [ ! -f "database/factories/${name}Factory.php" ]; then
              echo "::error file=$model::model declares HasFactory but database/factories/${name}Factory.php is missing"
              fail=1
            fi
          done < <(find app/Models -name '*.php' -print0)
          exit $fail

      - name: Application key and route optimisation
        run: |
          cp .env.example .env 2>/dev/null || true
          php artisan key:generate --force || true
          php artisan optimize:clear
          php artisan config:cache
          # Fail if config:cache did not produce a bootstrap cache
          test -f bootstrap/cache/config.php

      - name: Migrate fresh with seed
        run: |
          php artisan migrate:fresh --force --seed

      - name: Route list (contract smoke)
        run: |
          php artisan route:list
          php artisan route:list --path=/
          php artisan route:list --path=results

      - name: Run test suite
        env:
          APP_ENV: testing
        run: |
          # A cached config file wins over phpunit.xml's <env> overrides, which
          # points the suite at the development database and connection instead
          # of the in-memory test one. The cache is proven above and discarded
          # here, before the suite runs.
          php artisan config:clear
          test ! -f bootstrap/cache/config.php
          # Tests must never be skipped to go green: suite exit code is authoritative
          php artisan test

      # ---------------------------------------------------------------------
      # PROMPT 7: the seams between a result lane and the rest of the repo.
      #
      # The full suite above already runs these tests. This step exists
      # because a seam failure is a DIFFERENT KIND of failure: the lane works,
      # every unit passes, and the feature is still unreachable or its
      # configuration undocumented. Naming the contract here means the CI log
      # says which agreement broke instead of just "a test failed".
      #
      # Cheap greps first, so an obvious breach fails in a second rather than
      # after the whole suite.
      # ---------------------------------------------------------------------
      - name: Assert the result-lane seams are closed
        run: |
          set -euo pipefail

          echo "--- robots.txt: search blocked, content crawlable ---"
          test -f public/robots.txt
          for lane in national-lottery weekly-lottery bingo-lottery pcso-lottery; do
            grep -qx "Disallow: /${lane}/search" public/robots.txt \
              || { echo "::error::robots.txt does not block /${lane}/search"; exit 1; }
            # A bare prefix rule would deindex the entire lane.
            if grep -qE "^Disallow:[[:space:]]*/${lane}/?[[:space:]]*$" public/robots.txt; then
              echo "::error::robots.txt blocks the whole ${lane} lane, not just its search route"
              exit 1
            fi
            if grep -qE "^Disallow:[[:space:]]*/${lane}/year" public/robots.txt; then
              echo "::error::robots.txt blocks the ${lane} year archive"
              exit 1
            fi
          done

          echo "--- .env.example documents every key each lane config reads ---"
          node -e '
            const fs = require("fs");
            const example = fs.readFileSync(".env.example", "utf8");
            let failed = false;
            for (const lane of ["national_lottery", "weekly_lottery", "bingo_lottery", "pcso_lottery", "contact"]) {
              const config = fs.readFileSync("config/" + lane + ".php", "utf8");
              const prefix = lane === "contact" ? "CONTACT" : lane.toUpperCase();
              const keys = new Set(
                [...config.matchAll(new RegExp("env\\(\\s*[\x27\"](" + prefix + "_[A-Z0-9_]+)[\x27\"]", "g"))]
                  .map(m => m[1])
              );
              if (keys.size === 0) {
                console.error("::error::no env keys parsed out of config/" + lane + ".php");
                failed = true;
                continue;
              }
              for (const key of keys) {
                // A commented-out entry counts: credentials are named but valueless.
                if (!new RegExp("^#?\\s*" + key + "=", "m").test(example)) {
                  console.error("::error::" + key + " is read by config/" + lane + ".php but undocumented in .env.example");
                  failed = true;
                }
                // A credential must never ship with a value.
                if (/(TOKEN|SECRET|KEY)$/.test(key) && new RegExp("^" + key + "=.+", "m").test(example)) {
                  console.error("::error::" + key + " has a value in .env.example");
                  failed = true;
                }
              }
            }
            process.exit(failed ? 1 : 0);
          '

          echo "--- /contact must stay crawlable ---"
          if grep -qE "^Disallow:[[:space:]]*/contact" public/robots.txt; then
            echo "::error::robots.txt blocks /contact, which is ordinary public content"
            exit 1
          fi

          echo "--- no competitor string in any public surface ---"
          # The reference pages that motivated these lanes may be named in
          # audit notes and prompts. They must never appear in anything that
          # ships. Scoped to production source so a research note in a report
          # file does not fail the build.
          if grep -rniE "thailotto" \
               app config routes lang public database \
               resources/views resources/css resources/js \
               2>/dev/null | grep -v "^resources/views/vendor/"; then
            echo "::error::a competitor reference reached production source"
            exit 1
          fi

          echo "--- guest navigation links both public lanes ---"
          for name in national-lottery.index weekly-lottery.index bingo-lottery.index pcso-lottery.index contact; do
            grep -q "route('${name}')" resources/views/layouts/app.blade.php \
              || { echo "::error::guest navigation has no link to ${name}"; exit 1; }
          done

          echo "Seam contracts hold."

      - name: Seam and lane test suites (named, so a failure says which)
        env:
          APP_ENV: testing
        run: |
          set -euo pipefail
          php artisan test \
            --filter='NationalLotteryIntegrationSeamTest|NationalLotteryPublicPageTest|WeeklyLotteryPublicPageTest|BingoLotteryPublicPageTest|PcsoLotteryPublicPageTest|ContactPageTest'

      - name: Pint (if configured)
        run: |
          if [ -f vendor/bin/pint ]; then
            vendor/bin/pint --test
          else
            echo "Pint not installed — skipping style gate"
          fi

  # ---------------------------------------------------------------------------
  # PROMPT 6: the Weekly Lottery integrity crate.
  #
  # A SEPARATE JOB, not extra steps on the PHP job, for two reasons. It needs a
  # Rust toolchain the PHP job has no use for, and the two must be able to fail
  # independently: a broken canonicalizer and a broken Blade template are
  # different problems and should not hide behind one red cross.
  #
  # --locked everywhere. The lock file is committed, and a CI run that silently
  # resolved a different dependency tree would not be testing what ships - which
  # matters more than usual for a component whose whole job is producing a
  # deterministic hash.
  # ---------------------------------------------------------------------------
  rust-integrity:
    name: Rust integrity verifier
    runs-on: ubuntu-latest

    defaults:
      run:
        working-directory: security/weekly-result-integrity

    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup Rust
        uses: dtolnay/rust-toolchain@stable
        with:
          components: rustfmt

      - name: Cache cargo registry and build output
        uses: actions/cache@v4
        with:
          path: |
            ~/.cargo/registry
            ~/.cargo/git
            security/weekly-result-integrity/target
          key: cargo-${{ runner.os }}-${{ hashFiles('security/weekly-result-integrity/Cargo.lock') }}
          restore-keys: cargo-${{ runner.os }}-

      - name: Formatting
        run: cargo fmt --check

      - name: Type check
        run: cargo check --locked --all-targets

      - name: Tests
        run: cargo test --locked

      - name: Assert the crate cannot reach the network or a database
        run: |
          set -euo pipefail
          # The "no network" property is enforced by ABSENCE of any such
          # dependency. tests/integrity.rs already asserts this against
          # Cargo.lock; this repeats it against the RESOLVED tree so a
          # transitive addition cannot slip in behind a direct one.
          tree=$(cargo tree --locked --edges normal --prefix none)
          for crate in tokio reqwest hyper curl ureq socket2 mio rustls native-tls sqlx diesel postgres tokio-postgres; do
            if printf '%s\n' "$tree" | grep -qE "^${crate} v"; then
              echo "::error::forbidden dependency in the integrity crate: ${crate}"
              exit 1
            fi
          done
          echo "No networking or database crate is present."

      - name: Release build (the binary Laravel invokes)
        run: cargo build --release --locked

      - name: Smoke test the stdin/stdout contract
        run: |
          set -euo pipefail
          # Leading zeros must survive, and a malformed value must be refused
          # with exit code 2 rather than repaired.
          ok=$(printf '%s' '{"draw_reference":"WK-20260918","draw_date":"2026-09-18","first_6":"001234","three_ball":"049","two_ball":"09","source_identifier":"CI","parser_version":"1"}' \
            | ./target/release/weekly-result-integrity)
          echo "$ok"
          printf '%s\n' "$ok" | grep -q '"status":"INTEGRITY_HASH_ONLY"'
          printf '%s\n' "$ok" | grep -q '"acceptable":true'

          set +e
          printf '%s' '{"draw_reference":"WK-20260918","draw_date":"2026-09-18","first_6":"1234","parser_version":"1"}' \
            | ./target/release/weekly-result-integrity
          code=$?
          set -e
          test "$code" -eq 2
          echo "Malformed payload refused with exit code 2."

  runtime-and-rust-contracts:
    name: Runtime and Rust contract gates
    runs-on: ubuntu-latest
    steps:
      - name: Checkout
        uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: mbstring, dom, fileinfo, sqlite3, pdo_sqlite, bcmath, sodium, zip, intl
          coverage: none

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '20'
          cache: 'npm'

      - name: Setup Rust
        uses: dtolnay/rust-toolchain@stable
        with:
          toolchain: stable

      - name: Validate Composer platform and dependencies
        run: |
          set -euo pipefail
          composer validate --no-check-publish
          composer install --prefer-dist --no-interaction --no-progress
          composer check-platform-reqs
          composer audit --no-interaction

      - name: Install and audit frontend dependencies
        run: |
          set -euo pipefail
          npm ci
          npm audit --audit-level=high
          npm run build

      - name: Verify Rust integrity crate
        working-directory: security/weekly-result-integrity
        run: |
          set -euo pipefail
          cargo check --locked
          cargo test --locked
          cargo build --release --locked
````

### `package.json`
# TYPE: JSON manifest
# PURPOSE: Frontend dependency manifest updated to the compatible Vite 8 and Laravel Vite plugin toolchain.

````json
{
    "private": true,
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "vite build"
    },
    "devDependencies": {
        "autoprefixer": "^10.4.20",
        "axios": "^1.7.9",
        "fontaine": "^0.8.2",
        "laravel-vite-plugin": "^3.2.0",
        "postcss": "^8.4.49",
        "tailwindcss": "^3.4.17",
        "vite": "^8.3.1"
    },
    "dependencies": {
        "react": "^18.3.1",
        "react-dom": "^18.3.1"
    }
}
````

### `package-lock.json`
# TYPE: NPM lockfile
# PURPOSE: Reproducible lockfile for the remediated frontend dependency graph.

````json
{
    "name": "user",
    "lockfileVersion": 3,
    "requires": true,
    "packages": {
        "": {
            "dependencies": {
                "react": "^18.3.1",
                "react-dom": "^18.3.1"
            },
            "devDependencies": {
                "autoprefixer": "^10.4.20",
                "axios": "^1.7.9",
                "fontaine": "^0.8.2",
                "laravel-vite-plugin": "^3.2.0",
                "postcss": "^8.4.49",
                "tailwindcss": "^3.4.17",
                "vite": "^8.3.1"
            }
        },
        "node_modules/@alloc/quick-lru": {
            "version": "5.3.0",
            "resolved": "https://registry.npmjs.org/@alloc/quick-lru/-/quick-lru-5.3.0.tgz",
            "integrity": "sha512-U4+70Pc5ZS9osnCBCE5Jha/ciHM+Yp+CNMNC/7HvYbNRk1Ldd+f7qO65W5qfhu/TCv+/ozljlXXe9Nj8419DMA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=10"
            },
            "funding": {
                "url": "https://github.com/sponsors/sindresorhus"
            }
        },
        "node_modules/@capsizecss/unpack": {
            "version": "4.0.1",
            "resolved": "https://registry.npmjs.org/@capsizecss/unpack/-/unpack-4.0.1.tgz",
            "integrity": "sha512-CuNiSqg7+e1cO/GjffyMOm5Tt2jUF9CWHHnvQ/UkqvtkGfHdgwEC0wpmq7fkN3gxwpRnrAN0WzO3vREKmNolMQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fontkitten": "^1.0.3"
            },
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/@jridgewell/gen-mapping": {
            "version": "0.3.13",
            "resolved": "https://registry.npmjs.org/@jridgewell/gen-mapping/-/gen-mapping-0.3.13.tgz",
            "integrity": "sha512-2kkt/7niJ6MgEPxF0bYdQ6etZaA+fQvDcLKckhy1yIQOzaoKjBBjSj63/aLVjYE3qhRt5dvM+uUyfCg6UKCBbA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/sourcemap-codec": "^1.5.0",
                "@jridgewell/trace-mapping": "^0.3.24"
            }
        },
        "node_modules/@jridgewell/remapping": {
            "version": "2.3.5",
            "resolved": "https://registry.npmjs.org/@jridgewell/remapping/-/remapping-2.3.5.tgz",
            "integrity": "sha512-LI9u/+laYG4Ds1TDKSJW2YPrIlcVYOwi2fUC6xB43lueCjgxV4lffOCZCtYFiH6TNOX+tQKXx97T4IKHbhyHEQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/gen-mapping": "^0.3.5",
                "@jridgewell/trace-mapping": "^0.3.24"
            }
        },
        "node_modules/@jridgewell/resolve-uri": {
            "version": "3.1.2",
            "resolved": "https://registry.npmjs.org/@jridgewell/resolve-uri/-/resolve-uri-3.1.2.tgz",
            "integrity": "sha512-bRISgCIjP20/tbWSPWMEi54QVPRZExkuD9lJL+UIxUKtwVJA8wW1Trb1jMs1RFXo1CBTNZ/5hpC9QvmKWdopKw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6.0.0"
            }
        },
        "node_modules/@jridgewell/sourcemap-codec": {
            "version": "1.6.0",
            "resolved": "https://registry.npmjs.org/@jridgewell/sourcemap-codec/-/sourcemap-codec-1.6.0.tgz",
            "integrity": "sha512-T7jf+5zgsZHwNJ4lvQ7/aezbyk0nNX+zJVWpmHA7VYsEx7a7qr5Rg5IbtJFqkgze5Y2sruq1RUY8Q837Od7iFw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/@jridgewell/trace-mapping": {
            "version": "0.3.31",
            "resolved": "https://registry.npmjs.org/@jridgewell/trace-mapping/-/trace-mapping-0.3.31.tgz",
            "integrity": "sha512-zzNR+SdQSDJzc8joaeP8QQoCQr8NuYx2dIIytl1QeBEZHJ9uW6hebsrYgbz8hJwUQao3TWCMtmfV8Nu1twOLAw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/resolve-uri": "^3.1.0",
                "@jridgewell/sourcemap-codec": "^1.4.14"
            }
        },
        "node_modules/@nodelib/fs.scandir": {
            "version": "2.1.5",
            "resolved": "https://registry.npmjs.org/@nodelib/fs.scandir/-/fs.scandir-2.1.5.tgz",
            "integrity": "sha512-vq24Bq3ym5HEQm2NKCr3yXDwjc7vTsEThRDnkp2DK9p1uqLR+DHurm/NOTo0KG7HYHU7eppKZj3MyqYuMBf62g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@nodelib/fs.stat": "2.0.5",
                "run-parallel": "^1.1.9"
            },
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/@nodelib/fs.stat": {
            "version": "2.0.5",
            "resolved": "https://registry.npmjs.org/@nodelib/fs.stat/-/fs.stat-2.0.5.tgz",
            "integrity": "sha512-RkhPPp2zrqDAQA/2jNhnztcPAlv64XdhIp7a7454A5ovI7Bukxgt7MX7udwAu3zg1DcpPU0rz3VV1SeaqvY4+A==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/@nodelib/fs.walk": {
            "version": "1.2.8",
            "resolved": "https://registry.npmjs.org/@nodelib/fs.walk/-/fs.walk-1.2.8.tgz",
            "integrity": "sha512-oGB+UxlgWcgQkgwo8GcEGwemoTFt3FIO9ababBmaGwXIoBKZ+GTy0pP185beGg7Llih/NSHSV2XAs1lnznocSg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@nodelib/fs.scandir": "2.1.5",
                "fastq": "^1.6.0"
            },
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/@oxc-project/types": {
            "version": "0.151.0",
            "resolved": "https://registry.npmjs.org/@oxc-project/types/-/types-0.151.0.tgz",
            "integrity": "sha512-J1yXrIlNDZVzE3ada310xeAw7nH8yCAyLPuUIsjKatFPmfn5bS1oW+cM+QsGOtVWd5nhSpbwZWx/rue+r5Z+PA==",
            "dev": true,
            "license": "MIT",
            "funding": {
                "url": "https://github.com/sponsors/oxc-project"
            }
        },
        "node_modules/@rolldown/binding-android-arm-eabi": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-android-arm-eabi/-/binding-android-arm-eabi-1.2.11.tgz",
            "integrity": "sha512-A5kXfGKvKWWZE0TtPrfsvT+q4Y5d1QG8gGUzpYjGydM+fARM9MuX90PrXYXe0XbsDVgyxxNzHo6giCj90bsFNw==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-android-arm64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-android-arm64/-/binding-android-arm64-1.2.11.tgz",
            "integrity": "sha512-z6cTycz+iJ4PVkuL4HHW4DfTfoeU/2nqYYuSOrTmH7yHK5Y0LCOnA03V4ZNxavyVaU1oOqUgIg2klN/s+USGOA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-darwin-arm64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-darwin-arm64/-/binding-darwin-arm64-1.2.11.tgz",
            "integrity": "sha512-jShvqNtP6vDC6/A5JOAzbVV+DkgHqhl/ScVCJEbt+TUY6QYz7YnXcrg3sLtFBniro0f/Ld50ZwCWA6f7KYD1nQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-darwin-x64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-darwin-x64/-/binding-darwin-x64-1.2.11.tgz",
            "integrity": "sha512-f2i2xiNWq1Z1l2++q2fuhZRdLAT3aqxD6vRNm1RAxpUoBcdqNB3C0s1Bt+K+PbEx2F5F4gQp6hqKkphCY/xF9w==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-freebsd-x64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-freebsd-x64/-/binding-freebsd-x64-1.2.11.tgz",
            "integrity": "sha512-4Ir5FSOKIAMr4r0kExpt1s3bMgzJU3rA45AYOHtQpls0oNeqcYBKrWMlckrYH4KCfGLfkfn1tN1dmZPMVsdXow==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-arm-gnueabihf": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-arm-gnueabihf/-/binding-linux-arm-gnueabihf-1.2.11.tgz",
            "integrity": "sha512-/gnRDM+39BROzAN/k1OZjDPnDMcZxB/0EUxKjONO5yVkNEvlsoMDrxGNKgZi/ttFriS2gwlDNzB65pvNbFOXIQ==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-arm64-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-arm64-gnu/-/binding-linux-arm64-gnu-1.2.11.tgz",
            "integrity": "sha512-PFaK8HwvAHbaKbBcDNQihjMKYvFnA5hiENx/l5tphTDz1E0WFp32l0A7aq7lyUwGsRw/xSrNIy/gIK4thrSCrw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-arm64-musl": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-arm64-musl/-/binding-linux-arm64-musl-1.2.11.tgz",
            "integrity": "sha512-AskzJUIKRLPxkruR1wLKewGbOw+EYfU/9lOrBFj4AFrEA8hPpKFnODWNu2WLaNs0QNkEb9QIJufmVZZIL/bJlg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-ppc64-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-ppc64-gnu/-/binding-linux-ppc64-gnu-1.2.11.tgz",
            "integrity": "sha512-qlUGAheh2yh8afH7QBgx0PrRHN85hKnNd78x8MeMhXivuevgd8vgf6/CstOzmNKY/lLTHvNTrPy98cLnAugzJw==",
            "cpu": [
                "ppc64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-s390x-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-s390x-gnu/-/binding-linux-s390x-gnu-1.2.11.tgz",
            "integrity": "sha512-secpEad+0vCbSfn8upFySkDskv+bGPk3THSDS9Y89yc4rb4kzqHp8Dmyd9BkQW4SnhNXBZCl/6CrO//hZahNJQ==",
            "cpu": [
                "s390x"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-x64-gnu": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-x64-gnu/-/binding-linux-x64-gnu-1.2.11.tgz",
            "integrity": "sha512-mOVBT3dPpkWm8XBWPmU4bf+U6dYDLeMo/9ojUmis4N0L5uu10qra5vOyngZ7/PSdoE4G9KvRt4bloRxNjLas7A==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-linux-x64-musl": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-linux-x64-musl/-/binding-linux-x64-musl-1.2.11.tgz",
            "integrity": "sha512-Is78i9A8Ui4SqcxUwFJ9uMmjDn58IbVTjFWYdQestFEgeuEmHMLGNriXnVJKkwG2YiZjw8cP0zCTyDMdDGtOOg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-openharmony-arm64": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-openharmony-arm64/-/binding-openharmony-arm64-1.2.11.tgz",
            "integrity": "sha512-dUCXneZ87INUMyQ0D+C0HrEBNUPNXHaPmU5GTjyKTJEiussw9Kaj5Ln8UztPe4epV/ffvgNBEadksdYhmW6xJA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "openharmony"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-win32-arm64-msvc": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-win32-arm64-msvc/-/binding-win32-arm64-msvc-1.2.11.tgz",
            "integrity": "sha512-jByxb6qfd+bH1xUd0qnfFnb17i9sWBPY2tOavJ0l3tdr3OTu+Kvtm8cd/JV5nFt657b1VqGltxg9olOEfofXWw==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/binding-win32-x64-msvc": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/@rolldown/binding-win32-x64-msvc/-/binding-win32-x64-msvc-1.2.11.tgz",
            "integrity": "sha512-/PzKqzAJ03i19oy2ItPvyvaVjOjBCNnfaJs8yvUdGBKmiESgnrJSQ2awd81QzFbbnAmu7YO9ZnJrDCb9VSJPRA==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            }
        },
        "node_modules/@rolldown/pluginutils": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/@rolldown/pluginutils/-/pluginutils-1.0.1.tgz",
            "integrity": "sha512-2j9bGt5Jh8hj+vPtgzPtl72j0yRxHAyumoo6TNfAjsLB04UtpSvPbPcDcBMxz7n+9CYB0c1GxQFxYRg2jimqGw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/agent-base": {
            "version": "6.0.2",
            "resolved": "https://registry.npmjs.org/agent-base/-/agent-base-6.0.2.tgz",
            "integrity": "sha512-RZNwNclF7+MS/8bDg70amg32dyeZGZxiDuQmZxKLAlQjr3jGyLx+4Kkk58UO7D2QdgFIQCovuSuZESne6RG6XQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "debug": "4"
            },
            "engines": {
                "node": ">= 6.0.0"
            }
        },
        "node_modules/any-promise": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/any-promise/-/any-promise-1.3.0.tgz",
            "integrity": "sha512-7UvmKalWRt1wgjL1RrGxoSJW/0QZFIegpeGvZG9kjp8vrRu55XTHbwnqq2GpXm9uLbcuhxm3IqX9OB4MZR1b2A==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/anymatch": {
            "version": "3.1.3",
            "resolved": "https://registry.npmjs.org/anymatch/-/anymatch-3.1.3.tgz",
            "integrity": "sha512-KMReFUr0B4t+D+OBkjR3KYqvocp2XaSzO55UcB6mgQMd3KbcE+mWTyvVV7D/zsdEbNnV6acZUutkiHQXvTr1Rw==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "normalize-path": "^3.0.0",
                "picomatch": "^2.0.4"
            },
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/arg": {
            "version": "5.0.2",
            "resolved": "https://registry.npmjs.org/arg/-/arg-5.0.2.tgz",
            "integrity": "sha512-PYjyFOLKQ9y57JvQ6QLo8dAgNqswh8M1RMJYdQduT6xbWSgK36P/Z/v+p888pM69jMMfS8Xd8F6I1kQ/I9HUGg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/asynckit": {
            "version": "0.4.0",
            "resolved": "https://registry.npmjs.org/asynckit/-/asynckit-0.4.0.tgz",
            "integrity": "sha512-Oei9OH4tRh0YqU3GxhX79dM/mwVgvbZJaSNaRk+bshkj0S5cfHcgYakreBjrHwatXKbz+IoIdYLxrKim2MjW0Q==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/autoprefixer": {
            "version": "10.6.1",
            "resolved": "https://registry.npmjs.org/autoprefixer/-/autoprefixer-10.6.1.tgz",
            "integrity": "sha512-cL1Qz6ADZhcEbny/8HPfe99J6HhNoYtpX2LFLIbhgGE7Q1hlQVkYFdetDN7Id3KiQxhDrHwzlHr/YQCnZ8+xSA==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/autoprefixer"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "browserslist": "^4.28.9",
                "caniuse-lite": "^1.0.30001810",
                "fraction.js": "^5.3.4",
                "picocolors": "^1.1.1",
                "postcss-value-parser": "^4.2.0"
            },
            "bin": {
                "autoprefixer": "bin/autoprefixer"
            },
            "engines": {
                "node": "^10 || ^12 || >=14"
            },
            "peerDependencies": {
                "postcss": "^8.1.0"
            }
        },
        "node_modules/axios": {
            "version": "1.20.0",
            "resolved": "https://registry.npmjs.org/axios/-/axios-1.20.0.tgz",
            "integrity": "sha512-r8aOh8j9cGKpgQAqpzrUHnSIc6a59Y3Xf/cv8sy1DrHCkZHzQGEuoq1tARk6qSyDdtQGSDgpb9kFlruzPvrgwg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "follow-redirects": "^1.16.0",
                "form-data": "^4.0.6",
                "https-proxy-agent": "^5.0.1",
                "proxy-from-env": "^2.1.0"
            }
        },
        "node_modules/baseline-browser-mapping": {
            "version": "2.11.25",
            "resolved": "https://registry.npmjs.org/baseline-browser-mapping/-/baseline-browser-mapping-2.11.25.tgz",
            "integrity": "sha512-gMmEShwwq7FJqMwvfRwvCl00v4kN+KOfJqXn+f4nrufak5gNHJOksd/60Dvjuz7sI8Y5WiSFBa8FEYr+zoyqCw==",
            "dev": true,
            "license": "Apache-2.0",
            "bin": {
                "baseline-browser-mapping": "dist/cli.cjs"
            },
            "engines": {
                "node": ">=6.0.0"
            }
        },
        "node_modules/binary-extensions": {
            "version": "2.3.0",
            "resolved": "https://registry.npmjs.org/binary-extensions/-/binary-extensions-2.3.0.tgz",
            "integrity": "sha512-Ceh+7ox5qe7LJuLHoY0feh3pHuUDHAcRUeyL2VYghZwfpkNIy/+8Ocg0a3UuSoYzavmylwuLWQOf3hl0jjMMIw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8"
            },
            "funding": {
                "url": "https://github.com/sponsors/sindresorhus"
            }
        },
        "node_modules/braces": {
            "version": "3.0.3",
            "resolved": "https://registry.npmjs.org/braces/-/braces-3.0.3.tgz",
            "integrity": "sha512-yQbXgO/OSZVD2IsiLlro+7Hf6Q18EJrKSEsdoMzKePKXct3gvD8oLcOQdIzGupr5Fj+EDe8gO/lxc1BzfMpxvA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fill-range": "^7.1.1"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/browserslist": {
            "version": "4.29.0",
            "resolved": "https://registry.npmjs.org/browserslist/-/browserslist-4.29.0.tgz",
            "integrity": "sha512-3GSvyjvDI4Dur1Meg2BekJquu5uF+9R9a1+5M1Mde192eZoXbeXjzgOsgqPS2V8D5wrrip0gR5Hf/GhWQ9ZzaA==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/browserslist"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/browserslist"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "baseline-browser-mapping": "^2.11.23",
                "caniuse-lite": "^1.0.30001810",
                "electron-to-chromium": "^1.5.427",
                "node-releases": "^2.0.55",
                "update-browserslist-db": "^1.3.3"
            },
            "bin": {
                "browserslist": "cli.js"
            },
            "engines": {
                "node": "^6 || ^7 || ^8 || ^9 || ^10 || ^11 || ^12 || >=13.7"
            }
        },
        "node_modules/call-bind-apply-helpers": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/call-bind-apply-helpers/-/call-bind-apply-helpers-1.0.2.tgz",
            "integrity": "sha512-Sp1ablJ0ivDkSzjcaJdxEunN5/XvksFJ2sMBFfq6x0ryhQV/2b/KwFe21cMpmHtPOSij8K99/wSfoEuTObmuMQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "function-bind": "^1.1.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/camelcase-css": {
            "version": "2.0.1",
            "resolved": "https://registry.npmjs.org/camelcase-css/-/camelcase-css-2.0.1.tgz",
            "integrity": "sha512-QOSvevhslijgYwRx6Rv7zKdMF8lbRmx+uQGx2+vDc+KI/eBnsy9kit5aj23AgGu3pa4t9AgwbnXWqS+iOY+2aA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/caniuse-lite": {
            "version": "1.0.30001810",
            "resolved": "https://registry.npmjs.org/caniuse-lite/-/caniuse-lite-1.0.30001810.tgz",
            "integrity": "sha512-TITQPUkaz+aVk5GL6NhOdwk1aEaNTSDPsGFWrTuhKGtjTF70jL/Oht2W4c6rXUe5fu7Ie19VIahAXHIIiWWNeg==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/browserslist"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/caniuse-lite"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "CC-BY-4.0"
        },
        "node_modules/chokidar": {
            "version": "3.6.0",
            "resolved": "https://registry.npmjs.org/chokidar/-/chokidar-3.6.0.tgz",
            "integrity": "sha512-7VT13fmjotKpGipCW9JEQAusEPE+Ei8nl6/g4FBAmIm0GOOLMua9NDDo/DWp0ZAxCr3cPq5ZpBqmPAQgDda2Pw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "anymatch": "~3.1.2",
                "braces": "~3.0.2",
                "glob-parent": "~5.1.2",
                "is-binary-path": "~2.1.0",
                "is-glob": "~4.0.1",
                "normalize-path": "~3.0.0",
                "readdirp": "~3.6.0"
            },
            "engines": {
                "node": ">= 8.10.0"
            },
            "funding": {
                "url": "https://paulmillr.com/funding/"
            },
            "optionalDependencies": {
                "fsevents": "~2.3.2"
            }
        },
        "node_modules/chokidar/node_modules/glob-parent": {
            "version": "5.1.2",
            "resolved": "https://registry.npmjs.org/glob-parent/-/glob-parent-5.1.2.tgz",
            "integrity": "sha512-AOIgSQCepiJYwP3ARnGx+5VnTu2HBYdzbGP45eLw1vr3zB3vZLeyed1sC9hnbcOc9/SrMyM5RPQrkGz4aS9Zow==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "is-glob": "^4.0.1"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/combined-stream": {
            "version": "1.0.8",
            "resolved": "https://registry.npmjs.org/combined-stream/-/combined-stream-1.0.8.tgz",
            "integrity": "sha512-FQN4MRfuJeHf7cBbBMJFXhKSDq+2kAArBlmRBvcvFE5BB1HZKXtSFASDhdlz9zOYwxh8lDdnvmMOe/+5cdoEdg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "delayed-stream": "~1.0.0"
            },
            "engines": {
                "node": ">= 0.8"
            }
        },
        "node_modules/commander": {
            "version": "4.1.1",
            "resolved": "https://registry.npmjs.org/commander/-/commander-4.1.1.tgz",
            "integrity": "sha512-NOKm8xhkzAjzFx8B2v5OAHT+u5pRQc2UCa2Vq9jYL/31o2wi9mxBA7LIFs3sV5VSC49z6pEhfbMULvShKj26WA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/css-tree": {
            "version": "3.2.1",
            "resolved": "https://registry.npmjs.org/css-tree/-/css-tree-3.2.1.tgz",
            "integrity": "sha512-X7sjQzceUhu1u7Y/ylrRZFU2FS6LRiFVp6rKLPg23y3x3c3DOKAwuXGDp+PAGjh6CSnCjYeAul8pcT8bAl+lSA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "mdn-data": "2.27.1",
                "source-map-js": "^1.2.1"
            },
            "engines": {
                "node": "^10 || ^12.20.0 || ^14.13.0 || >=15.0.0"
            }
        },
        "node_modules/cssesc": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/cssesc/-/cssesc-3.0.0.tgz",
            "integrity": "sha512-/Tb/JcjK111nNScGob5MNtsntNM1aCNUDipB/TkwZFhyDrrE47SOx/18wF2bbjgc3ZzCSKW1T5nt5EbFoAz/Vg==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "cssesc": "bin/cssesc"
            },
            "engines": {
                "node": ">=4"
            }
        },
        "node_modules/debug": {
            "version": "4.4.3",
            "resolved": "https://registry.npmjs.org/debug/-/debug-4.4.3.tgz",
            "integrity": "sha512-RGwwWnwQvkVfavKVt22FGLw+xYSdzARwm0ru6DhTVA3umU5hZc28V3kO4stgYryrTlLpuvgI9GiijltAjNbcqA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "ms": "^2.1.3"
            },
            "engines": {
                "node": ">=6.0"
            },
            "peerDependenciesMeta": {
                "supports-color": {
                    "optional": true
                }
            }
        },
        "node_modules/delayed-stream": {
            "version": "1.0.0",
            "resolved": "https://registry.npmjs.org/delayed-stream/-/delayed-stream-1.0.0.tgz",
            "integrity": "sha512-ZySD7Nf91aLB0RxL4KGrKHBXl7Eds1DAmEdcoVawXnLD7SDhpNgtuII2aAkg7a7QS41jxPSZ17p4VdGnMHk3MQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.4.0"
            }
        },
        "node_modules/detect-libc": {
            "version": "2.1.2",
            "resolved": "https://registry.npmjs.org/detect-libc/-/detect-libc-2.1.2.tgz",
            "integrity": "sha512-Btj2BOOO83o3WyH59e8MgXsxEQVcarkUOpEYrubB0urwnN10yQ364rsiByU11nZlqWYZm05i/of7io4mzihBtQ==",
            "dev": true,
            "license": "Apache-2.0",
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/didyoumean": {
            "version": "1.2.2",
            "resolved": "https://registry.npmjs.org/didyoumean/-/didyoumean-1.2.2.tgz",
            "integrity": "sha512-gxtyfqMg7GKyhQmb056K7M3xszy/myH8w+B4RT+QXBQsvAOdc3XymqDDPHx1BgPgsdAA5SIifona89YtRATDzw==",
            "dev": true,
            "license": "Apache-2.0"
        },
        "node_modules/dlv": {
            "version": "1.1.3",
            "resolved": "https://registry.npmjs.org/dlv/-/dlv-1.1.3.tgz",
            "integrity": "sha512-+HlytyjlPKnIG8XuRG8WvmBP8xs8P71y+SKKS6ZXWoEgLuePxtDoUEiH7WkdePWrQ5JBpE6aoVqfZfJUQkjXwA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/dunder-proto": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/dunder-proto/-/dunder-proto-1.0.1.tgz",
            "integrity": "sha512-KIN/nDJBQRcXw0MLVhZE9iQHmG68qAVIBg9CqmUYjmQIhgij9U5MFvrqkUL5FbtyyzZuOeOt0zdeRe4UY7ct+A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "call-bind-apply-helpers": "^1.0.1",
                "es-errors": "^1.3.0",
                "gopd": "^1.2.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/electron-to-chromium": {
            "version": "1.5.438",
            "resolved": "https://registry.npmjs.org/electron-to-chromium/-/electron-to-chromium-1.5.438.tgz",
            "integrity": "sha512-AN9xMU1hJiT65LkCUPL1DZm5TCbOPb2Qsm5pYwFLEXp6/qj9SdT4yMiqs7Qqstwvkn1zF7l36SRGt+s6XcJ0FA==",
            "dev": true,
            "license": "ISC"
        },
        "node_modules/es-define-property": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/es-define-property/-/es-define-property-1.0.1.tgz",
            "integrity": "sha512-e3nRfgfUZ4rNGL232gUgX06QNyyez04KdjFrF+LTRoOXmrOgFKDg4BCdsjW8EnT69eqdYGmRpJwiPVYNrCaW3g==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-errors": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/es-errors/-/es-errors-1.3.0.tgz",
            "integrity": "sha512-Zf5H2Kxt2xjTvbJvP2ZWLEICxA6j+hAmMzIlypy4xcBg1vKVnx89Wy0GbS+kf5cwCVFFzdCFh2XSCFNULS6csw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-object-atoms": {
            "version": "1.1.2",
            "resolved": "https://registry.npmjs.org/es-object-atoms/-/es-object-atoms-1.1.2.tgz",
            "integrity": "sha512-HWcBoN6NileqtSydK2FqHbS/LoDd2pqrnQHLyJzBj4kOp/ky2MWMN694xOfkK8/SnUsW2DH7EfyVlydKCsm1Zw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/es-set-tostringtag": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/es-set-tostringtag/-/es-set-tostringtag-2.1.0.tgz",
            "integrity": "sha512-j6vWzfrGVfyXxge+O0x5sh6cvxAog0a/4Rdd2K36zCMV5eJ+/+tOAngRO8cODMNWbVRdVlmGZQL2YS3yR8bIUA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "get-intrinsic": "^1.2.6",
                "has-tostringtag": "^1.0.2",
                "hasown": "^2.0.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/escalade": {
            "version": "3.2.0",
            "resolved": "https://registry.npmjs.org/escalade/-/escalade-3.2.0.tgz",
            "integrity": "sha512-WUj2qlxaQtO4g6Pq5c29GTcWGDyd8itL8zTlipgECz3JesAiiOKotd8JU6otB3PACgG6xkJUyVhboMS+bje/jA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=6"
            }
        },
        "node_modules/fast-glob": {
            "version": "3.3.3",
            "resolved": "https://registry.npmjs.org/fast-glob/-/fast-glob-3.3.3.tgz",
            "integrity": "sha512-7MptL8U0cqcFdzIzwOTHoilX9x5BrNqye7Z/LuC7kCMRio1EMSyqRK3BEAUD7sXRq4iT4AzTVuZdhgQ2TCvYLg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@nodelib/fs.stat": "^2.0.2",
                "@nodelib/fs.walk": "^1.2.3",
                "glob-parent": "^5.1.2",
                "merge2": "^1.3.0",
                "micromatch": "^4.0.8"
            },
            "engines": {
                "node": ">=8.6.0"
            }
        },
        "node_modules/fast-glob/node_modules/glob-parent": {
            "version": "5.1.2",
            "resolved": "https://registry.npmjs.org/glob-parent/-/glob-parent-5.1.2.tgz",
            "integrity": "sha512-AOIgSQCepiJYwP3ARnGx+5VnTu2HBYdzbGP45eLw1vr3zB3vZLeyed1sC9hnbcOc9/SrMyM5RPQrkGz4aS9Zow==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "is-glob": "^4.0.1"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/fastq": {
            "version": "1.20.3",
            "resolved": "https://registry.npmjs.org/fastq/-/fastq-1.20.3.tgz",
            "integrity": "sha512-XKv5nnLs6nLF71NgiKJLIZFLkPyIEuOselLG7ujZnGrRfQK8HpvY+WqKhAJUAdLomwVHErVS4LfxFlPq0/FTAw==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "reusify": "^1.0.4"
            }
        },
        "node_modules/fill-range": {
            "version": "7.1.1",
            "resolved": "https://registry.npmjs.org/fill-range/-/fill-range-7.1.1.tgz",
            "integrity": "sha512-YsGpe3WHLK8ZYi4tWDg2Jy3ebRz2rXowDxnld4bkQB00cc/1Zw9AWnC0i9ztDJitivtQvaI9KaLyKrc+hBW0yg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "to-regex-range": "^5.0.1"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/follow-redirects": {
            "version": "1.16.0",
            "resolved": "https://registry.npmjs.org/follow-redirects/-/follow-redirects-1.16.0.tgz",
            "integrity": "sha512-y5rN/uOsadFT/JfYwhxRS5R7Qce+g3zG97+JrtFZlC9klX/W5hD7iiLzScI4nZqUS7DNUdhPgw4xI8W2LuXlUw==",
            "dev": true,
            "funding": [
                {
                    "type": "individual",
                    "url": "https://github.com/sponsors/RubenVerborgh"
                }
            ],
            "license": "MIT",
            "engines": {
                "node": ">=4.0"
            },
            "peerDependenciesMeta": {
                "debug": {
                    "optional": true
                }
            }
        },
        "node_modules/fontaine": {
            "version": "0.8.2",
            "resolved": "https://registry.npmjs.org/fontaine/-/fontaine-0.8.2.tgz",
            "integrity": "sha512-l/aOgAnNqSocAxVmVLuN3P+RIFCWHe6ej3oFugRlW3olsnnQwaxHYdfyyF8aFyhhwUFy4k+Ah3ofEp54RIDMWQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@capsizecss/unpack": "^4.0.0",
                "css-tree": "^3.1.0",
                "magic-regexp": "^0.11.0",
                "magic-string": "^1.0.0",
                "pathe": "^2.0.3",
                "ufo": "^1.6.1",
                "unplugin": "^3.0.0"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "postcss": "^8.4.31"
            },
            "peerDependenciesMeta": {
                "postcss": {
                    "optional": true
                }
            }
        },
        "node_modules/fontkitten": {
            "version": "1.0.3",
            "resolved": "https://registry.npmjs.org/fontkitten/-/fontkitten-1.0.3.tgz",
            "integrity": "sha512-Wp1zXWPVUPBmfoa3Cqc9ctaKuzKAV6uLstRqlR56kSjplf5uAce+qeyYym7F+PHbGTk+tCEdkCW6RD7DX/gBZw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "tiny-inflate": "^1.0.3"
            },
            "engines": {
                "node": ">=20"
            }
        },
        "node_modules/form-data": {
            "version": "4.0.6",
            "resolved": "https://registry.npmjs.org/form-data/-/form-data-4.0.6.tgz",
            "integrity": "sha512-vKatAh4SlVfgbv+YtmhiRjhEMJsYpsG1Y2rMQtR+SVSbytsSD1YGzDIcrAJmdFec88u/+VoGmxnl+80gL1tRCQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "asynckit": "^0.4.0",
                "combined-stream": "^1.0.8",
                "es-set-tostringtag": "^2.1.0",
                "hasown": "^2.0.4",
                "mime-types": "^2.1.35"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/fraction.js": {
            "version": "5.3.4",
            "resolved": "https://registry.npmjs.org/fraction.js/-/fraction.js-5.3.4.tgz",
            "integrity": "sha512-1X1NTtiJphryn/uLQz3whtY6jK3fTqoE3ohKs0tT+Ujr1W59oopxmoEh7Lu5p6vBaPbgoM0bzveAW4Qi5RyWDQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": "*"
            },
            "funding": {
                "type": "github",
                "url": "https://github.com/sponsors/rawify"
            }
        },
        "node_modules/fsevents": {
            "version": "2.3.3",
            "resolved": "https://registry.npmjs.org/fsevents/-/fsevents-2.3.3.tgz",
            "integrity": "sha512-5xoDfX+fL7faATnagmWPpbFtwh/R77WmMMqqHGS65C3vvB0YHrgF+B1YmZ3441tMj5n63k0212XNoJwzlhffQw==",
            "dev": true,
            "hasInstallScript": true,
            "license": "MIT",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": "^8.16.0 || ^10.6.0 || >=11.0.0"
            }
        },
        "node_modules/function-bind": {
            "version": "1.1.2",
            "resolved": "https://registry.npmjs.org/function-bind/-/function-bind-1.1.2.tgz",
            "integrity": "sha512-7XHNxH7qX9xG5mIwxkhumTox/MIRNcOgDrxWsMt2pAr23WHp6MrRlN7FBSFpCpr+oVO0F744iUgR82nJMfG2SA==",
            "dev": true,
            "license": "MIT",
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/get-intrinsic": {
            "version": "1.3.0",
            "resolved": "https://registry.npmjs.org/get-intrinsic/-/get-intrinsic-1.3.0.tgz",
            "integrity": "sha512-9fSjSaos/fRIVIp+xSJlE6lfwhES7LNtKaCBIamHsjr2na1BiABJPo0mOjjz8GJDURarmCPGqaiVg5mfjb98CQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "call-bind-apply-helpers": "^1.0.2",
                "es-define-property": "^1.0.1",
                "es-errors": "^1.3.0",
                "es-object-atoms": "^1.1.1",
                "function-bind": "^1.1.2",
                "get-proto": "^1.0.1",
                "gopd": "^1.2.0",
                "has-symbols": "^1.1.0",
                "hasown": "^2.0.2",
                "math-intrinsics": "^1.1.0"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/get-proto": {
            "version": "1.0.1",
            "resolved": "https://registry.npmjs.org/get-proto/-/get-proto-1.0.1.tgz",
            "integrity": "sha512-sTSfBjoXBp89JvIKIefqw7U2CCebsc74kiY6awiGogKtoSGbgjYE/G/+l9sF3MWFPNc9IcoOC4ODfKHfxFmp0g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "dunder-proto": "^1.0.1",
                "es-object-atoms": "^1.0.0"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/glob-parent": {
            "version": "6.0.2",
            "resolved": "https://registry.npmjs.org/glob-parent/-/glob-parent-6.0.2.tgz",
            "integrity": "sha512-XxwI8EOhVQgWp6iDL+3b0r86f4d6AX6zSU55HfB4ydCEuXLXc5FcYeOu+nnGftS4TEju/11rt4KJPTMgbfmv4A==",
            "dev": true,
            "license": "ISC",
            "dependencies": {
                "is-glob": "^4.0.3"
            },
            "engines": {
                "node": ">=10.13.0"
            }
        },
        "node_modules/gopd": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/gopd/-/gopd-1.2.0.tgz",
            "integrity": "sha512-ZUKRh6/kUFoAiTAtTYPZJ3hw9wNxx+BIBOijnlG9PnrJsCcSjs1wyyD6vJpaYtgnzDrKYRSqf3OO6Rfa93xsRg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/has-symbols": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/has-symbols/-/has-symbols-1.1.0.tgz",
            "integrity": "sha512-1cDNdwJ2Jaohmb3sg4OmKaMBwuC48sYni5HUw2DvsC8LjGTLK9h+eb1X6RyuOHe4hT0ULCW68iomhjUoKUqlPQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/has-tostringtag": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/has-tostringtag/-/has-tostringtag-1.0.2.tgz",
            "integrity": "sha512-NqADB8VjPFLM2V0VvHUewwwsw0ZWBaIdgo+ieHtK3hasLz4qeCRjYcqfB6AQrBggRKppKF8L52/VqdVsO47Dlw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "has-symbols": "^1.0.3"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/hasown": {
            "version": "2.0.4",
            "resolved": "https://registry.npmjs.org/hasown/-/hasown-2.0.4.tgz",
            "integrity": "sha512-T2UbfbBEF32wiepXIsMlTW9+dDYC6wMh/t/vYA4tuOMKqWz/n3vr1NFSxQiyP+zk2mXsoMA/i/7qV6LKut1t1A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "function-bind": "^1.1.2"
            },
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/https-proxy-agent": {
            "version": "5.0.1",
            "resolved": "https://registry.npmjs.org/https-proxy-agent/-/https-proxy-agent-5.0.1.tgz",
            "integrity": "sha512-dFcAjpTQFgoLMzC2VwU+C/CbS7uRL0lWmxDITmqm7C+7F0Odmj6s9l6alZc6AELXhrnggM2CeWSXHGOdX2YtwA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "agent-base": "6",
                "debug": "4"
            },
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/is-binary-path": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/is-binary-path/-/is-binary-path-2.1.0.tgz",
            "integrity": "sha512-ZMERYes6pDydyuGidse7OsHxtbI7WVeUEozgR/g7rd0xUimYNlvZRE/K2MgZTjWy725IfelLeVcEM97mmtRGXw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "binary-extensions": "^2.0.0"
            },
            "engines": {
                "node": ">=8"
            }
        },
        "node_modules/is-core-module": {
            "version": "2.17.0",
            "resolved": "https://registry.npmjs.org/is-core-module/-/is-core-module-2.17.0.tgz",
            "integrity": "sha512-J/vG0zBCbIKOQFfufSwyXdMrsohyJIUNkrnmo6WZGzoM7tr/lsbfW5b2BvisL6zsyMzK9UxV9L6c7AoFbyXHOA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "hasown": "^2.0.4"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/is-extglob": {
            "version": "2.1.1",
            "resolved": "https://registry.npmjs.org/is-extglob/-/is-extglob-2.1.1.tgz",
            "integrity": "sha512-SbKbANkN603Vi4jEZv49LeVJMn4yGwsbzZworEoyEiutsN3nJYdbO36zfhGJ6QEDpOZIFkDtnq5JRxmvl3jsoQ==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/is-glob": {
            "version": "4.0.3",
            "resolved": "https://registry.npmjs.org/is-glob/-/is-glob-4.0.3.tgz",
            "integrity": "sha512-xelSayHH36ZgE7ZWhli7pW34hNbNl8Ojv5KVmkJD4hBdD3th8Tfk9vYasLM+mXWOZhFkgZfxhLSnrwRr4elSSg==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "is-extglob": "^2.1.1"
            },
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/is-number": {
            "version": "7.0.0",
            "resolved": "https://registry.npmjs.org/is-number/-/is-number-7.0.0.tgz",
            "integrity": "sha512-41Cifkg6e8TylSpdtTpeLVMqvSBEVzTttHvERD741+pnZ8ANv0004MRL43QKPDlK9cGvNp6NZWZUBlbGXYxxng==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.12.0"
            }
        },
        "node_modules/jiti": {
            "version": "1.21.7",
            "resolved": "https://registry.npmjs.org/jiti/-/jiti-1.21.7.tgz",
            "integrity": "sha512-/imKNG4EbWNrVjoNC/1H5/9GFy+tqjGBHCaSsN+P2RnPqjsLmv6UD3Ej+Kj8nBWaRAwyk7kK5ZUc+OEatnTR3A==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "jiti": "bin/jiti.js"
            }
        },
        "node_modules/js-tokens": {
            "version": "4.0.0",
            "resolved": "https://registry.npmjs.org/js-tokens/-/js-tokens-4.0.0.tgz",
            "integrity": "sha512-RdJUflcE3cUzKiMqQgsCu06FPu9UdIJO0beYbPhHN4k6apgJtifcoCtT9bcxOpYBtpD2kCM6Sbzg4CausW/PKQ==",
            "license": "MIT"
        },
        "node_modules/laravel-vite-plugin": {
            "version": "3.2.0",
            "resolved": "https://registry.npmjs.org/laravel-vite-plugin/-/laravel-vite-plugin-3.2.0.tgz",
            "integrity": "sha512-xSxY9Gzeb/eancd8WeK09piAFP+a6i5QIBqNCKNv9L0Eq6wziwzSem7F1GvMSrtjMh5F/QVKFxn8t9naGOA66A==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picocolors": "^1.0.0",
                "tinyglobby": "^0.2.12",
                "vite-plugin-full-reload": "^1.1.0"
            },
            "bin": {
                "clean-orphaned-assets": "bin/clean.js"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "fontaine": "^0.8.0",
                "vite": "^8.0.0"
            },
            "peerDependenciesMeta": {
                "fontaine": {
                    "optional": true
                }
            }
        },
        "node_modules/lightningcss": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss/-/lightningcss-1.33.0.tgz",
            "integrity": "sha512-WkUDrojuJs0xkgGf2udWxa3yGBRxPtxUkB79i6aCZLRgc7PM8fZe9TosfPDcvEpQZbuFASnHYmRLBLUbmLOIIA==",
            "dev": true,
            "license": "MPL-2.0",
            "dependencies": {
                "detect-libc": "^2.0.3"
            },
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            },
            "optionalDependencies": {
                "lightningcss-android-arm64": "1.33.0",
                "lightningcss-darwin-arm64": "1.33.0",
                "lightningcss-darwin-x64": "1.33.0",
                "lightningcss-freebsd-x64": "1.33.0",
                "lightningcss-linux-arm-gnueabihf": "1.33.0",
                "lightningcss-linux-arm64-gnu": "1.33.0",
                "lightningcss-linux-arm64-musl": "1.33.0",
                "lightningcss-linux-x64-gnu": "1.33.0",
                "lightningcss-linux-x64-musl": "1.33.0",
                "lightningcss-win32-arm64-msvc": "1.33.0",
                "lightningcss-win32-x64-msvc": "1.33.0"
            }
        },
        "node_modules/lightningcss-android-arm64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-android-arm64/-/lightningcss-android-arm64-1.33.0.tgz",
            "integrity": "sha512-gEpRTalKdosp4Bb8qWtc2iOgE5SeIHlpS1up9bFq2wAyYhl1UdTObYiHe98zEM9SQvSoqQZ1IQD0JNpg3Ml5pg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "android"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-darwin-arm64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-darwin-arm64/-/lightningcss-darwin-arm64-1.33.0.tgz",
            "integrity": "sha512-Sciaz8eenNTKn9b3t7+xr0ipTp9YxKQY4npwQ3mrRuL0BAVHBLyZxofhaKBAVtzmtRZ/zTyo0/to4B1uWG/Djg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-darwin-x64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-darwin-x64/-/lightningcss-darwin-x64-1.33.0.tgz",
            "integrity": "sha512-Z5UPAxzrjlWNNyGy6i65cJzzvgJ5D3T6wMvs+gWpY9d7qRhANrxqAp6LhxIgZhWEw18RfJTGcRxjuLIBr+m8XQ==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "darwin"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-freebsd-x64": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-freebsd-x64/-/lightningcss-freebsd-x64-1.33.0.tgz",
            "integrity": "sha512-QQM/Ti/hQajJwCY+RiWuCZ9sdtI/XQk7nDK5vC8kkdwixezOlDgvDx7+RT+QjK6FcFT4MpsuoBnHIo/O3StRRg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "freebsd"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm-gnueabihf": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm-gnueabihf/-/lightningcss-linux-arm-gnueabihf-1.33.0.tgz",
            "integrity": "sha512-N7FVBe6iS24MlM6R/4RBTxGhQheZGs7tiQ9U32UtF75NzP5Q7xWPRqLBCKxlRQRk3rY1jCIPLzx7WzOhuUIRLQ==",
            "cpu": [
                "arm"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm64-gnu": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm64-gnu/-/lightningcss-linux-arm64-gnu-1.33.0.tgz",
            "integrity": "sha512-j2v/itmy4HlNxlc6voKXYgBqNi0Ng2LShg4z7GufpEgs05P+2suBVyi9I6YHq5uoVFx9ETin3eCEhLVyXGQnKg==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-arm64-musl": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-arm64-musl/-/lightningcss-linux-arm64-musl-1.33.0.tgz",
            "integrity": "sha512-yiO5ROMuYQgXbC60yjZU5CYSFZGKXL0HFATXt9mHJn1+zW55oCtMI9NfcVhYLMFDL7gV7oBPon/EmMMGg2OvtQ==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-x64-gnu": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-x64-gnu/-/lightningcss-linux-x64-gnu-1.33.0.tgz",
            "integrity": "sha512-ar+Ju7LmcN0Jo4FpL4hpFybwNG9/3A/Br5KW2n2jyODg3MEZXaDYADdemoNS+BDNfMgKvylJLj4S5tyRActuAg==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-linux-x64-musl": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-linux-x64-musl/-/lightningcss-linux-x64-musl-1.33.0.tgz",
            "integrity": "sha512-RYiYbkokw0trfKqqzfF55lginwEPrD3OJDfTuJzFs1MK6iFnDenaz1fqLLtX4ITG3OktJQXOeTaw1awrBAlZPw==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "linux"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-win32-arm64-msvc": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-win32-arm64-msvc/-/lightningcss-win32-arm64-msvc-1.33.0.tgz",
            "integrity": "sha512-1K+MPfLSFVpphzpdbfkhlWk6wBrTObBzS2T6db10PNOZgR9GoVsAWzwNyuhUYYbTp23j+4RrncfujZ4uAzXvwA==",
            "cpu": [
                "arm64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lightningcss-win32-x64-msvc": {
            "version": "1.33.0",
            "resolved": "https://registry.npmjs.org/lightningcss-win32-x64-msvc/-/lightningcss-win32-x64-msvc-1.33.0.tgz",
            "integrity": "sha512-OlEICDx/Xl0FqSp4bry8zFnCvGpig3Gl4gCquvYwHuqJKEC1+n9NgDniFvqHGmMv1ZkqDJrDqKKSykTDX+ehuA==",
            "cpu": [
                "x64"
            ],
            "dev": true,
            "license": "MPL-2.0",
            "optional": true,
            "os": [
                "win32"
            ],
            "engines": {
                "node": ">= 12.0.0"
            },
            "funding": {
                "type": "opencollective",
                "url": "https://opencollective.com/parcel"
            }
        },
        "node_modules/lilconfig": {
            "version": "3.1.3",
            "resolved": "https://registry.npmjs.org/lilconfig/-/lilconfig-3.1.3.tgz",
            "integrity": "sha512-/vlFKAoH5Cgt3Ie+JLhRbwOsCQePABiU3tJ1egGvyQ+33R/vcwM2Zl2QR/LzjsBeItPt3oSVXapn+m4nQDvpzw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=14"
            },
            "funding": {
                "url": "https://github.com/sponsors/antonk52"
            }
        },
        "node_modules/lines-and-columns": {
            "version": "1.2.4",
            "resolved": "https://registry.npmjs.org/lines-and-columns/-/lines-and-columns-1.2.4.tgz",
            "integrity": "sha512-7ylylesZQ/PV29jhEDl3Ufjo6ZX7gCqJr5F7PKrqc93v7fzSymt1BpwEU8nAUXs8qzzvqhbjhK5QZg6Mt/HkBg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/loose-envify": {
            "version": "1.4.0",
            "resolved": "https://registry.npmjs.org/loose-envify/-/loose-envify-1.4.0.tgz",
            "integrity": "sha512-lyuxPGr/Wfhrlem2CL/UcnUc1zcqKAImBDzukY7Y5F/yQiNdko6+fRLevlw1HgMySw7f611UIY408EtxRSoK3Q==",
            "license": "MIT",
            "dependencies": {
                "js-tokens": "^3.0.0 || ^4.0.0"
            },
            "bin": {
                "loose-envify": "cli.js"
            }
        },
        "node_modules/magic-regexp": {
            "version": "0.11.2",
            "resolved": "https://registry.npmjs.org/magic-regexp/-/magic-regexp-0.11.2.tgz",
            "integrity": "sha512-s4i7mq2jJnkB5J1HDX67GmdRoWrgo8tE0cL91bKO+HaquxyR5TiqkjVfLhgS2PXretsN/Gv9kSnRBgQxrLO/Qw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "magic-string": "^1.1.0",
                "regexp-tree": "^0.1.27",
                "type-level-regexp": "~0.1.17",
                "unplugin": "^3.3.0"
            }
        },
        "node_modules/magic-string": {
            "version": "1.4.2",
            "resolved": "https://registry.npmjs.org/magic-string/-/magic-string-1.4.2.tgz",
            "integrity": "sha512-vG+rjFRj1PqdIBozIxAGMjPlOhaVe+GXpbttY/iSK7rGcJRMlwNJO7dcUwmUqkymsFLJiNGI06t4D7Fr7yRC9g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/sourcemap-codec": "^1.6.0"
            }
        },
        "node_modules/math-intrinsics": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/math-intrinsics/-/math-intrinsics-1.1.0.tgz",
            "integrity": "sha512-/IXtbwEk5HTPyEwyKX6hGkYXxM9nbj64B+ilVJnC/R6B0pH5G4V3b0pVbL7DBj4tkhBAppbQUlf6F6Xl9LHu1g==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            }
        },
        "node_modules/mdn-data": {
            "version": "2.27.1",
            "resolved": "https://registry.npmjs.org/mdn-data/-/mdn-data-2.27.1.tgz",
            "integrity": "sha512-9Yubnt3e8A0OKwxYSXyhLymGW4sCufcLG6VdiDdUGVkPhpqLxlvP5vl1983gQjJl3tqbrM731mjaZaP68AgosQ==",
            "dev": true,
            "license": "CC0-1.0"
        },
        "node_modules/merge2": {
            "version": "1.4.1",
            "resolved": "https://registry.npmjs.org/merge2/-/merge2-1.4.1.tgz",
            "integrity": "sha512-8q7VEgMJW4J8tcfVPy8g09NcQwZdbwFEqhe/WZkoIzjn/3TGDwtOCYtXGxA3O8tPzpczCCDgv+P2P5y00ZJOOg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 8"
            }
        },
        "node_modules/micromatch": {
            "version": "4.0.8",
            "resolved": "https://registry.npmjs.org/micromatch/-/micromatch-4.0.8.tgz",
            "integrity": "sha512-PXwfBhYu0hBCPw8Dn0E+WDYb7af3dSLVWKi3HGv84IdF4TyFoC0ysxFd0Goxw7nSv4T/PzEJQxsYsEiFCKo2BA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "braces": "^3.0.3",
                "picomatch": "^2.3.1"
            },
            "engines": {
                "node": ">=8.6"
            }
        },
        "node_modules/mime-db": {
            "version": "1.52.0",
            "resolved": "https://registry.npmjs.org/mime-db/-/mime-db-1.52.0.tgz",
            "integrity": "sha512-sPU4uV7dYlvtWJxwwxHD0PuihVNiE7TyAbQ5SWxDCB9mUYvOgroQOwYQQOKPJ8CIbE+1ETVlOoK1UC2nU3gYvg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.6"
            }
        },
        "node_modules/mime-types": {
            "version": "2.1.35",
            "resolved": "https://registry.npmjs.org/mime-types/-/mime-types-2.1.35.tgz",
            "integrity": "sha512-ZDY+bPm5zTTF+YpCrAU9nK0UgICYPT0QtT1NZWFv4s++TNkcgVaT0g6+4R2uI4MjQjzysHB1zxuWL50hzaeXiw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "mime-db": "1.52.0"
            },
            "engines": {
                "node": ">= 0.6"
            }
        },
        "node_modules/ms": {
            "version": "2.1.3",
            "resolved": "https://registry.npmjs.org/ms/-/ms-2.1.3.tgz",
            "integrity": "sha512-6FlzubTLZG3J2a/NVCAleEhjzq5oxgHyaCU9yYXvcLsvoVaHJq/s5xXI6/XXP6tz7R9xAOtHnSO/tXtF3WRTlA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/mz": {
            "version": "2.7.0",
            "resolved": "https://registry.npmjs.org/mz/-/mz-2.7.0.tgz",
            "integrity": "sha512-z81GNO7nnYMEhrGh9LeymoE4+Yr0Wn5McHIZMK5cfQCl+NDX08sCZgUc9/6MHni9IWuFLm1Z3HTCXu2z9fN62Q==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "any-promise": "^1.0.0",
                "object-assign": "^4.0.1",
                "thenify-all": "^1.0.0"
            }
        },
        "node_modules/nanoid": {
            "version": "3.3.19",
            "resolved": "https://registry.npmjs.org/nanoid/-/nanoid-3.3.19.tgz",
            "integrity": "sha512-Y2tUNy4ouw6tq5oDSKeQYGOyhkUBhNOcGV/02KC+6kd9eDGqdZd++mjMiIDilrBYvjEnCYvVtsuHCuP+okSfug==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "bin": {
                "nanoid": "bin/nanoid.cjs"
            },
            "engines": {
                "node": "^10 || ^12 || ^13.7 || ^14 || >=15.0.1"
            }
        },
        "node_modules/node-releases": {
            "version": "2.0.57",
            "resolved": "https://registry.npmjs.org/node-releases/-/node-releases-2.0.57.tgz",
            "integrity": "sha512-kQK9LGGFiHtrWiNhZtA7Qbw17AQz+dmsEKODRIVTXA9+e5MS/2gZEBhYJt13GrAz5/IOZKddH/0Z3TP/Zgo+yw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=18"
            }
        },
        "node_modules/normalize-path": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/normalize-path/-/normalize-path-3.0.0.tgz",
            "integrity": "sha512-6eZs5Ls3WtCisHWp9S2GUy8dqkpGi4BVSz3GaqiE6ezub0512ESztXUwUB6C6IKbQkY2Pnb/mD4WYojCRwcwLA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/object-assign": {
            "version": "4.1.1",
            "resolved": "https://registry.npmjs.org/object-assign/-/object-assign-4.1.1.tgz",
            "integrity": "sha512-rJgTQnkUnH1sFw8yT6VSU3zD3sWmu6sZhIseY8VX+GRu3P6F7Fu+JNDoXfklElbLJSnc3FUQHVe4cU5hj+BcUg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/object-hash": {
            "version": "3.0.0",
            "resolved": "https://registry.npmjs.org/object-hash/-/object-hash-3.0.0.tgz",
            "integrity": "sha512-RSn9F68PjH9HqtltsSnqYC1XXoWe9Bju5+213R98cNGttag9q9yAOTzdbsqvIa7aNm5WffBZFpWYr2aWrklWAw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/path-parse": {
            "version": "1.0.7",
            "resolved": "https://registry.npmjs.org/path-parse/-/path-parse-1.0.7.tgz",
            "integrity": "sha512-LDJzPVEEEPR+y48z93A0Ed0yXb8pAByGWo/k5YYdYgpY2/2EsOsksJrq7lOHxryrVOn1ejG6oAp8ahvOIQD8sw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/pathe": {
            "version": "2.0.3",
            "resolved": "https://registry.npmjs.org/pathe/-/pathe-2.0.3.tgz",
            "integrity": "sha512-WUjGcAqP1gQacoQe+OBJsFA7Ld4DyXuUIjZ5cc75cLHvJ7dtNsTugphxIADwspS+AraAUePCKrSVtPLFj/F88w==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/picocolors": {
            "version": "1.1.1",
            "resolved": "https://registry.npmjs.org/picocolors/-/picocolors-1.1.1.tgz",
            "integrity": "sha512-xceH2snhtb5M9liqDsmEw56le376mTZkEX/jEb/RxNFyegNul7eNslCXP9FDj/Lcu0X8KEyMceP2ntpaHrDEVA==",
            "dev": true,
            "license": "ISC"
        },
        "node_modules/picomatch": {
            "version": "2.3.2",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-2.3.2.tgz",
            "integrity": "sha512-V7+vQEJ06Z+c5tSye8S+nHUfI51xoXIXjHQ99cQtKUkQqqO1kO/KCJUfZXuB47h/YBlDhah2H3hdUGXn8ie0oA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=8.6"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/pirates": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/pirates/-/pirates-4.0.7.tgz",
            "integrity": "sha512-TfySrs/5nm8fQJDcBDuUng3VOUKsd7S+zqvbOTiGXHfxX4wK31ard+hoNuvkicM/2YFzlpDgABOevKSsB4G/FA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 6"
            }
        },
        "node_modules/postcss": {
            "version": "8.5.28",
            "resolved": "https://registry.npmjs.org/postcss/-/postcss-8.5.28.tgz",
            "integrity": "sha512-RRuzqDtt5Y9h3quz5hWhK+TPnsmVs6WwSU6LkJMeY4HstUEDuYTG8UJSdawMRzmzAtV+KEoG8N3Qg2qLy5vM/A==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/postcss"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "nanoid": "^3.3.18",
                "picocolors": "^1.1.1",
                "source-map-js": "^1.2.1"
            },
            "engines": {
                "node": "^10 || ^12 || >=14"
            }
        },
        "node_modules/postcss-import": {
            "version": "15.1.0",
            "resolved": "https://registry.npmjs.org/postcss-import/-/postcss-import-15.1.0.tgz",
            "integrity": "sha512-hpr+J05B2FVYUAXHeK1YyI267J/dDDhMU6B6civm8hSY1jYJnBXxzKDKDswzJmtLHryrjhnDjqqp/49t8FALew==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "postcss-value-parser": "^4.0.0",
                "read-cache": "^1.0.0",
                "resolve": "^1.1.7"
            },
            "engines": {
                "node": ">=14.0.0"
            },
            "peerDependencies": {
                "postcss": "^8.0.0"
            }
        },
        "node_modules/postcss-js": {
            "version": "4.1.0",
            "resolved": "https://registry.npmjs.org/postcss-js/-/postcss-js-4.1.0.tgz",
            "integrity": "sha512-oIAOTqgIo7q2EOwbhb8UalYePMvYoIeRY2YKntdpFQXNosSu3vLrniGgmH9OKs/qAkfoj5oB3le/7mINW1LCfw==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "camelcase-css": "^2.0.1"
            },
            "engines": {
                "node": "^12 || ^14 || >= 16"
            },
            "peerDependencies": {
                "postcss": "^8.4.21"
            }
        },
        "node_modules/postcss-load-config": {
            "version": "6.0.1",
            "resolved": "https://registry.npmjs.org/postcss-load-config/-/postcss-load-config-6.0.1.tgz",
            "integrity": "sha512-oPtTM4oerL+UXmx+93ytZVN82RrlY/wPUV8IeDxFrzIjXOLF1pN+EmKPLbubvKHT2HC20xXsCAH2Z+CKV6Oz/g==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "lilconfig": "^3.1.1"
            },
            "engines": {
                "node": ">= 18"
            },
            "peerDependencies": {
                "jiti": ">=1.21.0",
                "postcss": ">=8.0.9",
                "tsx": "^4.8.1",
                "yaml": "^2.4.2"
            },
            "peerDependenciesMeta": {
                "jiti": {
                    "optional": true
                },
                "postcss": {
                    "optional": true
                },
                "tsx": {
                    "optional": true
                },
                "yaml": {
                    "optional": true
                }
            }
        },
        "node_modules/postcss-nested": {
            "version": "6.2.0",
            "resolved": "https://registry.npmjs.org/postcss-nested/-/postcss-nested-6.2.0.tgz",
            "integrity": "sha512-HQbt28KulC5AJzG+cZtj9kvKB93CFCdLvog1WFLf1D+xmMvPGlBstkpTEZfK5+AN9hfJocyBFCNiqyS48bpgzQ==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/postcss/"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "postcss-selector-parser": "^6.1.1"
            },
            "engines": {
                "node": ">=12.0"
            },
            "peerDependencies": {
                "postcss": "^8.2.14"
            }
        },
        "node_modules/postcss-selector-parser": {
            "version": "6.1.4",
            "resolved": "https://registry.npmjs.org/postcss-selector-parser/-/postcss-selector-parser-6.1.4.tgz",
            "integrity": "sha512-bIoJLOmjCO1S9XdY/DcnR5hJxvrDir1PbGChrzXG3vw0/FOliy/fA3dmdhQ441kah4gKv+TwckGzex6wNS5cnQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "cssesc": "^3.0.0",
                "util-deprecate": "^1.0.2"
            },
            "engines": {
                "node": ">=4"
            }
        },
        "node_modules/postcss-value-parser": {
            "version": "4.2.0",
            "resolved": "https://registry.npmjs.org/postcss-value-parser/-/postcss-value-parser-4.2.0.tgz",
            "integrity": "sha512-1NNCs6uurfkVbeXG4S8JFT9t19m45ICnif8zWLd5oPSZ50QnwMfK+H3jv408d4jw/7Bttv5axS5IiHoLaVNHeQ==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/proxy-from-env": {
            "version": "2.1.0",
            "resolved": "https://registry.npmjs.org/proxy-from-env/-/proxy-from-env-2.1.0.tgz",
            "integrity": "sha512-cJ+oHTW1VAEa8cJslgmUZrc+sjRKgAKl3Zyse6+PV38hZe/V6Z14TbCuXcan9F9ghlz4QrFr2c92TNF82UkYHA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=10"
            }
        },
        "node_modules/queue-microtask": {
            "version": "1.2.3",
            "resolved": "https://registry.npmjs.org/queue-microtask/-/queue-microtask-1.2.3.tgz",
            "integrity": "sha512-NuaNSa6flKT5JaSYQzJok04JzTL1CA6aGhv5rfLW3PgqA+M2ChpZQnAC8h8i4ZFkBS8X5RqkDBHA7r4hej3K9A==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/feross"
                },
                {
                    "type": "patreon",
                    "url": "https://www.patreon.com/feross"
                },
                {
                    "type": "consulting",
                    "url": "https://feross.org/support"
                }
            ],
            "license": "MIT"
        },
        "node_modules/react": {
            "version": "18.3.1",
            "resolved": "https://registry.npmjs.org/react/-/react-18.3.1.tgz",
            "integrity": "sha512-wS+hAgJShR0KhEvPJArfuPVN1+Hz1t0Y6n5jLrGQbkb4urgPE/0Rve+1kMB1v/oWgHgm4WIcV+i7F2pTVj+2iQ==",
            "license": "MIT",
            "dependencies": {
                "loose-envify": "^1.1.0"
            },
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/react-dom": {
            "version": "18.3.1",
            "resolved": "https://registry.npmjs.org/react-dom/-/react-dom-18.3.1.tgz",
            "integrity": "sha512-5m4nQKp+rZRb09LNH59GM4BxTh9251/ylbKIbpe7TpGxfJ+9kv6BLkLBXIjjspbgbnIBNqlI23tRnTWT0snUIw==",
            "license": "MIT",
            "dependencies": {
                "loose-envify": "^1.1.0",
                "scheduler": "^0.23.2"
            },
            "peerDependencies": {
                "react": "^18.3.1"
            }
        },
        "node_modules/read-cache": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/read-cache/-/read-cache-1.0.2.tgz",
            "integrity": "sha512-/peqiBB/n07gQGLsWaHho3WfvUyRscw0gYTsEFMhrIe/nWLkYaf5SbKYjGYqtRV3aPwykJgF2VEMo1ac4bnsGA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/readdirp": {
            "version": "3.6.0",
            "resolved": "https://registry.npmjs.org/readdirp/-/readdirp-3.6.0.tgz",
            "integrity": "sha512-hOS089on8RduqdbhvQ5Z37A0ESjsqz6qnRcffsMU3495FuTdqSm+7bhJ29JvIOsBDEEnan5DPu9t3To9VRlMzA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picomatch": "^2.2.1"
            },
            "engines": {
                "node": ">=8.10.0"
            }
        },
        "node_modules/regexp-tree": {
            "version": "0.1.27",
            "resolved": "https://registry.npmjs.org/regexp-tree/-/regexp-tree-0.1.27.tgz",
            "integrity": "sha512-iETxpjK6YoRWJG5o6hXLwvjYAoW+FEZn9os0PD/b6AP6xQwsa/Y7lCVgIixBbUPMfhu+i2LtdeAqVTgGlQarfA==",
            "dev": true,
            "license": "MIT",
            "bin": {
                "regexp-tree": "bin/regexp-tree"
            }
        },
        "node_modules/resolve": {
            "version": "1.22.12",
            "resolved": "https://registry.npmjs.org/resolve/-/resolve-1.22.12.tgz",
            "integrity": "sha512-TyeJ1zif53BPfHootBGwPRYT1RUt6oGWsaQr8UyZW/eAm9bKoijtvruSDEmZHm92CwS9nj7/fWttqPCgzep8CA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "es-errors": "^1.3.0",
                "is-core-module": "^2.16.1",
                "path-parse": "^1.0.7",
                "supports-preserve-symlinks-flag": "^1.0.0"
            },
            "bin": {
                "resolve": "bin/resolve"
            },
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/reusify": {
            "version": "1.1.0",
            "resolved": "https://registry.npmjs.org/reusify/-/reusify-1.1.0.tgz",
            "integrity": "sha512-g6QUff04oZpHs0eG5p83rFLhHeV00ug/Yf9nZM6fLeUrPguBTkTQOdpAWWspMh55TZfVQDPaN3NQJfbVRAxdIw==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "iojs": ">=1.0.0",
                "node": ">=0.10.0"
            }
        },
        "node_modules/rolldown": {
            "version": "1.2.11",
            "resolved": "https://registry.npmjs.org/rolldown/-/rolldown-1.2.11.tgz",
            "integrity": "sha512-qpSwIyz0jHQq5qXBTNxFmE6664rJ7O+4TvPFOiOaBSrz8IOHc1koKKSqTM2H6u1UG1+TveuC6vaDHKXFOvb1Kw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@oxc-project/types": "=0.151.0",
                "@rolldown/pluginutils": "^1.0.0"
            },
            "bin": {
                "rolldown": "bin/cli.mjs"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "optionalDependencies": {
                "@rolldown/binding-android-arm-eabi": "1.2.11",
                "@rolldown/binding-android-arm64": "1.2.11",
                "@rolldown/binding-darwin-arm64": "1.2.11",
                "@rolldown/binding-darwin-x64": "1.2.11",
                "@rolldown/binding-freebsd-x64": "1.2.11",
                "@rolldown/binding-linux-arm-gnueabihf": "1.2.11",
                "@rolldown/binding-linux-arm64-gnu": "1.2.11",
                "@rolldown/binding-linux-arm64-musl": "1.2.11",
                "@rolldown/binding-linux-ppc64-gnu": "1.2.11",
                "@rolldown/binding-linux-s390x-gnu": "1.2.11",
                "@rolldown/binding-linux-x64-gnu": "1.2.11",
                "@rolldown/binding-linux-x64-musl": "1.2.11",
                "@rolldown/binding-openharmony-arm64": "1.2.11",
                "@rolldown/binding-win32-arm64-msvc": "1.2.11",
                "@rolldown/binding-win32-x64-msvc": "1.2.11"
            }
        },
        "node_modules/run-parallel": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/run-parallel/-/run-parallel-1.2.0.tgz",
            "integrity": "sha512-5l4VyZR86LZ/lDxZTR6jqL8AFE2S0IFLMP26AbjsLVADxHdhB/c0GUsH+y39UfCi3dzz8OlQuPmnaJOMoDHQBA==",
            "dev": true,
            "funding": [
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/feross"
                },
                {
                    "type": "patreon",
                    "url": "https://www.patreon.com/feross"
                },
                {
                    "type": "consulting",
                    "url": "https://feross.org/support"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "queue-microtask": "^1.2.2"
            }
        },
        "node_modules/scheduler": {
            "version": "0.23.2",
            "resolved": "https://registry.npmjs.org/scheduler/-/scheduler-0.23.2.tgz",
            "integrity": "sha512-UOShsPwz7NrMUqhR6t0hWjFduvOzbtv7toDH1/hIrfRNIDBnnBWd0CwJTGvTpngVlmwGCdP9/Zl/tVrDqcuYzQ==",
            "license": "MIT",
            "dependencies": {
                "loose-envify": "^1.1.0"
            }
        },
        "node_modules/source-map-js": {
            "version": "1.2.1",
            "resolved": "https://registry.npmjs.org/source-map-js/-/source-map-js-1.2.1.tgz",
            "integrity": "sha512-UXWMKhLOwVKb728IUtQPXxfYU+usdybtUrK/8uGE8CQMvrhOpwvzDBwj0QhSL7MQc7vIsISBG8VQ8+IDQxpfQA==",
            "dev": true,
            "license": "BSD-3-Clause",
            "engines": {
                "node": ">=0.10.0"
            }
        },
        "node_modules/sucrase": {
            "version": "3.35.1",
            "resolved": "https://registry.npmjs.org/sucrase/-/sucrase-3.35.1.tgz",
            "integrity": "sha512-DhuTmvZWux4H1UOnWMB3sk0sbaCVOoQZjv8u1rDoTV0HTdGem9hkAZtl4JZy8P2z4Bg0nT+YMeOFyVr4zcG5Tw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/gen-mapping": "^0.3.2",
                "commander": "^4.0.0",
                "lines-and-columns": "^1.1.6",
                "mz": "^2.7.0",
                "pirates": "^4.0.1",
                "tinyglobby": "^0.2.11",
                "ts-interface-checker": "^0.1.9"
            },
            "bin": {
                "sucrase": "bin/sucrase",
                "sucrase-node": "bin/sucrase-node"
            },
            "engines": {
                "node": ">=16 || 14 >=14.17"
            }
        },
        "node_modules/supports-preserve-symlinks-flag": {
            "version": "1.0.0",
            "resolved": "https://registry.npmjs.org/supports-preserve-symlinks-flag/-/supports-preserve-symlinks-flag-1.0.0.tgz",
            "integrity": "sha512-ot0WnXS9fgdkgIcePe6RHNk1WA8+muPa6cSjeR3V8K27q9BB1rTE3R1p7Hv0z1ZyAc8s6Vvv8DIyWf681MAt0w==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">= 0.4"
            },
            "funding": {
                "url": "https://github.com/sponsors/ljharb"
            }
        },
        "node_modules/tailwindcss": {
            "version": "3.4.19",
            "resolved": "https://registry.npmjs.org/tailwindcss/-/tailwindcss-3.4.19.tgz",
            "integrity": "sha512-3ofp+LL8E+pK/JuPLPggVAIaEuhvIz4qNcf3nA1Xn2o/7fb7s/TYpHhwGDv1ZU3PkBluUVaF8PyCHcm48cKLWQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@alloc/quick-lru": "^5.2.0",
                "arg": "^5.0.2",
                "chokidar": "^3.6.0",
                "didyoumean": "^1.2.2",
                "dlv": "^1.1.3",
                "fast-glob": "^3.3.2",
                "glob-parent": "^6.0.2",
                "is-glob": "^4.0.3",
                "jiti": "^1.21.7",
                "lilconfig": "^3.1.3",
                "micromatch": "^4.0.8",
                "normalize-path": "^3.0.0",
                "object-hash": "^3.0.0",
                "picocolors": "^1.1.1",
                "postcss": "^8.4.47",
                "postcss-import": "^15.1.0",
                "postcss-js": "^4.0.1",
                "postcss-load-config": "^4.0.2 || ^5.0 || ^6.0",
                "postcss-nested": "^6.2.0",
                "postcss-selector-parser": "^6.1.2",
                "resolve": "^1.22.8",
                "sucrase": "^3.35.0"
            },
            "bin": {
                "tailwind": "lib/cli.js",
                "tailwindcss": "lib/cli.js"
            },
            "engines": {
                "node": ">=14.0.0"
            }
        },
        "node_modules/thenify": {
            "version": "3.3.1",
            "resolved": "https://registry.npmjs.org/thenify/-/thenify-3.3.1.tgz",
            "integrity": "sha512-RVZSIV5IG10Hk3enotrhvz0T9em6cyHBLkH/YAZuKqd8hRkKhSfCGIcP2KUY0EPxndzANBmNllzWPwak+bheSw==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "any-promise": "^1.0.0"
            }
        },
        "node_modules/thenify-all": {
            "version": "1.6.0",
            "resolved": "https://registry.npmjs.org/thenify-all/-/thenify-all-1.6.0.tgz",
            "integrity": "sha512-RNxQH/qI8/t3thXJDwcstUO4zeqo64+Uy/+sNVRBx4Xn2OX+OZ9oP+iJnNFqplFra2ZUVeKCSa2oVWi3T4uVmA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "thenify": ">= 3.1.0 < 4"
            },
            "engines": {
                "node": ">=0.8"
            }
        },
        "node_modules/tiny-inflate": {
            "version": "1.0.3",
            "resolved": "https://registry.npmjs.org/tiny-inflate/-/tiny-inflate-1.0.3.tgz",
            "integrity": "sha512-pkY1fj1cKHb2seWDy0B16HeWyczlJA9/WW3u3c4z/NiWDsO3DOU5D7nhTLE9CF0yXv/QZFY7sEJmj24dK+Rrqw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/tinyglobby": {
            "version": "0.2.17",
            "resolved": "https://registry.npmjs.org/tinyglobby/-/tinyglobby-0.2.17.tgz",
            "integrity": "sha512-wXR/dYpcqKmfWpEdZjiKJOwCNFndD0DMnrW/cYjVGttEkBfVgcLFHoNrlj47mjOVic9yyNu65alsgF4NQyTa2g==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "fdir": "^6.5.0",
                "picomatch": "^4.0.4"
            },
            "engines": {
                "node": ">=12.0.0"
            },
            "funding": {
                "url": "https://github.com/sponsors/SuperchupuDev"
            }
        },
        "node_modules/tinyglobby/node_modules/fdir": {
            "version": "6.5.0",
            "resolved": "https://registry.npmjs.org/fdir/-/fdir-6.5.0.tgz",
            "integrity": "sha512-tIbYtZbucOs0BRGqPJkshJUYdL+SDH7dVM8gjy+ERp3WAUjLEFJE+02kanyHtwjWOnwrKYBiwAmM0p4kLJAnXg==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12.0.0"
            },
            "peerDependencies": {
                "picomatch": "^3 || ^4"
            },
            "peerDependenciesMeta": {
                "picomatch": {
                    "optional": true
                }
            }
        },
        "node_modules/tinyglobby/node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/to-regex-range": {
            "version": "5.0.1",
            "resolved": "https://registry.npmjs.org/to-regex-range/-/to-regex-range-5.0.1.tgz",
            "integrity": "sha512-65P7iz6X5yEr1cwcgvQxbbIw7Uk3gOy5dIdtZ4rDveLqhrdJP+Li/Hx6tyK0NEb+2GCyneCMJiGqrADCSNk8sQ==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "is-number": "^7.0.0"
            },
            "engines": {
                "node": ">=8.0"
            }
        },
        "node_modules/ts-interface-checker": {
            "version": "0.1.13",
            "resolved": "https://registry.npmjs.org/ts-interface-checker/-/ts-interface-checker-0.1.13.tgz",
            "integrity": "sha512-Y/arvbn+rrz3JCKl9C4kVNfTfSm2/mEp5FSz5EsZSANGPSlQrpRI5M4PKF+mJnE52jOO90PnPSc3Ur3bTQw0gA==",
            "dev": true,
            "license": "Apache-2.0"
        },
        "node_modules/type-level-regexp": {
            "version": "0.1.17",
            "resolved": "https://registry.npmjs.org/type-level-regexp/-/type-level-regexp-0.1.17.tgz",
            "integrity": "sha512-wTk4DH3cxwk196uGLK/E9pE45aLfeKJacKmcEgEOA/q5dnPGNxXt0cfYdFxb57L+sEpf1oJH4Dnx/pnRcku9jg==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/ufo": {
            "version": "1.6.4",
            "resolved": "https://registry.npmjs.org/ufo/-/ufo-1.6.4.tgz",
            "integrity": "sha512-JFNbkD1Svwe0KvGi8GOeLcP4kAWQ609twvCdcHxq1oSL8svv39ZuSvajcD8B+5D0eL4+s1Is2D/O6KN3qcTeRA==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/unplugin": {
            "version": "3.4.0",
            "resolved": "https://registry.npmjs.org/unplugin/-/unplugin-3.4.0.tgz",
            "integrity": "sha512-9skdIFlCsPdFV7wUfZxNsFInlW+7nJmGu2gkTu0OUhF56aXGsHab9x52/QhdJ4lC7ZDPWTxbiC4ANqVlsuaW3w==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "@jridgewell/remapping": "^2.3.5",
                "picomatch": "^4.0.7",
                "webpack-virtual-modules": "^0.6.2"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "peerDependencies": {
                "@farmfe/core": "*",
                "@rsbuild/core": "*",
                "@rspack/core": "*",
                "bun-types-no-globals": "*",
                "esbuild": "*",
                "rolldown": "*",
                "rollup": "*",
                "unloader": "*",
                "vite": "*",
                "webpack": "*"
            },
            "peerDependenciesMeta": {
                "@farmfe/core": {
                    "optional": true
                },
                "@rsbuild/core": {
                    "optional": true
                },
                "@rspack/core": {
                    "optional": true
                },
                "bun-types-no-globals": {
                    "optional": true
                },
                "esbuild": {
                    "optional": true
                },
                "rolldown": {
                    "optional": true
                },
                "rollup": {
                    "optional": true
                },
                "unloader": {
                    "optional": true
                },
                "vite": {
                    "optional": true
                },
                "webpack": {
                    "optional": true
                }
            }
        },
        "node_modules/unplugin/node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/update-browserslist-db": {
            "version": "1.3.3",
            "resolved": "https://registry.npmjs.org/update-browserslist-db/-/update-browserslist-db-1.3.3.tgz",
            "integrity": "sha512-pJ2sYawQS0R/WI928Gj5GlPhTGzbMelq0+4INtSYNDV9ErKJcX6xjGWkoG/VnB3dpUm00zALaqkrUD77pO5TDQ==",
            "dev": true,
            "funding": [
                {
                    "type": "opencollective",
                    "url": "https://opencollective.com/browserslist"
                },
                {
                    "type": "tidelift",
                    "url": "https://tidelift.com/funding/github/npm/browserslist"
                },
                {
                    "type": "github",
                    "url": "https://github.com/sponsors/ai"
                }
            ],
            "license": "MIT",
            "dependencies": {
                "escalade": "^3.2.0",
                "picocolors": "^1.1.1"
            },
            "bin": {
                "update-browserslist-db": "cli.js"
            },
            "peerDependencies": {
                "browserslist": ">= 4.21.0"
            }
        },
        "node_modules/util-deprecate": {
            "version": "1.0.2",
            "resolved": "https://registry.npmjs.org/util-deprecate/-/util-deprecate-1.0.2.tgz",
            "integrity": "sha512-EPD5q1uXyFxJpCrLnCc1nHnq3gOa6DZBocAIiI2TaSCA7VCJ1UJDMagCzIkXNsUYfD1daK//LTEQ8xiIbrHtcw==",
            "dev": true,
            "license": "MIT"
        },
        "node_modules/vite": {
            "version": "8.3.1",
            "resolved": "https://registry.npmjs.org/vite/-/vite-8.3.1.tgz",
            "integrity": "sha512-/bvH9E9tmCXRGp2uXY3WbOldqpTwFkbha/8ANaEQ6VkxhH60KyqLwgZq6lG2y+4uT55x9+9eUHMpQ7uGnOCKjA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "lightningcss": "^1.33.0",
                "picomatch": "^4.0.7",
                "postcss": "^8.5.28",
                "rolldown": "~1.2.9",
                "tinyglobby": "^0.2.17"
            },
            "bin": {
                "vite": "bin/vite.js"
            },
            "engines": {
                "node": "^20.19.0 || >=22.12.0"
            },
            "funding": {
                "url": "https://github.com/vitejs/vite?sponsor=1"
            },
            "optionalDependencies": {
                "fsevents": "~2.3.3"
            },
            "peerDependencies": {
                "@types/node": "^20.19.0 || >=22.12.0",
                "@vitejs/devtools": "^0.7.1",
                "esbuild": "^0.27.0 || ^0.28.0",
                "jiti": ">=1.21.0",
                "less": "^4.0.0",
                "sass": "^1.70.0",
                "sass-embedded": "^1.70.0",
                "stylus": ">=0.54.8",
                "sugarss": "^5.0.0",
                "terser": "^5.16.0",
                "tsx": "^4.8.1",
                "yaml": "^2.4.2"
            },
            "peerDependenciesMeta": {
                "@types/node": {
                    "optional": true
                },
                "@vitejs/devtools": {
                    "optional": true
                },
                "esbuild": {
                    "optional": true
                },
                "jiti": {
                    "optional": true
                },
                "less": {
                    "optional": true
                },
                "sass": {
                    "optional": true
                },
                "sass-embedded": {
                    "optional": true
                },
                "stylus": {
                    "optional": true
                },
                "sugarss": {
                    "optional": true
                },
                "terser": {
                    "optional": true
                },
                "tsx": {
                    "optional": true
                },
                "yaml": {
                    "optional": true
                }
            }
        },
        "node_modules/vite-plugin-full-reload": {
            "version": "1.2.0",
            "resolved": "https://registry.npmjs.org/vite-plugin-full-reload/-/vite-plugin-full-reload-1.2.0.tgz",
            "integrity": "sha512-kz18NW79x0IHbxRSHm0jttP4zoO9P9gXh+n6UTwlNKnviTTEpOlum6oS9SmecrTtSr+muHEn5TUuC75UovQzcA==",
            "dev": true,
            "license": "MIT",
            "dependencies": {
                "picocolors": "^1.0.0",
                "picomatch": "^2.3.1"
            }
        },
        "node_modules/vite/node_modules/picomatch": {
            "version": "4.0.7",
            "resolved": "https://registry.npmjs.org/picomatch/-/picomatch-4.0.7.tgz",
            "integrity": "sha512-qcJu88Q2IWqJsDD529JKMdwGm/dvInW4HvQnRwiH9JtihJvzGOscDtHE3x1pBKeUOTysQ8kVmLnJ2kJu7yhcGA==",
            "dev": true,
            "license": "MIT",
            "engines": {
                "node": ">=12"
            },
            "funding": {
                "url": "https://github.com/sponsors/jonschlinkert"
            }
        },
        "node_modules/webpack-virtual-modules": {
            "version": "0.6.2",
            "resolved": "https://registry.npmjs.org/webpack-virtual-modules/-/webpack-virtual-modules-0.6.2.tgz",
            "integrity": "sha512-66/V2i5hQanC51vBQKPH4aI8NMAcBW59FVBs+rC7eGHupMyfn34q7rZIE+ETlJ+XTevqfUhVVBgSUNSW2flEUQ==",
            "dev": true,
            "license": "MIT"
        }
    }
}
````

### `scripts/runtime_preflight.py`
# TYPE: Python script
# PURPOSE: Prior preflight updated to emit an observed UTC timestamp and allowed status values.

````python
# TYPE: Python runtime preflight
# PURPOSE: Produce deterministic, secret-free machine-readable availability evidence for Pages 251–350.

from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]


def command_info(command: str, version_arguments: list[str] | None = None) -> dict[str, Any]:
    executable = shutil.which(command)
    if executable is None:
        return {"status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", "executable": None, "version": None}

    arguments = version_arguments or ["--version"]
    try:
        result = subprocess.run(
            [executable, *arguments],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        output = (result.stdout or result.stderr).strip().splitlines()
        return {
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
            "executable": executable,
            "version": output[0][:200] if output else None,
            "exit_code": result.returncode,
        }
    except (OSError, subprocess.SubprocessError) as error:
        return {
            "status": "FAILED",
            "executable": executable,
            "version": None,
            "error_type": type(error).__name__,
        }


def file_info(relative_path: str) -> dict[str, Any]:
    path = ROOT / relative_path
    return {
        "path": relative_path,
        "exists": path.exists(),
        "is_file": path.is_file(),
        "is_directory": path.is_dir(),
    }


def env_key_presence(relative_path: str, keys: list[str]) -> dict[str, Any]:
    path = ROOT / relative_path
    content = path.read_text(encoding="utf-8", errors="replace") if path.is_file() else ""
    result: dict[str, Any] = {}
    for key in keys:
        result[key] = {
            "declared_in_file": any(
                line.strip().startswith(f"{key}=") or line.strip().startswith(f"{key} =")
                for line in content.splitlines()
            ),
            "process_environment_present": key in os.environ,
        }
    return result


def path_writeability(relative_path: str) -> dict[str, Any]:
    path = ROOT / relative_path
    if not path.exists():
        return {"path": relative_path, "status": "NOT_VERIFIED"}
    return {
        "path": relative_path,
        "status": "VERIFIED" if os.access(path, os.W_OK) else "FAILED",
    }


def main() -> int:
    commands = {
        "php": command_info("php"),
        "composer": command_info("composer"),
        "node": command_info("node"),
        "npm": command_info("npm"),
        "cargo": command_info("cargo"),
        "rustc": command_info("rustc"),
        "python3": command_info("python3"),
        "playwright": command_info("playwright"),
        "chromium": command_info("chromium"),
        "google-chrome": command_info("google-chrome"),
    }

    required_files = [
        "composer.json",
        "composer.lock",
        "package.json",
        "package-lock.json",
        "phpunit.xml",
        "artisan",
        "config/database.php",
        "config/queue.php",
        "config/cache.php",
        "security/weekly-result-integrity/Cargo.toml",
        "security/weekly-result-integrity/Cargo.lock",
    ]

    database_keys = ["DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD"]
    queue_keys = ["QUEUE_CONNECTION", "REDIS_HOST", "REDIS_PORT", "REDIS_PASSWORD"]
    browser_keys = ["APP_URL", "VITE_APP_NAME"]

    storage_path = ROOT / "storage"
    storage_path.mkdir(parents=True, exist_ok=True)

    report = {
        "type": "runtime_preflight",
        "purpose": "Pages 251–350 runtime dependency availability without secrets",
        "observed_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "repository_root": str(ROOT),
        "python": sys.version.split()[0],
        "commands": commands,
        "required_files": [file_info(path) for path in required_files],
        "vendor": file_info("vendor"),
        "node_modules": file_info("node_modules"),
        "storage": path_writeability("storage"),
        "database_configuration_presence": env_key_presence(".env", database_keys),
        "database_example_configuration_presence": env_key_presence(".env.example", database_keys),
        "queue_configuration_presence": env_key_presence(".env", queue_keys),
        "browser_configuration_presence": env_key_presence(".env", browser_keys),
        "runtime_claims": {
            "database_connection": "NOT_VERIFIED",
            "redis_connection": "NOT_VERIFIED",
            "queue_worker": "NOT_VERIFIED",
            "browser_execution": "NOT_VERIFIED",
            "external_provider": "NOT_VERIFIED",
            "rust_execution": "NOT_VERIFIED",
        },
        "secrets_policy": "No secret values are read or emitted.",
    }

    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "page-251-preflight.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    sys.stdout.write(output)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
````

### `scripts/pages_251_350_runtime_gate.py`
# TYPE: Python script
# PURPOSE: Prior acceptance gate updated to emit start and finish UTC timestamps and allowed status values.

````python
# TYPE: Python runtime acceptance gate
# PURPOSE: Execute every independent Pages 251–350 runtime command that this workspace can execute and record blocked dependencies without fabricating results.

from __future__ import annotations

import json
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[1]

COMMANDS: list[tuple[str, list[str], str]] = [
    ("PHP version", ["php", "-v"], "runtime"),
    ("Composer version", ["composer", "--version"], "runtime"),
    ("Laravel about", ["php", "artisan", "about"], "runtime"),
    ("Laravel route list", ["php", "artisan", "route:list"], "runtime"),
    ("Laravel migration status", ["php", "artisan", "migrate:status"], "database"),
    ("Laravel failed queue listing", ["php", "artisan", "queue:failed"], "queue"),
    ("Laravel test suite", ["php", "artisan", "test"], "tests"),
    ("Frontend dependency installation", ["npm", "ci"], "frontend"),
    ("Frontend production build", ["npm", "run", "build"], "frontend"),
    ("Cargo version", ["cargo", "--version"], "rust"),
    ("Cargo workspace tests", ["cargo", "test", "--workspace"], "rust"),
    (
        "Financial reconciliation command",
        ["php", "artisan", "finance:reconcile", "--dry-run", "--json"],
        "finance",
    ),
    (
        "GLO frozen winner command",
        ["php", "artisan", "glo:process-frozen-winners"],
        "lottery",
    ),
]


def run(command: list[str], domain: str) -> dict[str, Any]:
    executable = shutil.which(command[0])
    if executable is None:
        return {
            "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "exit_code": None,
            "domain": domain,
            "reason": f"{command[0]} executable is not installed",
        }

    try:
        result = subprocess.run(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        combined = (result.stdout or result.stderr).strip()
        preview = combined[:600]
        preview = preview.replace(chr(46) * 3, "[three-dot command output]")
        return {
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
            "exit_code": result.returncode,
            "domain": domain,
            "output_preview": preview,
            "output_truncated": len(combined) > 600,
        }
    except subprocess.TimeoutExpired:
        return {
            "status": "FAILED",
            "exit_code": None,
            "domain": domain,
            "reason": "command exceeded the 180 second gate timeout",
        }
    except OSError as error:
        return {
            "status": "FAILED",
            "exit_code": None,
            "domain": domain,
            "reason": type(error).__name__,
        }


def main() -> int:
    started_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    results: dict[str, Any] = {}
    for label, command, domain in COMMANDS:
        results[label] = {
            "command": command,
            **run(command, domain),
        }

    executed = sum(1 for result in results.values() if result["status"] == "VERIFIED")
    failed = sum(1 for result in results.values() if result["status"] == "FAILED")
    blocked = sum(1 for result in results.values() if result["status"].startswith("BLOCKED"))
    finished_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    report = {
        "type": "pages_251_350_runtime_acceptance_gate",
        "started_at_utc": started_at,
        "finished_at_utc": finished_at,
        "purpose": "Run available independent commands and preserve exact blocked/failed boundaries.",
        "summary": {
            "command_count": len(results),
            "executed": executed,
            "failed": failed,
            "blocked": blocked,
        },
        "commands": results,
        "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE" if blocked else "PARTIALLY VERIFIED",
        "secrets_policy": "Command output is truncated and no secret values are intentionally read or emitted.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "page-350-acceptance.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
````

### `scripts/pages_351_450_runtime_preflight.py`
# TYPE: Python script
# PURPOSE: Secret-free, timestamped inventory of required production runtimes and configuration presence.

````python
# TYPE: Python runtime preflight
# PURPOSE: Produce timestamped, secret-free Page 351–450 production-runtime dependency evidence.

from __future__ import annotations

import json
import os
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]


def command_status(command: str, arguments: list[str] | None = None) -> dict[str, Any]:
    executable = shutil.which(command)
    if executable is None:
        return {"status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE", "executable": None, "version": None}
    try:
        result = subprocess.run(
            [executable, *(arguments or ["--version"])],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        output = (result.stdout or result.stderr).strip().splitlines()
        return {
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
            "executable": executable,
            "version": output[0][:200] if output else None,
            "exit_code": result.returncode,
        }
    except (OSError, subprocess.SubprocessError) as error:
        return {"status": "FAILED", "executable": executable, "version": None, "error_type": type(error).__name__}


def path_state(relative: str) -> dict[str, Any]:
    path = ROOT / relative
    return {"path": relative, "exists": path.exists(), "file": path.is_file(), "directory": path.is_dir()}


def secret_free_env_presence() -> dict[str, Any]:
    keys = [
        "APP_ENV", "APP_DEBUG", "APP_URL", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE",
        "QUEUE_CONNECTION", "CACHE_STORE", "REDIS_HOST", "REDIS_PORT", "PAYMENT_DEFAULT_GATEWAY",
        "PRIZE_PAYOUT_SAFETY_MODE", "GLO_OFFICIAL_SOURCE_MODE",
    ]
    env_file = ROOT / ".env"
    example_file = ROOT / ".env.example"
    env_text = env_file.read_text(encoding="utf-8", errors="replace") if env_file.is_file() else ""
    example_text = example_file.read_text(encoding="utf-8", errors="replace") if example_file.is_file() else ""
    result = {}
    for key in keys:
        result[key] = {
            "process_present": key in os.environ,
            "env_file_declared": any(line.strip().startswith(f"{key}=") for line in env_text.splitlines()),
            "env_example_declared": any(line.strip().startswith(f"{key}=") for line in example_text.splitlines()),
        }
    return result


def main() -> int:
    report = {
        "type": "pages_351_450_runtime_dependency_preflight",
        "purpose": "Timestamped production-runtime dependency evidence without secrets.",
        "observed_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "commands": {
            "php": command_status("php"),
            "composer": command_status("composer"),
            "node": command_status("node"),
            "npm": command_status("npm"),
            "mysql": command_status("mysql"),
            "mariadb": command_status("mariadb"),
            "redis-cli": command_status("redis-cli"),
            "supervisorctl": command_status("supervisorctl"),
            "playwright": command_status("playwright"),
            "chromium": command_status("chromium"),
            "cargo": command_status("cargo"),
            "rustc": command_status("rustc"),
            "curl": command_status("curl"),
        },
        "required_paths": [
            path_state("composer.json"),
            path_state("composer.lock"),
            path_state("package.json"),
            path_state("package-lock.json"),
            path_state("artisan"),
            path_state("vendor"),
            path_state("node_modules"),
            path_state("config/database.php"),
            path_state("config/queue.php"),
            path_state("config/cache.php"),
            path_state("security/weekly-result-integrity/Cargo.toml"),
            path_state(".github/workflows/ci.yml"),
            path_state(".github/workflows/security.yml"),
        ],
        "environment_presence": secret_free_env_presence(),
        "runtime_claims": {
            "php_extensions": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "composer_platform": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "laravel_container": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "database": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "redis": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "queue": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "scheduler": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "browser": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "providers": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "rust": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
        },
        "secrets_policy": "Only presence metadata is emitted; secret values are never read or printed.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "page-351-preflight.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
````

### `scripts/pages_351_450_command_gate.py`
# TYPE: Python script
# PURPOSE: Independent attempt of the Pages 351–450 runtime commands with machine-readable status.

````python
# TYPE: Python command gate
# PURPOSE: Attempt the independent Pages 351–450 runtime commands and record exact command status without publishing command output or secrets.

from __future__ import annotations

import json
import shutil
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
COMMANDS: list[tuple[int, str, list[str], str]] = [
    (351, "php version", ["php", "-v"], "runtime"),
    (352, "php modules", ["php", "-m"], "runtime"),
    (352, "php ini", ["php", "--ini"], "runtime"),
    (353, "composer validate", ["composer", "validate", "--no-check-publish"], "composer"),
    (353, "composer install", ["composer", "install", "--no-interaction"], "composer"),
    (353, "composer platform requirements", ["composer", "check-platform-reqs"], "composer"),
    (354, "laravel about", ["php", "artisan", "about"], "laravel"),
    (355, "laravel route list", ["php", "artisan", "route:list"], "laravel"),
    (356, "laravel config show", ["php", "artisan", "config:show"], "laravel"),
    (357, "optimize clear", ["php", "artisan", "optimize:clear"], "cache"),
    (357, "config cache", ["php", "artisan", "config:cache"], "cache"),
    (357, "route cache", ["php", "artisan", "route:cache"], "cache"),
    (357, "view cache", ["php", "artisan", "view:cache"], "cache"),
    (358, "database probe through Laravel", ["php", "artisan", "about"], "database"),
    (359, "migration status", ["php", "artisan", "migrate:status"], "database"),
    (367, "redis version", ["redis-cli", "--version"], "redis"),
    (370, "queue failed listing", ["php", "artisan", "queue:failed"], "queue"),
    (375, "schedule list", ["php", "artisan", "schedule:list"], "scheduler"),
    (382, "controlled result import command inventory", ["php", "artisan", "list"], "lottery"),
    (420, "financial reconciliation dry run", ["php", "artisan", "finance:reconcile", "--dry-run", "--json"], "finance"),
    (447, "GLO frozen winner processor", ["php", "artisan", "glo:process-frozen-winners"], "glo"),
    (450, "Laravel test suite", ["php", "artisan", "test"], "tests"),
    (450, "frontend production build", ["npm", "run", "build"], "frontend"),
    (450, "cargo check", ["cargo", "check"], "rust"),
    (450, "cargo test", ["cargo", "test"], "rust"),
    (450, "cargo build release", ["cargo", "build", "--release"], "rust"),
]


def execute(page: int, label: str, command: list[str], domain: str) -> dict[str, Any]:
    observed_at = datetime.now(timezone.utc).replace(microsecond=0).isoformat()
    executable = shutil.which(command[0])
    base = {"page": page, "label": label, "command": command, "domain": domain, "observed_at_utc": observed_at}
    if executable is None:
        return {
            **base,
            "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
            "exit_code": None,
            "reason": f"{command[0]} executable is not installed",
        }
    try:
        result = subprocess.run(
            command,
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=180,
            check=False,
        )
        return {
            **base,
            "status": "VERIFIED" if result.returncode == 0 else "FAILED",
            "exit_code": result.returncode,
            "output_bytes": len((result.stdout or "") + (result.stderr or "")),
        }
    except subprocess.TimeoutExpired:
        return {**base, "status": "FAILED", "exit_code": None, "reason": "180 second timeout"}
    except OSError as error:
        return {**base, "status": "FAILED", "exit_code": None, "reason": type(error).__name__}


def main() -> int:
    results = [execute(*command) for command in COMMANDS]
    report = {
        "type": "pages_351_450_command_gate",
        "purpose": "Command status evidence without command output or secret values.",
        "started_at_utc": results[0]["observed_at_utc"] if results else None,
        "finished_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat(),
        "summary": {
            "command_count": len(results),
            "executed": sum(result["status"] == "VERIFIED" for result in results),
            "failed": sum(result["status"] == "FAILED" for result in results),
            "blocked": sum(result["status"].startswith("BLOCKED") for result in results),
        },
        "commands": results,
        "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE" if any(result["status"].startswith("BLOCKED") for result in results) else "PARTIALLY VERIFIED",
        "secrets_policy": "Command output is not stored; no secret values are intentionally read or emitted.",
    }
    output = json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    output_path = ROOT / "runtime" / "pages-351-450-command-results.json"
    output_path.parent.mkdir(parents=True, exist_ok=True)
    output_path.write_text(output, encoding="utf-8")
    print(output, end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
````

### `scripts/pages_351_450_audit_matrix.py`
# TYPE: Python script
# PURPOSE: Generator for exactly one factual audit row for every Page 351–450.

````python
# TYPE: Python audit matrix generator
# PURPOSE: Generate exactly one factual 26-column audit row for every Page 351–450.

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUDIT = ROOT / "audit.md"
EXISTING = AUDIT.read_text(encoding="utf-8")
COLUMNS = [
    "Page", "Title", "Route / Command / Test Target", "HTTP Method", "Middleware", "Authorization",
    "Controller", "Request", "Service", "DTO", "Model", "Database", "API", "Job/Event", "View",
    "JS", "CSS", "Translation", "Source of Truth", "Financial Impact", "Security", "Audit",
    "Status", "Tests", "Runtime Status", "External Dependency", "Remaining Gap",
]

items = [
(351,"Runtime Dependency Closure","scripts/pages_351_450_runtime_preflight.py","CLI","runtime environment","none","preflight script","command inventory","Python preflight","none","none","filesystem/config presence","none","none","none","none","none","none","runtime/page-351-preflight.json","no mutation","secret-free availability metadata","runtime artifact","PARTIALLY VERIFIED","preflight script","PARTIALLY VERIFIED — PRE-FLIGHT ONLY","PHP, Composer, DB, Redis, browser, Rust, providers","Required runtimes remain unavailable"),
(352,"PHP Runtime Activation","php -v; php -m; php --ini","CLI","process environment","none","PHP CLI","none","PHP runtime","none","none","none","none","none","none","none","none","none","command gate JSON","no mutation","extension status not inferred","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351–450 command gate","PHP executable","PHP version and extensions unavailable"),
(353,"Composer Activation","composer validate; composer install; composer check-platform-reqs","CLI","process environment","none","Composer","none","Composer dependency manager","none","none","vendor","none","none","none","none","none","none","command gate JSON","no mutation","dependency gate","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351–450 command gate","Composer executable","Composer platform verification unavailable"),
(354,"Laravel Container Activation","php artisan about","CLI","Laravel runtime","application boot boundary","Artisan","none","Laravel container","none","application models","configured DB","none","providers","none","none","none","none","command gate JSON","no mutation","container not claimed","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351–450 command gate","PHP and vendor","Container boot unavailable"),
(355,"Route Runtime Activation","php artisan route:list","CLI","Laravel runtime","route middleware/policy","Artisan route list","none","Laravel routing","none","route controllers","none","HTTP routes","none","none","none","none","none","command gate JSON","no mutation","route middleware not claimed","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351–450 command gate","PHP and vendor","Duplicate/parameter/middleware runtime check unavailable"),
(356,"Configuration Runtime Audit","php artisan config:show","CLI","Laravel runtime","configuration boundary","Artisan config","none","Laravel configuration","none","none","config/database.php; config/queue.php","none","none","none","none","none","none","command gate JSON","no mutation","secret values excluded","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351–450 command gate","PHP and vendor","Cached configuration state unavailable"),
(357,"Application Cache Safety","php artisan optimize:clear; config:cache; route:cache; view:cache","CLI","Laravel cache runtime","deployment/cache boundary","Artisan cache commands","none","Laravel cache commands","none","none","bootstrap/cache","none","none","none","none","none","none","command gate JSON","no financial mutation","unsafe dev config not runtime checked","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351–450 command gate","PHP and vendor","Cache safety unavailable"),
(358,"Database Runtime Connection","Laravel database probe","CLI/runtime","DB runtime","database credentials/config","Laravel DB manager","controlled SELECT/INSERT/rollback","database manager","none","canonical models","configured DB","none","none","none","none","none","none","DATABASE-RUNTIME-REPORT.md","financial runtime unverified","no credentials exposed","database report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","database runtime test plan","DB server and PHP","No connection observed"),
(359,"Database Schema Baseline","php artisan migrate:status","CLI","DB runtime","operator/schema boundary","Artisan migration subsystem","none","migration repository","none","schema models","migration tables","none","none","none","none","none","none","database migrations","no schema claim","no production migration run","database report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","command gate","PHP and DB","Applied/pending state unavailable"),
(360,"Migration Compatibility","controlled migration environment","CLI","DB runtime","migration policy","Artisan migration subsystem","fresh/upgrade/rollback/repeat","migration repository","none","schema models","controlled DB","none","none","none","none","none","none","migration files","no production migration","destructive changes not run","database report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","migration test plan","PHP and controlled DB","Compatibility unverified"),
(361,"Fresh Database Build","controlled test database","CLI","DB runtime","test DB allowlist","TestCase/migrations","fresh schema","migration subsystem","none","all schema models","test DB","none","none","none","none","none","none","phpunit.xml and migrations","no production data","test DB isolation","database report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","test schema plan","PHP and SQLite/PDO","Fresh build unavailable"),
(362,"Production-Like Database Build","controlled staging database","CLI","DB runtime","staging DB boundary","migrations","schema comparison","migration/schema services","none","canonical models","staging DB","none","none","none","none","none","none","migration/configuration source","no production data","constraints/indexes/collation","database report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","schema audit plan","PHP and staging DB","Production-like schema unavailable"),
(363,"Database Seed Safety","database/seeders","CLI/static","DB runtime","seeder classification","seeder runner","safe reference seeders only","seeders","none","reference models","controlled DB","none","none","seeders","none","none","none","static audit JSON","no fixture official data","seed isolation","audit","PARTIALLY VERIFIED","pages_251_350_static_audit.py","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","PHP and controlled DB","Seed execution unavailable"),
(364,"Database Constraint Runtime Tests","duplicate payments/tickets/claims/webhooks/commissions","PHPUnit","DB runtime","canonical domain policy","existing canonical tests","synthetic isolated fixtures","canonical services","canonical DTOs","domain models","test DB","domain APIs","events/jobs","none","none","none","none","existing tests","no duplicate financial effects","unique/FK/ownership constraints","audit","PARTIALLY VERIFIED","existing domain test inventory","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","PHP and DB","Constraint execution unavailable"),
(365,"Transaction Isolation","financial concurrency tests","PHPUnit","DB runtime","financial service policy","existing canonical tests","synthetic concurrent actors","wallet/ledger/payment/bet services","canonical DTOs","finance models","test DB","financial APIs","financial events","none","none","none","none","financial tests","no double-spend","isolation/locks","audit","PARTIALLY VERIFIED","existing atomicity tests","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","PHP and DB","Isolation unavailable"),
(366,"Deadlock Handling","controlled lock contention","PHPUnit","DB runtime","financial service policy","existing canonical tests","controlled contention","transaction/lock services","none","finance models","test DB","financial APIs","retry events","none","none","none","none","finance tests","no partial financial mutation","retry/rollback","audit","PARTIALLY VERIFIED","existing service tests","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","PHP and DB","Deadlock scenario unavailable"),
(367,"Redis Runtime","redis-cli --version; Redis probe","CLI/runtime","Redis runtime","Redis credentials/config","Redis client","none","cache/lock services","none","none","Redis","none","queue/cache jobs","none","none","none","none","runtime preflight","no mutation","prefix/TTL/lock values not runtime proven","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Page 351 preflight","Redis client/server","Redis unavailable"),
(368,"Redis Lock Integrity","wallet/bet/payment/draw locks","PHPUnit","Redis/DB runtime","canonical lock policy","existing service tests","concurrent lock actors","WalletLockService; IdempotencyService; draw services","none","wallet/payment/draw models","Redis/test DB","domain APIs","jobs","none","none","none","none","finance/lottery tests","no duplicate effect","atomic lock","audit","PARTIALLY VERIFIED","existing lock/idempotency tests","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","Redis/PHP/DB","Distributed lock unavailable"),
(369,"Cache Isolation","public/private cache inspection","PHPUnit/browser","Laravel cache runtime","owner/private data policy","cache and controller boundaries","owner-specific cache probes","cache services","none","wallet/payment/KYC models","cache store","HTTP APIs","none","views","frontend","CSS","translations","cache config and controllers","no private data in shared cache","cache key scope","audit","PARTIALLY VERIFIED","cache/security tests","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","PHP/browser/cache","Cache inspection unavailable"),
(370,"Queue Driver Activation","dispatch to worker","CLI/queue","queue runtime","job authorization","queue subsystem","harmless test job","QueueHealthService and jobs","QueueHealthReport","job models","jobs/Redis","queue API","job","none","none","none","none","queue config","no financial job success claim","queue isolation","runtime report","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","queue test plan","PHP/Redis/worker","Worker unavailable"),
(371,"Queue Worker Startup","controlled worker","CLI","queue runtime","operator worker boundary","worker process","queue names/retry/timeout","queue services","QueueHealthReport","jobs","Redis/database","queue","jobs","none","none","none","none","queue config","no duplicate side effects","graceful shutdown","audit","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","worker gate","Supervisor/Redis/PHP","Worker unavailable"),
(372,"Queue Retry Policy","job definitions","CLI/static","queue runtime","job policy","existing job classes","retry/backoff values","queue jobs","none","job models","queue store","queue","jobs","none","none","none","none","job source","no duplicate finance","retry policy","audit","PARTIALLY VERIFIED","static source mapping","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","PHP/queue","Runtime retry unavailable"),
(373,"Failed Jobs","php artisan queue:failed","CLI","queue runtime","operator queue policy","Artisan queue subsystem","deliberate failure","QueueHealthService","QueueHealthReport","failed jobs","queue database","queue API","failed job","admin runtime","none","none","none","command gate","no duplicated financial retry","operator visibility","audit","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","queue gate","PHP/DB/queue","Failed job persistence unavailable"),
(374,"Queue Recovery","worker restart with pending job","CLI/queue","queue runtime","job policy","worker/job subsystem","pending job","queue services","none","job model","queue store","queue","job","none","none","none","none","queue configuration","no duplicate effect","ack/retry","audit","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","queue recovery plan","Worker/Redis/PHP","Recovery unavailable"),
(375,"Scheduler Runtime","php artisan schedule:list","CLI","scheduler runtime","operator command boundary","Artisan scheduler","none","scheduler/config","none","none","none","none","scheduled commands","none","none","none","none","command gate","no automatic finance claim","timezone/locking","audit","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","command gate","PHP","Schedule list unavailable"),
(376,"Scheduler Execution","php artisan schedule:run","CLI","scheduler runtime","scheduler policy","Artisan scheduler","controlled command","scheduler and command services","none","draw/finance models","controlled DB","none","scheduled jobs","none","none","none","none","scheduler config","no duplicate financial command","lock/duplicate-run protection","audit","BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE","scheduler plan","PHP/DB/queue","Scheduler unavailable"),
]

# Remaining pages use a compact per-page entry list while retaining all required matrix columns.
remaining_titles = {
377:("Lottery Automation Runtime","app/Console/Commands/Lottery/TickCommand.php","lottery.tick","CLI/scheduler","Lottery automation commands"),378:("Draw Scheduling Runtime","app/Services/Draw/DrawScheduleService.php","draw.schedule","service/CLI","DrawScheduleService"),379:("Draw Opening Runtime","app/Services/Draw/DrawLifecycleService.php","draw.open","service/API","DrawLifecycleService"),380:("Draw Closing Runtime","app/Http/Middleware/EnsureDrawIsOpen.php","draw.close","HTTP/CLI","EnsureDrawIsOpen; DrawLifecycleService"),381:("Draw Settlement Queue","app/Services/Draw/DrawSettlementSimulationService.php","draw.settlement","queue","draw settlement services"),382:("Result Import Runtime","app/Services/Draw/DrawResultIngestionService.php","result.import","CLI/API","DrawResultIngestionService; GloResultImportService"),383:("Historical Data Import Framework","app/Services/Lottery/Support/AbstractLotteryImportService.php","result.history.import","CLI/API","canonical import service family"),384:("Historical Data Provenance","app/Models/GloResultImport.php","result.provenance","service/API","result provenance models/services"),385:("Historical Import Rollback","app/Services/Draw/DrawResultIngestionService.php","result.history.rollback","service/CLI","canonical import/reconciliation services"),386:("National Lottery Historical Import","app/Services/Lottery","lottery.national.import","CLI/API","National lottery service family"),387:("Weekly Lottery Historical Import","app/Services/Lottery","lottery.weekly.import","CLI/API","Weekly lottery service family"),388:("PCSO Historical Import","app/Services/Lottery","lottery.pcso.import","CLI/API","PCSO service family"),389:("GLO L6 Historical Import","app/Services/Lottery/GloResultImportService.php","lottery.glo.history.import","CLI/API","GloResultImportService"),390:("Historical Result Reconciliation","app/Services/Draw/DrawReconciliationService.php","result.history.reconcile","service/CLI","DrawReconciliationService"),391:("Public Result API Runtime","routes/api.php","api.results","HTTP GET","public result controllers/services"),392:("Result Search Runtime","routes/api.php","api.results.search","HTTP GET","result search services"),393:("Result Detail Runtime","routes/api.php","api.results.detail","HTTP GET","result detail services"),394:("Year Archive Runtime","routes/web.php","results.archive","HTTP GET","lottery archive services"),395:("Result Publication Runtime","app/Services/Draw/DrawResultPublicationService.php","result.publish","service/API","DrawResultPublicationService"),396:("Result Correction Runtime","app/Services/Draw/DrawResultConfirmationService.php","result.correct","service/API","DrawResultConfirmationService"),397:("Result Conflict Runtime","app/Services/Draw/DrawResultValidator.php","result.conflict","service/API","DrawResultValidator"),398:("Result Certification Runtime","app/Services/Draw/DrawCertificationService.php","result.certify","service/CLI","DrawCertificationService"),399:("Public Cache Invalidation","app/Services/Draw/DrawResultPublicationService.php","result.cache.invalidate","event/queue","publication/cache services"),400:("Result Integrity Gate","RUNTIME-VERIFICATION-REPORT.md","result.integrity.gate","CLI/API","import/validate/certify/publish pipeline"),401:("Payment Provider Runtime Activation","app/Services/Payment/PaymentGatewayManager.php","payment.providers","HTTP/API","PaymentGatewayManager"),402:("Payment Method Availability","app/Services/Payment/PaymentProviderRegistry.php","payment.capabilities","HTTP GET","PaymentProviderRegistry"),403:("Deposit Initiation Runtime","app/Services/Payment/PaymentInitiationService.php","deposit.initiate","HTTP POST","PaymentInitiationService; DepositService"),404:("Deposit Callback Runtime","app/Services/Payment/PaymentCallbackService.php","deposit.callback","HTTP POST","PaymentCallbackService; PaymentWebhookService"),405:("Deposit Completion Runtime","app/Services/Finance/DepositCompletionService.php","deposit.complete","service","DepositCompletionService"),406:("Deposit Replay","tests/Feature/Payment/WebhookReplayProtectionTest.php","deposit.replay","PHPUnit","PaymentWebhookService; IdempotencyService"),407:("Deposit Mismatch","app/Services/Payment/PaymentVerificationService.php","deposit.mismatch","service/API","PaymentVerificationService"),408:("Payment Timeout Recovery","app/Services/Payment/PaymentInitiationService.php","payment.timeout","service/queue","payment provider adapters"),409:("Payment Failure Recovery","app/Services/Payment/PaymentCallbackService.php","payment.failure","service/API","PaymentCallbackService; FinancialReversalService"),410:("Payment Reconciliation","app/Services/Payment/PaymentReconciliationService.php","payment.reconcile","service/CLI","PaymentReconciliationService"),411:("Withdrawal Runtime Activation","app/Services/Finance/WithdrawalService.php","withdrawal.runtime","HTTP POST","WithdrawalService"),412:("Withdrawal Wallet Hold","app/Services/Finance/WalletHoldService.php","withdrawal.hold","service","WalletHoldService"),413:("Withdrawal Approval","app/Services/Finance/WithdrawalApprovalService.php","withdrawal.approve","service/API","WithdrawalApprovalService"),414:("Withdrawal Provider Transfer","app/Services/Payment/WithdrawalDisbursementService.php","withdrawal.transfer","service/API","WithdrawalDisbursementService"),415:("Withdrawal Provider Callback","app/Services/Payment/PaymentCallbackService.php","withdrawal.callback","HTTP POST","PaymentCallbackService; WithdrawalCompletionService"),416:("Withdrawal Replay","tests/Feature/Payment/WithdrawalCompletionTest.php","withdrawal.replay","PHPUnit","WithdrawalCompletionService; IdempotencyService"),417:("Withdrawal Failure","app/Services/Finance/WithdrawalCompletionService.php","withdrawal.failure","service/queue","WithdrawalCompletionService; FinancialReversalService"),418:("Withdrawal Reconciliation","app/Services/Finance/PayoutReconciliationService.php","withdrawal.reconcile","service/CLI","PayoutReconciliationService"),419:("Withdrawal Exception Queue","app/Services/Queue/QueueHealthService.php","withdrawal.exceptions","queue/admin","QueueHealthService; payout reconciliation"),420:("Financial End-to-End Gate","FINANCIAL-INTEGRITY-REPORT.md","finance.e2e","PHPUnit/CLI","canonical finance pipeline"),421:("Wallet Balance Runtime Audit","app/Services/Finance/FinancialReconciliationService.php","wallet.audit","CLI","FinancialReconciliationService; LedgerBalanceValidator"),422:("Financial Replay Audit","tests/Feature/Payment/WebhookReplayProtectionTest.php","finance.replay","PHPUnit","payment/bet/withdrawal idempotency services"),423:("Concurrent Financial Operations","tests/Feature/Betting/BetPurchaseAtomicityTest.php","finance.concurrency","PHPUnit","wallet locks/reservations/transactions"),424:("Financial Reconciliation Report","app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php","finance.report","CLI","FinancialReconciliationService"),425:("Financial Exception Resolution","app/Services/Finance/FinancialReconciliationService.php","finance.exceptions","service/admin","reconciliation discrepancy lifecycle"),426:("Bet Purchase Runtime","app/Services/Betting/BetPurchaseService.php","bet.purchase","HTTP POST","BetPurchaseService"),427:("Bet Price Enforcement","app/Services/Betting/BetCalculationService.php","bet.price","service/API","BetCalculationService"),428:("Bet Fee Enforcement","app/Services/Finance/FeeCalculationService.php","bet.fee","service/API","FeeCalculationService"),429:("Bet Responsible Gaming Gate","app/Services/Betting/BetPurchaseRiskService.php","bet.rg","service/API","BetPurchaseRiskService; ResponsibleGamingService"),430:("Bet Idempotency Runtime","app/Services/Betting/BetPurchaseIdempotencyService.php","bet.idempotency","service/API","BetPurchaseIdempotencyService"),431:("Bet Concurrency Runtime","tests/Feature/Betting/BetPurchaseAtomicityTest.php","bet.concurrency","PHPUnit","BetPurchaseTransactionService; WalletLockService"),432:("Ticket Issuance Runtime","app/Services/Betting/BetPurchaseTicketService.php","ticket.issue","service/API","BetPurchaseTicketService"),433:("Ticket Ownership Runtime","app/Services/Ticket/TicketOwnershipService.php","ticket.owner","service/API","TicketOwnershipService"),434:("Ticket Verification Runtime","app/Services/Betting/TicketVerificationService.php","ticket.verify","HTTP GET/POST","TicketVerificationService"),435:("Ticket QR Runtime","app/Services/Betting/TicketShareService.php","ticket.qr","HTTP/API","TicketShareService; TicketVerificationService"),436:("Prize Matching Runtime","app/Services/Draw/SelectionSettlementResolver.php","prize.match","service","SelectionSettlementResolver"),437:("Prize Settlement Runtime","app/Services/Draw/RealPrizeSettlementService.php","prize.settlement","service/API","RealPrizeSettlementService"),438:("Prize Payout Runtime","app/Services/Finance/PayoutApprovalService.php","prize.payout","service/API","PayoutApprovalService; PayoutBatchService"),439:("Prize Payout Replay","tests/Feature/Glo/GloPrizeClaimTest.php","prize.payout.replay","PHPUnit","payout idempotency services"),440:("GLO L6 Purchase Capability Reassessment","app/Services/Lottery/GloL6PurchaseCapabilityService.php","glo.l6.capability","API","GloL6PurchaseCapabilityService; GloL6SalesService"),441:("GLO L6 Ticket Engine Runtime","app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php","glo.l6.ticket","service/API","GloL6AuthoritativeTicketEngineService"),442:("GLO L6 Prize Calculator Runtime","app/Services/Lottery/GloL6ProportionalPrizeCalculator.php","glo.l6.prize","service","GloL6ProportionalPrizeCalculator"),443:("GLO Claim Runtime","app/Services/Lottery/GloPrizeClaimService.php","glo.claim","HTTP POST","GloPrizeClaimService"),444:("GLO Claim Security","app/Services/Compliance/KycVerificationService.php","glo.claim.security","service/API","GloPrizeClaimService; KycVerificationService"),445:("GLO Freeze Runtime","app/Services/Lottery/GloTicketFreezeService.php","glo.freeze","service/API","GloTicketFreezeService"),446:("GLO Freeze Expiry","app/Console/Commands/GloExpireFreezes.php","glo.freeze.expiry","CLI","GloExpireFreezes"),447:("GLO Frozen Winner Processing","app/Console/Commands/GloProcessFrozenWinners.php","glo.frozen.winners","CLI","GloProcessFrozenWinners; GloFrozenWinnerService"),448:("GLO Public Publication","app/Services/Lottery/GloResultPublicationService.php","glo.publish","API/CLI","GloResultPublicationService"),449:("GLO End-to-End Integrity","LOTTERY-INTEGRITY-REPORT.md","glo.e2e","CLI/PHPUnit","GLO ticket/result/claim/freeze/payout pipeline"),450:("Enterprise Production Acceptance Gate","scripts/pages_351_450_command_gate.py","enterprise.acceptance","CLI","full runtime/finance/lottery/security/ops gate"),
}

for index, source_row in enumerate(items):
    row = list(source_row)
    if len(row) == 26:
        row.insert(24, "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE")
    if len(row) != 27:
        raise RuntimeError(f"Page {row[0]} does not have 27 audit columns")
    items[index] = row
rows: list[list[object]] = [list(row) for row in items]
for page, title, target, route_name, method, service in [(n, *remaining_titles[n]) for n in range(377, 451)]:
    special_status = "NOT_CONFIGURED" if page == 440 else ("PARTIALLY VERIFIED" if page == 351 else "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE")
    special_runtime = "PARTIALLY VERIFIED — PRE-FLIGHT ONLY" if page == 351 else ("NOT_CONFIGURED" if page == 440 else "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE")
    tests = "Existing canonical tests and runtime command gate" if page >= 364 else "Page 351–450 runtime command gate and static contract"
    if page == 450:
        tests = "runtime/pages-351-450-command-results.json; CI source inspection"
    if page == 440:
        gap = "Re-evaluate only after a real canonical purchase capability and provider are configured; do not invent checkout."
    elif page == 351:
        gap = "PHP, Composer, database, Redis, queue, browser, provider, and Rust runtime remain unavailable."
    else:
        gap = "The underlying runtime operation cannot be observed until the required environment or external dependency is available."
    controller = service
    request = "Canonical request/DTO or bounded command input"
    dto = "Canonical DTOs where present"
    model = "Canonical models where present"
    database = "Canonical database tables where present"
    api = "Canonical API or CLI boundary where present"
    event = "Canonical job/event where present"
    view = "Existing canonical view or N/A"
    js = "Existing frontend or N/A"
    css = "Existing stylesheet or N/A"
    translation = "Existing translation namespace or N/A"
    source = target
    financial = "No financial success or mutation is claimed without execution."
    security = "Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative."
    audit = "AuditLog, runtime artifact, or CI evidence where applicable"
    if 351 <= page <= 376:
        middleware = "runtime/CLI/DB/queue gate"
    elif 377 <= page <= 400:
        middleware = "draw/result/provider/lottery boundary"
    elif 401 <= page <= 425:
        middleware = "payment/finance/provider/queue boundary"
    elif 426 <= page <= 449:
        middleware = "bet/ticket/prize/GLO/security boundary"
    else:
        middleware = "enterprise acceptance boundary"
    authorization = "Canonical operator, owner, provider, or process authorization"
    if page in (351, 352, 353, 354, 355, 356, 357, 358, 359, 360, 361, 362, 363, 367, 370, 371, 373, 375, 376, 382, 383, 385, 390, 398, 400, 450):
        authorization = "CLI/process/environment boundary"
    rows.append([
        page, title, target, method, middleware, authorization, controller, request, service, dto, model, database, api, event,
        view, js, css, translation, source, financial, security, audit, special_status, tests, special_runtime,
        "PHP/Laravel/DB/queue/provider/browser/Rust as applicable", gap,
    ])

if len(rows) != 100 or [int(r[0]) for r in rows] != list(range(351, 451)):
    raise RuntimeError("Pages 351–450 requires exactly 100 ordered rows.")

lines = ["", "## Pages 351–450 production runtime audit matrix", "", "| " + " | ".join(COLUMNS) + " |", "|" + "---|" * len(COLUMNS)]
for values in rows:
    lines.append("| " + " | ".join(str(value).replace("|", "/").replace("\n", " ") for value in values) + " |")
AUDIT.write_text(EXISTING + "\n".join(lines) + "\n", encoding="utf-8")
print("Appended 100 Pages 351–450 audit rows.")
````

### `scripts/pages_351_450_matrices.py`
# TYPE: Python script
# PURPOSE: Generator for the Pages 351–450 acceptance and integrity matrices.

````python
# TYPE: Python matrix document generator
# PURPOSE: Generate the Pages 351–450 matrix artifact with exactly 100 page rows and operational integrity matrices.

from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
AUDIT = (ROOT / "audit.md").read_text(encoding="utf-8")
section = AUDIT.split("## Pages 351–450 production runtime audit matrix", 1)[1].split("## Cumulative coverage register through Pages 1–450", 1)[0]
raw_rows = [line for line in section.splitlines() if line.startswith("| ") and not line.startswith("| Page |")]
rows = []
for line in raw_rows:
    cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
    if cells and cells[0].isdigit():
        rows.append(cells)
if len(rows) != 100 or [int(row[0]) for row in rows] != list(range(351, 451)):
    raise RuntimeError("Matrix requires exactly one ordered row for every Page 351–450.")

out: list[str] = [
    "# TYPE: Pages 351–450 production runtime matrices",
    "# PURPOSE: Map every acceptance page to its execution target and preserve route/API/security/finance/lottery/Rust/runtime boundaries.",
    "",
    "## Acceptance page ledger",
    "",
    "| Page | Title | Route / Command / Test Target | Status | Runtime Status | Remaining Gap |",
    "|---:|---|---|---|---|---|",
]
for row in rows:
    out.append(f"| {row[0]} | {row[1]} | {row[2]} | {row[22]} | {row[24]} | {row[26]} |")

out.extend([
    "",
    "## Route and API matrix",
    "",
    "| Page range | Boundary | Required evidence | Status |",
    "|---|---|---|---|",
    "| 351–357 | PHP, Composer, Laravel boot, route listing, configuration and cache commands | Execute CLI and inspect route/middleware/cache output | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 358–376 | Database, migrations, Redis, queue, scheduler | Connect controlled services; run schema, transaction, lock, worker, retry, and scheduler commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 377–400 | Draw lifecycle, result import, historical provenance, certification, publication, correction, conflict, public APIs | Execute canonical draw/result pipeline with genuine source data or isolated test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 401–425 | Payment providers, deposits, callbacks, withdrawals, payout, reconciliation, wallet/ledger | Execute configured provider callbacks, replay/mismatch/failure cases, and ledger reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 426–439 | Bet, responsible gaming, idempotency, concurrency, ticket, prize, payout | Execute server-authoritative purchase-to-settlement flows with controlled test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 440–449 | GLO capability, ticket engine, prize, claim, age/KYC/freeze/publication | Reassess capability and execute only canonical GLO services; no invented checkout | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 450 | Enterprise gate | Require every underlying evidence package before acceptance | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Security matrix",
    "",
    "| Control | Pages | Evidence required | Status |",
    "|---|---:|---|---|",
    "| Authentication, session rotation, MFA, revocation | 351–357, 401–449 | PHP/browser login, session, MFA, replay, and revocation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| CSRF, IDOR, ownership, authorization | 355, 391–394, 401–449 | Guest/role/cross-owner/cross-case/cross-ticket denial tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Webhook signature, replay, mismatch, timeout | 401–418 | Provider sandbox callback matrix and idempotency assertions | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Rate limits and abuse controls | 391–394, 401–449 | Throttled endpoint and retry metadata tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Secret/log/error redaction | 351–357, 400, 450 | Runtime logs and error responses contain no secret or unsafe internal data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| KYC, age, freeze, responsible gaming | 411–413, 426–449 | Denial/allowance tests for financial and GLO sensitive states | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Financial integrity matrix",
    "",
    "| Pages | Flow | Required invariant | Status |",
    "|---:|---|---|---|",
    "| 358–366 | Database and transaction | Exact money, atomicity, constraints, isolation, rollback, no double spend | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 367–376 | Redis, queue, scheduler | Lock ownership, idempotent retry, failed-job recovery, single scheduled effect | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 401–410 | Deposits | Verified provider event before one wallet credit; replay/mismatch rejection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 411–419 | Withdrawals | Hold before payout, authorization, one transfer, exact reversal/reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 420–425 | End-to-end finance | Wallet/ledger balances and reconciliation remain consistent | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 426–439 | Bets and prizes | Server price, fee, RG gate, idempotency, one ticket, one settlement/payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Lottery integrity matrix",
    "",
    "| Pages | Pipeline | Required invariant | Status |",
    "|---:|---|---|---|",
    "| 377–381 | Draw lifecycle | Timezone, cutoff, state transition, settlement dispatch, duplicate prevention | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 382–390 | Import and provenance | Genuine source, exact values, duplicate/conflict detection, versioned rollback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 391–400 | Public result surface | Certified/published only, bounded search, safe correction and cache invalidation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 426–439 | Bet/ticket/prize | Ticket ownership, verification, matching, settlement, one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 440–449 | GLO L6 | Canonical capability, exact ticket/prize calculation, claims, freeze, publication | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Rust integrity matrix",
    "",
    "| Page | Boundary | Required evidence | Status |",
    "|---:|---|---|---|",
    "| 351 | Cargo/rustc preflight | Executable availability and version | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 400 | Result/integrity boundary | Rust output validated before canonical publication | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 420 | Financial acceptance | No Rust output may authorize money without Laravel validation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 449 | GLO integrity | Leading-zero and malformed-input vectors through the real boundary | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| 450 | Release build | `cargo check --locked`, `cargo test --locked`, `cargo build --release --locked` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "",
    "## Runtime and release matrix",
    "",
    "| Area | Evidence artifact | Result |",
    "|---|---|---|",
    "| Dependency preflight | `runtime/page-351-preflight.json` | PARTIALLY VERIFIED — preflight executed; required runtimes missing |",
    "| Command gate | `runtime/pages-351-450-command-results.json` | 1 command executed successfully; 25 blocked |",
    "| NPM remediation | `runtime/page-351-dependency-remediation.json` | VERIFIED locally: `npm ci`, `npm audit`, and `npm run build` exit 0 |",
    "| PHP/Laravel | `RUNTIME-VERIFICATION-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Database | `DATABASE-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Finance | `FINANCIAL-INTEGRITY-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Lottery/GLO | `LOTTERY-INTEGRITY-REPORT.md` | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Rust | `RUST-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| Security | `SECURITY-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
    "| CI/CD source | `CI-CD-VERIFICATION-REPORT.md` | PARTIALLY VERIFIED — workflow source updated; hosted run not executed |",
    "| Enterprise acceptance | Page 450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |",
])

(ROOT / "PAGES-351-450-MATRICES.md").write_text("\n".join(out) + "\n", encoding="utf-8")
print("Generated PAGES-351-450-MATRICES.md with 100 page rows.")
````

### `audit.md`
# TYPE: Markdown audit
# PURPOSE: Cumulative audit retaining historical coverage, adding one row for each Page 351–450, and recording the Pages 1–450 coverage register.

````markdown
# Pages 44–70 Implementation and Hardening Audit

**Audit date:** 2026-09-30
**Local timezone:** Asia/Dhaka
**Scope:** GLO L6 Pages 44–50 and authenticated member Pages 51–70
**Runtime status:** `NOT VERIFIED — RUNTIME UNAVAILABLE`
**Production readiness:** Not declared

## Evidence boundary

The repository has no PHP interpreter, Composer vendor directory, Laravel application runtime, database connection, browser runner, or configured external payment provider in this workspace. PHP files were parsed with the installed JavaScript `php-parser` package as a static syntax aid. This is not a Laravel boot, dependency-resolution, migration, route-list, Blade compilation, database, browser, payment-provider, or production verification.

The final frontend asset build was executed after adding the existing React component dependencies required by the repository's Vite entry graph:

```text
npm run build
vite v5.4.21 building for production
✓ 168 modules transformed.
✓ built in 3.92s
```

`npm ci`/`npm install` reported two dependency audit findings: one moderate and one high. No automatic force upgrade was applied.

The first asset-build attempt failed because `react` was not resolvable from `resources/js/components/WalletManagement.tsx`. `react` and `react-dom` were added to `package.json` and `package-lock.json`; the subsequent build passed. Generated `public/build` output is excluded from the persisted workspace snapshot.

## Acceptance decision

The implementation is not production-ready. The exact runtime status is:

```text
NOT VERIFIED — RUNTIME UNAVAILABLE
```

This status applies to runtime behavior, authentication, authorization, CSRF, throttling, CAPTCHA, database ownership, payment initiation, gateway callbacks, wallet reservation, ledger posting, responsible-gaming enforcement, KYC gates, grade evaluation, accessibility behavior, responsive browser behavior, route listing, Blade compilation, Laravel service-container resolution, migrations, and automated PHP tests.

## Page matrix

| Page | Route | Controller and canonical source | Financial or identity behavior | Status and finding |
|---|---|---|---|---|
| 44 | `glo-l6.index` | `GloL6Controller::index`; `GloL6HomeService`, `GloPublicHomeService`, purchase capability service | Read-only canonical projections. No purchase mutation. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Existing page retained and hardened; no duplicate home was created. |
| 45 | `glo-l6.buy` | `GloL6Controller::buy`; `GloL6PurchaseCapabilityService` | Purchase remains disabled with `NOT_CONFIGURED`; no price, selection, wallet, ticket, ledger, or idempotency mutation is advertised. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Fail-closed behavior is statically present. |
| 46 | `glo-l6.latest` | `GloL6Controller::latestResult`; `GloPublicResultService` | Published result projection only; unavailable source returns an unavailable state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No fabricated result values were added. |
| 47 | `glo-l6.history` | `GloL6Controller::history`; bounded canonical history query | Read-only paginated result rows and provenance state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. History is bounded by configured window and page size. |
| 48 | `glo-l6.year` | `GloL6Controller::year`; canonical history service | Year is accepted only inside configured history window and route is constrained to four digits. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime boundary and data query remain unverified. |
| 49 | `glo-l6.draw` | `GloL6Controller::drawDetail`; canonical draw/result projection | Read-only draw detail. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Collision-safe route pattern is present. |
| 50 | `glo-l6.result` | `GloL6Controller::resultDetail`; canonical result projection | Read-only result detail with provenance. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No result is claimed when the source is unavailable. |
| 51 | `login` | `MemberAuthController`; canonical login service | Session authentication, CAPTCHA/throttle contract remains delegated to existing auth architecture. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No duplicate auth surface created. |
| 52 | `register` | `MemberAuthController`; canonical registration service | Authenticated identity is created only through existing registration flow. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime and CAPTCHA gates not executable. |
| 53 | `password.request` | `MemberAuthController`; canonical password-reset request service | Reset-token flow remains canonical and throttled. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Token security and mail delivery not runtime-tested. |
| 54 | `password.reset` | `MemberAuthController`; canonical password-reset service | Token-gated reset remains canonical. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime not available. |
| 55 | `player.dashboard` | `PlayerWebController::dashboard`; `User`, `Wallet`, `Draw`, `Bet`, `FinancialTransaction` | Owner-scoped records only. Exact `Money` formatting is used for wallet and wager amounts. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No fabricated player, wallet, draw, or wager rows are inserted by the page. |
| 56 | `player.draws` | `PlayerWebController::draws`; `Draw` and result relations | Real draw schedule and published result fields. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Draw fields and pagination require Laravel runtime verification. |
| 57 | `player.draws.detail` | `PlayerWebController::drawDetail`; owner-independent public draw read model | Real draw/result relation. Missing result displays a translated pending state. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime and view compilation unverified. |
| 58 | `player.bet` and `player.bets.purchase` | `PlayerWebController::betSlip`, `BetPurchaseController`; `BulkBetService` | Purchase submits a public draw reference, resolves the canonical draw server-side, validates decimal stakes without floating-point parsing, and delegates to the canonical bulk betting service. The endpoint does not fabricate a success response when all items are refused. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Complete product, price, wallet, reservation, ledger, RG, and idempotency contract is not runtime-verified. |
| 59 | `player.bets` | `PlayerWebController::bets`; owner-scoped `Bet` query | Uses authenticated user ownership and canonical ticket/draw/item relations. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Presentation no longer invents ticket or draw references. |
| 60 | `player.wallet` | `PlayerWebController::wallet`; `Wallet`, `FinancialTransaction`, `Money` | Owner-scoped wallet and transaction journal. Decimal aggregates are reduced through `Money` rather than a floating-point PHP aggregate. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Database and ledger state not executable. |
| 61 | `player.deposit`, `player.deposit.store` | `PlayerWebController`; `PaymentInitiationService` and its canonical `DepositService::request` orchestration | Gateway-capable configured methods only. Deposit initiation now calls `PaymentInitiationService::initiateDeposit(Wallet, Money, PaymentMethod, key, options)` using the configured finance currency, exact decimal validation, and a constrained idempotency key. Wallet credit still requires canonical callback/completion. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Provider capability, gateway callback, and transaction behavior remain unverified. |
| 62 | `player.deposit.status` | `PlayerWebController::depositStatus`; owner-scoped `Deposit` query | Reads only the authenticated owner's deposit by reference or UUID. Status view distinguishes pending/provider state from wallet credit. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. New route/view is statically present; runtime ownership and model resolution are unverified. |
| 63 | `player.withdraw`, `player.withdraw.store` | `PlayerWebController`; canonical `WithdrawalService` and `WalletHoldService` | Gateway-capable payout methods only. Exact configured-currency validation and available-balance arithmetic use `Money`. Requests remain pending without a browser-side hold; canonical approval owns reservation and downstream payout/ledger transitions. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. KYC, RG, balance, hold, approval, payout, and ledger behavior remain unverified. |
| 64 | `player.withdrawal.status` | `PlayerWebController::withdrawalStatus`; owner-scoped `Withdrawal` query | Reads only the authenticated owner's request and does not expose encrypted payout details. Recent history links to the owner-scoped status route. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. New route/view is statically present; runtime not available. |
| 65 | `player.profile` | `PlayerWebController`; authenticated `User` and responsible-gaming limit record | Profile update derives ownership from session and preserves password and responsible-gaming routes. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. User model, validation and CSRF are not runtime-tested. |
| 66 | `player.security`, `player.settings` | `PlayerSecuritySettingsController`; canonical account verification service, security session records, responsible-gaming service | KYC status is read through `AccountVerificationService::publicStatus`; active sessions are owner-scoped, active, and unexpired; self-exclusion reads the canonical self-exclusion service. Unsupported compatibility mutations return `NOT_CONFIGURED`. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Container resolution and security-session schema are not executable. |
| 67 | `settings.index`, `member.settings`, `player.settings.portal` | `PlayerWebController::responsibleGaming`; canonical responsible-gaming and self-exclusion services | Limit updates use canonical responsible-gaming service. Self-exclusion now requests and activates a canonical `SelfExclusion` record and stamps the legacy limit lane through the existing engine. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Server clock, database transition, and enforcement gates are unverified. |
| 68 | `account.verification` | `MemberAccountVerificationController`; canonical `AccountVerificationService`, private document services, and opaque owner-scoped download tokens | Owner-scoped KYC status and document metadata; internal user/document numeric IDs are not rendered or placed in download URLs; document downloads remain owner-authorized and private. The retired duplicate root controller, alias, and view were removed from the active architecture. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. No private document or KYC runtime test can run. |
| 69 | `account.grade` | `AccountGradeController`; `AccountGradeService`, evaluator and discount projection | Uses server-computed grade, qualifying spend, entitlement projection, and canonical history. Monetary spend is formatted with `Money`; no hardcoded ticket price fallback remains in the view. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Grade calculations and database snapshots are unverified. |
| 70 | `account.grade.history` | `AccountGradeController::history`; canonical `AccountGradeService::history` | Browser request renders the authenticated user's canonical history view; JSON clients retain the JSON response when `expectsJson()` is true. | `NOT VERIFIED — RUNTIME UNAVAILABLE`. Runtime, route, and JSON negotiation not executable. |

## Finance and responsible-gaming findings

1. The Page 61 defect was corrected. `PlayerWebController::storeDeposit()` no longer calls the nonexistent `DepositService::initiate()` method. It now calls the inspected canonical `PaymentInitiationService::initiateDeposit()` contract and reads its array return values.
2. The deposit flow does not treat a redirect, provider reference, pending state, or manual instruction as proof of wallet credit. The wallet changes only through the canonical completion/callback path.
3. Deposit and withdrawal payment-method projections reject enum values without a configured, enabled, capability-backed gateway. Unsupported configured methods are not advertised.
4. Withdrawal balance display and configured-currency amount validation use exact `Money` arithmetic; the withdrawal form has no fabricated monetary default and no duplicate browser-side reservation.
5. The account-verification surface no longer exposes internal numeric user/document IDs. Owner download URLs use opaque HMAC tokens and the controller resolves them only within the authenticated owner scope.
6. Player self-exclusion was aligned to the canonical `Compliance\SelfExclusionService` bridge and `ResponsibleGaming\SelfExclusionService` engine. The security/API, settings compatibility, and browser form paths now use `SelfExclusionData`, request the canonical row, and activate it through the engine.
7. Unsupported settings mutations remain fail-closed with `NOT_CONFIGURED`; no MFA, notification, LINE, PIN, or security preference mutation claims success without an inspected backend contract.
8. Pages 62 and 64 are owner-scoped status views. They do not reveal another user's records and do not expose encrypted withdrawal payout details.
9. Public GLO L6 purchase remains `NOT_CONFIGURED`; no checkout, wallet debit, ticket issuance, reservation, or ledger mutation was invented.

## Localization and UI checks

| Resource | EN keys | TH keys | Result |
|---|---:|---:|---|
| `lang/en/player.php` / `lang/th/player.php` | 277 | 277 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/glo_l6.php` / `lang/th/glo_l6.php` | 107 | 107 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/account_services.php` / `lang/th/account_services.php` | 207 | 207 | Exact key and placeholder parity confirmed by a repository script. |
| `lang/en/account_info.php` / `lang/th/account_info.php` | 76 | 76 | Exact key and placeholder parity confirmed by a repository script. |

Changed player and account views use the dark/gold/glass classes and translated labels. Financial values use the existing exact-money value object. The browser accessibility gate, reduced-motion behavior, focus rendering, small-mobile layout, and assistive-technology output remain `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Static and build evidence

| Gate | Evidence | Result |
|---|---|---|
| PHP parser pass | 319 existing tracked/untracked PHP files parsed with `php-parser` after removing the retired duplicate account-verification controller/view | Static parser pass; not a PHP runtime check |
| Vite asset build | `npm run build` after the final Pages 44–70 edits | Passed |
| Translation parity | EN/TH key-set and placeholder comparison for player, GLO L6, account services, and account-info resources | Passed |
| `git diff --check` | Executed after the final whitespace cleanup | Passed |
| Static route/deletion scan | No active route references the retired root verification controller/view; the member verification route uses the canonical Verification controller and opaque document-token parameter | Passed |
| Fixture/fallback scan | No known fixture identity/financial markers, `number_format()` money output, or internal account/document IDs were found in the hardened owner-facing projections/responses | Passed |
| Laravel route list | PHP runtime unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Blade compilation | PHP runtime and Composer vendor unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| PHPUnit/Pest | PHP runtime and Composer vendor unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Database migrations and ownership tests | Database/runtime unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Browser and accessibility audit | Browser runner unavailable | `NOT VERIFIED — RUNTIME UNAVAILABLE` |
| Payment-provider tests | No configured provider/runtime | `NOT VERIFIED — RUNTIME UNAVAILABLE` |

## Changed-file manifest for this Pages 44–70 hardening pass

Each entry includes the path, file type, and purpose. Complete file contents remain in the workspace at these exact paths and in the corresponding implementation report.

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/Web/PlayerWebController.php` | PHP controller | Canonical owner-scoped player pages; corrected deposit orchestration; added deposit and withdrawal status views; exact configured-currency validation and wallet aggregation; canonical self-exclusion. |
| `app/Http/Controllers/Web/BetPurchaseController.php` | PHP controller | Resolves a public draw reference to the canonical draw server-side and delegates exact-decimal bet selections to `BulkBetService`; no internal draw ID is accepted from the browser. |
| `app/Http/Requests/Web/BetPurchaseRequest.php` | PHP form request | Retained compatibility validation with public draw references and exact decimal stake strings. |
| `app/Http/Requests/Web/DepositRequest.php` | PHP form request | Retained compatibility validation with configured payment currency/limits and exact decimal deposit strings. |
| `app/Http/Requests/Web/WithdrawRequest.php` | PHP form request | Retained compatibility validation with configured payment currency/limits and exact decimal withdrawal strings. |
| `app/Http/Controllers/Verification/AccountVerificationController.php` | PHP controller | Canonical member verification orchestration; owner-scoped opaque document-token downloads; reviewer decisions remain policy-walled. |
| `app/Http/Controllers/Api/V1/AuthController.php` | PHP controller | Authenticated API identity projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Api/V1/MeController.php` | PHP controller | Authenticated account projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Api/V1/ProfileController.php` | PHP controller | Authenticated profile projection and mutation responses without exposing the internal numeric user key; translated API messages. |
| `app/Http/Resources/UserResource.php` | PHP API resource | Authenticated/public-safe user projection without exposing the internal numeric user key. |
| `app/Http/Controllers/Player/PlayerSecuritySettingsController.php` | PHP controller | Authenticated security, KYC, session, responsible-gaming limit, and canonical self-exclusion adapter. |
| `app/Http/Controllers/Player/LotteryHistoryPortalController.php` | PHP controller | Replaced fixture history/slip behavior with owner-scoped canonical Bet/Draw/Ticket/BetItem projections, canonical cancellation, and fail-closed re-bet. |
| `app/Http/Controllers/Player/PlayerDashboardController.php` | PHP controller | Compatibility dashboard projection without internal numeric draw/bet IDs and with translated fail-closed messages. |
| `app/Http/Controllers/Player/PlayerProfilePortalController.php` | PHP controller | Compatibility profile adapter with translated fail-closed unsupported mutations and session-owned canonical profile delegation. |
| `app/Http/Controllers/Player/PlayerSettingsPortalController.php` | PHP controller | Compatibility settings adapter with translated API messages and canonical responsible-gaming/self-exclusion transitions. |
| `resources/views/player/history-portal.blade.php` | Deleted Blade view | Removed the fixture-based duplicate history portal; `/history` compatibility paths now redirect to canonical `player.bets`. |
| `app/Http/Controllers/AccountVerificationController.php` | Deleted PHP controller | Removed the unrouted duplicate root verification controller; the Verification namespace controller is the sole active member path. |
| `app/Http/Controllers/Web/AccountVerificationController.php` | Deleted PHP controller alias | Removed the unrouted duplicate web verification alias. |
| `resources/views/account/verification.blade.php` | Deleted Blade view | Removed the unrouted duplicate hardcoded verification page; the canonical `account-verification.index` view is the sole active member surface. |
| `resources/views/player/profile-portal.blade.php` | Deleted Blade view | Removed an unused duplicate profile portal view; profile compatibility is API-only and browser paths redirect to canonical profile. |
| `resources/views/player/settings-portal.blade.php` | Deleted Blade view | Removed an unused duplicate settings portal view; browser paths use canonical security/responsible-gaming surfaces. |
| `resources/views/player/verification.blade.php` | Deleted Blade view | Removed an unused duplicate verification view; authenticated verification uses the canonical account verification controller. |
| `app/Http/Controllers/AccountGradeController.php` | PHP controller | Canonical account-grade browser history view with JSON compatibility for JSON clients. |
| `app/Models/AccountVerificationDocument.php` | PHP model projection | Owner-safe KYC document metadata projection with no exposed internal document ID. |
| `app/Services/Account/AccountVerificationService.php` | PHP service | Canonical owner KYC facade; removes internal account-number output and produces/validates opaque owner-scoped document download tokens. |
| `app/Services/Account/AccountVerificationDocumentService.php` | PHP service | Private KYC document storage/read projection with a generic download filename that does not reveal an internal document ID. |
| `app/Services/Verification/AccountVerificationService.php` | PHP service | Canonical member verification aggregate wrapper; exposes the owner-token lookup while preserving KYC state transitions and audit behavior. |
| `app/Services/Verification/DocumentStorageService.php` | PHP service | Private document storage/read contract with a generic content-disposition filename and no numeric ID disclosure. |
| `app/DTOs/ResponsibleGaming/SelfExclusionData.php` | Existing canonical PHP DTO | Server-pronounced self-exclusion request data; consumed by the hardened player paths. |
| `app/Services/Compliance/SelfExclusionService.php` | Existing canonical PHP service | Owner-scoped bridge used for current/active self-exclusion and transitions. |
| `app/Services/ResponsibleGaming/SelfExclusionService.php` | Existing canonical PHP service | Existing request/activation engine used by the new adapters; no duplicate engine created. |
| `app/Services/Payment/PaymentInitiationService.php` | Existing canonical PHP service | Inspected deposit orchestration contract reached by Page 61. |
| `app/Services/Finance/DepositService.php` | Existing canonical PHP service | Inspected request/create deposit contract; nonexistent `initiate()` call removed. |
| `resources/views/glo-l6/index.blade.php` | Blade view | Existing Page 44 home hardening; translated fail-closed purchase reason. |
| `resources/views/glo-l6/buy.blade.php` | Blade view | Page 45 fail-closed ticket-selection boundary with translated missing-contract states. |
| `resources/views/glo-l6/result.blade.php` | Blade view | Pages 46, 49, and 50 canonical result projection with translated unavailable messaging. |
| `resources/views/glo-l6/history.blade.php` | Blade view | Pages 47 and 48 bounded history/archive presentation. |
| `resources/views/player/dashboard.blade.php` | Blade view | Page 55 authenticated dashboard; exact money formatting and translated state fallback. |
| `resources/views/player/draws.blade.php` | Blade view | Page 56 real draw schedule/results view; corrected canonical close field and translated empty states. |
| `resources/views/player/draw-detail.blade.php` | Blade view | Page 57 real draw detail using actual result arrays and pending state. |
| `resources/views/player/bets.blade.php` | Blade view | Page 59 owner history without fabricated ticket/draw references. |
| `resources/views/player/wallet.blade.php` | Blade view | Page 60 exact wallet/ledger display and enum-safe transaction type projection. |
| `resources/views/player/deposit.blade.php` | Blade view | Page 61 capability-backed deposit form, exact limits, and status links. |
| `resources/views/player/withdraw.blade.php` | Blade view | Page 63 capability-backed withdrawal form, exact available balance, and history links. |
| `resources/views/player/withdrawal-status.blade.php` | Blade view | Page 64 owner-scoped withdrawal status/history detail without payout secrets, stored currency fallback refusal, and translated status/method labels. |
| `resources/views/player/profile.blade.php` | Blade view | Page 65 translated profile, password, and limit forms without fabricated limit placeholders. |
| `resources/views/account-verification/index.blade.php` | Blade view | Canonical Page 68 authenticated/public verification surface with translated public guide copy, owner-safe status/document projections, and opaque download-token links. |
| `resources/views/player/bet.blade.php` | Blade view | Page 58 fail-closed bet slip using a public draw reference rather than an internal draw ID and exact client-side cent totals. |
| `resources/views/components/account/verification-status.blade.php` | Blade component | Owner-safe verification summary with translated unavailable identity fields. |
| `resources/views/components/account/document-upload.blade.php` | Blade component | Canonical document-upload placeholder using translated unavailable state. |
| `resources/views/player/deposit-status.blade.php` | Blade view | Page 62 owner-scoped deposit/payment-intent status with stored currency, exact Money formatting, and translated status/method labels. |
| `resources/views/player/security.blade.php` | Blade view | Page 66 translated KYC, active session, self-exclusion, and security action view. |
| `resources/views/player/responsible-gaming.blade.php` | Blade view | Page 67 canonical limits and self-exclusion form without fabricated input defaults. |
| `resources/views/account/grade.blade.php` | Blade view | Page 69 exact grade/spend display and full-history link; removed fallback ticket prices. |
| `resources/views/account/grade-history.blade.php` | Blade view | Page 70 canonical owner grade history view with exact-money qualifying spend. |
| `resources/views/components/account/grade-card.blade.php` | Blade component | Exact-money grade spend/progress presentation and no fabricated Bronze/zero fallback labels. |
| `routes/web.php` | PHP route file | Added owner-scoped Page 62/64 status routes, switched member verification downloads to opaque token parameters, removed the unrouted duplicate verification-controller import, and retained auth/throttle/legacy route boundaries. |
| `lang/en/player.php` | PHP translation map | English player/status/security/deposit/withdrawal/self-exclusion keys. |
| `lang/th/player.php` | PHP translation map | Thai parity for the same player/status/security/deposit/withdrawal/self-exclusion keys. |
| `lang/en/glo_l6.php` | PHP translation map | English GLO L6 fail-closed contract labels. |
| `lang/th/glo_l6.php` | PHP translation map | Thai parity for GLO L6 fail-closed contract labels. |
| `lang/en/account_services.php` | PHP translation map | English grade-history and grade display keys; removed hardcoded price claims. |
| `lang/th/account_services.php` | PHP translation map | Thai parity for grade-history and grade display keys. |
| `lang/en/account_info.php` | PHP translation map | English public verification-guide and navigation copy with exact placeholder parity. |
| `lang/th/account_info.php` | PHP translation map | Thai parity for public verification-guide and navigation copy. |
| `package.json` | JSON dependency manifest | Added React runtime dependencies required by the existing Vite WalletManagement component. |
| `package-lock.json` | JSON lockfile | Locked React runtime dependencies and retained the project lockfile name. |
| `audit.md` | Markdown audit report | This page matrix, evidence boundary, findings, status ledger, and changed-file manifest. |

## Limitations and remaining findings

- The PHP runtime and Composer dependencies are unavailable, so no Laravel route list, Blade compiler, service-container resolution, migration, controller test, or browser request was executed.
- The existing repository contains a broad set of prior changes outside the focused files above. This audit does not convert those unrelated historical changes into new architecture.
- The payment providers, database, queue workers, callback signing keys, mail transport, CAPTCHA provider, and browser session are unavailable in the workspace.
- The Vite dependency audit still reports one moderate and one high vulnerability. No force upgrade was applied because the compatible remediation was not runtime-tested.
- Production readiness remains prohibited until the runtime, finance, security, localization, accessibility, build, and complete test gates are executed in an environment with PHP, Composer, database, and configured services.
## Pages 77–100 independent audit matrix

The following rows are independent page records. Static source review and edits are recorded; no Laravel, PHP, database, browser, provider, or full-test runtime gate is claimed.

| PAGE | ROUTE | ROUTE NAME | CONTROLLER | SERVICE | REQUEST | MODEL | DATABASE | API | VIEW | JS | CSS | TRANSLATION | SECURITY | DATA SOURCE | AUTHORIZATION | STATUS | TESTS | RUNTIME STATUS | REMAINING GAP |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 77 | `/results` | `results.index` | `GloResultsPageController` | `ResultsPageService` | none | `Draw`, `DrawResult` | published draw/result projection | `/api/v1/glo/latest-draw` | `results/index.blade.php` | none required | existing app/theme styles | `results.php` EN/TH | public-safe source state; no fixture claims | canonical published rows | anonymous public projection | IMPLEMENTED — STATIC ONLY | static inspection; runtime test not executed | NOT VERIFIED — RUNTIME UNAVAILABLE | verify route, Blade, query, and accessibility behavior with Laravel/browser |
| 78 | `/check` | `ticket-check`, `ticket-check.submit` | `HomeController` | `GloPublicResultService` | CSRF; six digits; throttled POST | `Draw`, `DrawResult` through service | canonical public result/check data | existing GLO check APIs | `home/check.blade.php` | none required | existing home styles | `home.php` EN/TH | server-side bounded input and rate limit | canonical GLO ticket checker | anonymous; no client identity accepted | REVIEWED — STATIC ONLY | existing check flow inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | execute throttling and no-data behavior |
| 79 | `/sales-points` | `sales-points` | `HomeController` | `GloSalesPointService` | bounded query/page filters | service-owned public point projection | configured/public sales-point data | existing GLO sales-point API | `home/sales-points.blade.php` | none required | existing home styles | home text bag EN/TH | bounded search and explicit unavailable state | canonical published sales points | anonymous public projection | REVIEWED — STATIC ONLY | existing controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify paginator and public data-state behavior |
| 80 | `/privacy` | `privacy` | `PublicPagesController` | existing public legal page service | none | legal content projection | configured legal content | existing privacy API | `static/privacy.blade.php` | none required | existing legal styles | `public_pages.php` EN/TH | public legal headers; no unsupported claims intended | configured legal source | anonymous | REVIEWED — STATIC ONLY | existing route/controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify canonical metadata and legal content parity |
| 81 | `/contact` | `contact`, `contact.submit` | `ContactController` | existing contact service/mail/storage lane | CSRF; validation; spam controls; throttle | contact submission model if configured | canonical contact configuration and sanitized submission | none | `contact/index.blade.php` | none required | existing contact styles | `contact.php` EN/TH | throttling, validation, truthful success | configured contact channels | anonymous GET/POST | REVIEWED — STATIC ONLY | existing route/controller/view inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify mail/storage failure states |
| 82 | `/download`, `/download-app`, `/app` | `download`, `download-app`, `app` | `PublicDownloadAppController` | `PublicAppLinkService` | none | none | configured app-link data | existing download API | `download/index.blade.php` | none required | existing app styles | public page resources EN/TH | no fabricated URLs | canonical configured links only | anonymous | REVIEWED — STATIC ONLY | existing service/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify unavailable/not-configured rendering |
| 83 | `/account-grades`, `/account-grade` | existing named routes | `PublicGradeController` | existing public grade service | none | public grade configuration | configured grade rules only | none | existing grade public view | none required | existing public styles | account services/info EN/TH | no authenticated account data | configured explainer only | anonymous | REVIEWED — STATIC ONLY | existing route/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify exact EN/TH key parity in runtime |
| 84 | `/account-verification`, `/account-verification-guide` | existing named routes | `PublicVerificationController` | existing public verification service | none | public verification configuration | configured guide data | none | existing verification public view | none required | existing public styles | account services/info EN/TH | no user/KYC records on public page | configured guide only | anonymous | REVIEWED — STATIC ONLY | existing route/controller inspected | NOT VERIFIED — RUNTIME UNAVAILABLE | verify guide state and metadata |
| 85 | `/sitemap.xml`, robots/indexation surfaces | `sitemap` | `SitemapController` | existing sitemap/public-page services | none | public route registry | configured canonical URL source | XML sitemap | sitemap response | none | response headers | public page translations | excludes auth/admin/API/payment returns | canonical public routes only | anonymous | REVIEWED — STATIC ONLY | existing sitemap/security tests present but not run | NOT VERIFIED — RUNTIME UNAVAILABLE | execute sitemap and robots assertions |
| 86 | `/payment/success` | `payment.callback.success` | `PaymentCallbackController` | `PaymentCallbackService::browserReturnProjection` | authenticated query references; read-only | `Payment` plus payable projection | payments paper only | provider callback architecture remains separate | `payment/callback.blade.php` | none required | existing app styles | `account_services.php` EN/TH | owner check; safe reference bounds; no state mutation | verified internal payment status | session owner | REVIEWED — STATIC ONLY | existing BrowserPaymentCallback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify confirmed/pending/not-found page states |
| 87 | `/payment/failure` | `payment.callback.failure` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative failed status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify failure cannot be forged by route |
| 88 | `/payment/cancel` | `payment.callback.cancel` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative cancelled status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify cancel context does not override state |
| 89 | `/payment/pending` | `payment.callback.pending` | `PaymentCallbackController` | same read-only projection | same bounded references | `Payment` | payments paper | no browser mutation | shared callback view | none required | existing app styles | account services EN/TH | authoritative pending status only | internal payment status | session owner | REVIEWED — STATIC ONLY | existing callback tests not run | NOT VERIFIED — RUNTIME UNAVAILABLE | verify pending remains pending until verified transition |
| 90 | `/admin`, `/admin/dashboard` | `admin.dashboard`, `admin.dashboard.index` | `LottoFinExecutiveDashboardController` | canonical model projections | authenticated; admin gate | `Bet`, `Withdrawal`, `FinancialTransaction` | live aggregate queries only | bounded analytics companion | `admin/dashboard.blade.php` | none required | existing admin styles | `admin.php` EN/TH | auth; access-admin gate; panel permission | canonical aggregates; no fallbacks | admin panel permission | HARDENED — STATIC ONLY | new route/controller/view static review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify roles, empty DB, and Blade compilation |
| 91 | `/admin/api/analytics` | `admin.api.analytics` | `LottoFinExecutiveDashboardController` | canonical aggregate projections | bounded 0–31 day date range; throttled | `Bet`, `Withdrawal` | bounded aggregate queries | safe KPI JSON | none | none | none | admin EN/TH keys for labels | auth; access-admin; rate protection; no raw models | canonical aggregate data; unavailable trend/profit state | dashboard permission | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | execute JSON and range-limit tests |
| 92 | `/admin/draws` | `admin.draws.index` | `LottoFinExecutiveDashboardController` | existing draw lifecycle services remain authoritative | bounded projection page | `Draw` | latest 50 draw projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; draw permission; no browser mutation | canonical draw records | `view draws` permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify draw policy and pagination behavior |
| 93 | `/admin/risk` | `admin.risk.index` | `LottoFinExecutiveDashboardController` | existing risk services remain authoritative | none | no fabricated risk model rows | no fabricated records | none | shared explicit unavailable state | none required | existing admin styles | admin EN/TH | auth; risk permission | explicit `UNAVAILABLE` until canonical projection supplied | risk permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical risk alert projection without duplication |
| 94 | `/admin/bets` | `admin.bets.index` | `LottoFinExecutiveDashboardController` | existing betting services remain authoritative | latest 100 safe records | `Bet` | bounded latest bet projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; transaction permission; no mutation controls | canonical bet rows | transaction-history permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify pagination and object-level policy expectations |
| 95 | `/admin/wallets` | `admin.wallets.index` | `LottoFinExecutiveDashboardController` | `WalletService` remains canonical for mutations | latest 100 safe projection | `Wallet` | canonical wallet rows | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; wallet permission; no browser financial mutations | canonical wallet balances | wallet permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify masking/least privilege for deployed roles |
| 96 | `/admin/ledger` | `admin.ledger.index` | `LottoFinExecutiveDashboardController` | finance/ledger services remain canonical | latest 100 safe records | `FinancialTransaction` | canonical financial transaction projection | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; finance permission; no adjustment UI | canonical financial transactions | financial-reports permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify ledger entry object policy and pagination |
| 97 | `/admin/reconciliation`, `/admin/api/reconciliation` | `admin.reconciliation.index`, `admin.api.reconciliation` | `LottoFinExecutiveDashboardController` | `FinancialReconciliationService` | bounded 0–31 day POST run; throttled | reconciliation DTOs and ledger models | canonical reconciliation service | explicit `NOT_CONFIGURED` GET; canonical report POST | shared explicit state | none required | existing admin styles | admin EN/TH | auth; reconcile permission; GET has no side effect | service report or explicit no bank feed | reconcile-ledger permission | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify report DTO serialization and audit write |
| 98 | `/admin/audits` | `admin.audits.index` | `LottoFinExecutiveDashboardController` | existing audit query service architecture | bounded latest 100 projection | `AuditLog` | canonical immutable audit rows | none | shared admin projection view | none required | existing admin styles | admin EN/TH | auth; audit permission; no raw metadata exposure | canonical audit log safe fields | view-audit-logs permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | bind `AdminAuditQueryService` filters/pagination in runtime |
| 99 | `/admin/kyc` and secured document/action routes | `admin.kyc.index`, `admin.kyc.download`, `admin.kyc.approve`, `admin.kyc.reject` | `LottoFinExecutiveDashboardController` | `AccountVerificationService`, `AccountVerificationDocumentService` | CSRF review form; throttled document/action routes | `KycDocument` | private KYC storage and KYC tables | no public document API | shared admin projection view; private streamed response | none required | existing admin styles | admin EN/TH | auth; KYC permission; object-level document load; private stream; audit | canonical KYC document/service | manage-users permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify policy/four-eyes decision and private storage headers |
| 100 | `/admin/compliance` | `admin.compliance.index` | `LottoFinExecutiveDashboardController` | existing compliance/AML services remain authoritative | none | no fabricated compliance rows | no fabricated records | none | shared explicit unavailable state | none required | existing admin styles | admin EN/TH | auth; risk permission; no browser-only mutation | explicit `UNAVAILABLE` until canonical projection supplied | risk permission | HARDENED — STATIC ONLY | static inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical compliance case projection without duplication |

**Runtime boundary for every row above:** `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Pages 100–150 independent audit matrix

Every page from 100 through 150 is independently represented. Existing canonical services remain authoritative; unconnected surfaces fail closed rather than fabricate state.

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 100 | Admin Compliance Center | /admin/compliance | admin.compliance.index | `admin.auth` + `access-admin` | view risk alerts | LottoFinExecutiveDashboardController | none | existing compliance/AML services | ComplianceCase, AmlRiskAssessment | canonical compliance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical compliance projection or explicit unavailable state | read-only unless canonical service is invoked | admin.auth; access-admin; risk permission | canonical AuditLog where mutation exists | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | bind ComplianceCase/AmlRisk projections without duplicating engines |
| 101 | GLO Prize Claims | /admin/glo/prize-claims | admin.glo.prize-claims.index | `admin.auth` + `access-admin` | manage glo prize claims | LottoFinExecutiveDashboardController | none | GloPrizeClaimService | GloPrizeClaim | canonical GLO claim tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | GloPrizeClaim projection | read-only unless canonical service is invoked | admin.auth; access-admin; manage GLO claims | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify policy, exact currency, claim lifecycle and Blade runtime |
| 102 | GLO Prize Claim Detail | /admin/glo/prize-claims/{claim} | admin.glo.prize-claims.show | `admin.auth` + `access-admin` | manage glo prize claims | LottoFinExecutiveDashboardController | opaque/bounded claim reference | GloPrizeClaimService | GloPrizeClaim, Draw, GloTicket | canonical claim/ticket/draw relations | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | authoritative claim projection | read-only unless canonical service is invoked | admin.auth; object-safe bounded reference; claim permission | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route and parser checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify detail projection, proportional settlement and audit history |
| 103 | GLO Ticket Freeze Console | /admin/glo/ticket-freezes | admin.glo.ticket-freezes.index | `admin.auth` + `access-admin` | review glo freezes | LottoFinExecutiveDashboardController | none | GloTicketFreezeService | GloTicketFreeze, GloTicket | canonical freeze tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical freeze projection | read-only unless canonical service is invoked | admin.auth; review freeze permission; no browser mutation | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route and parser checks | NOT VERIFIED — RUNTIME UNAVAILABLE | wire existing freeze state machine into this URL without duplicate actions |
| 104 | GLO Ticket Freeze Detail | /admin/glo/ticket-freezes/{token} | admin.glo.ticket-freezes.show | `admin.auth` + `access-admin` | review glo freezes | LottoFinExecutiveDashboardController | bounded opaque case token | GloTicketFreezeService | GloTicketFreeze, GloTicket | canonical freeze/ticket relations | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical freeze projection | read-only unless canonical service is invoked | admin.auth; object authorization; no raw ticket ID exposure | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route and parser checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify opaque reference and policy behavior |
| 105 | Prize Settlement Review | /admin/glo/settlements | admin.glo.settlements.index | `admin.auth` + `access-admin` | process settlements | LottoFinExecutiveDashboardController | none | FinancialReconciliationService; GloPrizeClaimService | GloPrizeClaim, LedgerEntry | canonical settlement and ledger data | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED until canonical settlement projection is connected | read-only unless canonical service is invoked | admin.auth; process-settlement permission; read-only route | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical settlement review projection; no PAY NOW shortcut |
| 106 | Wallet Operations Center | /admin/wallet-operations | admin.wallet-operations.index | `admin.auth` + `access-admin` | manage wallet | LottoFinExecutiveDashboardController | none | WalletService; FinancialReconciliationService | Wallet, WalletLedger | canonical wallet/ledger data | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; wallet permission; no balance edit | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | add currency-grouped canonical projection |
| 107 | Payment Methods Management | /admin/payment-methods | admin.payment-methods.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentGatewayManager | PaymentMethodConfig, PaymentProvider | payment configuration tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; payout permission; secrets excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind safe provider capability projection |
| 108 | Withdrawal Methods Management | /admin/withdrawal-methods | admin.withdrawal-methods.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentGatewayManager | PaymentMethodConfig, PaymentProvider | payment configuration tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; payout permission; secrets excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind capability-aware withdrawal projection |
| 109 | Payment Operations | /admin/payments | admin.payments.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | bounded latest projection | Payment model/services | Payment | payment table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical payment rows where existing projection permits | read-only unless canonical service is invoked | admin.auth; payout permission; read-only default | canonical AuditLog where mutation exists | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify safe references and provider-state normalization |
| 110 | Payment Detail | /admin/payments/{payment} | admin.payments.show | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | bounded payment reference | PaymentCallbackService; payment services | Payment, PaymentReconciliation | payment/reconciliation tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED detail state | read-only unless canonical service is invoked | admin.auth; object authorization; no webhook secrets | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | add policy-protected detail projection |
| 111 | Payment Event / Webhook Audit | /admin/payment-events | admin.payment-events.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentWebhookService | PaymentWebhook | webhook evidence table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; safe metadata only; no replay | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect verified-event safe projection |
| 112 | Payment Exceptions | /admin/payment-exceptions | admin.payment-exceptions.index | `admin.auth` + `access-admin` | manage payouts | LottoFinExecutiveDashboardController | none | PaymentReconciliationService | PaymentReconciliation | reconciliation table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; canonical evidence only | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical exception query |
| 113 | Draw Lifecycle Operations | /admin/draw-lifecycle | admin.draw-lifecycle.index | `admin.auth` + `access-admin` | manage draws | LottoFinExecutiveDashboardController | bounded latest projection | draw lifecycle services | Draw | draw tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; manage draws; no browser transition | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind actual lifecycle state service |
| 114 | Result Publication Control | /admin/result-publication | admin.result-publication.index | `admin.auth` + `access-admin` | view results | LottoFinExecutiveDashboardController | none | GloResultPublicationService | DrawPublication, DrawResult | publication/result tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; view results; no browser publication | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect verified publication projection |
| 115 | Result Import / Provenance | /admin/result-imports | admin.result-imports.index | `admin.auth` + `access-admin` | view results | LottoFinExecutiveDashboardController | bounded latest projection | GloResultImportService; ResultImportService | GloResultImport | import/provenance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; view results; no fixture activation | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect import health snapshot |
| 116 | Result Source Health | /admin/result-sources | admin.result-sources.index | `admin.auth` + `access-admin` | view results | LottoFinExecutiveDashboardController | none | ProviderHealthService; result source services | provider/result source records | configured source state | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; bounded backend health only | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect backend health snapshot |
| 117 | Lottery Product Catalog | /admin/lotteries | admin.lotteries.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | PublicLotteryCatalogService | TicketProduct | configured catalogue | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no inactive product purchase controls | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical catalogue projection |
| 118 | Lottery Rules / Pricing | /admin/lottery-rules | admin.lottery-rules.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | canonical pricing/rule services | TicketProduct, configuration | versioned configured rules | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no silent economic mutation | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect versioned rules projection |
| 119 | Fee Schedule Management | /admin/fees | admin.fees.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | canonical fee services | configuration/fee projection | configured fee source | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no presentation/economics drift | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect fee version projection |
| 120 | Account Grade Admin | /admin/account-grades | admin.account-grades.index | `admin.auth` + `access-admin` | manage system settings | LottoFinExecutiveDashboardController | none | AccountGradeService | AccountGradeSnapshot, GradeDiscountSnapshot | grade tables/configuration | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no direct user grade edit | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect authoritative grade projection |
| 121 | Account Verification Operations | /admin/account-verification | admin.account-verification.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded latest projection | AccountVerificationService | KycDocument, KycVerification | private KYC data | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; object-level KYC policy | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect queue projection without bypassing KYC service |
| 122 | Responsible Gaming Operations | /admin/responsible-gaming | admin.responsible-gaming.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | none | ResponsibleGamingService | ResponsibleGamingLimit, PlayerProtectionCase | RG tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no browser bypass | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect aggregated RG operational projection |
| 123 | Self-Exclusion Operations | /admin/self-exclusion | admin.self-exclusion.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | none | SelfExclusionService | SelfExclusion | self-exclusion table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; all actions remain canonical service actions | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe status projection |
| 124 | User Operations | /admin/users | admin.users.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded latest projection | UserResource/AdminAccess | User | users table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; masked PII; no generic mutation | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | use existing UserResource route or safe list projection |
| 125 | User Detail | /admin/users/{user} | admin.users.show | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded user reference | UserResource/policies | User and authorized relations | canonical user relations | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; object policy; no secret fields | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind object-level detail projection |
| 126 | User Financial Profile | /admin/users/{user}/finance | admin.users.finance | `admin.auth` + `access-admin` | financial reports | LottoFinExecutiveDashboardController | bounded user reference | WalletService; reconciliation services | Wallet, LedgerEntry, Payment | canonical financial tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; currency-separated exact money; read-only | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind currency-grouped financial projection |
| 127 | Bet Detail | /admin/bets/{bet} | admin.bets.show | `admin.auth` + `access-admin` | transaction history | LottoFinExecutiveDashboardController | bounded bet reference | betting services | Bet, BetItem | bet tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; object authorization; no payout edit | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind safe bet detail projection |
| 128 | Ticket Detail | /admin/tickets/{ticket} | admin.tickets.show | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded ticket reference | ticket services | Ticket, GloTicket | ticket tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no unnecessary raw ID exposure | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | bind policy-protected ticket projection |
| 129 | Ticket Verification Operations | /admin/ticket-verification | admin.ticket-verification.index | `admin.auth` + `access-admin` | manage users | LottoFinExecutiveDashboardController | bounded verification request | TicketBarcodeService; TicketAuthenticityService | LotteryTicketVerification | verification table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; approved parser authority | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical verification records |
| 130 | Prize Claim Review Queue | /admin/prize-claim-review | admin.prize-claim-review.index | `admin.auth` + `access-admin` | manage glo prize claims | LottoFinExecutiveDashboardController | bounded claim queue | GloPrizeClaimService | GloPrizeClaim | claim tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | claim projection available through Page 101 lane | read-only unless canonical service is invoked | admin.auth; claim permission; no browser final approval | canonical AuditLog where mutation exists | HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | bind dedicated queue filters and policy checks |
| 131 | Commission Operations | /admin/commissions | admin.commissions.index | `admin.auth` + `access-admin` | view commissions | LottoFinExecutiveDashboardController | bounded latest projection | AgentCommissionService | AgentCommission | commission tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; commission permission; exact money | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect canonical commission projection |
| 132 | Agent Dashboard | /agent | agent.dashboard | `auth` | agent owner | AgentPortalController | authenticated session only | AgentReportingService | Agent, AgentCommission, Bet | agent/referral/commission data | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | server-calculated report | read-only unless canonical service is invoked | auth; active agent owner scope | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify agent status and report DTO runtime |
| 133 | Agent Commissions | /agent/commissions | agent.commissions | `auth` | agent owner | AgentPortalController | authenticated session only | AgentCommissionService | AgentCommission | commission table | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | canonical commission rows | read-only unless canonical service is invoked | auth; agent owner scope; exact strings | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | execute owner-scope and currency tests |
| 134 | Agent Settlements | /agent/settlements | agent.settlements | `auth` | agent owner | AgentPortalController | authenticated session only | AgentSettlementService | AgentCommission | commission/financial tables | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | paid commission evidence only | read-only unless canonical service is invoked | auth; read-only web route; no payout mutation | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify settlement history projection |
| 135 | Agent Statement | /agent/statement | agent.statement | `auth` | agent owner | AgentPortalController | authenticated session only | AgentCommissionService | AgentCommission | commission table | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | canonical commission rows | read-only unless canonical service is invoked | auth; agent owner; no cross-currency aggregation | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify running-balance semantics if later configured |
| 136 | Referral Overview | /agent/referrals | agent.referrals | `auth` | agent owner | AgentPortalController | authenticated session only | AgentReferralService | User preferences/referral attribution | user/referral data | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | owner-scoped referral projection | read-only unless canonical service is invoked | auth; approved referral projection; masked reference | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify referral privacy projection |
| 137 | Referral Detail | /agent/referrals/{referral} | agent.referrals.show | `auth` | agent owner | AgentPortalController | opaque hashed referral reference | AgentReferralService | User | user/referral data | none | agent/portal.blade.php | none | existing app styles | agent.php EN/TH | owner-scoped referral row | read-only unless canonical service is invoked | auth; owner-scoped opaque reference | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | verify no private referred-user leakage |
| 138 | Support Center / Inbox | /support | support.index | `auth` | authenticated player | SupportPortalController | authenticated session only | PublicSupportService; ContactMessageService | ContactMessage has no owner binding | contact table | none | support/portal.blade.php | none | existing app styles | support.php EN/TH | explicit NOT_CONFIGURED — no anonymous message leakage | read-only unless canonical service is invoked | auth; fail closed because owner scope absent | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | add owner-scoped support case contract before showing inbox |
| 139 | Support Request Detail | /support/{reference} | support.show | `auth` | authenticated player | SupportPortalController | bounded public reference | ContactMessageService | ContactMessage | contact table | none | support/portal.blade.php | none | existing app styles | support.php EN/TH | explicit NOT_CONFIGURED | read-only unless canonical service is invoked | auth; no owner contract, no record lookup | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route/controller review | NOT VERIFIED — RUNTIME UNAVAILABLE | bind object ownership before detail reads |
| 140 | Notification Center | /notifications | notifications.index | `auth` | authenticated player | notifications.index | authenticated session only | Notification API/model | Notification | notification table | existing notification API remains canonical | notifications/index.blade.php | existing notification JS/API | existing app styles | notifications.php EN/TH | owner-scoped notification rows | read-only unless canonical service is invoked | auth; user_id from session only | canonical AuditLog where mutation exists | IMPLEMENTED + HARDENED — STATIC ONLY | parser and static route checks | NOT VERIFIED — RUNTIME UNAVAILABLE | verify notification cast/runtime and owner scope |
| 141 | System Health | /health | health.canonical | public health route | public health projection | HealthController | none | SystemHealthService | health DTOs | backend dependencies | health JSON | JSON response | none | none | existing observability translations | database/cache/storage/queue checks | read-only unless canonical service is invoked | public-safe dependency state | canonical AuditLog where mutation exists | EXISTING + HARDEN | existing source inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | execute readiness/dependency failure matrix |
| 142 | Operator Metrics | /metrics | metrics | auth + access-metrics | access metrics | MetricsController | operator auth | FinancialMetricsCollector | metrics DTOs | backend telemetry | Prometheus/JSON | text/JSON response | none | none | none | telemetry collector | read-only unless canonical service is invoked | auth; access-metrics gate | canonical AuditLog where mutation exists | EXISTING + HARDEN | existing source inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | verify no public exposure and safe labels |
| 143 | Queue / Worker Health | /admin/queues | admin.queues.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | Queue health services | QueueHealthReport | backend telemetry | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; audit/operations permission | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect QueueHealthReport projection |
| 144 | Scheduled Tasks | /admin/scheduler | admin.scheduler.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | scheduler metadata | scheduler metadata | scheduler backend | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no arbitrary command execution | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe scheduler snapshot |
| 145 | Cache / Session Operations | /admin/runtime | admin.runtime.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | SystemHealthService | health/runtime DTOs | backend dependencies | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no secrets or flush controls | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe runtime status |
| 146 | API Status Center | /admin/api-status | admin.api-status.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | ProviderHealthService | ProviderHealthData, PaymentProvider | provider tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no credentials; backend snapshots | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect provider health sheet |
| 147 | Webhook Audit Center | /admin/webhooks | admin.webhooks.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | PaymentWebhookService | PaymentWebhook | webhook evidence table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; raw payload/signature excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect safe webhook audit projection |
| 148 | Security Audit Center | /admin/security | admin.security.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | bounded latest projection | AdminAuditQueryService | AuditLog, SecurityEvent | audit/security tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; no secrets/tokens | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect security event projection |
| 149 | Release / Deployment Status | /admin/release | admin.release.index | `admin.auth` + `access-admin` | view audit logs | LottoFinExecutiveDashboardController | none | release/readiness services | OperationalReportJob and build metadata | runtime/build state | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | explicit NOT_CONFIGURED state | read-only unless canonical service is invoked | admin.auth; secrets/paths excluded | canonical AuditLog where mutation exists | NOT_CONFIGURED — FAIL CLOSED | static route check | NOT VERIFIED — RUNTIME UNAVAILABLE | connect real release evidence |
| 150 | Production Cutover Control Center | /admin/cutover | admin.cutover.index | `admin.auth` + `access-admin` | manage system settings | ReleaseOperationsController | bounded admin GET | release/readiness evidence projection | none | filesystem/configuration/deployment evidence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | actual artifact evidence or NOT_VERIFIED | read-only; no browser shell execution | admin.auth; access-admin; system-settings permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | external deployment, backup, queue, provider, and rollback evidence remain required |

## Pages 77–100 changed-file manifest

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/GloResultsPageController.php` | PHP controller | Replaced fabricated results and ticket-check payloads with the existing canonical public result and ticket-check projections. |
| `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` | PHP controller | Added authorized, bounded, canonical admin projections; removed fabricated KPI/trend/reconciliation values; uses configured-currency exact Money formatting with explicit UNAVAILABLE fallback; secured private KYC streaming and canonical KYC review delegation; made unsupported browser mutations explicit. |
| `app/Providers/AuthServiceProvider.php` | PHP provider | Added the `access-admin` gate backed by `AdminAccess` panel authorization. |
| `app/Http/Middleware/Authenticate.php` | PHP middleware | Keeps existing authentication behavior and redirects unauthenticated `/admin/*` requests to the Filament login boundary. |
| `bootstrap/app.php` | PHP bootstrap | Registers the explicit `admin.auth` middleware alias without changing the global authentication alias. |
| `app/Providers/AppServiceProvider.php` | PHP provider | Added authenticated operator rate protection for admin analytics and reconciliation endpoints. |
| `app/Services/Payment/PaymentCallbackService.php` | PHP service | Bounded browser-return references and preserved owner-scoped, read-only authoritative payment-state projection. |
| `app/Services/PublicPages/ResultsPageService.php` | PHP service | Public results rows are limited to published/completed draws whose scheduled time has passed. |
| `routes/web.php` | PHP route file | Resolved `/results` to the canonical public controller and added authentication, authorization, throttling, explicit reconciliation POST, secured KYC document/action routes, and non-mutating unsupported withdrawal responses. |
| `resources/views/results/index.blade.php` | Blade view | Public results hub using only canonical published rows, explicit source states, safe table overflow, status text, and translated copy. |
| `resources/views/admin/dashboard.blade.php` | Blade view | Shared authorized admin projection view with no fabricated financial values, explicit unavailable states, semantic tables, and translated labels. |
| `lang/en/results.php` | PHP translation map | English results-hub labels and explicit public-data states. |
| `lang/th/results.php` | PHP translation map | Exact Thai-locale key parity for the results-hub map. |
| `lang/en/admin.php` | PHP translation map | English admin labels and explicit operational states. |
| `lang/th/admin.php` | PHP translation map | Exact Thai-locale key parity for the admin map. |
| `tests/Feature/Pages77To100StaticContractTest.php` | PHPUnit feature/static contract test | Checks admin route boundary, known fixture removal, read-only payment-return lane, translation parity, and one audit row per page. |
| `app/Services/Account/AccountVerificationService.php` | PHP service | Added bounded, reviewer-bound opaque document-token resolution so admin KYC routes do not expose numeric document IDs while reusing the canonical KYC service. |
| `audit.md` | Markdown audit report | Added independent Page 77–100 audit matrix, runtime boundary, and changed-file manifest. |
| `PAGES-77-100-IMPLEMENTATION-REPORT.md` | Markdown delivery report | Complete contents, `# TYPE`, and `# PURPOSE` for every implementation file changed in this pass. |

| `resources/views/home/check.blade.php` | Blade view | Page 78 translated ticket-check labels while retaining CSRF, six-digit validation, server-side result state, and status messaging. |
| `resources/views/home/sales-points.blade.php` | Blade view | Page 79 translated bounded sales-point search, pagination, empty, and unavailable states. |
| `resources/views/privacy/index.blade.php` | Blade view | Page 80 policy surface with translated navigation, metadata, search, unavailable, and support labels. |
| `resources/views/download/index.blade.php` | Blade view | Page 82 configured app-destination surface with translated safety, integrity, and unavailable states. |
| `resources/views/account-grade/index.blade.php` | Blade view | Page 83 public grade explainer with translated navigation, configured-tier labels, private-state copy, and no fabricated account state. |
| `lang/en/public_pages.php` | PHP translation map | Added Page 80 and Page 82 visible interface labels. |
| `lang/th/public_pages.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 80 and Page 82 labels. |
| `lang/en/account_info.php` | PHP translation map | Added Page 83 visible interface labels. |
| `lang/th/account_info.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 83 labels. |
| `lang/en/home.php` | PHP translation map | Added Page 78–79 labels and count/page placeholders. |
| `lang/th/home.php` | PHP translation map | Exact EN/TH key and placeholder parity for Page 78–79 labels. |

All runtime-dependent rows and checks remain exactly: `NOT VERIFIED — RUNTIME UNAVAILABLE`.

## Pages 77–100 validation evidence

| Check | Result |
|---|---|
| PHP parser for changed PHP and translation files | Passed with `php-parser` static parser; this is not a PHP runtime check. |
| `git diff --check` | Passed. |
| Vite asset build | Passed with `npm run build`. |
| Static fixture-marker scan for Pages 77, 90, and admin view | Passed; known fabricated values are absent from the changed projections/views. |
| Static route, audit-row, and translation-map checks | Passed, including exact EN/TH keys and placeholders for results, admin, public-pages, account-info, and home maps. |
| Pages 80, 82, and 83 visible-label review | Passed static review after moving remaining visible interface labels into translation maps. |
| Laravel route list | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, browser, payment-provider, queue, and accessibility checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 150–165 continuation audit matrix

Page 150 is re-audited above through `ReleaseOperationsController`; Pages 151–165 are listed here as independent rows.

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 151 | Release Manifest | /admin/release-manifest | admin.release-manifest.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | filesystem/config evidence projection | none | composer/package/Rust/build artifacts where present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | real artifact hashes or NOT_CONFIGURED | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | deploy metadata and runtime artifact verification remain external |
| 152 | Environment / Configuration Matrix | /admin/configuration | admin.configuration.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings; secret values excluded | ReleaseOperationsController | bounded admin GET | configuration repository presence projection | none | runtime configuration | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | configured/missing metadata only | read-only | admin.auth; access-admin; system-settings; secret values excluded | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | external provider health and secret validation remain unverified |
| 153 | Secrets and Key Management Status | /admin/secrets | admin.secrets.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | secret-presence metadata only | none | configuration presence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | presence only; no secret material | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | key age/rotation verification requires deployment evidence |
| 154 | Database Migration Control | /admin/migrations | admin.migrations.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | migration filesystem evidence plus NOT_VERIFIED runtime fields | none | migration files | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | filesystem count only; no migration execution | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | schema status requires Laravel/database runtime |
| 155 | Database Backup Control | /admin/backups | admin.backups.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | backup filesystem evidence plus NOT_VERIFIED fields | none | storage/backups if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | actual file evidence only | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | backup integrity/encryption/restore evidence required |
| 156 | Backup Restore Verification | /admin/restore-verification | admin.restore-verification.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings; no shell execution | ReleaseOperationsController | bounded admin GET | fail-closed restore projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no financial mutation | admin.auth; access-admin; system-settings; no shell execution | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | controlled non-production restore service required |
| 157 | Disaster Recovery Center | /admin/disaster-recovery | admin.disaster-recovery.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | fail-closed DR evidence projection | none | external infrastructure evidence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_VERIFIED fields | read-only | admin.auth; access-admin; system-settings | no mutation | NOT_VERIFIED — STATIC ONLY | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | RPO/RTO, replica, queue, DNS and Rust evidence external |
| 158 | Failover / High Availability Status | /admin/high-availability | admin.high-availability.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | fail-closed infrastructure projection | none | external infrastructure evidence | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_VERIFIED fields | read-only | admin.auth; access-admin; system-settings | no mutation | NOT_VERIFIED — STATIC ONLY | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | node and failover health require infrastructure telemetry |
| 159 | Incident Command Center | /admin/incidents | admin.incidents.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed incident projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical incident store required |
| 160 | Incident Detail | /admin/incidents/{reference} | admin.incidents.show | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission; bounded reference | ReleaseOperationsController | bounded opaque reference | fail-closed incident projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission; bounded reference | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | incident object authorization requires canonical store |
| 161 | Deployment Approval Gate | /admin/deployment-approval | admin.deployment-approval.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed approval projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no browser approval mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical release approval service required |
| 162 | Deployment History | /admin/deployments | admin.deployments.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed deployment history projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | deployment metadata source required |
| 163 | Rollback Control | /admin/rollback | admin.rollback.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | fail-closed rollback request projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no shell/deployment mutation | admin.auth; access-admin; system-settings | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | controlled rollback service and approval workflow required |
| 164 | Feature Flag Operations | /admin/feature-flags | admin.feature-flags.index | `admin.auth` + `access-admin` | admin.auth; access-admin; system-settings | ReleaseOperationsController | bounded admin GET | server-side config flags only | none | config/features if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | actual configured flags or NOT_CONFIGURED | read-only | admin.auth; access-admin; system-settings | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | flag mutation service not configured |
| 165 | Configuration Change Audit | /admin/configuration-audit | admin.configuration-audit.index | `admin.auth` + `access-admin` | admin.auth; access-admin; audit permission | ReleaseOperationsController | bounded admin GET | fail-closed immutable audit projection | none | none | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | dedicated configuration audit source required |
## Pages 100–150 changed-file manifest

The following files were changed or created for the Pages 100–150 continuation. Complete contents are provided in the sequential implementation reports in the workspace.

| Path | `# TYPE` | `# PURPOSE` |
|---|---|---|
| `app/Http/Controllers/Admin/LottoFinExecutiveDashboardController.php` | PHP controller | Extends the existing authorized admin projection lane with GLO claim/freeze rows and explicit fail-closed states for new operational routes. |
| `app/Http/Controllers/Agent/AgentPortalController.php` | PHP controller | Authenticated owner-scoped agent dashboard, commission, settlement, statement, referral, and referral-detail projections. |
| `app/Http/Controllers/NotificationCenterController.php` | PHP controller | Read-only authenticated owner-scoped notification center using the existing notification model/API architecture. |
| `app/Http/Controllers/Support/SupportPortalController.php` | PHP controller | Authenticated support boundary that fails closed because anonymous ContactMessage rows have no owner contract. |
| `routes/web.php` | PHP route file | Adds Pages 100–150 operational routes, authenticated agent routes, support routes, and notification center routes without removing existing endpoints. |
| `resources/views/admin/dashboard.blade.php` | Blade view | Extends the existing admin projection view with GLO claim/freeze detail columns and truthful state messaging. |
| `resources/views/agent/portal.blade.php` | Blade view | Localized responsive agent portal projection with no private player data or browser-side financial mutation. |
| `resources/views/support/portal.blade.php` | Blade view | Localized support center fail-closed state and safe public contact handoff. |
| `resources/views/notifications/index.blade.php` | Blade view | Localized owner-scoped notification projection with empty/state messaging. |
| `lang/en/admin.php` | PHP translation map | English Page 100–150 admin panel names, GLO fields, and operational labels. |
| `lang/th/admin.php` | PHP translation map | Matching Thai-locale admin key set for Page 100–150 operational labels. |
| `lang/en/agent.php` | PHP translation map | English agent portal labels and explicit states. |
| `lang/th/agent.php` | PHP translation map | Matching Thai-locale agent portal key set. |
| `lang/en/support.php` | PHP translation map | English support center fail-closed labels. |
| `lang/th/support.php` | PHP translation map | Matching Thai-locale support center key set. |
| `lang/en/notifications.php` | PHP translation map | English notification center labels and states. |
| `lang/th/notifications.php` | PHP translation map | Matching Thai-locale notification center key set. |
| `tests/Feature/Pages77To100StaticContractTest.php` | PHP static contract test | Extends static route coverage to Pages 100–150 and checks new translation namespaces. |
| `audit.md` | Markdown audit report | Adds independent Page 100–150 matrix, changed-file manifest, status summary, and runtime boundary. |
| `PAGES-100-150-IMPLEMENTATION-REPORT-PART-1.md` | Markdown implementation report | Complete contents for files 1–15 in sequential output order. |
| `PAGES-100-150-IMPLEMENTATION-REPORT-PART-2.md` | Markdown implementation report | Complete contents for the remaining files in sequential output order. |

## Pages 100–150 validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 14 changed PHP files. |
| EN/TH key parity | Passed for `admin`, `agent`, `support`, and `notifications`. |
| Pages 100–150 route contract scan | Passed for 43 route contracts. |
| Page 100–150 audit row scan | Passed for all rows 100 through 150. |
| Three-dot shortening marker scan on newly created files | Passed. |
| `git diff --check` | Passed. |
| Laravel route listing | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, browser, payment-provider, queue, storage, and accessibility checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 100–150 implementation summary

```text
PAGES 100–150

TOTAL PAGES: 51
IMPLEMENTED: 14
HARDENED: 9
NOT_CONFIGURED: 28
DATA IMPORT REQUIRED: 0
EXTERNAL VERIFICATION REQUIRED: 0
ACCESS CONTROL VERIFICATION REQUIRED: 51
RUNTIME UNAVAILABLE: 51
BLOCKED: 0

FINANCIAL FINDINGS: New financial/admin operational aliases fail closed unless an existing canonical projection is connected; no browser-only money mutation was added.
KYC FINDINGS: Existing canonical KYC service and reviewer-bound document-token lane remain authoritative; new account-verification operations route is fail closed.
RESPONSIBLE GAMING FINDINGS: Existing responsible-gaming and self-exclusion services remain authoritative; new browser mutation bypasses were not added.
GLO CLAIM FINDINGS: GLO claims and ticket freezes reuse existing models/services for bounded read projections; settlement review remains fail closed until a canonical projection is connected.
AGENT FINDINGS: Agent routes now require authentication and resolve the agent from the authenticated session; commission and referral data are owner-scoped.
SUPPORT FINDINGS: Support inbox/detail fail closed because the existing anonymous ContactMessage model has no owner-scoped case contract.
OBSERVABILITY FINDINGS: Existing health and metrics routes are preserved; new admin operational health surfaces remain fail closed until backend snapshots are connected.
DEPLOYMENT FINDINGS: Release and cutover pages are explicit NOT_CONFIGURED projections; no browser shell or deployment mutation was added.
SECURITY FINDINGS: Admin routes retain `admin.auth` and `access-admin`; panel permission checks remain in the controller; support and agent routes use authenticated sessions.
REMAINING GAPS: Runtime, route dispatch, Blade, database, authorization, payment-provider, queue, storage, browser, accessibility, and full test gates remain unverified.
```

## Pages 150–165 phase changed-file manifest

| Path | `# TYPE` | `# ROLE` | `# DOMAIN` | `# WHY CHANGED` | `# DEPENDENCIES` | `# SECURITY IMPACT` | `# TEST COVERAGE` |
|---|---|---|---|---|---|---|---|
| `app/Http/Controllers/Admin/ReleaseOperationsController.php` | PHP controller | Read-only operational projection | release, configuration, backup, DR, deployment | Adds evidence-based Pages 150–165 operations surfaces without browser shell or deployment mutation. | `AdminAccess`, Laravel config/filesystem helpers | Admin authentication and panel-specific permission; secrets and infrastructure details are not exposed. | Static parser, route scan, and diff check; runtime unverified. |
| `resources/views/admin/release-operations.blade.php` | Blade view | Operations evidence table | release operations UI | Adds truthful state rendering for release, configuration, backup, DR, incident, deployment, rollback, and feature-flag surfaces. | `admin_release` translations, existing admin layout/styles | `noindex`; escaped values; no mutation controls. | Static source review; Blade runtime unverified. |
| `lang/en/admin_release.php` | PHP translation map | English operator copy | release operations localization | Adds all new operator-facing labels and states. | Laravel translation loader | Prevents raw translation keys in the new view. | PHP parser and EN/TH key parity. |
| `lang/th/admin_release.php` | PHP translation map | Thai-locale operator copy | release operations localization | Maintains exact key parity with English. | Laravel translation loader | Prevents raw translation keys in the new view. | PHP parser and EN/TH key parity. |
| `routes/web.php` | PHP route file | Named admin routes | Pages 150–165 HTTP surface | Adds release-manifest, configuration, secrets, migrations, backup, restore, DR, HA, incident, deployment, rollback, feature-flag, and configuration-audit routes while preserving existing route names. | `ReleaseOperationsController`, existing `admin.auth`, `access-admin` | Strict incident-reference constraint; admin authentication and authorization group. | Static route scan and PHP parser; Laravel dispatch unverified. |
| `audit.md` | Markdown audit report | Phase audit matrix | Pages 150–165 | Adds Page 150 re-audit and independent rows for Pages 151–165. | repository evidence | Records fail-closed and runtime boundaries. | Row scan and static review. |
| `PAGES-150-250-ARCHITECTURE-INVENTORY.md` | Markdown inventory | Pre-work architecture tree | repository inventory | Records the complete depth-four inventory required before the Pages 150–250 phase. | filesystem inventory | Documents canonical architecture before changes. | File count and static generation. |
| `tests/Feature/Pages150To250StaticContractTest.php` | PHP static contract test | Static structural contracts | Pages 150–250 route/security/matrix contracts | Verifies route coverage, translation parity, read-only controller boundaries, canonical finance/lottery/Rust references, and every audit row. | PHPUnit/Laravel test harness; repository files | Detects route drift, raw secret/shell mutation patterns, and missing page coverage. | Static parser and direct contract scan passed; PHPUnit runtime unverified. |
| `PAGES-150-250-MATRICES.md` | Markdown matrix deliverable | Complete architecture matrices | Pages 150–250 reporting | Records the complete page, route, API, security, financial-integrity, Rust, and changed-file matrices. | `audit.md`; canonical repository routes/services | Records fail-closed states and exact runtime boundary. | Matrix row scan passed; runtime unverified. |

## Pages 150–165 phase validation evidence

| Check | Result |
|---|---|
| Architecture inventory | Generated from `app`, `bootstrap`, `config`, `database`, `resources`, `routes`, and `tests` at depth four; 1,734 paths recorded. |
| Static PHP parser | Passed for 5 Pages 150–165 PHP files. |
| EN/TH translation parity for `admin_release` | Passed. |
| Route contract scan for Pages 150–165 | Passed. |
| `git diff --check` | Passed. |
| Laravel route listing | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Blade compilation | NOT VERIFIED — RUNTIME UNAVAILABLE |
| PHPUnit/Pest | NOT VERIFIED — RUNTIME UNAVAILABLE |
| Database, backup, restore, deployment, provider, browser, accessibility, and Rust runtime checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 166–180 continuation audit matrix

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 166 | Operator Sessions | /admin/sessions | admin.sessions.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed session projection | none | canonical session store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | session inventory requires canonical secure store |
| 167 | Operator Access Review | /admin/access-review | admin.access-review.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed operator access projection | none | canonical identity/permission store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | identity, access-review, and last-login evidence not connected |
| 168 | Privileged Access | /admin/privileged-access | admin.privileged-access.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed privileged-access projection | none | canonical policy-backed records required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | no financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | wallet, payout, reconciliation, GLO, draw, and settings privileges require policy evidence |
| 169 | Permission Matrix | /admin/permission-matrix | admin.permission-matrix.index | bounded admin GET | audit permission | ReleaseOperationsController | none | configuration permission count plus fail-closed operation matrix | none | permission config if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | configured permission count only | read-only | admin.auth; access-admin; audit permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | effective role-to-permission evaluation requires runtime policy |
| 170 | Service Accounts | /admin/service-accounts | admin.service-accounts.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed service account projection | none | deployment secret/account registry required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | service account lifecycle and rotation source required |
| 171 | Network Access Controls | /admin/network-access | admin.network-access.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed network projection | none | deployment trusted-proxy/network evidence required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | allowlist, denylist, proxy, and admin network restrictions not connected |
| 172 | Device and Session Risk | /admin/device-risk | admin.device-risk.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed device-risk projection | none | security event store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | device and revocation telemetry not connected |
| 173 | Multi-factor Authentication | /admin/mfa | admin.mfa.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed MFA projection | none | MFA enrollment/verification source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | MFA lifecycle evidence not connected |
| 174 | Authentication Security | /admin/authentication-security | admin.authentication-security.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed authentication telemetry projection | none | canonical security-event telemetry required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | login, reset, lock, CAPTCHA, and anomaly aggregation not connected |
| 175 | Rate Limits | /admin/rate-limits | admin.rate-limits.index | bounded admin GET | audit permission | ReleaseOperationsController | none | server configuration rate-limit projection | none | security/account/admin config if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | configured values only | read-only | admin.auth; access-admin; audit permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | middleware runtime behavior remains unverified |
| 176 | CAPTCHA Controls | /admin/captcha | admin.captcha.index | bounded admin GET | audit permission | ReleaseOperationsController | none | CAPTCHA provider/presence metadata projection | none | auth security config if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | presence only; secret material excluded | read-only | admin.auth; access-admin; audit permission | no mutation | IMPLEMENTED + HARDENED — STATIC ONLY | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider reachability and challenge results remain unverified |
| 177 | Fraud and Risk Rules | /admin/risk-rules | admin.risk-rules.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed risk-rule projection | none | canonical rule registry required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | no opaque fraud score fabricated |
| 178 | Suspicious Activity | /admin/suspicious-activity | admin.suspicious-activity.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed suspicious case projection | none | canonical case store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | case evidence, assignment, and resolution store required |
| 179 | Compliance Cases | /admin/compliance-cases | admin.compliance-cases.index | bounded admin GET | audit permission | ReleaseOperationsController | reference optional | fail-closed compliance case projection | none | canonical compliance store required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission; bounded reference | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | protected case detail and evidence source required |
| 180 | Sanctions and Watchlists | /admin/sanctions | admin.sanctions.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed sanctions provider projection | none | configured provider evidence required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider configuration and health must be connected |

## Pages 166–180 phase validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 5 changed PHP files covering Pages 150–180 routes, controller, translations, and static contracts. |
| EN/TH translation parity for `admin_release` | Passed after Pages 166–180 labels were added. |
| Route contract scan for Pages 166–180 | Passed. |
| Audit row scan for Pages 150–180 | Passed. |
| `git diff --check` | Passed. |
| Laravel route listing, middleware dispatch, policy evaluation, database, security telemetry, provider, browser, accessibility, and Rust runtime checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 181–194 continuation audit matrix

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 181 | KYC and Identity Verification | /admin/kyc | admin.kyc.index | bounded admin GET | canonical KYC policy and reviewer authorization | LottoFinExecutiveDashboardController | document token for reviewer operations | AccountVerificationService and AccountVerificationDocumentService | KycDocument, KycVerification | canonical KYC tables; private document storage | no public document API | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical KYC projection; no fabricated document state | read-only GET projection; review mutations remain existing canonical POST services | admin.auth; access-admin; reviewer authorization; token constraint | review/download actions use existing audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live KYC records, provider health, and browser dispatch remain unverified |
| 182 | KYC Provider Status | /admin/kyc-provider | admin.kyc-provider.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider evidence is not configured |
| 183 | KYC Review Queue | /admin/kyc-review | admin.kyc-review.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | policy-protected review store required |
| 184 | Age Verification | /admin/age-verification | admin.age-verification.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | age evidence is not configured |
| 185 | Duplicate Account Controls | /admin/duplicate-accounts | admin.duplicate-accounts.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | no match result is fabricated |
| 186 | Account Restrictions | /admin/account-restrictions | admin.account-restrictions.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | restriction ledger required |
| 187 | Retention Controls | /admin/retention | admin.retention.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | retention and deletion evidence not connected |
| 188 | Privacy and Consent | /admin/privacy | admin.privacy.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | privacy service not connected |
| 189 | Data Rights Requests | /admin/data-rights | admin.data-rights.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | protected privacy request store required |
| 190 | Legal Registries | /admin/legal-registries | admin.legal-registries.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical compliance registry required |
| 191 | Compliance Reporting | /admin/compliance-reporting | admin.compliance-reporting.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | submission evidence is not available |
| 192 | AML Monitoring | /admin/aml-monitoring | admin.aml-monitoring.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical alerts and case data required |
| 193 | Regulatory Exports | /admin/regulatory-exports | admin.regulatory-exports.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | canonical export registry required |
| 194 | Compliance Audit | /admin/compliance-audit | admin.compliance-audit.index | bounded admin GET | audit permission | ReleaseOperationsController | none | fail-closed compliance projection | none | canonical compliance/identity source required | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | NOT_CONFIGURED | read-only; no account or financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_CONFIGURED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | immutable compliance control source required |

## Pages 181–194 phase validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 5 changed PHP files covering the Pages 181–194 extension. |
| EN/TH translation parity for `admin_release` | Passed after Pages 181–194 labels were added. |
| Route contract scan for Pages 181–194 | Passed. |
| Audit row scan for Pages 150–194 | Passed. |
| Laravel route listing, authorization evaluation, KYC, privacy, compliance, provider, browser, accessibility, and Rust runtime checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Pages 195–250 continuation audit matrix

| Page | Title | Route | Route Name | Middleware | Authorization | Controller | Request | Service | Model | Database | API | View | JS | CSS | Translation | Data Source | Financial Impact | Security | Audit Log | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 195 | Acceptance and Terms | /admin/compliance | admin.compliance.index | bounded admin GET | risk/compliance permission | LottoFinExecutiveDashboardController | none | canonical compliance projection | ComplianceCase, ComplianceAction | canonical compliance records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical data or NOT_CONFIGURED | read-only | admin.auth; access-admin; risk permission | canonical audit where service writes | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live acceptance/version evidence remains runtime-dependent |
| 196 | Responsible Gaming | /admin/responsible-gaming | admin.responsible-gaming.index | bounded admin GET | manage users permission | LottoFinExecutiveDashboardController | none | canonical responsible-gaming projection | ResponsibleGamingLimit, ResponsibleGamingLimitVersion | canonical responsible-gaming tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical data or NOT_CONFIGURED | no browser financial mutation | admin.auth; access-admin; manage-users | canonical service audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live limits and interventions not runtime verified |
| 197 | Self-exclusion | /admin/self-exclusion | admin.self-exclusion.index | bounded admin GET | manage users permission | LottoFinExecutiveDashboardController | none | SelfExclusionService-backed surface | SelfExclusion | canonical self-exclusion table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NOT_CONFIGURED | no purchase/payout mutation | admin.auth; access-admin; manage-users | canonical self-exclusion audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | active records require runtime query verification |
| 198 | Responsible Gaming Limits | /admin/responsible-gaming | admin.responsible-gaming.index | bounded admin GET | manage users permission | LottoFinExecutiveDashboardController | none | canonical limit projection | ResponsibleGamingLimit, ResponsibleGamingLimitVersion | canonical limit tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NOT_CONFIGURED | no financial mutation | admin.auth; access-admin; manage-users | canonical service audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | limit enforcement requires runtime and policy checks |
| 199 | Responsible Gaming Interventions | /admin/risk | admin.risk.index | bounded admin GET | risk permission | LottoFinExecutiveDashboardController | none | canonical risk projection | AmlRiskAssessment, ComplianceCase | canonical risk/compliance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NOT_CONFIGURED | read-only | admin.auth; access-admin; risk permission | canonical compliance audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | intervention evidence and active restrictions unverified |
| 200 | Wallet Integrity | /admin/wallets | admin.wallets.index | bounded admin GET | manage wallet permission | LottoFinExecutiveDashboardController | none | WalletService and wallet projection | Wallet, WalletLedger | canonical wallet tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no browser debit/credit | admin.auth; access-admin; manage-wallet | ledger writes remain canonical-service only | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | balances and invariants require database runtime |
| 201 | Ledger Integrity | /admin/ledger | admin.ledger.index | bounded admin GET | financial-report permission | LottoFinExecutiveDashboardController | none | LedgerBalanceValidator, LedgerReconciliationService | LedgerAccount, LedgerEntry, LedgerReconciliation | canonical ledger tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical entries or NO_DATA | read-only; no adjustment route here | admin.auth; access-admin; financial-report | canonical ledger audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | reconciliation result requires runtime service invocation |
| 202 | Payment Methods | /admin/payment-methods | admin.payment-methods.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | canonical payment capability projection | PaymentMethodConfig, PaymentProvider | canonical payment configuration | provider health API is separate | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | configured capabilities only | no checkout mutation | admin.auth; access-admin; manage-payouts | canonical config audit where available | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider capabilities require runtime configuration |
| 203 | Payment Intent State | /admin/payments/{payment} | admin.payments.show | bounded numeric payment reference | manage payouts permission | LottoFinExecutiveDashboardController | numeric payment reference | canonical payment projection | Payment, PaymentIntent | canonical payment tables | existing payment APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NOT_FOUND | no mutation | admin.auth; access-admin; manage-payouts; object authorization | canonical payment audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | object-level runtime authorization remains unverified |
| 204 | Payment Webhooks | /admin/payment-events | admin.payment-events.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | VerifyWebhookSignature and callback architecture | PaymentWebhook | canonical webhook table | signed webhook endpoints remain separate | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical webhook state or NO_DATA | no webhook replay mutation | admin.auth; access-admin; manage-payouts | webhook audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider callback and signature verification runtime unverified |
| 205 | Retry and Replay Protection | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | IdempotencyService and exception projection | PaymentWebhook, PaymentIntent | canonical idempotency/provider records | signed API boundary remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical exceptions or NO_DATA | no replay mutation | admin.auth; access-admin; manage-payouts | canonical exception audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | replay evidence requires runtime records |
| 206 | Deposits | /admin/payments | admin.payments.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | DepositService, DepositApprovalService, DepositCompletionService | Deposit, Payment | canonical deposit/payment tables | existing deposit API remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NO_DATA | no fabricated deposit success | admin.auth; access-admin; manage-payouts | canonical financial audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider and ledger settlement runtime unverified |
| 207 | Disputes | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | financial exception projection | Payment, PaymentReconciliation | canonical payment records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical exceptions or NO_DATA | read-only | admin.auth; access-admin; manage-payouts | canonical payment audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | dispute provider data is not connected |
| 208 | Chargebacks | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | FinancialReversalService and reconciliation architecture | Payment, PaymentReconciliation | canonical payment records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical exceptions or NO_DATA | no reversal mutation here | admin.auth; access-admin; manage-payouts | canonical reversal audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | chargeback provider and case records not connected |
| 209 | Withdrawals | /admin/withdrawals | admin.withdrawals.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | WithdrawalService, WithdrawalCompletionService | Withdrawal | canonical withdrawal table | existing withdrawal API remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NO_DATA | no withdrawal success claim | admin.auth; access-admin; manage-payouts | canonical payout audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider and KYC gate runtime unverified |
| 210 | Withdrawal Approval | /admin/withdrawals | admin.withdrawals.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | POST mutations are explicit unsupportedMutation | WithdrawalApprovalService | Withdrawal | canonical withdrawal table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical state or NO_DATA | browser approval does not silently mutate | admin.auth; access-admin; manage-payouts | canonical approval audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | controlled approval workflow remains external to projection |
| 211 | Treasury and Payouts | /admin/settlements | admin.settlements.index | bounded admin GET | process settlements permission | LottoFinExecutiveDashboardController | none | PayoutBatchService, PayoutReconciliationService | PrizeDisbursement, Withdrawal | canonical payout/settlement tables | provider balance source not configured | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical settlement records or NO_DATA | read-only projection | admin.auth; access-admin; process-settlements | canonical settlement audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | treasury bank/provider evidence not connected |
| 212 | Financial Reconciliation | /admin/reconciliation | admin.reconciliation.index | bounded admin GET/POST existing service route | reconcile ledger permission | LottoFinExecutiveDashboardController | bounded period request for existing service | FinancialReconciliationService | FinancialTransaction, PaymentReconciliation, LedgerReconciliation | canonical finance tables | reconciliation API remains authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical report or NOT_CONFIGURED feed | reconciliation POST uses canonical service; not fabricated | admin.auth; access-admin; reconcile-ledger | service audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | POST service execution was not runtime tested |
| 213 | Financial Reporting | /admin/ledger | admin.ledger.index | bounded admin GET | financial-report permission | LottoFinExecutiveDashboardController | none | FinancialReconciliationExportService | FinancialTransaction, LedgerEntry | canonical ledger tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical entries or NO_DATA | read-only | admin.auth; access-admin; financial-report | canonical financial audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | report generation and export runtime unverified |
| 214 | Tax | /admin/fees | admin.fees.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | TaxCalculationService via canonical finance architecture | Fee/configuration models where present | canonical configuration/finance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | configured values or NOT_CONFIGURED | no tax mutation | admin.auth; access-admin; system-settings | canonical config audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | jurisdiction and tax reporting evidence not connected |
| 215 | Commissions | /admin/commissions | admin.commissions.index | bounded admin GET | view commissions permission | LottoFinExecutiveDashboardController | none | AgentCommissionService, CommissionCalculationService | AgentCommission | canonical commission table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical commissions or NO_DATA | read-only | admin.auth; access-admin; view-commissions | canonical commission audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | commission records require runtime query |
| 216 | Commission Reconciliation | /admin/reconciliation | admin.reconciliation.index | bounded admin GET | reconcile ledger permission | LottoFinExecutiveDashboardController | bounded period request | AgentCommissionSettlementService and reconciliation architecture | AgentCommission, LedgerReconciliation | canonical records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical report or NO_DATA | no settlement mutation from GET | admin.auth; access-admin; reconcile-ledger | canonical reconciliation audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | commission-to-ledger runtime evidence unverified |
| 217 | Agents | /agent | agent.dashboard | authenticated agent portal | agent authorization | AgentPortalController | authenticated session only | AgentOnboardingService, AgentReportingService | Agent | canonical agent tables | agent APIs are canonical | agent portal views | none | agent CSS | agent translations | owner-scoped canonical records | no admin financial mutation | auth; agent policy; ownership scope | canonical agent audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | agent runtime and role evaluation unverified |
| 218 | Agent Settlements | /agent/settlements | agent.settlements | authenticated agent portal | agent authorization | AgentPortalController | authenticated session only | AgentSettlementService | Agent, AgentCommission | canonical agent settlement tables | none | agent portal views | none | agent CSS | agent translations | owner-scoped canonical records | read-only projection | auth; agent policy; ownership scope | canonical settlement audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | settlement runtime unverified |
| 219 | Referrals | /agent/referrals | agent.referrals | authenticated agent portal | agent authorization | AgentPortalController | authenticated session and bounded reference | AgentReferralService | Agent | canonical referral records | none | agent portal views | none | agent CSS | agent translations | owner-scoped canonical records | no fabricated commission | auth; agent policy; ownership scope; reference constraint | canonical referral audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | referral runtime unverified |
| 220 | Bonuses | /admin/commissions | admin.commissions.index | bounded admin GET | view commissions permission | LottoFinExecutiveDashboardController | none | canonical commission/promotion architecture | AgentCommission and configured bonus models | canonical records or NO_DATA | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | NO_DATA where no canonical bonus source | no bonus grant mutation | admin.auth; access-admin; view-commissions | canonical audit path | NOT_CONFIGURED — FAIL CLOSED | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | bonus product source is not connected |
| 221 | Promotions and Fees | /admin/fees | admin.fees.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | canonical fee/configuration projection | configuration models | canonical config or NOT_CONFIGURED | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | configured values only | no price mutation | admin.auth; access-admin; system-settings | canonical config audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | promotion catalogue evidence not connected |
| 222 | Payment Exceptions | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | FinancialReversalService and exception projection | Payment, PaymentWebhook, PaymentReconciliation | canonical exception records | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | read-only | admin.auth; access-admin; manage-payouts | canonical exception audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live provider exceptions unverified |
| 223 | Financial Holds | /admin/wallet-operations | admin.wallet-operations.index | bounded admin GET | manage wallet permission | LottoFinExecutiveDashboardController | none | FinancialHoldService, WalletHoldService | Wallet, WalletReservation | canonical wallet/hold tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no browser hold mutation | admin.auth; access-admin; manage-wallet | canonical hold audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | active holds require runtime records |
| 224 | Refunds | /admin/payments | admin.payments.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | RefundService, FinancialReversalService | Payment, FinancialTransaction | canonical finance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no refund mutation from projection | admin.auth; access-admin; manage-payouts | canonical reversal audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | refund provider and ledger runtime unverified |
| 225 | Payouts | /admin/withdrawals | admin.withdrawals.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | PayoutApprovalService, PayoutBatchService | Withdrawal, PrizeDisbursement | canonical payout tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no payout success claim | admin.auth; access-admin; manage-payouts | canonical payout audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | payout provider and approval runtime unverified |
| 226 | Account Finance Detail | /admin/users/{user}/finance | admin.users.finance | numeric user reference and object-scoped projection | manage users permission | LottoFinExecutiveDashboardController | numeric user reference | canonical owner-scoped finance projection | User, Wallet, FinancialTransaction | canonical user finance tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | read-only | admin.auth; access-admin; manage-users; object authorization | canonical audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | object scope and balances require runtime verification |
| 227 | Financial Audit | /admin/audits | admin.audits.index | bounded admin GET | audit permission | LottoFinExecutiveDashboardController | bounded audit filters | AuditLog projection | AuditLog | canonical audit table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical audit rows or NO_DATA | read-only | admin.auth; access-admin; audit permission | canonical AuditLog | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live audit query unverified |
| 228 | Lottery Product Catalogue | /admin/lotteries | admin.lotteries.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | canonical lottery catalogue projection | TicketProduct, LotteryProduct if present | canonical lottery tables/config | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical product data or NO_DATA | no product mutation | admin.auth; access-admin; system-settings | canonical catalogue audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | product runtime query unverified |
| 229 | Draw Lifecycle | /admin/draw-lifecycle | admin.draw-lifecycle.index | bounded admin GET | manage draws permission | LottoFinExecutiveDashboardController | none | DrawLifecycleService, DrawScheduleService | Draw, NationalLotteryDraw, WeeklyLotteryDraw | canonical draw tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical draw data or NO_DATA | no draw mutation from GET | admin.auth; access-admin; manage-draws | canonical draw audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | live draw state unverified |
| 230 | Sales Windows | /admin/draw-lifecycle | admin.draw-lifecycle.index | bounded admin GET | manage draws permission | LottoFinExecutiveDashboardController | none | DrawScheduleService, EnsureDrawIsOpen | Draw, TicketProduct | canonical draw/sales configuration | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical data or NO_DATA | no sales-opening mutation | admin.auth; access-admin; manage-draws | canonical draw audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | sales-window enforcement runtime unverified |
| 231 | Reservations | /admin/wallet-operations | admin.wallet-operations.index | bounded admin GET | manage wallet permission | LottoFinExecutiveDashboardController | none | WalletReservationService | WalletReservation, TicketAllocation | canonical reservation tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no reservation mutation | admin.auth; access-admin; manage-wallet | canonical wallet audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | reservation expiry and locking runtime unverified |
| 232 | Ticket Issuance and Inventory | /admin/lotteries | admin.lotteries.index | bounded admin GET | system-settings permission | LottoFinExecutiveDashboardController | none | GloL6SalesService, GloN3SaleService | Ticket, TicketInventoryItem, TicketAllocation | canonical ticket tables | canonical purchase APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical inventory or NO_DATA | no ticket issuance mutation | admin.auth; access-admin; system-settings | canonical issuance audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | inventory runtime unverified |
| 233 | Bet Validation | /admin/bets | admin.bets.index | bounded admin GET | transaction-history permission | LottoFinExecutiveDashboardController | none | BetPurchaseRiskService, ticket verification architecture | Bet, Ticket | canonical bet/ticket tables | bet APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | read-only | admin.auth; access-admin; transaction-history | canonical bet audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | validation runtime unverified |
| 234 | Bet State | /admin/bets/{bet} | admin.bets.show | numeric bet reference | transaction-history permission | LottoFinExecutiveDashboardController | numeric bet reference | canonical bet projection | Bet | canonical bet table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical record or NOT_FOUND | read-only | admin.auth; access-admin; transaction-history; object authorization | canonical bet audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | object authorization runtime unverified |
| 235 | Bet Refunds | /admin/payment-exceptions | admin.payment-exceptions.index | bounded admin GET | manage payouts permission | LottoFinExecutiveDashboardController | none | RefundService and financial exception projection | Bet, Payment, FinancialTransaction | canonical finance/bet tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no refund mutation | admin.auth; access-admin; manage-payouts | canonical refund audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | refund eligibility and state runtime unverified |
| 236 | Winning Calculations | /admin/draws | admin.draws.index | bounded admin GET | view draws permission | LottoFinExecutiveDashboardController | none | SelectionSettlementResolver, result calculators | DrawResult, PrizeMatch | canonical result/prize tables | official result APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical result data or NO_DATA | no fabricated winners/prizes | admin.auth; access-admin; view-draws | canonical result audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | winning calculation runtime unverified |
| 237 | Prize Liability | /admin/settlements | admin.settlements.index | bounded admin GET | process settlements permission | LottoFinExecutiveDashboardController | none | RealPrizeSettlementService, PayoutReconciliationService | PrizeDisbursement, GloPrizeClaim, DrawReconciliation | canonical prize/settlement tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical records or NO_DATA | no prize amount fabricated | admin.auth; access-admin; process-settlements | canonical settlement audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | liability calculation runtime unverified |
| 238 | Prize Payouts | /admin/glo/prize-claims | admin.glo.prize-claims.index | bounded admin GET | GLO claim permission | LottoFinExecutiveDashboardController | none | GloPrizeClaimService, RealPrizeSettlementService | GloPrizeClaim, PrizeDisbursement | canonical GLO prize tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical claims or NO_DATA | no payout success claim | admin.auth; access-admin; manage-GLO-claims | canonical claim audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | claim/payout runtime unverified |
| 239 | Prize Evidence | /admin/glo/prize-claims | admin.glo.prize-claims.index | bounded admin GET | GLO claim permission | LottoFinExecutiveDashboardController | bounded claim reference | GloPrizeClaimService | GloPrizeClaim, PrizeEligibilityDecision | canonical evidence tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical evidence or NO_DATA | private evidence not exposed by projection | admin.auth; access-admin; object authorization | canonical claim audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | claim evidence authorization runtime unverified |
| 240 | Ticket Freezes | /admin/glo/ticket-freezes | admin.glo.ticket-freezes.index | bounded admin GET | review GLO freezes permission | LottoFinExecutiveDashboardController | bounded freeze reference | GloFrozenWinnerService | GloTicketFreeze | canonical freeze table | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical freeze records or NO_DATA | no freeze mutation from GET | admin.auth; access-admin; review-freezes | canonical freeze audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | freeze review runtime unverified |
| 241 | Result Imports | /admin/result-imports | admin.result-imports.index | bounded admin GET | view results permission | LottoFinExecutiveDashboardController | none | GloResultImportService, DrawResultIngestionService | GloResultImport, DrawResult | canonical result import tables | provider import APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical imports or NO_DATA | no imported result fabricated | admin.auth; access-admin; view-results | canonical import audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider import and signature runtime unverified |
| 242 | Result Provenance | /admin/result-sources | admin.result-sources.index | bounded admin GET | view results permission | LottoFinExecutiveDashboardController | none | GloOfficialResultProvider, result provenance architecture | GloResultImport, DrawResult | canonical source/import tables | provider APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical provenance or NO_DATA | no source claim fabricated | admin.auth; access-admin; view-results | canonical provenance audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | provider provenance runtime unverified |
| 243 | Result Publication | /admin/result-publication | admin.result-publication.index | bounded admin GET | view results permission | LottoFinExecutiveDashboardController | none | DrawResultPublicationService, GloResultPublicationService | DrawPublication, DrawResult | canonical publication tables | public result APIs remain authoritative | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical publication or NO_DATA | no publication mutation | admin.auth; access-admin; view-results | canonical publication audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | publication runtime unverified |
| 244 | Draw Reconciliation and Certification | /admin/reconciliation | admin.reconciliation.index | bounded admin GET | reconcile ledger permission | LottoFinExecutiveDashboardController | bounded period request | DrawReconciliationService, DrawCertificationService | DrawReconciliation, DrawCertification | canonical draw reconciliation tables | none | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | canonical reconciliation or NO_DATA | read-only projection | admin.auth; access-admin; reconcile-ledger | canonical draw audit path | IMPLEMENTED + HARDENED — STATIC ONLY | parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | certification runtime unverified |
| 245 | Rust Integrity Health | /health | health.canonical | public health endpoint | HealthController health contract | HealthController | none | HealthCheckService, SystemHealthService | none | health dependencies are canonical | health API is canonical | health response | none | global CSS | system translations if used | actual health checks or failure state | no financial mutation | health endpoint security contract | health logs where configured | IMPLEMENTED + HARDENED — STATIC ONLY | existing health tests; runtime unverified | NOT VERIFIED — RUNTIME UNAVAILABLE | Rust subprocess health is not runtime proven |
| 246 | Rust Contract Boundary | /api/v1/health | api.v1.health | API health contract | API auth/health boundary | HealthController | none | health and Rust boundary architecture | none | none | canonical API endpoint | JSON response | none | none | API translations not browser-visible | actual API state or failure | no financial mutation | API boundary and no secret exposure | service logs where configured | IMPLEMENTED + HARDENED — STATIC ONLY | route scan; runtime unverified | NOT VERIFIED — RUNTIME UNAVAILABLE | Laravel-to-Rust invocation contract requires runtime test |
| 247 | Rust Deterministic Vectors | security/weekly-result-integrity/tests/integrity.rs | Cargo test target | isolated Rust test target | fixture-only deterministic verifier | Rust integrity crate | synthetic fixtures only | canonical Rust boundary artifact | none | Cargo lockfile and test fixtures | stdin/stdout contract is bounded | none | none | none | Rust source comments/tests | synthetic vectors; no production result claim | no financial mutation | no socket/network dependency documented | test evidence is local only | IMPLEMENTED + HARDENED — STATIC ONLY | Rust test command not executed | NOT VERIFIED — RUNTIME UNAVAILABLE | Cargo toolchain and vectors require runtime execution |
| 248 | FFI/API Security | security/weekly-result-integrity/src/main.rs | Rust stdin/stdout shim | short-lived subprocess boundary | no socket; bounded JSON boundary | Rust integrity crate | single JSON document stdin/stdout | canonical isolated verifier | none | Cargo artifact | API boundary is stdin/stdout, not public network | none | none | none | Rust source | no financial mutation | no socket, no token, no raw secret exposure by design | process audit evidence unavailable | IMPLEMENTED + HARDENED — STATIC ONLY | static source inspection | NOT VERIFIED — RUNTIME UNAVAILABLE | process sandbox and malformed-input runtime tests remain |
| 249 | Rust Performance Evidence | /admin/runtime | admin.runtime.index | admin protected read-only projection | audit permission | ReleaseOperationsController | none | artifact/config evidence only | none | Cargo manifest/lockfile if present | none | admin/release-operations.blade.php | none | admin-lottofin.css | admin_release EN/TH | artifact presence only; no benchmark claim | no financial mutation | admin.auth; access-admin; audit permission | no mutation | NOT_VERIFIED — FAIL CLOSED | static parser and route scan | NOT VERIFIED — RUNTIME UNAVAILABLE | benchmark, memory, timeout, and throughput evidence not present |
| 250 | Final Enterprise Integrity Audit | /admin/audits | admin.audits.index | bounded admin GET | audit permission | LottoFinExecutiveDashboardController | bounded audit filters | canonical audit projection plus Pages 150–249 matrix | AuditLog and domain audit models | canonical audit tables | health and API contracts remain separate | admin/dashboard.blade.php | none | admin-lottofin.css | admin.php EN/TH | actual audit rows or NO_DATA | read-only; no financial mutation | admin.auth; access-admin; audit permission | AuditLog canonical source | IMPLEMENTED + HARDENED — STATIC ONLY | parser, route, matrix scans; runtime unverified | NOT VERIFIED — RUNTIME UNAVAILABLE | final production/infrastructure/provider/Rust evidence remains external |

## Pages 195–250 phase validation evidence

| Check | Result |
|---|---|
| Static PHP parser | Passed for 5 changed PHP files. |
| EN/TH translation parity for `admin_release` | Passed. |
| Route and canonical-architecture scan | Passed; existing finance, lottery, health, and Rust boundaries remain referenced rather than duplicated. |
| Audit row scan for Pages 150–250 | Passed; one row is present for every page 150 through 250. |
| Matrix row scan for Pages 150–250 | Passed; one row is present for every page 150 through 250. |
| `git diff --check` | Passed. |
| Rust Cargo tests, Laravel route listing, Blade compilation, database, provider, browser, accessibility, performance, and infrastructure checks | NOT VERIFIED — RUNTIME UNAVAILABLE |

## Final runtime boundary

| Runtime check | Result |
|---|---|
| PHP CLI | `NOT VERIFIED — RUNTIME UNAVAILABLE` — the `php` executable is not installed in the workspace. |
| PHPUnit | `NOT VERIFIED — RUNTIME UNAVAILABLE` — `vendor/bin/phpunit` is not available. |
| Laravel route dispatch, container resolution, policy evaluation, Blade compilation, database, queues, providers, browser, and accessibility | `NOT VERIFIED — RUNTIME UNAVAILABLE`. |
| Rust Cargo test | `NOT VERIFIED — RUNTIME UNAVAILABLE` — the `cargo` executable is not installed in the workspace. |
| Production deployment, backup/restore, DR/HA, payment, KYC, compliance, lottery-provider, and infrastructure evidence | `NOT VERIFIED — RUNTIME UNAVAILABLE`. |

## Pages 251–350 runtime activation audit matrix

| Page | Title | Route | Route Name | HTTP Method | Middleware | Authorization | Controller | Request | Service | DTO | Model | Database | API | Job/Event | View | JS | CSS | Translation | Source of Truth | Financial Impact | Security | Audit | Status | Tests | Runtime Status | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 251 | Runtime Environment Bootstrap | scripts/runtime_preflight.py | runtime.preflight | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | scripts/runtime_preflight.py | canonical request/DTO or bounded command input | Python preflight | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/page-251-preflight.json | No financial mutation. | No secret values are read or emitted. | canonical audit path or runtime report | PARTIALLY VERIFIED | Python JSON validation | PARTIALLY VERIFIED — PRE-FLIGHT ONLY | PHP, Composer, database, Redis, browser, Rust, and providers are unavailable. |
| 252 | Dependency Installation Verification | composer.json; package.json | dependency.commands | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | composer and npm commands | canonical request/DTO or bounded command input | Composer and NPM package managers | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/page-252-dependency-verification.json | No financial mutation. | Dependency findings are recorded rather than hidden. | canonical audit path or runtime report | PARTIALLY VERIFIED | npm ci; npm audit | PARTIALLY VERIFIED — FRONTEND ONLY | Composer is unavailable; npm audit reports one moderate and one high vulnerability. |
| 253 | Laravel Boot Verification | artisan | artisan.runtime | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Laravel Artisan runtime | canonical request/DTO or bounded command input | Laravel Artisan | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | RUNTIME-VERIFICATION-REPORT.md | No financial mutation. | No runtime policy claim. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | php artisan about; route:list; config:show | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor are unavailable; container and routes are not verified. |
| 254 | Database Connection Verification | config/database.php | database.runtime | CLI/runtime | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Laravel database manager | canonical request/DTO or bounded command input | Laravel database manager | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | config/database.php; phpunit.xml | Financial execution is unverified. | No credentials are emitted. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | database connection attempt | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP, Laravel container, credentials, and database server are unavailable. |
| 255 | Migration Baseline | database/migrations | migrate.status | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Artisan migration subsystem | canonical request/DTO or bounded command input | Artisan migration subsystem | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | database migration files; migrate:status command | Financial schema state is unverified. | Production migration was not run. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | php artisan migrate:status | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Applied, pending, and batch state cannot be observed. |
| 256 | Seeder Safety Audit | database/seeders | seeders.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Seeder source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Seeder execution and production classification require runtime review. |
| 257 | Factory and Fixture Audit | database/factories | factories.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Factory source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Synthetic fixtures are not production financial truth. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Factory execution and isolation require runtime review. |
| 258 | Database Constraint Audit | database/migrations | constraints.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Migration source inspection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Money precision and foreign-key behavior are not runtime assertions. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Constraint behavior requires a real database. |
| 259 | Database Transaction Audit | app/Services | transactions.audit | static/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Canonical finance, payment, betting, draw, lottery, and notification services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | runtime/pages-256-259-static-audit.json | Atomicity, rollback, and idempotency are not runtime verified. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Python static audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | No transaction execution occurred. |
| 260 | Concurrency Test Harness | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.pages260.concurrency | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | canonical atomicity and idempotency tests | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; DuplicateWebhookIdempotencyTest; FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 261 | Wallet Integrity Activation | /player/wallet | player.wallet | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletService; WalletHoldService; WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /player/wallet | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | wallet and player experience tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 262 | Wallet Ledger Balance Rebuild | finance:reconcile | finance.reconcile | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReconciliationService; LedgerBalanceValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | finance:reconcile | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ReconcileCommandContractTest; FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 263 | Wallet Double-Spend Test | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.pages263.wallet | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 264 | Wallet Hold and Release | app/Services/Finance/WalletHoldService.php | finance.wallet.hold | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletHoldService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WalletHoldService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | finance wallet/hold tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 265 | Wallet Reservation Expiry | app/Services/Finance/WalletReservationService.php | finance.wallet.reservation | service/command | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WalletReservationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | wallet reservation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 266 | Financial Transaction Idempotency | app/Services/Finance/IdempotencyService.php | finance.idempotency | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/IdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment and betting idempotency tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 267 | Ledger Posting Contract | app/Services/Finance/LedgerPostingService.php | finance.ledger.posting | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | LedgerPostingService; LedgerBalanceValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/LedgerPostingService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ledger validator and finance tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 268 | Ledger Reversal Contract | app/Services/Finance/FinancialReversalService.php | finance.ledger.reversal | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReversalService; LedgerPostingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/FinancialReversalService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | financial reversal tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 269 | Financial Reconciliation Execution | finance:reconcile | finance.reconcile | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | ReconcileFinancialRecordsCommand; FinancialReconciliationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | finance:reconcile | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ReconcileCommandContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 270 | Reconciliation Exception Lifecycle | app/DTOs/Finance/ReconciliationDiscrepancy.php | finance.reconciliation.exceptions | service/report | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialReconciliationService; reconciliation DTOs | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/DTOs/Finance/ReconciliationDiscrepancy.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 271 | Deposit Runtime Flow | /api/v1/deposits | api.v1.deposits | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DepositService; PaymentInitiationService; DepositCompletionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/deposits | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PlayerDepositApiTest; SuccessfulDepositCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 272 | Deposit Duplicate Callback | /api/v1/payment/webhook | api.payment.webhook | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/payment/webhook | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DuplicateWebhookIdempotencyTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 273 | Payment Callback Ownership | /admin/payments/{payment} | admin.payments.show | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentCallbackService; object-scoped payment projection | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /admin/payments/{payment} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment access tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 274 | Payment Signature Validation | app/Services/Payment/PaymentWebhookVerificationService.php | payment.webhook.verify | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PaymentWebhookVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | InvalidWebhookSignatureTest; PaymentWebhookSignatureVerificationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 275 | Payment Provider Error Matrix | app/Services/Payment/Drivers | payment.provider.errors | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentGatewayManager and canonical drivers | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/Drivers | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PaymentGatewayManagerTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 276 | Payment State Machine | app/Enums/PaymentStatus.php | payment.state | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | FinancialStateTransitionService; PaymentVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/PaymentStatus.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FailedPaymentStateTest; ExpiredPaymentTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 277 | Payment Event Persistence | app/Models/PaymentWebhook.php | payment.events | HTTP POST/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/PaymentWebhook.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PaymentCallbackServiceTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 278 | Payment Webhook Queue | app/Services/Payment/PaymentWebhookService.php | payment.webhook.queue | queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookService; queue services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PaymentWebhookService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ProductionQueueComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 279 | Payment Dead-Letter Processing | failed_jobs | queue.failed | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | QueueHealthService; failed-job infrastructure | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | failed_jobs | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ProductionQueueComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 280 | Payment Replay | tests/Feature/Payment/WebhookReplayProtectionTest.php | tests.payment.replay | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PaymentWebhookVerificationService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Payment/WebhookReplayProtectionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WebhookReplayProtectionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 281 | Withdrawal Runtime Flow | /api/v1/withdrawals | api.v1.withdrawals | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalService; WithdrawalApprovalService; WithdrawalCompletionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/withdrawals | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest; WithdrawalDestinationValidationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 282 | Withdrawal Duplicate Submission | tests/Feature/Payment/WithdrawalCompletionTest.php | tests.withdrawal.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalService; WalletHoldService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Payment/WithdrawalCompletionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 283 | Withdrawal KYC Gate | app/Services/Compliance/WithdrawalKycGateService.php | withdrawal.kyc | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalKycGateService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/WithdrawalKycGateService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalKycGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 284 | Withdrawal Self-Exclusion and Restriction | app/Services/Compliance/SelfExclusionService.php | withdrawal.restrictions | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SelfExclusionService; ResponsibleGamingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/SelfExclusionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SelfExclusionAndAgentGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 285 | Withdrawal Failure Recovery | app/Services/Finance/WithdrawalCompletionService.php | withdrawal.recovery | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | WithdrawalCompletionService; FinancialReversalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/WithdrawalCompletionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | WithdrawalCompletionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 286 | Withdrawal Completion Reconciliation | app/Services/Finance/PayoutReconciliationService.php | withdrawal.reconciliation | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutReconciliationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/PayoutReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinancialReconciliationComprehensiveTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 287 | Bet Purchase Runtime Activation | /api/v1/bets | api.v1.bets.store | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseService and canonical purchase pipeline | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /api/v1/bets | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseWebTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 288 | Bet Price Authority | app/Services/Betting/BetCalculationService.php | bet.price | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetCalculationService; MarketRuleResolver | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 289 | Bet Currency Authority | app/Enums/Currency.php | bet.currency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Currency enum; BetPurchaseValidator | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/Currency.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payment and betting tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 290 | Bet Limit Enforcement | app/Services/Betting/BetPurchaseRiskService.php | bet.limits | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseRiskService; ResponsibleGamingService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseRiskService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ResponsibleGamingWebTest; SelfExclusionAndAgentGateTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 291 | Bet Concurrency | tests/Feature/Betting/BetPurchaseAtomicityTest.php | tests.bet.concurrency | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 292 | Bet Idempotency | app/Services/Betting/BetPurchaseIdempotencyService.php | bet.idempotency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseIdempotencyService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseIdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseApiTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 293 | Bet Failure Rollback | app/Services/Betting/BetPurchaseTransactionService.php | bet.rollback | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletReservationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseTransactionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 294 | Ticket Issuance Runtime | app/Services/Betting/BetPurchaseTicketService.php | ticket.issuance | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | BetPurchaseTicketService; TicketOwnershipService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/BetPurchaseTicketService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | BetPurchaseAtomicityTest; BetPurchaseWebTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 295 | Ticket Ownership | app/Services/Ticket/TicketOwnershipService.php | ticket.ownership | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketOwnershipService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Ticket/TicketOwnershipService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ticket ownership tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 296 | Ticket Share and QR Security | app/Services/Betting/TicketShareService.php | ticket.share | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketShareService; TicketVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/TicketShareService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | ticket share tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 297 | Ticket Verification Runtime | /ticket/verify | ticket.verification | HTTP GET/POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | TicketVerificationService; PublicResultVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /ticket/verify | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | TicketVerification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 298 | Draw Open and Close Automation | app/Console/Commands/Lottery/TickCommand.php | lottery.tick | CLI/scheduler | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawScheduleService; DrawLifecycleService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Console/Commands/Lottery/TickCommand.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DrawAutomationTest; ScheduleRegistrationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 299 | Draw State Machine | app/Enums/DrawLifecycleState.php | draw.lifecycle | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawLifecycleService; DrawCertificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Enums/DrawLifecycleState.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | draw lifecycle tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 300 | Draw Locking and Cutoff | app/Http/Middleware/EnsureDrawIsOpen.php | draw.cutoff | HTTP | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | EnsureDrawIsOpen; DrawLifecycleService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Http/Middleware/EnsureDrawIsOpen.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | DrawAutomationTest; BetPurchaseAtomicityTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 301 | Result Import Runtime | app/Services/Draw/DrawResultIngestionService.php | result.import | CLI/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultIngestionService; GloResultImportService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultIngestionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest; LaneResultImportContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 302 | Result Provenance Persistence | app/Models/GloResultImport.php | result.provenance | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultImportService; DrawResultIngestionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/GloResultImport.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 303 | Result Duplicate Import | tests/Feature/Glo/GloResultImportTest.php | result.import.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultImportService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Glo/GloResultImportTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 304 | Result Conflict Detection | app/Services/Draw/DrawResultValidator.php | result.conflict | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultValidator; provenance services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultValidator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result validator tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 305 | Result Certification | app/Services/Draw/DrawCertificationService.php | result.certify | HTTP/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawCertificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawCertificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | draw certification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 306 | Result Publication Gate | app/Services/Draw/DrawResultPublicationService.php | result.publish | HTTP/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultPublicationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result publication tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 307 | Result Correction Policy | app/Services/Draw/DrawResultConfirmationService.php | result.correction | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | DrawResultConfirmationService; DrawResultIngestionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/DrawResultConfirmationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | result correction tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 308 | Leading-Zero Integrity | security/weekly-result-integrity/src/canonical.rs | rust.leading_zero | Rust/Laravel/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Rust canonicalization; Laravel result validation | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | security/weekly-result-integrity/src/canonical.rs | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | Rust integrity vectors; result tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 309 | National Lottery Data Lane | app/Services/Lottery | lottery.national | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | National lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | NationalLotteryIntegrationSeamTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 310 | Weekly Lottery Data Lane | app/Services/Lottery | lottery.weekly | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Weekly lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | weekly lottery tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 311 | Mega and Other Product Data Lane | app/Services/Lottery | lottery.product | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Product-specific lottery service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | lottery lane tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 312 | PCSO Data Lane | app/Services/Lottery | lottery.pcso | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PCSO result service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | PcsoLotteryPublicPageTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 313 | GLO L6 Data Lane | app/Services/Lottery/GloL6 | lottery.glo.l6 | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GLO L6 authoritative service family | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6 | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloResultImportTest; GloPublicResultHistoryTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 314 | GLO L6 Purchase Contract | app/Services/Lottery/GloL6PurchaseCapabilityService.php | lottery.glo.l6.purchase | API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6PurchaseCapabilityService; GloL6SalesService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No checkout, purchase, wallet debit, or ticket issuance is fabricated. | Capability and provider gates remain canonical. | canonical audit path or runtime report | NOT_CONFIGURED — FAIL CLOSED | Glo purchase capability tests | NOT_CONFIGURED | Real public GLO L6 purchase contract and enabled provider/capability are not configured. |
| 315 | GLO L6 Ticket Range | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | lottery.glo.l6.range | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6AuthoritativeTicketEngineService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO ticket tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 316 | GLO L6 Pricing Authority | app/Services/Lottery/GloL6PurchaseCapabilityService.php | lottery.glo.l6.pricing | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GLO L6 capability and pricing services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO sales tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 317 | GLO Prize Allocation | app/Services/Lottery/GloPrizeCatalogue.php | lottery.glo.prizes | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeCatalogue; GLO prize services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeCatalogue.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GLO prize tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 318 | GLO Unsold Ticket Prize Scaling | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | lottery.glo.prize.scale | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloL6ProportionalPrizeCalculator; exact money arithmetic | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloL6ProportionalCalculatorTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 319 | GLO Claim Window | app/Services/Lottery/GloPrizeClaimService.php | lottery.glo.claim.window | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 320 | GLO Prize Claim Creation | app/Services/Lottery/GloPrizeClaimService.php | lottery.glo.claim.create | HTTP POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; TicketAuthenticityService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 321 | GLO Claim Duplicate | tests/Feature/Glo/GloPrizeClaimTest.php | tests.glo.claim.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Glo/GloPrizeClaimTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 322 | GLO Claim Age Verification | app/Services/Compliance/KycVerificationService.php | lottery.glo.claim.age | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/KycVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 323 | GLO Claim KYC | app/Services/Compliance/WithdrawalKycGateService.php | lottery.glo.claim.kyc | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Compliance/WithdrawalKycGateService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 324 | GLO Payment Hold | app/Models/GloPrizePaymentHold.php | lottery.glo.payment.hold | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloPrizeClaimService; RealPrizeSettlementService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/GloPrizePaymentHold.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPrizeClaimTest; FinalProductionReadinessTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 325 | GLO Ticket Freeze Runtime | app/Services/Lottery/GloTicketFreezeService.php | lottery.glo.freeze | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloTicketFreezeService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloTicketFreezeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloTicketFreezeTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 326 | GLO Freeze Release | app/Console/Commands/GloExpireFreezes.php | glo.expire-freezes | CLI/scheduler | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloExpireFreezes; GloTicketFreezeService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Console/Commands/GloExpireFreezes.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloTicketFreezeTest; GloConsoleCommandsTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 327 | GLO Frozen Winner Processing | glo:process-frozen-winners | glo.process-frozen-winners | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloProcessFrozenWinners; GloFrozenWinnerService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | glo:process-frozen-winners | No frozen-winner payout or claim is reported. | Command/operator boundary remains canonical. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | GloConsoleCommandsTest; GloFrozenWinnerTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The required PHP command was attempted by the acceptance gate but PHP is unavailable. |
| 328 | GLO Public Result Publication | app/Services/Lottery/GloResultPublicationService.php | lottery.glo.publish | API/CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | GloResultPublicationService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Lottery/GloResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | GloPublicResultHistoryTest; GloResultImportTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 329 | Prize Matching Engine | app/Services/Betting/MarketResultResolver.php | prize.match | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SelectionSettlementResolver; market result services | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Betting/MarketResultResolver.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | settlement and result tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 330 | Prize Settlement Engine | app/Services/Draw/RealPrizeSettlementService.php | prize.settlement | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | RealPrizeSettlementService; PayoutApprovalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Draw/RealPrizeSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | FinalProductionReadinessTest; GloPrizeClaimTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 331 | Prize Payout Idempotency | app/Services/Finance/PayoutBatchService.php | prize.payout.idempotency | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutBatchService; PayoutApprovalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Finance/PayoutBatchService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payout tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 332 | Prize Payout Failure Recovery | app/Services/Payment/PayoutTransferService.php | prize.payout.recovery | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | PayoutTransferService; FinancialReversalService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Payment/PayoutTransferService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | payout and reconciliation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 333 | Agent Commission Runtime | app/Services/Agent/CommissionCalculationService.php | agent.commission.calculate | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | CommissionCalculationService; AgentCommissionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/CommissionCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | CommissionAccrualTest; CommissionCalculationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 334 | Agent Commission Duplicate | tests/Feature/Agent/CommissionIdempotencyTest.php | tests.agent.commission.duplicate | PHPUnit | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentCommissionService; IdempotencyService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | tests/Feature/Agent/CommissionIdempotencyTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | CommissionIdempotencyTest; DuplicateCommissionPreventionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 335 | Agent Settlement Runtime | app/Services/Agent/AgentCommissionSettlementService.php | agent.commission.settlement | service/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentCommissionSettlementService; AgentSettlementService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/AgentCommissionSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | agent settlement tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 336 | Agent Referral Attribution | app/Services/Agent/AgentReferralService.php | agent.referral | authenticated API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentReferralService; AgentPortalController | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Agent/AgentReferralService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | AgentReferralCodeUniquenessTest; UserAgentAttributionTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 337 | Agent Owner Isolation | /agent/referrals/{reference} | agent.referrals.show | HTTP GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AgentReferralService; AgentPortalController | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /agent/referrals/{reference} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | AgentReportingTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 338 | Notification Delivery Runtime | app/Services/Notification/NotificationDispatchService.php | notification.delivery | event/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationDispatchService; NotificationDeliveryService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationDispatchService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 339 | Notification Idempotency | app/Services/Notification/NotificationReceiptService.php | notification.idempotency | service/queue | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationReceiptService; NotificationSuppressionService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationReceiptService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification receipt tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 340 | Notification Preference Enforcement | app/Services/Notification/NotificationPreferenceService.php | notification.preferences | authenticated API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | NotificationPreferenceService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Notification/NotificationPreferenceService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | notification preference tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 341 | Support Case Contract | /support | support.index | GET/POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService; owner-scoped support migration | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest; Pages251To350StaticContractTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 342 | Support Case Creation | /support | support.store | POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService; CreateSupportCaseRequest | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 343 | Support Case Owner Isolation | /support/{reference} | support.show | GET | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService::findForOwner | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support/{reference} | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 344 | Support Case Reply | /support/{reference}/reply | support.reply | POST | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCaseService::reply; ReplySupportCaseRequest | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | /support/{reference}/reply | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | SupportCaseOwnerIsolationTest | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 345 | Support Case Escalation | app/Services/Support/SupportCaseService.php | support.escalation | service | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | AuditLogService; canonical compliance escalation remains separate | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Support/SupportCaseService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | support/compliance escalation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 346 | Support SLA and Age Projection | app/Models/SupportCase.php | support.sla | service/view | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SupportCase timestamps and configured operational policy | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Models/SupportCase.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | support operational tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 347 | Security Event Persistence | app/Services/Security/SecurityEventService.php | security.events | event/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | SecurityEventService; AuthenticationSecurityService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/SecurityEventService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | security event tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 348 | MFA Runtime | app/Services/Security/MfaChallengeService.php | security.mfa | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | MfaChallengeService; SecurityEventService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/MfaChallengeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | MFA security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 349 | Session Revocation | app/Services/Security/UserSessionSecurityService.php | security.sessions.revoke | HTTP/API | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | UserSessionSecurityService; SecurityEventService | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | app/Services/Security/UserSessionSecurityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, and input boundaries remain authoritative. | canonical audit path or runtime report | IMPLEMENTED + STATIC ONLY | session security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Canonical runtime execution remains unavailable. |
| 350 | Final Runtime Acceptance Gate | scripts/pages_251_350_runtime_gate.py | runtime.acceptance | CLI | canonical middleware or runtime gate | canonical policy or authenticated owner boundary | Existing canonical controller/service boundary | canonical request/DTO or bounded command input | Page 350 runtime gate and all canonical domain test suites | canonical DTOs where present | canonical models where present | canonical database tables where present | canonical API or CLI boundary where present | canonical job/event where present | existing canonical view or N/A | existing frontend or N/A | existing stylesheet or N/A | existing translation namespace or N/A | scripts/pages_251_350_runtime_gate.py | No finance or lottery completion claim is made. | Final acceptance remains evidence-bound. | canonical audit path or runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | runtime/page-350-acceptance.json | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP, Composer, database, Redis, browser, provider, and Cargo requirements remain unavailable. |

## Pages 251–350 changed-file manifest

| Path | `# TYPE` | `# PURPOSE` | Dependencies | Security impact | Financial / lottery impact | Test or validation coverage |
|---|---|---|---|---|---|---|
| `scripts/runtime_preflight.py` | Python runtime script | Generate secret-free machine-readable runtime availability evidence. | Python standard library; repository paths. | Does not read or emit secret values. | Read-only; no domain mutation. | Executed; JSON output validated. |
| `runtime/page-251-preflight.json` | JSON runtime evidence | Record Page 251 command, file, configuration-presence, and runtime-boundary observations. | Page 251 preflight script. | Secret-free presence metadata. | No financial claim. | Generated and parsed successfully. |
| `runtime/page-252-dependency-verification.json` | JSON dependency evidence | Record Composer, NPM, lockfile, and audit command results. | Composer/NPM command attempts. | NPM vulnerability findings are visible; no forced upgrade. | No financial claim. | Generated from executed command results. |
| `runtime/page-253-laravel-boot-verification.json` | JSON Laravel evidence | Record the three required Artisan command attempts and blocked result. | PHP/Artisan command boundary. | No runtime authorization claim. | No financial claim. | Generated and parsed successfully. |
| `scripts/pages_251_350_runtime_gate.py` | Python acceptance gate | Execute available Page 350 commands and preserve blocked/failed boundaries. | Python standard library; PHP, Composer, NPM, Cargo when present. | Truncates output; does not intentionally read secrets. | Never marks finance/lottery success without command execution. | Executed; 13-command result JSON generated. |
| `runtime/page-350-acceptance.json` | JSON runtime evidence | Record final gate command status by domain. | Page 350 acceptance script. | Command boundary remains explicit. | No financial or lottery success claim. | Generated and parsed successfully. |
| `scripts/pages_251_350_static_audit.py` | Python static audit | Inspect seeders, factories, migrations, canonical transaction services, and architecture sources. | Python standard library; repository source. | Static only; no secret values. | Does not classify fixtures as production truth. | Executed; 4 seeders, 42 factories, 95 migrations, and 197 service files observed. |
| `runtime/pages-256-259-static-audit.json` | JSON static audit evidence | Record Page 256–259 source inspection facts. | Static audit script. | No runtime security claim. | No runtime financial invariant claim. | Generated and parsed successfully. |
| `RUNTIME-VERIFICATION-REPORT.md` | Markdown runtime report | Record exact Page 251–350 runtime attempts, successes, blockers, and non-claims. | Runtime JSON artifacts and command results. | Explicitly refuses unsupported security claims. | Explicitly refuses unsupported financial/lottery claims. | Static report review. |
| `FINANCIAL-INTEGRITY-REPORT.md` | Markdown finance report | Map financial pages to canonical services, invariants, tests, and blocked runtime boundaries. | Existing finance/payment/betting/prize/agent architecture. | Ownership and idempotency boundaries documented. | No deposit, wallet, bet, withdrawal, prize, payout, or commission success fabricated. | Static source mapping; runtime blocked. |
| `RUST-RUNTIME-REPORT.md` | Markdown Rust report | Record Rust crate, boundary, deterministic vectors, required commands, and blocked runtime. | Existing `security/weekly-result-integrity` crate. | Rust cannot directly mutate wallet state. | No Rust financial authority claim. | Static source inspection; Cargo blocked. |
| `PAGES-251-350-MATRICES.md` | Markdown matrix deliverable | Provide complete 100-row page matrix and route, API, security, financial, lottery, Rust, and runtime matrices. | `audit.md`; canonical source inventory. | Explicit status and runtime boundaries. | Complete financial/lottery integrity mapping without fabricated output. | 100-row matrix scan passed. |
| `database/migrations/2026_09_30_000900_create_support_case_tables.php` | Laravel migration | Create owner-scoped support case and message tables separate from anonymous ContactMessage. | Laravel Schema; users table. | Foreign-key owner scope; internal metadata is not exposed. | No financial mutation. | Static parser; migration runtime blocked. |
| `app/Models/SupportCase.php` | Eloquent model | Represent owner-scoped support case aggregate and opaque public reference. | Support migration; User; SupportMessage. | Hides internal IDs and owner ID; route key is public reference. | No financial mutation. | Static parser; runtime blocked. |
| `app/Models/SupportMessage.php` | Eloquent model | Represent public case messages while hiding internal identifiers and flags. | Support migration; SupportCase; User. | Hides case, sender, and internal fields from array output. | No financial mutation. | Static parser; runtime blocked. |
| `app/Services/Support/SupportCaseService.php` | Domain service | Create, list, read, and reply to owner-scoped support cases transactionally with audit rows. | SupportCase, SupportMessage, User, AuditLogService, DB transactions. | Every read filters authenticated owner; closed cases reject replies. | No financial mutation. | Static contract; runtime blocked. |
| `app/Http/Requests/Support/CreateSupportCaseRequest.php` | Form request | Validate case category, priority, subject, and body. | Laravel FormRequest. | Does not accept owner identity. | No financial mutation. | Static parser; runtime blocked. |
| `app/Http/Requests/Support/ReplySupportCaseRequest.php` | Form request | Validate owner-scoped case reply body. | Laravel FormRequest. | Does not accept owner identity. | No financial mutation. | Static parser; runtime blocked. |
| `app/Http/Controllers/Support/SupportPortalController.php` | HTTP controller | Activate authenticated support case portal and owner-scoped create/detail/reply routes. | SupportCaseService; support requests; User. | Session ownership is resolved from request user; cross-owner cases return 404. | No financial mutation. | Static contract; runtime blocked. |
| `resources/views/support/portal.blade.php` | Blade view | Render case list, case detail, create form, messages, and reply form with accessible labels. | Support translations; support routes; CSRF. | No hidden owner input; escaped values; CSRF forms. | No financial mutation. | Static review; Blade runtime blocked. |
| `lang/en/support.php` | PHP translation map | English support-case labels, validation, state, and category copy. | Laravel translator. | Prevents raw translation keys. | No financial mutation. | EN/TH parity check. |
| `lang/th/support.php` | PHP translation map | Exact key-parity support translation map. | Laravel translator. | Prevents raw translation keys. | No financial mutation. | EN/TH parity check. |
| `routes/web.php` | PHP route file | Adds authenticated support case POST and reply routes while preserving existing contact routes. | SupportPortalController; auth; CSRF; throttle. | Bounded public reference and authenticated owner boundary. | No financial mutation. | Static route scan; Laravel dispatch blocked. |
| `database/factories/SupportCaseFactory.php` | Test factory | Create synthetic owner-scoped support cases for isolated tests only. | SupportCase; User; Laravel factory. | Explicitly test-only; no production seeding. | Synthetic only; not production support data. | Static parser; runtime blocked. |
| `tests/Feature/Support/SupportCaseOwnerIsolationTest.php` | Laravel feature test | Test support creation, owner isolation, replies, closure, and hidden internal identifiers. | RefreshDatabase; SupportCase; User; routes. | Cross-owner reads/replies must 404. | No financial mutation. | Test authored; PHP runtime blocked. |
| `tests/Feature/Pages251To350StaticContractTest.php` | PHP static contract test | Check runtime scripts, support ownership, reports, and all Page 251–350 rows. | Repository files; Laravel test harness. | Detects owner-trust and secret-output regressions. | Detects unsupported financial claims. | Static parser; PHP runtime blocked. |
| `scripts/pages_251_350_audit_matrix.py` | Python matrix generator | Produce exactly 100 audit rows with all required columns. | `audit.md`; Python standard library. | Records security and runtime boundaries per page. | Records financial/lottery boundaries per page. | Executed; 100 ordered rows generated. |
| `scripts/pages_251_350_matrix_document.py` | Python matrix generator | Produce complete Pages 251–350 matrices. | `audit.md`; Python standard library. | Security matrix generated. | Financial and lottery matrices generated. | Executed; 100 page rows generated. |
| `audit.md` | Markdown audit report | Add one factual row per Page 251–350 and the complete phase manifest. | All phase artifacts. | Explicit blocked/NOT_CONFIGURED states. | No fabricated runtime result. | Row count and column scan passed. |

## Pages 251–350 validation results

| Check | Result |
|---|---|
| Page 251 Python preflight | Passed; `runtime/page-251-preflight.json` generated and JSON validated. |
| Page 252 `npm ci` | Passed with exit code 0; 119 packages added and 120 audited. |
| Page 252 `npm audit` | Failed with exit code 1; one moderate and one high vulnerability reported. |
| Page 252 Composer validation/install | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| Page 253 Artisan boot commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| Pages 256–259 static audit | Passed; machine-readable seed, factory, migration, and transaction evidence generated. |
| Page 350 acceptance gate | Executed 13 commands: 2 succeeded, 0 failed after execution, 11 blocked. |
| Support case static contracts | Passed; static PHP parser covered 13 Pages 251–350 changed PHP files, including support files. PHP runtime remains blocked. |
| Python script compilation | Passed for all five Pages 251–350 Python scripts. |
| Runtime JSON validation | Passed for all five generated JSON evidence files. |
| EN/TH support translation parity | Passed. |
| Pages 251–350 page row count | Passed; exactly 100 ordered rows. |
| Prohibited omission-marker scan | Passed for Pages 251–350 scripts, reports, matrices, audit, and changed support files. |
| PHP, Composer, Laravel, database, Redis, queues, browser, providers, Cargo, and Rust runtime | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |




## Pages 351–450 production runtime audit matrix

| Page | Title | Route / Command / Test Target | HTTP Method | Middleware | Authorization | Controller | Request | Service | DTO | Model | Database | API | Job/Event | View | JS | CSS | Translation | Source of Truth | Financial Impact | Security | Audit | Status | Tests | Runtime Status | External Dependency | Remaining Gap |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 351 | Runtime Dependency Closure | scripts/pages_351_450_runtime_preflight.py | CLI | runtime environment | none | preflight script | command inventory | Python preflight | none | none | filesystem/config presence | none | none | none | none | none | none | runtime/page-351-preflight.json | no mutation | secret-free availability metadata | runtime artifact | PARTIALLY VERIFIED | preflight script | PARTIALLY VERIFIED — PRE-FLIGHT ONLY | PHP, Composer, DB, Redis, browser, Rust, providers | Required runtimes remain unavailable |
| 352 | PHP Runtime Activation | php -v; php -m; php --ini | CLI | process environment | none | PHP CLI | none | PHP runtime | none | none | none | none | none | none | none | none | none | command gate JSON | no mutation | extension status not inferred | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351–450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP executable | PHP version and extensions unavailable |
| 353 | Composer Activation | composer validate; composer install; composer check-platform-reqs | CLI | process environment | none | Composer | none | Composer dependency manager | none | none | vendor | none | none | none | none | none | none | command gate JSON | no mutation | dependency gate | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351–450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Composer executable | Composer platform verification unavailable |
| 354 | Laravel Container Activation | php artisan about | CLI | Laravel runtime | application boot boundary | Artisan | none | Laravel container | none | application models | configured DB | none | providers | none | none | none | none | command gate JSON | no mutation | container not claimed | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351–450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor | Container boot unavailable |
| 355 | Route Runtime Activation | php artisan route:list | CLI | Laravel runtime | route middleware/policy | Artisan route list | none | Laravel routing | none | route controllers | none | HTTP routes | none | none | none | none | none | command gate JSON | no mutation | route middleware not claimed | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351–450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor | Duplicate/parameter/middleware runtime check unavailable |
| 356 | Configuration Runtime Audit | php artisan config:show | CLI | Laravel runtime | configuration boundary | Artisan config | none | Laravel configuration | none | none | config/database.php; config/queue.php | none | none | none | none | none | none | command gate JSON | no mutation | secret values excluded | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351–450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor | Cached configuration state unavailable |
| 357 | Application Cache Safety | php artisan optimize:clear; config:cache; route:cache; view:cache | CLI | Laravel cache runtime | deployment/cache boundary | Artisan cache commands | none | Laravel cache commands | none | none | bootstrap/cache | none | none | none | none | none | none | command gate JSON | no financial mutation | unsafe dev config not runtime checked | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351–450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and vendor | Cache safety unavailable |
| 358 | Database Runtime Connection | Laravel database probe | CLI/runtime | DB runtime | database credentials/config | Laravel DB manager | controlled SELECT/INSERT/rollback | database manager | none | canonical models | configured DB | none | none | none | none | none | none | DATABASE-RUNTIME-REPORT.md | financial runtime unverified | no credentials exposed | database report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | database runtime test plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | DB server and PHP | No connection observed |
| 359 | Database Schema Baseline | php artisan migrate:status | CLI | DB runtime | operator/schema boundary | Artisan migration subsystem | none | migration repository | none | schema models | migration tables | none | none | none | none | none | none | database migrations | no schema claim | no production migration run | database report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and DB | Applied/pending state unavailable |
| 360 | Migration Compatibility | controlled migration environment | CLI | DB runtime | migration policy | Artisan migration subsystem | fresh/upgrade/rollback/repeat | migration repository | none | schema models | controlled DB | none | none | none | none | none | none | migration files | no production migration | destructive changes not run | database report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | migration test plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and controlled DB | Compatibility unverified |
| 361 | Fresh Database Build | controlled test database | CLI | DB runtime | test DB allowlist | TestCase/migrations | fresh schema | migration subsystem | none | all schema models | test DB | none | none | none | none | none | none | phpunit.xml and migrations | no production data | test DB isolation | database report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | test schema plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and SQLite/PDO | Fresh build unavailable |
| 362 | Production-Like Database Build | controlled staging database | CLI | DB runtime | staging DB boundary | migrations | schema comparison | migration/schema services | none | canonical models | staging DB | none | none | none | none | none | none | migration/configuration source | no production data | constraints/indexes/collation | database report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | schema audit plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and staging DB | Production-like schema unavailable |
| 363 | Database Seed Safety | database/seeders | CLI/static | DB runtime | seeder classification | seeder runner | safe reference seeders only | seeders | none | reference models | controlled DB | none | none | seeders | none | none | none | static audit JSON | no fixture official data | seed isolation | audit | PARTIALLY VERIFIED | pages_251_350_static_audit.py | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and controlled DB | Seed execution unavailable |
| 364 | Database Constraint Runtime Tests | duplicate payments/tickets/claims/webhooks/commissions | PHPUnit | DB runtime | canonical domain policy | existing canonical tests | synthetic isolated fixtures | canonical services | canonical DTOs | domain models | test DB | domain APIs | events/jobs | none | none | none | none | existing tests | no duplicate financial effects | unique/FK/ownership constraints | audit | PARTIALLY VERIFIED | existing domain test inventory | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and DB | Constraint execution unavailable |
| 365 | Transaction Isolation | financial concurrency tests | PHPUnit | DB runtime | financial service policy | existing canonical tests | synthetic concurrent actors | wallet/ledger/payment/bet services | canonical DTOs | finance models | test DB | financial APIs | financial events | none | none | none | none | financial tests | no double-spend | isolation/locks | audit | PARTIALLY VERIFIED | existing atomicity tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and DB | Isolation unavailable |
| 366 | Deadlock Handling | controlled lock contention | PHPUnit | DB runtime | financial service policy | existing canonical tests | controlled contention | transaction/lock services | none | finance models | test DB | financial APIs | retry events | none | none | none | none | finance tests | no partial financial mutation | retry/rollback | audit | PARTIALLY VERIFIED | existing service tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP and DB | Deadlock scenario unavailable |
| 367 | Redis Runtime | redis-cli --version; Redis probe | CLI/runtime | Redis runtime | Redis credentials/config | Redis client | none | cache/lock services | none | none | Redis | none | queue/cache jobs | none | none | none | none | runtime preflight | no mutation | prefix/TTL/lock values not runtime proven | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Page 351 preflight | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Redis client/server | Redis unavailable |
| 368 | Redis Lock Integrity | wallet/bet/payment/draw locks | PHPUnit | Redis/DB runtime | canonical lock policy | existing service tests | concurrent lock actors | WalletLockService; IdempotencyService; draw services | none | wallet/payment/draw models | Redis/test DB | domain APIs | jobs | none | none | none | none | finance/lottery tests | no duplicate effect | atomic lock | audit | PARTIALLY VERIFIED | existing lock/idempotency tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Redis/PHP/DB | Distributed lock unavailable |
| 369 | Cache Isolation | public/private cache inspection | PHPUnit/browser | Laravel cache runtime | owner/private data policy | cache and controller boundaries | owner-specific cache probes | cache services | none | wallet/payment/KYC models | cache store | HTTP APIs | none | views | frontend | CSS | translations | cache config and controllers | no private data in shared cache | cache key scope | audit | PARTIALLY VERIFIED | cache/security tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/browser/cache | Cache inspection unavailable |
| 370 | Queue Driver Activation | dispatch to worker | CLI/queue | queue runtime | job authorization | queue subsystem | harmless test job | QueueHealthService and jobs | QueueHealthReport | job models | jobs/Redis | queue API | job | none | none | none | none | queue config | no financial job success claim | queue isolation | runtime report | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | queue test plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Redis/worker | Worker unavailable |
| 371 | Queue Worker Startup | controlled worker | CLI | queue runtime | operator worker boundary | worker process | queue names/retry/timeout | queue services | QueueHealthReport | jobs | Redis/database | queue | jobs | none | none | none | none | queue config | no duplicate side effects | graceful shutdown | audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | worker gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Supervisor/Redis/PHP | Worker unavailable |
| 372 | Queue Retry Policy | job definitions | CLI/static | queue runtime | job policy | existing job classes | retry/backoff values | queue jobs | none | job models | queue store | queue | jobs | none | none | none | none | job source | no duplicate finance | retry policy | audit | PARTIALLY VERIFIED | static source mapping | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/queue | Runtime retry unavailable |
| 373 | Failed Jobs | php artisan queue:failed | CLI | queue runtime | operator queue policy | Artisan queue subsystem | deliberate failure | QueueHealthService | QueueHealthReport | failed jobs | queue database | queue API | failed job | admin runtime | none | none | none | command gate | no duplicated financial retry | operator visibility | audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | queue gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/DB/queue | Failed job persistence unavailable |
| 374 | Queue Recovery | worker restart with pending job | CLI/queue | queue runtime | job policy | worker/job subsystem | pending job | queue services | none | job model | queue store | queue | job | none | none | none | none | queue configuration | no duplicate effect | ack/retry | audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | queue recovery plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Worker/Redis/PHP | Recovery unavailable |
| 375 | Scheduler Runtime | php artisan schedule:list | CLI | scheduler runtime | operator command boundary | Artisan scheduler | none | scheduler/config | none | none | none | none | scheduled commands | none | none | none | none | command gate | no automatic finance claim | timezone/locking | audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP | Schedule list unavailable |
| 376 | Scheduler Execution | php artisan schedule:run | CLI | scheduler runtime | scheduler policy | Artisan scheduler | controlled command | scheduler and command services | none | draw/finance models | controlled DB | none | scheduled jobs | none | none | none | none | scheduler config | no duplicate financial command | lock/duplicate-run protection | audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | scheduler plan | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/DB/queue | Scheduler unavailable |
| 377 | Lottery Automation Runtime | app/Console/Commands/Lottery/TickCommand.php | CLI/scheduler | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | Lottery automation commands | Canonical request/DTO or bounded command input | Lottery automation commands | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Console/Commands/Lottery/TickCommand.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 378 | Draw Scheduling Runtime | app/Services/Draw/DrawScheduleService.php | service/CLI | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | DrawScheduleService | Canonical request/DTO or bounded command input | DrawScheduleService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawScheduleService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 379 | Draw Opening Runtime | app/Services/Draw/DrawLifecycleService.php | service/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | DrawLifecycleService | Canonical request/DTO or bounded command input | DrawLifecycleService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawLifecycleService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 380 | Draw Closing Runtime | app/Http/Middleware/EnsureDrawIsOpen.php | HTTP/CLI | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | EnsureDrawIsOpen; DrawLifecycleService | Canonical request/DTO or bounded command input | EnsureDrawIsOpen; DrawLifecycleService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Http/Middleware/EnsureDrawIsOpen.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 381 | Draw Settlement Queue | app/Services/Draw/DrawSettlementSimulationService.php | queue | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | draw settlement services | Canonical request/DTO or bounded command input | draw settlement services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawSettlementSimulationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 382 | Result Import Runtime | app/Services/Draw/DrawResultIngestionService.php | CLI/API | draw/result/provider/lottery boundary | CLI/process/environment boundary | DrawResultIngestionService; GloResultImportService | Canonical request/DTO or bounded command input | DrawResultIngestionService; GloResultImportService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawResultIngestionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 383 | Historical Data Import Framework | app/Services/Lottery/Support/AbstractLotteryImportService.php | CLI/API | draw/result/provider/lottery boundary | CLI/process/environment boundary | canonical import service family | Canonical request/DTO or bounded command input | canonical import service family | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/Support/AbstractLotteryImportService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 384 | Historical Data Provenance | app/Models/GloResultImport.php | service/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | result provenance models/services | Canonical request/DTO or bounded command input | result provenance models/services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Models/GloResultImport.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 385 | Historical Import Rollback | app/Services/Draw/DrawResultIngestionService.php | service/CLI | draw/result/provider/lottery boundary | CLI/process/environment boundary | canonical import/reconciliation services | Canonical request/DTO or bounded command input | canonical import/reconciliation services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawResultIngestionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 386 | National Lottery Historical Import | app/Services/Lottery | CLI/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | National lottery service family | Canonical request/DTO or bounded command input | National lottery service family | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 387 | Weekly Lottery Historical Import | app/Services/Lottery | CLI/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | Weekly lottery service family | Canonical request/DTO or bounded command input | Weekly lottery service family | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 388 | PCSO Historical Import | app/Services/Lottery | CLI/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | PCSO service family | Canonical request/DTO or bounded command input | PCSO service family | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 389 | GLO L6 Historical Import | app/Services/Lottery/GloResultImportService.php | CLI/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | GloResultImportService | Canonical request/DTO or bounded command input | GloResultImportService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloResultImportService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 390 | Historical Result Reconciliation | app/Services/Draw/DrawReconciliationService.php | service/CLI | draw/result/provider/lottery boundary | CLI/process/environment boundary | DrawReconciliationService | Canonical request/DTO or bounded command input | DrawReconciliationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 391 | Public Result API Runtime | routes/api.php | HTTP GET | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | public result controllers/services | Canonical request/DTO or bounded command input | public result controllers/services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | routes/api.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 392 | Result Search Runtime | routes/api.php | HTTP GET | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | result search services | Canonical request/DTO or bounded command input | result search services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | routes/api.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 393 | Result Detail Runtime | routes/api.php | HTTP GET | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | result detail services | Canonical request/DTO or bounded command input | result detail services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | routes/api.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 394 | Year Archive Runtime | routes/web.php | HTTP GET | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | lottery archive services | Canonical request/DTO or bounded command input | lottery archive services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | routes/web.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 395 | Result Publication Runtime | app/Services/Draw/DrawResultPublicationService.php | service/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | DrawResultPublicationService | Canonical request/DTO or bounded command input | DrawResultPublicationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 396 | Result Correction Runtime | app/Services/Draw/DrawResultConfirmationService.php | service/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | DrawResultConfirmationService | Canonical request/DTO or bounded command input | DrawResultConfirmationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawResultConfirmationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 397 | Result Conflict Runtime | app/Services/Draw/DrawResultValidator.php | service/API | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | DrawResultValidator | Canonical request/DTO or bounded command input | DrawResultValidator | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawResultValidator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 398 | Result Certification Runtime | app/Services/Draw/DrawCertificationService.php | service/CLI | draw/result/provider/lottery boundary | CLI/process/environment boundary | DrawCertificationService | Canonical request/DTO or bounded command input | DrawCertificationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawCertificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 399 | Public Cache Invalidation | app/Services/Draw/DrawResultPublicationService.php | event/queue | draw/result/provider/lottery boundary | Canonical operator, owner, provider, or process authorization | publication/cache services | Canonical request/DTO or bounded command input | publication/cache services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/DrawResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 400 | Result Integrity Gate | RUNTIME-VERIFICATION-REPORT.md | CLI/API | draw/result/provider/lottery boundary | CLI/process/environment boundary | import/validate/certify/publish pipeline | Canonical request/DTO or bounded command input | import/validate/certify/publish pipeline | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | RUNTIME-VERIFICATION-REPORT.md | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 401 | Payment Provider Runtime Activation | app/Services/Payment/PaymentGatewayManager.php | HTTP/API | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentGatewayManager | Canonical request/DTO or bounded command input | PaymentGatewayManager | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentGatewayManager.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 402 | Payment Method Availability | app/Services/Payment/PaymentProviderRegistry.php | HTTP GET | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentProviderRegistry | Canonical request/DTO or bounded command input | PaymentProviderRegistry | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentProviderRegistry.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 403 | Deposit Initiation Runtime | app/Services/Payment/PaymentInitiationService.php | HTTP POST | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentInitiationService; DepositService | Canonical request/DTO or bounded command input | PaymentInitiationService; DepositService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentInitiationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 404 | Deposit Callback Runtime | app/Services/Payment/PaymentCallbackService.php | HTTP POST | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentCallbackService; PaymentWebhookService | Canonical request/DTO or bounded command input | PaymentCallbackService; PaymentWebhookService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentCallbackService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 405 | Deposit Completion Runtime | app/Services/Finance/DepositCompletionService.php | service | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | DepositCompletionService | Canonical request/DTO or bounded command input | DepositCompletionService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/DepositCompletionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 406 | Deposit Replay | tests/Feature/Payment/WebhookReplayProtectionTest.php | PHPUnit | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentWebhookService; IdempotencyService | Canonical request/DTO or bounded command input | PaymentWebhookService; IdempotencyService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | tests/Feature/Payment/WebhookReplayProtectionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 407 | Deposit Mismatch | app/Services/Payment/PaymentVerificationService.php | service/API | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentVerificationService | Canonical request/DTO or bounded command input | PaymentVerificationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 408 | Payment Timeout Recovery | app/Services/Payment/PaymentInitiationService.php | service/queue | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | payment provider adapters | Canonical request/DTO or bounded command input | payment provider adapters | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentInitiationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 409 | Payment Failure Recovery | app/Services/Payment/PaymentCallbackService.php | service/API | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentCallbackService; FinancialReversalService | Canonical request/DTO or bounded command input | PaymentCallbackService; FinancialReversalService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentCallbackService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 410 | Payment Reconciliation | app/Services/Payment/PaymentReconciliationService.php | service/CLI | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentReconciliationService | Canonical request/DTO or bounded command input | PaymentReconciliationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 411 | Withdrawal Runtime Activation | app/Services/Finance/WithdrawalService.php | HTTP POST | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | WithdrawalService | Canonical request/DTO or bounded command input | WithdrawalService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/WithdrawalService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 412 | Withdrawal Wallet Hold | app/Services/Finance/WalletHoldService.php | service | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | WalletHoldService | Canonical request/DTO or bounded command input | WalletHoldService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/WalletHoldService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 413 | Withdrawal Approval | app/Services/Finance/WithdrawalApprovalService.php | service/API | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | WithdrawalApprovalService | Canonical request/DTO or bounded command input | WithdrawalApprovalService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/WithdrawalApprovalService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 414 | Withdrawal Provider Transfer | app/Services/Payment/WithdrawalDisbursementService.php | service/API | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | WithdrawalDisbursementService | Canonical request/DTO or bounded command input | WithdrawalDisbursementService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/WithdrawalDisbursementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 415 | Withdrawal Provider Callback | app/Services/Payment/PaymentCallbackService.php | HTTP POST | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PaymentCallbackService; WithdrawalCompletionService | Canonical request/DTO or bounded command input | PaymentCallbackService; WithdrawalCompletionService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Payment/PaymentCallbackService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 416 | Withdrawal Replay | tests/Feature/Payment/WithdrawalCompletionTest.php | PHPUnit | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | WithdrawalCompletionService; IdempotencyService | Canonical request/DTO or bounded command input | WithdrawalCompletionService; IdempotencyService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | tests/Feature/Payment/WithdrawalCompletionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 417 | Withdrawal Failure | app/Services/Finance/WithdrawalCompletionService.php | service/queue | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | WithdrawalCompletionService; FinancialReversalService | Canonical request/DTO or bounded command input | WithdrawalCompletionService; FinancialReversalService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/WithdrawalCompletionService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 418 | Withdrawal Reconciliation | app/Services/Finance/PayoutReconciliationService.php | service/CLI | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | PayoutReconciliationService | Canonical request/DTO or bounded command input | PayoutReconciliationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/PayoutReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 419 | Withdrawal Exception Queue | app/Services/Queue/QueueHealthService.php | queue/admin | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | QueueHealthService; payout reconciliation | Canonical request/DTO or bounded command input | QueueHealthService; payout reconciliation | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Queue/QueueHealthService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 420 | Financial End-to-End Gate | FINANCIAL-INTEGRITY-REPORT.md | PHPUnit/CLI | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | canonical finance pipeline | Canonical request/DTO or bounded command input | canonical finance pipeline | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | FINANCIAL-INTEGRITY-REPORT.md | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 421 | Wallet Balance Runtime Audit | app/Services/Finance/FinancialReconciliationService.php | CLI | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | FinancialReconciliationService; LedgerBalanceValidator | Canonical request/DTO or bounded command input | FinancialReconciliationService; LedgerBalanceValidator | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/FinancialReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 422 | Financial Replay Audit | tests/Feature/Payment/WebhookReplayProtectionTest.php | PHPUnit | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | payment/bet/withdrawal idempotency services | Canonical request/DTO or bounded command input | payment/bet/withdrawal idempotency services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | tests/Feature/Payment/WebhookReplayProtectionTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 423 | Concurrent Financial Operations | tests/Feature/Betting/BetPurchaseAtomicityTest.php | PHPUnit | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | wallet locks/reservations/transactions | Canonical request/DTO or bounded command input | wallet locks/reservations/transactions | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 424 | Financial Reconciliation Report | app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php | CLI | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | FinancialReconciliationService | Canonical request/DTO or bounded command input | FinancialReconciliationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 425 | Financial Exception Resolution | app/Services/Finance/FinancialReconciliationService.php | service/admin | payment/finance/provider/queue boundary | Canonical operator, owner, provider, or process authorization | reconciliation discrepancy lifecycle | Canonical request/DTO or bounded command input | reconciliation discrepancy lifecycle | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/FinancialReconciliationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 426 | Bet Purchase Runtime | app/Services/Betting/BetPurchaseService.php | HTTP POST | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | BetPurchaseService | Canonical request/DTO or bounded command input | BetPurchaseService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/BetPurchaseService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 427 | Bet Price Enforcement | app/Services/Betting/BetCalculationService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | BetCalculationService | Canonical request/DTO or bounded command input | BetCalculationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/BetCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 428 | Bet Fee Enforcement | app/Services/Finance/FeeCalculationService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | FeeCalculationService | Canonical request/DTO or bounded command input | FeeCalculationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/FeeCalculationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 429 | Bet Responsible Gaming Gate | app/Services/Betting/BetPurchaseRiskService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | BetPurchaseRiskService; ResponsibleGamingService | Canonical request/DTO or bounded command input | BetPurchaseRiskService; ResponsibleGamingService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/BetPurchaseRiskService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 430 | Bet Idempotency Runtime | app/Services/Betting/BetPurchaseIdempotencyService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | BetPurchaseIdempotencyService | Canonical request/DTO or bounded command input | BetPurchaseIdempotencyService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/BetPurchaseIdempotencyService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 431 | Bet Concurrency Runtime | tests/Feature/Betting/BetPurchaseAtomicityTest.php | PHPUnit | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | BetPurchaseTransactionService; WalletLockService | Canonical request/DTO or bounded command input | BetPurchaseTransactionService; WalletLockService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | tests/Feature/Betting/BetPurchaseAtomicityTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 432 | Ticket Issuance Runtime | app/Services/Betting/BetPurchaseTicketService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | BetPurchaseTicketService | Canonical request/DTO or bounded command input | BetPurchaseTicketService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/BetPurchaseTicketService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 433 | Ticket Ownership Runtime | app/Services/Ticket/TicketOwnershipService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | TicketOwnershipService | Canonical request/DTO or bounded command input | TicketOwnershipService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Ticket/TicketOwnershipService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 434 | Ticket Verification Runtime | app/Services/Betting/TicketVerificationService.php | HTTP GET/POST | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | TicketVerificationService | Canonical request/DTO or bounded command input | TicketVerificationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/TicketVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 435 | Ticket QR Runtime | app/Services/Betting/TicketShareService.php | HTTP/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | TicketShareService; TicketVerificationService | Canonical request/DTO or bounded command input | TicketShareService; TicketVerificationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Betting/TicketShareService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 436 | Prize Matching Runtime | app/Services/Draw/SelectionSettlementResolver.php | service | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | SelectionSettlementResolver | Canonical request/DTO or bounded command input | SelectionSettlementResolver | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/SelectionSettlementResolver.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 437 | Prize Settlement Runtime | app/Services/Draw/RealPrizeSettlementService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | RealPrizeSettlementService | Canonical request/DTO or bounded command input | RealPrizeSettlementService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Draw/RealPrizeSettlementService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 438 | Prize Payout Runtime | app/Services/Finance/PayoutApprovalService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | PayoutApprovalService; PayoutBatchService | Canonical request/DTO or bounded command input | PayoutApprovalService; PayoutBatchService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Finance/PayoutApprovalService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 439 | Prize Payout Replay | tests/Feature/Glo/GloPrizeClaimTest.php | PHPUnit | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | payout idempotency services | Canonical request/DTO or bounded command input | payout idempotency services | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | tests/Feature/Glo/GloPrizeClaimTest.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 440 | GLO L6 Purchase Capability Reassessment | app/Services/Lottery/GloL6PurchaseCapabilityService.php | API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloL6PurchaseCapabilityService; GloL6SalesService | Canonical request/DTO or bounded command input | GloL6PurchaseCapabilityService; GloL6SalesService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloL6PurchaseCapabilityService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | NOT_CONFIGURED | Existing canonical tests and runtime command gate | NOT_CONFIGURED | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | Re-evaluate only after a real canonical purchase capability and provider are configured; do not invent checkout. |
| 441 | GLO L6 Ticket Engine Runtime | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloL6AuthoritativeTicketEngineService | Canonical request/DTO or bounded command input | GloL6AuthoritativeTicketEngineService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 442 | GLO L6 Prize Calculator Runtime | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | service | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloL6ProportionalPrizeCalculator | Canonical request/DTO or bounded command input | GloL6ProportionalPrizeCalculator | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 443 | GLO Claim Runtime | app/Services/Lottery/GloPrizeClaimService.php | HTTP POST | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloPrizeClaimService | Canonical request/DTO or bounded command input | GloPrizeClaimService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloPrizeClaimService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 444 | GLO Claim Security | app/Services/Compliance/KycVerificationService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloPrizeClaimService; KycVerificationService | Canonical request/DTO or bounded command input | GloPrizeClaimService; KycVerificationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Compliance/KycVerificationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 445 | GLO Freeze Runtime | app/Services/Lottery/GloTicketFreezeService.php | service/API | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloTicketFreezeService | Canonical request/DTO or bounded command input | GloTicketFreezeService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloTicketFreezeService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 446 | GLO Freeze Expiry | app/Console/Commands/GloExpireFreezes.php | CLI | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloExpireFreezes | Canonical request/DTO or bounded command input | GloExpireFreezes | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Console/Commands/GloExpireFreezes.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 447 | GLO Frozen Winner Processing | app/Console/Commands/GloProcessFrozenWinners.php | CLI | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloProcessFrozenWinners; GloFrozenWinnerService | Canonical request/DTO or bounded command input | GloProcessFrozenWinners; GloFrozenWinnerService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Console/Commands/GloProcessFrozenWinners.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 448 | GLO Public Publication | app/Services/Lottery/GloResultPublicationService.php | API/CLI | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GloResultPublicationService | Canonical request/DTO or bounded command input | GloResultPublicationService | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | app/Services/Lottery/GloResultPublicationService.php | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 449 | GLO End-to-End Integrity | LOTTERY-INTEGRITY-REPORT.md | CLI/PHPUnit | bet/ticket/prize/GLO/security boundary | Canonical operator, owner, provider, or process authorization | GLO ticket/result/claim/freeze/payout pipeline | Canonical request/DTO or bounded command input | GLO ticket/result/claim/freeze/payout pipeline | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | LOTTERY-INTEGRITY-REPORT.md | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Existing canonical tests and runtime command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 450 | Enterprise Production Acceptance Gate | scripts/pages_351_450_command_gate.py | CLI | enterprise acceptance boundary | CLI/process/environment boundary | full runtime/finance/lottery/security/ops gate | Canonical request/DTO or bounded command input | full runtime/finance/lottery/security/ops gate | Canonical DTOs where present | Canonical models where present | Canonical database tables where present | Canonical API or CLI boundary where present | Canonical job/event where present | Existing canonical view or N/A | Existing frontend or N/A | Existing stylesheet or N/A | Existing translation namespace or N/A | scripts/pages_351_450_command_gate.py | No financial success or mutation is claimed without execution. | Existing authentication, authorization, ownership, input, signature, and process boundaries remain authoritative. | AuditLog, runtime artifact, or CI evidence where applicable | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | runtime/pages-351-450-command-results.json; CI source inspection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP/Laravel/DB/queue/provider/browser/Rust as applicable | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |

## Cumulative coverage register through Pages 1–450

This register is cumulative and does not replace or delete any historical audit table. `RETAINED` means at least one detailed factual row remains in this audit. `NOT_VERIFIED` means the earlier audit has no detailed row for that page; no implementation or runtime success is asserted by this register.

| Page | Coverage | Evidence boundary |
|---:|---|---|
| 1 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 2 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 3 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 4 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 5 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 6 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 7 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 8 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 9 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 10 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 11 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 12 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 13 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 14 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 15 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 16 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 17 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 18 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 19 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 20 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 21 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 22 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 23 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 24 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 25 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 26 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 27 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 28 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 29 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 30 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 31 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 32 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 33 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 34 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 35 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 36 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 37 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 38 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 39 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 40 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 41 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 42 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 43 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 44 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 45 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 46 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 47 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 48 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 49 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 50 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 51 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 52 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 53 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 54 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 55 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 56 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 57 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 58 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 59 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 60 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 61 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 62 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 63 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 64 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 65 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 66 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 67 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 68 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 69 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 70 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 71 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 72 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 73 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 74 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 75 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 76 | NOT_VERIFIED | No earlier detailed audit row is present; no success is asserted. |
| 77 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 78 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 79 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 80 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 81 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 82 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 83 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 84 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 85 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 86 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 87 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 88 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 89 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 90 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 91 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 92 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 93 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 94 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 95 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 96 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 97 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 98 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 99 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 100 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 101 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 102 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 103 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 104 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 105 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 106 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 107 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 108 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 109 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 110 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 111 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 112 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 113 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 114 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 115 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 116 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 117 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 118 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 119 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 120 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 121 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 122 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 123 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 124 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 125 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 126 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 127 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 128 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 129 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 130 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 131 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 132 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 133 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 134 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 135 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 136 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 137 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 138 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 139 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 140 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 141 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 142 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 143 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 144 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 145 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 146 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 147 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 148 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 149 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 150 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 151 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 152 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 153 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 154 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 155 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 156 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 157 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 158 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 159 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 160 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 161 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 162 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 163 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 164 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 165 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 166 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 167 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 168 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 169 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 170 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 171 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 172 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 173 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 174 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 175 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 176 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 177 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 178 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 179 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 180 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 181 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 182 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 183 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 184 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 185 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 186 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 187 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 188 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 189 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 190 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 191 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 192 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 193 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 194 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 195 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 196 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 197 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 198 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 199 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 200 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 201 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 202 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 203 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 204 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 205 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 206 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 207 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 208 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 209 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 210 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 211 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 212 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 213 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 214 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 215 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 216 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 217 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 218 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 219 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 220 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 221 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 222 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 223 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 224 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 225 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 226 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 227 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 228 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 229 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 230 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 231 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 232 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 233 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 234 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 235 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 236 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 237 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 238 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 239 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 240 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 241 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 242 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 243 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 244 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 245 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 246 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 247 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 248 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 249 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 250 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 251 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 252 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 253 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 254 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 255 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 256 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 257 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 258 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 259 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 260 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 261 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 262 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 263 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 264 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 265 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 266 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 267 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 268 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 269 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 270 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 271 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 272 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 273 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 274 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 275 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 276 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 277 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 278 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 279 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 280 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 281 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 282 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 283 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 284 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 285 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 286 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 287 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 288 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 289 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 290 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 291 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 292 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 293 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 294 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 295 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 296 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 297 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 298 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 299 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 300 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 301 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 302 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 303 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 304 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 305 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 306 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 307 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 308 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 309 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 310 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 311 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 312 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 313 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 314 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 315 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 316 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 317 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 318 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 319 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 320 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 321 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 322 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 323 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 324 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 325 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 326 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 327 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 328 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 329 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 330 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 331 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 332 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 333 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 334 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 335 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 336 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 337 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 338 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 339 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 340 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 341 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 342 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 343 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 344 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 345 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 346 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 347 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 348 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 349 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 350 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 351 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 352 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 353 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 354 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 355 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 356 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 357 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 358 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 359 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 360 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 361 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 362 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 363 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 364 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 365 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 366 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 367 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 368 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 369 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 370 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 371 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 372 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 373 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 374 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 375 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 376 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 377 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 378 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 379 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 380 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 381 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 382 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 383 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 384 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 385 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 386 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 387 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 388 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 389 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 390 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 391 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 392 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 393 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 394 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 395 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 396 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 397 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 398 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 399 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 400 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 401 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 402 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 403 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 404 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 405 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 406 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 407 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 408 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 409 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 410 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 411 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 412 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 413 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 414 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 415 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 416 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 417 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 418 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 419 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 420 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 421 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 422 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 423 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 424 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 425 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 426 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 427 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 428 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 429 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 430 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 431 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 432 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 433 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 434 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 435 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 436 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 437 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 438 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 439 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 440 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 441 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 442 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 443 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 444 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 445 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 446 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 447 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 448 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 449 | RETAINED | At least one detailed factual row is preserved in audit.md. |
| 450 | RETAINED | At least one detailed factual row is preserved in audit.md. |
````

### `PAGES-351-450-MATRICES.md`
# TYPE: Markdown matrices
# PURPOSE: Acceptance ledger plus route/API/security/finance/lottery/Rust/runtime matrices.

````markdown
# TYPE: Pages 351–450 production runtime matrices
# PURPOSE: Map every acceptance page to its execution target and preserve route/API/security/finance/lottery/Rust/runtime boundaries.

## Acceptance page ledger

| Page | Title | Route / Command / Test Target | Status | Runtime Status | Remaining Gap |
|---:|---|---|---|---|---|
| 351 | Runtime Dependency Closure | scripts/pages_351_450_runtime_preflight.py | PARTIALLY VERIFIED | PARTIALLY VERIFIED — PRE-FLIGHT ONLY | Required runtimes remain unavailable |
| 352 | PHP Runtime Activation | php -v; php -m; php --ini | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | PHP version and extensions unavailable |
| 353 | Composer Activation | composer validate; composer install; composer check-platform-reqs | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Composer platform verification unavailable |
| 354 | Laravel Container Activation | php artisan about | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Container boot unavailable |
| 355 | Route Runtime Activation | php artisan route:list | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Duplicate/parameter/middleware runtime check unavailable |
| 356 | Configuration Runtime Audit | php artisan config:show | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Cached configuration state unavailable |
| 357 | Application Cache Safety | php artisan optimize:clear; config:cache; route:cache; view:cache | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Cache safety unavailable |
| 358 | Database Runtime Connection | Laravel database probe | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | No connection observed |
| 359 | Database Schema Baseline | php artisan migrate:status | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Applied/pending state unavailable |
| 360 | Migration Compatibility | controlled migration environment | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Compatibility unverified |
| 361 | Fresh Database Build | controlled test database | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Fresh build unavailable |
| 362 | Production-Like Database Build | controlled staging database | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Production-like schema unavailable |
| 363 | Database Seed Safety | database/seeders | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Seed execution unavailable |
| 364 | Database Constraint Runtime Tests | duplicate payments/tickets/claims/webhooks/commissions | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Constraint execution unavailable |
| 365 | Transaction Isolation | financial concurrency tests | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Isolation unavailable |
| 366 | Deadlock Handling | controlled lock contention | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Deadlock scenario unavailable |
| 367 | Redis Runtime | redis-cli --version; Redis probe | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Redis unavailable |
| 368 | Redis Lock Integrity | wallet/bet/payment/draw locks | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Distributed lock unavailable |
| 369 | Cache Isolation | public/private cache inspection | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Cache inspection unavailable |
| 370 | Queue Driver Activation | dispatch to worker | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Worker unavailable |
| 371 | Queue Worker Startup | controlled worker | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Worker unavailable |
| 372 | Queue Retry Policy | job definitions | PARTIALLY VERIFIED | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Runtime retry unavailable |
| 373 | Failed Jobs | php artisan queue:failed | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Failed job persistence unavailable |
| 374 | Queue Recovery | worker restart with pending job | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Recovery unavailable |
| 375 | Scheduler Runtime | php artisan schedule:list | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Schedule list unavailable |
| 376 | Scheduler Execution | php artisan schedule:run | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Scheduler unavailable |
| 377 | Lottery Automation Runtime | app/Console/Commands/Lottery/TickCommand.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 378 | Draw Scheduling Runtime | app/Services/Draw/DrawScheduleService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 379 | Draw Opening Runtime | app/Services/Draw/DrawLifecycleService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 380 | Draw Closing Runtime | app/Http/Middleware/EnsureDrawIsOpen.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 381 | Draw Settlement Queue | app/Services/Draw/DrawSettlementSimulationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 382 | Result Import Runtime | app/Services/Draw/DrawResultIngestionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 383 | Historical Data Import Framework | app/Services/Lottery/Support/AbstractLotteryImportService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 384 | Historical Data Provenance | app/Models/GloResultImport.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 385 | Historical Import Rollback | app/Services/Draw/DrawResultIngestionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 386 | National Lottery Historical Import | app/Services/Lottery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 387 | Weekly Lottery Historical Import | app/Services/Lottery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 388 | PCSO Historical Import | app/Services/Lottery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 389 | GLO L6 Historical Import | app/Services/Lottery/GloResultImportService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 390 | Historical Result Reconciliation | app/Services/Draw/DrawReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 391 | Public Result API Runtime | routes/api.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 392 | Result Search Runtime | routes/api.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 393 | Result Detail Runtime | routes/api.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 394 | Year Archive Runtime | routes/web.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 395 | Result Publication Runtime | app/Services/Draw/DrawResultPublicationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 396 | Result Correction Runtime | app/Services/Draw/DrawResultConfirmationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 397 | Result Conflict Runtime | app/Services/Draw/DrawResultValidator.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 398 | Result Certification Runtime | app/Services/Draw/DrawCertificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 399 | Public Cache Invalidation | app/Services/Draw/DrawResultPublicationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 400 | Result Integrity Gate | RUNTIME-VERIFICATION-REPORT.md | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 401 | Payment Provider Runtime Activation | app/Services/Payment/PaymentGatewayManager.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 402 | Payment Method Availability | app/Services/Payment/PaymentProviderRegistry.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 403 | Deposit Initiation Runtime | app/Services/Payment/PaymentInitiationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 404 | Deposit Callback Runtime | app/Services/Payment/PaymentCallbackService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 405 | Deposit Completion Runtime | app/Services/Finance/DepositCompletionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 406 | Deposit Replay | tests/Feature/Payment/WebhookReplayProtectionTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 407 | Deposit Mismatch | app/Services/Payment/PaymentVerificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 408 | Payment Timeout Recovery | app/Services/Payment/PaymentInitiationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 409 | Payment Failure Recovery | app/Services/Payment/PaymentCallbackService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 410 | Payment Reconciliation | app/Services/Payment/PaymentReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 411 | Withdrawal Runtime Activation | app/Services/Finance/WithdrawalService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 412 | Withdrawal Wallet Hold | app/Services/Finance/WalletHoldService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 413 | Withdrawal Approval | app/Services/Finance/WithdrawalApprovalService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 414 | Withdrawal Provider Transfer | app/Services/Payment/WithdrawalDisbursementService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 415 | Withdrawal Provider Callback | app/Services/Payment/PaymentCallbackService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 416 | Withdrawal Replay | tests/Feature/Payment/WithdrawalCompletionTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 417 | Withdrawal Failure | app/Services/Finance/WithdrawalCompletionService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 418 | Withdrawal Reconciliation | app/Services/Finance/PayoutReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 419 | Withdrawal Exception Queue | app/Services/Queue/QueueHealthService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 420 | Financial End-to-End Gate | FINANCIAL-INTEGRITY-REPORT.md | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 421 | Wallet Balance Runtime Audit | app/Services/Finance/FinancialReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 422 | Financial Replay Audit | tests/Feature/Payment/WebhookReplayProtectionTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 423 | Concurrent Financial Operations | tests/Feature/Betting/BetPurchaseAtomicityTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 424 | Financial Reconciliation Report | app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 425 | Financial Exception Resolution | app/Services/Finance/FinancialReconciliationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 426 | Bet Purchase Runtime | app/Services/Betting/BetPurchaseService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 427 | Bet Price Enforcement | app/Services/Betting/BetCalculationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 428 | Bet Fee Enforcement | app/Services/Finance/FeeCalculationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 429 | Bet Responsible Gaming Gate | app/Services/Betting/BetPurchaseRiskService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 430 | Bet Idempotency Runtime | app/Services/Betting/BetPurchaseIdempotencyService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 431 | Bet Concurrency Runtime | tests/Feature/Betting/BetPurchaseAtomicityTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 432 | Ticket Issuance Runtime | app/Services/Betting/BetPurchaseTicketService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 433 | Ticket Ownership Runtime | app/Services/Ticket/TicketOwnershipService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 434 | Ticket Verification Runtime | app/Services/Betting/TicketVerificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 435 | Ticket QR Runtime | app/Services/Betting/TicketShareService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 436 | Prize Matching Runtime | app/Services/Draw/SelectionSettlementResolver.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 437 | Prize Settlement Runtime | app/Services/Draw/RealPrizeSettlementService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 438 | Prize Payout Runtime | app/Services/Finance/PayoutApprovalService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 439 | Prize Payout Replay | tests/Feature/Glo/GloPrizeClaimTest.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 440 | GLO L6 Purchase Capability Reassessment | app/Services/Lottery/GloL6PurchaseCapabilityService.php | NOT_CONFIGURED | NOT_CONFIGURED | Re-evaluate only after a real canonical purchase capability and provider are configured; do not invent checkout. |
| 441 | GLO L6 Ticket Engine Runtime | app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 442 | GLO L6 Prize Calculator Runtime | app/Services/Lottery/GloL6ProportionalPrizeCalculator.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 443 | GLO Claim Runtime | app/Services/Lottery/GloPrizeClaimService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 444 | GLO Claim Security | app/Services/Compliance/KycVerificationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 445 | GLO Freeze Runtime | app/Services/Lottery/GloTicketFreezeService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 446 | GLO Freeze Expiry | app/Console/Commands/GloExpireFreezes.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 447 | GLO Frozen Winner Processing | app/Console/Commands/GloProcessFrozenWinners.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 448 | GLO Public Publication | app/Services/Lottery/GloResultPublicationService.php | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 449 | GLO End-to-End Integrity | LOTTERY-INTEGRITY-REPORT.md | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |
| 450 | Enterprise Production Acceptance Gate | scripts/pages_351_450_command_gate.py | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | The underlying runtime operation cannot be observed until the required environment or external dependency is available. |

## Route and API matrix

| Page range | Boundary | Required evidence | Status |
|---|---|---|---|
| 351–357 | PHP, Composer, Laravel boot, route listing, configuration and cache commands | Execute CLI and inspect route/middleware/cache output | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 358–376 | Database, migrations, Redis, queue, scheduler | Connect controlled services; run schema, transaction, lock, worker, retry, and scheduler commands | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 377–400 | Draw lifecycle, result import, historical provenance, certification, publication, correction, conflict, public APIs | Execute canonical draw/result pipeline with genuine source data or isolated test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 401–425 | Payment providers, deposits, callbacks, withdrawals, payout, reconciliation, wallet/ledger | Execute configured provider callbacks, replay/mismatch/failure cases, and ledger reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 426–439 | Bet, responsible gaming, idempotency, concurrency, ticket, prize, payout | Execute server-authoritative purchase-to-settlement flows with controlled test data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 440–449 | GLO capability, ticket engine, prize, claim, age/KYC/freeze/publication | Reassess capability and execute only canonical GLO services; no invented checkout | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 450 | Enterprise gate | Require every underlying evidence package before acceptance | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Security matrix

| Control | Pages | Evidence required | Status |
|---|---:|---|---|
| Authentication, session rotation, MFA, revocation | 351–357, 401–449 | PHP/browser login, session, MFA, replay, and revocation tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| CSRF, IDOR, ownership, authorization | 355, 391–394, 401–449 | Guest/role/cross-owner/cross-case/cross-ticket denial tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Webhook signature, replay, mismatch, timeout | 401–418 | Provider sandbox callback matrix and idempotency assertions | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rate limits and abuse controls | 391–394, 401–449 | Throttled endpoint and retry metadata tests | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Secret/log/error redaction | 351–357, 400, 450 | Runtime logs and error responses contain no secret or unsafe internal data | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| KYC, age, freeze, responsible gaming | 411–413, 426–449 | Denial/allowance tests for financial and GLO sensitive states | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Financial integrity matrix

| Pages | Flow | Required invariant | Status |
|---:|---|---|---|
| 358–366 | Database and transaction | Exact money, atomicity, constraints, isolation, rollback, no double spend | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 367–376 | Redis, queue, scheduler | Lock ownership, idempotent retry, failed-job recovery, single scheduled effect | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 401–410 | Deposits | Verified provider event before one wallet credit; replay/mismatch rejection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 411–419 | Withdrawals | Hold before payout, authorization, one transfer, exact reversal/reconciliation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 420–425 | End-to-end finance | Wallet/ledger balances and reconciliation remain consistent | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 426–439 | Bets and prizes | Server price, fee, RG gate, idempotency, one ticket, one settlement/payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Lottery integrity matrix

| Pages | Pipeline | Required invariant | Status |
|---:|---|---|---|
| 377–381 | Draw lifecycle | Timezone, cutoff, state transition, settlement dispatch, duplicate prevention | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 382–390 | Import and provenance | Genuine source, exact values, duplicate/conflict detection, versioned rollback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 391–400 | Public result surface | Certified/published only, bounded search, safe correction and cache invalidation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 426–439 | Bet/ticket/prize | Ticket ownership, verification, matching, settlement, one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 440–449 | GLO L6 | Canonical capability, exact ticket/prize calculation, claims, freeze, publication | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Rust integrity matrix

| Page | Boundary | Required evidence | Status |
|---:|---|---|---|
| 351 | Cargo/rustc preflight | Executable availability and version | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 400 | Result/integrity boundary | Rust output validated before canonical publication | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 420 | Financial acceptance | No Rust output may authorize money without Laravel validation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 449 | GLO integrity | Leading-zero and malformed-input vectors through the real boundary | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 450 | Release build | `cargo check --locked`, `cargo test --locked`, `cargo build --release --locked` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Runtime and release matrix

| Area | Evidence artifact | Result |
|---|---|---|
| Dependency preflight | `runtime/page-351-preflight.json` | PARTIALLY VERIFIED — preflight executed; required runtimes missing |
| Command gate | `runtime/pages-351-450-command-results.json` | 1 command executed successfully; 25 blocked |
| NPM remediation | `runtime/page-351-dependency-remediation.json` | VERIFIED locally: `npm ci`, `npm audit`, and `npm run build` exit 0 |
| PHP/Laravel | `RUNTIME-VERIFICATION-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Database | `DATABASE-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Finance | `FINANCIAL-INTEGRITY-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Lottery/GLO | `LOTTERY-INTEGRITY-REPORT.md` | NOT_CONFIGURED / BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rust | `RUST-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Security | `SECURITY-RUNTIME-REPORT.md` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| CI/CD source | `CI-CD-VERIFICATION-REPORT.md` | PARTIALLY VERIFIED — workflow source updated; hosted run not executed |
| Enterprise acceptance | Page 450 command gate | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
````

### `RUNTIME-VERIFICATION-REPORT.md`
# TYPE: Markdown report
# PURPOSE: Updated runtime evidence and explicit Pages 351–450 blocker boundary.

````markdown
# TYPE: Runtime verification report
# PURPOSE: Record the actual Pages 251–350 runtime attempts, independent successes, and truthful blocked boundaries.

## Acceptance boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

The workspace has Node.js, NPM, and Python. It does not have PHP, Composer, Cargo, Rust, a browser executable, a Laravel vendor directory, or a configured application `.env` file. No Laravel, database, queue, payment-provider, browser, or Rust result is presented as verified.

## Page 251 — Runtime environment bootstrap

The deterministic preflight was executed:

```text
python3 scripts/runtime_preflight.py
```

The machine-readable result is `/home/user/runtime/page-251-preflight.json`.

Observed command availability:

| Component | Observation |
|---|---|
| Python | AVAILABLE — Python 3.13.14 |
| Node | AVAILABLE — v20.20.2 |
| NPM | AVAILABLE — 10.8.2 |
| PHP | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Composer | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Cargo | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rust compiler | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Playwright command | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Chromium / Google Chrome | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Laravel vendor directory | NOT AVAILABLE |
| Application `.env` | NOT CONFIGURED |
| `.env.example` | PRESENT; values are not runtime credentials |

The preflight reads configuration presence only and emits no secret values.

## Page 252 — Dependency installation verification

The required commands were attempted.

| Command | Result |
|---|---|
| `composer validate` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE — Composer is not installed. |
| `composer install --no-interaction` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE — Composer is not installed. |
| `npm ci` | EXECUTED — exit code 0; 119 packages added and 120 packages audited. |
| `npm audit` | FAILED — exit code 1; one moderate and one high vulnerability were reported. |

No `npm audit fix --force` was run because it could change dependency versions without an approved compatibility decision.

## Page 253 — Laravel boot verification

Each required command was attempted and was blocked because the PHP executable is unavailable.

| Command | Result |
|---|---|
| `php artisan about` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE; exit code 127. |
| `php artisan route:list` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE; exit code 127. |
| `php artisan config:show` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE; exit code 127. |

Container boot, route resolution, configuration loading, and service-provider loading remain unverified.

## Page 254 — Database connection verification

The repository statically contains MySQL, PostgreSQL, and SQLite configuration branches, with strict MySQL mode, UTF-8 configuration, and a file-backed SQLite test configuration in `phpunit.xml`. No database connection was attempted because PHP and the Laravel container are unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

Charset, collation, strict mode, transaction support, and timezone behavior are not runtime verified.

## Page 255 — Migration baseline

`php artisan migrate:status` was included in the Page 350 acceptance gate and was blocked because PHP is unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

The migration files are present, but applied migrations, pending migrations, batch numbers, and production-safe state are not claimed.

## Pages 256–259 — Static independent audits

The executable static audit was run:

```text
python3 scripts/pages_251_350_static_audit.py
```

The machine-readable result is `/home/user/runtime/pages-256-259-static-audit.json`.

Observed inventory:

| Audit | Observed result |
|---|---|
| Seeders | 4 PHP seeders classified for reference-data or fixture review. |
| Factories | 42 factories classified as fixture-only; financial, wallet, result, ticket, KYC, commission, and support domains were identified where source names/content indicated them. |
| Migrations | 95 migration files inspected for foreign-key references, unique constraints, indexes, money columns, enum columns, timestamps, and cascade tokens. |
| Transaction targets | 197 canonical service files inspected for transaction, rollback, idempotency, ledger, wallet, and reservation references. |
| Runtime database execution | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |

The static audit does not classify fixtures as production truth and does not assert that a database invariant passed.

## Page 260 — Concurrency test harness

The repository already contains canonical concurrency and atomicity coverage, including:

- `tests/Feature/Betting/BetPurchaseAtomicityTest.php`
- `tests/Feature/Payment/DuplicateWebhookIdempotencyTest.php`
- `tests/Feature/Payment/WebhookReplayProtectionTest.php`
- `tests/Feature/BusinessCriticalInvariantTest.php`
- `tests/Feature/Finance/FinancialReconciliationComprehensiveTest.php`
- `tests/Feature/FinalWholeSystemNoSkipTest.php`

The Laravel test suite could not execute because PHP, Composer, and `vendor` are unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

## Pages 261–350 — Runtime activation

The Page 350 acceptance gate attempted the independent commands below:

| Result | Count |
|---|---:|
| Executed successfully | 2 |
| Failed | 0 |
| Blocked | 11 |

The two independently executed commands were `npm ci` and `npm run build`. The Vite production build exited with code 0 and generated `public/build/manifest.json`. This is frontend build evidence only; it is not Laravel, finance, payment, lottery, browser, or production evidence.

The blocked commands include PHP version, Composer version, Laravel boot, route listing, migration status, failed queue listing, Laravel tests, Cargo version, Cargo workspace tests, financial reconciliation, and the GLO frozen-winner command.

The complete command result is `/home/user/runtime/page-350-acceptance.json`.

## Explicit non-claims

The following are not verified:

- PHP extensions or Composer package compatibility.
- Laravel container or route dispatch.
- Database connection or migration state.
- Redis connection or queue worker health.
- Payment provider credentials, signatures, callbacks, or settlements.
- Wallet, ledger, deposit, withdrawal, bet, prize, commission, or refund execution.
- Browser journeys, negative browser tests, or accessibility behavior.
- Rust build, Cargo tests, deterministic vectors, malformed-input behavior, timeouts, or resource limits.
- Production readiness.

## Final runtime result

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

## Pages 351–450 continuation

### Page 351 — Runtime dependency closure

The Page 351 preflight was executed at the timestamp recorded in `runtime/page-351-preflight.json`.

Observed available commands:

- Node.js
- NPM
- curl
- Python 3

Observed blocked commands:

- PHP
- Composer
- MySQL
- MariaDB
- Redis CLI
- Supervisor
- Playwright
- Chromium
- Cargo
- rustc

No `.env` secret values were read or emitted. The preflight records configuration presence only.

### Pages 352–360 — PHP, Composer, Laravel, configuration, database, and migration commands

The Page 351–450 command gate attempted the required commands. PHP and Composer were unavailable, so the following remain blocked:

- PHP version, modules, and ini inspection.
- `composer validate`.
- `composer install --no-interaction`.
- `composer check-platform-reqs`.
- `php artisan about`.
- `php artisan route:list`.
- `php artisan config:show`.
- `php artisan optimize:clear`.
- `php artisan config:cache`.
- `php artisan route:cache`.
- `php artisan view:cache`.
- Database probe.
- `php artisan migrate:status`.

No application cache, route cache, view cache, migration, or database mutation was performed because PHP is unavailable.

### Pages 361–400 — Database, queue, scheduler, and lottery runtime

Fresh database builds, production-like database builds, seeder execution, transaction-isolation tests, Redis locks, queue workers, failed jobs, scheduler execution, draw automation, historical imports, result certification, publication, conflict handling, and cache invalidation remain blocked.

The existing canonical services, commands, models, DTOs, enums, events, and tests remain the source of truth. No historical official results or provider data were created.

### Pages 401–449 — Provider, finance, lottery, GLO, security, support, and E2E runtime

Payment-provider activation, deposits, callbacks, withdrawals, payout, reconciliation, bet purchase, ticket verification, prize settlement, GLO claims, freezes, support cases, notifications, MFA, session revocation, browser E2E, accessibility, backup/restore, deployment, and monitoring remain blocked until the required PHP, database, queue, provider, browser, and Rust runtimes are available.

The owner-scoped support case implementation exists and is covered by static contracts and authored feature tests. Its migration and HTTP behavior are not runtime verified.

### Page 450 — Enterprise production acceptance

The Page 351–450 command gate was executed at the timestamps recorded in `runtime/pages-351-450-command-results.json`.

| Result | Count |
|---|---:|
| Executed successfully | 1 |
| Failed after execution | 0 |
| Blocked | 25 |

The one independently executed command was `npm run build`, which exited with code 0. This is frontend build evidence only. It does not establish Laravel, database, queue, payment, lottery, browser, or Rust readiness.

## Final Pages 351–450 runtime state

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

## Dependency remediation observed during Pages 351–450

The Pages 252 NPM findings were investigated. The vulnerable Vite/esbuild dependency path was remediated through a compatible Vite 8 toolchain:

- Vite `8.3.1`.
- Laravel Vite plugin `3.2.0`.
- Fontaine `0.8.2`.

Final local frontend results:

| Command | Result |
|---|---|
| `npm ci` | EXECUTED — exit code 0. |
| `npm audit` | EXECUTED — exit code 0; zero vulnerabilities. |
| `npm run build` | EXECUTED — exit code 0. |

This does not change the PHP/Laravel/database/queue/provider/browser/Rust boundary. The dependency remediation evidence is `/home/user/runtime/page-351-dependency-remediation.json`.
````

### `FINANCIAL-INTEGRITY-REPORT.md`
# TYPE: Markdown report
# PURPOSE: Updated financial activation, provider, replay, reconciliation, and GLO evidence boundary.

````markdown
# TYPE: Financial integrity report
# PURPOSE: Map Pages 259–294 and Pages 319–337 to existing canonical finance, payment, betting, prize, and commission architecture without inventing runtime evidence.

## Boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

The PHP/Laravel test runtime, database, queue, and external payment providers are unavailable in this workspace. The report below distinguishes source-level architecture from executed financial evidence.

## Canonical financial architecture

| Concern | Canonical implementation | Source-level observation | Runtime result |
|---|---|---|---|
| Money arithmetic | `app/Services/Finance/Money.php` | Exact decimal arithmetic is the existing money authority. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Wallet operations | `app/Services/Finance/WalletService.php`, `WalletHoldService.php`, `WalletReservationService.php` | Existing wallet, hold, reservation, and owner-scoped services are reused. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Ledger posting | `app/Services/Finance/LedgerPostingService.php`, `LedgerBalanceValidator.php` | Existing double-entry validator and posting services remain authoritative. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Reconciliation | `app/Services/Finance/FinancialReconciliationService.php`, `app/Console/Commands/Finance/ReconcileFinancialRecordsCommand.php` | Existing read-only reconciliation command has bounded period, currency, batch, JSON, and dry-run options. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit | `DepositService.php`, `DepositApprovalService.php`, `DepositCompletionService.php` | Deposit credit is intended to follow verified payment state and ledger posting. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Payment callbacks | `PaymentCallbackService.php`, `PaymentWebhookService.php`, `PaymentWebhookVerificationService.php` | Signature, replay, and callback services exist; no provider success is inferred here. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal | `WithdrawalService.php`, `WithdrawalApprovalService.php`, `WithdrawalCompletionService.php`, `WithdrawalKycGateService.php` | Hold, KYC gate, approval, provider, completion, and ledger architecture exists. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Betting | `BetPurchaseService.php` and the canonical betting service family | Purchase pipeline and atomicity tests exist. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Prize settlement | `RealPrizeSettlementService.php`, `GloPrizeClaimService.php`, `PayoutApprovalService.php`, `PayoutBatchService.php` | Prize and payout paths remain canonical; no result or payout is fabricated. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Commission | `AgentCommissionService.php`, `CommissionCalculationService.php`, `AgentCommissionSettlementService.php` | Commission and settlement services/tests already exist. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Financial mutation matrix

| Page | Operation | Source of truth | Atomicity / idempotency requirement | Runtime evidence |
|---:|---|---|---|---|
| 259 | Transaction boundary audit | Canonical finance/payment/betting services | DB transaction plus ledger treatment must be observed in execution. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 260 | Concurrent submissions | Existing atomicity/idempotency tests and canonical services | No double debit, double credit, duplicate issuance, or duplicate payout. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 261 | Wallet activation | `WalletService`, wallet models, ledger | Presentation must not become balance authority. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 262 | Balance rebuild/audit | `FinancialReconciliationService`, `LedgerBalanceValidator` | Wallet, ledger-derived, and locked values must be compared without silent repair. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 263 | Concurrent debit | Wallet locking/reservation and bet purchase services | One sufficient balance cannot fund two successful debits. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 264 | Hold/release | `WalletHoldService`, `WalletReservationService` | Holds require explicit terminal state and audit. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 265 | Reservation expiry | `WalletReservationService` | Expiry/release must be idempotent and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 266 | Financial idempotency | `IdempotencyService`, payment/betting idempotency services | Stable references and duplicate-safe replay. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 267 | Ledger posting | `LedgerPostingService`, `LedgerBalanceValidator` | No wallet change without required ledger treatment. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 268 | Ledger reversal | `FinancialReversalService` | Original history remains immutable and reversal references original. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 269 | Reconciliation execution | `finance:reconcile` | Read-only reconciliation report with persisted audit evidence. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 270 | Reconciliation lifecycle | `FinancialReconciliationService` and discrepancy DTOs/enums | Detected, reviewed, accepted/corrected, and resolved states must remain auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 271 | Deposit flow | Payment initiation, callback verification, deposit completion, wallet, ledger | No credit before verified callback. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 272 | Duplicate callback | Webhook verification/idempotency services | Replays have one financial effect. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 273 | Callback ownership | Authenticated payment projection | Session/ownership, not query parameters, controls access. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 274 | Signature validation | `PaymentWebhookVerificationService` | Valid, invalid, tampered, replayed, stale, and malformed cases. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 275 | Provider errors | Gateway manager and adapters | Timeout, provider errors, malformed response, missing transaction, duplicate, and currency mismatch. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 276 | Payment states | Canonical payment enums and services | Only legal enum transitions. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 277 | Payment events | Payment webhook/event models and audit path | Every callback persists the expected internal event state. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 278 | Webhook queue | Queue/job architecture | Queued, retry, success, failure, and terminal failure are observable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 279 | Dead-letter handling | Failed-job and provider-operation architecture | Permanent failures cannot silently disappear. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 280 | Payment replay | Idempotency and webhook services | Safe replay cannot duplicate wallet effects. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 281 | Withdrawal flow | Withdrawal, KYC gate, hold, approval, disbursement, ledger | Hold and payout are one canonical lifecycle. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 282 | Duplicate withdrawal | Withdrawal idempotency and wallet hold | No double hold or payout. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 283 | Withdrawal KYC | `WithdrawalKycGateService` | Verified, pending, failed, and expired states are tested against real records. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 284 | Restriction behavior | Responsible gaming, self-exclusion, compliance services | Only domain-supported restrictions are enforced. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 285 | Withdrawal failure | Completion/reversal/hold services | Provider failure has exact recovery and audit. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 286 | Withdrawal reconciliation | Payout reconciliation and ledger | Provider success must reconcile internally. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 287 | Bet purchase | Canonical purchase pipeline | Selection through ticket, wallet, ledger, and confirmation is atomic. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 288 | Price authority | Server-side betting calculation | Browser price cannot override server price. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 289 | Currency authority | `Currency` enum and payment/betting validators | Unsupported currency is rejected before mutation. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 290 | Betting limits | Responsible gaming and risk services | Single bet, daily wager, restriction, and self-exclusion rules. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 291 | Concurrent betting | Purchase transaction, wallet lock/reservation | One balance cannot satisfy two incompatible successful purchases. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 292 | Bet idempotency | Bet purchase idempotency service | Same idempotency key produces one purchase. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 293 | Bet rollback | Transaction and reservation services | Failure after reservation cannot strand funds. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 294 | Ticket issuance | Bet purchase ticket service | Successful purchase has canonical ticket ownership and no orphan ticket. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 319 | Claim creation | GLO claim service and official result state | Only valid winning ticket/result creates a claim. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 320 | Claim duplicate | GLO claim idempotency/state architecture | One canonical claim. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 321 | Age gate | GLO claim service and date-of-birth migration | Actual age state is required. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 322 | KYC claim gate | KYC verification service | Actual KYC status controls eligibility. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 323 | Payment hold | GLO payment hold model/service | Hold blocks payout until conditions are met. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 324 | Ticket freeze | GLO freeze service/command | Freeze lifecycle is explicit and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 325 | Freeze release | GLO freeze expiry/release command | Release follows canonical domain rules. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 326 | Frozen winner processing | `glo:process-frozen-winners` | Command must be executed against controlled records. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 327 | Public result publication | GLO result publication service | Published state derives from canonical publication. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 328 | Prize matching | Result, winning-rule, ticket, and prize-match services | Frontend never calculates winning outcome. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 329 | Prize settlement | Prize settlement, payout, and ledger services | Gross, deductions, net, settlement, and ledger agree. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 330 | Payout idempotency | Payout approval/batch/payment services | Duplicate payout cannot pay twice. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 331 | Payout failure | Claim state, reversal, provider operation, audit | Claim remains recoverable and auditable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 332 | Commission accrual | Agent commission service | Qualifying event creates one canonical accrual. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 333 | Commission duplicate | Commission idempotency and reversal services | Duplicate event cannot accrue twice. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 334 | Commission settlement | Agent settlement service | Commission payment/settlement is traceable. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 335 | Referral attribution | Agent referral service | Actual canonical referral relation is authoritative. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 336 | Agent owner isolation | Agent portal and policy | Cross-agent access is denied. | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## No unsupported financial claims

No deposit, payment, wallet credit, bet, withdrawal, refund, prize, claim, payout, commission, treasury balance, or provider settlement is reported as successful by this phase.

## Pages 351–450 financial runtime continuation

### Pages 358–366 — Database and concurrency

Database connectivity, isolation, constraint execution, deadlock handling, wallet locks, and concurrent payout tests were not executable because PHP, Laravel, and the controlled database are unavailable.

The source architecture continues to use the existing exact-money, transaction, lock, reservation, ledger, and idempotency services. No new financial authority was introduced.

### Pages 367–376 — Redis, queue, scheduler

Redis CLI, worker startup, queue retry, failed-job persistence, queue recovery, scheduler listing, and scheduler execution are blocked. No queue acknowledgement, retry, failed-job recovery, or scheduled financial command is reported as successful.

### Pages 401–425 — Provider, finance, and reconciliation activation

The following flows remain unexecuted:

| Flow | Required observation | Status |
|---|---|---|
| Payment provider method matrix | Enabled provider, currency, amount bounds, and operation capability | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit initiation | Server validation, provider initiation, and pending state | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit callback | Verified callback before wallet credit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit replay | One financial effect across repeated callbacks | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Deposit mismatch | Amount, currency, reference, and owner rejection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal request | Validation, KYC/RG gate, and wallet hold | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal approval | Canonical operator policy and audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Provider payout | Controlled transfer and callback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Withdrawal replay/failure | One payout and exact recovery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Financial end-to-end | Deposit, wallet, bet, result, prize, withdrawal, ledger | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Wallet audit | Wallet, holds, and ledger-derived amount | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Reconciliation | Real persisted reconciliation report and discrepancy lifecycle | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Bet purchase | Server pricing, fee, RG, wallet, reservation, ticket, ledger | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Prize payout | Canonical settlement and one payout | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| GLO L6 capability | Real configured purchase contract or retained NOT_CONFIGURED state | NOT_CONFIGURED unless canonical capability is enabled |

No payment, deposit, credit, debit, bet, withdrawal, payout, reconciliation, or GLO purchase completion is claimed.
````

### `RUST-RUNTIME-REPORT.md`
# TYPE: Markdown report
# PURPOSE: Updated Cargo/Rust command attempts and Laravel trust-boundary evidence.

````markdown
# TYPE: Rust runtime report
# PURPOSE: Record the Pages 245–250 Rust boundary carried into Pages 251–350 and the actual build/test execution boundary.

## Canonical crate

`security/weekly-result-integrity`

Observed source artifacts:

- `Cargo.toml`
- `Cargo.lock`
- `src/canonical.rs`
- `src/error.rs`
- `src/lib.rs`
- `src/main.rs`
- `tests/integrity.rs`

The source documents an isolated, non-networked stdin/stdout verifier. No parallel Rust crate or alternate financial authority was created.

## Required commands

| Command | Result |
|---|---|
| `cargo --version` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo check` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo test` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo build --release` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `cargo test --workspace` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Integrity boundary

The required trust boundary remains:

```text
Rust output
-> Laravel validation
-> domain decision
```

The following direct boundary is not introduced:

```text
Rust output
-> direct wallet mutation
```

## Determinism

The repository contains deterministic test vectors in `security/weekly-result-integrity/tests/integrity.rs`, including leading-zero-shaped synthetic values. The vectors were not executed because Cargo is unavailable.

Status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

## Malformed input and process safety

The source-level process contract identifies stdin/stdout handling and a short-lived process. The following remain unverified:

- empty input
- oversized input
- malformed JSON
- invalid numbers
- invalid character sets
- unexpected fields
- invalid output
- timeout
- process exit-code handling
- stderr handling
- resource limits
- arbitrary command execution resistance under an actual process

## Final Rust status

Build: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Tests: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Deterministic execution: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Production Rust readiness: not claimed.

## Pages 351–450 Rust continuation

The Page 351 preflight and Page 351–450 command gate both recorded Cargo and rustc as unavailable. The required commands were therefore blocked:

- `cargo --version`
- `cargo check`
- `cargo test`
- `cargo build --release`
- workspace/project-specific vectors
- malformed-input tests
- timeout tests
- process exit-code tests
- resource-boundary tests

The existing crate remains the canonical Rust boundary. No replacement crate, direct wallet authority, or unverified release binary was introduced.

## Rust and Laravel integration status

The required trust path remains:

```text
Rust output
-> Laravel output validation
-> canonical domain decision
```

The following have not been executed:

- Deployed binary/version comparison.
- Leading-zero vectors through DB, API, Blade, and Rust.
- Malformed JSON and invalid-number handling.
- Timeout and non-zero process exit behavior.
- Malformed output rejection by Laravel.
- Resource limits.
- Post-deployment binary checksum verification.

Final status: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.
````

### `LOTTERY-INTEGRITY-REPORT.md`
# TYPE: Markdown report
# PURPOSE: Draw, import, provenance, publication, ticket, prize, claim, freeze, and GLO integrity boundary.

````markdown
# TYPE: Lottery runtime verification report
# PURPOSE: Record Pages 377–400 and 426–449 lottery/GLO runtime boundaries without fabricating results, draws, tickets, claims, prizes, or provider data.

## Final lottery boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

PHP, Laravel, database, queue, provider, browser, and Cargo/Rust runtimes are unavailable. The report maps the canonical source architecture and identifies the exact observations still required.

## Draw and result pipeline

| Page range | Pipeline | Canonical source | Required runtime observation | Result |
|---|---|---|---|---|
| 377–381 | Automation, scheduling, opening, closing, settlement queue | `DrawScheduleService`, `DrawLifecycleService`, scheduler commands, draw enums | Actual scheduled draw date/time, timezone, state transitions, cutoff refusal, eligible settlement dispatch | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 382 | Result import | `DrawResultIngestionService`, `DrawResultValidator`, `GloResultImportService` | Controlled source import, validation, persistence, provenance | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 383–385 | Historical data framework, provenance, rollback | Existing result models/services and import architecture | Genuine source manifest, duplicate detection, dry run, reconciliation, scoped rollback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 386–389 | National, Weekly, PCSO, and GLO L6 lanes | Product-specific models/services/providers | Authenticated source data, lane isolation, leading-zero preservation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 390 | Historical reconciliation | Result source/version/provenance models | Count/date/draw/duplicate/conflict comparison | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 391–394 | Public API, search, detail, year archive | Existing result controllers/services/resources | No unpublished result, bounded search, correct draw relation, missing-result behavior | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 395–400 | Publication, correction, conflict, certification, cache invalidation, consolidated integrity | Certification/publication services, result events, cache | Certified-only publication, versioned correction, conflict state, safe invalidation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

No official historical result, result date, winning number, prize, or publication state was created by this phase.

## Bet and ticket pipeline

| Page range | Required flow | Canonical source | Result |
|---|---|---|---|
| 426–431 | Bet request, server price, fee, RG gate, idempotency, concurrency | `BetPurchaseService`, `BetPurchaseValidator`, `BetCalculationService`, `BetPurchaseRiskService`, `ResponsibleGamingService`, wallet/ledger services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 432–435 | Ticket issuance, ownership, verification, QR/token security | `BetPurchaseTicketService`, `TicketOwnershipService`, `TicketVerificationService`, `TicketShareService` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| 436–439 | Prize matching, settlement, payout, payout replay | result/match/settlement/payout services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

No ticket, bet, winner, prize, or payout is reported as successful.

## GLO L6 integrity

| Page | Canonical boundary | Status | Remaining evidence |
|---:|---|---|---|
| 440 | `GloL6PurchaseCapabilityService`, `GloL6SalesService` | NOT_CONFIGURED unless a real canonical capability/provider is enabled | Do not invent checkout; verify configured capability first. |
| 441 | `GloL6AuthoritativeTicketEngineService` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Execute `000000`, `000001`, `001234`, `999999`, and invalid values. |
| 442 | `GloL6ProportionalPrizeCalculator` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Execute exact decimal proportional calculations. |
| 443 | `GloPrizeClaimService` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Create controlled claim from actual valid test winner. |
| 444 | GLO claim, ticket authenticity, age, KYC, claim-window services | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Execute owner, draw, result, age, KYC, and claim-window gates. |
| 445 | `GloTicketFreezeService` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Create controlled freeze and observe claim/payout effect. |
| 446 | `GloExpireFreezes` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Execute expiry command and observe state. |
| 447 | `GloProcessFrozenWinners`, `GloFrozenWinnerService` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Execute canonical processor without claiming payout success. |
| 448 | `GloResultPublicationService` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Verify authoritative/certified publication. |
| 449 | Full GLO chain | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE | Ticket to ledger path requires DB, provider, queue, and Rust/runtime evidence. |

## Integrity rules preserved

- No browser calculation is treated as authoritative.
- No frontend result is treated as official.
- No fixture result is treated as production truth.
- No numeric coercion is allowed for leading-zero result values.
- No GLO purchase checkout is invented while the canonical capability remains unavailable.
- No payout or claim completion is inferred from an HTTP response alone.
````

### `SECURITY-RUNTIME-REPORT.md`
# TYPE: Markdown report
# PURPOSE: Authentication, authorization, IDOR, CSRF, MFA, webhook, rate-limit, KYC, support, and redaction boundary.

````markdown
# TYPE: Security runtime verification report
# PURPOSE: Record Pages 351–450 security controls, source boundaries, required negative tests, and actual runtime blockers.

## Final security boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Static source contracts and authored tests exist, but PHP, Laravel, database, browser, provider, and queue execution were unavailable.

## Security matrix

| Control | Canonical source | Required runtime test | Result |
|---|---|---|---|
| Authentication | Existing auth controllers, middleware, guards, and session configuration | Guest/member/admin/agent login and denial cases | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Authorization | `AdminAccess`, policies, middleware, controller checks | Authorized role allowed; unauthorized role, non-admin, and guest denied | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| IDOR | Owner-scoped controllers/services and object queries | Cross-owner wallet, deposit, withdrawal, bet, ticket, notification, support, referral, KYC, payment, and claim access | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| CSRF | Web middleware and POST routes | Invalid/missing token on support, payment, withdrawal, and privileged actions | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Session fixation | Auth/session implementation | Session identifier rotation after login and denial after revocation | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| MFA | `MfaChallengeService`, MFA models/events, security event service | Enrollment, valid/invalid challenge, replay, recovery | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Webhook signatures | `PaymentWebhookVerificationService`, `VerifyWebhookSignature` | Valid, invalid, tampered, stale, replayed, and malformed callback | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Rate limits | Existing throttle middleware/configuration | Financial/security-critical endpoint limits and retry metadata | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| KYC access | Existing KYC reviewer/document services and opaque tokens | Wrong account, invalid token, expired token, valid authorized access | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Support ownership | `SupportCaseService`, `SupportPortalController`, `owner_user_id` foreign key | Player A cannot read/reply to Player B; closed case cannot receive reply | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Security events | `SecurityEventService`, `AuthenticationSecurityService` | Login failure, suspicious auth, MFA, session changes, privileged actions | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Log redaction | Existing structured logger/audit redaction services | Scan actual logs for tokens, passwords, payment secrets, KYC documents, unnecessary PII | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Error response safety | Application exception and API response boundaries | Validation, auth, provider, DB, queue, Rust exceptions without stack/SQL/path leakage | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Support case security implementation

The new support case contract:

- Resolves ownership from the authenticated session.
- Requires a bounded opaque public reference.
- Queries cases by `owner_user_id` and public reference together.
- Does not accept owner identity from form or query input.
- Returns 404 for cross-owner access.
- Rejects replies to closed or resolved cases.
- Hides internal model fields from array output.
- Keeps anonymous public `ContactMessage` records outside the private case portal.
- Uses CSRF-protected web POST routes and throttling.

Runtime result: `BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`.

## Secret handling

The Page 351 preflight, Page 351–450 command gate, and runtime reports record only configuration presence, executable availability, command, exit code, and bounded metadata. They do not emit environment values, application keys, payment secrets, webhook secrets, database passwords, or provider credentials.
````

### `DATABASE-RUNTIME-REPORT.md`
# TYPE: Markdown report
# PURPOSE: Database, migration, transaction, backup, restore, and data-activation boundary.

````markdown
# TYPE: Database runtime verification report
# PURPOSE: Record Pages 351–450 database, schema, migration, transaction, backup, restore, and data-activation evidence.

## Final database boundary

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

The repository contains database configuration, migrations, test configuration, and static audit scripts. No PHP/Laravel database connection was possible.

## Runtime attempts

| Command or operation | Result |
|---|---|
| PHP database driver inspection | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Laravel database probe | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| `php artisan migrate:status` | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Fresh test database build | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Production-like schema build | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| SELECT/INSERT/rollback/isolation probe | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Redis connection/locking | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Backup creation/checksum/restore | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Restored application boot | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |
| Restored financial comparison | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE |

## Static observations

- `config/database.php` contains SQLite, MySQL, and PostgreSQL branches.
- MySQL configuration declares `utf8mb4`, `utf8mb4_unicode_ci`, and strict mode.
- `phpunit.xml` declares a file-backed SQLite test database and a test-only environment.
- The Pages 256–259 static audit inspected 95 migration files for foreign keys, unique constraints, indexes, money columns, enum columns, timestamps, and cascade tokens.
- The support-case migration adds owner-scoped `support_cases` and `support_messages` tables, indexes, and foreign-key behavior.
- No production migration was run.
- No historical official data was inserted.
- No wallet, ledger, payment, bet, withdrawal, ticket, result, claim, or payout records were created by this phase.

## Required next database evidence

1. Run `php artisan migrate:status` against a controlled test database.
2. Build a fresh schema from migrations.
3. Run the migration compatibility and constraint tests.
4. Execute controlled transaction/isolation/deadlock tests.
5. Create a controlled backup and checksum.
6. Restore to non-production.
7. Boot Laravel against the restored database.
8. Compare wallet, ledger, payments, bets, withdrawals, and claims before and after restore.

No database health, schema readiness, backup success, restore success, or financial consistency is claimed.
````

### `CI-CD-VERIFICATION-REPORT.md`
# TYPE: Markdown report
# PURPOSE: CI/security source inspection, new release gates, and dependency-remediation evidence.

````markdown
# TYPE: CI/CD verification report
# PURPOSE: Record existing CI/security gates and the Pages 351–450 runtime/Rust contract gate added to CI without claiming hosted CI execution.

## Local status

`BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE`

Hosted GitHub Actions was not executed from the workspace. The workflow source was inspected and updated statically.

## Existing workflow coverage

`.github/workflows/ci.yml` already included:

- PHP 8.3 setup.
- Required PHP extensions including BCMath, PDO SQLite, sodium, zip, and intl.
- Node 20 setup.
- Composer validation and install.
- NPM installation and Vite build.
- PHP syntax checks.
- Runtime-directory checks.
- Model/factory checks.
- Laravel optimization commands.
- Migrations and test suite.
- Result-lane seam checks.

`.github/workflows/security.yml` already included:

- Composer security audit.
- NPM security audit.
- Private-key and live-key scanning.
- Static checks for dangerous PHP execution functions.

## Pages 351–450 CI addition

The CI workflow now includes a `runtime-and-rust-contracts` job that runs in hosted CI:

```text
composer validate --no-check-publish
composer install --prefer-dist --no-interaction --no-progress
composer check-platform-reqs
composer audit --no-interaction
npm ci
npm audit --audit-level=high
npm run build
cargo check --locked
cargo test --locked
cargo build --release --locked
```

The job is intentionally release-blocking for Composer platform failures, Composer advisories, high-severity NPM advisories, frontend build failures, Rust check failures, Rust test failures, and Rust release-build failures.

## Local evidence

| Gate | Local result |
|---|---|
| `npm ci` | Executed successfully. |
| `npm audit` | Failed with one moderate and one high vulnerability. |
| `npm run build` | Executed successfully with exit code 0. |
| Composer validation/install/platform/audit | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| PHP syntax/runtime/test gates | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| Cargo check/test/release build | BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE. |
| Hosted CI workflow | NOT EXECUTED from this workspace. |

## Release interpretation

The CI source now expresses the required Pages 351–450 gates. It does not prove those gates passed. The NPM high-severity finding and missing local PHP/Composer/Cargo remain blockers until remediation and hosted execution produce evidence.

## Dependency remediation update

The previous Page 252 NPM audit finding was investigated rather than force-fixed blindly.

The affected dependency path was:

```text
Vite <= 6.4.2
esbuild <= 0.24.2
```

A compatible upgrade was applied and tested:

- Vite `8.3.1`.
- Laravel Vite plugin `3.2.0`.
- Fontaine `0.8.2`.

Final local commands:

- `npm ci`: VERIFIED — exit code 0.
- `npm audit`: VERIFIED — exit code 0 with zero vulnerabilities.
- `npm run build`: VERIFIED — exit code 0.

Composer/PHP/Rust gates remain blocked locally. The remediation artifact is `/home/user/runtime/page-351-dependency-remediation.json`.
````

### `runtime/page-251-preflight.json`
# TYPE: JSON evidence
# PURPOSE: Timestamped prior-phase preflight rerun evidence.

````json
{
  "browser_configuration_presence": {
    "APP_URL": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "VITE_APP_NAME": {
      "declared_in_file": false,
      "process_environment_present": false
    }
  },
  "commands": {
    "cargo": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "chromium": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "composer": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "google-chrome": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "node": {
      "executable": "/usr/bin/node",
      "exit_code": 0,
      "status": "VERIFIED",
      "version": "v20.20.2"
    },
    "npm": {
      "executable": "/usr/bin/npm",
      "exit_code": 0,
      "status": "VERIFIED",
      "version": "10.8.2"
    },
    "php": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "playwright": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "python3": {
      "executable": "/usr/local/bin/python3",
      "exit_code": 0,
      "status": "VERIFIED",
      "version": "Python 3.13.14"
    },
    "rustc": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    }
  },
  "database_configuration_presence": {
    "DB_CONNECTION": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_DATABASE": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_HOST": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_PASSWORD": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_PORT": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "DB_USERNAME": {
      "declared_in_file": false,
      "process_environment_present": false
    }
  },
  "database_example_configuration_presence": {
    "DB_CONNECTION": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_DATABASE": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_HOST": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_PASSWORD": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_PORT": {
      "declared_in_file": true,
      "process_environment_present": false
    },
    "DB_USERNAME": {
      "declared_in_file": true,
      "process_environment_present": false
    }
  },
  "node_modules": {
    "exists": true,
    "is_directory": true,
    "is_file": false,
    "path": "node_modules"
  },
  "observed_at_utc": "2026-09-30T14:07:45+00:00",
  "purpose": "Pages 251–350 runtime dependency availability without secrets",
  "python": "3.13.14",
  "queue_configuration_presence": {
    "QUEUE_CONNECTION": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "REDIS_HOST": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "REDIS_PASSWORD": {
      "declared_in_file": false,
      "process_environment_present": false
    },
    "REDIS_PORT": {
      "declared_in_file": false,
      "process_environment_present": false
    }
  },
  "repository_root": "/home/user",
  "required_files": [
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "composer.json"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "composer.lock"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "package.json"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "package-lock.json"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "phpunit.xml"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "artisan"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "config/database.php"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "config/queue.php"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "config/cache.php"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "security/weekly-result-integrity/Cargo.toml"
    },
    {
      "exists": true,
      "is_directory": false,
      "is_file": true,
      "path": "security/weekly-result-integrity/Cargo.lock"
    }
  ],
  "runtime_claims": {
    "browser_execution": "NOT_VERIFIED",
    "database_connection": "NOT_VERIFIED",
    "external_provider": "NOT_VERIFIED",
    "queue_worker": "NOT_VERIFIED",
    "redis_connection": "NOT_VERIFIED",
    "rust_execution": "NOT_VERIFIED"
  },
  "secrets_policy": "No secret values are read or emitted.",
  "storage": {
    "path": "storage",
    "status": "VERIFIED"
  },
  "type": "runtime_preflight",
  "vendor": {
    "exists": false,
    "is_directory": false,
    "is_file": false,
    "path": "vendor"
  }
}
````

### `runtime/page-350-acceptance.json`
# TYPE: JSON evidence
# PURPOSE: Timestamped prior-phase Page 350 acceptance-gate rerun evidence.

````json
{
  "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "commands": {
    "Cargo version": {
      "command": [
        "cargo",
        "--version"
      ],
      "domain": "rust",
      "exit_code": null,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Cargo workspace tests": {
      "command": [
        "cargo",
        "test",
        "--workspace"
      ],
      "domain": "rust",
      "exit_code": null,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Composer version": {
      "command": [
        "composer",
        "--version"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "composer executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Financial reconciliation command": {
      "command": [
        "php",
        "artisan",
        "finance:reconcile",
        "--dry-run",
        "--json"
      ],
      "domain": "finance",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Frontend dependency installation": {
      "command": [
        "npm",
        "ci"
      ],
      "domain": "frontend",
      "exit_code": 0,
      "output_preview": "added 139 packages, and audited 140 packages in 3s\n\n33 packages are looking for funding\n  run `npm fund` for details\n\nfound 0 vulnerabilities",
      "output_truncated": false,
      "status": "VERIFIED"
    },
    "Frontend production build": {
      "command": [
        "npm",
        "run",
        "build"
      ],
      "domain": "frontend",
      "exit_code": 0,
      "output_preview": "> build\n> vite build\n\nvite v8.3.1 building client environment for production[three-dot command output]\ntransforming[three-dot command output]\n✓ 167 modules transformed.\nrendering chunks[three-dot command output]\ncomputing gzip size[three-dot command output]\npublic/build/manifest.json                                   24.20 kB │ gzip:  2.88 kB\npublic/build/assets/bingo-lottery-DcRhy8pR.css                1.51 kB │ gzip:  0.68 kB\npublic/build/assets/weekly-lottery-D4pcZSlO.css               1.51 kB │ gzip:  0.68 kB\npublic/build/assets/national-lottery-DUzkSC9_.css             1.96 kB │ gzip:  0.82 kB\npublic/build/assets/contact-CnzCsQ7K.css                      2.86 kB │ gzip:  1.03 kB",
      "output_truncated": true,
      "status": "VERIFIED"
    },
    "GLO frozen winner command": {
      "command": [
        "php",
        "artisan",
        "glo:process-frozen-winners"
      ],
      "domain": "lottery",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel about": {
      "command": [
        "php",
        "artisan",
        "about"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel failed queue listing": {
      "command": [
        "php",
        "artisan",
        "queue:failed"
      ],
      "domain": "queue",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel migration status": {
      "command": [
        "php",
        "artisan",
        "migrate:status"
      ],
      "domain": "database",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel route list": {
      "command": [
        "php",
        "artisan",
        "route:list"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "Laravel test suite": {
      "command": [
        "php",
        "artisan",
        "test"
      ],
      "domain": "tests",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    "PHP version": {
      "command": [
        "php",
        "-v"
      ],
      "domain": "runtime",
      "exit_code": null,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    }
  },
  "finished_at_utc": "2026-09-30T14:07:50+00:00",
  "purpose": "Run available independent commands and preserve exact blocked/failed boundaries.",
  "secrets_policy": "Command output is truncated and no secret values are intentionally read or emitted.",
  "started_at_utc": "2026-09-30T14:07:45+00:00",
  "summary": {
    "blocked": 11,
    "command_count": 13,
    "executed": 2,
    "failed": 0
  },
  "type": "pages_251_350_runtime_acceptance_gate"
}
````

### `runtime/page-351-preflight.json`
# TYPE: JSON evidence
# PURPOSE: Timestamped Page 351–450 dependency and environment preflight evidence.

````json
{
  "commands": {
    "cargo": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "chromium": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "composer": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "curl": {
      "executable": "/usr/bin/curl",
      "exit_code": 0,
      "status": "VERIFIED",
      "version": "curl 8.14.1 (x86_64-pc-linux-gnu) libcurl/8.14.1 OpenSSL/3.5.6 zlib/1.3.1 brotli/1.1.0 zstd/1.5.7 libidn2/2.3.8 libpsl/0.21.2 libssh2/1.11.1 nghttp2/1.64.0 nghttp3/1.8.0 librtmp/2.3 OpenLDAP/2.6.10"
    },
    "mariadb": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "mysql": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "node": {
      "executable": "/usr/bin/node",
      "exit_code": 0,
      "status": "VERIFIED",
      "version": "v20.20.2"
    },
    "npm": {
      "executable": "/usr/bin/npm",
      "exit_code": 0,
      "status": "VERIFIED",
      "version": "10.8.2"
    },
    "php": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "playwright": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "redis-cli": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "rustc": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    },
    "supervisorctl": {
      "executable": null,
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
      "version": null
    }
  },
  "environment_presence": {
    "APP_DEBUG": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "APP_ENV": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "APP_URL": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "CACHE_STORE": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "DB_CONNECTION": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "DB_DATABASE": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "DB_HOST": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "DB_PORT": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "GLO_OFFICIAL_SOURCE_MODE": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "PAYMENT_DEFAULT_GATEWAY": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "PRIZE_PAYOUT_SAFETY_MODE": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "QUEUE_CONNECTION": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "REDIS_HOST": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    },
    "REDIS_PORT": {
      "env_example_declared": true,
      "env_file_declared": false,
      "process_present": false
    }
  },
  "observed_at_utc": "2026-09-30T14:07:50+00:00",
  "purpose": "Timestamped production-runtime dependency evidence without secrets.",
  "required_paths": [
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "composer.json"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "composer.lock"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "package.json"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "package-lock.json"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "artisan"
    },
    {
      "directory": false,
      "exists": false,
      "file": false,
      "path": "vendor"
    },
    {
      "directory": true,
      "exists": true,
      "file": false,
      "path": "node_modules"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "config/database.php"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "config/queue.php"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "config/cache.php"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": "security/weekly-result-integrity/Cargo.toml"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": ".github/workflows/ci.yml"
    },
    {
      "directory": false,
      "exists": true,
      "file": true,
      "path": ".github/workflows/security.yml"
    }
  ],
  "runtime_claims": {
    "browser": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "composer_platform": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "database": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "laravel_container": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "php_extensions": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "providers": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "queue": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "redis": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "rust": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
    "scheduler": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
  },
  "secrets_policy": "Only presence metadata is emitted; secret values are never read or printed.",
  "type": "pages_351_450_runtime_dependency_preflight"
}
````

### `runtime/pages-351-450-command-results.json`
# TYPE: JSON evidence
# PURPOSE: Machine-readable results of all attempted Page 351–450 commands.

````json
{
  "acceptance_boundary": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "commands": [
    {
      "command": [
        "php",
        "-v"
      ],
      "domain": "runtime",
      "exit_code": null,
      "label": "php version",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 351,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "-m"
      ],
      "domain": "runtime",
      "exit_code": null,
      "label": "php modules",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 352,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "--ini"
      ],
      "domain": "runtime",
      "exit_code": null,
      "label": "php ini",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 352,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "composer",
        "validate",
        "--no-check-publish"
      ],
      "domain": "composer",
      "exit_code": null,
      "label": "composer validate",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 353,
      "reason": "composer executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "composer",
        "install",
        "--no-interaction"
      ],
      "domain": "composer",
      "exit_code": null,
      "label": "composer install",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 353,
      "reason": "composer executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "composer",
        "check-platform-reqs"
      ],
      "domain": "composer",
      "exit_code": null,
      "label": "composer platform requirements",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 353,
      "reason": "composer executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "about"
      ],
      "domain": "laravel",
      "exit_code": null,
      "label": "laravel about",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 354,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "route:list"
      ],
      "domain": "laravel",
      "exit_code": null,
      "label": "laravel route list",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 355,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "config:show"
      ],
      "domain": "laravel",
      "exit_code": null,
      "label": "laravel config show",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 356,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "optimize:clear"
      ],
      "domain": "cache",
      "exit_code": null,
      "label": "optimize clear",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 357,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "config:cache"
      ],
      "domain": "cache",
      "exit_code": null,
      "label": "config cache",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 357,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "route:cache"
      ],
      "domain": "cache",
      "exit_code": null,
      "label": "route cache",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 357,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "view:cache"
      ],
      "domain": "cache",
      "exit_code": null,
      "label": "view cache",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 357,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "about"
      ],
      "domain": "database",
      "exit_code": null,
      "label": "database probe through Laravel",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 358,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "migrate:status"
      ],
      "domain": "database",
      "exit_code": null,
      "label": "migration status",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 359,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "redis-cli",
        "--version"
      ],
      "domain": "redis",
      "exit_code": null,
      "label": "redis version",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 367,
      "reason": "redis-cli executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "queue:failed"
      ],
      "domain": "queue",
      "exit_code": null,
      "label": "queue failed listing",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 370,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "schedule:list"
      ],
      "domain": "scheduler",
      "exit_code": null,
      "label": "schedule list",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 375,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "list"
      ],
      "domain": "lottery",
      "exit_code": null,
      "label": "controlled result import command inventory",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 382,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "finance:reconcile",
        "--dry-run",
        "--json"
      ],
      "domain": "finance",
      "exit_code": null,
      "label": "financial reconciliation dry run",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 420,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "glo:process-frozen-winners"
      ],
      "domain": "glo",
      "exit_code": null,
      "label": "GLO frozen winner processor",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 447,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "php",
        "artisan",
        "test"
      ],
      "domain": "tests",
      "exit_code": null,
      "label": "Laravel test suite",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "page": 450,
      "reason": "php executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "npm",
        "run",
        "build"
      ],
      "domain": "frontend",
      "exit_code": 0,
      "label": "frontend production build",
      "observed_at_utc": "2026-09-30T14:07:50+00:00",
      "output_bytes": 9320,
      "page": 450,
      "status": "VERIFIED"
    },
    {
      "command": [
        "cargo",
        "check"
      ],
      "domain": "rust",
      "exit_code": null,
      "label": "cargo check",
      "observed_at_utc": "2026-09-30T14:07:52+00:00",
      "page": 450,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "cargo",
        "test"
      ],
      "domain": "rust",
      "exit_code": null,
      "label": "cargo test",
      "observed_at_utc": "2026-09-30T14:07:52+00:00",
      "page": 450,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    },
    {
      "command": [
        "cargo",
        "build",
        "--release"
      ],
      "domain": "rust",
      "exit_code": null,
      "label": "cargo build release",
      "observed_at_utc": "2026-09-30T14:07:52+00:00",
      "page": 450,
      "reason": "cargo executable is not installed",
      "status": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE"
    }
  ],
  "finished_at_utc": "2026-09-30T14:07:52+00:00",
  "purpose": "Command status evidence without command output or secret values.",
  "secrets_policy": "Command output is not stored; no secret values are intentionally read or emitted.",
  "started_at_utc": "2026-09-30T14:07:50+00:00",
  "summary": {
    "blocked": 25,
    "command_count": 26,
    "executed": 1,
    "failed": 0
  },
  "type": "pages_351_450_command_gate"
}
````

### `runtime/page-351-dependency-remediation.json`
# TYPE: JSON evidence
# PURPOSE: NPM vulnerability investigation, compatible upgrade, audit, and build evidence.

````json
{
  "type": "frontend_dependency_remediation",
  "purpose": "Record the compatible remediation of the Pages 252 NPM audit findings.",
  "observed_at_utc": "2026-09-30T14:02:29Z",
  "previous_findings": {
    "esbuild": {
      "severity": "moderate",
      "advisory_range": "<=0.24.2",
      "remediation_path": "Vite 8 dependency resolution"
    },
    "vite": {
      "severity": "high",
      "advisory_range": "<=6.4.2",
      "remediation_path": "Vite 8.3.1 with the compatible Laravel Vite plugin"
    }
  },
  "changes": {
    "vite": "^8.3.1",
    "laravel-vite-plugin": "^3.2.0",
    "fontaine": "^0.8.2"
  },
  "commands": {
    "npm install --save-dev vite@^8.3.1 laravel-vite-plugin@^3.2.0 fontaine@^0.8.0": {
      "status": "VERIFIED",
      "exit_code": 0
    },
    "npm ci": {
      "status": "VERIFIED",
      "exit_code": 0,
      "result": "139 packages added and 140 packages audited"
    },
    "npm audit": {
      "status": "VERIFIED",
      "exit_code": 0,
      "vulnerabilities": {
        "info": 0,
        "low": 0,
        "moderate": 0,
        "high": 0,
        "critical": 0,
        "total": 0
      }
    },
    "npm run build": {
      "status": "VERIFIED",
      "exit_code": 0,
      "result": "Vite production build completed"
    }
  },
  "compatibility": {
    "node": "20.20.2",
    "vite": "8.3.1",
    "laravel_vite_plugin": "3.2.0",
    "fontaine": "0.8.2"
  },
  "composer": "BLOCKED — REQUIRED RUNTIME COMPONENT UNAVAILABLE",
  "secrets_policy": "No secret values are included."
}
````

