# Source snapshot — not a verified final release

This ZIP contains the current work on the cloned thai-lottery-ainul-islam source as of 2026-10-29. It is a work-in-progress snapshot, not proof that the requested corrections are complete, and it is not safe to deploy as a production release.

## Verification status

- The complete PHP/SQLite and MySQL lanes have NOT passed and have not been reconciled on frozen source. An exploratory SQLite run found failures, including legacy lottery/payment cases and static release-audit checks.
- A focused MySQL paid-commission run had one error and one failed race-witness assertion after the first fixture fix. They are not resolved by this archive.
- The standalone wallet-conversion foundation suite was omitted from normal discovery and failed when run directly. Conversion model changes are in progress and have not been verified as a complete fix.
- 130 browser cases were discovered but have NOT been run. A locally isolated GLO purchase-race test passed once; that does not prove all GLO or financial behavior.
- The Python inventories passed locally: 112 script tests and 24 tool tests. These do not substitute for the other test lanes.
- Legacy payout terms, provider authenticity, worldwide eligibility, POS/legacy GLO correctness and hosted CI remain unresolved. No legacy liability has been migrated or paid on the strength of this snapshot.

## Contents and precautions

Source, test files, lockfiles and the three environment templates are included. Repository documents and prior evidence describe earlier points in time and must not be read as the status of this snapshot. No actual .env file, runtime test database, cached config, logs, root vendor/, node_modules/ or generated build output is bundled. Dependencies and assets must be installed/built before local use. Run destructive migration or concurrency tests only against an independently verified disposable database; do not point this snapshot at production data.
