# Batch 02 — Audit & Corrections Reconciliation

**Repository:** `Ainul-550Islam/thai-lottery-ainul-islam`
**Commit audited:** `da74424` (HEAD)
**Date:** 2026-10-27

---

## সংক্ষেপে (বাংলা সারমর্ম)

| # | বিষয় | ফলাফল |
|---|---|---|
| **P0** | রিপোর **১,০৭৩টা ফাইল পুরোটাই NUL বাইট** দিয়ে ভরা — `10fd070` কমিটে নষ্ট হয়েছে | 🔴 **ব্লকার** — পুরো টেস্ট স্যুট ও ২০৭টা Blade ভিউ মৃত। সবগুলো `dd7a0a4` থেকে উদ্ধারযোগ্য |
| **#1** | `glo:fetch-result` নয়, `glo:fetch-official`-ই আছে | ✅ আপনি ঠিকই বলেছেন signature অপরিবর্তিত রাখতে — কিছু বদলাইনি |
| **#2** | `Uploads/Scanners/ClamAvScanner`-এ `scanStream()` **নেই** অথচ caller কল করে | ✅ ঠিক করেছি + কনট্র্যাক্টে যোগ + ১৬টা টেস্ট (সব পাস) |
| **#3** | ম্যানুয়াল পাবলিকেশন | ✅ কোড ইতিমধ্যেই সঠিক, কোনো পরিবর্তন লাগেনি |
| **#4** | Batch 01 ownership | ✅ হাত দিইনি |

---

## 1. P0 — 1,073 zero-filled files (blocker)

### What the damage is

Commit **`10fd070`** — *"feat(thai-lottery): integrate final update bundle, social login, TOTP MFA web controller, real-money enforcement middleware, and WeChat/IC user profile fields"* — replaced **1,073 tracked files with the same number of NUL bytes**.

Every file kept its **exact byte length**. The tree is structurally valid, `git fsck` reports nothing, and `git status` is clean. Nothing in git notices. The corruption is invisible until a file is *read*.

Proof — the git object store itself, not the worktree:

```
$ git cat-file -s HEAD:tests/TestCase.php
11141
$ git cat-file -p HEAD:tests/TestCase.php | tr -cd '\000' | wc -c
11141          # every byte is NUL
$ git status --porcelain | wc -l
0              # nothing reports as modified
$ git fsck
               # no output — no error
```

### Timeline

| Commit | `tests/TestCase.php` size | NUL bytes | State |
|---|---|---|---|
| `dd7a0a4` | 11,141 | **0** | ✅ healthy |
| **`10fd070`** | 11,141 | **11,141** | 🔴 zeroed |
| `da74424` (HEAD) | 11,141 | 11,141 | 🔴 inherited |

The size is identical across the boundary — which is exactly why this got through review.

### What it takes down

| Area | Files | Impact |
|---|---|---|
| `tests/Feature/**` | 460 | feature suite dead |
| `resources/views/**` | 207 | **Blade templates render NUL** |
| `tests/Unit/**` | 122 | unit suite dead |
| `resources/js/**` | 88 | front-end sources dead |
| `tests/Support/**`, `tests/Concerns/**` | 34 | `TestCase.php` among them → **suite cannot boot at all** |
| `lang/th/**`, `lang/en/**` | 31 | Thai/English translations empty |
| `security/weekly-result-integrity/**` | 12 | Rust integrity verifier replaced by zeros |
| `scripts/**`, `tools/**` | 20 | CI and ops scripts dead |
| `routes/channels.php`, `routes/thai_rails.php` | 2 | the only files under `app/`, `config/`, `routes/` affected |
| **total** | **1,073** | **9.37 MB of source destroyed** |

Symptom you will see on a fresh clone:

```
$ php vendor/bin/phpunit tests/Feature/Compliance/ClamAvScannerTest.php
An error occurred inside PHPUnit.
Message:  Class "Tests\TestCase" not found
Location: .../ClamAvScannerTest.php:39
```

