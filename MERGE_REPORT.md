# মার্জ রিপোর্ট — ২১ নভেম্বর ২০২৬

মেইন কোড: `app.zip` (শুধু `app/` ফোল্ডার, ২০২৯ ফাইল)
নতুন সেট: **১** `thai-lottery-new-files-only` · **২** `payment-prompt-final-changes-2026-11-17` · **৩** `thai-lottery-p0-changed-files`

## ফলাফল এক নজরে

| | সংখ্যা |
|---|---|
| `app/`-এ বদলানো ফাইল | ২০৫ |
| `app/`-এ নতুন ফাইল | ৪৭ |
| `app/`-এর বাইরে (config ৫, migration ৪, tests ৩৯, resources ৯, lang ৩, docs ১০, seeders ২, env ৩, phpstan-baseline ১) | ৭৬ |
| ইচ্ছে করে বসানো হয়নি / আলাদা হ্যান্ডেল | `ci.yml`, `composer.json`, `composer.lock`, `routes/web.php`, `bootstrap/providers.php` (নিচে কারণ) |

**ক্রম:** main (১৫ নভে) → ৩ (১৭ নভে) → ২ (১৮ নভে সকাল) → ১ (১৮ নভে বিকাল)।
সেট ১-এর patch উল্টো প্রয়োগ করে আসল baseline বের করেছি, তারপর baseline / আপনার app.zip / নতুন সেট — এই তিনটার 3-way merge করেছি। তাই আপনার নিজের এডিট বাদ পড়েনি।

---

## ১. আপনার নিজের কোড, যেগুলো নতুন সেট চুপচাপ মুছে ফেলতো — বাঁচানো হয়েছে

| ফাইল | আপনার যে কোড | নতুন সেট বসালে কী হতো | এখন |
|---|---|---|---|
| `DepositResource/DepositDecisionActions.php` | bKash/Nagad `settleManualWallet` ("Verify TrxID & credit") | ১ ও ২ দুটোতেই নেই, বাটন উধাও | রাখা হয়েছে; gate = `DEPOSITS_APPROVE` |
| `Providers/AppServiceProvider.php` | Google sign-in limiter `registerSocialLimiter()` | ৩ মুছে দিতো | রাখা + ৩-এর money bindings যোগ |
| `Models/User.php` | ৫টা relation (cashbackAccruals, rewardClaims, freeBetVouchers, bankAccounts, loginEvents) | মুছে যেত | রাখা + ১-এর পরিবর্তন |
| `Models/Withdrawal.php` | `member_bank_account_id` + `memberBankAccount()` | মুছে যেত | রাখা |
| `Enums/NotificationEventType.php` | `NewDeviceLogin` | মুছে যেত | রাখা + ১-এর Lifecycle cases |
| `Models/Payout.php` | `paid_at` datetime cast | মুছে যেত | রাখা |
| `routes/web.php` (সেট ২) | আপনার `submitDepositProof`, `throttle:social` ইত্যাদি route | ২-এর পুরো ফাইল বসালে এগুলো যেত | পুরো ফাইল বসানো হয়নি; স্ক্রিপ্ট শুধু transfer route-এর ২ লাইন throttle যোগ করে |
| `bootstrap/providers.php` (সেট ১) | `RewardsServiceProvider` | ১-এর ফাইলে নেই | শুধু `SystemSettingsServiceProvider` insert |
| `composer.json` / `composer.lock` (সেট ১) | `google/auth`, `firebase/php-jwt` (Google sign-in) | lock বসালে Class not found | বসানো হয়নি; শুধু `composer require --dev larastan/larastan:^3.0` |
| `config/payment.php` (সেট ৩) | bKash/Nagad `mode`, `merchant_name`, `merchant_number` | ৩-এর ফাইলে নেই → manual রেইল বন্ধ (mode ডিফল্ট `api`) | কী ফেরত যোগ, env-এ `BKASH_MODE` ইত্যাদি |

## ২. নতুন সেটগুলোর নিজেদের মধ্যে দ্বন্দ্ব — কীভাবে মেটানো

