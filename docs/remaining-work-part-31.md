# Part31 application acceptance and remaining work

Date: 2026-10-15. Continuation begins at File473; this release supplies 32 complete bodies through File504 (30 bodies in Part31 and two accompanying report bodies in Part32). Next: File505. The cumulative ZIP is a delivered-source overlay on base commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency bundle or database export.

## Application functionality delivered

1. Private maker/checker intake at `/admin/payout-approvals`, linked to the existing external final-result console. Maker requests take a persisted payout ID; server SQL supplies amount, currency, owner and deterministic request identity. A separate current authorized checker must attest to original facts before approval/rejection. Destination facts are immutable after the request. Neither requesting nor approving moves money.
2. Immutable SQL `payout_batch_members`: one original batch per payout, restricted foreign keys, original amount/currency/method/fingerprint, homogeneous method/currency and exact server-derived aggregate. Historical metadata is not membership authority. No automatic migration backfill or historical approval is invented. Populated rollback refuses destructive loss.
3. Atomic dispatch: the original non-expiring batch claim and durable `jobs` insertion use the same SQL connection/transaction. Queue insertion failure rolls back the claim; duplicate route claims do not enqueue again. Wallet and external jobs are selected from the original verified method, not a browser parameter. No after-commit enqueue gap is introduced.
4. Canonical wallet payout execution uses wallet-before-payout locking, original owner/currency/wallet identity, current independent maker/checker authority and actual batch membership. It rejects external Payments/transfer records. A completed replay does not credit again. Posting, approval completion and payout completion occur atomically through the financial services. Synthetic THB, USD and BDT wallet cases are exercised; no FX or cross-currency credit is inferred.
5. Bank/cheque execution opens only private transfer/Payment records. It does not call a bank/provider or credit a wallet. Independent final-result review remains in `/admin/payout-transfers`; completed external accounting debits prize expense and credits system cash, not player liability. Human attestation is not authenticated provider evidence or proof of funds reaching a bank.
6. Worker exceptions/uncertain work stay held rather than being stamped final Failed. No claim expiry, automatic reset, takeover, lane reassignment or uncertain resend action is added.
7. Batch counts/amounts are derived from actual members and canonical terminal evidence, not caller counters. Completed projections verify transaction type, status, reference, owner, amount, currency, wallet/external lane, balanced exact ledger amount/account pair and external Payment/reference ownership. A balanced but wrong-value ledger or deleted external Payment cannot be counted paid. Failed projections require coherent terminal refusal evidence. Settlement requires every original member terminal.
8. Legacy status-only prize disbursement journals cannot reserve, complete, reverse or pronounce failure on approval/batch/transfer-managed payouts. Existing legacy reservations block fresh intake and new batch assignment. This prevents the new canonical workflow from being bypassed; it does not certify or convert all unmanaged legacy journals.
9. Generator integration now selects bounded approved unassigned obligations, groups by exact currency/method, creates actual batches and routes them. The production dispatch contract no longer incorrectly labels the now-wired external job deferred. Generic request/decision audit descriptions use note hashes, not destination text. Private destination reads are separately authorized/audited; projections omit destination details and sensitive old input is not flashed.

## Operator usage

Use the existing session-authenticated admin middleware, CSRF and admin throttle. Review duty grants explicitly; no production user was granted access in this task. Fresh domain checks independently enforce `view payout transfers`, `request payout approvals`, `review payout approvals`, `review payout transfers` and the new `route payout batches`. A payout owner cannot act as the staff maker/checker/router for their own obligation. Do not blindly reseed customized production roles.

Routes under `/admin/payout-approvals`:

- GET root: bounded obligation list; POST root: maker intake (`payout_id`, `method=wallet|bank_transfer|cheque`, original method-specific `beneficiary` for external methods, `note`). Financial identity overrides are prohibited.
- GET `/{payout}`: redacted facts; GET `/{payout}/destination`: private audited original destination JSON.
- POST `/{payout}/decision`: `decision=approved|rejected`, affirmative `original_facts_verified`, `note` (15–1000 characters).
- POST `/batches`: distinct `payout_ids[]`, 1–200 entries; amount/currency/key are server-derived.
- GET `/batches/{batch}`: redacted projection; POST `/batches/{batch}/route`: atomically claim and queue; POST `/batches/{batch}/refresh`: derive outcomes and finalize when eligible.

Bank original fields: `bank_name`, `account_name`, `account_number`. Cheque original fields: `payee_name`, `address`. Wallet intake has no external beneficiary. Do not put secrets/personal data in rationale fields. Protected successful projections are private/no-store and values render escaped.

