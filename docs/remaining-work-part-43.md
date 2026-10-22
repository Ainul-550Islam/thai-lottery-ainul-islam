# Part43 — coordinated NEW unpaid commissions and member refund

Recorded: 2026-10-16T09:56:50.588266+00:00. Full Files785–803; next File804.

## Actual functionality

Part42 refused any commission history rather than refund a member while retaining an agent sale. Part43 now supports the NEW OWNED UNPAID lane: a funded purchase records the exact original commission membership and immutable direct/parent terms in the same purchase transaction. Membership includes a captured empty set, so later rows cannot be silently adopted. A one-shot snapshot cannot be overwritten. Historical NULL is deliberately retained, not guessed or backfilled.

Cancellation still proves the original paid bet/wallet/journal and all Part42 gates. It additionally locks/currently revalidates agent IDs, complete original membership, exact stored amounts/rates/base/owner/draw/currency, no soft deletion and NO financial/payout history. Accrued/Calculated/Payable rows may close only with their permanent matching source claims. Paid rows, paid pointers/stamps, orphan transaction references or even orphan ledger references refuse; a removed transaction label cannot hide its surviving journal reference.

The member refund, own commission claim closure, direct/parent Cancelled status, exact earned-counter decrements, risk release, ticket, canonical refund ledger and audit now commit together or all roll back. No agent wallet movement is needed or performed for UNPAID receivables. Paid counters are never decremented by this lane. Other owned amounts plus known unowned outstanding book amounts form a current conservative minimum; an unexplained surplus is preserved, a deficit is refused, and no negative counter is silently clamped. Known unowned rows are protected, not enrolled as owned sources. Other already-paid commissions and their wallet credits remain untouched.

Accrual now reads referral identity under the member lock, locks relevant agent rows and computes earned increments from SQL text-cast current counters rather than a stale unlocked model. Changed/unavailable hierarchy hints refuse instead of acquiring arbitrary late locks or silently misattributing a sale. Currency mismatch refuses. Existing records are not accrued twice. This does not authenticate a historical accrual or certify every legacy payout/reversal writer.

Verified terminal replay checks the original commission membership, immutable terms, own Cancelled states, source reason/stamp and absence of commission financial history before returning the original member refund. It does not cancel another commission, subtract another amount or issue another credit. A real HTTP test found the older BetDomain refusal handler calling a nonexistent `codeKey()`; it now uses the actual `errorCode()` method and returns422 instead of500. The existing authenticated endpoint is reused with no new route or permission.

## Executed acceptance

| Executed check | Result |
|---|---|
| Complete default PHP / four isolated owned SQLite stores | 3306 cases / 134761 assertions / 0 errors or failures / 82 explicit skips |
| Test discovery equality | Every discovered PHP test ID executed exactly once in the full default run |
| Owned MariaDB matrix | 174 tests / 1032 assertions / 0 errors, failures or skips |
| New coordinated unpaid matrix, included above | 31 cases / 194 assertions, including THB/USD/BDT direct+parent closure, other owned/unowned/paid liabilities, HTTP, corruption, rollback, replay and retention |
| New native current-read/concurrency matrix, included above | 5 cases / 63 assertions; four selected old-snapshot scenarios and an actual two-child lock-wait race |
| Actual InnoDB lock witnesses | Two distinct two-child races confirmed simultaneous decisive wallet-lock waits, including attributed cancellation |
| Negative control, not a positive financial operation | Removing selected current agent/book locks produced 2 expected failures / 0 errors; correct source bytes restored |
| Static PHP contracts / dependency boundaries | 35 tests / 1402 assertions, passed |
| Python | 86 tests, passed |
| PHP syntax | 2008 PHP-suffixed source/template files; 0 failures |
| Pint | All 9 changed/new PHP files pass |
| Native verifier | 27 Rust tests and locked release build pass |
| Full locked npm graph | 149 locked/audited packages; 0 known findings; 54 Node policy tests pass |
| Actual Vite build | Passed under pinned Node24.22.0/npm11.21.1 |
| Full locked Composer graph, including development dependencies | 0 advisories / 0 abandoned packages |
| Source preflight / generated inventory | Passed; no missing imported App classes or stale generated documents |
| Final source freeze | All 10 implementation/test/generator bodies unchanged throughout final acceptance |


The174 native cases include31 new coordinated functional cases,5 new native cases and138 earlier cases rerun. They overlap the default suite; do not add them as unique coverage.82 default skips consist of77 earlier plus5 new SQL-only cases. All5 new and the earlier9 native hold/cancellation cases passed on the owned SQL store. The other68 profile-dependent skips remain explicit.

