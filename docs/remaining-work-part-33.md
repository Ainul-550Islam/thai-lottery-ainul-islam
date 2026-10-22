# Part33: private external-prize evidence and enforced final-review provenance

Date: 2026-10-15. Files528–557 provide 30 complete file bodies, continuing after File527. Next sequential file: File558. The cumulative delivered-source overlay contains 419 unique paths on base commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. It is not a standalone repository/dependency/database export.

The selected completion target is application functionality with unauthorized live transfers disabled. This tranche closes the private external-prize archival/access/consumption gap; it does not claim that all remaining platform work or real-world live-money acceptance is complete.

## Implemented application behavior

- An external prize's original approved facts can now have a private uploaded evidence archive. Every archive records a restricted-FK payout/uploader, immutable original-facts fingerprint, content digest, byte count, detected MIME type and a random private object key. The client cannot choose its storage path/disk, uploader, digest or remote URL.
- Objects are encrypted through Laravel's authenticated encryption before writing to the fixed private local root `storage/app/private/payout-evidence`. Neither original filename nor plaintext is stored in SQL; no public Storage disk, symlink, signed URL, remote fetch or active document parser is used.
- Actual detected PDF, JSON or plain-text bytes are accepted, from 1 byte to 2 MiB. Filename/MIME claims are not provider authenticity. HTML/SVG and other unsupported active-content types are refused. Files are delivered only as a fixed-name octet-stream attachment with no-store, nosniff, sandbox CSP and no-referrer headers.
- Upload, read/list and final-review duties are distinct. Fresh active staff status, role and explicit permission are rechecked in services, independently of Gate's super-admin shortcut. A beneficiary cannot upload/read/review their own payout archive even if otherwise staff-authorized. An uploader cannot consume their own archive as final reviewer; original maker/reviewer separation remains enforced too.
- A successful authenticated file download records an append-only read event and a generic private audit with archive ID only. Final review requires that exact reviewer to have downloaded the exact selected archive within the preceding 24 hours. CLI integrity inspection does not count as that human review.
- Both completed and definitively failed external prize results require an archive, not just an `evidence_verified=true` checkbox. HTTP review requires an explicit positive archive ID. The backward-compatible domain signature may omit the ID only when exactly one original archive exists; multiple archives are refused, never silently selected by recency. There is no archive-policy disable switch.
- Finalization rechecks decryptability, exact byte count/digest and original-facts binding inside the same SQL transaction as common reference ownership and canonical accounting. Consumption records one original outcome/reference hash/reviewer; accounting/reference failure rolls consumption back. Compatible replay preserves that record; it cannot replace the archive or invent proof for an old terminal row.
- Canonical external batch projections verify the attached consumed proof and byte integrity, including definitive-failure projections. A lost/corrupt archive or missing terminal provenance is held for controlled review; it is not silently repaired or treated as new money authority.
- The existing private transfer console includes upload controls, a bounded redacted archive list, audited downloads and an exact inspected-archive selector. Validation failures do not flash file rationale or provider-reference fields into old input. No new public endpoint exists.
- Archives default to indefinite legal hold. There is no retained-evidence purge, hold-release, automatic expiry deletion or automatic retrospective import endpoint. Populated rollback refuses destructive schema loss. Upload-failure cleanup can remove only a just-created uncommitted object and refuses to remove a SQL-retained object.
- Retained evidence for a soft-deleted payout remains inspectable by currently authorized staff. An increasing-ID read-only audit command supports bounded retention/integrity inspection without disclosing file contents, object keys, hashes, destinations or credentials.

Archival source provenance is explicitly `human_uploaded`; APIs return provider authenticity as false. A digest plus encryption authenticates stored bytes, not a bank/provider or the truth of a human-uploaded document. Actual final source verification remains an independent staff responsibility until an approved provider-specific authenticated adapter exists. Nothing in this tranche sends a bank payout or issues a cheque.

## Private operator workflow

Use the existing session-authenticated admin/CSRF/throttle boundary. New catalogue duties are `upload payout evidence` and `read payout evidence`; no production user was granted permissions here. Review explicit grants instead of blindly reseeding customized roles. Auditors may read only when separately granted the read duty; viewing a payout does not imply file access.

Under `/admin/payout-transfers`:

1. Open the original record-only transfer lane using the existing action, if not already open.
2. POST `/{payoutId}/evidence` with multipart `evidence` and a 15–1000 character rationale `note`, using a current authorized uploader who is not the beneficiary. An original approved external obligation is required; wallet prizes and terminal/unapproved rows cannot create a new archive.
3. GET `/{payoutId}/evidence` for at most 50 redacted newest archive records. No raw filenames, paths, content hashes or destinations are projected.
4. A different authorized final reviewer downloads GET `/{payoutId}/evidence/{evidenceId}` and independently inspects its contents and actual source/final settlement facts. A read event is recorded only after successful decryption/integrity validation. Cross-payout archive IDs are refused.
5. POST the existing `/{payout}/decision` with `evidence_id`, `outcome=completed|failed`, an affirmative `evidence_verified`, review note and exact provider reference for completed only. Unknown/not-found results must remain open; an uploaded document does not convert uncertainty into final refusal.

Example authorized server diagnostic syntax (not actual production IDs or a production command execution):

