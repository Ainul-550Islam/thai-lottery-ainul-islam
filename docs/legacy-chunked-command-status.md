# Legacy chunked command — f38 read-only status and explicit execution refusal

Historical **f38** checkpoint, retained unchanged in production source. The f39 job-class loading/
refusal continuation is in `docs/legacy-chunked-job-status.md`; latest aggregate measurements are
in `docs/ci-regression-status.md`.

Verified 2026-10-14 on PHP 8.4.26. This continues the unfinished **legacy command wiring** review,
not the already-completed f37 preview service. The original cursor/LIVE settlement implementation
and its fifteen failing tests are not implemented, renamed, weakened or hidden by this change.

## Reproduced defect, then passing tests

The initial three regression tests produced **one error and two failures** against the original
command (`/home/user/legacy-cli-red-f38.log`):

1. A real `--draw=<id> --status` call raised missing `ChunkedSettlementOrchestrator::runFor()`.
2. The queue invocation reached missing `start()` instead of a safe, deliberate execution refusal.
3. `--all-runs` without `--status` incorrectly returned success despite the documented flag contract.

After the complete command replacement and expanded tests:

**69 tests / 334 assertions — all pass, no skips or warnings**
(`/home/user/legacy-cli-focused-f38.log` and matching JUnit XML).

Expanded regression: **1,140 tests / 104,574 assertions — all pass, no skips**.
Unfiltered repository: **2,424 tests / 113,167 assertions — 15 errors, 0 failures, 2 existing skips**.
All fifteen backend failing names are exactly unchanged from f37; no new failing name was introduced.
Evidence: `/home/user/part3-focused-f38.log`, `/home/user/full-f38.log` and matching JUnit XML.
Detailed comparisons are in `docs/ci-regression-status.md` and
`docs/full-suite-failure-classification.json`. A passing command component is not a green-full-suite
or a LIVE settlement certification.

## Closed safely

| Identified problem | Current behavior |
|---|---|
| Status depended on nonexistent cursor methods | Direct, read-only `Draw`/`SettlementRun` queries; no orchestrator is resolved |
| Old queue dispatch passed two arguments to a six-argument job | That command dispatch path is removed; execution returns an explicit non-zero refusal |
| Resume/start/run advice implied a working money path | Help and description truthfully say execution/queue/resume are disabled |
| `--all-runs` accepted without its read-only intent flag | `--status` is mandatory; mixed execution/status options are refused |
| Raw stored failure reason/operator identity could be printed | Failure presence only; no raw reason, started-by id, SQL, provider payload or stack trace |
| Invalid counters could be cast/truncated or progress clipped | Raw integers are validated; invalid/overflow/overrun data is flagged, not clamped |
| Zero denominator implied a made-up remaining estimate | Percentage and remaining are null when the stored denominator is unknown or zero |
| One corrupt timestamp could break the whole triage list | Its row stays visible with a safe issue code; other rows still appear; exit is non-zero |
| List silently stopped at 200 | Real observed total, returned count, limit and truncation flag are explicit |
| A stored LIVE/completed row could be mistaken for payment proof | Every report says `financial_effects_verified: false` and labels stored-only status |

This closes the **CLI caller** mismatch by safely removing unsupported execution, not by implementing
the six-argument job, a finalizer, missing payout methods or LIVE compatibility aliases. The batch
planner/job/result-fingerprint defects remain separate release blockers.

## Operator usage — read only

```bash
php artisan lottery:settle-draw-chunked --draw=42 --status
php artisan lottery:settle-draw-chunked --status --all-runs
```

42 is illustrative; use an actual draw id/reference. Numeric ids take precedence over an identical
draw number, including zero padding. If there is no matching id, the exact draw-number string is
looked up, retaining leading zeroes and digit strings larger than a native PHP integer.

No run is created when none exists. The report says `run_exists: false` and separately gives the
actual current non-deleted bet count. That count is **not** a frozen participant denominator.

All execution variants remain accepted option names solely so they get a clear, non-zero refusal:

- default execution without `--status`;
- `--queue`, `--resume`, `--chunk` and legacy `--dry-run` execution;
- any execution flags mixed into a status request.

