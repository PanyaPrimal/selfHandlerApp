# Pre-Implementation Analysis: Android Capacitor Shell

**Date**: 2026-08-13

**Artifacts checked**: constitution 1.2.0, specification, requirements checklist, research, data model,
OpenAPI contract, plan, quickstart, and tasks.

## Result

No critical or high-severity inconsistency remains. Application implementation may begin only after
the failing server/unit/browser/native-validator contracts in T009-T016 are in place.

## Constitution Coverage

| Principle | Evidence | Result |
|---|---|---|
| Specifications before implementation | 012 specification through task list complete before code | Pass |
| Design/delivery truth | Roadmap, architecture, 003 auth, 011 notifications linked and reconciled | Pass |
| Thin vertical slice | Android only; existing accounts; one token/vault/transport/presenter | Pass |
| Deterministic core | Token lifecycle, ids, routing, permission and retry rules explicit | Pass |
| Ownership/privacy | Hashed owner tokens, bearer-only operations, Keystore/no backup/HTTPS | Pass |
| Contracts/tests together | OpenAPI/backend/TS/unit/browser/native/config layers mapped | Pass |
| Complete localisation | Vue + Laravel EN/RU/UK surface and automated gates named | Pass |

## Cross-Artifact Traceability

- FR-001–FR-006 map to package/config/validator, Back/keyboard, Android project, assets, and build tasks.
- FR-007–FR-014 map to token schema/session/OpenAPI, vault/transport/auth routing, and security tests.
- FR-015–FR-020 map to permission/dedupe/presenter/ack/tap behavior and native/source tests.
- FR-021–FR-024 map to shared regressions, localization, full gates, docs, and external-tool reporting.
- SC-001–SC-010 each has at least one automated task; native compilation criteria have a conditional
  task plus explicit blocked-evidence rules rather than a false pass.
- The OpenAPI operations match the only new server entry points; domain APIs are reused unchanged.
- The data-model token/presentation states match spec acceptance and the implementation sequence.

## Resolved Findings

1. **Cross-origin cookie temptation**: rejected in research/plan. Android token auth is separate and the
   proven browser session/CSRF path remains untouched.
2. **Token storage gap**: Preferences/Web Storage are prohibited. A current consumer justifies a narrow
   custom Android Keystore plugin; its native source/config is validator-covered until compilation exists.
3. **Mobile registration scope creep**: existing-account sign-in is the roadmap outcome; native register
   is explicitly unavailable with guidance, while browser invite registration remains unchanged.
4. **Local notification overclaim**: the adapter mirrors delivered inbox events after sync/resume. It is
   not a server channel capable of waking a stopped app and does not require exact-alarm permission.
5. **Duplicate native/server state**: server notification remains authoritative; `android_local` is an
   idempotent successful-channel acknowledgment and native descriptors are presentation artifacts.
6. **Native id overflow/collision**: signed-32-bit mapping and original-id comparison are required before
   scheduling; collision is a recoverable skip, never silent overwrite.
7. **Logout partial failure**: 401 is already revoked and clears; network failure preserves the token for
   retry rather than claiming server revocation. Vault-write failure after issuance revokes best-effort.
8. **Build evidence gap**: Node/build/sync/static gates are separable from Gradle/device gates. The
   current missing JDK/SDK/adb is documented and does not block useful implementation work.

## Medium/Low Risks To Watch During Implementation

- `auth:sanctum` accepts both cookie and token credentials; token-specific routes must enforce the
  `mobile` current-token boundary without breaking shared domain routes.
- The HTTP refactor is a high-blast-radius seam. Preserve byte-equivalent browser credentials/CSRF/419/
  401/retry/error behavior and run complete browser + backend suites.
- Login token issuance followed by bridge failure must not leak an orphaned active token.
- Native event listeners must be installed once and removed cleanly across hot reload/tests/unmount.
- Local notification permission must never be prompted at startup or falsely acknowledged on denial.
- Generated Capacitor files may include machine paths or build outputs; validate and stage explicitly.
- Android assets must retain legibility under adaptive masks and Android 12 splash constraints.

## External Evidence Boundary

Verified before implementation: Node `v23.6.1`, npm `10.9.2`, current Capacitor core/CLI/Android `8.5.0`,
official Node 22+ and Android Studio/SDK requirements.

Unavailable now: `java`, Android Studio/JDK, Android SDK roots, `adb`, and Gradle. Consequently no APK,
emulator, real-device, or release-signing result may be asserted in 012 unless those checks later become
available. T047 owns the final detection/attempt/report.

## Authorization To Implement

The checklist is complete, no NEEDS CLARIFICATION marker or constitution exception remains, and the
complexity tracking has one current consumer per seam. Proceed sequentially through `tasks.md` on the
existing `master` branch without deployment or handoff changes.

---

# Post-Implementation Analysis

**Implementation date**: 2026-08-13

## Delivered Boundary

- The additive standard Sanctum token table, `HasApiTokens`, an exact `mobile` ability, an absolute
  30-day expiry, strict localized credential exchange, current-session inspection, and current-token
  revocation are implemented. Browser login/registration/logout still use the original cookie/CSRF
  routes and create no personal access token.
