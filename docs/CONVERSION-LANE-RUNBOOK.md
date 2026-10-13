# Cash ⇄ Win Conversion — Operator Runbook

Everything an operator needs to run, watch, price, freeze and repair the
Cash ⇄ Win conversion lane without reading the code.

This runbook is written for the person on shift. Every command below was run
against this codebase and the observed output is quoted where it matters.

---

## 1. What the lane is

Members hold two balances that are **not the same money**:

| Wallet | Type value | Can withdraw | Can stake | How it is funded |
|---|---|---|---|---|
| Cash | `primary` | yes | yes | deposits, prizes, refunds |
| Win | `win` | no | yes | paid prizes and Cash→Win conversions |

Moving value between them is priced. Both directions charge **1%** by default,
deducted from the amount being converted (`bearer = source`), so a member
converting 100.00 receives 99.00 and the platform recognises 1.00 in fee
revenue. The member sees the fee before consenting, on the page where the
conversion happens.

---

## 2. Daily checks

```bash
php artisan conversions:status
```

Prints the live rate for each direction, the fee revenue recognised in the
window, and the number of quotes members are currently holding. A rate printed
here is the rate the engine will charge, read from the same configuration the
public fee page reads.

```bash
php artisan conversions:expire-quotes --dry-run     # what would be swept
php artisan conversions:expire-quotes               # sweep it
```

Expiry is also enforced on use: a quote past its TTL is refused even if it has
not been swept yet. The sweep exists so that a member's open-quote allowance
(`conversions.quotes.max_open_per_user`, default 5) is not consumed by abandoned
intents, and so the reconciliation report does not accumulate dead rows.

```bash
php artisan conversions:reconcile --lookback-hours=48
php artisan conversions:reconcile --lookback-hours=720 --queue
```

Compares what each completed conversion says it did with what the ledger
actually posted, per wallet. It **never repairs anything**. A mismatch is
reported for a human to investigate, because an automated correction on a
financial record turns a small accounting bug into an unauditable one. The
command exits non-zero when it finds a mismatch: that exit code is the alarm.

---

## 3. Changing the price

The rate lives in exactly one place:

```php
// config/fees.php
'conversion' => [
    'cash_to_win_percentage' => env('FEES_CONVERSION_CASH_TO_WIN_PERCENTAGE', '1.00'),
    'win_to_cash_percentage' => env('FEES_CONVERSION_WIN_TO_CASH_PERCENTAGE', '1.00'),
],
```

Both rows in `fees.categories` name this value through their `execution` path.

**The value is a whole percent.** `1.00` means one percent. It is read by two
callers — the public fee page and the conversion engine — which both divide by
100 and both round the same way, so the number on the page and the number
charged cannot drift apart without a test failing.

**To change the tariff:**

1. Set the new percentage (env or config).
2. Bump `fees.rule_version` in `config/fees.php`.
3. Run `php artisan test tests/Feature/Wallet/Conversion` — the parity tests
   compare the page against the engine and will fail if the two disagree.
4. Run `php artisan conversions:status` and confirm the rate it prints is the
   rate you set.
5. Announce the change on the public fees page copy if the wording mentions a
   number.

**Quotes taken before a rule change cannot execute at the old price.** A quote
carries the rule version that priced it; if the version moved, the engine refuses
the quote with a rule-supersession refusal rather than charging a price no longer
on offer. The member simply asks again.

**Never set the rate to zero to "switch the fee off".** Zero is a valid price and
the member would be charged nothing while every screen still describes a tariff.
To stop conversions, use section 4.

---

## 4. Freezing the lane

Two independent switches, and the difference matters:

```bash
# Both directions off (an incident, a migration, a regulator's question)
CONVERSION_ENABLED=false

# One direction off (stop money entering Win while the exit lane stays open)
CONVERSION_CASH_TO_WIN_ENABLED=false
```

- With the master switch off, money-moving endpoints answer **503** with a
  `Retry-After` of 900 seconds, no wallet is touched, and the scheduled sweeps do
  not run.
- With one direction off, the other keeps working. **This is the switch to reach
  for first.** Freezing both because one is unsafe traps members holding value
  they can neither withdraw nor move, which is never acceptable.
- The member-facing page still renders when the lane is closed: the form is
  replaced by an explanation and the published fee table stays visible, so a
  member who bookmarked the page learns what it will cost when it returns.

---

## 5. Alerts

