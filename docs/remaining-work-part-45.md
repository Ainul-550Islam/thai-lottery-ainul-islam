# Part45 — original-specific paid member cancellation

Recorded2026-10-16. Complete Files829–855; next File856.

## Implemented application behavior

The existing authenticated member cancellation now supports immutable direct/parent sources containing paid commissions, unpaid commissions, or both. It still requires the original owner, eligible active un-settled purchase, open cancellation window, exact funded member debit/journal, original ownership and protected number-risk floor. It is not a new operator, payment or draw-wide ingress.

`AgentCommissionPayoutProof` snapshots an explicit cancellation-policy reference ONLY when creating a NEW owned original payout. The configuration defaults OFF. A valid reference has8–128 ASCII characters in the recorded grammar. A later configuration change cannot give a historical NULL receipt permission. The current configured reference must equal the original captured one for a fresh cancellation. Disabling/changing it safely holds those new cancellations. A completed cancellation is still verified read-only after the current policy is disabled; no new debit or authorization is inferred.

Configuration uses `AGENT_PAID_CANCELLATION_ENABLED` and `AGENT_PAID_CANCELLATION_POLICY`. These are mechanics gates, NOT proof of independently authorized operator/agent consent, a jurisdictional legal basis or liquidity. Do not enable them merely because this code exists. No real contract/provider/merchant was selected or approved here. Different policy versions are not silently substituted; older-policy cases remain for a separately authorized workflow rather than being rewritten.

Member and original recipient wallet IDs are ordinary routing hints only. The root service locks the complete hinted wallet set in sorted order before the draw/ticket/bet/risk/source/book proof. Each paid source must currently resolve to an already locked original wallet. If a payout commits after an old RR routing snapshot, the missing route refuses the whole operation; it never adds a late unsorted lock. Current original headers/journals/accounts, source terms, receipts and current exact book counters are re-read under locks.

All selected sources are preflighted before closing any. For each paid source the private coordinator writer creates ONE canonical reversal of its own original payout, mirrors every original ledger row, debits exactly that original agent wallet through shared hold/withdrawal spend floors, verifies the completed balanced compensation, marks the original transaction Reversed and inserts a permanent `agent_commission_compensations` fact. The original paid pointer/stamp and immutable source metadata are preserved. Unpaid rows have no original credit to debit and become Cancelled only with history-free proof. The retained `cancelUnpaid` method name is compatibility terminology for the existing INTERNAL coordinator primitive, not a claim that it is still unpaid-only.

The root member transaction owns all agent debits, source closure, exact counter changes, member stake refund, risk decrement, bet/ticket transitions and audit. Failure in an agent/parent leg, a protected hold, member wallet credit or final member audit rolls back the complete operation. Earned decreases by exactly every selected owned amount; paid decreases ONLY by exactly the selected proved paid originals. No clamping, available-balance inference, repricing or stale broad agent save is used. Other paid and pending liabilities remain protected by the shared current book floor.

`BetCommissionCompensation` contains policy/original/terminal PROOF only. It exposes no standalone money writer. The actual compensation method is private inside `BetCommissionCancellation`; its existing internal primitive is not a new public authorization boundary. Call the complete member service, not that internal primitive in isolation. Universal actor/all-writer integration remains distinct work.

Terminal replay verifies the original payout and permanent receipt, exactly one completed canonical mirror, its exact financial header/accounting, required binding metadata and hash, the permanent payout-fact hash including policy, original/commission reversal stamps and actor, member/purchase binding, source closure reason/stamp and absence of competing compensation/reversal/orphan-ledger history. It does not resend, reset, heal, adopt a partial outcome or compare today's running balance to an old balance snapshot.

The generic financial reversal guard independently rejects durable owned payout originals and compensation transactions, even when mutable labels are changed. The standalone agent bet/draw reversal now rejects ANY owned commission scope, including unpaid rows, rather than detaching source labels/counters from an unrefunded member purchase. It does not implement draw-wide refunds by reversing commissions alone. Historical unowned writers are not thereby certified.

## Executed final acceptance

