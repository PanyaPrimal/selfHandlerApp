# Specification Analysis: Private Attachments with First Consumers

**Date**: 2026-08-14

**Mode**: read-only Spec Kit analysis after the complete specification package; baseline, RED, and final
implementation evidence are appended at their checkpoints.

## Inputs Reviewed

- `spec.md`, checklist, `research.md`, `plan.md`, `data-model.md`, `quickstart.md`, OpenAPI, `tasks.md`
- Constitution 1.2.0, canonical Attachment/Body/Nutrition/privacy/mobile documents
- Delivered 007/016/020 contracts, migrations, models, services, APIs, clients, and tests
- Official Capacitor Camera 8.1+, File Transfer, Filesystem, and HTTP binary transport documentation
- GitNexus pre-change impact at `419010ae0efb25018a0989ff9c87aa657a556335`

## Findings and Remediation

| ID | Severity | Finding | Resolution |
|---|---:|---|---|
| A-001 | High | Shipping only the roadmap's minimum one consumer would not satisfy the named body-and-meal outcome. | Complete both BodyMeasurement and Meal while keeping an explicit two-alias allowlist. |
| A-002 | Critical | Adding behavior directly to the shared User model has a very broad blast radius. | Keep User unchanged; a registered observer and explicit owner-id queries provide locking/quota/cleanup, backed by full regression. |
| A-003 | High | Native Capacitor HTTP cannot safely carry full binary FormData without base64 or fetch patching. | Use official Camera URI plus File Transfer multipart/query/auth; use Filesystem cache downloads for preview. |
| A-004 | High | Copying camera bytes would retain EXIF/GPS and trust attacker-controlled containers. | Magic-probe, decode, orient, bound, resize, re-encode, and re-probe every accepted image. |
| A-005 | High | Counting rows or checking quota before a row exists permits concurrent final-slot races. | Lock stable User then parent rows, resolve retry, and derive exact count/sum before store/create. |
| A-006 | High | Database cascade cannot remove private files and delete-first semantics could orphan content. | Synchronous observers call one file-first cleanup service; I/O failure preserves metadata/aborts parent deletion. |
| A-007 | Medium | Native transfer accepts only the file as multipart data, not arbitrary form fields. | Put bounded parent alias/id/upload key in authenticated query parameters for browser and native parity. |
| A-008 | Medium | Resource-level content URLs could accidentally become public or leak physical paths. | Expose only an authenticated relative proxy endpoint; test serialization/log/DOM exclusions. |
| A-009 | Medium | Persistent native previews would silently become an offline private-media store. | Download only to unique cache paths and require deterministic disposal on unload/delete/error. |
| A-010 | Medium | A polymorphic table could invite unsupported documents, receipts, GPX, or arbitrary class input. | Feature 021 accepts only photo + two server-owned aliases; all broader consumers remain explicit deferrals. |

## Coverage Result

- User stories / acceptance scenarios: **5 stories / 20 scenarios**
- Functional requirements / success criteria: **32 / 12**, sequential with no gaps
- OpenAPI: **3 paths / 3 unique authenticated operations** with closed JSON/object schemas
- Implementation tasks: **140**, sequential with no gaps or duplicate task lines
- Specification checklist: **16 / 16**
- Unresolved clarification/placeholder markers: **0**
- Critical/high/medium findings remaining: **0**
- Constitution violations: **0**
- Protected/deployment scope: **absent**; handoff remains unrelated/untracked

## Shared-Impact Gate

GitNexus classifies User **CRITICAL** (137 direct / 429 total), but the remediation avoids editing that class.
BodyMeasurement and Meal are **MEDIUM** (5 direct each); BodyMeasurementController, MealResource,
MealService, and native transport are **LOW**. Permanent legacy Body/Nutrition tests, explicit user-deletion
cleanup tests, fixed query budgets, full Laravel/browser regressions, and staged re-analysis remain mandatory.

## Requirement Traceability

