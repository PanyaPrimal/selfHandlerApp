# Tasks: Planner and Day Planning

**Input**: Design documents from `specs/009-planner-day/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`, `quickstart.md`

**Tests**: Mandatory. This feature reads across three modules and writes back into two of them, so
boundary, compatibility and refusal behaviour all need coverage.

**Organization**: Grouped by independently testable user story.

## Phase 1: Setup

- [X] T001 Add Planner fixtures in `apps/api/tests/Feature/Planner/PlannerTestCase.php` (SC-006)
- [X] T002 [P] Add `apps/api/app/Support/PlannerEntry.php` and `apps/api/app/Contracts/SchedulableSource.php` (FR-001, FR-004)

---

## Phase 2: Foundational

- [X] T003 Write failing schema, ownership and additive-column tests in `apps/api/tests/Feature/Planner/PlannerSchemaTest.php` (FR-011, FR-018)
- [X] T004 Implement the additive migration in `apps/api/database/migrations/2026_08_12_180000_create_planner_day.php`: `time_blocks` plus `planned_occurrences.rescheduled_to` (FR-011, FR-018)
- [X] T005 [P] Implement `apps/api/app/Models/TimeBlock.php` with ownership and casts (FR-018)
- [X] T006 Implement `apps/api/app/Services/Planner/SourceRegistry.php` (FR-002)

---

## Phase 3: User Story 1 - See One Day (Priority: P1)

- [X] T007 [P] [US1] Write failing day-assembly, ordering, empty, beyond-window, ownership and query-bound tests in `apps/api/tests/Feature/Planner/PlannerDayTest.php` (FR-003, FR-006-FR-010, SC-001, SC-006, SC-007)
- [X] T008 [US1] Implement `RoutineOccurrenceSource`, honouring `rescheduled_to` in both directions (FR-003)
- [X] T009 [P] [US1] Implement `StorageItemSource` and `TimeBlockSource` (FR-003)
- [X] T010 [US1] Implement `DayAssembler` ordering and window state (FR-008, FR-009)
- [X] T011 [US1] Implement the day read in `apps/api/app/Http/Controllers/PlannerController.php` and register the routes (FR-007, FR-023)

---

## Phase 4: User Story 2 - Move Or Skip (Priority: P1)

- [X] T012 [P] [US2] Write failing reschedule, clear, refusal, skip-parity and Today/progress compatibility tests in `apps/api/tests/Feature/Planner/PlannerActionsTest.php` (FR-011-FR-017, SC-002-SC-005)
- [X] T013 [US2] Implement reschedule and its refusals (completed, past, beyond window) (FR-011-FR-014)
- [X] T014 [US2] Implement skip by writing the existing routine log through the existing path (FR-015, FR-016)
- [X] T015 [US2] Assert a rescheduled occurrence survives materialization in `apps/api/tests/Feature/Recurrence/RecurrenceEngineTest.php` (FR-022)

---

## Phase 5: User Story 3 - Time Blocks (Priority: P1)

- [X] T016 [P] [US3] Write failing create, time-order, end-before-start, edit, delete and overlap tests in `apps/api/tests/Feature/Planner/TimeBlockApiTest.php` (FR-018-FR-020)
- [X] T017 [US3] Implement `apps/api/app/Http/Controllers/TimeBlockController.php` (FR-018-FR-020)

---

## Phase 6: User Stories 4 and 5 - Tomorrow and the Window (Priority: P2)

- [X] T018 [P] [US5] Write a failing test that `recurrence:materialize` is registered on the schedule in `apps/api/tests/Feature/Planner/MaterializationScheduleTest.php` (FR-021, SC-008)
- [X] T019 [US5] Register the daily command in `apps/api/routes/console.php` and add the `scheduler` service to `_local-deploy/compose.local.yaml` (FR-021)

---

## Phase 7: Interface (Priority: P2)