- **`WalletControlActions`**: ২ `adjustBalance` সরিয়েছে (এক-অপারেটরের ফ্রি-টেক্সট ব্যালেন্স এডিট), ১ রেখে দিয়েছে। **২-এর হার্ডেনিং জিতেছে**; ১-এর granular permission (`wallets.adjust`) lock/unlock-এ বসেছে।
- **`DepositDecisionActions`**: ২ জেনেরিক `complete` সরিয়েছে, ১ রেখেছে। **`complete` সরানোই থাকলো**, ১-এর granular permission (`DEPOSITS_APPROVE/REJECT/REVIEW`) বসেছে।
- **`FraudEventRecorder`**: ২ = `firstOrCreate` (alert immutable), ১ = `player_user_id` লিংক। **দুটোই আছে।**
- **`DoubleEntryLedgerService`**: আপনার ৬ নভেম্বরের tip-walk বদলে ২-এর `ledger_chain_state` (migration সহ)। migration বর্তমান chain-tip থেকে শুরু করে; একাধিক tip পেলে `migrate` ইচ্ছে করেই থেমে যায় (chain fork হয়ে থাকলে আগে ঠিক করুন)।
- **`BotDetectionMiddlewareTest`**: ২-এর ফাইল পুরোনো baseline (constructor মেলে না, `TypeError`) — **১-এরটা নেওয়া হয়েছে** (আসল middleware-এর সাথে মেলে)।
- **`FinanceResourcesTest` (সেট ২)**: ২-এর নিজের কোডে `complete` ও `adjustBalance` নেই, অথচ টেস্টে ৯টা পুরোনো টেস্ট সেগুলো কল করতো — নিশ্চিত ফেল। ৯টা সরিয়েছি, ২টা ছোট টেস্ট যোগ করেছি (`complete` নেই + `settleManualWallet` আছে; wallet-এ শুধু lock/unlock)।
- **`.env.example` / `.env.ci` / `.env.production.example`**: ২ (৯১৮ key) ৩-এর (৩২০ key) সুপারসেট, তাই ২-কে ভিত্তি ধরে ৩-এর ৩টা key (`GLO_L6_PURCHASE_ENABLED`, `REAL_MONEY_LANE_READINESS_MODE`, `REAL_MONEY_TECHNICAL_READINESS_MODE`) কমেন্ট সহ যোগ। `.env.example`-এ ২-এর তিনটা ফাঁকা value (`MAIL_PASSWORD`, `PERMISSION_CACHE_KEY`, `REDIS_PASSWORD`) ৩-এর আসল value (`null`, `spatie.permission.cache`, `null`) ঢেকে দিতো — ৩-এরগুলো নেওয়া। এখন প্রতিটায় ৯২৬ key।

## ৩. জিপে যা মিসিং ছিল

- **`ci.yml` (সেট ১)** এই ফাইলগুলো ডাকে, কোনো জিপে নেই: `scripts/run_php_test_lane.py`, `scripts/mysql-roundtrip-fingerprint.sql`, `scripts/build-weekly-native-verifier.sh`, `scripts/check-test-runtime.php`। ১-এর README-ও `scripts/` (lane runner, runtime gate, schema fingerprint), `tests/Unit/Build/*` (CiRuntimeContractTest, NativeVerifierBuildContractTest) এবং `RolePermissionSeeder`-এর কথা বলে — এগুলোও জিপে নেই (ফাইল সংখ্যা ২৮৩ মিললেও)। `RolePermissionSeeder` আপনার প্রজেক্টে আগে থেকেই আছে (`DatabaseSeeder` ওটা ডাকে), তাই সম্ভবত অপরিবর্তিত; সেট ১-এর সোর্সে বদলে থাকলে ফাইলটা পাঠান। CI লাল হতো, তাই `ci.yml` **`_held_back_by_merge/ci.yml`-এ রাখা**, বসানো হয়নি।
- **`.nvmrc`** নেই, অথচ ১-এর `security.yml` ব্যবহার করে → `20` দিয়ে তৈরি করা হয়েছে (পুরোনো workflow-এর Node 20)। আপনার নিজের থাকলে স্ক্রিপ্ট ছোঁয় না।
- ২-এর `PAYMENT_CHANGED_FILES_FULL_CONTENT.txt` (৭৪১ KB, পুরোনো কোডের ডাম্প) ও `CHANGESET_MANIFEST.txt` প্রজেক্টে বসানো হয়নি।
- ২-এর ডিলিট তালিকার ২ ফাইল (`ManualDisbursementQueueService.php`, `Antimalware/ClamAvScanner.php`) আপনার app.zip-তে ছিলই না; কোনো কোড এগুলোর ওপর সরাসরি নির্ভর করে না (পুরোনো `ManualDisbursementQueueService` নামটা `AppServiceProvider` runtime-এ alias করে)। আপনার প্রজেক্টে থাকলে স্ক্রিপ্ট সরাবে।
- `app/`-এ ৪টা বাড়তি ব্যাকআপ ফাইল পড়ে আছে (`AppServiceProvider.php.bak`, তিনটা `*.bak-hardening`) — PHP লোড করে না, তবে মুছে ফেলাই ভালো। আমি ছুঁইনি।

