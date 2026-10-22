# SECOND.md — P0/P1 POSTGRESQL FINANCIAL GUARDS, BI-TEMPORAL LEDGER & IMMUTABLE AUDIT

## ROLE

You are modifying the `thai-lottery-ainul-islam` Laravel 11/12 codebase.

Act as a Principal Enterprise Architect, CISO, and financial-ledger engineer.

This is **Prompt 2 of 5**. Prompt 1 closes the GLO result write-boundary and four-eyes confirmation. This prompt hardens the financial database invariants, introduces a defensible bi-temporal model, and strengthens audit tamper detection.

Do not redo Prompt 1 unless a discovered dependency requires a minimal compatibility correction. Do not implement settlement chunking, provider fallback, WebSockets, Docker, or Rust runtime workers in this prompt.

## OBJECTIVE

Make PostgreSQL enforce the same or stronger financial invariants as the application, represent both business-effective and system-recorded time, and make unauthorized modification/deletion of posted ledger entries and audit events detectable or impossible for the application database role.

This is not permission to invent accounting semantics. Inspect the existing models, migrations, database engine support, money representation, ledger posting flow, transaction lifecycle, and tests first.

---

# 1. NON-NEGOTIABLE RULES

1. Inspect the actual repository before editing.
2. Preserve correct wallet, ledger, idempotency, reconciliation, and transaction logic.
3. Do not replace entire files with toy implementations.
4. No placeholders, ellipses, pseudo-code, or “existing code here”.
5. Keep all money arithmetic exact. Never use floating point for financial values.
6. PostgreSQL is the production target; if MySQL remains supported, do not silently break it.
7. All new migrations must be safe for the actual schema and naming conventions.
8. Check existing constraints before adding duplicate constraints.
9. Do not silently rewrite historical ledger amounts or timestamps.
10. Do not create fake `effective_at` values without documenting their derivation.
11. Never treat `created_at` as a complete substitute for business-effective time when a source event has a distinct effective timestamp.
12. Posted ledger entries must not be editable/deletable through ordinary application paths.
13. Reversals and corrections must be represented by compensating entries, not history rewriting.
14. Audit records must not contain secrets, raw credentials, payment tokens, HMAC secrets, or full sensitive provider payloads.
15. Do not claim a hash chain provides authenticity if the chain key is stored beside the data and controlled by the same database administrator.
16. Do not weaken tests or skip a failing financial invariant.
17. If a constraint cannot be installed because existing data violates it, stop and report the offending rows; do not silently delete or coerce production data.
18. Execute relevant tests, and report commands/results honestly.
19. Do not claim runtime verification if dependencies or PostgreSQL are unavailable.
20. Keep this prompt limited to ledger, DB invariants, temporal fields, and audit integrity.

---

# 2. REQUIRED FILE TREE

Use the following as the minimum scope. Each `#` comment explains the intended responsibility. Reuse existing files if they already perform the same job; do not create duplicate services or migrations.

