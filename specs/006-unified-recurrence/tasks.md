# Tasks: Unified Recurrence with Routine Migration

**Input**: Design documents from `specs/006-unified-recurrence/`

**Prerequisites**: `plan.md`, `spec.md`, `research.md`, `data-model.md`,
`contracts/openapi-delta.md`, `quickstart.md`

**Tests**: Mandatory. This feature moves live schedule data and must prove that nothing observable
changed, so migration, expansion, compatibility and browser coverage all move with the code.

**Organization**: Grouped by independently testable user story. Test tasks precede the implementation
they verify.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel because it changes a different file and has no incomplete dependency
- **[Story]**: Maps the task to one specification user story

## Phase 1: Setup

- [X] T001 Add recurrence fixtures and assertions in `apps/api/tests/Feature/Recurrence/RecurrenceTestCase.php` (FR-026, SC-001)
- [X] T002 [P] Add `RecurringRule` and `PlannedOccurrence` factories in `apps/api/database/factories/` (FR-001, FR-005)

---

## Phase 2: Foundational (Schema Cutover)

**⚠️ CRITICAL**: nothing else starts until live rows are proven to survive the cutover.

- [X] T003 Write failing preservation, one-rule-per-routine, weekday-backfill and reversibility tests in `apps/api/tests/Feature/Recurrence/RecurrenceMigrationTest.php` (FR-007, FR-027, SC-001, SC-007)
- [X] T004 Implement the cutover migration in `apps/api/database/migrations/2026_08_12_120000_introduce_recurring_rules.php`: create the three tables, backfill from routines and `routine_weekdays`, drop the old shape, and restore it in `down()` (FR-001-FR-007, FR-027)
- [X] T005 [P] Implement `apps/api/app/Models/RecurringRule.php` with ownership, casts, weekday sync and the owner relation (FR-001-FR-004)
- [X] T006 [P] Implement `apps/api/app/Models/RecurringRuleWeekday.php` and `apps/api/app/Models/PlannedOccurrence.php` with ownership and casts (FR-003, FR-005, FR-026)

**Checkpoint**: live rows preserved, one rule per routine, old schedule shape gone.

---

## Phase 3: User Stories 1 and 2 - Preserve Behaviour, Own One Schedule (Priority: P1)

### Tests

- [X] T007 [P] [US1] Write failing pure-expansion unit tests (daily, weekly, bounds, empty weekday set) in `apps/api/tests/Unit/Recurrence/RecurringRuleExpanderTest.php` (FR-008, FR-010)
- [X] T008 [P] [US1] Write failing routine API compatibility tests asserting the unchanged response key set and values in `apps/api/tests/Feature/Recurrence/RoutineCompatibilityTest.php` (FR-023, FR-024, SC-002)
- [X] T009 [P] [US2] Write failing rule-ownership, schedule-lock and single-source tests in `apps/api/tests/Feature/Recurrence/RuleLifecycleTest.php` (FR-012, FR-025, FR-026)

### Implementation

- [X] T010 [US1] Implement the pure `apps/api/app/Services/RecurringRuleExpander.php` (FR-008-FR-011)
- [X] T011 [US1] Rewrite `apps/api/app/Services/RoutineScheduleService.php` as an owner-aware facade with its signature unchanged (FR-012, FR-024)
- [X] T012 [US1] Add rule-backed `schedule_type`, `weekdays`, `starts_on`, `ends_on` and `preferred_time` accessors to `apps/api/app/Models/Routine.php` and hide the internal relations (FR-007, FR-023)
- [X] T013 [US2] Move routine create/update schedule writes onto the rule in `apps/api/app/Http/Controllers/RoutineController.php`, preserving validation and lock messages (FR-023, FR-025)
- [X] T014 [US1] Update eager loading in `apps/api/app/Http/Controllers/TodayController.php` and `apps/api/app/Services/RoutineProgressService.php` to the rule relation without changing their results (FR-024)

**Checkpoint**: one schedule store, identical API and identical Today/progress values.

---

## Phase 4: User Story 3 - Time Zones and Daylight Saving (Priority: P1)

- [X] T015 [P] [US3] Write failing two-user opposite-day, spring-forward and fall-back expansion tests in `apps/api/tests/Feature/Recurrence/RecurrenceTimezoneTest.php` (FR-009, FR-011, SC-005, SC-006)
- [X] T016 [US3] Make expansion walk calendar days in the rule's zone and seed the rule's zone from the profile (FR-009, FR-011)

---

## Phase 5: User Story 4 - Materialization and Facts (Priority: P2)

### Tests

- [X] T017 [P] [US4] Write failing window, idempotency, retry, bounds, paused/archived and expansion-equality tests in `apps/api/tests/Feature/Recurrence/MaterializationTest.php` (FR-013-FR-018, SC-003, SC-004)
- [X] T018 [P] [US4] Write failing fact-linkage and reconciliation tests in `apps/api/tests/Feature/Recurrence/OccurrenceFactTest.php` (FR-020-FR-022)
- [X] T019 [P] [US4] Write a failing query-count test over 50 routines and a full window in `apps/api/tests/Feature/Recurrence/MaterializationPerformanceTest.php` (FR-019, SC-008)

### Implementation

- [X] T020 [US4] Implement `apps/api/app/Services/RecurrenceMaterializer.php`: bounded window, single upsert, single delete, atomic per rule (FR-013-FR-019)
- [X] T021 [US4] Implement `apps/api/app/Services/OccurrenceFactSynchronizer.php` and call it from `apps/api/app/Http/Controllers/RoutineLogController.php` (FR-020-FR-022)
- [X] T022 [US4] Trigger materialization from routine create and update inside the existing transaction (FR-018)
- [X] T023 [P] [US4] Add `apps/api/app/Console/Commands/MaterializeRecurrence.php` and `apps/api/app/Console/Commands/ReconcileOccurrences.php` (FR-018, FR-022)

