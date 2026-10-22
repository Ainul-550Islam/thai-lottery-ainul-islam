# Part60 — source-backed Stripe refund exposure and new paid wagers

## Application change

A canonically published authenticated succeeded full-refund recovery intent now constrains the original player's **canonical new paid wagers in the original currency**, across the player's selected wallets. An unpaid local refund recovery is not additional wagering authority. This is a mandatory admission rule for this known source, not a configurable risk preview.

This builds on Part59's original captured and canonically credited zero-fee Stripe deposit, retained signed follow-up source, four authenticated provider GET proofs outside SQL, immutable encrypted intent/receipt custody, independently authorized actual original-wallet recovery, and exact one-debit double-entry closure. It neither sends a provider refund nor invents a new debt from an unauthenticated or merely unproved event.

## Money and policy boundaries

- A new ordinary single purchase, prevalidated-context purchase, bulk/permutation item and amendment replacement use the canonical purchase writer. Preview flags and a different wallet of the same currency do not skip current source checks.
- Already-funded exact purchase replay is checked before new-wager admission. It is not a second wager or debit.
- Ordinary owned cancellation/refund remains available. Cancellation can restore the original wallet's funds; only the independently authorized financial recovery writer can then debit the actual refundable amount and produce the closure that clears this exposure.
- Other players and other currencies are not frozen by this source.
- A feature switch, elapsed receipt time, revoked/disabled staff, an acknowledgement, a projected completion label or unrelated funds do not clear pending exposure. The wager guard never invokes staff authorization or provider HTTP.
- A signed refund inbox event alone, before authenticated successful provider proof has produced local recovery custody, does not manufacture this specific liability. Absence of a known intent is not a proof that the provider has no unknown refunds.

## Two-phase order: pending source first, full financial closure after risk

The canonical purchase owns its SQL transaction and locks its selected wallet, checks committed idempotency, then performs current ownership/account and shared-player admission.

1. `BetPurchaseAdmission` calls `StripeRefundWagerGuard::assertNoPendingExposure` after selected-wallet/context ownership and current active-player validation. This early check uses the same reentrant player mutex as source publication/recovery, and reads current locked source history before responsible-gaming checks and risk reservation. A proved pending source refuses immediately. A completed row is only provisional at this stage, not financial clearance.
2. The writer checks affordability and reserves risk under the existing draw/number locks.
3. `BetPurchaseTransactionService` calls the guard's full `assertAllowed` before creating any new bet, ticket, financial debit or journal. The complete proof now takes current financial/ledger/chart locks **after risk**, matching canonical debit posting's risk-before-chart order. This avoids introducing a chart-before-risk inversion. A missing or forged closure cannot turn the early provisional decision into a money write; reservation changes roll back with the owned root.

The guard does not acquire any other original wallet after holding the selected wallet/player. Original refund recovery takes its own wallet, then the same player, before deposit/source rows. The user mutex serializes canonical publication and same-player admissions across distinct selected wallets. General contention still uses existing whole-transaction retry behavior; this is not a claim that arbitrary external writers are deadlock-free.

An implementation review caught the initial chart-before-risk ordering before final acceptance. The incomplete diagnostic runs were stopped and are not counted. Final acceptance is a fresh run against the refined frozen source; a query-order regression proves that completed-recovery chart proof follows risk reservation.

## Exact source and completed-recovery evidence

Discovery joins original deposits for the requested owner/currency and selects only the encrypted intent header, using a current locking read, not an old repeatable-read snapshot. It reads the complete ordered history up to **100 intents plus one saturation sentinel**, including completed intents. A count above 100 refuses with an unproved-exposure outcome instead of scanning a partial history or ignoring older completions. Each intent independently retains at most Part59's 100 chained provider receipts.

For every discovered source, authenticated ciphertext/hash/header, exact owner/currency/deposit linkage, signed original refund source, all retained receipt links and the fixed authenticated succeeded full-refund proof are validated. A source with no verified financial closure is pending. A completed source must additionally satisfy the exact encrypted original/recovery binding, retained transaction and ledger snapshot, original-wallet and user dimensions, completed/unreversed adjustment transaction, original idempotency and polymorphic owner, zero fee, exact amount, and actual before-minus-after amount.