```text
app/
├── Models/
│   ├── LedgerEntry.php
│   │   # Posted ledger entry model; restrict ordinary mutation/deletion and expose
│   │   # temporal fields without allowing mass assignment of authoritative fields.
│   ├── FinancialTransaction.php
│   │   # Transaction model; effective_at/recorded_at semantics and safe fillable/casts.
│   └── AuditLog.php
│       # Append-only audit model with canonical hash/signature metadata if compatible.
│
├── Services/
│   ├── Finance/
│   │   ├── LedgerPostingService.php
│   │   │   # Single posting path; exact amount arithmetic, balanced entries, temporal stamps,
│   │   │   # and no update/delete of previously posted ledger history.
│   │   ├── WalletService.php
│   │   │   # Preserve row-locking and transaction invariants; do not introduce naive balance writes.
│   │   └── LedgerReversalService.php
│   │       # Reverses posted entries through a new balanced compensating transaction, if absent.
│   │
│   └── Audit/
│       ├── AuditLogWriter.php
│       │   # Canonical audit serialization, append-only write path, hash-chain or signed-event metadata.
│       └── AuditIntegrityVerifier.php
│           # Verifies sequence, prior hash, entry hash and external signature when configured.
│
├── Exceptions/
│   └── LedgerIntegrityException.php
│       # Stable, safe domain exception for mutation, imbalance, temporal, or integrity failures.
│
├── config/
│   └── audit.php
│       # Audit chain/signature policy; secrets must come from environment/KMS, not source control.
│
├── database/
│   └── migrations/
│       ├── *_add_financial_temporal_columns.php
│       │   # Adds effective_at/recorded_at safely to actual transaction/ledger tables.
│       ├── *_add_postgresql_financial_constraints.php
│       │   # PostgreSQL CHECK/unique constraints matching verified schema.
│       ├── *_protect_ledger_and_audit_rows.php
│       │   # PostgreSQL triggers/privileges for posted ledger/audit immutability, if supported.
│       └── *_add_audit_integrity_columns.php
│           # Adds hash-chain/signature metadata only after checking current audit schema.
│
└── tests/
    ├── Feature/
    │   ├── Finance/
    │   │   ├── PostgreSqlFinancialConstraintsTest.php
    │   │   │   # Verifies DB-level financial invariants on actual PostgreSQL.
    │   │   ├── LedgerImmutabilityTest.php
    │   │   │   # Proves posted entries cannot be mutated/deleted through supported paths.
    │   │   ├── LedgerTemporalSemanticsTest.php
    │   │   │   # Proves effective_at and recorded_at remain distinct and consistent.
    │   │   └── LedgerReversalTest.php
    │   │       # Proves corrections use compensating entries, never historical edits.
    │   └── Audit/
    │       ├── AuditAppendOnlyTest.php
    │       │   # Proves audit events are append-only.
    │       └── AuditIntegrityVerifierTest.php
    │           # Proves changed/deleted/reordered audit events fail integrity verification.
    └── Unit/
        └── Finance/
            # Exact-money, temporal validation and reversal service unit tests.
```

---

# 3. PHASE A — SCHEMA AND DATA INVENTORY

Before changing schema, inspect and document:

```bash
grep -RInE "Schema::create\\('(wallets|ledger_entries|financial_transactions|audit_logs)'|Schema::table\\('(wallets|ledger_entries|financial_transactions|audit_logs)'" database/migrations
grep -RInE "effective_at|recorded_at|posted_at|created_at|deleted_at|softDeletes|balance|locked_balance" app/Models app/Services/Finance database/migrations
```

Confirm:
- exact table names;
- numeric precision/scale of monetary columns;
- ledger debit/credit representation;
- account normal-balance rules;
- idempotency unique indexes;
- existing soft-delete behavior;
- whether `audit_logs` already exists and which fields it stores;
- supported database drivers;
- existing tests/factories that rely on nullable timestamps.

Do not assume `ledger_entries.ledger_account_id` or `financial_transactions.wallet_id` exists; inspect actual migrations and adapt indexes to real columns.

Write findings into the implementation report before claiming a migration is safe.

---

# 4. PHASE B — POSTGRESQL FINANCIAL CONSTRAINTS

Create a new migration with a unique timestamp, for example:

```text
database/migrations/2026_10_09_000100_add_postgresql_financial_constraints.php
# PostgreSQL-native CHECK and uniqueness constraints for financial invariants.
```

Use actual schema discovered in Phase A.

At minimum, enforce the following where the schema supports them:

### Wallets

```text
balance >= 0
locked_balance >= 0
locked_balance <= balance
```

### Ledger entries

```text
amount > 0
entry type is within the exact supported enum/value set
currency is non-empty and valid under the existing currency policy
```

### Financial transactions

```text
amount > 0 where zero-value transactions are not a valid domain event
fee >= 0
currency is valid
idempotency key uniqueness is enforced at the correct business scope
```

### Double-entry transaction invariant

If entries are represented as `debit`/`credit` rows, enforce per-transaction balance through the posting service and, where feasible, a deferred PostgreSQL constraint trigger. Do not attempt to express a cross-row sum as a normal `CHECK` constraint.

A deferred constraint trigger must:
- run at transaction commit;
- validate only the relevant transaction;
- support multiple entries written during one transaction;
- use correct account/currency semantics;
- avoid accepting an empty posting when the transaction requires entries;
- be covered by integration tests.

If the existing design already uses a posting transaction state machine, preserve it and validate the terminal posted state instead of inventing a new one.

### Safe migration requirements

