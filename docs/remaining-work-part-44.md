# Part44 — new source-owned internal commission payouts

Recorded: 2026-10-16T13:28:23.984652+00:00. Full Files804–828; next File829.

## Actual functionality

Both the existing draw and manual internal settlement services now delegate to one `AgentCommissionPayoutService`. It routes from ordinary hints only, locks relevant source/member and agent wallets in a sorted pass, locks current agent rows and currently revalidates the target commission set. Unknown or changed routing refuses instead of acquiring arbitrary late wallet locks. Counter arithmetic uses SQL text-cast current values, updates only the paid counter and never clamps deficits or overwrites earned totals from stale models.

A payout must prove a NEW Part43 immutable source set, every original member/agent/amount/rate/base/draw/currency/metadata binding, the original funded member debit/journal and its permanent purchase fingerprint. Unbound historical/synthetic commission rows are refused, even when cash or aggregate capacity exists. No new public adoption or backfill path exists. The immutable original turnover hash is checked without Part42's refund-only prohibition on winning-item lifecycle fields, so a winning sale can still earn its originally captured turnover commission. The winning-item test is a synthetic lifecycle mechanics test, not certification of a prize disbursement.

Before and after credit, the complete current active book protects OTHER owned and known unowned liabilities. Current earned must cover the current paid counter PLUS all remaining unpaid minima. A larger paid-counter surplus cannot quietly borrow someone else's pending earnings. Known unowned entries are protected, never thereby paid/enrolled. The original direct/parent rates remain the captured rates; no repricing from a later agent setting occurs.

Each new credit uses a single commission-specific canonical key regardless of manual/draw ingress. A permanent `agent_commission_payouts` fact binds exactly one commission, source claim, agent wallet, financial transaction, amount/currency, source terms, paid timestamp and original canonical journal fingerprint. Capture requires an actually new original transaction in the same transaction. Credit, receipt, commission Paid status, exact paid-counter increment and audit commit together or all roll back. A receipt is a fact that the ORIGINAL local credit occurred, NOT a statement that no later compensation occurred or an external bank paid anyone.

Paid replay currently verifies the source set, exact original header/metadata/key/reference, complete canonical journal/account identity, receipt fingerprint/stamp and absence of competing/reversal history. It returns zero newly paid rows without another credit, receipt, counter update or audit. A missing receipt/old paid row is not adopted. Mutable status labels or a matching balance are not proof.

Unpaid member cancellation now ALSO checks the durable payout receipt. Even if paid stamps/pointers and polymorphic transaction/ledger labels have been removed and aggregate surplus exists, the old original credit remains a blocker to an unpaired member refund. A dedicated mutation control demonstrates that dropping only that lookup would authorize the bad refund. The controller still returns the existing commission-workflow refusal; no new permission was added.

Suspension no longer silently converts an earned commission to Cancelled while leaving its source/earned counter active. It holds the original Accrued row and ownership unchanged; reactivation can pay that same source. The draw DTO reports `held_commissions` and does not falsely report a fully settled batch. A batch that contains currencies with incompatible totals refuses atomically. Per-agent settlement remains currency-specific. The mixed-currency test funds two real purchases on distinct number-limit buckets; it does NOT weaken the earlier currency-bound risk floor for a shared bucket.

The facade now calls actual `accrueForBet` / `settleForDraw` delegates and returns real commission lists rather than declaring nullable single models. Its standalone reversal delegate is still the distinct legacy writer, not a coordinated paid member refund. Original journal account reads are now current locking reads when inside a transaction, rather than a stale snapshot under an otherwise current original proof.

## Executed final acceptance

| Executed check | Result |
|---|---|
| Complete accepted default PHP / four SQLite shards plus83-case GD file rerun | 3355 tests / 134922 assertions / 0 errors or failures / 87 explicit skips |
| Discovery equality | Every discovered PHP test ID appears once in the final accepted result set; infrastructure retries remain recorded |
| Final owned MariaDB matrix | 223 tests / 1212 assertions / 0 errors, failures or skips |
| New functional source-owned payout matrix, included above | 44 tests / 120 assertions: THB/USD/BDT, direct+parent, draw/manual replay, source/history corruption, protected other liabilities, suspension/reactivation, rollback, retention and paid-label erasure |
| New native current-read/race matrix, included above | 5 tests / 60 assertions: peer paid-counter increment, fresh book deficit, committed paid replay, hidden original account, actual draw-vs-manual child race |
| Actual InnoDB lock witnesses | Three distinct two-child decisive wallet-lock races, including cross-ingress payout; every child observed waiting before release |
| Three separate mutation controls | 4 expected assertion failures / 0 errors; correct bytes restored before final acceptance |
| Static PHP contracts / dependency boundaries | 35 tests / 1424 assertions, pass |
| Python | 86 tests, pass |
| PHP syntax | 2013 PHP-suffixed source/template files; 0 failures |
| Pint | All 15 changed/new PHP bodies pass |
| Native verifier | 27 Rust tests and locked release build pass |
| Full locked npm graph | 149 locked/audited packages, 0 known findings; 54 Node dependency-policy tests pass |
| Actual Vite build | Pass under pinned Node24.22.0/npm11.21.1 |
| Full locked Composer graph, including development dependencies | 0 advisories / 0 abandoned packages |
| Source preflight / generated inventory | Pass; no missing imported App classes or stale generated documents |
| Final source freeze | All 16 implementation/test/generator bodies unchanged throughout final complete acceptance |


The223 SQL cases consist of44 new functional cases,5 new SQL-only cases and174 earlier cases rerun. They overlap the default suite.87 default skips are82 prior plus5 new native-only cases; all19 selected hold/cancellation/payout SQL-only cases were actually rerun successfully. The other68 profile-dependent default skips remain explicit, not silently removed.

