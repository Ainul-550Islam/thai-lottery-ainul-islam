# Owned draw cancellation — Part48

## Actual application path

Both existing Filament cancel-draw actions now call `DrawCancellationService::cancel(authenticatedActorId, drawId, reason)`. They no longer call a lifecycle-only status flip for funded draws. This is an actual wallet/refund/risk/commission write path, not a report-only replacement or a simulated settlement. Local acceptance uses synthetic balances only.

A plain `DrawLifecycleService::cancel()` / `transitionTo(Cancelled)` refuses any draw with bet history, including previously cancelled and soft-deleted bets, unless supplied the currently running coordinator instance. It also refuses anonymous nonzero number-limit capacity. Neither an administrator flag nor a context containing `refunded`, `force` or an ownership ID grants that capability. Empty, obligation-free legacy lifecycle transitions remain non-financial operations.

## Authorization and transaction ownership

- Require a root framework transaction boundary; reject ambient transactions without committing or rolling back the caller's boundary.
- Require a fresh active, non-deleted user, an explicit current `admin` or `super-admin` staff role and explicit `manage draws` duty. A super-admin name/Gate bypass alone is insufficient. Teams mode is conservatively refused.
- Read current user, role membership and direct/role permission rows with locks. Do not trust a previously loaded actor, cached relation or a page's old visibility decision. The default application permission schema/web guard is the supported schema here.
- The actor argument comes from the authenticated server action, never from submitted cancellation fields. The service authorizes again; the screen is not the authority.
- The durable header records the original operator and reason. Each newly dispatched member-refund audit records that operator, and the refund metadata links to the draw owner. A draw-level completion audit is committed with the same root transaction.
- A second currently authorized operator may read a proven terminal result. That does not rewrite who performed the original cancellation.

## Wallet-first current membership protocol

1. Outside the root transaction, obtain bounded navigation hints for all bet rows of the draw, including hidden histories, their original purchase ownerships and all associated original paid-agent wallets. Hints grant no authority.
2. Start one root database transaction, with no automatic root retry. Lock every hinted original member/paid-agent wallet together in numeric ID order. No wallet is created or re-resolved from the current account preference.
3. Lock/re-authorize current staff, then lock/re-read the draw.
4. Re-read complete bet/original-ownership membership with current locks. Re-read the associated original paid-agent wallet set. Require exact equality to the hints. If a racing purchase/payout changed membership or the wallet set, refuse and roll back; never acquire a newly discovered wallet underneath the held draw.
5. If a durable owner already exists, execute only the terminal proof path below. An incomplete, changed or unknown owner is review, not a new dispatch grant.
6. For a new owner require an allowed unpublished lifecycle state, the new-dispatch switch, the configured capacity and no payout/settlement-run history. Refuse hidden, unowned or unsupported bet histories; never infer funding from a bet label, counter or balance.
7. Seal the ordered original participant identities, wallet/source transaction pointers, original accounting/terms hashes, initial active/cancelled states and any original prior refund IDs. The unique draw key is permanent and has no lease, expiry or reset.
8. Create the owner and establish the coordinator's private in-memory dispatch context. This context exists only while the fresh authorized root is running. The service cannot be cloned or serialized. Resolving another service instance, loading a row, replaying an old request or passing context fields cannot reconstruct it.
9. Dispatch only initially active originals, using the existing strict per-bet cancellation engine and `DrawCancelled` reason. The live context alone bypasses the player's time window and player-feature switch. It does not bypass original funding/journal ownership, exact stake, ticket/item identity, shared risk floors, wallet credit restrictions or commission recovery policy.
10. Previously player/amendment-cancelled originals are proven but never credited again. Do not relabel their original cancellation reason or original refund.
11. Prove every original member refund and associated existing commission closure using canonical current accounting. Require all current draw number-limit stake/exposure counters to be exactly zero; unsupported anonymous residual reservations are not swept, assigned a recipient or left behind a newly cancelled draw.
12. Seal ordered refund IDs/hashes and the completion stamp against the original terms seal. Stage `refunded` inside the same uncommitted root. The lifecycle service requires the live instance and independently re-proves this complete closure before changing the draw to Cancelled. Set `completed`, add the completion audit and commit the root.

