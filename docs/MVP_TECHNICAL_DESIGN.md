# MVP Technical Design

> Implemented contract for the first product slice described in [MVP.md](MVP.md).
> Core Daily Loop tasks T001-T040 were complete and green on 2026-08-11, including the final
> cross-cutting contract, accessibility, responsive-layout, and documentation pass.

## Scope

The first implementation slice is:

1. Daily routine checklist
2. Evening review
3. Goals linked to routines
4. Today dashboard with completion rate and streaks

This is intentionally smaller than the full module design. The goal is to build one complete vertical path through Laravel, MySQL, REST, and Vue before adding heavier mechanisms.

## Product Flow

An authenticated user opens the private application and lands on **Today**:

- sees routine items scheduled for the selected calendar date
- marks each occurrence done or skipped, or clears it back to pending
- sees selected-day counts plus a seven-day completion summary
- fills in or edits one evening review for the date
- sees scheduled-occurrence streaks and active goal context

The MVP is online-only. Every domain route requires a Sanctum-authenticated session, and every query,
write, relationship lookup, and uniqueness boundary is scoped to that account's `user_id`.

## Backend Domain Model

### `goals`

General goals for the MVP. This is the first small version of the cross-cutting Goal module.

Fields:

- `id`
- `user_id`
- `name`
- `description` nullable
- `type` string, default `general`
- `status` enum/string: `active`, `completed`, `abandoned`
- `is_archived` boolean, default false
- `archived_at` nullable UTC datetime
- `target_date` nullable date
- `completed_at` nullable datetime
- timestamps
- optional `deleted_at`

Notes:

- Keep this simple for the MVP.
- Do not model all future goal types yet.
- Future body/training/finance-specific data can be added through explicit columns or a typed detail model later, following [design/data-conventions.md](design/data-conventions.md).

### `routines`

A user-defined repeatable action that can appear on Today.

Fields:

- `id`
- `user_id`
- `name`
- `description` nullable
- `kind` enum/string: `routine`, `sleep`, `habit`
- `schedule_type` enum/string: `daily`, `weekdays`
- `preferred_time` nullable time
- `sort_order` unsigned integer
- `is_active` boolean
- `is_archived` boolean
- `archived_at` nullable UTC datetime
- `starts_on` nullable date
- `ends_on` nullable date
- timestamps
- optional `deleted_at`

Notes:

- This is not the full recurrence engine yet.
- Daily routines have no weekday rows. Weekday schedules require one or more normalized
  `routine_weekdays` rows using `MO` through `SU`.
- Pause (`is_active=false`) and archive are separate lifecycle states; restore preserves the prior
  paused/active value. Archive is not soft deletion.
- After the first log exists, schedule type, weekdays, and start date are locked to preserve history.
- When the shared `RecurringRule` engine is implemented, a routine can become an owner of a recurring
  rule. Until then, the routine itself carries the simple schedule.

### `routine_weekdays`

Normalized weekday membership for a weekday-scheduled routine.

Fields:

- `id`
- `user_id`
- `routine_id`
- `weekday`: `MO`, `TU`, `WE`, `TH`, `FR`, `SA`, or `SU`
- timestamps

Constraints:

- unique `(user_id, routine_id, weekday)`
- the row owner must match the routine owner

### `goal_routine`

Pivot table connecting goals and routines.

Fields:

- `id`
- `user_id`
- `goal_id`
- `routine_id`
- timestamps

Constraints:

- unique `(user_id, goal_id, routine_id)`

Why a pivot:

- A routine can support multiple goals.
- A goal can require several routines.
- This is more flexible than putting `goal_id` directly on `routines`.

### `routine_logs`

The fact that a routine was handled on a specific date.

Fields:

- `id`
- `user_id`
- `routine_id`
- `log_date` date
- `status` enum/string: `done`, `skipped`
- `note` nullable text
- `completed_at` nullable datetime
- timestamps

Constraints:

- unique `(user_id, routine_id, log_date)`

Notes:

- Absence of a log means "not handled yet", not "failed".
- `skipped` is explicit: the user chose to skip it.
- deleting the dated log is the explicit and idempotent transition back to pending
- This table is the source for streaks and completion rate.

### `daily_reviews`

One evening review per user per calendar day.

Fields:

- `id`
- `user_id`
- `review_date` date
- `mood` tiny integer nullable, 1-10
- `energy` tiny integer nullable, 1-10
- `stress` tiny integer nullable, 1-10
- `day_rating` tiny integer nullable, 1-10
- `went_well` nullable text
- `improve_tomorrow` nullable text
- `notes` nullable text
- `completed_at` nullable datetime
- timestamps

Constraints:

- unique `(user_id, review_date)`

Notes:

