# Thai Lottery Android APK

This Android application is a small, origin-locked WebView client for the existing Laravel service. Authentication, lottery data, payments, account state, and authorization remain server-side. The APK contains no copied production records and no hard-coded test account.

## Production build requirements

A release build requires all of the following:

1. A public production URL served over HTTPS. The application refuses `http://`, `localhost`, `.local`, and `.internal` destinations. `http://localhost` in the development `.env.example` is not a production URL and must not be embedded in a public APK.
2. A persistent Android release keystore. Back up this keystore securely. If it is lost, Android will not allow a future APK signed with a different key to update an already-installed copy.
3. Java 17, Gradle 8.10.2, Android SDK platform 35, and Android build tools 35.0.0.

The repository's `Signed Android APK` workflow builds and verifies a signed release. It requires these protected GitHub Actions secrets:

- `ANDROID_KEYSTORE_BASE64`: base64-encoded JKS or PKCS12 keystore bytes;
- `ANDROID_KEYSTORE_PASSWORD`: keystore password;
- `ANDROID_KEY_ALIAS`: release key alias;
- `ANDROID_KEY_PASSWORD`: release key password. For a PKCS12 keystore, set this to the same password as `ANDROID_KEYSTORE_PASSWORD`; JKS keystores may use separate values.

Create a keystore with the organization's approved Android release-key process, encode it without adding line breaks, and add its value and passwords as repository or protected-environment secrets. Never commit the keystore, passwords, or a base64 keystore value to Git.

Run **Actions → Signed Android APK → Run workflow** and enter the canonical production HTTPS URL, semantic version, and a monotonically increasing positive version code. The workflow validates the destination, builds `com.ainul550.thailottery`, verifies the APK signature and package label, computes SHA-256, and uploads an Actions artifact containing:

- `app/thai-apk.apk` — the signed APK;
- `app/thai-apk.manifest.json` — generated version, source commit, site URL, file size, and SHA-256 evidence.

The workflow does not automatically commit a binary or deploy it. To enable the in-site download, deploy **both files together** at those exact paths. The `/download` page and `/app/thai-apk.apk` endpoint remain fail-closed until the binary matches its manifest.

## Local debug build

With the Android SDK installed and a deployed HTTPS site available, a debug build can be produced with:

```sh
ANDROID_APP_URL=https://your-production-host.example gradle --no-daemon -p android :app:assembleDebug
```

The debug APK is written to `android/app/build/outputs/apk/debug/app-debug.apk`, uses a distinct `.debug` application ID, and is not a public production release. A release build must use the persistent signing key and the GitHub workflow (or equivalent protected build process).
