# Runbook: payment and ledger incidents

## Immediate controls

1. Declare an incident and assign an incident commander, payment operator, ledger reviewer and communications owner.
2. Identify affected lane and time interval using opaque transaction/event identifiers only. Do not copy payment credentials, full bank account numbers, identity documents or secret payloads into logs or chat.
3. If money movement or state is uncertain, stop new submissions for the affected lane and preserve provider responses, webhook delivery IDs, application audit events and ledger records. Do not replay a payment or payout to test whether it succeeds.
4. Preserve database and private-storage evidence through approved backups. Keep the original data immutable; work from a restricted copy.
5. Require two-person review for any manual reconciliation or operator-approved money movement. An application status label alone is not proof of settlement.

## Uncertain bank payout

A timeout or missing callback does not prove that a bank transfer failed. Never resend an uncertain payout.

1. Record the internal payout ID, idempotency key, bank and submission timestamp; do not include recipient PII in incident notes.
2. Poll or query the bank using its documented status inquiry for the original provider reference. Use a read-only inquiry only; do not submit another transfer.
3. Compare the response with signed webhook history and the internal payout state. Verify signature, event identity, event timestamp, amount and currency using the designated service; do not trust an unsigned screenshot or operator-entered status.
4. Reconcile the exact amount against the wallet/ledger entries and bank statement or provider settlement evidence. A pending/unknown response remains uncertain.
5. If the provider has no authoritative status, escalate to the bank's approved support channel and obtain evidence tied to the original reference. Keep the lane paused.
6. Only after independent evidence establishes the actual outcome may an authorized operator apply the approved reconciliation workflow. Attach the evidence reference and audit the decision. Do not create a second payout to force a match.

## Webhook replay, duplicate or missing event

1. Preserve the provider event ID, endpoint path, delivery timestamps and HTTP result. Do not log raw secret signatures or personal payloads.
2. Confirm the route and signature scheme against `routes/api.php` or `routes/thai_rails.php`. Compare the raw-body signature result, provider timestamp policy and durable replay-guard row.
3. For a duplicate event, verify that idempotent processing produced exactly one state transition and one canonical ledger effect. A duplicate HTTP delivery is not permission to post again.
4. For a missing event, use the provider's read-only event lookup or signed redelivery mechanism for the original event ID. Preserve the original event identity; do not fabricate a callback or directly update balances.
5. If signature verification, event ordering, or replay state is ambiguous, keep the item held and escalate to the payment integration owner. Do not disable signature or replay protection to clear a queue.

## Slip fraud or unverifiable deposit

1. Pause the affected review/provider lane if a suspicious slip pattern or verification bypass is suspected.
2. Preserve the original private object, its immutable storage identifier, upload time, verification response and audit record. Restrict access to authorized reviewers; do not copy the image into ordinary logs or tickets.
3. Verify the slip using the configured authoritative provider/API and compare amount, currency, reference, beneficiary and timestamp with independent bank evidence. Do not accept OCR or a user-provided image as proof of funds.
4. Search for duplicate references and previously credited transactions before any manual action. Use a read-only investigation first.
5. If the evidence is inconclusive, leave the deposit pending/held. Do not credit, refund or accuse the user based on a heuristic. Escalate through the privacy and fraud response processes.
6. If confirmed fraud or a security compromise is found, preserve chain of custody and follow the organization's legal/privacy notification rules.

## TrueMoney `RedeemedUnconfirmed`

1. Treat `RedeemedUnconfirmed` as uncertain, not as a confirmed deposit and not as a failed redemption. Do not ask the user to redeem the voucher again.
2. Keep the voucher secret and personal data out of logs. Capture only internal record IDs and provider correlation IDs.
3. Query the provider's documented status endpoint using the original idempotency/correlation key. Verify that the response is authentic and belongs to the exact redemption attempt.
4. If the provider confirms redemption, reconcile the exact amount and currency against the ledger and use the audited reconciliation path. If it confirms non-redemption, follow the provider's approved release/refund process. If unresolved, keep the case held and escalate.
5. Never create a second redemption or manually mark the voucher successful without authoritative provider evidence.

## Wallet/ledger mismatch

1. Pause affected money movement and preserve the current transaction boundary. Do not directly edit wallet balances, ledger rows, or cached totals.
2. Run the approved read-only reconciliation command/report for the affected account and time range. Verify database connection, currency precision, transaction status, reversals and idempotency evidence.
3. Compare canonical ledger postings with the wallet projection and provider/bank evidence. Determine whether the discrepancy is a missing posting, duplicate posting, stale projection or incomplete external settlement.
4. Have an independent reviewer verify the proposed repair. Use an existing audited correction/reconciliation workflow; never issue raw SQL to adjust a balance.
5. Re-run reconciliation and confirm all affected accounts and linked transactions. Keep the lane paused until the owner authorizes reopening.

## Closure

Document scope, timeline, opaque record IDs, provider evidence reference, financial reconciliation result, user impact, corrective action and follow-up owner. Do not record secrets, full payment card/bank details, credentials or decrypted PII. Obtain finance and security sign-off before closing an incident involving money movement or suspected data exposure.
