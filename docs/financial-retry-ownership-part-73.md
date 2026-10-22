# Part73 — financial retry ownership and statement-timeout cleanup

## Implemented application fixes

`FinancialTransactionService::executeWithDeadlockRetry()` now captures entry ownership once, after the shared non-owning counter/write-PDO consistency check. A caller-owned root never grants local retry authority, even if the callback later loses or rolls back that root. Divergent entry is refused before invoking the callback or issuing callback SQL.

For standalone work, another attempt is permitted only after its callback has returned the same default connection/write PDO to coherent empty state. Active or divergent transactions, switched connection/PDO identity, exhausted attempt budgets, unsupported drivers, and noncanonical error codes are not retried. The original query/wrapped exception propagates rather than being replaced by a retry or boundary-adoption attempt.

The callback still owns its **complete SQL transaction boundary**. The canonical `execute()` and original-bound `reverse()` callbacks already use `WalletLockService::withinTransaction()`. The retry helper does not open, repair, adopt, or silently commit a transaction. Arbitrary autocommit work, provider/network I/O and manual transaction-management callbacks are outside its contract. Current empty state cannot prove that an arbitrary callback did not commit earlier facts; this is not historical transaction-identity attestation.

## Canonical native error authority

Only a MySQL-family write PDO and an integer or string driver code `1213` (deadlock) or `1205` (lock wait timeout) can grant retry. A message containing “deadlock” or “lock wait timeout” is not authority. Laravel `DeadlockException` wrappers are followed only to their causal query exception. Unknown codes, message-only faults, unrelated exception types and SQLite synthetic MySQL codes are refused rather than retried.

The existing configurable attempt budget and backoff remain. A zero or negative configured budget still means one attempt. Exhaustion preserves the failure and leaves the owned SQL unit rolled back.

## Why nested timeout cleanup needed an application fix

In the pinned Laravel framework, a nested concurrency failure decrements Laravel's counter and throws a `DeadlockException` without rolling back the corresponding savepoint. An InnoDB `1205` normally aborts only the blocked statement, **not** all earlier wallet and journal writes in the root. A caller that catches that exception and commits unrelated work could therefore also commit the failed unit's earlier money changes.

`WalletLockService::withinTransaction()` now catches that specific wrapped nested failure. When the original write PDO still owns a live transaction and Laravel has returned to the entry nesting level, the service rolls back **only its own known savepoint** using the pinned connection grammar. It neither resets counters nor rolls back the caller's root. The original wrapped failure is then rethrown. Direct-join mode, which intentionally delegates rollback policy to its caller, remains unchanged.

## A real deadlock is different from a statement timeout

A native `1213` can already have aborted the entire InnoDB root. The new cleanup explicitly recognizes that causal driver code and never sends `ROLLBACK TO SAVEPOINT` in that situation. This distinction is essential: the disposable MariaDB/PDO combination actually reported `PDO::inTransaction() == true` immediately after a real engine deadlock. That stale reported flag must not authorize savepoint SQL against a root the engine aborted.

The service does not turn such a failed caller root into a fresh one. The caller receives the exception and must end/roll back its own failed unit. Standalone canonical work can retry only after its owned wrapper has fully cleaned up the root. This is not a universal poisoned-transaction fence for arbitrary SQL executed after an ignored exception.

## Actual money/journal verification

The feature class has 24 cases covering divergent entry, canonical integer/string error codes, rejection of message-only or unrelated codes, whole-unit owned rollback before retry, caller-owned nonretry, savepoint cleanup, exhaustion, lost caller-root ownership, and a callback that leaves an active transaction. Synthetic driver faults are explicitly code-path tests, not native lock evidence. The native-1213 synthetic case models the engine-wide abort rather than pretending that a timeout-like surviving root is a real deadlock.

Four additional profiles use two independent PHP children on an acknowledged local socket-bound MariaDB test database:

1. Standalone deadlock: both children stage canonical 10.00 credits before deliberately taking opposite test-only wallet locks. A genuine `1213` victim rolls back its whole unit and retries it once. Both final credits exist exactly once.
2. Caller-owned deadlock: the same actual cycle is not locally retried. The victim's caller root is rolled back; only the winner's financial unit commits.
3. Standalone timeout: the parent holds a third wallet row. Both children are observed simultaneously waiting on that actual parent, then both receive genuine `1205` errors while their PDO transactions survive. Parent release permits each owned whole-unit retry to commit once.
4. Caller-owned timeout: the same two actual parent waits and statement timeouts cause no local retries. Both staged credits and journals are removed by the inner savepoint cleanup. Both unrelated caller markers remain committable and are committed.

Each child first stages a complete wallet credit and balanced canonical journal, not merely a label or fake exception. Fixtures are canonically funded from zero. The children use independent THB and BDT ledger accounts so the fixture reaches the intended wallet contention rather than accidentally serializing first on shared same-currency ledger accounts. There is **no FX**, transfer between the wallets, or sum of different-currency money. Each final currency/balance is checked separately, together with exact financial/journal row counts and every committed transaction's balanced posting.

The deliberate opposite lock order is confined to the disposable deadlock probe. Production wallet ordering is not changed. Native wait receipts and native deadlock-cycle receipts are distinct evidence types and must not be relabeled or added to overlapping test-suite counts.

## CI and reproduction

The new native CI gate requires exactly 28 dedicated cases without errors, failures or skips; two actual two-child deadlock cycles; and two observed parent/two-child timeout profiles. Its independent Python omission tests refuse duplicate/skipped cases, missing profiles, fake sessions, unstaged money, caller retries, wrong driver codes, false parent receipts, partial journals, lost caller markers and currency substitution. Existing gates remain. Local workflow parsing is not remote CI execution.

Restore deliberately consolidated exact Git duplicates with the retained recovery CLI, install the locked dependencies and build genuine Vite assets before full regression. The dedicated default feature check is:

```bash
php vendor/bin/phpunit tests/Feature/Finance/FinancialRetryOwnershipTest.php
```

With the isolated local Unix-socket test server configured:

```bash
export APP_ENV=testing
export DB_CONNECTION=mysql
export DB_DATABASE=thai_lottery_test
export DB_SOCKET=/absolute/path/to/disposable/test.sock
export DB_USERNAME=root
export DB_PASSWORD=''
export FINANCIAL_RETRY_WITNESS=/tmp/financial-retry-local.jsonl
php vendor/bin/phpunit tests/Feature/Finance/FinancialRetryOwnershipTest.php tests/Feature/Finance/FinancialRetryOwnershipServerTest.php --log-junit /home/user/retry-local.xml
python3 scripts/verify_financial_retry_ci.py /home/user/retry-local.xml "$FINANCIAL_RETRY_WITNESS"
python3 -m unittest discover -s scripts -p test_verify_financial_retry_ci.py -v
```

Use fresh receipt filenames and never append old receipts to a new acceptance run. Full default runs isolate each source file in its own PHP process, SQLite store and `TEST_TOKEN`.

## Boundaries

This does not certify all database drivers, custom connections, arbitrary callback side effects, manual transaction manipulation, ignored poisoned-root exceptions, original transaction identity, crash/power-loss durability, populated-production restores, provider refunds/transfers or worldwide legal approval. An already committed external effect cannot be made safe by retrying SQL. No merchant credential is needed for these application fixes, and no provider was invoked. Current executed evidence is separately recorded in `evidence/acceptance-part-73.json` and `evidence/runtime-part-73.txt`.
