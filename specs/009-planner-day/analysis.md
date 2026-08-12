# Pre-Implementation Analysis: Planner and Day Planning

**Feature ID**: `009-planner-day` · **Run**: 2026-08-12

**Artifacts analysed**: `spec.md`, `plan.md`, `research.md`, `data-model.md`, `quickstart.md`,
`tasks.md`, `checklists/requirements.md`, `docs/design/modules.md`,
`docs/design/recurrence-engine.md`, `docs/design/delivery-roadmap.md`, `.specify/memory/constitution.md`.

## Coverage

| Metric | Result |
|---|---|
| Functional requirements | 28 |
| Requirements with at least one task | 28 / 28 |
| Success criteria with at least one task | 10 / 10 |
| User stories with an independent test | 5 / 5 |
| Tasks with a named repository path | 29 / 29 |

## Findings

### Critical

None.

### High

- **H1 — Planner could quietly become a second owner.** It displays other modules' records and offers
  actions on them; the easy implementation copies or caches them, and every later module then has to
  reconcile with a Planner table. *Resolution*: FR-005 forbids it, sources are read-only by contract,
  and every action is routed to the owning endpoint. T012 asserts that the *owning* table changed — a
  copy would satisfy a naive assertion but fails this one.

### Medium

- **M1 — reschedule and materialization can fight.** Moving an occurrence by editing `occurrence_date`
  would make the next run see a missing day and recreate it, silently duplicating. *Resolution*:
  `rescheduled_to` is a separate column, the expanded date is immutable, and T015 asserts a rescheduled
  occurrence survives a materialization run.
- **M2 — two ways to skip.** A planner-side skip state would diverge from the routine log that Today,
  progress and streaks already read. *Resolution*: FR-015 forbids a parallel state; skip writes the
  existing log, and T012 compares the row Planner produces with the one Today produces.

### Low

- **L1 — overlapping time blocks are allowed.** Deliberate: noting a conflict is a normal use, and
  refusing it would make the planner argue with the user about their own day. Recorded in research R4.
- **L2 — the day is assembled on every read.** Bounded by one query per source and asserted by a
  query-count test; caching would reintroduce exactly the drift H1 is about.
- **L3 — the scheduler needs a process in the deployment.** A `scheduler` service is added to the local
  compose. Without it the window silently stops advancing, which T018 guards at the code level and the
  quickstart covers operationally.

## Consistency checks

- **Design ↔ spec**: matches `modules.md` Module 5 — Planner as hub, sources are the modules, both skip
  and reschedule offered, notifications and calendar sync excluded. Answers open question 6 of
  `recurrence-engine.md`; T026 records the answer there.
- **Roadmap ↔ spec**: prerequisites 006 and 008 are complete; "define one read/interaction boundary
  instead of copying records into Planner" is FR-005; the deferral list matches.
- **Feature 006 ↔ this**: the deferred materialization scheduler is picked up here, at the consumer that
  needs it, exactly as that feature's analysis said it would be.

## Ambiguities resolved before implementation

| Question | Resolution | Where |
|---|---|---|
| How does Planner read other modules? | A source contract plus a registry | R1, FR-001 |
| What does Planner own? | Time blocks, and the reschedule pointer | R4, data model |
| Skip versus reschedule? | A past fact versus a moved plan; different stores | R2, FR-011/FR-015 |
| How is a reschedule stored? | A new column; the expanded date is immutable | R2, M1 |
| Can a dated task be skipped? | No; its due date moves through Storage | R3, FR-017 |
| Is the window scheduled now? | Yes; this is its first real consumer | R5, FR-021 |

## Verdict

**Ready for implementation.** No critical finding. The one high finding is the reason the feature
exists, and it is mitigated by the contract shape plus a test that would catch a copy.
