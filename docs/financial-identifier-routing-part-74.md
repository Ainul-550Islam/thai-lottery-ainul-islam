# Part74 — financial identifier fidelity before routing

## Application defect and fix

`intval`, numeric-string acceptance and Eloquent's implicit integer primary-key cast can turn a fractional, exponent, suffix-bearing, whitespace-bearing or overflowing value into a DIFFERENT live record key. A valid transaction boundary does not make that routing safe. This is a substantive wallet/financial write fix, not a change to balances in test fixtures.

`FinancialRecordIdentifier` accepts only positive PHP integers and canonical ASCII decimal strings in `1..PHP_INT_MAX`. It compares string length and lexicographic maximum BEFORE the sole conversion. It refuses floats (even integral floats), booleans, zero, signs, leading zeros, decimal/exponent forms, whitespace, non-ASCII digits, suffixes, arrays, objects and overflow. Objects are never stringified and rejected identifiers are not echoed into errors.

For a supplied Eloquent model, validation reads its CURRENT raw primary-key attribute from `getAttributes()`. `getKey()` has already applied a lossy cast. `getRawOriginal()` can refer to a stale different identifier and is not the input about to be routed.

Integrated boundaries:

- Canonical financial credit/debit execution validates the supplied wallet model before transaction/idempotency/routing work.
- Wallet relock validates a supplied model; direct integer lock refuses nonpositive keys. Multi-wallet locking validates EVERY member before de-duplication, numeric sorting or its single lock query.
- Hold, release, conditional release and consumption validate the supplied wallet before their decisive lock.
- Canonical user-wallet resolution validates either the user model's raw key or its already-typed integer input before lookup/creation.
- Deposit/withdrawal lifecycle row locks, financial-row locks and fresh `markFailed()` persistence refuse malformed identities before lookup.
- Generic compensating reversal, eligibility lookup and reversal-key derivation validate the persisted model identity before routing; the existing `exists` requirement and original-proof authority remain.

Error categories for existing unsaved model entry points remain compatible. A well-formed missing record still follows the existing missing-row path. Canonical decimal string keys remain supported. Healthy sorted locking, caller-owned roots/savepoints, native deadlock/timeout cleanup, idempotent financial replay, exact arithmetic and balanced journals are unchanged.

## Evidence and reproduction

The dedicated suite has 31 pure parser/model-cast unit cases and 106 application cases: 104 malformed-input cases across thirteen entry routes, a healthy canonical-string credit/replay/hold/release case, and sorted/de-duplicated multi-wallet locking. Each refusal checks no routing SQL, no model mutation, unchanged caller-owned physical/framework boundary and byte-equivalent accounting rows. Canonical funding and subsequent credits use the real service and ledger, never a fabricated balance update.

```bash
php vendor/bin/phpunit tests/Unit/FinancialRecordIdentifierTest.php tests/Feature/Finance/FinancialIdentifierRoutingTest.php --log-junit identifier-default.xml
```

Run the same command with `APP_ENV=testing`, `DB_CONNECTION=mysql`, an acknowledged disposable `thai_lottery_test` database and its local Unix socket. Native execution checks actual MySQL-protocol routing behavior; it is not an additional new concurrency profile. Existing native concurrency profiles are retained and separately checked.

Full default acceptance isolates every source test file in its own process, SQLite store and `TEST_TOKEN`. Native financial selection retains every preceding native case ID. Overlapping dedicated/default/native suites must not be added together as unique coverage. The new CI gate requires exactly 137 unique dedicated cases with no skips/errors/failures; local workflow validation is not a claim of remote CI execution.

Omission controls use copied source and an explicit shadow classmap/PSR-4 loader, audit loaded class filenames, and require assertion failures without setup errors. A separate canonical accounting probe compares healthy raw-key validation with its isolated `getKey()` omission, including the actually committed wallet/financial/journal state. Production source hashes are checked unchanged.

## Boundaries that are not hidden

This protects identity fidelity at the integrated service boundaries. It is NOT authorization: a forged but well-formed ID still requires existing policy, ownership and locked persisted-state checks. Nor can it detect an input already coerced by an upstream caller or PHP's non-strict typed argument conversion. It does not add an arbitrary Eloquent save fence, certify every application writer, migrate unsupported oversized unsigned identifiers, restore an already committed external effect or attest historical transaction identity.

No migration, historical key repair, provider request, live transfer, customer data access, populated production restore or worldwide legal approval is performed. Provider/merchant credentials and selected-country rollout remain owner inputs, not reasons to defer this application fix. Current executed results are recorded separately in `evidence/acceptance-part-74.json`, `evidence/runtime-part-74.txt` and `docs/remaining-work-part-74.md`.
