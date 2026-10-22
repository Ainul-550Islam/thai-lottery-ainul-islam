# Part38 — shared wallet mutation floor for owned withdrawal money

Date: 2026-10-16. Sequential continuation starts File673. This tranche changes real shared wallet mechanics and the original withdrawal settlement/release unit. It is not a test-only delivery or permission to operate a live payment system. Part37 remains authoritative except where explicitly strengthened below.

## Implemented application changes

1. `WithdrawalHoldFloor` calculates the exact row-wise sum of active durable withdrawal reservations for one actually locked wallet. It validates beneficiary user, currency, positive exact decimal amount and active-record shape. No SQL floating SUM, concatenation, request-supplied minimum, caller-supplied exclusion or inferred historical owner is used.
2. Shared `WalletService::lockFunds` checks the **existing** floor before adding a new hold. A missing withdrawal reservation aggregate cannot be disguised by reserving another obligation's funds. `unlockFunds`, its alias and covered WalletHoldService release/releaseIfHeld/consume callers cannot reduce locked money below the remaining active owned withdrawal sum. Excess above that floor can still be released through the existing generic mechanics; this does not authenticate which generic obligation owns that excess.
3. Shared balance credit, debit and reversal application re-read the actual wallet under the row lock instead of trusting stale model balance/status/owner attributes, and refuse an already inconsistent withdrawal floor before changing balances or running totals. A normal debit can still spend only genuinely available balance while the protected amount stays locked. A credit is not a repair capability for a pre-existing coverage deficit. Whole-wallet freeze/unfreeze remain available for investigation; they are not money movements.
4. Floor reads are **locking/current reads** after the wallet lock. An ambient InnoDB REPEATABLE READ snapshot may have been established before another transaction approved and reserved a withdrawal. A plain snapshot read can then hide the new owner despite a fresh wallet lock; the shared floor must not use that stale read view. Active owner reads and canonical debit-collision checks in the coordinated withdrawal owner service also use current reads inside transactions; out-of-transaction terminal proof remains observational/read-only.
5. Original withdrawal settlement no longer exposes separate consume-only and mark-consumed-only methods. One owned savepoint verifies the active owner, refuses an existing canonical debit, closes this owner inside SQL, unlocks only its gross amount, posts the deterministic canonical debit, links the consumed owner and checks original balanced accounting proof. No generic caller receives a release exemption. The enclosing completion transaction still owns the withdrawal's Completed link/timestamp and rolls back the whole action on failure.
6. A verified owned release similarly closes **only its own** reservation before shared mechanics check the remaining protected floor. Closing, unlocking and any refusal share a savepoint. The transient closed-without-transaction link during settlement is never committed or visible outside its enclosing SQL transaction. If debit eligibility or post-posting proof fails, owner state, closed timestamp, unlocked money, debit, ledger and counters roll back together even if an ambient caller catches the exception and commits other work.
7. Ordinary generic wallet reservation release/consume/expiry and generic financial hold release/conversion/expiry now reach the central floor through their existing shared wallet mechanics. A stale legacy record cannot release an active withdrawal's money merely because the wallet has enough aggregate locked balance. Its attempted lifecycle mutation rolls back on the central refusal. Unknown outbound claims, their non-expiring owned reservations, canonical original proof, independent evidence and existing authorization gates remain intact.

## Executed acceptance

| Check | Actual final result |
|---|---|
| Complete default PHPUnit, four owned SQLite shards, exact discovery match | 3091 tests / 133481 assertions; zero failures/errors; 63 explicit skips |
| Entire Finance + Payment on MariaDB | 673 tests / 2998 assertions PASS; no skips |
| SQL Finance portion | 75 tests / 321 assertions PASS |
| SQL Payment portion | 598 tests / 2677 assertions PASS |
| New functional floor/rollback/stale-wallet cases, both SQLite and MariaDB | 38 tests / 186 assertions PASS on each; included in complete matrices |
| New dedicated native SQL floor/current-read profiles | 3 × 7 tests / 154 assertions PASS; 21 direct-parent wallet waits, including 3 paired-old-snapshot profiles |
| Narrow stale-read mutation control | Expected failure observed when only locking read is removed; original source restored byte-for-byte before final acceptance |
| Covered original-reference/payout/evidence/claim SQL | 3 × 14 tests / 262 assertions PASS; 42 direct-parent decisive-index waits |
| Existing all-race financial SQL | 3 × 27 tests / 600 assertions PASS; 69 direct-parent waits |
| Entire three generated-source static contract files | 15 tests / 1399 assertions PASS |
| Offline Python guards | 86 PASS |
| PHP syntax / focused Pint | 1986 PHP-suffixed source/view files / 8 tranche PHP files PASS |
| Locked Rust tests / native release build | 27 tests PASS / PASS |
| Locked frontend dependency install / release build | PASS / PASS |
| Source preflight / generated inventory | PASS, zero missing imported application classes / current generated source hashes PASS |

Overlapping matrices are not additive coverage totals. Explicit default-suite skips are not passes; the new seven row-lock profiles execute without skips on the acknowledged local SQL server. The expected-failure mutation control is reported separately from final acceptance, not presented as a successful normal test run.

