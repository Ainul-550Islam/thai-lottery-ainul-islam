# Part41 — owned local financial holds and aggregate protection

Date: 2026-10-16. Complete Files744–761; next File762. This tranche fixes a real remaining financial writer, not only its tests. Cumulative overlay: 544 paths; 526 prior bodies unchanged, 7 revised, 11 first delivered (including existing base files now supplied in full).

## Actual problem and correction

The old local financial-hold workflow inferred ownership from `wallet.locked_balance`, locked the business entity before its wallet, accepted raw-constructor input and loosely matched replay, could mark premature/unreviewed expiry, and treated a terminal label/reference as sufficient conversion replay. Generic releases could borrow a different hold's aggregate money. A failed nested placement could also leave partial work if its caller caught the exception.

The new additive `financial_hold_claims` table gives each newly placed hold a permanent, unique ownership record: wallet, user, canonical amount/currency, source reference, original input/terms fingerprints, lifecycle, closure and the exact consumed transaction. Wallet/source identity remains claimed after terminal completion. Changing an amount or reason under that source is a conflict, not a second reservation. Numerically equivalent decimal spellings and currency/source casing normalize before identity lookup. Raw public DTO construction is revalidated, unsupported currencies and excessive precision/range are rejected. Current enabled currencies remain THB/USD/BDT; multi-country architecture is not unlimited currency or legal support.

Placement and lifecycle changes own a savepoint even under an ambient transaction. The actual stored wallet is locked first, then current business/claim records. Caller-supplied stale status/wallet/amount does not select the money. The central wallet mechanical check now sums active withdrawal obligations PLUS owned local financial holds; independent maximum checks would incorrectly overlap their money. Reserve, generic release/consume, debit/credit and reversal mechanics cannot spend a claim or cover up an existing deficit with new money.

Only this workflow can close its proved owner and unlock it. Conversion closes/unlocks and posts one canonical zero-fee Adjustment debit, linked to this exact FinancialHold, in the same savepoint. Success and terminal replay require its actual completed header, durable claim link, exact amount/currency/provenance and exactly two live posted opposite ledger legs on the canonical player-liability/adjustment-equity accounts. The inherited counterpart wallet context is accepted ONLY when absent or this same wallet; another wallet is refused. Account/header/entry reads are locking/current reads, not an old repeatable-read snapshot. The Critical conversion audit level is preserved. Generic reversal of this domain-managed debit is blocked by its durable link even if its header label is removed. A separate authorized compensation policy is not invented.

Clock passage never closes a claim. Expiry is an explicit action on a reviewed, due local compliance hold; not an unknown bank/payout timeout release. A future or unreviewed hold cannot expire. Terminal operations do not add another unlock/debit. Routine migration down refuses to drop ANY claim history, including closed claims; administrative DROP/fresh/restore privileges remain outside application guarantees.

## Historical and authority boundaries

No historical owner is inferred from aggregate money, old labels, a timestamp, a source string or a human note. Recognized active/reviewed ownerless or inconsistent legacy holds quarantine that wallet's protected mutations for controlled review. The migration adds an empty table; it does NOT backfill ownership or change existing balances/holds. Existing contradictory history cannot be made healthy by creating another reservation or toggling its label. Fingerprints are local drift/identity checks, not protection against a privileged database attacker or authenticated bank evidence.

This is an INTERNAL local mechanics service, with no new public route or role/permission grant. Operator IDs and notes are nominal evidence, not authentication/authorization or maker/checker segregation. Real ingress callers still must enforce operator identity, granular permissions, country/compliance policy and authorized review/conversion. No provider, incoming-fee/refund/dispute policy, merchant credentials or live transfer was selected or authorized. Do not use these local holds to reset/resend/release an unknown remote payout. Avoid placing secrets or raw identity documents in short audit notes; private document storage/retention remains separately governed.

## Executed acceptance

| Executed check | Result |
|---|---|
| Complete default PHP, four independent owned SQLite stores | 3217 test cases; 134232 assertions; 0 errors/failures; 72 explicit skips |
| Discovered PHP test IDs | Every discovered ID executed exactly once in the full run |
| Owned MariaDB hold/withdrawal matrix | 85 tests / 441 assertions; 0 failures/errors/skips |
| New owner matrix (included above) | 43 cases across validation, THB/USD/BDT, closure, expiry, rollback, corruption and replay |
| Native overlapping-session current-read proof (included above) | 4 tests / 39 assertions, passed |
| Lock-removal mutation control, separate expected failure | 4 failures, 0 errors; correct source restored; not counted as a positive pass |
| Static PHP contracts and patched YAML boundary | 21 tests / 1507 assertions, passed |
| Python | 86 tests, passed |
| PHP syntax | 1996 files; 0 failures |
| Pint | All 8 changed/new PHP files pass |
| Pinned native verifier | 27 Rust tests and release build pass |
| Locked npm / Node gate | 149 locked and audited packages; 0 known findings; 54 policy tests pass |
| Fresh npm ci / Vite release build | Passed under Node24.22.0/npm11.21.1; unchanged frontend source rebuilt |
| Locked Composer audit / abandoned-package check | 0 findings / 0 abandoned |
| Source preflight and generated inventory | Passed; no missing imported App classes or stale generated documents |
| Final source freeze | All 9 edited implementation/test/generator bodies unchanged throughout final acceptance |


