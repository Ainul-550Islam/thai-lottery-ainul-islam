# সব বর্তমান Frontend কোড — ডাউনলোড ও Git push-এর জন্য

এটি **পূর্ণ বর্তমান Frontend source snapshot**, শুধু শেষ incremental patch নয়। এটি সম্পূর্ণ Laravel/backend application বা runtime-certified release নয়। ZIP-এর `frontend-source/` directory-র ভেতরে মূল repository-relative paths রাখা হয়েছে।

## কী আছে

- `resources/` সম্পূর্ণ: Blade views/components/partials, browser JS, CSS ও অন্য frontend resources।
- `lang/` সম্পূর্ণ: বর্তমান locale catalogues; কোনো operative legal/consent wording অনুবাদ বা পরিবর্তন করা হয়নি।
- `public/`-এর browser/static assets: images/icons/fonts/CSS/JS/manifest/offline page/worker। pre-existing Filament vendor assets-ও আছে; সব ফাইল নতুন করে আমার লেখা বলে দাবি করা হয়নি।
- `tests/js/`, `tests/browser/`, `tests/frontend/`, `tests/dependencies/`, `tests-pwa-sw.cjs`, `scripts/dependency_security_gate.mjs`। Browser directory-র PHP files কেবল বিদ্যমান frontend test helpers, production backend নয়।
- বিদ্যমান `package.json`, `package-lock.json`, `.nvmrc`, `.npmrc`, Vite/PostCSS/Tailwind/Playwright configs হুবহু। এগুলো build reproduction-এর জন্য দেওয়া, এই delivery-তে পরিবর্তিত নয়।
- `frontend-handoff/FILES.csv`: প্রতিটি source file-এর path/size/mode/SHA/category; `SOURCE-CHECKPOINT.tsv`: বর্তমান541 scoped source identity; `CHECKLIST.csv`: সব108 acceptance item; `SHA256SUMS`: ZIP-এর অন্য সব content file-এর hash।

Source/config/test/asset files: **599টি**, uncompressed bytes **7682439**। Documentation অতিরিক্ত।

## কী নেই

Backend `app/`, `routes/`, database/schema/migrations, Composer/vendor, `.env` বা secrets, `.git`, node_modules, Docker/CI/deployment, historical full archives/logs এবং generated Vite build output নেই। public server entry/config বাদ: public/.htaccess, public/index.php।

এটি backend-নির্ভর Blade frontend; নতুন standalone repository-তে push দেওয়া যায়, কিন্তু Laravel application/API/contracts ছাড়া শুধু এই ZIP দিয়ে সম্পূর্ণ app চলবে না। Existing Laravel repository-তে overlay করলে আগে diff/owner/concurrent changes পরীক্ষা করবেন; অন্ধভাবে overwrite নয়। বিদ্যমান root configs আলাদা review করবেন।

## ডাউনলোডের পর Git push — আপনার কম্পিউটারে

ZIP extract করে `frontend-source` folder-এ ঢুকুন। নতুন repository হলে:

```bash
git init
git add .
git commit -m "Add current frontend source snapshot"
git branch -M main
```

তারপর নিজের remote URL দিয়ে `git remote add origin YOUR_REPOSITORY_URL`, এবং `git push -u origin main` চালাবেন। `YOUR_REPOSITORY_URL` আপনাকে পূরণ করতে হবে। বিদ্যমান repository হলে নতুন init/remote নয়—প্রথমে `git status` ও `git diff`, তারপর পছন্দের files stage/commit/push। এখানে কোনো Git repository তৈরি, commit, remote সংযোগ বা push করা হয়নি। Secrets review করে তারপর push করবেন।

## Source verification

Checkpoint541 SHA256: `02fdccf806eb7a350afbb78de81137dacd3a21bb42555f516f4c8684c8014d1e`। এই checkpoint-এর সব541 file এবং অতিরিক্ত public/config/test assets ZIP-এ আছে। ফাইলগুলোর bytes/modes বিদ্যমান workspace-এর সঙ্গে যাচাই করা। Extracted `frontend-source` directory থেকে `sha256sum -c frontend-handoff/SHA256SUMS` চালানো যায়। ZIP-এর CRC/member/path/mode/hash ও fresh extraction proof detached verification file-এ আছে। ZIP SHA256 বাইরে রাখা হয়েছে, যাতে self-hash cycle না হয়।

## Acceptance এখনো সীমিত

শেষ sealed source checkpoint-এর diagnostics:153 Node +16 markup +19 worker VM =188 unique passing identities;16 নতুন lifecycle test। এটি approved runtime/browser acceptance নয়। CAPTCHA audit1 finding/exit1; historical mobile crash এবং PHP failures এখনও unresolved/unrerun।108 criteria:32 PASS/2 FAIL/72 BLOCKED/2 N/A। Node24.22.0/npm11.21.1, PHP8.4/Composer/vendor, locked browser dependencies/Chromium/build assets এবং actual browser/AT/viewport/locale/print gates এখনও BLOCKED/NOT RUN। কোনো install/download/network বা runtime-pin/lockfile পরিবর্তন করা হয়নি। Client abort server-side operation rollback/cancel হওয়ার প্রমাণ নয়।

### ZIP আলাদা করে test চালানোর সীমা

এই frontend-only ZIP-এ `.github/workflows/` ইচ্ছাকৃতভাবে নেই, যাতে আলাদা Git push-এ backend/DevOps workflows অকারণে চালু না হয়। অপরিবর্তিত `tests/dependencies/security-gate.test.mjs`-এর একটি test পূর্ণ repository-র পাঁচটি CI file পড়ে। ফলে শুধু ZIP extract করে পুরো Node command চালালে **152 PASS/1 FAIL (missing CI context, exit1)**; test বাদ দেওয়া বা দুর্বল করা হয়নি। মূল পূর্ণ repository checkpoint-এ এই একই source-এর153/153 PASS ছিল। ZIP থেকে markup16/16 ও worker19/19 আলাদাভাবে চালানো হয়েছে; বিস্তারিত detached ZIP-VERIFICATION.json-এ থাকবে। CI-context failure-কে PASS বা release certification বলা হয়নি।

পূর্ণ frontend snapshot হলেও এটিকে পুরো platform release-ready বলা হয়নি। নতুন request অনুযায়ী frontend-only ZIP দেওয়া হয়েছে; পুরো backend/workspace duplicate ZIP নয়।
