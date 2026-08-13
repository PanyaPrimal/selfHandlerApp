# Research: Android Capacitor Shell

**Feature**: `012-android-capacitor-shell`
**Date**: 2026-08-13

## R1 — Capacitor version and checked-in native project

Current official documentation is Capacitor v8. The registry reports core/CLI/Android `8.5.0`; the
official environment guide requires Node 22+, Android Studio 2025.2.1+, and an Android SDK platform API
24+. This workspace has Node 23.6.1 but no Java, Android SDK, `adb`, or Gradle executable.

**Decision**: pin the shell to the compatible Capacitor 8 family and commit the generated Android
project. `apps/mobile` owns CLI/Android/config/build scripts; `apps/web` owns compile-time imports for
the same core/plugin versions. `webDir` points to `../web/dist`. The checked-in Gradle wrapper is the
reproducible Android entry point once external tools exist.

**Rejected**:

- a remote `server.url` release: it turns the APK into a remote-site launcher and violates bundled-code
  integrity;
- a second Vue application: it duplicates every product and localisation change;
- hiding the missing SDK by claiming Gradle success: it makes the acceptance evidence false.

## R2 — Cookie session versus mobile token

The current browser client uses same-origin Fetch, a readable XSRF cookie, and an HttpOnly Laravel
session cookie. A bundled Android WebView has a local app origin. Cross-site cookie replication would
require third-party cookie behavior, `SameSite=None`, stateful-origin/CORS configuration, and a separate
way to read the API-domain CSRF cookie. It would weaken and complicate the already proven browser
contract.

Laravel Sanctum officially documents a separate mobile application flow: exchange credentials and a
device name for a Bearer token, protect the same `auth:sanctum` routes, and revoke the token on logout.
Capacitor's security guidance recommends Android Keystore/secure storage for persisted session tokens.

**Decision**: use a Sanctum token scoped `mobile`, with an absolute 30-day expiry. Browser endpoints stay
session-only. Three `/api/mobile/session` operations create, inspect, and revoke the current device token.
A bearer-only middleware prevents a cookie session from invoking token-specific operations. Password
verification uses the same normalization, generic error, locale, and rate-limit contract as web login
without creating a web session.

The plaintext token is returned once and immediately passed to a custom vault. Android Keystore owns a
non-exportable AES/GCM key; encrypted bytes and IV use private SharedPreferences with backup disabled.
There is deliberately no JavaScript/Web fallback and no token cached in Vue reactive state.

## R3 — Native network transport

Capacitor 8 bundles `CapacitorHttp` in core. It can use native HTTP libraries either by patching global
Fetch/XHR or through explicit helper calls. Global patching makes browser/native differences implicit
and could change unrelated web behavior.

**Decision**: add an explicit transport seam in `apps/web/src/api/http.ts`. Browser calls keep the
existing relative Fetch + CSRF path. Native calls use `CapacitorHttp.request`, an absolute API URL
validated at build/runtime, and a Bearer value read just-in-time from the vault. Both branches normalize
status, payload, response headers, retry-after, 204, network failures, and unauthorized callbacks into
the existing `ApiError` contract. Native unsafe requests do not initialize browser CSRF.

Only HTTPS origins are accepted. The endpoint is public configuration, not a secret, but query, path,
fragment, embedded credentials, localhost, and non-default URL tricks are rejected. Local Android
development should use an HTTPS tunnel/domain; no cleartext exception enters production config.

## R4 — Registration and session restoration

Supporting invite registration natively would require extracting the existing web controller's
transaction into a shared account service and defining token issuance/partial-failure rules. The roadmap
requires sign-in, logout, and expiry, not new-account creation.

**Decision**: 012 supports existing accounts only. Native `/register` redirects to `/login`; the login
view replaces its create-account link with localized browser guidance. Browser registration remains
untouched. On startup, native restoration checks whether a token exists before calling the mobile
session endpoint. Missing/revoked/expired credentials clear the vault and become guest; service failures
preserve a valid token and show the existing unavailable/retry state.

Logout attempts server revocation, treats 401 as already revoked, then clears locally in a `finally`
path. A general network failure does not pretend revocation succeeded: it preserves the credential and
shows retry feedback, while an explicit local-forget fallback is outside this thin slice.

## R5 — Hardware Back, lifecycle, and keyboard