The default 72 skips comprise the prior68 profile-dependent skips and4 new SQL-session tests that cannot be proved on SQLite. Those4 ran and passed, without skips, in the separate owned MariaDB matrix. Matrices overlap; these are not additive unique-test totals. The lock-removal mutant was deliberately red: all4 assertions detected the missing current-read protection, without relying on an engine exception. The exact correct helper bytes were restored before final acceptance.

The native sessions deliberately disable MariaDB snapshot-abort protection only locally in the test sessions; production/global settings are not changed. The reader snapshot predates another session's committed owner, and the journal test preserves an old good snapshot after a peer corrupts the actual journal. This is current-read proof, not load/throughput certification or a claim that every race is covered.

The source snapshot omitted the previous cumulative ZIP, installed dependencies and build outputs. All533 prior bodies were recovered from the verified499 archive plus verified Files691–743 and checked against the delivered533-path manifest. Missing dependencies and real Vite/native artifacts were restored. Earlier partial/error candidates are excluded; no `withoutVite`, skipped image/driver fixtures or weakened application assertions were used to hide missing environment artifacts. All9 final edited source bodies remained hash-identical throughout final acceptance. Part40 browser measurements remain historical, not recertified here.

## Safe reproduction and rollout

Use the identified base repository plus the cumulative overlay, PHP8.4 and the declared extensions, Node24.22.0/npm11.21.1. Install the locked dependencies and actually build release assets before full route tests:

```sh
composer install --prefer-dist --no-interaction
npm ci
npm run test:dependencies
npm run security:dependencies
npm run build
cargo test --locked --manifest-path security/weekly-result-integrity/Cargo.toml
cargo build --release --locked --manifest-path security/weekly-result-integrity/Cargo.toml
php vendor/bin/phpunit tests/Feature/Payment/FinancialHoldOwnershipTest.php
```

Use only a separately owned migrated disposable `thai_lottery_test` store for truncating tests. The native command additionally selects `DB_CONNECTION=mysql` with owned test credentials/socket and explicitly runs FinancialHoldCurrentReadTest and WithdrawalHoldFloorTest; never point these tests at customer data or run independent truncating suites against the same store concurrently. Source-only preflight is not financial runtime approval.

For an authorized deployment: first audit/quiesce affected writers and old workers, preserve backups and private evidence, apply the additive migration, then deploy/restart all writers coherently. Mixed old and new writers are NOT protected merely by adding the table. Inventory active legacy holds read-only before rollout. For example, an owned database operator may inspect (not repair):

```sql
SELECT h.id, h.wallet_id, h.source_reference, h.status
FROM financial_holds h
LEFT JOIN financial_hold_claims c ON c.financial_hold_id = h.id
WHERE h.status IN ('active', 'reviewed') AND c.id IS NULL;
```

Do not manufacture hashes/claims, release locked funds, edit statuses or copy a new owner's proof onto old records. Historical remediation needs independent original evidence and an explicitly authorized plan. A routine downgrade with claim history now refuses; destructive fresh/drop/restore is not a safe financial rollback. Stage indexes, aggregate scan costs, backup/key restoration and coherent workers at representative deployment scale before a rollout.

## Remaining work — authoritative continuation

1. Actual provider/merchant/jurisdiction selection, authenticated original payment/refund adapters and authorized acceptance. External refund/dispute economics and incoming fees remain fail-closed; human archives/labels do not authenticate a bank or resolve an unknown payout.
2. Other unmanaged generic reservations, prize/cancellation/reversal/reporting/hold writers and liability aggregation; granular ingress permission/maker-checker enforcement; agent aggregate concurrency and historical bookkeeping. This tranche protects NEW local FinancialHold ownership and its shared wallet minimum, not every reservation system or arbitrary raw SQL writer.
3. Independently controlled historical reconciliation/migration. No missing ownership, references, economics, fingerprints or signatures are backfilled by guessing.
4. Authorized remote CI/staging/deployment, all-writer/deployment-volume contention and scan/index acceptance, multi-host private storage, backup/encryption-key restoration and lawful retention. Old native/race matrices remain historical unless explicitly executed in this tranche.
5. Country/operator legal, tax, KYC/AML, liquidity, monitoring, incident response, continuing advisory checks and broader browser/accessibility/security acceptance. Clean locked audits and local arithmetic proof are not worldwide operating permission or whole-platform100-percent completion.

All18 file bodies are delivered in full. The544-path ZIP is a source overlay on base `a73ad5b8dce6c1f9f777f25ed38b20355d69b3be`, not a standalone repository, dependency/database export or live-money approval.
