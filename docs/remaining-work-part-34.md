# Part34 — original player claims, independent review and safe legacy annotations

Date: 2026-10-16. Continues the authoritative 419-path Part33 delivered-source overlay on repository commit `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. Files558–601 contain 44 complete bodies; next File602. The cumulative overlay contains 448 unique paths: 404 earlier bodies unchanged, 15 intentionally revised, 29 paths first delivered. First delivered does not necessarily mean newly created in the base repository. This is a source overlay, not a standalone dependency/database export.

## Actual application changes

- Generic `Services/Prize` claims now resolve exactly one original, non-deleted payout for the owned winning bet. Direct and reverse pointers must agree when both exist. A dangling/foreign direct pointer never authorizes fallback. Owner, draw, receipt, currency and exact positive actual prize must match. A linked receipt must itself be a current confirmed receipt of that owner/draw/currency. Ambiguous duplicate obligations are held, not silently selected or repaired.
- Claim submissions and review re-query persisted state under SQL locks. New submissions require a current active owner and a still-unmanaged Pending obligation. Existing approval/batch/transfer markers, immutable SQL membership, evidence/reference rows, disbursements, financial/ledger history (including soft-deleted history) and Payment history protect money from this legacy metadata writer. Original destination method/beneficiary and unrelated fresh metadata survive.
- An actual claim carries original-facts and original-deadline snapshots. Same-owner replay is read-only, including Rejected, Expired and canonically Paid claims; rejection no longer creates a restart opportunity. Missing historical snapshots are not manufactured on replay.
- Player-assertion review now requires current active staff, an explicit `review player prize claims` duty, a non-beneficiary reviewer, original-facts confirmation and a bounded substantive rationale. Rationale is hashed, not retained raw. One decision wins; a second review cannot overwrite it. The private console at `/admin/player-prize-claims` is linked from the maker/checker console and uses existing admin authentication, CSRF and throttling. No production permission grants were executed here.
- Financial maker intake, financial checker decision and canonical execution revalidate actual player assertions. UnderReview/Rejected/Expired assertions cannot be bypassed by money approval. A reviewed claim does not itself approve a destination, create a batch or release money. A current explicit reviewer duty is rechecked for manually reviewed assertions. Method-only administrative routing metadata is not misrepresented as a player claim.
- Window registration locks and re-reads the original bet/payout, preserves fresh metadata and uses only persisted draw completion or win timestamps. Missing historical anchors do not become `now + 730 days`. Invalid policy values or malformed/missing original window snapshots fail closed. A missing registration before first valid claim is distinct from a corrupted existing registration. Auto-review threshold arithmetic is exact decimal; malformed threshold policy falls into manual review, not automatic approval.
- Expiry/window/disposal workers share one locked per-row engine. Candidate IDs are chunked; each row is freshly locked and rechecked in its own retryable transaction. Absolute ISO deadlines are parsed as instants, not lexically compared JSON strings. Financially managed or terminal obligations are excluded. Missing/corrupt snapshots, conflicting disposal records and changed original disposal identities are held.
- These legacy expiry/disposal transitions are **annotations only**. They do not cancel a Payout, release a hold, reverse a ledger entry, credit an operator, or establish a legal forfeiture. Sweep verifies the original disposal key, original facts and matching valid expiry timestamps; it does not repair historical contradictions. Managed financial obligations remain durable even when an older eligibility deadline passes.
- Totals are exact strings grouped by currency. Legacy scalar summary fields are `null` for mixed currencies, never a sum of THB and USD. The three scheduled summary consumers retain grouped totals and explicitly describe annotation-only behavior. `pendingClaimsTotalsByCurrency()` is authoritative when more than one currency is present.
- A canonical completion bridge marks an actual Approved player claim Paid only after the original Completed payout has its exact Completed Payout financial transaction and exact balanced currency-specific prize-expense/source ledger pair. Wallet completion credits the original wallet; external completion does not credit it. Paid claim, financial transaction ID, window closure and audit share the enclosing financial transaction. A post-ledger bridge failure rolls back wallet, transaction, ledger, approval and claim changes together. Terminal Paid replay checks original financial evidence and cannot infer payment from a label or the clock.
- Generic legacy status-only disbursement is refused for actual player assertions and protected financial/membership/evidence history. The older job cannot mark the newly covered player claims paid through a status-only journal.
- Player GET/list projections exclude destination-only metadata and incompatible original associations. Invalid historical rows are withheld rather than served as valid claims. Page counts are explicitly candidate counts; invalid withheld rows can produce a sparse page. The read-only preview uses the shared identity/threshold guards and never registers or extends a window.
- The generic endpoint is an account-owned digital-win endpoint. Arbitrary `ticket_number`/`verification_code` strings are now explicitly prohibited instead of being silently accepted as verified physical possession. Account-owned confirmed digital receipts remain supported. This does not invent a paper-secret authentication protocol. Failed validation does not flash such sensitive strings to session old input.

## Operator workflow and compatibility

1. An active player submits only the original `bet_id` to `/api/v1/prize-claims`. New claim submission does not move money.
2. For UnderReview, an authorized independent staff member opens `/admin/player-prize-claims/{payout}`, verifies the original owned winning bet and exact money/currency, and posts `decision=approved|rejected`, accepted `original_facts_verified`, and a 15–1000 character `note`.
3. An Approved player assertion can enter the existing separate financial maker/checker workflow. The original destination remains immutable there. Review alone never authorizes execution.
4. Existing immutable batch membership and atomic database queue routing choose the canonical wallet executor or the external **record-only** route. External result completion still requires independent review of the original private archive and the original reference. Human-uploaded archives remain `human_uploaded`, not provider-authenticated proof.
5. Only actual canonical completion changes the player claim to Paid. A timeout, expiry run, status-only disbursement or staff approval is not payment proof.

The domain review signatures now require a rationale and explicit original-facts confirmation: `approve(payoutId, operatorUserId, reason, originalFactsVerified)` and `reject(...)`. All in-repository new callers use these signatures. Callers must not supply an arbitrary staff ID as a substitute for actual authenticated authorization.

There is no new schema migration in Part34. All earlier membership/reference/archive migrations must already be present before deploying these writers. Release all web/worker instances together. This sandbox migrated only disposable test stores; it did not migrate production. Do not blindly reseed customized production roles: catalogue and grant the new duty through an approved access-control change.

Existing legacy claims/windows without original snapshot proof intentionally become controlled-review holds; they are not auto-backfilled, reset, approved, expired or paid by inference. Reconciliation must establish original authoritative facts and preserve unknown outcomes before any separately approved migration. Retain financial records, evidence and non-expiring claims during rollout. The default 730-day eligibility policy is configurable operator policy, not a statement of universal law. Currency support and worldwide architecture do not mean every operator, payment rail or jurisdiction is authorized.

Per-row reads/writes are chunked, but a run's compatibility response still collects its annotation event list; no deployment-scale memory/query-volume certification is claimed. `lapsedClaimCount()` is a read-only parsed candidate diagnostic, not a locked eligibility certificate. Candidate pagination intentionally does not scan an entire database to fabricate a precise valid-history count.

## Executed local acceptance

| Check | Final result |
|---|---|
| Complete default PHPUnit | 2954 tests / 132741 assertions; exit0, zero failures/errors; 53 explicit skips |
| Entire Payment directory on MariaDB | 479 tests / 1926 assertions PASS; no skips |
| Covered payout/common/archive/player-claim SQL profile | 3 × 11 tests / 203 assertions PASS; 33 direct-parent decisive-index wait witnesses |
| New claim contention cases, each iteration | Two reviews, two expiry workers, expiry versus late intake, and expiry versus managed wallet completion; both independent children directly wait on the parent-owned payout PRIMARY lock |
| Existing all-race financial profile | 3 × 27 tests / 600 assertions PASS; 23 owned waits each iteration |
| Corrected existing Batch7 HTTP suite on MariaDB | 12 tests / 74 assertions PASS |
| Corrected Batch7 plus generated-source static contract on SQLite | 15 tests / 1082 assertions PASS |
| Offline Python guards | 86 PASS |
| PHP syntax / focused Pint | 1965 PHP-suffixed source/view files / 35 tranche PHP-suffixed files including Blade PASS |
| Rust locked tests / release build | 27 tests PASS / PASS |
| Frontend locked install / final release build | PASS / PASS |
| Static source preflight / generated inventory | PASS, no missing imported application classes / current byte hashes PASS |

Matrices overlap; these are not unique-coverage totals. The 53 default skips are explicit server/recovery profiles and the existing simulation, not passed tests. The 4 newly added claim server cases execute in the explicit MariaDB profile. Redis/worker restart/SIGKILL recovery profiles, hosted CI, live-provider and multi-host operations were not rerun or certified; older evidence remains historical.

Validation corrections are retained honestly. The first complete run failed because an older Batch7 helper assigned `draw.completed_at` only in memory without saving it, and because the generated source inventory still held previous bytes. The helper now persists its original timestamp; application guards were not weakened or test expectations skipped. The inventory was regenerated from current bytes. The complete suite then reran successfully. Earlier focused failures during development are not represented as final successes. No application source changed after the final complete default run; subsequent work records evidence and packages source.

`evidence/runtime-part-34.txt` embeds exact final logs, JUnit status/skip summaries, all 33 payout/common/archive/player-claim witnesses, and the complete existing financial race report. Archives exclude dependencies, builds, DB state, private encrypted objects and credentials.

## Remaining work — not a whole-platform 100% claim

1. Approved provider-specific authenticated final-result/callback adapters and actual authorized acceptance. No provider, merchant, credentials, jurisdiction or live transfer was selected or authorized. Private human uploads and synthetic tests do not authenticate a bank or prove real delivery.
2. Other unmanaged legacy prize/reversal/cancellation/reporting writers, inbound/deposit policy and common-reference integration, and controlled historical reconciliation remain separate audited work. The newly covered generic player-claim path is fixed; this is not a blanket certification of every namespace. A separately approved physical-possession verification path must not be replaced by accepting arbitrary secrets in this digital endpoint.
3. Deployment-scale query/index/memory review, production rollout/migrations, multi-host/shared private storage, key backup/rotation/restore drills, legal retention and operator/country/KYC/AML/tax/liquidity policy, monitoring and hosted CI remain. No automatic evidence purge, uncertainty resend, claim reset, takeover or hold release was added.

Continue from File602 with the complete 448-path source overlay. Preserve original financial/evidence identities and unknown outcomes; do not restore the older 406- or 419-path overlay over these Part34 changes.
