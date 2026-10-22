# Financial parity — installment 8: legacy GLO writer quarantine and enterprise contracts

Date: 2026-10-14 (Asia/Dhaka). Base commit: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

## Full-file delivery manifest

This installment continues after File78 and supplies **File79–File86** completely. All eight paths are newly delivered paths, although the application engine and the two legacy master-test files already existed upstream. The previous 75 delivered paths remain byte-for-byte unchanged. The current combined source overlay contains **83 unique repository paths** and there have been **86 sequential delivery labels**; the difference is the three revisions in installment 7. Next delivery label: **File87**.

| File | Repository-relative path |
|---|---|
| 79 | `app/Services/Lottery/GloL6AuthoritativeTicketEngineService.php` |
| 80 | `tests/Feature/FinalWholeSystemNoSkipTest.php` |
| 81 | `tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php` |
| 82 | `tests/Feature/Finance/LegacyGloSettlementBoundaryTest.php` |
| 83 | `tests/Feature/Payment/EnterprisePaymentBoundaryTest.php` |
| 84 | `evidence/enterprise-contract-validation-part-8.txt` |
| 85 | `evidence/financial_source_preflight-part-8.json` |
| 86 | `docs/financial-parity-part-8.md` |

The ZIP is the current delivered-source overlay, not a full repository, dependency bundle or deployable production release. Every supplied file is included in full. Historical earlier artifacts remain snapshots; this report does not silently rewrite their results.

## Environment recovery and genuine baseline

The workspace snapshot had retained some dependency stubs but not the actual PHPUnit package. Some previously delivered untracked source files were also absent. Dependencies were restored from the existing Composer/npm lockfiles and all 75 delivered paths were restored from the verified installment-7 ZIP. A build attempted before source restoration failed; the final build after restoration passed. Those environment recovery failures are not presented as application-contract baselines.

After restoration and before source edits, the two additional master suites produced **23 tests / 24 assertions / 15 errors**. The complete real baseline is included in File84.

Their failures included obsolete typed wallet calls, unsupported named arguments, absent calculator/bulk/self-exclusion methods, missing receiving wallets for agent commission fixtures, a false expectation that bKash executes a THB deposit, raw claim/withdrawal rows pretending to be reviewed authority, and direct mass assignment of server-owned user KYC standing.

## Critical alternate-writer finding

The old `GloL6AuthoritativeTicketEngineService::settleVerifiedClaim()` was a separate direct wallet-credit path. It accepted a stored Approved claim, checked selected age/KYC/window/freeze conditions, computed a prize and credited the wallet. It did **not** establish the canonical winner/result/finality/payment-hold/legal-conflict/payment-permission contract. Its Paid branch also returned a row as a replay without proving that this writer had reconciled the original financial event.

This was not merely a stale fixture problem. It was verified in a controlled disposable database:

- An adult claimant had test KYC evidence.
- The draw was Open, with no result ingestion or publication.
- A local half-sold L6 sales fixture and a deliberately raw Approved claim were inserted.
- The alternate writer credited **995.00 THB** and marked the claim Paid without a verified winning result.
- The operator was an ordinary test user, not a separately authorized payment operator.

The regression baseline explicitly reports `Balance: 995.00`. An earlier larger-prize probe hit the independent wallet maximum-balance guard; its complete log is also included. The smaller-prize reproduction demonstrates why that balance cap was not a sufficient protection. No real merchant, external result endpoint, real identity or production wallet was involved. This finding is an internal money-authority gap, **not proof of a remotely exploitable public endpoint**.

Application-source inspection found no controller/job/service invocation of `settleVerifiedClaim()` other than its definition. It was invoked by the legacy enterprise test. That inspection does not establish that no external downstream integration ever uses it.

## Fail-closed quarantine, not a new automatic payout

File79 retires the alternate method while retaining its public signature for explicit refusal. Every call now throws the stable financial error:

`glo_l6_direct_wallet_settlement_disabled`

