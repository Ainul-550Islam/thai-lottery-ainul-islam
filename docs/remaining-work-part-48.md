# Remaining work after Part48 — Files903–924

## Actual application work completed

Both existing operator cancel-draw actions now refund all supported original owned member bets and close their associated original commission obligations in one root transaction before the draw is marked Cancelled. Current explicit staff authority, original wallet-first membership revalidation, private fresh-only dispatch, exact original/refund journals and immutable ordered terminal proof replace the former lifecycle-only status flip. Previously player/amendment-cancelled originals are proved, not refunded twice. Closed unpublished draws may be refunded by this operator capability without weakening the player's window.

Every current draw number-limit stake/exposure counter must close exactly to zero. Anonymous residual risk, unowned/hidden bet history, settlement/prize history, capacity excess, insufficient funds or unapproved/unprovable paid-agent recovery refuses the entire operation. No aggregate counter is swept and no historical funding/policy is invented. Durable incomplete owners never grant resend or resume authority. New-dispatch capacity defaults to100 bets, configurable only within the protocol ceiling1000; this is deliberately not chunked partial cancellation.

The full algorithm, current proof and rollout boundaries are in `docs/owned-draw-cancellation-protocol-part-48.md`. Existing Part46 owned amendment and Part47 original-purchase replay mechanics retain their separate authorities.

## Accepted final-source local evidence

| Matrix | Actual result |
|---|---|
| Entire default PHP discovery, four isolated disjoint SQLite stores | 3561 tests; 136531 assertions;107 explicit skips; zero failures/errors; exact discovered-ID equality |
| Selected complete native InnoDB matrix, including both operator actions | 438 tests;2559 assertions; zero skips/failures/errors |
| Simultaneous SQL contention | Seventeen actual two-child lock witnesses; six new draw/coordinator/player/cross-wallet-purchase cases plus eleven retained financial races |
| Isolated omission controls | Four intended assertion failures; zero errors; original journal/header/lifecycle/atomic-root omissions each detected; actual application source never modified by controls |
| Static / additional standalone / Python |35 tests/1490 assertions;29 tests/94 assertions;86 Python tests passed |
| PHP syntax / style |2080 repository PHP-suffixed files linted;11 changed PHP files pass Pint |
| Exact frontend pin |Node24.22.0/npm11.21.1; clean install, complete-lock known-advisory gate,54 contracts, genuine Vite build;109 accepted assets unchanged through PHP execution |
| Composer |Full locked audit: no advisories or abandoned packages |
| Native verifier |Actual Rust1.85.1 locked release build and27 tests passed |

Matrices overlap and must not be summed as unique coverage. Native execution covers39 of107 default environment/native skips;68 other skips were not executed by this selected matrix. Preliminary/interrupted runs and initial fixture/selector diagnostics are not acceptance. The final complete matrices execute the frozen source, without relaxing financial proofs, native witness requirements or integrity constraints.

## Retention and rollout

Apply the additive draw-owner migration through authorized deployment before enabling this writer version. Preserve original ownerships, financial keys/journals, source snapshots and terminal seals. Populated owner-table erasure refuses; there is no automatic history backfill, expiry, reset or unknown-outcome resend. Stop unsupported generic reservation/legacy writers during rollout pending separate all-writer acceptance: current zero-risk closure is not a global lifecycle barrier for those writers. Legacy settlement's draw-first order has not been globally rewritten.

This is a source overlay of635 complete paths on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export or deployment approval.613 prior paths are unchanged,10 revised and12 first supplied in full; the first-supplied lifecycle file existed in the base. Next File925.

## Remaining implementation and independent authorizations

1. Legacy/unbound accrual/referral, aggregate/generic writers, broader actor/maker-checker ingress and completed-draw policy; all-writer country/operator integration and lock-order unification.
2. Durable bulk/permutation whole-intent ownership and safe unknown-outcome handling; unmanaged risk ingress; large-draw/multi-host cancellation; full settlement/prize/reversal/reporting and other replay integration. This release implements bounded owned member draw cancellation, not all historical obligations or all money writers.
3. Independently authorized historical reconciliation, missing snapshots, operator policy capture/migration and unknown-outcome resolution. Never infer enrollment or original payment facts from mutable labels/balances, or resend an unknown monetary attempt.
4. Actual country/operator/provider/merchant choice, authenticated adapters, fee/refund/dispute economics and authorized real acceptance. THB/BDT/USD local mechanics do not grant worldwide permission or prove a live provider integration.
5. Authorized CI/staging/deployment; volume/all-writer/cross-wallet/multi-host contention and scan/index costs; private storage/key/backup recovery/retention; real-browser/accessibility/security/pentest and advisory monitoring.
6. Country/operator legal, KYC/AML/tax, liquidity, monitoring and incident-response decisions.

No production migration, bank transfer, merchant/jurisdiction choice or live provider execution was authorized or performed. The scoped application work above is complete and locally tested; the platform is not represented as globally100% complete.
