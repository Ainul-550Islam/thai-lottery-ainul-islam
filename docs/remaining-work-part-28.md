# Part28 — usable four-eyes outbound reconciliation, File383–File412

## Delivery identity

Continue after File382. This part contains 30 complete file bodies, File383–File412; next is File413. The cumulative archive contains 333 unique delivered paths: 303 earlier bodies preserved byte-for-byte, nine intentional revisions and 21 first-delivered paths. This is a source overlay for the existing repository, not a standalone complete repository/dependency/database export.

Base HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. Prior authority: `Full-Code-All-312-Files.zip`, SHA-256 `f80414c6e9f2392de38d12a6d9a48236f4bf981eb6194d47eff9b74196996109`. At the start, 128 missing/drifted delivered paths were restored from this hash-verified authority before applying new changes. Dependencies and generated assets were restored separately, not substituted for source.

## Actual application work completed

This is not a test-only delivery. The earlier read-only status command remains read-only; this part adds a separate controlled write workflow.

- A new authenticated web console supports evidence submission, paginated case listing, case detail, private attachment download, independent destination inspection and maker/checker decisions.
- Evidence files are uploaded, not fetched from arbitrary URLs. Only PDF, plain text and JSON up to 2 MiB are accepted. The original file name is not trusted for storage paths.
- Artifact storage is local private and encrypted with the application encryption key. Content-addressed SHA-256 identities, byte counts and authenticated decryption are checked again before money changes. Facts and decision notes are encrypted in SQL.
- The case model refuses application-level changes to original maker/evidence/snapshot facts and refuses deletion. A final decision cannot be rewritten. An already applied decision replay by its original checker returns without additional money or audit mutation.
- Active persisted staff authority is checked afresh. Explicit view permission is mandatory; request/review require their respective permission plus an admin/super-admin role. Auditors can read only if explicitly granted view permission, never operate money.
- A super-admin Gate bypass cannot override these service checks. Suspended actors, lost roles/permissions, self-review and reconciliation of the actor's own withdrawal are refused. Maker authority is rechecked before applying evidence; a revoked maker's case may still be rejected without touching money.
- Evidence must bind the original internal reference, canonical provider, known external reference, currency, exact gross amount and exact net beneficiary amount. Observation time must be explicit RFC3339, not future beyond one minute and not before the claim.
- Only `completed` and `definitively_rejected` are accepted final evidence outcomes. Unknown, pending, timeout and not-found are not inferred to mean rejection.
- The immutable Part27 dispatch claim, bound Payment and original beneficiary facts must agree. Supported destination fields and a provider-appropriate destination identifier are required; missing/custom/secret destination schemas fail closed for private adapter review.
- A pending case snapshots the claim and Payment state. Changed claims, references or beneficiary data require a new evidence case rather than quietly applying stale facts.
- Lock order is wallet, withdrawal, Payment, case, followed by the reference-binding/finance work. SQL retries are bounded; no gateway or external transfer is invoked by this workflow.
- Confirmed completion uses the existing WithdrawalCompletionService to consume the reservation and post the debit through the balanced finance engine. Proven final non-acceptance uses WithdrawalApprovalService to release the reservation without creating a refund credit.
- Rejecting an evidence case does not reject the withdrawal: its Processing/unknown state and reservation stay intact.
- An already completed bound callback cannot be overwritten by later human rejection evidence. Competing evidence cases cannot apply contradictory terminal money states.
- Applied provider references are bound by a unique SQL identity in this reconciliation lane, including across different wallet rows. Known conflicting withdrawal/Payment references are refused. This is not a universal third-party exactly-once guarantee: other existing execution/callback lanes do not all share this new binding table, and a broader shared-reference migration remains separate work.
- Safe audits record submission, decision, private destination inspection and evidence export. They do not contain raw private artifacts, beneficiary details, arbitrary notes or dispatch keys/tokens.

Part27's non-expiring claims, no automatic uncertain resend and timeout hold retention remain intact. The only sender change is an internal read-only claim-validation boundary reused under owned SQL locks.

## Operator usage

After authorized deployment/migration and explicit role review, open:

`/admin/withdrawal-reconciliation`

Named routes under `admin.withdrawal-reconciliation`:

| Method | Suffix | Purpose |
|---|---|---|
| GET | `/` | Paginated HTML or JSON list, 30 cases per page |
| POST | `/` | Multipart evidence submission |
| GET | `/{reconciliation}` | Safe case detail |
| GET | `/{reconciliation}/evidence` | Authorized private binary attachment, audited |
| GET | `/{reconciliation}/destination` | Independent reviewer's sensitive original destination view, pending unchanged case only, audited |
| POST | `/{reconciliation}/decision` | Independent apply/reject decision |

