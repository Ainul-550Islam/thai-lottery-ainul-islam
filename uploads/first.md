# FIRST.md — P0 GLO RESULT WRITE-BOUNDARY / FOUR-EYES HARDENING

## ROLE

You are modifying the `thai-lottery-ainul-islam` Laravel 11/12 enterprise lottery codebase.

Act as a Principal Enterprise Architect + CISO + Financial Systems Engineer.

This prompt is **Prompt 1 of 5**. Complete ONLY the P0 GLO result write-boundary and four-eyes security module described below. Do not redesign unrelated wallet, settlement, Docker, WebSocket, Rust-worker, or frontend systems; those are handled by later prompts.

The goal is to make it structurally impossible for an unreviewed or self-ingested GLO result to become authoritative.

---

# 1. NON-NEGOTIABLE SECURITY MODEL

The authoritative result flow MUST be:

```text
Official / certified provider / signed manual source
                    |
                    v
             GLO IMPORT RECORD
                    |
                    v
          RESULT INGESTION RECORD
          status = pending
          ingested_by = MAKER
                    |
                    v
            FOUR-EYES CHECK
          checker != maker
                    |
                    v
       DrawResultPublicationService
                    |
                    v
        AUTHORITATIVE DrawResult
                    |
                    v
       winning numbers / settlement
```

The following flow MUST become impossible:

```text
provider -> DrawResult
provider -> publication -> DrawResult
maker -> confirm own result
unknown operator -> confirm
fixture -> production publication
unconfirmed -> publication
legacy GLO service -> direct DrawResult write
```

There MUST be exactly one authoritative publication boundary.

---

# 2. ABSOLUTE RULES FOR THIS IMPLEMENTATION

1. Inspect the current repository before editing.
2. Preserve all existing valid logic.
3. Do NOT replace a whole file with a simplified example.
4. Do NOT use placeholders such as:
   - `... existing code ...`
   - `TODO`
   - `implement this`
   - `rest of class`
   - `same as before`
5. Every modified PHP file must remain complete and syntactically valid.
6. Do not silently remove existing validation, auditing, lifecycle checks, fingerprint logic, idempotency logic, or provenance logic.
7. Do not bypass the existing `DrawResultPublicationService`.
8. Do not introduce a second result persistence path.
9. Do not use direct `DrawResult::create()`, `new DrawResult()`, `$drawResult->save()`, or direct authoritative result updates from import/ingestion/legacy services.
10. All database mutations in the critical result path must remain transactional.
11. Every operator-facing confirmation must be attributable.
12. The ingesting operator and confirming operator MUST be different users.
13. An ingestion without an attributable maker MUST NOT be confirmable.
14. `UNKNOWN` provenance MUST NEVER publish.
15. Fixture provenance MUST NEVER publish in production.
16. Do not weaken existing tests to make them pass.
17. Add regression tests for every security invariant introduced here.
18. Run all relevant existing tests plus the new tests.
19. If an existing test expects the old unsafe behavior, update the test to the new secure contract rather than restoring unsafe behavior.
20. At the end, produce a concise implementation report listing exact changed files, security invariants, and test commands/results.

---

# 3. TARGET FILE TREE

Use this tree as the minimum scope. The `#` comments are part of the specification.

