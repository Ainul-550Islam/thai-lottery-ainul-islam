# Durable deposit checkout — Part53

This is application implementation, not a merchant activation or a live transfer. The canonical `PaymentInitiationService` now delegates to `DepositCheckoutService`; the former remote-inside-SQL orchestration is removed. Existing withdrawal and independently verified funding paths are not replaced by checkout responses.

## Request contract

A stable nonempty caller request key is mandatory before canonical checkout initiation. API requests require a 16–128-character key using the existing safe alphabet. The body `idempotency_key`, standard `Idempotency-Key` header and legacy `X-Idempotency-Key` header are supported. If more than one is supplied, their exact values must agree; conflicting values fail validation. Persist the original key before the first request. A timeout is not permission to generate another key.

Capability and currency refusal precede creation. Both Laravel transaction depth and physical PDO transaction state must be zero at the durable root. A nested caller cannot obtain a provider side effect that its own later SQL rollback would erase. There is no testing, SQLite or production bypass. Successful checkout fixtures that previously used caller transactions now use the established committed, dedicated-test-store fixture base.

Canonical options are recursively key-sorted, scalar/null/array or Money facts, with bounded nesting and serialized size. Floats, objects other than Money and resources refuse. IP and user agent are observational and excluded from replay identity. Dispatch identity, attempt identity and account scope are server-owned; caller overrides refuse.

## Durable state machine

| State | Durable fact | Permitted automatic action |
|---|---|---|
| `prepared` | New deposit, one pending Payment and original-bound attempt committed; count zero | A first claim, only after current admission |
| `dispatching` | Unique fenced claim and count one committed before adapter entry | Never another dispatch, including after process interruption |
| `unknown` | No authoritative successful response is in durable custody | No resend or timeout-based release; controlled reconciliation required |
| `response_received` | Successful original checkout response independently encrypted and committed | SQL-only original response projection |
| `projected` | Original response projected into local checkout fields | Read-only original response replay |

There is no lease expiry, count reset, new key allocation, automatic provider retry, historical attempt backfill, status-polling fiction or synthetic payment proof. The guarantee is **at most one automatic adapter invocation per original committed intent**, not exactly-once execution by an arbitrary remote provider. Existing adapters may perform more than one HTTP operation internally; a driver invocation is not a claim that every provider supports idempotent APIs.

A crash after claim but before network entry can leave `dispatching` with zero actual calls. This intentionally remains uncertain rather than risking a second call. A successful network reply can still be lost before encrypted custody commits; this also remains uncertain and is not recoverable from local data alone. The system does not fabricate an acknowledgement in either case.

## Original facts and locking

The new table has unique deposit, payment, attempt UUID and server dispatch-key ownership; parent foreign keys restrict deletion. The Payment carries a writer-owned attempt marker. Original checks bind source terms, Payment identity, method/currency/amount/fee/user, provider, nonsecret account scope, canonical request hash, dispatch count/fence and provider-configuration HMAC. The HMAC includes the configured provider block, payment routes and application URL; plaintext configuration or merchant secrets are not stored in that field. A configuration change may conservatively hold recovery even if operationally harmless.

Preparation uses the original deposit root and its wallet/player admission locks. Claim and recovery lock wallet, player, current deposit, all matching Payments and attempt in that order. Ambiguous Payment membership refuses. Before committing first dispatch permission, claim also resolves and validates local response-encryption capability; a missing local key leaves count zero and permits no provider call. This is not a promise against a later disk/database/key failure. Claim rechecks current player/wallet protections and selected-currency gross deposit limits, excluding its own existing reservation from double counting. It passes the locked original deposit snapshot to the adapter, not an unlocked post-claim re-read that could change the dispatched amount.

Original-term user-existence reads use the primary connection and current locking reads inside SQL transactions, rather than opening an old repeatable-read snapshot ahead of an independently writable attempt. A controlled native two-connection interleave verifies that an original replay survives a committed attempt-only update. Response custody locks only its own fenced attempt and never subsequently obtains parent locks. Thus it can commit independently without reversing the parent-first order. The provider call owns no application SQL transaction or wallet lock. Configuration/admission validation at claim is a local authorization linearization point, not a distributed atomic transaction with a bank; policy may change after claim. Actual first credit independently rechecks the funding root's current admission.

