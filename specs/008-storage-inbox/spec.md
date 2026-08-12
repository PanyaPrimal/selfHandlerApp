# Feature Specification: Storage Inbox and Quick Capture

**Feature ID**: `008-storage-inbox`

**Created**: 2026-08-12

**Status**: Ready for implementation

**Input**: Give the application one place to capture a thought in a single field, triage it later, group
it into a project, and break it into child items that can block their parent — without inventing a
second task model that Planner would later have to reconcile.

**Design sources**: [Module 7 — Storage](../../docs/design/modules.md#module-7--storage) ·
[Data Conventions](../../docs/design/data-conventions.md) ·
[Delivery Roadmap — 008](../../docs/design/delivery-roadmap.md#008--storage-inbox-and-quick-capture) ·
[Feature 005](../005-interface-foundation/spec.md)

## Why This Feature Exists

Everything the application holds today has to be fully formed before it can be recorded: a routine needs
a schedule, a goal needs a name and intent, a measurement needs a metric and a value. There is nowhere
to put "book the dentist" or "maybe learn to weld" at the moment it occurs to you.

That gap is what an inbox is for: one field, no decisions, sort it out later. The roadmap places this
before Planner deliberately — Planner displays and schedules tasks, and if it arrived first it would
have to invent its own task model, which every later module would then have to reconcile.

## Clarifications

### Session 2026-08-12

- Q: How is the polymorphic item stored?
  A: Single table plus a `type` column, as `data-conventions.md` §2 already decides for Storage: "items
  + type (task/idea/purchase/note) + shared fields; rare specifics go in nullable/JSON". The types in
  this increment differ by behaviour, not by fields, so no detail table is created.
- Q: Which types ship now?
  A: `task` and `idea` only. A purchase's defining rule is its link to money ("bought" ⟺ a linked
  expense or installment debt), and that belongs to Finance; shipping a purchase without it would mean
  a status that cannot mean what the design says it means. List items wait for the List container.
- Q: Is the inbox a status or a view?
  A: A status. `inbox` is a real state an item is captured into, so "how much is unsorted" is a plain
  count rather than a rule reimplemented per screen. This resolves an open question in `modules.md`.
- Q: How deep is the parent/child hierarchy?
  A: One level. A child cannot itself be a parent. That covers the two cases the design names — an idea
  with dependent items, and a task with subtasks — and keeps the blocking rule decidable without walking
  a tree. Deeper nesting waits for a case that needs it.
- Q: What exactly does a blocker block?
  A: A parent cannot be completed while any child marked as a blocker is still open. The parent's own
  completion is refused with a field-level explanation; nothing else about the parent is restricted.
- Q: Are tags shared with the rest of the application?
  A: No. They are Storage-local, as the roadmap requires. Extraction into an application-wide mechanism
  waits for a second module that needs compatible behaviour.
- Q: Do projects and lists share one container?
  A: Not yet. This increment ships `Project` only, because only tasks and ideas ship. The container
  question in `modules.md` is answered when list items arrive and there is something to compare.
- Q: Does anything schedule these items?
  A: No. An item may carry a due date as a plain calendar date, but nothing expands, reminds or plans.
  Planner owns that, and it reads this model rather than copying it.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Capture Without Deciding (Priority: P1)

As a signed-in user, I write one line and it is saved, so a thought never has to be organised at the
moment it arrives.

**Independent Test**: Type a title, save, and confirm the item exists in the inbox with no other input.

**Acceptance Scenarios**:

1. **Given** the capture field, **When** a title alone is submitted, **Then** an item is created with
   status `inbox` and type `task`, and no other field is required.
2. **Given** a captured item, **When** the inbox is read, **Then** it is listed, and the count of
   unsorted items reflects it.
3. **Given** an empty or whitespace-only title, **When** it is submitted, **Then** it is rejected with a
   field-level message and nothing is written.
4. **Given** several captures in a row, **When** the inbox is read, **Then** they are ordered newest
   first and the capture field is empty and focused for the next one.

---

### User Story 2 - Triage What Was Captured (Priority: P1)

As a user, I later give a captured item its type, project, priority and tags, and it leaves the inbox.

**Independent Test**: Capture an item, assign a project and a tag, and confirm it is no longer counted
as unsorted while keeping everything assigned.

**Acceptance Scenarios**:

1. **Given** an item in the inbox, **When** it is triaged, **Then** its status becomes `active` and it
   disappears from the inbox count.
2. **Given** an item, **When** its type is changed between task and idea, **Then** its identity, title,
   children and tags are preserved.
3. **Given** an item, **When** a project is assigned, **Then** it appears under that project.
4. **Given** an item, **When** tags are set, **Then** exactly those tags apply, and removing one removes
   only that one.
5. **Given** an item, **When** it is completed or dropped, **Then** it leaves the active list while
   remaining readable in its history.

---

### User Story 3 - Break Work Into Blocking Children (Priority: P1)

As a user, I attach child items to an idea or a task and mark the ones that must be finished first.

**Independent Test**: Create a parent with two children, mark one as a blocker, and confirm the parent
cannot be completed until that child is closed.

**Acceptance Scenarios**:

1. **Given** an item, **When** a child is attached to it, **Then** the child is listed under it and
   carries its own status.
2. **Given** a child marked as a blocker that is still open, **When** the parent is completed, **Then**
   the completion is refused with a message naming what is blocking it, and nothing changes.
3. **Given** the blocking child is completed or dropped, **When** the parent is completed again,
   **Then** it succeeds.
4. **Given** a non-blocking child that is still open, **When** the parent is completed, **Then** it
   succeeds.
5. **Given** a child, **When** something is attached to it, **Then** it is refused: nesting is one level.
6. **Given** an item, **When** it is made its own parent or an existing child's parent forms a cycle,
   **Then** it is refused.

---

### User Story 4 - Group Work Into Projects (Priority: P2)

As a user, I collect related items into a named project and see what is open in it.

**Independent Test**: Create a project, move two items into it, and confirm both appear under it with an
open count.

**Acceptance Scenarios**:

1. **Given** a project name, **When** it is created, **Then** it belongs to me and appears in the
   project list.
2. **Given** a project with items, **When** it is read, **Then** its open and completed counts are
   computed by this module.
3. **Given** a project is archived, **When** the project list is read, **Then** it is out of the way
   while its items keep their assignment and remain readable.
4. **Given** a project is deleted, **When** its items are read, **Then** they survive without a project
   rather than disappearing.

---

### User Story 5 - Use It On A Phone (Priority: P2)

As a phone user, I capture and triage from a 390px screen and with the keyboard alone.

**Independent Test**: At 390×844, capture an item, triage it, and attach a child using only the
keyboard.

**Acceptance Scenarios**:

1. **Given** a 390×844 viewport, **When** Storage is used, **Then** there is no horizontal overflow and
   every control is reachable.
2. **Given** the keyboard alone, **When** an item is captured and triaged, **Then** every step is
   operable and focus is never lost.
3. **Given** an empty inbox, **When** it is shown, **Then** it says so rather than rendering an empty
   frame.

## Requirements *(mandatory)*

### Functional Requirements — Item

- **FR-001**: An item MUST be owned by a user and MUST carry a title, a type, a status and a creation
  time.
- **FR-002**: Item types in this increment MUST be `task` and `idea`. No other type may be accepted.
- **FR-003**: Statuses MUST be `inbox`, `active`, `done` and `dropped`, with `inbox` the capture default.
- **FR-004**: A title MUST be required, trimmed, and rejected when empty after trimming.
- **FR-005**: Optional fields MUST be description, priority, due date (a calendar date), project and
  parent.
- **FR-006**: Completion and dropping MUST record their time on the server; the client MUST NOT supply it.
- **FR-007**: Items MUST be listable filtered by status, type, project, tag and parent, and every read
  MUST be bounded.

### Functional Requirements — Hierarchy and Blocking

- **FR-008**: An item MAY have one parent owned by the same user.
- **FR-009**: Nesting MUST be limited to one level: an item that has a parent MUST NOT become a parent.
- **FR-010**: An item MUST NOT be its own parent, and no parent relationship may form a cycle.
- **FR-011**: A child MAY be marked as a blocker.
- **FR-012**: Completing an item MUST be refused while any of its children is both a blocker and open,
  with a message naming the blocking children, and nothing may be written.
- **FR-013**: Deleting a parent MUST NOT silently delete its children; they MUST become parentless.

### Functional Requirements — Projects and Tags

- **FR-014**: A project MUST be owned by a user and MUST carry a name and an archived flag.
- **FR-015**: Project names MUST be unique per user.
- **FR-016**: A project MUST expose open and completed item counts computed by this module.
- **FR-017**: Deleting a project MUST leave its items intact and parentless of any project.
- **FR-018**: A tag MUST be owned by a user, named uniquely per user, and attachable to many items.
- **FR-019**: Setting an item's tags MUST replace the set exactly, creating tags that do not exist yet.
- **FR-020**: Tags MUST remain local to Storage; no other module may read or write them in this feature.

### Functional Requirements — Ownership and Contracts

- **FR-021**: Every read, write and relationship MUST stay inside the owning account; another user's
  identifiers MUST answer as not found.
- **FR-022**: A parent, project or tag belonging to another user MUST be refused.
- **FR-023**: New endpoints MUST be documented in an OpenAPI contract held against the routes by a test.
- **FR-024**: No existing endpoint, payload or behaviour may change.

### Functional Requirements — Interface

- **FR-025**: An authenticated `/storage` route MUST offer single-field capture, the inbox, triage, the
  project list and child management, built on the feature 005 control set.
- **FR-026**: Storage MUST appear in the navigation.
- **FR-027**: The capture field MUST clear and keep focus after a successful capture.
- **FR-028**: Empty inbox, empty project and no-children states MUST each be explained rather than blank.
- **FR-029**: The screen MUST work on desktop, at exactly 390×844, and from the keyboard, with no
  horizontal overflow.

### Key Entities

- **Item**: one captured thing. Single table plus `type`; carries status, optional project, optional
  parent, blocker flag, priority, due date and tags.
- **Project**: a named grouping of items, with counts computed by this module.
- **Tag**: a Storage-local label, unique per user, attached to many items.

## Success Criteria *(mandatory)*

- **SC-001**: An item is captured from a title alone, in one request, and lands in the inbox.
- **SC-002**: Two accounts cannot read, write or relate each other's items, projects or tags.
- **SC-003**: Completing a parent with an open blocking child is refused and writes nothing.
- **SC-004**: A second level of nesting and every parent cycle are refused.
- **SC-005**: Deleting a project or a parent leaves the affected items present and readable.
- **SC-006**: Setting tags replaces the set exactly, with no orphaned attachments.
- **SC-007**: Listing a large inbox stays within an explicit bound and a fixed query count.
- **SC-008**: The documented contract matches the routes and the vocabularies, enforced by a test.
- **SC-009**: The full Laravel suite, Pint, Vue type check, production build and both Playwright
  projects pass.

## Scope Boundaries

### Out of Scope

Purchases and their money link (Finance, feature 018+), list items and the List container, scheduling,
reminders and calendar placement (Planner 009, Notifications 011), converting an idea into a goal or a
project automatically, application-wide tags, recurring tasks, attachments, and AI triage. Each returns
with the feature that owns it.

## Assumptions

- The profile time zone from feature 004 decides what "today" means for a due date.
- The control set from feature 005 is available, including the searchable combobox used for projects.

## Dependencies

Feature 003 for ownership, 004 for the time zone, 005 for the controls. No new runtime dependency.
