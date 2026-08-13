# Research: Sleep and Rich Routine Templates

**Feature ID**: `014-sleep-routine-templates`

## Sources Reviewed

- `docs/design/modules.md` Module 1: planned/actual sleep, quality, ordered routine activities,
  independent morning/evening choice, independent activity outcomes, daily aggregation.
- `docs/design/delivery-roadmap.md` feature 014: extend rather than replace feature 006's routine
  occurrence source; feed owner summaries into Today/Review; reuse Planner and reminders.
- `docs/design/{vision,data-conventions,recurrence-engine,notifications,decisions}.md` for timezone,
  ownership, deterministic aggregate, Planner pull, and delivery-channel boundaries.
- Implemented features 001/004/006/009/011/012/013: `Routine`, `RoutineLog`, recurrence materializer,
  occurrence fact reconciliation, Today, Daily Review, Planner source registry, notifications,
  localization, and bundled Android transport.
- GitNexus exploration of `RoutineController`, `TodayController`, `DailyReviewController`, and
  `RoutineOccurrenceSource`; the index was refreshed at HEAD before querying.

## Decision 1: Existing Routine Is the Template Parent

**Decision**: Add a day-period and child activities to `Routine`. Do not create a parallel
`RoutineTemplate` table or occurrence source.

**Why**: A current routine already owns lifecycle, goal links, one recurring rule, planned occurrences,
Planner projection, reminder identity, and a parent fact. Replacing it would duplicate or migrate every
working boundary. A child list is the smallest additive interpretation of the canonical template.

**Rejected**:

- New template plus generated routines: copies schedule/state and makes two owners authoritative.
- One recurrence rule per activity: turns a template into unrelated Planner entries and loses parent
  selection/reminder semantics.
- JSON activities: cannot enforce ownership/order/fact relationships or query aggregates safely.

## Decision 2: Activity Facts Derive the Existing Parent Fact

**Decision**: Store one `RoutineActivityLog` per activity/date. For a rich template, synchronize its
existing `RoutineLog` only when all active activities resolve: all done → done; otherwise skipped.
Any pending child removes the derived parent fact. Parent clear clears child facts; direct parent set
is rejected; Planner skip marks all unresolved children skipped.

**Why**: `planned_occurrences.routine_log_id` is already the recurrence/Planner/notification fact
contract. Deriving that fact keeps one closure signal and makes partial progress honest.

**Rejected**:

- Treat first child as parent done: closes reminders and streaks while work is pending.
- Add `partial` to RoutineLog: widens every existing consumer for a state that can be computed from
  children and is not a resolved occurrence.
- Persist counters on Routine: corrections would drift and violate module-owned calculation.

## Decision 3: Lock Semantic Activity Structure After Facts

**Decision**: Before facts, replace activities atomically. After the first parent/activity fact,
membership and numeric progress totals lock; cosmetic name/order/time changes remain allowed.

**Why**: Adding/removing an activity retroactively changes whether historical parent occurrences
were complete. Changing a total changes recorded progress meaning. Versioned template revisions are
larger than this vertical slice; archive/duplicate is the safe user workflow.

**Rejected**:

- Silently apply current structure to all history: rewrites aggregate meaning.
- Snapshot the full activity list into every occurrence now: correct but adds a second materialization
  tree before a second consumer justifies it.

## Decision 4: Day Selection Filters Existing Scheduled Occurrences

**Decision**: Persist optional morning/evening selections per user/date. A nullable routine means
explicit none; no row uses the deterministic lowest sort/name/id candidate. Candidates must already
be scheduled/effective. One shared projection service is used by Today, Planner, writes, and reminders.

**Why**: This implements independent choice without creating ad-hoc recurrence or Planner copies.
Anytime routines remain outside selection and preserve current behavior.

**Rejected**:

- Store selected routine ids in Planner: Planner would own routine intent and drift.
- Auto-create/delete occurrences on selection: destructive to the recurrence window and reschedules.
- Return every candidate as actionable: contradicts “choose one” and creates duplicate reminders.

## Decision 5: Sleep Is a Separate Recurrence Owner

**Decision**: `SleepPlan` owns one shared recurring rule whose slot is planned bedtime. A module-owned
`SleepOccurrenceDetail` snapshots planned wake time for each shared occurrence. The shared
materializer synchronizes those details in the same transaction on both plan-specific and global CLI
paths, so no occurrence can be committed without its wake snapshot. `SleepLog` is the one actual fact,
linked through a new nullable unique `planned_occurrences.sleep_log_id`.

