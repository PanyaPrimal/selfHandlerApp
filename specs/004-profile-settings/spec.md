# Feature Specification: Profile and Settings Foundation

**Feature ID**: `004-profile-settings`

**Created**: 2026-08-11

**Status**: Draft

**Input**: Establish the per-user profile inputs required by later SelfHandler modules: regional
preferences, baseline anthropometrics, and deterministic calculation preferences, without introducing
measurement history or downstream domain calculations.

**Design sources**: [Module 0 — User Profile](../../docs/design/modules.md#module-0--user-profile) ·
[Data Conventions](../../docs/design/data-conventions.md) ·
[Delivery Roadmap](../../docs/design/delivery-roadmap.md#004--profile-and-settings-foundation)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set Personal Regional Preferences (Priority: P1)

As a signed-in user, I set my display name, time zone, formatting locale, unit system, and base
currency so SelfHandler presents dates and future domain values for me rather than using one
installation-wide identity.

**Why this priority**: Time zone and regional preferences are inputs to every later schedule,
notification, measurement, nutrition, workout, and finance feature. They must become user-owned
before those features are delivered.

**Independent Test**: Sign in as two accounts, save different preferences, reload each account, and
verify that each user sees only their own values and that Today resolves its default calendar date in
that user's time zone.

**Acceptance Scenarios**:

1. **Given** an existing account with migrated defaults, **When** the user opens Profile and Settings,
   **Then** all current regional values are visible and can be understood before any edit.
2. **Given** valid regional values, **When** the user saves them, **Then** the saved values survive a
   reload and apply only to that account.
3. **Given** two users whose time zones are on different calendar dates at the same instant, **When**
   each opens Today without selecting a date, **Then** each sees the date and routine summary for
   their own local day.
4. **Given** a user changes their time zone, **When** they next open a date-sensitive screen, **Then**
   the default selected day uses the new time zone while previously stored calendar dates and facts
   remain unchanged.
5. **Given** a user changes between metric and imperial display, **When** a saved anthropometric value
   is shown, **Then** the same underlying quantity is displayed in the selected unit system without
   cumulative conversion drift.

---

### User Story 2 - Record Calculation Inputs (Priority: P2)

As a signed-in user, I record my baseline anthropometrics, non-sport activity, preferred metabolic
formula, and recommendation tone so later modules can calculate targets from one authoritative set
of inputs.

**Why this priority**: Nutrition, workouts, body goals, and recommendations cannot produce consistent
results if they each collect or interpret these values independently.

**Independent Test**: Save a complete valid baseline, reload it, change display units, and verify that
the same inputs are restored accurately; then clear optional values and verify the profile explains
which future calculations are not yet ready.

**Acceptance Scenarios**:

1. **Given** a user with no baseline anthropometrics, **When** they save valid birth date, sex,
   height, weight, and baseline non-sport activity, **Then** the profile records one current baseline
   for later module use.
2. **Given** a user selects the body-fat-based metabolic formula, **When** no valid body-fat percentage
   is provided, **Then** the save is rejected with a field-specific explanation and no partial change
   is applied.
3. **Given** a user saves weight and height using imperial display units, **When** they later switch to
   metric display, **Then** equivalent metric values are shown within normal display precision.
4. **Given** optional body-fat information was previously saved, **When** the user explicitly clears
   it while using the default formula, **Then** the field becomes absent without deleting the other
   profile inputs.
5. **Given** a baseline is incomplete, **When** the profile is viewed, **Then** the missing inputs are
   identifiable without blocking unrelated current SelfHandler features.

---

### User Story 3 - Recover Safely from Invalid or Unavailable Saves (Priority: P3)

As a user editing sensitive personal inputs, I receive clear validation, pending, success, and retry
states so I know whether my profile changed and never lose the last accepted values silently.

**Why this priority**: Ambiguous profile saves can corrupt the inputs used by many later modules or
make users repeat sensitive data entry.

**Independent Test**: Exercise invalid fields, a duplicate submit, an unavailable service, an expired
session, and a successful retry; verify that no partial or cross-account update occurs and that the
last accepted profile can always be restored.

**Acceptance Scenarios**:

1. **Given** one or more invalid fields, **When** the user saves, **Then** every invalid field is
   explained, focus can reach the first error, and none of the submitted changes are accepted.
2. **Given** a save is already pending, **When** the user attempts to submit again, **Then** only one
   save is processed and the pending state remains clear.
3. **Given** the service is unavailable, **When** a save is attempted, **Then** no success is claimed,
   the user's entered values remain available for retry, and the last accepted profile remains
   authoritative.
4. **Given** the session expires during editing, **When** the user attempts to save, **Then** the write
   is refused, no profile data is exposed, and the user is directed to sign in again.

### Edge Cases

- An existing account predates profile settings; it receives deterministic defaults without changing
  existing routines, logs, goals, reviews, or their calendar dates.
- A time zone observes daylight-saving changes or changes its UTC offset; the named zone, not a fixed
  offset, determines the user's local day.
- A user changes time zone close to midnight; subsequent default dates use the new zone, while an
  explicitly selected date stays explicit.
- A browser or device time zone differs from the saved profile; the saved profile is authoritative for
  SelfHandler day boundaries.
- Unit-system toggles happen repeatedly; persisted quantities do not accumulate rounding error.
- Birth date is in the future or implausibly old; the value is rejected without changing other fields.
- Weight, height, or body-fat percentage is at or beyond accepted safety bounds; validation is
  deterministic and field-specific.
- The body-fat-based formula is selected while body-fat percentage is cleared in the same save; the
  complete save is rejected atomically.
- A user submits an unknown time zone, locale, unit system, currency, formula, activity level, tone,
  or sex value; the value is rejected rather than stored for later interpretation.
- One account attempts to address another account's profile; the other profile is neither revealed
  nor changed.
- A user refreshes while loading or saving; only the last accepted server state is treated as saved.
- Long display names and translated formatting samples are viewed at a 390-pixel viewport; controls
  remain readable without horizontal page overflow.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Every authenticated account MUST have exactly one user-owned profile and settings record;
  signed-out users MUST NOT be able to read or change one.
- **FR-002**: Users MUST be able to view and update their display name without changing their email,
  password, account identifier, or authentication semantics in this feature.
- **FR-003**: Users MUST be able to select a valid named time zone, and date-sensitive default views
  MUST derive the user's current calendar date from that saved zone rather than the device or one
  installation-wide zone.
- **FR-004**: Changing time zone MUST affect future boundary and display decisions without rewriting
  stored instants, explicit calendar dates, or historical domain facts.
- **FR-005**: Users MUST be able to select metric or imperial display units. Physical quantities MUST
  retain one canonical value so repeated display-unit changes do not alter the underlying quantity.
- **FR-006**: Users MUST be able to select `en-GB`, `uk-UA`, or `ru-UA` formatting for dates and
  numbers. Interface translation is explicitly outside this feature.
- **FR-007**: Users MUST be able to select a supported ISO 4217 base currency for future consolidated
  financial views. This feature MUST NOT perform currency conversion or create financial records.
- **FR-008**: Users MUST be able to select a recommendation tone from neutral, friendly, or direct;
  neutral MUST be the default. This feature MUST NOT generate recommendations.
- **FR-009**: Users MUST be able to select Mifflin-St Jeor or Katch-McArdle as their metabolic formula;
  Mifflin-St Jeor MUST be the default.
- **FR-010**: Users MUST be able to save a current anthropometric baseline containing birth date,
  sex (`female`, `male`, or `unspecified`), height, weight, optional body-fat percentage, and baseline
  non-sport activity (`sedentary`, `light`, `moderate`, or `high`).
- **FR-011**: Selecting Katch-McArdle MUST require a valid body-fat percentage in the same accepted
  profile state. Other incomplete anthropometric inputs MUST remain allowed and visibly identifiable
  so current features are not blocked.
- **FR-012**: The accepted anthropometric bounds MUST be: birth date not in the future and no more than
  120 years ago; height from 50 through 300 centimetres; weight from 20 through 500 kilograms; and
  body-fat percentage from 2 through 75 when present.
- **FR-013**: A profile save MUST validate the complete submitted state and apply all accepted changes
  atomically; one invalid field MUST prevent partial persistence.
- **FR-014**: Users MUST be able to clear optional body-fat percentage explicitly without clearing
  unrelated values, provided the resulting formula selection remains valid.
- **FR-015**: Profile reads and writes MUST be scoped only to the authenticated account and MUST expose
  no existence or value signal for another account's profile.
- **FR-016**: New and existing accounts MUST receive deterministic defaults: the configured SelfHandler
  time zone, `en-GB` formatting, metric units, UAH base currency, neutral recommendation tone, and
  Mifflin-St Jeor formula. Existing domain records MUST remain unchanged during default creation.
- **FR-017**: Successful reads and saves MUST return the complete non-secret current profile state so
  clients can restore it after reload and show whether required future calculation inputs are missing.
- **FR-018**: Profile editing MUST provide explicit loading, unchanged, unsaved, saving, saved,
  validation-error, session-expired, service-error, and retry states without discarding unsaved input
  after a recoverable failure.
- **FR-019**: Profile controls and errors MUST be keyboard reachable, programmatically labelled,
  focus-recoverable, and usable without horizontal page overflow at a 390-pixel viewport.
- **FR-020**: This feature MUST NOT add measurement history, body recommendations, recurrence rules,
  reminders, notifications, currency conversion, finance behavior, workout targets, nutrition targets,
  AI behavior, avatar/file uploads, email changes, or password changes.

### Key Entities

- **User Profile**: The single user-owned source of cross-module inputs. It contains regional
  preferences, calculation preferences, the current anthropometric baseline, completeness state, and
  change timestamps; it does not contain measurement history.
- **Account Identity**: The existing authenticated user's identifier, display name, and email. This
  feature may change only the display name and links it one-to-one with the User Profile.
- **Anthropometric Baseline**: The current birth date, sex, height, weight, optional body-fat
  percentage, and non-sport activity inputs within the User Profile. Later features may read it but
  MUST NOT create competing copies.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A signed-in user can review defaults and save a valid regional profile in under three
  minutes without assistance.
- **SC-002**: Across acceptance tests using at least two accounts and time zones on opposite sides of
  a calendar-day boundary, 100% of default Today dates follow the authenticated user's saved zone.
- **SC-003**: Repeated metric-to-imperial-to-metric display changes preserve height and weight within
  the documented display precision in 100% of tested round trips.
- **SC-004**: Every invalid-field scenario rejects the complete save, identifies all invalid fields,
  and leaves the last accepted profile unchanged.
- **SC-005**: Cross-account profile read and write attempts expose zero personal fields and produce
  zero changes to the other account.
- **SC-006**: Existing users receive defaults with zero changes to the identifiers, dates, ownership,
  or counts of existing routines, logs, goals, goal links, and daily reviews.
- **SC-007**: Loading, save, failure, retry, session-expiry, and reload journeys complete without stale
  success claims, lost recoverable input, or protected data from a previous account.
- **SC-008**: All Profile and Settings journeys pass keyboard/focus checks and complete at an exact
  390-pixel viewport with no horizontal page overflow.

## Assumptions

- Existing invite-only authentication and ownership behavior from `003-multi-user-auth` remains the
  account boundary and is not redesigned.
- The configured SelfHandler time zone is the correct migration/default value for existing accounts;
  users may change it after the feature ships.
- `en-GB`, `uk-UA`, and `ru-UA` are the first supported formatting locales, with `en-GB` as the
  default. The list may grow later, but arbitrary or unsupported tags are rejected.
- UAH is the default base currency for this installation. The feature records the preference but does
  not need exchange rates or a currency catalog UI.
- Metric values are the canonical persistence meaning; imperial values are input/display conversions.
- Baseline non-sport activity excludes planned or completed workouts to prevent future target
  calculations from double-counting exercise.
- The profile is online-only in this increment; Android/offline synchronization belongs to later
  delivery features.