- Check existing rows before applying constraints.
- If invalid rows exist, fail with a diagnostic listing identifiers/counts; do not auto-fix balances.
- Use stable, unique constraint names.
- Make rollback explicit.
- Do not create constraints that contradict valid domain behavior.
- Add PostgreSQL-specific SQL only behind the driver check.
- Preserve MySQL support if the project currently claims it.

Do not blindly copy constraint names from this prompt without checking existing migrations.

---

# 5. PHASE C — BI-TEMPORAL ACCOUNTING MODEL

Add the temporal model only after confirming existing semantics.

Required meanings:

```text
effective_at = when the economic/business event took effect
recorded_at  = when the platform committed/recorded the fact
```

Examples:

```text
Normal bet debit:
effective_at = bet acceptance time
recorded_at  = ledger posting/record time

Delayed provider settlement:
effective_at = provider's verified settlement effective time
recorded_at  = when the platform accepted and posted the settlement
```

## Migration

Use an appropriately timestamped migration, for example:

```text
database/migrations/2026_10_09_000110_add_financial_temporal_columns.php
# Adds indexed effective_at/recorded_at columns to actual transaction/ledger tables.
```

Requirements:

1. Use timezone-aware timestamps on PostgreSQL (`timestamptz`) when supported by the existing Laravel schema conventions.
2. Preserve the original `created_at` and `posted_at`; do not rename or reinterpret them.
3. Existing records need a documented backfill rule.
4. If the source has a trustworthy business timestamp, use it for `effective_at`.
5. If no trustworthy source timestamp exists, use a documented legacy backfill such as the original posting timestamp and mark the record as legacy-derived if the schema permits.
6. `recorded_at` should derive from the original system-record timestamp where available.
7. New ledger entries must inherit or receive temporal values through the trusted posting service, not arbitrary caller mass assignment.
8. Add indexes only for query patterns actually needed for reconciliation and historical as-of queries.
9. Update model casts and factories.
10. Add tests for late-arriving events where `effective_at < recorded_at`.

Do not write a migration that uses `useCurrent()` as an unexplained substitute for historical effective time.

---

# 6. PHASE D — LEDGER IMMUTABILITY

Inspect:

```text
app/Models/LedgerEntry.php
app/Services/Finance/LedgerPostingService.php
app/Services/Finance/WalletService.php
```

The system must follow:

```text
Posted entry is immutable.
Correction is a new compensating entry.
Reversal is a new financial transaction.
```

If `LedgerEntry` uses `SoftDeletes`, determine whether that is compatible with the ledger's accounting requirements. A posted ledger entry must not disappear from accounting history merely because `deleted_at` is set.

Implement the smallest safe change that fits the existing domain:

- prohibit ordinary update/delete of posted entries;
- avoid broad protection that prevents legitimate creation inside the posting service;
- do not rely only on model events, because raw SQL bypasses them;
- add PostgreSQL trigger/permission protection if compatible with the application's DB role model;
- document a separate, tightly controlled migration/maintenance role if schema changes need privileged access.

If using a PostgreSQL trigger, use a clear error such as:

```text
posted ledger entries are append-only; use a compensating transaction
```

Do not make audit/ledger data immutable by relying on a flag controlled by ordinary application input.

---

# 7. PHASE E — AUDIT TAMPER EVIDENCE

Inspect the current audit model, migrations, writer, redaction rules and all call sites first.

The goal is to detect:
- changed event payload;
- deleted event;
- reordered event;
- broken previous-hash link;
- invalid signature when signatures are configured.

## Hash-chain design

Canonicalize a strictly defined set of fields, for example:

```text
event_id
occurred_at
actor_type
actor_id
action
subject_type
subject_id
redacted_payload
previous_hash
key_id (if signed)
```

Use deterministic JSON serialization and a documented hash algorithm such as SHA-256.

Conceptually:

```text
entry_hash = SHA256(canonical_event || previous_hash)
```

Do not concatenate ambiguous strings without canonical encoding or length-prefixing.

The chain writer must:
- lock the chain-head row or use an equivalent atomic sequence;
- assign monotonically increasing sequence numbers;
- append events in a transaction;
- prevent two writers from claiming the same previous hash;
- avoid rewriting prior events;
- preserve existing redaction;
- never include secret material in the event payload.

The verifier must detect missing sequence, broken link, hash mismatch, duplicate sequence and invalid signature.