```text
app/
├── Exceptions/
│   └── DrawResultException.php
│       # Domain exception for rejected/malformed/duplicate/forbidden GLO result operations.
│       # Must expose stable machine-readable error codes and safe scalar context.
│
├── Services/
│   ├── Draw/
│   │   ├── DrawResultConfirmationService.php
│   │   │   # Four-eyes checker boundary. Validates pending result, maker/checker separation,
│   │   │   # claim agreement, transactionality, publication, confirmation audit, and final state.
│   │   │
│   │   ├── DrawResultIngestionService.php
│   │   │   # Creates/updates pending result-ingestion records only.
│   │   │   # MUST NOT create or update authoritative DrawResult rows.
│   │   │
│   │   └── DrawResultPublicationService.php
│   │       # ONLY authoritative DrawResult writer.
│   │       # Must require a valid confirmed result and preserve lifecycle/integrity invariants.
│   │
│   └── Lottery/
│       ├── GloResultImportService.php
│       │   # Provider import adapter. Records provider payload/provenance and delegates
│       │   # to DrawResultIngestionService. MUST NOT persist authoritative DrawResult.
│       │
│       └── GloResultPublicationService.php
│           # Legacy compatibility facade. MUST NOT have its own authoritative persistence path.
│           # It must delegate safely or fail closed.
│
tests/
├── Feature/
│   ├── Glo/
│   │   ├── DrawResultFourEyesTest.php
│   │   │   # Regression tests proving maker cannot confirm own ingestion.
│   │   ├── GloResultImportBoundaryTest.php
│   │   │   # Proves provider import cannot directly create authoritative DrawResult.
│   │   └── GloResultPublicationBoundaryTest.php
│   │       # Proves unconfirmed/unknown/fixture-in-production results cannot publish.
│   │
│   └── Draw/
│       └── DrawResultConfirmationServiceTest.php
│           # Existing confirmation behavior plus new maker/checker invariant tests.
│
database/
└── migrations/
    # Only add a migration here if inspection proves the current ingestion/provenance
    # schema cannot persist the maker/checker identity required by the security boundary.
```

---

# 4. FIRST REQUIRED CHANGE — FOUR-EYES ENFORCEMENT

## File

```text
app/Services/Draw/DrawResultConfirmationService.php
# Four-eyes confirmation and authoritative publication coordinator.
```

Inside the existing `confirm()` transaction, immediately after:

```php
$draw = $this->lockedDraw($drawId);
$record = $this->pendingRecord($draw, throwNotPending: true);
```

enforce:

```php
$ingestedBy = isset($record['ingested_by'])
    ? (int) $record['ingested_by']
    : null;

if (
    $ingestedBy === null ||
    $ingestedBy < 1 ||
    $ingestedBy === $operatorId
) {
    throw DrawResultException::confirmationForbidden(
        $drawId,
        DrawConfirmationStatus::Pending->value,
        $ingestedBy === $operatorId
            ? 'confirmed by the same operator who ingested it'
            : 'confirmed without an attributable ingesting operator',
        [
            'draw_id' => $drawId,
            'operator_id' => $operatorId,
            'ingested_by' => $ingestedBy,
        ],
    );
}
```

IMPORTANT:

- Keep the existing transaction.
- Keep the existing row lock.
- Keep existing claim normalization.
- Keep existing stored-vs-claimed comparison.
- Keep existing publication call.
- Keep existing audit behavior.
- Keep existing response shape unless a security reason requires a change.
- Do not allow the maker/checker test to be bypassed by null, zero, string, or malformed operator IDs.
- Do not trust only a request field; use the persisted ingestion maker identity.

If the current `DrawResultException::confirmationForbidden()` semantics use a result ID rather than draw ID, preserve the existing domain contract and pass the correct persisted record identifier while still retaining draw/operator context. Do not break the exception API unnecessarily.

---

# 5. SECOND REQUIRED CHANGE — IMPORT MUST NEVER PUBLISH

## File

```text
app/Services/Lottery/GloResultImportService.php
# GLO provider adapter/import boundary. It must stage data, never publish it.
```

The uploaded repository contains an earlier patch artifact named:

```text
P0-GLO-WRITE-BOUNDARY.patch
# Historical proposed patch. Do not treat the patch file itself as implementation.
```

The current source MUST be made equivalent to the secure intent of that patch.

Remove any authoritative persistence behavior from this service.

Specifically, this service MUST NOT perform:

```php
DrawResult::create(...)
```

or:

```php
$result = new DrawResult();
$result->save();
```

