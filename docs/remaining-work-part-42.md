# Part42 — real owned-purchase cancellation and exact local refund

Recorded: 2026-10-16T09:08:10.057838+00:00. Files762–784; next File785. This continues Part41 without declaring the unfinished platform complete.

## Actual implementation

The previous cancellation read the bet, status, window, draw and items outside its transaction. Its refund assumed a primary wallet and replay selected a loosely matching latest refund. A stale/concurrent request could release risk twice even when financial idempotency prevented a second credit.

New purchases now create a durable `bet_purchase_ownerships` row in the SAME wallet-first purchase transaction, after the actual debit, canonical balanced posting, placement and commission accrual. It binds one bet, original transaction and wallet to exact stored economics, one risk row and immutable terms/journal fingerprints. Decimal claim amounts are canonical strings; critical proof/counter amounts are read through SQL text casts rather than float conversion. A loaded historical purchase is not enrolled by replay/reconciliation. UNIQUE links and restrictive foreign keys preserve ownership; ordinary downgrade refuses nonempty history. Fresh model flags are defensive writer-context checks, not actor authentication.

Cancellation uses only navigation hints before its transaction. Inside it, current locks re-read the actual original wallet, draw, ticket, bet, number limit, receipt, item, transaction, entries and canonical accounts. It revalidates ownership, exact original paid stake/currency, one logical item, immutable terms, active/unsettled state, actual time cutoff and reason authority. Refund posts to that ORIGINAL wallet, not a newly available primary wallet. THB/USD/BDT nonprimary-wallet cases were actually executed. No exchange rate, currency mixing or worldwide license is inferred.

The shared risk engine now refuses release below the SUM of all active new owned stake/payout claims and refuses a new reservation that would camouflage an existing deficit. Cancellation closes only its own reservation in its transaction before the precise decrement; another owner's minimum remains. Legacy unowned excess is not silently converted into ownership. A configured lottery risk currency still governs a number-limit book; changing it over open owned claims fails closed. This is not a new multi-currency risk pooling/FX protocol.

The bet cancellation, owned claim closure, single risk decrement, original-wallet credit, two-legged refund journal, ticket synchronization and audit commit together or all roll back. A deliberately late audit fault after the actual refund posting rolled every part back. Terminal replay proves both original and canonical refund headers, exact two full-amount linked entries, canonical currency/account/side/wallet identity, immutable fingerprints, closure reason/stamp and unambiguous history. It never resends, repairs or re-credits. Generic reversal cannot evade durable original/refund links by removing a polymorphic label.

The EXISTING authenticated HTTP endpoint now also maps risk/financial refusals to redacted422 responses AFTER rollback rather than transport500s. Actual HTTP tests exercise refund/replay, a risk deficit, frozen-wallet rollback and forbidden client financial/owner fields. No route, public permission, operator authority or external provider adapter was introduced.

## Deliberately fail-closed boundaries

- Historical purchases without the new receipt are NOT automatically enrolled or refunded, even if their balance, status or old journal looks plausible. Historical review needs independent evidence and an authorized migration plan.
- Any attributed commission history, including hidden history, requires a coordinated cancellation workflow. This implementation refuses rather than refunding a member while retaining a paid/accrued agent sale. It does NOT claim to have implemented that workflow.
- New ownership is for the purchase/direct-cancellation lane. Other settlement, draw-wide cancellation, prize, reporting, amendment replay, generic raw writers and agent aggregate writers are not all rewritten or certified here. The shared engine floors protect integrated mechanics; arbitrary raw SQL is not made safe by a helper.
- Ownership is permanent history, not a TTL reservation. No unknown payment outcome is resolved by time, status editing, deletion, reset, resend or takeover.

## Executed acceptance

| Executed check | Result |
|---|---|
| Complete default PHP, four independent disposable SQLite stores | 3270 cases / 134548 assertions / 0 errors or failures / 77 explicit skips |
| Discovery equality | Every discovered PHP test ID executed exactly once in the complete default run |
| Owned MariaDB matrix, including earlier holds/withdrawals | 138 tests / 775 assertions / 0 failures, errors or skips |
| New purchase/cancellation matrix (included in native/default above) | 48 cases, including THB/USD/BDT original nonprimary wallets, exact accounting, corruption, rollback and real authenticated HTTP responses |
| New native current-read/concurrency matrix (included in 138) | 4 stale-snapshot cases and 1 actual two-process lock-wait race passed |
| Actual InnoDB lock witness | Two independent children confirmed simultaneously waiting on one decisive wallet lock; one refund and one proved replay |
| Negative control, NOT a positive operation | Removing only journal-set and ownership-set current locks produced 3 expected failures / 0 errors; correct bytes restored |
| Static PHP contracts / dependency boundaries | 35 tests / 1388 assertions, passed |
| Python | 86 tests, passed |
| PHP syntax | 2003 PHP-suffixed source/template files; 0 failures |
| Pint | All 13 changed/new PHP files pass |
| Native verifier | 27 Rust tests and locked release build pass |
| Full npm graph audit | 149 locked/audited packages, 0 known findings; 54 Node policy tests pass |
| Fresh actual Vite build | Passed on pinned Node24.22.0/npm11.21.1 |
| Full locked Composer graph audit | 0 advisories / 0 abandoned packages, including development dependencies |
| Source preflight / generated inventory | Passed; no missing imported App classes or stale generated documents |
| Final source freeze | All 14 implementation/test/generator bodies unchanged throughout final acceptance |


