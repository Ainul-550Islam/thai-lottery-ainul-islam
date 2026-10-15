# Remaining-work follow-up f36 — SQLite concurrency and readiness

Historical **f36** checkpoint. The f37 audit and f38 legacy-command continuations are in
`docs/chunked-settlement-preview-status.md` and `docs/legacy-chunked-command-status.md`.
F39 job loading/refusal is in `docs/legacy-chunked-job-status.md`. Current aggregate measurements
are in `docs/ci-regression-status.md`.

Verified 2026-10-14. This continues unfinished work after f35; it does not repeat the completed public
copy, optional encrypted identity, authenticated presence, result intake or durable webhook delivery work.

## Outcome

- **36 previously failing tests are now passing.**
- Direct current-change regression: **116 tests / 757 assertions, all pass, no skips**.
- Expanded regression including all previously delivered changed areas: **1,018 tests / 103,713 assertions,
  all pass, no skips**.
- Unfiltered repository: **2,302 tests / 112,306 assertions, 15 errors, 0 failures, 2 existing skips**.
- The repository is **not fully green or certified for real-money production**.

Evidence: `/home/user/changed-area-f36.log`, `/home/user/part3-focused-f36.log`,
`/home/user/full-f36.log`, and matching JUnit XML files. Full-suite exit is 2; passing subsets exit 0.

## Complete files, beginning to end

These are the nine PHP files added or changed by this follow-up. Their full contents are in the
refreshed bundle, not just the changed lines. No method body, import block, fixture or assertion is elided.

| Complete path | Lines | Purpose |
|---|---:|---|
| `config/database.php` | 101 | SQLite immediate transactions and an actual configurable busy timeout; other database drivers preserved |
| `tests/Unit/Config/SqliteLockingConfigurationTest.php` | 93 | Configured PRAGMA, per-connection override and real two-connection writer reservation |
| `tests/Feature/Betting/BetPurchaseAtomicityTest.php` | 1,387 | Stronger genuine two-process purchased/replayed, debit/ledger/risk invariants |
| `tests/Feature/BusinessCriticalInvariantTest.php` | 403 | All fifteen existing business invariants on supported contracts |
| `tests/Feature/RealMoneyBusinessReadinessTest.php` | 251 | Eight tests: exact prize math, safe claims, holds, reconciliation, gateway boundaries, RG and partial slips |
| `tests/Feature/FinalProductionReadinessTest.php` | 279 | Twelve tests: public/member access, labelled provenance, finance replay, legal stability and health |
| `tests/Feature/FinalWholeSystemNoSkipTest.php` | 277 | Ten local integration tests with no class-level skips or KYC/approval bypass |
| `tests/Feature/P0P1P2ComprehensiveEnterpriseSuiteTest.php` | 433 | Thirteen actual checks; no claim that twenty-five audit headings were all implemented |
| `tests/Support/CanonicalReadinessTestCase.php` | 276 | Disposable, typed fixtures, real private KYC/review, operator number limits and legitimate player purchases |
| **Total** | **3,500** | **Nine complete PHP files** |

The archive also retains the complete earlier delivery and refreshed status/classification documents.
The canonical list and per-member manifest are authoritative for exact bytes and intentional deletions.

## Root cause and fix

AB2 was reproduced before editing: one worker purchased and the other failed with SQLite error 5
while updating `number_limits`. A deferred transaction had read first and could not upgrade its
snapshot to a writer while another process was writing. Merely declaring `DB_BUSY_TIMEOUT` in the
test environment did not apply a PRAGMA because the connection config left that option unset.

SQLite now begins `IMMEDIATE` and applies the non-negative timeout from `DB_BUSY_TIMEOUT` (15,000 ms
by default). The second writer waits at entry and then observes the committed idempotency winner,
rather than trying a late read-to-write upgrade. The transaction/purchase services and retry policy
were not modified.

The AB2 test now requires exactly one `purchased` and one `replayed`, matching persisted identities,
exactly one bet item/ticket/transaction, two balanced ledger entries, one 10.00 debit and one 10.00
risk increment. It passed **eight independent two-process repetitions**, not merely a mocked race.
All **50** purchase-atomicity tests also pass in the direct, expanded and unfiltered runs.

The three new SQLite tests prove the configured PRAGMA, an explicit 2,250 ms connection override, and
that `BEGIN` reserves the writer before either connection reads a row. After rollback, the other
connection can begin successfully. Independent temporary databases are always disconnected and removed.

## Readiness changes and preserved safety

All 58 test methods across the five readiness suites remain present and pass with **518 assertions**.
No production compatibility service, permissive overload or fillable KYC attribute was added.

