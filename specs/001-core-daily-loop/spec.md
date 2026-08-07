# Feature Specification: Core Daily Loop

**Feature ID**: `001-core-daily-loop`

**Created**: 2026-08-07

**Status**: Draft

**Input**: Establish the first usable SelfHandler product slice around daily routines, an evening
review, supporting goals, and simple progress feedback. Existing prototype code may be reused only
where it satisfies this specification.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Plan and Complete Today's Routines (Priority: P1)

As the user, I create the small set of routines that matter to me, see only the routines scheduled
for the selected day, and mark each one done or skipped so I always know what remains today.

**Why this priority**: The daily checklist is the smallest complete SelfHandler loop and provides
value even if no other part of the product has been delivered.

**Independent Test**: Create a weekday routine, open a matching day, mark the routine done, and
confirm that its state and the daily completion summary survive a reload.

**Acceptance Scenarios**:

1. **Given** the user has no routines, **When** they create an active daily routine, **Then** it
   appears on Today for the selected date in the chosen order.
2. **Given** a routine is scheduled today, **When** the user marks it done, **Then** the item is shown
   as done and today's completion rate is recalculated immediately.
3. **Given** a routine is scheduled today, **When** the user marks it skipped, **Then** the skip is
   recorded explicitly and the item is not counted as completed.
4. **Given** a weekday routine is not scheduled on the selected day, **When** the user opens Today,
   **Then** that routine is not included in the checklist or completion denominator.
5. **Given** a routine was already handled for a date, **When** the user changes its state, **Then**
   the new state replaces the previous state rather than creating a duplicate entry.

---

### User Story 2 - Complete an Evening Review (Priority: P2)

As the user, I record a short reflection for a calendar day so I can preserve how the day felt and
what I want to improve tomorrow.

**Why this priority**: Reflection closes the daily loop and creates useful history without requiring
the broader analytics module.

**Independent Test**: Open the review for any date, enter ratings and reflections, save it, reload
the same date, and confirm that the saved values are restored.

**Acceptance Scenarios**:

1. **Given** no review exists for the selected date, **When** the user saves valid ratings and text,
   **Then** one completed review is stored for that date.
2. **Given** a review already exists, **When** the user edits and saves it, **Then** the existing
   review is updated without creating a second review for the date.
3. **Given** the user enters a rating outside the allowed range, **When** they attempt to save,
   **Then** the review is not saved and the invalid field is explained clearly.
4. **Given** a review was saved, **When** the user returns to Today for that date, **Then** Today
   indicates that the review is complete and provides access to it.

---

### User Story 3 - Connect Routines to Goals (Priority: P3)

As the user, I create a goal and connect one or more routines to it so today's actions have visible
context and purpose.

**Why this priority**: Goals make the daily checklist meaningful, while remaining optional for users
who only need routines.

**Independent Test**: Create a goal and a routine, link them, verify the goal context on Today, then
unlink them and verify that both records still exist independently.

**Acceptance Scenarios**:

1. **Given** an active goal and a routine exist, **When** the user links them, **Then** the
   relationship is visible from goal management and from the routine's Today context.
2. **Given** a goal is linked to several routines, **When** one link is removed, **Then** only that
   relationship is removed and neither the goal nor routine is deleted.
3. **Given** a completed or abandoned goal is linked to a routine, **When** Today is opened, **Then**
   the inactive goal is not presented as active motivation.

---

### User Story 4 - Understand Recent Progress (Priority: P4)

As the user, I see a concise progress summary so I can understand today's completion, each routine's
current streak, and my overall consistency during the last seven calendar days.

**Why this priority**: Immediate feedback reinforces continued use, but it depends on the core loop
already producing history.

**Independent Test**: Prepare seven days of routine history with known outcomes and verify today's
completion, the routine streak, and the seven-day completion rate against manual calculations.

**Acceptance Scenarios**:

1. **Given** scheduled routines have mixed done, skipped, and pending states today, **When** Today is
   opened, **Then** the displayed counts and completion rate match those states.
2. **Given** a routine has consecutive completed scheduled occurrences ending on the selected date,
   **When** progress is viewed, **Then** its current streak equals that consecutive count.
3. **Given** seven days include scheduled and unscheduled routines, **When** recent progress is
   viewed, **Then** only scheduled occurrences are included in the completion denominator.
4. **Given** no routines were scheduled in the measured period, **When** progress is viewed, **Then**
   the summary shows an empty state rather than an undefined or misleading percentage.

### Edge Cases

- The selected date crosses midnight in the user's configured time zone while the app remains open.
- A routine starts after or ends before the selected date.
- A user tries to change schedule-defining fields after the routine already has history.
- A weekday routine contains no selected weekdays.
- An archived or deleted routine has historical logs that must remain available for past summaries.
- A goal or routine is removed while the user has an older screen open.
- The same save or mark action is submitted more than once because of retries or repeated taps.
- The application cannot load or save data; existing on-screen input must not be reported as saved.
- Text fields contain leading or trailing whitespace, Unicode, or reach their allowed size limit.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The product MUST let the current user create, view, edit, archive, and restore routines.
- **FR-002**: A routine MUST have a name, an active state, an explicit display order, and either a
  daily schedule or a non-empty set of weekdays.
- **FR-003**: A routine MAY include a description, a category, a preferred time, a start date, and
  an end date. After its first daily log exists, schedule type, weekdays, and start date MUST remain
  immutable; the user is guided to archive it and create a replacement instead of rewriting history.
