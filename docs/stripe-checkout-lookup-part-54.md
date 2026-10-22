# Positive-only Stripe checkout reconciliation — Part54

## Application behavior

`DepositCheckoutService::reconcileStripe` is a real read-only provider lookup orchestration, not a new checkout or a fabricated acknowledgement. The existing admin deposit resource now has a separately permissioned confirmation action. It uses the actual authenticated panel operator; no public customer lookup/resend route or console impersonation command was added.

The server feature flag `STRIPE_CHECKOUT_LOOKUP_ENABLED` defaults to false. It lives outside the original gateway configuration block so enabling lookup does not itself alter the Part53 provider fingerprint. The new duty is `reconcile deposit checkouts`. Active admin/super-admin role AND explicit permission are required through a freshly loaded primary User. The normal admin role is not automatically granted this duty by the seeder. The super-admin catalogue includes it, but the service checks permission directly rather than relying on a permissive Gate shortcut. Existing account and panel authentication remain necessary.

Permission is checked before original preparation, under the current family snapshot and again before successful lookup evidence is persisted. Revocation through supported permission writers during the GET prevents receipt publication. This does not certify arbitrary SQL role edits, all-writer authorization, MFA or global role-writer lock coordination.

## Provider requests and limits

Stripe documents list/retrieve Checkout Session GET endpoints and paginated list responses; this implementation uses those endpoints, not a nonexistent metadata search endpoint. [1](https://docs.stripe.com/api/checkout/sessions) [2](https://docs.stripe.com/api/checkout/sessions/list)

- Fixed `https://api.stripe.com/v1/checkout/sessions` endpoint and fixed-host retrieve endpoint; no caller-supplied URL or account selector.
- List filter: original dispatch timestamp minus five minutes through plus one hour. This window is a lookup bound, NOT a claim that a missing session was never accepted.
- At most three pages of 100 sessions and one retrieve GET, with 5-second connect/15-second request limits, no automatic HTTP retry and redirects disabled.
- Strict list shape, boolean pagination marker, cursor progression and unique IDs. Repeated IDs, malformed pages, incomplete/truncated scans or multiple tagged matches remain held.
- Only an exact original attempt UUID candidate is considered. Attempt, deposit ID/reference, wallet, user, client reference, payment mode, exact integer gross amount in cents, currency, creation window and test/live mode must agree. The independently retrieved session must pass the same checks and match the selected ID.
- Only unexpanded account-authenticated session data is read. An open-session redirect must use HTTPS on `checkout.stripe.com`, with no credentials/port/control characters and an unexpired integer expiration. Unconfigured custom checkout domains fail closed.
- Complete/expired session responses can prove discovery of a checkout but return no redirect. Their labels do NOT prove settlement, credit, refund, cancellation or safe reservation release. Stripe describes these session states separately from the application's accounting authority. [3](https://docs.stripe.com/api/checkout/sessions/object?api-version=2024-12-18.acacia)

A zero-result scan, provider error, timeout, malformed response, missing metadata, authentication failure or pagination bound never means nonacceptance. No session create/expire POST, PaymentIntent mutation, transfer, refund or original dispatch retry exists in this path. The original automatic invocation count remains one. Old sessions outside the bounded window, deleted/edited metadata, eventual consistency, high account volume, unavailable provider history and custom domains can remain inconclusive.

## Durable custody and replay

Only newly owned Part53 Stripe attempts with a committed dispatch fence are eligible. Prepared/unowned/historical rows cannot be enrolled by lookup. The original terms, account scope, provider configuration HMAC and request hash are checked before the network and again under ordered family locks after it. Current player admission is not turned into a new money grant: a lookup recovers historical checkout evidence only.

No SQL transaction or wallet lock is held during the GET. After a positive match, the current actor and original fence/bindings are revalidated. The complete matched response is bounded, encrypted and hashed. An original-bound `deposit_checkout_lookup_receipts` row and attempt response custody/receipt UUID marker commit atomically before projection. SQL deadlock retries are limited to this SQL-only persistence closure; they cannot repeat GET or POST calls.

The receipt binds actor, attempt, original terms/request/configuration/fence/account scope, dispatch-key hash, provider reference and encrypted response. Later ordinary SQL-only recovery validates the receipt marker, exact ciphertext/hash and original reference; deleting or corrupting the required receipt cannot silently fall back to a normal creator response. A trusted arbitrary database/key administrator remains outside this application integrity boundary.

If the original response writer or another authorized lookup publishes custody first, matching existing custody wins without being overwritten. Conflicting references refuse. Late projection failure rolls back projection while preserving committed receipt/custody; the same customer request or a staff SQL recovery can subsequently project it without contacting Stripe again. Revoking the original operator after valid custody does not erase already-authorized evidence.

The existing `recover` path preserves independently captured/credited status and callback evidence. Lookup itself leaves Pending/Approved financial states, wallet balance and journal untouched. A complete or expired provider session is not promoted to Captured/Confirmed. Provider-confirmed failure release, payment settlement, refunds and dispute reconciliation are still separate protocols.

## Storage and activation

The additive migration adds an empty lookup receipt table and nullable marker; no old attempts or payments are backfilled. Parent foreign keys restrict deletion. Apply/rehearse it only after backup and nonproduction verification. No production migration was run here.

Independent custody and receipt columns are encrypted with the existing Laravel key. Existing checkout projection columns retain their earlier contract and are not all encrypted. Preserve keys/backups, restrict metadata access and plan outstanding-account/credential changes: Part53's original configuration fingerprint deliberately blocks drift. The feature flag is an execution gate, not merchant approval or evidence that a processor permits lottery transactions.

## Local acceptance

Tests use synthetic HTTP responses and dedicated disposable SQL stores, not real merchant calls. They cover wrong original/money/mode/redirect facts, absent/duplicate/incomplete results, retrieve mismatch, pagination, stale actor/revocation, account drift, lost receipt, independent late-failure custody, complete/expired no-credit behavior, the actual admin action and two genuinely blocked native child lookups with only one published receipt. The measured barrier is the common wallet lock at the initial original snapshot; it does not force both GETs to finish before custody, or certify an original creator HTTP response versus lookup-reply interleave. Full final source checks, exact overlap/skip accounting and isolated omission controls are recorded in the Part54 acceptance evidence. Remote CI wiring is supplied, not a claim that remote CI ran.

The final default regression isolates every source test file in its own PHP process and disposable SQLite database, with eight workers and four disjoint aggregate reports. The initial shared-shard run exposed committed GLO fixture contamination of a later empty-result home test; that failing matrix is diagnostic evidence, not a pass. No production logic or test assertion was changed or filtered to resolve the harness isolation. The selected native profile remains a separate dedicated InnoDB execution.