- This is a daily cross-section, not analytics over time.
- Feature 022 additively delivers weekly/monthly reflections in `periodic_reviews`; trends and
  correlations remain separate from Review. Feature 023 now computes them on read from bounded
  module-owned daily primitives without adding an Analytics table.

## Deferred Tables

Do not implement these in the first coding slice:

- `recurring_rules`
- `planned_occurrences`
- `notifications`
- `daily_metrics`
- attachments (deferred from the first slice and delivered later by feature 021)
- integrations

Reason:

- The MVP can be built with direct routine logs and simple schedule fields.
- The full recurrence engine is already designed, but implementing it before the first visible flow would make the first slice much larger.
- The current model leaves a clear migration path: `Routine` can later own a `RecurringRule`.
- Feature 023 deliberately leaves `daily_metrics` unimplemented: bounded grouped source queries meet
  the current performance contract, and a rebuildable cache requires measured demand.

## API Endpoints

All endpoints are under `/api`.

### Today

`GET /api/today?date=YYYY-MM-DD`

Returns:

- selected calendar date
- routines scheduled for the date, including each log and current streak
- selected-day scheduled/done/skipped/pending counts and completion rate
- the date's daily review when present
- active, non-archived goal context related to displayed routines
- the inclusive seven-day progress period and summary

### Routines

`GET /api/routines?archived=false|true`

List current or archived routines owned by the authenticated user.

`POST /api/routines`

Create routine.

`PATCH /api/routines/{routine}`

Update routine fields or perform pause/resume and archive/restore transitions. There is no routine
resource `PUT` or `DELETE`; soft-deleted trash remains a future concern.

### Routine Logs

`PUT /api/routines/{routine}/logs/{date}`

Upsert routine status for a date.

Request:

- `status`: `done` or `skipped`
- `note` optional

`DELETE /api/routines/{routine}/logs/{date}`

Idempotently clear the dated outcome back to pending.

### Daily Reviews

`GET /api/daily-reviews/{date}`

Get review for a date.

`PUT /api/daily-reviews/{date}`

Upsert review for a date.

`GET /api/review-workspaces/daily/{date}`

Compose the saved daily reflection, eight live module summaries, and transparent day-score evidence.

`GET /api/periodic-reviews/{weekly|monthly}/{anchor}`

Compose the canonical period, saved reflection if present, eight live aggregates, and well-being averages.

`PUT /api/periodic-reviews/{weekly|monthly}/{anchor}`

Idempotently upsert the one owner/type/canonical-start reflection without snapshotting module values.

### Goals

`GET /api/goals?archived=false|true`

List current or archived goals owned by the authenticated user.

`POST /api/goals`

Create goal.

`PATCH /api/goals/{goal}`

Update editable fields or perform complete/abandon/reactivate and archive/restore transitions. Goal
`type`, `completed_at`, and `archived_at` are server-derived rather than writable client fields.

`POST /api/goals/{goal}/routines/{routine}`

Link routine to goal.

`DELETE /api/goals/{goal}/routines/{routine}`

Unlink routine from goal.

## Frontend Screens

### Today

Route: `/`

Shows:

- date selector or today's date
- durable done/skipped/pending routine actions
- selected-day counts and completion percentage
- inclusive seven-day counts, completion percentage, and current streaks
- evening review entry point
- active goal context beside the routines it supports

### Routines

Route: `/routines`

Shows:

- current and archived routine lists
- create/edit routine form
- simple schedule controls: daily or weekdays
- pause/resume and archive/restore actions

### Goals

Route: `/goals`

Shows:

- current and archived goals
- create/edit goal form
- complete/abandon/reactivate and archive/restore actions
- active routine link management

### Review

Route: `/review/:date?`

Shows:

- mood/energy/stress/day rating
- text fields for went well, improve tomorrow, notes
- save action

Routes: `/review/weekly/:anchor?`, `/review/monthly/:anchor?`

Show canonical bounds, well-being averages, eight live module summaries, a periodic reflection form,
and navigation-only Planner/Goals follow-ups.

## Dashboard Metrics

For the MVP, compute these from routines, normalized weekday rows, and `routine_logs` directly:

- selected-day scheduled/done/skipped/pending counts and completion rate
- current scheduled-occurrence streak per displayed routine
- inclusive seven-day scheduled/done/skipped/pending counts and completion rate

`RoutineProgressService` eager-loads schedule metadata, streams logs in routine/date order, and keeps
the 500-routine by one-year regression within five queries. No `daily_metrics` rollup, cache table, or
per-routine query loop is introduced.

## Auth Decision For MVP

Use Laravel's existing `users` table and keep `user_id` on every domain table.

Feature [`003-multi-user-auth`](../specs/003-multi-user-auth/spec.md) supplies the account boundary
used by this slice:

- Visitors who can reach the private application can register independent name/email/password
  accounts.