- **FR-004**: Today MUST show the routines scheduled for the selected calendar date in stable order.
- **FR-005**: The user MUST be able to record exactly one current state per routine and calendar date:
  pending by absence of a record, done, or explicitly skipped.
- **FR-006**: Repeating a state-changing action MUST update the existing daily record without creating
  duplicates or corrupting the completion summary.
- **FR-007**: Today MUST show scheduled, done, skipped, and pending counts plus a completion rate based
  on done divided by scheduled routines.
- **FR-008**: The product MUST let the current user create, view, edit, complete, abandon, archive, and
  restore general goals.
- **FR-009**: The user MUST be able to link any number of active routines to any number of goals and
  remove a link without deleting either side.
- **FR-010**: Today MUST show active goal context associated with its scheduled routines without
  showing completed, abandoned, archived, or deleted goals as active motivation.
- **FR-011**: The product MUST store at most one daily review for the current user and calendar date.
- **FR-012**: A daily review MUST allow mood, energy, stress, and overall day ratings from 1 through 10
  plus optional reflections for what went well, what to improve tomorrow, and additional notes.
- **FR-013**: Saving a review MUST clearly distinguish saved, saving, validation-failed, and
  service-failed states.
- **FR-014**: The product MUST calculate a current streak per routine from consecutive completed
  scheduled occurrences and MUST break the streak on a scheduled occurrence that is skipped or left
  incomplete after that date has ended.
- **FR-015**: The product MUST show an overall completion rate for the selected day and the trailing
  seven-calendar-day period using only scheduled occurrences in each denominator.
- **FR-016**: Every feature record and relationship MUST belong to the current user, and data belonging
  to a different user MUST never appear or be changed through this feature.
- **FR-017**: All date-based behavior MUST use the user's configured time zone when determining a
  calendar day and whether a scheduled occurrence has ended.
- **FR-018**: The primary flows MUST remain usable at phone and desktop viewport sizes.
- **FR-019**: Loading, empty, validation, service-error, saved, and retry states MUST be explicit for
  every primary flow rather than represented by a blank or misleading screen.
- **FR-020**: Historical routine logs and reviews MUST remain available when a routine or goal is
  archived, restored, or removed from current planning.

### Scope Boundaries

This feature includes the core online daily loop only. It explicitly excludes account registration
and sign-in screens, collaboration, the full recurrence engine, notifications, offline synchronization,
native mobile packaging, long-period analytics, external integrations, attachments, finance, nutrition,
workouts, and AI assistance. Those capabilities require separate feature specifications.

### Key Entities

- **Routine**: A user-owned repeatable action with descriptive information, a simple daily or weekday
  schedule, an active/archive state, validity dates, and display order.
- **Routine Log**: The user-owned handling state of one routine on one calendar date; it records done
  or skipped while absence represents pending.
- **Daily Review**: One user-owned reflection per calendar date with bounded ratings, optional text,
  and a completion moment.
- **Goal**: A user-owned desired outcome with active, completed, or abandoned lifecycle states and an
  optional target date.
- **Goal-Routine Link**: A user-owned many-to-many relationship explaining which routines support
  which goals.
- **Progress Summary**: A derived view of scheduled occurrences and logs for today, recent completion,
  and routine streaks; it is not an independently edited record in this feature.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A first-time user can create a daily routine, find it on Today, and mark it done in less
  than two minutes without guidance.
- **SC-002**: After a routine state change, the visible item state and daily completion summary agree
  with the saved result within two seconds under normal local-network conditions.
- **SC-003**: A user can complete and save an evening review in less than three minutes, and 100% of
  successfully saved fields are restored when the same date is reopened.
- **SC-004**: For a controlled seven-day history, displayed counts, completion rates, and streaks match
  manual calculations in every defined acceptance scenario.
- **SC-005**: The complete P1 daily-routine journey can be completed without horizontal scrolling or
  inaccessible controls at both a 390-pixel-wide viewport and a standard desktop viewport.
- **SC-006**: Automated ownership scenarios demonstrate that one user cannot read or change another
  user's routines, logs, reviews, goals, or relationships through any feature operation.
- **SC-007**: Repeating any supported save or mark action produces one logical result and no duplicate
  routine log, review, or goal-routine relationship in all acceptance scenarios.
- **SC-008**: Every primary screen provides a meaningful loading, empty, success, validation-error,
  and service-error outcome during acceptance testing.

## Assumptions

- The first delivery is online-only and behaves as a personal single-user product, while all data is
  modeled with enforceable user ownership for future authenticated use.
- Account registration and sign-in are outside this feature; a preconfigured personal user is
  available for the first delivery, and production access control requires a separate feature before
  public deployment.
- The user's profile is the eventual source of time-zone settings. Until the profile feature exists,
  one explicit application-level time zone is used consistently and is documented in the plan.
- Routine schedules in this feature are intentionally limited to daily or selected weekdays. More
  expressive recurrence belongs to the separately designed recurrence engine.
- A skipped scheduled routine counts in the denominator but not the numerator. An unscheduled routine
  counts in neither.
- The selected date is the end of the streak window; future dates do not create pending failures.
- No existing production user data needs to be preserved for the first delivery.
- Schedule-defining fields become immutable after the first log so seven-day summaries remain
  reproducible without implementing schedule versioning or the full recurrence engine.
