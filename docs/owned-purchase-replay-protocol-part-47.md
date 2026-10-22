# Original funded purchase replay — Part 47

## Application defect and implemented boundary

Ordinary purchase replay previously returned the first bet item, an existing ticket and the first polymorphically referenced financial transaction. A row labelled paid or an unrelated refund could be mistaken for original funding. No current original journal or ticket identity proof was required. Decimal comparison could round an over-precise retry into the original amount. The unlocked fast path also returned inside an ambient transaction, despite the purchase writer's root-ownership rule.

The ordinary purchase, `existingPurchaseFor`, direct `replayResultFor` and lost-idempotency-race paths now use the same current original-funding proof. This changes actual application receipt creation and replay. It is not a new payment adapter, an alternate wallet writer, a historical reconciler or a test-only tranche.

## Fresh receipt capture in the actual purchase transaction

The additive `bet_purchase_receipts` table owns one original purchase ownership row, ticket, original debit and full financial key. Each binding has a UNIQUE constraint and restrictive foreign keys. It has no expiry, lease or reset state.

Immediately after the ordinary purchase has created and confirmed its ticket, completed its canonical debit, recorded risk/purchase ownership and captured commission membership, `BetPurchaseReceipt::record` captures a receipt in that SAME transaction. The amendment replacement hook still executes afterward within the same commit. A failed receipt insert or later amendment hook rolls back the entire new bet/item/ticket, risk reservation, member debit, journal and new commission ownership. No handwritten financial compensation is attempted after rollback.

Capture requires genuinely newly created bet, original financial transaction and confirmed ticket model instances in an active transaction. It verifies the source purchase is active and funded through the existing canonical original-debit proof. Loaded models cannot be enrolled. UNIQUE constraints remain authoritative if an invocation is repeated.

The versioned receipt hash binds:

- original purchase, bet, wallet, original transaction and risk-bucket identities;
- original currency, stake, potential payout, immutable bet/item terms hash and original accounting fingerprint;
- captured original commission membership hash/count;
- full original financial idempotency key;
- ticket ID, UUID, number, owner, draw, currency, exact total and single-bet/single-selection counts;
- original issued/confirmed timestamps and ticket metadata.

Ticket totals are selected as SQL decimal strings and canonicalized with bcmath, not a float. The ticket must belong to the original bet's member/draw/currency, and no additional standing or soft-deleted bet may share this single-purchase ticket. Mutable later ticket status/cancellation/expiry stamps are not original receipt identity.

## Current locked replay algorithm

1. Refuse a framework or raw-PDO ambient transaction. Purchase/replay owns its root; an old caller snapshot cannot authorize this shortcut.
2. Derive the complete ordinary bet key from authenticated user, draw and untruncated client key. Find a possible existing bet, including soft-deleted history. An unused key can continue through the unchanged ordinary sales pipeline; this read does not reserve money or create authority.
3. Treat the candidate and purchase-ownership route only as hints. Within a new transaction, lock the original wallet first, then the hinted ticket, current bet, source ownership, selection and original accounting rows. Re-read and verify the original identities. A changed or missing route is a refusal, not permission to create a wallet or acquire a guessed replacement route.
4. Use `BetPurchaseOwnership::funded` for immutable original funding. This verifies the actual original debit and canonical matching journal against stored ownership; it does not pick the first referenced transaction or treat a refund as the debit.
5. Verify the sealed original receipt against current original source and ticket facts. Missing ownership/receipt, deleted artifacts, changed ticket, extra/deleted selection, changed risk binding or altered original header/journal refuses. Nothing is repaired or backfilled.
6. Compare the incoming effective request to the current locked/proved selection and original bet, not an earlier unlocked item. Verify both the derived bet key and full original financial key. A market/number/owner/draw/stake mismatch does not replay.
7. Return Replayed with the actual original debit, its canonical journal IDs, exact original stake/potential payout and an empty new risk reservation. Response context identifies `proof_scope=original_funding_only` and the durable receipt ID. SQL proof aliases are not returned as writable item attributes.

Read-only transaction retries may repeat proof after a database rollback; they cannot dispatch a purchase or resend a debit. A lost purchase race is replayed only after the losing mutation transaction has unwound and the winner passes this same proof. If no proved winner is visible, the existing concurrency error is retained rather than retrying a new purchase.

`resolve`, `mismatchedFields` and `debitFor` remain low-level lookup/comparison helpers, not funded-success authority. `debitFor` now uses the durable original ownership pointer instead of a first polymorphic reference. Every actual ordinary replay result builder enters the protected proof service.

## Exact request amount policy

An amount must be a positive decimal string with at most the original currency's scale before comparison. `10`, `10.0`, `10.00` and a valid leading-zero decimal spelling can represent the same original stake. `10.001`, `10.000`, scientific notation, a sign, zero or a materially different amount cannot be rounded into replay. Existing canonical number-width/leading-zero matching is retained. Current sales configuration is not used to re-price a committed purchase.

## Original funding is not current prize/refund authority

A closed draw, changed current sales currency, frozen wallet or later winning fields does not erase an original correctly funded receipt. A genuinely cancelled purchase replays its ORIGINAL debit, never its refund and never a new purchase. Its returned current bet state may be Cancelled; Replayed does not mean Active again.

Readonly funding proof does not authorize cancellation, payout, reversal, balance repair or a new risk reservation. Strict cancellation proof and its source/counter/hold/commission policies remain unchanged. Later winning-field tests distinguish historical funding from mutation permission; they do not claim a new end-to-end prize settlement certification. Extra or unintegrated financial histories are not adopted as the original debit or globally reconciled by this historical-funding acknowledgement.

The Part46 amendment dispatch capability remains mandatory for a NEW replacement purchase. A reserved key with no fresh in-memory permit still rolls back. A completed replacement can now also return its own original ordinary-purchase receipt without giving its caller any new dispatch permission.

## Migration, historical records and retention

Apply the additive migration through an authorized deployment before running this writer version. No historical ticket/receipt snapshot is inferred from current labels or counters. An earlier otherwise-owned purchase without this NEW receipt is held on ordinary replay for independent review; replay cannot invent an immutable original ticket snapshot after the fact. Previously source-owned cancellation/amendment proof retains its own existing rules; this table does not fabricate or silently revoke those separate authorities.

Do not delete receipt/ownership rows, release original financial keys, clear an ambiguous state or change original facts to make replay pass. The migration refuses to erase a populated receipt table. Any historical migration or resolution procedure needs separate original-specific authority and evidence.

The complete selected native acceptance includes actual simultaneous new-key purchase, existing receipt replay and cancelled purchase replay under observed original-wallet lock contention. All operations use owned disposable synthetic stores. Currency test coverage is not country licensing, bank authentication, live transfer authorization or an all-writer/global-lock certification.
