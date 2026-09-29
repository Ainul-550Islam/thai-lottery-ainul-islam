# WORKSPACE RESTORATION STATUS — 2026-09-28

## কী হয়েছিল / What happened

Sandboxes প্রতি টার্নের শেষে **snapshot**-এ persist হয়, এবং snapshot-এর একটা
**~10,000-file ক্যাপ** আছে। গত টার্নের শেষে workspace-এ ছিল:

| অংশ | ফাইল সংখ্যা |
|---|---|
| `vendor/` (composer dependencies) | 9,363 |
| প্রজেক্ট ফাইল (app, config, tests, views...) | ~2,100 |
| `.git/` (Prompt 1 commit `edbaccc` সহ) | ~2,000+ |
| **মোট** | **~13,500+** |

ক্যাপ ছাড়িয়ে যাওয়ায় শেষ snapshot থেকে **কয়েকশো ফাইল বাদ পড়ে যায়** — ফলে এই
টার্নের sandbox-এ ওই ফাইলগুলো নেই। এটা কোনো কোড লেখার ভুল নয়; বরং একটা
infrastructure সীমাবদ্ধতা। গত টার্ন পর্যন্ত সব ফাইল বিদ্যমান ছিল এবং
verify হয়েছিল (26+21 টেস্ট পাস, smoke run সফল)।

## প্রতিরোধ / Prevention (এখন থেকে সক্রিয়)

- `vendor/` (46MB, 9,363 ফাইল) → **একটাই ফাইল**: `vendor.tar.gz` (3.5MB)
- Workspace এখন **~690 ফাইল / 19MB** — ক্যাপের অনেক নিচে, তাই আর কোনো ফাইল
  বাদ পড়বে না।
- **নতুন টার্নে টেস্ট চালাতে হলে আগে extract করতে হবে:**
  `cd /home/user/thai-lottery && tar -xzf vendor.tar.gz`
- PHP প্রতি নতুন sandbox-এ reinstall লাগে:
  `sudo apt-get install -y php-cli php-mbstring php-xml php-sqlite3 php-bcmath php-curl php-zip`

---

## আমার Prompt-2 লেয়ার — সম্পূর্ণ পুনরুদ্ধার করা হয়েছে ✅

আমার লেখা ৩০-ফাইল প্ল্যানের **প্রতিটি ফাইল** পুরো content সহ আবার লেখা হয়েছে
(কোনো stub/`... existing code` নেই; সবগুলো `php -l` পাস):

**Config (2):** `config/account_grades.php` (rewrite, টিকে ছিল), `config/lotto_discount_matrix.php` (টিকে ছিল; label_key গুলো enum namespace-এ sync করা)

**Enums (3):** `app/Enums/AccountGradeLevel.php`, `DiscountGame.php`, `DiscountLottery.php` — পুনর্নির্মিত (case list, `sl()`/`isBase()`/`publicGames()`/`lottery()`/`hasDrawRegularModes()`/`labelKey()` verified contract অনুযায়ী)

**DTOs (5):** `GradeTier`, `GradeEvaluationResult`, `GradeDiscountEntitlement`, `DiscountRule`, `DiscountMatrix` — টিকে ছিল ✅ (ক্যাপে বাদ পড়েনি)

**Rules (2):** `StrictMoneyAmount`, `ValidDiscountPercentage` — টিকে ছিল ✅

**Services (7):** `GradeTierCatalog`, `AccountGradeEvaluator`, `GradeDiscountEntitlementService`, `GradeDiscountApplicationPolicy`, `CanonicalDiscountMatrixService`, `LottoDiscountCalculator`, `DiscountParityProjectionService` — পুনর্নির্মিত (গত টার্নের verified fragment + 47টি পাস করা টেস্টের exact contract ধরে রেখে)

**Model (2):** `GradeDiscountSnapshot` — পুনর্নির্মিত (sqlite test DB-র schema থেকে exact column সহ); `AccountGradeSnapshot` — পুনর্নির্মিত (verified fillable + নতুন 3 কলাম)

