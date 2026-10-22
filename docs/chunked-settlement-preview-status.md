# Resumable DRY_RUN preview — f37 safe partial closure

Historical **f37** checkpoint. Its source remains unchanged and passing in f38. The latest legacy
CLI status/refusal continuation is in `docs/legacy-chunked-command-status.md`; f39 job loading/refusal
is in `docs/legacy-chunked-job-status.md`. Current aggregate measurements are in `docs/ci-regression-status.md`.

Verified 2026-10-14 on PHP 8.4.26. This implements the safe, audit-only part of the remaining
chunked-settlement work. It does **not** implement the legacy LIVE wallet-credit orchestrator,
replace its tests, settle a draw or claim the 15 remaining legacy errors are resolved.

## Measured current-change result

**53 tests, 517 assertions: all pass, no skips.** Evidence:
`/home/user/preview-focused-f37.log` and `/home/user/preview-focused-f37.junit.xml`.
The real two-process tests also passed **three separate repetitions**, each with two tests and
51 assertions (`/home/user/preview-concurrency-repeated-f37.log`). These are not mocked races.

Expanded regression: **1,071 tests / 104,240 assertions, all pass, no skips**.
Unfiltered repository: **2,355 tests / 112,833 assertions, 15 errors, 0 failures, 2 existing skips**.
All 15 legacy error names are unchanged from f36; none is relabelled fixed by this separate preview.
Evidence: `/home/user/part3-focused-f37.log`, `/home/user/full-f37.log` and matching JUnit XML.
Detailed comparisons are in `docs/ci-regression-status.md` and
`docs/full-suite-failure-classification.json`. A passing preview subset is not a green-full-suite or
live financial certification.

## What was implemented

- A separate `ChunkedSettlementPreviewService`, with persistent checkpoints in **only**
  `settlement_previews` and `settlement_preview_bets`.
- Fixed `DRY_RUN` mode, with no mode/money/winner/force argument and no payment/queue dependency.
- A captured source manifest over **active, non-deleted bets only**, with each bet's selections and
  ticket input hashed, rather than pretending a count or min/max range freezes identities.
- Pinned published-result and pricing-policy fingerprints, plus an immutable-plan checksum.
- Keyset chunks, bounded source hydration, a stored expected cursor and no-op retry acknowledgment.
- Transactional rollback of every audit-entry update if any selection in a chunk fails. An unchanged
  aborted checkpoint can resume without duplicating previous decisions or totals.
- Exact BCMath stake/prize totals through the existing pure `SelectionSettlementResolver`; Tod
  coverage and repeated Run digits do not multiply money.
- Finalization checks the entire source manifest, every recorded outcome/checksum, entry totals,
  aggregate totals, selections, cursor and chunk count before marking the **preview** complete.
- A new explicit operator command, `lottery:preview-settlement`. It is not scheduled or queued.

## What is never changed

Behavioral tests compare every column/row in these 18 business tables before and after preview work:

`wallets`, `financial_transactions`, `ledger_accounts`, `ledger_entries`, `payouts`, `deposits`,
`withdrawals`, `payments`, `agent_commissions`, `bets`, `bet_items`, `tickets`, `draws`, `draw_results`,
`winning_numbers`, `number_limits`, `settlement_runs`, `audit_logs`.

A real query trace independently verifies that the observed INSERT/UPDATE/DELETE write set contains
only the two new preview tables, not merely unchanged financial row counts. HTTP and bus assertions
prove no provider request or job dispatch occurs. Source rows stay unchanged even after preview
completion: the draw remains `ResultPublished`, not `Completed`, and no payable obligation is created.

The schema deliberately has no foreign key from an audit source id into `draws` or `bets`, so preview
retention does not prevent business-source retention/deletion. The only foreign key connects the two
new audit tables. Missing/deleted source inputs refuse further processing/finalization.

## Operator usage

Apply the normal reviewed migration process to create the two audit tables. This migration writes no
source business row and schedules no operation. After that:

```bash
php artisan lottery:preview-settlement --draw=42 --chunk=500 --steps=1
```

