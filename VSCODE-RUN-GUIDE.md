# VS Code Run Guide — Thai Lottery (Laravel 12 / PHP 8.4)

Everything below was verified in the build sandbox: Unit suite 745/745,
Feature suite 2,166/2,166, real Android debug + signed-release builds.

## 1. Requirements on your machine

| Tool | Version | Notes |
|---|---|---|
| PHP | 8.4.x | extensions: bcmath, ctype, curl, dom, fileinfo, gd (JPEG), intl, mbstring, pdo_mysql, pdo_sqlite, sqlite3, tokenizer, xml, zip, sodium |
| Composer | 2.8+ | |
| Node.js | 20.x + npm | for the Vite frontend build |
| Database | MySQL 8 (production-like) or SQLite (quick local run) | tests use SQLite in-memory |
| Java (Android only) | JDK 17 or 21 | only needed to build the APK |
| Android SDK (Android only) | platform 35, build-tools 35.0.0 | only needed to build the APK |

## 2. First run (web app)

```bash
git clone https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git
cd thai-lottery-ainul-islam

composer install --no-interaction
npm install
npm run build

cp .env.example .env
php artisan key:generate

php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Open http://127.0.0.1:8000

SQLite quick alternative: in `.env` set `DB_CONNECTION=sqlite` and
`DB_DATABASE=database/database.sqlite`, create the empty file, then run
`php artisan migrate --seed`.

In VS Code: open the folder, install the recommended extensions
(`.vscode/extensions.json`), then use Run and Debug → "Serve Laravel"
or Terminal → Run Task → "Laravel: serve".

## 3. Run the tests (must stay green)

```bash
php scripts/check-test-runtime.php        # prints "ready": true
php -d memory_limit=1G vendor/bin/phpunit --testsuite=Unit
php -d memory_limit=1G vendor/bin/phpunit --testsuite=Feature
```

Expected: Unit OK (745 tests), Feature OK (2,166 tests, 1 skipped).

## 4. Build the Android APK

```bash
cd android
export JAVA_HOME=/path/to/jdk-21
export ANDROID_HOME=$HOME/Android/Sdk
export ANDROID_APP_URL=https://your-production-domain.example

./gradlew --no-daemon :app:assembleDebug -PversionName=1.0.0 -PversionCode=1
# debug output: android/app/build/outputs/apk/debug/app-debug.apk
```

Signed release (requires your production keystore — never commit it):

```bash
export ANDROID_KEYSTORE_PATH=/safe/path/release.keystore
export ANDROID_KEYSTORE_PASSWORD=...
export ANDROID_KEY_ALIAS=...
export ANDROID_KEY_PASSWORD=...

./gradlew --no-daemon :app:assembleRelease -PversionName=1.0.0 -PversionCode=1
$ANDROID_HOME/build-tools/35.0.0/apksigner verify --print-certs \
  app/build/outputs/apk/release/app-release.apk
```

The build fails closed on purpose: no `ANDROID_APP_URL` (public HTTPS,
no credentials/query/fragment) or no complete keystore material = no APK.
The normal release path is GitHub Actions → "Signed Android APK"
workflow (`.github/workflows/signed-android-apk.yml`); deploy the produced
`app/thai-apk.apk` + `app/thai-apk.manifest.json` pair together — the public
download endpoints stay `404 NOT_CONFIGURED` until a VERIFIED manifest is
deployed, and no binary or keystore is ever committed.

## 5. Push to GitHub

```bash
git remote add origin https://github.com/Ainul-550Islam/thai-lottery-ainul-islam.git
git branch -M main
git push -u origin main
```

GitHub will ask you to sign in (browser token / PAT) — the sandbox that
built this ZIP has no GitHub credentials, so the push must run from your
machine. All work is already committed on `main`, so the push is one
command. If `git push` reports non-fast-forward, run
`git pull --rebase origin main` first and resolve, never `--force`.

## 6. What is NOT in this ZIP (regenerate locally)

`vendor/` (composer install), `node_modules/` and `public/build/`
(npm install && npm run build), `.env` (cp .env.example .env),
`android/app/build/` (Gradle), and any APK binary/keystore (by design).