Routes use authenticated web sessions, the existing admin boundary and throttling. Browser POST forms contain CSRF tokens. No credential or actor ID supplied in the form replaces the authenticated user. JSON validation uses the existing global `error.details.fields` envelope; controlled domain conflicts return HTTP409 with a safe reason.

Case JSON is whitelisted. Dispatch keys/tokens/fingerprints, raw withdrawal metadata and private storage paths are not included. Only the separate sensitive destination route intentionally discloses supported original beneficiary fields to an independent authorized reviewer. It is not available to makers, beneficiaries, auditors or final/stale cases. Responses are private/no-store; artifacts are downloaded as octet-stream attachments with nosniff, never embedded as executable content.

### Maker

Submit the existing withdrawal ID and original reference, canonical provider, final provider reference, stored currency, gross/net amounts, final outcome, provider observation time and original provider evidence file. Submission alone changes no balance or reservation. Identical evidence/facts/snapshot joins the existing case; it does not replace its original maker.

### Independent checker

1. Open the case and obtain the artifact through the private download.
2. Inspect the original immutable payout destination through the separate protected link. Compare the actual provider result, original request/beneficiary, currency, gross/net and references using the approved provider channel.
3. Reject insufficient or contradictory evidence. This preserves the held money for later reconciliation.
4. Apply only after independent verification, selecting the explicit attestation and supplying a 15–1000 character review note. The service rechecks authority, facts, snapshot, original claim and actual artifact integrity inside the money transaction.
5. Read canonical withdrawal/Payment state after the decision; do not issue a new transfer or clear a claim.

Two persisted accounts are enforced, not proof that two accounts belong to different human beings. The organization must enforce actual duty separation and account security. An artifact hash proves byte integrity, not provider authenticity or legal authority. Uploaded PDF/JSON/text is not automatically parsed into trusted provider truth; the checker must verify it. Do not execute downloaded files or include credentials in evidence/notes.

## Deployment compatibility and retention

The new migration creates `withdrawal_reconciliations` and `withdrawal_reconciliation_references`. It was applied only to disposable test schemas here. Deploy the full set together and run migrations through the normal approved release process; no production migration was executed.

New catalogue permissions:

- `view withdrawal reconciliations`
- `request withdrawal reconciliations`
- `review withdrawal reconciliations`

The existing RolePermissionSeeder reads this catalogue and grants its normal admin/super-admin matrix. Its auditor matrix does not automatically grant the new view duty. Review individual production grants and duty separation through your approved access-management process. Do not blindly run a role-sync seeder against a customized live permission matrix. No actual live user was granted a role or permission here.

Back up the application encryption key and private artifact directory together with SQL; restoring SQL alone is insufficient. Preserve evidence according to the authorized retention/legal-hold policy. Application deletion is blocked, but a privileged database/filesystem administrator is outside that model guard. Missing/corrupt artifacts block application instead of bypassing evidence.

File storage is not transactional with SQL. A refused/rolled-back submission may leave an unreferenced encrypted blob. There is no automatic purge, because deletion could race a new reference or violate retention. Establish audited retention/quota management before deployment. A populated evidence table deliberately blocks schema rollback; the refusal was exercised with synthetic evidence, preserving both tables and all case rows.

Legacy Processing rows without exactly one coherent bound Payment, missing destinations or unsupported private destination schemas remain held/refused by this lane. This part does not fabricate historical Payments or invent destination facts. A controlled legacy evidence-import/adapter review is still needed for those cases.

## Executed final verification

