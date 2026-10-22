# Part59 — source-owned confirmed Stripe full-refund recovery

## What the new financial action does

`StripeRefundRecoveryService::recover(originalDeposit, currentStaff, signedInboxId)` compensates a previously, canonically credited original Stripe deposit **after Stripe has already succeeded in refunding its entire amount**. This is an actual local wallet debit and double-entry journal, not an observation-only review. The amount, wallet, currency, account and provider references come exclusively from the original owned capture and signed source. No caller amount, destination, provider URL, or transaction ID is accepted.

The initial supported policy is deliberately narrow: zero original fee, gross equal to net, a single succeeded full refund, and original currencies supported by the existing captured-deposit lane (THB/USD/BDT, two-decimal minor units). Partial/multiple refund aggregation, nonzero-fee allocation, chargeback losses, new provider refund requests and debt/negative-balance collection are not implemented by this action. A partial or ambiguous case refuses rather than silently applying an invented fee/loss policy.

## Activation and independent authority

- `STRIPE_REFUND_RECOVERY_ENABLED=false` by default, in both secret-free environment templates.
- The dedicated Stripe follow-up review must be deliberately enabled with its correct endpoint-specific webhook secret. Original Stripe account/configuration and secret must still match their owned capture.
- The actor must currently be active staff with the existing `review stripe deposit followups` duty **and a direct-user assignment** of `recover confirmed stripe refunds` (web guard). A broad inherited admin or super-admin permission is insufficient. The seeder creates the permission but does not directly assign it to any user. Ordinary admin inheritance excludes it.
- Assign the direct permission to an independently approved existing staff identity through the deployment's audited permission administration. This delivery invents no staff identity, credentials or approval.
- Both Deposit list and detail action definitions use the same service boundary. The new red financial action is distinct from the unchanged inspection and acknowledgement actions. Its only input is a succeeded-refund signed inbox ID.
- The earlier scheduler/router/reviewer lanes receive no financial recovery authority. There is no automatic recovery sweep, provider POST, outbound transfer, or implicit retry on insufficient funds.

Disabling the financial flag or revoking current authority also prevents this entry point's proved replay. Existing evidence remains in SQL; hiding an action is never the authorization boundary.

## Root ordering, provider proof and custody

1. Refuse any caller framework **or raw PDO** transaction. Acquire existing wallet → player → original deposit/payment/checkout locks; re-prove current authority, original request/account/configuration, immutable original settlement receipt, canonical credit and its actual two-sided journal. Preserve original historical Confirmed/Captured labels. The existing bounded signed review coordinator verifies and records linked observations; it does not authorize money or clear a case.
2. Read the named signature-verified inbox under the original root. Only `refund.created`/`refund.updated` with a succeeded refund, exact full integer amount/currency, original charge/PaymentIntent and authenticated mode can trigger recovery. Re-prove the encrypted raw-body custody and current scope. Bad source, fee or original evidence refuses before any provider HTTP.
3. Release SQL completely. Make four authenticated **GET-only** requests to fixed `https://api.stripe.com/v1/` paths: refund, original charge, original PaymentIntent, and charge-scoped refund list (`limit=2`). No user URL, redirect, retry, capture, refund creation or transfer request is made. Each response must be a JSON object, at most 1 MiB and depth64. Connect timeout5s; request timeout15s.
4. Require the same sole full succeeded refund in the complete list (`has_more=false`, exactly one row). Charge must be paid/captured/succeeded, fully refunded by the exact amount, undisputed and linked to the exact original PaymentIntent; PaymentIntent must be succeeded with exact received amount and latest charge. Money is integer minor units, not floats/strings; currency/live mode must match. Connect/transfer/application-fee/review/failure evidence refuses. Refunds do not need an invented `livemode` field: signed event and current charge/intent prove mode.
5. Reacquire the same original root, re-prove source, original binding, current staff and provider proof; refuse a snapshot older than300s, measured from **before** the first GET, or a future observation epoch. Commit the immutable owner and append-only encrypted provider receipt independently of financial application. Account-scoped refund identity deliberately excludes configuration hash so a key/configuration change cannot reclaim the same refund.
6. Reacquire and re-prove again for financial application. Read and validate every bounded receipt, encrypted header/body, original/source binding and predecessor succession; stale receipt cannot debit. A receipt is not authority to bypass current source, original capture, configuration or staff checks. Header/cipher/hash disagreement refuses, never repairs history. Up to100 receipts per original; saturation refuses explicitly. The inherited review scan/case bounds remain authoritative.
7. The original wallet mutex, unique original-deposit/payment owner, unique account/refund identity, unique original compensation key, and unique recovery/receipt/transaction links arbitrate concurrent observations. There is no lease-based financial authority. HTTP observations can be duplicated; money is compensated once per original.