It does so before reading or changing claims, wallets, financial transactions, ledgers or audits. This includes Approved, Pending, Eligible, Paid and unpersisted claim inputs. Existing balances and historical rows are not deleted, reversed, backfilled, relabeled or guessed into a new workflow.

The former direct-credit body is removed, not replaced by another money writer. There is no configuration flag to reactivate it and no automatic fallback. The canonical reviewed `GloPrizeClaimService` remains unchanged. Merely delegating to its `pay()` method would falsely suggest that a claim Paid marker also posts a wallet credit; this installment deliberately does not do that.

Local series bookkeeping, ticket purchases and proportional arithmetic retain their previous implementations. The class documentation now describes them as internal product contracts, not independent certification of official inventory or licensed operation. The historical constants/constructor signature remain intact.

**Compatibility consequence:** an external consumer that called the retired direct settlement method, including a Paid replay, now receives an explicit refusal. Such a consumer must be audited rather than migrated to a guessed payout path. This is a source hardening change; no production deployment or live payout activation was performed.

## Repaired master tests and honest scope

The two legacy master filenames are retained, but method names/comments now describe the narrower contracts actually tested. They are not end-to-end tests of all modules, all 25 audit pillars, all supported products or live payments.

The WholeSystem fixture now tests:

1. User factory and empty canonical wallet initialization, not HTTP registration.
2. A requested manual deposit, distinct reviewer approval, canonical accounting completion and replay without another credit. Test evidence is not a real bank receipt.
3. Real close/result-pending lifecycle services instead of a raw status setter.
4. Valid canonical bulk markets, open windows, number limits, exact stakes and replay without another transaction.
5. Proportional prize arithmetic with no payment authority.
6. An approved withdrawal hold and canonical accounting completion/replay. Completion is bookkeeping; it does not send or verify a bank payout.
7. An accrued commission fixture with an existing receiving wallet, canonical settlement and replay. Referral attribution is not exercised.
8. Explicit self-exclusion request followed by activation; a request is not silently assumed Active.
9. Real private-storage upload and distinct KYC review through the service, rather than forced user KYC status.
10. Local health contract, not production queue or merchant health.

The Enterprise fixture tests local L6 ticket pricing/debit/replay, proportional math plus refusal of the retired writer, THB refusal on the BDT bKash rail, actual balanced journal entries with BCMath totals, local health, canonical bulk replay, currency separation, exclusion activation, internal confirmed provenance classification, a nonfinancial National history model, private KYC review, genuine claim submit/review/approve transitions and an explicitly empty-system reconciliation.

The old agent/reconciliation case is now labeled empty-system reconciliation; agent accrual settlement remains covered in the WholeSystem test. The National model case does not certify a multi-lane import pipeline. The old fake official source-state assignment is replaced with actual maker/checker confirmation and the public classifier's `INTERNAL_RECONCILED` result. No fixture is labeled `OFFICIAL_SOURCE_VERIFIED` merely to satisfy an assertion.

PNG uploads use small actual embedded bytes with `createWithContent`; no GD-dependent fake image generator or MIME-only placeholder is required. Storage remains a local test fake, not proof of encrypted physical identity storage or document authenticity. The service's distinct-review rule is also checked negatively.

## Nine new boundary cases

File82 contributes five executed cases: four stored claim-state variants and an unpersisted claim. They require the exact disabled-writer error and unchanged claim/financial/ledger/audit state; the unpersisted case also checks no wallet creation. The corrupted/raw claim setup is deliberate adversarial test data, not the legitimate approval workflow.

File83 contributes four cases:

- Manual bank-transfer facade initiation/replay creates one pending intent, has no redirect and credits nothing. The test explicitly enables the method only in in-memory test configuration with a clearly non-payable test account; production configuration is unchanged.
- The real manual bank driver returns a pending withdrawal response. The facade must leave the withdrawal Processing, retain its hold, and neither mark it Completed nor debit the wallet.
- A claimant cannot approve their own private KYC evidence.
- A registered driver cannot bypass the deposit method allowlist to create an intent.

