# Quickstart: Android Capacitor Shell

## Prerequisites

- Node.js 22+ and npm.
- PHP 8.4 plus the existing Laravel dependencies for server tests.
- For native compilation only: Android Studio 2025.2.1+, its bundled JDK, Android SDK tools, and one API
  24+ platform (API 36 is the current stable platform in the referenced Capacitor 8 guide).
- An HTTPS SelfHandler API origin reachable from the device. Do not use a URL containing credentials,
  path, query, fragment, localhost, or cleartext HTTP.

The current workspace has Node 23.6.1 but no Android Studio/JDK/SDK/adb; JS/config/sync checks are
available, while Gradle/emulator/device steps are expected to report missing prerequisites.

## Configure Public Mobile Environment

Copy `apps/mobile/.env.example` to the ignored `apps/mobile/.env` and set:

```dotenv
SELFHANDLER_MOBILE_API_ORIGIN=https://selfhandler.example.test
```

This is a public endpoint, not a secret. Never place an auth token, password, signing password, URL
credential, or private key in this file.

## Install

```powershell
npm --prefix apps/web ci
npm --prefix apps/mobile ci
```

## Server Gates

```powershell
Set-Location apps/api
php artisan test --testsuite Feature --filter Mobile
php artisan test
vendor\bin\pint --test
php artisan route:list --path=api/mobile --json
Set-Location ../..
```

## Shared Web And Native-Mode Gates

```powershell
npm --prefix apps/web run test:unit
npm --prefix apps/web run check:i18n
npm --prefix apps/web run typecheck
npm --prefix apps/web run build
npm --prefix apps/web run test:e2e -- e2e/mobile
```

## Build And Sync The Shell

```powershell
npm --prefix apps/mobile test
npm --prefix apps/mobile run sync:android
npm --prefix apps/mobile run validate
```

`build:web` validates the HTTPS origin and injects the public origin for the native API boundary.
`sync:android` copies that bundle and declares the native plugins. The release Capacitor config must not
contain `server.url`.

## Compile And Sideload (External Toolchain)

After Android Studio/JDK/SDK are installed:

```powershell
Set-Location apps/mobile/android
.\gradlew.bat assembleDebug
adb install -r .\app\build\outputs\apk\debug\app-debug.apk
Set-Location ../../..
```

For a signed release, copy the committed signing template to the ignored
`apps/mobile/android/keystore.properties`, point it to a keystore outside git, then run:

```powershell
Set-Location apps/mobile/android
.\gradlew.bat assembleRelease
Set-Location ../../..
```

APK/AAB outputs and signing material must remain ignored. Play Store/AAB publication is not part of 012.

## Manual Acceptance Journey

1. Install the debug APK on an Android 13+ emulator/device and open it.
2. Confirm the existing account sign-in UI appears and native registration guidance replaces the web
   create-account link.
3. Sign in with a valid existing account; navigate Today, Routines, Planner, Notifications, Account.
4. Background/reopen the app and confirm session restoration without another password.
5. Open a form near the screen bottom; show/hide the keyboard and confirm focus/submit feedback remain
   visible with no horizontal scroll.
6. Open a modal/popover, press Android Back (surface closes), navigate away and press Back (route
   returns), then press Back at Today (app minimizes).
7. In Notifications explicitly enable Android alerts. Seed/deliver one unread event, resume/sync twice,
   and confirm one system notification and one `android_local` server channel.
8. Tap the native alert; confirm only its safe Planner route or Notifications opens and it becomes read.
9. Sign out; confirm the app returns to sign-in and the old token receives 401.

## Security/Repository Audit

The secret audit is part of the synchronized validator:

```powershell
npm --prefix apps/mobile run validate
git diff --check
git status --short
```

Verify no changed path is under `specs/002-homelab-deployment`, `deployment`, `_local-deploy`,
`deploy.ps1`, or workflows; `design_handoff_selfhandler_mvp/` must remain untracked and untouched.