**Policy (1):** `AccountGradePolicy` — পুনর্নির্মিত (owner-or-admin / administer / default-deny)

**Command (1):** `grades:rebuild-snapshots` — পুনর্নির্মিত (fingerprint idempotency; `--force` বাদ — UNIQUE constraint ইতিমধ্যে সেটা enforce করে; রিপোর্টে নথিভুক্ত)

**Migrations (2):** `2026_09_27_220001_extend_account_grade_snapshots_table`, `2026_09_27_220002_create_grade_discount_snapshots` — sqlite schema থেকে exact পুনর্নির্মিত

**Views (3):** `components/account-grade/grade-tier`, `components/account-grade/discount-games`, `components/discount/game-rule` — টিকে ছিল ✅

**API Controller (1):** `Api/V1/AccountGradeApiController` — verbatim পুনরুদ্ধার

**Tests (2 + 1):** `AccountGradeProgrammeTest` (26 টেস্ট) verbatim পুনরুদ্ধার; `DiscountMatrixTest` টিকে ছিল + ২টি pending fix সম্পন্ন (variant count 11, live-quote signature); **৩য় টেস্ট ফাইল (`GradesRebuildSnapshotsTest`) এখনও লিখতে বাকি** — base ফাইল ফিরে এলেই লিখে পুরো স্যুট চালাব।

**Wiring (সব টিকে আছে ✅):** `routes/api.php` (/v1/account group), `AuthServiceProvider` (policy registration), `account-info/grades.blade.php`, `discounts/index.blade.php`, `account/grade.blade.php`, `.env`+`.env.example` (RULE_VERSION=2), view components।
**Wiring (পুনরায় প্রয়োগ):** `public-pages.js` (gradeGamesPanels), `PublicAccountInfoService` (catalogue-driven gradeLadder), `AccountGradeController` (সম্পূর্ণ জানা ছিল), base `Controller.php` (Laravel boilerplate)

---

## ❌ হারানো PRE-EXISTING ফাইল — আপনার re-upload দরকার

এগুলো আমার লেখা নয় — পুরোনো প্রজেক্টের ফাইল, যেগুলোর পুরো content আমার কাছে
কখনোই ছিল না (শুধু fragment দেখেছি)। **"existing logic preserved"** শর্ত
রাখতে গেলে এগুলো আপনার লোকাল কপি থেকে ফেরত দিতে হবে:

| পথ | আনুমানিক সংখ্যা | কী আছে |
|---|---|---|
| `app/Enums/` | ~93 ফাইল | Currency, UserStatus, TransactionType, TransactionStatus, WalletStatus, KycStatus, ... (সব enum) |
| `app/Models/*.php` | ~73 ফাইল | User, Wallet, FinancialTransaction, Bet, Ticket, Payment, ... (top-level সব মডেল; `Models/Support/` টিকে আছে) |
| `app/Http/Controllers/` (root + `Api/V1/`) | ~28 ফাইল | AuthController(api), GLOController, PublicAccountInfoController, LottoDiscountController, HomeController, FeesController, ... (`Api/V1/Admin/` ১টা + `Web/` ২টা টিকে আছে) |
| `database/migrations/` | ~115 ফাইল | পুরো migration ইতিহাস (114 টেবিলের schema sqlite test DB-তে সংরক্ষিত আছে, কিন্তু ফাইল নেই) |
| `lang/en/` + `lang/th/` | 12+12 ফাইল | account_info, account_services, prize_discount, public_pages, ... (আমার নতুন key-গুলোসহ পুরনো সব অনুবাদ) |
| `resources/css/` | 11 ফাইল | app.css, public-pages.css, prize-discount.css, account-services.css, ... |
| `app/Console/Commands/` (top-level + Finance/Queue subdirs) | অজানা | যেকোনো top-level command |
| `.git/` | — | commit history (`edbaccc` সহ) — ফিরে পেলে ভালো, না পেলে নতুন init করা যাবে |

