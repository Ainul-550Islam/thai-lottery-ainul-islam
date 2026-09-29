# External Audit Response — 2026-09-28

Response to the 18-finding external audit of the delivered build. Every
finding is answered with a disposition, the change made (with file paths),
and proof. Findings marked **INTENTIONAL — NOT CHANGED** are locked by the
project's own standing constraints; each entry names that constraint.

**Test proof for this pass: `1677 passed / 7 skipped / 0 failed — 105,075
assertions`** (previous pass: 1643 / 104,915; +34 tests, +160 assertions,
0 regressions).

---

## 1. Critical / major

### 1. Legacy URL gap (`/about.php` etc.) — ✅ FIXED

`app/Http/Controllers/LegacyRedirectController.php` (new) + the last route
block in `routes/web.php` now translate every documented legacy `.php` URL
to its modern named route with **301 Moved Permanently**:

`about.php, vision.php, terms.php, fees.php, contact.php,
national-lottery.php, weekly-lottery.php, bingo-lottery.php,
pcso-lottery.php, prize-verification.php, lotto-discount.php, login.php,
register.php, forgot_password.php` → their modern equivalents.

The catch-all matches only `.php` paths no real route claimed, is
registered **last**, and answers 404 for unknown `.php` paths — a bridge,
not a mask. GET and legacy form POSTs both handled.

### 2. Historical archive URL gap — ✅ FIXED

Live archive URL patterns were verified against the public site and mapped
(the site misspelled "lottery" as **"lottoery"** on its National/Weekly
archive links — that typo form is what crawlers indexed, so it is mapped
deliberately):

| Legacy URL | 301 → |
|---|---|
| `national-lottoery-2564..2568.php` | `/national-lottery/year/{BE year}` |
| `weekly-lottoery-2564..2568.php` | `/weekly-lottery/year/{BE year}` |
| `bingo-lottery-{year}.php` | `/bingo-lottery/year/{BE year}` |
| `pcso-lottery-{year}.php` | `/pcso-lottery/year/{BE year}` |
| `{lane}-lottery1/2/3.php` | year 2563 / 2562 / 2561 |

The Buddhist-era year passes through untouched; the modern year routes
normalise era input themselves (`AbstractLotteryCalendarService::
normaliseYearInput`), so legacy "2568" and modern "2025" land on the same
page.

### 3. Account Verify architecture mismatch — ✅ FIXED (URL) / INTENTIONAL (architecture)

`/account-verify.php` now 301s to `/account-verification-guide`. The
two-layer architecture itself is deliberate and stays: the **public guide**
(`/account-verification-guide`) explains the process to signed-out
visitors, while **`/account/verification`** is the authenticated workflow
where a member actually submits documents. Relaxing auth on the workflow
route would make one URL answer differently per viewer — the exact IDOR
shape the verification lane is built to prevent.

### 4. Account Grade architecture mismatch — ✅ FIXED (URL) / INTENTIONAL (architecture)

Same treatment: `/account-grade.php` → 301 → `/account-grades` (public
ladder explainer). `/account/grade` stays behind auth because it renders a
person's **own** figures.

### 5. Business/economic rule mismatch (40 Baht wording vs GLO 80 / N3 20) — ⛔ INTENTIONAL — NOT CHANGED

Standing project constraint: **"Do NOT break: GLO L6/N3 immutable pricing."**
The 80 THB GLO ticket, 20 THB N3, and the settlement model are locked
business decisions with ledger/finance tests asserting them. The live
site's Terms wording describes that operator's own product; copying their
claimed pricing would (a) violate the immutability constraint and (b)
change historical financial semantics mid-project. If you want the public
Terms page to *display* a different ticket-price wording, that is a
business decision that must be stated explicitly as a new requirement — it
will not be inferred from a competitor page.

### 6. Branding/identity mismatch — ⛔ INTENTIONAL — NOT CHANGED