Unresolved `dispatching`, `unknown` and `response_received` attempts cannot be changed through the canonical lifecycle transition service into Cancelled, Rejected or Failed merely by a local cancel, review or timeout. This prevents those supported transitions from silently freeing their gross reservation. Confirmed funding still requires its separate canonical proof. A failure callback that encounters this conservative unresolved gate must remain for controlled handling; Part53 does not invent an authoritative-release capability. Arbitrary direct SQL/model writers and universal actor authorization are not certified by this gate.

## Response custody and recovery

Only a successful response with a nonempty bounded original provider reference enters custody. Failed DTOs, thrown exceptions and malformed acknowledgements are uncertain, since legacy drivers may swallow transport exceptions. The bounded complete response and raw object are encrypted with Laravel Crypt, hashed and committed before projection. An error while marking unknown cannot rearm the already-committed dispatch fence or erase received custody.

`recover(Deposit)` is an internal SQL-only operation; it never invokes the gateway. It requires a top-level caller, current original source/account/configuration facts, valid encrypted custody and consistent references. Projection failure rolls back projection while preserving the separately committed response. A same-key initiation can recover an already-received response without network contact. Projected replay does not rewrite or re-encrypt the attempt.

Checkout projection does not set Captured, Confirmed, a balance or a ledger entry. It preserves independently captured/credited state and does not overwrite an existing callback gateway-response object. It may publish the existing checkout-facing redirect/client-secret fields and original response snapshot into legacy Payment/Deposit metadata as required by those existing contracts. **Encrypted custody is not a claim that every legacy projection field or raw gateway-response column is encrypted.** Production access, redaction, retention and key management remain release requirements. The public status projection never returns ciphertext, fence, dispatch key, configuration hash, raw response or provider secrets.

Application-key rotation, lost keys, configuration drift, conflicting references or corrupt custody fail closed; they require an authorized migration/reconciliation plan, not a blind resend. Restoring a configuration in a synthetic test demonstrates binding, not a production credential-rotation procedure.

## Public read surface and uncertain responses

The existing authenticated owner-filtered deposit show endpoint includes coarse checkout state, count, custody/recovery indicators and `automatic_resend_allowed: false`. These indicators do not themselves decrypt/prove custody or authorize money. The internal recovery operation performs the actual proof checks. No public resend or reconciliation endpoint was added.

An uncertain API initiation returns HTTP409 with `checkout_outcome_unknown`, the same player's deposit UUID when available and safe checkout status. The client keeps its original key, displays a pending/controlled-review state and reads that owned deposit. It must not interpret a browser timeout, age, local review or new page load as authorization to send again. A missing API request key returns validation failure before provider contact.

## Historical compatibility

Part52 rows with a proved original response remain read-only historical replay; they are not enrolled into the new attempt table. Contradictory or unproven historical checkout paper refuses. A newly owned Payment whose attempt is missing cannot use that historical fallback. Existing direct `DepositService::request` callers can still create local intents without this remote-root key contract; they are not automatically certified remote dispatchers.

## Verification scope

The acceptance JSON and runtime evidence record final-source default/native profiles, exact skip accounting, source hashes, independent omission controls and dependency/build checks. New tests cover committed claim visibility, no ambient transaction, standard/header conflicts, unknown acknowledgement/no automatic resend after time, separate encrypted custody after late SQL failure, original options/configuration binding, private status, capture-before-projection preservation and canonical uncertain-reservation release refusal. Post-commit interruption tests inject controlled exceptions; they are not an OS-kill or power-loss certification.

Three new native races use two real child processes blocked simultaneously on the decisive InnoDB wallet row. A separate local-only synthetic adapter records external acceptance outside any SQL transaction; each original produces exactly one such recorded invocation. These are not real Stripe/bank transactions. Three pre-existing purchase concurrency assertions are now explicitly routed away from SQLite, which cannot prove row-lock replay; their original assertions run in the native profile without alteration. Remote CI wiring is supplied but a remote CI execution is not claimed.
