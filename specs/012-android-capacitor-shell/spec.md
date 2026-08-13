# Feature Specification: Android Capacitor Shell

**Feature ID**: `012-android-capacitor-shell`

**Created**: 2026-08-13

**Status**: Ready for implementation

**Input**: Package the shared Vue product as a sideloadable Android application, add a mobile-safe
authentication boundary, native navigation/keyboard behavior, branded assets, and the first local
notification presentation adapter without changing browser security or deployment.

**Design sources**: [Delivery Roadmap — 012](../../docs/design/delivery-roadmap.md#012--android-capacitor-shell) ·
[Architecture](../../docs/ARCHITECTURE.md) · [Vision](../../docs/design/vision.md) ·
[Notifications subsystem](../../docs/design/notifications.md) ·
[Feature 003](../003-multi-user-auth/spec.md) · [Feature 011](../011-in-app-notifications/spec.md)

## Why This Feature Exists

The responsive web product works on a phone, but it is not an installable Android application and its
same-origin cookie session cannot simply be copied into a bundled WebView with a local app origin.
SelfHandler needs one deliberately small native boundary: a reproducible Capacitor shell around the
same production Vue build, a revocable device credential protected by Android Keystore, predictable
hardware/keyboard behavior, and an honest bridge from the existing inbox to Android notifications.

## Clarifications

### Session 2026-08-13

- Q: Does Android load the production site remotely or bundle the web build?
  A: It bundles `apps/web/dist`. Capacitor `server.url` is not used in release builds; remote HTML and
  live-update mechanisms are out of scope. A build-time public HTTPS API origin is the only environment
  value embedded in the bundle.
- Q: How does mobile authentication differ from browser authentication?
  A: Browser behavior remains the feature-003 HttpOnly session + CSRF flow. Android exchanges an email,
  password, and recognizable device name for a short-lived Sanctum token over HTTPS. The token is
  stored only through a custom Android Keystore vault, read only for native requests, and never written
  to Web Storage, cookies, logs, URLs, deep links, or source control.
- Q: Does the Android app support registration?
  A: No. The 012 acceptance journey begins with an existing invite-created account. Native registration
  is hidden and `/register` redirects to sign-in with localized guidance; account creation remains on
  the browser surface until a later account-lifecycle feature explicitly includes it.
- Q: How long does a mobile credential live?
  A: Thirty days absolute. Logout revokes the current server token and clears the vault. A revoked,
  expired, malformed, or missing token yields the existing guest transition and clears local credential
  state before another request can reuse it.
- Q: How are native requests made?
  A: The native branch uses Capacitor's native HTTP API and an absolute HTTPS API origin. The browser
  branch continues using same-origin Fetch and CSRF unchanged. No cleartext production endpoint or
  arbitrary runtime host entry is allowed.
- Q: What does Android Back do?
  A: It first closes an open modal/popover via the shared escape boundary, then returns through Vue
  Router history. At the authenticated root or sign-in root it minimizes the app instead of silently
  destroying session state.
- Q: How are keyboard and viewport changes handled?
  A: Android uses resize mode, the bridge reports keyboard visibility/height as CSS state, and the
  focused control is scrolled into view without changing the shared form implementation. Safe-area and
  dynamic viewport units prevent system bars or the bottom nav from covering controls.
- Q: What is the first native notification adapter?
  A: It mirrors newly synchronized, already-delivered unread in-app events to Android Local
  Notifications after the user grants OS permission. It records `android_local` in the existing
  notification channels after successful presentation and deduplicates against pending/delivered native
  ids. Tapping safely opens the notification action or inbox and marks the inbox event read. It cannot
  wake a stopped app; FCM remains deferred.
- Q: Which artifacts are build outputs?
  A: The native Android project, source icon/splash assets, generated Android resources, Gradle wrapper,
  and build scripts are versioned. APK/AAB files, real API env files, signing keys/properties, Android SDK
  state, and generated web assets remain ignored.
- Q: What can be verified in the current workspace?
  A: Node 23 satisfies Capacitor 8's Node 22 floor, so web/mobile builds, sync/config/static/native-source
  checks can run. Android Studio, JDK, SDK, `adb`, and Gradle are absent, so `assembleDebug`,
  `assembleRelease`, emulator, and real-device installation are externally blocked and must be reported
  with exact reproduction commands rather than claimed as passed.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Install And Sign In Safely (Priority: P1)

As an existing SelfHandler user, I can install the APK, sign in to my homelab account, reopen the app,
and sign out without exposing a reusable credential to Web Storage.

**Independent Test**: Configure an HTTPS test API, install a debug build, sign in, reload/restart, use
one protected screen, sign out, and confirm the server token is revoked and the vault is empty.

**Acceptance Scenarios**:

1. **Given** a build without a valid HTTPS API origin, **When** mobile packaging starts, **Then** it
   fails before producing a bundle and never falls back to an arbitrary or cleartext host.
2. **Given** valid credentials and a device name, **When** Android signs in, **Then** one 30-day mobile
   token is returned once, stored through Keystore, and the shared authenticated UI opens.
3. **Given** the app is relaunched with a valid vaulted token, **When** session restoration runs, **Then**
   the user returns without entering a password and no token appears in local/session storage or cookies.
4. **Given** an invalid credential, throttle, network failure, or vault failure, **When** sign-in is
   attempted, **Then** localized recoverable feedback appears and the password/token is not retained.
5. **Given** a revoked or expired token, **When** a protected request returns 401, **Then** the vault is
   cleared once and the app returns to sign-in with the intended redirect.
6. **Given** an authenticated Android session, **When** the user signs out, **Then** only the current
   device token is revoked, local credential state is cleared even if the network already considers it
   invalid, and browser sessions remain unchanged.
7. **Given** a normal web browser, **When** auth runs, **Then** the existing cookie/CSRF behavior remains
   byte-for-byte observable and the browser never uses mobile token endpoints or native storage.

### User Story 2 - Use The Shared Product As An Android App (Priority: P1)

As a phone user, I can use all existing shared routes inside a branded native shell with Android
navigation and keyboard behavior that feels predictable.

**Independent Test**: Build/sync the bundled web assets, open core authenticated routes at 390×844,
exercise hardware-back semantics and a keyboard-heavy form, then validate the native configuration.

**Acceptance Scenarios**:

1. **Given** a successful mobile build, **When** Capacitor syncs, **Then** the Android project receives
   the current production web bundle and all declared native plugins with no remote server URL.
2. **Given** navigation away from the root, **When** Android Back is pressed, **Then** an open surface
   closes first or Vue returns one route; at a root the app minimizes.
3. **Given** an input near the viewport bottom, **When** the Android keyboard opens, **Then** the focused
   input and submit feedback remain reachable with no horizontal overflow or covered fixed navigation.
4. **Given** light/dark/system preferences, **When** the app starts, **Then** the existing no-flash theme
   and EN/RU/UK locale behavior remain authoritative from the authenticated profile.
5. **Given** the committed Android project, **When** a developer follows the documented commands with
   supported Android tooling, **Then** debug and release Gradle tasks have deterministic inputs and no
   signing secret is committed.
6. **Given** the launcher or splash screen, **When** Android renders adaptive and Android-12+ variants,
   **Then** SelfHandler branding is recognizable on supported background modes.

### User Story 3 - Receive A Native Presentation Of Inbox Events (Priority: P2)

As an Android user, I can opt into system notifications for newly synchronized SelfHandler reminders,
without duplicating their server state or requiring FCM.

**Independent Test**: Grant notification permission, seed one unread in-app event, synchronize twice,
observe one native presentation and `android_local` acknowledgment, then tap it and land on the safe
action with the inbox event read.

**Acceptance Scenarios**:

1. **Given** Android notification permission is not decided, **When** the user explicitly enables native
   alerts from Notifications, **Then** the OS prompt is requested once and the current result is shown.
2. **Given** permission is granted and a delivered unread event lacks `android_local`, **When** the app
   synchronizes or resumes, **Then** exactly one local notification is presented with the persisted
   localized title/body and the server channel is acknowledged.
3. **Given** permission is denied/unavailable, **When** synchronization runs, **Then** in-app delivery and
   unread count continue unchanged and no false channel acknowledgment is written.
4. **Given** the same event is synchronized repeatedly or an acknowledgment retries, **When** native
   pending/delivered ids or server channels already contain it, **Then** no duplicate presentation occurs.
5. **Given** a native notification is tapped, **When** its relative action is valid, **Then** the shared
   router opens it and marks the event read; invalid/missing actions open `/notifications`.
6. **Given** the app was stopped before an event existed, **When** no push transport wakes it, **Then**
   no background-delivery claim is made; the event is presented only after the next app synchronization.

### User Story 4 - Reproduce And Diagnose Mobile Builds (Priority: P3)

As a maintainer, I can configure, validate, sync, and build the Android shell without editing source
files or committing machine-specific secrets.

**Independent Test**: Run the mobile validation/build/sync commands with a sample HTTPS origin, inspect
the generated config and native resource contract, and exercise expected failures for missing/unsafe
configuration and missing Android toolchain.

**Acceptance Scenarios**:

1. **Given** a documented environment template, **When** a maintainer configures a target, **Then** only
   public API origin/app metadata enter the bundle; signing properties and keys remain ignored.
2. **Given** Node 22+ but no Android SDK, **When** validation runs, **Then** JS/config/native-source gates
   pass and the unavailable Gradle/device gates are reported explicitly.
3. **Given** supported Android tooling, **When** debug/release commands run, **Then** outputs land only in
   ignored Gradle build directories and the debug APK is sideloadable.

## Functional Requirements

### Packaging And Platform Boundary

- **FR-001**: `apps/mobile` MUST own a Capacitor 8 Android shell around `apps/web/dist`; it MUST not fork
  the Vue product or load remote application HTML in production.
- **FR-002**: Mobile packaging MUST require one normalized HTTPS API origin and MUST reject credentials,
  paths, fragments, query strings, localhost, or cleartext production values.
- **FR-003**: Capacitor config, Android project, Gradle wrapper, plugins, branded icon/splash resources,
  and deterministic build/sync/validation commands MUST be versioned; APK/AAB and secrets MUST not be.
- **FR-004**: The Android application id MUST be stable, and debug/release version metadata MUST be
  explicit and reproducible without deployment changes.
- **FR-005**: Android Back MUST close a transient surface first, traverse client history second, and
  minimize at an application root.
- **FR-006**: Keyboard/system-bar/viewport integration MUST keep focused controls, feedback, and fixed
  navigation reachable at 390×844 without horizontal overflow.

### Mobile Authentication And Transport

- **FR-007**: Browser cookie sessions and CSRF handling MUST remain unchanged; native mode MUST not use
  those cookies as an authentication credential.
- **FR-008**: Android sign-in MUST accept normalized email, password, and bounded device name, reuse the
  generic credential/rate-limit feedback boundary, and create one scoped 30-day Sanctum token.
- **FR-009**: Mobile tokens MUST be hashed at rest on the server, returned only at creation, owner-bound,
  individually revocable, and never serialized by user/profile/domain responses.
- **FR-010**: The plaintext token MUST exist only transiently in JS memory and an Android Keystore-backed
  vault; no Web Storage, cookie, URL, log, backup, source, or build artifact may contain it.
- **FR-011**: Native API requests MUST use the configured HTTPS origin and Capacitor native HTTP with the
  vaulted Bearer token; browser requests MUST continue using relative same-origin Fetch.
- **FR-012**: Native session restore/logout/401 expiry MUST read, revoke when possible, clear, and forget
  the current token deterministically without affecting other device or browser sessions.
- **FR-013**: Native account registration MUST be unavailable with localized guidance rather than route
  users through the incompatible cookie registration flow.
- **FR-014**: Mobile session endpoints MUST be OpenAPI-documented, localized, throttled, and protected
  against cookie-only callers, invalid bearer tokens, cross-account access, and token leakage.

### Native Notification Presentation

- **FR-015**: Android notification permission MUST be requested only from an explicit user action and
  its granted/denied/prompt state MUST be visible and localized.
- **FR-016**: The native adapter MUST consider only delivered unread owner-scoped events whose successful
  channels do not contain `android_local`; source/domain state remains untouched.
- **FR-017**: Native ids MUST deterministically map server notification ids into Android's signed 32-bit
  range and deduplicate against pending/delivered native notifications before scheduling.
- **FR-018**: Successful native presentation MUST idempotently append `android_local` through an
  authenticated API operation; failure/denial MUST not acknowledge it.
- **FR-019**: A native tap MUST validate the stored relative Planner/inbox action, mark the event read
  best-effort, and route without accepting arbitrary schemes/hosts.
- **FR-020**: The adapter MUST explicitly remain resume/synchronization based; it MUST not claim stopped-
  app delivery, exact alarms, FCM, Web Push, or a second authoritative notification state.

### Shared Quality And Localisation

- **FR-021**: Existing shared routes, session expiry feedback, preference hydration, and product behavior
  MUST remain supported in browser and native modes from the same Vue source.
- **FR-022**: Every new user-visible string and accessibility label MUST ship in English, Russian, and
  Ukrainian with exact catalog parity and no native hardcoded product copy.
- **FR-023**: Automated tests MUST cover schema/token security/API contracts, transport/vault isolation,
  back/keyboard behavior, native notification dedupe/tap, config validation, and browser regressions.
- **FR-024**: Documentation MUST distinguish verified gates from Android-toolchain-blocked gates and give
  exact Android Studio/JDK/SDK, signing, build, install, and diagnostic commands.

## Localisation Requirements

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: Android sign-in/account-creation guidance, native credential/vault errors,
  notification permission enable/status/denied feedback, native presentation accessibility labels,
  and the 012 changelog entry.
- **Formatting**: The API continues to use request/profile locale. Device names and user-authored content
  are never translated. Delivered notification copy remains the persisted 011 event copy.
- **Native resources**: Android app label remains the product name `SelfHandler`; translated runtime UI
  stays in Vue catalogs so native and browser surfaces cannot drift.
- **Gate**: Existing parity/blank/unknown/unused/hardcoded-copy checks plus backend locale/error tests and
  native-mode browser tests.

## Key Entities

- **MobileAccessToken**: Sanctum personal access token named for one Android device, `mobile` scoped,
  hashed server-side, last-used tracked, and expiring after 30 days.
- **MobileCredentialVault**: Android-only Capacitor plugin that encrypts/decrypts the token with an
  Android Keystore AES key and non-backed-up private preferences; no web fallback exists.
- **NativeTransport**: The absolute-HTTPS Capacitor HTTP branch that injects a vaulted Bearer token and
  normalizes responses into the existing `ApiError` contract.
- **AndroidLocalPresenter**: Permission-aware, idempotent adapter from delivered unread inbox events to
  Android Local Notifications, with safe tap routing and server channel acknowledgment.
- **AndroidShell**: Versioned Capacitor/Gradle project that embeds the shared Vue production bundle and
  registers the platform integrations.

## Success Criteria *(mandatory)*

- **SC-001**: Static credential scans and native-mode tests find zero auth tokens/passwords in
  localStorage, sessionStorage, cookies, URLs, logs, committed files, or build configuration.
- **SC-002**: Valid mobile login/restore/protected request/logout works end-to-end; after logout or 401,
  the old token receives 401 and the vault is empty.
- **SC-003**: Invalid login responses remain generic and rate-limited in all three locales; one account's
  token cannot read or mutate another account's records.
- **SC-004**: Browser auth/security and complete Laravel suites remain green after mobile tokens are added.
- **SC-005**: Mobile build + Capacitor sync copies the same 108+ module Vue product, includes every
  declared Android plugin, and contains no production `server.url`.
- **SC-006**: Hardware-back and keyboard acceptance checks pass at 390×844 with zero horizontal overflow
  and no covered focused control or submit feedback.
- **SC-007**: Two synchronizations of one unread notification produce exactly one native presentation
  and one idempotent `android_local` acknowledgment; denial produces neither.
- **SC-008**: A native notification tap opens only an allow-listed relative route and updates the inbox
  unread state without changing the source domain fact.
- **SC-009**: EN/RU/UK catalogs remain in exact parity and all new Android states are understandable by
  text without relying on color or icon alone.
- **SC-010**: In a supported Android environment, documented debug/release Gradle commands are sufficient
  to produce ignored APK outputs; when unavailable, every missing prerequisite is reported precisely.

## Explicitly Out Of Scope

FCM, Web Push, always-on background delivery, exact alarms, offline read/write synchronization, conflict
resolution, Play Store publication, AAB distribution, iOS, biometric lock, password reset, mobile
registration, device-management UI, OAuth/deep links, camera/gallery/files, remote code/live updates,
deployment configuration, server rollout, and edits to protected deployment paths.