This captures/reuses a snapshot and evaluates at most one chunk. It returns a stored preview id in the
JSON report. Substitute that actual id, rather than a placeholder, in later commands:

```bash
php artisan lottery:preview-settlement --preview=7 --status
php artisan lottery:preview-settlement --preview=7 --steps=2
php artisan lottery:preview-settlement --preview=7 --finalize
```

The illustrative ids 42 and 7 above are not real deployment ids. `--status` writes nothing, including
no timestamps or last-seen state. `--finalize` refuses while participants are unevaluated and completes
only the audit preview. It does not run more chunks implicitly or mark the business draw settled.

Limits are 1..1000 bets per new chunk (default 500), at most 100 chunk steps per command invocation,
50 source bets per read page and 100 selections per source bet. The eager relation also has a bounded
per-parent limit, and the count is rechecked before evaluation. Existing snapshots retain their
captured chunk size even if a later caller requests a different one.

There is no `--live`, `--mode`, `--queue`, `--pay` or `--force` option. Flipping the estate's payout
safety/automation flags does not change this component's mode or pricing fingerprint. Only the actual
multiplier-source field, not the payout-automation configuration block, is pinned.

Invalid options, missing previews, changed inputs and incomplete finalization return non-zero. An
explicitly requested, bounded partial audit can return success with `status: open` and an accurate
remaining count; this is not a partially paid draw represented as financially complete.

## Snapshot, replay and integrity contract

1. Identical draw/result/rules/participant inputs share a database-unique snapshot key and one frozen
   participant set. Two genuine concurrent PHP processes prove that contract.
2. A processed chunk advances its cursor and counters in the same transaction as its entry outcomes.
   Two workers holding the same expected cursor commit only one chunk; the other acknowledges the
   stored checkpoint without writing another evaluation.
3. A cursor ahead of the stored checkpoint is refused. An older cursor is an acknowledgment, not
   authorization to skip work or apply it twice.
4. Pricing changes and invalid result/derived-number changes refuse processing. A transient failure
   can resume when the exact captured context is again valid; no force flag changes the context.
5. Already-evaluated inputs are rechecked during finalization too, so modifying an earlier bet cannot
   hide behind a cursor that has already passed it.
6. If inputs legitimately change, an explicit new capture has a different snapshot key and audit id.
   It does not rewrite, reset or relabel an older snapshot's recorded history.
7. Finalization rejects missing entries, altered outcomes, conflicting totals or a corrupt completed
   flag. Empty participation reports percentage `null`, not a fabricated denominator or winner.
8. Status is explicitly labelled `status_is_stored_snapshot: true`. Completed replay/status reads
   historical audit evidence; they do not re-certify today's feed, today's configuration or the
   current source. Fingerprints/checksums are integrity checks, not signatures against a malicious
   database administrator who can rewrite both data and checksums.

Amounts remain decimal strings in reports, including exact fractional-stake cases under an explicitly
configured satang-step test policy. Tests never disable stake validation. The regular purchase pipeline
creates the source bets/debits before the preview; no preview fixture substitutes a wallet-credit path.

Detailed entry outcomes contain internal bet/item/ticket/user identifiers and belong in private
operator-controlled storage. No public route or export of those rows was added; the CLI report exposes
aggregated audit status and fingerprints, not credentials or raw source payloads.

## Complete files — no elision

The following twelve PHP files are delivered beginning to end, totaling **2,220 lines**:

| Complete path | Lines |
|---|---:|
| `app/Console/Commands/Lottery/PreviewSettlementCommand.php` | 98 |
| `app/Exceptions/SettlementPreviewException.php` | 49 |
| `app/Models/SettlementPreview.php` | 64 |
| `app/Models/SettlementPreviewBet.php` | 33 |
| `app/Services/Draw/ChunkedSettlementPreviewService.php` | 663 |
| `database/migrations/2026_10_14_000100_create_settlement_preview_tables.php` | 71 |
| `tests/Feature/Settlement/ChunkedSettlementPreviewTest.php` | 623 |
| `tests/Feature/Settlement/ChunkedSettlementPreviewConcurrencyTest.php` | 163 |
| `tests/Feature/Settlement/PreviewSettlementCommandTest.php` | 157 |
| `tests/Feature/Settlement/SettlementPreviewMigrationTest.php` | 54 |
| `tests/Support/settlement-preview-worker.php` | 86 |
| `tests/Unit/Settlement/ChunkedSettlementPreviewSafetyTest.php` | 159 |

