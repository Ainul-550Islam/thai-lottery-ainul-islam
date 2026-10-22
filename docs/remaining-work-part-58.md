# Part58 completion and remaining release work

## Newly completed application functionality

- Independently opt-in scheduled signed Stripe inbox routing command with an explicitly configured existing approved staff actor; no automatic staff creation/duty grant.
- Durable scoped SQL work enrollment without an ID watermark, at most100 enrollments/tick, oldest-due bounded claims and at most25 independently processed items/tick.
- Committed300-second leases with actor/token/generation binding, abandoned lease recovery, before-dispatch validation and post-routing completion fencing. No queue locks remain held while the original router acquires wallet locks.
- At-least-once observational routing with explicit `lease_lost`/`routing_committed` outcomes; retry after a lost completion re-proves an existing routing receipt rather than fabricating a second financial application.
- Exponential60–3600-second retry,100-attempt per-budget hold, source corruption quarantine, explicit protected staff requeue with fixed audited reason and preserved generation/history.
- Encrypted protected Work snapshots and append-only transition events, unique predecessor succession and atomic index/event writes; repeatable same-generation quarantine/requeue is supported, not trapped by a duplicate generation/kind index.
- Actual finance inbox worker hints and Livewire held-retry action, command/scheduler tests and three additional genuine two-child native waits. The known original routing/capture/review proof remains mandatory.

This closes the previously missing bounded automatic routing/retry capability. It does not close refund execution, chargeback accounting, risk/withdrawal controls, live merchant authorization or production operations certification.

## Operator configuration and deployment

1. Retain the approved current merchant/account configuration and separate follow-up signing secret from Parts56–57. Supply merchant/provider details later as requested; none were fabricated here.
2. Apply the new additive Work/events migration after Part57 on a rehearsed nonproduction copy. Preserve application encryption keys and all original capture/inbox/review/routing/worker custody. The local empty migration up/down/up rehearsal is not a populated-history restore or production deployment.
3. Deliberately configure `STRIPE_DEPOSIT_FOLLOWUP_SWEEP_ACTOR_ID` to an existing currently approved active admin/super-admin with explicit `review stripe deposit followups` duty. Protect the trusted CLI host and service-attribution policy. Normal admins are not automatically granted this duty.
4. Enable `STRIPE_DEPOSIT_FOLLOWUP_SWEEP_ENABLED` only alongside existing approved review/routing flags. The new flag defaults OFF; no original-credit, refund or withdrawal permission follows from it. Ensure the deployed scheduler actually runs every minute.
5. Monitor command exits, aged pending/retry/expired leases, held items, corrupt custody and budget/generation/storage saturation. A successful empty/bounded tick is not a complete external-ingress or refund-absence proof. Under sustained overload, add separately tested capacity/partitioning instead of claiming universal fairness.
6. After an interrupted tick inspect queue/routing history: earlier item roots and a route whose queue completion failed can already be committed. Retry uses proved idempotence; do not release money or resend uncertain originals based on queue status.
7. Use the actual finance held-retry action only after investigation. Protected header corruption requires an audited custody restore/incident process, not silent auto-healing. Harden panel authentication/MFA, private audit access, TLS/raw-body preservation, clock synchronization, delivery monitoring and provider-dashboard checks separately.

## Still separate or unfinished

- Refund execution, dispute evidence submission, source-owned chargeback/refund wallet recovery/reversal, partial capture/refund policy, fee/merchant balance and bank-payout reconciliation. Automatic routing does NOT implement these financial actions.
- Provider-confirmed negative/cancellation release. Unmatched, expired, delayed, held, empty-tick or absent follow-up states cannot release an uncertain reservation, authorize resend or prove no refund/dispute.
- Atomic ingress/review/routing fences, future-event finality, automatic header reopening exactly at HTTP arrival, risk clearance or withdrawal gating. New evidence reopens a case only when routing/review actually observes it.
- Global notification/incident escalation, worker capacity/overload SLAs, tenant/partition fairness certification, archive/retention migration and large-volume operation beyond current bounds:5000 signed inbox rows/account/configuration per review scan,100 observations/case,100 routing decisions/inbox,100 attempts/budget,1000000 generations/work.
- Thin-event retrieval, Connect movement, other providers, approved account/key migration and legacy Applied-webhook refund/dispute import. The dedicated signed lane remains separate from older latest-matching-Applied deposit reports.
- Native simultaneous ingress-duplicate, cross-account/cross-wallet ownership races, every callback/manual writer and globally synchronized permission/KYC/AML/age/sanctions/country writers. Current role-cache/global lock-order limits remain explicit.
- Complete externally witnessed history/anti-rollback/deletion guarantees. Current Work/header receipt validation and SQL predecessor uniqueness are not certification against arbitrary privileged database/schema/key compromise.
- Actual scheduler-host deployment/failover, process-kill/power-loss/disaster recovery, populated-history restore, provider sandbox/live certification, browser/load/pentest, production observability and remote CI execution. Controlled clocks/faults and local native waits are not substitutes.
- Country-specific lottery/payment/data/tax/legal approval. Worldwide architecture is not legal permission in every country.

## Delivery

Sequential full source begins File1142 after immutable Part57 File1141. Every supplied body is complete. Exact final-source discovery/results/skips, deliberately failing isolated controls, native witnesses, checksums and recovery notes accompany delivery. Cumulative archives are source/evidence overlays on retained base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not standalone repository exports. Restore documented exact retained Git/archive duplicates, reinstall locked dependencies and rebuild generated assets before execution; disposable test databases are not delivered. Previous primary delivery artifacts remain unchanged. No live funds/refund/transfer or worldwide100% completion claim is made.
