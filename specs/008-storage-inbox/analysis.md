# Pre-Implementation Analysis: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox` · **Run**: 2026-08-12

**Artifacts analysed**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `quickstart.md`,
`tasks.md`, `checklists/requirements.md`, `docs/design/modules.md`, `docs/design/data-conventions.md`,
`docs/design/delivery-roadmap.md`, `.specify/memory/constitution.md`.

## Coverage

| Metric | Result |
|---|---|
| Functional requirements | 29 |
| Requirements with at least one task | 29 / 29 |
| Success criteria with at least one task | 9 / 9 |
| User stories with an independent test | 5 / 5 |
| Tasks with a named repository path | 26 / 26 |

## Findings

### Critical

None.

### High

None.

### Medium

- **M1 — this feature decides the task model for everything after it.** Planner, Habits and Review all
  read tasks; a shape chosen carelessly here is inherited by all of them. *Resolution*: the shape is not
  invented — `data-conventions.md` §2 already assigns Storage Item to single-table-plus-type, and the
  roadmap orders this feature before Planner precisely so Planner has something to read. The types that
  would strain the shape (purchase, list item) are deferred until their real fields exist, with the rule
  for revisiting written down in research R1.
- **M2 — a rule that refuses a write is easy to bypass later.** A second endpoint that closes an item
  would silently skip the blocking check. *Resolution*: the rule lives in `ItemCompletionGuard`, and
  T014 requires every closing path to consult it rather than reimplement it.

### Low

- **L1 — `due_on` looks like scheduling but is not.** It is a plain calendar date with nothing reading
  it. Recorded in the plan's gate 4 so a later reader does not mistake it for a second schedule beside
  feature 006.
- **L2 — the Project/List container question stays open.** Deliberate: there is no List to compare
  against yet, and inventing the shared container now is the speculative abstraction principle III
  forbids. Recorded in research R5 so the question survives.
- **L3 — tags are duplicated work if a second module wants them.** Accepted: the roadmap defers global
  tags explicitly, and the extraction trigger is named.

## Consistency checks

- **Design ↔ spec**: item base, types, statuses, parent/child with a blocker flag, projects and local
  tags all match `modules.md` Module 7. Two of its open questions (inbox as status, nesting depth) are
  answered here and the answers are recorded; the container question is explicitly left open.
- **Data conventions**: single-table plus type (§2), no STI magic (§2), `user_id` everywhere (§3),
  calendar dates day-precise and instants UTC (§5), module-owned aggregates (§7).
- **Roadmap ↔ spec**: prerequisites complete; the deferral list matches item for item.

## Ambiguities resolved before implementation

| Question | Resolution | Where |
|---|---|---|
| Storage shape? | Single table plus `type` | R1, per data-conventions §2 |
| Which types now? | `task` and `idea` only | R2 |
| Inbox: status or view? | Status | R3 |
| Nesting depth? | One level | R4 |
| Project and List one container? | Left open on purpose | R5 |
| Tag scope? | Storage-local | R6 |
| What does a blocker block? | Completion only | R7 |

## Verdict

**Ready for implementation.** No critical or high finding; both medium findings are resolved by
decisions and tasks already in the plan.
