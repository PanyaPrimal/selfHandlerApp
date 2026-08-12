# Feature Specification: Planner and Day Planning

**Feature ID**: `009-planner-day`

**Created**: 2026-08-12

**Status**: Ready for implementation

**Input**: Give the day one surface that shows everything already scheduled — routine occurrences,
dated Storage work, and the user's own time blocks — and let a specific day be rescheduled or skipped,
without Planner becoming a second owner of anything it displays.

**Design sources**: [Module 5 — Planner](../../docs/design/modules.md#module-5--planner) ·
[Recurrence Engine](../../docs/design/recurrence-engine.md) ·
[Delivery Roadmap — 009](../../docs/design/delivery-roadmap.md#009--planner-and-day-planning) ·
[Feature 006](../006-unified-recurrence/spec.md) · [Feature 008](../008-storage-inbox/spec.md)

## Why This Feature Exists

Three modules now know when something should happen, and none of them can show a day. Today lists
routines. Storage holds tasks with due dates. Neither knows about the other, and nothing at all holds
"dentist at 14:00" — an appointment that belongs to no module.

The design is explicit about what Planner is: "the Planner does not produce domain data itself — it
plans/displays/reminds about it; the sources are the modules". So the value here is a *boundary*, not
a new store. Get it wrong and every later module copies its records into Planner; get it right and
habits, workouts and supplements simply appear.

This also answers open question 6 of the recurrence design — "how the Planner aggregates occurrences
from all modules into a single calendar: a `Schedulable` view/contract".

## Clarifications

### Session 2026-08-12

- Q: How does Planner read from other modules?
  A: A `SchedulableSource` contract with a registry. Each source answers "what is on this day for this
  user" and nothing else. Three real implementations ship with it: routine occurrences, dated Storage
  items, and Planner's own time blocks. A later module registers a source; it never writes into Planner.
- Q: What does Planner itself own?
  A: Exactly one new fact: the time block. Everything else it shows belongs to the module that produced
  it, and is edited through that module's rules.
- Q: What is the difference between skipping and rescheduling?
  A: Skipping records that the day happened and the thing did not — it is a domain fact, so for a
  routine it writes the routine log that already exists. Rescheduling says the plan moved and nothing
  has happened yet — it is engine-side, so it writes to the occurrence. They are not two flavours of the
  same action, and the design keeps both because the user chooses which one is true.
- Q: How is a rescheduled occurrence stored?
  A: A `rescheduled_to` date on the occurrence, added additively. The occurrence keeps its identity and
  its original date; it is simply shown on the new day. That preserves the history of what was
  originally planned, which deleting-and-recreating would destroy.
- Q: Can a dated Storage task be skipped?
  A: No. A task is not a recurrence — it has one due date, so "moving it" is editing that date through
  Storage's own endpoint, and "not doing it" is its existing status. Planner offers the move and defers
  to Storage for the rest.
- Q: Does the materialization window get scheduled now?
  A: Yes. Feature 006 deferred it to "the first consumer that needs a fresh window", and this is that
  consumer: rescheduling attaches to a materialized occurrence, so the window has to stay ahead of the
  user. A scheduler service runs it daily.
- Q: Do reminders arrive with this?
  A: No. The design puts delivery, channels, escalation and quiet hours in Notifications, which is
  feature 011. Planner shows what is scheduled; it does not notify.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - See One Day (Priority: P1)

As a signed-in user, I open a day and see everything scheduled for it in one place, whatever module it
came from.

**Independent Test**: With a routine due today, a Storage task due today and a time block today, open
the day and see all three, each labelled with where it came from.

**Acceptance Scenarios**:

1. **Given** sources with entries for a day, **When** the day is opened, **Then** every entry appears
   once, ordered by time and then by title, with untimed entries after timed ones.
2. **Given** a day with nothing on it, **When** it is opened, **Then** it says so rather than rendering
   an empty frame.
3. **Given** an entry, **When** it is displayed, **Then** it names its source and carries the identifier
   needed to act on it.
4. **Given** a day is opened without choosing one, **When** it resolves, **Then** it is the user's today
   in their profile time zone, never the browser's.
5. **Given** another user's data exists for the same day, **When** the day is read, **Then** none of it
   appears.

---

### User Story 2 - Move Or Skip A Planned Day (Priority: P1)

As a user, when something planned will not happen today, I either move it or record that it was skipped,
and the two mean different things.

**Independent Test**: Reschedule one routine occurrence to tomorrow and skip another, then confirm the
first appears tomorrow and the second is recorded as skipped today.

**Acceptance Scenarios**:

1. **Given** a routine occurrence today, **When** it is rescheduled to another day, **Then** it stops
   appearing today, appears on that day, and its original date is still recorded.
2. **Given** a rescheduled occurrence, **When** the reschedule is cleared, **Then** it returns to its
   original day.
3. **Given** a routine occurrence, **When** it is skipped, **Then** the existing routine log records the
   skip and the existing progress and streak behaviour treats it exactly as it does today.
4. **Given** an occurrence already satisfied by a completion, **When** a reschedule is attempted,
   **Then** it is refused: what already happened cannot be moved.
5. **Given** a reschedule to a day outside the materialized window or into the past, **When** it is
   attempted, **Then** it is refused with a field-level explanation and nothing is written.
6. **Given** a dated Storage task, **When** it is moved from Planner, **Then** its due date changes
   through Storage's own rules and nothing is duplicated into Planner.

---

### User Story 3 - Plan The Day With Time Blocks (Priority: P1)

As a user, I add my own blocks of time — an appointment, focused work — that belong to no module.

**Independent Test**: Create a block with a start and end time, see it in the day in time order, edit it
and delete it.

**Acceptance Scenarios**:

1. **Given** a title and a day, **When** a block is created, **Then** it appears on that day; times are
   optional.
2. **Given** a block with a start and an end, **When** the end is not after the start, **Then** it is
   refused with a field-level message and nothing is written.
3. **Given** blocks and module entries on the same day, **When** the day is read, **Then** they are
   interleaved in one time order rather than shown in separate lists.
4. **Given** a block, **When** it is edited or deleted, **Then** only that block changes.
5. **Given** overlapping blocks, **When** they are saved, **Then** both are kept: the product does not
   decide that overlap is a mistake.

---

### User Story 4 - Plan Tomorrow (Priority: P2)

As a user, I look at tomorrow and arrange it before it arrives.

**Independent Test**: Open tomorrow, add a block, move a routine occurrence into it, and confirm today
is unchanged.

**Acceptance Scenarios**:

1. **Given** the day surface, **When** tomorrow is selected, **Then** it shows tomorrow's entries and
   allows the same planning actions.
2. **Given** a change made to tomorrow, **When** today is reopened, **Then** today is unaffected.
3. **Given** a day far ahead, **When** it is opened, **Then** it either shows the expanded schedule or
   explains that it is beyond the planned window, rather than showing a misleading empty day.

---

### User Story 5 - Keep The Window Ahead (Priority: P2)

As the system, I keep the materialized window extended so a future day can be planned at all.

**Independent Test**: Run the scheduled materialization twice and confirm the window advances, stays
idempotent, and leaves marked days alone.

**Acceptance Scenarios**:

1. **Given** the deployment, **When** it runs, **Then** materialization runs on a daily schedule without
   anyone invoking it by hand.
2. **Given** the scheduled run, **When** it executes twice, **Then** the result is identical.
3. **Given** an occurrence linked to a fact or carrying a reschedule, **When** materialization runs,
   **Then** it is preserved.

## Requirements *(mandatory)*

### Functional Requirements — The Boundary

- **FR-001**: A `SchedulableSource` contract MUST define how a module reports its entries for one user
  and one calendar day.
- **FR-002**: Sources MUST be resolved through a registry, so adding a module means adding a source, not
  editing Planner.
- **FR-003**: Three sources MUST ship: routine occurrences, dated Storage items, and time blocks.
- **FR-004**: A planner entry MUST carry a source name, a stable identifier within that source, a title,
  an optional time, a status and whatever the interface needs to offer its actions.
- **FR-005**: Planner MUST NOT copy, cache or duplicate any record owned by another module.
- **FR-006**: Reading a day MUST use a bounded number of queries that does not grow with the number of
  entries.

### Functional Requirements — The Day

- **FR-007**: A day MUST be a `YYYY-MM-DD` calendar date; the default MUST be the user's today in their
  profile time zone.
- **FR-008**: Entries MUST be ordered by time, then title, with untimed entries after timed ones.
- **FR-009**: A day beyond the materialized window MUST be explained rather than shown as empty.
- **FR-010**: Every read and write MUST stay inside the owning account.

### Functional Requirements — Reschedule and Skip

- **FR-011**: A routine occurrence MUST be reschedulable to another calendar date, recorded on the
  occurrence without losing its original date.
- **FR-012**: A reschedule MUST be clearable, returning the occurrence to its original day.
- **FR-013**: Rescheduling an occurrence already linked to a completion MUST be refused.
- **FR-014**: Rescheduling into the past or beyond the materialized window MUST be refused with a
  field-level message and no write.
- **FR-015**: Skipping a routine occurrence MUST write the existing routine log; no parallel skip state
  may be introduced.
- **FR-016**: Existing Today, progress and streak behaviour MUST be unchanged by either action.
- **FR-017**: Moving a dated Storage item MUST go through Storage's existing update rules.

### Functional Requirements — Time Blocks

- **FR-018**: A time block MUST be owned by a user and carry a title, a calendar date, and optional
  start and end times.
- **FR-019**: An end time MUST be after its start time when both are present.
- **FR-020**: Blocks MUST be editable and deletable, and overlapping blocks MUST be allowed.

### Functional Requirements — Window

- **FR-021**: Materialization MUST run on a daily schedule in the deployed environment.
- **FR-022**: The scheduled run MUST remain idempotent and MUST preserve occurrences that are linked to
  a fact or carry a reschedule.

### Functional Requirements — Contracts and Interface

- **FR-023**: New endpoints MUST be documented in an OpenAPI contract held against the routes by a test.
- **FR-024**: No existing endpoint, payload or behaviour may change.
- **FR-025**: An authenticated `/planner` route MUST present the selected day, its entries, the planning
  actions and time-block management, built on the feature 005 control set.
- **FR-026**: Planner MUST appear in the navigation.
- **FR-027**: Empty days, beyond-window days and refused actions MUST each be explained.
- **FR-028**: The screen MUST work on desktop, at exactly 390×844, and from the keyboard, with no
  horizontal overflow.

### Key Entities

- **TimeBlock**: the only fact Planner owns — a titled span on one calendar day.
- **PlannerEntry**: a read-only projection of something a source reports for a day. Never persisted.
- **SchedulableSource**: the contract a module implements to appear in the day.

## Success Criteria *(mandatory)*

- **SC-001**: One day shows entries from all three sources, each once, in one order.
- **SC-002**: Reschedule moves an occurrence between days and is reversible, with the original date kept.
- **SC-003**: Skip produces exactly the routine log the existing Today screen produces.
- **SC-004**: Progress and streak values are unchanged by planner actions for the same data.
- **SC-005**: Rescheduling a completed occurrence, into the past, or beyond the window is refused with
  nothing written.
- **SC-006**: Two accounts never see each other's day.
- **SC-007**: Reading a day with many entries uses a bounded, fixed query count.
- **SC-008**: The scheduled materialization advances the window and is idempotent.
- **SC-009**: The documented contract matches the routes, enforced by a test.
- **SC-010**: The full Laravel suite, Pint, Vue type check, production build and both Playwright
  projects pass.

## Scope Boundaries

### Out of Scope

Reminders, notifications, escalation and quiet hours (feature 011); external calendar synchronisation
(025); habits, sleep, workouts, nutrition and supplement sources, each arriving with its own feature;
drag-and-drop rearranging; recurring time blocks; multi-day or all-day spanning events; and any
automatic planning or suggestion.

## Assumptions

- The profile time zone decides what "today" means.
- The feature 006 window is the source of routine occurrences; the feature 008 item is the source of
  dated work.

## Dependencies

Features 004, 005, 006 and 008. No new runtime dependency.