## Signing key

A hash chain alone does not prevent a privileged database administrator from rewriting the entire chain.

If signing is implemented:
- read the signing key from an external secret manager/KMS or protected environment variable;
- store only `key_id` and signature in the database;
- never store the private key in `audit_logs`;
- support key rotation by key ID;
- fail closed for high-risk signed events when signing is required but unavailable.

If the repository cannot safely implement an external signer in this task, implement hash-chain integrity and explicitly report that it is **tamper-evident, not cryptographically non-repudiable against a privileged database administrator**. Do not overclaim.

---

# 8. REQUIRED AUDIT EVENTS

At minimum, preserve or add structured events for:

```text
wallet.debit
wallet.credit
ledger.posted
ledger.reversed
payout.created
payout.settled
withdrawal.approved
withdrawal.rejected
draw.result.ingested
draw.result.confirmed
draw.result.published
admin.financial_override
audit.integrity.failure
```

Do not duplicate events if existing code already writes equivalent events. Prefer extending the existing audit writer.

Ensure audit failure behavior is explicit:
- for critical financial operations, audit append should participate in the same DB transaction when it is a required accounting control;
- if an external sink is used, use a transactional outbox and retry policy rather than fire-and-forget logging;
- never allow a failed required audit append to leave a financial operation partially committed.

---

# 9. EXCEPTION / ERROR HANDLING

Create or extend:

```text
app/Exceptions/LedgerIntegrityException.php
# Stable domain exception for unbalanced posting, temporal invalidity,
# immutable-entry violation, and audit integrity failure.
```

Requirements:
- stable error codes;
- safe scalar context only;
- no credentials/secrets/full payment payloads;
- do not expose SQL, connection strings, or raw stack traces to public API clients.

Use existing exception conventions if already present rather than introducing a competing hierarchy.

---

# 10. TESTS REQUIRED

Create or extend the following tests, reusing existing files where equivalent:

```text
tests/Feature/Finance/PostgreSqlFinancialConstraintsTest.php
# Actual PostgreSQL constraints reject invalid wallet/ledger/transaction values.

tests/Feature/Finance/LedgerImmutabilityTest.php
# Posted ledger rows cannot be changed/deleted; reversal uses compensating entries.

tests/Feature/Finance/LedgerTemporalSemanticsTest.php
# Late-arriving events preserve effective_at < recorded_at and historical values.

tests/Feature/Finance/LedgerReversalTest.php
# Reversal is a new balanced transaction, never an edit to history.

tests/Feature/Audit/AuditAppendOnlyTest.php
# Audit event append-only behavior.

tests/Feature/Audit/AuditIntegrityVerifierTest.php
# Tampered/deleted/reordered event detection.
```

Test cases:

1. Negative wallet balance rejected at DB layer.
2. Negative locked balance rejected at DB layer.
3. Locked balance greater than wallet balance rejected.
4. Zero/negative ledger amount rejected where invalid.
5. Invalid ledger type rejected.
6. Unbalanced posting rejected by trusted posting boundary.
7. Posted ledger update rejected.
8. Posted ledger deletion rejected.
9. Reversal creates new entries and preserves original entries.
10. Effective time and recorded time can differ.
11. Existing ledger entries backfill deterministically.
12. Tampered audit payload is detected.
13. Deleted audit event is detected.
14. Reordered audit sequence is detected.
15. Concurrent audit writers cannot fork the chain.
16. Audit redaction remains active.
17. Hash/signature key is not persisted in the audit table.
18. Existing wallet idempotency and payment webhook replay tests remain green.

Tests requiring PostgreSQL must be marked or configured as PostgreSQL integration tests; do not claim SQLite verifies PostgreSQL triggers or types.

---

# 11. REQUIRED STATIC AUDIT

Run:

```bash
grep -RInE \
'UPDATE users SET balance|balance\s*=\s*balance\s*[+-]|DB::raw\(.*balance|->balance\s*=' \
app
```

Classify each match. Do not assume every assignment is unsafe; the trusted wallet service may assign a computed locked balance.

Run:

```bash
grep -RInE \
'LedgerEntry::.*(update|delete|forceDelete)|ledger_entries.*(UPDATE|DELETE)|->delete\(\)|->forceDelete\(\)' \
app/Models app/Services
```

