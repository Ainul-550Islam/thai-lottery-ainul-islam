# Current deposit admission, gross reservations and original checkout replay — Part52

Date: 2026-10-17. Full continuation Files984–1006; next File1007. Cumulative 678-path source overlay on retained base commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. This is a real application write-path correction, not live provider acceptance or worldwide operating permission. No migration or historical backfill is added.

## The authority boundary

A pending intent, an old approval, a verified successful callback and an old cached wallet/user are not permission for a NEW deposit credit. Fresh requests and actual first credit now run current admission inside their own SQL transaction: current wallet, current player mutex, current eligible account, current exclusion/cool-off and selected-currency active deposit ceilings. The admission applies to the gross source obligation, while the existing canonical accounting still credits the original net amount after its original fee exactly once.

`DepositAdmission` coordinates the supported request, completion and callback/binding callers through the Part50/51 player-row mutex. This serializes one player's current budget across distinct wallets, not merely two operations sharing a wallet. Nonactive or unavailable accounts refuse new request/credit. Current legacy exclusion/cool-off rows include hidden rows; current versioned self-exclusion and active versioned numeric ceilings remain binding. Pending increases supply no extra headroom. Neither this new helper nor a browser/DTO boolean can certify actor/maker-checker/KYC or every writer in the system.

## Fresh requests and historical request replay

`DepositService::request` no longer performs an unlocked default-THB policy check before its actual transaction. It locks the current wallet, rejects stale wallet-owner disagreement, locks the player and proves any exact existing request before considering fresh admission. Current currency comes from the locked wallet. An existing request with matching original terms/provider-account scope is historical replay, not a new reservation; its own amount is not projected twice. A genuinely new request passes current admission immediately before writing its pending row. It credits nothing. Its transaction/savepoint also protects a caller that catches a failure and commits unrelated work.

Global request keys still bind exact wallet, gross, fee and method under the existing contract. Changed terms do not become a new request or a hidden repair. A unique-key winner is re-proved rather than blindly returned. This is not universal durable bulk intent or provider unknown-outcome recovery.

## Current gross budget evidence

For each daily/seven-day/thirty-day deposit ceiling, the current locked source query covers all the player's wallets in the selected currency and includes soft-deleted source rows. It selects exact decimal strings and adds them with bcmath; it does not perform a PHP float SUM or aggregate distinct currencies.

- Pending, Approved and Processing obligations reserve gross amount inside their request-created rolling window.
- Confirmed and Refunded gross deposits count inside their original confirmation rolling window. Refunding a deposit does not silently replenish a gross deposit allowance.
- Historical confirmed/refunded rows without `confirmed_at` fall back conservatively to their existing request timestamp; this does not invent original settlement/ownership facts.
- Failed, Cancelled and Rejected uncredited requests do not reserve new headroom.
- An actual first credit excludes ONLY its own proven current deposit ID from existing evidence, then projects its full gross amount now. Thus an old pending/approved request cannot disappear from today's budget merely because it was created weeks ago, and its current reservation is not counted twice.
- Malformed/nonpositive selected gross evidence fails closed. Trusted arbitrary SQL, deletion, changing source status or forged accounting is outside this source writer proof; those acts need separate control and reconciliation.

The evidence is source-obligation budgeting, not proof that an arbitrary legacy confirmed label has balanced accounting or that an old unbound numeric summary has a known currency. No automatic legacy numeric currency enrollment is performed.

## First credit versus original funding replay

`DepositCompletionService` still owns the only canonical deposit credit, original reference/account scope, deterministic financial key, balanced net transaction and original-term proof. Before any first credit/reference bind, it now rechecks current admission, even for an old approval. A later type/ledger failure rolls back reference, credit, ledger and status together through the existing completion transaction/savepoint.