`preparing`, `refunded`, refund postings, cancellations, source closures, risk releases, agent compensation, lifecycle cancellation and audits are atomic. Other connections do not observe a successful partially cancelled draw. A later member/proof/policy/credit failure rolls back earlier work and the header together. There is no queue, external network call, partial chunk commit or automatic resume in this protocol.

## Existing original-specific commission mechanics

Unpaid owned direct/parent receivables close through the already integrated cancellation proof. Paid originals use only their existing captured compensation policy, original payout receipts, exact earned/paid closure and current wallet floors. Mixed paid/unpaid sources close together. A disabled/unbound historical policy, insufficient agent funds, corrupt original payout or unprovable compensation holds the entire draw without a partial member refund.

This release does not enroll past paid credits in a policy, reverse an unrelated receivable, or invent a recipient from aggregate counters. Closed originals can be proven after the new-dispatch compensation flag is disabled; that is not authorization for a fresh clawback.

## Proven read-only terminal replay

Require current requesting staff authority, the same normalized reason, the sealed original header, `completed` state, its sealed completion stamp and current Cancelled draw state. Prove exact current membership and original wallet/source identities, original paid debit/canonical journal, owned cancellation/refund/canonical journal, associated commission closure and zero current draw risk. Compare every actual refund outcome to the stored outcome seal. Labels, outcome JSON and aggregate amounts alone are insufficient.

Return the original ownership and refund transaction IDs with `proof_scope=original_owned_refunds`, `replayed=true`, and `newly_refunded_count=0`. Do not create an owner, credit, audit, risk release, repair or dispatch context. The new-dispatch switches and a subsequently reduced configured capacity cannot revoke this read-only original receipt; the protocol's hard participant ceiling still bounds reads. HTTP/page infrastructure may maintain its own unrelated telemetry; the domain replay itself performs no writes.

A changed reason conflicts. A missing owner on an already cancelled draw, incomplete owner, missing/deleted original, inconsistent membership, altered seal, journal or accounting failure requires controlled review. No historical capture, balance-based reconciliation, new key or unknown-outcome resend is performed.

## Boundaries, deployment and retention

- New dispatch defaults to at most 100 participant bets. Configuration must be between 1 and the hard ceiling of 1000. Excess volume refuses before any partial refund. This is not a volume-tested chunked cancellation engine.
- Apply the additive `draw_cancellation_ownerships` migration through authorized deployment before enabling this code. Unique draw ownership and restrictive actor/draw foreign keys are permanent. Populated `down()` refuses deletion. No existing financial hash namespace is rewritten, no historical rows are backfilled and no table is automatically purged.
- Publish/settled/already-cancelled-without-owner histories and any payout/settlement-run history do not enter new dispatch. This is not prize reversal, cancellation of a paid official draw, historical migration or a legal refund-policy decision.
- Every member bet history and its associated owned original commission obligations must be supported and proven. Unrelated/unbound commission, generic aggregate and other legacy writers are not globally certified by this coordinator.
- Zero risk is checked under current locks at the transition. Legacy generic reservation writers have not all been integrated into draw-lifecycle authority. Do not enable such writers concurrently in a rollout without separate all-writer review/acceptance. This release is not a global write barrier or global lock-order rewrite; legacy settlement's draw-first order still needs independent integration and contention acceptance.
- Synthetic THB/BDT/USD acceptance is not proof of any merchant, country/operator license, provider authentication, live transfer, worldwide permission, browser accessibility or deployment certification.

The accompanying evidence records exact final-source matrices, native two-process lock witnesses and isolated omission controls. Interrupted/preliminary runs are diagnostics, not accepted results. Default/native/static matrices overlap and must not be summed as unique coverage.