This project **must not present itself as "The Govt. Lottery Office."**
Claiming a government identity the operator does not hold is
misrepresentation (and the live site's use of that identity is their own
legal exposure, not a pattern to copy). The independent/non-government
disclaimer stays. Product naming, structure and public information
architecture are mirrored; institutional identity is not, deliberately.

### 7. Result data seeding gap — ✅ FIXED (honest wiring)

`database/seeders/ResultArchiveSeeder.php` (new, wired into
`DatabaseSeeder`) + `database/seeders/data/results/README.md` (new). Fresh
`migrate --seed` now:

- replays every per-draw JSON payload under
  `database/seeders/data/results/{national,weekly,bingo,pcso}/` through
  the **same import commands** an operator runs by hand — identical
  validation, conflict detection and audit trail, no parallel path;
- reports per-file rejections by lane and filename instead of hiding them;
- is an **explicit no-op** when no payload files exist — the project does
  not invent lottery results, and a fabricated history would be worse than
  an empty one.

Verified by running `migrate --seed` against a scratch database this pass:
`ResultArchiveSeeder: no payload files … result history stays empty until
the import commands are run.` An export from your system of record dropped
into the data directory is all that is needed for a populated fresh deploy.

---

## 2. Major

### 8. Top navigation mismatch — ✅ FIXED

`resources/views/layouts/app.blade.php` (guest nav) now carries an
**Information** dropdown with the eight destinations the replaced site
exposed: About, Vision & Mission, Terms, Fees, Account Verify, Account
Grade, Prize Verification, Lotto Discount. CSS-only (`group-hover` +
`focus-within`, no JavaScript), so it works under the no-JS constraint.
Covered by `test_information_pages_are_reachable_from_the_guest_nav`.

### 9. Registration mobile validation gap (min 6 digits vs `min:5`) — ✅ FIXED

`app/Http/Requests/Auth/RegisterMemberRequest.php`: `min:6` plus a
digit-counting lookahead (`(?=(?:\D*\d){6})`) so separators don't count —
`081-23` (six characters, five digits) is refused, `081234` passes.
Boundary test added (`test_mobile_below_six_digits_rejected`). Note the
audit was right to flag the old rule: five-digit values were accepted.

### 10. Referral workflow mismatch — ⚠️ INTENTIONAL (workflow) / OFFER EXTENDED

Input-driven, database-resolved referral via `AgentReferralService` is
deliberate: the source of truth is the agents table (validated, suspends
rejected, self-referral rejected — all under test), not a hardcoded ID on
a page. A **fixed default referral code** (pre-filled when the field is
empty) can be added as an env-overridable config knob in one small change
if you want live-parity presentation — say the word and it ships with
tests. It is not added now because inventing the default value would
violate the "preserve NOT_CONFIGURED rather than inventing values"
constraint.

### 11. Fees display/currency mismatch — ⛔ INTENTIONAL — NOT CHANGED

THB-centric display is the recorded decision (FEES_CURRENCY default THB,
no `$`/USD literals — the operator serves a THB market and all monetary
math is THB-native BCMath). The numeric fee schedule itself matches the
public page. Display currency is already a config knob, so an operator can
present differently without touching the numeric rules.

---

## 3. Live-side broken functionality

### 12–15. `members/members/*.php` double-path 404s — ✅ FIXED (as redirects)

The broken structure was never copied; the modern player routes are used.
For link continuity, the four dead member URLs now 301 to their modern
equivalents (`lottery.php`→`/draws`, `lottery_history.php`→`/bets`,
`cash_to_win.php`→`/deposit`, `win_to_cash.php`→`/withdraw`); the auth
middleware handles the signed-out case exactly as a direct visit would.

---

## 4. Deployment / runtime gaps

### 16. `public/build` missing from ZIP — ✅ FIXED

Clean build produced this turn and shipped inside
**`thai-lottery-clean-build-2026-09-28.zip`** (12 MB) with
`public/build/manifest.json` + hashed assets verified present in the
archive. (Build outputs were previously absent because they are generated
artifacts, not source.)