Confirmed replay first proves the original transaction, ledger, terms and reference ownership and returns without crediting again. Current account status is no longer incorrectly part of an immutable original-term fingerprint/proof. Current status instead gates actual NEW writes. Consequently a subsequent ban, exclusion or stricter deposit cap cannot turn a proven historical credit into a second credit or force replay to consume today's allowance. Existing wallet/currency/reference/provider and missing/contradictory-proof checks are not bypassed.

## Callbacks, receipt custody and held funds

Both normalized original callback application and legacy financial webhook binding take the player mutex before deposit rows, consistently with new request/completion roots. Supported order is wallet, current player, then required policy/deposit/payment/reference rows and ledger. Policy writers never acquire wallets. This is not certification of every reversal, legacy administrative, reconciliation, payout or provider writer's global lock order.

Expected current deposit/account/protection refusals roll back the callback's attempted approval, Payment capture, reference ownership and wallet/ledger changes. The hardened `processEnvelope` lane retains the already verified signed receipt as Verified with a stable safe reason rather than marking it Applied, marking the external payment Failed, or losing the receipt by rolling back the whole envelope. A duplicate sighting does not auto-credit a held receipt. An explicit authorized persisted retry re-verifies stored signature/identity, rechecks CURRENT permission, and credits once only when allowed; repeated applied retries are no-write.

This hold is not a bank refund, returned funds, a settlement asset, a suspense-account ledger or a legal decision to retain money. An external provider may already have received funds. That discrepancy requires controlled provider reconciliation/refund or another specifically authorized disposition. The legacy normalized caller is responsible for its upstream durable evidence/retry contract; it is not newly represented as the hardened persisted-envelope API. Uncaught infrastructure/connection/commit failures and provider unknown outcomes require their own durable recovery protocol.

## Checkout replay and external side effects

A new successful checkout records the original gateway response snapshot alongside its Payment aggregate. A later exact initiation replay returns that original response and current paper without making another provider call, even if the player is now banned. A replayed URL/secret is historical response data, not new permission to deposit; actual first credit still checks current permission. Provider capability/account configuration remains independently required.

An existing request/payment lacking original checkout snapshot, a missing/deleted/contradictory payment or a mismatched provider reference is refused without re-driving the provider. No history is auto-enrolled. New response metadata is server-written, not a claim of resistance to arbitrary trusted database mutation.

A fresh provider call still executes inside the existing orchestration transaction with no automatic SQL retry of that external call. A remote acceptance followed by local failure/unknown commit can still require provider reconciliation; this continuation does NOT implement durable pre-call dispatch intent, response custody across every failure, authoritative status polling, provider idempotency or safe automatic resend after unknown outcome. Those remain release gates, not fabricated success.

## Final-source acceptance and reproduction

The acceptance JSON records exact JUnit/discovery IDs and log hashes. Complete default and selected native matrices overlap; explicit skipped cases are not passed tests. New native cases include four observed two-child waits on the decisive player row, distinct-wallet request/credit/mixed budget races, lowering versus actual credit, three real old-repeatable-read snapshots and a 999-row exact gross sum where float accumulation could lose a cent. Ordinary snapshot reads are asserted to remain old; a driver conflict is not accepted as current permission proof.

Six isolated shadow-source omissions target account admission, first-credit admission, approved reservations, selected currency, checkout re-driving and exact arithmetic. The float control uses its own separate socket-only native test store. Each must fail its intended assertion with no infrastructure error; accepted application sources are never mutated. Focused authenticated-domain callback/receipt/checkout and original-deposit regressions, full source contracts, lint/style, locked dependency/security gates, actual Vite and Rust checks accompany the complete matrices.

Reproduce only on owned disposable databases with locked dependencies, PHP GD/bcmath/PDO and the reviewed Node/npm/Rust environment. The evidence harness is not an application endpoint. Generated dependencies/assets are removed after verified acceptance to preserve the workspace snapshot; install locks and rebuild assets before execution. Restore any explicitly documented retained-base design-preview duplicates from their exact Git blobs before a fresh full source census. No production deployment, customer money, browser/pentest or external merchant/country approval is included.