Apply `2026_10_15_000400_create_payout_batch_members_table.php` only through an approved production migration/release plan, after preserving financial history. Disposable test migrations were exercised; production was not migrated. Existing metadata-only batches will be held, not silently adopted. Database queue connection must use the same configured SQL connection as the financial models and table `jobs`; run an explicitly monitored database worker (for example `php artisan queue:work database --queue=default`) rather than assuming a Redis-only worker consumes these records. The LIVE prize safety setting is required; it is not enabled for a production deployment by this delivery.

Generator planning and routing are separate transactions. If a newly created Pending batch cannot enqueue, its original membership remains durable and the operator can explicitly route that Pending batch after resolving the queue/configuration problem. It is not automatically selected again as unassigned. A Processing batch with an uncertain or missing job must not be reset/re-enqueued by changing metadata; investigate durable queue, job and financial evidence. This release does not implement automatic recovery of such a claim.

Current external bank/cheque policy remains THB-only. Currency-specific wallet architecture does not enable all currencies, all jurisdictions or any-country legal operation. No live merchant credentials, jurisdiction, bank beneficiary, provider contract or deployment authorization were selected. Preserve the existing country/provider gates, wallet fee rules, disabled member-to-member transfers and official-result provenance boundaries.

## Executed final verification

| Check | Result |
|---|---|
| Complete default PHPUnit, final application sources | 2847 tests / 132085 assertions; exit0, zero failures/errors; 46 explicit skips |
| Entire Payment directory on MariaDB | 372 tests / 1399 assertions PASS; no skips |
| Payout SQL race profile | Three captured iterations, each 4 tests / 68 assertions PASS; 12 direct-parent lock-wait witnesses total |
| Duplicate wallet execution | Both children directly wait on original wallet PRIMARY; paid/replayed, one financial transaction, two ledger entries, 1000.00 to 1400.00 only once |
| Duplicate batch routing | Both children directly wait on payout PRIMARY; queued/replayed, one durable job, no financial transaction |
| External duplicate completion / shared reference | Original payout reference-number / reference-owner identity indexes respectively; one canonical financial posting, foreign owner refused |
| Existing all-race financial profile, refreshed | 3 × 27 tests / 600 assertions PASS; 23 owned lock-wait witnesses per iteration |
| Offline Python guards | 86 PASS |
| PHP syntax | 1934 PHP-suffixed files under app/bootstrap/config/database/routes/tests/resources/views PASS |
| Focused Pint, including Blade | 24 files PASS |
| Native Rust tests / locked release build | 27 tests PASS / PASS |
| Frontend release build | PASS |
| Static preflight / generated inventory | PASS; not runtime or legal certification |

`evidence/runtime-part-31.txt` embeds exact final logs, JUnit case/skip summaries, the full existing-race report and all 12 payout race witnesses. The separate static preflight JSON is delivered in full. Default skips are explicit server/recovery profiles and one existing simulation; skipped cases are not passed cases. MariaDB Payment and the separately captured race runs have no skips. SQL worker recovery, Redis restart and dispatch SIGKILL profiles were not rerun; their earlier evidence remains historical. Hosted CI, multi-host deployment and live transfers were not exercised.

Validation corrections: an initial SQLite membership comparison needed raw DECIMAL-to-string normalization; exact ledger projection needed explicit eager loading because lazy loading is disabled. The initial full run preceded frontend/native build preparation and inventory regeneration, yielding missing-manifest/stale-inventory failures plus a genuinely stale queue contract. The manifest/native artifacts were built, the contract updated for the real producer, inventory regenerated, and the complete final run above passed. Initial payout witness output filenames did not satisfy the helper's safe prefix; three complete iterations were rerun with the required prefix and their actual witness records are embedded. None of those early failures or uncaptured files is represented as final passing evidence.

## Still remaining, with no silent completion claim

1. Provider-authenticated private final-evidence ingestion/upload, retention/access lifecycle and provider-specific final-result adapters. The external path remains record-only until independent evidence is reviewed; it does not send a real bank payout.
2. A genuinely common cross-domain outbound reference registry. Prize and withdrawal ownership indexes remain separate, and the broader Payment unique constraint is conservatively preserved. Legacy references outside Payment need controlled reconciliation.
3. Remaining unmanaged legacy prize/claim intake, reversal, cancellation and reporting integration. This release isolates managed payouts and fixes their canonical batch projections; it does not certify every legacy claim source, reversal journal, report precision/group aggregation or historical financial row.
4. Controlled handling of missing immutable approvals/destinations/memberships, historical duplicates, unsupported account rotations/currencies and incoherent records. These remain held; no backfill, claim reset, inferred approval or retry is invented.
5. Approved operator/jurisdiction/provider configuration, licensing/KYC/AML/tax review, liquidity, operational monitoring, hosted CI, multi-host acceptance, restore drills and a separately authorized live-provider acceptance. Worldwide architecture is not universal lawful/live availability.

Continue from File505, preserving this complete source overlay and the existing financial evidence/claims.
