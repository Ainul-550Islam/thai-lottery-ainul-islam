# Thai Lottery — Enterprise Gap Analysis, Security Audit & Structural Fix

**Repository:** `Ainul-550Islam/thai-lottery-ainul-islam` @ `f5cec15`
**Audit date:** 2026-10-07
**Scope:** 2,038 files · ~271,800 LOC · 1,748 PHP files · 110 migrations · 171 test classes
**Reviewer roles applied:** Principal Enterprise Architect · CISO · Fintech Financial Engineer

---

## 0. Executive Summary — read this first

This codebase is **substantially better than most "lottery platform" codebases**, and it is also **not safe to take real money with today**. Both statements are true and the second one is the one that matters.

**What is genuinely good, and should not be rewritten:**

- A real double-entry ledger (`LedgerPostingService`, `LedgerEntry`, `LedgerAccount`) where **debits must equal credits before any row is written** (`LedgerBalanceValidator`), `balance_after` is deliberately non-fillable, and a transaction cannot be posted twice.
- `WalletService` has **no `setBalance()` and no `forceBalance()`**. All eight mutators bump a `version` column and require an open transaction (`assertInsideTransaction()` enforces `DB::transactionLevel() >= 1`). `Wallet::$fillable` carries only `user_id`, `type`, `currency` — balances are not mass-assignable.
- **261 uses of `lockForUpdate()` across 40+ files** and 253 `DB::transaction()` calls. Concurrency was actually thought about.
- `Money` (bcmath throughout) — no float touches a balance anywhere in the financial core.
- Idempotency keys on money-in with a **UNIQUE index** behind them; a retried provider delivery cannot credit twice.
- **90 throttle bindings** across `routes/api.php` and `routes/web.php`, including a dedicated `throttle:bet`, `throttle:deposit`, `throttle:withdrawal`.
- `ProductionSafetyServiceProvider` **refuses to boot production** with `APP_DEBUG=true`, `QUEUE_CONNECTION=sync`, non-expiring Sanctum tokens, fixture lanes enabled, or `LIVE` payout mode without an approval threshold. This is genuinely uncommon and it is the single best piece of engineering in the repository.
- Database-level CHECK constraints (`locked_balance >= 0`, `locked_balance <= balance`, `net_amount >= 0`).

**The five things that block production, in severity order:**

| # | Finding | Severity |
|---|---|---|
| **P0-1** | **The GLO four-eyes write boundary was reverted to a broken state by the "restore" commit.** Both halves of the control are currently non-functional: ingestion writes `draw_results` directly with `published_at = now()`, and confirmation does not check that the confirming operator differs from the ingesting one. A single operator — or an unattended command — can publish an official result with zero verification. | **P0** |
| **P0-2** | **Inbound webhooks have no replay protection.** A captured valid webhook can be replayed indefinitely. | **P0** |
| **P1-1** | **Settlement cannot scale.** `RealPrizeSettlementService::settle()` is one transaction that `LOCK FOR UPDATE`s every bet row and eager-loads ticket+user for each. At 100k slips this is ~100k locked rows, 400-800 MB of hydrated models, and ~100k N+1 queries inside a single transaction against a 50s default lock-wait timeout. | **P1** |
| **P1-2** | **No production deployment layer exists.** Zero Dockerfiles, zero compose files, zero nginx config, zero php-fpm config, no Horizon, no Reverb. | **P1** |
| **P1-3** | **The result-provider "fallback" is a `? :` switch.** Two providers, no secondary gateway, no manual signed ingestion, no circuit breaker. | **P1** |

I have written and verified everything that follows. Where I could not verify something, I say so explicitly rather than asserting it.

---

## 1. 📊 Enterprise Gap Matrix (Your Code vs World-Class Live Benchmark)

