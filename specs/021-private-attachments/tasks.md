# Tasks: Private Attachments with First Consumers

**Input**: [spec.md](spec.md), [research.md](research.md), [plan.md](plan.md),
[data-model.md](data-model.md), [contract](contracts/openapi.yaml), [quickstart.md](quickstart.md)

**Tests**: Mandatory permanent RED-first tests precede production implementation for every story.

**Organization**: Tasks are grouped by user story and safety boundary. `[P]` means the files do not overlap.

## Phase 1: Specification and Baseline

- [x] T001 Confirm selected branch, HEAD/origin equality, dirty state, protected deployment exclusions, and handoff boundary
- [x] T002 Inventory canonical Attachment/Body/Nutrition/privacy/mobile docs and delivered 007/016 code/contracts
- [x] T003 Resolve both consumers, formats, normalization, proxy access, quota, retry, and cleanup decisions in `research.md`
- [x] T004 Complete Constitution check, all ten Architecture Gates, phases, and complexity in `plan.md`
- [x] T005 Complete Attachment fields, states, quota, ownership, parent integration, deletion, and rollback in `data-model.md`
- [x] T006 Complete and parse the three-operation closed authenticated 021 OpenAPI contract with resolving references
- [x] T007 Validate 5 stories, 20 scenarios, FR-001–FR-032, SC-001–SC-012, assumptions, edges, and exclusions
- [x] T008 Complete the requirements checklist with no clarification marker or placeholder
- [x] T009 Run GitNexus pre-change impact for User, BodyMeasurement, Meal, controllers/resources, views, and native transport
- [x] T010 Run and record clean 020 baseline gates before adding permanent 021 RED tests

---

## Phase 2: Permanent RED Tests and Contract Foundation

**Goal**: Prove the absent-feature boundary while all earlier behavior stays green.

- [x] T011 [P] Add migration/schema/index/constraint/rollback RED tests in `apps/api/tests/Feature/Attachments/AttachmentSchemaTest.php`
- [x] T012 [P] Add model owner/morph/immutability/resource-exclusion RED tests in `apps/api/tests/Unit/Attachments/AttachmentModelTest.php`
- [x] T013 [P] Add FileStorage private-disk/path/I/O/failure RED tests in `apps/api/tests/Unit/Attachments/FileStorageTest.php`
- [x] T014 [P] Add image magic/decode/orientation/resize/metadata/transparency RED tests in `apps/api/tests/Unit/Attachments/ImageNormalizerTest.php`
- [x] T015 [P] Add idempotency/quota/concurrency/compensation RED tests in `apps/api/tests/Unit/Attachments/AttachmentServiceTest.php`
- [x] T016 [P] Add upload/content/delete auth/ownership/header/error RED tests in `apps/api/tests/Feature/Attachments/AttachmentApiTest.php`
- [x] T017 [P] Add parent/user/missing-file cleanup RED tests in `apps/api/tests/Feature/Attachments/AttachmentCleanupTest.php`
- [x] T018 [P] Add Body response/query/trend/fact compatibility RED tests in `apps/api/tests/Feature/Attachments/BodyAttachmentConsumerTest.php`
- [x] T019 [P] Add Meal response/query/summary/fact compatibility RED tests in `apps/api/tests/Feature/Attachments/MealAttachmentConsumerTest.php`
- [x] T020 [P] Add 021 OpenAPI parse/ref/closed/security/route RED tests in `apps/api/tests/Feature/Attachments/AttachmentOpenApiContractTest.php`
- [x] T021 [P] Add additive 007 Body and 016 Nutrition contract compatibility expectations
- [x] T022 [P] Add TypeScript/client/browser/native adapter RED tests in `apps/web/src/__tests__/attachments-contracts.test.ts`
- [x] T023 [P] Add mobile shell Camera/Filesystem/File Transfer contract RED tests in `apps/mobile/tests/attachment-platform.test.mjs`
- [x] T024 [P] Add browser attachment flow and visual RED specs under `apps/web/e2e/attachments/`
- [x] T025 Run focused permanent RED suites and append exact absent-021 failures to `analysis.md`

**Checkpoint**: failures name only absent 021 schema/classes/routes/types/components/plugins.

