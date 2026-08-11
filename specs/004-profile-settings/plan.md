# Implementation Plan: Profile and Settings Foundation

**Feature**: `004-profile-settings` | **Date**: 2026-08-11 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/004-profile-settings/spec.md`

## Summary

Deliver one private per-user profile that owns regional preferences, calculation preferences, and a
current anthropometric baseline. Add an authenticated read/full-save profile contract and turn the
existing Account screen into a resilient Profile and Settings workspace. Replace the global
SelfHandler calendar-timezone assumption at current user-facing boundaries with the authenticated
user's saved named zone while preserving UTC storage, explicit calendar dates, existing history, and
all feature-001 behavior.

The design deliberately stops before measurement history, recurrence, notifications, finance,
nutrition/workout calculations, files, mobile behavior, and AI. It uses existing Laravel/Vue/session
boundaries, an additive one-to-one profile table, explicit canonical physical values, and no new
runtime dependency.

## Technical Context

**Language/Version**: PHP `^8.4` and TypeScript `~6.0`

**Primary Dependencies**: Laravel `^12.61.1`, Eloquent/Carbon, Sanctum `^4`, Vue `^3.5`, Vue Router
`^5.1`, Vite `^8`; browser `Intl` APIs for display formatting

**Storage**: MySQL 8 in normal use; SQLite for isolated automated tests; additive `user_profiles`
table with exactly one row per user and canonical metric values

**Testing**: PHPUnit 11 feature/unit tests, Laravel Pint, `vue-tsc`, Vite production build, Playwright
1.61 desktop and exact 390-pixel projects

**Target Platform**: Responsive online web application on modern phone and desktop browsers;
Windows/Open Server is the primary local backend environment

**Project Type**: Monorepo web application with a REST API and a deferred Capacitor Android shell

**Performance Goals**: Profile read/save visibly resolves within two seconds in normal homelab use;
Today/progress query counts remain bounded and profile resolution adds no per-routine query pattern

**Constraints**: Existing authenticated cookie session remains unchanged; profile/anthropometric data
is private; storage timestamps stay UTC; display-unit changes never rewrite canonical quantities;
existing live rows require additive backfill; online-only; no downstream calculations or generalized
settings framework

**Scale/Scope**: One profile per account, three user stories, two authenticated profile operations,
one existing Account route, and timezone propagation through the existing Today/routine/progress paths

## Constitution Check

*GATE: Passed before Phase 0 research and re-checked after Phase 1 design.*

- **Specifications Before Implementation**: PASS. [spec.md](spec.md) defines prioritized user value,
  independent acceptance journeys, exact validation boundaries, measurable outcomes, and explicit
  deferrals before code changes.
- **Vision and Delivery Sources**: PASS. Module 0, data conventions, and the dependency roadmap remain
  authoritative; this feature links to them and delivers only the `004` slice.
- **Thin Vertical Slices**: PASS. Regional preferences are usable independently; anthropometrics and
  recovery behavior build on the same current profile consumer. No recurrence, notification, mobile,
  finance, analytics, or AI framework is pulled forward.
- **Deterministic Core**: PASS. Validation, unit conversion, completeness, locale selection, and time
  boundaries are deterministic and require no LLM.
- **User-Owned Data and Privacy**: PASS. `user_profiles.user_id` is both owner and unique identity;
  routes never accept a target account id; canonical units, UTC timestamps, and calendar dates follow
  [data conventions](../../docs/design/data-conventions.md).
- **Contracts and Tests**: PASS. The design includes OpenAPI, backend feature/unit coverage, typed
  client changes, auth/current-user compatibility checks, and browser journeys on desktop/390px.
- **Branch Governance**: PASS. Work remains on the user's current `master`; no branch automation,
  switch, merge, or Git extension is introduced.

### Post-Design Re-check

PASS. The separate profile row is justified by a current Account/Profile UI and current Today/timezone
consumer, isolates sensitive health inputs from authentication credentials, and has no speculative
repository or provider layer. The HTTP contract, data model, migration/backfill, and validation guide
resolve all technical unknowns without a constitution exception.

## Project Structure

### Documentation (this feature)

```text
specs/004-profile-settings/
├── spec.md
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── openapi.yaml
├── checklists/
│   └── requirements.md
└── tasks.md
```

### Source Code (repository root)

```text
apps/api/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── ProfileController.php
│   │   │   ├── RoutineLogController.php
│   │   │   └── TodayController.php
│   │   ├── Requests/
│   │   │   └── UpdateProfileRequest.php
│   │   └── Resources/
│   │       ├── ProfileResource.php
│   │       └── UserResource.php
│   ├── Models/
│   │   ├── User.php
│   │   └── UserProfile.php
│   ├── Services/
│   │   ├── RoutineProgressService.php
│   │   └── RoutineScheduleService.php
│   └── Support/
│       └── ProfileDefaults.php
├── database/
│   ├── factories/
│   │   └── UserProfileFactory.php
│   └── migrations/
│       └── 2026_08_11_*.php
├── routes/api.php
└── tests/
    ├── Feature/Profile/
    │   ├── ProfileApiTest.php
    │   ├── ProfileMigrationTest.php
    │   └── ProfileTimezoneBoundaryTest.php
    └── Unit/Profile/
        └── ProfileValueConversionTest.php

