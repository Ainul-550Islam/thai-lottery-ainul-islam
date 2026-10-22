# Part64 — original conversion accounting before replay or reversal

## Application change

`WalletConversionService` now treats a settled label as insufficient authority. Its exact-key lookup is a current locking read under the existing player mutex. `ConversionIntegrityGuard` re-reads the candidate, original and bounded reversal membership on the same physical SQL transaction before a replay can return. New conversions are also proved after their money/journal writes and before quote consumption and completion audit. Reversals are proved before movement and again after reciprocal parent/child markers exist, before either success audit.

This is structural independent-source corroboration, not a cryptographic receipt against an arbitrary privileged writer coherently rewriting every source. There is no new schema, historical enrollment, background repair or provider call.

## What must agree

- Conversion identity, direction, owner, currency, distinct source/destination wallets and wallet types; exact positive two-decimal input/output and signed fee arithmetic; completion timestamp and valid settled status.
- The stored 64-hex channel-scoped idempotency identity, canonical request fingerprint and historical quote reference. Originals are not repriced against today's fee settings.
- Both current wallet identities, including ownership, currency and type. Their **current balances, later lock flags, holds and policy eligibility are not substituted for historical balances**.
- Historical before/after arithmetic on both conversion snapshots and the corresponding journal wallet legs.
- The original `FinancialTransaction`, including hidden/deleted discovery: completed/processed state, owner, wallet, currency, original-versus-reversal type, amount, nonnegative transaction fee, conversion-specific idempotency identity, reference linkage, description and engine/conversion metadata. Generic reversal markers on that transaction cannot silently authorize conversion replay.
- The full bounded original journal, including soft-deleted and unposted rows. There must be exactly two wallet liability legs plus exactly one revenue leg when the fee is nonzero, no extra hidden leg, exact side/amount/currency/reference/account identity and exact snapshots. The system fee leg requires a genuinely NULL wallet and NULL wallet-balance snapshot, not a wildcard wallet match. Its canonical account is currency-specific `4100`; wallet liability uses `2000`.
- Original chart rows must remain present, active and of their canonical currency/type/code. A removed, inactive or reclassified source chart is a controlled-review condition, not something the guard recreates.

## One reciprocal inverse only

A completed original has no discovered reversal child and no reversal markers. A reversed original has exactly one completed inverse child, its reciprocal marker and timestamp, a shared nonempty reason, opposite direction, swapped wallets and exact inverse amounts/fee. Historical fee-rule facts must match; the child's own canonical request fingerprint binds the original, operator and reason. Both original and child financial transactions and full journals are independently proved. Children cannot acquire an independent reversal chain. Membership reads are bounded to two candidate children and four journal rows, enough to reject surplus without unbounded replay scans.

Normal exact replay failures use `conversion_original_unproven` with HTTP422 and a safe controlled-review message. A different request using the same key still receives the existing conflict contract. Pre-movement original-reversal proof failures retain `reversal_unavailable`; a corrupted settled reversal retry is refused by the common replay proof. Invalid persisted enums are not allowed to escape proof as a false completed result. SQL/infrastructure exceptions remain SQL exceptions, preserving Laravel transaction retry behavior instead of mislabelling a database failure as a financial refusal.

## Posting NULL semantics and compatibility

The shared posting validator normalizes both omitted wallet IDs and explicit NULL to NULL. Its established default posting identity falls back to the transaction wallet, and other existing financial proofs depend on that historical identity. Part64 therefore adds an explicit trusted `preserveExplicitNulls` opt-in to `LedgerPostingService::post`, used by the conversion writer only. Conversion supplies explicit wallet snapshots for its two wallet legs and explicit NULL for the optional system fee leg. Both NULL wallet and NULL snapshot survive storage. Other writers retain their existing fallback semantics; no source guard is loosened and no historical record is rewritten.

Earlier conversions whose fee leg was attached to the transaction wallet or carried a system-account balance in the wallet-snapshot slot are **not automatically adopted as canonical**. Replay/reversal holds them for controlled accounting review. They must not be repaired by copying current wallet balances, deleting a journal leg, forging a receipt or simply relabelling completion. A separately approved, original-evidence-backed historical remediation procedure is outside this increment.

## Locking and atomicity

Existing service order remains owner mutex, current conversion row and sorted wallet pair before source proof/money. The guard also takes wallets in ascending ID order and uses locking current reads for original transactions, full entries and account identities. No provider/network I/O runs within this SQL boundary. The outer service transaction/savepoint owns all writes. A proof failure after movement rolls back wallets, versions, chart caches, conversion markers, transactions, journals, quote consumption and successful audits even when an ambient caller catches the refusal and commits its outer transaction. A truthful rejection audit may remain; it is not an economic repair or success claim.

The Part63 known-refund admission gate remains **after proved exact replay and before new work**. It is not applied to quotes or generic wallet mechanics and is not a blanket prohibition on independently authorized original reversal. The new proof does not invent provider authentication, globally clear unknown debts or introduce an FX writer.

## Verification contract

`ConversionOriginalProofTest` exercises transaction, conversion, journal and chart corruption; NULL fee identities; hidden surplus and missing legs; original/reversal replay linkage; later legitimate activity and locked-wallet historical replay; API422; missing physical transactions; and before-commit fault injection in both root and ambient transactions.

`ConversionOriginalProofServerTest` uses two independent PHP child processes on the explicitly disposable local InnoDB server. Four profiles require both children to wait on the parent's owner lock. Three additionally establish actual old repeatable-read snapshots: an empty conversion lookup before the parent commits a conversion, and two valid-original transaction snapshots before the parent corrupts that transaction. Classic RR behavior is selected only in those disposable MariaDB child sessions, with mode restoration, so an engine snapshot-abort cannot replace the current-read proof.

CI retains all previous gates and adds a mandatory 55-case, zero-skip focused run with four genuine wait witnesses and the three exact declared snapshots. Local workflow parsing does not claim a remote CI run. Deliberate shadow-source omissions separately demonstrate that the tests reject missing proof/current-read behavior. Current executed counts and exact-ID inventories are in `evidence/acceptance-part-64.json` and `evidence/runtime-part-64.txt`; overlapping default/native/focused totals must not be added.
