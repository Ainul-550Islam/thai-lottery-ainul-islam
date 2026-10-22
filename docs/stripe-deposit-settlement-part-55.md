# Original Stripe captured-funding reconciliation — Part55

## What actually moves money

The new `StripeDepositSettlementService::reconcile` is an authenticated staff application path from an already owned, projected original Stripe checkout to canonical deposit accounting. Its real Filament confirmation action says that successful verification can credit balance. It is not a customer browser-return grant or a fake provider callback.

The feature defaults OFF through `STRIPE_DEPOSIT_SETTLEMENT_ENABLED`. The separate duty is `reconcile stripe deposit settlements`: current active admin/super-admin role AND explicit permission. The normal admin role does not automatically receive it. The actor is freshly checked before the original snapshot, before evidence custody and again before credit. These checks do not certify arbitrary permission-table writers or globally synchronized role revocation.

Part54 original-session discovery remains a separate no-money action. An unknown checkout must first acquire valid original response custody and SQL projection. No historical ownerless checkout can be enrolled by supplying a provider ID. No public request accepts a caller's amount, proof object, payment reference, account selector or URL as settlement authority.

## Authenticated read protocol

Only fixed Stripe API GETs are used, in this order:

1. Retrieve the exact original Checkout Session from committed checkout response custody.
2. Retrieve its unexpanded `payment_intent` ID.
3. Retrieve that PaymentIntent's unexpanded `latest_charge` ID.
4. List refunds for that exact charge, with `limit=1`.