| Check | Result |
|---|---|
| Full default PHPUnit, final rerun | 2702 tests / 131355 assertions; exit0; zero failures/errors; 40 explicit server-profile skips |
| SQLite Payment + comprehensive queue + all three readiness/invariant files | 288 tests / 1248 assertions PASS |
| Entire Payment feature directory on MariaDB | 229 tests / 958 assertions PASS |
| All SQL race profile | 3 repetitions, each 25 tests / 540 assertions PASS; 21 actual parent-owned contention witnesses per iteration |
| New reconciliation races within that profile | Duplicate review debits once; conflicting final evidence yields one terminal result |
| Existing outbound SIGKILL/replacement recovery refreshed | 3 × 2 tests / 61 assertions PASS; two real kill witnesses each |
| Existing SQL worker recovery refreshed | 3 × 9 tests / 444 assertions PASS; seven witnesses each |
| Existing private Redis AOF restart recovery refreshed | 3 × 7 tests / 353 assertions PASS; seven actual hard-restart witnesses each |
| Offline Python guards | 84 PASS |
| PHP syntax | 1691 files PASS |
| Pint | 19 changed/new PHP files PASS |
| Native verifier | 27 tests PASS and locked release build PASS |
| Frontend | Production Vite build PASS; new Blade console rendered through HTTP tests |
| Migration rollback guard | Populated synthetic evidence prevented rollback; both tables and case rows preserved |
| CI source | YAML parsed; 11 Bash blocks valid; four SQL-writing profiles serial; hosted CI not executed |
| Static preflight/inventory/whitespace | PASS; no missing sources/stale reports; git diff --check exit0 |

The new feature matrices include THB, USD and BDT completion through the bank-transfer test adapter, hold-only submission, definitive rejection without refund credit, actor independence and beneficiary refusals, permission revocation, suspended super-admin, artifact corruption/missing files, immutable evidence, callback precedence, reference reuse, full SQL rollback and private HTTP boundaries. These are synthetic financial/provider facts, not three approved live corridors.

The first new test run had two assertions expecting Laravel's default validation envelope rather than this application's existing `error.details.fields` envelope. Those test expectations were corrected; production validation/authorization was not weakened. A later independent destination-inspection path and destination-schema refusal were added for actual operator usability, then the entire full suite was rerun on the final sources.

Exact default skips: SQL worker recovery9, conversion races9, Redis recovery7, settlement races4, callback races4, dispatch races2, dispatch recovery2, reconciliation races2 and settlement simulation1. Separate real server profiles covered them with no skipped cases. The matrices overlap; do not sum them into unique coverage or money-volume claims.

Runtime: PHP8.4.26, MariaDB11.8.6 InnoDB through a private Unix socket, Redis8.0.2 for the separate private recovery profile. All database-writing server profiles ran serially against the explicitly acknowledged disposable `thai_lottery_test` schema. Dispatch recovery kills real PHP job-handler processes but journals only synthetic acceptance; it is not a real merchant transfer or a broker-daemon restart proof. The other SQL/Redis profiles have their own explicitly bounded worker/restart scopes.

Packaged machine-readable race evidence: `evidence/server-races-part-28.json`. Complete refreshed dispatch/SQL/Redis recovery JSON and static-preflight JSON are embedded verbatim in `evidence/runtime-part-28.txt`, together with executed logs. The separate scratch JSON paths are not additional delivered file labels. Earlier Part27 evidence remains byte-preserved, not overwritten or relabeled as a new result.

## Remaining work — not silently skipped

1. Approve the real operator/legal entity, jurisdictions, player countries, merchant/currency corridor and allowed payout rail. Complete the selected provider's actual payout/status integration and authenticated sandbox acceptance. No live provider was activated or called here.
2. Verify provider-side finality, idempotency retention, internal adapter retries and delayed/lost acknowledgements. This manual lane cannot turn a receipt/not-found response into cryptographic proof or guarantee exactly-once behavior inside a third party.
3. Generalize provider-reference binding across all outbound finalization and callback lanes, with explicit merchant-account namespace and compatibility migration. The new table only serializes this reconciliation lane; known external conflicts are checked but universal cross-lane concurrent binding is not certified.
4. Add controlled legacy evidence/Payment import and provider-specific destination/evidence schema support where historical claims lack coherent facts. Keep unsupported/corrupt records held, not automatically reset.
5. Execute hosted CI and representative multi-host deployment/broker/provider tests, including backups of SQL/private artifacts/key, restore drills, monitoring/escalation, staff MFA/step-up policy and retention/quota operations.
6. Complete official result-source onboarding/authenticity and jurisdiction-specific regulatory acceptance. Declared page slots/static projections do not prove every route, operational workflow or live financial integration is complete.

Worldwide means explicit currency and fail-closed approved country/provider/currency combinations, not universal legality or automatic availability in every country. Existing own-wallet conversion versus disabled member transfers, fee rules, manual approval, canonical settlement/cursors and official provenance are preserved.

No live deployment, production migration, merchant activation, real transfer, official-feed authentication or universal real-money readiness was performed/certified. The completed 30-file tranche is usable controlled application code; the remaining items above require further integration/operational evidence rather than a false all-done claim.
