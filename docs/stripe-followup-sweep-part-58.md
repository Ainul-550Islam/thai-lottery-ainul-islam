# Part58 — Durable scheduled Stripe signed-inbox routing

## Operational functionality and activation

`stripe-followups:sweep` enrolls previously unseen current-account/configuration signed inbox rows, claims committed expiring SQL leases, and invokes the existing safe original router outside any queue transaction. It handles at most25 items per invocation and enrolls at most100 rows per invocation. The finance inbox now shows historical worker-state/attempt hints and supplies **Retry held worker item** with a fixed audited reason. No refund, debit, credit, acknowledgement, withdrawal release or risk clearance is added.

The command is registered every minute under scheduler name `stripe-followup-routing-sweep`. Its `when` condition requires the new independent default-OFF flag `STRIPE_DEPOSIT_FOLLOWUP_SWEEP_ENABLED`. Existing `STRIPE_DEPOSIT_REVIEW_ENABLED` and `STRIPE_DEPOSIT_FOLLOWUP_ROUTING_ENABLED` must also be deliberately enabled, with the approved same-account configuration and separate follow-up signing secret from Part56. A scheduler/cron process still must actually execute Laravel's scheduler; registering a schedule is not proof of deployment.

Set `STRIPE_DEPOSIT_FOLLOWUP_SWEEP_ACTOR_ID` to an explicitly approved current active admin/super-admin with `review stripe deposit followups` duty. No system user or permission is automatically created or granted. Every operation and item rechecks current primary-server staff authority through the existing access boundary. This is explicit trusted-host service attribution, not an assertion the human personally logged in for every cron tick. Existing role-cache/global writer limitations remain.

Manual bounded invocation:

```bash
php artisan stripe-followups:sweep --actor=123 --limit=25
```

`123` is illustrative: supply a real approved existing staff ID, never invent one. Actor and limit options reject malformed values; limit must be1–25. Without `--actor`, the configured actor is used. Missing/unauthorized staff or disabled prerequisites cause a nonzero command exit. JSON output contains only redacted per-item results, never lease tokens or raw events. Authorization/infrastructure failures stop remaining work, and earlier independent commits may remain durable.

Apply additive migration `2026_10_18_000500_create_stripe_followup_sweep_work.php` after Part57. It adds `stripe_followup_sweep_work` and `stripe_followup_sweep_events`, restrictive inbox/user/decision/work foreign keys, and a unique inbox-to-work identity. Encryption keys and original capture/inbox/review/routing custody must remain available.

## Enrollment and oldest-due retry selection

Enrollment repeatedly selects the first100 visible scoped inbox rows without a work row through `NOT EXISTS`; there is NO persisted increasing-ID watermark. A lower-ID row published later remains enrollable. Each enrollment commits independently; unique inbox identity handles concurrent attempts without duplicate work. Invalid source ciphertext is represented as operationally held, never accepted financial evidence.

Claims select the earliest `available_epoch`, then work ID, among pending/retry/expired-lease rows. The candidate ID is discovered without a lock. A new SQL root locks that exact Work primary row FIRST, then rechecks staff, protected current header, source and eligibility. A competing claimant that waited and finds a no-longer-eligible row returns no lease; that invocation can stop before other eligible rows. The next scheduler tick can continue. Therefore this is bounded oldest-due best effort, not a complete empty-backlog certificate, universal fairness guarantee, throughput SLA or absence proof under continuous overload.

A normal claim commits a random UUID token, actor, incremented generation, incremented retry-budget usage and a300-second expiry before executing any route. Queue locks are NOT held across original wallet/player/deposit/payment/attempt locks. SQL ownership, not Redis scheduler locks or a single-server cache convention, serializes each claim. Multiple scheduler hosts are supported for this named claim protocol; universal global writer closure is not certified.

## At-least-once execution and completion fencing

The executor checks strict lease shape, current staff, current protected work/source, actor, generation, token, source ID and unexpired ownership BEFORE dispatch. It then invokes `StripeFollowupRoutingService::route`, which independently re-proves current original/capture/canonical-credit/source boundaries in its own top-level SQL root. The worker never supplies a target deposit, provider endpoint, amount or caller-generated financial proof.

After routing commits, a DIFFERENT short SQL root locks the Work row, validates its protected header and current lease again, validates source custody, and authenticates the particular routing receipt through the router's history service before recording completion. No queue lock is acquired while a routing wallet lock remains held. `retryHeld` likewise acquires the current Work row before the fresh in-root staff read, avoiding a pre-lock repeatable-read snapshot that could miss a competing committed event.

