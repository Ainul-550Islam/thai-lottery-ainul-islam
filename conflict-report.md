# Conflict triage — repo vs package

- Repo: `C:\Users\Ainul Islam\Desktop\thai-lottery-ainul islam`
- Package root: `C:/Users/Ainul Islam/Downloads/mobile-pwa-next/mobile-pwa-next`
- Generated: 2026-10-23T15:06:34.443Z

| File | Repo lines | Pkg lines | repo-only | pkg-only | Verdict |
| --- | --- | --- | --- | --- | --- |
| `.github/workflows/ci.yml` | 528 | 112 | 528 | 112 | **MANUAL-MERGE** |
| `.github/workflows/signed-android-apk.yml` | 207 | 143 | 167 | 103 | **MANUAL-MERGE** |
| `android/.gitignore` | 17 | 8 | 10 | 1 | **MANUAL-MERGE** |
| `android/app/build.gradle` | 114 | 97 | 72 | 55 | **MANUAL-MERGE** |
| `android/app/proguard-rules.pro` | 4 | 12 | 4 | 12 | **MANUAL-MERGE** |
| `android/app/src/main/AndroidManifest.xml` | 24 | 37 | 6 | 19 | **MANUAL-MERGE** |
| `android/app/src/main/java/com/ainul550/thailottery/MainActivity.java` | 293 | 362 | 177 | 246 | **MANUAL-MERGE** |
| `android/app/src/main/res/drawable/ic_launcher.xml` | 19 | 9 | 18 | 8 | **MANUAL-MERGE** |
| `android/app/src/main/res/values/strings.xml` | 10 | 8 | 6 | 4 | **MANUAL-MERGE** |
| `android/app/src/main/res/values/styles.xml` | 13 | 9 | 8 | 4 | **MANUAL-MERGE** |
| `android/build.gradle` | 3 | 19 | 2 | 18 | **MANUAL-MERGE** |
| `android/gradle.properties` | 4 | 6 | 1 | 3 | **MANUAL-MERGE** |
| `android/gradle/wrapper/gradle-wrapper.jar` | — binary (43583B) | — binary (43453B) | — | — | MANUAL-MERGE |
| `android/gradle/wrapper/gradle-wrapper.properties` | 8 | 7 | 2 | 1 | **MANUAL-MERGE** |
| `android/gradlew` | 224 | 221 | 6 | 3 | **MANUAL-MERGE** |
| `android/gradlew.bat` | 73 | 71 | 73 | 71 | **MANUAL-MERGE** |
| `android/README.md` | 23 | 37 | 22 | 36 | **MANUAL-MERGE** |
| `android/settings.gradle` | 16 | 16 | 1 | 1 | **MANUAL-MERGE** |
| `app/Console/Commands/Operations/VerifyFrontendBuildCommand.php` | 25 | 53 | 14 | 42 | **MANUAL-MERGE** |
| `app/Http/Controllers/PublicAndroidApkController.php` | 79 | 84 | 52 | 57 | **MANUAL-MERGE** |
| `app/Services/Media/AndroidApkReleaseService.php` | 164 | 159 | 126 | 121 | **MANUAL-MERGE** |
| `app/Services/Operations/FrontendBuildService.php` | 104 | 223 | 77 | 196 | **MANUAL-MERGE** |
| `package.json` | 25 | 26 | 23 | 24 | **MANUAL-MERGE** |
| `public/.htaccess` | 20 | 87 | 3 | 70 | **MANUAL-MERGE** |
| `public/icons/apple-touch-icon.png` | — binary (15136B) | — binary (2176B) | — | — | MANUAL-MERGE |
| `public/icons/favicon-16.png` | — binary (626B) | — binary (252B) | — | — | MANUAL-MERGE |
| `public/icons/favicon-32.png` | — binary (1532B) | — binary (386B) | — | — | MANUAL-MERGE |
| `public/icons/icon-192.png` | — binary (22415B) | — binary (2670B) | — | — | MANUAL-MERGE |
| `public/icons/icon-512.png` | — binary (76928B) | — binary (8569B) | — | — | MANUAL-MERGE |
| `public/icons/icon-maskable-192.png` | — binary (16469B) | — binary (2072B) | — | — | MANUAL-MERGE |
| `public/icons/icon-maskable-512.png` | — binary (58376B) | — binary (6775B) | — | — | MANUAL-MERGE |
| `public/manifest.webmanifest` | 16 | 56 | 9 | 49 | **MANUAL-MERGE** |
| `public/offline.html` | 191 | 133 | 176 | 118 | **MANUAL-MERGE** |
| `public/sw.js` | 35 | 366 | 27 | 358 | **MANUAL-MERGE** |
| `resources/css/accessibility.css` | 68 | 124 | 54 | 110 | **MANUAL-MERGE** |
| `resources/js/layout/mobile-menu.js` | 42 | 189 | 36 | 183 | **MANUAL-MERGE** |
| `resources/js/pwa.js` | 83 | 178 | 70 | 165 | **MANUAL-MERGE** |
| `resources/views/components/navigation/mobile-nav.blade.php` | 57 | 78 | 53 | 74 | **MANUAL-MERGE** |
| `tests-pwa-sw.cjs` | 197 | 637 | 168 | 608 | **MANUAL-MERGE** |
| `android/app/src/main/res/xml/network_security_config.xml` | 8 | 18 | 0 | 10 | **TAKE-PACKAGE** |

**Summary:** 1 take-package · 39 manual-merge · 0 keep-repo · 0 byte-differs (40 total)

How to read a verdict:

- **TAKE-PACKAGE** — the package version has every line you have plus additions; copying it loses nothing.
- **BYTE-DIFFERS** — same lines, different whitespace/order; take the package version.
- **KEEP-REPO** — your file already contains everything the package offers; keep yours (you may have newer work).
- **MANUAL-MERGE** — both sides have unique lines. Inspect with:
  `node conflict-triage.mjs --package "<dir>" --unified <path>`