---

## Phase 3: Additive Persistence and Safe Image Core

- [x] T026 Add `apps/api/config/attachments.php` with private disk, format, source/stored/dimension, parent, and owner limits
- [x] T027 Create additive reversible `attachments` migration with MySQL-safe named indexes and constraints
- [x] T028 Add Attachment model with factory, strict casts/fillable fields, immutable update protection, and owner relation
- [x] T029 Register explicit BodyMeasurement/Meal morph aliases without accepting arbitrary class names
- [x] T030 Add ordered morph-many relations to BodyMeasurement and Meal only
- [x] T031 Implement FileStorage disk allowlist and opaque owner/UUID path generation
- [x] T032 Implement FileStorage write/open/exists/size/delete methods without absolute/public URL exposure
- [x] T033 Implement FileStorage typed failures and safe log context without path/content/token leakage
- [x] T034 Implement source byte cap and Fileinfo magic MIME allowlist before decode
- [x] T035 Implement safe dimension probe and decoded-pixel ceiling before full processing
- [x] T036 Implement JPEG EXIF orientation transforms for all orientation values 2–8
- [x] T037 Implement bounded no-enlargement resampling with alpha preservation for PNG/WebP
- [x] T038 Implement JPEG/WebP quality 85 and PNG compression 6 re-encoding into request temp storage
- [x] T039 Re-probe normalized MIME/dimensions/size/digest and reject any inconsistent output
- [x] T040 Prove EXIF/GPS/comment marker removal and original-file immutability with real fixtures
- [x] T041 Enforce model owner/current supported parent/disk/path/mime/kind/dimension/digest invariants
- [x] T042 Enforce no update/reparent/path/digest mutation after Attachment creation
- [x] T043 Prove database cascade/restrict behavior and private path uniqueness across owners/parents
- [x] T044 Prove 021 rollback drops only Attachment schema and reapplies while preserving seeded 020 data
- [x] T045 Run schema/model/storage/normalizer focused GREEN checkpoint
- [x] T046 Run Pint and identifier/portable-SQL checks after persistence core
- [x] T047 Record foundational evidence and remaining shared risks in `analysis.md`

---

## Phase 4: User Story 1 — Body Progress Photos (P1)

**Independent test**: valid formats, orientation/privacy, retry, foreign parent, parent response, and unchanged
body facts/trends pass without relying on Nutrition.

- [x] T048 [US1] Implement strict upload query/file request validation with closed field handling
- [x] T049 [US1] Resolve `body_measurement` through an owned locked BodyMeasurement or 404-equivalent result
- [x] T050 [US1] Implement safe basename/display-name normalization independent from physical path
- [x] T051 [US1] Implement upload-key lookup and same parent/hash idempotent replay
- [x] T052 [US1] Reject upload-key parent/content mismatch with deterministic conflict and no mutation
- [x] T053 [US1] Implement owner/body row locking plus count and exact stored-byte quota calculation
- [x] T054 [US1] Store final private bytes and Attachment row transactionally with exception compensation
- [x] T055 [US1] Implement closed AttachmentResource with relative authenticated content URL only
- [x] T056 [US1] Add oldest-first attachment summaries to bounded Body measurement reads
- [x] T057 [US1] Return attachment summaries on Body upsert without changing upsert identity
- [x] T058 [US1] Add BodyMeasurement cleanup observer through the shared deletion service
- [x] T059 [US1] Add localized backend Body upload/type/size/quota/conflict/storage messages EN/RU/UK
- [x] T060 [US1] Prove foreign/missing Body parents create no row/temp/final file and disclose nothing
- [x] T061 [US1] Prove Body metric/date/value/note, latest semantics, goals, and trends are set-equal after photos
- [x] T062 [US1] Run independent Body consumer GREEN checkpoint and fixed 20-parent query budget

---

## Phase 5: User Story 2 — Meal Photos (P1)

**Independent test**: dish-photo upload/list/delete/cleanup and unchanged entries/Nutrition aggregates pass.

