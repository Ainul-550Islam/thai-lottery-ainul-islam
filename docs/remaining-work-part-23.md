# Part23 — Native integrity build, signature downgrade fixes and international identifiers

Date: 2026-10-15. Repository base: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Continuation: File258–File282, 25 complete bodies; cumulative overlay 249 unique paths; next File283.
Apply the overlay to the same pinned repository. It is not a complete repository, dependency, database or binary export.

## Actual application fixes, not only added tests

The Part22 native-verifier gap led to reproducible application defects. This delivery changes the PHP integrity boundary, the real shared importer and the Rust verifier. It does not weaken money, permission, approval, jurisdiction or provider gates.

### 1. A missing half of signature material was silently discarded

Previously PHP sent `public_key_hex` and `signature_hex` to Rust only when both were nonempty strings. A configured trusted key with no signature, or a supplied signature with no trusted key, therefore became an unsigned request. Rust correctly rejected half-supplied material when it actually received it, but the wrapper hid its presence.

Now either a configured key or a supplied signature requires a complete, correctly encoded pair. Incomplete material returns `SIGNATURE_INVALID` with `INCOMPLETE_SIGNATURE_MATERIAL`. Invalid key/signature encodings are refused. A signed request cannot fall back to a PHP-only hash when the binary is missing or disabled, regardless of the optional unsigned setting.

Setting `integrity.required=true` and `integrity.enabled=false` no longer silently accepts a hash. Required verification fails closed with `VERIFIER_DISABLED`.

### 2. Import normalization erased malformed signatures

The real shared importer previously converted a malformed supplied `signature_hex` to null without recording an error. With no configured key, an invalid signature could then be imported and published as unsigned.

Normalization now distinguishes absence/explicit null from supplied malformed material. Empty, non-hex, short, numeric, array and boolean signatures are rejected as `INVALID_SIGNATURE_ENCODING` before draw/result/version writes. Well-formed uppercase or lowercase hex remains supported and is normalized to lowercase. Other result lanes share this defensive normalization; their unsigned behavior and authorization are not replaced by a claim of native Weekly verification.

### 3. Native response parsing trusted truthiness and ignored protocol contradictions

Previously an arbitrary string status and a truthy `acceptable` value could be accepted; process exit status was ignored, a missing fingerprint could evade comparison, and a reply could claim signature verification without signing material. Invalid output from an installed optional binary could also be treated as an acceptable fallback.

The wrapper now checks:

- Allowed status codes, exact boolean acceptability and agreement between them.
- Success exit 0; determinate rejections exit 1 or 2.
- Exact `WKLY1` canonical version, rather than relabeling the existing bytes through configuration or a subprocess reply.
- For success, exact canonical UTF-8 byte length, an equal PHP/native fingerprint and no attached error fields.
- `SIGNED_VERIFIED` only for a complete signature request; `INTEGRITY_HASH_ONLY` only for a genuinely unsigned one.

An installed verifier's invalid protocol or execution failure is a refusal, even when missing-binary fallback is optional. Generic error codes are returned without logging arbitrary process exception text or request material. A trusted executable path remains deployment configuration; this protocol validation does not make an attacker-controlled executable trustworthy.

### 4. A configured weak Ed25519 key could produce false authentication

The original Rust `verify` accepted the synthetic low-order identity public key together with a trivial identity/zero signature. The baseline returned `SIGNED_VERIFIED` even after the result digits changed.

The application now calls `VerifyingKey::verify_strict`, rejecting low-order keys and R points as well as invalid signatures. The regression tests use only synthetic public identity points and local test signatures. This does not demonstrate a forgery against a valid real provider key; the false acceptance required the weak configured key. Valid PHP Sodium signatures still verify in Rust, while a one-digit result change is rejected before database writes.

### 5. Rust incorrectly treated an international identifier's limit as bytes

PHP normalizes the database `VARCHAR(191)` source identifier with a character limit. Rust previously used `identifier.len() > 191`, which is a UTF-8 byte limit. Valid Thai/Bangla identifiers were therefore refused only when the native binary was installed.

Rust now bounds Unicode scalar characters at 191, matching PHP's multibyte normalization and database string semantics. Canonical lengths and hashes remain UTF-8 **byte** based; the `WKLY1` format and existing ASCII fingerprints are unchanged. Tests include a 160-character Thai identifier, a 191-character Bangla identifier, and refusal of a direct native request containing 192 characters. No transliteration, trimming of significant canonical bytes or foreign-exchange behavior was introduced.

