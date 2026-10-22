# Owned refund-and-replace amendment protocol — Part 46

## Actual application change

The amendment API now coordinates an original-specific member refund and exactly one newly funded replacement dispatch. It is not a second ledger writer. Existing cancellation, paid-commission compensation, ordinary purchase, risk reservation, canonical journal and commission capture primitives remain the money writers. The application container binds the internal replacement interface to the real ordinary purchase service.

The old implementation could return a globally keyed report before checking its owner/payload, run two purchases behind one replayed cancellation, truncate replacement keys, blindly replay Pending, or label an already committed purchase Failed after losing its acknowledgement. These are application write-path changes, not test-only changes.

## Ownership, scope and immutable proposal

A new `bet_amendment_ownerships` row uniquely owns the original funded purchase, amendment report, member refund, full replacement bet/financial keys and eventual replacement purchase. There is no expiry, lease, reset, takeover, source-adoption or resend method.

The report key is `amend-v1-{authenticated-user-id}-{sha256(trimmed-client-key)}`. The complete validated client key participates in the digest. It is not truncated. The independent server-generated replacement client key is a UUID-derived value, and both full ordinary-purchase derived keys are stored. Two 128-character client keys sharing their first 127 characters remain different requests. Identical raw keys belonging to different authenticated users do not share a report.

Replay requires the same original bet, authenticated user and effective canonical number/stake proposal. Optional omitted fields resolve against the immutable original selection, not a current replacement. The source hash binds the original purchase/risk/debit/commission membership facts. The request hash binds user, original bet, canonical replacement number and exact decimal stake. The terms hash additionally binds report identity, original owner/wallet/draw/currency/market, both old/new terms, full replacement keys and the specific refund transaction. Mutable outcome fields are checked separately, never trusted as authority.

A report without its own durable owner is not enrolled, including an old Applied, Failed, Pending or Expired row. Another request key cannot acquire the same original purchase. A member cannot amend another member's bet, and a key cannot silently move to another original.

## Phase 1: atomically refund and permanently claim the attempt

1. Refuse an ambient transaction. The coordinator owns its root; the refund and replacement must not accidentally become one transaction.
2. Resolve only routing hints. Lock the sorted original member and captured agent-payout wallets before draw, ticket, bet, risk bucket and source rows. A changed/missing route is a refusal, not permission to lock an undisclosed late wallet.
3. Read current locked original purchase, exact selection, canonical original debit and all matching original financial references. Reject unowned history, conflicting reports/claims, an inactive original, disabled new amendments or an expired original amendment window.
4. Create the new Pending report inside this same transaction, then call the real original cancellation with reason `amendment`. A replayed refund cannot establish a new owner. Existing cancellation policies, earned/paid floors, hold protection and atomic mixed commission compensation still apply.
5. Run readonly ordinary canonical validation after OUR original risk reservation has been released, but before this transaction commits. This permits a same-number larger replacement that fits after releasing its own stake. Validation refusal rolls back the complete member/agent refund, counters, report and audit. There is no early refund commit merely to validate syntax/risk.
6. Store the exact source/proposal/refund/full-key ownership row in `attempting` state. Mint the ephemeral permit from the freshly created report in this transaction. Commit the complete refund and attempt ownership together.

The default state is not used as a lease. The attempt marker means the ordinary purchase may have run, not that somebody may try again when nothing is found.

## Ephemeral dispatch authority

Only the fresh phase-1 caller receives `BetAmendmentPermit`. Its constructor is private; minting requires a newly created Pending report inside a transaction and its current new attempting owner. The permit binds owner ID and sealed terms. It is not persisted, returned to the browser, reconstructed from a loaded report or accepted from a purchase request array.

The fresh coordinator calls the ordinary purchase service's internal `purchaseAmendment` entry once with that permit. The normal purchase API supplies no permit. Supplying the reserved client key, copied hashes or a JSON `permit` field to the public purchase request does not authorize a replacement: the fresh-purchase hook refuses and rolls back the debit, risk, bet, ticket and commissions. Ordinary unrelated purchases retain their original behavior.

If the process dies after phase 1, the permit is lost. The durable refund and attempting owner remain; no timer or second request reconstructs dispatch authority. An independently authorized review may inspect that situation, but this release intentionally offers no automatic resumption or guessed historical completion.