| Signal | Where it comes from | What to do |
|---|---|---|
| A refusal whose code names a PLATFORM fault (`feature_disabled`, a missing fee rule, a missing ledger account) | `AlertOnConversionFailureSpike`, `operator_fault_threshold` = 1 by default | One occurrence is enough. Check the fee rule and the ledger accounts before anything else. |
| A demand-side refusal spike (`insufficient_balance`, `below_minimum`) reaching `refusal_spike_threshold` (25 by default) | same listener | Usually a promotion landing badly or a stale balance display; check the deposit lane for an outage before changing limits. |
| A receipt-time mismatch between conversions and ledger postings | `conversions:reconcile` exit code, plus the nightly schedule at 03:45 | Treat as an accounting incident: freeze the lane, keep the report, do not "correct" rows by hand. |

Both thresholds are configuration, not constants, so they can be raised during a
campaign without a deploy:

```php
config('conversions.alerts.operator_fault_threshold');
config('conversions.alerts.refusal_spike_threshold');
```

---

## 6. Reversing a conversion

Reversal is **operator-only** and audited. A member cannot reverse their own
conversion, and the endpoint answers them 403.

What a reversal does, precisely:

1. Posts a second movement in the opposite direction, returning the amount that
   actually **arrived** (the net — 99.00 on a 100.00 conversion, not 100.00).
2. Refunds the fee from the fee-revenue account as its own ledger leg, so the
   member is whole to the satang and the platform keeps nothing.
3. Marks the original row `reversed` and links the two rows in both directions
   (`reversal_of_id` on the new row, `reversed_by_id` on the old one). The
   original's own figures are **never rewritten**.
4. Writes an audit row carrying the operator, the channel, the reason and the
   moment.

Constraints to expect:

- Only a **completed** conversion can be reversed; a failed one moved nothing.
- The reversal **window is 24 hours** by default (`config('conversions.reversal')`).
  Outside it the refusal code is `conversion_reversal_window_closed` and the API
  answers **409**, not 403 — a closed window is a timing fact, not a permissions
  problem, and an operator should not be sent hunting for a missing permission.
- A conversion cannot be reversed twice.
- A reason of at least 10 characters is required. It is permanent.

---

## 7. The admin panel

`Wallet Conversions` in the panel is **read-only by design**:

- no create, no edit, no delete — a financial record is amended by an opposite
  movement, never by an update;
- filters by direction, status and whether it was reversed;
- the navigation badge counts conversions that are not settled;
- the conversion volume widget shows four figures with 60-second polling.

If a member's conversion looks wrong, the correct action is a **reversal**, not
a row edit.

---

## 8. Incident playbook

**"A member says they were charged twice."**
Check the conversion list filtered to that member. Two rows with the same amount
and different references are two conversions; two rows with the same
`idempotency_key` are impossible (the database enforces uniqueness per member,
channel and key). If two conversions were genuinely created from one intention,
reverse the second and keep the reason.

**"A member's fee looks wrong."**
Open the conversion detail page: it prints the rate, the rule key and the rule
version that priced it, plus the audit timeline. Compare that version against
`fee_rule_version` in the fee configuration at the time. If the recorded version
is not the version you expect, the tariff moved — the row is correct and the
expectation is not.

**"Conversions are refusing everything."**
Run `php artisan conversions:status`. If it prints a rate, the tariff is
configured; the refusal is elsewhere (balance, KYC, self-exclusion, limits).
Every refusal names its cause in the error code, and the code is shown beside
the message on the member's page.

**"The reconciliation command failed."**
Do not re-run it hoping for a different answer, and do not repair rows. Capture
the output, freeze the lane, and treat it as an accounting incident.

---

## 9. Configuration reference

| Key | Default | Meaning |
|---|---|---|
| `conversions.enabled` | true | Master switch; 503 when off |
| `conversions.directions.*.enabled` | true | Per-direction switch |
| `conversions.limits.min_amount` | 10.00 | Smallest conversion |
| `conversions.limits.max_amount` | 500000.00 | Largest single conversion |
| `conversions.limits.daily_limit` | 1000000.00 | Rolling daily ceiling |
| `conversions.limits.monthly_limit` | 5000000.00 | Rolling monthly ceiling |
| `conversions.limits.enforce_daily` / `enforce_monthly` | true | Publish and enforce, or publish only |
| `conversions.quotes.ttl_seconds` | 120 | How long a quoted price is honoured |
| `conversions.quotes.require_for_execution` | true | An execution must present a quote |
| `conversions.gates.require_kyc` | true | Identity gate |
| `conversions.idempotency.min_key_length` | 16 | Shortest accepted key |
| `conversions.reversal.window_hours` | 24 | How long a reversal is possible |
| `conversions.reconciliation.tolerance` | 0.00 | Accepted mismatch, in currency |
| `conversions.alerts.*` | 1 / 25 | Alert thresholds |