1. Wallet calls now supply `Wallet`, exact `Money` and the correct transaction type/idempotency key.
2. Webhook service tests start from real pending deposits and use canonical normalized DTOs. Replay
   must leave balance, financial transaction count and ledger count unchanged.
3. The P0 bKash flow uses BDT, test-only credentials, faked provider HTTP and a real signed callback.
   Bank-transfer dispatch stays fail-closed under its existing disabled configuration; it is not
   rewritten into a fake successful payout.
4. Reservations assert stored `locked_balance`, the available-balance split and exactly-once
   consumption. Currency isolation is checked before and after the hold.
5. Withdrawal failure releases an approved hold. It does not pre-debit on request and does not mint a
   refund credit when no debit happened.
6. Claims use private KYC evidence and independent review. Publicly supplied money/status fields do
   not replace server-derived amounts. The existing manual engine test uses a pre-approved disposable
   fixture; the separate canonical claim service's payment record is not misrepresented as a wallet credit.
7. Bulk slips use configured market keys and actual operator-owned number limits. A refused middle
   item does not stop a later valid item; replay charges zero and creates no new tickets or journals.
8. Single-agent tests use an existing, already-accrued fixture backed by a real funded/purchased player
   bet. No referral chain, multi-level accrual or deployed commission automation is enabled. The
   fixture stays inside the current risk ceiling rather than raising it to make the test pass.
9. KYC tests perform real private uploads and independent review. A self-review attempt is refused;
   `kyc_status` remains absent from client-fillable attributes.
10. Responsible-gaming tests activate the exclusion using its actual lifecycle and exercise the real
    betting refusal. Legal versions/effective dates stay stable under a moved clock; health uses the
    current application contract `healthy`.
11. Historical references for National, Weekly, Bingo and PCSO remain distinct on the same date and
    do not fabricate a result version.

Only `config/database.php` changes production runtime behavior in f36. All wallet, purchase, payout,
settlement, withdrawal, KYC and commission service implementations remain unchanged.

## What is still unfinished — no rows hidden

The remaining **15 errors** are unchanged legacy cursor-run expectations in `Draw\ChunkedSettlementTest`:

1. `finalize_refuses_while_bets_remain_past_the_cursor`
2. `the_draw_stays_published_until_the_run_is_finalized`
3. `re_running_a_chunk_does_not_pay_a_bet_twice`
4. `a_completed_run_cannot_settle_the_draw_a_second_time`
5. `the_monolithic_path_refuses_while_a_chunked_run_is_in_flight`
6. `only_one_run_can_exist_for_a_draw`
7. `totals_accumulate_exactly_across_chunks`
8. `an_interrupted_run_resumes_from_its_cursor_and_finishes`
9. `the_chunked_path_pays_only_once_and_only_under_live`
10. `a_run_opened_under_dry_run_keeps_dry_run_even_if_config_changes_mid_run`
11. `the_orchestrator_clamps_the_configured_chunk_size`
12. `the_safety_mode_is_read_at_open_and_not_per_chunk`
13. `percent_complete_reports_progress_and_is_null_without_a_denominator`
14. `the_monolithic_job_stands_aside_while_a_chunked_run_owns_the_draw`
15. `the_monolithic_job_stands_aside_for_an_aborted_resumable_run`

They call missing `start/run/defaultChunkSize` first and then expect resumable cursor/chunk/finalize,
unique run ownership, pinned safety mode and exactly-once LIVE credits. The implemented orchestrator
is a different batch-planning API, not a drop-in replacement. The original class is unchanged; no
LIVE assertion was weakened, no test was hidden and no new payout path was inferred from a skipped
scope question. Individual pure configuration expectations also belong to this unresolved API design.

One other unchanged test in the class broadly expects `Throwable`; a missing method can satisfy it.
Its nominal pass does not certify the refusal or the orchestrator.

The two existing skips are the settlement real-concurrency prerequisite and the unavailable native
Rust verifier. Complete names, types and unshortened details, including every resolved f35 case, are
in `docs/full-suite-failure-classification.json`.

## Limits and continuation boundary

- No live provider credentials, external transfers, cross-host database concurrency, load benchmark or
  hosted CI execution was verified here.
- SQLite `IMMEDIATE` is a single-writer serialization choice, not a claim of high money-write throughput.
- The manual settlement fixture does not certify a canonical public claim-to-wallet category bridge or
  every authorization/finality property of the existing engine.
- No new LIVE payout automation, multi-level commission, invented campaign/counter, government
  affiliation claim or unverified marketing promise is introduced.
- The remaining cursor-run settlement design must stay visible and separately reviewed; it must not be
  "fixed" with aliases, fake success or disabled guards.

See `docs/ci-regression-status.md` for all current and prior group counts and the complete regression
command. See `docs/part3-bundle-usage.md` for verified, complete-file restoration and runtime setup.