## Phase 2: real purchase plus receipt/report in one commit

The ordinary purchase pipeline retains its no-ambient-transaction rule, wallet-first locks, replay checks, current affordability and risk proof, newly created bet/item/ticket, canonical debit and fresh commission/purchase ownership capture.

Immediately after fresh purchase ownership capture, but before the purchase transaction commits, the amendment hook:

- resolves the owner by its full canonical replacement bet key;
- requires the ephemeral matching permit and genuinely new bet/debit instances;
- proves the sealed Pending/attempting plan and exact original cancelled member refund;
- proves current newly funded purchase ownership, owner/wallet/draw/currency/market, number/stake and exact replacement financial key;
- stores the replacement ownership ID and a hash of its original funded debit/risk/commission facts;
- marks the owned report and claim Applied with the same completion stamp and writes the amendment audit.

Any late failure, including amendment-audit failure, rolls back the entire replacement transaction. Phase 1 remains committed, refunded and attempting. A normal return alone never establishes Applied; the service reads the committed original-specific receipt.

## Outcomes and replay

**Applied:** The response proves both the original cancelled member refund and the linked replacement's original funding. Losing the purchase acknowledgement after its commit still returns this proven Applied result. A misleading typed insufficient-balance error after commit cannot convert Applied into Failed.

**Failed/refunded:** Only a known pre-mutation purchase-validation refusal or the explicitly typed insufficient-balance refusal can be classified this way. Under the locked member wallet, the coordinator first checks for an already Applied receipt. Otherwise the exact Pending/attempting shape, source refund and absence of BOTH canonical replacement bet and financial artifacts must hold before atomically stamping Failed. Deleted artifacts also count. A later replay re-proves the original refund, exact failed projection and canonical artifact absence; it does not resend.

**Unresolved:** Any unexpected Throwable, uncertain result, missing receipt, inconsistent source/proposal/report, partial artifact or changed financial proof is held with `bet_amendment_outcome_unresolved`. It is not relabelled Failed merely because the current query found no replacement. Replays are read-only and never recreate the permit or purchase. The user may see a controlled review-required refusal even though the original refund is committed; that is deliberately different from claiming that no money moved.

Owner/request conflicts use `bet_amendment_request_conflict`. The controller now uses the real `errorCode()` exception API and safe financial/risk error mapping rather than nonexistent exception methods. Reports remain response projections, not financial authority.

## Later replacement lifecycle

An amendment's Applied claim certifies its original refund-and-new-funding event. It does not certify the replacement's future prize, loss, cancellation, current balance or settlement outcome.

`BetPurchaseOwnership::funded()` verifies only immutable original funding with exact current original terms/debit. It tolerates mutable winning/actual-payout fields and does not confer cancellation permission or adopt unrelated financial histories. The ordinary strict `locked()` cancellation proof still uses winner-free terms and exact matching original financial references. No mutation caller was changed to use the readonly funding proof.

A later real replacement cancellation does not erase its earlier original funded amendment fact. A changed original debit, number, immutable term or receipt hash refuses replay rather than silently repairing or adopting history. The tests separately distinguish readonly winning-field proof from default strict refund permission; they are not a claim of new end-to-end prize settlement certification.

## Expiry, retention and deployment

Managed reports are created without `expires_at`. `sweepExpired()` excludes every report with a durable owner even if its report clock was later altered. It may still change an unowned historical report label; that cannot release an original purchase, dispatch key or refund authority. A changed managed report clock is a proof inconsistency, not a lease renewal.

The additive migration does no backfill. Its rollback refuses to drop a populated ownership table. Apply it through an authorized deployment before enabling this source version; a missing schema must fail rather than bypass the ownership hook. Preserve claims, original transactions, journals, report IDs and keys. Never delete an attempting row, manufacture a Failed/Applied label, change a canonical key, infer a commit from balances or reuse the reserved key to resolve an unknown result.

The separately recorded local acceptance covers synthetic THB/BDT/USD owned fixtures, paid/unpaid original commissions, current native SQL and observed two-child lock contention. It is not remote deployment, historical reconciliation, official result authenticity, provider authentication or a real merchant transfer.
