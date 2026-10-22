# Part36 — exact reconciliation arithmetic and original accounting proof

Date: 2026-10-16. Continues Part35's authoritative 470-path source overlay on base commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. Files637–649 contain 13 complete bodies; next File650. The cumulative overlay has 480 unique paths: 467 prior bodies unchanged, 3 revised, 10 first delivered. A first-delivered path may already exist in the base repository. This is a source overlay, not a standalone repository, dependency/database export or production deployment.

## Actual application changes

### Exact journal and report arithmetic

The main reconciliation service no longer compresses accounting amounts into `GROUP_CONCAT` strings. MariaDB truncates those strings at a configurable session limit; the old implementation could therefore publish incorrect ledger totals or classify a balanced transaction as unbalanced. Replacing that limit with a larger limit is not a correctness fix.

`ExactReconciliationReader` now reads the original decimal values as database text and folds them with bcmath. Each transaction's debit/credit check has one running accumulator and count. Report totals and per-wallet liability netting also read exact rows rather than concatenated money or floating SQL aggregates. SQL identifiers accepted by its domain-sum method are explicitly allow-listed. Unknown ledger sides, nonpositive journal amounts and malformed decimal text refuse a passing report rather than silently becoming zero.

Database text projection matters: SQLite's numeric affinity can otherwise deliver fractional DECIMAL values to PHP as floats. The new SQL cast avoids routing those values through a PHP float. This does not upgrade SQLite's underlying storage precision or certify it as a production DECIMAL database. The MariaDB acceptance includes an exact amount above PHP/JavaScript's binary-safe integer range, `9007199254740993.17`, and a 141-entry balanced journal under `group_concat_max_len=64`. No journal uniqueness constraint was removed; the many-debit fixture uses distinct valid debit accounts.

### Correct currency-specific wallet liability lanes

Both the global report and the existing per-wallet reconciliation conversation now resolve the liability account with `LedgerPostingService::accountCodeFor(base, originalCurrency)`. USD/BDT wallets no longer incorrectly look for THB's base account. Soft-deleted/missing currency accounts do not prove a clean zero balance. The global report records a critical wallet/account finding; the per-wallet conversation records `missing-currency-liability-account` drift.

A per-wallet replay now compares the current computed status and drift lines as well as its financial fingerprint before returning an existing unresolved conversation. An unchanged numeric zero fingerprint cannot preserve a stale Matched result after the required chart account disappears. This service still writes its own reconciliation conversation/audit records; it does not change money or repair financial history.

Wallet invariant checks also reject a negative original balance or negative original hold independently of `balance − locked`. Two negative values producing a positive arithmetic remainder are not a valid spendable wallet. Deliberate historical corruption is detected without normalization or automatic hold release.

### Original deposit proof, not labels or a plausible foreign transaction

Every selected Confirmed deposit is now checked with the read-only canonical `DepositCompletionService::verifySettled()` proof. Original terms/owner/wallet/currency, canonical transaction identity, actual balanced journal and immutable provider-reference ownership must agree. A balanced transaction belonging to another deposit, changed terms with an unchanged net, missing ownership, deleted journal, wrong account, or Reversed original transaction cannot establish a passing deposit reconciliation.

Proof failures become critical `DepositAccountingMissing` findings with a controlled-review marker. Raw exception/provider-reference text is not copied into the finding. No audit binds a missing owner, reconstructs a snapshot, changes a deposit, credits a wallet, repairs a journal or resends anything. Deleted Confirmed history remains inspectable and fails current canonical proof rather than disappearing as a clean completion.

Deposit evidence selection uses `confirmed_at` for the requested period; a malformed terminal row without that stamp is selected by its recorded creation timestamp, not assigned a fabricated settlement time. Existing recorded domain totals retain their gross-amount compatibility. Those totals are explicitly labelled `recorded_domain_gross_not_proved_funds`: a recorded gross total is not authenticated arrival, available liquidity or proved money. Journal totals remain separate.

The remaining withdrawal completeness check additionally requires its own live Completed Withdrawal transaction, original domain reference, owner, wallet, currency and exact gross amount. A same-amount foreign or non-completed transaction is not completeness. This is not a new complete withdrawal settlement/replay engine and does not certify all legacy withdrawal writers or all withdrawal journal/reference semantics.

