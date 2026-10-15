# Weekly native integrity verifier — local runtime report

Verified 2026-10-14, f40. **Local build/test and synthetic PHP/Rust cross-checks executed successfully.**
This is **not** certification of a live result feed, an official provider, hosted CI or production readiness.
The earlier “Cargo unavailable/not executed” local status no longer describes this measured environment.

## Exact component and toolchain

- Only `security/weekly-result-integrity` was built. The separate monetary settlement-worker crate was not built or enabled.
- Rust/Cargo **1.85.1**, Clippy **0.1.85**, host `x86_64-unknown-linux-gnu`.
- PHP **8.4.26**, including Sodium for public deterministic test-vector signatures.
- Committed Cargo.lock SHA-256:
  `afbe3e911b46d630c15b759560e09b78598c369d464de7f6958ee6877d102bbe`.
- Actual local host release executable SHA-256:
  `4f6257631324bcccfbad4859df29addef1f05965dbd10923d8b8c797379af9d9`.

These hashes identify observed inputs/artifact, not a distributor signature or a cross-host reproducibility guarantee.
The manifest's declared lower Rust MSRV was not separately tested; the observed compiler is 1.85.1.

## Observed gates

`bash scripts/build-weekly-native-verifier.sh` completed with exit 0:

1. `cargo fmt --check`: pass.
2. Locked all-target Clippy with `-D warnings`: pass.
3. Locked release-mode tests: **29 pass** — 19 existing library/integrity cases plus 10 real CLI cases.
4. Locked host release build: pass.
5. Lock-file checksum unchanged; host executable copied atomically to the configured PHP integration path.

Evidence: `/home/user/native-build-gate-f40.log`. Initial independent test/build outputs are retained
in `/home/user/native-cargo-{test,clippy,build}-f40.log`.

The unchanged former skipped PHP test now runs: the complete `WeeklyLotteryPublicPageTest` class
passed **63 tests / 542 assertions with zero skips** after the build. Before provisioning, its native
case genuinely skipped (`native-baseline-skipped-f40.log`). No skip guard was disabled.

New mandatory PHP native tests use the actual subprocess, not a fake executable. They compare leading
zeroes, UTF-8 byte lengths, null/empty values and field order; public synthetic PHP Sodium signatures
are verified independently by Rust. Numeric, partial, malformed, oversized and tampered values are refused.
Full current PHP measurements are in `docs/ci-regression-status.md` and the matching JUnit evidence.

## PHP boundary defect found through execution

Two new tests failed before the PHP fix: a configured key with no signature, or a signature with no
configured key, was silently stripped into an unsigned HASH_ONLY native request. The PHP adapter now
forwards each supplied half independently. The existing Rust protocol returns
`SIGNATURE_INVALID / INCOMPLETE_SIGNATURE_MATERIAL`, not an unsigned success.

Evidence: `/home/user/native-php-integration-red-f40.log`. The optional missing-binary policy and
production `required` default are unchanged; strict deployments must make their own reviewed policy choice.

## Meaning of a successful report

A hash match is byte consistency only. `INTEGRITY_HASH_ONLY` is not a signature or source authentication.
`SIGNED_VERIFIED` in these tests means verification under the explicitly supplied **public synthetic test key**,
not proof that any government/lottery/provider signed a real result. The binary exposes verification only;
it has no signing endpoint, wallet, payout, KYC, authentication, database or networking API.

No real provider payload/signing authority was independently verified here. No signing credential was
provisioned, no official affiliation was asserted, and no result import/publication or money movement
was performed by the build gate. No hosted pipeline execution or cross-host/load certification is claimed.

## Reproduce after a reset

```bash
# Debian example; reviewed administrator/runtime setup, not an application migration.
sudo apt-get install rustc cargo rust-clippy rustfmt
CARGO_HOME="$HOME/.cache/weekly-cargo" bash scripts/build-weekly-native-verifier.sh
php -d memory_limit=-1 vendor/bin/phpunit \
  tests/Feature/WeeklyLottery/WeeklyNativeVerifierRuntimeTest.php \
  tests/Feature/WeeklyLottery/WeeklyLotteryPublicPageTest.php
```

Compiled `target` output and dependency caches are deliberately not in the source bundle or persisted
snapshots. Rebuild the native executable before the native-required/default full test suite after a reset.
Do not replace the binary with a stub or skip tests. Current CI definitions provision Rust/build gates
before full PHP suites; those workflow changes were contract-tested locally, not executed on hosted CI.
