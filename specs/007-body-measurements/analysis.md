# Pre-Implementation Analysis: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements` · **Run**: 2026-08-12

**Artifacts analysed**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `contracts/api.md`,
`quickstart.md`, `tasks.md`, `checklists/requirements.md`, `docs/design/modules.md`,
`docs/design/data-conventions.md`, `docs/design/delivery-roadmap.md`, `.specify/memory/constitution.md`.

## Coverage

| Metric | Result |
|---|---|
| Functional requirements | 30 |
| Requirements with at least one task | 30 / 30 |
| Success criteria with at least one task | 10 / 10 |
| User stories with an independent test | 5 / 5 |
| Tasks with a named repository path | 26 / 26 |

## Findings

### Critical

None.

### High

None.

### Medium

- **M1 — health guidance is a reputational and safety surface.** A wrong number here is worse than a
  wrong number anywhere else in the application. *Resolution*: exactly one boundary is cited (CDC, 1-2
  lb per week, linked in research R6) and exactly one is a labelled product limitation whose message
  says so. Metrics with neither produce no warning at all, enforced by FR-024. Nothing diagnoses,
  recommends or blocks; the user's target is stored exactly as typed.
- **M2 — the Profile and the log hold overlapping quantities.** Weight and body-fat exist in both.
  *Resolution*: FR-009 forbids either writing the other, and T010 asserts it. A future "copy today's
  weight into my profile" is an explicit user action for a later feature, not an implicit rule now.

### Low

- **L1 — `goal_milestones` is a general table introduced by one consumer.** Justified: `modules.md`
  states milestones are a general mechanism for goals of any type, and this feature is its first real
  consumer, which is exactly what constitution III requires.
- **L2 — the trend has no smoothing.** Deliberate: with sparse manual entries, smoothing would
  interpolate observations the user never made. Recorded in research R5.
- **L3 — body goals use their own endpoints rather than extending `/api/goals`.** This keeps the
  existing goal contract untouched, which matters because feature 001's suites assert it. The goal row
  itself is shared, so there is still only one goal system.

## Consistency checks

- **Design ↔ spec**: the measurement log matches `modules.md` Module 0 ("a log with history, not a
  single value"); the body goal matches Module 4 ("a single Goal entity … the type defining the
  specifics", "progress is measured by the measurement log").
- **Data conventions**: canonical base units (§6), decimal not float (§1's reasoning), single-table plus
  type for structurally identical rows and a detail table for the divergent goal type (§2), `user_id`
  everywhere (§3), calendar dates day-precise (§5), aggregates owned by the module (§7).
- **Roadmap ↔ spec**: prerequisites 004 and 005 are complete; the deferral list matches.
- **Contracts ↔ tasks**: every endpoint and every internal signature has an implementing task.

## Ambiguities resolved before implementation

| Question | Resolution | Where |
|---|---|---|
| Profile or log owns the fact? | Both, separately; neither writes the other | R1, FR-009 |
| How are metrics extensible? | One row per observation, enum-validated metric column | R2, FR-003 |
| Canonical value? | Metric base unit as `DECIMAL` | R3, FR-002 |
| Duplicate date? | Unique per user/metric/date; re-saving is a correction | FR-004 |
| Trend method? | Least squares, change per week, explicit states | R5, FR-010 |
| Safe pace numbers? | CDC for loss; labelled product limitation for gain; none invented | R6, FR-022, FR-024 |
| Milestone achievement stored? | No, derived at read time | FR-019 |

## Verdict

**Ready for implementation.** No critical or high finding. Both medium findings are resolved by
requirements and tasks already in the plan.
