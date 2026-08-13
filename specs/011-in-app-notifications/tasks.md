# Tasks: In-App Notifications

**Input**: Design documents from `/specs/011-in-app-notifications/`

**Prerequisites**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/openapi.yaml`

**Tests**: Required by Constitution VI/VII. Focused tests are written and observed failing before the
implementation they protect; the complete gate closes the feature.

## Phase 1: Spec Kit and Failing Foundations

- [X] T001 Point `.specify/feature.json` at 011 and validate specification, clarification decisions,
  checklist, research, data model, OpenAPI, quickstart, and plan.
- [X] T002 [P] Add failing additive schema/default/relationship/identity/index tests in
  `apps/api/tests/Feature/Notifications/NotificationSchemaTest.php` (FR-001-FR-003, FR-015).
- [X] T003 [P] Add failing pure same-day, cross-midnight, profile-zone, and DST quiet-time tests in
  `apps/api/tests/Unit/Notifications/QuietHoursTest.php` (FR-006, FR-017, SC-003).
- [X] T004 [P] Add failing inbox/settings/action/validation/ownership tests in
  `apps/api/tests/Feature/Notifications/NotificationApiTest.php` (FR-015-FR-022, FR-026, SC-004/007).
- [X] T005 [P] Add failing source/digest/idempotency/domain-immutability tests in
  `apps/api/tests/Feature/Notifications/NotificationSourceTest.php` (FR-007-FR-011, FR-014, SC-001/005).
- [X] T006 [P] Add failing dispatcher/quiet/snooze/escalation/locale tests in
  `apps/api/tests/Feature/Notifications/NotificationDeliveryTest.php` (FR-004-FR-006, FR-012-FR-020,
  SC-002/003/006/008).
- [X] T007 [P] Add failing command/unique-job/schedule registration tests in
  `apps/api/tests/Feature/Notifications/NotificationScheduleTest.php` (FR-005).
- [X] T008 [P] Add failing OpenAPI route/schema drift checks in
  `apps/api/tests/Feature/Notifications/NotificationOpenApiContractTest.php` (FR-021-FR-022, SC-009).
- [X] T009 [P] Add failing desktop/mobile inbox/badge/triage/action journeys in
  `apps/web/e2e/notifications/notifications-inbox.spec.ts` (FR-021-FR-025).
- [X] T010 [P] Add failing EN/RU/UK settings, quiet/digest/category, keyboard and exact 390×844 coverage
  in `apps/web/e2e/notifications/notifications-settings.spec.ts` (FR-015-FR-017, FR-023-FR-025).

## Phase 2: Persistence and Shared Contracts

- [X] T011 Add `notification_settings` and `notifications` in the additive
  `apps/api/database/migrations/2026_08_13_120000_create_notifications.php` migration with owner FKs,
  finite defaults, composite uniqueness, bounded indexes, and portable rollback (FR-001-FR-003/015).
- [X] T012 Implement `NotificationSettings`, `InAppNotification`, status/type/category constants,
  casts, relationships, owner helpers, and `User::ensureNotificationSettings()` defaults (FR-001-FR-003/015).
- [X] T013 Add current source policies/defaults to `apps/api/config/selfhandler.php` and channel/content
  keys to EN/RU/UK Laravel notification catalogs (FR-004, FR-012, localisation).
- [X] T014 Define `NotificationChannel`, `ChannelRegistry`, and the first `InAppChannel`, registered
  through the application provider without a second delivery-history table (FR-004).
- [X] T015 Pass T002 schema/default/identity coverage on both portable migration assumptions and the
  repository's identifier-length guard.

## Phase 3: Source Processing and Delivery

- [X] T016 Implement `QuietHours` with local interval resolution and UTC deferral, then pass T003
  including cross-midnight and DST cases (FR-006/017).
- [X] T017 Implement direct planned-occurrence discovery/update/re-arm/closure in
  `NotificationSourceSynchronizer` without source writes (FR-007-FR-008/014).
- [X] T018 Extend the synchronizer for high-priority due Storage tasks and cancellation/action rules
  without changing Storage state (FR-007/009/014).
- [X] T019 Implement `DailyDigestBuilder` with mutually exclusive minor-source counts, per-category
  settings, local-date identity, and empty/disabled no-op behavior (FR-010-FR-011).
- [X] T020 Implement `NotificationEscalator` with configured routine interval/maximum, family dismissal,
  pending/overdue/category checks, and unique repeat identities (FR-003/012-FR-013).
- [X] T021 Implement `NotificationDispatcher` for due scheduled/snoozed rows, current settings/locale,
  quiet deferral, channel resolution, delivered copy, and fresh escalation deadline (FR-004/006/017/020).
- [X] T022 Implement unique queued `ProcessUserNotifications`, the `notifications:process` command with
  bounded user dispatch and `--user`/`--sync`, plus per-minute scheduler registration (FR-005).
- [X] T023 Pass T005-T007 source/digest/delivery/locale/job coverage and prove repeat runs do not mutate
  domain rows or duplicate notification identities (SC-001-SC-008).

## Phase 4: Authenticated API

- [X] T024 Implement strict complete `ReplaceNotificationSettingsRequest`, including unknown-field,
  time-shape, category completeness, and distinct enabled quiet endpoints (FR-015-FR-017).
- [X] T025 Implement settings GET/PUT with recoverable defaults, atomic replacement, normalized response,
  options, and authenticated routes (FR-015-FR-017/022/026).
- [X] T026 Implement notification list with view validation, 50-row cap, visible statuses, newest-first
  ordering, exact owner unread count, and finite snooze options (FR-021/026).
- [X] T027 Implement owner-invisible/idempotent read and dismiss actions; dismissal cancels pending
  source-family repeats without touching the source (FR-018-FR-020/026).
- [X] T028 Implement finite-duration server-timed snooze and strict transition feedback (FR-018-FR-020/026).
- [X] T029 Pass T004/T008 API/OpenAPI/ownership/localised-feedback coverage (SC-004/007/009).

## Phase 5: Complete Localised Interface

- [X] T030 Add typed notification/settings/payload contracts and API methods in
  `apps/web/src/api/{types,client}.ts` (FR-021-FR-024).
- [X] T031 Implement `apps/web/src/notifications/store.ts` with session-safe mount/action/focus refresh,
  60-second polling, stale-response protection, and cleanup (FR-024).
- [X] T032 Add complete canonical `notifications.*` and changelog keys to EN/RU/UK catalogs, keeping
  exact parity and all delivered-state/action/accessibility labels (localisation surface).
- [X] T033 Add `/notifications` route and global desktop/mobile shell destination with exact unread badge
  and accessible names in `router.ts` and `AppShell.vue` (FR-024-FR-025).
- [X] T034 Build `NotificationsView.vue` active/history list, local-time metadata, action/read/dismiss/
  snooze flows, explicit async states, and safe relative Planner navigation (FR-018-FR-025).
- [X] T035 Build complete settings within the view using shared controls, atomic save/rollback, quiet
  precedence help, digest/category controls, keyboard flow, and 390×844 layout (FR-015-FR-017/023/025).
- [X] T036 Add the 011 changelog entry and focused responsive styles without hardcoded product copy.
- [X] T037 Pass T009-T010 in desktop/mobile plus `check:i18n`, typecheck, and build (FR-023-FR-025,
  localisation, SC-008/010).

## Phase 6: Reconciliation and Atomic Closure

- [X] T038 Resolve implementation-time notification design open questions in
  `docs/design/notifications.md`, mark 011 complete in `docs/design/delivery-roadmap.md`, and reconcile
  README/changelog/spec artifacts without expanding scope.
- [X] T039 Append post-implementation evidence to `analysis.md`, mark completed tasks only with evidence,
  and run the quickstart/API contract parse/route comparison.
- [X] T040 Run the full feature gate: Laravel, Pint, i18n, Vue typecheck/build, all Playwright projects,
  `git diff --check`, repository status, and protected-path/handoff audit (SC-010).
- [X] T041 Update `C:\Code\memory\projects\selfhandler\overview.md`, make one atomic 011 commit without
  the preserved untracked handoff, push `master`, and verify local HEAD equals `origin/master`.

## Dependencies and Execution Order

- T001 completes the active delivery contract.
- T002-T010 are failing tests and may be authored independently before application implementation.
- T011-T015 establish persistence/contracts before source or endpoint work.
- T016-T023 implement the processing pipeline in dependency order.
- T024-T029 implement the API on the proven model/services.
- T030-T037 implement the typed/localised user surface after the API contract is stable.
- T038-T041 require the complete implementation.

## Traceability

| Requirement area | Tasks |
|---|---|
| Delivery boundary, identity, jobs, time (FR-001-FR-007) | T002-T003, T005-T008, T011-T018, T021-T023 |
| Direct sources, digest, escalation, closure (FR-008-FR-014) | T005-T006, T013, T017-T023 |
| Settings, quiet hours, triage/snooze (FR-015-FR-020) | T003-T006, T010-T012, T016, T021, T024-T028, T035 |
| API, interface, ownership, responsive (FR-021-FR-026) | T004, T008-T010, T025-T037 |
| Localisation and feature closure | T006, T010, T013, T029, T032-T041 |
