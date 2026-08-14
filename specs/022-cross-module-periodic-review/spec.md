# Feature Specification: Cross-Module and Periodic Review

**Feature Branch**: existing user branch
**Created**: 2026-08-14
**Status**: Complete
**Input**: Roadmap feature 022 and the Review boundaries in `docs/design/modules.md`,
`docs/design/vision.md`, `docs/design/decisions.md`, and `docs/design/llm-layer.md`.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Trust the Daily Cross-Module Summary (Priority: P1)

As a user closing or checking the current day, I can see one current summary assembled from the
implemented modules, so corrections made in a source module immediately appear without copied facts.

**Independent Test**: Seed owned Sleep, Routine, Workout, Nutrition, Supplement, Habit, Planner, and
Finance facts for one date; load the daily review workspace and verify that each displayed total equals
the corresponding module-owned aggregate while no aggregate is persisted on the daily review.

**Acceptance Scenarios**:

1. **Given** facts exist in every implemented source, **When** the daily workspace is opened, **Then**
   it presents one bounded aggregate per source and the existing reflection for that date.
2. **Given** a source fact is corrected after the workspace was first read, **When** it is reloaded,
   **Then** the corrected module value appears and no stale Review-owned copy remains.
3. **Given** a source has no eligible data, **When** the workspace is opened, **Then** that source has
   an explicit empty/not-applicable state rather than a misleading zero-success claim.
4. **Given** another user owns matching-date facts, **When** the owner opens the workspace, **Then**
   none of the other user's records affect any module total or score.

---

### User Story 2 - Understand the Deterministic Day Score (Priority: P1)

As a user, I can see a deterministic day score with visible components and coverage, so the score is
useful without an LLM and never hides missing evidence.

**Independent Test**: Supply exact component fixtures for Nutrition, Workouts, Supplements, Habits,
and Planner; verify every normalized contribution, equal available-component weighting, coverage, and
the null result when no component is available.

**Acceptance Scenarios**:

1. **Given** all five components are available, **When** the daily workspace is read, **Then** the score
   is the rounded arithmetic mean of their bounded 0-100 values and coverage is 5/5.
2. **Given** one or more components have no eligible evidence, **When** the score is composed, **Then**
   unavailable components are named and excluded from the denominator.
3. **Given** no score component is available, **When** the workspace is read, **Then** the score is null,
   coverage is 0/5, and the client explains that more tracked data is needed.
4. **Given** a source value is corrected, **When** the workspace is reloaded, **Then** the score is
   recomputed from current module aggregates and no historical score snapshot is treated as authority.

---

### User Story 3 - Complete a Weekly Review (Priority: P1)

As a user, I can review the canonical Monday-Sunday week containing a selected date, record what
worked and what did not, and set the next focus without changing the daily evening ritual.

**Independent Test**: Open a weekly workspace by a mid-week anchor, verify the canonical period and
aggregates, save reflection fields, reopen by another anchor in the same week, and recover one record.

**Acceptance Scenarios**:

1. **Given** any valid anchor date, **When** weekly review is opened, **Then** the period is the user's
   timezone-aware Monday-Sunday week containing that anchor.
2. **Given** no weekly reflection exists, **When** at least one valid field is saved, **Then** exactly one
   owner-scoped weekly review is created for the canonical period.
3. **Given** the same week is saved again or addressed through another date in that week, **When** the
   write completes, **Then** the existing record is updated and its first completion time is preserved.
4. **Given** the weekly review is open, **When** the user follows the planning links, **Then** Planner and
   Goals remain the owners of future plans and goal state; Review does not duplicate those writes.

---

### User Story 4 - Complete a Monthly Review (Priority: P1)

As a user, I can review the calendar month containing a selected date and record lessons and next
focus beside current period aggregates.

**Independent Test**: Open February in a leap year and a 31-day month, verify exact boundaries, save
and edit the monthly reflection, and prove another user cannot read or mutate it.

**Acceptance Scenarios**:

1. **Given** any valid anchor date, **When** monthly review is opened, **Then** the canonical period is
   the first through last calendar day of that month in the profile timezone.
2. **Given** a saved monthly review, **When** its source data is corrected, **Then** the reflection stays
   intact while the period aggregate is recomputed.
3. **Given** a foreign or anonymous request, **When** it addresses a period used by the owner, **Then**
   no reflection or aggregate data is disclosed or changed.
4. **Given** a leap-year February, **When** the month is opened, **Then** February 29 is included exactly
   once and no neighboring date contributes.

---

### User Story 5 - Use Review Across Browser and Android (Priority: P2)

As a user, I can switch among daily, weekly, and monthly review modes on desktop, an exact 390px phone,
and the synchronized Android shell with complete EN/RU/UK feedback.

**Independent Test**: Exercise mode/date navigation, load/empty/error, validation, save/retry, and
saved states at desktop and 390x844 viewports in all locales, then build and sync the mobile client.

**Acceptance Scenarios**:

1. **Given** the Review workspace, **When** the user switches period mode or anchor, **Then** the URL
   records the selection and browser navigation restores it.
