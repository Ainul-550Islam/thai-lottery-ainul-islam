# Part32 continuation: common outbound reference ownership

Date: 2026-10-15. Files505–527 supply 23 complete file bodies, continuing after the previously delivered Files503–504. Next sequential file: File528. The cumulative delivered-source overlay contains 406 unique paths on base repository commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`; it is not a standalone repository, dependency bundle or database export.

## Actual application work completed in this tranche

A common `outbound_provider_references` SQL registry now protects the integrated external-prize and withdrawal lanes. The original prize-local and withdrawal-local indexes are retained as compatibility/identity anchors; they no longer constitute the only shared-ownership authority.

The common identity is SHA-256 over canonical provider, original server-owned account scope and the exact provider reference. `manual` and `bank_transfer` resolve to one canonical bank namespace, including cheque's manual Payment gateway. A reference cannot be reused merely by switching between those aliases. Reference identity deliberately excludes currency: one provider transfer cannot settle two obligations just because their currency labels differ. Different canonical providers/accounts remain distinct identities, subject to the existing broader Payment storage constraint. These account labels are routing facts, not proof of merchant authorization or a way for callers to pick an account.

Every common row has exactly one real payout or withdrawal owner, enforced by restricted foreign keys, unique owner columns and a SQL CHECK (SQLite uses equivalent INSERT/UPDATE refusal triggers). The identity and optional Payment linkage are unique. Raw provider references are not stored in this registry: it retains their hashes. A compatible initially Payment-less withdrawal binding can attach its subsequently verified Payment, but cannot be reassigned to a different owner, Payment, provider, account or reference. Populated schema rollback refuses destruction. No migration backfill, deduplication or historical winner selection is performed.

### Covered application paths

- External prize independent final-result completion and compatible completion replay, through `PayoutTransferReferenceService`.
- Withdrawal known-reference dispatch acknowledgements, including pending/uncertain results, through `WithdrawalProviderReferenceService`.
- Withdrawal success/failure callback binding, before any wallet debit or hold release.
- Withdrawal independent reconciliation application.
- Manual/canonical withdrawal completion, now including known-reference completed replays and unmarked-transaction bookkeeping paths before their early returns.
- Completed external batch projections, which require a matching common binding without creating/repairing it.
- Existing withdrawal reference audits, now including common-registry issues, plus the new cross-domain cursor audit command.

This is known-reference ownership, not a new provider invocation or proof of delivery. An uncertain outbound request may already have reached a provider; refusal to book a conflicting response cannot undo that request. Durable non-expiring dispatch claims and existing no-uncertain-resend behavior remain. No wallet fee, legal/provider gate, member-transfer restriction or original approval rule is relaxed.

### Atomicity and historical refusal

Callers must already hold their financial transaction and the normal wallet/obligation/Payment locks. Each binding uses a savepoint so local and common writes roll back together even when the caller catches a refusal inside its outer transaction, as webhook binding does. A caught foreign-owner refusal therefore cannot poison a new local index while the outer callback returns a generic binding failure.

The common insert uses a no-op upsert to acquire the duplicate identity's exclusive SQL lock directly, followed by owner verification. It does not update reference, owner, account, timestamp or other original facts on replay. This avoids the shared-to-exclusive upgrade deadlock reproduced with INSERT IGNORE followed by FOR UPDATE. The common lock is acquired after the local compatibility index, preserving the existing local-index race/lock contract. Historical ambiguity rolls the entire binding savepoint back.

Read-only historical checks include both local indexes; soft-deleted Payment/withdrawal/payout rows; known withdrawal provider references and dispatch-claim references; prize transfer metadata; and older withdrawal reconciliation ownership keys. A known foreign reference or an indeterminate original namespace is refused rather than adopted. Payment's broader gateway/reference UNIQUE remains intact and conservatively checked, including inbound payable domains and soft-deleted history. The common registry does not replace that constraint or certify unintegrated inbound/deposit writers.

Existing historical completed rows are not globally certified by creating this table. Audit them first. A compatible active finalization/replay can bind its own coherently verified original facts under the existing authorization and transaction rules; there is no bulk import/repair endpoint. Conflicting history, lost plaintext preimages, missing original facts and corrupt bindings remain controlled-review cases. Unknown/no-reference legacy completion behavior is not silently converted into authenticated external settlement.

## Operator diagnostics

Run only from an authorized server/operator environment:

```
php artisan payments:outbound-reference-audit --domain=payout --id=123
php artisan payments:outbound-reference-audit --domain=withdrawal --id=456
php artisan payments:outbound-reference-audit --domain=withdrawal --attention --after=0 --limit=50
```

These examples are diagnostic syntax, not actual production identifiers or commands executed against production. Domains are exactly `payout` or `withdrawal`. Choose one exact positive ID or a cursor page, never both. Attention pages accept 1–200 rows, use an exclusive increasing ID cursor and return `has_more`/`next_after`; a page is not whole-database certification. Soft-deleted history can be inspected. Missing original facts produce an item-level issue rather than aborting an otherwise readable page.

Output is read-only and redacted: domain, obligation ID/status, deleted/index flags and stable issue codes. It does not print provider references, identity hashes, private destinations, tokens or credentials. It always says automatic resend is not allowed and provider authenticity is not verified. Existing `payments:withdrawal-reference-audit` remains available and now includes the same common-ownership issues. Neither command binds, migrates, resets, releases funds, decrypts a destination or chooses a historical winner.

Apply `2026_10_15_000500_create_outbound_provider_references_table.php` through an approved migration/deployment process before enabling these changed application paths. Only disposable SQLite/MariaDB schemas were migrated here. Preserve all earlier local indexes, shared claims, Payments and financial history. Coordinate rollout of the integrated writers; an old application instance that does not use the registry is not covered by the new concurrency guarantee. Keep production code and migrations version-aligned. Existing Part31 database queue operational requirements still apply.

## Executed acceptance on final application sources

| Check | Result |
|---|---|
| Complete default PHPUnit | 2882 tests / 132212 assertions; exit0, zero errors/failures, 48 explicit skips |
| Entire Payment directory on MariaDB | 407 tests / 1535 assertions PASS; no skips |
| Combined payout/common SQL race profile | 3 × 6 tests / 110 assertions PASS; 18 captured direct-parent decisive-index wait witnesses |
| New common-registry cross-domain cases | Both children directly wait on parent's actual common `identity_key` index; prize-owned and withdrawal-owned cases each preserve one owner and one canonical posting |
| Existing payout cases in that profile | Duplicate completion, distinct-prize reference ownership, duplicate wallet credit and duplicate queue claim remain passing |
| Existing all-race financial profile, refreshed | 3 × 27 tests / 600 assertions PASS; 23 owned wait witnesses per iteration |
| Offline Python guards | 86 PASS |
| PHP syntax / focused Pint | 1943 PHP-suffixed source/view files / 15 changed PHP files PASS |
| Native Rust / locked release build | 27 tests PASS / PASS |
| Frontend release build | PASS |
| Static source preflight / generated inventories | PASS; static success is not runtime or legal certification |

The two new cross-domain contention tests intentionally pre-bind a legitimate original owner, then concurrently execute its completion and a different-domain contender on distinct financial obligations/wallets. They prove enforcement and finalization under direct common-index contention, not a production provider race or automatic first-owner historical selection. A shared Payout handle in the witness command's `payout_ids` is the helper's bootstrap handle for the withdrawal child; that child explicitly executes `WithdrawalCompletionService` for its separate withdrawal ID. The source test checks that withdrawal's balance/hold/status and the prize status, as well as one transaction/two ledger entries and one common row. Do not interpret that bootstrap field as two same-domain participants.

`evidence/runtime-part-32.txt` embeds final logs, JUnit case/skip summaries, all 18 payout/common wait witnesses and the complete refreshed existing-race report. Default skips are explicit server/recovery profiles plus one existing simulation, not passed cases. The MariaDB Payment and captured race runs have no skips. SQL worker/Redis restart/SIGKILL recovery profiles were not rerun; earlier evidence remains historical. No hosted CI, deployment, multi-host or live-provider acceptance was performed.

Validation corrections included a genuine common-index lock-upgrade deadlock, fixed using the no-op exclusive upsert; the nested caught-refusal atomicity issue, fixed with binding savepoints; and a test fixture's cheque beneficiary fields, fixed to use the actual cheque schema. A temporary test-only detailed child diagnostic used to identify the SQL deadlock was removed; delivered child errors remain redacted. Early failing logs are not represented as successful final proof.

## Remaining application and operational work

1. Provider-authenticated private evidence ingestion/upload, retention/access lifecycle and provider-specific final-result adapters. Independent human attestation and a reference hash are not provider authentication; the external prize path remains record-only and does not send a real bank payout.
2. Remaining unintegrated legacy claim/prize/reversal/cancellation/reporting paths, inbound/deposit namespace policy and any future outbound adapter must be reviewed against the common owner contract. This tranche closes the separate-index gap for the explicitly covered prize/withdrawal paths, not every possible financial writer or arbitrary historical record.
3. Controlled reconciliation of historical duplicates, corrupt/missing immutable approvals, references/preimages, account rotations and unsupported currencies. Preserve holds/history; no automatic backfill, approval, reset, reassignment or uncertain retry is provided.
4. Reference lookups include existing JSON/history scans; production-volume query/index plans, rollout/migration staging and operational monitoring need deployment-specific review. Cursor pagination bounds the number of obligations per audit page, not every internal matching-history scan.
5. Authorized operator/jurisdiction/provider configuration, licensing/KYC/AML/tax review, liquidity, production migration, hosted CI, multi-host/restore drills and separately authorized live acceptance remain. THB-only external bank/cheque policy and currency-specific wallet architecture remain; this is not universal lawful/live availability in any country.

Continue from File528 with this complete source overlay and the original durable financial claims/evidence preserved.