Migration tests execute up/down in an independent in-memory schema, including a retained business
marker and foreign-key inspection. The worker fixture accepts only a testing environment/allow-listed
disposable database; configuration travels via stdin, not command-line arguments or test output.

## Relationship to every remaining legacy expectation

These are not compatibility aliases. The original `ChunkedSettlementTest` and all of its LIVE
assertions remain unchanged and visible.

| Legacy expectation | Safe preview result / remaining distinction |
|---|---|
| Refuse finalization with remaining bets | Preview refuses; no monetary draw finalization exists |
| Draw remains published while processing | Draw also remains published after preview completion |
| Re-run a chunk without double payment | Cursor replay writes nothing; this component has no payment operation |
| Completed run cannot settle/pay again | Completed preview replay is a no-op, not LIVE settlement certification |
| Monolithic settlement yields to a chunked money run | Not implemented here; an audit preview claims no financial run ownership |
| One money run per draw | One preview per exact snapshot; changed inputs may create another preserved audit version |
| Exact totals across chunks | Implemented for simulated amounts through existing exact decision services |
| Interrupted run resumes | Implemented for audit checkpoints under the identical captured context |
| Payouts happen only under LIVE | Preview never creates a payout, under any estate mode |
| Mid-run config flip cannot promote DRY_RUN | Mode is constant; payout safety/automation flags cannot promote it |
| Legacy default chunk-size API/clamps | Preview has its own validated 1..1000 contract, not a misleading alias |
| Safety mode is read/pinned at open | Preview never reads an execution mode; it is always DRY_RUN |
| Progress/null denominator | Exact percentage string for known counts; null for an empty set |
| Queued monolithic job yields to cursor owner | Deferred LIVE orchestration; no new queue job exists |
| Aborted resumable money run retains ownership | Deferred LIVE orchestration; preview records do not own real settlement runs |

## Additional legacy wiring findings — not silently repaired

At the f37 checkpoint, inspection found that the old chunked console command dispatched two arguments while
`SettleDrawChunkJob` requires six; the job calls nonexistent `SelectionSettlementResolver::settleOneBet`;
and the planner references a missing `FinalizeDrawSettlementJob`. Its fingerprint reads also assume a
`draw_results.result_fingerprint` column not added by this repository's draw-result migrations. The
similarly named GLO-import fingerprint is a different table/contract.

F38 subsequently removed that invalid CLI dispatch caller and made its legacy status path read-only;
execution is explicitly refused, not repaired into a money path. F39 subsequently fixed the job's
trait-composition fatal and retired its invalid per-bet/fingerprint callees behind a permanent refusal.
The planner's missing finalizer/fingerprint contract and original fifteen cursor/LIVE errors remain
release blockers; they are not reasons to patch together an unapproved live money path.
The new command/service does not invoke that legacy wiring. No existing money, KYC, purchase,
settlement, commission or approval implementation, environment flag, scheduler or legacy test is
modified by f37's production-code additions.

## Limits and what still needs separate work

- The original 15 legacy cursor/LIVE test errors remain a separate unfinished contract. Passing the
  audit component must not be described as having fixed them or made the repository fully green.
- Existing settlement real-concurrency and native Rust runtime prerequisites are not bypassed.
- Initial capture and final source verification still scan the full active participant set while
  holding their transaction/draw lock. Memory is bounded; seconds-level latency, 100,000-slip load
  performance and cross-host database behavior were not benchmarked or certified. SQLite remains a
  single-writer store and a large capture may delay unrelated writes.
- Real payout automation, multi-level commission, fake campaigns/counters, government-affiliation
  branding and unverified marketing promises remain outside the approved scope.

The refreshed canonical bundle list and manifest include every complete file and intentional deletion.
See `docs/part3-bundle-usage.md` for restoration and runtime prerequisites.