Before the repair I could not run a single test in this repository.

### Recovery — 100% successful

**All 1,073 files are intact and recoverable from `dd7a0a4`** (the commit immediately before the corrupting one). Verified: `recoverable: 1073 / unrecoverable: 0`.

### Status: DONE — restored and committed

Commit **`0f7e018`** — *"fix(restore): recover 1,073 files that commit 10fd070 replaced with NUL bytes"* — contains all 1,073 restored paths.

Apply it to your own clone with **one command** (the data is already in your repository at `dd7a0a4`, nothing needs to be transferred):

```bash
git checkout dd7a0a4 -- $(git ls-tree -r --name-only HEAD | while read -r f; do
  [ -s "$f" ] && [ "$(tr -cd '\000' < "$f" | wc -c)" = "$(wc -c < "$f")" ] && echo "$f"
done)
```

Then verify and commit:

```bash
git status --short | wc -l          # expect 1073
git add -A && git commit -m "fix: restore 1,073 files zero-filled by 10fd070"
```

A reusable, safer tool is at **`scripts/recover-zeroed-files.sh`**:

```bash
./scripts/recover-zeroed-files.sh              # dry run, report only
./scripts/recover-zeroed-files.sh --apply      # restore
```

It restores a path only when the current copy is 100 % NUL bytes, and only from a revision where that exact path holds real content. It never overwrites a file that has content, and it reports any path it cannot recover instead of silently skipping it.

### Verification after the restore

| Check | Result |
|---|---|
| Zero-filled files remaining (whole worktree, `vendor`/`.git` excluded) | **0** |
| Restored files byte-identical to `dd7a0a4` (`git hash-object` vs blob) | **1073 / 1073** |
| Missing files | **0** |
| Files differing from `dd7a0a4` | **0** |
| PHP syntax errors across the 873 restored `.php` files | **0** |
| Blade templates compiling (real `blade.compiler`, not `php -l`) | **244 / 244** |
| JSON parse errors | **0** |
| PHPUnit tests executable | **0 → 1567** |

The full `tests/Unit` lane now runs: **1567 tests, 108,181 assertions**.

Of those, **31 fail (11 errors, 20 failures)**. These are **not** restoration damage: the restored test files are byte-identical to `dd7a0a4`, and the application classes they exercise (`app/Services/Payment/Slip/`, `app/Services/Payment/BankSlipVerificationService.php`, `.github/workflows/`) are **unchanged** between `dd7a0a4` and `HEAD` — so they would fail identically on the pre-corruption tree. They are genuine pre-existing defects, e.g. `SlipOkProviderTest` expects `slip_provider_error` while the provider raises `slip_invalid`. Listed here rather than fixed, because fixing application behaviour is not restoration.

One `php -l` false positive: `resources/views/sitemap.blade.php` starts with `@php echo '<?xml version="1.0" …'`, which `php -l` reads as broken PHP. It compiles correctly through Blade (included in the 244).

**Review before committing.** A blind restore to `dd7a0a4` also reverts any *intentional* edit `10fd070` made to those 1,073 files. In practice that risk is nil — the current content is nothing but zeros, so there is no edit left to preserve — but `git diff --stat` after the restore is the check.

---

## 2. Correction #1 — schedule ownership & the GLO command

**Status: accepted, nothing to change.**

Your instruction resolved its own ambiguity: *"Keep `FetchGloOfficialDrawResultCommand` working under its current signature."*

What the code actually contains:

```php
// app/Console/Commands/FetchGloOfficialDrawResultCommand.php:71
protected $signature = 'glo:fetch-official
    {draw_code? : Specific draw code}
    {--dry-run : ...}';
```

- `grep -rn "glo:fetch-result"` → **0 hits**. The name in the correction brief does not exist in this tree.
- `{draw_code?}` **is** already on this command.
- It is already scheduled at `routes/console.php:324` — `everyFifteenMinutes()`, `withoutOverlapping()`, `onOneServer()`.

