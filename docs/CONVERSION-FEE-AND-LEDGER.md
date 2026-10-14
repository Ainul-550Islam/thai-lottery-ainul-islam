# Cash ⇄ Win Conversion — Fee, Ledger and Compliance Notes

Written for finance, compliance and whoever has to answer a regulator's question
about where a member's money went.

---

## 1. The product in one sentence

A member may move value between a withdrawable balance (Cash) and a playable
balance (Win); both directions are charged 1% of the amount converted, the fee is
deducted from the amount being sent, and every movement is double-entered in the
ledger within a single database transaction.

---

## 2. The fee

### 2.1 What is charged

| Direction | Rate | Who bears it | Example |
|---|---|---|---|
| Cash → Win | 1.00% | the member (source) | convert 100.00 → fee 1.00 → Win receives 99.00 |
| Win → Cash | 1.00% | the member (source) | convert 100.00 → fee 1.00 → Cash receives 99.00 |

The fee is computed with `bcmath` on decimal strings. No float ever touches a
monetary amount, at any point, in either the fee calculation or the ledger
posting.

### 2.2 Rounding

Fees are rounded **half up** at two decimal places (satang), once, at the moment
the fee becomes an amount. Because a fee is rounded and the net is the remainder,
the two always add back to the original amount exactly:

```
fee + net = amount     (asserted for every rate and amount in the test suite)
```

Rounding is covered by boundary tests at exactly half a satang (0.005 → 0.01) and
just below it (0.004 → 0.00).

### 2.3 The rate is published and executed from one value

```php
// config/fees.php — the only place the number exists
'conversion' => [
    'cash_to_win_percentage' => '1.00',   // whole percent
    'win_to_cash_percentage' => '1.00',
],
```

Two independent readers consume it:

- **the public fee page** (`FeesPageService::resolveRate`), which renders the
  tariff a member is shown;
- **the conversion engine** (`ConversionFeeCalculator::effectiveRate`), which
  computes what is actually charged.

Both read the value as a whole percent, divide by 100 using `bcdiv`, and round at
the same scale. The suite asserts that the number the page publishes and the
number the engine charges are equal (`ConversionFeeParityTest`), and — critically
— that they are equal to **1%**, so a change that moved both sides together to the
wrong number would still be caught.

### 2.4 What happens when the rule cannot be read

The failure mode this design refuses to have is a silent zero.

- A row that names a live rule whose value is unreadable falls back to the rate
  published beside the row.
- A row with neither a live value nor a published rate reports itself
  **unconfigured**, the engine refuses the conversion with a named error, and the
  member's page states that no price is published.
- A rate below zero is rejected outright: a negative fee would pay members to
  convert.

### 2.5 Every conversion records the rule that priced it

Each conversion row stores `fee_rate`, `fee_rule_key` and `fee_rule_version`.
A member disputing a fee two months later can be answered from the row itself,
without reconstructing which configuration was live at the time.

---

## 3. The ledger

### 3.1 Accounts

The lane uses the platform's existing chart of accounts — no new accounts are
invented for it:

| Code | Account | Role in a conversion |
|---|---|---|
| `2000` | Member wallet liability (one per wallet, per currency) | The member's own balance. Both wallets in a conversion are these accounts. |
| `3000` | Adjustment equity — used here as the **transfer counterpart** | The counterparty for the two legs that move value between wallets. They post against it and therefore net to zero, so a transfer between two of the member's own wallets does not distort equity. |
| `4100` | Fee revenue | Where the 1% is recognised. The fee is a real posting, not "an amount that was not added". |

The counterpart code is configurable (`config('conversions.ledger.transfer_counterpart_code')`,
default `3000`) and the fee account is `config('conversions.ledger.fee_revenue_account_code')`,
default `4100`.

### 3.2 How a conversion is posted

A conversion of 100.00 with a 1% fee is three engine transactions inside **one**
database transaction:

1. the full 100.00 leaves the source wallet, counterpart 3000;
2. the net 99.00 arrives in the destination wallet, counterpart 3000;
3. the 1.00 fee moves from the member's side into **4100 Fee Revenue**, as its
   own leg.

Legs 1 and 2 net to zero against the transfer counterpart; leg 3 is the one that
changes the platform's position. Each leg is individually balanced — the ledger
validator refuses anything else — so the books stay correct even if a later leg
fails, in which case the whole transaction rolls back and **no** leg survives.

Why the fee is its own leg rather than simply "not credited": a fee that is only
the absence of an amount has no ledger row proving it was charged, and
reconciliation against the published tariff would be impossible. `4100` is also
what `conversions:status` reads to report fee revenue.

