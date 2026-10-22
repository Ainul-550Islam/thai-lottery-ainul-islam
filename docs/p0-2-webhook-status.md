# P0-2 — Durable webhook replay protection

Verified 2026-10-15 using PHP 8.4.26 with intl, GD/JPEG, PDO MySQL and PDO SQLite.
F42 completes explicit manual checkpoint coordination; payment webhook/transfer production behavior is unchanged.

## Measured results

| Scope | Result | Evidence |
|---|---|---|
| Complete Payment group | 86 tests, 386 assertions; no errors, failures or skips | `/home/user/full-f42.junit.xml` |
| Expanded changed-area regression | 1,441 tests, 107,236 assertions; all pass, no skips | `/home/user/part3-focused-f42.log` |
| Retained audit-preview component | 53 tests, 517 assertions; all pass, no skips | `/home/user/full-f42.junit.xml` |
| Retained legacy command | 69 tests, 334 assertions; all pass, no skips | `/home/user/full-f42.junit.xml` |
| Retained job refusal | 26 tests, 121 assertions; all pass, no skips | `/home/user/full-f42.junit.xml` |
| Manual checkpoint/inventory scope | 51 tests, 315 assertions; all pass, no skips | `/home/user/manual-focused-f42.log` |
| Whole repository, unfiltered | 2,538 tests, 113,966 assertions; 0 errors, 0 failures, 0 skips | `/home/user/full-f42.log` |

The prepared local whole repository is **fully passing**, without excluded errors or skips. All 51
resolved f35 cases and historical failure details remain in `docs/full-suite-failure-classification.json`. Payment passes
both in the full run and the independently repeated expanded regression. No test was disabled.

## Implemented contract

1. **Durable claim:** after successful signature verification, middleware inserts into
   `webhook_replay_guards`. The unique `(gateway, nonce)` index, not node-local cache, recognizes an
   identical signed delivery. Cache clearing does not reopen that nonce.
2. **Duplicate acknowledgement:** an accepted repeated delivery returns HTTP 200 with
   `{"success":true,"duplicate":true}` without running the handler again; its delivery count increases.
3. **Refused handler response:** a returned 4xx/5xx releases this request's claim so a later delivery
   can reach the handler. Bad signatures are refused before a durable claim exists.
4. **Database failure:** errors not classified as unique-constraint violations propagate; database
   unavailability does not silently allow a request.
5. **Stripe scheme:** only Stripe parses `Stripe-Signature: t=<unix>,v1=<hex>`. Signed material is
   `<timestamp>.<raw body>`, subject to the configured timestamp tolerance.
6. **Body-signed scheme:** implemented bKash, Nagad and Crypto lanes select `signed_material => 'body'`.
   They verify raw-body HMAC with **no timestamp freshness guarantee**. Repeated signatures still face
   the durable guard. This repository contract is not live gateway certification.
7. **Zero tolerance:** disables the timestamp window only; it does not disable nonce control or make
   timestamps optional for a timestamped signature.
8. **Observability:** best-effort `WebhookReceipt` records are separate from durable control. Guard rows
   retain payload hash and delivery timestamps. Identical bodies under fresh nonces are separate rows,
   not body-only rejections. Ledger idempotency remains necessary for financial effects.
9. **Route attachment:** both named webhook routes resolve to `VerifyWebhookSignature`. Tests require
   the **named `webhook` throttle**, including the Redis middleware variant; generic API throttle
   alone is insufficient.
10. **Cleanup:** `PurgeWebhookReplayGuards` removes expired rows under configured retention.

## Existing payment safety decisions retained

- Client-supplied fee/net/other protected money fields get 422, rather than being trusted.
- Public bank-transfer withdrawal remains unavailable and creates no withdrawal row.
- Deposit API tests fake checkout; they do not contact providers.
- Signed Stripe amounts match the referenced deposit, not unrelated hard-coded values.
- bKash fixtures use their own payload/signature shape and gateway nonce space.
- The four `*_LOTTERY_FIXTURE_ENABLED=true` flags in `phpunit.xml` are explicit labelled-test flags.
  Production force-disables the lanes; neither safe environment template enables them.
- No real-money payout automation or multi-level commission functionality is added or enabled.

## Readiness follow-up coverage

Business/readiness tests now use actual pending deposits and normalized `WebhookPayload` objects
rather than a nonexistent `ProcessPaymentWebhookService` or blind `process(provider, array, id)` call.
They assert replay does not create another financial transaction, ledger pair or wallet credit.
These service tests do not replace signature admission: the P0 integration additionally initiates a
BDT bKash checkout against faked HTTP and submits a genuinely HMAC-signed callback through the route.

Withdrawal tests retain manual approval, reserve before completion, and release holds on failure
without minting a refund when no debit occurred. The disabled bank rail is refused before an outbound
request; no fake successful bank payment is substituted. Production payment/money services are unchanged.

## Limitations and review gates

- Unique-violation classification includes SQLSTATE `23000`. Tests use SQLite. Cross-host MySQL/MariaDB
  concurrency and PostgreSQL transaction-abort behavior are not certified.
- A known payload hash under a new nonce is stored, but no dedicated signature-rotation operator
  alert exists. Stored hash alone is not an alerting system.
- Tests use disposable fixtures and HTTP fakes, not live credentials or transfers.
- The f36 SQLite `IMMEDIATE` fix and eight historical repeated probes do not certify cross-host money
  concurrency or a server row-lock money race; the f41 SQLite witness is explicitly non-monetary.
- Payment changes still require independent security/finance review before production use.

## Related closures and remaining blocker

GLO/runtime/caller issues remain passing. The fifteen original cursor/LIVE errors are now resolved
by the explicit manual API over the existing payer, without changing the original core assertions.
No legacy queued/CLI execution, gateway transfer, scheduler or deployment flag is activated. See
`docs/manual-cursor-settlement-status.md`; local green is not real-provider/production certification.

Runtime prerequisites are enforced by `scripts/check-test-runtime.php` and the PHP 8.4 workflows.
Runtime installs and compiled assets are not preserved across snapshots; reproduce setup using
`docs/part3-bundle-usage.md`. Hosted CI was not executed here; the local native build/runtime is measured in the f40 report.
