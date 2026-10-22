# Part 9 — contention repair, server-SQL evidence, and actual remaining work

Date: 2026-10-14. Base repository HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.

This is a source overlay on the supplied repository, not a complete repository, deployed product, or production certification. Part 9 delivers File87–File99 in full. The cumulative archive contains 96 unique delivered paths. Previously delivered 83 paths remain byte-identical to the Part 8 archive. Original repository files newly revised here are delivered for the first time, not silently patched outside the manifest.

## Completed in this part

### SQLite purchase contention

The reproduced SQLite snapshot-upgrade failure is repaired with a bounded retry of the complete purchase transaction, not a retry of individual balance, capacity, bet, ticket or ledger writes. Only SQLite driver primary codes 5 and 6 qualify, including extended codes through their low byte. Exception message text alone is not a retry signal.

A retry starts only after Laravel transaction depth is zero **and the PDO connection is outside a transaction**. The existing caller-owned transaction refusal remains. The default retry budget is three, capped at ten; the default delay is 150 milliseconds, with bounded increasing delays capped at 250 milliseconds. The server-SQL transaction path is unchanged. Exhaustion returns the named `bet_purchase_deadlock` failure with the underlying QueryException retained.

The deterministic fault tests throw after capacity reservation and bet creation, so successful outcomes require actual rollback of partially mutated rows. They cover primary busy/locked codes, extended code 517, budget exhaustion, a non-busy error, and caller-owned transaction refusal. Financial assertions cover one purchase/debit/ledger outcome, no leaked reservation or bet, and closed transaction state. The identical-request race now requires exactly purchased/replayed outcomes; it cannot pass merely because a second request was rejected.

This does **not** give SQLite server-style row locks, certify every SQLite workload, or replace production server-SQL testing.

### Real server-SQL race evidence

`evidence/server-sql-concurrency-part-9.json` records three repetitions on the actual disposable MariaDB 11.8.6 server through PDO mysql. All four selected races passed in every repetition, with 30 assertions per repetition and no skips:

1. Two purchases compete for the last cash balance.
2. Two purchases compete for the last number capacity.
3. Concurrent identical purchases produce purchase plus replay, not duplicate debit.
4. Concurrent settlement produces only one settlement writer.

This is four distinct scenarios and twelve test executions, not twelve distinct scenarios. Twelve inspected financial/draw/audit tables were InnoDB. MariaDB evidence is not MySQL 8, PostgreSQL, production replica, failover, or deployment-topology evidence.

The reusable Python runner requires an explicitly acknowledged disposable database named `thai_lottery_test`, an existing absolute Unix socket, an explicit account, a supported server-SQL connection, no cached Laravel configuration, bounded repetitions and prepared schema. Output is restricted to repository `evidence/*.json`. It forces testing connection settings and clears DB_URL. Read-only PDO preflight checks the actual database/server/engines; JUnit validation requires the exact four case names and rejects failures, skips or missing cases. Eight offline guard tests require no server or production credentials.

**Destructive test scope:** the script itself issues no direct DDL, but the ordinary PHPUnit bootstrap can migrate and seed the disposable test schema; fixtures truncate it. The acknowledgement is not proof of isolation. Use a dedicated server/socket and account scoped only to the test database. Do not run against a shared or live database, reuse production credentials, expose the socket publicly, or assume a database name alone makes a server safe.

Example after provisioning a dedicated test server, installing locked dependencies and preparing its schema:

```bash
python3 scripts/run_financial_concurrency.py \
  --connection mysql \
  --database thai_lottery_test \
  --socket /absolute/path/to/disposable/mysql.sock \
  --username scoped_test_account \
  --acknowledge-disposable-database \
  --repeats 3 \
  --json evidence/server-sql-concurrency-part-9.json
```

If required, supply DB_PASSWORD through the environment, not command-line arguments. This example does not provision a database or authorize live operations.

### Console fixtures and test isolation

The automation fixtures now use the real trusted manual-result maker/checker workflow, two distinct authorized operators, and required notes. Negative fixtures reach the intended number/lifecycle guard rather than failing early for a missing operator. Three explicit CLI refusal cases cover a roleless operator, self-confirmation and missing noninteractive confirmation notes. Production commands were not weakened or edited.

The stale production-fixture acknowledgement test now proves the removed bypass option is rejected without result/winning-number/financial-transaction writes. Fixture publication remains forbidden in production; switching a mode label to official does not authenticate a source or make a missing draw exist.

A broader diagnostic uncovered mixed database-isolation behavior: grade snapshot tests passed alone (10/55) but failed after the two preceding console classes (45 cases, four failures). RefreshDatabase wrapping retained fixtures from preceding DatabaseTruncation tests. Grade tests now use guarded DatabaseTruncation, keeping the same exact output, fingerprint, append-only, scope and no-write assertions. The same ordered group now passes 45/204.

The common TestCase also aborts on an unapproved database name during application creation, before framework traits can migrate/truncate and before setup seeders write. Previously returning from schema preparation left later setup writes possible. An unsafe-name **sandbox copy** was refused; its sentinel value and sole table remained unchanged. No production database was contacted. Named-database guards still do not certify physical server isolation or production credential hygiene.

