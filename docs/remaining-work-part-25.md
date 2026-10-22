# Part25 — Actual Redis restart recovery and truthful operator queue handoff

Date: 2026-10-15. Base repository HEAD: `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`.
Continuation: complete File304–File322; next File323. These are nineteen complete replacement/new bodies, not a repository export. The cumulative overlay now contains 272 unique paths: 253 earlier bodies preserved, seven intentionally revised and twelve newly delivered paths.

## Actual application defects reproduced and corrected

The new command tests were run against the previously delivered command before changing it. Four selected tests failed with no harness errors: `--queue` incorrectly returned success with sync and null drivers, returned success for an aborted run without explicit resume, and `--resume --queue` accepted sync execution. Separately, the real Redis baseline ran seven tests/317 assertions with one failure: a stopped broker produced an uncaught socket exception from the initial handoff. The first six Redis restart/replay cases already passed; they are not presented as newly repaired application failures.

`SettleDrawChunkedCommand` now:

1. Checks `--queue` transport admission before creating/reopening a run. The configured connection name is resolved to its driver. Database, Redis, SQS and Beanstalkd are admitted asynchronous transport types; this is not a claim that all four received runtime acceptance here. Sync, null, background, deferred, failover and unreviewed custom drivers are refused. Removing `--queue` remains the explicit inline execution path, subject to all existing settlement controls.
2. Refuses to hand off a paused/unknown-status run. An aborted run requires an explicit, reviewed `--resume`. Read-only `--status` continues to work regardless of the queue backend.
3. Uses one immediate `Bus::dispatch` inside a shared catch boundary for initial and resume handoffs. No deferred `PendingDispatch` destructor is responsible for the broker call. An unavailable broker returns exit 1 with a controlled message, not an uncaught exception or success label.
4. Reports an unconfirmed acknowledgement accurately: the broker may already have accepted the delivery. The command does not retry automatically, delete the run, reset its cursor, reveal the underlying socket/credential error, or replay wallet credits.
5. Preserves the existing draw/run identity and recorded cursor. Explicit resume still passes through canonical reopen validation, pinned participants/result/safety policy and existing maker/checker/provenance gates. Queue acceptance is not settlement completion.

The three settlement jobs, Part24 delivery budgets, overlap middleware and all money/fee/conversion services remain byte-identical to the previous delivery. A suspected stale-cache-release defect was **not reproduced** on this PhpRedis runtime: the actual paused stale-client worker recovered without leaking its physical overlap lock. No speculative retry middleware, force-release, money-callback replay or vendor patch was added.

## New real Redis profile

Actual runtime: PHP 8.4.26, PhpRedis 6.2.0, Redis **8.0.2**, MariaDB 11.8.6 through a private Unix socket. The database fixture is strictly `thai_lottery_test`. No Redis TCP listener or external endpoint is used.

Every case starts its own fresh private Redis directory, Unix socket, two logical databases, `appendonly yes`, `appendfsync always`, no periodic RDB snapshot and `maxmemory-policy noeviction`. Each hard restart uses real SIGKILL, verifies the signal, verifies a different Redis `run_id`, reloads a durable marker, and then reaches the asserted terminal money state. Existing workers are paused at precise real SQL/cache events with SIGSTOP. Only parent Redis connections are purged after restart; the paused worker retains its genuinely stale connections.

Seven allow-listed cases are run three times:

| Case | Real boundary and acceptance |
|---|---|
| Queued work | Hard restart before delivery retains the queued message and finishes once. |
| After SQL checkpoint commit | Resume the same stale-client worker after Redis restart; no leaked physical overlap lock and no duplicate credit. |
| Before queue acknowledgement | Restart after the owned cache-lock release and before acknowledgement, then resume the same worker. |
| Manual approval | Restart after commit while the stricter approval policy is pinned; the obligation remains uncredited. |
| Worker death | Kill the stopped worker, restart Redis, recover the expired reservation and consume duplicate-safe continuations. |
| Explicit queue data loss | After hard restart, deliberately FLUSHALL **only this private instance**; the committed SQL cursor remains authoritative. Explicit operator `--queue` handoff repairs the missing message without re-crediting the winner. |
| Broker unavailable | Run the actual command while the owned Redis process is killed; it returns controlled failure, then durable queued work drains after restart. |

The destructive FLUSHALL fixture is a separate data-loss scenario, not evidence that arbitrary queue loss is automatically repaired. The command does not implement an autonomous watchdog or a new transactional outbox. Operators must inspect and republish an open canonical run when messages have been lost.

The default synthetic fixture purchases three 10.00 THB bets from 500.00. Purchases leave 470.00. One 9000.00 winning payout produces 9470.00, exactly one added financial transaction and two added ledger entries. Recovery ends with one completed run/draw, three settled bets and three recorded chunks. Pending approval leaves 470.00, one pending obligation and zero additional credit transactions/ledger entries. Every new recovered case requires an empty financial queue and zero failed jobs.

