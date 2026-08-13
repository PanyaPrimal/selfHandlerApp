# Tasks: Android Capacitor Shell

**Input**: Design documents from `/specs/012-android-capacitor-shell/`

**Prerequisites**: `spec.md`, checklist, `research.md`, `data-model.md`, `contracts/openapi.yaml`,
`plan.md`, `quickstart.md`, and pre-implementation `analysis.md`.

**Tests**: Required by Constitution VI/VII. Focused backend/unit/browser tests are written and observed
failing before the application/native implementation they protect. Android compilation/device gates are
conditional on the external toolchain and must never be reported as passed when unavailable.

## Phase 1: Complete Spec Kit Before Code

- [X] T001 Point `.specify/feature.json` at 012 and write the feature specification with prioritized,
  independently testable Android/auth/notification/build journeys.
- [X] T002 Complete `checklists/requirements.md` and resolve packaging, auth, registration, expiry,
  transport, Back, keyboard, native notification, asset, and external-tooling clarifications.
- [X] T003 Research current official Capacitor/Laravel guidance and record adopted/rejected decisions in
  `research.md` without using prototype behavior as requirements.
- [X] T004 Define Sanctum token, Android vault, local-presentation descriptor, ownership, and state
  transitions in `data-model.md`.
- [X] T005 Define the four-operation mobile OpenAPI 3.1 contract in `contracts/openapi.yaml`.
- [X] T006 Produce `plan.md` with technical/localisation context, architecture gates, constitution check,
  complexity tracking, and sequential implementation phases.
- [X] T007 Produce traceable `tasks.md` and `quickstart.md`, including honest external Android blockers.
- [X] T008 Run the pre-implementation cross-artifact analysis and resolve all critical/high findings.

## Phase 2: Failing Server And Shared-Client Contracts

- [X] T009 [P] Add failing additive standard-token schema, expiry/index, `HasApiTokens`, and user deletion
  tests in `apps/api/tests/Feature/Mobile/MobileTokenSchemaTest.php` (FR-008-FR-010).
- [X] T010 [P] Add failing create/current/delete session tests for normalization, safe resource shape,
  expiry, one-token-only response, current-token revocation, and ownership in
  `MobileSessionApiTest.php` (FR-008-FR-014, SC-002/003).
- [X] T011 [P] Add failing mobile auth security/locale/rate-limit tests proving generic feedback,
  bearer-only operations, invalid/expired/other-token refusal, no web-session side effect, and unchanged
  cookie/CSRF browser behavior in `MobileSessionSecurityTest.php` (FR-007-FR-014, SC-001/003/004).
- [X] T012 [P] Add failing notification presentation acknowledgment/state/ownership/idempotency tests and
  OpenAPI↔route/vocabulary drift checks in `MobileNotificationApiTest.php` and
  `MobileOpenApiContractTest.php` (FR-014/016/018, SC-007/008).
- [X] T013 [P] Install/configure Vitest and add failing platform URL validation, vault no-web-fallback,
  native transport normalization/header/401, and browser-transport isolation tests (FR-002/007/010-012).
- [X] T014 [P] Add failing unit tests for Back ordering/cleanup, keyboard CSS lifecycle, native id
  mapping/collision, permission branches, pending/delivered dedupe, acknowledgment, and safe tap routing
  (FR-005/006/015-FR-020).
- [X] T015 [P] Add failing Playwright native-mode existing-account login guidance, expired-session
  redirect, EN/RU/UK, and 390×844 keyboard/no-overflow coverage under `apps/web/e2e/mobile/` (FR-006/013/021/022).
- [X] T016 [P] Add failing mobile-project validator fixtures for missing/unsafe origins, version/plugin/
  config/manifest/vault/resource/signing/ignore rules, secret scans, and synchronized-asset evidence
  (FR-001-FR-004/010/023/024).

## Phase 3: Server Mobile Session And Presentation Boundary

- [X] T017 Add the standard additive `personal_access_tokens` migration, `HasApiTokens` on `User`, and
  the explicit `mobile` ability/30-day policy constants (FR-008-FR-010).
- [X] T018 Implement `MobileLoginRequest` with strict fields, normalization, generic credential check,
  locale-aware five-attempt throttle, and zero web-guard/session mutation (FR-008/014).
- [X] T019 Implement `MobileSessionController@store` to issue one named expiring scoped token and return
  its plaintext exactly once with the safe existing `UserResource` (FR-008-FR-010).
- [X] T020 Implement `RequireMobileToken` middleware plus current-session inspection and current-token-only
  revoke operations; register aliases/routes without changing browser auth routes (FR-007/009/012/014).
- [X] T021 Add `android_local` notification vocabulary and implement owner-scoped, sent-only,
  idempotent presentation acknowledgment without domain/read mutation (FR-016/018).
- [X] T022 Complete EN/RU/UK backend mobile feedback catalogs and route/OpenAPI contract alignment
  (FR-014/022).
- [X] T023 Pass T009-T012 focused suites, reverse-test invalid/expired/foreign/duplicate cases, then pass
  the complete Laravel suite and Pint (SC-002-FR-004/007/008).

## Phase 4: Shared Vue Native Boundary

- [X] T024 Add exact compatible Capacitor core/app/device/keyboard/local-notifications dependencies to
  the web compile graph and Vitest scripts/config without changing the web production entry (FR-001/023).
- [X] T025 Implement `mobile/platform.ts` with native detection and strict normalized HTTPS origin
  validation; production mobile build must fail closed (FR-002).