The138 native cases include the48 new ownership/HTTP cases,5 new current-read/race cases and85 earlier hold/withdrawal cases. They overlap the default suite. The default77 skips are visible:72 earlier plus5 new SQL-only cases. All5 new and the earlier4 hold-session cases actually passed on native SQL; the other68 profile-dependent cases remain default skips. Expected mutant failures are not passed operations.

The actual race used two separate child SQL connections, both observed simultaneously waiting in `INNODB_LOCK_WAITS` on the parent-held wallet row. Releasing that blocker produced one cancellation/refund and one verified replay, one audit and one risk release. Four other tests retain an old repeatable-read snapshot while another session commits cancellation, original-journal corruption, refund-journal corruption or a new risk owner plus deficit. The guards consult current rows. Removing only the entry-set and claim-set locking reads made the three selected corruption/deficit tests fail; exact correct bytes were restored before final acceptance. Session-only MariaDB snapshot-abort protection was disabled in those controlled stale-snapshot scenarios, not globally or in production.

## Safe deployment/migration sequence

1. Use the known base repository plus this complete cumulative source overlay. Restore the locked PHP/npm/native dependencies and rebuild assets with the pinned toolchain. Do not use a prior overlay to overwrite later edits.
2. Obtain an authorized backup/restore and rollout plan. Quiesce incompatible purchase/cancellation/settlement workers for a coherent schema/code deployment; apply the new migration before enabling new writer code. This conversation performs no production migration.
3. Keep ownerless existing bets in controlled review. The new migration intentionally creates no historical claims. Do not synthesize fingerprints, copy receipt rows, infer ownership from aggregate risk, or bypass commission refusal to clear a queue.
4. Verify primary/unique/foreign-key index behavior, per-number claim scan costs, draw/wallet contention, legacy writer lock order, backup/key restoration, private storage and retention at actual deployment volume. The single confirmed cancellation race is not all-writer deadlock freedom or a load certificate.
5. Routine rollback refuses nonempty receipt history. Destructive fresh/drop/restore is not a financial rollback. Preserve receipts and original/refund records through an independently reviewed retention/remediation process.

Read-only triage for historical missing ownership, not an instruction to backfill:

```sql
SELECT b.id, b.user_id, b.draw_id, b.status
FROM bets b
LEFT JOIN bet_purchase_ownerships o ON o.bet_id = b.id
WHERE b.status IN ('pending', 'active', 'cancelled') AND o.id IS NULL;
```

## Remaining work — authoritative continuation

1. Coordinated attributed-bet/member-and-agent cancellation/refund, paid-commission compensation, agent aggregate concurrency and controlled history. No silent clamping or member-only refund is acceptable.
2. Remaining unmanaged generic reservations, draw-wide cancellation, settlement/prize/reversal/reporting/hold/purchase/amendment replay and all-writer integration. New-purchase/direct-cancellation proof is not universal legacy protection. Granular operator ingress, maker/checker and separation of duties remain required across those lanes.
3. Actual provider/merchant/jurisdiction selection, authenticated original inbound/outbound/refund adapters, explicit incoming-fee/refund/dispute economics and authorized acceptance. External refund policies remain fail-closed; human archives or labels do not authenticate a bank or resolve an unknown outcome.
4. Independently authorized historical reconciliation/migration. Do not guess missing owners, canonical references, economics, fingerprints or signatures; no historical balance repair was run.
5. Authorized remote CI/staging/deployment, all-writer/deployment-volume and multi-host contention, scan/index cost acceptance, private storage, backup/encryption-key restoration and lawful retention; broader browser/accessibility/security/pentest acceptance.
6. Country/operator legal, KYC/AML, tax, liquidity, monitoring, incident response and continuing dependency/advisory checks. Supported currency accounting and clean local audits are not worldwide operating permission or whole-platform100-percent completion.

All23 delivered bodies are complete. The559-path ZIP is a SOURCE OVERLAY on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export, bank acceptance or live-money approval.536 prior bodies are unchanged;8 are revised;15 paths are first supplied in full, including some files that already existed in the base repository.
