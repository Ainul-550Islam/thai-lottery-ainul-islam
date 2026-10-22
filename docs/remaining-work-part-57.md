# Part57 completion and remaining release work

## Newly completed application workflow

- Current-account/configuration global signed Stripe follow-up inbox in the finance panel, without requiring an original deposit ID. Event search, arrival sorting, pagination, never-routed/last-unmatched filters and redacted history are supplied.
- Explicit one-row and bounded25-row independent routing actions. No caller-selected target, amount or provider endpoint is accepted. Partial batch commits are explicit; revoked authorization/infrastructure failure stops remaining work.
- Server-discovered candidate originals, ambiguous-source refusal, wallet-before-inbox lock order, fresh in-root discovery checks, original applied-capture/canonical-credit proof and exact signed-source case-observation proof.
- A savepoint-protected SQL-only coordinated review entry that re-proves its own original, not caller-supplied financial evidence. Existing standalone inspect/acknowledge top-level boundaries are preserved.
- Encrypted append-only source/body/account/configuration/original/case/version-bound routing decisions, full available history validation before replay, retained unmatched-to-routed history, same-source original continuity and immutable original actor attribution.
- New default-off activation, existing explicit active-staff review duty, instance save/delete guards, no CRUD UI and no raw/private payload display. Three additional genuine two-child native SQL waits exercise duplicate matched routing, duplicate unmatched routing and route versus old acknowledgement.

This closes the previously missing **manual global inbox and safe original routing** capability. It does not claim to close automatic monitoring, financial refund/chargeback handling or provider/legal certification.

## Operator configuration and deployment work

1. Provision approved merchant/account credentials and lottery-business authorization later, as already requested. Register the approved raw-body-preserving HTTPS Stripe follow-up endpoint and separate signing secret from Part56. No credentials, approval or real event was fabricated.
2. Rehearse additive migrations and populated backup/restore in a nonproduction copy; preserve encryption keys, checkout/capture/inbox/case/acknowledgement/routing custody. The supplied local empty SQLite up/down/up rehearsal is not populated-history restore or production deployment proof.
3. Deliberately grant `review stripe deposit followups` to approved active admin/super-admin staff. Harden panel authentication/MFA, private audit access, account-label/key ownership and incident procedures separately. Normal admins do not receive automatic access.
4. Enable both `STRIPE_DEPOSIT_REVIEW_ENABLED` and `STRIPE_DEPOSIT_FOLLOWUP_ROUTING_ENABLED` only after the approved current account/configuration and `STRIPE_DEPOSIT_FOLLOWUP_WEBHOOK_SECRET` are established. Original captured-funding credit activation is independent and is not granted by routing.
5. Monitor delivery failures, clock synchronization, TLS/proxy raw bodies and provider-dashboard incidents. HTTP200 means signed inbox custody only. Staff must explicitly route/inspect, investigate unknown/ambiguous/held events, and use the existing deposit review actions for exact-version findings notes. Retry unmatched events when original evidence becomes available; do not treat them as harmless.
6. Read historical routing labels as hints, not current risk clearance. Batch items commit independently; after an interrupted batch, reopen history to discover earlier successful commits before retrying. No durable increasing-ID checkpoint exists; older pending rows remain available.

## Still separate or unfinished

- Automatic global routing workers/sweeps, fair retry scheduling, notifications, automatic case-header reopening on ingress, atomic ingress/review fences and authenticated absence/future-event finality. The manual global backlog is supplied; these automatic behaviors are not.
- Provider-confirmed negative/cancellation release. Local timeout, empty/unmatched search, expiry, or lack of follow-ups cannot release an uncertain reservation or authorize resend.
- Refund execution, dispute evidence submission, chargeback accounting and source-owned wallet recovery/reversal; partial capture/refund/settlement policy; actual fees, merchant-balance and bank-payout reconciliation. Routing/acknowledgement authorizes none of them.
- Withdrawal gating or risk clearance derived from follow-ups, and globally synchronized permission/KYC/AML/age/sanctions/country writers. A single reviewed snapshot does not certify the whole money system.
- Generic legacy Applied-webhook refund/dispute import or universal observation-history adjudication. The dedicated signed lane remains separate from the older latest-matching-Applied deposit reports.
- Thin-event retrieval, Connect money movement, other providers, approved account/key migration and large-volume archive/partition migration beyond5000 inbox rows/account/configuration,100 observations/case and100 routing decisions/inbox row.
- Simultaneous native ingress-duplicate certification, cross-account/cross-wallet duplicate-owner races, universal callback/manual-writer ownership closure, an independently witnessed global routing-history deletion detector and all-writer/global lock-order certification. Read-only models do not constrain every privileged raw SQL writer.
- Real provider sandbox/live certification, browser/load/pentest, production observability/incident automation, process-kill/power-loss/disaster recovery, populated-history restore and remote CI execution. New queue GET/UI actions and local SQL witnesses are not substitutes for those proofs.
- Country-specific lottery/payment/data/tax/legal permission. Worldwide architecture is not legal permission in every country.

## Delivery and recovery

Sequential full source starts File1118 after immutable Part56 File1117. Every supplied body is complete, not a snippet. Exact final-source test discovery, actual results/skips, isolated expected-failure controls, genuine native lock witnesses, checksums and cleanup recovery accompany this delivery. Cumulative archives remain overlays on retained base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not standalone repository exports. Restore documented retained Git-backed preview duplicates if needed, reinstall locked dependencies and rebuild frontend/native assets; generated dependencies/assets and disposable test stores are excluded. Historical Part56 artifacts remain unchanged. No live funds/refund/transfer or worldwide100% completion claim is made.
