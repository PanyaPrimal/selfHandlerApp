# Feature Specification: Sleep and Rich Routine Templates

**Feature ID**: `014-sleep-routine-templates`

**Created**: 2026-08-13

**Status**: Complete

**Input**: User description: "Implement the complete non-deployment Sleep and Rich Routine Templates
vertical slice from the canonical design: plan and record sleep, build ordered morning/evening
templates with independently completable activities, retain the shared recurrence/Planner source,
and feed module-owned summaries into Today and Review."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Plan and Record a Night (Priority: P1)

The user creates one current sleep plan with a recurring bedtime and planned wake time, then records
actual bedtime, wake time, quality, and an optional note for one scheduled night. The user can correct
or clear that record without creating a duplicate or losing the original planned times.

**Why this priority**: Planned versus actual sleep is the smallest complete sleep loop and the input
required by every later sleep summary.

**Independent Test**: Create a daily plan, record a cross-midnight night, reload, correct quality and
wake time, then clear it. Exactly one fact and one planned occurrence remain linked throughout.

**Acceptance Scenarios**:

1. **Given** no active sleep plan, **When** the user creates one with bedtime 23:00 and wake time
   07:00, **Then** future bedtime occurrences and their planned wake snapshots are materialized in the
   user's timezone.
2. **Given** a scheduled night, **When** the user records actual local bedtime/wake fields and quality
   1–10, **Then** one UTC-backed fact is linked to that occurrence and the exact duration is derived.
3. **Given** an existing record, **When** the user corrects it, **Then** the same fact changes and all
   summaries recalculate from source data.
4. **Given** an invalid DST wall time, reversed/over-24-hour interval, foreign plan, unscheduled date,
   or unknown field, **When** a write is attempted, **Then** the request fails atomically with localized
   feedback and no fact or occurrence state changes.

---

### User Story 2 - Build an Ordered Routine Template (Priority: P1)

The user classifies a routine as morning, evening, or anytime and defines ordered activities. Every
activity has a name, required unique order, optional time, and optional numeric progress ceiling.

**Why this priority**: A template is not a renamed checklist unless its activity order and progress
contract are durable and owned by the existing routine.

**Independent Test**: Create morning and evening templates, replace/reorder their activity lists
atomically, reload, and prove invalid/foreign/duplicate orders preserve the accepted list.

**Acceptance Scenarios**:

1. **Given** a routine without facts, **When** activities are replaced with a valid ordered list,
   **Then** the list reloads in exact order with optional times and totals intact.
2. **Given** a template with its first activity fact, **When** a request adds/removes an activity or
   changes a numeric ceiling, **Then** structure is locked so historical meaning cannot drift.
3. **Given** an existing simple routine, **When** feature 014 is applied, **Then** it remains an anytime
   routine with its existing schedule, logs, goals, Planner entry, and direct completion behavior.

---

### User Story 3 - Complete Activities Independently (Priority: P1)

On Today the user marks each scheduled template activity done or skipped and may record numeric
progress. The routine occurrence closes only when every active activity is resolved.

**Why this priority**: Independent completion is the defining difference between a rich routine and
the pre-existing one-action routine.

**Independent Test**: Resolve a three-activity template one item at a time, correct and clear one
result, and prove the parent routine fact/occurrence mirrors pending, all-done, and any-skipped states.

**Acceptance Scenarios**:

1. **Given** a rich template with pending activities, **When** one is completed, **Then** its own fact
   is saved but the parent occurrence remains planned.
2. **Given** all activities are done, **When** the final fact is saved, **Then** one derived parent
   routine log is `done` and the existing planned occurrence is satisfied.
3. **Given** all activities are resolved and at least one was skipped, **When** the final fact is saved,
   **Then** the derived parent routine log is `skipped` while individual outcomes remain visible.
4. **Given** a rich template, **When** direct parent completion is attempted, **Then** it is rejected;
   clearing the parent clears that date's activity facts, while Planner skip resolves remaining
   activities as skipped through the routine module.

---

### User Story 4 - Choose Morning and Evening Independently (Priority: P2)

For a local date the user chooses one eligible morning template and one eligible evening template,
or explicitly chooses none for either slot. Anytime routines remain independently scheduled.

**Why this priority**: Day planning requires a choice between templates without creating a second
routine scheduler or copying occurrences into Planner.

**Independent Test**: Schedule two candidates per slot, verify deterministic defaults, replace only
the intended morning/evening choices in one atomic request, choose none, and reject foreign,
wrong-period, unscheduled, moved-away, or fact-bearing alternatives.

**Acceptance Scenarios**:

1. **Given** no explicit selection, **When** a day is read, **Then** the lowest sort/name/id scheduled
   candidate is the default for each slot.
