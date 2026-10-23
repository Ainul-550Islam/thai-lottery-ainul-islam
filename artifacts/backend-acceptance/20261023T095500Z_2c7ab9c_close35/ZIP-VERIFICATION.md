# Final Backend Handoff ZIP — Integrity Verification Report

- **ZIP path:** `/home/user/artifacts/backend-acceptance/BACKEND-CLOSE-35-FINAL-2c7ab9c.zip`
- **Detached SHA-256:** `/home/user/artifacts/backend-acceptance/BACKEND-CLOSE-35-FINAL-2c7ab9c.zip.sha256` — authoritative value is recorded in that file and verified with `sha256sum -c` (see §5)
- **Contents:** `20261023T095500Z_2c7ab9c_close35/` — the complete Backend-only evidence bundle (83 entries, 6 top-level documents + junit/ + logs/ + pre-fix-logs/ + post-fix-logs/)
- **Excluded:** `.env`, secrets, payment credentials, private keys, `vendor/`, `node_modules/`, `target/`, `.cache/`, `.git/`, and any unrelated-track or unreviewed dirty file

## 1. CRC integrity (`unzip -t` + independent `zlib.crc32` re-computation)

```
No errors detected in compressed data of /home/user/artifacts/backend-acceptance/BACKEND-CLOSE-35-FINAL-2c7ab9c.zip.
83 / 83 entries report OK; 0 errors detected in compressed data.
```

Independent re-computation (not trusting the stored CRC field) over the **78 file entries**
(the other 5 of the 83 entries are directory entries, which carry no payload):

```
file entries CRC-tested: 78
CRC OK: 78
CRC FAILED: 0
```

## 2. Traversal / absolute-path / symlink / duplicate-name checks

```
entries: 83
absolute or ../ traversal paths: NONE
symlink entries: NONE
duplicate names (exact): NONE
case-collision duplicates: NONE
```

## 3. Fresh extraction equality

```
$ unzip -q BACKEND-CLOSE-35-FINAL-2c7ab9c.zip && diff -r 20261023T095500Z_2c7ab9c_close35 <live-evidence-dir>
EXTRACTION EQUALITY: IDENTICAL (byte-for-byte, all 83 files)
```

## 4. Embedded `checksums.sha256` verification inside the fresh extraction

```
$ sha256sum -c checksums.sha256
77 / 77 files OK, 0 FAILED
```

## 5. Detached ZIP SHA-256

The authoritative digest of the archive is the **detached** file
`BACKEND-CLOSE-35-FINAL-2c7ab9c.zip.sha256` sitting beside the ZIP, verified with:

```
$ sha256sum -c BACKEND-CLOSE-35-FINAL-2c7ab9c.zip.sha256
BACKEND-CLOSE-35-FINAL-2c7ab9c.zip: OK
```

> **Why the digest is not printed as a literal inside this document:** this document is itself
> an entry inside the archive, so embedding the archive's own digest here would be
> self-referential — writing the correct value would change the archive and invalidate it.
> The detached `.sha256` file is the single authoritative, independently verifiable source for
> the digest; re-run the `sha256sum -c` command above against the downloaded ZIP at any time.
>
> An earlier draft of this report recorded a stale digest literal in this section. That value
> did **not** match the archive and has been removed rather than left to mislead a reviewer.
> `sha256sum -c` against the detached file was, and remains, `OK`.

## 6. ZIP acceptance criteria — all satisfied

| Criterion | Status |
|---|---|
| All 35 Backend/Audit-owned `H1`–`H5`/`H7` cases resolved | **YES** — see `BACKEND-FAILURES-AND-OWNER-HANDOFFS.md` §1 |
| All required Backend/financial suites pass with 0 failures, 0 errors and 0 unexecuted required skips | **YES** — `BACKEND-TEST-RESULTS.md` §2, §5 |
| Backend/financial regression and security invariants intact | **YES** — regression suites re-run and green |
| Frontend-owned files untouched in this iteration | **YES** — SHA-256 comparison, `changed-files-manifest.csv` |
| Final source identity, test evidence and file manifests match the exact candidate tree | **YES** — `source-identity.json` bound to `2c7ab9c` |
| No `.env`, secrets, credentials, private keys, `vendor/`, `node_modules/` in the archive | **YES** — 83 entries enumerated and inspected |