apps/web/
├── src/
│   ├── api/
│   │   ├── client.ts
│   │   └── types.ts
│   ├── auth/
│   │   └── session.ts
│   ├── lib/
│   │   ├── format.ts
│   │   └── units.ts
│   └── views/
│       └── AccountView.vue
└── e2e/
    └── profile-settings.spec.ts
```

**Structure Decision**: Keep the existing API/web delivery units and `/account` route. Add one
first-party profile model/resource/request/controller, a small defaults helper shared by migration and
registration, and pure frontend unit-conversion helpers. Do not add a settings package, repository
layer, client state library, or mobile implementation.

## Impact and Migration Safety

Direct inspection identifies a **HIGH blast radius** because current-user identity and configured
timezone participate in authentication restoration, AppShell/Today display, Today selection, routine
schedule evaluation, progress/streak calculation, routine-log date validation, and many regression
tests. Authentication semantics themselves do not change.

Risk controls:

1. Add `user_profiles` and backfill before any read path requires it.
2. Preserve `config('selfhandler.timezone')` only as the deterministic provisioning fallback; runtime
   authenticated behavior reads the user's profile.
3. Pass the resolved timezone explicitly into routine scheduling/progress helpers so loops never lazy
   load a profile and never depend on ambient mutable configuration.
4. Keep `UserResource` backward-compatible (`id`, `name`, `email`) and add only a typed `preferences`
   object needed by shared display consumers.
5. Update feature-001 timezone regression tests to create users with explicit zones, and retain tests
   proving calendar-date fields never shift.
6. Snapshot row counts and representative calendar dates before/after the migration in an isolated
   migration test; the production rollout remains a later explicit action.

## Implementation Strategy

### Foundation

1. Create a one-to-one `user_profiles` row keyed by `user_id`; backfill every existing account from
   deterministic installation defaults without touching any domain table.
2. Provision the same defaults inside new-account registration. The profile endpoint defensively
   repairs a missing row for legacy/test-created accounts, but normal product creation always writes
   both account and profile in one transaction.
3. Persist height as `DECIMAL` metres and weight as integer grams. Persist body-fat percentage as a
   bounded decimal. Imperial/metric values are display/input conversions around these canonical fields.
4. Compute readiness/missing fields from the selected formula and baseline; do not persist a stale
   completeness cache.

### User Story Delivery

1. **P1 Regional preferences**: authenticated GET/full PUT, default/backfill behavior, display name,
   time zone, locale, unit system, base currency, typed client state, and immediate per-user Today date.
2. **P2 Calculation inputs**: birth date, sex, height, weight, body fat, activity, formula/tone,
   canonical conversion, atomic validation, and explicit completeness presentation.
3. **P3 Recovery and accessibility**: unsaved/saving/saved/validation/session/service/retry states,
   duplicate-submit protection, keyboard/focus recovery, 390px overflow, and multi-user privacy tests.

### Contract Evolution

`GET /api/profile` returns the complete current profile and supported option lists. `PUT /api/profile`
accepts one complete canonical state and atomically updates the account display name plus profile. The
route has no account identifier. Unknown ownership input cannot select another user. The existing
current-user/auth responses retain their fields and gain a `preferences` summary so shared screens can
format dates without loading sensitive anthropometrics. See [contracts/openapi.yaml](contracts/openapi.yaml).

### Completion Gate

Before completion, run the full backend suite and formatter, frontend typecheck/build, and all
Playwright projects. In addition to profile-specific journeys, existing auth and core-daily-loop tests
must remain green because the feature changes their identity/timezone boundaries.

## Complexity Tracking

No constitution violations or complexity exceptions are required.

## Implementation Results

**Status:** Complete on 2026-08-12. All `34/34` tasks are implemented; no deployment work was included.

- Persistence: additive `user_profiles` migration, unique owner, deterministic backfill, registration
  provisioning, and defensive missing-row repair.
- Contract: authenticated `GET /api/profile` and atomic full-state `PUT /api/profile`; the current-user
  response gains only a non-sensitive preference summary.
- Product: regional preferences, canonical anthropometrics, formula-aware readiness, unit display
  conversion, accessible save/retry/error states, and responsive 390px layout.
- Time boundary: authenticated Today, routine-log validation, scheduling, and progress resolve one
  explicit user timezone; the installation setting remains a provisioning fallback.

### Completion Evidence

- Laravel: `120 passed` with `918 assertions` after the final profile/unit additions.
- Formatting: `vendor/bin/pint --test` passes.
- Web: `npm run build` passes (`vue-tsc --noEmit` plus Vite production build).
- Browser: `30 passed` across desktop and exact `390x844` mobile projects.
- Disposable data-bearing migration check: one pre-existing user remained one user and gained exactly
  one profile; `evidence@example.test` was preserved and the profile received the configured
  `Europe/Kyiv` default. The temporary SQLite file was removed after verification.

No accepted product deviation exists. The intentionally deferred scope in `spec.md` remains deferred.