## Baselines and fixture correction

Before the fixes, the corrected focused baseline produced **37 tests / 57 assertions / 31 failures**. The same new assertions pass after the changes. The Rust signature/international-identifier baseline had **1 passing / 2 failing** tests: weak-key authentication and the valid Unicode identifier failed; the over-limit refusal already passed.

An earlier new positive test incorrectly read result numbers from the immutable version row instead of the related result row. That was a test fixture/assertion error, not an application defect. It was corrected before the recorded baseline: the original application sources and original verifier were temporarily restored, the corrected baseline was rerun, and the final sources/binary were restored and rebuilt. The recorded 37-case baseline excludes that mistaken assertion as defect evidence. An initial attempt with a partially restored PHPUnit dependency tree emitted include warnings without running tests; that apparent exit 0 is not evidence and was superseded by locked Composer restoration and the valid baseline.

## Native and PHP acceptance

The crate is built with the locked dependencies and Rust/Cargo **1.85.1**. `Cargo.toml` now declares Rust 1.85 rather than 1.75: the existing locked active `zeroize` dependency requires 1.85, and the lock also includes `base64ct` with that requirement. No dependency version or checksum was changed to make the build pass. The manifest's dependency/signing comments now describe the five direct dependencies and verification-only production protocol accurately; no provider private key is configured or delivered.

- **27 Rust tests PASS**: 19 existing canonicalization/cryptography/source-boundary tests, 3 strict-signature/international-identifier tests, and 5 real executable stdin/stdout tests.
- The executable tests cover valid unsigned/hash agreement, malformed JSON, the 64KiB ceiling, invalid UTF-8 and fingerprint disagreement, including their determinate exit codes.
- Cargo formatting and Clippy with `-D warnings` pass; release build passes. Final Cargo test/build/Clippy commands also pass with `--locked --offline` after the initial dependency fetch.
- **100 PHP tests / 648 assertions PASS, zero skips**, invoked with `--fail-on-skipped`: 23 controlled subprocess-protocol cases, 14 actual native/importer cases and the existing 63-case Weekly public suite.
- The controlled protocol executables are deliberate fault-injection fixtures, not cryptographic proof. Native/import tests use the actual rebuilt release binary, PHP Sodium signatures, the real importer and the guarded disposable SQLite database.
- Signature rejection checks confirm zero draws, zero result rows and zero version rows are written. The successful signed import persists the same fingerprint and preserves `001234`, `049`, `09` in the actual result row.
- Signature fixtures are locally generated in memory. They are not approved provider signatures; fixture provenance remains fixture provenance. Hash-only agreement does not establish an official result or authorize a payout.

## Final complete regression and static checks

| Check | Observed final result |
|---|---|
| Complete default suite with release binary available | 2525 tests / 130406 assertions; zero errors/failures; 14 server-only skips; exit 0 |
| Focused Weekly/native acceptance | 100 tests / 648 assertions PASS, zero skips, `--fail-on-skipped` |
| Native Rust tests | 27 PASS, zero failures/ignored |
| Native build/format/Clippy | PASS, warnings denied; final test/build/Clippy also locked/offline |
| All offline Python guard suites | 40 tests PASS |
| PHP syntax | 1656 files PASS |
| Pint | Five files PASS |
| Frontend build | PASS |
| Financial source preflight | PASS; no missing imported application classes |
| Passive source inventory | Regenerated and read-only check PASS |
| CI workflow | YAML/all Bash syntax and native ordering/no-skip invariants PASS; hosted execution not claimed |

The old PHP/native agreement case and all 14 new actual native/import cases execute in this complete run. The remaining 14 skips are the 13 Part22 money-server races and the older shared-server settlement simulation: SQLite does not prove that lock protocol. They are not Rust gaps and not passes; Part22's separate MariaDB evidence covers them historically. No database-writing suites overlapped.

Detailed stdout is in `evidence/runtime-part-23.txt`; `evidence/native-verifier-part-23.json` binds the native source bytes, compiler/build identity and exact focused case outcomes. The final binary's hash is evidence about this local build only, not a claim of cross-platform reproducible binary bytes.

## CI now executes the PHP/native seam

The ordinary PHP job in `.github/workflows/ci.yml` now installs the pinned 1.85.1 toolchain, checks formatting/Clippy, runs Rust tests and builds the release binary **before** PHP regression. Its focused native acceptance explicitly uses `--fail-on-skipped`, so an unavailable binary cannot silently leave the seam untested. The separate pre-existing Rust jobs remain intact.

