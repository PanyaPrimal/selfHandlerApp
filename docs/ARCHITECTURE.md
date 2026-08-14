# Architecture

> Detailed product and domain design lives in [design/](design/) — module specs, ER diagrams, and cross-cutting mechanisms. Start at [design/README.md](design/README.md).

## Goal

Build SelfHandler as a monorepo with separated delivery units and a shared local development setup.

## Applications

### `apps/api`

Laravel API for:

- auth
- routines
- habits and anti-habits
- goals
- tasks
- ideas
- daily reviews
- analytics

Habits are distinct from routines. A `Habit` owns its mode, context links, lifecycle and exactly one
shared `RecurringRule`; `HabitLog` owns one result per effective local date, and `HabitLimitStep` owns
stepped anti-habit ceilings. `PlannedOccurrence.habit_log_id` is a derived fact link beside the existing
routine link. Planner reads habits through `SchedulableSource`, while notifications reuse the same
occurrence identity, quiet hours, localization and delivery state.

Sleep is a third recurrence owner rather than a special routine kind. `SleepPlan` owns its rule and
planned wake snapshots; `SleepLog` owns actual UTC instants. Rich routines remain `Routine` records
and own ordered `RoutineActivity` definitions and per-day facts. One `RoutineDayProjectionService`
selects morning/evening templates for Today, Planner, validation, and notification synchronization,
while parent `RoutineLog` rows remain derived compatibility facts.

Workouts are the fourth recurrence owner. `WorkoutProgram` owns one rule plus typed program details;
`WorkoutSession` is the correctable fact root with mutually exclusive strength, endurance, or timed
details and an optional `PlannedOccurrence` link. Exercise prescriptions, sets, records, progression,
summaries, and training-goal current values stay relational and are derived in bounded module queries.
Planner and Notifications are adapters over the same occurrence; Today and Review transport the same
Workout summary rather than persisting copies. Training-specific detail extends the existing `Goal`
lifecycle instead of creating a second goal aggregate.

Nutrition owns its reference, fact, estimate, and aggregate boundaries. Public immutable plain water
and private foods feed private ordered solid recipes; accepted `MealEntry` rows snapshot every exact
nutrition, hydration, quality, label, and basis value so later catalogue corrections cannot rewrite
history. `NutritionDailyTarget` is a concurrency-safe immutable user/date snapshot derived once from
Profile, selected body goal, and effective Workout occurrences with explicit planned energy. Actual
Workout energy only refines the read-time comparison. Nutrition computes selected-day and bounded
range summaries; Today transports that DTO and Review presents it without persisting a second copy.

Analytics is a read-only composition boundary over ten module-owned metric sources. Source modules
produce bounded owner-local daily primitives; the Analytics core alone constructs clipped daily,
Monday-week, and calendar-month buckets, comparisons, trends, and pairwise-complete correlations.
Its stable 17-metric catalog and three correlation definitions are API metadata, not persisted facts.
No Analytics service imports source models or stores raw history, and every response contains only
aggregate evidence rather than notes, journals, attachments, transactions, or identifiers.

Reports are human-readable projections of that existing Analytics workspace, never a new calculation
path. CSV and PDF reuse the same query validation, metric catalog, period comparison, evidence states,
and Profile locale. PDF rendering is server-side with a bundled Cyrillic-capable font and remote
resource access disabled.

Portability is a separate schema-v1 boundary over the authoritative owner tables. Export assigns
archive-local IDs, converts owned and polymorphic references explicitly, and uses stable system keys
for shared Exercise/Food catalogue rows. The ZIP keeps `manifest.json`, Profile/settings JSON,
record JSON, and private attachment bytes as separate checksum-declared members; database IDs,
`user_id`, credentials, sessions, storage paths, and delivery/runtime rows never cross the boundary.
Restore performs a read-only structural/content preflight, issues a short-lived HMAC token bound to
the target user and archive digest, locks and rechecks that the target is empty, then replaces rows
and private files atomically with newly allocated IDs/paths. It never changes target login identity
and does not support merge semantics.

AI is an optional adapter boundary, not a domain authority. Two fixed-host provider adapters consume
encrypted owner-supplied credentials selected at runtime; only a successful probe may make one
connection active. Per-scope consent and a bounded context builder govern disclosure. Providers emit
one closed tool proposal, while a backend registry, independent validator, encrypted one-use
confirmation capability, and the owning domain service authorize and execute any later write. The
first and only delivered tool triages one Storage Inbox item after explicit confirmation. Keys,
prompts, response bodies, and proposal content never enter audit rows or schema-v1 portability, and
the web/Android clients contain no provider logic or credential storage.

### `apps/web`

Vue 3 SPA for desktop and mobile web usage.

### `apps/mobile`

Capacitor 8 wrapper around the production web client for Android. It packages `apps/web/dist` and has
no `server.url`, remote HTML, or live-update path. The only platform-specific source is configuration,
resources, lifecycle integration, and a credential-vault plugin; product routes remain in Vue.

The bundled WebView cannot reuse the browser's same-origin cookie session. Android therefore exchanges
existing-account credentials for one 30-day Sanctum token with exactly the `mobile` ability, retrieves
that token from Android Keystore immediately before native HTTP calls, and revokes only that device
token on sign-out. The plaintext token exists only across issue/write and just-in-time read/request
boundaries. Browser Fetch, session cookies, and CSRF remain a separate unchanged transport.
Capacitor's native HTTP fetch patch carries bounded report/archive Blob and multipart operations with
that same bearer token; files remain explicit online WebView operations and are not an offline or
native database authority.

Android Local Notifications is a presentation adapter over feature 011: after explicit permission, it
mirrors already-delivered unread inbox records when the app synchronises or resumes, then records the
`android_local` channel. There is no stopped-app wakeup or FCM in this increment.

## Infrastructure

Local backend development is based on Open Server:

- Open Server as the Windows local environment manager
- PHP 8.4 for Laravel
- MySQL 8 for the primary database
- Redis as an optional local cache/queue backend
- Vue web app running separately through Vite during frontend development

Open Server is the primary local backend runtime because the project is also a learning path for PHP and Laravel on Windows.

## Delivery Model

- API and web stay decoupled through REST.
- Mobile reuses the web client instead of becoming a separate frontend codebase.
- Local development is optimized for Open Server first.
- Docker and homelab deployment may be added later, but they are not the current default.

## Suggested Near-Term Scope

1. Bootstrap API and web.
2. Define first domain slice: routines + daily review.
3. Deliver explicit multi-user registration/authentication with user-owned boundaries through
   [`003-multi-user-auth`](../specs/003-multi-user-auth/spec.md); keep roles and collaboration outside
   the first account slice.
