# Part63 — known refund-source admission for new wallet conversions

## Implemented authority boundary

Part63 adds a mandatory application-service gate to `WalletConversionService::convert()`. A proved pending original-player/current-currency full Stripe refund recovery, or unproved custody/closure history, refuses a NEW conversion before conversion creation, wallet movement, quote consumption or financial posting. Both Cash-to-Win and Win-to-Cash are new monetary authority. Moving value between wallet types must not bypass admission just because the original capture belongs to another wallet.

This is the existing same-currency conversion product, **not foreign exchange**. Conversion policy, KYC, account status, fee/rate rules, rolling limits, source/destination ownership, balanced accounting, permissions, immutable request identities and quote validation remain mandatory. Existing request/fingerprint conflict still wins over source admission on a reused key.

## Atomicity and serialization

The existing conversion root locks the owner row before replay, policy decisions and sorted wallet-pair locks. This increment retains that conversion ordering rather than introducing an original-deposit-wallet lock or changing the platform's global lock graph. The new guard reenters the same owner mutex after exact replay and before new policy/financial work. That lock remains held through conversion commit. The supported custody publisher shares the owner mutex, so new custody cannot be published between this admitted decision and the new conversion write.

`StripeRefundConversionGuard` requires an actual SQL transaction, acquires a current owner row and delegates source interpretation to `StripeRefundRecoveryService::hasRecoveryExposureInTransaction()`. It does not treat mutable deposit labels, a signed inbox item, wallet totals or an aggregate hold as a recovery obligation. The common source engine retains its bounded current locking reads, exact encrypted original and independent authenticated receipt chain, and full original financial/journal/chart closure proof. Saturated or contradictory history refuses; it does not skip unknown rows.

Pending sources return `conversion_refund_exposure_pending`. Invalid source/receipt/financial closure returns `conversion_refund_exposure_unproven`. Both use the existing `ConversionException` API transport with HTTP422. Refusal messages contain no provider IDs, payloads, ciphertext, SQL or credentials. The existing rejected-operation audit may persist; no new economic state or quote consumption may persist. A caller catching a refusal inside an ambient transaction cannot commit a partial conversion.

## Replay, quotes and reversal

An exact already-settled conversion replay remains observational and does not perform a new recovery scan or money movement. It cannot clear refund custody or authorize a changed payload. This increment does not claim that legacy conversion replay is a newly strengthened original-journal integrity verifier.

Quotes remain policy/price previews and may be issued while custody is pending. An earlier quote is not spending authority; execute rechecks source admission under the owner mutex. A refused quote remains unconsumed. Generic credits, debits and wallet mechanics do not receive this new conversion hook.

The separate original-conversion reversal flow is unchanged: current permitted operator, allowed channel, reason, time window, original transaction/posting proof, wallet pair locks and exact replay remain required. It is not reclassified as a fresh ordinary conversion and is not frozen by this new hook. This is not certification of all reversal writers or permission to bypass their checks.

## Source scope and limits

The fence is original player plus selected currency, not only the source wallet. Another owner's new conversion and an independently configured other-currency conversion are not automatically frozen. That currency isolation is **not** global debt clearance, value tracing or permission for implicit FX. No currency is converted into another here.

Disabled review/recovery switches, revoked staff, old receipt age and source labels cannot expire an already-proved pending obligation. A succeeded recovery label alone cannot clear admission: exact original debit and chart proof must still validate. A signed but unreviewed inbox item alone does not manufacture a financial debt.

This increment performs no provider call, collection, negative-balance repair, backfill, anonymous-hold adoption, partial refund/dispute expansion or live bank transfer. It reuses the known supported succeeded full-refund source; undiscovered obligations, every provider and every monetary writer are not globally certified.

## Executed verification

The dedicated feature class has 18 cases: both directions, pre-publication quotes, exact replay/conflict, safe authenticated HTTP422, disabled/aged custody, five source/financial corruptions, real-transaction boundary, ambient caught refusal, actual proved recovery, owner/currency isolation, signed-inbox nonauthority, generic credit and permitted original reversal.

Four native profiles use two distinct child processes, distinct source wallets and database-confirmed simultaneous waits on the parent owner mutex: pending refusal; two old empty classic-RR custody snapshots followed by publication; corrupted receipt refusal; and two balanced conversions after actual proved recovery. The snapshot profile temporarily changes only its disposable MariaDB child session mode so an engine snapshot-conflict abort cannot masquerade as application source safety.

The mandatory CI step requires all 22 cases with zero errors/failures/skips, four genuine two-child waits with distinct source wallets, and exactly one old-empty snapshot pair. Local workflow parsing is not remote CI execution. Final acceptance supplies exact complete-default discovery, selected native case IDs, assertion counts and independent archive verification. Overlapping default, native, dedicated and static totals must not be added.