Application time is advanced only for queue visibility/backoff in these Redis fixtures. Redis cache TTL uses real server time. No claim is made that a Carbon clock expires the 300-second Redis lock, or that these tests waited for an abandoned physical Redis lock to expire. Existing Part24 database-cache recovery has its own separately stated accelerated-clock scope.

## Final executed verification

| Check | Final result |
|---|---|
| New real Redis profile | **3 × 7 tests / 353 assertions**, no skips/errors/failures; seven validated hard-restart witnesses each, 21 total. |
| Existing actual worker recovery refreshed | **3 × 9 tests / 444 assertions**, no skips/errors/failures; seven killed-worker recovery witnesses each. |
| Existing financial races refreshed | **3 × 17 tests / 347 assertions**, no skips/errors/failures; thirteen owner-specific lock-wait witnesses each. |
| New command handoff suite | **12 tests / 91 assertions** on SQLite and separately on MariaDB, both pass. |
| Final complete default regression | **2556 tests / 130585 assertions / 30 explicit server-profile skips**, no errors/failures, process exit 0. |
| Full offline Python guards | **69 pass**, including 21 new Redis runner/evidence guard tests; runner-only discovery is 43 pass. |
| PHP syntax and focused formatting | **1664 PHP files** lint pass; Pint five changed/new PHP files pass. |
| Unchanged native verifier crate | **27 Rust tests** pass; native release executable rebuilt before the PHP regression. |
| Frontend | Locked dependency restoration and production build pass. |
| Source checks | Static financial preflight, exact source inventory, test inventory and whitespace pass. |
| CI declaration | YAML parsed, ten Bash blocks syntax-checked; all three server profiles are serial, Redis dependencies declared, sanitized report uploaded. Hosted CI execution is not claimed. |

The thirty default skips are exactly nine SQL worker recovery cases, nine conversion races, four settlement races, seven new Redis recovery cases and one settlement simulation race. They are covered by the explicitly acknowledged server profiles, not counted as successes in the default run. Overlapping test matrices are not additive unique-coverage counts.

## Reproduce safely on a dedicated test checkout

Install the locked PHP/Node dependencies and build browser assets and the native verifier as required by the base repository. Install a local `redis-server` binary and PHP Redis/pcntl/posix/PDO-MySQL extensions. Prepare a separate socket-only MariaDB/InnoDB schema named exactly `thai_lottery_test`, using a dedicated test account. These fixtures truncate that schema; never point them at customer data. Do not use a deployment `.env` or cached configuration. The profile owns its Redis instances and accepts no operator Redis host/port.

From the repository root, with the database password supplied only through the process environment if needed:

```bash
python3 scripts/run_redis_settlement_recovery.py \
  --database thai_lottery_test \
  --connection mariadb \
  --socket "$DB_SOCKET" \
  --username financial_test \
  --acknowledge-disposable-database \
  --repeats 3 \
  --json evidence/server-redis-recovery-local.json
```

Do not run schema-writing profiles concurrently. The new Python runner requires the exact seven JUnit identities and seven distinct hard-restart witnesses; skipped/missing/duplicate/failed cases, unchanged/reused server incarnations, wrong phases, wrong signal, mixed versions, non-durable AOF claims or accelerated-cache claims cannot pass its evidence validator.

## Operator recovery behavior

For an interrupted draw, inspect `lottery:settle-draw-chunked --draw=ID --status` and the queue/failed jobs first. For an **open** run with a missing delivery, explicit `--queue` republishes the persisted canonical run/cursor. For an **aborted** run, review the failure, then use `--resume --queue` on an admitted asynchronous backend. A controlled handoff failure does not establish that the broker rejected the message; duplicate delivery must continue through the existing idempotent settlement path. Never repair this by deleting settlement/payout/ledger records or manually crediting a winning wallet.

Deployment workers must use the intended queue/cache configuration and be restarted through the normal deployment procedure. This part does not rewrite already serialized envelopes or alter Part24 retryUntil/max-exception semantics.

## Scope still requiring deployment-specific work

This is real disposable-server acceptance and an actual operator-command fix, not live payment activation. No authorized operator, jurisdiction or merchant/currency combination has been established. Multi-country architecture and fail-closed unapproved combinations remain intact; a THB fixture does not prove all countries/currencies. No country gates, permissions, fees, manual approval or disabled member-transfer boundary were weakened.

Redis Cluster/Sentinel, Predis, replicas, network partitions, managed-service ACL/READONLY behavior, remote multi-host failover, power/disk loss, all AOF policies and SQS/Beanstalkd live operation are not certified by this single-node private-socket profile. Payment-provider onboarding, jurisdiction approval, official result-source authentication, production secrets, deployment topology, monitoring and operator acceptance remain separate requirements.

## Delivery preservation

Seven prior paths revised: the operator command, CI workflow, passive inventory script, generated audit/runtime/financial reports and generated inventory JSON. Twelve new paths: six test/runner support sources, three scoped server reports, static preflight JSON, complete runtime evidence and this document. All nineteen delivered bodies are complete; no dependencies, generated browser assets, native binaries, database files, private Redis AOF/socket state, runtime `.env`, production credentials or customer data are included.