The new race uses TWO actual independent children, one draw ingress and one manual ingress, BOTH observed waiting on the same decisive agent wallet row before the parent blocker releases. Exactly one pays, the other verifies the already-paid original; one receipt and one agent credit remain. The earlier two member cancellation races also passed again. A dedicated rerun of these same3 frozen-source race cases recorded the allowlisted temporary witness files; it passed3/3 and is not added as unique coverage. Selected RR tests show stale ordinary readers while authoritative current reads preserve a peer's committed paid-counter increment, refuse a fresh aggregate/book deficit, verify committed payout replay and reject a now-hidden original expense account.

Three separate source mutation controls produced four expected assertion failures, with zero errors: two selected current agent/book read removals, one original-account current-read removal and one durable-receipt lookup removal. Correct bytes were restored before final positive acceptance. Each control owns a fresh synthetic SQL schema. These failures are not successful financial operations. An initial SQL attempt after intentionally hiding an account hit a dirty-test-store bootstrap unique key; it was not accepted. The OWNED disposable schema was recreated for final acceptance, not repaired or adopted as financial history. An earlier run was stopped to add the durable-receipt cancellation guard; the final source acceptance reran afterward with frozen source. The full default attempt then exposed40 infrastructure-only missing-GD errors in two files. After installing GD, ALL83 cases in those complete files reran and passed; exact matching source-case IDs replace those files in the final accepted set while retaining3272 other accepted cases. Raw initial exits, initial errors and the83-case supplementary run remain explicit. No test/source change or added skip hid the environment error.

Earlier successful master tests had directly inserted unowned commission labels with zero earned counters. Those positives now exercise actual funded purchase ownership instead of preserving unsafe disbursement by fiat. Their suspended-agent expectation now asserts a hold with preserved earnings, not forfeiture. Historical/unowned refusal remains independently tested.

## Retention, actor and rollout boundaries

- The new migration has no historical backfill/default receipt. Any retained original receipt, even after a later reversal, blocks ordinary downgrade. FK restrictions prevent ordinary hard-delete of its original source/credit. Never treat migrate:fresh, clearing keys, deleting receipts or database restoration as a financial rollback.
- Deploy schema BEFORE coherent new application/worker writers, with authorized backup/restore acceptance. No production migration was run. Old workers or unintegrated writers do not automatically acquire this protocol by sharing a database.
- These are existing INTERNAL service ingresses only. `reviewerId` is audit attribution, not role/permission verification or maker/checker approval. No new HTTP/console route, live transfer or provider permission exists. Existing caller/open-draw semantics were not silently replaced with a universal completed-draw policy; operator/draw ingress policy still requires integration.
- Unknown routing refuses; owning top-level payout transactions use at most3 DB transaction attempts, ambient transactions1. Wallet-first/current reads are not a proof of universal deadlock freedom: legacy reversal and other writers still have different lock orders. Measure scans/indexes and representative volume before rollout.
- The immutable receipt is an original-credit fact, not a completed compensation state machine. Legacy standalone reversal is not globally integrated with source claims/aggregate floors; it may leave a contradictory source held for controlled review. Do not reopen/correct it by raw edits or claim a paid refund is enabled.
- Agent-wallet credit is local internal accounting, not provider/bank settlement. THB/USD/BDT tests do not confer worldwide operator/country permission.

Read-only triage, never authorization to adopt history:

```sql
SELECT c.id, c.agent_id, c.status, c.financial_transaction_id
FROM agent_commissions c
LEFT JOIN agent_commission_payouts p ON p.commission_id = c.id
WHERE c.status = 'paid' AND p.id IS NULL;
```

## Remaining work — authoritative continuation

1. Original-specific PAID commission compensation coordinated atomically with member refund/risk/ticket/audit, using the proved original receipts, agent wallet/hold floors and exact owned aggregate closure. Insufficient/locked agent funds, previously compensated originals and unknown outcomes need explicit original-specific policy; no standalone reset/resend or unilateral member refund.
2. Integrate/retire generic and standalone agent reversal, legacy/unbound payout, historical accrual/referral and every aggregate writer; prove compensated/reversed replay and globally coherent lock/history ownership. Fixing the facade's delegate/list shape does not certify its legacy reversal algorithm or automatic operational permission.
3. Remaining unmanaged reservations, draw-wide cancellation, settlement/prize/reversal/reporting/hold/purchase/amendment replay and all-writer integration; actor ingress, completed-draw policy, maker/checker and separation of duties.
4. Actual provider/merchant/jurisdiction selection, authenticated original inbound/outbound/refund adapters and authorized acceptance; explicit incoming-fee/refund/dispute economics. Evidence labels and manual attestations are not authenticated provider facts. External refund gates remain closed.
5. Independently authorized historical reconciliation/migration and aggregate review. Missing owners/receipts/fingerprints/signatures are never reconstructed by guessing or from available balances.
6. Authorized remote CI/staging/deployment and volume/all-writer/cross-wallet/multi-host contention; scan/index cost acceptance, private storage, backup/encryption-key restore/retention, fresh browser/accessibility/security/pentest coverage and ongoing advisory monitoring.
7. Country/operator legal, KYC/AML, tax, liquidity, monitoring and incident response. No worldwide operating permission or whole-platform100-percent completion is claimed.

All25 bodies are supplied beginning-to-end. The584-path cumulative ZIP is a SOURCE OVERLAY on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export, deployment approval or bank acceptance.559 prior paths are unchanged,11 revised and14 first supplied in full; some first-supplied paths already existed in the base repository.