2. **Given** a slow or failed read/write, **When** the state changes, **Then** loading, retryable error,
   validation, unsaved, saving, and saved feedback is explicit and accessible.
3. **Given** English, Russian, or Ukrainian, **When** every Review state is rendered, **Then** product,
   validation, empty-state, score-reason, and accessibility copy is localized with key parity.
4. **Given** the shared web bundle is synchronized to Android, **When** Review is opened in the shell,
   **Then** the same online contract and responsive workspace are used without native data authority.

## Edge Cases

- The profile timezone differs from server/device timezone at midnight or a DST transition.
- An invalid date, impossible date, or unsupported period type is supplied.
- A weekly anchor is Sunday; the canonical week still begins on the preceding Monday.
- A monthly anchor belongs to February in leap and non-leap years.
- A source has facts but no target, schedule, exchange rate, or other evidence required for a score.
- Finance contains a currency without an applicable historical rate; amounts are explicitly incomplete.
- A planned occurrence was rescheduled into or out of the selected period.
- A source owner is archived after historical facts exist; historical resolved facts remain reviewable.
- All score sources are empty, or only one of five is available.
- Two retries save the same periodic review concurrently.
- A source correction occurs while the review form has unsaved text; reloading aggregates must not
  silently discard the form.
- Period text reaches its exact field limit or contains trimmed Unicode.

## Functional Requirements

- **FR-001**: The system MUST expose one authenticated daily Review workspace containing the canonical
  selected date, current DailyReview reflection, current module aggregates, and composite day score.
- **FR-002**: Review controllers and composers MUST obtain every source value through a registered
  module aggregate contract/service and MUST NOT query source modules' raw tables directly.
- **FR-003**: The source registry MUST have stable unique keys and support daily and bounded period
  aggregation for Routines, Sleep, Workouts, Nutrition, Supplements, Habits, Planner, and Finance.
- **FR-004**: Source services MUST remain the owners of their calculations; Review MAY normalize and
  compose returned values but MUST NOT persist or redefine their authoritative facts.
- **FR-005**: Daily source responses MUST preserve the already-consumed Sleep, rich Routine activity,
  Workout, Nutrition, and Supplement shapes or provide additive compatible fields.
- **FR-006**: Daily and period aggregates MUST be recomputed on read so a source correction is visible
  without synchronizing or invalidating a Review-side snapshot.
- **FR-007**: Every aggregate MUST use the authenticated owner's records and profile calendar timezone;
  unowned records MUST never affect counts, values, availability, or score.
- **FR-008**: Period requests MUST be bounded to either a seven-day canonical week or one calendar month;
  arbitrary and long-period analytics remain outside 022.
- **FR-009**: Weekly periods MUST run Monday-Sunday and monthly periods first-last calendar day, both
  derived from an anchor date in the user's profile timezone.
- **FR-010**: The day score MUST have exactly five candidate components: Nutrition, Workouts,
  Supplements, Habits, and Planner. Routines, Sleep, Finance, well-being, and manual day rating MUST
  remain visible evidence but MUST NOT influence the score in 022.
- **FR-011**: Every available score component MUST be normalized to 0-100, all available components MUST
  have equal weight, and unavailable components MUST be excluded from the denominator and named.
- **FR-012**: Nutrition contribution MUST average available target evidence: calories/fat/carbohydrates
  use bounded closeness to 100%, while protein/hydration/quality use bounded attainment up to 100%.
- **FR-013**: Workout contribution MUST be completed planned workouts divided by planned workouts; when
  none was planned, at least one completed unplanned workout yields 100, otherwise it is unavailable.
- **FR-014**: Supplement, Habit, and Planner contributions MUST divide successful/done facts by all
  eligible scheduled items in the day, counting skipped, overdue, and pending items as not successful;
  a component with no eligible item is unavailable.
- **FR-015**: The score MUST expose rounded value or null, available/total coverage, and for each component
  its stable key, value or null, availability, equal applied weight, and stable reason code.
- **FR-016**: The deterministic score MUST remain fully usable without an LLM; an LLM MUST NOT create,
  override, or become authoritative for any aggregate or score in 022.
- **FR-017**: The system MUST store at most one PeriodicReview per owner, period type, and canonical period
  start, with a server-derived period end and first completion time.
- **FR-018**: A PeriodicReview MUST support an optional 1-10 period rating plus optional `worked_well`,
  `did_not_work`, `learned`, `next_focus`, and `notes` fields.
- **FR-019**: At least one PeriodicReview field MUST be supplied per save; text MUST be trimmed or null and
  bounded to 5,000 characters per focused field and 10,000 for notes.
- **FR-020**: Periodic Review upsert MUST be idempotent for every anchor in the same canonical period and
  MUST preserve the original non-null completion time across edits.
- **FR-021**: Periodic review reads MUST return the canonical period, saved reflection or null, current
  period aggregates, and Review-owned daily well-being counts/averages without storing source totals.
- **FR-022**: Weekly/monthly Review MUST link to Planner and Goals for follow-up while leaving all planning,
  goal adjustment, and module fact writes to those owning modules.