If ownership expired or changed, the old executor returns `lease_lost` without acknowledging someone else's lease. Its route may already have committed: `routing_committed=true` reports that fact and is NOT financial application. An unexpected completion-write failure also leaves routing committed but the queue header at its prior leased state. After expiry another approved worker may reclaim a NEW generation/token and safely route again through authenticated idempotent routing. This is deliberately **at least once**, not exactly-once execution or a fence preventing all post-expiry route commits. No external provider HTTP or money action occurs in this lane.

A crashed/abandoned lease becomes eligible at expiry. An old token cannot execute or complete a newer generation. The supplied recovery tests use controlled clock advancement and injected faults, not an actual OS-kill/power-loss experiment.

## Backoff, budget and explicit held-item retry

- A proved routed result marks the work `routed`. This is only a historical source-routing outcome, not case acknowledgement or current clearance.
- Unmatched outcomes and expected financial proof refusals become `retry`, initially due in60 seconds, then exponential delay capped at3600 seconds. No uncertain original reservation is released or resent.
- At100 claimed attempts per budget, an unsuccessful completed item becomes `held`; an abandoned expired lease at exhausted budget is also held instead of granted another lease.
- A provable operational header whose signed source is now corrupt is quarantined as held without routing. Other due rows can continue. Corrupt/missing protected operational header custody itself is not silently healed; the tick refuses for incident investigation.
- Approved staff can retry only a held item, after current source validation and one of `capture_now_visible`, `evidence_investigated`, `operational_retry`. This resets only the budget and due time, NEVER generation, original routing receipts or case history. The next claim advances generation. Invalid reasons or nonheld state refuse.
- Generation is bounded at1000000. Saturation refuses and needs a separately reviewed migration/archive operation, not a silent counter reset. Event history is not automatically purged.

Manual routing through Part57 remains independent: it does not magically update or clear a worker's historical index. A routed worker item does not need periodic rerouting merely because another signed event arrived; the new inbox event receives its own work identity. New linked evidence reopens the review when a worker/manual routing root observes it, not atomically at ingress. Withdrawals are not gated by these hints.

## Protected operational custody, not financial authority

Every enrollment/claim/completion/quarantine/requeue appends an encrypted event. The receipt binds a normalized entire Work header, work UUID/source hash/account/configuration, generation, token/lease actor/expiry, budget, due epoch, last routing decision, transition actor/kind/reason and predecessor event ID. A current header is accepted only when its latest event ciphertext/MAC/digest and indexed fields agree. Source crypto and current scope are separately re-proved before routing.

Each event has a UNIQUE nullable predecessor reference and restrictive self foreign key. After initial enrollment, one predecessor can have only one successor. Repeated quarantine/requeue transitions in the SAME lease generation do not reuse an artificial `(generation, kind)` slot. This repairs a real design defect found during review; a focused regression executes the repeated same-generation sequence through public domain methods. Each work/event transition is transaction-atomic; failure between the index update and event insert cannot publish a partial header.

This is not a new external immutable ledger: the service verifies the current header/latest receipt, not every historical predecessor's ciphertext on each claim. Complete privileged deletion, arbitrary database/schema/encryption-key compromise, global anti-rollback and universal writer/permission fences are not certified. Financial authority remains exclusively in the independently proved original router. Queue state cannot authorize money, release, refund absence or harmlessness.

The read-only Work model hides lease capability/sensitive binding fields and rejects instance save/delete. Bulk builders and privileged raw SQL are not claimed to be database-enforced append-only controls. Finance table columns are historical operational hints; backend mutations re-prove current state rather than trusting those indexes.

## Acceptance scope

The final local profile includes command execution, actual Livewire held-retry action, budget100/held/requeue, delayed retry, lower-ID enrollment, enrollment101 split100+1, abandoned/stale lease and post-routing completion recovery, corruption/quarantine, current role/configuration boundaries and transaction faults. Three new native two-child SQL waits cover one lease under competing Work waiters, one audited held retry under competing Work waiters, and distinct committed leases routing under the same original wallet wait. Previous routing/review/capture witnesses are retained in the selected native profile. Exact results, source freeze, overlapping skips and intentionally failing isolated controls are in the supplied acceptance JSON/runtime evidence.

No real merchant event, live Stripe API request, production migration, populated restore, remote CI execution, OS-kill/power-loss, bank settlement, refund or worldwide legal approval occurred. Official signed-ingress protocol references remain in Parts56–57; this part does not change their signature protocol.