| Executed local acceptance | Actual result |
|---|---|
| Complete default PHP, four disjoint file-backed SQLite shards | 3411 tests / 135122 assertions / 94 explicit skips / 0 errors or failures; all four exits 0 |
| Discovery equality | Every one of the3411 discovered test IDs occurs exactly once in the final result |
| Native MariaDB/InnoDB,13 complete selected files | 288 tests / 1538 assertions / 0 skips or failures |
| Selected default skips actually exercised natively | 26 of94;68 other profile-dependent cases remain explicit skips |
| Real independent two-child wallet contention | Four race cases; both children SQL-observed blocked before release; distinct child and blocker connection IDs |
| Mutation controls | Four intended assertion failures,0 errors; original source restored before final positive acceptance |
| Static PHP contracts | 35 tests / 1438 assertions, pass |
| Offline Python contracts | 86 tests, pass |
| PHP syntax | 2018 PHP-suffixed source/template files,0 failures |
| Changed PHP formatting | All16 complete PHP bodies pass Pint |
| Locked Rust tests / native release build | 27 tests pass; release build succeeds |
| Locked full Composer audit | No advisories or abandoned packages |
| Node24.22.0 / npm11.21.1 | Clean install,54 dependency contracts,0-vulnerability dependency gate, production Vite build pass |
| Source inventory/preflight | No missing sources/imports or stale generated inventory |


The49 new functional cases cover THB/USD/BDT across direct, both-paid and mixed hierarchies, exact residual receivables, policy disable/change/history refusal, spent/frozen/held/floor-deficit wallets, bad source/receipt/header/journal/counters, unknown and orphan compensation artifacts, terminal tampering, late-audit rollback, actual authenticated HTTP and managed generic bypass rejection. Seven separate native-only cases prove six independent committed-peer RR outcomes and an actual two-child paid cancellation race. Native tests do not inherit functional test classes or multiply inherited cases.

The four native witness cases are part of the288 cases, not extra unique coverage. Each has two actual independent children SQL-observed waiting on its decisive first wallet before the parent blocker releases. Paid cancellation leaves one member refund, exactly two original-specific agent compensation facts and one verified replay. The other three original payout/unpaid/member races also pass again.

Four controlled source mutations each yield one expected assertion failure, with0 errors: retroactive NULL-policy adoption; forbidden late wallet locking/map enrollment after a stale hint; stale current agent-counter authorization; and omitted terminal compensation digest validation. Correct source bytes are restored before final positive acceptance. These failures are not successful financial operations. The first incomplete late-wallet mutation produced a test-setup map error and was discarded, not advertised as proof.

An earlier user continuation interrupted incomplete acceptance and its runtime/loose-source state did not all persist. The authentic584-path archive was verified, baseline paths recovered without treating stale loose files as authority, and17 updated sources explicitly reconstructed. Final source hashes are recorded; all PHP acceptance and mutation controls reran. A resumed HTTP attempt then exposed the missing generated Vite manifest. That raw failed attempt was retained, the actual pinned frontend rebuilt and the ENTIRE default suite restarted on fresh isolated SQLite stores. No test or extra skip concealed the missing artifact. Final acceptance is one clean complete four-shard run, not an unrecorded mixture of interrupted results.

## Retention and rollout

Deploy the additive migration before coherent authorized application/worker writers, after actual backup/restore and operational review. It adds a nullable historical policy column WITHOUT backfill and a unique original/payout/source/compensation fact. Explicit short FK/index names are compatible with native InnoDB identifier limits; the native fresh migration is exercised. Ordinary downgrade refuses any compensation OR any captured policy, including a captured policy before cancellation. FK restrictions preserve referenced original accounting/source facts. Application immutability is not a promise against a privileged database administrator deleting or rewriting all history.

Never delete a claim/receipt/key, reset a status, reconstruct provenance from balances, adopt a guessed historical counter or resend an unknown outcome. A spent/held original wallet or unknown prior compensation holds the member purchase intact for independent controlled review. This delivery is a SOURCE OVERLAY of596 paths on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export or deployment approval.569 prior paths are unchanged,15 revised and12 first supplied in full; some first-supplied paths existed in the base repository.

## Remaining work — authoritative continuation

1. Remaining legacy/unbound accrual/referral, aggregate and generic writers; broader actor/maker-checker ingress and completed-draw policy. Scoped owned-source commission-only reversal is retired, not universal historical reconciliation or global lock certification.
2. Unmanaged reservations, draw-wide cancellation and full settlement/prize/reversal/reporting/purchase/amendment replay and all-writer integration. Draw-wide member refunds cannot be manufactured by a commission-only reversal.
3. Independently authorized historical reconciliation/migration and source/counter review. NULL policies, missing owners, missing journals and unsupported signatures are never enrolled by toggling configuration.
4. Actual country/operator/provider/merchant selection; authenticated inbound/outbound/refund/dispute adapters and explicit fee/refund economics; independently authorized real acceptance. No live financial operation was run here.
5. Authorized CI/staging/deployment, volume/all-writer/cross-wallet/multi-host contention and scan/index cost; private storage, key/backup recovery and retention; fresh browser/accessibility/security/pentest coverage and continuous advisory monitoring.
6. Country/operator legal, KYC/AML, tax, liquidity, monitoring and incident response. Currency support is not worldwide operating permission, agent consent or whole-platform100-percent completion.