2. **Given** explicit morning and evening selections, **When** Today and Planner are opened, **Then**
   both expose the same selected parent occurrences and all anytime routines.
3. **Given** explicit null for a slot, **When** the day is read, **Then** no routine is projected for
   that slot even if candidates exist.
4. **Given** a selected template already has a fact, **When** the user tries to select it away, **Then**
   the request is rejected without hiding history.

---

### User Story 5 - Review Module-Owned Summaries (Priority: P2)

Today and Daily Review show read-only sleep and routine-template summaries for the selected local
date. Sleep owns duration/quality aggregates; the routine module owns activity completion aggregates.

**Why this priority**: Review should provide context without recomputing or persisting another
module's health/productivity data.

**Independent Test**: Seed controlled sleep and activity facts, request Today and open Review, and
verify both render identical module summaries before and after corrections with a bounded query count.

**Acceptance Scenarios**:

1. **Given** a planned/recorded night, **When** Today or Review loads its night date, **Then** planned
   and actual times, duration, quality, and trailing range averages come from the sleep module.
2. **Given** selected templates with partial results, **When** Today or Review loads, **Then** activity
   scheduled/done/skipped/pending counts and completion percentage come from the routine module.
3. **Given** no sleep plan, no night fact, or no rich activities, **When** summaries load, **Then**
   honest empty/not-recorded states are shown rather than zero-quality or inferred sleep.
4. **Given** the user saves a Daily Review, **When** module facts later change, **Then** the Review row
   remains untouched and the read-only context updates from its owners.

---

### User Story 6 - Use the Slice Across Planner, Reminders, Locales, and Lifecycle (Priority: P3)

Sleep and selected routine templates work in Planner and in-app reminders, while pause/archive/restore,
ownership, accessibility, three locales, desktop, and the bundled Android client remain coherent.

**Why this priority**: A planning module is incomplete if other day surfaces remind about unselected
templates or lose facts when lifecycle state changes.

**Independent Test**: Exercise Planner/reschedule/reminder/lifecycle flows in EN/RU/UK at desktop and
390×844, including quiet hours, dedupe, closure, focus, overflow, reload, and rollback failures.

**Acceptance Scenarios**:

1. **Given** a selected timed routine and a planned bedtime, **When** notification synchronization
   runs, **Then** only those actionable occurrences receive deduplicated localized reminders.
2. **Given** an unselected candidate, explicit-none slot, untimed routine, paused/archived owner, or
   satisfied occurrence, **When** synchronization runs, **Then** no pending reminder remains.
3. **Given** a sleep occurrence without a fact, **When** Planner loads, **Then** it appears through a
   sleep source with planned wake context and may be rescheduled; recording sleep closes its family.
4. **Given** any supported locale/theme/client, **When** the slice is used by keyboard or touch, **Then**
   all copy, formatting, focus, live feedback, 44px controls, and safe-area layout remain usable.

## Edge Cases

- A night is identified by the local calendar date on which its planned bedtime begins. Actual bedtime
  may be on that date or the next; actual wake must be later and the interval may not exceed 24 hours.
- Planned wake equal to planned bedtime is invalid; an earlier wake time means the following local day.
- Nonexistent DST wall times are rejected. Ambiguous fall-back wall times use the backend's documented
  deterministic offset and return the resulting UTC instant so corrections remain explicit.
- One user may have many archived/paused sleep plans but at most one active non-archived plan.
- A sleep-plan edit refreshes only unlinked occurrences/details; linked planned times and facts survive.
- Routine activity order is unique inside a template. Numeric totals and activity membership lock after
  the first activity/parent fact; cosmetic name/order/time edits may not change aggregate semantics.
- Missing activity facts remain pending for an open/current occurrence and failed for strict parent
  completion; they are never inferred done.
- An explicit null day selection is different from no selection row: null means none, absence uses the
  deterministic scheduled default.
- Selection candidates must have an effective occurrence on the date. Rescheduled-away candidates are
  ineligible; a moved-here occurrence retains its own routine period.
- Existing simple routine logs remain authoritative and no activity rows are synthesized for them.
- Deleting a routine/sleep plan is not exposed. Archive/restore retains definitions, occurrences,
  activities, facts, selections, notification history, and summaries.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST extend the authenticated Routines surface into one responsive
  Routines & Sleep workspace without removing the existing `/routines` route or navigation entry.
- **FR-002**: The sleep module MUST own user-scoped sleep plans with name, planned wake time, lifecycle,
  and exactly one shared recurring rule whose slot time is planned bedtime.
- **FR-003**: A user MUST have at most one active non-archived sleep plan; paused and archived history
  MUST remain restorable subject to that invariant.
- **FR-004**: Every materialized sleep occurrence MUST own a module detail that snapshots planned wake
  time; rule edits MUST update only unlinked future occurrence/details and preserve linked history.
