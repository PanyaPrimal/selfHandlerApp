# Specification Analysis: Supplements, Courses, Intake, and Stock

**Date**: 2026-08-13

**Mode**: `$speckit-analyze` read-only consistency review followed by explicitly authorized remediation
and a second read-only verification.

## Inputs Reviewed

- [spec.md](spec.md) and requirements checklist
- [research.md](research.md)
- [plan.md](plan.md)
- [data-model.md](data-model.md)
- [contracts/openapi.yaml](contracts/openapi.yaml)
- [quickstart.md](quickstart.md)
- [tasks.md](tasks.md)
- Constitution 1.2.0 and canonical module/recurrence/notification/data-convention/roadmap inputs
- Current implementation and refreshed GitNexus impact at baseline
  `9cd7c116888ee7c63bcaa52a8d8b995c7fec1f14`

## Coverage Inventory

| Item | Count | Result |
|---|---:|---|
| Prioritized independently testable user stories | 6 | Complete |
| Functional requirements | 32 | Complete |
| Measurable success criteria | 11 | Complete |
| OpenAPI paths / unique operations | 9 / 13 | Contract and roadmap plan agree |
| Closed OpenAPI component schemas | 38 | YAML parses successfully |
| Implementation/verification tasks | 91 | Every FR range has primary tasks |
| Explicit deferral section | 1 | Complete and consistent |
| Unresolved clarification markers | 0 | Pass |

## Findings and Resolutions

| ID | Severity | Finding | Resolution |
|---|---:|---|---|
| A-001 | High | Plan/research said six new tables while the normalized model contains seven. | Corrected every count to seven; migration/task paths already named all seven. |
| A-002 | High | Course PATCH exposed `supplement_id`, allowing historical course/intake/stock scope to move. | Supplement link is immutable after course creation; PATCH contract removed the field and tests require rejection. |
| A-003 | High | `no_stock`, `already_depleted`, and proposal eligibility were not mutually precise. | `no_stock` means no inventory or taken-intake facts (skips do not establish stock); factual zero/negative is depleted with runout as-of and immediate proposal eligibility. |
| A-004 | High | A newly created past-start course had no complete durable occurrence/fact interaction. | New courses must start today/future; durable correction works thereafter; bulk historical import is explicitly deferred. |
| A-005 | Medium | Reference `stock_unit` correction could change the dimension of dependent facts. | It becomes immutable after the first course/intake/stock fact; descriptive/default fields stay editable. |
| A-006 | Medium | Normalized generic slot plus domain context ownership needed an explicit one-to-one invariant. | Data model and tasks require same-owner course-rule-slot validation and unique detail relation. |
| A-007 | Medium | High/Critical shared recurrence impact needed explicit closure breadth. | Plan/tasks/quickstart require legacy equivalence plus Recurrence, Planner, Notifications, Habit, Sleep, Workout, Nutrition, and Core gates. |

## Requirement-to-Story/Task Consistency

- FR-001–004 map to US1 and quantity/schema/catalogue tasks T002–T003, T009–T013, T021–T028.
- FR-005–009 map to US2 and recurrence/course tasks T004, T014–T020, T029–T038.
- FR-010–013 map to US3 and fact/synchronization tasks T039–T046.
- FR-014–019 map to US4 and ledger/forecast/proposal tasks T047–T058.
- FR-020–025 map to US5 and notification/Planner/adherence/daily-loop tasks T059–T071.
- FR-026–031 map to contract/client/closure tasks T005–T008 and T072–T091.
- FR-032 maps to scope/document/audit tasks T001, T084, T086, and T089.

Every story has at least one permanent backend and/or browser test, an independent verification, and a
checkpoint. Every entity is used by requirements and tasks. No task introduces a second scheduler,
stock counter, consumption movement, aggregate rollup, finance fact, native authority, or medical/AI
output.

## Architecture and Safety Result

- **Critical inconsistencies remaining**: 0
- **High inconsistencies remaining**: 0
- **Medium inconsistencies remaining**: 0
- **Low/editorial inconsistencies remaining**: 0
- **Constitution gates**: all pass; recurrence impact requires the documented enhanced regression gate
- **Protected/deployment/handoff scope**: absent from planned mutations; handoff remains untracked

The artifacts are internally consistent and ready for `$speckit-implement`.

## Implementation Evidence

### RED and implementation sequence

- Permanent schema, quantity, recurrence, ownership, OpenAPI, client, and browser tests were authored
  before their production counterparts. Their first focused run failed only on absent 017 tables,
  models/services/routes, client types, and locale keys; the pre-017 regression surface stayed green.
- Implementation then followed the task phases: portable schema and exact quantities; catalogue;
  bounded shared recurrence; intake facts; immutable stock and forecasts; proposal reconciliation;
  Planner/Notifications/Today/Review; and finally the shared responsive client.
