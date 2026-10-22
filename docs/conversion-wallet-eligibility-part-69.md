# Part69 — current conversion wallet facts and identities

## Real application change

`ConversionPolicyGuard` delegates wallet authorization to `ConversionWalletEligibility` after the existing member, fee and rolling-limit gates. Quote issuance and new conversion execution now read the current source and existing destination under their actual transaction, rather than authorizing from ordinary reads backed by an old REPEATABLE READ snapshot.

The explicit decisive method requires both Laravel's managed transaction and PDO's actual transaction. It acquires the existing User mutex before querying the requested owner's same-currency Primary/Win roles in canonical role-list and ascending-ID output order with `FOR UPDATE`. Missing, soft-deleted and explicitly locked source wallets cannot authorize. An existing soft-deleted or locked destination is refused, not treated as an absent destination to recreate. Raw status values are checked safely: source must permit debit, destination must permit credit. This preserves the existing rule that Suspended may receive credit but may not debit; unknown statuses fail closed without an enum-cast exception.

Affordability uses exact decimal subtraction of the freshly read `locked_balance` from `balance`. Reserved funds are not spendable. Negative, malformed, oversized or internally inconsistent source facts do not grant authority. No float accumulation is used. Native SQL DECIMAL precision remains the authoritative native precision contract; accepting SQLite numeric-affinity hydration is not a claim that SQLite faithfully represents every 20-digit native decimal.

`ConversionPolicyGuard::evaluate()` retains its public contract: it never takes wallet locks, even inside a managed transaction. Its verdict remains advisory; only decisive execution may grant new authority. Member/limit checks retain their separate locking behavior.

Outside a managed transaction, `assertAllowed()` is a nonlocking write-connection advisory check. It never creates a wallet, quote, hold, ledger posting or authority that survives until execution. Inside a managed transaction it delegates to the physical-transaction-checked decisive method.

## Fix after policy: do not rediscover from the old snapshot

A current policy read is insufficient if the conversion then issues another ordinary source lookup or `firstOrCreate` against its old snapshot. The source could have been legitimately created while the caller waited, or a destination could now exist while the old snapshot still says it is absent. The old path could falsely report missing source or attempt a duplicate destination insert after a correct policy decision.

Actual `WalletConversionService::convert()` now reuses the current pair returned by the new component. It creates a destination only if that decisive read found no existing destination. Creation remains inside the existing owner-held money transaction. The existing pair relock and second policy check remain before quote consumption and posting. Missing source is never auto-funded or replaced with another owner's wallet. Quotes do not create destinations or reserve money.

These changes do not alter pricing, exact rolling totals, quote lifecycle identity, refund-recovery admission, ledger posting, hold-floor/maximum-balance controls or reversal permission/accounting proof. Those remain independent requirements, not properties inferred merely from this wallet check.

## Original replay

Exact original conversion replay remains before new policy and wallet discovery. The original owner, request fingerprint and settled original accounting still have to be proven. Restricting the current source/destination's status or lock marker does not post again or turn replay into a new grant. This is not permission to replay a changed identity, bypass authentication, restore deleted accounts or weaken operator reversal authority. Deleted-identity historical replay is not newly certified by these tests.

## Executable coverage

The dedicated feature selection covers both real quote and real conversion refusals, unchanged original quote/financial paper, raw debit/credit status semantics, nonlocking advisory evaluation, physical/framework transaction divergence, current query ordering, missing destination creation only at execution, stale cached available-balance models, exact available-funds boundaries, owner isolation and observational original replay.

Fourteen native profiles create two independent old RR wallet snapshots before the parent acquires and changes the real User-held policy boundary. Both children must be observed in `information_schema.INNODB_LOCK_WAITS` waiting on that parent. After parent commit, one actual child requests a quote and one requests a conversion. Eight profiles revoke source/destination status, lock markers, funds, available funds through the stored held counter, or soft-deletion state. Both must refuse without consuming the original offer or changing the captured financial paper.

Five positive profiles restore source status, destination status or funds, or create a previously absent source/destination. Both current quote and real money execution must work despite the old negative/missing snapshot. A final profile replays the same completed original in both children after both wallets are restricted and must change no money. Raw lifecycle/counter changes in these fixtures isolate eligibility behavior; they are not represented as authenticated deposits, real holds, reconciliation receipts or historical accounting repairs.

The mandatory CI step requires the exact 46-case dedicated native selection, fourteen genuine two-child waits, matching independent snapshot pairs, two missing-role old snapshots, two old Frozen snapshots and one old insufficient-funds snapshot. Local workflow parsing/compilation is distinct from a remote CI execution. Full results and qualified omission controls are in `evidence/acceptance-part-69.json` and `evidence/runtime-part-69.txt`. Overlapping suite counts must never be added.

## Boundary

Qualification covers tested local owner-serialized InnoDB REPEATABLE READ admission and identity reuse. Ascending-ID output/order clauses do not certify every engine's physical scan or lock-acquisition order. No bound on all SQL scan work, all physical locks or production latency is claimed. Arbitrary privileged identity/lifecycle writers or wallet creators, other isolation modes, all other money lanes, distributed failover, production load and custom DST accounting timezones remain separate work. No schema migration, historical wallet repair, evidence relabeling, arbitrary writer serialization or foreign-exchange authority was added.

Provider/merchant credentials remain owner-supplied later and did not defer this application implementation. No live provider operation, capture, payout, transfer, refund, remote CI run, populated production restore or worldwide legal approval is invented.