| Requirement group | Story / primary tasks |
|---|---|
| FR-001–FR-003 | Foundation / T011–T012, T026–T030, T041–T044 |
| FR-004–FR-007 | US1 / T014, T034–T040, T048–T062 |
| FR-008–FR-010 | Foundation + US1 / T013, T031–T033, T050–T055 |
| FR-011–FR-013 | US4 / T015, T053–T054, T089–T099 |
| FR-014–FR-018 | US3 / T016–T017, T076–T088 |
| FR-019–FR-021 | US1/US2 / T056–T075 |
| FR-022–FR-025 | US5 / T101–T107, T117–T120 |
| FR-026–FR-028 | US5 / T108–T116, T121–T128 |
| FR-029–FR-032 | Contract/final / T020–T025, T129–T140 |

## Completion Standard

Implementation may begin only after permanent RED evidence. Completion requires all 140 tasks, exact final
evidence, one atomic pushed commit, unchanged handoff, and zero deployment/recognition/offline/generic-file
scope. This analysis does not authorize changes outside the selected feature.

## Baseline at 020 HEAD

The clean pre-RED baseline at `419010ae0efb25018a0989ff9c87aa657a556335` passed:

- focused Body/Nutrition backend: **69 passed / 1114 assertions**;
- shared-client i18n: **1627 keys across EN/RU/UK and 98 source files**;
- shared-client unit: **10 files / 37 tests**;
- mobile shell: **15/15 Node tests**.

The feature package itself has **140/140** continuous unique task IDs, **32/32 FR**, **12/12 SC**,
**20/20 acceptance scenarios**, **16/16 checklist items**, and a parsable OpenAPI 3.1 contract with three
paths/operations. Permanent 021 RED tests may now be added.

## RED Baseline

Permanent 021 tests were added against 020 HEAD before production implementation:

- backend focused: **28 failed / 1 passed**; the only pass is the static OpenAPI parse/reference check,
  while failures identify the absent table, model, services, routes, projection, and cleanup boundary;
- shared client: suite import fails exactly because `attachments/platform.ts` is absent;
- mobile shell: **3/3 failed** because Camera/Filesystem/File Transfer dependencies and the shared URI
  transport are absent.

The local Herd PHP binary exposes GD but omits its JPEG and WebP codecs. Production remains specified for
standard `ext-gd` with JPEG/PNG/WebP; codec capability will fail closed at startup/use, and full image tests
will run with a temporary official PHP CLI build rather than weakening accepted camera formats.

## Final Implementation Result

Feature 021 is complete as the intentionally narrow private-photo slice specified above. One immutable,
owner-scoped Attachment model supports BodyMeasurement and Meal through an explicit two-alias allowlist.
The shared service owns detection, decode, orientation, bounded normalization, private storage, stable retry,
serialized quota, authenticated streaming, explicit deletion, and parent/user cleanup. Body and Nutrition
retain their existing domain facts and expose only additive, oldest-first attachment summaries.

The browser uses multipart binary upload and revocable authenticated object URLs. Android uses the official
Camera URI, File Transfer multipart upload, and disposable Filesystem cache previews; full image bytes never
cross the JavaScript bridge as base64. Activity restoration creates an explicit foreground offer and never an
offline queue. All user-visible controls and errors are complete in EN/RU/UK.

Three final regression findings were corrected before acceptance:

1. Laravel transaction retries could repeat a filesystem write after SQLite lock contention and leave orphan
   files. Upload now performs one locked transaction attempt and relies on the stable client identity for a
   safe whole-operation retry; five repeated two-process parent/owner quota races each committed one winner
   and left one final file.
2. Global `enforceMorphMap` also closed Sanctum's existing User token polymorphism. Attachment aliases now
   use a registered morph map while the Attachment model/service retain their own closed parent allowlist;
   existing `App\\Models\\User` token rows and all mobile-session tests remain compatible.
3. Nutrition used the device calendar day instead of the Profile timezone and did not preserve a selected
   historical date across reload. The already affected Nutrition surface now derives today from the Profile
   timezone and mirrors date selection into the route query; the midnight-boundary RED and full browser
   projects pass.

## Final GREEN Evidence

### Server and contracts