- The first-party SPA uses Laravel Sanctum's stateful cookie-session and CSRF flow; it does not store
  bearer tokens in the browser.
- Every domain route requires an explicit authenticated session and every query/write remains scoped
  to the authenticated `user_id`.
- Tests create and authenticate explicit users just like application requests, and cross-owner
  identifiers return `404`. Feature 001 makes no changes to the established authentication flow.

## Time and Calendar Boundary

Application and storage timestamps remain UTC. Strict `Y-m-d` fields are interpreted in
the authenticated user's named profile timezone. `SELFHANDLER_TIMEZONE` remains only the deterministic
default used while provisioning or repairing a profile. Calendar dates are serialized as dates, never
shifted through UTC as instants.

## Implemented Delivery Status

The current product delivery baseline now includes features through `025-calendar-integration`
(2026-08-14). Feature 025 adds encrypted optional Google OAuth and Apple CalDAV connections, bounded
two-way calendar synchronization, read-only external Planner busy entries, opt-in category export,
and local-authoritative conflict convergence. Provider-bound data is excluded from schema-v1 backup,
and disconnect removes only local credentials/cache/mappings. It performs no deployment and live
provider acceptance still needs operator credentials; `026-ai-assistant-foundation` is the next
increment. The historical first-slice evidence below remains the implementation record for feature 004.

Feature `004-profile-settings` is complete as of 2026-08-12. It adds an additive one-to-one profile,
existing-user backfill, registration provisioning and repair, full atomic profile GET/PUT contracts,
regional preferences, canonical anthropometrics, formula readiness, a responsive Account editor, and
explicit user-timezone propagation through Today, routine logs, scheduling, and progress. Its final
gate passed 120 Laravel tests with 918 assertions, production Vue typecheck/build, Pint, and 30
desktop/mobile Playwright journeys. Deployment was not part of the feature task list; the completed
release was subsequently rolled out to the existing homelab on 2026-08-12 as revision
`d6ebdf62e28e3a771d4fc71f14cf88295ab20200`. The additive migration created and backfilled one profile
for the existing user, with all prior domain row counts preserved.

- T001-T008: configured timezone boundary, additive schema alignment, ownership concern, API errors,
  explicit fixtures, and browser support
- T009-T017: routine schedule/lifecycle/log/Today vertical slice
- T018-T022: daily review vertical slice
- T023-T029: goal lifecycle, links, and Today context
- T030-T035: seven-day progress and streak vertical slice
- T036-T040: shared async presentation, accessibility/responsiveness, final contract and documentation
  reconciliation, validated by 105 Laravel tests/847 assertions and 24 desktop/mobile browser journeys

The schema alignment is a new additive migration that backfills normalized weekday rows and safely
replaces indexes after creating their MySQL-compatible replacements. Existing migrations were not
rewritten. This design update records application behavior only; it does not imply that a migration
was run or that any environment was deployed during this continuation.

## E2E Tests

The web app uses Playwright for MVP browser coverage.

Command:

- from repo root: `npm run test:e2e`
- from `apps/web`: `npm run test:e2e`

The E2E runner starts isolated local servers:

- Laravel API: `127.0.0.1:18110`
- Vite web: `127.0.0.1:15183`

These ports intentionally avoid DealFlow's local `18000` listener so both projects can run together.

It uses a dedicated SQLite database at `apps/api/database/e2e.sqlite` and runs
`php artisan migrate:fresh --force` before tests. This keeps E2E data separate from the manual local
development database.

Current core-loop coverage runs on desktop and an exact 390-by-844 phone viewport:

- daily and weekday create/edit, ordering, schedule lock, pause/resume, archive/restore
- Today filtering plus done/skipped/pending transitions, idempotency, persistence, and summaries
- review create/update/reload, 422 preservation, service retry, and Today completion context
- goal create/edit/lifecycle/archive/restore, idempotent link/unlink, and active Today context
- known seven-day history, streaks, zero-occurrence state, and no horizontal overflow
- runtime console/page errors, explicit loading/error/retry states, typecheck, and production build

## Accepted Limitation

Routines store only the current `is_active` value and no pause/resume interval history. Historical
scheduled denominators therefore cannot be reproduced across pause cycles; doing so requires schedule
versions or materialized occurrences from the deferred recurrence design. Archive history is retained
through `archived_at` and historical logs.

## Learning Notes

- **Migration**: Laravel file that describes a database change. It is the history of the schema.
- **Eloquent model**: PHP class representing a table row plus relationships and query helpers.
- **Pivot table**: join table for many-to-many links, here `goals` to `routines`.
- **Upsert**: update if a row exists, create if it does not. Perfect for "one log per routine per date".
- **REST endpoint**: stable HTTP contract between Vue and Laravel.
- **Vue screen**: route-level component that fetches data and renders a workflow.