## Decisive SQL snapshot evidence

The test helper starts two independent child transactions and records that each established an empty active-owner read view **before** the parent approves the withdrawal. The parent then takes the real wallet lock, approves/reserves the request inside that owned transaction, starts both contenders, verifies both actual child InnoDB connections waiting directly on its lock, and commits the new owner before allowing either contender to continue. Both generic unlock attempts must refuse with `wallet_withdrawal_floor_conflict`, leaving the original hold and wallet balance unchanged.

MariaDB 11.8 can independently abort a lock acquisition when its optional `innodb_snapshot_isolation` mode detects a stale snapshot. That is safe refusal, but would not exercise the classic MySQL REPEATABLE READ stale-owner path. **Only** this explicitly selected synthetic child/session temporarily disables that MariaDB-specific mode before starting its test transaction, then restores the saved session value in `finally`. The guarded probe requires testing environment, the exact disposable database, an absolute local Unix socket, safe input paths and uncached test configuration. It changes no global SQL mode, production configuration, constraint or application isolation policy. The paired-child empty-owner markers and expected refusal codes distinguish current-read floor proof from an unrelated concurrency abort.

A negative control temporarily removes only the floor's locking/current-read clause, runs only that regression on the disposable server and verifies an expected assertion failure exposing a generic unlock of newly protected money. The original source is restored byte-for-byte in `finally` **before** final acceptance and source hashing. The negative-control JSON records the original/mutant hashes, actual nonzero result and failure output. No mutant code is shipped as application source.

## Rollout and limitations

- No new migration or ownership backfill is introduced in Part38. The Part37 non-expiring reservation migration is a prerequisite. Do not skip it or run mixed old/new money writers during an authorized production rollout. No production migration, release, historical wallet adjustment or live transfer was executed here.
- Existing coverage deficits, malformed active owners and historical missing ownership remain independent controlled-review cases. Do not add an unrelated generic hold, incoming credit, inferred owner, custom-key debit, status label or reference string to make the guard pass. Restore/reconcile original facts through an authorized procedure, not an automatic balance setter.
- The protected minimum covers **active durable withdrawal reservations**. It does not prove the ownership or aggregate sufficiency of all generic WalletReservation/FinancialHold records, tax/prize obligations or legacy direct SQL writers. If two generic obligations share unproved excess above the withdrawal floor, this change does not manufacture per-generic-obligation ownership. Those writers still need controlled integration or isolation.
- The guarantees apply to mutations routed through the modified shared wallet mechanics and coordinated original withdrawal services. Arbitrary privileged SQL, unmanaged legacy direct balance/locked-balance writes, data tampering by a database administrator and external side effects outside these SQL boundaries are not made safe by a PHP floor guard. Fingerprints remain integrity checks, not bank or administrator signatures.
- Locking/current reads can add contention and require existing outer bounded retry/refusal handling. Per-wallet scans, next-key locks, high-volume approval throughput, buffering and multi-host behavior are not certified at deployment scale. SQLite has no equivalent InnoDB row-lock proof; its functional tests do not substitute for the native server races.
- New withdrawal requests, original gross/fee/net/destination snapshots, canonical debit/journal proof, completed replay and original Payment/browser proof from Part37 remain. Human evidence, a local/manual no-Payment/no-reference accounting completion or a Captured label alone is not authenticated external delivery.
- Internal low-level mutation methods still require trusted authorized caller boundaries and balanced finance-engine use; testing a low-level re-read is not authority to expose raw credit/debit/reversal operations as public endpoints. This tranche does not add a provider refund, reversal-policy or live-transfer API.

## Remaining substantive work

1. Provider-authenticated adapters and independently authorized acceptance for selected merchants, jurisdictions, methods and currencies. Unknown payout outcomes remain non-resendable and non-releasable without trustworthy final facts. No provider, merchant or jurisdiction was selected and no live transfer was authorized.
2. Remaining unmanaged legacy prize/reversal/cancellation/reporting/hold writers, canonical provenance and granular permissions. Generic hold ownership and non-withdrawal aggregate liabilities still need their own durable proof. A normalized reversal/refund word is not an authorized original-transaction compensation workflow.
3. Provider incoming-fee/refund policy and controlled independent historical reconciliation/migration. Missing original snapshots, ownership, transaction mappings, references or signatures must not be guessed or backfilled to obtain a green status. Part36 reports remain observational, not atomic snapshots or repair authority.
4. Production staging/rollout/restore, deployment-volume contention and index/scan acceptance, multi-host evidence storage, encryption-key restoration, lawful retention and operator authorization. Older Redis/queue-kill/recovery evidence stays historical unless explicitly rerun; no new recovery or multi-host certification is claimed.
5. Country/operator legal, tax, KYC/AML, liquidity, monitoring, incident response and CI acceptance. Multi-country architecture means explicit configuration and fail-closed unsupported operations, not worldwide permission to operate.

The full sequential source and cumulative overlay accompany this scope document. The overlay requires the identified base repository and locked dependencies; it contains no credentials, database/customer exports, private evidence, installed dependencies or compiled builds, and is not a standalone deployment approval.
