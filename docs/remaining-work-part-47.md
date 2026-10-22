# Remaining work after Part47 — Files882–902

## Application work completed

The ordinary purchase replay path no longer returns the first item/ticket/referenced transaction as financial proof. NEW paid purchases atomically capture permanent original purchase/ticket/full financial-key receipts. Ordinary purchase retry, existing-purchase lookup, direct replay and lost-race resolution now share current wallet-first original ownership/debit/canonical-journal and sealed-ticket proof. No receipt is manufactured from a historical label. Invalid or over-precise amounts cannot be rounded into replay, and an ambient old snapshot cannot use the former unlocked shortcut.

Original funding is distinct from current cancellation/prize/balance authority. A cancelled purchase returns its original debit, not its refund or another purchase; closed sales, later winning fields and frozen-wallet status do not erase historical original funding. Missing/changed/deleted source facts, ticket identity, selected item or original accounting holds the result without repair, new reservation or debit. New receipt failure rolls back the entire ordinary purchase. Part46 amendment capabilities and strict cancellation proof remain unchanged.

The complete algorithm and migration boundary are in `docs/owned-purchase-replay-protocol-part-47.md`. The new receipt is never backfilled. Earlier otherwise-owned purchases without it need independently authorized historical review for ordinary replay; their existing cancellation/amendment policies retain their separate original-specific proof rules.

## Accepted local evidence

| Matrix | Actual result |
|---|---|
| Entire default PHP discovery, four isolated disjoint SQLite stores | 3492 tests; 135512 assertions; 101 explicit skips; zero failures/errors; exact discovered-ID equality |
| Selected complete native InnoDB matrix | 369 tests; 2045 assertions; zero skips/failures/errors |
| Simultaneous SQL contention | Eleven verified two-child witnesses; three new purchase/replay/cancelled-replay wallet races plus eight retained financial races |
| Isolated omission controls | Four intended assertion failures, zero errors; application source never modified; shadow source restored exactly |
| Static / Python | 35 tests and 1472 assertions / 86 tests passed |
| PHP syntax / style | 2030 PHP-suffixed files linted; 10 changed PHP files pass Pint |
| Exact frontend pin | Node24.22.0/npm11.21.1; clean install, complete-lock known-advisory gate, 54 contracts and genuine Vite build; all109 canonical PHP-test assets exactly match the isolated pinned rebuild |
| Composer | Full locked audit: no advisories or abandoned packages |
| Native verifier | Actual Rust1.85.1 locked release build and 27 tests passed |

These matrices overlap and must not be summed. Native execution covers33 of101 default environment/native skips;68 other skips were not executed by this selected matrix. Initial focused fixture errors are retained as diagnostics and are not acceptance. No skip, financial proof, integrity constraint or native lock-witness assertion was disabled to get the final results.

## Retention and rollout

Apply the additive receipt migration through an authorized deployment BEFORE enabling this writer version. There is no history backfill, expiry or reset. Populated receipt-table erasure is refused. Preserve original financial keys, receipt/source rows, journals and ticket facts; do not rewrite them or create a new key automatically to work around an unresolved old intent. A missing new receipt is controlled review, not permission to adopt an old row or debit again.

This is a source overlay of623 complete paths on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export or deployment approval. 602 prior paths are unchanged, 10 revised and11 first supplied in full; some first-supplied paths existed in the base repository. Next File903.

## Remaining implementation and independent authorizations

1. Legacy/unbound accrual/referral, aggregate/generic writers, broader actor/maker-checker ingress and completed-draw policies. This release does not certify all writers or globally reconcile historical counters/lock order.
2. Unmanaged/bulk reservations, draw-wide member cancellation, full settlement/prize/reversal/reporting and other replay integration. Scoped ordinary owned purchase replay and the Part46 owned amendment protocol are implemented; unrelated monetary histories/current settlement are not adopted by a historical funding receipt.
3. Independently authorized historical reconciliation, missing-snapshot migration and unknown-outcome resolution. Never guess enrollment, original ticket facts, policy or source from balances/labels, or resend an unknown outcome.
4. Actual country/operator/provider/merchant selection, authenticated adapters, fee/refund/dispute economics and authorized real acceptance. Multi-currency mechanics are not worldwide operating permission or evidence of a live integration.
5. Authorized CI/staging/deployment, volume/all-writer/cross-wallet/multi-host contention and scan/index cost; private storage/key/backup recovery/retention; browser/accessibility/security/pentest and advisory monitoring.
6. Country/operator legal, KYC/AML/tax, liquidity, monitoring and incident-response decisions.

No production migration, merchant/jurisdiction choice, bank transfer or live provider execution was authorized or performed. These boundaries accompany the actual application changes above, not a claim that all remaining work is complete.
