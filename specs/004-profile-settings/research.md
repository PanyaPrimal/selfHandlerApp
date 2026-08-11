# Research: Profile and Settings Foundation

All plan-time unknowns are resolved below. No external service or new runtime package is required.

## Profile Persistence Boundary

**Decision**: Use a dedicated `user_profiles` table with `user_id` as a unique required owner and a
one-to-one `User::profile()` relationship.

**Rationale**: The profile contains sensitive health inputs and will grow independently from login
credentials. Separation keeps the authentication row focused, makes explicit profile resources safer,
and still uses one direct relationship with no repository abstraction.

**Alternatives considered**:

- Add every field to `users`: simpler initially, but mixes credentials, preferences, and health data
  and makes accidental identity serialization more costly.
- Split preferences and anthropometrics into multiple tables: rejected because this increment has one
  current baseline and one save boundary; extra tables add no current value.

## Existing and New Account Defaults

**Decision**: An additive migration creates/backfills profiles for all current users. Registration
creates the profile in the existing account transaction. A single `ProfileDefaults` helper supplies
the configured time zone plus fixed locale/unit/currency/tone/formula defaults to both paths.

**Rationale**: Every normal account immediately has one profile, migration is data-preserving, and
defaults cannot silently diverge between backfill and registration.

**Alternatives considered**:

- Lazy creation on first Profile visit: rejected because downstream Today already needs a profile and
  the specification requires every account to have one.
- Database trigger: rejected as unnecessary vendor-specific behavior that would complicate SQLite
  verification and application ownership.

## Canonical Anthropometric Values

**Decision**: Persist height in metres to three decimals, weight in integer grams, and body-fat
percentage to two decimals. The API exchanges canonical values; the web client converts centimetres/
kilograms or feet/inches/pounds only at input/display boundaries.

**Rationale**: Canonical values follow data conventions and prevent repeated metric/imperial toggles
from rewriting rounded values. Three-decimal metres preserve millimetre precision across the accepted
height range; integer grams exceed required display precision across the weight range.

**Alternatives considered**:

- Persist the user's display value and unit: rejected because unit changes would rewrite or reinterpret
  stored quantities.
- Store floating-point values: rejected because repeated conversions accumulate binary rounding drift.

## Profile API Shape

**Decision**: Add authenticated `GET /api/profile` and idempotent full-state `PUT /api/profile`.
GET returns `data` plus finite supported option lists. PUT updates `users.name` and `user_profiles`
atomically and returns the same complete representation.

**Rationale**: A full replacement contract matches the atomic-save requirement and keeps validation
of cross-field formula/body-fat rules deterministic. A fixed current-user route removes object-level
authorization ambiguity.

**Alternatives considered**:

- PATCH individual fields: rejected for this increment because cross-field validity and unit/formula
  changes make partial update semantics easier to misuse.
- Put all profile fields in `/api/auth/user`: rejected because every session restoration would return
  sensitive anthropometrics even when no profile screen needs them.

## Current-User Compatibility

**Decision**: Preserve `id`, `name`, and `email` in the current-user resource and add a non-sensitive
`preferences` summary containing time zone, locale, unit system, base currency, tone, formula, and
calculation readiness. Profile saves refresh the frontend session identity from the returned user
summary.

**Rationale**: Shared screens need locale/timezone inputs while anthropometrics remain behind the
explicit Profile read. Existing consumers remain structurally compatible and display-name changes are
visible immediately.

**Alternatives considered**:

- Keep the auth shape unchanged and load Profile on every app boot: rejected because it adds a second
  blocking request before Today and duplicates session-level preference state.
- Return all anthropometrics during auth restoration: rejected as unnecessary exposure.

## Timezone Propagation

**Decision**: Resolve the authenticated user's named time zone once at request/service entry and pass
it explicitly to routine schedule/progress helpers. Installation config remains only a provisioning
fallback, never the authority for an authenticated user who has a profile.

**Rationale**: Explicit input prevents ambient config mutation, supports simultaneous users in
different zones, and avoids lazy profile queries inside routine loops.

**Alternatives considered**:

- Mutate Laravel config per request: rejected because it is ambient state and unsafe for long-lived
  workers/tests.
- Have every routine lazy-load its owner's profile: rejected because it creates N+1 risk.

## Supported Option Sets

**Decision**: The initial finite sets are locales `en-GB`, `uk-UA`, `ru-UA`; unit systems `metric`,
`imperial`; base currencies `UAH`, `USD`, `EUR`; tones `neutral`, `friendly`, `direct`; formulas
`mifflin_st_jeor`, `katch_mcardle`; sex values `female`, `male`, `unspecified`; activity values
`sedentary`, `light`, `moderate`, `high`. Named time zones come from the runtime's standard IANA list.

**Rationale**: Finite values are testable and cover the current installation without introducing a
currency/reference module. IANA zones are already required for daylight-saving correctness.

**Alternatives considered**:

- Arbitrary locale/currency strings: rejected because unsupported formatting or financial inputs would
  be stored without a consumer contract.
- A currency catalog/exchange-rate table: deferred to the Finance ledger feature.

## Completeness Semantics

**Decision**: Compute `calculation_ready` and `missing_fields` at read time. Mifflin-St Jeor requires
birth date, male/female sex, height, weight, and activity. Katch-McArdle requires weight, body-fat
percentage, and activity; other saved inputs remain useful but are not formula prerequisites.

**Rationale**: Readiness must reflect the selected formula and current inputs immediately; persisting
it would introduce a derived cache with no performance need.

**Alternatives considered**:

- Require a complete profile before any save: rejected because current unrelated features must remain
  usable and gradual data entry is explicit product behavior.
- One formula-independent completeness flag: rejected because the two formulas require different data.

## Frontend State and Accessibility

**Decision**: Extend the existing Account view and session store. Keep a separate editable draft and
accepted snapshot, use the existing `ApiError`/validation contract, block duplicate submits, preserve
drafts after recoverable failures, and update accepted/session state only after success.

**Rationale**: This matches established async/recovery behavior and avoids another client state
library. It also provides a precise unsaved/saved boundary for sensitive inputs.

**Alternatives considered**:

- Auto-save each field: rejected because atomic cross-field validation and service failures would be
  ambiguous.
- New settings route hierarchy: deferred until multiple settings subsystems exist.
