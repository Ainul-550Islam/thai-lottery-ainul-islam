# Part62 — source-owned initial withdrawal holds and atomic handoff

## Implemented money behavior

The member API previously placed a generic hold when creating a withdrawal, while canonical approval separately reserved the amount. Part61 made the first request/hold atomic and exact replay safe, but did not make that generic hold an approved reservation. Part62 gives newly created API initial holds their own durable ownership and transfers that same source to canonical approval **without another hold or an unlock/relock window**.

For a 100.00 wallet and a 10.00 request: request leaves balance 100.00 / held 10.00; approval still leaves balance 100.00 / held 10.00; canonical completion leaves balance 90.00 / held 0.00 and one original withdrawal debit with balanced accounting. Approval also works when all 10.00 of a 10.00 wallet is already held by this exact initial source. It does not require a fictitious second 10.00 of available funds.

This changes real service, HTTP, hold, reservation, wallet and canonical ledger write paths. It does not invoke a payment provider or fabricate a live cash transfer.

## New durable source and backward-compatible terms

The additive migration creates `withdrawal_initial_holds` and adds a nullable, unique, restrictive-FK `initial_hold_id` to canonical `withdrawal_reservations`. Existing canonical owners retain null pointers. Migration performs no wallet adjustment, status edit, backfill or adoption of historical anonymous holds.

`WithdrawalRequestWithHoldService` writes a server-owned `withdrawal_initial_hold_origin=v1` marker into fresh request metadata. The original-terms fingerprint includes that marker only when present; the exact old fingerprint formula is unchanged when absent. Consequently removing or replacing a new marker breaks the original terms rather than converting a new source into legacy history.

A fresh initial source binds withdrawal, wallet, player, currency, gross amount and original terms hash in an encrypted authenticated origin envelope. No payout destination is copied into that envelope. Header changes, bad ciphertext, a changed original, missing required source, or a contradictory canonical link fail closed on controlled actions and replay. The marker does not supply a missing source and a wallet total does not prove which request owns it.

The source has no TTL or automatic expiration. Its states are:

- `held`: initial request ownership only, no approved payout authority;
- `transferred`: one explicitly linked active canonical reservation owns the same held funds;
- `consumed`: canonical settlement closed both histories and proved the original debit;
- `released`: pending or canonical release closed that same original without a debit.

Creation itself holds funds once before inserting the sealed source, inside a savepoint within the request root. A failure after the actual wallet update or source insert rolls back the entire new request/hold unit. No caller can catch the failure and commit an orphaned hold from that unit.

## Lock order and current-read floors

The API finance adapter takes the selected wallet lock **before** canonical pre-policy reads can establish an old repeatable-read snapshot. The native identical-key test exposed a MariaDB snapshot-conflict refusal with the previous ordering; fixing the application ordering, rather than weakening the expectation to permit a failed duplicate, makes both exact requests observe one original.

Wallet locking and the request/player lock remain held through initial ownership creation. Initial ownership discovery and mechanical floor reads use current locking SQL reads. The central wallet floor adds live initial `held` amounts to existing active canonical reservation and independent financial-hold minima. It does not take the maximum of overlapping minima.

Transferred initial sources must have a matching active canonical link, amount, player, wallet, currency and original hash; they are checked, not counted twice. A missing canonical row or pointer does not turn a transferred source into spendable funds. Generic unlock, consume or debit mechanics cannot reduce live covered holds below their aggregate floor.

This is a live-owner mechanical floor, not a universal certificate against arbitrary SQL deletion, malicious rewriting of closed history, every legacy writer or global debt. Controlled source actions validate original and lifecycle proof. Existing canonical/local-hold invariants are retained.

## Approval, release and settlement

`WithdrawalReservationService::reserve()` owns one savepoint for source transfer, canonical insertion and coverage proof. It still rechecks source-backed refund exposure before granting NEW approved ownership. With a valid initial source, it marks that source transferred and inserts exactly one canonical reservation with the explicit pointer; it never calls hold again. Without an initial source, ordinary canonical request approval keeps its original fresh-hold behavior. A required but missing initial source refuses instead of falling back.

Cancellation, rejection and allowed local failure release a pending initial source without inventing approval. Approved release closes the canonical and linked initial source together before wallet mechanics check remaining owners. Canonical completion closes both, consumes the one original hold, posts one canonical debit and validates the original transaction and accounting. If any later step fails, the common savepoint rolls the close, unlock and debit back together.

An initial hold is **not** permission to dispatch or complete. Existing status, KYC, original provider evidence and canonical reservation requirements remain. The member cancellation endpoint retains its existing pending-only contract. Other server review paths keep their existing lifecycle permissions.

New refund custody after initial request blocks approval/handoff but does not prevent releasing that initial hold. Refund custody after canonical ownership does not prevent accounting for the already-owned settlement. No blanket refund guard was added to generic debit, release or completion.

## Exact replay and historical boundary

Exact API replay validates an existing new initial source and lifecycle link without holding again. Replays after completion or release cannot reopen ownership or alter wallet totals. Original service-only requests remain service-only; a replay never manufactures an initial source.

Historical anonymous API holds cannot safely be assigned to a request from wallet totals or mutable labels. This migration deliberately does not do that. Such pre-Part62 records require controlled reconciliation with independent provenance; their historical hold-to-approval handoff is not certified or automatically repaired by this increment. This boundary protects against spending somebody else's hold while apparently fixing a stranded balance.

## Verification

The dedicated feature class has 27 cases, including six corrupt/unbound source variants, pending/approved cancel/reject/failure, authenticated full lifecycle and cancellation, zero-available approval, additive floors, missing origin, changed/removed marker, exact old fingerprint preservation, refund publication boundaries, nonempty rollback preservation, and failures injected after actual source/canonical inserts while an outer caller catches and commits.

Five native profiles use two independent child PHP processes and database-observed simultaneous parent lock waits:

1. identical concurrent requests both return the same original with one initial hold;
2. competing new keys cannot both reserve a 10.00 wallet;
3. competing approvals transfer one original, without a second hold;
4. approval/cancellation races end without stranded or duplicate held funds;
5. two old empty repeatable-read initial-owner snapshots cannot authorize generic unlock after the parent publishes ownership.

The CI step requires all 32 dedicated cases, five genuine two-child wallet waits, exactly one old-empty-snapshot profile, and zero failures/errors/skips. Workflow parsing and local native execution are not remote CI execution. The accepted evidence gives complete default-source coverage, affected native coverage, exact IDs, overlap and omission controls; overlapping totals must not be added.

Empty schema up/down/up and rollback preservation are local test-store checks, not a populated production restore or power-loss certification. Production migration, merchant activation and legal approval remain separately controlled operational work.
