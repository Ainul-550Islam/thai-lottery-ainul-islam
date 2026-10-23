# DEVOPS-NEXT: Incident Response SOP

Standard operating procedure for responding to production incidents. Optimised for fast mitigation over diagnosis; root-cause analysis is performed **after** service is restored.

---

## 1. Severity levels

| Level | Definition | Examples | Response target |
|-------|-----------|----------|-----------------|
| **P1 / SEV1** | Complete outage, data loss, or funds at risk | Site returns 5xx for all users; wallet totals disagree with ledger; settlement worker corrupting payouts | Acknowledge < 5 min; mitigate < 30 min |
| **P2 / SEV2** | Major feature broken for many users, no funds at risk | Login broken; bets cannot be placed; queue backlog growing with no recovery | Acknowledge < 15 min; mitigate < 2 h |
| **P3 / SEV3** | Minor / cosmetic / single-user impact | CSS broken on one page; non-critical alert firing incorrectly | Fix in next business day |
| **P4 / SEV4** | Informational / near-miss | CI flake; log noise; a single retryable failure | Track in backlog |

## 2. On-call response loop (every SEV)

1. **Acknowledge** the page. If you are P2 on the rotation and the P1 does not ack within 5 min, escalate by paging P1 directly.
2. **Open a channel.** Create a thread/channel named `#inc-<yyyymmdd>-<short-slug>` and invite the on-call rotation plus service owners. Post the first message with: time detected, suspected severity, one-line symptom.
3. **Assess.** Spend no more than **5 minutes** reading dashboards/logs before trying a mitigation. Do not guess — look at `/health`, error rate, queue depth, MySQL threads.
4. **Mitigate.** The default mitigation is **rollback** (Runbook §2) if a deploy happened within the last 60 min. Only attempt in-place fixes if:
   - the incident is clearly infrastructure (e.g., MySQL replica down, Redis OOM) **and**
   - you have a one-command fix (restart, failover) **and**
   - the fix is on a known checklist.
5. **Communicate.** Every 15 min (SEV1/2) post a one-line status update in the incident channel even if nothing changed: "still investigating — error rate 1.2%, rollback started 2 min ago."
6. **Resolve.** When error rate returns to baseline and canary (Release Gate §3) is clean, announce resolution in the channel with the mitigating action and timestamp.
7. **Post-mortem.** For SEV1/2, schedule a blameless post-mortem within 5 business days and file an action list.

## 3. Triage by symptom

### 3.1 The entire site is down (5xx/timeout)

1. Confirm LB health target (`/up`). If all targets unhealthy → instance-level problem.
2. Check `docker compose ps` on the host. Are `app`, `nginx`, `worker` up?
3. Check `docker compose logs app --tail=200` for a fatal error in the last minute.
4. If recent deploy → **rollback immediately** (Runbook §2).
5. If no deploy, check MySQL/Redis reachability from the app container:
   ```bash
   docker compose exec app php artisan tinker --execute="DB::select('select 1'); Redis::ping();"
   ```
6. If MySQL is down, failover to the replica (managed-service-specific command).
7. If host is out of memory/disk, `df -h`, `free -m` — free space or add capacity.

### 3.2 502/504 Bad Gateway

1. nginx is up but cannot reach php-fpm. Check the app container health status.
2. If `healthy`, check worker processes inside the container: `ps aux | grep php-fpm` should show several children.
3. Check php-fpm slow log (stdout) for stuck requests; a single lock can back up all workers.
4. Restart app: `docker compose restart app`.
5. If it recurs within minutes, treat as P1 and rollback.

### 3.3 Queue not draining / payouts delayed

1. Check queue depth: `docker compose exec app php artisan queue:monitor redis:default --max=1000`.
2. Check worker logs: `docker compose logs worker --tail=200`. A Rust panic causes fast crash-loops — look for repeated `panicked at` lines.
3. If worker is crash-looping → **rollback first** (P1 if payouts are affected).
4. If worker is healthy but consumption is slow, scale horizontally: `docker compose up -d --scale worker=3`.
5. Check for a poison message (a message that always fails); check `failed_jobs` table for repeated same-uuid failures.

