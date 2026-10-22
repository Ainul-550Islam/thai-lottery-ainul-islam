# Native Weekly integrity runtime — f40

Historical **f40** checkpoint. F41 also resolves the non-monetary simulation runtime skip with an
admitted shared-file process witness. Current results are in `docs/ci-regression-status.md` and
`docs/simulation-concurrency-runtime-status.md`.

Verified 2026-10-14. This closes the documented **missing native verifier runtime prerequisite**,
not the fifteen unfinished LIVE cursor-settlement backend expectations. No payout/commission automation,
result-publication authority, provider credential or affiliation claim is introduced.

## What was observed before the change

- `WeeklyLotteryPublicPageTest::test_51_php_and_the_native_verifier_agree_or_the_test_is_skipped`
  actually skipped because the Rust executable was absent. Evidence: `native-baseline-skipped-f40.log`.
- The Rust source/lock already existed. No previously observed local Cargo execution supported the old
  runtime report, and its assertion about a hosted green job is not treated as current verification.
- After building the native binary, two new PHP tests revealed a real boundary bug: supplying only a key
  or only a signature was downgraded to HASH_ONLY because PHP omitted both request fields. Both tests
  failed against the original adapter (`native-php-integration-red-f40.log`).

## Safe implementation

1. The build script addresses only `security/weekly-result-integrity`, with a fixed manifest, explicit
   compiler host, explicit target directory and committed lock. It rejects arbitrary arguments and
   missing tools instead of silently skipping. No monetary worker or application command is executed.
2. Formatting, strict Clippy, release-mode Rust tests and a release build must all pass. Cargo.lock is
   checksummed before/after. The produced host binary is compared and atomically installed at the
   existing PHP-configured path; inherited cross-target settings cannot quietly leave a stale binary.
3. Ten new Rust CLI tests exercise the real binary over stdin/stdout, exact JSON/exit codes, invalid UTF-8,
   the 64-KiB input limit, numeric JSON rejection, malformed width, fingerprints and test-key signatures.
4. PHP forwards public key/signature halves independently. Existing Rust incomplete-material rejection
   is preserved; a signature policy error no longer becomes an unsigned native success.
5. Thirty new PHP runtime cases require the actual built binary, without skip/fake-process guards. They
   verify cross-language canonicalization, byte lengths, leading zeroes, actual Sodium-to-Rust signature
   verification, malformed/tampered data, stdin shell isolation and no database/provider/job side effects.
6. CI definitions provision native build/tool components before both unfiltered PHP suites. An isolated
   cross-language job runs the real native and full Weekly PHP class. Six build-contract tests verify
   workflow ordering, safe tooling failure, locked scope and absence of skip-based shortcuts.

## Measured component checks

- **29 Rust tests pass**: 19 existing library/integrity tests and 10 new actual CLI tests.
- `cargo fmt --check`, strict all-target Clippy and locked host release build: pass.
- Unchanged Cargo.lock:
  `afbe3e911b46d630c15b759560e09b78598c369d464de7f6958ee6877d102bbe`.
- Real local executable:
  `4f6257631324bcccfbad4859df29addef1f05965dbd10923d8b8c797379af9d9`.
- Complete existing Weekly class with native available: **63 tests / 542 assertions, all pass, no skips**.
- Native-focused PHP/CI/static-contract regression: **112 tests / 976 assertions, all pass, no skips**.
- Expanded regression: **1,265 tests / 105,444 assertions, all pass, no skips**.
- Unfiltered repository: **2,486 tests / 113,500 assertions, 15 errors, 0 failures, 1 existing skip**.
- The native runtime skip is genuinely closed; only the separate settlement real-concurrency skip remains.

Evidence is in `/home/user/native-build-gate-f40.log`, `/home/user/native-focused-f40.log`,
`/home/user/native-focused-f40.junit.xml` and the baseline logs named above. Whole-repository and expanded
regression results are recorded separately in `docs/ci-regression-status.md` and the failure JSON; a
passing focused group is not a claim that the money backend or the whole repository is green.

## Semantics that did not change

- WKLY1 field order, byte-length prefixes, explicit null tags and result digit widths are unchanged.
- The original Rust production source and dependency lock are unchanged. The new native CLI tests
  are test-only and use public deterministic synthetic signing material, not provider credentials.
- No public signing key or private signing seed is installed in production configuration.
- `INTEGRITY_HASH_ONLY` remains consistency evidence, never signed-authority evidence. A fixture
  `SIGNED_VERIFIED` report is verification against its configured synthetic test key, not an official feed.
- The existing optional/required missing-binary policy is unchanged. A deployment requiring no fallback
  must choose and provision that policy explicitly; this change does not silently enable it.
- Missing-binary tests still deliberately point to a nonexistent path and verify honest refusal/degradation.
  The original native-availability skip guard is unchanged; the real prerequisite is now supplied.
- The verifier checks canonical structure, not the full business calendar. Import/publication policies
  and real-world source trust remain Laravel/operator responsibilities.
- Native verification reads no database, issues no provider HTTP and dispatches no payout/queue job.

## Build and rerun

From any working directory, invoke the script by its actual project path:

```bash
CARGO_HOME="$HOME/.cache/weekly-cargo" bash scripts/build-weekly-native-verifier.sh
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/WeeklyLottery/WeeklyNativeVerifierRuntimeTest.php \
  tests/Feature/WeeklyLottery/WeeklyLotteryPublicPageTest.php \
  tests/Unit/Build/NativeVerifierBuildContractTest.php
```

The command block assumes the project root for its relative paths. The script itself locates its
project from its own file, not the caller's current directory. Rust/Cargo/Clippy/rustfmt and a host C
linker are build prerequisites. The tested Rust compiler is 1.85.1 on x86_64 Linux; a lower MSRV,
other platforms, hosted CI, real provider signatures and deployment/load behavior were not certified.

## Snapshot and complete-file delivery

The six changed/new code files below are delivered beginning to end (1,595 complete lines), not patches:

| Complete path | Lines |
|---|---:|
| `app/Services/Lottery/WeeklyResultIntegrityService.php` | 322 |
| `tests/Feature/WeeklyLottery/WeeklyNativeVerifierRuntimeTest.php` | 269 |
| `tests/Unit/Build/NativeVerifierBuildContractTest.php` | 137 |
| `scripts/build-weekly-native-verifier.sh` | 66 |
| `security/weekly-result-integrity/tests/cli.rs` | 167 |
| `.github/workflows/ci.yml` | 634 |

Full Cargo manifest/lock, existing Rust production sources/tests, the unchanged complete Weekly PHP
class and default config are also included so the native contract is reproducible after a reset.
No method-body placeholder is used. `RUST-RUNTIME-REPORT.md` replaces the stale/unverified report
with actual local evidence and limits.

Compiled `target` output and registry caches are not bundled or snapshot-persisted. Rebuild after a
workspace reset before running the native-required/default PHP suite. The standalone archive restorer
verifies/restores source only and does not install toolchains, compile code, contact a provider or run
an application job. See `docs/part3-bundle-usage.md` for the complete restoration sequence.

## Still unfinished

The original fifteen cursor/run/resume/finalize/LIVE errors remain outside this non-monetary runtime
closure. No test is disabled or relabelled to hide them. The f41 shared-file IMMEDIATE simulation witness subsequently executes the old concurrency case
without admitting unsupported memory/deferred environments; see `docs/simulation-concurrency-runtime-status.md`. No extra backend permission is inferred from the continuation request.
