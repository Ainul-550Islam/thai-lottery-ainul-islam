# Part82 — ledger text and JSON admission before posting writes

## Implemented storage contract

`LedgerEntryPayloadPreflight` prepares the complete normalized journal after the existing identity, current-read, account projection and monetary guards, but before the first account update or journal insert. The public posting signature is unchanged.

- Select `description` and `reference_type` with the same null-coalescing fallback to the canonical locked transaction as the original writer. Null and missing draft values use the fallback; an empty string remains empty. The explicit-system-null flag still affects only its pre-existing wallet/snapshot behavior.
- A selected non-null string must be valid UTF-8 and contain at most 255 Unicode code points, matching the existing VARCHAR(255) columns. Four-byte characters count as one character. Never trim, normalize, truncate or substitute audit values.
- Stage metadata on a fresh, unsaved `LedgerEntry` using the actual Eloquent array cast, including an installed Eloquent JSON encoder. A non-null array must produce a string containing valid UTF-8 JSON; ordinary encoding failures, recursion, excessive PHP codec depth, unsupported values, non-finite floats and thrown serializer exceptions refuse the complete plan.
- Freeze the resulting JSON bytes. The private writer excludes metadata from `fill` and places only these trusted preflighted bytes in the model raw attributes. It does not invoke the encoder again after account writes. Ordinary save events and database structural guards still run. No new request-facing raw-attribute setter is introduced.
- Null metadata stays SQL NULL, not the JSON text `null`; empty metadata stays the encoded empty array. Existing JSON flags/serialization semantics remain unchanged for valid values.

Stable errors: `ledger_entry_description_invalid`, `ledger_entry_description_too_long`, `ledger_entry_reference_type_invalid`, `ledger_entry_reference_type_too_long`, `ledger_entry_metadata_invalid`. Error context contains only the entry index, field and transaction currency, not payload bytes, serializer messages or exception chains. Existing arithmetic/type validation and its error precedence remain unchanged.

## Verification design

48 pure cases exercise draft/fallback selection, ASCII/Thai/four-byte boundaries, invalid UTF-8, valid/null/empty/nested metadata, non-encodable values, stateful callbacks, installed encoders and malformed/non-string custom output. Forty posting cases exercise eight later-field failures in THB and BDT at root/savepoint depth, Unicode boundaries, canonical rather than stale transaction fallbacks, SQL-null metadata and serialize-once commits.

Refusal tests catch the exception and really commit an unrelated caller marker. They compare complete financial tables and original model attributes, confirm no posting DML, and verify caller depth and PDO transaction state. These assertions do not stand in for owner rollback in a real failed money operation.

Six exact-original Part81 shadow controls use unchanged feature assertions and reflection-audited source/test hashes. Separate database reads qualify the intended failures: native overlong text produces SQLSTATE 22001; invalid JSON and a throwing callback fail in the original private writer after earlier account/journal changes. Each matching healthy control has zero journal rows. Synthetic disposable books only.

## Deliberate boundaries

No migration, schema widening, account projection/arithmetic changes, root-lock changes, transaction/savepoint ownership or historical rewrite. The retained native race profiles are still required; the new tests do not add a race profile.

Trusted PHP JSON callbacks are not sandboxed: their own side effects, arbitrary model hooks, FK races, row/packet limits, engine-specific JSON limits, unavailable storage and later I/O failures still require the caller's whole-operation rollback, including earlier wallet work. Freezing checked JSON is not a generic safe-to-catch-and-commit guarantee. Arbitrary future model mutators or schema changes require fresh qualification. No external transfer, populated-production restore, remote CI run or worldwide legal approval is claimed.
