# Current draw risk admission and savepoint atomicity — Part49

## Actual application change

The supported risk mutation paths now share a current draw-first admission boundary:

- `NumberLimitEngine::reserve()`;
- `NumberLimitEngine::reserveMany()`;
- `RiskAssessmentService::reserveBet()`;
- ordinary paid purchases, which use the single engine reservation before creating their bet/ticket/debit.

Previously a validated purchase context or risk facade could read an Open draw before waiting, then reserve against a number-limit row after cancellation/closure. A direct engine caller did not need any draw admission check at all. A caller could also catch a later single/batch risk failure inside its own transaction and commit an earlier counter change. Those are actual mutation/real-wallet-boundary defects, not source-inventory findings only.

## Current admission, not preview permission

`DrawRiskAdmission::lock()` requires an existing framework/PDO transaction and locks the draw before the supported reservation code takes any number-limit lock. It never starts or commits a root transaction, acquires a wallet, posts money, or manufactures historical ownership.

After obtaining the current draw row, require:

1. The draw exists and is Open for betting.
2. Both betting-window timestamps exist; opening is not in the future and closing is strictly after the current admission clock.
3. No `closed_at`, `drawn_at`, `result_published_at` or `completed_at` fact is present.
4. No durable draw-cancellation owner exists, in ANY state. A missing completion, changed status, corrupt/unknown label or manually reopened draw is not new-risk permission.
5. No payout or settlement-run history exists for the draw, including histories hidden by model scopes.

The checks on cancellation and settlement history are current locking reads as well. No row or snapshot is backfilled, repaired, relabelled or deleted. `lottery.betting.require_open_draw=false` may still affect read-only projections, but it cannot disable these write-path checks. The facade no longer establishes an unlocked draw snapshot as its reservation authority before waiting.

The draw lock remains owned by the caller's transaction. A cooperating close, publication or Part48 draw refund must obtain the same row. The reservation decision is linearized while holding that row, before counter mutation. This is an admission-time rule, not a promise that a long transaction commits before the wall-clock deadline.

For an ordinary paid purchase the order is its existing original wallet lock, then current draw admission, then number-limit/source accounting work. An old validator/context or a successful quote does not grant a later debit. A refusal leaves no new bet, ticket, risk increment, wallet charge or accounting receipt.

## Draw cancellation contention

With the Part48 coordinator and these reservation paths:

- If cancellation obtains the draw first, it proves/refunds the supported originals and commits Cancelled. The waiting new risk request reads that current barrier and refuses before reserving.
- If anonymous risk reserves and commits first, the coordinator observes the nonzero residual after its proposed original releases and refuses the whole cancellation. Earlier member refunds, source/risk/commission changes, audits and the header roll back. The original member wallet remains charged for its still-active wager; no guessed refund recipient is created for anonymous capacity.
- An independently authorized explicit release of known anonymous surplus can later permit a fresh cancellation root. That is not an unknown-outcome resend or automatic historical reconciliation.

The native matrix observes two independent child connections waiting on the parent's decisive draw lock for all three risk ingress forms, not just on a shared wallet. It also tests two risk writers competing for the last capacity, with exactly one successful reservation and no wallet/financial-transaction mutation by either risk writer.

## Operation-owned savepoints

Public single reserve, bulk reserve and single release now open a nested operation savepoint only AFTER confirming the caller already owns a transaction. The reserve/release facade operations own another savepoint spanning their entire result and batch. Each uses one attempt, not an internal retry loop.

- A late failure after actual counter SQL rolls back that operation's counters and indirect audit/status effects.
- A later batch reservation failure rolls back every earlier selection from that batch, even though each selection also has its own savepoint.
- A later batch release that would consume an active original's risk floor rolls back earlier releases in that facade operation.
- The caller can catch the refusal and commit unrelated caller work; it cannot accidentally commit a partial risk operation. Its transaction boundary is not committed or rolled back by the successful nested operation.
- Canonical current original-ownership risk floors are unchanged. A release cannot consume another still-active owned wager's capacity.

Tests inject a fault from a database query listener AFTER the real counter UPDATE has executed, then deliberately catch it and commit the caller's unrelated profile update. Both SQLite and native SQL verify the counter/audit/source state was restored and the caller's unrelated update survived. This is not a mocked counter writer.

Laravel/database deadlock or connection-failure behavior remains subject to the enclosing root's existing rules. These savepoints do not prove a remote commit acknowledgment or authorize resending an unknown monetary/risk request.

## Exact keyed batch reports

The generic bulk engine and release facade return maps keyed by canonical number. Previously repeated numbers could mutate a counter more than once while overwriting a report entry, understating totals/details. These keyed mutation APIs now refuse canonical duplicates with `RISK_DUPLICATE_SELECTION` before any batch mutation, rather than silently collapsing their report.

This does not ban separate legitimate identical wagers. Ordinary purchases and the existing bulk-bet loop still make distinct single purchases with their own original accounting/idempotency keys. Read-only assessment may show repeated selections independently; it is a projection, not an execution grant. This release does not implement a new whole-slip bulk/permutation idempotency protocol.

## Releases and historical replay are separate authorities

A release deliberately does NOT require new draw admission. Sales may be closed when a legitimate refund needs to release capacity. Existing current original-ownership floors still apply, and successful release of known anonymous surplus does not choose or pay a beneficiary.

The Part47 ordinary purchase replay remains original-funding-only and does not re-reserve risk or execute a new purchase. The Part46 owned amendment capability and Part48 original-refund replay retain their separate policies and proofs. Recognizing an old original receipt is not permission to accept a fresh wager on a closed/cancelled draw.

## Native stale-snapshot proof

Nine explicit two-session cases exercise single, bulk and facade reserve paths after a peer commits closure, an expired window or a durable unresolved cancellation namespace. The reader first establishes an ordinary REPEATABLE READ snapshot and proves subsequent ordinary reads STILL see Open/the old window/no owner. Its attempted mutation must then refuse from current locking admission and leave risk and money unchanged. MariaDB's optional automatic snapshot-conflict abort is disabled only in these disposable reader/peer sessions and the reader mode is restored; that engine abort is not accepted as a substitute for the application proof.

## Deployment and scope

- Deploy the existing Part48 draw-owner migration and this application version together through an authorized rollout. A missing required table is an error/refusal, never a fail-open schema fallback. No migration or historical backfill is added here.
- Do not pre-acquire number-limit locks and then call the reservation API from new custom callers. The supported reservation order is draw before limits, and money callers must acquire all required wallets before that draw.
- Admission/savepoint work adds draw-level serialization and nested SQL savepoints. Large-batch throughput, cross-draw/all-writer/multi-host/index costs require separate acceptance.
- Supported engine/facade/ordinary-purchase reservation ingress now coordinates with lifecycle/draw refund. Raw SQL, administrative aggregate/reconciliation writers, every legacy writer and legacy settlement's global wallet/draw order are NOT universally certified by this release.
- Anonymous risk remains anonymous. Durable whole-request/bulk/permutation ownership, safe historical reconciliation and unknown-outcome resolution remain distinct work. Do not infer ownership from a total or repeat a non-idempotent reservation after a lost acknowledgment.
- No merchant/provider, country/operator license, production transfer, worldwide permission or browser/security certification is implied. Acceptance uses owned disposable stores and synthetic funds only.

Final-source discovery, regression/native results, omission controls and remaining-work boundaries are supplied with this release. Preliminary focused/interrupted runs are diagnostics, not final acceptance. Matrices overlap and must not be summed as unique coverage.