```
php artisan payments:payout-evidence-audit --after=0 --limit=50
php artisan payments:payout-evidence-audit --after=0 --limit=50 --verify-bytes
```

Limits are 1–200 rows, using an exclusive archive-ID cursor and `has_more`/`next_after`. `--verify-bytes` decrypts and verifies selected objects without printing them. Corruption appears as a stable issue code; the command does not delete or repair anything. Its success exit means the audit ran, not that all item issues are clear or an entire database is certified.

## Release, retention and failure preservation

Apply `2026_10_15_000600_create_payout_transfer_evidence_tables.php` through an approved migration/rollout plan before using these changed writers. Only disposable test databases were migrated here. Retain all earlier membership/reference indexes, financial records and non-expiring claims. Version-align workers and web instances; older application instances without archive enforcement are not covered by this new contract.

Back up encrypted objects, SQL archive/consumption/read history and the matching application encryption key history together. Key loss is not recoverable by a content hash. Use a reviewed key-rotation/keyring plan and restore drill before production; no production key was supplied or rotated here. The fixed private store is local: multi-host operation requires an approved shared private storage topology and separate acceptance, not a public-disk switch.

Storage and SQL are not a distributed transaction. Upload writes/verifies an encrypted random object before SQL archival; a SQL failure attempts safe uncommitted cleanup. A process crash can leave an encrypted orphan, but not a usable SQL proof. There is no automatic orphan purge. A later external SQL transaction around upload is explicitly refused to avoid committing an ambiguous file/row lifecycle. Preserve retained objects during investigation; do not delete or alter evidence to force a retry.

No legal retention period was selected by the user. Indefinite legal hold is a preservation default, not a statement about compliance in any jurisdiction. A future deletion/hold-release policy needs approved legal/operator requirements and separate access/audit design. Historical terminal external rows without attached consumed evidence are deliberately not backfilled or blessed on replay. Their canonical batch projection/final-review replay requires controlled evidence reconciliation, not an invented document.

## Executed acceptance

| Check | Final result |
|---|---|
| Complete default PHPUnit, final application sources | 2906 tests / 132392 assertions; exit0, zero failures/errors; 49 explicit skips |
| Entire Payment directory on MariaDB | 431 tests / 1639 assertions PASS; no skips |
| Payout/common/evidence SQL profile | 3 × 7 tests / 132 assertions PASS; 21 direct-parent decisive-index wait witnesses |
| New conflicting final-result archive race | Completed and definitively failed contenders serialize on the actual payout reference index; only one final result consumes the original archive and posts at most one canonical transaction |
| Existing six payout/common contention cases | Duplicate completion, reference ownership, wallet credit, routing and both cross-domain common-index cases remain passing with archive enforcement |
| Existing all-race financial profile, refreshed after harness fix | 3 × 27 tests / 600 assertions PASS; 23 owned wait witnesses per iteration |
| Offline Python guards | 86 PASS |
| PHP syntax / focused Pint | 1953 PHP-suffixed source/view files / 22 delivered PHP-suffixed files including Blade PASS |
| Final generated-source contract after probe-only change | 3 tests / 958 assertions PASS; static inventory check PASS |
| Native Rust / locked release build | 27 tests PASS / PASS |
| Frontend release build | PASS |
| Source preflight | PASS; no imported application classes missing; static success is not live certification |

The complete default run's 49 skips are explicit server/recovery profiles plus the existing simulation, not passed cases. MariaDB Payment and separately captured SQL profiles have no skips. SQL worker/Redis restart/SIGKILL recovery, hosted CI, multi-host and live-provider acceptance were not rerun or certified. Earlier evidence for unrepeated profiles remains historical.

Validation corrections were substantive and are not hidden: the first full run lacked the sandbox GD extension and errored in 40 existing image-fixture tests; GD was installed and the complete suite was rerun successfully. Early integration preserved original invalid-reference exception ordering and corrected evidence routes accidentally placed in the approval prefix. A later existing financial race exposed partial readiness JSON publication: the standalone child probe now publishes a complete temporary marker via atomic rename instead of exposing a just-created empty file. That probe-only change does not alter application sources; all affected server profiles, offline guards, syntax/style and generated-source contract were then rerun. No failing early run is represented as a final success.

`evidence/runtime-part-33.txt` contains final JUnit status/skip summaries, exact logs, all 21 payout/common/evidence witnesses and the refreshed full existing-race JSON. The source overlay excludes dependencies/builds, DB state, retained private objects and credentials.

## What is still not honestly 100% complete

1. Approved provider-specific authenticated final-evidence/callback adapters and actual authorized provider acceptance. This tranche supplies real private archival, independent read/review enforcement and integrity, not an invented bank protocol or a live transfer.
2. Remaining unmanaged legacy claim/prize/reversal/cancellation/reporting and inbound/deposit namespace integration, controlled historical reconciliation and deployment-volume query/index review. Existing managed payout/wallet/common-reference fixes remain; unreviewed writers are not thereby certified.
3. Production privacy/retention/legal policy, key backup/rotation/restore, shared private storage and multi-host operation, production migrations/monitoring/hosted CI, and operator/jurisdiction/merchant/KYC/AML/tax/liquidity approvals. No jurisdiction/provider/currency authorization was fabricated; worldwide architecture is not universal lawful/live availability.

Continue from File558, preserving this complete source overlay and the original durable financial evidence and claims.
