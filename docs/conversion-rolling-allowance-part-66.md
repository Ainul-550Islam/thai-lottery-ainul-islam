# Part66 — current, exact rolling conversion allowance

## Financial application change

`ConversionLimitService` no longer authorizes a new conversion using SQL SUM from an older repeatable-read snapshot. The existing convert and quote service roots lock the owner `users` row before policy and allowance admission. Inside that transaction, `assertAllowed()` selects the explicit execution path, which requires both Laravel's transaction level and the physical PDO transaction. Its eligible-row queries use `FOR UPDATE`. The existing owner mutex, original-accounting proof, reversal authority and wallet lock ordering are not replaced.

This matters when a child session has already established a consistent snapshot before waiting for that owner. A committed original from the preceding owner-serialized operation must consume the child's allowance, even though an ordinary consistent SELECT could still report zero. Merely acquiring an owner lock or selecting the write PDO does not refresh a plain consistent aggregate.

The explicit execution method is an internal preconditioned API: its covered callers already own the owner mutex. A physical transaction check is not introspection proving that any arbitrary caller holds that row lock. Display totals and outside-transaction advisory policy remain nonlocking, and are not authorization capabilities. Quotes are advisory preparations; execution still rechecks live policy and allowance. Original settled replay returns before new-work admission and keeps its existing accounting proof. Reversal is separately authorized, not a new allowance grant.

## Exact eligibility and arithmetic contract

- Capture one cutoff per totals traversal, rounded down to stored second precision.
- Count completed or reversed **originals**, belonging to this owner and this currency, with `completed_at` between cutoff minus 30 days and cutoff, inclusively.
- Monthly is a rolling 30-day interval, not a calendar month. Daily is the inclusive last 24 hours within that same traversal in the qualified fixed-offset accounting clock.
- Count a reversed original once at its original input amount. Never count its inverse child, pending/quoted/failed work, another owner/currency, null completion, old rows or future rows.
- Read raw stored `amount_in` before Eloquent's decimal cast. Only positive unsigned plain decimal amounts with at most two fraction digits are eligible for summation. Malformed/nonpositive eligible facts refuse with `conversion_limits_unproven`; they are never rounded, repaired, adopted or silently ignored.
- Add each amount using BCMath at two places. No SQL SUM result, PHP binary-float addition or aggregate-to-float conversion supplies authorization.
- Traverse ascending IDs in batches of 250 and compute both windows together. Memory is bounded per batch. **Total scan work and total held locks are not bounded independently of eligible history size.** Production load qualification and suitable indexes still matter.
- Preserve configured minimum/maximum, enforcement switches, exact `attempted` totals, inclusive ceiling admission and existing daily/monthly exception mappings. New refusals roll back quote consumption, balances, original/conversion rows, journals and ledger accounts. A separate existing refusal audit may still be appended; that is not an economic mutation.

This aggregate is deliberately not full original-accounting certification of every historical row. Positive eligible persisted facts conservatively consume allowance; absence of their full receipt/journal proof is not relabelled as genuine original settlement. Part64's actual replay/reversal proof remains separate. SQLite's DECIMAL affinity cannot represent every possible large DECIMAL value exactly: no accumulator can recover digits already lost by its storage engine.

## Regression and genuine native proof

`ConversionRollingLimitTest` covers exact cents across 751 rows and four batches, one second-resolution cutoff and inclusive/exclusive boundaries, currency/owner/status/null/reversal eligibility, zero/negative-history refusal and economic rollback, advisory compatibility, physical transaction refusal even with a stale framework counter, safe member HTTP422, exact ceiling posting, exhausted-budget original replay and disabled window enforcement.

The 751 synthetic aggregate-fact rows each store `100000000000.01`; the exact expected sum is `75100000000007.51`. These rows are explicitly **not** fabricated valid conversion receipts or financial posting history. The native DECIMAL-only fixture additionally sums `9999999999999999.99` and `0.01` to `10000000000000000.00` without a binary-float round trip.

`ConversionRollingLimitServerTest` uses four independently witnessed two-child profiles. Each child starts its own physical repeatable-read transaction and actually reads both allowance totals as zero before the parent acquires the owner mutex. The parent posts a real original of 80 through the real conversion service, retains the mutex, releases both child barriers, and commits only after both independent child sessions are simultaneously observed waiting on that exact parent InnoDB lock. Three profiles exercise cash-to-win and win-to-cash requests of 30; both must refuse with attempted 110:

1. Current original: daily refusal.
2. Real original quoted and posted using a controlled clock two days earlier: monthly-only refusal. No completed source timestamps are rewritten.
3. Real original and legitimate separately authorized reversal in the parent: reversed original still consumes 80, inverse child excluded.
4. Both children replay the parent's exact original request/key: both remain read-only proved replays, not new admission.

For MariaDB's optional snapshot-conflict-abort mode, only disposable child sessions temporarily turn it off and restore it. This exercises the classic stale-consistent-read path rather than mistaking an engine abort for application current-read protection. No production setting is changed. Qualification is the tested cooperative owner-serialized InnoDB repeatable-read path with the default fixed-offset Asia/Bangkok accounting clock, not every database/isolation level, custom DST accounting timezone or privileged writer. Country display timezones do not authorize changing the accounting clock or reinterpreting stored local timestamps; a DST/custom-timezone deployment needs separate time-storage qualification.

The mandatory CI step requires all 27 focused cases, no skips/failures/errors, four real two-child waits and both old zero snapshots for every profile. The workflow was locally parsed and its assertions checked against local native evidence; no remote CI execution is claimed.

## Failure handling and operation

For `conversion_limits_unproven`, stop new affected conversion admission and investigate the persisted eligible amount and its source under controlled financial review. Do not delete rows, set amounts to zero, relabel status, move completion times, backfill receipts or alter balances to make the aggregate pass. Preserve journals and the original replay/reversal evidence. Existing exact settled replay and separately authorized legitimate reversal do not consume fresh allowance.

No schema migration, historical backfill, permission grant, provider I/O, bank transfer, external refund, credential assumption or worldwide legal approval is introduced. Follow the current runtime acceptance report for the actually executed suites and omission controls; their overlapping counts must not be added.

## Retained signature fixture determinism

The complete regression exposed an unrelated pre-existing future-signature fixture race: a signature minted 301 seconds ahead can validly enter the 300-second acceptance tolerance if verification crosses one wall-clock second under load. `StripeDepositReviewTest` now freezes only that test clock. All eight invalid-signature refusal cases, production signature validation and tolerance remain unchanged. This is not a weakened refusal or an application change to accept future signatures. The entire affected test file and all source-inventory static-contract files were re-executed after refreshing generated source hashes; the accepted full report retains every other independently passing per-file run exactly once. The selected native suite had already been completely rerun after the HTTP fixture correction and none of its selected sources changed in this final unrelated fixture adjustment.
