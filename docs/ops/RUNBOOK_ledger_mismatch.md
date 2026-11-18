# Runbook — `ledger:verify` reports a mismatch

**Audience:** on-call operator / engineer
**Trigger:** `ledger:verify` exits with code `1`, or the daily 03:10 scheduled run alerts.
**Severity:** SEV-2 by default. Escalate to SEV-1 if the mismatch is growing between runs.

---

## 0. What the command actually does

`ledger:verify` is a **read-only** sweep. It compares two things that are supposed to
agree and reports where they do not.

| Check | Question it answers |
|---|---|
| wallet invariant | Does each wallet's stored `balance` (and `locked_balance`) equal the sum of its ledger entries? |
| transaction invariant | For each financial transaction, do the debits and credits it produced balance? |
| `no_ledger_entries` | Does a wallet hold a non-zero balance with **no** ledger entries behind it? |

It writes **nothing**. It has no `--fix` flag, and one must never be added.

### Exit codes — this is the interface your alerting reads

| Exit | Meaning | What you do |
|---|---|---|
| `0` | Every check ran, nothing found | Nothing. |
| `1` | A discrepancy was found | This runbook. |
| `2` | A check could not be run at all | Fix the *verification* first — see §5. |

> **Exit `2` is not "all clear".**
> It means the tool could not see, not that the ledger is fine. Treat it as an
> incident until it exits `0` or `1`.

---

## 1. First five minutes

```bash
# 1. Look at it. Do not touch anything yet.
php artisan ledger:verify

# 2. Capture the output verbatim — the incident report needs the exact figures.
php artisan ledger:verify > /tmp/ledger-verify-$(date +%Y%m%dT%H%M%S).txt 2>&1
echo "exit=$?"
```

Then answer, in this order:

1. **Is money moving right now?** Check whether deposits, withdrawals and bets are
   still processing. A mismatch on a *static* ledger is an accounting problem; a
   mismatch that *grows between two runs five minutes apart* is an active bug and a
   SEV-1.
2. **How large is it?** A single wallet off by a rounding unit is a different incident
   from a thousand wallets off by their full balance.
3. **Is it one wallet or many?** One wallet usually means that member's history. Many
   wallets usually means a shared code path changed.

---

## 2. Decide what kind of mismatch it is

| Symptom | Most likely cause | Go to |
|---|---|---|
| One wallet, small delta, recent activity | A single transaction wrote its ledger entries but not the balance, or vice versa | §3 |
| One wallet, `no_ledger_entries`, non-zero balance | The balance was set directly (migration, manual SQL, import) | §3 |
| Many wallets, same delta pattern | A batch job or a code change touched balances outside the ledger service | §4 |
| Many wallets, deltas of varying size | A partial failure in a batch — some rows committed, some not | §4 |
| Transaction invariant fails | A transaction's entries do not sum to zero — double-entry is broken | §4 |

---

## 3. Single wallet

```bash
# Read the wallet and its entries. These are SELECTs; you are still not writing.
php artisan tinker
>>> $w = App\Models\Wallet::find(<wallet_id>);
>>> $w->balance;
>>> App\Models\LedgerEntry::where('wallet_id', $w->id)
...     ->orderBy('id')->get(['id','type','amount','created_at','reference']);
```

Write down three numbers: **stored balance**, **ledger sum**, **difference**.

- If the difference is **exactly** one transaction's amount, the cause is almost always
  a transaction that half-committed. Find it by timestamp and check whether it has both
  its entries and its effect on the balance.
- If the wallet has a balance and **no** entries, the balance was set outside the
  ledger. Find out by whom before doing anything: `audit_logs` filtered by the wallet's
  `user_id` and the surrounding time window.

**Do not correct the wallet by hand.** A manual `UPDATE wallets SET balance = ...` is
the thing that created this incident in the first place, and a second one destroys the
evidence the review needs. Use the audited adjustment path in §5.

---

## 4. Many wallets / transaction invariant

1. **Freeze the suspected writer before investigating.** If a job is still running, stop
   the scheduler worker for it (`schedule:list` shows the entry) so the mismatch stops
   growing while you look.
2. **Establish the window.** The first and last affected `created_at` values bound the
   cause. Everything else is noise.
3. **Check what deployed or ran in that window:** `git log` for the range, the queue's
   failed-jobs table, and the scheduler's output for the same hours.
4. **Do not re-run the batch.** A batch that partially applied is not made correct by
   being applied again; it is made worse.

---

## 5. Correcting, when the review says to

Correction is a **separate, audited decision**, never part of diagnosis. If a correction
is warranted:

- It goes through the existing **adjustment** path (a `LedgerEntry` of type
  `Adjustment` plus the matching balance update via the ledger service), so the
  correction itself is visible to `ledger:verify` and to the next audit.
- It is made by someone who did **not** write the original fault, and it is recorded in
  the incident ticket with the pre- and post- figures.
- Afterwards, `ledger:verify` must exit `0`. If it exits `1` again, the correction was
  wrong and is itself now part of the incident.

**If a check could not run (exit `2`)**: this is usually the database or the ledger
accounts table being unavailable. Confirm the connection, confirm the chart of accounts
is seeded (`php artisan db:seed --class=LedgerAccountSeeder`), then re-run. While it is
exit `2`, you have no evidence about the ledger — say so in the incident, do not report
"clean".

---

## 6. Related commands — which to reach for

| Command | Answers |
|---|---|
| `ledger:verify` | *This runbook.* Wallet-vs-ledger and transaction balance. The exit-code contract above. |
| `ledger:audit-integrity` | The ledger's **own** internal consistency: currency identity, per-transaction balance, hash-chain tampering. Run this too when the transaction invariant fails. |
| `ledger:reconcile-bank` | Aggregates against external statements. **External bank evidence is not available to this system** — this command says so; it does not prove the ledger against a bank. |
| `ops:health` | Scheduler liveness (reads `storage/framework/schedule-heartbeat`). |
| `GET /health/ready` | Node readiness for the load balancer, including scheduler freshness. |

---

## 7. After the incident

Write down, in the ticket:

1. The exact `ledger:verify` output before and after.
2. The root cause — *"which code path wrote outside the ledger"*, not *"balances were
   wrong"*.
3. Whether any member saw a wrong figure on a screen, and for how long.
4. The test that now fails if the same mistake is made again. If there is not one, the
   incident is not closed.

---

## Appendix — what this runbook deliberately does not cover

- **Fixing a member's displayed balance.** There is no such operation. A member's
  balance *is* their ledger.
- **Re-running a payout.** That is settlement's runbook, and it must not be triggered
  from a reconciliation incident.
- **Deciding whether an overpayment is recoverable.** That is a compliance decision, not
  an engineering one.
