# Tasks: Storage Inbox and Quick Capture

**Input**: Design documents from `specs/008-storage-inbox/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `contracts/openapi.yaml`,
`quickstart.md`

**Tests**: Mandatory. This feature adds a new ownership surface, a rule that refuses a write, and a
hierarchy that can be corrupted, so ownership, blocking and structure all need coverage.

**Organization**: Grouped by independently testable user story.

## Format: `[ID] [P?] [Story] Description`

## Phase 1: Setup

- [X] T001 Add shared Storage fixtures in `apps/api/tests/Feature/Storage/StorageTestCase.php` (SC-002)
- [X] T002 [P] Add the item type, status and priority vocabularies in `apps/api/app/Models/Item.php` constants (FR-002, FR-003)

---

## Phase 2: Foundational (Persistence)

- [X] T003 Write failing schema, ownership and container-deletion tests in `apps/api/tests/Feature/Storage/StorageSchemaTest.php` (FR-013, FR-017, FR-021, SC-005)
- [X] T004 Implement the additive migration in `apps/api/database/migrations/2026_08_12_160000_create_storage_items.php` for `projects`, `tags`, `items` and `item_tag` (FR-001, FR-014, FR-018)
- [X] T005 [P] Implement `apps/api/app/Models/Item.php` with casts, relations, lifecycle timestamps and same-owner guards (FR-001, FR-006, FR-022)
- [X] T006 [P] Implement `apps/api/app/Models/Project.php` and `apps/api/app/Models/Tag.php` with ownership and per-user uniqueness (FR-014, FR-015, FR-018)

**Checkpoint**: four owned tables; deleting a container keeps its items.

---

## Phase 3: User Story 1 - Capture Without Deciding (Priority: P1)

- [X] T007 [P] [US1] Write failing capture, default-status, blank-title and ordering tests in `apps/api/tests/Feature/Storage/ItemCaptureTest.php` (FR-003, FR-004, SC-001)
- [X] T008 [US1] Implement capture and the bounded inbox listing in `apps/api/app/Http/Controllers/ItemController.php` (FR-001, FR-004, FR-007)
- [X] T009 [US1] Register the storage routes in `apps/api/routes/api.php` behind the existing session guard (FR-023)

---

## Phase 4: User Story 2 - Triage (Priority: P1)

- [X] T010 [P] [US2] Write failing triage, type-change, tag-replacement and lifecycle tests in `apps/api/tests/Feature/Storage/ItemTriageTest.php` (FR-005, FR-006, FR-019, SC-006)
- [X] T011 [US2] Implement update, tag replacement and server-derived lifecycle timestamps (FR-005, FR-006, FR-019)

---

## Phase 5: User Story 3 - Hierarchy and Blocking (Priority: P1)

- [X] T012 [P] [US3] Write failing nesting-limit, self-parent, cycle and blocking tests in `apps/api/tests/Feature/Storage/ItemHierarchyTest.php` (FR-008-FR-012, SC-003, SC-004)
- [X] T013 [US3] Implement the nesting and cycle validation in `apps/api/app/Http/Controllers/ItemController.php` (FR-008-FR-010)
- [X] T014 [US3] Implement `apps/api/app/Services/ItemCompletionGuard.php` and consult it from every closing path (FR-011, FR-012)

---

## Phase 6: User Story 4 - Projects (Priority: P2)

- [X] T015 [P] [US4] Write failing project CRUD, uniqueness, counts and query-bound tests in `apps/api/tests/Feature/Storage/ProjectApiTest.php` (FR-014-FR-017, SC-007)
- [X] T016 [US4] Implement `apps/api/app/Http/Controllers/ProjectController.php` and `apps/api/app/Services/StorageSummary.php` with one grouped count query (FR-016, SC-007)

---

## Phase 7: User Story 5 - The Screen (Priority: P2)

- [X] T017 [P] [US5] Add failing desktop, 390px and keyboard scenarios in `apps/web/e2e/storage/storage-inbox.spec.ts` (FR-025-FR-029)
- [X] T018 [US5] Add typed storage payloads to `apps/web/src/api/types.ts` and client calls to `apps/web/src/api/client.ts` (contracts)
- [X] T019 [US5] Implement `apps/web/src/views/StorageView.vue` on the feature 005 controls with explicit empty states (FR-025, FR-027, FR-028)
- [X] T020 [US5] Register the `/storage` route and add the destination in `apps/web/src/layouts/AppShell.vue` (FR-026)
- [X] T021 [US5] Add storage styles with no horizontal overflow at 390px in `apps/web/src/style.css` (FR-029)

---

## Phase 8: Polish and Completion Gate

- [X] T022 Publish `specs/008-storage-inbox/contracts/openapi.yaml` and hold it against the routes and vocabularies in `apps/api/tests/Feature/Storage/StorageOpenApiContractTest.php` (FR-023, SC-008)
- [X] T023 Add the changelog entry in `apps/web/src/content/changelog.ts`
- [X] T024 Reconcile the feature documents against the implementation (Constitution VI)
- [X] T025 Run the full gate: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`, both Playwright projects, `git diff --check`, OpenAPI parsing (SC-009)
- [X] T026 Add the implementation-evidence section here and mark the roadmap entry complete

