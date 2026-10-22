# Part61 — refund-recovery exposure and new withdrawal authority

## Money-write change

An authenticated succeeded full Stripe refund can create source-owned recovery custody while the deposit wallet no longer has the full recoverable amount. Part60 stopped new canonical paid wagers under that exposure. Part61 also refuses **new withdrawal requests and new owned withdrawal reservations** for the original player/currency. Changing the selected wallet cannot remove the source risk.

This is application behavior, not a dashboard warning or a simulated remote payment. The services enter real SQL transactions and the tests inspect actual request, hold, wallet, recovery, financial-transaction, posting and ledger rows.

## Exact admission boundary

`WithdrawalService::request()` retains its exact historical idempotency replay branch. Only a genuinely new request passes through `StripeRefundWithdrawalGuard` before checking availability and inserting the pending withdrawal. The `create()` compatibility alias uses this same path.

`WithdrawalReservationService::reserve()` calls the same guard before obtaining new held funds or inserting reservation ownership. A request made before custody publication therefore cannot acquire new payout authority after publication. Approval reaches this reservation boundary; direct reservation is covered as well.

The guard requires both a positive Laravel transaction depth and a physical PDO transaction. It re-enters the selected-wallet lock, verifies the selected owner and currency against current rows, locks the existing player row and reads the shared exact recovery source. The wallet/player locks remain held by the caller's transaction. Scope is player plus currency, not a wallet ID, staff identity or feature switch.

## Source proof remains shared, not duplicated

`StripeRefundRecoveryService::hasRecoveryExposureInTransaction()` contains the existing exact source/history and completed-financial-closure implementation. `hasWagerExposureInTransaction()` remains a compatibility wrapper. The wagering policy has not been removed or replaced with a weaker withdrawal-specific query.

The bounded complete-history limit remains 100. Custody-body hashes, receipt linkage, original settled capture ownership, source/event status and amounts, original player/currency, and completed-recovery transaction, line, account-currency, wallet-transaction and posting evidence remain material. A completion label or additional funding is not clearance. A forged completed record, incomplete financial proof, wrong chart currency or unproved source fails closed. Overflow is not a partially scanned permission to spend.

A valid pending source raises `withdrawal_refund_exposure_pending`. An unproved source raises `withdrawal_refund_exposure_unproven` with a safe message. Both are `WithdrawalException` failures, so callers cannot mistake unproved custody for an empty list. Neither admission path performs provider I/O, recovery collection or a recovery retry.

## Authenticated member API: atomic first hold and replay

The existing API initially places a generic withdrawal hold after canonical request creation. Part61 keeps those operations inside **one outer database transaction** owned by `WithdrawalRequestWithHoldService`, not by the HTTP controller. The source/player lock acquired by canonical admission consequently extends through the initial hold. There is no release-and-reacquire window in which source custody can publish between new request admission and that hold. Failure while actually updating the hold rolls back the new withdrawal and its financial rows too.

Only a newly inserted canonical row (`wasRecentlyCreated`) receives this initial API hold. An exact replay does not hold again, including after source publication. This fixes the prior repeated-hold behavior without adding a second idempotency namespace or changing the public success shape. The existing KYC gate remains after this transaction; its legitimate detention behavior is not an exposure waiver.

The two exposure refusals return member HTTP 422 with `error.code=refund_recovery_required`. Other withdrawal refusals retain their existing code. Internal custody, original deposit, receipt and source identifiers are not included in the public exposure response.

The API initial generic hold is not silently reclassified as approved payout ownership. This increment does not migrate every legacy hold into the canonical reservation table or certify every historical API-to-approval hold handoff.

## Obligations which must still settle

There is deliberately no hook in generic wallet debit, completion, release or reconciliation. Exposure must not prevent settlement of an already-owned or externally paid obligation, or prevent release of an existing hold. Exact historical request replay is observational at the service boundary; API replay also avoids a second initial hold.

The focused scenarios prove an approved manual withdrawal can complete and replay under newly pending custody: wallet balance moves from 90.00 to 80.00, its existing 10.00 owned hold is consumed once, and recovery exposure remains pending. Rejection releases an existing owned hold without pretending to repay the refund. Existing withdrawal completion, dispatch, original-proof, hold-floor and reconciliation regressions are separately included in the native affected-path run.

A positive recovery scenario adds 30.00 to a 90.00 source wallet, performs the exact 100.00 local recovery, leaves 20.00, and then permits a new 10.00 request and owned reservation. Native distinct-wallet requesters likewise proceed after actual recovery without another collection debit.

## Deterministic verification

The focused feature suite has 21 cases, including five corruption data sets, authenticated refusal, exact HTTP replay and a failure injected after an actual hold-update SQL statement. Provider inspection used to establish fixture custody is synthetic authenticated GET-only data. The admission and replay assertions verify no additional provider request.

Four server profiles execute real service requests/approvals in independent child PHP processes against the owned disposable MariaDB store:

1. Two distinct-wallet new requesters wait on the same current player row, then both refuse pending exposure with no new money ownership.
2. Two real request transactions start with old empty InnoDB repeatable-read custody snapshots. The parent publishes the original lower-ID custody while holding the player row. Current locking discovery sees it after the wait and both real requests refuse.
3. Two existing, unapproved requests cannot create new reservation ownership after publication.
4. After actual proved recovery, both distinct-wallet requesters may create requests; their observation performs no second recovery debit.

Witnesses use database-observed lock waits and distinct child connections, not process-start timing. CI includes a mandatory 25-case feature/server step, rejects failures, errors and skips, requires four distinct-wallet player waits and checks the two old empty snapshots. Local workflow parsing is not a claim that remote CI ran.

The acceptance JSON and runtime evidence contain the final full/default/native totals, exact overlap, omission-control results and source hashes. Those overlapping runs must not be summed as unique tests. The complete source bodies and cumulative archive are independently checked against the delivery registry.

## Boundaries

This is known-source admission for routed new withdrawal authority. It is not a universal debit freeze, an arbitrary-SQL-writer debt oracle, a partial-refund collection implementation or a global balance certificate. A verified inbox event without materialized recovery custody alone does not become a spending veto. No age, TTL, disabled flag, staff review or forged done label clears exact pending custody.

There is no new schema migration. The release checks rehearse the existing schema on an empty disposable store, not a populated production restore. No remote refund POST, cash transfer or merchant payout is fabricated. Provider credentials, actual production operation, power-loss durability, global writer certification and country-by-country legal permission are not established by these local tests.
