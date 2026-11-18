# RUNBOOK: Settlement stuck

**Owner (on-call):** `<OPERATOR: name / rota link>`   **Escalation:** `<OPERATOR: name / channel>`
**Last reviewed:** `<OPERATOR: date>`

> Lines marked **VERIFY** name a command or table this package could not confirm,
> because the settlement code is outside Batch 3. Confirm each one against the
> repository (`php artisan list`, `php artisan route:list`) before the first real
> incident. Do not run a command from this page that you have not verified.

## 1. Symptoms

- Draws are past their result time and bets are still `pending` / unsettled.
- Members report "my bet has not been decided" after the official result is out.
- `GET /api/v1/me/bets/counts` shows `pending` growing while `winning` / `confirmed`
  stay flat for a finished draw.
- The queue is backing up, or `GET /health/ready` returns 503 with
  `checks.scheduler.reason = scheduler_heartbeat_stale` or `queue_unreachable`.

## 2. First five minutes (read-only)

1. Is the platform itself healthy?
   - `curl -s https://<host>/health/ready` : look at `checks.*.ok` and `reason`.
   - `php artisan ops:health` (existing command).
2. Is the scheduler alive? A stale heartbeat means scheduled settlement jobs are
   not firing. Restart the scheduler process first, then re-check `/health/ready`.
3. Is the queue draining? `php artisan queue:monitor <queue-name>` and look for a
   growing backlog or a worker that is not running.
4. Look for the failing job: `php artisan queue:failed` and the application log
   around the draw time. Note the draw id and the exception class.
5. Run the ledger check BEFORE touching anything:
   `php artisan ledger:verify`
   Exit 0 means money is consistent and settlement is "late", not "wrong".
   Exit 1 means stop here and follow `RUNBOOK_ledger_mismatch.md`.

## 3. Decide: late, or wrong?

| Observation | Meaning | Action |
|---|---|---|
| Result confirmed, settlement job failed with an exception | Late | Fix cause, resume (section 4) |
| Result NOT confirmed yet by the official source | Not a settlement problem | `RUNBOOK_result_provider_down.md` |
| `ledger:verify` exits 1 | Possibly wrong | `RUNBOOK_ledger_mismatch.md`, freeze |
| Some bets settled, some not, same draw | Partial run | Resume is safe only if the job is idempotent per bet (section 4) |

## 4. Safe resume

Settlement must be **resumable and idempotent per bet**: a bet that already has a
settlement/payout record must be skipped, never paid twice.

1. Capture the "before" state for the affected draw (counts of bets by status and the
   sum of payouts credited). Save it in the incident ticket.
2. Re-dispatch the settlement for that single draw through the project's own
   settlement entry point. **VERIFY** the artisan command or admin action name in
   the repository. Never write to `bets`, `wallets` or ledger tables by hand.
3. Watch the log until the job finishes. Re-run `php artisan ledger:verify`.
4. Compare the "after" state with the "before" state. Payout totals must equal the
   expected prize amounts for the winning numbers and nothing more.
5. If a bet shows two payout records, STOP and open a ledger mismatch incident.

### Never

- Never run the same settlement twice "to be sure" without confirming the idempotency
  guard exists for the path you are using.
- Never edit a wallet balance or insert a ledger row manually.
- Never mark a draw settled to make a dashboard turn green.
- Never use `ledger:verify` or any check as a "fix"; it only reports.

## 5. Who to notify

| When | Notify |
|---|---|
| Settlement late > `<OPERATOR: threshold>` minutes | On-call engineer + operations lead |
| Any payout discrepancy | Finance owner + compliance owner, immediately |
| Members affected by delay | Support lead, so the support inbox has an approved message |

## 6. After the incident

- Record: draw id, start/end time, root cause, number of bets affected, who resumed it.
- Add a test or an alert so the same failure is caught earlier.
- Update this page if a step was wrong or a **VERIFY** item has now been confirmed.