**সবচেয়ে সহজ পথ:** আপনার লোকাল প্রজেক্ট থেকে `vendor/`, `node_modules/`,
`.git/` বাদ দিয়ে পুরো zip upload করুন — আমি extract করে তার উপরে আমার
পুনরুদ্ধার করা Prompt-2 লেয়ারটা বসিয়ে দেব (আমার ফাইলগুলো workspace-এ
already আছে, overlay করা নিরাপদ)।

## রিস্ক নোট

`app/Services/*`, `app/DTOs/*`, `app/Policies/*`, `app/Rules/*`, `app/Events/`,
`app/Filament/`, `app/Http/{Middleware,Requests,Resources}/`, `tests/` (79),
`config/` (36), `routes/` (4), `resources/views/` (107), `resources/js/`,
`database/seeders/`, `database/thai_lottery_test` (2.9MB sqlite, 114 টেবিলের
schema) — **সব টিকে আছে**।


---

## ✅ FINAL UPDATE (একই দিন, সন্ধ্যা) — RESTORATION COMPLETE

**GitHub base restoration + Prompt 2 দুটোই সম্পূর্ণ এবং validated।**

### যা হলো

1. **GitHub base merge** — `github.com/Ainul-550Islam/thai-lottery-ainul-islam` (main, 09-27 push, mid-Prompt-1 state) থেকে:
   - 867 gap ফাইল copy, 606 identical, 25 differ → **local kept** (local সবগুলোতে নতুন ছিল, verified)
   - আমার lang key blocks (prize_discount 178/178, account_info 42/42, account_services 8) + 3 CSS block আবার re-apply করা হয়েছে
2. **Compatibility fixes** (repo-র পুরনো ফাইল vs local-newest views/routes/tests):
   - `PublicServicePagesController` পুনর্নির্মাণ — groups-shaped fees page + `feesPreview()` (POST /api/v1/fees/preview, server-authoritative)
   - `LottoDiscountController` re-wire — matrix projection আবার যোগ
   - `FinancialReconciliationService` — fee-aware journal basis (deposits=NET, withdrawals=GROSS + fee column)
   - `AccountServicesPagesTest` #33/35/36/37 — benchmark ladder edits আবার re-apply
   - `GradeTier::discountPercent()` — `'2.00'` (suffix ছাড়া); `%` শুধু display helper-এ
   - `LottoDiscountCalculator` — `use App\DTOs\Lottery\DiscountRule;` missing import + roundHalfUp unit bug (`'0.01'` not `'0.001'`)
3. **Vendor** — পুরনো `vendor.tar.gz` টা damaged ছিল (vendor/composer-সহ ~5k ফাইল কম, snapshot truncation-এর শিকার)। Fresh `composer install` (PHP 8.4 + php-intl) → সম্পূর্ণ 14,505 ফাইল → নতুন `vendor.tar.gz` (34MB)
4. **Vite manifest** — `npm install && npm run build` (public/build টেস্টের জন্য দরকার)

### Validation

- Focused: AccountGradeProgrammeTest **26/26**, DiscountMatrixTest **30/30**, GradesRebuildSnapshotsTest **10/10**
- **FULL SUITE: 1558 passed / 7 skipped / 0 FAILED / 103,963 assertions** (baseline ছিল 1550/103,682/0F/7S)
- Git commit: `eeb7162` (restored + Prompt-2 state)
- Workspace: ~1,825 ফাইল (vendor deleted, শুধু `vendor.tar.gz` রাখা) — cap-এর নিচে

### পরবর্তী টার্নে টেস্ট চালাতে হলে

```bash
sudo apt-get install -y php8.4-cli php8.4-mbstring php8.4-xml php8.4-sqlite3 php8.4-bcmath php8.4-curl php8.4-zip php8.4-intl
cd /home/user/thai-lottery && tar -xzf vendor.tar.gz
php artisan test
```