## Actual accounting and pending funds

Canonical compensation key: `stripe-full-refund:<original-deposit-id>`.

The dedicated managed financial transaction is a completed zero-fee Adjustment, explicitly referenced to `StripeRefundRecoveryIntent`, with immutable exact custody. It is **not** a generic Adjustment debit: the shared generic Adjustment counterpart would be equity, which is wrong for a returned captured deposit. This workflow uses `WalletService::applyDebit` for the locked wallet and the canonical `LedgerPostingService` for this exact posting:

| Line | Account | Side | Amount |
|---|---|---|---|
| Original wallet | currency-specific player liability | Debit | full original net = gross |
| Counterpart | currency-specific system cash | Credit | the same amount |

The wallet debit, completed transaction, both entries, account roll-forwards and encrypted recovery proof commit in one root. A ledger failure rolls all those financial changes back, while the independently committed provider receipt survives. No original credit, journal or capture is deleted or marked reversed. Original lifetime deposited totals remain historical funding, not a new net-refund report.

Available funds are balance minus locked balance. Shared withdrawal/financial-hold ownership floors, debit status and positive-amount checks remain active. Insufficient available funds **do not** unlock holds, collect a smaller amount, create debt or allow a negative wallet. The action returns the existing domain refusal; the authenticated owner/receipt remains pending, with no recovery row. After funds lawfully become available an explicitly authorized retry performs fresh provider GETs and appends a new receipt before trying the single full debit. The original player may have spent funds; this implementation does not claim that such uncollectible exposure has vanished.

## Replay and corruption refusal

A completed replay re-proves original credit/capture, current signed source and immutable owner/receipt/recovery chain, then compares the actual entire financial header and ordered journal rows with their encrypted recovery snapshot. It also checks the managed canonical key/reference, completed/nondeleted lifecycle and exact before/after wallet delta. It never requires the wallet's *current* balance to equal the historical result: unrelated later legitimate activity does not authorize a second debit.

Exact replay makes no provider request and no financial change. A newly signed alias event may append observational review evidence, but cannot produce a second recovery. A missing recovery link is not healed by adopting an existing financial transaction. Modified/deleted source or receipt custody and modified financial rows refuse, without rewriting the changed rows to manufacture a valid replay.

`ReversalIntegrityGuard` blocks this managed morph, its reserved canonical key prefix and the durable recovery-to-financial-transaction link. Removing both morph and key still cannot turn a linked recovery into a generic reversible adjustment. This is domain workflow protection, not an operator button to issue another provider refund.

## Local acceptance and limits

Exact final results and hashes are in `evidence/stripe-confirmed-refund-recovery-acceptance-part-59.json` and `evidence/runtime-part-59.txt`; the supplied evidence archive contains the raw reports and reproducible runners. The new feature suite has40 tests; two additional native profiles observe two genuine child connections blocked on the same parent wallet row. The first also requires both independent provider-response arrivals outside PDO/framework transactions before allowing either to proceed. Isolated omission controls modify copied source only, validate shadow autoload paths, and require intended assertion failures rather than setup errors.

Testing uses synthetic provider responses and disposable SQLite/MariaDB stores. An injected ledger exception and controlled clocks are not real provider failure, OS-kill/power-loss or production crash certification. Existing default/native matrices overlap; do not sum them as unique coverage. Local CI command checks are not remote CI execution. Empty migration up/down/up is not populated migration/restore proof.

Provider refund success and current charge accounting are not bank payout/settlement confirmation. Zero original fee means the captured application deposit fee is zero; this workflow neither proves that Stripe charged no processing fee nor reverses/allocates normal processor fees. The journal mirrors the original gross cash/liability funding; processor-fee settlement/loss reconciliation is separate. There has been no live Stripe refund, actual provider money transfer, merchant certification, deployed activation, production data migration, universal actor/writer audit or worldwide licensing/legal approval. Database/schema privileges and application encryption-key compromise remain outside this local custody proof. Automatic risk restrictions, case financial-resolution projections, disputed/partial/fee policy, refunds initiated by this application and uncollectible-debt operations require separately approved workflows; an acknowledged or locally recovered case does not clear risk or withdrawals.
