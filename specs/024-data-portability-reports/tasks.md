# Tasks: Data Portability and Reports

## Phase 1 — Specification and architecture

- [x] T001 Read canonical roadmap/export/attachment/data-convention boundaries and prior Analytics contracts.
- [x] T002 Capture branch/HEAD/remote/dirty baseline and preserve protected/untracked paths.
- [x] T003 Inventory runtime PDF/ZIP capabilities, owner tables, foreign keys, polymorphic links, and UI routes.
- [x] T004 Run pre-change GitNexus queries for Analytics and attachment/file flows.
- [x] T005 Specify report, backup, preflight, empty-target, restore, localization, accessibility, and deferrals.
- [x] T006 Complete research decisions for PDF, archive shape, portable references, exclusions, bounds, and atomicity.
- [x] T007 Complete data model, implementation plan, architecture gates, and constitution checks.
- [x] T008 Publish OpenAPI and JSON schema contracts for all five operations and archive-v1 members.
- [x] T009 Complete requirements checklist and pre-implementation analysis with no critical/high finding.

## Phase 2 — Permanent RED contracts

- [x] T010 Add failing catalog drift test covering every current authoritative owner table and deliberate exclusion.
- [x] T011 Add failing report tests for workspace reuse, CSV BOM/quoting/formula safety, exact evidence, and headers.
- [x] T012 Add failing PDF tests for signature/pages/escaped values/remote disable and EN/RU/UK Cyrillic extraction.
- [x] T013 Add failing backup tests for deterministic portable IDs, closed fields, public and polymorphic references.
- [x] T014 Add failing attachment export tests for separate members, metadata/hash/size, owner isolation, missing bytes.
- [x] T015 Add failing archive-reader attack matrix for paths, duplicates, symlinks, encryption, compression, and bounds.
- [x] T016 Add failing JSON/manifest matrix for closed keys, UTF-8, version, hashes/counts, IDs, and dangling refs.
- [x] T017 Add failing restore-token tests for tamper, expiry, user/digest/version binding, and literal confirmation.
- [x] T018 Add failing round-trip tests for full catalog, Profile/settings, references/cycles, timestamps/decimals/JSON.
- [x] T019 Add failing restore ownership/non-empty-race/DB rollback/file cleanup/public-key/quota tests.
- [x] T020 Add failing API tests for auth, owner privacy, query validation, multipart, response shapes, and no writes.
- [x] T021 Add failing OpenAPI route/ref/schema/auth/content-type closure tests.
- [x] T022 Add failing frontend contract tests for download filenames, upload state reset, validation/result shapes.
- [x] T023 Add failing E2E report and Data journeys for success, ineligible, malformed, retry, keyboard, and mobile.
- [x] T024 Run focused RED suites and record that failures are caused only by absent feature behavior.

## Phase 3 — Backend reports

- [x] T025 Add audited direct Dompdf runtime dependency and lockfile changes.
- [x] T026 Implement report query request reuse with existing Analytics validation/defaults.
- [x] T027 Implement locale-owned report vocabulary, value/date/evidence/reason/comparison formatting in EN/RU/UK.
- [x] T028 Implement safe UTF-8 BOM/RFC4180 CSV renderer with formula neutralization.
- [x] T029 Implement bounded escaped A4 Dompdf renderer with DejaVu Sans and remote access disabled.
- [x] T030 Add report controller/routes with authenticated private no-store/nosniff attachment-safe downloads.
- [x] T031 Prove report unit/API/localization/security tests green.

## Phase 4 — Backup schema and export

- [x] T032 Add bounded portability configuration and stable exclusion/issue code catalogs.
- [x] T033 Implement schema-v1 table catalog with ordered allowed attributes, JSON fields, FK/deferred mappings.
- [x] T034 Implement closed recurring-owner, Finance-source, notification-source, and attachment-parent maps.
- [x] T035 Implement catalog coverage drift guard against migrated schema and global/runtime/singleton exclusions.
- [x] T036 Implement deterministic owner-scoped row snapshot and portable ID/reference assignment without N+1.
- [x] T037 Implement Profile/name/notification-settings snapshot with no credential or target identity fields.
- [x] T038 Implement global Food/Exercise system-key reference export and fail-closed missing-key behavior.
- [x] T039 Implement attachment stream verification and manifest/member creation without JSON blob/path exposure.
- [x] T040 Implement temporary ZIP lifecycle, manifest/member hashes/counts/limits, and cleanup on success/failure.
- [x] T041 Add backup controller/route and private no-store/nosniff filename headers.
- [x] T042 Prove export/catalog/attachment/ownership/privacy/bounds tests green.