- [X] T020 [P] Add failing desktop, 390px and keyboard scenarios in `apps/web/e2e/planner/planner-day.spec.ts` (FR-025-FR-028)
- [X] T021 Add typed planner payloads to `apps/web/src/api/types.ts` and client calls to `apps/web/src/api/client.ts` (contracts)
- [X] T022 Implement `apps/web/src/views/PlannerView.vue` on the feature 005 controls with explicit empty and beyond-window states (FR-025, FR-027)
- [X] T023 Register the `/planner` route and add the destination in `apps/web/src/layouts/AppShell.vue` (FR-026)
- [X] T024 Add planner styles with no horizontal overflow at 390px in `apps/web/src/style.css` (FR-028)

---

## Phase 8: Polish and Completion Gate

- [X] T025 Publish `specs/009-planner-day/contracts/openapi.yaml` and hold it against the routes in `apps/api/tests/Feature/Planner/PlannerOpenApiContractTest.php` (FR-023, SC-009)
- [X] T026 Record the answer to open question 6 in `docs/design/recurrence-engine.md` (Constitution II)
- [X] T027 Add the changelog entry in `apps/web/src/content/changelog.ts`
- [X] T028 Run the full gate: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`, both Playwright projects, `git diff --check`, OpenAPI parsing (SC-010)
- [X] T029 Add the implementation-evidence section here and mark the roadmap entry complete

---

## Dependencies

- T004 blocks every later phase; T002 blocks T008-T010.
- T010 blocks T011; T013 depends on T008 for the reschedule read path.
- T021 blocks T022, which blocks T023.

## Parallel Opportunities

- T007, T012, T016, T018 and T020 are five independent failing surfaces.
- T009 is two source files independent of T008.

## Notes

- A source reads only. Every write goes to the module that owns the record.
- Never overwrite `occurrence_date`; a reschedule is a separate column.
- No existing endpoint, payload or behaviour may change.
- Mark a task `[X]` only after its behaviour and verification are complete.

---

## Implementation Evidence

**Status**: complete, 2026-08-12. Every task above is `[X]` and the full gate ran clean.

### Gate results

| Check | Result |
| --- | --- |
| `php artisan test` | 204 passed (1391 assertions) |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` | passed |
| `npm run build` | passed (259.56 kB JS / 22.34 kB CSS) |
| Playwright `desktop` | 50 passed, 5 skipped |
| Playwright `mobile` | 54 passed, 1 skipped |
| `git diff --check` | clean |
| OpenAPI parses and matches the routes | `PlannerOpenApiContractTest`, 4 tests |

### What the tests actually pin down

- **The day is assembled, never stored.** `PlannerDayTest` asserts that reading a busy day writes
  nothing into any module (`test_planner_stores_nothing_belonging_to_another_module`) and that a 45-entry
  day stays inside a bounded query count.
- **A skip from Planner is the same record Today writes.** `PlannerActionsTest` compares the two logs
  field by field and asserts exactly one log row exists, so no parallel planner-side skip state can
  appear later.
- **A move never overwrites what the rule expanded.** `occurrence_date` is asserted unchanged after a
  reschedule, and re-running materialization produces no duplicate.

### Defects found and fixed while building this

1. **A rescheduled day was deleted by materialization.** The stale-day sweep spared only occurrences
   linked to a fact, so narrowing a rule silently dropped a day the user had deliberately moved
   elsewhere. Caught by a test written against the rule in `RecurrenceEngineTest`
   (`test_a_moved_day_is_intent_and_survives_materialization`), which was confirmed failing before
   `RecurrenceMaterializer` was changed to spare `rescheduled_to` as well.
2. **The scheduled materialization run was unverified.** `MaterializationScheduleTest` now asserts the
   command is registered exactly once, with `withoutOverlapping` and `onOneServer`. The assertion was
   verified by removing the registration and confirming the failure, then restoring it. Without a
   scheduled run the window silently stops advancing and a future day quietly becomes unplannable.

### Interface note

A daily routine moved to a day that already has its own occurrence shows **two** rows, not one. This is
deliberate and asserted in `planner-day.spec.ts`: they are two real commitments, and merging them would
quietly discard one.

### Known limitations

- No reminders or notifications. Planner shows a day; it does not prompt.
- Routine days exist only inside the 90-day materialization window; beyond it the day says it is not
  expanded yet rather than showing as empty.
- Time blocks may overlap. Recording a conflict is normal use.
- A task's date is still owned by Storage, so `move` on a task edits its due date through Storage.