- Token-specific operations require a real current `PersonalAccessToken` with exactly the mobile
  ability. Valid mobile bearer tokens can reuse existing owner-scoped domain APIs; cookie sessions and
  other abilities cannot inspect/revoke a device token or acknowledge Android presentation.
- The shared Vue client selects relative Fetch/CSRF only in browsers and explicit Capacitor native HTTP
  on Android. The API origin fails closed unless it is one path-free, credential-free, public HTTPS
  origin. Native registration redirects to localized existing-account/browser guidance.
- The custom Android plugin encrypts the token with an Android Keystore AES-256 GCM key and persists
  only ciphertext/IV in private, backup-disabled preferences. Corruption/invalidation clears both. No
  Web Storage, cookie, URL, source-log, or Preferences fallback exists.
- Native Back, keyboard/dynamic viewport/safe areas, resume restoration, listener cleanup, opt-in local
  notification permission, native id collision detection, pending/delivered deduplication, safe tap
  routing, and ack-after-presentation are wired around the existing shared UI/inbox.
- `apps/mobile` owns the Capacitor 8.5.0 Android project, Gradle wrapper, exact plugin versions, stable
  `app.selfhandler.mobile` / `0.1.0` metadata, official generated adaptive/legacy/light/dark resources,
  signing template/ignore rules, build/sync commands, and a fail-closed synchronized-bundle/secret
  validator. There is no `server.url`, remote HTML, exact-alarm permission, committed key, APK, or AAB.

## Requirement Traceability

| Requirement group | Implementation evidence | Automated evidence |
|---|---|---|
| FR-001-FR-004 | Capacitor config/project, public-origin build script, resources, version/signing contract | mobile config/native project tests + synchronized validator |
| FR-005-FR-006 | Android shell listener owner, cancellable shared surface Back, keyboard CSS/safe area/dvh | Vitest Back/keyboard tests + 390×844 Playwright flow |
| FR-007-FR-014 | Sanctum session API, exact middleware, vault, native transport, native auth routing | 15 focused Laravel tests + Vitest + Android login/vault/expiry Playwright |
| FR-015-FR-020 | opt-in local presenter, stable id/original-id collision guard, dedupe, ack and safe tap | presenter Vitest + owner/state/idempotency API tests |
| FR-021-FR-024 | shared Vue routes, EN/RU/UK catalogs/changelog, regressions, ignored outputs/secrets | i18n/typecheck/build, split Playwright, audits and repository checks |

Every FR-001-FR-024 and SC-001-SC-010 has implementation plus a repository-owned automated check,
except the SC-009 physical-device portion, which is necessarily in the external evidence boundary
below. FCM, stopped-app wake, exact alarms, offline synchronization, native registration, Play Store,
iOS, camera/gallery, and deployment remain explicitly deferred; none is an implicit follow-up for 012.

## External Android Evidence Boundary

Final detection on 2026-08-13 found `java`, `adb`, `gradle`, and `studio64` missing, with `JAVA_HOME`,
`ANDROID_HOME`, and `ANDROID_SDK_ROOT` unset. Per T047, neither `gradlew assembleDebug` nor
`assembleRelease` was invoked because the prerequisite JDK/SDK pair did not exist. APK generation,
emulator/sideload, release signing, and the manual real-device journey are therefore **blocked external
gates**, not passes and not application defects.

After installing Android Studio 2025.2.1+ with its JDK, API 36 SDK/platform tools, run:

```powershell
$env:SELFHANDLER_MOBILE_API_ORIGIN = 'https://selfhandler.example.test'
npm run sync:android
Set-Location apps/mobile/android
.\gradlew.bat assembleDebug
adb install -r .\app\build\outputs\apk\debug\app-debug.apk
.\gradlew.bat assembleRelease
```

The release command requires an ignored `keystore.properties` copied from the committed example for a
signed result. Exact manual acceptance is in `apps/mobile/README.md` and `quickstart.md`.

## Final Repository-Owned Evidence

- Laravel: `262` tests, `1705` assertions; focused mobile boundary `15/15`; Pint passed.
- Mobile OpenAPI: OpenAPI 3.1 document parsed by the contract suite; `4` documented operations equal
  the `4` registered `/api/mobile` routes; security/channel vocabulary checks passed (`7` assertions).
- Shared client: Vitest `27/27`; i18n `677` keys with exact EN/RU/UK parity across `68` source files;
  typecheck passed; Vite production build transformed `127` modules.
- Browser/native-mode regression: focused auth/notification/navigation/Android matrix `28` passed and
  `4` conditional skips; complete desktop project `70` passed / `8` phone-only skips; complete mobile
  project `77` passed / `1` desktop-only skip.
- Mobile package: Node tests `15/15`; build and Capacitor sync passed with four official Android plugins;
  validator confirmed byte-identical shared assets and digest `f2538513218a`; complete npm audit reports
  zero production or development vulnerabilities after bounded patched transitive overrides.
- Repository: `git diff --check` passed; protected deployment-path changes `0`; private-key/token pattern
  matches `0`; ignored generated bundle/build/signing outputs stayed out of the candidate change; the
  untracked `design_handoff_selfhandler_mvp/` directory remains untouched.

The task ledger contains 48 traced tasks for 24 functional and 10 success requirements. All are closed
by the atomic commit/push procedure; Android compilation/device evidence retains the explicit external
status above rather than being converted into a false pass.