The current journal must have exactly two posted, nondeleted entries: debit original-currency player liability and credit original-currency system cash, with exact amount and current nondeleted chart code/currency. Both journal legs bind the original wallet: that is the canonical writer's actual null-coalescing wallet-dimension behavior. The liability entry's after-balance equals the retained financial closure. No journal, wallet or historical source is repaired or adopted by admission.

Financial recovery replay now uses this same additional current accounting/chart proof. No new schema or migration is introduced in Part60. An independent full-history empty-store rehearsal did uncover two existing SQLite rollback bugs: the webhook lease index and four KYC verification indexes were still present when their columns were dropped. Their historical `down()` implementations now explicitly drop those indexes first; both `up()` definitions remain byte-identical. Real SQLite and MariaDB regressions preserve original webhook/document fields and recreate the exact indexes/defaults without financial writes. The final complete empty-history up/down/up rehearsal passes, rather than hiding either initial diagnostic failure. This is not permission to downgrade populated production tables or discard active lease/KYC state.

## Member API and amendment integration

Internal refusals are `bet_purchase_refund_exposure_pending` and `bet_purchase_refund_exposure_unproven`. The public purchase API maps both to HTTP422 `refund_recovery_required`, a controlled review message and only the caller's currency. It does not emit encrypted custody, raw provider identifiers, source IDs, hashes or backend errors. The existing API refusal audit may persist; no new wager or financial debit persists.

Amendment is the existing durable, staged original cancellation/refund followed by a separately owned replacement purchase. Its already committed legitimate refund is not rolled back or relabelled as a new purchase. A definitive **proved pending** exposure refusal now marks that replacement failed with the precise domain reason; the old bet remains refunded, no replacement bet is created, and this alone does not recover the provider refund. Corrupt/unproved custody is intentionally not reclassified as a definitive financial decline: it retains original-specific unresolved review rather than guessing an outcome. There is no new resend or historical-adoption path.

## Verification model

Focused tests exercise real committed purchase/debit/posting roots and real provider-shaped synthetic proof fixtures, including cross-wallet admission, stale contexts, disabled preview/recovery/review and revoked actors, unchanged historical replay, cancellation followed by actual recovery and legitimate new funding, owner/currency isolation, corrupt custody and completed accounting/chart, missing receipts, saturation, bulk refusal, amendment outcome, safe authenticated member HTTP422 and risk-before-chart ordering.

Three fresh two-child MariaDB profiles exercise: distinct-wallet paid purchases both refusing pending exposure; two old empty repeatable-read snapshots observing a newly committed earlier-ID authenticated source after a genuine player-lock wait; and distinct-wallet paid purchases both proceeding after exact financial recovery. The middle profile deliberately invokes actual admission inside a controlled transaction, not a public paid purchase inside an ambient transaction that the production writer rightly refuses. The other two profiles execute the full public paid-purchase root.

The server helper requires two independently connected children genuinely waiting on the parent's existing user row and records distinct selected wallet IDs. The snapshot test republishes the fixture's exact previously authenticated immutable intent/receipt IDs under the held user mutex; it does not claim to call the provider while a transaction is held or certify arbitrary out-of-band publication. Mutation controls verify that removed money-stage proof or current discovery locking makes selected tests fail, with child shadow autoload explicitly checked.

See `evidence/acceptance-part-60.json` and `evidence/runtime-part-60.txt` for actual final counts and limitations. CI is updated with the focused suites and genuine lock-witness assertions; no remote CI execution is inferred from local workflow changes.

## Operations and capacity

Investigate the original source and use the existing independently authorized recovery writer with its original receipt chain. Do not delete intents, mark recovery done, refresh timestamps, disable features, grant broad staff roles, or credit an unrelated wallet to clear the wager fence. Do not run a provider refund twice: this feature performs no provider refund POST.

The 100-intent bound is a lifetime per-player/currency operational capacity, including proved completed histories. The application intentionally refuses when saturated. A future sealed archival/summary design would require new immutable proof, migration and concurrency acceptance before lifting that bound; operators must not silently purge evidence.

This is a narrow known-source **new paid-wager** safeguard, not a global withdrawal, wallet transfer, conversion, settlement or worldwide risk clearance. Already-paid external obligations are not blocked by a new generic debit hook. Signed inbox routing, staff review and other money roots retain their existing distinct authority. Production credentials, country/legal/licensing policy, merchant onboarding and provider-live certification remain deployment work, not fabricated success.