| Component / Feature | Current Code Status | World-Class Benchmark Standard | Identified Gap | Severity |
|---|---|---|---|---|
| **Double-entry ledger** | ✅ Real. `LedgerPostingService::post()` writes balanced entry sets; `LedgerBalanceValidator` refuses unbalanced rows pre-write; `assertNotAlreadyPosted()`; account balances rolled forward in-transaction | Immutable append-only ledger; balance derived, never authoritative | Ledger is **not bi-temporal** — there is no `valid_from`/`valid_to`/`recorded_at` pair, no as-of query. `wallets.balance` is an authoritative mutable column reconciled against the ledger, not derived from it | **P2** |
| **Wallet mutation path** | ✅ No `setBalance`/`forceBalance`. `$fillable = [user_id, type, currency]`. All mutators version-bump inside a transaction | Same | **No gap.** This is already world-class | ✅ |
| **Concurrency locks on bet placement** | ✅ `BetPurchaseWalletService::lockForDebit()` takes the wallet row lock FIRST, inside the transaction; balance re-checked against the locked row; `BetPurchaseTransactionService` classifies deadlock/lock-timeout as retryable | Redlock **or** DB pessimistic locks, in a fixed global order | **No gap on correctness.** Lock order is documented and deliberate. No Redis lock — see next row | ✅ |
| **Redis distributed lock (Redlock)** | ❌ **Redis is never referenced by application code.** The only match in 1,748 files is a comment at `app/Services/Queue/QueueHealthService.php:91`. `CACHE_STORE=file` in `.env.example` | Redis Cluster for locks, cache, queue, sessions | Brief's Redlock requirement unmet. DB pessimistic locking is a defensible substitute and arguably safer for money, but `CACHE_STORE=file` means rate-limit buckets and idempotency caches are **per-node** — they do not work behind more than one instance | **P1** |
| **Transaction isolation** | ❌ No `SET TRANSACTION`, no `READ COMMITTED`, no `SERIALIZABLE` anywhere (grep: 0 hits) | Explicit isolation per money lane | Isolation is whatever the engine defaults to. On MySQL that is REPEATABLE READ, where `lockForUpdate()` reads can still see a stale snapshot at transaction start | **P2** |
| **🛑 P0 Write-Boundary / 4-Eye Principle** | ❌ **BROKEN.** `GloResultImportService::persistPayload()` calls `DrawResult::create()` directly with `'published_at' => now()` (lines 118-195). `DrawResultConfirmationService::confirm()` has **no** `ingested_by !== $operatorId` guard | Ingester ≠ confirmer, enforced in code and provable by test | **Both halves of the control were deleted by commit `f5cec15`.** A single operator can ingest and publish. `P0-GLO-WRITE-BOUNDARY.patch` exists in the repo root and is **UNAPPLIED** | **P0** |
| **Result ingestion lifecycle guard** | ❌ The direct write path never calls `DrawResultIngestionService::mayIngestIn($state)` | Refuse ingestion into Open/Settled draws | A result can be written into a draw that is still accepting bets, or over one already settled | **P0** |
| **Fingerprint fail-closed** | ❌ The `$fingerprint === ''` → `failed` guard was deleted in `f5cec15` | An unpinnable payload is refused | A payload with no fingerprint falls through to the write | **P0** |
| **Multi-provider result fallback** | ❌ `GloResultImportService::provider()` (lines 42-47) returns `$mode === 'official' ? official : fixture` | Official → certified secondary → manual signed ingestion, with circuit breaker | It is a **switch, not a ladder**. No secondary gateway class exists. No manual signed-ingestion path exists. No circuit breaker | **P1** |
| **Webhook signature** | ⚠️ `VerifyWebhookSignature` verifies HMAC-SHA256 over the raw body using `hash_equals` (correct primitive) | HMAC over `timestamp.body` + nonce + tolerance window | **No timestamp, no nonce, no tolerance window.** A captured webhook is replayable forever | **P0** |
| **Webhook replay defence** | ❌ None | Nonce cache (`SET NX`) + DB unique index | Unlimited replay traffic; every replay costs a full DB transaction before idempotency discards it | **P0** |
| **Automated payout settlement at scale** | ❌ `RealPrizeSettlementService::settle()` (line 101) = ONE transaction. `lockedBets()` (line 721) = `->with(['ticket','user'])->lockForUpdate()->get()` over **all** bets. `lockedItems()` = one query per bet | Chunked, resumable, parallel workers; bounded transaction per chunk | Cannot settle 100k slips. Lock-wait timeout aborts the run mid-way with the lifecycle already moved | **P1** |
| **Prize matching chunking** | ⚠️ `MatchDrawPrizesJob` chunks at 50 (line 101) — correct — but settlement does not | Both chunked | Partial: matching scales, settlement does not | **P1** |
| **Queue topology** | ⚠️ 46 jobs, **23 implement `ShouldQueue`** — the other 23 run inline. `QUEUE_CONNECTION=sync` in `.env.example`, `database` as config default | Redis queue, dedicated lanes, Horizon | No queue lanes, no balancing, no autoscaling. `ProductionSafetyServiceProvider` correctly refuses `sync` in production, but nothing supplies a working alternative | **P1** |
| **Queue failover / DLQ** | ❌ `failed_jobs` table exists; **nothing subscribes to `JobFailed`** | Alerting on money-lane failure; replayable DLQ | A failed settlement chunk is one row in a table nobody reads. Silent | **P1** |
| **Horizon** | ❌ Not in `composer.json` | Horizon with per-lane supervisors | `deployment/supervisor/thai-lottery-worker.conf` is a single-process snippet — fine for a small deploy, not a draw night | **P1** |
| **WebSocket / real-time push** | ❌ 7 `ShouldBroadcast` events; `BROADCAST_CONNECTION=log`; `config/broadcasting.php` states "laravel/reverb is not installed yet" | Result push, countdown, live ticket status over WS | Every event writes to a **log file**. Clients must poll. The brief's "without polling overhead" is currently "with polling only" | **P1** |
| **Draw countdown integrity** | ✅ `HomeCountdownService` + `lottery:tick` scheduled with `withoutOverlapping(10)`, `runInBackground`, `onOneServer`, timezone-pinned | Server-authoritative countdown | No gap in the mechanism. Untested at runtime (no PHP in this sandbox) | ✅ |
| **Rate limiting** | ✅ 54 throttle bindings in `routes/api.php` + 36 in `routes/web.php`, with a dedicated `throttle:bet` on the purchase route | Per-IP + per-user + per-device | **No device-signature dimension.** Limits are IP/user only; a distributed botnet with valid accounts is one bucket per account | **P2** |
| **Input sanitisation / mass assignment** | ✅ Strict DTOs, FormRequests, `$fillable` discipline on financial models | Same | No gap found in the audited paths | ✅ |
| **Audit trail** | ⚠️ `AuditLog` written by `recordAudit()` in ingestion, confirmation, and import paths | Immutable, tamper-evident, signed | Audit rows are plain inserts into a mutable table — **no hash chain, no signature, no append-only trigger**. An attacker with DB write access can edit history | **P2** |
| **Production config safety** | ✅ `ProductionSafetyServiceProvider` refuses unsafe production boot | Same | **No gap.** Excellent | ✅ |
| **Docker / compose / nginx / php-fpm** | ❌ `find . -iname "*docker*"` → **empty**. No compose, no nginx.conf, no php-fpm pool | Multi-stage builds, read-only rootfs, non-root, healthchecks, secret files | The entire deployment tier is absent. Brief requirement unvalidatable because the artifacts do not exist | **P1** |
| **Database engine** | ❌ `config/database.php` defaults to **pgsql**; `.env.example` sets **mysql**; CI runs **sqlite**. Three engines across three surfaces | One engine, tested in CI | An untested claim. `GROUP_CONCAT` (used in 8 places) is MySQL-only | **P1** |
| **`GROUP_CONCAT` truncation** | ❌ Used at `FinancialReconciliationService.php:214,215,296,388,608,1023` and `PayoutReconciliationService.php:125` | `STRING_AGG` / explicit `group_concat_max_len` | MySQL truncates at **1024 bytes silently, without error**. Past ~100 amounts a reconciliation reports a discrepancy that does not exist — or misses one that does. The repo's own `FINANCIAL-INTEGRITY-REPORT.md` flags this as unguarded | **P1** |
| **CI health** | ❌ 11 runs, 0 green. CI pins PHP **8.3**; `composer.json` requires **`^8.4`** | Green pipeline on the deployed PHP version | The pipeline cannot install dependencies on its own pinned version. No independent reproduction of any local result | **P1** |
| **Reasoning about itself** | ✅ `FINANCIAL-INTEGRITY-REPORT.md`, `audit.md`, `RUST-RUNTIME-REPORT.md` are **honest** — they say "NOT VERIFIED — RUNTIME UNAVAILABLE" rather than claiming success | Honest status documentation | No gap. Rare and valuable | ✅ |

