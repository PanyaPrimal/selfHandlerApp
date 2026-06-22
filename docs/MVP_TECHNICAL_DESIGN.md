# MVP Technical Design

> Implementation contract for the first product slice described in [MVP.md](MVP.md).
> This document narrows the full design into the smallest useful backend + web loop.

## Scope

The first implementation slice is:

1. Daily routine checklist
2. Evening review
3. Goals linked to routines
4. Today dashboard with completion rate and streaks

This is intentionally smaller than the full module design. The goal is to build one complete vertical path through Laravel, MySQL, REST, and Vue before adding heavier mechanisms.

## Product Flow

The user opens the app and lands on **Today**:

- sees today's routine items
- marks each routine as done or skipped
- sees today's completion rate
- fills in an evening review
- sees simple streak and recent completion data

The MVP is online-only and single-user in product behavior, but all domain tables still have `user_id` from day one.

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
- `weekdays` JSON nullable, e.g. `["MO", "WE", "FR"]`
- `preferred_time` nullable time
- `sort_order` unsigned integer
- `is_active` boolean
- `starts_on` nullable date
- `ends_on` nullable date
- timestamps
- optional `deleted_at`

Notes:

- This is not the full recurrence engine yet.
- The fields cover the MVP need: daily routines and weekday-based routines.
- When the shared `RecurringRule` engine is implemented, a routine can become an owner of a recurring rule. Until then, the routine itself carries the simple schedule.

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
- Weekly/monthly reviews are deferred.

## Deferred Tables

Do not implement these in the first coding slice:

- `recurring_rules`
- `planned_occurrences`
- `notifications`
- `daily_metrics`
- attachments
- integrations

Reason:

- The MVP can be built with direct routine logs and simple schedule fields.
- The full recurrence engine is already designed, but implementing it before the first visible flow would make the first slice much larger.
- The current model leaves a clear migration path: `Routine` can later own a `RecurringRule`.

## API Endpoints

All endpoints are under `/api`.

### Today

`GET /api/today?date=YYYY-MM-DD`

Returns:

- target date
- routines scheduled for the date
- each routine's log status for that date
- completion summary
- today's daily review if it exists
- active goals related to today's routines

### Routines

`GET /api/routines`

List routines.

`POST /api/routines`

Create routine.

`PATCH /api/routines/{routine}`

Update routine.

`DELETE /api/routines/{routine}`

Soft-delete or deactivate routine. For the MVP, prefer `is_active=false` for normal user hiding; reserve `deleted_at` for real deletion/trash behavior.

### Routine Logs

`PUT /api/routines/{routine}/logs/{date}`

Upsert routine status for a date.

Request:

- `status`: `done` or `skipped`
- `note` optional

### Daily Reviews

`GET /api/daily-reviews/{date}`

Get review for a date.

`PUT /api/daily-reviews/{date}`

Upsert review for a date.

### Goals

`GET /api/goals`

List goals.

`POST /api/goals`

Create goal.

`PATCH /api/goals/{goal}`

Update goal.

`POST /api/goals/{goal}/routines/{routine}`

Link routine to goal.

`DELETE /api/goals/{goal}/routines/{routine}`

Unlink routine from goal.

## Frontend Screens

### Today

Route: `/`

Shows:

- date selector or today's date
- routine checklist
- completion percentage
- evening review entry point
- small goal context section

### Routines

Route: `/routines`

Shows:

- routine list
- create/edit routine form
- simple schedule controls: daily or weekdays

### Goals

Route: `/goals`

Shows:

- active goals
- create/edit goal form
- linked routines

### Review

Route: `/review/:date?`

Shows:

- mood/energy/stress/day rating
- text fields for went well, improve tomorrow, notes
- save action

## Dashboard Metrics

For the MVP, compute these from `routine_logs` directly:

- today's completion rate
- current streak per routine
- overall completion rate for the last 7 or 30 days

Do not create `daily_metrics` yet. Add rollups when analytics starts reading longer periods or the dashboard becomes slow.

## Auth Decision For MVP

Use Laravel's existing `users` table and keep `user_id` on every domain table.

Open implementation choice before coding:

- Option A: proper API auth with Laravel Sanctum
- Option B: temporary local single-user resolver for development

Current implementation decision:

- The first backend slice uses a temporary `CurrentUser` resolver.
- In `local` and `testing`, it resolves the authenticated user or creates a local development user.
- Outside `local`/`testing`, missing auth returns `401`.
- Sanctum is still the preferred SPA-auth target, but it is deferred until the first domain loop is working.

## Implementation Order

1. Backend migrations
2. Eloquent models and relationships
3. API routes/controllers
4. Feature tests for create/list/upsert flows
5. Vue API client
6. Today screen
7. Routines screen
8. Review screen
9. Goals linking

## E2E Tests

The web app uses Playwright for MVP browser coverage.

Command:

- from repo root: `npm run test:e2e`
- from `apps/web`: `npm run test:e2e`

The E2E runner starts isolated local servers:

- Laravel API: `127.0.0.1:18000`
- Vite web: `127.0.0.1:15173`

It uses a dedicated SQLite database at `apps/api/database/e2e.sqlite` and runs `php artisan migrate:fresh --force` before tests. This keeps E2E data separate from the manual local development database.

Current coverage:

- create a routine
- see it on Today
- mark it done
- fill and save the evening review
- run the same flow on desktop and mobile viewports

## Learning Notes

- **Migration**: Laravel file that describes a database change. It is the history of the schema.
- **Eloquent model**: PHP class representing a table row plus relationships and query helpers.
- **Pivot table**: join table for many-to-many links, here `goals` to `routines`.
- **Upsert**: update if a row exists, create if it does not. Perfect for "one log per routine per date".
- **REST endpoint**: stable HTTP contract between Vue and Laravel.
- **Vue screen**: route-level component that fetches data and renders a workflow.
