# Signed Stripe follow-up inbox and operator review — Part56

## Implemented application path

Part55 supplied original captured-funding verification and canonical wallet credit. Part56 adds a distinct, usable post-credit observation/review path: a public raw-body Stripe-signature endpoint, independent encrypted durable follow-up custody, original-capture-bound operator cases, readable redacted event summaries, encrypted review-note history and exact-version acknowledgement actions in both deposit list and detail screens.

This is not a refund, reversal, payment-finality declaration, risk clearance, account freeze or withdrawal gate. It does not call Stripe, change any wallet/Payment/deposit/journal status, release a reservation or fabricate an Applied payment webhook. Acknowledged means a named authorized staff member recorded a note about a specific evidence version; it never means the provider dispute/refund is financially resolved.

## Dedicated transport boundary

The route is `POST /api/v1/payments/stripe-followups`, named `api.v1.payments.stripe-followups`. It is public, throttled by the existing webhook limiter and protected inside the controller/service by raw-body HMAC verification. It does not reuse the generic payment webhook financial-application controller or its observation middleware.

Both inbound storage and staff review default OFF through `STRIPE_DEPOSIT_REVIEW_ENABLED`. The endpoint requires the separate `STRIPE_DEPOSIT_FOLLOWUP_WEBHOOK_SECRET`, provisioned from the approved SAME Stripe account/endpoint as the original checkout account. The code never invents that secret or registers a remote endpoint. Review does not require the Part55 new-credit switch to remain ON; original Stripe account/configuration/custody must still validate. Existing gateway secret/application URL/account pins remain authoritative. Automatic credential/account migration is not implemented.

Stripe documents that verification uses the unchanged raw request body, `Stripe-Signature` and the endpoint-specific signing secret. Retries receive a new signature/timestamp; the event's own creation time need not equal delivery time. [1](https://docs.stripe.com/webhooks) [3](https://docs.stripe.com/webhooks/signature)

The implementation computes HMAC-SHA256 over `timestamp.raw-body`, compares each bounded v1 candidate with `hash_equals`, and requires a unique positive integer timestamp within300 seconds in either direction. Header length is at most8192 bytes with at most16 v1 candidates. Non-v1 schemes do not authorize a delivery. The body and encrypted receipt plaintext are each capped at1MiB; JSON depth is64. These are after-read checks, not streaming web-server memory caps. Configure ingress/proxy body limits separately and maintain a synchronized server clock.

The supported snapshot event allowlist is:

- `charge.refunded`, `charge.refund.updated`;
- `refund.created`, `refund.updated`, `refund.failed`;
- `charge.dispute.created`, `charge.dispute.updated`, `charge.dispute.closed`, `charge.dispute.funds_withdrawn`, `charge.dispute.funds_reinstated`.

Stripe's event catalogue identifies refund/dispute event objects and notes that `charge.refunded` also occurs for partial refunds. This application conservatively retains all supported kinds as review signals, including failed refunds or closed/won disputes; it does not adjudicate their net monetary outcome. [5](https://docs.stripe.com/api/events/types)

The event must be an `event` snapshot with a bounded `evt_` identifier, supported type, integer positive created time not more than300 seconds into the future, strict test/live mode matching the configured Stripe API key, and an array `data.object`. Connect `account` envelopes are unsupported and refused. Thin-event expansion, multiple merchant selection and Connect workflows are separate work. A platform event plus a server label is not an independent remote `/v1/account` certification.

After signature/type/mode checks, one root SQL transaction binds the event ID to the current server account scope and original Stripe configuration fingerprint, encrypts the exact raw body plus receipt metadata, and commits custody. The unique event key arbitrates concurrent duplicates. An exact raw-body replay returns the original receipt without replacing its ciphertext. The same key/event ID with changed raw bytes returns a conflict, even for semantically equivalent JSON whitespace/key-order changes. Secret rotation signatures can supply several v1 values, but retaining/configuring approved secrets remains an operator duty.

HTTP200 means `recorded=true, financial_application=false`, not that money moved or the case was examined. Signature refusal is403, conflicting identity409, disabled503 and other structured event/custody refusal422. Unexpected infrastructure failure remains a failure, not an invented200. No raw event, note, secret or SQL exception is deliberately returned by this controller.

## Original-bound cases and complete bounded history

Only a confirmed Stripe deposit with a validated applied Part55 capture receipt and canonical original net credit can acquire a case. No historical ownerless deposit is adopted. The service obtains original wallet/player/deposit/Payment/checkout locks through the existing settlement context, rechecks active staff, validates original checkout response custody/configuration/terms/account, validates applied capture ownership/ciphertext and re-proves canonical accounting.