- [X] T026 Implement the typed `MobileCredentialVault` bridge with no web implementation/fallback and a
  just-in-time read/write/clear API that never exposes tokens through app state/logging (FR-010).
- [X] T027 Implement explicit `NativeTransport`; refactor `api/http.ts` behind a normalized response seam
  while preserving the browser Fetch/CSRF branch and errors exactly (FR-007/011/012/021).
- [X] T028 Route login/current/logout through mobile session operations only on native, derive a bounded
  Device plugin name, revoke on vault-write failure, and clear on authoritative 401/logout (FR-008-FR-013).
- [X] T029 Make native `/register` safely redirect and replace create-account UI with localized existing-
  account/browser guidance while leaving browser registration untouched (FR-013/021/022).
- [X] T030 Implement `mobile/android-shell.ts` listener ownership, transient-surface cancellable Back,
  router history/minimize roots, resume refresh, and complete cleanup (FR-005/021).
- [X] T031 Wire the shared transient dialog/popover boundary to consume native Back before navigation;
  prove Escape/keyboard browser behavior remains unchanged (FR-005/021).
- [X] T032 Implement Keyboard show/hide CSS state, focused-control visibility, dynamic viewport/safe-area
  layout, and fixed-nav protection at 390×844 (FR-006).
- [X] T033 Implement `AndroidLocalPresenter`: explicit permission/status, channel creation, stable id/
  collision checks, pending/delivered dedupe, immediate presentation, and ack-after-success (FR-015-FR-020).
- [X] T034 Wire notification-store/resume presentation plus safe action tap/read behavior and listener
  cleanup; in-app behavior must survive every native failure (FR-016-FR-021).
- [X] T035 Add complete `mobile.*` and changelog EN/RU/UK catalogs plus native Notifications permission
  controls/accessibility feedback with exact parity (FR-015/022).
- [X] T036 Pass T013-T015 unit/Playwright flows, i18n, typecheck, and production web build; run focused web
  auth/notification/navigation regressions (SC-001/004/006-FR-009).

## Phase 5: Versioned Capacitor Android Project

- [X] T037 Replace the mobile placeholder with package/lock, dynamic Capacitor config, ignored env
  template, root/mobile build/sync/validate scripts, and strict public-origin injection (FR-001-FR-004).
- [X] T038 Build the shared Vue output, add/sync the Capacitor Android platform, and commit the generated
  project/Gradle wrapper with no `server.url`, remote content, or generated APK (FR-001/003/005).
- [X] T039 Implement/register `MobileCredentialVaultPlugin.java` using Android Keystore AES/GCM and
  private backup-disabled preferences; add deterministic invalidation/corruption cleanup (FR-010).
- [X] T040 Configure manifest/activity for internet, `adjustResize`, backup exclusion, stable application
  id/label/version, and only permissions required by resume-based local notifications (FR-003-FR-006/015/020).
- [X] T041 Create source icon/splash art and generate/verify adaptive, legacy, Android-12+ splash, and
  monochrome/status resources using the official asset workflow (FR-003, SC-005).
- [X] T042 Add ignored release signing properties contract/example and deterministic debug/release Gradle
  commands without committing keystores/passwords/artifacts (FR-003/004/010/024).
- [X] T043 Implement the Node validator/secret audit and pass T016, mobile build, `cap sync android`,
  plugin listing, synchronized bundle hash/config/resource checks (SC-001/005/010).

## Phase 6: Documentation, Full Gates, And Atomic Closure

- [X] T044 Update `apps/mobile/README.md`, root README/scripts, architecture, notification resolution,
  changelog, and roadmap 012 status; document verified versus Android-toolchain-blocked evidence.
- [X] T045 Append post-implementation traceability/evidence to `analysis.md`, parse OpenAPI, compare all
  mobile operations/routes, verify 48/48 tasks, and leave no implicit follow-up.
- [X] T046 Run full Laravel/Pint, Vitest, i18n, typecheck/build, affected and complete split Playwright
  desktop/mobile projects, mobile build/sync/validator, `git diff --check`, and secret audit.
- [X] T047 Attempt `gradlew assembleDebug` and `assembleRelease` only if the detected JDK/SDK exist;
  otherwise record missing tools and exact post-install commands without marking native compilation pass.
- [X] T048 Audit protected deployment paths and preserved untracked handoff, update canonical workspace
  memory, create one atomic 012 commit without co-author trailers, push `master`, and verify HEAD equals
  `origin/master`.

## Dependencies And Execution Order

- T001-T008 complete before every application/native change.
- T009-T016 establish failing contracts and may be authored in parallel conceptually, but this execution
  remains on one working tree.
- T017-T023 prove the server credential/ack boundary before the client can depend on it.
- T024-T036 add the platform seam and user behavior before native packaging copies the bundle.
- T037-T043 create and validate the Android project from the proven shared build.
- T044-T048 reconcile truth, run all gates, document the external tool boundary, and close atomically.

## Traceability

| Requirement area | Tasks |
|---|---|
| Packaging/config/assets/build (FR-001-FR-004/024) | T006, T016, T037-T047 |
| Back/keyboard/shared product (FR-005/006/021) | T014-T015, T030-T032, T036, T046 |
| Mobile auth/vault/transport (FR-007-FR-014) | T009-T013, T017-T029, T036, T039, T043, T046 |
| Local presentation (FR-015-FR-020) | T012, T014, T021-T023, T033-T035, T040, T043, T046 |
| Localisation/quality/security (FR-022/023, SC-001-SC-010) | T011-T016, T022-T024, T035-T048 |