---

## 2. 🚨 Critical Security & Logic Fixes

### P0-1 — The GLO four-eyes write boundary is currently non-functional

**This is the most severe finding in the audit.** It is not a missing feature; it is a working control that was **deleted by a later commit**.

#### The evidence

```
$ git log --oneline
f5cec15 feat: restore and sync missing core source code from backup   <-- HEAD
544d319 feat: apply GLO write boundary security patch                 <-- the fix
...

$ git diff 544d319..HEAD -- app/Services/Draw/DrawResultConfirmationService.php
 app/Services/Draw/DrawResultConfirmationService.php | 13 ---
    (13 lines deleted, 0 added)

$ grep -n "ingested_by" app/Services/Draw/DrawResultConfirmationService.php
    (no output — the guard is gone)

$ grep -rn "DrawResultIngestionService" app/Services/Lottery/GloResultImportService.php
    (no output — the delegation is gone)
```

Commit `f5cec15` is titled *"restore and sync missing core source code from backup"*. It touched **147 files (+2,433 / −4,595)**. Alongside genuinely useful restorations, it **reverted the applied security patch** in two files. `P0-GLO-WRITE-BOUNDARY.patch` sits in the repository root — the fix was written, committed, and then undone.

#### Breach A — `GloResultImportService` writes `draw_results` directly

`app/Services/Lottery/GloResultImportService.php`, `persistPayload()`, lines ~118-195. The current code:

```php
// app/Services/Lottery/GloResultImportService.php  (CURRENT — BROKEN)
return $this->db->connection()->transaction(function () use ($drawId, $provider, $payload, $fingerprint, $actor): array {
    $result = DrawResult::query()->where('draw_id', $drawId)->lockForUpdate()->first();

    $attributes = [
        'draw_id' => $drawId,
        'first_prize' => (string) $payload['first_prize'],
        // ...
        'published_at' => $result->published_at ?? now(),   // <-- PUBLISHED, UNVERIFIED
    ];

    if ($result === null) {
        $result = DrawResult::create($attributes);          // <-- BYPASSES INGESTION
    } else {
        $result->fill($attributes);
        $result->save();
    }
    // ...
});
```

Three separate contract violations compose into one severe defect:

1. **Four-eyes bypass.** `DrawResultIngestionService::ingest()` is the only component that writes an ingested result into the `Pending` state awaiting a second operator. Writing `DrawResult` directly skips `Pending` entirely.
2. **Ungated publication.** `'published_at' => now()` means every public surface that treats `published_at IS NOT NULL` as "the official result is live" — results pages, settlement, ticket checking — serves and settles an unverified number.
3. **No lifecycle guard.** The direct write never calls `mayIngestIn($state)`, so a result can be written into a draw that is **still accepting bets**, or over one whose payouts were already computed.

Plus a fourth: the `$fingerprint === ''` fail-closed guard was removed, so a payload whose integrity cannot be pinned is persisted anyway.

#### Breach B — `DrawResultConfirmationService` lost its separation check

`app/Services/Draw/DrawResultConfirmationService.php`, `confirm()`, lines 78-129. The guard that `P0-GLO-WRITE-BOUNDARY.patch` adds is simply absent. The two regressions **compose**: ingestion stopped creating reviewable `Pending` records, and confirmation stopped refusing self-confirmation. There is currently no working four-eyes control on the GLO result path.

#### The fix — exact file locations

The repository's own patch is the correct remediation. Apply it, or apply the two edits below.

**Fix 1 of 2 — `app/Services/Lottery/GloResultImportService.php`**

Replace the entire file with `deliverables/app/Services/Lottery/GloResultImportService.php` (provided alongside this report). It is a complete, drop-in replacement. The essential change is that the direct write becomes a delegation:

```php
// app/Services/Lottery/GloResultImportService.php  (REPLACEMENT — the fix)
use App\Services\Draw\DrawResultIngestionService;

class GloResultImportService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly GloFixtureResultProvider $fixtureProvider,
        private readonly GloOfficialResultProvider $officialProvider,
        private readonly DrawResultIngestionService $ingestion,   // <-- restored
    ) {}

    // ... and in persistPayload(), the direct DrawResult::create() becomes:

        $fingerprint = trim((string) ($payload['fingerprint'] ?? ''));

        // FAIL CLOSED: an `imported` payload with no fingerprint is a provider
        // contract violation, not a reason to relax the check.
        if ($fingerprint === '') {
            $payload['status'] = 'failed';
            $payload['failure_reason'] = 'Provider returned an imported payload without a result fingerprint.';
            $import = $this->recordImport($drawId, $provider, $payload, $actor);

            return ['import' => $import, 'draw_result' => null, 'payload' => $payload, 'ingestion' => null];
        }

        // Hand the payload to the ONE component allowed to write an ingested
        // result. It owns the row lock, the lifecycle guard, the canonical
        // fingerprint and the Pending state.
        $ingestion = $this->ingestion->ingest(
            $drawId,
            [
                'first_prize'        => (string) ($payload['first_prize'] ?? ''),
                'bottom_two'         => (string) ($payload['bottom_two'] ?? ''),
                'tiers'              => (array) ($payload['tiers'] ?? []),
                'n3'                 => (array) ($payload['n3'] ?? []),
                'provider_fingerprint' => $fingerprint,
            ],
            source: sprintf('glo_import:%s', $provider->name()),
            actorUserId: $actor?->getKey() === null ? null : (int) $actor->getKey(),
        );
```

**Fix 2 of 2 — `app/Services/Draw/DrawResultConfirmationService.php`**

Insert the following block into `confirm()`, immediately after the `pendingRecord()` line and before `$stored = $record['payload'] ?? [];`. This is the **exact** content of `P0-GLO-WRITE-BOUNDARY.patch`:

```php
// app/Services/Draw/DrawResultConfirmationService.php
// INSERT at line 84, inside confirm(), after:
//     $record = $this->pendingRecord($draw, throwNotPending: true);
// and before:
//     $stored = $record['payload'] ?? [];

            $ingestedBy = isset($record['ingested_by']) ? (int) $record['ingested_by'] : null;

            if ($ingestedBy === null || $ingestedBy < 1 || $ingestedBy === $operatorId) {
                throw DrawResultException::confirmationForbidden(
                    $drawId,
                    DrawConfirmationStatus::Pending->value,
                    $ingestedBy === $operatorId
                        ? 'confirmed by the same operator who ingested it'
                        : 'confirmed without an attributable ingesting operator',
                    ['draw_id' => $drawId, 'operator_id' => $operatorId],
                );
            }
```

`DrawResultException::confirmationForbidden()` already exists at `app/Exceptions/DrawResultException.php:127` and is already used elsewhere in the same class — **no new exception class is needed**.

**Verification that the fix is real:**

```bash
grep -c "ingested_by" app/Services/Draw/DrawResultConfirmationService.php   # must be >= 1
grep -c "DrawResultIngestionService" app/Services/Lottery/GloResultImportService.php  # must be >= 1
grep -c "DrawResult::create" app/Services/Lottery/GloResultImportService.php  # must be 0
```

---

### P0-2 — Webhook replay protection

`app/Http/Middleware/VerifyWebhookSignature.php` (current, lines 37-41):

```php
$expected = hash_hmac('sha256', $request->getContent(), $secret);

if (! hash_equals($expected, $provided)) {
```

The primitive is correct (HMAC-SHA256, timing-safe comparison). Two things are missing:

1. **No replay protection.** A captured body + signature stays valid forever. The service layer's idempotency key (`whk:{provider}:{externalId}`) prevents a *second credit*, which is why this is not a direct double-spend. It does **not** stop unlimited replay traffic, does not stop a replayed `refund`/`chargeback` body from re-entering the state machine, and makes every replay cost a full DB transaction before being discarded.
2. **No timestamp tolerance window.** With no signed timestamp there is no notion of "too old to accept".

**Fix:** replace with `deliverables/app/Http/Middleware/VerifyWebhookSignature.php`. It binds the timestamp **into the signed material** — `hash_hmac('sha256', $timestamp.'.'.$body, $secret)` — so the header cannot be edited to extend a signature's life, enforces a 300s tolerance in both directions, and claims a single-use nonce via `Cache::add()` (atomic `SET NX`).

**Companion files required:**
- `deliverables/app/Models/WebhookReceipt.php`
- `deliverables/database/migrations/2026_10_07_000100_create_webhook_receipts_table.php`

**Breaking-change warning:** a provider that cannot sign a timestamp cannot use this middleware. For such a gateway set `tolerance_seconds: 0` explicitly to acknowledge replay protection is off — recording the decision in configuration rather than leaving it as an accident. It is never a safe setting for a gateway that moves money.

---

### P1-1 — Settlement cannot scale to 100,000 slips

`app/Services/Draw/RealPrizeSettlementService.php`:

```php
public function settle(int $drawId): SettlementResult          // line 101
{
    return DB::transaction(function () use ($drawId): SettlementResult {
        $draw = $this->lifecycle->lockForUpdate($drawId);
        // ...
        return $this->performSettlement($draw, $stateBefore);
    });
}

private function lockedBets(int $drawId)                        // line 721
{
    return Bet::query()
        ->with(['ticket', 'user'])       // eager-loads two relations
        ->where('draw_id', $drawId)
        ->whereNull('deleted_at')
        ->orderBy('id')
        ->lockForUpdate()                // FOR UPDATE on EVERY bet row
        ->get();                         // materialises the entire set
}
```