- [x] T063 [US2] Resolve `meal` through an owned locked Meal or 404-equivalent result
- [x] T064 [US2] Reuse upload idempotency/quota/storage path without a Nutrition-specific photo service
- [x] T065 [US2] Add attachment summaries to MealResource with `whenLoaded`-safe closed serialization
- [x] T066 [US2] Eager-load Meal attachments in Nutrition day reads without per-meal queries
- [x] T067 [US2] Return attachments from create/update Meal resource responses without changing entry snapshots
- [x] T068 [US2] Preserve attachments when Meal entries are replaced during ordinary edit
- [x] T069 [US2] Add Meal cleanup observer through the same deletion service
- [x] T070 [US2] Add localized backend Meal parent/upload/cleanup messages EN/RU/UK
- [x] T071 [US2] Prove foreign/missing Meals create no row/temp/final file and disclose nothing
- [x] T072 [US2] Prove meal entry snapshots are byte-for-byte/set-equal after attachment mutations
- [x] T073 [US2] Prove day/range calories/macros/hydration/quality/estimate totals are set-equal
- [x] T074 [US2] Prove Today/Review Nutrition consumers remain compatible with additive attachment arrays
- [x] T075 [US2] Run independent Meal consumer GREEN checkpoint and fixed 20-meal query budget

---

## Phase 6: User Story 3 — Authenticated Streaming and Deletion (P1)

- [x] T076 [US3] Add AttachmentController routes for upload, content, and delete inside Sanctum middleware
- [x] T077 [US3] Re-resolve Attachment owner and current supported parent for every stream/delete operation
- [x] T078 [US3] Implement streamed response using FileStorage without loading content into JSON/base64
- [x] T079 [US3] Emit exact MIME/length, safe inline filename, private no-store, nosniff, and sandbox headers
- [x] T080 [US3] Make missing content bytes a safe 404-equivalent stream failure without metadata leakage
- [x] T081 [US3] Implement explicit file-first then metadata deletion under owner/parent locks
- [x] T082 [US3] Treat missing bytes as repairable deletion while preserving metadata on disk I/O failure
- [x] T083 [US3] Normalize anonymous/foreign/unsupported-parent stream/delete behavior and timing-safe payload shape
- [x] T084 [US3] Register User deletion observer without modifying the critical shared User model
- [x] T085 [US3] Purge remaining user-owned files/rows synchronously before database cascades
- [x] T086 [US3] Prove parent/user deletion failure aborts destructive DB mutation and stays retryable
- [x] T087 [US3] Prove logs/exceptions never contain image bytes, bearer tokens, physical paths, EXIF, or request bodies
- [x] T088 [US3] Run full auth/ownership/header/delete/cleanup matrix GREEN checkpoint

---

## Phase 7: User Story 4 — Quotas, Races, and Failure Consistency (P1)

- [x] T089 [US4] Prove exact 5 MiB source acceptance and +1 byte rejection before image decode
- [x] T090 [US4] Prove normalized stored-size ceiling and decoded dimension/pixel limits
- [x] T091 [US4] Prove exact 10-photo parent boundary for Body and Meal plus eleventh rejection
- [x] T092 [US4] Prove exact 100 MiB derived owner boundary and +1 byte rejection across both parents
- [x] T093 [US4] Prove idempotent replay at full quota returns existing identity without another charge
- [x] T094 [US4] Prove concurrent final parent-slot requests commit at most one Attachment
- [x] T095 [US4] Prove concurrent final owner-byte requests commit within exact quota
- [x] T096 [US4] Force temp creation/normalization/final write/row create failures and assert row/file set equality
- [x] T097 [US4] Prove successful delete releases derived quota and failed delete does not
- [x] T098 [US4] Prove cleanup batching stays bounded for a user with many parents/photos
- [x] T099 [US4] Run quota/idempotency/concurrency/failure GREEN checkpoint on SQLite and portable locking path

---

## Phase 8: User Story 5 — Shared Browser and Android Client (P2)

