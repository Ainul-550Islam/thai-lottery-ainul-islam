# Part76 — direct ledger admission

## Application defects fixed

The canonical wallet engine already admitted a coherent physical writer transaction and checked a supplied model's current routing context. Direct `LedgerPostingService::post()` did not share both safeguards: it checked only Laravel's transaction counter and trusted the supplied financial model's key/context. A caller that raw-committed the write PDO could leave a positive counter and let account updates/journal rows autocommit. A malformed raw model ID could also be cast to a different transaction ID.

The posting service now retains its existing outside-transaction error and additionally calls the existing write-PDO/counter coherence admission before posting SQL. It does not begin, repair, roll back or commit the caller's transaction. A missing/unmanaged transaction is refused; a positive framework counter with no physical transaction is refused before account locks/writes.

Posting admission now uses `FinancialRecordIdentifier::fromModel()` before status/entry processing. Direct `hasPosting()`, `postedEntriesFor()` and `LedgerBalanceValidator::assertTransactionBalanced()` use the same gate before querying a journal. Stored ledger foreign keys also use the validated identity rather than an Eloquent numeric cast. Current raw positive ID, supported table, canonical `id` key name and actual default-writer Connection object are therefore required on these direct entry points. Unknown foreign aliases are not resolved. Existing ordered account locks, draft validation, duplicate refusal, exact arithmetic and persisted balancing remain intact.

## Dedicated execution design

`tests/Feature/Finance/LedgerPostingAdmissionTest.php` contains 41 cases:

- Four entry points times eight invalid contexts: leading-zero/suffix/exponent/zero/negative keys, unknown foreign connection, wrong table and wrong key column. All 32 refuse before SQL, preserve the supplied model and the caller-owned physical/framework state, leave every account/journal/financial row unchanged, and actually allow an unrelated caller marker to commit.
- Four invalid physical boundaries: raw commit, raw rollback, unmanaged PDO transaction and missing transaction. The service neither writes journal/account rows nor repairs the caller's deliberately broken boundary. Only fixture teardown resolves that boundary.
- Four valid inherited/explicit writer and root/savepoint combinations. Posting produces exactly two balanced rows and correct account balances, duplicate posting refuses, caller depth stays unchanged and caller rollback restores the whole pre-posting book.
- One financial transaction genuinely loaded from a distinct SQLite store with a colliding local ID. Its 99.00 amount is an observational sentinel, not a funded foreign journal; no store is altered by the refused posting.

The same class executes with SQLite and native MariaDB selection. The fixture's asset/liability entries are synthetic system adjustment journals without wallets or a claimed external cash receipt. This is actual application journal/account mutation testing, not a live transfer or newly funded wallet.

```bash
php vendor/bin/phpunit tests/Feature/Finance/LedgerPostingAdmissionTest.php
```

Native selection is restricted to the acknowledged disposable `thai_lottery_test` store on the local Unix socket. Full default execution isolates every test source file in a distinct PHP process, SQLite database and Laravel storage token. Native selection starts after default/auxiliary compilation finish. Every preceding default/native case ID and existing genuine contention profile is retained; overlapping suite counts must not be added. CI requires exactly 41 unique dedicated cases with no skips/errors/failures. Local workflow parsing is not remote CI execution.

## Qualified omission controls

Two separate shadow workspaces audit the loaded implementation class paths. One replaces current raw-model parsing with Eloquent's cast key, and one removes only direct posting's physical-state admission. Each must fail exactly one intended assertion with no setup error/skip. Independent SQLite inspection after the caller boundary finishes proves the real consequence: one synthetic adjustment transaction, two committed journal rows and asset/liability balances of 10.00 each, with zero wallet rows. Healthy source refuses and leaves account/journal rows unchanged. Preliminary overbroad/empty dataset filters are diagnostic, not acceptance.

## Explicit boundaries

The gate validates CURRENT addressing and transaction coherence, not complete historical model/PDO provenance, caller authentication, tenant authority or the contents of an upstream-coerced key. It does not newly re-lock/re-prove all persisted transaction fields, authorize arbitrary draft wallet/reference IDs, close all direct Eloquent writers, repair earlier books or certify every driver. Existing orchestrators own their persisted-state checks and transaction rollback policy. A later posting failure is still the caller's responsibility to roll back; this part does not introduce a universal local savepoint wrapper.

No new migration/backfill, provider request, live transfer, populated production restore, remote CI run or worldwide legal approval is claimed. Merchant/country rollout inputs remain owner deployment work and did not postpone this application fix. Executed results are separately recorded in `evidence/acceptance-part-76.json`, `evidence/runtime-part-76.txt` and `docs/remaining-work-part-76.md`.
