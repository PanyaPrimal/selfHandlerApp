# Implementation Plan: Android Capacitor Shell

**Feature ID**: `012-android-capacitor-shell`

**Spec**: [spec.md](spec.md) · **Research**: [research.md](research.md) ·
**Data model**: [data-model.md](data-model.md) ·
**Contracts**: [contracts/openapi.yaml](contracts/openapi.yaml) · **Quickstart**: [quickstart.md](quickstart.md)

## Summary

Wrap the existing Vue production build in a versioned Capacitor 8 Android project, introduce a
separate expiring Sanctum mobile-token boundary backed by an Android Keystore plugin and explicit
native HTTPS transport, integrate Android Back/keyboard/lifecycle behavior, and mirror newly
synchronized delivered inbox events through an opt-in Local Notifications presenter.

## Technical Context

**Language/Version**: PHP 8.4 / Laravel 12.61; TypeScript 6 / Vue 3.5; Capacitor 8.5; Android Java.

**Primary Dependencies**: Laravel Sanctum 4, `@capacitor/core`, `@capacitor/android`, `@capacitor/app`,
`@capacitor/device`, `@capacitor/keyboard`, `@capacitor/local-notifications`, `@capacitor/assets`, Vitest.

**Storage**: MySQL 8 / SQLite tests for standard Sanctum token rows; Android Keystore + private,
backup-disabled SharedPreferences for encrypted token bytes; no client domain cache.

**Testing**: PHPUnit feature/schema/OpenAPI tests, Pint, Vitest unit tests, i18n/typecheck/Vite build,
Playwright desktop/mobile/native-mode flows, Capacitor build/sync, Node native-project validator.
Gradle/emulator/device verification is conditional on external Android tooling.

**Target Platform**: Android API 24+ Capacitor shell plus unchanged responsive browser application.

**Performance Goals**: one vault read and one native HTTP call per API request; bounded notification
list already capped at 50; native dedupe linear in at most current pending/delivered plugin results.

**Constraints**: bundled assets, HTTPS-only API origin, no Web Storage credential, 30-day token,
no remote code, no FCM/offline/iOS/deployment changes, preserved untracked handoff.

**Scale/Scope**: One Android application id, one existing account per app session, current shared Vue
routes, one local-presentation channel.

## Localisation Plan

**Message ownership**: Vue `mobile.*` keys own native-only guidance, permission state, and recoverable
bridge feedback. Laravel `messages.php` owns mobile-login/throttle/token/presentation validation in
EN/RU/UK. Android resources expose only the invariant product label.

**Runtime locale**: Native transport sends `Accept-Language` exactly like browser transport. Before
authentication the prepaint/guest locale drives feedback; after restore the profile locale remains
authoritative and synchronizes the existing cache.

**Formatting**: Token expiry is not exposed as a normal user-facing timestamp in 012. Device model/name
is user/device content and remains unchanged. Native notifications reuse already localized persisted
title/body from feature 011.

**Delivery gates**: EN canonical parity/blank/unknown/unused/hardcoded-copy check, backend locale tests,
native-mode UI coverage, typecheck/build, and platform validator.

## Architecture

```text
apps/api/
├── app/Http/Controllers/MobileSessionController.php
├── app/Http/Controllers/MobileNotificationController.php
├── app/Http/Middleware/RequireMobileToken.php
├── app/Http/Requests/MobileLoginRequest.php
├── database/migrations/*_create_personal_access_tokens_table.php
└── tests/Feature/Mobile/

apps/web/src/mobile/
├── platform.ts                 # native detection + validated environment
├── credential-vault.ts         # no web fallback
├── native-transport.ts         # CapacitorHttp adapter
├── android-shell.ts            # Back/lifecycle/keyboard listeners
└── local-notifications.ts      # permission, dedupe, tap, acknowledgment

apps/mobile/
├── capacitor.config.ts
├── package.json / package-lock.json
├── scripts/{build,validate}-mobile.mjs
├── assets/                     # source icon/splash
└── android/
    ├── gradlew / gradle wrapper
    └── app/src/main/java/.../
        ├── MainActivity.java
        └── MobileCredentialVaultPlugin.java
```

The shared API client retains one public `request` contract. Its internal transport is selected once
from Capacitor platform metadata, not URL heuristics. Native auth endpoints are deliberately separate
because their credential lifecycle is different; all existing domain routes keep `auth:sanctum` and
therefore accept a valid mobile token without controller forks.

## Architecture Gate Answers

