# Thai Lottery — FINAL (Batch 02 + zeroed-file recovery)

This archive is the `Ainul-550Islam/thai-lottery-ainul-islam` project at commit
`da74424`, **with two commits applied on top**:

| Commit | What it does |
|---|---|
| `0f7e018` | Restores **1,073 files** that commit `10fd070` had replaced with NUL bytes |
| `0e70c1f` | Implements the missing `ClamAvScanner::scanStream()` and hardens the upload scan lane |
| `eff248d` | Adds `docs/BATCH-02-AUDIT.md` (the audit + reconciliation record) |

Full detail: **`docs/BATCH-02-AUDIT.md`**.

---

## 1. Which commits are already in here

Nothing needs to be applied — the files are already fixed in this tree. The
patches are included only so you can apply the same change to your own clone
instead of replacing files:

```
_delivery/batch-02-patches/0001-fix-restore-...patch    (the 1,073-file restore)
_delivery/batch-02-patches/0002-fix-batch-02-...patch   (the ClamAvScanner fix)
_delivery/batch-02-patches/0003-docs-batch-02-...patch  (the audit document)
_delivery/BATCH-02-PLUS-RESTORE.diff                    (all three as one diff)
```

To apply to your own clone of the repository:

```bash
git am _delivery/batch-02-patches/*.patch
# or, if you prefer one flat diff:
git apply _delivery/BATCH-02-PLUS-RESTORE.diff
```

---

## 2. The zeroed-file bug, in one paragraph

Commit **`10fd070`** replaced **1,073 tracked files with the same number of NUL
bytes**, keeping each file's exact byte length. The tree stayed structurally
valid, so `git fsck` was clean and `git status` showed nothing modified — the
damage was only visible when a file was read. `tests/TestCase.php` was 11,141
NUL bytes, so the entire PHPUnit suite died with
`Class "Tests\TestCase" not found` and **not a single test could run**. 207
Blade templates, 88 front-end sources, 31 translation files, the Rust
`weekly-result-integrity` crate and the CI scripts were equally dead.

All 1,073 are restored **byte-for-byte from `dd7a0a4`**, the commit immediately
before the corruption.

### Verification actually performed after the restore

| Check | Result |
|---|---|
| Zero-filled files remaining (whole worktree) | **0** |
| Restored files byte-identical to `dd7a0a4` (`git hash-object` vs blob) | **1073 / 1073** |
| Missing files | **0** |
| PHP syntax errors across the 873 restored `.php` files | **0** |
| Blade templates compiling (real `blade.compiler`) | **244 / 244** |
| JSON parse errors | **0** |
| PHPUnit tests executable | **0 → 1567** |

`tests/Unit` runs green-ish: **1567 tests, 108,181 assertions**, with **31
pre-existing failures** (11 errors, 20 failures). Those are **not** restore
damage — the restored test files are byte-identical to `dd7a0a4`, and the
application classes they exercise are unchanged between `dd7a0a4` and `HEAD`,
so they fail identically on the pre-corruption tree. See section 5 of
`docs/BATCH-02-AUDIT.md`.

If you ever need to redo the recovery on another clone:

```bash
./scripts/recover-zeroed-files.sh            # dry run, report only
./scripts/recover-zeroed-files.sh --apply    # restore
```

---

## 3. What was fixed in the upload scan lane

`UploadScanService::scanRecord()` calls `$this->scanner->scanStream()` on the
quarantine-disk branch — **the path every scanned upload takes**. That method
was declared on no interface and implemented by no class, so the branch died
with `Call to undefined method` and the **malware gate never ran**.

Now declared on `App\Contracts\Security\MalwareScanner` and implemented.
`ClamAvScanner` is the only implementer in the repository, so the contract
change is safe.

Nine further defects fixed in `app/Services/Uploads/Scanners/ClamAvScanner.php`:

1. reply read with `fgets()` against a protocol that NUL-terminates → every
   scan stalled the full timeout before parsing anything
2. `fwrite()` return ignored → a **short write scanned a truncated file** and
   clamd answered `stream: OK` for bytes it never saw
3. `fread() === ''` treated as EOF → truncated the stream the same way
4. `FOUND` checked after `OK`; detection is now parsed first
5. unix-socket support promised by the docblock never existed
6. `getVersionInfo()` invented `'ClamAV 1.3'` while claiming `engine_active`
7. exception message (host:port) interpolated into the persisted `threat_name`
8. no file-size ceiling
9. `file_exists()` rather than `is_file()`