**Checkpoint**: a bounded, idempotent, fact-linked window that provably equals the expansion.

---

## Phase 6: User Story 5 - Recurrence Editor (Priority: P2)

- [X] T024 [P] [US5] Add failing desktop, 390px and keyboard recurrence-editor scenarios in `apps/web/e2e/recurrence/recurrence-editor.spec.ts` (FR-028-FR-030)
- [X] T025 [US5] Build the recurrence section of `apps/web/src/views/RoutinesView.vue` on the feature 005 controls, with a locked-schedule explanation (FR-028-FR-030)

---

## Phase 7: Polish and Completion Gate

- [X] T026 Reconcile `spec.md`, `plan.md`, `contracts/openapi-delta.md` and `data-model.md` against the implementation (Constitution VI)
- [X] T027 Record the resolution of open question 2 in `docs/design/recurrence-engine.md` (Constitution II)
- [X] T028 Add the changelog entry for recurrence in `apps/web/src/content/changelog.ts` (feature 005 contract)
- [X] T029 Run the full gate: `php artisan test`, `vendor/bin/pint --test`, `npm run typecheck`, `npm run build`, both Playwright projects, `git diff --check`, and a migration on a disposable data-bearing database (SC-009)
- [X] T030 Add the implementation-evidence section here and mark the roadmap entry complete

---

## Dependencies

- T003-T006 block every later phase.
- T010 blocks T011, which blocks T014.
- T012 blocks T013.
- T020 blocks T022.
- T021 depends on T020 for the occurrence rows it updates.
- Phase 6 depends on Phase 3 for the API it drives.

## Parallel Opportunities

- T005 and T006 are separate model files.
- T007, T008 and T009 are three independent failing surfaces.
- T017, T018 and T019 are independent.
- T024 is independent of the backend once the API shape is settled.

## Notes

- No historical migration may be edited.
- No public request or response shape may change.
- Do not add a frequency, interval or cycle field without a consumer in this feature.
- Do not make a read request trigger materialization.
- Mark a task `[X]` only after its behaviour and verification are complete.

---

## Implementation Evidence

**Completed**: 2026-08-12 — 30/30 tasks.

### Delivered

- `recurring_rules`, `recurring_rule_weekdays` and `planned_occurrences`, created and backfilled by
  `2026_08_12_120000_introduce_recurring_rules`, which then drops `routine_weekdays` and the four
  schedule columns from `routines`. `down()` rebuilds the old shape from the rules.
- `RecurringRuleExpander` (pure, any date), `RoutineScheduleService` (owner gating, signature
  unchanged), `RecurrenceMaterializer` (bounded, idempotent, atomic), `OccurrenceFactSynchronizer`
  (derived status), `RoutineRecurrence` (routine-side translation), and the `recurrence:materialize`
  and `recurrence:reconcile` commands.
- Routine, Today, progress and goal read paths moved to the rule relation. The routine API response is
  unchanged: `schedule_type`, `weekdays`, `preferred_time`, `starts_on` and `ends_on` are now appended
  accessors over the rule.
- The recurrence editor explains the post-history schedule lock before a save is attempted, and the
  changelog gained a recurrence entry.

### Refinements against the drafted plan

- **`recurring_rules` has no `is_active` column.** The draft gave the rule its own pause flag, which
  would have duplicated `routines.is_active` and recreated exactly the competing-source problem this
  feature exists to remove. The owner's lifecycle is authoritative; the materializer asks it. FR-002 and
  the data model were corrected before implementation.
- **`Routine` now declares its column defaults** (`is_active`, `is_archived`). Without them a freshly
  created instance had a null `is_active`, so materialization read the routine as paused and wrote an
  empty window. Found by the fact-linkage test.
- **The progress query budget rose from 5 to 6.** Weekdays are one relation deeper now
  (routines → rules → weekdays). The assertion still guards against N+1: the count is independent of the
  500 routines and 365 days in the fixture. The change is recorded in the test with its reason.

### Accepted deviations

- **AD-1**: `planned_occurrences.status` mirrors the routine log. Derived, written by exactly one
  service, rebuilt by `recurrence:reconcile`, and covered by a test that reconstructs it from the logs
  alone. `routine_logs` remains the only authoritative fact and the only public contract.

### Migration evidence (data-bearing rehearsal, 2026-08-12)

A disposable SQLite database was migrated to the pre-006 shape, seeded with feature-001 rows, then
carried through the cutover:

| Check | Result |
|---|---|
| Rows before / after | users 1, routines 2, logs 2, goals 1, goal links 1, reviews 1 — all preserved |
| Rules created | 2 (one per routine) |
| Daily routine | `frequency=daily`, `starts_on=2026-06-01`, `slot_time=07:30:00`, `timezone=Europe/Kyiv` |
| Weekday routine | `frequency=weekly`, bounds `2026-06-01`..`2026-12-31`, weekdays `WE`,`FR` |
| `recurrence:materialize` | 117 occurrences written for the window |
| `migrate:rollback` | restored `schedule_type`, `preferred_time` and both weekday rows exactly |

### Gate results (2026-08-12)

| Gate | Result |
|---|---|
| `php artisan test` | 132 passed, 989 assertions |
| `vendor/bin/pint --test` | passed |
| `npm run typecheck` | passed |
| `npm run build` | passed |
| Playwright, both projects | 69 passed, 3 project-specific skips |
| Data-bearing migration rehearsal | passed, see above |
