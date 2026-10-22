# Remaining work after Part49 — Files925–941

## Actual application work completed

New risk cannot be accepted from an obsolete Open label, expired betting window, old validated purchase context or stale REPEATABLE READ snapshot. Supported engine single/batch, facade and ordinary paid-purchase reservation paths now take and revalidate the current draw BEFORE number-limit locks. Durable cancellation ownership in any state and payout/settlement histories block new risk even after an incorrect mutable reopen. The preview configuration switch is not write permission.

Single and batch risk reserve/release operations now own savepoints, without owning or committing the caller's transaction. A late real SQL counter failure, later batch ceiling refusal or later owned-floor release refusal rolls back the whole operation even if the caller catches it and commits unrelated work. Existing original risk floors remain intact and releases remain possible after sales close. Keyed generic risk mutation batches reject canonical duplicates instead of mutating several times while overwriting a report entry. Separate identical ordinary wagers remain distinct purchases.

The Part48 draw coordinator now contends on the same draw row as these supported reservation paths: cancellation wins and new risk refuses, or anonymous risk commits first and the whole cancellation holds without partial member refunds. Original purchase replay is still historical funding only, not new risk admission. Full protocol and boundaries: `docs/draw-risk-admission-protocol-part-49.md`.

## Accepted final-source local evidence

| Matrix | Actual result |
|---|---|
| Entire default discovery, four isolated disjoint SQLite stores |3623 tests;136797 assertions;122 explicit skips; zero failures/errors; exact discovered-ID equality |
| Complete selected native InnoDB matrix |500 tests;3091 assertions; zero skips/failures/errors |
| Simultaneous SQL contention |23 actual two-child lock witnesses: six new risk/capacity/draw-cancellation cases plus17 retained financial cases |
| Current-read proof |Nine new independent-session stale-snapshot cases; ordinary reads demonstrably stay old while mutation refuses from current locked admission |
| Isolated omission controls |Four intended assertion failures; zero errors; admission/single-savepoint/batch-savepoint/release-savepoint omissions each detected; real application sources never modified by controls |
| Static / additional standalone / Python |35 tests/1498 assertions;29 tests/94 assertions;86 Python tests passed |
| PHP syntax / style |2083 repository PHP-suffixed files linted; six changed PHP files pass Pint |
| Exact frontend pin |Node24.22.0/npm11.21.1; clean install, complete-lock known-advisory gate,54 contracts and genuine Vite build;109 built files unchanged through PHP acceptance |
| Composer |Full locked audit: no advisories or abandoned packages |
| Native verifier |Actual Rust1.85.1 locked release build and27 tests passed |

Matrices overlap; do not sum them as unique coverage. The selected native run executes54 of122 default environment/native skips;68 other skips are not executed by this profile. Preliminary/focused/interrupted runs are diagnostics only. The final complete matrices execute the frozen six-path application/test/probe source. No financial proof, floor, integrity constraint, witness assertion or skip was weakened.

## Deployment and retention

Part49 adds no migration/backfill. Apply the existing Part48 ownership migration before deploying this writer; missing required schema fails rather than opening an unsafe fallback. New custom callers must acquire wallets before the draw and the draw before limits. The supported paths share lifecycle admission, but every raw/admin/aggregate/reconciliation writer and legacy settlement's global order are not thereby integrated. Throughput, large-batch and multi-host/index costs require separate acceptance.

This is a644-path source overlay on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export or deployment approval.627 prior paths are unchanged, eight revised and nine first supplied in full. The first-supplied risk facade existed in the base. Next File942.

## Remaining implementation and independent authorizations

1. Legacy/unbound accrual/referral and aggregate/reconciliation writers; broader current actor/maker-checker ingress and completed-draw economics; all-writer country/operator integration and global lock-order unification.
2. Durable bulk/permutation whole-intent ownership, original accounting-backed reports and safe unknown-outcome handling. Anonymous risk is not durably request-owned by this release; never retry an unknown non-idempotent reservation from a label/total. Large-draw/multi-host cancellation, full settlement/prize/reversal/reporting and unrelated monetary replay still need integration.
3. Independently authorized historical reconciliation, missing snapshots, policy capture/migration and unknown-outcome resolution. Do not infer ownership/enrollment or resend an unknown monetary attempt.
4. Actual country/operator/provider/merchant choice, authenticated adapters, fee/refund/dispute economics and authorized real acceptance. Multi-currency local mechanics are not worldwide operating permission or live provider evidence.
5. Authorized CI/staging/deployment; volume/all-writer/cross-wallet/multi-host contention and scan/index costs; private storage/key/backup/retention; real-browser/accessibility/security/pentest and advisory monitoring.
6. Country/operator legal, KYC/AML/tax, liquidity, monitoring and incident-response decisions.

No production migration, live bank/provider transfer, merchant/jurisdiction choice or worldwide authorization was performed. This scoped application fix is complete and locally accepted; the platform is not represented as globally100% complete.