1. **Owner**: Sanctum owns token persistence; the Android vault owns device encryption; auth/session
   owns login/logout state; notifications owns event/read/channels; the shell owns platform lifecycle.
2. **Inputs**: Credentials enter only the mobile login request; device name is bounded; all domain data
   continues through existing owner-scoped REST APIs.
3. **Time**: Token expiry is a UTC server instant. Local presenter does not schedule future domain time.
4. **Identity**: One random Sanctum token per login/device name; server notification id maps
   deterministically to a native signed-32-bit id with collision checks.
5. **Retry**: Login creates a new token only per accepted submit. A failed vault write revokes it.
   Presentation acknowledgment and channel append are idempotent; native dedupe precedes schedule.
6. **Security**: HTTPS only; server hashes tokens; plaintext uses memory + Keystore only; no backup;
   bearer-only middleware; safe relative actions; remote HTML disabled.
7. **Failure**: Vault/network/permission/config/token-expiry states are explicit. Existing in-app
   functionality survives native presentation failure.
8. **Localisation**: Vue/Laravel catalogs cover all dynamic feedback; delivered copy is reused.
9. **Observability**: No secret logs. Validator reports configuration/tool availability; server rows
   expose name, created/last-used/expiry for future device management but no token.
10. **Deferral**: FCM/exact scheduling/offline/iOS/store distribution/biometrics/device UI/registration.

## Implementation Sequence

### Phase A — Failing contracts

Write Laravel migration/mobile session/presentation/OpenAPI tests, Vitest platform/vault/transport/
navigation/presenter tests, and Playwright native-mode UI/layout journeys. Observe focused failure before
production code.

### Phase B — Server mobile boundary

Add the standard token migration and `HasApiTokens`, login request/controller, bearer-only middleware,
current/revoke routes, presentation acknowledgment, constants/localized feedback, and OpenAPI drift
guard. Re-run focused and full backend gates.

### Phase C — Shared client platform seam

Install matching Capacitor compile dependencies. Add native detection/config/vault/transport, refactor
the current HTTP wrapper without changing its browser branch, route auth operations by platform, clear
native credentials on 401/logout, and hide native registration with complete localization.

### Phase D — Android interaction and notification presentation

Implement lifecycle listener cleanup, transient-surface Back event integration, router/minimize order,
keyboard CSS state, resume refresh, explicit permission UI, local notification dedupe/schedule/ack, and
safe tap navigation/read behavior.

### Phase E — Native project and assets

Create the mobile package/config/build validator, build the shared web output, generate the Capacitor
Android project, add the Keystore Java plugin and manifest security/resize values, produce adaptive/
legacy/splash resources, add ignored signing template, and sync plugins.

### Phase F — Reconciliation and closure

Run backend/Pint, Vitest, i18n, typecheck/build, affected/full Playwright projects, mobile build/sync/
validator, OpenAPI parse/route match, secret/protected-path/status audits. Attempt Android Gradle only if
tooling exists; otherwise record exact blockers and commands. Update design/roadmap/README/changelog,
memory, one atomic commit, push, and verify local/origin equality.

## Constitution Check

| Principle | Evidence | Result |
|---|---|---|
| I | Specification/checklist/research/data/OpenAPI/plan/tasks/analyze precede code | Pass |
| II | Links and resolves roadmap, 003 auth, 011 notifications, architecture | Pass |
| III | One Android shell/auth boundary/presenter; broad native scope deferred | Pass |
| IV | Deterministic platform/auth/presentation behavior | Pass |
| V | Hashed owner token + Keystore/no-backup/HTTPS/relative action | Pass |
| VI | Server/OpenAPI/TS/unit/browser/native/config layers named | Pass |
| VII | Vue/Laravel EN/RU/UK delivery and automated gates | Pass |

## Complexity Tracking

| Deliberate complexity | Why required | Simpler option rejected |
|---|---|---|
| Separate mobile token lifecycle | Bundled app origin cannot safely reuse the proven same-origin cookie/CSRF contract | Cross-site cookie/CSRF/CORS changes would weaken browser security and depend on WebView cookie policy |
| Custom Android Keystore plugin | A persisted 30-day token is necessary for relaunch; no approved free official secure-vault plugin exists | Web Storage/Preferences is plaintext; an unreviewed community plugin adds a deeper supply-chain boundary |
| Explicit native transport adapter | Absolute app-to-API calls need HTTPS/native networking and Bearer injection | Globally patching Fetch would hide platform behavior and risk all browser consumers |

No constitution exception is requested. Each complexity item has one current acceptance consumer and
is limited to `apps/mobile` or a narrow shared seam.