The attributed race used two independent child connections both observed waiting on the same parent-held decisive wallet row. Releasing the blocker produced one member refund/commission closure and one verified replay. The earlier direct-cancellation race passed again. Four selected stale-snapshot cases exercise accrual arithmetic after peer accrual, a committed other owner plus aggregate deficit, committed cancellation replay and committed payout refusal. Ordinary reader snapshots demonstrably remain old while decisions use current rows. Removing the selected current agent/book locks produced two expected assertion failures, including a lost earned increment and an unpaired refund; correct bytes were restored before final positive acceptance. These failures are NOT passed operations.

The accrual-only stale-snapshot fixture uses a synthetic already-funded initially-unattributed third sale, then associates its test member before isolated accrual. It does not weaken the no-nested-purchase transaction guard, rewrite its original empty commission snapshot, approve retroactive attribution or enroll historical ownership. No bank/provider call was made.

## Scope boundaries and rollout

- This tranche coordinates NEW OWNED UNPAID commission receivables only. A paid attributed purchase still returns a safe422 and preserves both member purchase and agent credit for explicit original-specific compensation. It must not silently clamp paid balances or refund the member alone.
- Older Part42 DIRECT purchases with NULL commission snapshot remain usable ONLY when there are no commission rows or claims at all. Older attributed/unbound history remains held for independent authorized reconciliation. No claim/hash is manufactured from a balance or label.
- New snapshot/claim history never expires. Ordinary downgrade refuses nonempty snapshots, including captured empty sets and already-cancelled history. Destructive fresh/drop/restore is not a financial rollback.
- Deploy schema before the new writers under an authorized coherent rollout/worker plan with backup/restore acceptance. No production migration was run here. Test indexes, per-agent book scan costs, all writer lock orders and representative volume before rollout. Current locks and bounded transaction retry are not a claim of universal deadlock freedom.
- Standalone historical accrual, legacy generic reversal, draw/manual settlement, facade/adapter call shapes, actor review and every aggregate writer have NOT all been integrated into this ownership protocol. They require further work; do not bypass refusal by editing statuses, deleting claims or clearing pointers. A shared book check does not make arbitrary raw SQL safe.
- THB/USD/BDT direct+parent paths were actually exercised without currency mixing. Country/operator legal, KYC/AML/tax, liquidity and provider permission are not implied by supported currency arithmetic.

Read-only triage, not authorization to adopt or repair:

```sql
SELECT c.id, c.agent_id, c.bet_id, c.status
FROM agent_commissions c
LEFT JOIN bet_commission_ownerships o ON o.commission_id = c.id
WHERE c.bet_id IS NOT NULL AND o.id IS NULL;
```

## Remaining work — authoritative continuation

1. Original-specific PAID commission compensation coordinated with the member refund, wallet floors and agent aggregates; insufficient/locked agent funds, verified compensation replay and no unknown-outcome resend. Other paid/legacy agent payout/reversal/facade/referral writers and unified lock/permission policy remain uncompleted.
2. Other unmanaged generic reservations, draw-wide cancellation, settlement/prize/reversal/reporting/hold/purchase/amendment replay and all-writer integration; granular operator ingress, maker/checker and separation of duties. This tranche is not universal historical protection.
3. Actual provider/merchant/jurisdiction selection, authenticated original inbound/outbound/refund adapters, explicit incoming-fee/refund/dispute economics and authorized acceptance. Human evidence labels do not authenticate a bank or resolve an unknown outcome. External refund policies remain fail-closed.
4. Independently authorized historical reconciliation/migration and balance review. No missing owners, references, fingerprints, signatures or economics are reconstructed by guessing.
5. Authorized remote CI/staging/deployment, all-writer/cross-wallet/deployment-load/multi-host contention, scan/index cost acceptance, private storage, backup/encryption-key restoration and lawful retention; broader browser/accessibility/security/pentest acceptance and continuing advisory monitoring.
6. Country/operator legal, KYC/AML, tax, liquidity, monitoring and incident response. No worldwide operating permission or whole-platform100-percent completion is claimed.

All19 delivered bodies are complete. The570-path ZIP is a SOURCE OVERLAY on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependencies/database export, deployment approval or real-bank acceptance.551 prior bodies are unchanged,8 revised and11 paths first supplied in full, including an existing base accrual file.