At 100,000 slips this produces:

| Problem | Consequence |
|---|---|
| One transaction holding `FOR UPDATE` on 100k bet rows + related ticket/user rows | MariaDB's default `innodb_lock_wait_timeout` is 50s. Exceeding it **aborts and rolls back the entire run** — and because the draw lifecycle has already moved, it leaves a state needing manual repair |
| 100k hydrated Eloquent models with two eager relations | ~400-800 MB in a single PHP worker → OOM kill mid-settlement |
| `lockedItems()` called per bet (line 737) | ~100k additional queries inside that aging transaction, each holding more locks |
| No checkpointing | A crash at bet 90,000/100,000 loses all of it |

`MatchDrawPrizesJob` chunks at 50 (line 101) — the matching step scales. **Settlement does not.**

**Fix — three new files, provided complete:**

- `deliverables/app/Services/Draw/ChunkedSettlementOrchestrator.php` — a short finalize transaction that locks the draw, asserts the lifecycle, **freezes the participant id range and result fingerprint**, and commits in milliseconds. Then plans a `Bus::batch` of disjoint id-range chunks. Note `allowFailures()` semantics: a chunk that exhausts retries marks the batch failed and leaves the draw un-Settled — partial payout with an unseen hole must be impossible.
- `deliverables/app/Jobs/Draw/SettleDrawChunkJob.php` — one bounded id range per job, own transaction, `chunkById(200)` inside the range so memory stays flat, re-asserts the result fingerprint per row, `$tries = 3` with `backoff = [5, 30, 120]`. Idempotency is preserved because payout credits already flow through `payout:{drawId}:{betId}` keys.
- Chunking changes the **transaction boundary**, not the money semantics.

**One interface you must implement:** `SettleDrawChunkJob::handle()` calls `SelectionSettlementResolver::settleOneBet($settlement, $bet, $drawId)`. Your existing `SelectionSettlementResolver` resolves a single selection; extract the per-bet settlement from `performSettlement()`'s inner loop into that method. The logic is not new — only its transaction scope changes.

---

### P1-2 — The provider "fallback" is a switch, not a ladder

`app/Services/Lottery/GloResultImportService.php`, lines 42-47:

```php
public function provider(): GloResultProvider
{
    $mode = (string) config('glo.official_source.mode', 'fixture');

    return $mode === 'official' ? $this->officialProvider : $this->fixtureProvider;
}
```

Only two providers exist (`GloFixtureResultProvider`, `GloOfficialResultProvider`). If the official endpoint is down on draw night, the import records a failure and **stops**. There is no certified secondary gateway class, no manual signed-ingestion path, and no circuit breaker.

**Fix — three new files, provided complete:**

- `deliverables/app/Services/Lottery/GloResultProviderChain.php` — ordered ladder: Official → Certified secondary → Manual signed ingestion. Ordering is **by authority, not by health**; a healthy secondary is never allowed to answer while the primary might recover, because that is how two different official numbers reach the public in one day. The ladder only advances when a rung has exhausted its retries. **A fixture is never a rung** — a fabricated number reaching a paying player is the one failure mode an apology does not fix.
- `deliverables/app/Services/Lottery/CircuitBreaker.php` — closed/open/half-open with a cache-backed shared view of provider health across all workers. Without it, every import during an outage pays the full connection timeout on the dead primary before falling through, turning a provider outage into a queue backlog.
- `ProviderAttemptRecorder` — a thin audit recorder (implement against your existing `AuditLogService`).

**Contracts to implement:** `ResultSource` (a `GloResultProvider` alias) and `ProviderAttemptRecorder`. Both are one-method interfaces around existing services.

---

### P1-3 — No deployment layer, no Horizon, no DLQ, no WebSocket

These are bundled because they share one root cause: **the operational tier was never written.**