A live ledger entry referencing a soft-deleted transaction is now reported as an orphan; a still-present SQL FK value is not proof that its accounting parent is current.

### Accurate, redacted historical duplicate evidence

Deposit/withdrawal reference duplicate checks now use canonical provider plus stored account scope plus exact reference bytes. Different provider/account namespaces are no longer called duplicates solely because they reuse a local number. A candidate in the requested currency/creation window is compared against historical owners including other currencies, older records and soft deletion. A currency filter or deletion flag cannot erase original ownership.

Known bank/manual aliases share their canonical namespace. Indeterminate provider/account/reference facts refuse a passing report rather than being guessed. Raw provider references are replaced in these findings with a reference hash, non-secret namespace labels, original internal document reference and complete owner IDs. SQL collation does not substitute for exact reference identity.

Duplicate-prize diagnostic ID/reference lists no longer depend on concatenation limits. Null/unassigned bet IDs are not grouped together as if they were one winning bet. These diagnostics do not become a new payout sender or settlement authority.

## The five earlier SQL fixture incompatibilities are resolved

The earlier Part35 supplemental Finance-on-MariaDB run had five fixture errors: two SQLite-only foreign-key PRAGMAs and three deliberately invalid wallet states rejected by MariaDB CHECK constraints. Their detector tests could not reach their intended historical corruption on that driver.

`Tests\Support\InjectsHistoricalAccountingFaults` now provides deliberate test-only corruption scopes. It requires an actual testing environment, the explicitly named disposable `thai_lottery_test` store, no outer transaction, and SQLite or local MariaDB. Only the test connection's appropriate session setting is temporarily changed; its exact previous value is restored in `finally`, including on fixture exceptions. It never changes a schema/migration, drops a constraint, changes a global server setting or adds a production bypass. The helper is not a production recovery tool, application service, API or repair endpoint. These fault fixtures do not claim portability to every MySQL version/server.

The five adversarial fixtures still test the corrupted-history detectors; they are not skipped or replaced with assertions that an attempted insert failed. MariaDB production constraints remain in place. The new tests verify restoration and reject nested/outer-transaction use.

The old direct-reversal fixture now expects the actual contradiction to be reported: FinancialReversalService wrote balanced compensating entries but left the original deposit Confirmed while its original transaction became Reversed. A balanced journal alone must not make that incompatible domain state Pass. The test now requires the critical original-deposit finding; no accounting guard was weakened, and reconciliation does not pretend it completed a missing reversal workflow.

## Acceptance executed locally

| Check | Final result |
|---|---|
| Complete default PHPUnit, four disjoint isolated SQLite shards | 3005 tests / 133033 assertions; zero failures/errors; 56 explicit skips |
| Entire Finance + Payment directories on MariaDB | 587 tests / 2434 assertions PASS; no skips |
| Finance portion of that SQL run | 75 tests / 321 assertions PASS; includes all five previously incompatible corruption fixtures |
| Payment portion of that SQL run | 512 tests / 2113 assertions PASS |
| New original-evidence/currency/arithmetic/fault-scope cases | 17 cases execute on both SQLite and MariaDB, no skip |
| Covered original-reference/payout/evidence/claim SQL | 3 × 14 tests / 262 assertions PASS; 42 direct-parent decisive-index wait witnesses |
| Existing all-race financial SQL | 3 × 27 tests / 600 assertions PASS; 23 owned waits per iteration |
| Focused entire Finance on SQLite | 75 tests / 321 assertions PASS |
| Generated-source static contract | 3 tests / 1058 assertions PASS |
| Offline Python guards | 86 PASS |
| PHP syntax / focused Pint | 1978 PHP-suffixed source/view files / 6 tranche PHP files PASS |
| Rust locked tests / native release build | 27 tests PASS / PASS |
| Frontend locked install / release build | PASS / PASS |
| Source preflight / generated inventory | PASS, zero missing imported application classes / current source hashes PASS |

