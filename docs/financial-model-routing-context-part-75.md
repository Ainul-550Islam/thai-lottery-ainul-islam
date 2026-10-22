# Part75 — financial model routing context

## Defect fixed

A canonical positive ID is not a complete database address. The financial engine deliberately routes through fixed aggregate tables on its default writer. A supplied wallet, user, deposit, withdrawal or financial-transaction model could previously declare another connection/table/key but still have its raw numeric ID silently reinterpreted against that writer. Colliding IDs can exist in different countries, tenant stores or tables. A direct low-level wallet call was not automatically safe merely because it re-locked a row.

`FinancialRecordIdentifier::fromModel()` retains its exact positive-integer validation and now also requires the supported aggregate's canonical table, the `id` primary-key name and the current default financial writer Connection object. Supported tables are `wallets`, `users`, `deposits`, `withdrawals` and `financial_transactions`. Deposit/withdrawal lifecycle key extraction now calls the same model-aware gate rather than only the scalar parser.

A non-null declared connection must match the default writer name BEFORE the model's connection is resolved. Unknown names, alternate aliases and read-suffixed names are therefore refused without instantiating or connecting to them. A model reporting the right name but resolving a different Connection object is refused too. An inherited default connection and an explicit actual writer name remain supported.

This propagates through existing canonical credit/debit, relock, fund reservation/release, administrative freeze/unfreeze, direct `applyCredit`/`applyDebit`/both `applyReversal` directions, hold/release/conditional release/consumption, user-wallet resolution, lifecycle row locks/failure persistence and generic reversal entry. Existing physical-transaction admission, wallet-first lock order, current raw-key checks, exact arithmetic, idempotency and canonical journal proof remain authoritative.

## Executed test design

The new dedicated class contains 90 cases:

- 21 integrated routes times four foreign contexts: unknown connection, a same-backend alternate alias, altered aggregate table and altered primary-key name. Each of these 84 cases checks no routing SQL, no foreign connection instantiation, no caller model mutation and unchanged caller-owned PDO/framework state. Its caller then actually commits an unrelated marker while every accounting row stays unchanged.
- Four healthy combinations of inherited/explicit writer and root/caller-owned transaction. Real canonical funding, credit/replay, reservation/release, balanced ledger rows and caller rollback remain intact.
- A model with the correct declared name but a different actual Connection object.
- A wallet genuinely loaded from a distinct SQLite store with a colliding local ID. The foreign 900.00 value is an observational sentinel, NOT a claimed canonically funded foreign book. The local 500.00 is canonically funded. Neither store is altered by the refused credit.

The same 90 cases execute under default SQLite and native MariaDB selection. The foreign sentinel store stays distinct even when the canonical writer is native SQL. There is no cross-currency sum, FX operation or new concurrency-race claim. Every preceding default/native case ID and the existing genuine concurrency profiles are retained.

```bash
php vendor/bin/phpunit tests/Feature/Finance/FinancialModelRoutingContextTest.php
```

Use only the acknowledged disposable `thai_lottery_test` database and local Unix socket for native selection. Complete default acceptance isolates every test source file in its own process/store/token. Native acceptance is queued until default and auxiliary compilation finish; no child deadline or assertion is weakened to accommodate simultaneous toolchain load. Suites overlap and must not be added together as unique coverage. CI requires exactly 90 unique dedicated cases with no skips/errors/failures and preserves its XML; local workflow validation is not remote CI execution.

Two isolated omission controls remove only the writer-context check or the aggregate-context check. They audit shadow-loaded class paths and require one expected assertion failure each with no setup error/skip. The test caller commits in `finally`, so the control database can be independently inspected after the assertion: omitting either check really credits the wrong-context default-store wallet from 500.00 to 510.00 and leaves two financial transactions/four journal legs. Healthy source refuses the routing and preserves the funding-only book plus unrelated caller work.

## Explicit boundaries

This validates the supplied CURRENT routing context, not a model's complete historical origin or an authenticated identity. A caller that replaces all context/ID fields can still construct a well-formed address; authorization, tenant ownership and locked persisted-state proof remain separate requirements. The helper cannot reconstruct an upstream-coerced key or attest an earlier physical PDO/store identity. Alternate-tenant financial routing requires an explicit supported integration; silent fallback to the default writer is not one.

No arbitrary Eloquent save fence, universal all-writer/driver certification, provider request, live transfer, historical repair, populated-production backup/restore or worldwide legal approval is claimed. Scalar ID APIs remain context-bound to their existing caller contract. In-memory status preparation remains preparation, not generic persistence authority. Provider/merchant details remain owner rollout inputs and did not defer this application fix. Executed results are recorded separately in `evidence/acceptance-part-75.json`, `evidence/runtime-part-75.txt` and `docs/remaining-work-part-75.md`.