or:

```php
$result->fill(...);
$result->save();
```

or any equivalent direct write to the authoritative result row.

For a successful provider import:

1. validate the provider payload;
2. require a non-empty result fingerprint;
3. detect identical idempotent re-imports;
4. record the provider import;
5. call `DrawResultIngestionService`;
6. create/update only the pending ingestion record;
7. return the import and ingestion information;
8. return `draw_result => null` for a newly imported but unconfirmed result.

For failed imports:

- record the failure;
- preserve provider/source information;
- do not create a DrawResult;
- do not publish;
- do not silently convert failure to success.

For duplicate fingerprints:

- identical fingerprint = idempotent no-op;
- do NOT create a second authoritative result;
- do NOT silently overwrite an already confirmed result;
- return the existing import/ingestion state safely.

---

# 6. THIRD REQUIRED CHANGE — LEGACY PUBLICATION PATH MUST BE CLOSED

## File

```text
app/Services/Lottery/GloResultPublicationService.php
# Legacy compatibility facade. Must not contain a second DrawResult writer.
```

This class currently has legacy publication responsibilities.

Convert it into a compatibility facade over the authoritative architecture:

```text
GloResultPublicationService
        |
        +--> DrawResultConfirmationService
        |
        +--> DrawResultPublicationService
```

It MUST NOT independently instantiate or persist:

```php
new DrawResult()
DrawResult::create(...)
$result->save()
$result->update(...)
```

If callers still depend on this service, preserve the public API where possible but route the call into the secure confirmation/publication boundary.

If the legacy method cannot safely be mapped to a maker/checker-aware workflow because it lacks an operator identity or confirmation state, it MUST fail closed with:

```php
DrawPublicationException::...
```

rather than silently publishing.

Do not preserve backward compatibility at the expense of financial/result integrity.

---

# 7. FOURTH REQUIRED CHANGE — ONE AUTHORITATIVE WRITER

## File

```text
app/Services/Draw/DrawResultPublicationService.php
# Sole authoritative DrawResult persistence boundary.
```

Audit the complete class.

It must enforce all of the following before creating/updating authoritative result state:

```text
confirmed state
+
valid draw lifecycle
+
valid result payload
+
valid provenance
+
no fixture in production
+
no unknown provenance
+
idempotent publication
+
transactional write
+
winning-number consistency
```

Do not duplicate this service elsewhere.

After implementation, this command:

```bash
grep -RInE 'DrawResult::create|new DrawResult|->save\(\)|->update\(' \
    app/Services/Draw \
    app/Services/Lottery
```

must be manually reviewed.

Every authoritative `DrawResult` mutation must originate from the intended publication boundary.

Do not mechanically delete unrelated `$model->save()` calls; classify them by model first.

---

# 8. EXCEPTION CONTRACT

## File

```text
app/Exceptions/DrawResultException.php
# Stable domain exception for result pipeline failures.
```

Preserve all existing constants and methods unless a real defect is discovered.

The following semantic states must remain distinguishable:

```text
RESULT_DRAW_NOT_FOUND
RESULT_EMPTY_PAYLOAD
RESULT_MALFORMED
RESULT_DUPLICATE_INGESTION
RESULT_CONFIRMATION_FORBIDDEN
RESULT_PUBLISH_REQUIRES_CONFIRMED
RESULT_MISMATCH_CONFIRMED
```

If a new error code is genuinely required, add it explicitly and cover it with a test.

Never put:

- passwords
- tokens
- provider secrets
- HMAC secrets
- session data
- full external API payloads
- payment credentials

into exception context.

---

# 9. PROVENANCE RULES

The result publication boundary MUST enforce:

```text
externally-authenticated  -> allowed if verification succeeded
source-verified           -> allowed
source-known              -> allowed only through proper controlled workflow
fixture                   -> forbidden in production
unknown                   -> always forbidden
```

Do not interpret:

```text
unknown
```

as:

```text
official
```

Do not default missing provenance to a trusted provider.

Missing provenance MUST fail closed.

---

# 10. DATABASE / TRANSACTION RULE

The critical operation:

```text
confirmation
+
publication
+
ingestion-state transition
+
audit
```

must remain protected by the existing DB transaction and appropriate row locks.

Do not introduce:

```php
DB::statement('...');
```

as a replacement for existing Eloquent locking unless necessary.

Do not move authoritative publication outside the transaction.

Do not perform a Redis-only lock for financial/result authority.

PostgreSQL remains the authoritative consistency boundary.

---

# 11. REQUIRED REGRESSION TESTS

Create or update:

```text
tests/Feature/Glo/DrawResultFourEyesTest.php
# Four-eyes maker/checker security regression suite.

tests/Feature/Glo/GloResultImportBoundaryTest.php
# Proves import cannot write authoritative DrawResult.

tests/Feature/Glo/GloResultPublicationBoundaryTest.php
# Proves publication requires confirmed state and trusted provenance.
```

If equivalent test files already exist, extend them rather than creating duplicate suites.

### Test A — maker cannot confirm

Scenario:

```text
maker = User A
checker = User B

A ingests result
A attempts confirmation
=> MUST THROW / REFUSE

B confirms same result
=> MUST SUCCEED
```

### Test B — missing maker cannot confirm

```text
ingested_by = null
checker attempts confirmation
=> MUST REFUSE
```

### Test C — zero/invalid maker cannot confirm

```text
ingested_by = 0
=> MUST REFUSE
```

### Test D — import does not create DrawResult

```text
successful provider import
=> GloResultImport exists
=> pending ingestion exists
=> DrawResult does NOT exist
```

### Test E — duplicate fingerprint is idempotent

```text
same draw
same fingerprint
same provider payload
=> no second authoritative result
=> no duplicate ingestion state
```

### Test F — unknown provenance cannot publish

```text
provenance = unknown
=> publication refused
```

### Test G — fixture cannot publish in production

Set production environment in the test and verify:

```text
provenance = fixture
=> publication refused
```

### Test H — unconfirmed result cannot publish

```text
status = pending
=> DrawResultPublicationService refuses publication
```

### Test I — alternate legacy service cannot bypass

Call the public legacy GLO publication API with insufficient maker/checker context.

Expected:

```text
refused
+
no DrawResult created/modified
```

### Test J — concurrent confirmation

Two different operators attempt confirmation concurrently.

Expected:

```text
exactly one confirmation
exactly one authoritative publication
no duplicate winning numbers
no duplicate DrawResult
no inconsistent ingestion state
```

Use PostgreSQL in this test if the existing test infrastructure supports it.

---

# 12. REQUIRED STATIC AUDIT

Run:

```bash
grep -RInE \
'DrawResult::create|new DrawResult|DrawResult::query|->save\(\)|->update\(' \
app/Services/Draw \
app/Services/Lottery
```

Then classify every result.

Also run:

```bash
grep -RInE \
'DrawResult::create|new DrawResult' \
app/ app/Console/ routes/ database/
```

The final report MUST identify every authoritative result creation/update site.

The desired architecture is:

```text
ONE authoritative DrawResult writer
```

If another legitimate writer exists, either:

1. route it through `DrawResultPublicationService`, or
2. prove that it is not authoritative and rename/document it accordingly.

---

# 13. REQUIRED TEST COMMANDS

Run at minimum:

```bash
php -l app/Services/Draw/DrawResultConfirmationService.php
php -l app/Services/Draw/DrawResultIngestionService.php
php -l app/Services/Draw/DrawResultPublicationService.php
php -l app/Services/Lottery/GloResultImportService.php
php -l app/Services/Lottery/GloResultPublicationService.php
php -l app/Exceptions/DrawResultException.php
```

Then:

```bash
php artisan test tests/Feature/Glo
```

Then the relevant Draw tests:

```bash
php artisan test tests/Feature/Draw
```

Then:

```bash
php artisan test
```

If PHPUnit/Pest is configured separately, also run the repository's existing preferred command.

Do NOT report tests as passing if the environment prevented execution.

If dependencies are unavailable, report:

```text
NOT EXECUTED — dependency/runtime unavailable
```

rather than claiming success.

---

# 14. ACCEPTANCE CRITERIA

This prompt is complete ONLY when all are true:

- [ ] Maker cannot confirm their own ingestion.
- [ ] Missing maker cannot be confirmed.
- [ ] Import cannot directly create/update authoritative DrawResult.
- [ ] Legacy publication cannot bypass the four-eyes workflow.
- [ ] Exactly one authoritative publication boundary exists.
- [ ] Unknown provenance cannot publish.
- [ ] Fixture provenance cannot publish in production.
- [ ] Pending result cannot publish.
- [ ] Duplicate fingerprint remains idempotent.
- [ ] Existing result fingerprint logic remains intact.
- [ ] Existing audit logging remains intact.
- [ ] Existing lifecycle validation remains intact.
- [ ] Existing winning-number generation remains intact.
- [ ] Existing API/service contracts are preserved where safe.
- [ ] No placeholder code was introduced.
- [ ] No unrelated financial/frontend code was rewritten.
- [ ] Regression tests cover all new P0 invariants.
- [ ] Static search confirms there is no hidden alternate authoritative writer.
- [ ] Full relevant test suite is executed or explicitly marked NOT EXECUTED.

---

# 15. REQUIRED FINAL REPORT

Create:

```text
P0-GLO-WRITE-BOUNDARY-IMPLEMENTATION-REPORT.md
# Exact implementation evidence for Prompt 1.
```

The report must contain:

```markdown
# P0 GLO Write Boundary Implementation Report

## Changed Files
- exact/path/file.php — exact purpose
- ...

## Security Invariants
- maker != checker
- attributable maker required
- import cannot publish
- legacy path cannot bypass
- one authoritative writer
- provenance fail-closed
- fixture forbidden in production

## Removed / Closed Bypass Paths
- exact path
- exact method
- exact reason

## Tests Executed
```bash
exact command
```

## Test Results
exact result

## Static Audit
exact command
exact relevant findings

## Remaining P0/P1 Items
Only items belonging to later prompts.
```

---

# 16. DO NOT IMPLEMENT THESE IN FIRST.MD

These are intentionally reserved for later prompts:

```text
Prompt 2:
PostgreSQL financial DB guards + true bi-temporal ledger + immutable audit chain

Prompt 3:
100k+ chunked settlement + payout concurrency + financial queue/DLQ hardening

Prompt 4:
Live GLO provider fallback + WebSocket/Reverb + automated ingestion scheduler

Prompt 5:
Docker production stack + Redis/PostgreSQL/Rust workers + final CI/load/security verification
```

Do NOT mix those tasks into this prompt.

---

# 17. FINAL INSTRUCTION TO CODING AGENT

Inspect the existing repository first.

Implement the complete P0 GLO write-boundary hardening described above.

Preserve existing correct code and business rules.

Do not provide a superficial report.

Actually modify the repository.

Do not stop after editing the first obvious file.

Trace every caller of:

```text
GloResultImportService
GloResultPublicationService
DrawResultIngestionService
DrawResultConfirmationService
DrawResultPublicationService
```

and ensure no caller can bypass the security boundary.

Then run the tests and static searches.

At completion, return:

1. exact changed file list;
2. complete explanation of each security fix;
3. exact test commands;
4. exact test results;
5. exact remaining findings for Prompt 2 onward.

The security objective is:

> **No single operator, provider, legacy service, fixture, or unconfirmed payload can cause an authoritative GLO result to reach players or settlement.**
