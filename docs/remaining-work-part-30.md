# Part30 — external prize-transfer controls, accounting and remaining work

2026-10-15. Full continuation File443–472; next File473.
Base HEAD `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Prior authority: Full-Code-All-349-Files.zip, SHA-256 `5272787aeeceb0e9e349ba92abe1d6eec84c46305806e3a5443ddb4296852186`.

## Application defects fixed

The previous external-prize transfer service returned same-key replays before checking the beneficiary, currency, method or amount. It accepted caller-selected beneficiaries without proving the persisted payout owner. Completion/failure could proceed even when the named Payment was missing; completion set a payout to Completed without a financial transaction or ledger posting. Opening also allowed a failed lane to be replaced by a different key. The job defaulted absent/unknown methods to a bank transfer. These paths are now guarded rather than described as live-ready.

### Approval and original facts

`PayoutApprovalService` now re-reads and locks the actual payout for request, checker decision, completion and cancellation petition. It does not trust a stale caller model to overwrite a newer decision. Maker/checker actions require current active persisted staff roles and explicit granular duties; the beneficiary cannot request or approve their own disbursement, and the maker cannot act as checker.

A request must match the persisted prize reference, owner, positive exact amount, currency and deterministic request key, including on replay. Supported original method/destination facts are established from the maker's request without overwriting an incompatible existing claim. A fingerprint includes the financial identity, canonical sorted destination and server-owned merchant-account label. Changed original destination, money or account context blocks the checker and later execution. External destinations require current verified KYC documents, not merely a caller-supplied KYC flag. Unsupported schemas or currencies remain refused.

Legacy approval stamps without this fingerprint are not silently promoted into new approvals. A controlled historical review/import is still needed. The generic approval status reader is not a certificate that every other legacy batch or prize path has been fully hardened.

### Opening a record, not sending money

`PayoutTransferService::transfer()` opens one canonical Pending Payment and marks its payout Processing only after the approved original identity passes. There is no bank API invocation, automatic cheque issuance or wallet movement. The Payment reference uses a full-strength derived hash within the existing 64-character column limit. Arbitrary caller context is not copied into the Payment metadata.

Replay checks the persisted obligation, approval, account and sole coherent Payment before returning. A missing, deleted, duplicated, foreign, differently denominated, differently priced or differently routed Payment is refused. A malformed historical stamp is not treated as absent. A failed/completed lane cannot be replaced by a new anchor; there is no reset, TTL, takeover or automatic resend. The job no longer invents a bank method when the stored method is unknown.

The existing `finance.prize_payout.safety_mode` must be LIVE for these mutations. Its shipped default remains DISABLED. This part did not enable a provider or authorize a production money movement.

### Independent final-result confirmation

Completion and final refusal now require a current active staff principal, the explicit transfer-review duty, a 15–1000 character review note and affirmative independent-evidence attestation. A maker or beneficiary cannot confirm their own transfer. The original approval and KYC are rechecked inside the transaction. Unknown/not-found results must remain open; they are not final rejection. The note is represented in transfer/audit state by its SHA-256, not copied into provider metadata or generic failure messages. Operations must retain the actual source evidence under their approved retention process.

For independently confirmed external completion, `ExternalPayoutCompletionService` posts exactly one Payout financial transaction through the existing `LedgerPostingService`: debit currency-specific prize expense, credit currency-specific system cash. It uses existing chart accounts, not a newly invented wallet or live bank account. The transaction and entries have no wallet ID; the player wallet is neither credited nor debited. Then Payment becomes Captured, the payout links the completed financial transaction and the approval becomes Completed. All reference, transaction, ledger, Payment, payout and audit writes share one SQL transaction. Missing/inactive accounts or incompatible facts roll back finalization.

This is accounting for an already independently confirmed external transfer, not a mechanism to send money or a bank-liquidity check. System-cash books can show a negative position if prior external movements/funding were not booked; reconciliation must investigate rather than assume the local books prove bank funds. It does not create provider truth.

Final refusal changes payout/Payment to Failed with a static reason and no ledger posting. Repeated same terminal decisions return canonical state; conflicting reference or terminal result is refused. A completed approval cannot be manufactured without a matching completed Payout financial transaction and actual balanced ledger entries.

### Prize reference ownership and storage compatibility

The new `payout_transfer_references` table reserves a unique hash of gateway, account scope and exact provider reference, with immutable payout/Payment links. Historical Payment and payout-transfer records, including soft-deleted records, continue to own their known references. Existing duplicates are refused, not repaired or ranked. A populated table refuses destructive migration rollback.

**This is a prize-scoped reference index, not the universal cross-domain index still outstanding in Part29.** Part29 withdrawal ownership remains unchanged. The existing Payment table additionally has a broader `(gateway, gateway_reference)` unique constraint, whose database collation may be case-insensitive and which does not include account scope. Known collisions against that broader constraint are refused before posting, including other payable domains. Concurrent storage collisions roll back and become a domain refusal, never permission to resend. This part does not drop, weaken, re-collate or backfill that global constraint. Account-label isolation does not guarantee the Payment schema will permit reference reuse across accounts or case variants.

Bank transfers use the bank-transfer gateway namespace. Cheques use the manual gateway namespace; their account label currently resolves through the existing canonical bank/manual account configuration. That is identity context, not cheque issuance, credential routing or legal approval.

## Usable private operator review

The session-authenticated admin console is `/admin/payout-transfers`. Exact rows can be opened at `/admin/payout-transfers/{ID}`. It provides a record-only open action, an independently verified final-result form and a separate private audited original-destination inspection. Each protected page/JSON response is private/no-store. Normal list/detail projections do not disclose beneficiary details, provider references, approval fingerprints, idempotency keys or caller context. Values render escaped. Financial transactions remain in domain services, not controllers.

Routes:

- GET `/admin/payout-transfers` — bounded pagination, 30 per page.
- GET `/admin/payout-transfers/{ID}` — redacted detail.
- POST `/admin/payout-transfers/{ID}/open` — record only; never sends.
- GET `/admin/payout-transfers/{ID}/destination` — separately authorized and audited private JSON.
- POST `/admin/payout-transfers/{ID}/decision` — `outcome=completed|failed`, `note`, affirmative `evidence_verified`; exact `provider_reference` required for completed and prohibited for failed.

Existing admin authentication/access middleware, web CSRF and admin throttling remain. Service authorization is checked independently of Gate's super-admin shortcut. New catalogue duties are `view payout transfers`, `request payout approvals`, `review payout approvals`, `review payout transfers`. The existing seeder grants its normal admin/super-admin matrix, but production grants must be explicitly reviewed. Do not blindly role-sync a customized live installation. No actual production user was granted a duty here.

The review console does not fabricate upstream approval or batch membership. Creation/checker approval remains available through the locked `PayoutApprovalService` domain API with real persisted staff IDs. A complete human-facing maker/checker intake and explicit batch-routing integration for external prizes remain follow-up product work. Missing membership, original destination, current KYC or immutable approval is intentionally refused rather than guessed.

## Deployment and data preservation

Deploy the complete source tranche and apply `2026_10_15_000300_create_payout_transfer_references_table.php` through an approved release/migration process before using these paths. Only disposable SQLite/MariaDB schemas were migrated here. Preserve SQL history, references and original private destination data; never clear a claim/index or soft-delete a financial row to force a retry. This migration provides no destructive backfill, deduplication, credential/account switch or legal-policy migration.

Current external method policy remains THB-only for bank/cheque. Existing wallet currency support is not changed. Worldwide architecture does not mean silently enabling every rail, currency or country. Real operator, jurisdiction, KYC/AML, licensing, merchant routing, tax/fees and provider contracts still require approved configuration and integrations.

## Final executed verification

| Check | Final result |
|---|---|
| Complete default PHPUnit on final application sources | 2811 tests / 131796 assertions; exit0, zero failures/errors; 44 explicit skips |
| Entire Payment directory on MariaDB, including new payout races | 336 tests / 1243 assertions PASS; zero skips |
| New independent-process payout races | 3 iterations of both cases; each pair 2 tests / 34 assertions; first pair included in the Payment run, two separate repeats |
| Duplicate completion race | Both children directly wait on parent's actual payout reference-number index; one transaction and two ledger entries, replay-safe result |
| Different-prize reference race | Both children directly wait on parent's prize identity unique index; owner completes, foreign payout remains Processing; one transaction/two ledger entries |
| Existing all-race SQL profile, refreshed | 3 × 27 tests / 600 assertions PASS; 23 owned lock-wait witnesses per iteration |
| Offline Python guards | 86 PASS |
| PHP source syntax / focused Pint | 1715 files / 22 PHP-suffixed files including the new Blade source PASS |
| Native tests / locked release build | 27 Rust tests PASS / PASS |
| Frontend release build | PASS; output not delivered as source |
| Static source preflight, generated hashes, whitespace | PASS; static success is not runtime or legal certification |

The new contention witness names actual parent/child SQL connection IDs, decisive table/index, positive row IDs, exactly two direct-parent waiters, payout IDs and outcomes. An indirect child chain or shared wallet is not accepted as shared-reference evidence. The different-prize case uses different obligations/beneficiaries and pre-existing Payments so insertion-gap blocking cannot masquerade as decisive-index contention. Fixtures and references are synthetic; no real bank account or transfer was used.

Early checking found an amount-drift replay that still passed the approval fast path; amount equality was moved before replay and the regression now passes. A first SQL duplicate test locked only the payout primary record; it was corrected to own the actual reference-number lookup index rather than weaken the two-direct-waiter condition. A full regression caught a controller transaction in private destination inspection; the transaction/audit was moved into the domain service without changing the architecture test. A candidate case-distinct reference test exposed the broader existing Payment unique key; this compatibility limit is now explicitly guarded and documented instead of weakening that constraint.

The default 44 skips are explicit server-only profiles plus the pre-existing simulation: SQL worker recovery9, conversion9, Redis7, settlement4, callbacks4, dispatch2, dispatch-recovery2, reconciliation2, withdrawal-reference2, new payout-reference2, simulation1. Skips are not passed tests. Current Payment/server race runs have no skips. SQL worker recovery, Redis restart and dispatch SIGKILL profiles were not rerun in this part; their Part28/29 evidence remains historical.

`evidence/runtime-part-30.txt` embeds the complete current all-race JSON, six new payout wait witnesses, JUnit case-status summaries and final logs. The static preflight is a separate full delivered JSON body. Dependencies, native executables, builds, database state, credentials and private customer/provider artifacts are not packaged. The cumulative ZIP is a source overlay on the stated base, not a full standalone repository/database export.

## Still remaining — do not call universal live money all done

1. Human-facing payout maker/checker intake, authorized batch-routing integration, private provider-evidence upload/retention and provider-specific final-result adapters remain. Human attestation plus a hash is not automatic provider authentication.
2. A genuinely common cross-domain outbound reference registry is still outstanding. Prize/withdrawal indexes remain separate; known Payment collisions are conservatively refused. Legacy references known only outside the Payment table need controlled cross-domain reconciliation.
3. Other legacy prize, claim, batch, cancellation, reversal and reporting lanes need continued end-to-end review. In particular, reporting that assumes every completed payout credits a wallet must understand external cash postings; this part does not certify every batch counter or claim source integration.
4. Legacy approvals without immutable facts, incoherent Payments, missing destinations, unsupported currencies/account rotations and historical duplicates require controlled review/import. No automatic repair, retry or historical approval is invented.
5. No merchant account, jurisdiction, operator, bank beneficiary, production migration, live provider activation or actual transfer was authorized here. No deployment, hosted CI, multi-host acceptance, restore drill or universal worldwide legal availability is claimed.

Continue from File473 with the delivered authority preserved.
