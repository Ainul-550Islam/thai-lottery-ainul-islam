# Part67 — current open-quote slots and physical transaction boundaries

## Implemented application correction

New quote issuance no longer uses `countOpenForUser()` as admission authority. That ordinary consistent COUNT can remain old after an InnoDB repeatable-read transaction has waited for the quote owner's mutex: it can hide a newly issued quote or resurrect a quote that a preceding real conversion already consumed.

`ConversionQuoteService::quote()` retains the existing physical transaction and `users` owner lock, then existing policy and exact pricing. It captures one second-resolution issuance instant and calls `WalletConversionQuoteRepository::assertOpenSlotAvailableForExecution()` before persisting the offer. The repository selects current `FOR UPDATE` IDs, ascending, limited to the effective configured ceiling. If that many eligible IDs exist, issuance refuses with the existing `rate_limited` code. An obsolete advisory count never supplies this decision.

`max_open_per_user` retains its old effective minimum of one. There is no new hard upper clamp that silently changes an operator's ceiling. Returned rows and application memory are O(effective ceiling), not O(all matching history); this is **not a bound on database scan work, examined records or all locks the chosen engine plan can take**. Indexing, configuration sizing, lock deadlines and production load still require operational qualification.

The repository's decisive slot check, locked reference lookup and consumption now require both a positive Laravel transaction level and a physical PDO transaction. `ConversionQuoteService::assertUsable()` applies the same two-part check before either required or optional-quote handling. A stale framework counter cannot make an autocommit read look locked; a manually opened PDO transaction with no framework transaction also cannot grant this capability.

These guards establish transaction prerequisites, not introspection proving that an arbitrary caller owns a user/quote lock. The covered real quote service already holds the owner mutex; the real conversion service already holds the owner and then selected quote row before consuming it. Direct repository creation, privileged SQL writers and unrelated lifecycle writers are not magically serialized by this part.

## Eligibility and lifecycle semantics retained

An occupied slot is one quote for this owner with `status=open`, null `converted_id`, null `consumed_at`, and `expires_at` strictly after the shared issuance cutoff. At exact expiry, the slot is free. Quotes are counted across directions and currency facts for the owner, just as before. Another member's quotes never spend this member's slots. This is not FX approval or proof that another currency quote can be executed.

Open-count/list methods remain available for unlocked advisory display. Sweeping expiry is not necessary to free an overdue slot. Admission never deletes, relabels, repairs or adopts historical offers. Existing consumed/expired or partially linked lifecycle facts are not reactivated as execution authority. Their raw test fixtures establish only aggregate eligibility behavior, not fabricated valid financial receipts.

One second-resolution instant now supplies both admission expiry filtering and persisted issue/expiry terms. DTO timestamps match stored second precision rather than claiming fractional validity discarded by SQL persistence. TTL still uses its existing one-to-3600-second clamp. A slot is not a wallet hold, money reservation, permission grant, settlement receipt or guarantee of later execution. Execution must still check current quote ownership/expiry, original price terms, current policy, balances, refund exposure and rolling allowance.

A consumed quote can free an open slot without resetting any daily/monthly conversion allowance. Exact original settled replay still precedes new-work admission and retains all original accounting proof. The current reversal operator authority, root/journal/snapshot proof, explicit NULL system fee legs and separate legitimate reversal path remain unchanged.

## Executed test design

`ConversionQuoteSlotTest` supplies 31 cases:

- Five decisive paths with no transaction, a stale framework counter after direct physical commit, and an untracked PDO-only transaction: 15 cases. All refuse before quote or economic mutation.
- Effective ceilings zero/negative/one/two, refusal rollback and advisory compatibility.
- Just before, exactly at and after expiry, without deleting or relabelling old history.
- Shared second precision for DTO, stored issue time, TTL and admission cutoff.
- Consumed/expired/partially linked lifecycle facts, owner isolation, and shared direction/currency eligibility.
- Free-slot versus rolling allowance separation and proved original replay.
- One query selecting IDs with `LIMIT effective_ceiling`, not COUNT or every quote body.
- Safe member HTTP429 at capacity, with no new quote or economic rows. Existing separate refusal audit behavior is not claimed absent.

`ConversionQuoteSlotServerTest` supplies four real two-child profiles. Both children independently start physical repeatable-read transactions and actually observe the declared old advisory quote count before the parent owns the owner mutex. The parent keeps that mutex, performs the indicated real quote/conversion operation, releases both readiness barriers and commits only after both child sessions are simultaneously observed waiting on that exact parent's InnoDB lock:

| Profile | Both old counts | Ceiling | Parent mutation | Required child results |
|---|---:|---:|---|---|
| Parent fills empty slot | 0 | 1 | Real quote creation | Both `rate_limited` |
| First child fills empty slot | 0 | 1 | No quote mutation | Exactly one new quote, one `rate_limited` |
| Parent consumes slot | 1 | 1 | Real conversion consumes the previous quote | Exactly one new quote, one `rate_limited` |
| Parent fills last remaining slot | 1 | 2 | Real second quote creation | Both `rate_limited` |

Child requests exercise Cash-to-Win and Win-to-Cash. Parent quote creation posts no money. The one parent conversion is genuinely posted through the financial service, not a raw status rewrite. Children never post a conversion in these profiles. Parent and child quote history remains retained; economic rows are compared against the parent commit.

Only disposable MariaDB child sessions temporarily disable and restore optional snapshot-conflict abort mode, so the test reaches the classic stale-consistent-read path instead of treating a database abort as proof of application protection. Qualification is the actually tested cooperative local InnoDB repeatable-read path. Other isolation modes, every privileged quote writer, a distributed production deployment or a custom DST accounting timezone are not certified.

The mandatory CI step requires 35 focused cases with zero skips/errors/failures, four genuine two-child owner waits, two old `[0,0]` snapshots and two old `[1,1]` snapshots. Local workflow parsing and matched evidence are distinct from remote CI execution.

## Operational boundary

At `rate_limited`, consume an existing usable offer or wait for expiry; do not delete retained quotes, alter ownership, extend expiry or increase the cap merely to bypass a refusal. At `transaction_required`, fix the internal caller's transaction/lock discipline rather than disabling the guard. No automatic data repair, migration, backfill, money reservation, provider request, external transfer/refund, merchant credentials or worldwide legal approval is introduced. See the current runtime acceptance report for exact overlapping suite and omission-control counts.
