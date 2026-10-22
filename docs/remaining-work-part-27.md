# Part27 — outbound dispatch safety, complete File353–File382

## Implemented application behavior

This part changes the production sender, response contract and queue job; it is not a test-only delivery. The base repository remains `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`. Apply this delivery over the existing repository and prior delivered overlay, not as a standalone clean-install repository.

1. Lock the wallet and withdrawal, validate identity/economics/reservation/provider, and commit a durable claim plus its bound Payment before calling the driver.
2. Make the application-level external invocation outside SQL transactions. An outer transaction is refused. Bounded SQL retries surround SQL work only, never the invocation.
3. Generate the idempotency key on the server; caller/job options cannot replace it. Bind the claim to immutable money, beneficiary and Payment facts. Detect destination changes, known-reference conflicts and incompatible Payment metadata.
4. Claims do not expire into permission to resend. Concurrent/replayed jobs, delayed retries, force options, old Processing rows and existing Payment/reference evidence do not start another transfer.
5. A thrown transport exception, explicit uncertain response or malformed success acknowledgement retains Processing and the existing reservation. It does not fabricate a refund, report final success or release the hold because of a timeout.
6. Definitive rejection follows the existing failure/hold-release workflow without refund-credit arithmetic. Drivers must reserve `failed()` for proven non-acceptance, not a lost acknowledgement.
7. Re-read locked canonical state after the call. An already completed verified callback wins over a stale failure response. A post-invocation persistence failure leaves the committed claim preventing another send.
8. Keep existing approval, corridor/provider authorization, strict currency, wallet locks, fee economics and callback-binding controls. Manual approval is not silently disabled.
9. Log exception classes instead of arbitrary transport exception text in the changed sender/job paths. The new inspection command never prints raw destination, claim key/token/fingerprint or arbitrary metadata.

There is no new migration: claim facts use existing withdrawal/Payment metadata. Deploy the sender, DTO, interface and job together. The gateway interface documents the distinction between definitive rejection and uncertainty; real adapters must honor it.

## Read-only operator inspection

Run only through authorized server-operator access:

```bash
php artisan payments:withdrawal-dispatch-status --withdrawal=123
php artisan payments:withdrawal-dispatch-status --attention
```

The selector accepts an existing numeric ID, reference or UUID. `--attention` lists at most 200 Processing rows, oldest ID first; it is not an exhaustive unbounded reconciliation export. Inspect other known records with the exact selector. The two modes are mutually exclusive.

Output includes canonical status, a normalized dispatch state, currency/amounts, reconciliation attention and `automatic_resend_allowed=false`. The command is read-only: no driver invocation, settlement, hold release, token reset, claim deletion or financial write. A pending acknowledgement is not proof of completed bank delivery. Claim state may lag a later callback; canonical withdrawal status is authoritative.

For sending/unknown/legacy_unknown/corrupt state, obtain provider-side evidence through the approved reconciliation workflow. Do not clear metadata, re-approve, create replacement sends or release held money merely because time has elapsed. A crash immediately after claiming but before invocation can intentionally remain held. This favors safety over automatic availability. A new privileged evidence-ingestion/maker-checker operator workflow is not added by this read-only command.

## Executed verification on final application sources

| Check | Executed result |
|---|---|
| Full default PHPUnit | 2645 tests, 130919 assertions, zero errors/failures, exit 0; 38 explicit server-profile skips |
| Payment + comprehensive queue + readiness/invariant scope | 221 tests / 891 assertions PASS on SQLite |
| Complete Payment feature directory on MariaDB | 174 tests / 696 assertions PASS |
| All SQL races, 3 serial repetitions | Each 23 tests / 490 assertions PASS; each 19 actual parent-owned lock-wait witnesses |
| New outbound SIGKILL/replacement-process profile, 3 repetitions | Each 2 tests / 61 assertions PASS; two kill witnesses per repetition |
| Existing SQL worker recovery, 3 repetitions | Each 9 tests / 444 assertions PASS; seven witnesses per repetition |
| Existing private Redis AOF restart recovery, 3 repetitions | Each 7 tests / 353 assertions PASS; seven hard-restart witnesses per repetition |
| Offline Python guard/runner tests | 83 PASS |
| PHP syntax | 1676 files PASS |
| Pint | 13 changed/new PHP files PASS |
| Native crate | 27 tests PASS; locked release build also completed |
| Frontend | Production Vite build PASS |
| CI source | YAML parses; 11 Bash blocks pass bash -n; four SQL-writing profiles ordered serially; hosted workflow not executed |
| Static source preflight/inventory | PASS; source checks are not runtime/payment certification |
| Whitespace | git diff --check exit 0 |