---

## Dependencies

- T004 blocks every later phase; T005 blocks T008, T011, T013 and T014.
- T014 depends on T005 for the children relation.
- T018 blocks T019, which blocks T020.

## Parallel Opportunities

- T005 and T006 are separate model files.
- T007, T010, T012 and T015 are four independent failing surfaces.
- T017 is independent of the backend once the contract is settled.

## Notes

- No existing endpoint, payload or behaviour may change.
- Nothing here schedules, reminds or expands; a due date is data.
- Do not add a type, container or tag scope without a consumer in this feature.
- Mark a task `[X]` only after its behaviour and verification are complete.

---

## Implementation Evidence

**Completed**: 2026-08-12 — 26/26 tasks.

### Delivered

- Four owned tables added by one additive migration: `projects`, `tags`, `items` (single table plus
  `type`) and `item_tag`.
- `Item` with its vocabularies, server-derived lifecycle timestamps and same-owner guards on both its
  project and its parent; `Project` and `Tag` with per-user uniqueness.
- `ItemCompletionGuard` — the single place that decides whether an item may be completed — and
  `StorageSummary`, which computes the unsorted count and every project count in one grouped query.
- `GET/POST /api/storage/items`, `PATCH/DELETE /api/storage/items/{item}`, and the four project
  endpoints, all behind the existing session guard.
- `/storage` screen on the feature 005 controls: one-field capture, inbox, triage, child items with a
  blocker toggle, projects with counts, and explicit empty states throughout.

### Defects found while building

- **Capture never returned focus to the field.** The input is disabled while the request is in flight,
  and a disabled input cannot take focus, so focusing it before clearing the flag did nothing. The flag
  is cleared first now. Found by the browser scenario for FR-027.
- **Tags were not optional on capture.** `syncTags` received `null` when a request said nothing about
  tags, which turned a plain one-field capture into a 500. Found by the capture tests.
- **The contract guard matched a framework route.** Laravel registers `storage/{path}` for the local
  disk; stripping the `api/` prefix without checking made it look like one of ours. The guard now
  considers only routes actually under `api/`.

### Contract

`contracts/openapi.yaml` (OpenAPI 3.1, 4 paths, 14 schemas) is the machine-readable contract.
`StorageOpenApiContractTest` fails when a documented operation is not a registered route, when a
`/storage` route is undocumented, or when the documented type, status or priority vocabulary drifts
from its constant.

### Questions answered, and one left open

- The inbox is a status, not a view (`modules.md` open question).
- Nesting is one level deep (`modules.md` open question).
- Whether `Project` and `List` are one container is **deliberately still open**: there is no List to
  compare against yet. Recorded in research R5 rather than guessed at.

### Gate results (2026-08-12)

| Gate | Result |
|---|---|
| `php artisan test` | 171 passed, 1222 assertions |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` | passed |
| `npm run build` | passed |
| Playwright, both projects | 95 passed, 5 project-specific skips |
| OpenAPI parsing | 6 documents parse; every `$ref` in the new one resolves |
| `git diff --check` | clean |
| Additive migration on a data-bearing database | existing rows preserved, four tables created |