- The closure analysis found one traceability gap: legacy equivalence and fact exclusivity were tested
  in 017 suites but not asserted in the named shared recurrence suite. A RED assertion first exposed
  the intended physical `interval_count` default, then the corrected permanent regression proved the
  effective legacy expansion, empty-slot identity, and mutually exclusive Supplement fact link.

### Delivered architecture and contracts

- Seven additive private tables plus nullable shared recurrence/fact extensions implement neutral
  references, bounded courses, normalized slots, one intake fact, append-only stock movements, and one
  active fingerprinted restock proposal. Domain-reference deletion is restricted while account deletion
  cascades private data.
- Canonical quantities are `DECIMAL(14,6)` grams, millilitres, or pieces. The conversion boundary rejects
  floating notation, excess precision, fractional pieces, and dimension mismatches.
- Daily/weekly intervals and paired on/off cycles are anchored to the course start date. Shared
  materialization remains idempotent for routines, habits, sleep plans, and workout programs, with
  legacy owners retaining one empty-slot occurrence.
- Intake is the only consumption fact. Forecasting is a pure bounded 730-date overlay with seven closed
  states; adherence is a bounded 366-date projection whose denominator contains elapsed opportunities.
- The 017 OpenAPI contract parses as 3.1, contains 9 paths, 13 unique authenticated operations, and 38
  closed component schemas. Registered routes and local references match exactly. The compatible 001,
  009, and 011 contracts document the new summary/source/category values.

### Verification gates

| Gate | Final evidence |
|---|---|
| Feature-only Laravel | 42 passed, 969 assertions |
| Shared recurrence + Supplements | 54 passed, 1,031 assertions |
| Full Laravel | 495 passed, 6,227 assertions in 123.49 s |
| PHP quality/dependencies | Pint pass; strict Composer validate pass; locked audit: no advisories |
| Web static/unit | i18n 1,313 keys across EN/RU/UK and 84 source files; typecheck pass; Vitest 7 files / 33 tests |
| Web production bundle | Vite/Capacitor sync built 155 modules; expected size advisory only |
| Full browser regression | desktop 90 passed / 8 conditional skips; mobile 97 passed / 1 desktop-only skip |
| Focused browser flow | Supplements desktop 2/2 and mobile 2/2 |
| Mobile shell | Node 15/15; npm audit 0; sync pass; bundle fingerprint `190f5f2bb74c` |

### Visual and interaction inspection

- Inspected 60 screenshots: EN/RU/UK × light/dark × desktop/exact 390×844 across Supplements, Today,
  Review, Planner, and Notifications. All 30 mobile captures are exactly 390×844; desktop captures use
  1366px width with full-page heights appropriate to each surface.
- No horizontal overflow, clipping, overlap, inaccessible contrast, or locale-specific truncation was
  found. Long Russian and Ukrainian labels wrap inside their cards; mobile controls preserve the 44px
  target and safe-area spacing.
- Browser coverage verifies rollback after rejected mutations, draft recovery, focus/live-region
  feedback, deep-link restoration, keyboard use, theme/locale switching, and notification settings.

### Safety, portability, and dependency audit

- A fresh isolated SQLite database migrated through 017, rolled back exactly the 017 migration, and
  reported every prior migration still `Ran` with 017 `Pending`; the verified temporary file was removed.
- Secret-pattern matches: 0. Dependency-manifest changes: 0. New/changed feature files over 512 KiB: 0.
  `git diff --check` passes.
- Git status/path audit reports 0 changes under feature 002, `deployment/`, `_local-deploy/`,
  `deploy.ps1`, or workflow paths. The unrelated handoff remains the same seven untracked files and is
  excluded from staging.

### GitNexus and final Spec Kit analysis

- Forced refresh indexed 7,567 nodes, 18,563 edges, 425 clusters, and 300 flows. `detect_changes(all)`
  classified the broad shared-engine diff as critical: 147 changed symbols, 28 affected processes, and
  53 mapped files.
- Every reported affected flow was reviewed. Direct-consumer impact was bounded to
  RecurrenceMaterializer (medium, 8), OccurrenceFactSynchronizer (medium, 6),
  NotificationSourceSynchronizer (medium, 7), RecurringRuleExpander (low, 2), and Planner SourceRegistry
  (low, 3). The named consumers are covered by the shared recurrence, command, Planner, notification,
  Habit, Sleep, Workout, Nutrition, Core, and full-suite gates above.
- `detect_changes(staged)` completed against the 138-path atomic feature set and retained the expected
  critical classification because new endpoints and shared recurrence/fact flows are included. The
  graph's forced-working-tree text mapping also surfaced indexed handoff/deployment documentation
  sections; the authoritative staged Git path audit contains 0 such paths, 0 dependency manifests,
  0 secret matches, and 0 files over 512 KiB.
- The post-implementation specification/plan/model/contract/task review has 0 critical, high, medium,
  or low inconsistencies; 6 stories, 32 functional requirements, 11 success criteria, and all 91 tasks
  remain traceable. Final staged GitNexus and push evidence is verified during the atomic commit closure;
  the exact commit identity is recorded by Git history and durable workspace memory.