## Validation and interpretation

Full command output, environment failures, pre-repair failures, refusal probe and final checks are preserved in `evidence/runtime-part-9.txt`. The server result has a separate machine-readable report. Static preflight is separately recorded as `evidence/financial_source_preflight-part-9.json` and explicitly does not certify runtime behavior.

| Scoped check | Recorded result |
|---|---|
| Deterministic SQLite rollback/retry | 6 tests, 76 assertions, pass |
| Purchase atomicity, SQLite retry and risk limits | 63 tests, 300 assertions, pass |
| Server-SQL races | 3 rounds, each 4 tests / 30 assertions, no skips |
| Five broad financial/readiness suites plus Payment and Finance | 209 tests, 991 assertions, pass |
| Automation + production fixture guard + ordered grade snapshots | 45 tests, 204 assertions, pass |
| Wallet conversion feature/unit | 71 tests, 259 assertions, pass |
| Standalone conversion foundation | 29 tests, 92 assertions, pass |
| Settlement, Draw and two selected GLO suites | 186 tests, 1,806 assertions, one SQLite server-lock skip |
| Runner offline guards | 8 tests, pass |
| Source-preflight regression guards | 11 tests, pass |
| PHP lint / Pint | Seven delivered PHP files, pass |
| Static financial source audit | Pass, no missing imported App classes in its scope |
| Repository test collection | 2,280 cases; collection is not execution |
| Final whole-repository stop-first diagnostic | 681 executed, 113,004 assertions, one failure; remainder not executed |

Suites overlap. Do not add their counts as unique coverage. Environment recovery included restoring the frontend build and installing GD; initial failures are preserved, not retroactively relabeled as passes. The intentional SQLite server-row-lock skip remains; the same settlement race did execute successfully on the actual MariaDB server.

## What is still actually unfinished

### Immediate next code investigation — File100 onward

The final whole-repository diagnostic stops at:

`Tests\Feature\Filament\DrawResourceTest::test_publishing_writes_the_result_through_the_domain_service`

The Filament publishing action produced no draw_results row, at test line 226. This is the next **observed** blocker, not proof it is the only blocker. Diagnose the operator UI/action against the real ingestion, validation, maker/checker confirmation, authorization and publication workflow. Determine whether the action, fixture, or both need repair. Do not restore raw publication, permit self-confirmation, bypass lifecycle checks, or forge provenance to make this assertion pass. Then rerun the full suite from the beginning, not just the failing test. The rest of the 2,280 collected cases remains uncertified by this diagnostic.

The earlier automation, removed-fixture-option and order-dependent grade failures are repaired in the delivered scope; they are historical diagnostics, not the current first blocker.

### Production/runtime acceptance gates

1. Run the completed financial matrices on the intended **production database version** and topology, with realistic contention, isolation, connection pools, lock timeouts, worker counts, failure/failover and replay handling. MariaDB socket evidence alone does not satisfy this.
2. Certify real queue workers and transactional outbox/recovery behavior. Check crash boundaries, abandoned runs, serial keyset cursor ownership, expected-cursor replay, retry exhaustion, proof-required finalization and scheduler overlap. A local sync queue or scoped race is not durable delivery evidence.
3. Verify the full operator/member browser flows: session/CSRF, validation, role denial, bank evidence upload/review, double submission, wallet quote/expiry/execute/history, maker/checker UI and recovery. HTTP/unit tests and a frontend build are not browser certification.
4. Finish merchant and bank execution only under explicit authorization, verified contracts/credentials, currency/provider eligibility, signed callbacks, reconciliation, refunds/reversals and operational approval. Approved/Paid database labels do not independently prove bank delivery. No live payout was activated here.
5. Verify an authentic official draw source and provenance. Internally confirmed results are **not** `OFFICIAL_SOURCE_VERIFIED`. Do not infer government affiliation or authenticated payment integrations from the reference site's claims/logos.
6. Confirm local licensing, age/KYC/AML rules, permitted jurisdictions, privacy, retention and operational controls with qualified reviewers before handling real customer funds. Public reference fee labels are not authority to alter operator economics.
7. Keep member-to-member legacy transfer fail-closed until recipient, identity, limit, fee, authorization and ledger contracts are defined and certified. Own-wallet Cash↔Win conversion is a distinct operation.
8. Keep the alternate GLO direct wallet writer unconditionally disabled with `glo_l6_direct_wallet_settlement_disabled`. Canonical settlement/payment authority and permissions must not be relaxed.
9. Review deployment configuration, secret management, dependency/build reproducibility, monitoring, backups, least-privilege test accounts, migrations and recovery on a separate staging environment. Pin the settlement/result inventory and preserve quarantine/proof requirements.

## Package boundaries

The cumulative ZIP overlays 96 delivered paths on the original repository. It excludes dependencies, generated frontend assets, vendor/node_modules, .env, uploads, credentials, test databases and disposable server data. It is not a self-contained deployable release. Full source for every Part 9 file appears without elision in `Full-Code-Continuation-Files-87-99.md`; archive entries and manifest hashes must match those bytes. The next label is File100.
