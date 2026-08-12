# Research: Planner and Day Planning

**Feature ID**: `009-planner-day` · **Date**: 2026-08-12

## R1 — The `Schedulable` contract (recurrence-engine open question 6)

`recurrence-engine.md` leaves this open: "How the Planner aggregates occurrences from all modules into
a single calendar — a `Schedulable` view/contract."

**Options**

| Option | Verdict |
|---|---|
| Planner queries each module's tables directly | Rejected: every new module edits Planner, and Planner learns other modules' rules. |
| Modules push their entries into a Planner table | Rejected outright: the design says Planner "does not produce domain data itself", and a copy drifts the moment the source changes. |
| **A source contract plus a registry** | **Adopted.** |

**Decision**: `SchedulableSource` — one method answering "what does this module have for this user on
this calendar day", returning read-only projections. A registry lists the implementations. Three ship
now (routine occurrences, dated Storage items, time blocks), so the abstraction has three consumers on
the day it appears, which is what constitution principle III asks for.

Planner never writes through the contract. An action on an entry is routed back to the endpoint that
owns it — a skip is a routine log, a moved task is a Storage update — so each module keeps enforcing its
own rules.

## R2 — Reschedule versus skip

`modules.md` gives both and says "the user chooses what to do with a specific item".

**Findings**: they are not two spellings of one action.

- **Skip** asserts something about the past: the day came and this did not happen. That is a domain
  fact, and for routines the application already has one — `routine_logs` with status `skipped`, which
  Today writes and which progress and streaks already understand.
- **Reschedule** asserts something about the plan: nothing has happened, the day moved. That is
  engine-side and belongs on the occurrence.

**Decision**: skip writes the existing routine log; no second skip state is invented. Reschedule adds
`rescheduled_to` to `planned_occurrences`, additively.

**Why a column rather than moving the row**: `occurrence_date` is what the rule expanded, and
materialization compares against it. Overwriting it would make the next run believe the day is missing
and recreate it, producing a duplicate. Keeping the original and adding the destination also preserves
what was originally planned, which the design's "record the skip → flows into Analytics/Review" line
depends on.

**Why refuse rescheduling a completed occurrence**: the occurrence is linked to a fact. Moving it would
claim the completion happened on a day it did not.

## R3 — Storage items in the day

A task has one due date, not a recurrence, so "skip" has no meaning for it and "reschedule" is simply
editing that date.

**Decision**: Planner offers "move to another day" for a dated item and performs it through the existing
`PATCH /api/storage/items/{item}`, which already validates ownership, and leaves status changes to
Storage's own screen. Planner adds no item state of its own.

## R4 — Time blocks

The one thing with no owner: "the user's own tasks/events not tied to modules (doctor, meeting)" and
"day planning: time blocks".

**Decision**: `time_blocks`, owned by Planner, with a calendar date and optional start/end times. Times
are optional because "dentist, Tuesday" is a real thing a user wants to write before they know when.

**Overlap is allowed.** Two blocks at the same time is a normal way to note a conflict you intend to
resolve; refusing it would make the planner argue with the user about their own day.

Recurring blocks are deliberately absent: a repeating block is a recurrence, and the application already
has one engine for that. When a real case appears it becomes a `RecurringRule` owner, not a second
mechanism.

## R5 — Scheduling the materialization window

Feature 006 deferred this with a named trigger: "the scheduler arrives with the first consumer that
actually needs a fresh window". This is that consumer — a reschedule attaches to a materialized
occurrence, so a day the window has not reached cannot be planned.

**Decision**: register the command on Laravel's scheduler and run a dedicated `scheduler` service in the
deployment so `schedule:work` has a process. Daily is enough for a 90-day window.

A day beyond the window is reported as such rather than shown empty, because "nothing is planned" and
"we have not expanded that far" are different answers — the same distinction the trend states in
feature 007 draw.

## R6 — Query bounds

Each source answers for one day in one query, so a day costs a fixed number of queries regardless of how
much is on it. Asserted by a query-count test with many entries.

## Constitution Check

| Principle | Assessment |
|---|---|
| I | Full contract before implementation. |
| II | Answers open question 6 of the recurrence design and records the answer there. |
| III | The contract has three implementations on day one; recurring blocks, drag-and-drop and further sources are deferred with named triggers. |
| IV | No AI, no automatic planning. Ordering and refusals are deterministic. |
| V | `user_id` on the new table; every source scopes to the owner. |
| VI | Migration, contract, source, compatibility and browser coverage move together. |
