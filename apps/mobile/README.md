# SelfHandler Android shell

This package wraps the production `apps/web` bundle in Capacitor 8 for Android. It does not fork the
Vue product, load remote HTML, or contain a live-update channel. `capacitor.config.ts`, the Android
project, Gradle wrapper, native vault, and generated launcher/splash resources are versioned; copied web
assets, SDK state, signing material, APKs, and AABs are ignored.

## Requirements

Repository-owned gates require Node.js 22+ and npm. Native compilation requires JDK 21, Android SDK
API 36, Build Tools 35.0.0, and platform tools. Android Studio is optional: command-line SDK tools and
the checked-in Gradle wrapper are sufficient. Set `JAVA_HOME` and `ANDROID_HOME` to those installations.
The minimum Android API is 24. Compilation and lint do not replace installation and real-device checks.

## Public configuration

Set one reachable public HTTPS origin. Credentials, a path, query, fragment, localhost, private IPs,
and HTTP fail before the Vue build begins.

```powershell
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
```

The origin is public configuration, not a secret. Mobile session tokens are returned once by the API,
stored as Android Keystore-backed AES-GCM ciphertext in private, backup-disabled preferences, and read
only immediately before native HTTP requests. They must never appear in `.env`, Web Storage, cookies,
URLs, logs, source, or signing properties.

## Install, test, build, and sync

```powershell
npm --prefix apps/web ci
npm --prefix apps/mobile ci
npm --prefix apps/mobile test
npm --prefix apps/web run test:unit
npm --prefix apps/web run check:i18n
npm --prefix apps/web run typecheck
npm run sync:android
```

`sync:android` validates the origin, builds `apps/web/dist`, runs `cap sync android`, and then verifies:

- exact Capacitor/plugin versions and a stable `app.selfhandler.mobile` id;
- no `server.url` or cleartext/private endpoint fallback;
- byte-identical shared files in the synchronized bundle (apart from Capacitor's two Cordova stubs);
- vault registration/Keystore/GCM/private-storage source invariants;
- manifest, Back/keyboard notification permission, resource, signing-ignore, and wrapper contracts;
- absence of tracked APK/AAB/signing material and common credential patterns in packaged sources.

Ignored local build outputs and signing configuration may remain in place between builds; validation
rejects them if Git tracks them. Keep the release signing key outside the repository and retain it for
future updates, which Android requires to have the same signing identity.

Run `npx cap ls android` from this directory to inspect synchronized plugins. The custom
`MobileCredentialVault` is registered by `MainActivity`, so it is not an npm plugin entry.

## Compile and sideload

After installing the external Android toolchain:

```powershell
Set-Location apps/mobile/android
.\gradlew.bat assembleDebug
adb install -r .\app\build\outputs\apk\debug\app-debug.apk
Set-Location ../../..
```

For a signed release, copy `android/keystore.properties.example` to the ignored
`android/keystore.properties`, replace its placeholders, and keep the `.jks` outside the repository:

```powershell
Set-Location apps/mobile/android
.\gradlew.bat assembleRelease
Set-Location ../../..
```

Without signing properties the release task produces an unsigned build input for validation. Play
Store publication and production deployment are not part of feature 012.

## Device acceptance

1. Install the signed APK and sign in with an existing account. Account creation remains browser-only.
2. Reopen the app, navigate protected routes, and confirm the session restores without a password.
3. Open a popover and press Back; it closes before route history. At Today/login root, Back minimizes.
4. On the Ulefone Armor Mini 20T Pro, check normal and enlarged Android text/display settings.
   Verify headers, two-column Today metrics, profile Save above navigation, and scrolling without
   sideways overflow. Focus a bottom control and confirm the keyboard leaves the field and popup
   reachable. Check both gesture navigation and the system navigation bar if used.
5. Explicitly enable Android notifications in Notifications. Synchronize one unread inbox event twice
   and confirm one local notification plus one idempotent `android_local` server channel.
6. Tap it and confirm only its safe Planner action or Notifications opens and the event becomes read.
7. Sign out and confirm the old token receives 401 while a separate browser/device session survives.

Browser regressions in `compact-phone.spec.ts` and `compact-actions.spec.ts` cover 320/360 CSS-pixel
widths, enlarged text, dark mode, simulated Android insets/keyboard, and saving through real forms.
The phone's 720×1600 physical panel does not imply a 720 CSS-pixel viewport. Browser emulation does
not verify native camera/gallery, notification permission, Android Back, or the installed WebView;
complete those checks on the physical phone.

Local notifications mirror inbox events only after app synchronisation/resume. They do not wake a
stopped app; FCM, exact alarms, background sync, offline data, iOS, and Play Store delivery are deferred.
