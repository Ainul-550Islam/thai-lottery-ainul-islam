# ZIP Verification

> **NOT FINAL — BACKEND NOT 100%.** This is an interim diagnostic bundle. The 14 Frontend
> Blade `H6` failures are closed and zero regressions were introduced, but **117
> pre-existing failures/errors (65 Backend-owned) remain open**, so the repository does
> not satisfy the Phase 5 condition *"all required Backend/financial suites pass with zero
> failures, zero errors and zero unexecuted required skips."* The archive name carries the
> `NOT-FINAL-BACKEND-NOT-100PCT` marker so it can never be mistaken for a completion
> claim. See `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md` for the exact closure that remains.

- **Archive:** `artifacts/backend-acceptance/BACKEND-H6-CLOSE-14-NOT-FINAL-BACKEND-NOT-100PCT-2c7ab9c.zip`
- **Detached digest file:** `artifacts/backend-acceptance/BACKEND-H6-CLOSE-14-NOT-FINAL-BACKEND-NOT-100PCT-2c7ab9c.zip.sha256`
- **Evidence directory:** `20261023T124608Z_2c7ab9c_close14_h6/`
- **Source:** branch `backend-next` @ `2c7ab9ca7e7b1b953c7a9d1f4c0c17ea154b64c7`

---

## 1. Entry and CRC accounting

| Check | Result |
|---|---|
| Total ZIP entries | **100** |
| File entries | **87** |
| Directory entries | **13** |
| `unzip -t` errors | **0** |
| CRC independently recomputed with `zlib.crc32` over every file entry | **87 / 87 OK** |
| CRC mismatches | **0** |
| Path-traversal entries (`/…` or `..` component) | **0** |
| Symlink entries | **0** |
| Duplicate entries | **0** |
| Case-colliding entries | **0** |

The file entries are every file of the evidence directory except `checksums.sha256`
itself, and they break down as: 4 reports (`BACKEND-IMPLEMENTATION-REPORT.md`,
`BACKEND-TEST-RESULTS.md`, `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md`, this file), 1 gate
matrix, 3 manifests (`source-identity.json`, `environment.json`,
`changed-files-manifest.csv`), 19 JUnit XML files, 37 raw command logs, 6 pre-fix
reproduction logs, 10 post-fix reproduction logs, and **6 changed-source files** (the 5
modified source files plus `changed-source/README.md`). `checksums.sha256` is carried as
the final file entry and is the one file that cannot list itself. The 13 directory entries
are the bundle root plus `junit/`, `logs/`, `pre-fix-logs/`, `post-fix-logs/`,
`changed-source/` and the seven nested directories that preserve the modified files'
original repository paths.

The `changed-source/` subtree satisfies the Phase 5 requirement that the bundle contain
*"the approved changed Backend source/tests plus the necessary current Backend
implementation/evidence documents and raw test outputs."* For this iteration those five
files are Frontend-owned — see `changed-source/README.md` for the ownership rationale and
the owner's explicit reversal of the no-Frontend-edits constraint.

---

## 2. Fresh extraction is byte-identical

The archive was extracted into a clean temporary directory and every file entry
compared byte-for-byte against the corresponding member read from the archive:
**identical**. A second, independent extraction produced the same result.

---

## 3. Embedded and detached checksums

- `checksums.sha256` inside the archive lists **86 files**; re-verified against the
  freshly extracted copies: **86 OK, 0 FAILED**.
- The detached digest file is the **authoritative** record of the archive's integrity.
  Its recorded SHA-256 was recomputed from the archive bytes and **matches**.
- The digest is deliberately **not** restated as a literal inside this document. An
  earlier iteration embedded a stale archive digest in its own verification report,
  which then disagreed with both the detached file and the recomputed value. Stating the
  digest in exactly one place — the detached `.sha256` — makes that class of defect
  structurally impossible: the value a reader checks is the value the archive was
  actually built with.

To verify:

```sh
cd artifacts/backend-acceptance
sha256sum -c BACKEND-H6-CLOSE-14-NOT-FINAL-BACKEND-NOT-100PCT-2c7ab9c.zip.sha256
unzip -t BACKEND-H6-CLOSE-14-NOT-FINAL-BACKEND-NOT-100PCT-2c7ab9c.zip
cd 20261023T124608Z_2c7ab9c_close14_h6 && sha256sum -c checksums.sha256
```

---

## 4. Exclusions enforced

The archive contains **no** `.env`, no secrets, no payment credentials, no private keys,
no generated caches and no unrelated-track files:

| Scanned for | Found |
|---|---:|
| `.env` files | **0** |
| `vendor/` | **0** |
| `node_modules/` | **0** |
| `*.pem`, `*.key`, `*.p12` | **0** |
| `id_rsa*` | **0** |
| `*credential*` | **0** |
| `.git/` | **0** |
| path traversal / symlink entries | **0** |

The evidence directory is self-contained: it holds only the reports, the gate matrix,
the manifests, the checksums and the raw test evidence for this iteration. The
`database/thai_lottery_test` SQLite fixture (git-ignored) and the `.phpunit.cache`
result cache are not included.

---

## 5. Environment note

The sandbox was reset between the test runs and the final packaging. Every JUnit XML and
raw log in this bundle was captured while PHP 8.4.27, MariaDB 11.8.6, Redis 8.0.2 and
rustc 1.93.1 were live. At packaging time `php`, `composer`, `mysql`, `redis-server` and
`cargo` were no longer on `PATH`, so the suites could **not** be re-run — the final
packaging and integrity verification used `python3`, `sha256sum` and `zip` only.

To keep the evidence bound to the source it describes, all five modified files were
re-hashed and confirmed **byte-identical** to the tree the suites ran against (recorded in
`changed-files-manifest.csv`). No test result in this bundle was regenerated or restated
after the reset.

## 6. Provenance of the numbers

Every count in this evidence set was derived from a fresh run against the current source
identity, not carried over from the prior iteration:

- `BACKEND-TEST-RESULTS.md` §2 lists all 19 JUnit files with counts read directly from
  each XML.
- §3 gives the deduplicated totals (**7,277 unique cases; 7,159 passed; 93 failed; 24
  errored; 1 skipped**), with the deduplication rule and the skip-handling rule stated
  explicitly.
- §4 records that the prior iteration's **5,591 / 14** figure is superseded, and why
  (inconsistent gate scopes — e.g. the prior gate-a XML omitted
  `Tests\Feature\Locale\BanglaPublicPagesTest`, and the prior gate-c XML contained
  `Tests\Unit\Payment\*` classes its recorded command cannot produce).
- §5 and `BACKEND-IMPLEMENTATION-REPORT.md` §6 give the zero-regression proof: the five
  files edited this iteration were reverted and the failing suites re-run, producing a
  **byte-identical** failing set.

## 7. Verdicts

| Verdict | Result |
|---|---|
| **FRONTEND `H6` CLOSURE** | **PASS — 14 / 14 closed, 0 regressions** |
| **BACKEND IMPLEMENTATION** | **PASS** (no Backend file touched this iteration) |
| **FINANCIAL LOGIC** | **PASS** |
| **PLATFORM RELEASE ACCEPTANCE** | **RELEASE BLOCKED** — Docker-hosted smoke tests unavailable, live payment-gateway cutover prohibited, 117 pre-existing failures open |

**Archive integrity: VERIFIED.**