- **FR-005**: The sleep module MUST own at most one sleep log per user/night date with plan, actual UTC
  bedtime/wake instants, quality 1–10, optional note, and stable timestamps.
- **FR-006**: Sleep writes MUST interpret explicit local date/time inputs in Profile timezone, reject
  DST gaps, non-forward or over-24-hour intervals, and derive duration rather than accept it as input.
- **FR-007**: A sleep log MUST correspond to one effective owned planned occurrence; upsert/correction
  MUST be idempotent, clear MUST remove only that fact, and the occurrence mirror MUST reconcile.
- **FR-008**: The sleep module MUST compute selected-night and bounded range summaries from its owned
  occurrences/facts, including planned/recorded state, duration, quality, record count, and averages.
- **FR-009**: A routine MUST expose `day_period` exactly `morning`, `evening`, or `anytime`; existing
  routines MUST migrate to `anytime` without changing their schedule, facts, goals, or lifecycle.
- **FR-010**: A routine MAY own ordered activities with name, unique non-negative order, optional time,
  optional positive numeric progress total, and immutable user ownership.
- **FR-011**: Activity-list replacement MUST be transactional, exact-payload, owner-scoped, reject
  duplicate/foreign ids or orders, and lock membership/numeric totals after the first related fact.
- **FR-012**: The routine module MUST own at most one activity fact per activity/local date with outcome
  `done` or `skipped`, optional bounded numeric progress only for compatible done activities, optional
  note, and deterministic completion timestamp.
- **FR-013**: Activity writes MUST require an effective selected routine occurrence on the date, support
  idempotent correction/clear, and reject facts for inactive, archived, unselected, or unscheduled work.
- **FR-014**: For rich templates the module MUST derive the existing parent `RoutineLog`: pending while
  any activity is unresolved, `done` only when all are done, and `skipped` when all resolve with any
  skip. Direct parent completion MUST be rejected; whole-date clear and Planner skip MUST route through
  activity ownership and keep the occurrence fact link consistent.
- **FR-015**: Simple routines without active activities MUST retain the existing direct done/skipped/
  clear API and every existing Today/Planner/progress/notification behavior.
- **FR-016**: The routine module MUST own one explicit day-selection record per user/date/period, with a
  nullable routine link so explicit none is distinguishable from no stored selection.
- **FR-017**: Day-selection replacement MUST accept morning and evening independently and atomically;
  candidates MUST be active, owned, correct-period, effectively scheduled occurrences without hidden
  facts, and invalid targets MUST produce owner-scoped validation/not-found behavior.
- **FR-018**: In the absence of an explicit selection, the default MUST be the eligible candidate with
  lowest sort order, then name, then id; anytime routines are never filtered by slot selection.
- **FR-019**: Today, Planner, activity validation, and notification synchronization MUST reuse one
  routine-owned day-selection/projection service so selected work cannot drift between consumers.
- **FR-020**: Today MUST expose sleep and routine-activity summaries for its selected local date,
  including per-template activity counts and a null completion rate when no rich activity is
  scheduled, while preserving the existing top-level routine summary for backward compatibility.
- **FR-021**: Review MUST display those read-only module summaries without copying them into
  `daily_reviews` or recomputing duration/completion in the Review module.
- **FR-022**: The existing `RoutineOccurrenceSource` MUST remain the Planner owner for routine
  templates and project only selected morning/evening occurrences plus all anytime occurrences.
- **FR-023**: Sleep MUST implement `SchedulableSource` from its shared planned occurrences, expose wake
  context, allow safe rescheduling before a fact, and never create Planner-owned projections.
- **FR-024**: Timed selected routine and sleep occurrences MUST reuse feature 011 in-app locale, quiet
  hours, dedupe, snooze, escalation, and source-driven closure; notification settings MUST add a
  backwards-compatible sleep category, while untimed/unselected sources create no direct reminder.
- **FR-025**: Pause/archive/restore MUST stop or resume future materialization/reminders idempotently and
  retain every historical activity/sleep fact and linked planned occurrence.
- **FR-026**: Every new record and nested lookup MUST enforce immutable `user_id`, same-owner links,
  owner-scoped 404 boundaries, exact bounded payloads, and account-deletion behavior.
- **FR-027**: Schema evolution MUST be additive/reversible, preserve all existing rows, use portable
  constraints and MySQL-safe identifiers, and add nullable fact links rather than rewrite recurrence.
- **FR-028**: Laravel routes, OpenAPI 3.1, frontend types/consumers, Today/Review contracts, Planner and
  notification vocabularies MUST change together with exact authenticated-operation parity.
