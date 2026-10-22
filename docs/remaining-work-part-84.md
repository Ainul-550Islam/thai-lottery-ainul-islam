# Part84 completed scope

Complete default: 5560 cases / 152390 assertions; 265 retained conditional/platform skips across 345 isolated source-file processes/databases; zero errors/failures, no new skip.

Executed native: 1838 cases / 18095 assertions; zero skips/errors/failures. Raw selection 1839 includes one SQLite-only trigger record separately passed default.

New overlapping native subset: 88 cases / 1221 assertions; zero skips/errors/failures. Pure invocation unit: 48 / 629; SQLite posting: 40 / 576, zero skips.

All previous 5472 default and 1750 executed native case IDs retained. All 112 genuine contention profiles retained; no new race profile claimed.

After existing caller-state admission, the public post acquires a lease on the physical write PDO, runs the unchanged posting pipeline privately and releases its own token in finally. Same-PDO recursive attempts fail before root/account queries or posting DML, even through another service instance or wrapper.

The weak-key owner-token registry is process-local and performs no SQL, connection resolution, transaction management or permission decision. Failed acquisition never owns or clears another lease. Repeated release cannot clear a later owner. Independent PDOs and sequential calls remain independent.

Forty-eight pure cases cover ordinary returns/exceptions/Errors, repeat acquisition/refusal, stale release, independent handles/release order, weak-key GC and wrapper aliases. Forty feature cases cover four recursion modes across service identity, THB/BDT and root/savepoint depth, plus sequential 10+3=13 cache projection and failure-release retries.

Six exact-original Part83 controls have six healthy counterparts, reflection source hashes, unchanged assertions and independent post-process reads. Original callback recursion commits four balanced rows but caches both shared accounts at 10.00 while stored totals are 13.00. Healthy propagation leaves zero rows; caught refusals allow only the outer two-row 10.00 journal. All caller markers commit. Synthetic disposable books only.

PHP syntax 2219 files; Pint four changed PHP bodies; Python 100; zero Composer advisories/abandoned; genuine locked Vite and 27 locked Rust tests/release before complete default acceptance. No new JavaScript test execution claimed.

The native workflow adds an 88-case reentrancy zero-skip gate/artifact and retains all prior boundary/payload/money/projection/identity/observer/current-read gates. Locally parsed, not remotely executed.

No migration, schema widening, financial arithmetic/projection/monetary/payload or root-lock change. Public post signature and caller transaction ownership remain unchanged.

This is synchronous same-PDO admission, not a distributed lock, cross-process mutex or callback sandbox. Independent/replacement PDOs, earlier wallet/callback DML, arbitrary later hooks and Part83 same-handle restart/stale-driver limitations remain owner responsibilities. An outer failed money operation still requires whole-operation rollback where possible. Fatal exits bypassing finally require discarding the failed worker, not forcing another lease clear.

The four root STATIC_ONLY reports remain generator-owned; runtime proof is dedicated Part84 evidence. No historical rewrite, external transfer, populated-production restore or worldwide legal approval is claimed.

Runbook: docs/ledger-posting-reentrancy-part-84.md.

Provider/merchant configuration and rollout approvals remain separate owner deployment inputs. This synchronous posting fence is implemented and locally verified within the limits above.
