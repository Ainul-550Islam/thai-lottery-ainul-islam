# Part81 completed scope

Complete default: 5296 cases / 149504 assertions; 265 conditional/platform skips across 339 isolated source-file processes/databases; zero errors/failures. One new skip is exact native DECIMAL persistence, not a missing prerequisite; previous 264 skips remain.

Executed native: 1574 cases / 15166 assertions; zero skips/errors/failures. Raw selection 1575 includes one SQLite-only trigger record separately passed default.

New overlapping native subset: 73 cases / 544 assertions; zero skips/errors/failures. Pure encoding: 48 / 158; SQLite posting: 25 / 360, one native-only skip. Combined local: 73 / 518 with that same skip.

All previous 5223 default and 1501 executed native case IDs retained. All 112 genuine contention profiles retained; no new race profile claimed.

The pure encoder preflights every entry amount and selected balance snapshot at exact column scale two before the first update/insert. Amounts fit DECIMAL(20,2); signed/null snapshots fit DECIMAL(24,2). Custom Money scales cannot bypass lost-cent checks. The writer uses checked canonical strings.

Forty-eight pure cases cover canonical values, custom-scale trailing zeroes and precision loss, amount/snapshot limits, invalid snapshot types/format, signed boundaries, null/absent/fallback modes, later invalid fields and ignored keys. Twenty-five posting cases cover five failures across THB/BDT and root/savepoint depth, four positives and one genuine native boundary commit.

Snapshot selection semantics, account projection, arithmetic validation, canonical-root locks, identity/current-read checks and observer ownership remain unchanged. The preflight does not mutate original Money values or own any transaction.

Six exact-original Part80 assertion-failure controls have six matching healthy, reflection-audited controls and independent post-process database reads. Original native overflow yields SQLSTATE 22003 and a committed first debit; a selected float snapshot yields a late TypeError and the same partial journal. Original precision cases return without an error but persist 0.009 as 0.01, including an account-cache/entry discrepancy. Healthy admission writes zero journal rows in every case. All caller markers commit. These are disposable synthetic books, not provider transfers.

PHP syntax 2210 files; Pint four changed PHP bodies; Python 100; zero Composer advisories/abandoned; genuine locked Vite build and 27 locked Rust tests/release build before complete default acceptance. No new JavaScript test execution claimed.

The native workflow adds a 73-case zero-skip gate and artifact while retaining prior 82/25/18/21-case gates. Locally parsed, not remotely executed.

The caller must roll back a failed money operation as a whole, including earlier caller wallet changes. Other late failure paths such as descriptions/references, metadata encoding, foreign keys, hooks and storage faults are not certified safe to catch and commit.

The four root STATIC_ONLY reports stay exactly generator-owned; runtime acceptance is written only to dedicated Part81 docs/evidence. No migration, schema widening, historical rewrite, external transfer, populated-production restore or worldwide legal approval is claimed.

Runbook: docs/ledger-entry-money-part-81.md.

Provider/merchant details and rollout approvals remain separate owner deployment inputs; this monetary preflight is implemented and locally verified.