## Phase 5 — Validation and restore

- [x] T043 Implement upload request bounds and safe temporary archive lifecycle.
- [x] T044 Implement ZIP central-directory/path/duplicate/type/compression/encryption/member/size validation.
- [x] T045 Implement strict UTF-8 JSON decoding and closed manifest/profile/records schema validation.
- [x] T046 Implement table/attribute/reference/type/count/hash/system-key and polymorphic validation.
- [x] T047 Implement attachment magic/MIME/dimension/size/hash/parent/quota validation without writes.
- [x] T048 Implement empty-target service covering every authoritative table, attachments, and notification rows.
- [x] T049 Implement stateless 10-minute target/digest/schema-bound HMAC restore token.
- [x] T050 Implement read-only validation controller/route with closed issue list and eligible summary.
- [x] T051 Implement restore request confirmation/token/archive checks and locked target emptiness recheck.
- [x] T052 Implement ordered target-owned inserts, new ID mapping, JSON scalars, public references, deferred cycles.
- [x] T053 Implement Profile/name/settings replacement without account authentication identity changes.
- [x] T054 Implement regenerated attachment keys/paths, private writes, metadata creation, and compensating cleanup.
- [x] T055 Add restore controller/route with atomic result counts/digest and no retained upload.
- [x] T056 Prove attack/token/round-trip/ownership/race/rollback/cleanup/API tests green on SQLite.
- [x] T057 Prove catalog SQL/constraints/order compatible with intended MySQL behavior and identifier limits.

## Phase 6 — Web, localization, and Android

- [x] T058 Add TypeScript report/validation/result types and blob/multipart API client methods.
- [x] T059 Add Analytics CSV/PDF actions using current applied query, safe blob download, busy/error/retry states.
- [x] T060 Add `/settings/data` route and desktop/mobile navigation destination.
- [x] T061 Implement backup download panel with privacy/exclusion explanation and progress/error/retry.
- [x] T062 Implement archive chooser and state reset on every file change.
- [x] T063 Implement validation summary with schema/date/count/bytes/exclusions/issues and eligibility.
- [x] T064 Implement exact confirmation and restore action enabled only for current valid eligible token/file.
- [x] T065 Implement restore loading/failure/success/status announcements and stale-state clearing.
- [x] T066 Add responsive light/dark Data/report styles, visible focus, 44 px targets, long-name wrapping, no overflow.
- [x] T067 Add every new visible/ARIA/validation/changelog string in EN/RU/UK and use locale formatters.
- [x] T068 Add 024 changelog entry and relevant Analytics/Data links in all locales.
- [x] T069 Synchronize the existing web bundle into Android without native storage/DB/offline authority.
- [x] T070 Prove Vitest contracts/state, i18n guards, typecheck, build, and frontend dependency audit green.

## Phase 7 — Acceptance, regression, and delivery

- [x] T071 Prove focused Playwright report/Data desktop and exact-390 journeys green.
- [x] T072 Prove keyboard, accessible names/status/focus, 44 px targets, no console errors, and no overflow.
- [x] T073 Capture EN/RU/UK light/dark desktop/mobile screenshots and inspect every image visually.
- [x] T074 Run full Laravel suite, Pint, Composer strict validation, and Composer security audit.
- [x] T075 Run full i18n guards, Vitest, TypeScript typecheck, production build, and web audit.
- [x] T076 Run full desktop and exact-phone Playwright suites; account for only documented conditional skips.
- [x] T077 Run Capacitor sync, mobile tests, plugin inventory, bundle fingerprint, and mobile audit.
- [x] T078 Verify OpenAPI operation uniqueness/all refs, migrations/schema preservation, and no live/deployment action.
- [x] T079 Audit changed/staged paths for deployment/workflow/feature002/handoff/generated-agent exclusions.
- [x] T080 Run GitNexus staged-change review and inspect every affected/high-risk flow.
- [x] T081 Update README/architecture/design roadmap/status with the delivered 024 boundary and explicit deferrals.
- [x] T082 Update quickstart/spec/checklist/analysis/tasks only after actual evidence; remove all placeholders.
- [x] T083 Update durable workspace memory with commit, decisions, gates, caveats, and next feature 025.
- [x] T084 Create one atomic feature commit without attribution and push current `master` only.
- [x] T085 Confirm `HEAD == origin/master` and working tree contains only preserved user/generated exclusions.