Stripe documents PaymentIntent captured/received amounts, latest-charge association and Charge capture/refund/dispute properties separately from a Checkout Session label. An authenticated refund-list query supports the exact charge filter. [1](https://docs.stripe.com/api/payment_intents/object) [2](https://docs.stripe.com/api/charges/object) [3](https://docs.stripe.com/api/refunds/list)

All requests use the original-current configuration's secret, a fixed HTTPS host, redirects disabled, 5-second connect and 15-second request timeouts, and no automatic retry. Response JSON is size-checked before parsing at 1MiB/depth64; this is not a streaming network-body memory cap. At most four GETs occur in one observation, without a framework or PDO transaction. Read failure, missing fields, timeout, unexpected shape, expanded IDs or any incomplete observation remain held. There is no provider create/capture/expire/cancel/refund/transfer POST or DELETE.

Required positive facts include:

- Original attempt UUID, deposit ID/reference, wallet/user IDs and client reference match exactly on the original session; creation falls within the existing original dispatch window.
- Session is payment-mode, complete AND paid; its PaymentIntent ID is the actual retrieved object's ID.
- PaymentIntent is succeeded, has zero capturable remainder, and both amount and amount_received equal the original gross amount in integer minor units.
- Latest Charge ID and its PaymentIntent back-reference agree; Charge is succeeded, paid and captured with exact full original amount and amount_captured.
- Every object has the original currency, consistent secret-key test/live mode, integer amounts and bounded creation timestamps. Current supported THB/USD/BDT are two-decimal currencies; unsupported future currencies are not silently scaled.
- Charge has zero amount_refunded, explicit false refunded/disputed flags and no active review. Connect transfer/on-behalf-of/application-fee flows are not supported by this lane.
- Refund list is a complete empty list. Any listed refund, including pending/failed/cancelled history, is conservatively held; an empty list is NOT negative-outcome/cancellation authority and does not release anything.

These are processor captured-funding observations suitable for the application's existing deposit-credit policy, not proof of merchant bank payout availability, irreversible finality or immunity to later refund/dispute. The four remote reads are not an atomic provider snapshot; external facts can change after any response. Production monitoring and refund/dispute protocols remain necessary.

## Durable custody, ownership and freshness

The additive migration creates a permanent per-attempt capture owner and append-only observation receipt history, plus a nullable current receipt marker on original attempts. It backfills no history. Account-scoped intent and charge identity hashes have unique database constraints within this lane, so a second original session cannot reuse the same captured-funding IDs here. This does not certify every other callback or manual writer globally.

Snapshot and final capture use the original wallet/player/deposit/Payment/attempt order. The original account, request/configuration HMAC, fence, terms, dispatch identity, original response hash and projected session reference are revalidated after the GETs. The custody transaction freshly checks staff authority, validates provider facts, records ownership, encrypts the full bounded observation with actor/observation time/binding inside it, and commits the receipt and current marker together. It does not credit balance.

Only afterwards does an independent SQL-only credit transaction revalidate the receipt, original graph and current staff. SQL deadlock retries never repeat provider requests. An interrupted/failed credit preserves receipt custody but rolls back approval, captured labels, credit, journal and applied marker together. The final receipt marker/applied timestamp and canonical credit commit atomically. Controlled fault injection verifies this boundary; it is not process-kill/power-loss certification.

An unapplied observation can authorize a new credit for at most five minutes. Within that interval a staff retry can use SQL-only custody. After that interval the service performs four fresh GETs and appends a new receipt if the facts still qualify; it never overwrites old observation ciphertext. If a refund is now observed, nothing is credited and previous custody remains. Timeouts/age never prove safe nonacceptance, reset the dispatch count or release a reservation. No unattended customer/background credit retry was added.

Both a required marker with a missing receipt and a marker-null orphan receipt are refused. Decryption, plaintext hash, embedded actor/time/binding, original reference, proof chain and permanent owner must agree. Already applied replay re-proves existing canonical deposit evidence and does not fetch Stripe or credit again. Arbitrary SQL plus application-key compromise remains outside this application boundary.

## Canonical accounting and reporting

New credit uses existing `DepositApprovalService` and `DepositCompletionService`, not direct wallet SQL. It preserves deterministic original-credit identity, exact original gross/fee/net accounting, provider-reference ownership, current player admission, wallet-credit eligibility and responsible-gaming policy. If current admission refuses, the independent observed receipt remains held and no financial projection survives. Approval does not bypass the completion guard.

A concurrent already-proven original credit is verified and returned as replay, never recreated or reversed. The original checkout count stays one. New metadata identifies the actual settlement receipt; `provider_normalized` and `payment_webhooks` are not fabricated or relabeled.

`PaymentReconciliationService` can consume a validated applied captured-funding receipt when no matching Applied webhook exists. Its observation reader performs no wallet-lock acquisition under the report's Payment lock. The latest matching Applied webhook observation retains precedence, including linked Charge/PaymentIntent refund-related drift, rather than being hidden by historical capture evidence. Refund/dispute follow-ups with minor-unit refund amounts require review; they are not mislabeled as the original paid major-unit amount or invented definitive reversal. Unrelated Charge IDs do not override the original receipt. Arrival-order-independent event-history adjudication is still separate work. This is provider-observation reporting, not a replacement for ledger reconciliation or live dispute monitoring.

## Local verification and deployment limits

The new native race observes two real child connections simultaneously blocked on the original wallet. After release, a second synthetic provider-response barrier forces BOTH children through all four GETs outside SQL before either publishes custody. Assertions require two successful results, exactly one credited result, one receipt/owner, one canonical financial transaction and two ledger entries. SQL errors are not accepted as a winning race outcome. This does not certify cross-wallet duplicate-owner races, creator-response races or universal lock-order closure.

Default regression executes every source test file in a separate process/disposable SQLite store; selected native acceptance uses the owned Unix-socket-only InnoDB database. Tests use synthetic provider responses, not a live merchant. Full-source checks, exact profile overlap/skips, counterfactual omission failures and hashes are supplied in the acceptance evidence. CI configuration is included; remote CI was not run here.

Before activation: rehearse migration and rollback/restore on nonproduction data, preserve keys/backups, obtain applicable merchant/business/jurisdiction approval, deliberately assign the duty and enable the server flag. No production migration, real merchant call, external charge, funds transfer or worldwide approval occurred in this work.