Classify all ledger mutations.

Run:

```bash
grep -RInE \
'AuditLog::(create|insert)|new AuditLog|audit_logs' \
app
```

Ensure all required audit writes pass through the canonical writer or an explicitly approved compatibility adapter.

---

# 12. REQUIRED TEST COMMANDS

```bash
composer validate --strict
php artisan test tests/Feature/Finance
php artisan test tests/Feature/Audit
php artisan test tests/Feature/Payment
php artisan test
```

Static checks:

```bash
find app config database tests -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/pint --test
```

PostgreSQL migration verification must run against PostgreSQL:

```bash
php artisan migrate:fresh --force
php artisan migrate:status
```

Inspect constraints:

```sql
SELECT conname, pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conrelid IN (
    'wallets'::regclass,
    'ledger_entries'::regclass,
    'financial_transactions'::regclass
)
ORDER BY conrelid::regclass::text, conname;
```

Do not report a PostgreSQL test as passed if it ran only against SQLite.

---

# 13. ACCEPTANCE CRITERIA

Prompt 2 is complete only when:

- [ ] PostgreSQL enforces supported wallet invariants.
- [ ] PostgreSQL enforces ledger amount/type invariants.
- [ ] Financial transaction constraints match real domain semantics.
- [ ] Existing data is validated before constraints are installed.
- [ ] Temporal fields have documented meanings.
- [ ] Legacy temporal backfill is deterministic and documented.
- [ ] New postings receive trusted temporal values.
- [ ] Posted ledger entries cannot be edited/deleted through supported paths.
- [ ] Reversals use compensating entries.
- [ ] Audit canonicalization is deterministic.
- [ ] Audit sequence/hash integrity is verifiable.
- [ ] Concurrent audit writers cannot fork the chain.
- [ ] Existing redaction remains active.
- [ ] Signing claims accurately reflect the implementation.
- [ ] No naive wallet-balance mutation was introduced.
- [ ] Existing idempotency/replay protection tests remain green.
- [ ] No historical financial data was silently rewritten.
- [ ] Tests were executed or explicitly marked NOT EXECUTED.
- [ ] No placeholders or truncated source code remain.

---

# 14. REQUIRED REPORT

Create:

```text
P0-FINANCIAL-INTEGRITY-IMPLEMENTATION-REPORT.md
# Exact schema, ledger, temporal and audit integrity evidence for Prompt 2.
```

Include:

```markdown
# P0 Financial Integrity Implementation Report

## Schema Inventory
- actual table/column types
- current constraints
- supported database drivers

## Changed Files
- exact path — exact purpose

## PostgreSQL Constraints
- constraint names
- SQL semantics
- invalid legacy data findings

## Temporal Semantics
- effective_at definition
- recorded_at definition
- backfill rule
- late-arriving event test

## Ledger Immutability
- model protections
- DB trigger/privilege behavior
- reversal contract

## Audit Integrity
- canonicalization rules
- chain-head concurrency strategy
- hash/signature behavior
- key storage and rotation
- explicit threat-model limitations

## Tests Executed
- exact command
- exact result

## Static Scan
- exact command
- classified findings

## Remaining Risks
- only unresolved items, clearly separated into P0/P1
```

---

# 15. OUT OF SCOPE FOR SECOND.MD

Reserved for later prompts:

```text
Prompt 1 — P0 GLO write boundary / four-eyes confirmation
Prompt 3 — 100k+ chunked settlement, payout concurrency, financial queue/DLQ
Prompt 4 — certified provider fallback, scheduled ingestion, WebSocket/Reverb
Prompt 5 — production Docker, Redis/PostgreSQL/Rust runtime, final CI/load/security verification
```

Do not implement these unrelated modules here.

---

# 16. FINAL INSTRUCTION

Inspect the current source, migrations, models, and tests before changing anything.

Implement this module completely and safely.

Do not just write a report: modify the repository, add migrations and tests, execute the checks, and report exact evidence.

Preserve existing correct financial logic. Do not rewrite the wallet/ledger architecture without a demonstrated defect.

The core guarantee is:

> **Financial invariants must be enforced at the PostgreSQL boundary; posted ledger history must be append-only; effective time must remain distinct from recorded time; and audit tampering must be detectable without overstating the threat protection.**
