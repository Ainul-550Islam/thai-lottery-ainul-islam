# Remaining work and acceptance boundary — Part60

## Completed in this part

The agreed canonical paid-bet refund-exposure safeguard is implemented and locally accepted, not a test-only proposal. New ordinary, bulk/permutation and amendment-replacement bets cannot use the original player's same-currency wallets to bypass a published authenticated succeeded full-refund recovery obligation. It integrates current user/wallet ownership, the shared player mutex, current locked source history, safe member HTTP422 and exact actual financial closure before the money write.

A pending source is checked before risk. Completed recovery is not cleared by a label: full immutable financial/journal/chart proof is mandatory after the risk locks and before any new bet/debit, preserving canonical risk-before-chart ordering. Historical funded replay and owned cancellation/refund remain separate. A definitive pending-exposure amendment replacement is failed safely after its legitimate original refund; unproved custody is not guessed into a definitive historical outcome. No provider HTTP, provider refund, financial debit or automatic repair is performed by the wager guard.

Two additional **actual application compatibility bugs** found during acceptance were fixed rather than ignored: historical webhook-lease and KYC-verification rollback definitions failed on SQLite because their standalone indexes still referenced the columns being dropped. Both now drop those indexes first, with byte-identical `up()` schema definitions. SQLite and MariaDB regressions preserve original event/document fields and recreate exact indexes/defaults. The entire empty-history SQLite up/down/up now passes.

## Final local acceptance

| Check | Actual result |
| --- | --- |
| Complete default regression | 4,097 unique cases across all 301 source test files; 141,649 assertions; zero errors/failures; 169 explicit skips |
| Affected native SQL regression | 324 unique cases; 4,453 assertions; zero skips/errors/failures; current complete322 profiles plus two separately added unchanged-UP compatibility profiles |
| Real SQL contention evidence | 18 genuine two-child parent-lock waits; three new distinct-selected-wallet profiles, including two old empty RR snapshots observing a subsequently committed earlier-ID authenticated source |
| New source-focused feature suite | 24 cases / 684 assertions, zero failures/errors |
| Indexed-column regressions | Two cases / 34 assertions on each of SQLite and MariaDB, with original row fields preserved and no financial writes |
| Omission controls | 12 isolated intended assertion failures, one per control, no setup errors/skips; includes current-read and early-admission omissions after genuine two-child waits and verified parent/child shadow loading |
| PHP syntax / changed PHP formatting | 2,148 PHP files linted; 14 changed PHP files pass Pint |
| Python / static / standalone | 86 Python tests; static35 / 1,652 assertions; standalone29 / 94 assertions |
| Dependencies and browser build | Locked Composer audit: zero advisories/abandoned packages; npm149 packages audited, zero vulnerabilities; Node54 tests; genuine Vite production build |
| Retained Rust integrity boundary | 27 locked tests plus genuine release build; retired settlement-worker prototype is not run or activated |
| Schema | Complete empty-store SQLite up/down/up passes; no new Part60 migration or `up()` schema change |

Counts overlap and must not be added. Default skips remain explicit; selected native execution is not all-skipped-suite certification. Exact discovered default IDs, unique native composite IDs, frozen source hashes, raw log/JUnit, lock witnesses, diagnostic provenance and package integrity are independently checked in the evidence bundle.

The first existing KYC image-fixture source run encountered13 missing-GD setup errors. The matching PHP8.4 GD extension was installed, and the same unchanged51 test IDs were rerun in a new store. No source assertion or application behavior was changed to conceal that environment error. Initial logs/JUnit are retained separately; the final aggregate uses only the successful identical-ID run. Incomplete pre-lock-order-refinement runs and initial broader migration diagnostics are not acceptance. The two independent compatibility cases added after initial source enumeration are explicitly included in final default/native composites, without duplicate IDs; canonical guarded-purchase sources stayed byte-identical.

## Operational boundaries that remain deliberate

- The restriction is for known authenticated local recovery sources and the canonical routed paid-bet writer. No verified intent does not prove the absence of unknown provider events. An inbox-only event does not manufacture debt.
- This is not a global wallet/withdrawal/conversion/spend fence or clearance for all provider liabilities, currencies, products and financial primitives. Already-paid obligations are not silently blocked by a generic debit hook.
- Independent internal/unrouted local L6 ticket-inventory purchase bookkeeping and generic reservation/financial primitives are not certified here as alternative publicly approved paid-bet roots. They must not be wired into live sales as a bypass of canonical ownership/current-admission policy. Official ticket inventory, physical authenticity and external licensing are separate from local bookkeeping.
- The 100-intent per-player/currency complete-history cap includes completed intents. Saturation intentionally refuses; no TTL, staff acknowledgement, feature toggle or record purge is an authorized bypass. A sealed archival/summary extension requires its own design and proof.
- Ordinary deposit owner/currency discovery integrity, database schema and encryption/signing key custody are trusted. Database/key compromise or arbitrary external writers bypassing the shared mutex are not universal risk guarantees.
- Broad inherited roles do not grant Part59's direct financial recovery authority. Wager admission itself does not acquire a staff capability or initiate collection.

## Deployment work, not fabricated execution

The user plans to provide merchant/provider details later. This release contains actual locally tested wallet/money-write application behavior; it does not claim a live provider refund/transfer, live merchant approval, remote CI execution, populated production migration/restore, production concurrency load, OS/power-loss certification or worldwide gambling/legal approval.

Before production enablement: provision reviewed credentials, webhook endpoint secrets and public HTTPS; verify exact merchant/account and currency routes; apply licensed jurisdiction/age/KYC/AML and player-protection policy; review direct financial permissions; test the provider's authenticated fixtures and go-live evidence; and perform deployment-specific populated backup/restore and migration rehearsal. Do not run historical down migrations on populated live lease/KYC state merely because an empty-store compatibility test passed.

CI now runs the new source-focused/native profiles with explicit lock-witness assertions. Local workflow validation does not assert that a remote CI runner has executed it. Source/acceptance/full bodies and cumulative checksums are delivered; locked dependencies, compiled output and disposable test stores are regenerable, not production backup artifacts.

## Next implementation priorities outside this accepted scope

1. A source-owned non-wager risk-review policy for future withdrawal/conversion requests, without blocking already-paid external obligations or creating an automatic debt/collection primitive.
2. Explicit current-admission and immutable ownership design for any independent ticket-sale root before exposing it to live public purchase routes.
3. A cryptographically sealed archival/capacity strategy for very long-lived refund histories; never silently omit or delete completed proof.
4. Merchant- and jurisdiction-specific live acceptance when the user supplies the necessary account details.

These are future scopes, not missing code bodies in Part60. No country-wide or worldwide100% certification is asserted.