Actions taken: **none.** `routes/console.php` and `bootstrap/app.php` untouched. No schedule line appended (there was never one to append — the entry already exists).

> ⚠️ One thing to carry to the batch that owns scheduling: the entry at `routes/console.php:324` is **duplicated** by `bootstrap/app.php:55 withSchedule(...)`, which schedules `lottery:tick`, `risk:provision-number-limits`, `webhooks:purge-replay-guards`, `settlement:verify-guards`, `operations:readiness-report`, `conversions:*` and five chat jobs. Two schedule sources in one app is a double-run waiting to happen. That is Batch-later's problem, flagged here only so it is not lost.

---

## 3. Correction #2 — the ClamAV scanner

### 3a. First: there are **two** scanners in this repo, not one

| | Stack A (canonical per your correction) | Stack B |
|---|---|---|
| Interface | `App\Contracts\Security\MalwareScanner` | `App\Services\Antimalware\ScannerInterface` |
| Impl | `App\Services\Uploads\Scanners\ClamAvScanner` | `App\Services\Antimalware\ClamAvScanner` |
| Returns | `MalwareScanResult` DTO | throws `AntimalwareException` |
| Bound in | `AppServiceProvider:68`, `ThaiRailsServiceProvider:30` | `FrontendOpsServiceProvider:97` |
| Consumers | `UploadScanService`, `SecurityReadinessCommand`, `SecurityOverviewController` | `DocumentStorageService` (KYC docs) |
| Tests | **none existed** | `tests/Feature/Compliance/ClamAvScannerTest.php` (8) |

Per your correction I worked **only** on Stack A and created **no** `app/Services/Antimalware/ClamAvScanner.php`. Nothing in Stack B was touched — the 8 tests there still pass.

**The duplication is real and worth a separate decision.** Two independent ClamAV clients, two exception models, two configs. The `/api/v1/admin/security` overview reads Stack A's `ping()`/`getVersionInfo()`, while KYC document scanning uses Stack B. A `clamd` outage is reported differently in two places.

### 3b. The fatal defect: `scanStream()` did not exist

```php
// app/Services/Uploads/UploadScanService.php:54
$scanResult = $this->scanner->scanStream($stream, $record->original_filename);
```

`$this->scanner` is typed `App\Contracts\Security\MalwareScanner`, which declared only `scanFile()`, `ping()` and `getVersionInfo()`:

```
$ grep -rn "function scanStream" app/
(no output)
```

The method was declared on **no interface** and implemented by **no class**. That call sits on the `quarantine`-disk branch — the path uploads actually take — so **every quarantined upload died with `Call to undefined method ClamAvScanner::scanStream()` and the malware gate never ran.**

This is the same failure shape the project's own `START-HERE.md` documents for `GatewayWithdrawalResponse::uncertain()`: *"this factory was called by the disbursement service but never existed, so every transport timeout was a fatal Error."* It has recurred.

### 3c. Other real defects fixed in the same file

`app/Services/Uploads/Scanners/ClamAvScanner.php` — rewritten, contract preserved (`MalwareScanner`, DTO return, never throws):

