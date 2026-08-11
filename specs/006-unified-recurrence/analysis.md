# Pre-Implementation Analysis: Unified Recurrence with Routine Migration

**Feature ID**: `006-unified-recurrence` · **Run**: 2026-08-12

**Artifacts analysed**: `spec.md`, `plan.md`, `research.md`, `data-model.md`,
`contracts/openapi-delta.md`, `quickstart.md`, `tasks.md`, `checklists/requirements.md`,
`docs/design/recurrence-engine.md`, `docs/design/data-conventions.md`,
`docs/design/delivery-roadmap.md`, `.specify/memory/constitution.md`.

## Coverage

| Metric | Result |
|---|---|
| Functional requirements | 30 |
| Requirements with at least one task | 30 / 30 |
| Success criteria with at least one task | 9 / 9 |
| User stories with an independent test | 5 / 5 |
| Tasks with a named repository path | 30 / 30 |

## Findings

### Critical

None.

### High

- **H1 — the cutover drops live schema.** Dropping `routine_weekdays` and four `routines` columns is the
  most dangerous step in the feature. *Resolution*: T003 writes the preservation and reversibility tests
  before T004 implements the migration; the migration backfills and verifies before dropping; `down()`
  restores the old shape from the rules; T029 runs it against a disposable data-bearing database. This
  is the same sequence feature 001 used when it moved `routines.weekdays`, which succeeded on live data.

### Medium

- **M1 — two places appear to hold "done".** `routine_logs.status` and `planned_occurrences.status`.
  *Resolution*: recorded as accepted deviation AD-1. The occurrence status is strictly derived, written
  by exactly one service, recomputable by `recurrence:reconcile`, and covered by a test that rebuilds it
  from the logs. `routine_logs` remains the only authoritative fact and the only public contract.
- **M2 — expansion and materialization could drift.** Two code paths could answer differently.
  *Resolution*: SC-003 and T017 assert set equality between the materialized window and the expansion
  over the same range, so drift fails the suite rather than reaching a user.
- **M3 — "no observable change" is a negative requirement.** *Resolution*: T008 asserts the exact
  response key set and values, and the untouched feature-001 suites act as the behavioural baseline: any
  drift in Today, progress or streaks fails tests that were written before this feature existed.

### Low

- **L1 — `slot` uses `''` rather than `NULL`.** Slightly unusual, but `NULL`s are distinct in a MySQL
  unique index, which would silently permit duplicate occurrences. Documented in the data model.
- **L2 — materialization is not scheduled automatically.** Without a cron entry the window stops
  extending. *Resolution*: acceptable because expansion, not the window, drives every current behaviour.
  Recorded as a known limitation; the scheduler arrives with feature 010, which is the first consumer
  that actually needs a fresh window.

## Consistency checks

- **Design ↔ spec**: entity names, the `+90` window and the idempotency key match
  `recurrence-engine.md`. Its open question 2 (rule edits versus materialized occurrences) is resolved
  here and T027 records the resolution in the design document itself, as principle II requires.
- **Data conventions**: weekdays are a child table rather than JSON because they are filtered and
  validated; `user_id` is on every new table; calendar dates stay day-precise; instants stay UTC.
- **Roadmap ↔ spec**: prerequisites (004, 005) and the deferral list agree.
- **Contracts ↔ tasks**: every internal signature in the contract document has an implementing task.
- **Tasks ↔ phases**: dependencies are acyclic; every `[P]` marking names a distinct file.

## Ambiguities resolved before implementation

| Question | Resolution | Where |
|---|---|---|
| Cutover or adapter? | Single cutover, no surviving adapter | R2, FR-007 |
| What answers "is scheduled"? | Pure expansion, any date | R1, FR-008 |
| Why keep occurrences at all? | Durable identity for future days | R1, FR-005 |
| Where does completion live? | `routine_logs`, occurrence status derived | R3, AD-1 |
| Window size and trigger | 90 days; rule writes and a console command | R5, FR-013, FR-018 |
| Rule edited after materialization | Regenerate unmarked, keep linked | R6, FR-016, FR-017 |
| Which frequencies? | `daily` and `weekly` only | R8, FR-002 |

## Verdict

**Ready for implementation.** No critical finding. One high finding is fully mitigated by ordering the
migration tests before the migration; three medium findings are resolved by existing tasks.