- Official PHP **8.5.9** CLI with `mbstring`, PDO SQLite, SQLite3, Fileinfo, GD JPEG/PNG/WebP, OpenSSL, and
  EXIF: focused Attachment plus affected 007/016 contracts **63 tests / 1,038 assertions**.
- Two independent-process quota harnesses passed five consecutive repetitions for both the last parent slot
  and last owner byte, followed by the combined focused and full suites.
- Full Laravel after the final PHP/test change: **687 tests / 9,294 assertions**, zero failures or skips.
- Exact byte/dimension/orientation/transparency, source immutability, idempotency/conflict, 5 MiB, ten-photo,
  100 MiB, partial-write/row compensation, delete/cleanup, fixed-query-budget, ownership/header, schema,
  rollback/reapply, and OpenAPI tests pass.
- Isolated 021 rollback removed only `attachments`, preserved seeded 020 Finance rows, and reapplied the
  migration. Named indexes fit MySQL's 64-character ceiling; no dialect-specific SQL was added.
- Pint passes. `composer validate --strict` passes and Composer's locked advisory audit returned no security
  advisories for the unchanged final lock.

### Shared web and Android client

- i18n guard: **1,652 keys × EN/RU/UK**, **103** checked source files; zero parity, used-key, or hardcoded-copy
  failures.
- Vitest: **11 files / 42 tests**. TypeScript typecheck and Vite production build pass with **191 modules**;
  only the established bundle-size warning remains. Web npm audit: **0 vulnerabilities**.
- Focused Attachment browser journeys pass on desktop and exact 390×844 mobile; the final visual matrix is
  **2/2** and produced **24** screenshots (three locales × two schemes × two consumers × two viewports).
  Desktop/mobile contact sheets and the full-resolution long Nutrition captures were inspected for content,
  localization, contrast, private previews, focus affordances, destructive actions, safe areas, and overflow.
- Full Playwright desktop: **104 passed / 8 conditional mobile-only skips**. Full exact-390 mobile:
  **109 passed / 3 conditional desktop-only skips**. Every Attachment journey passes in both projects.
- Mobile Node suite: **19/19**; mobile npm audit: **0 vulnerabilities**. Final safe Capacitor sync found the
  expected seven plugins (App, Camera, Device, File Transfer, Filesystem, Keyboard, Local Notifications),
  validated native source/config/permissions, and produced bundle fingerprint **`467ac7e21b74`**.
- No APK/native compilation, device installation, background upload, or deployment action was performed.

### Consistency, impact, and safety

- Final Spec Kit audit: **140** unique sequential tasks, **5** stories, **20** acceptance scenarios,
  **32** FR, **12** SC, **16/16** checklist items, three authenticated closed OpenAPI operations, no missing
  IDs, duplicates, unresolved clarifications, or placeholders.
- GitNexus was force-refreshed to **9,824 nodes / 24,580 edges / 563 clusters / 300 flows**. BodyMeasurement
  and Meal are the only MEDIUM changed models (**9 direct consumers each**); their controllers, trend/goal
  services, MealService, NutritionSummary/Today, observers, provider bootstrap, and Android runtime direct
  consumers were reviewed and covered by the full suites. Other queried changed symbols are LOW.
- `git diff --check` passes. Privacy scans found only deliberate EXIF/token fixtures, authenticated native
  Authorization headers, and tiny inline E2E PNG fixtures; application Attachment code contains no logging,
  public/signed URL, absolute-path response, image base64 bridge, request-body logging, or generated binary.
- Tracked protected deployment/workflow paths have zero diff. The unrelated handoff remains exactly seven
  untracked files with its prior manifest identity **`86fc680bc0265c902af697aa41a2d668b0b2f240`**; generated
  `AGENTS.md` and `CLAUDE.md` remain excluded.

## Deferred Boundary

Recognition or macro/health inference, receipts, documents, GPX, arbitrary parent types, thumbnails,
deduplication, sharing/public links, external providers, background/offline queues, native offline authority,
analytics/export/AI consumers, and every deployment concern remain outside feature 021.
