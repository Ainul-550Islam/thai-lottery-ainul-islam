# Payment Master Prompt — Part 5 Policies and Jobs Verification

**Verification date:** 2026-11-17  
**Scope:** Every policy and asynchronous payment job explicitly named in the supplied segment. Callers, policy registration, scheduler/dispatch producers, queue routing, retry behavior, idempotency, terminal-state handling, and sensitive payload boundaries were traced.

## File-by-file matrix

| Prompt path | Disposition | Verification |
|---|---|---|
| `app/Policies/PaymentPolicy.php` | BROKEN → FIXED | Exact file exists and passes syntax. Owner reads previously bypassed the active-account condition used by permission reads. Owner access now requires an active principal; foreign rows still require explicit policy permission. |
| `app/Policies/PayoutPolicy.php` | BROKEN → FIXED | Exact file exists and passes syntax. Owner/operator/viewAny shortcuts now reject inactive principals. Pending-state and strict owner checks remain intact. The policy is now explicitly registered for `Payout`, rather than depending solely on convention discovery. |
| `app/Policies/WalletPolicy.php` | BROKEN → FIXED | Exact file exists and passes syntax. Owner reads now require an active principal; foreign wallet access remains permission-controlled. |
| `app/Policies/LedgerPolicy.php` | BROKEN → FIXED | Exact file exists and passes syntax. `LedgerEntry` has no `user_id`, so the inherited ownership helper could never recognize a player's own wallet entry. Ownership now resolves through the entry's wallet. CRUD authorization is always denied because ledger history is service-posted and corrected only with compensating entries. The global super-admin shortcut explicitly defers these immutable actions to the policy. |
| `app/Policies/MemberBankAccountPolicy.php` | EQUIVALENT / ABSENT | Exact file is absent because the checkout has no `MemberBankAccount` model/table/controller. Current withdrawals use validated, encrypted, immutable destination snapshots. No dangling registration or caller exists, and no duplicate mutable bank-account domain was invented. |
| `app/Jobs/ProcessPaymentWebhookJob.php` | PASS | Exact persisted-envelope job exists and passes syntax. Active v2 controller producer dispatches only the persisted row id after commit; the service re-verifies stored evidence and application is idempotent. Routes to `webhooks`; permanent refused evidence is recorded without futile retry. |
| `app/Jobs/Payment/ProcessPaymentWebhookJob.php` | EQUIVALENT / LEGACY | Exact legacy normalized-DTO job exists and passes syntax. No production producer was found; current ingress uses the persisted-envelope job. It remains for queued-payload/backward compatibility and tests, routes to `webhooks`, is unique by gateway/event, and explicitly cannot reopen unverified bKash/Nagad or unsupported crypto ingress. It was not wired back into production. |
| `app/Jobs/Payment/DisburseWithdrawalJob.php` | PARTIAL / DORMANT PRODUCER | Exact job exists and passes syntax. It carries withdrawal id, uses a unique lock, terminal guards, after-commit dispatch semantics, bounded retries/backoff, and delegates money movement to the canonical three-phase service. No production dispatch producer was found; current operator execution invokes that same canonical service synchronously and uncertain outcomes go to reconciliation. No competing producer was invented without workflow evidence. |
| `app/Jobs/ReconcilePaymentProviderJob.php` | PASS | Exact file exists and passes syntax. Scheduled hourly on the reconciliation queue, pages by monotonically increasing payment id, records comparison evidence rather than mutating money, and dispatches the next page only when progress is proven. |
| `app/Jobs/ExecutePayoutTransferJob.php` | PASS | Exact file exists and passes syntax. Produced by approved batch routing, financial-critical queue, after-commit, batch uniqueness plus transfer-anchor idempotency, approval/status filters, wallet-method exclusion, per-member refusal isolation, and audit summary are present. Money movement remains delegated to `PayoutTransferService`. |
| `app/Jobs/PollThaiBankPayoutStatusJob.php` | BROKEN → FIXED | Exact file exists and passes syntax. It polls status only and never submits a payout; unique attempt locks, status/amount/currency checks, terminal guards, hold preservation, max-age/manual-reconciliation handling, and sanitized errors are covered. It previously had no explicit queue and could drift to the generic default worker. It now routes after commit to the declared `reconciliation` queue. |

## Verified corrections

### Policy owner shortcuts now preserve account status

Payment, wallet, and payout policy owner branches no longer allow suspended/inactive users merely because the resource id matches. This makes policy-level authorization safe even outside routes that currently carry active-user middleware.

### Ledger ownership follows the actual schema

A ledger entry belongs to a wallet, not directly to a user. The old generic `user_id` check always failed for ordinary players. The policy now checks the wallet owner while retaining permission-based operator reads. Create, update, and delete remain impossible through Gate, including for super-admin, preserving append-only financial history.

### Thai-bank poll jobs use the reconciliation worker

`PollThaiBankPayoutStatusJob` now declares `QueueName::Reconciliation` and after-commit dispatch. Both delayed gateway dispatch and scheduled recovery sweeps therefore land on a worker that is explicitly provisioned for reconciliation.

## New direct coverage

Added `tests/Feature/Security/PromptPolicyAndPaymentJobCoverageTest.php`, proving:

1. Inactive owners cannot use payment, wallet, or payout ownership shortcuts.
2. Active owners can read their payment, wallet, wallet-backed ledger entry, and payout but not foreign resources.
3. Ledger create/update/delete is denied even to an active super-admin.
4. All six named jobs route to a declared worker queue, including Thai-bank polling on `reconciliation`.

## Executed evidence

```text
Exact files present: 10/11
Existing named files syntax: 10/10 passed
Focused policy/job regression selection: 59 passed, 317 assertions
Pint on all Part 5 changed PHP files: passed
```

A broader inclusion of `ProductionQueueComprehensiveTest` produced 2 errors and 7 failures in stale fixtures that expect now-disabled provider paths and older draw/disbursement prerequisites. The failures were outside the Part 5 edits (for example, missing provider-account context and an intentionally closed bKash normalized-DTO lane), so they are recorded rather than misreported as a pass or “fixed” by weakening current safety gates.

## Conclusion

Every prompt-named Part 5 path has an explicit disposition. Ten exact files exist and pass syntax validation. The absent bank-account policy matches the already-verified absence of that entire mutable-account domain. Five verified authorization/queue defects were corrected without adding a second payment, payout, ledger, or destination engine.
