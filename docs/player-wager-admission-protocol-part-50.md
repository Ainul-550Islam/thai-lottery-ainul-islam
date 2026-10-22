# Current player wager admission and responsible-gaming serialization — Part50

## Actual paid-write defect and fix

Part49 closed draw/window/cancellation admission after an old purchase context. A different stale-permission path remained: `BetPurchaseTransactionService::execute()` trusted the earlier player's status and did not execute the responsible-gaming gate inside the money transaction. The ordinary public facade's unlocked preflight could be overtaken by a ban, exclusion, stricter limit or another funded wager. The context itself did not run that facade preflight. Daily wagering versions were not consulted, rolling losses considered only the first wallet, and the early public preflight defaulted to THB even for a differently configured betting currency.

New paid purchases now execute `BetPurchaseAdmission::assertAllowed()` after the actual wallet lock and idempotency recheck but before affordability/risk/bet/ticket/debit. It locks and re-reads the current player, checks the account is Active, binds the current wallet/player/currency and request player, and executes the current selected-currency responsible-gaming gate. No request flag, prior context, quote or cached User relation can disable it. The existing replacement pipeline consumes this same new-wager gate; a replacement is not free historical replay.

No accounting/debit engine was duplicated. Ordinary successful purchases still use the existing exact wallet/ledger/original ownership/receipt pipeline and preserve all Part49 draw admission and risk floors.

## Authority and lock order

For ordinary paid purchase: current wallet, existing request ownership recheck, player mutex and policy rows/current ledger evidence, current draw admission, number limits, then original bet/ticket/accounting work. The player mutex is the existing `users` row, not a cache lock, new bypass flag or new database table.

`ResponsibleGamingPlayerLock` requires both an actual framework transaction and PDO transaction. It starts or commits neither. Supported self-exclusion request/activate/expire/cancel and versioned limit pronounce/activate/expire writers take that same player mutex before policy rows. A writer given an existing policy model uses only a primary-server owner hint, then locks the owner and rechecks the policy's actual owner under its own row lock. A changed owner fails rather than silently authorizing another player's policy.

A purchase holds its player mutex to transaction completion, so two supported paid purchases using different wallets cannot jointly spend the same player's periodic ceiling. Account status updates/deletion also contend on the user row. Policy writers do not acquire wallets. Current SQL locking reads of legacy time gates, active exclusion, versioned limits and transaction evidence prevent an older ordinary REPEATABLE READ snapshot from granting permission.

This order is scoped to these supported ordinary purchases and policy writers. It does not certify every amendment/legacy settlement/admin/reconciliation writer's global lock order, every country/operator actor boundary or all wallet mutations. Existing owned roots retain their rollback/retry rules. Cross-writer deadlock/volume/scan costs still require integrated acceptance; a savepoint or retry is not proof of a remote commit acknowledgment.

## Current policy meaning

- A non-Active, missing or soft-deleted current account cannot place a new wager.
- Legacy `self_excluded_until` and `cool_off_until` time gates both bind while future, even if the legacy row was soft hidden. No client may lift them by supplying an older model.
- An Active non-ended new self-exclusion binds independently. The new lane and existing legacy stamp remain protected by the player mutex for supported service writers.
- Current versioned SingleBet and StakeLimit ceilings bind per act. DailyWagering binds gross completed stake volume plus this proposed stake. Daily/Weekly/MonthlyLoss bind completed stakes minus completed winnings/refunds, floored at zero, plus proposed stake.
- Daily wagering is NOT net loss. A refund/win may reduce loss, never already staked daily volume. Equality with a ceiling is allowed; exceeding it refuses before mutation.
- Evidence uses all completed financial transactions attributed to the player in the selected currency and rolling window, not the first selected wallet. Each exact database amount is retrieved as a string and accumulated with bcmath; there is no float SQL SUM or rounding. Soft-hidden completed rows still count. Pending/failed rows do not become completed stake; future-dated rows are not evidence of current winnings or spending.
- Versioned active/pending replacement chains are partitioned by player, limit type AND currency. Pronouncing USD must not replace a THB limit or consume its pending-increase namespace. No FX conversion or cross-currency policy guess is introduced.
- Existing cool-off/version status semantics remain. Versioned-limit activation/expiry sweeps recheck current status and horizon under their locks; exclusion expiry independently rechecks its end time. There is no historical enrollment/backfill. Legacy numeric policies without versioned currency ownership, generic ledger reversals/legacy adjustments, other limit/deposit/provider lanes and unsupported writers need their own policy/accounting integration. This release is not a universal responsible-gaming or legal certification.