It then locks the one case header for that deposit/Payment and validates every prior observation and acknowledgement. The complete account-and-configuration-bound inbox is rescanned, not advanced by an ID high-water cursor: a lower-ID receipt published later must still be seen. At most5000 inbox rows and100 linked observations per case are supported. Exceeding either bound refuses the entire operation; it never certifies a partial scan. The global per-account bound includes unrelated signed events. Archive/partition/large-volume migration is deliberately not silently implemented.

Only stored signed inbox sources naming the original Session/PaymentIntent/Charge chain can become observations. Explicit contradictory linked charge/intent/reference, object ID, currency or mode fields refuse the operation; missing or unrelated references do not enroll a deposit. Stored receipt decryption/hash/embedded identity and original scope/configuration must agree. The generic `payment_webhooks` Applied history is NOT imported or relabeled into this lane. Existing Part55 latest-Applied report semantics are unchanged; the new review case instead preserves all linked supported events received by this dedicated endpoint.

Each observation is append-only encrypted custody with the case UUID, original deposit/Payment/applied-receipt binding, actor, batch version and exact signed source hash. The header records an aggregate evidence hash over the original identity and all ordered observation IDs/hashes. Count, bounded contiguous batch versions, ciphertext/plaintext hashes, actor/source bindings and historical acknowledgement hashes are revalidated before further mutation. Changed/deleted/reclassified required sources or missing prior custody refuse rather than silently manufacturing replacements. There is no universal SQL-admin-plus-key-compromise tamper guarantee.

A new nonempty batch increments the case version and makes its state `open`. Replays preserve version and immutable bytes. A later failed refund, won/closed dispute or funds-reinstated event does not erase earlier evidence or acknowledge itself. Out-of-order evidence is retained, but this is not arrival-order-independent financial adjudication.

## Staff acknowledgement and presentation

A separate duty, `review stripe deposit followups`, requires a freshly read active admin/super-admin AND that explicit permission. Normal admins are not automatically granted it; the existing super-admin catalogue policy is unchanged. Staff is checked before entry, after original lock acquisition and after the history refresh; details recheck again before returning notes. This does not certify raw SQL permission writers, global cache revocation synchronization, MFA or universal actor/writer closure.

An acknowledgement must submit the exact case UUID, version and aggregate evidence hash plus a trimmed16–2000 character note. Under the same original/case locks the service re-proves history. If a newer batch is found, its observations/new open version COMMIT, but a stale acknowledgement is refused outside that transaction. Thus a stale screen cannot roll back newly found evidence merely by receiving an exception. No acknowledgement is written for an incorrect token.

The first acknowledgement for a version encrypts the note together with staff/version/hash identity. It is immutable. Only the exact same actor and exact same trimmed note can receive idempotent replay; a different actor or changed note conflicts. Historical acknowledgements remain bound to their original evidence subsets when later versions open.

The deposit actions show event ID/type, raw integer minor-unit amount where present, currency/status hints and authorized prior notes. Refund/dispute amounts are NOT labeled as original paid major units or wallet loss. Raw provider fields/private data are not rendered. Text is HTML-escaped before display; markup in a review note remains inert. Form defaults/current header reads are UI hints only; backend mutation revalidation is authoritative.

Inspection is explicit staff work, not an automatic sweeper. Inbound durable custody does NOT immediately reopen a header; the next authorized inspection/acknowledgement refresh observes it. An acknowledgement describes the SQL history snapshot inspected in that transaction, not a wall-clock fence against concurrent/later inbox commits or unknown external events. There is no automatic withdrawal prohibition. An empty local inbox is not proof of no refund/dispute at Stripe. Operators must monitor the inbox/provider dashboard and follow independent incident/refund/dispute procedures.

## Verification and scope

The supplied acceptance evidence records the exact final frozen-source default discovery/regression, selected native tests, intentional isolated omitted-guard failures and auxiliary contracts. Default tests run in separate disposable SQLite processes per source file; selected native tests use the owned local Unix-socket-only InnoDB database. Profiles overlap; explicit skips are not passes.

Three native two-child tests observe simultaneous real wallet-lock waiters: duplicate inspection writes one case/observation; identical acknowledgements write one note and one exact replay; two stale acknowledgements commit one new open version with no note and return named domain refusals, not accepted SQL errors. They do not certify simultaneous raw-ingress duplicates, cross-account races, universal writer ordering or power-loss recovery. The lower-ID publication and rollback/permission faults are controlled fixtures/injected exceptions, not OS-kill tests. Prior Part55 capture tests are included in the selected native profile.

No live merchant event/Stripe request, refund, chargeback action, bank transfer, production migration, remote CI or worldwide legal approval occurred. The included CI gate is source configuration, not proof it ran remotely. The full code delivery is a cumulative overlay on the retained base, excluding locked dependencies, generated assets and databases; restore retained preview duplicates when needed, reinstall dependencies and rebuild assets before execution.
