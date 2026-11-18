# RUNBOOK: Payout failed

**Owner (on-call):** `<OPERATOR: name / rota link>`   **Finance owner:** `<OPERATOR: name>`
**Last reviewed:** `<OPERATOR: date>`

> Lines marked **VERIFY** name something this package could not confirm because the
> payout code is outside Batch 3. Confirm against the repository first.

## 1. Symptoms

- A winning bet shows a payout in `pending` or `failed`, or the member reports
  that a win did not arrive in their wallet.
- A withdrawal or payout request is stuck at the payment gateway.
- The wallet overview shows a balance the member does not expect.

## 2. Golden rule: never double-pay

A retry is safe ONLY if you have proven the first attempt did not succeed. "The
gateway returned an error" does not prove that: a timeout can hide a success.

### Pre-retry checklist (all must be true)

1. **Ledger first.** Find the payout's ledger entries. If a credit/debit for this
   payout reference already exists, the money moved: do NOT retry; fix the status
   record through the official path or escalate.
2. **Gateway second.** Ask the gateway for the status of the payout by the
   reference we sent (idempotency reference). Possible answers:
   - `succeeded` -> record it through the official path; do not retry.
   - `failed` (definitively) -> retry is allowed.
   - `pending` / unknown -> WAIT; never retry an unknown.
3. **Idempotency reference.** The retry MUST reuse the original idempotency
   reference so the gateway and the ledger both recognise it as the same payout.
4. **Member state.** Confirm the member is not self-excluded or restricted in a way
   that blocks payout (compliance owner decides).
5. **Integrity.** `php artisan ledger:verify` exits 0 before and after.

## 3. Retry rules

| Situation | Allowed action |
|---|---|
| Gateway says definitively failed, no ledger movement | Retry once with the SAME reference |
| Failed twice | Stop. Escalate to finance owner; manual review |
| Unknown / timeout | Wait for the gateway status; check again after `<OPERATOR: minutes>` |
| Ledger already shows the payout | No retry. Reconcile status only |
| Amount or recipient looks wrong | Stop. Compliance + finance review |

Use the project's own payout retry action (route group `/api/v1/payouts`, admin
action, or artisan command). **VERIFY** the exact name. Do not write to wallet or
ledger tables directly.

## 4. If the member was charged but not paid

1. Do not promise a time to the member; give the support lead the facts.
2. Finance owner decides: retry (if proven failed), or a reviewed manual
   adjustment through the existing wallet/ledger services with a dual approval and
   an audit entry. Never an ad-hoc SQL change.

## 5. Who to notify

| When | Notify |
|---|---|
| Any payout failure over `<OPERATOR: amount>` or older than `<OPERATOR: hours>` | Finance owner |
| Two failures on the same payout | Finance owner + on-call engineer |
| Suspected duplicate payment | Finance + compliance, immediately; freeze per `RUNBOOK_ledger_mismatch.md` |

## 6. After the incident

- Record payout reference, attempts, gateway statuses, who decided each retry.
- Check whether the failure class (timeout, validation, balance at gateway) needs an
  alert or a test.
