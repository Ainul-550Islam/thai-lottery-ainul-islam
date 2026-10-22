# Part81 — complete ledger monetary encoding before writes

## Implemented contract

`LedgerEntryMoneyPreflight` builds a pure complete plan of canonical amount and selected balance-snapshot strings. `LedgerPostingService` runs it after the existing canonical-root, draft, identity, locked-account, current-journal and account-projection checks, but before any account update or journal insert. The writer consumes the checked strings directly; it no longer formats an unchecked amount or accepts an unchecked snapshot during the write loop.

Ledger entry amount is positive under the existing validator and stored as DECIMAL(20,2), whose inclusive positive maximum is 999999999999999999.99. The selected balance snapshot is nullable signed DECIMAL(24,2), with inclusive magnitude 9999999999999999999999.99. Existing migrations are unchanged. Values are checked with exact decimal Money/bcmath operations, never floats or silent clamping.

A Money object can have a custom finer scale even though the transaction currency and storage columns use two decimals. This preflight explicitly re-encodes at column scale two: finer trailing zeroes are accepted without changing value; nonzero discarded digits are rejected. Valid strings are canonicalised to exactly two decimals. The original immutable Money object is not changed.

Selected snapshots must be strings or explicit system nulls. Integers, floats, booleans, arrays and objects are refused rather than reaching a late type error or database coercion. Exact decimal strings can contain lossless signs/leading zeroes and trailing zeroes; scientific notation and malformed values are not accepted as monetary encodings. Negative ledger snapshots remain valid.

Machine-readable refusals are `ledger_entry_amount_invalid`, `ledger_entry_amount_out_of_range`, `ledger_entry_balance_after_invalid` and `ledger_entry_balance_after_out_of_range`. Safe diagnostic context identifies normalised zero-based line index, field and currency, plus the underlying money error code when relevant. No raw snapshot/request payload is placed in that context.

## Compatibility

The existing snapshot selection expression is preserved: legacy null means the projected account balance fallback; the trusted explicit-system mode preserves a supplied null; an absent key still falls back in either mode. Unused override keys remain ignored. Wallet snapshots and account cached balances remain separate values. Same-account opposite-side carry, normal-balance directions, negative account balances, account projection, duplicate identities, canonical-root current reads and observer ownership are unchanged.

The arithmetic validator remains reusable and unchanged. The new storage contract belongs to the posting writer. Earlier canonical-root, structural, currency, balance, identity, account and existing-journal failure ordering is retained. No transaction, savepoint, retry, range lock, global latch or new database connection is introduced.

## Demonstrated failure paths

The exact original Part80 posting service is tested in an audited shadow workspace, with the current healthy source as a matched control. A later out-of-range amount or snapshot raises native SQLSTATE 22003 after an earlier debit and cached account changes exist. A non-string selected snapshot causes a later TypeError after cache updates. A caller that catches either error and commits can retain a one-sided journal.

The more subtle native precision controls return without an exception: a custom-scale amount of 0.009 is stored rounded to 0.01 while its account projection used two-decimal arithmetic and kept that account at 0.00; a snapshot of 0.009 is likewise stored as 0.01. Balanced stored entry totals alone do not prove preservation of the intended exact amount or consistency of the account cache.

The new preflight refuses the complete monetary plan before writes. Feature regressions catch any error and reach a real caller commit before assertions. They verify financial-table snapshots, supplied transaction attributes, caller depth, physical PDO state and no posting DML; an unrelated marker can still commit. Six original-source controls have six matching healthy controls, reflection-audited source loading, unchanged test bodies and independent post-process database reads. Invalid committed synthetic books exist only in disposable databases, not as provider funding or settlement.

## Verification scope

There are 48 pure encoding cases and 25 posting feature cases. Pure cases cover canonical and finer-zero Money values in THB/BDT; amount bounds and lost cents; selected snapshot types, malformed/precision/range failures and signed endpoints; null/absent/fallback behavior; later invalid fields; and ignored extra keys. Feature cases cover five later-field failures across both currencies and root/savepoint depth, four lossless/null/currency positives, and one exact native amount/signed-snapshot boundary commit.

SQLite's numeric affinity is not treated as proof of exact high-precision DECIMAL persistence. One new exact-column boundary case is explicitly native-only and skipped on SQLite; native executes all 73 new cases without skips. Current default/native counts, frozen source hashes, retained case IDs and genuine concurrency witnesses are in the dedicated Part81 acceptance evidence. The native workflow adds a strict 73-case zero-skip gate and artifact while retaining the previous gates; it is parsed locally, not claimed remotely executed.

## Ownership and remaining boundaries

The caller still owns its transaction and must roll back a failed money operation as a whole. A no-write refusal here does not undo wallet or other changes made earlier by that caller. This monetary preflight does not certify every possible later failure: descriptions/reference string limits, metadata encoding, foreign keys, model hooks, external side effects and arbitrary storage faults remain separate concerns. No migration, schema widening, historical repair, new provider authority, external transfer, populated-production restore or worldwide legal approval is claimed.

The four root inventory reports are wholly generator-owned STATIC_ONLY documents and remain exactly generator-consistent. Actual runtime acceptance belongs to this runbook and the dedicated Part81 evidence, not manual appended sections in those generated files.