- **FR-023**: Existing DailyReview one-per-date persistence and fields MUST remain backward compatible,
  including the existing authenticated GET/PUT endpoints and first completion semantics.
- **FR-024**: All new schema changes MUST be additive, reversible, owner-scoped, indexed for period access,
  and use an explicit composite unique constraint.
- **FR-025**: The public 022 API MUST use closed request/resource schemas, authenticated operations,
  strict calendar formats, explicit nullability, stable enum/reason values, and no secret/internal paths.
- **FR-026**: The client MUST provide daily/weekly/monthly navigation, URL-persisted selection, current
  aggregate cards, score evidence, reflection editing, and explicit loading/empty/error/save/retry states.
- **FR-027**: Review controls and evidence MUST be keyboard and screen-reader usable, with visible focus,
  semantic labels, live status feedback, and no reliance on color alone.
- **FR-028**: New user-facing strings MUST ship with English, Russian, and Ukrainian key parity and fit
  desktop plus exact 390x844 layouts without clipping or horizontal overflow.
- **FR-029**: The shared browser implementation MUST remain the Android UI; feature 022 MUST synchronize
  the Capacitor bundle without adding native storage, offline authority, or platform-only review logic.
- **FR-030**: Feature 022 MUST NOT add long-period rollups/trends/correlations, export/report/restore,
  calendar integration, notifications, AI narratives/tool calls, offline writes, or deployment changes.

## Key Entities

- **PeriodicReview**: Review-owned weekly or monthly reflection, unique by owner/type/canonical start.
- **Review Period**: Derived timezone-aware start/end/type/anchor value; not independently stored.
- **Review Aggregate Source**: Registered read-only adapter delegating calculation to one source module.
- **Module Aggregate**: Current module-owned daily or period projection; never copied into Review tables.
- **Day Score**: Non-persisted deterministic composition of five visible available contributions.
- **Score Component**: Stable source key, availability/reason, bounded value, and applied equal weight.
- **Well-being Summary**: Review-owned period projection from DailyReview ratings and completion counts.

## Success Criteria *(mandatory)*

- **SC-001**: A seeded all-module daily fixture returns correct owner-scoped values for all eight source
  keys, the legacy compatible fields, and no persisted aggregate columns or records.
- **SC-002**: Exact component fixtures produce the documented score and contribution evidence, including
  5/5, partial, and 0/5 coverage cases with no rounding drift beyond 0.01.
- **SC-003**: Correcting each source fact changes its next daily/period response and dependent score while
  leaving saved daily/period reflection text unchanged.
- **SC-004**: Weekly anchor matrices including Sunday and timezone-boundary cases produce exactly one
  Monday-Sunday identity; month matrices cover 28, 29, 30, and 31 days exactly.
- **SC-005**: Repeated and concurrent periodic upserts yield one owner/type/start row, one first completion
  instant, and the last successful valid payload without cross-user effects.
- **SC-006**: Owner/foreign/anonymous read/write matrices disclose and mutate only the authenticated
  owner's reflections and aggregates.
- **SC-007**: Daily workspace and weekly/monthly workspaces stay within documented fixed query budgets and
  never scale one query per source record or per calendar day.
- **SC-008**: The closed OpenAPI contract parses, all references resolve, every operation is authenticated,
  and backend responses/client types agree on all required, nullable, enum, and reason fields.
- **SC-009**: EN/RU/UK parity, used-key/hardcoded-copy checks, typecheck, unit tests, and production build
  pass with no unapproved user-facing strings.
- **SC-010**: Desktop and exact 390x844 Playwright journeys plus inspected locale/scheme screenshots show
  usable daily/weekly/monthly navigation, score evidence, and every async/save state without overflow.
- **SC-011**: Capacitor synchronization and Android source tests pass with the same review URL/API contracts
  and no native database, offline write queue, or platform-specific aggregate implementation.
- **SC-012**: Full backend/browser regressions, migration rollback/reapply, dependency audits, safety scans,
  and GitNexus impact review pass while deployment and preserved handoff paths remain untouched.

## Assumptions

- ISO-style Monday-Sunday weeks are the one deterministic weekly identity; the ritual may occur Sunday.
- Only a daily score is defined. Periodic workspaces show module period aggregates and daily well-being;
  period scores, score trends, comparisons, and correlations belong to 023.
- Equal weighting applies only across available components so absence is never silently treated as failure;
  coverage remains visible to prevent a partial score from looking complete.
- Existing module aggregate services can be extended or wrapped, but the Review layer cannot query their
  raw tables or own their domain formulas.
- Review remains online-only and uses current module facts on every read.

## Dependencies and Explicit Exclusions

Depends on features 013-017 and includes Finance only because 018-020 are complete. It reuses the core
DailyReview, profile timezone, recurrence/Planner projections, authenticated API, localization system,
responsive browser shell, and Capacitor wrapper.

Explicitly excluded: feature 002/deployment; arbitrary/long period analytics, daily rollups, trends,
comparisons, correlations, CSV/PDF/backup/restore, external calendars, LLM summaries or advice, automatic
tomorrow/week/month planning, goal mutation, notifications, offline synchronization, and native authority.
