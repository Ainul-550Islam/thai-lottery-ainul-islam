# External Audit Response 3 (v2 re-audit) — 2026-09-29

Response to `thailotto_full_gap_audit_2026-09-29-v2.md` (audited against HEAD
`03fb266`). The v2 re-audit was materially more precise than its predecessor;
three of its findings were code-level defects for this repository, and all
three are fixed and test-locked in this pass.

**Test proof for this pass: `1712 passed / 7 skipped / 0 failed — 105,246
assertions`** (previous: 1698 / 105,152; +14 tests, +94 assertions,
0 regressions).

---

## 1. C2 — Fees translation keys: fixed, and the defect was larger than audited

The audit found **16 literal keys** referenced by the Fees UI and missing
from both language files. All 16 are now defined in `lang/en/account_services.php`
and `lang/th/account_services.php` (preview section: title/lead/no-JS note/
loading/error/network-error/fee/state/category/provider/provider-none/amount/
submit labels, plus `fees_col_provider`, `fees_provider_none` and
`fees_rule_version_label` for the table).

**Follow-up discovery while fixing C2:** the Fees table also renders category
labels through a **dynamic** key — `trans('account_services.fees_category_'.$key)`
in `FeesPageService` — and 14 configured fee categories had no translation, so
the rendered table showed raw strings like `account_services.fees_category_withdrawal_bank`
for every provider-specific withdrawal, cash-in and commission row. The audit's
literal-key scan could not see these (dynamic family), which is exactly why
its own recommended remedy was not enough. All 14 are now defined in both
locales (withdrawal/cash-in × Bank/Skrill/Neteller/PayPal/Perfect Money,
agent commission Win/Cash, personal→agent commission, internal provider margin).
Verified by rendering the page in EN and TH and asserting zero
`account_services.*` key strings in the HTML.

### The regression lock (the audit's requested test)

New `tests/Feature/TranslationKeyIntegrityTest.php` — 14 tests:

1. **Literal scanner**: every complete literal `__()/trans()/Lang::get()`
   reference across views, controllers, services, rules, requests,
   notifications and console commands (556+ references checked at runtime)
   must resolve in **both** `lang/en` and `lang/th`, and any referenced
   namespace must exist as a lang file (framework namespaces exempt).
   Dynamic concatenations are excluded by construction (the matcher rejects
   a literal immediately followed by `.`).
2. **Config-driven dynamic-family guard**: every key under
   `config('fees.categories')` must have a `fees_category_{key}` label in
   both locales — the family the literal scanner cannot see.
3. **EN/TH symmetry**: per-namespace key-set parity asserted exactly.
4. **Fees-page render**: EN and TH pages contain the restored copy and zero
   raw key strings.

## 2. C4 — README baseline stale: fixed

`README.md` now states the verified baseline (**1712 / 0 failures**, with the
run date and assertion count) and records the historical progression
(1550 → 1558 → 1640/1643 → 1677 → 1698 → 1712) so the number is never again
mistaken for a fixed delivery figure.

## 3. C5 — workspace `.env`: verified, no real secrets present

Direct inspection of the workspace `.env` (values not reproduced anywhere):

- **No `APP_KEY` is set in the file at all** (tests use the key from
  `phpunit.xml`).
- Every credential-bearing key — `DB_PASSWORD`, `MAIL_PASSWORD`,
  `REDIS_PASSWORD`, gateway secrets — is **empty**.
- The only non-empty values are benign scaffolding: connection settings,
  `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME`, payment callback URLs and
  `*_ENABLED`/`*_SANDBOX` flags.

So the file is key scaffolding, not a secret store. Actions taken anyway:

- The deployment artifact remains the **clean-build ZIP**
  (`thai-lottery-clean-build-2026-09-29.zip`), which is verified to contain
  **zero `.env` matches** and a complete `public/build` manifest — and this
  pass refreshed it with all fixes herein.
- Standing advice unchanged: any value that was ever real and left the
  trusted boundary should be rotated; nothing in this repository's `.env`
  currently qualifies.

## 4. Dispositions for the remaining findings (not code defects)

| Finding | Disposition |
|---|---|
| C1 — live site is still the legacy `.php` application | ⚠️ **Operational cutover step** — the codebase side is complete: clean routes + the tested 301 bridge (16 static + 6 member + 9 archive-form mappings). The domain switch itself is a deployment action, not a repository change. |
| C3 — no real historical payloads bundled | ✅ by design — `ResultArchiveSeeder` + `database/seeders/data/results/README.md` define the import path; the code refuses to fabricate draws. Populating the payload directories from your system of record is the pre-cutover operational step (import, then reconcile). |
| Terms/Fees/Discount business content (40 THB, USD values, old prize table) | ⛔ **business/legal sign-off required** — standing constraint: GLO L6/N3 immutable pricing; no legacy value is copied silently. Needs one authoritative source-of-truth table from you. |
| Mega vs Bingo naming | ⚠️ **decision point, already consistent with live** — the live site itself serves "Mega Lottery" **on `/bingo-lottery.php` URLs**, and this codebase does exactly the same (URL family `bingo-lottery*`, public label "Mega Lottery"). No change needed for parity; renaming the route family is possible later if you decide to. |
| Live archive-chain inconsistencies, live nav "Results" routing, live copy defects, app-download check | 🟡 live-side observations — all are resolved by the cutover itself; the new application's navigation and year routes are generated from a single inventory. |
| v2's static verification results (1,461 files parse, 68 routes, 139 route refs, no dangerous calls, Vite manifest complete) | ✅ confirmed and preserved — this pass adds no regressions to any of those invariants (full suite green). |

## 5. Files changed this pass

| File | Change |
|---|---|
| `lang/en/account_services.php` | +16 literal Fees keys, +14 dynamic fee-category labels |
| `lang/th/account_services.php` | same 30 keys, Thai |
| `tests/Feature/TranslationKeyIntegrityTest.php` | new — 14 tests / 33 assertions (scanner, dynamic-family guard, EN/TH symmetry, EN+TH render) |
| `README.md` | test baseline updated to the verified 1712 run with progression note |
| `thai-lottery-clean-build-2026-09-29.zip` (repo root, outside git) | refreshed clean build — no `.env`, `public/build` complete |

## 6. Proof

- `TranslationKeyIntegrityTest` — **14 passed, 33 assertions**
- Full suite — **1712 passed / 7 skipped / 0 failed / 105,246 assertions**
- Fees page (EN and TH) — zero `account_services.*` raw keys in rendered HTML
- Clean ZIP — 0 `.env` entries, `public/build/manifest.json` present