**Reversal refunds the fee through the same account.** The reversal's third leg
debits `4100` back, so fee revenue for a reversed conversion nets to zero while
both halves of the pair remain visible in the ledger.

### 3.3 Why the reversal is two movements, not one correction

A reversal does not edit the original conversion. It creates a **second**
conversion in the opposite direction:

- it returns the amount that actually arrived (the net), because returning the
  gross out of a wallet that never held it is how a reversal becomes a way to
  mint money;
- it refunds the fee from `4100…` as its own leg, so fee revenue nets to zero for
  a reversed conversion and the member is made whole to the satang;
- it marks the original `reversed`, links the rows in both directions, and leaves
  the original's figures untouched.

After a reversal, both balances and the fee account are exactly where they were
before the original conversion — asserted in `ConversionReversalTest`.

### 3.4 Reconciliation

`php artisan conversions:reconcile` compares, per wallet, what each completed
conversion says it did against what the ledger actually posted, within a
configured tolerance (default 0.00). It reports and exits non-zero on mismatch;
it never writes. The nightly schedule runs it at 03:45 with a 48-hour lookback.

---

## 4. Identity, standing and limits

| Gate | Default | Why |
|---|---|---|
| KYC verified | **required** | A conversion lane is the cheapest path an unverified account has to move value. |
| Account status | must be active | A suspended account cannot convert; a read-only operator cannot reverse. |
| Self-exclusion | blocks | A self-excluded member must not be able to move money into a wallet they can play. |
| Minimum | 10.00 per conversion | Below this the fee rounds to nothing and the lane is a rounding exercise. |
| Maximum | 500,000.00 per conversion | Caps a single fat-finger or account-takeover movement. |
| Daily | 1,000,000.00 | Rolling ceiling over settled conversions. |
| Monthly | 5,000,000.00 | Rolling ceiling over settled conversions. |

Limits count **settled conversions only**: a refused, expired or failed attempt
costs the member nothing and consumes no allowance. Each ceiling can be published
without being enforced (`enforce_daily` / `enforce_monthly`), which exists so an
operator can raise a limit during a promotion without the UI and the engine
disagreeing about what is in force.

---

## 5. Idempotency and the money-moving request

Every request that can move money must carry an idempotency key (minimum 16
characters) in the `Idempotency-Key` header or the body. The key is unique per
member, channel and value, enforced by the database.

- A retry with the same key returns the **original** conversion and moves no
  money, and is marked `replayed` in the response.
- The same key with a different amount is refused as an idempotency conflict
  rather than silently replayed.
- The server never mints a key on the member's behalf. A server-generated key
  would differ on every retry, which is exactly the situation the mechanism
  exists to survive.
- The member's browser generates the key with `crypto.randomUUID()` (or
  `crypto.getRandomValues()`) and reuses it for the life of the form; a browser
  that can provide neither is refused rather than served a weaker key.

---

## 6. Audit trail

Two records are kept, and neither can be edited from the panel:

1. **`wallet_conversions`** — the movement: amounts, rate, rule version, both
   balances before and after, channel, actor, quote reference, and the links
   between an original conversion and its reversal.
2. **`wallet_conversion_audits`** — the story: one row per event (quoted,
   completed, refused, reversed) carrying the event name, the status it moved
   from and to, the error code if any, the actor type, the IP address, the user
   agent, the request id and a JSON context block naming both sides of a
   reversal.

Refusals are recorded **outside** the money transaction — a refused request
leaves no conversion row, but it does leave evidence that it was attempted and
why, which is what an operations team needs to see a spike.

---

## 7. Questions a regulator is likely to ask

**"How do you know the published fee is the charged fee?"**
One configuration value, two readers, and a test that computes both sides from
the live configuration and compares them. Changing the rate changes both or
fails the build.

**"What happens if the price changes mid-conversion?"**
A quote carries the rule version that priced it. If the version has moved, the
quote is refused with a rule-supersession refusal; the member is never charged a
price that is no longer published.

**"Can a member be charged twice for one intention?"**
Only by using two different keys, which is two intentions. The same key returns
the original conversion.

**"Can an operator move money out of a member's account?"**
No. An operator can reverse a completed conversion, which returns the member's
own money to where it came from, within 24 hours, with a written reason. Every
reversal is audited with the operator's identity, and the panel offers no way to
edit a balance, a conversion or a ledger posting.

**"What is the fee revenue for a reversed conversion?"**
Zero. The fee is refunded as its own ledger leg and the original recognition is
left in place, so the pair nets to nothing and both halves remain visible.