- [x] T100 [P] [US5] Add Attachment and additive BodyMeasurement/Meal TypeScript contracts
- [x] T101 [P] [US5] Add browser multipart upload adapter with query metadata and structured API errors
- [x] T102 [P] [US5] Add browser authenticated blob preview and deterministic object-URL revocation
- [x] T103 [P] [US5] Add native Camera take-photo and gallery URI selection adapters with bounded target dimensions
- [x] T104 [P] [US5] Add native File Transfer multipart URI upload with bearer/query metadata and parsed response
- [x] T105 [P] [US5] Add native authenticated preview download to a unique Filesystem cache path
- [x] T106 [P] [US5] Convert native cache path for WebView display and delete it on release/delete/error
- [x] T107 [US5] Add one platform-neutral attachment client interface and deterministic cancellation/error mapping
- [x] T108 [US5] Add reusable AttachmentUploader with browser picker and native camera/gallery actions
- [x] T109 [US5] Add reusable AttachmentGallery with private lazy previews, metadata, busy/error/empty states
- [x] T110 [US5] Add localized destructive delete confirmation and focus restoration/live feedback
- [x] T111 [US5] Compose uploader/gallery into each Body measurement history item
- [x] T112 [US5] Compose uploader/gallery into each Meal card without changing Meal editor draft behavior
- [x] T113 [US5] Preserve accepted parent data and recoverable photo action after upload/delete failures
- [x] T114 [US5] Add 44px controls, visible focus, descriptive alt text, semantic status, and keyboard behavior
- [x] T115 [US5] Add responsive desktop/exact-390 gallery layout with safe-area and overflow protection
- [x] T116 [US5] Add all attachment action/state/error/quota/ARIA copy to EN/RU/UK with key parity
- [x] T117 [US5] Add Camera/Filesystem/File Transfer compatible dependencies to web and mobile lockfiles
- [x] T118 [US5] Synchronize Android permissions/config only as required by official plugins; no gallery save permission
- [x] T119 [US5] Handle camera activity restoration as explicit foreground completion, never an offline queue
- [x] T120 [US5] Run client/native unit, i18n, typecheck, and production build GREEN checkpoint

---

## Phase 9: Browser Journeys, Documentation, and Final Gates

- [x] T121 [P] Complete Body browser upload/preview/retry/delete/foreign-error journey fixtures
- [x] T122 [P] Complete Meal browser upload/preview/delete and unchanged-summary journey fixtures
- [x] T123 [P] Complete desktop/mobile visual matrix for Body and Nutrition photo surfaces
- [x] T124 Run focused attachment journeys on desktop and exact 390×844 mobile
- [x] T125 Generate EN/RU/UK × light/dark × desktop/mobile screenshots for both consumers
- [x] T126 Build contact sheets and inspect every final screenshot for state, privacy, localization, focus, and overflow
- [x] T127 Run full Playwright desktop regression and resolve every failure
- [x] T128 Run full Playwright exact-mobile regression and resolve every failure
- [x] T129 Run full Laravel suite after the last PHP change with zero failure/skip regression
- [x] T130 Run Pint, strict Composer validation/audit, web/mobile npm audits, i18n, Vitest, typecheck, and build
- [x] T131 Run isolated 021 rollback/reapply and prove all seeded 020 tables/rows survive
- [x] T132 Run final safe Android sync, verify all expected plugins, validate shell, and record bundle fingerprint
- [x] T133 Update OpenAPI/body/nutrition/design/roadmap/docs/changelog in English with delivered 021 boundary
- [x] T134 Run Spec Kit prerequisite/task/FR/SC/scenario/checklist/placeholder and final consistency analysis
- [x] T135 Run `git diff --check`, secret/private-path/logging/dependency/generated/large-file scans
- [x] T136 Prove zero protected/deployment/handoff files entered status and handoff manifest hash is unchanged
- [x] T137 Force-refresh GitNexus and run staged change-impact detection
- [x] T138 Review every medium/high/critical direct consumer, especially Body/Meal and user-deletion lifecycle
- [x] T139 Mark 140/140 tasks and spec Complete; record all exact GREEN/visual/mobile/safety evidence in `analysis.md`
- [x] T140 Stage only authorized 021 files, atomic commit/push master, fetch, and prove HEAD equals origin/master

**Final checkpoint**: 021 is complete and pushed with private Body/Meal photos; no recognition, offline queue,
generic files, public URLs, deployment path, live data, or unrelated handoff/generated agent file entered it.