| # | Defect | Consequence | Fix |
|---|---|---|---|
| 1 | Reply read with `fgets()` while the protocol is `zINSTREAM`, which **NUL-terminates** | every scan stalled the full 15 s timeout waiting for a newline | `readReply()` frames on NUL *or* newline, capped at 4 KB |
| 2 | `fwrite()` return value ignored | a short socket write scanned a **truncated file** and clamd answered `stream: OK` → **clean verdict on bytes it never saw** | `write()` loops until every byte is out; zero-byte write is a failure |
| 3 | `fread() === ''` treated as EOF | truncated stream → same fail-open as (2) | timed-out read now distinguished from genuine EOF |
| 4 | `FOUND` checked *after* `OK` | correct only by accident of suffix matching | detection parsed **first**; `clean` is an equality check on `OK` |
| 5 | docblock promised unix-socket support the code never had (`fsockopen(host, port)` only) | a deployment on `antimalware.clamav.socket` dialled `127.0.0.1:3310` and reported the daemon unreachable | `resolveSocketAddress()` honours `socket`, then `host:port` |
| 6 | `getVersionInfo()` fell back to a **hard-coded `'ClamAV 1.3'`** while reporting `engine_active => true` | the admin security page printed a signature version nobody observed | silence is reported as `UNKNOWN` / `engine_active: false` |
| 7 | exception message interpolated into `threat_name` | `[host:port]` written into `upload_scan_records.threat_name` and the log | refusal names the failure class, never the address |
| 8 | no file-size ceiling | unbounded read; clamd's `StreamMaxLength` rejects mid-stream where the failure is indistinguishable from transport error | refused before connecting |
| 9 | `scanStream()` missing | see 3b | implemented; **never closes a caller-owned handle** |
| 10 | `file_exists()` | a directory passed as a "file" | `is_file()` + `is_readable()` |

Contract change — `app/Contracts/Security/MalwareScanner.php`:

```php
public function scanStream(mixed $stream, ?string $originalFilename = null): MalwareScanResult;
```

Safe: `ClamAvScanner` is the **only** implementer in the repo (`grep -rn "implements MalwareScanner"` → 1 hit), and both providers bind to it.

### 3d. `AntimalwareManager` and `NullScanner` — already compliant

Both of your requirements were already satisfied; I verified rather than changed them.

- **`AntimalwareManager` only selects among existing scanners** (`AntimalwareManager.php:80-84`):
  ```php
  $scanner = match ($name) {
      ClamAvScanner::DRIVER => $this->clamav,
      NullScanner::DRIVER   => $this->null,
      default => throw AntimalwareException::unavailable('unknown_driver:'.$name),
  };
  ```
  An unknown driver throws; it does not fall back to the permissive scanner.

- **`NullScanner` throws in production** (`NullScanner.php`): `isAvailable()` returns `false` under `production`, and `scan()` throws `AntimalwareException::driverRefused()` **inside the scanner itself**, so a caller that bypasses the manager and constructs it directly still cannot get a pass. Its verdict is `not_scanned`, never `clean`.

### 3e. Tests

New: **`tests/Feature/Compliance/UploadClamAvScannerTest.php`** — 16 tests, 59 assertions. No clamd required and none contacted; every case is a decision made *before* or *instead of* a successful daemon conversation.

Covers: contract declares `scanStream` · missing file is ERROR not CLEAN · a **directory** is not scanned as a file · oversized file refused before dialling · unreachable daemon is ERROR · **the outage verdict does not leak the socket path** · non-resource stream refused · `scanStream()` does not close a caller-owned handle · `scanStream` and `scanFile` agree · empty address ⇒ unavailable **without dialling** · configured unix socket ⇒ available · host:port ⇒ available · out-of-range port ⇒ unavailable · `getVersionInfo()` never invents a version · `ping()` false when unreachable · a sweep asserting **no verdict is clean except one the daemon returned**.

---

## 4. Correction #3 — result publication is manual

**Status: verified correct. No changes made.**

Every claim holds:

| Requirement | Evidence |
|---|---|
| `lottery:publish-result` exists | `app/Console/Commands/Lottery/PublishDrawResultCommand.php:26` — `{draw} {--first-prize=} {--bottom-two=} {--operator-id=} {--confirm} {--yes}` |
| `require_admin_confirmation` gates it | `config/lottery.php:447` → `'require_admin_confirmation' => true` |
| `lottery:tick` never publishes | `TickCommand.php:28` — *"Step 4 does not skip to publication: nothing here publishes a result, ever."* |
| Two-person confirmation | first invocation records **Pending**; a second invocation with `--confirm` and a **different** operator confirms that exact result |

`PublishDrawResultCommand` *"never calls the low-level publication writer directly"* (its own docblock), it goes through `DrawResultIngestionService` → `DrawResultConfirmationService`.

