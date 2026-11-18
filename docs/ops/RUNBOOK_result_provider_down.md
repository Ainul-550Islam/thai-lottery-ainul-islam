# RUNBOOK: Result provider down

**Owner (on-call):** `<OPERATOR: name / rota link>`   **Escalation:** `<OPERATOR: name / channel>`
**Last reviewed:** `<OPERATOR: date>`

> Lines marked **VERIFY** name something this package could not confirm because the
> result-entry code is outside Batch 3. Confirm against the repository first.

## 1. Symptoms

- The official draw result has been published, but the platform has no result for the
  draw after the usual delay.
- Result import jobs fail or time out; the provider returns errors or no data.
- `GET /health/ready` reports `checks.result_provider_config.ok = false`
  (this check reports CONFIGURATION status only, not whether the remote service is
  reachable; a provider outage with valid config still shows `ok: true`).

## 2. Principle

The platform never guesses a result. A result enters the system only through the
**official write boundary**: the result confirmation path that records who confirmed
it, from which source, with an audit entry. The public result route is
`/api/v1/draws/{draw}/result` (see `routes/api.php`); use the project's own
confirmation flow behind it. **VERIFY** the exact admin action or command name.

## 3. Triage (read-only)

1. Confirm the outage is the provider, not us:
   - `curl -sS -o /dev/null -w "%{http_code}\n" <provider status URL>` from the app host
     (`<OPERATOR: provider status URL>`).
   - Check the provider's status page and any notice sent to the operator.
2. Confirm our configuration did not change: compare the provider config keys in the
   environment with the last known good deploy. Never paste secret values into a ticket.
3. Check the failed jobs: `php artisan queue:failed`; note draw id and error.

## 4. Manual confirmation procedure

Use only when the official source has published the result and the provider feed is
down for longer than `<OPERATOR: threshold>` minutes.

1. **Obtain the result from the official source** (the authority's own publication).
   Take a screenshot or PDF and store it with the incident ticket as evidence.
2. **Two-person rule.** A second authorised person reads the numbers from the same
   official source independently and confirms they match, digit by digit.
3. Enter the result through the official write boundary (admin result confirmation).
   Record in the ticket: draw id, who entered, who verified, source URL, time.
4. Let normal settlement run. Do not trigger payouts manually.
5. After settlement, run `php artisan ledger:verify` (must exit 0).
6. When the provider returns, compare its figures with what was entered. Any
   difference is an incident: stop, do not overwrite, escalate to the compliance owner.

### Never

- Never type a result from memory, from social media or from an unofficial site.
- Never enter a result alone.
- Never edit settled bets or balances to correct a wrong result; escalate instead.

## 5. Who to notify

| When | Notify |
|---|---|
| Provider down past threshold | On-call engineer, operations lead |
| Manual confirmation performed | Compliance owner, finance owner (same day) |
| Entered result differs from provider | Compliance owner immediately |

## 6. After the incident

- Attach evidence (official publication, who entered, who verified).
- Record downtime and whether any draw was settled late.
- Review whether an alert on result delay should be added.