- **FR-029**: The shared Vue UI and bundled Android client MUST provide loading/empty/error/success,
  optimistic rollback, keyboard/focus/live-region semantics, 44px targets, safe areas, and no horizontal
  overflow at desktop and exact 390×844.
- **FR-030**: All new workspace, plan, sleep, activity, selection, summary, Planner, notification,
  validation, accessibility, and changelog copy MUST ship simultaneously in EN/RU/UK and use profile
  locale/timezone formatting while leaving user content untranslated.
- **FR-031**: Reads for a normal day/range MUST use bounded set queries rather than per-template,
  per-activity, or per-night N+1 queries.
- **FR-032**: Automated evidence MUST cover schema/rollback, ownership, DST/cross-midnight, recurrence
  edits, activity derivation, selections, aggregates, Planner/notifications, compatibility, localization,
  accessibility, exact mobile, reload/rollback, OpenAPI parity, and complete regression safety.

### Localisation Surface *(mandatory)*

- **Locales**: English (`en-GB`), Russian (`ru-UA`), and Ukrainian (`uk-UA`).
- **New user text**: combined workspace headings/tabs; sleep plan/lifecycle/form/date/time/quality/note/
  duration/statistics states; day-period and template selection controls; activity editor/order/time/
  total/progress/outcome states; Today/Review summary labels; Planner sleep metadata/actions;
  notification category/content; validation/domain/rollback feedback; ARIA labels and changelog copy.
- **Formatting**: Dates/times use Profile locale and timezone; durations, averages, percentages, counts,
  and progress use existing number/plural helpers. User-authored names/notes remain unchanged.
- **Static gate**: Catalog parity/blank/unknown/unused/hardcoded-copy checks MUST remain green.

### Key Entities

- **SleepPlan**: User-owned lifecycle definition and recurrence owner for planned bedtime.
- **SleepOccurrenceDetail**: User-owned wake-time snapshot for one shared planned occurrence.
- **SleepLog**: One explicit actual sleep fact for an effective night occurrence.
- **RoutineActivity**: Ordered child definition owned by one existing Routine template.
- **RoutineActivityLog**: One explicit activity outcome/progress fact for a local date.
- **RoutineDaySelection**: Explicit morning/evening choice or explicit none for a user/date.
- **ModuleDaySummary**: Read-only sleep/routine aggregate DTO consumed by Today and Review.

## Success Criteria *(mandatory)*

- **SC-001**: A user can create, record, correct, clear, pause, archive, and restore sleep without
  duplicate facts or lost planned snapshots in automated API/browser journeys.
- **SC-002**: Cross-midnight and DST tests return exact stable UTC instants/durations and reject every
  invalid interval without partial state.
- **SC-003**: A three-activity template supports independent result changes while its parent fact and
  occurrence always match the documented derivation.
- **SC-004**: Morning/evening defaults, explicit alternatives, and explicit none are identical in
  Today, Planner, validation, and notification behavior.
- **SC-005**: Existing simple routine API/E2E results and historical database rows remain unchanged.
- **SC-006**: Today and Review expose identical owner-computed summaries after correction without
  persisting any copied sleep/routine aggregate in `daily_reviews`.
- **SC-007**: Normal selected-day and 90/366-day aggregate reads remain within fixed query budgets as
  definition/fact counts grow.
- **SC-008**: Exactly ten new authenticated operations parse as OpenAPI 3.1, match Laravel routes, use
  closed mutation schemas, and match TypeScript consumers.
- **SC-009**: EN/RU/UK static guards plus desktop/390×844 browser flows have no missing/hardcoded text,
  runtime errors, horizontal overflow, inaccessible control, or unhandled rollback state.
- **SC-010**: Full Laravel, Vitest, typecheck, build, Playwright desktop/mobile, native wrapper, secret,
  protected-path, and diff gates pass before the one feature commit is pushed.

## Assumptions and Explicit Deferrals

- One current active sleep plan is sufficient for this increment. Naps, split/polyphasic sleep,
  rotating shift plans, wearable/passive import, alarms, stopped-app wake, and medical sleep advice are
  deferred; a later feature may add them without changing the one-night fact contract.
- Sleep night date is the planned-bedtime start date. Planned wake earlier than bedtime means next day.
- Activity structure and numeric totals lock after first fact; users can archive/duplicate a template
  when they need a materially different historical contract. Template cloning UI is deferred.
- Day selection chooses among occurrences already produced by the shared recurrence engine. It does
  not create ad-hoc occurrences or a second scheduler.
- Missing sleep/activity data is unknown/pending, never inferred as zero quality, zero duration, done,
  or healthy. No target sleep duration or recommendation is invented in 014.
- Long-period rollups/correlations remain features 023/026. Review only presents bounded owner summaries.
- Deployment, feature 002, provider configuration, real push delivery, live data, and user handoff
  artifacts remain outside this feature.