## ৪. কী যাচাই করেছি, কী পারিনি

যাচাই (স্ট্যাটিক):
- PHP lint: সব নতুন/বদলানো ফাইল ঠিক। একমাত্র এরর `FrontendOpsServiceProvider.php:182` (`new JsonResponse(...)->withHeaders()` = PHP 8.4 সিনট্যাক্স; আপনার 8.4-এ ঠিক, ফাইল অপরিবর্তিত)।
- সব `App\` class রেফারেন্স resolve হয়, নতুন কোনো মিসিং ক্লাস নেই (শুধু ২-এর একটা টেস্ট পুরোনো alias `ManualDisbursementQueueService` ব্যবহার করে, যা `AppServiceProvider` runtime-এ বানায়)।
- নতুন কোডের view রেফারেন্স সব আগে থেকেই আপনার ভার্সনে ছিল; `real_money` lang en/bn/th তিনটায় ১৯টা করে key মেলে।
- ৩-এর `MoneyEntryPointCoverageTest` ও `MoneyShutdownControlTest`-এর source-scan শর্ত মার্জড কোডে মিলিয়েছি: ০ সমস্যা।
- config key: কোড যে key পড়ে অথচ সরবরাহ করা config-এ নেই তার তালিকা করেছি। bKash/Nagad-এর ৫টা ঠিক করেছি; বাকি ২১টা হয় default সহ, নয়তো null-সহনশীল (sandbox/webhook/rate_limits), তাই কাজ আটকাবে না।
- `apply-merge.sh` একটা mock প্রজেক্টে দুবার চালিয়েছি: দ্বিতীয়বার কিছুই বদলায় না; `app/` মার্জড কপির সাথে byte-identical।

**করতে পারিনি:** PHPUnit চালাইনি (এখানে `vendor/` নেই)। টেস্ট রান আপনার মেশিনে।

## ৫. আপনার সিদ্ধান্ত লাগবে

1. **`settleManualWallet` রাখলাম।** ২-এর নীতি "টাইপ করা রেফারেন্স প্রমাণ নয়"; তবে আপনার সার্ভিস TrxID-কে প্লেয়ারের জমা দেওয়া proof-এর সাথে `hash_equals` দিয়ে মেলায়, তাই নীতির সাথে মেলে। চাইলে সরানো যায়।
2. **env নাম অনুমান:** `BKASH_MODE`, `BKASH_MERCHANT_NAME`, `BKASH_MERCHANT_NUMBER`, `NAGAD_MODE`, `NAGAD_MERCHANT_NAME`। আপনার আসল `config/payment.php`/`.env`-এ অন্য নাম থাকলে সেটাই রাখুন (স্ক্রিপ্ট ব্যাকআপ নেয়)।
3. `adjustBalance` ফেরত চান কিনা (২ বলছে না, ১-এর RBAC তাকে আলাদা permission দিয়ে রাখতো)।
4. `ci.yml`-এর স্ক্রিপ্টগুলো পাঠান, নয়তো বলুন আমি লিখে দিই।

## ৬. চালানোর ধাপ (Git Bash, প্রজেক্ট রুটে)

```bash
git add -A && git commit -m "before merge"          # আগে কমিট
bash apply-merge.sh merge-overlay.tar.gz            # ব্যাকআপ: .merge-backup/<সময়>/
composer require --dev larastan/larastan:^3.0 --no-interaction
php artisan migrate                                 # ৪টা নতুন migration
php artisan db:seed --class=AdminRbacSeeder
php artisan test --filter='FinanceResourcesTest|PromptConfigurationRoutesAndSeederCoverageTest|MoneyEntryPointCoverageTest|BotDetectionMiddlewareTest|DoubleEntryLedgerServerRaceTest|FraudDetectionServiceTest|GranularRbacTest'
git diff -- config/ tests/TestCase.php .env.example # আপনার কিছু বাদ পড়ল কিনা দেখুন
```

`tests/TestCase.php` ২-এর ভার্সনে বসে (`ensureLedgerChainState()` লাগে)। নিজের কোনো helper থাকলে `.merge-backup` থেকে ফিরিয়ে ওই মেথডটা যোগ করুন।