**Why**: Bedtime needs Planner/reminder/reschedule identity, while wake time is second metadata on the
same night rather than a separate action. A detail table avoids adding a sleep-only column to every
generic occurrence and preserves wake history across rule edits.

**Rejected**:

- Two rules for bed and wake: produces two occurrences for one fact and ambiguous closure.
- Sleep as `Routine kind=sleep`: a routine done/skipped fact cannot represent two actual instants,
  duration, quality, or correction.
- Daily rows without recurrence: no stable Planner/reminder identity and duplicates shared scheduling.

## Decision 6: One Active Sleep Plan, Many Historical Plans

**Decision**: Enforce at most one active, non-archived plan by locking the owning user row inside the
plan transaction before checking/saving; retain any number of paused or archived plans and logs.

**Why**: The canonical contract specifies one planned bedtime/wake time, not overlapping shift plans.
This prevents multiple planned sleep episodes per night while leaving an additive path to future
versioned or shift schedules.

**Rejected**:

- Database unique boolean: MySQL/SQLite portable uniqueness would also restrict multiple inactive rows.
- Multiple active plans with overlap detection: bounds/weekday intersections add complexity for an
  explicitly deferred shift-work feature.

## Decision 7: Local Night Inputs, UTC Facts, Exact Duration

**Decision**: Night date is the planned-bedtime local date. The API accepts separate local dates and
times for actual bed/wake, resolves them in Profile timezone, rejects DST gaps, requires forward
duration ≤24 hours, and stores UTC instants. Ambiguous fall-back inputs use the backend's deterministic
Carbon offset; returned UTC makes it visible/correctable.

**Why**: Browser timezone can differ from Profile timezone, so client-composed offsets are not
authoritative. Separate wall fields match owned controls and make cross-midnight explicit.

**Rejected**:

- Browser `Date` conversion: wrong when device/profile zones differ.
- Persist local datetime only: ambiguous instants and broken duration after timezone changes.
- Accept duration: permits disagreement with actual timestamps.

## Decision 8: Module-Owned Summary DTOs

**Decision**: `SleepStatisticsService` and `RoutineActivitySummaryService` compute bounded set-based
selected-day/range results. Today includes them under `module_summaries`; Review fetches/display the
same response and never stores or recomputes them.

**Why**: This follows the locked rule “modules compute, Review/Analytics present.” Corrections update
context without mutating DailyReview.

**Rejected**:

- Copy summaries into DailyReview: stale snapshot and ownership duplication.
- Compute in Vue: locale client would become a domain calculator and mobile/web could diverge.
- Add long-period rollups: feature 023 owns that need.

## Decision 9: Planner and Notification Reuse

**Decision**: Keep `RoutineOccurrenceSource`, filtering morning/evening candidates through the shared
selection service. Register `SleepOccurrenceSource` for sleep occurrences with wake metadata and safe
reschedule. Feature 011 gains a backwards-compatible sleep category/type; only selected timed routine
and sleep occurrences schedule direct reminders.

**Why**: Existing source identity, quiet hours, dedupe, snooze, escalation, localized rendering, and
mobile presentation already work. Owner lifecycle/facts remain the source of closure.

**Rejected**:

- A new routine-template notification type/source id: duplicates occurrence notification families.
- Client local scheduling directly from plans: violates current delivered-event mirroring and cannot
  close/dedupe with web facts.

## Decision 10: Strict Contracts and Query Budgets

**Decision**: New mutations reject unknown keys and nested extras; all nested ids are same-owner.
Activity/selection/sleep reads use eager/set queries with fixed query-budget evidence. Ten new
authenticated operations have an exact OpenAPI/route parity test; changed existing responses update
feature 001/006/009/011 contracts and TypeScript together.

**Why**: Large templates and sleep history otherwise invite N+1 queries and silent payload drift.

## Explicit Deferrals

- Naps, split/polyphasic sleep, rotating shifts, wearable/passive imports, alarms, stopped-app wake,
  medical advice, recommendations, and invented sleep targets.
- Per-occurrence activity-list overrides, template cloning UI/version history, ad-hoc selection-created
  occurrences, and new recurrence frequency semantics.
- Long-period rollups, correlations, AI coaching/narration, cloud push, and deployment.

## Constitution Check

All decisions preserve specification-first delivery, existing canonical owners, one usable vertical
slice, deterministic behavior without AI, exact user ownership/privacy, synchronized contracts/tests,
complete EN/RU/UK, and additive MySQL-portable evolution. No exception or clarification remains.