Workflow YAML, every Bash run block and the build-before-PHP/no-skip invariants were checked locally. The hosted CI workflow has not been executed in this conversation. External dependency audits and all existing CI gates were retained; this delivery is not a claim that every hosted job or external audit has passed.

The passive release inventory now includes every verifier module and the PHP integrity/import boundary, rather than hashing only the stdin shim. Its generated reports point to the latest separate executed evidence instead of presenting Part13 as current. It was regenerated because the bound source bytes changed. `audit.md` and `evidence/release-source-inventory-part-13.json` remain static inventories, not runtime certificates. The separate native evidence records actual executed results; the inventory generator itself does not run Cargo or make a runtime claim.

## Reproduction on the same source

Prerequisites: restore the pinned repository plus this overlay, locked Composer/npm dependencies, PHP 8.4 with required extensions including Sodium/GD/intl/SQLite, and a Rust 1.85.1 toolchain with rustfmt and Clippy. Build browser assets. Use no production `.env`, cached production configuration, customer database or provider secrets.

Run from the repository root:

```bash
composer install --prefer-dist --no-interaction --no-progress
npm ci
npm run build
cargo fmt --check --manifest-path security/weekly-result-integrity/Cargo.toml
cargo clippy --locked --all-targets --manifest-path security/weekly-result-integrity/Cargo.toml -- -D warnings
cargo test --locked --manifest-path security/weekly-result-integrity/Cargo.toml
cargo build --release --locked --manifest-path security/weekly-result-integrity/Cargo.toml
php vendor/bin/phpunit tests/Unit/Lottery/WeeklyIntegrityProtocolTest.php tests/Feature/WeeklyLottery/WeeklyNativeIntegrityBoundaryTest.php tests/Feature/WeeklyLottery/WeeklyLotteryPublicPageTest.php --fail-on-skipped
php vendor/bin/phpunit --stop-on-error --stop-on-failure --display-skipped
```

The repository's PHPUnit bootstrap allowlists its disposable `thai_lottery_test` store and migrates/refreshes/truncates it. Do not run these suites on operational data or alongside another database-writing suite. On a clone without the compiled binary the extra native feature tests honestly skip; the focused CI invocation treats those skips as failure. The local complete regression in this part has the binary available.

Runtime environment files, SQL files, dependencies, compiler caches, `target` output and native executable are not delivered. The binary must be rebuilt after snapshot restore or deployment; `.exe`/cross-platform binary compatibility is not claimed. `WEEKLY_LOTTERY_INTEGRITY_REQUIRED=true` requires deployed native verification even for unsigned imports. Configure a real provider key only after the operator approves that provider/key and canonical-signature contract; the presence of a key now requires valid signatures on that lane, including reimports.

## Preserved boundaries and selected next work

Part22 financial race sources/evidence remain byte-identical and were not rerun in this part. No wallet conversion engine, fee economics, member-transfer switch, prize approval threshold, monetary settlement engine or country/provider authorization was changed here. The 14 server-only SQLite skips are covered by Part22's scoped MariaDB results, not falsely reported as fresh Part23 server execution.

The local Rust gap is now closed: it is built, tested and exercised by PHP. The next operational priority is a separately provisioned disposable target-like queue topology with worker termination/restart/retry injection, checking durable settlement progress and reconciliation under failure, not only two-worker contention. Any concrete defect found there should be fixed in the application.

Actual worldwide operation still needs approved operator/jurisdiction/provider/currency combinations, authentic official-source material and authorized live-provider reconciliation. No live merchant call, customer transfer or universal country approval was supplied or enabled. These fixes make the integrity boundary fail closed and internationally usable without treating a synthetic signature as official authorization.

## Delivery integrity

All 25 delivered bodies are complete. The full native crate source/lock/tests are included, not only a patch fragment. Of 231 previously delivered paths, 224 are unchanged; the ordinary CI workflow, passive inventory generator and its five outputs are revised. Eighteen paths are newly delivered in the numbered overlay, although several already existed in the pinned base repository and are now substantively revised.

ZIP CRC, entry uniqueness, workspace equality, full Markdown body extraction and SHA256 manifests are checked. The 249-file ZIP is the cumulative delivered-source overlay, not the entire repository. Secret-free environment templates from prior parts remain; runtime secrets, runtime-generated/provider signing private keys, raw databases, dependency/cache/build directories and native binaries are excluded.