The initial outbound baseline was 17 tests / 46 assertions with 13 failures. Passing baseline controls are not claimed as newly broken behavior. The final safety file has 21 tests, supplemented by four read-only status tests and real server-process profiles. The comprehensive queue fake now identifies itself as bank_transfer; production gateway identity checks were not weakened to admit a mock provider.

Exact default skips: nine SQL worker recovery, nine conversion races, seven Redis recovery, four settlement races, four callback races, two dispatch races, two dispatch process-death cases and one settlement simulation. The relevant real-server profiles were executed separately, with no skipped cases. Matrices overlap; do not sum them into a unique coverage or money-volume total.

The dispatch race tests use independent SQL processes and actual owned wallet contention. Recovery kills a real independent PHP process executing `DisburseWithdrawalJob::handle()` after committed claim or after fsynced synthetic acceptance, then starts a replacement process. They prove claim durability and absence of another application driver invocation in those tested windows. The synthetic acceptance journal is not a merchant, bank or real transfer. This profile does not claim broker reservation/daemon restart coverage; the separate SQL/Redis settlement recovery profiles exercise their own worker/restart scenarios.

Local runtime: PHP 8.4.26, MariaDB 11.8.6 InnoDB through a private Unix socket, Redis 8.0.2 for the separate private Redis profile. All database-writing profiles ran serially against the explicitly acknowledged disposable `thai_lottery_test` schema. No customer account or live provider was used.

Evidence: `evidence/server-races-part-27.json`, `evidence/server-dispatch-recovery-part-27.json`, `evidence/server-recovery-part-27.json`, `evidence/server-redis-recovery-part-27.json`, `evidence/financial_source_preflight-part-27.json`, and `evidence/runtime-part-27.txt`.

## Worldwide architecture versus live authorization

Nothing here converts a country code, payment-brand candidate or public reference website into legal/merchant authorization. Currency remains explicit and immutable; provider/country/currency combinations stay fail-closed until approved. No Bangladesh-only shortcut, implicit currency conversion or unrestricted member transfer is introduced. Own-wallet conversions and disabled member-to-member transfers keep their existing distinct behavior and fees.

Before an actual live corridor is enabled, establish the operator/legal entity, licensed jurisdiction, allowed player countries, KYC/AML/sanctions obligations, merchant account, settlement currency and authorized payout rail. Audit each actual adapter's timeout classification, internal retries, idempotency retention, status lookup and signed callbacks. A single application driver invocation is not a universal guarantee of exactly-once behavior inside a third-party system.

## Remaining work in priority order

1. Select and approve one real merchant/currency/country corridor; finish its actual payout/status integration and sandbox certification. Do not treat Stripe, bKash, Nagad, Crypto, BankTransfer or incomplete PromptPay inbound support as automatically approved live services.
2. Add a controlled provider-evidence reconciliation intake for unresolved outbound claims, including role checks, maker/checker, bound references/money facts, immutable evidence and audit. The new command deliberately cannot mutate/release/reset a claim. Existing verified callback/completion controls remain authoritative.
3. Prove the chosen provider's acceptance/idempotency/status behavior under real sandbox network failures and delayed callbacks, without introducing automatic uncertain-send takeover.
4. Run hosted CI and representative multi-host/broker/provider deployment tests, including backup restoration, monitoring/escalation and an authorized staged rollout. Local private-service tests do not certify those environments.
5. Continue official result-source onboarding/provenance and jurisdiction-specific operational acceptance. A synthetic cryptographic signature does not establish an official live feed.

## Complete delivery and preservation

Part27 contains 30 full bodies, File353–File382; next is File383. Ten previously delivered paths are intentionally revised, 282 prior bodies remain byte-for-byte unchanged, and 20 paths are first-delivered. The cumulative overlay contains 312 unique paths. Full Markdown bodies and ZIP entries must be byte-verified against the workspace and the previous 292-path authority.

This is a source overlay, not a full dependency/database export. No runtime `.env`, secrets, customer data, SQL/Redis state, dependencies, built assets or native executable is packaged. Retain the base repository and prior delivery history. No production deployment, production migration, live transfer, official-source authenticity or universal real-money readiness is asserted.