Matrices overlap; they are not unique-coverage totals. Explicit skipped server/recovery profiles are not passes. Final default acceptance uses four disjoint source-file shards with separately owned disposable file-backed SQLite stores. Discovery identity/multiplicity is checked against the complete PHPUnit listing: no test filter or omitted test class is used. SQL Finance and Payment use their own disposable MariaDB store, separate from every SQLite shard.

Development corrections are not hidden: SQLite raw DECIMAL reads initially reached PHP as floats, so exact database text projection was implemented; an early test referred to the wrong revenue-account constant; the first many-entry fixture correctly hit the existing transaction/account/side uniqueness constraint, and now uses distinct debit accounts. The earlier reversal Pass expectation was corrected because it masked an actual contradictory domain state. Final acceptance was rerun on the final frozen source. Dependencies and native/frontend assets were rebuilt locally; generated artifacts are not included in the source overlay.

`evidence/runtime-part-36.txt` contains exact final logs, JUnit/skip summaries, complete shard/discovery evidence, source hashes and SQL race witnesses. `evidence/server-races-part-36.json` retains covered original-reference/payout/evidence/claim waits plus the existing financial contention profile. Passing offline inventory/preflight checks do not by themselves establish runtime correctness.

## Operator use and important limits

- Existing reconciliation entry points remain the same. No Part36 production migration or permission reseeding is required. All earlier financial ownership migrations and coordinated writer deployment still apply. This sandbox migrated only disposable test databases.
- A report may append its own audit record. The per-wallet reconciliation service may maintain its own conversation records. Neither updates balances, ledger entries, claims, provider ownership, payout status or external money. A Critical/Warning result authorizes investigation, not automatic repair, resend, reset, takeover, cancellation or hold release.
- This is an **observational multi-query report, not an atomic repeatable-read database snapshot**. Concurrent committed activity can cross query boundaries. Metadata explicitly states that limitation. Use a separately controlled stable reporting cut/snapshot and independent original evidence for consequential reconciliation. No new money gate may rely on this report alone.
- Gross domain totals are recorded bookkeeping sums, not proved bank funds. Audits can display a recorded gross total while simultaneously holding its contradictory original proof. Ledger side totals are independent exact sums, never a cross-currency net. Complete original deposit proof is local accounting proof, not provider authentication.
- Reference duplicate diagnostics cover deposit/withdrawal recorded provider-reference lanes; they are not a new complete cross-domain provider audit. Covered financial writers still use the conservative shared inbound/outbound reference registry. Unmanaged metadata writers, missing provider/account semantics and cross-direction false-positive holds still need controlled review.
- Row-wise arithmetic removes concatenation truncation; it is not deployment-scale certification. PDO may buffer cursors. Reports retain their finding lists, some existing scans/materializations remain, and historical duplicate checks can query per identity. Query/index/memory and long-history behavior require deployment-volume review before large production schedules.

## Remaining work — not a whole-platform 100% claim

1. Approved provider-specific authenticated incoming/final-result/refund adapters and authorized live acceptance remain. No provider/merchant/credentials/jurisdiction/live transfer was selected or authorized. Typed references, human uploads and synthetic callbacks are not bank authenticity.
2. Other unmanaged legacy prize, withdrawal, reversal, cancellation and reporting writers still need their own canonical original-proof audits. In particular, a direct journal reversal does not itself reconcile its original domain obligation. This tranche detects the contradiction rather than inventing a repair policy or refund protocol.
3. Historical reconciliation still requires independently established original evidence and an approved dual-control migration procedure. Missing/corrupt terminal proofs remain held, never auto-backfilled from labels. An approved real-provider namespace contract is needed before weakening conservative reference/account holds.
4. Controlled stable reporting snapshots, deployment-volume/query/index/memory review, production rollout, multi-host/private storage, key restore/rotation, legal retention, operator/country/KYC/AML/tax/liquidity approval, monitoring and hosted CI remain. Redis/worker-restart/SIGKILL recovery, multi-host operation and production deployment were not rerun or certified in Part36. Earlier unrepeated profiles remain historical.

Continue from File650 with the cumulative 480-path source overlay. Do not restore older 406/419/448/470-path archives over Part36. No live-money, worldwide legality or whole-platform 100-percent certification is claimed.