These tests prevent stray HTTP requests and do not contact a live merchant. Test checkout/hold semantics do not constitute authorization to move live money.

## Final verification and remaining failure

All application tests used disposable SQLite `database/thai_lottery_test`. Complete selected command output, including failures, is in File84.

| Check | Observed result |
|---|---|
| Original restored two-master baseline | 23 tests / 24 assertions; 15 errors |
| Original alternate-writer adversarial baseline, smaller prize | 4 cases; 2 errors / 2 failures; actual 995.00 credit observed |
| Repaired two-master suites | **23 tests / 106 assertions; pass** |
| All five named master suites together | **58 tests / 321 assertions; pass** |
| Nine new boundary cases together | **9 tests / 49 assertions; pass** |
| Payment + Finance, including new boundaries | **151 tests / 670 assertions; pass** |
| Canonical GLO claim + L6 proportional calculator | **31 tests / 100 assertions; pass** |
| Settlement + Draw | **155 tests / 1706 assertions; zero errors/failures; one skip** |
| Wallet conversion feature + unit | **71 tests / 259 assertions; pass** |
| Isolated conversion foundation | **29 tests / 92 assertions; pass** |
| Full BetPurchaseAtomicity + NumberLimitProvisioning run | **57 tests / 220 assertions; one failure** |
| Separate non-concurrent Betting/Risk subset | **54 tests / 211 assertions; pass; three concurrent cases excluded by an explicit command filter** |
| Syntax / scoped Pint | **All five supplied PHP files pass** |
| Source scanner | **11 tests pass** |
| Offline source preflight | **Pass; zero missing imported App classes** |
| Final Vite build / repository whitespace | **Pass** |

Several matrix rows overlap; do not add them as a unique-test count.

The full Betting/Risk failure is specifically `ab2_replays_rather_than_duplicates_two_concurrent_identical_requests`: one SQLite worker purchased, while the other received `database is locked` while updating `number_limits`. The test expected zero refused outcomes. Its assertions and application purchase code were **not changed**. The narrower passing run excludes all three concurrent cases by an explicit filter and is not a replacement for the failing full run. No repeat-to-green result is used to conceal this failure.

The earlier installment's passing 57-case result remains historical; this installment observes a failure and does not claim the concurrent replay guarantee is verified. Cause attribution to these changes is not established. SQLite busy/refusal handling and the real server-SQL protocol require separate investigation. The existing Settlement SQLite row-lock concurrency skip also remains; it is not a passed concurrency test.

## Apply and operational limitations

Apply this source overlay to the stated upstream base with the earlier delivered work. Use the original lockfiles to restore dependencies and rebuild assets. Keep database-truncating tests restricted to a dedicated disposable test database. The archive excludes `.env`, secrets, dependencies, generated assets, uploaded evidence and the test database.

This installment is **not repository-wide or production-readiness certification**. It fixes the named legacy test contracts and quarantines one alternate writer; other unrun modules, actual merchants, licensing, authenticated browsers, real identities, official result feeds, MySQL/PostgreSQL locks, queue workers, crash recovery and deployment remain unverified. No live payment, payout, production migration, push or deployment was performed.

The prior settlement constraints remain: incompatible queued jobs must be drained/quarantined, commit-before-dispatch has no transactional outbox, historical unpinned incomplete runs fail closed, and authoritative finalization does not mean every wallet has been paid. No result guard was weakened, no draw was blindly finalized and no historical payout was guessed.

Own-wallet Cash↔Win remains distinct from member transfer. Legacy member transfer remains fail-closed; this installment does not activate merchants, add compatibility transfer endpoints, copy government-affiliation claims or change reference-site fees. Merchant logos are still not integrations.

Static preflight deliberately retains `runtime_verified=false` and `live_payment_verified=false`. Scoped runtime evidence is separate. The remaining observed concurrency failure means this work must not be described as an entirely green regression matrix or permission to activate payouts.