| Missing artifact | Replacement provided |
|---|---|
| Dockerfile (php-fpm) | `deliverables/docker/php/Dockerfile` — multi-stage; runtime stage has **no compiler, no git, no npm, no composer**. Non-root (`app:app`, uid 10001), `tini` for zombie reaping, healthcheck |
| PHP ini / FPM pool | `deliverables/docker/php/php.ini`, `docker/php/php-fpm.conf` — annotated. `opcache.validate_timestamps=0` (valid only because the image is immutable), `bcmath.scale=10`, `request_terminate_timeout=60s`, `pm.max_requests=500` to bound memory drift |
| nginx | `deliverables/docker/nginx/nginx.conf` — TLS 1.2/1.3 + HSTS, edge rate limits (`api_auth` 5r/m, `api_bet` 10r/s), `Cache-Control: no-store` on all `/api/` and `/webhooks/`, WebSocket upgrade path for Reverb, `real_ip` handling (without which every per-IP limit keys on the load balancer's address and collapses to one bucket) |
| compose | `deliverables/docker-compose.production.yml` — 8 services incl. Horizon, **scheduler pinned to `replicas: 1`** (two schedulers double-fire every task), Reverb, and the Rust worker. Secrets are `*_FILE`/env with no defaults |
| Horizon | `deliverables/config/horizon.php` — five lanes: `settlement`, `payouts`, `webhooks`, `notifications`, `default`. The separation *is* the design: a settlement worker never shares a pool with a 30s provider API call |
| DLQ + failure alerting | `deliverables/app/Providers/QueueServiceProvider.php` — subscribes to `JobFailed`, alerts **unconditionally** on money lanes, carries the correlation id onto job logs. Replay is deliberately **not automatic** |
| WebSocket | Reverb service in compose; set `BROADCAST_CONNECTION=reverb`. Requires `composer require laravel/reverb` |
| Rust worker | `deliverables/runtime/settlement-worker/` — complete crate. Read-only, no floats, HMAC verification mirroring the PHP scheme, fingerprint guard before arithmetic |

**On the Rust worker's design, deliberately:** it writes **no** balances, ledger entries or financial transactions. It computes a settlement *plan* and hands it back to PHP, which applies it through the existing transactional, idempotent, ledger-posted path. A bug in Rust therefore produces a wrong **number** that PHP's invariants can still catch — rather than a wrong **balance** that nothing checks.

**`GROUP_CONCAT` hazard** (applies while on MySQL): MySQL truncates `GROUP_CONCAT` at 1024 bytes **silently**. `FinancialReconciliationService` uses it in 6 places to compress ledger amounts that are then summed in PHP. Past ~100 amounts, reconciliation reports a discrepancy that does not exist. The compose file sets `--group-concat-max-len=1048576` as a mitigation. **It is not a fix** — moving to PostgreSQL (`STRING_AGG`) removes the bug class entirely.

---

## 3. 📄 New Essential System Files

All files are complete and written to `/home/user/deliverables/`. Copy the tree over your repository root.

```
deliverables/
├── THAI-LOTTERY-ENTERPRISE-AUDIT.md          ← this document
│
├── app/
│   ├── Http/Middleware/
│   │   └── VerifyWebhookSignature.php        ← P0-2: replay-proof (timestamp+nonce)
│   ├── Jobs/Draw/
│   │   └── SettleDrawChunkJob.php            ← P1-1: one bounded id range per job
│   ├── Models/
│   │   └── WebhookReceipt.php                ← P0-2: replay observability
│   ├── Providers/
│   │   └── QueueServiceProvider.php          ← P1-3: DLQ alerting + correlation
│   ├── Services/
│   │   ├── Draw/
│   │   │   └── ChunkedSettlementOrchestrator.php  ← P1-1: freeze → batch → finalize
│   │   └── Lottery/
│   │       ├── GloResultImportService.php    ← P0-1: RESTORED write boundary
│   │       ├── GloResultProviderChain.php    ← P1-2: authority-ordered ladder
│   │       └── CircuitBreaker.php            ← P1-2: shared provider health
│
├── config/
│   └── horizon.php                           ← P1-3: five-lane queue topology
│
├── database/migrations/
│   └── 2026_10_07_000100_create_webhook_receipts_table.php
│
├── docker/                                    ← P1-3: entire deployment tier
│   ├── php/{Dockerfile,php.ini,php-fpm.conf}
│   ├── nginx/nginx.conf
│   └── rust/Dockerfile
├── docker-compose.production.yml
│
└── runtime/settlement-worker/                 ← Rust hot path (read-only)
    ├── Cargo.toml
    └── src/main.rs
```

**Registration you must do manually:**

```php
// bootstrap/providers.php — add:
App\Providers\QueueServiceProvider::class,

// bootstrap/app.php — the webhook middleware alias already exists;
// confirm it maps to the new class (no change needed if it points at
// App\Http\Middleware\VerifyWebhookSignature)

// config/broadcasting.php — set the default connection:
'default' => env('BROADCAST_CONNECTION', 'reverb'),
```

```bash
composer require laravel/horizon laravel/reverb
```

---

## 4. 🧪 Automated Test & Verification Commands

> **Honest limitation.** This sandbox has **no PHP, no Composer and no Cargo** (Python 3.13 and Node 20 only). **I did not execute a single test in this audit.** Every command below is written to be run by you, in an environment that has the toolchain. Do not read any statement in this document as a runtime verification result.

### 4.1 Prove the P0-1 fix (run FIRST — this is the regression gate)

```bash
# ── The four-eyes guard must exist ────────────────────────────────────────
grep -c "ingested_by" app/Services/Draw/DrawResultConfirmationService.php
# EXPECT: >= 1     FAIL: 0 means the guard was reverted again

# ── The import path must not write draw_results directly ──────────────────
grep -c "DrawResult::create\|->save()" app/Services/Lottery/GloResultImportService.php
# EXPECT: 0

# ── The import path must delegate to ingestion ────────────────────────────
grep -c "DrawResultIngestionService" app/Services/Lottery/GloResultImportService.php
# EXPECT: >= 1

# ── No write path may set published_at outside confirmation ───────────────
grep -rn "published_at" app/Services/Lottery/GloResultImportService.php
# EXPECT: empty

# ── Add this as a permanent CI gate ───────────────────────────────────────
cat >> .github/workflows/ci.yml <<'YAML'
      - name: P0 write-boundary regression gate
        run: |
          set -euo pipefail
          fail=0
          grep -q "ingested_by" app/Services/Draw/DrawResultConfirmationService.php \
            || { echo "::error::four-eyes guard missing on DrawResultConfirmationService"; fail=1; }
          if grep -q "DrawResult::create" app/Services/Lottery/GloResultImportService.php; then
            echo "::error::GloResultImportService writes draw_results directly"; fail=1
          fi
          if grep -q "published_at" app/Services/Lottery/GloResultImportService.php; then
            echo "::error::GloResultImportService sets published_at"; fail=1
          fi
          exit $fail
YAML
```

### 4.2 Apply and verify the repository's own patch

```bash
git apply --check P0-GLO-WRITE-BOUNDARY.patch && echo "patch applies cleanly"
# If it does not apply (the restore commit changed context), apply the two
# edits in section 2 by hand — they are reproduced verbatim there.
```

### 4.3 PHP test suite

```bash
composer install
cp .env.example .env && php artisan key:generate

# Static contract + unit + feature (SQLite, as CI does)
php artisan test --parallel

# The money suites specifically
php artisan test --filter=Settlement
php artisan test --filter=Wallet
php artisan test --filter=Ledger
php artisan test --filter=Financial
php artisan test --filter=DrawResult

# Style — must be clean before any PR
vendor/bin/pint --test
```

### 4.4 New behavioural tests you must add

```bash
php artisan make:test --unit Draw/FourEyesWriteBoundaryTest
php artisan make:test --unit Finance/LedgerBalanceInvariantTest
php artisan make:test --feature Security/WebhookReplayTest
php artisan make:test --feature Draw/ChunkedSettlementTest
```

**Four assertions that must exist — each maps to a P0/P1 in this report:**

```php
// tests/Unit/Draw/FourEyesWriteBoundaryTest.php
public function test_the_ingesting_operator_cannot_confirm_their_own_result(): void
{
    $draw   = Draw::factory()->create();
    $result = app(DrawResultIngestionService::class)->ingest(
        $draw->id, ['first_prize' => '123456', 'bottom_two' => '99'], 'operator', actorUserId: 7,
    );

    $this->expectException(DrawResultException::class);
    app(DrawResultConfirmationService::class)->confirm(
        $draw->id, operatorId: 7, claimed: ['first_prize' => '123456', 'bottom_two' => '99'],
    );
}

public function test_a_different_operator_can_confirm(): void
{
    // same setup, operatorId: 8  ->  must succeed and publish
}

// tests/Unit/Finance/LedgerBalanceInvariantTest.php
public function test_an_unbalanced_entry_set_is_refused_before_any_row_is_written(): void
{
    // post debit 100.00 / credit 99.99  ->  LedgerBalanceValidator must throw
    // and ledger_entries must remain empty
}

// tests/Feature/Security/WebhookReplayTest.php
public function test_the_same_signed_webhook_delivered_twice_is_refused_the_second_time(): void
{
    // POST identical signed payload twice; expect 200 then 409
}
```

### 4.5 Concurrency proof — the only test that proves a race shield

These must run against **MySQL or PostgreSQL**, never SQLite. SQLite serialises writes at the file level and will make any race test pass regardless of your code.

```bash
php artisan migrate:fresh --seed --database=mysql

# 200 concurrent bet placements from a wallet that can afford 50.
# EXPECT: exactly 50 succeed, 150 fail with InsufficientBalance,
#         final balance >= 0, and SUM(ledger debits) == SUM(credits).
seq 1 200 | xargs -P 200 -I{} curl -s -o /dev/null -w "%{http_code}\n" \
  -X POST http://localhost/api/v1/bets/purchase \
  -H "Authorization: Bearer ${TOKEN}" -H 'Content-Type: application/json' \
  -H "Idempotency-Key: race-{}" \
  -d '{"draw_id":1,"selections":[{"number":"123456","tier":"first","stake":"100.00"}]}' \
  | sort | uniq -c

# Post-conditions — both must return 0
php artisan tinker --execute="
  echo \App\Models\Wallet::where('balance','<',0)->count();           // 0
"
mysql -e "SELECT ABS(SUM(CASE WHEN type='debit' THEN amount ELSE -amount END)) AS drift
          FROM ledger_entries;" thai_lottery
# EXPECT: 0.00
```

### 4.6 Idempotency and replay

```bash
# Same idempotency key twice: second call must replay, not re-debit.
for i in 1 2; do
  curl -s -X POST http://localhost/api/v1/bets/purchase \
    -H "Authorization: Bearer ${TOKEN}" -H 'Content-Type: application/json' \
    -H "Idempotency-Key: fixed-key-000000000001" \
    -d '{"draw_id":1,"selections":[{"number":"123456","tier":"first","stake":"100.00"}]}'
done
# EXPECT: identical body both times; exactly ONE bet row and ONE ledger debit.

# Webhook replay: second identical delivery must be 409.
curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost/webhooks/stripe \
  -H "X-Signature: ${SIG}" -H "X-Timestamp: ${TS}" -d "${BODY}"
# EXPECT: 200 then 409
```

### 4.7 Docker / production stack

```bash
docker compose -f docker-compose.production.yml config >/dev/null && echo "compose valid"
docker compose -f docker-compose.production.yml build

# Verify the runtime image has no compiler (the point of multi-stage)
docker compose run --rm app which gcc cc g++ make git
# EXPECT: not found for every one

# Verify all containers reach healthy
docker compose -f docker-compose.production.yml up -d --wait

docker compose exec app  php artisan optimize
docker compose exec app  php artisan migrate --force --isolated
docker compose exec app  php artisan about
docker compose exec app  php artisan horizon:status     # EXPECT: running
docker compose exec app  php artisan queue:failed       # EXPECT: empty

# Prove the P0 regression gate inside the built image
docker compose exec app sh -lc '
  grep -q ingested_by app/Services/Draw/DrawResultConfirmationService.php &&
  ! grep -q "DrawResult::create" app/Services/Lottery/GloResultImportService.php &&
  echo "P0 write boundary OK"'

# Production safety provider must refuse a bad config
docker compose exec app sh -lc 'APP_DEBUG=true php artisan about; echo "exit=$?"'
# EXPECT: a hard boot failure naming the violation
```

### 4.8 Rust worker

```bash
cd runtime/settlement-worker
cargo build --release
cargo test                        # 9 tests: leading zeros, unknown tier, decimal sum, HMAC
cargo clippy --all-targets -- -D warnings
cargo audit

# Live smoke test
DATABASE_URL=postgres://... WORKER_BIND=0.0.0.0:9101 ./target/release/settlement-worker &
curl -sf localhost:9101/health | jq .        # EXPECT: {"status":"ok"}
```

### 4.9 CI fixes — the pipeline must go green

The pipeline pins **PHP 8.3** while `composer.json` requires **`^8.4`** — it cannot install its own dependencies. Fix, then add the matrix:

```yaml
# .github/workflows/ci.yml
jobs:
  test:
    strategy:
      fail-fast: false
      matrix:
        php: ['8.4']
        database: [sqlite, mysql, pgsql]   # MySQL + PostgreSQL catch GROUP_CONCAT
    steps:
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: mbstring,dom,fileinfo,bcmath,sodium,zip,intl,pdo_mysql,pdo_pgsql,sqlite3,pdo_sqlite
      # ...
      - name: Run suite against ${{ matrix.database }}
        env:
          DB_CONNECTION: ${{ matrix.database }}
          APP_ENV: testing
        run: php artisan test
```

**Add a reconciliation test against MySQL specifically.** This is the only way the `GROUP_CONCAT` truncation is caught:

```php
// tests/Feature/Finance/ReconciliationGroupConcatTest.php
// @group mysql
public function test_reconciliation_is_exact_past_the_group_concat_limit(): void
{
    // 500 ledger entries -> the concatenated list exceeds 1024 bytes.
    // With the default group_concat_max_len this test FAILS.
    // It must pass, which proves the mitigation is in place.
}
```

Add `--group mysql` to a dedicated CI leg and exclude it from sqlite runs.

---

## 5. Prioritised Remediation Sequence

**Before anything else — this week:**

1. **Apply the P0-1 fix** and add the regression gate to CI. A single operator publishing the official result is the finding that makes everything else academic.
2. **Apply the P0-2 webhook middleware** + migration. Then tell every payment provider to sign a timestamp. Providers that cannot: set `tolerance_seconds: 0` *deliberately and in writing*.
3. **Fix CI** — pin PHP 8.4, add a MySQL leg, re-enable the pipeline. Zero green runs in 11 means no finding in this report is independently reproducible, including the fixes.

**Before taking real money:**

4. **Chunk the settlement** (P1-1) and prove it at 100k selections with a load test.
5. **Build the deployment tier** (P1-3) and stand up Horizon + Reverb.
6. **Move `CACHE_STORE` and `SESSION_DRIVER` to Redis.** With `file`, rate-limit buckets and idempotency caches are per-node — they silently stop working the moment you run two instances. This is a correctness bug, not a performance one.
7. **Set an explicit transaction isolation level** per money lane. Do not inherit an engine default you did not choose.

**Then — hardening:**

8. Build the provider ladder (P1-2) and circuit breaker.
9. Add a device-signature dimension to the bet throttle. 90 throttle bindings are per-IP/per-user; a botnet with valid accounts is one bucket per account.
10. Make `AuditLog` tamper-evident — hash-chain each row to its predecessor, or add an append-only trigger.
11. Migrate to PostgreSQL **as a tested project**, not a config flip. Port the 8 `GROUP_CONCAT` sites to `STRING_AGG` first.
12. Make the ledger bi-temporal (`valid_from` / `valid_to` / `recorded_at`) if as-of reconstruction is a regulatory requirement in your jurisdiction.

---

## 6. What I Could Not Verify — stated plainly

Consistent with the honesty of this repository's own `audit.md`, `RUNTIME-VERIFICATION-REPORT.md` and `FINANCIAL-INTEGRITY-REPORT.md`:

| Claim | Status |
|---|---|
| Tests pass | **NOT VERIFIED.** No PHP/Composer in this sandbox. Not one test was executed. |
| The stated 100k/seconds settlement target is met | **NOT VERIFIED, and cannot be met** by the current single-transaction design (P1-1). |
| Docker configs work | **NOT VERIFIED.** They were authored in this audit and are syntactically sound, but no image was built and no container started. |
| Rust worker compiles and its tests pass | **NOT VERIFIED.** No Cargo toolchain here. `RUST-RUNTIME-REPORT.md` records the same limitation for the existing integrity crate. |
| Race conditions are eliminated under MariaDB | **NOT VERIFIED.** 261 `lockForUpdate()` sites and a documented lock order are strong evidence of correct intent; they are not a concurrency test. Section 4.5 is the test that would establish it. |
| The reverted-patch regression is confined to those two files | **PARTIALLY VERIFIED.** `git diff 544d319..HEAD` was inspected for the finance/controller/service subset (15 files). The full 147-file restore commit was not diffed file by file. **Treat it as likely that other reverts exist** and diff it yourself: `git diff 544d319..HEAD --stat`. |

**The single most important sentence in this report:** a commit titled *"restore and sync missing core source code from backup"* silently deleted a security patch that a previous commit had deliberately applied, and nothing in the pipeline noticed — because the pipeline has never been green. That failure mode will recur until CI gates the invariant, which is why section 4.1 is the first command in this document.