The official App plugin states that installing a `backButton` listener disables default behavior; the
application must navigate or exit/minimize explicitly. The Keyboard plugin reports show/hide and height;
Android itself uses the native window resize path.

**Decision**: one `initializeAndroidShell(router)` lifecycle owns listener handles and cleanup. Back
dispatches a cancellable shared `selfhandler:back` event so dialogs/popovers can consume it, then uses
Vue history, then `App.minimizeApp()` at `/` or `/login`. It never calls `exitApp()` as a routine root
action. App resume triggers session/inbox/native-presenter refresh.

Keyboard events toggle `data-native-keyboard`, set a CSS pixel variable, and scroll the active control
into the nearest visible area. Android manifest/activity remains `adjustResize`; shared CSS uses dynamic
viewport and safe-area values. Desktop/mobile browser tests emulate events; native-source checks guard
manifest/config values until an emulator exists.

## R6 — Local notifications without FCM

The server-side 011 channel contract cannot call code on an offline phone. Exposing future schedules to
the device would require localizing before delivery, reconciling changed sources/settings, exact-alarm
permissions, and a second scheduling authority. FCM is explicitly deferred.

**Decision**: implement a presentation adapter, not a second scheduler. After normal API synchronization
or app resume, it filters already-delivered unread rows that do not contain `android_local`. With explicit
OS permission, it creates a stable notification channel, checks pending/delivered native ids, schedules
one immediate local presentation using persisted delivered copy, then idempotently acknowledges the
channel on the server. Android 13 permission is requested only by the user's button.

Tap metadata contains only numeric notification id and an already validated relative action. The action
listener marks read best-effort and routes to Planner or `/notifications`. There is no exact alarm
permission because no future exact schedule exists. If the process is stopped, delivery waits for the
next synchronization; docs and UI say so.

## R7 — Native id mapping and acknowledgment

Android notification identifiers are signed 32-bit integers, while database ids may exceed that range.

**Decision**: reserve a positive namespace and compute `((serverId - 1) % 2_000_000_000) + 1`. Before
presentation the adapter also compares the original server id in native `extra`; a modulo collision does
not silently overwrite a different pending/delivered item. A collision skips presentation and reports a
recoverable adapter error. Server acknowledgment locks/updates the owner-scoped row and appends the
constant `android_local` only when its status is `sent`; retries return the same row.

## R8 — Icons, splash, and signing

Official Capacitor guidance uses `@capacitor/assets` with 1024×1024 icon and 2732×2732 splash sources;
Android 12+ renders the platform splash as an icon over a background.

**Decision**: create simple code-native SelfHandler source art derived from the existing square brand
mark, generate and commit Android adaptive/legacy/splash resources, and keep generated APK/AAB output
ignored. Debug uses Android's standard debug signing. Release signing reads an ignored
`keystore.properties`; a committed example names required values without credentials. Release source
validation can pass here, but compilation/signing cannot be claimed without the external toolchain/key.

## R9 — Test layers

1. Laravel schema/auth/ownership/rate-limit/locale/OpenAPI tests prove the server contract.
2. Vitest unit tests prove URL validation, transport selection/normalization, vault isolation, native id
   mapping, presentation dedupe, back ordering, and permission branches with mocked plugins.
3. Playwright browser/mobile tests prove native-mode UI copy/route guards, Android bridge events,
   keyboard layout, and unchanged web auth.
4. A Node mobile validator checks versions, config, no `server.url`, plugin registration, manifest
   security/resize, resources, signing ignores, no secret-shaped values, and synchronized web assets.
5. `cap sync android` proves the native dependency graph without Android SDK compilation.
6. Gradle/debug/release/emulator/device checks remain externally blocked until the documented tools exist.

## Constitution Check

| Principle | Result |
|---|---|
| I | Full feature artifacts precede package, API, web, and Android changes. |
| II | Roadmap/auth/notifications/architecture remain canonical; 012 resolves their mobile boundary. |
| III | One platform, one auth method, one presentation adapter; offline/FCM/iOS are deferred. |
| IV | Auth, ids, routing, and presentation are deterministic; no AI. |
| V | Tokens are owner-bound, hashed server-side, Keystore protected, expiring, and revocable. |
| VI | API/OpenAPI/TS/native/browser/config evidence moves with the boundary. |
| VII | Runtime feedback ships in complete EN/RU/UK catalogs. |