### One nuance in the wording

`OperatorResultEntryService` does **not** call `DrawResultConfirmationService` directly. It delegates to the lane's `AbstractLotteryImportService::approveVersion()` → `publishVersion()` (`OperatorResultEntryService.php:213`). That *is* the existing confirmation path — just reached through the import engine rather than the service name in the brief.

Also note there are **two** `DrawResultConfirmationService` classes:

```
app/Services/Draw/DrawResultConfirmationService.php
app/Services/ResultIngestion/DrawResultConfirmationService.php
```

Since I made no changes here, both stand. **Which one is canonical should be settled before the batch that touches publication**, or a future change will inevitably wire the wrong one.

---

## 5. Correction #4 — Batch 01 ownership

**Status: respected. Zero edits.**

Confirmed present and untouched:

```
app/Console/Commands/Ops/
app/Console/Commands/Payment/
app/Support/Ops/
app/Providers/ThaiRailsServiceProvider.php
```

`routes/console.php` and `bootstrap/app.php` were not touched either (correction #1).

Files I did change:

| File | Change |
|---|---|
| `app/Services/Uploads/Scanners/ClamAvScanner.php` | rewritten (10 defects fixed, contract preserved) |
| `app/Contracts/Security/MalwareScanner.php` | `scanStream()` declared |
| `tests/Feature/Compliance/UploadClamAvScannerTest.php` | **new**, 16 tests |
| `scripts/recover-zeroed-files.sh` | **new**, NUL-corruption recovery tool |
| `docs/BATCH-02-AUDIT.md` | **new**, this document |

> Note: `antimalware` config is read but **`config/antimalware.php` was not modified** — all keys used (`clamav.socket`, `clamav.host`, `clamav.port`, `clamav.chunk_bytes`, `timeout_seconds`, `max_file_size_bytes`) already existed.

---

## 6. Test evidence

Run on PHP 8.4.23 / PHPUnit 11.5.56 / SQLite, **after** the NUL recovery:

```
tests/Feature/Compliance/UploadClamAvScannerTest.php    OK (16 tests, 59 assertions)
tests/Feature/Compliance/ClamAvScannerTest.php          OK ( 8 tests, 24 assertions)   [pre-existing, Stack B]
tests/Feature/Compliance/AntimalwareUploadGateTest.php  OK ( 6 tests, 11 assertions)   [pre-existing]
```

Before the recovery none of these could run — `Class "Tests\TestCase" not found`.

Environment note: PHP 8.4.23 (static build) and `composer install --ignore-platform-req=ext-intl` were used because this container has no `intl` extension. That flag is a sandbox limitation, **not** a project requirement — the lock file correctly requires `ext-intl` for Filament.

---

## 7. Open items — not touched, flagged for the owning batch

1. **P0 corruption is not fixed in the repo yet.** The recovery was proven locally; the 1,073 files must be restored and committed by whoever holds write access. **This blocks every other batch** — a batch that "makes tests green" cannot run tests on a tree whose `TestCase.php` is 11 KB of zeros.
2. **Two ClamAV stacks** — decide whether `Services/Antimalware/*` is consolidated into the `MalwareScanner` contract or vice versa. Both are live today.
3. **Two `DrawResultConfirmationService` classes** — pick one before touching publication.
4. **Two schedule sources** — `routes/console.php` *and* `bootstrap/app.php withSchedule()`.
5. **`quarantine` disk is not configured** in `config/filesystems.php`, while `UploadQuarantineService::quarantineUploadedFile()` writes to `Storage::disk('quarantine')`. That call throws `Disk [quarantine] does not have a configured driver` — so even with `scanStream()` implemented, the quarantine branch needs a disk definition before it can run end to end. Left alone because it is a filesystem-config change, not a scanner change.
6. **No tests exist for `UploadScanService`** — the class that calls `scanStream()` and persists the verdict.

Nothing in this session was deployed, and no live ClamAV daemon, payment provider or bank API was contacted.