### 3.4 Wallet/ledger drift

1. Run `php artisan finance:reconcile` from the app container. Read the drift report carefully.
2. **Pause payouts immediately**: `php artisan payouts:pause`.
3. Check whether a recent deploy touched `WalletType`, `TransferDirection`, `BalanceTransferService`, or `FinancialReconciliationService`. If yes, roll back.
4. Compare the sum of `ledger_transactions.amount` grouped by wallet against `wallets.balance` for drifted wallets.
5. Do **not** patch balances manually. Reconciliation adjustments must go through a signed journal entry approved by the finance owner.

### 3.5 Security incident (unauth access, SQL error, audit critical)

1. Treat any `security_audit_logs` entry at `critical` severity as P1 **until proven otherwise**.
2. If there is evidence of an active attack, consider WAF/IP blocks first (see `security_ip_blocks` table + the `security:block-ip` artisan command).
3. Rotate credentials: APP_KEY (follow documented rotate procedure — **do not change it blindly**, it invalidates all encrypted data), DB password, Redis password, any third-party API keys that were in the blast radius.
4. Capture logs for forensics BEFORE restarting containers (save `docker logs` output to a safe location).
5. Notify the security lead and legal/compliance per the data-breach policy.

### 3.6 SSL/TLS problems

- nginx returns the wrong certificate? Reload nginx, confirm the cert mount.
- HSTS is misconfigured (max-age too low / missing)? Fix and redeploy — do not leave HSTS disabled in prod.
- If CDN/LB terminates TLS, make sure the backend connection is also encrypted (or the CDN strips HSTS correctly).

### 3.7 Disk filling up

1. `docker system df` — prune old images: `docker image prune -af` (safe; running containers use images in use).
2. Check `storage/logs/` for runaway log files; Laravel rotates daily, but an exception storm can create large files.
3. MySQL binary logs — if on the same host, check `PURGE BINARY LOGS BEFORE...` (DBA task).

## 4. Communication templates

When posting to the public status page / user comms:

- **Investigating:** "We are investigating elevated error rates on the Thai Lottery platform. Some users may experience timeouts or failed actions. Updates every 15 minutes."
- **Identified:** "We have identified the cause as <one sentence, no jargon> and are working on a fix. Current ETA: <n> minutes."
- **Resolved:** "Service has been restored. We will post a root-cause summary within 5 business days."

Do **not** name individual engineers, speculate on causes before confirmation, or expose internal infrastructure details in public comms.

## 5. Post-mortem template (for SEV1/SEV2)

```
# Incident: <short slug> — <date>

## Summary
(one paragraph, customer-facing)

## Timeline (UTC)
- HH:MM — first alert / user report
- HH:MM — on-call acknowledged
- HH:MM — mitigation applied (rollback/restart/...)
- HH:MM — service restored
- HH:MM — all-clear

## Root cause
(blameless, technical)

## Contributing factors
- ...

## What went well
- ...

## What went poorly
- ...

## Action items
- [ ] (owner, due date) item 1
- [ ] (owner, due date) item 2
```

## 6. Escalation contacts

| Domain | First escalation | Second escalation |
|--------|------------------|-------------------|
| Application / Laravel | Backend on-call | Tech lead |
| Infrastructure / Docker/Compose | Ops on-call | Infrastructure lead |
| Database (MySQL) | DBA on-call | Data lead |
| Settlement worker (Rust) | Rust service owner | Backend lead |
| Security | Security lead | CTO |
| Finance / wallet / payouts | Finance on-call | CFO delegate |

If in doubt — escalate. It is always cheaper to page someone who goes back to bed than to miss a P1.