Tests added: `tests/Feature/Compliance/UploadClamAvScannerTest.php` — **16 tests,
59 assertions, all green**. Pre-existing suites still green:
`ClamAvScannerTest` 8/8 and `AntimalwareUploadGateTest` 6/6.

---

## 4. Corrections honoured

| Correction | Status |
|---|---|
| **#1** do not touch `routes/console.php` / `bootstrap/app.php`; keep the GLO command's signature | ✅ untouched. `glo:fetch-official` is the real name; `glo:fetch-result` does not exist anywhere in the tree; `{draw_code?}` was already on it |
| **#2** existing scanner only, no `app/Services/Antimalware/ClamAvScanner.php`; `AntimalwareManager` selects among existing scanners; `NullScanner` throws in production | ✅ created no new scanner; modified only `Uploads/Scanners/ClamAvScanner.php` + the contract. The other two requirements were already true and are verified in the audit doc |
| **#3** publication stays manual | ✅ verified only, no changes. `lottery:publish-result` + `require_admin_confirmation`, `lottery:tick` never publishes |
| **#4** `app/Console/Commands/{Ops,Payment}/**`, `app/Support/Ops/**`, `ThaiRailsServiceProvider.php` are Batch 01 | ✅ zero edits |

---

## 5. How to run it

```bash
composer install
cp .env.example .env          # or: composer run env:local
php artisan key:generate
touch database/database.sqlite
# in .env: DB_CONNECTION=sqlite
php artisan migrate --seed
php artisan serve
```

Front-end (optional):

```bash
npm ci && npm run build
```

Run the tests:

```bash
php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Compliance/UploadClamAvScannerTest.php
php -d memory_limit=1G vendor/bin/phpunit tests/Unit
```

PHP 8.4 with `bcmath`, `mbstring`, `xml`, `curl`, `zip`, `intl`, `gd`,
`pdo_sqlite` (or `pdo_mysql`).

> `memory_limit=1G` is not decoration: the default 128 MB runs out partway
> through `tests/Unit` (`routes/web.php` and `SessionGuard`).

---

## 6. What is NOT in this archive

**Every source file is here** — all 3,260 PHP files, 616 tests, 191 migrations,
Blade views, lang files, config, routes, CI workflows, Docker/k8s/helm/terraform,
the Android app, the Rust crate, and the complete `evidence/`, `artifacts/` and
`images/` trees. 4,763 files in total, listed in `SHA256SUMS`.

The only things excluded are regenerable or machine-specific:

| Excluded | Size | Why | How to restore |
|---|---|---|---|
| `vendor/` | 217 MB | PHP dependencies | `composer install` |
| `node_modules/` | — | JS dependencies | `npm ci` |
| `.git/` | 54 MB | this is a snapshot, not a clone | use the patches in `_delivery/` |
| `.env` | 36 KB | local file created only to run the tests here | `cp .env.example .env` |
| `database/*.sqlite` | 5 MB | test database from this session | `touch database/database.sqlite` |
| `storage/framework/*`, `bootstrap/cache/*.php`, `.phpunit.cache` | — | runtime caches | regenerated on boot |

Nothing else was omitted. All 1,073 restored files are present and verified
byte-identical to `dd7a0a4`.

## 7. Still open — not touched, flagged for the owning batch

1. **Push this restore to the repository.** It is committed locally here but the
   remote still carries the corruption. Nothing else can move until it lands.
2. **Two ClamAV stacks** — `Services/Antimalware/*` (`ScannerInterface`) and
   `Uploads/Scanners/*` (`MalwareScanner`) are both live, with different
   exception models and consumers. Decide which one survives.
3. **Two `DrawResultConfirmationService` classes** — `Services/Draw/` and
   `Services/ResultIngestion/`.
4. **Two schedule sources** — `routes/console.php` *and*
   `bootstrap/app.php withSchedule()`.
5. **`quarantine` disk is not configured** in `config/filesystems.php`, while
   `UploadQuarantineService` writes to `Storage::disk('quarantine')`.
6. **No tests exist for `UploadScanService`** — the class that calls
   `scanStream()` and persists the verdict.

Nothing here was deployed. No live ClamAV daemon, payment provider or bank API
was contacted.