The first group returns `LEGACY_CHUNKED_EXECUTION_DISABLED`; mixed/ambiguous status options return
`LEGACY_STATUS_INVALID_OPTIONS`. There is no configuration flag, LIVE mode or future-added backend
method that silently re-enables this command. Tests turn all relevant estate automation flags on and
still observe **zero database queries**, jobs or provider requests for execution attempts.

The separate `lottery:preview-settlement` command remains an explicit DRY_RUN audit. This legacy
command never invokes it implicitly, changes its records or pretends its completion settles a draw.

## Stored-report interpretation

- `read_only: true` and `execution_enabled: false` describe this invocation.
- `status_is_stored_record: true` means reported mode/counters/timestamps came from the legacy record,
  not a newly executed settlement.
- `financial_effects_verified: false` is present even for stored `LIVE`/`completed` records. Nothing
  here proves past wallet credit, payout finality, balanced journals or provider transfer.
- `source_snapshot_is_frozen: false` prevents a status projection being sold as a pinned financial
  participant snapshot. The two list queries are explicitly labelled independently observed.
- A null recorded safety mode/currency stays null. Current estate configuration is not substituted.
- `metadata_issues` contains safe, field-level codes; unknown enum values/raw secrets are not echoed.
- `reported_rows_valid` checks only the displayed metadata shape. It is not financial reconciliation,
  validation of unseen rows beyond the limit, or a certificate that the whole system is healthy.

Valid plain stored money values are displayed as decimal strings using BCMath without rounding.
Excess scale, scientific notation, negative or oversized values are refused as invalid metadata,
not silently converted through Eloquent's decimal rounding. This projection cannot recover precision
already lost by an underlying database/driver and does not certify SQLite financial storage.

An aborted/incomplete row is readable without being resumed or repaired. Do not delete money-run
ownership rows, clear financial idempotency, disable KYC/approval or change payout flags to work
around the outstanding backend. Older service messages may still suggest `--resume`; that is not
authorization or a supported execution path in this command.

## What tests actually prove

- Before/after comparisons include **23 tables**: the 18 business/financial tables used by f37, both
  independent preview tables, and `jobs`, `job_batches`, `failed_jobs`.
- Disabled execution does not even query a draw/run; status query traces contain only reads and no
  `FOR UPDATE`. A caller's existing transaction is preserved without adding one.
- Poisoned bindings prove the command never resolves the missing orchestrator or monetary service.
- Real purchased-bet fixtures fund/debit before status checks. Staged legacy progress is explicitly
  metadata, not an asserted actual settlement or paid winner.
- A 201-row non-completed set plus a completed record proves exact observed totals, stable ordering,
  filtering and explicit 200-row truncation. Unknown non-completed statuses are not silently dropped.
- In-memory retrieved-row fault injection verifies bad timestamp handling on strict SQL drivers too,
  without corrupting a persisted source row just to set up the test.
- Pure metadata cases cover non-integral/negative/overflow counters, contradictory totals, invalid
  modes/currencies, excessive money precision and sensitive-value redaction. Negative controls prove
  the structural dependency/mutation detectors work.
- Console output is captured **once per invocation**. `BufferedOutput::fetch()` drains its buffer;
  privacy assertions must inspect the same complete captured text, never an already-empty buffer.

Existing money services, cursor orchestrator, job implementation, `SettlementRun` model, scheduler,
fixture flags and all original legacy tests are unchanged. Only the legacy console command's
production behavior changes; no new payout/commission automation, public route, campaign/counter or
branding claim is introduced.

## Complete files — no elision

| Complete path | Lines |
|---|---:|
| `app/Console/Commands/Lottery/SettleDrawChunkedCommand.php` | 283 |
| `tests/Feature/Settlement/LegacyChunkedSettlementCommandTest.php` | 539 |
| `tests/Unit/Settlement/LegacyChunkedCommandSafetyTest.php` | 204 |
| **Total** | **1,026** |

Each file is delivered beginning to end. No old invalid dispatch body is retained as dead code,
no method is represented by a placeholder and no test expectation is removed to create green output.

## Still unfinished

The original fifteen `Draw\ChunkedSettlementTest` expectations concern a different, incomplete
cursor/LIVE run contract. This command hardening does not satisfy them. The missing finalizer,
missing per-bet job method and unsupported result-fingerprint wiring remain visible. Any future
money-capable implementation requires separate reviewed scope, not inferred authorization from
repeating a continuation request or from the already-passing audit component.