Outside a transaction, the responsible-gaming facade is only a read-only preflight/projection. Inside supported new-wager roots it uses current locked policy/evidence and the shared player mutex. The facade's early check uses the configured betting currency; the authoritative transaction check uses the actually locked wallet's currency. Replays do not inherit a new stake decision from either preview.

## Historical money is a different authority

Original paid-purchase replay returns before new-wager gates, re-proves the original wallet/debit/journal/receipt and performs no new reservation, bet, ticket or debit. A later ban, exclusion or lowered current limit is not a reason to debit again, invent a new purchase or hide the original funding fact. Tests prove original replay remains no-write under those restrictions. Refund and withdrawal exit lanes are not newly gated by this purchase-only admission class. Their independent original/accounting/compliance policies remain intact.

No historical source, ownership, policy version or accounting receipt is manufactured to make a current check pass. Anonymous generic risk reservation still has no player debit authority and is not converted into durable slip ownership.

## Native proof, not only method mocks

The native profile adds three actual two-child InnoDB contention cases:

1. Two prevalidated paid purchases for the SAME player using DISTINCT actual wallets wait on the parent's decisive `users` row. Only one may consume the remaining daily wagering headroom. The refusal is a named policy failure; exactly one additional original paid receipt/debit exists.
2. A lower-limit writer and prevalidated paid purchase both wait on that same player row. The purchase linearizes before the limit and is funded once, or after it and refuses; all later new wagers refuse the committed policy.
3. A self-exclusion activation and prevalidated purchase exercise the same two legal orderings and current subsequent refusal, without blocking historical refunds/replays.

Seven additional independent-session cases execute the REAL purchase root. After its actual wallet lock, an ordinary snapshot is established. A peer commits a ban, legacy exclusion, cool-off, new exclusion, single-bet limit, daily wagering limit or completed stake on a separate wallet. Subsequent ordinary reads demonstrably remain old, yet current admission refuses without a new paid receipt/counter/debit. MariaDB's optional automatic snapshot-conflict abort is disabled only for those disposable sessions and restored; that incidental engine abort is not accepted as the application proof.

The functional profile also checks THB/USD/BDT status/exclusion/ceiling cases, exact headroom, independent currency policies, all-wallet completed losses, gross wagering after refunds, hidden time/funding facts, wallet ownership and root requirements. These are synthetic funds in owned disposable stores, not bank/provider executions. Preliminary diagnostic fixture errors are not final acceptance. The initial default image-upload shard lacked GD; after installing that extension its entire1092-case shard reran on a fresh owned store, with the same frozen sources and no filter/assertion/skip change. Other three already-passing complete disjoint shards remain in the final matrix. Initial environment failures are retained as diagnostics only. Final matrices overlap rather than additive unique coverage.

## Rollout

No schema migration/backfill is added. Deploy the current application and its already-required ownership schemas through authorized rollout. Custom policy writers must participate in the player mutex; raw SQL/trusted-PHP universal certification is not claimed. Protect the supported order and original replay semantics when integrating further actors, countries, bulk/permutation intent, completed-draw economics and legacy writers. Production KYC/AML/tax/license/liquidity/provider decisions, private deployment, browser/accessibility/security and large/multi-host acceptance remain separate authorizations.