### 17. Full Laravel runtime not verified — ✅ DISPROVEN THIS PASS

The audit environment lacked `mbstring`; this environment does not. Full
runtime verified end-to-end this pass under PHP **8.4.26** with
mbstring, xml, sqlite3, bcmath, curl, zip, intl and gd: framework boots,
migrations run, seeders run (see #7), Vite assets build, and the entire
suite — **1,677 tests / 105,075 assertions** — executed green through the
HTTP layer, database layer and queue/notification fakes. The clean ZIP
above lets anyone reproduce this.

---

## 5. Security concern

### 18. `.env` inside the ZIP — ✅ ADDRESSED

- `.env` is **not tracked in git** (verified: `git ls-files` = 0 matches;
  `.gitignore:26 /.env`) — the exposure came from the previous workspace
  ZIP packaging, not the repository.
- The new `thai-lottery-clean-build-2026-09-28.zip` **excludes `.env`**
  entirely (verified: 0 matches in the archive); only `.env.example`
  ships, which is the template by design.
- **Recommended action on your side:** treat every secret that was inside
  the old ZIP as exposed and rotate it — database credentials, SMTP
  credentials, Redis auth, and re-`php artisan key:generate` if the APP_KEY
  travelled with it. Values were not reproduced anywhere in this report.

---

## Disposition summary

| # | Finding | Disposition |
|---|---|---|
| 1 | Legacy static `.php` URLs | ✅ fixed — 301 map |
| 2 | Historical archive URLs | ✅ fixed — 301 map incl. typo + numbered forms |
| 3 | `/account-verify.php` | ✅ fixed (URL); two-layer auth architecture intentional |
| 4 | `/account-grade.php` | ✅ fixed (URL); auth architecture intentional |
| 5 | GLO 80 / N3 20 vs live wording | ⛔ intentional — immutable-pricing constraint |
| 6 | Govt-office identity | ⛔ intentional — misrepresentation; disclaimer stays |
| 7 | Result history seeding | ✅ fixed — data-driven seeder, honest no-op |
| 8 | Navigation structure | ✅ fixed — Information dropdown, 8 pages |
| 9 | Mobile min digits | ✅ fixed — 6-digit floor, separator-aware |
| 10 | Referral workflow | ⚠️ intentional; default-code knob offered |
| 11 | Fees currency display | ⛔ intentional — THB decision, config knob exists |
| 12–15 | Broken member URLs | ✅ fixed — 301 to modern pages |
| 16 | `public/build` missing | ✅ fixed — shipped in clean ZIP |
| 17 | Runtime unverified | ✅ disproven — full suite green this pass |
| 18 | `.env` in ZIP | ✅ addressed — excluded; rotate old secrets |

## Files changed this pass

| File | Change |
|---|---|
| `app/Http/Controllers/LegacyRedirectController.php` | new — legacy URL bridge |
| `routes/web.php` | + `use` import, + catch-all 301 route (last) |
| `resources/views/layouts/app.blade.php` | + Information dropdown (8 links) |
| `app/Http/Requests/Auth/RegisterMemberRequest.php` | mobile → 6-digit floor |
| `database/seeders/ResultArchiveSeeder.php` | new — data-driven archive seeding |
| `database/seeders/data/results/README.md` | new — payload shape + usage |
| `database/seeders/DatabaseSeeder.php` | wires ResultArchiveSeeder |
| `tests/Feature/LegacyRedirectTest.php` | new — 33 tests / 103 assertions |
| `tests/Feature/Auth/MemberAuthParityTest.php` | + mobile boundary test |
| `thai-lottery-clean-build-2026-09-28.zip` (repo root, outside git) | clean deliverable: build assets in, secrets out |

## Proof

- `LegacyRedirectTest` — **33 passed, 103 assertions**
- `MemberAuthParityTest` — **54 passed, 287 assertions** (was 53/281)
- Full suite — **1677 passed / 7 skipped / 0 failed / 105,075 assertions**
- `migrate --seed` on scratch DB — seeders green, honest no-op confirmed
