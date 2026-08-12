# Feature Specification: Interface Personalisation and Complete Localisation

**Feature Branch**: `010-interface-personalization`

**Created**: 2026-08-13

**Status**: Complete

**Input**: Complete the existing appearance work with safe background personalisation, localise the
entire current product in English, Russian and Ukrainian, and expose a global language selector plus
a quick light/dark toggle on authenticated and guest screens without first-paint flash.

## User Scenarios & Testing

### User Story 1 - Use SelfHandler in My Language (Priority: P1)

As a guest or signed-in user, I can switch the whole current interface between English, Russian and
Ukrainian and immediately continue the task I was doing.

**Why this priority**: A partially translated application is not a usable language choice, and the
language selector is the entry point for every other screen.

**Independent Test**: Start signed out, choose each language, register or sign in, visit every current
route, trigger representative loading/empty/validation/domain errors, reload and restore the session.
Every product-owned string and formatter uses the selected/profile language without a mixed-language
frame or loss of a profile draft.

**Acceptance Scenarios**:

1. **Given** a guest with no cache, **when** the application first renders, **then** English appears
   and a global EN/RU/UK selector is operable on the authentication screen.
2. **Given** a guest who selected Russian or Ukrainian, **when** the page reloads, **then** the cached
   language is applied before Vue mounts and no English first frame appears.
3. **Given** an authenticated user whose profile locale differs from the guest cache, **when** session
   restoration succeeds, **then** the profile locale becomes active and replaces the derived cache.
4. **Given** an authenticated user with an unsaved Account draft, **when** they switch language from
   the global selector, **then** only the locale preference is saved, the draft remains unchanged, and
   the current route and focusable workflow remain usable.
5. **Given** an authenticated locale save that fails, **when** the optimistic change is rejected,
   **then** the last accepted profile locale and cache are restored and localized feedback explains
   the failure.
6. **Given** any current route, **when** the active locale changes, **then** navigation, content,
   controls, placeholders, states, feedback, ARIA/title text, changelog content, enum/status labels,
   dates, numbers, units and plurals update without a full page reload.

---

### User Story 2 - Personalise a Safe Background (Priority: P1)

As an authenticated user, I can choose a curated background or provide a custom colour and preview a
readable light and dark result before saving it to my profile.

**Why this priority**: Background is the missing half of the existing appearance feature and must be
designed together with contrast, both colour schemes and profile persistence.

**Independent Test**: Open Appearance, choose every preset and a custom HEX colour, preview it in
light/dark/system schemes, verify contrast feedback, save, reload, switch device scheme and sign in
from a fresh browser context. The same safe palette is restored without first-paint flash.

**Acceptance Scenarios**:

1. **Given** existing theme data without background fields, **when** it is read, **then** the default
   paper background is supplied without a destructive migration or changed legacy appearance.
2. **Given** a light, dark or system scheme, **when** a background preset is selected, **then** its
   scheme-specific paper, surface, border, text and texture tokens update in the live preview and UI.
3. **Given** a valid custom six-digit HEX input, **when** it is applied, **then** deterministic
   scheme-safe tokens are derived and the minimum text/background contrast is shown before saving.
4. **Given** invalid custom input, **when** it is submitted, **then** the last valid background remains
   active, the draft is not corrupted and localized inline guidance is shown.
5. **Given** an attempted custom colour with poor raw contrast, **when** tokens are derived, **then**
   the application tints it into safe light/dark surfaces and never accepts arbitrary CSS.
6. **Given** a save failure, **when** the API rejects or cannot persist the theme, **then** all theme
   and background tokens roll back to the last accepted profile value.

---

### User Story 3 - Toggle Light and Dark Anywhere (Priority: P1)

As a guest or signed-in user, I can switch between light and dark from any screen without navigating
to settings.

**Why this priority**: The requested quick control must work consistently with the same theme source,
cache, persistence and rollback behavior as the full Appearance screen.

**Independent Test**: Toggle on login, registration, authenticated desktop and 390x844 mobile views;
reload each state and restore a profile. The button label, icon, scheme, background and persisted
preference remain consistent.

**Acceptance Scenarios**:

1. **Given** any guest screen, **when** the quick toggle is used, **then** the resolved scheme changes
   immediately and is stored only in the guest paint cache.
2. **Given** any authenticated screen, **when** the quick toggle is used, **then** the full current
   theme is optimistically applied and saved through the partial preference endpoint.
3. **Given** a system-following theme, **when** the quick toggle is used, **then** it becomes the
   opposite explicit light/dark scheme while preserving accent, background and interface details.
4. **Given** an authenticated save failure, **when** the quick toggle request fails, **then** the last
   accepted profile theme is restored and announced.

---

### User Story 4 - Keep Later Features Fully Localised (Priority: P2)

As the product evolves, I receive complete EN/RU/UK text with each feature instead of a gradually
mixed interface.

**Why this priority**: The initial translation would regress immediately without repository rules and
automated enforcement.

**Independent Test**: Remove one locale key, reference an unknown static key, or add unapproved
hardcoded user copy to a checked frontend surface. The localisation gate fails with the file/key; the
normal repository passes it and all Spec Kit templates require localisation work.

**Acceptance Scenarios**:

1. **Given** the three locale catalogs, **when** the gate runs, **then** exact key parity with canonical
   English is required.
2. **Given** statically referenced message keys, **when** the gate runs, **then** unknown/unused key
   mistakes are reported while documented dynamic-key families remain explicit.
3. **Given** new product-owned text in a checked Vue/TypeScript surface, **when** it is not routed
   through the translation layer or a narrow allowlist, **then** the gate fails.
4. **Given** a future Spec Kit feature, **when** its artifacts are authored, **then** the specification,
   plan, tasks and checklist templates require all three languages and their gates.

### Edge Cases

- Storage access is blocked, contains malformed JSON, an unsupported locale or an old theme shape.
- Session restoration is slow, unavailable, returns 401, or completes after a guest preference change.
- Locale/theme requests finish out of order after repeated rapid selection.
- A profile is missing or repaired lazily and contains an unsupported historical locale.
- The browser changes `prefers-color-scheme` while scheme is `system` and a custom background is active.
- A custom HEX value is pasted with whitespace/lowercase/no leading `#`, or is invalid mid-edit.
- Long Russian/Ukrainian copy at 390x844, browser zoom and keyboard-only navigation.
- User content contains English-like text and must never be translated or rejected by the hardcoded-copy gate.
- API validation/domain feedback is triggered before authentication and after authentication.

## Requirements

### Functional Requirements

- **FR-001**: The supported product locales MUST be exactly `en-GB`, `ru-UA`, and `uk-UA`.
- **FR-002**: English MUST define one canonical typed message-key set; Russian and Ukrainian MUST
  have exact key parity and values MUST never be blank.
- **FR-003**: Every current product-owned visible string MUST be localized, including navigation,
  controls, placeholders, helpers, actions, loading/empty/success/error states, validation/domain
  feedback, ARIA/title text, changelog content and enum/status labels.
- **FR-004**: User-authored content, brand name, technical token names and API paths MUST be preserved
  verbatim rather than translated.
- **FR-005**: Dates, numbers, currencies, units and plural forms MUST use the active locale and must
  preserve date-only/time-zone invariants from existing features.
- **FR-006**: A global accessible language selector and quick resolved-scheme toggle MUST be available
  on guest/authentication, unavailable, authenticated desktop and authenticated 390x844 screens.
- **FR-007**: Guest locale and theme changes MUST be cached locally and applied before application
  mount; unavailable or malformed storage MUST fall back safely to the defaults.
- **FR-008**: For authenticated users, `UserProfile.locale` and `UserProfile.theme_preferences` MUST
  remain authoritative; session/profile restoration MUST reconcile the paint caches to the profile.
- **FR-009**: Locale and theme MUST be independently patchable without sending, validating or
  replacing Account form fields or its unsaved draft.
- **FR-010**: Authenticated global preference changes MUST update optimistically, persist to the
  profile, ignore stale out-of-order results, and roll back to the last accepted value on failure.
- **FR-011**: Guest preference changes MUST NOT write account data; login/registration/session restore
  MUST replace them with the resulting profile preferences.
- **FR-012**: Every API/CSRF request MUST advertise the active supported locale; the API MUST prefer
  the authenticated profile locale when available and otherwise use the supported request locale.
- **FR-013**: Framework validation, auth errors and domain validation/warnings that can reach the UI
  MUST use backend translation keys for all three languages or stable codes translated by the client.
- **FR-014**: Theme preferences MUST include a background preset and custom background HEX while
  remaining backward compatible with existing JSON values that lack those fields.
- **FR-015**: Background choices MUST include a default and multiple curated presets with distinct,
  scheme-specific palettes plus a custom option.
- **FR-016**: Custom background input MUST accept only a normalized six-digit HEX colour and MUST
  never be inserted as arbitrary CSS or markup.
- **FR-017**: A deterministic algorithm MUST derive paper, surface, warm surface, borders, ink,
  muted/subtle text and texture tokens from the custom colour for both light and dark schemes.
- **FR-018**: Derived background palettes MUST maintain at least 4.5:1 contrast for normal text on
  paper and primary surface; the Appearance screen MUST show the minimum ratio before saving.
- **FR-019**: Invalid custom input MUST retain the last valid applied palette and provide localized
  inline feedback; saving MUST be blocked while the selected custom draft is invalid.
- **FR-020**: Appearance MUST keep a live preview for scheme, accent, background and interface-detail
  preferences and MUST apply all settings atomically on save or rollback.
- **FR-021**: The quick theme toggle MUST preserve accent, background, texture, numeral and motion
  preferences and change only `scheme` to the opposite resolved explicit scheme.
- **FR-022**: The initial HTML prehydration path and runtime normalization MUST use compatible locale
  and complete theme defaults so no wrong-language or wrong-theme first frame is painted.
- **FR-023**: The application MUST remain usable with local storage disabled and without JavaScript
  prehydration data; caches are an optimization, never authoritative persistence.
- **FR-024**: Automated gates MUST verify locale parity, statically used keys and unapproved hardcoded
  frontend product copy, with narrow documented exceptions for user content and technical literals.
- **FR-025**: Spec Kit constitution and templates MUST make three-locale delivery and localisation
  gates mandatory for every future user-facing feature.
- **FR-026**: Current auth, ownership, profile, recurrence, planner, Storage, Body, review and
  changelog behavior MUST remain unchanged except for localized presentation and preference changes.
- **FR-027**: No deployment file, live environment, live database or `specs/002-homelab-deployment`
  artifact may be changed by this feature.
- **FR-028**: The responsive interface MUST have no horizontal overflow at 390x844 in any supported
  locale, and preference controls MUST retain keyboard, visible-focus and accessible-name behavior.

### Localisation Surface

- **Locales**: complete `en-GB`, `ru-UA`, and `uk-UA` catalogs.
- **Current product surface**: App startup/unavailable states; auth; global preferences; navigation;
  Today/progress; routines; goals; review; Planner; Storage; Body; Account; Appearance; changelog;
  shared UI control defaults; validation/auth/domain feedback and accessible labels.
- **Formatting**: date-only calendar display, numbers, percentages, unit/currency labels, counts and
  plural forms use the active locale. Existing canonical storage formats do not change.
- **Non-translatable content**: `SELFHANDLER`, user-entered content, email addresses, IANA zones,
  currency codes, API paths, CSS/data token names, feature identifiers and numeric time values.
- **Verification**: catalog parity, used-key and hardcoded-copy gates; API locale tests; desktop and
  exact 390x844 browser journeys in all three locales.

### Key Entities

- **Profile locale**: existing user-owned locale and the authenticated source of truth.
- **Theme preferences**: existing profile JSON value extended with background preset/HEX fields;
  normalized as one complete value.
- **Guest paint cache**: versioned, best-effort locale/theme copies used before authentication and
  first paint; never domain data.
- **Message catalog**: canonical English keys plus parity-checked Russian and Ukrainian values.
- **Background palette**: deterministic scheme-specific presentation tokens derived from a preset or
  validated custom colour; not a new persisted aggregate.

## Success Criteria

### Measurable Outcomes

- **SC-001**: All current routes complete representative normal, loading/empty and error journeys in
  each of the three locales with zero missing-key or mixed product-copy findings.
- **SC-002**: Reload tests in guest and authenticated contexts observe the cached/profile language,
  scheme and background on the first application frame with no fallback flash.
- **SC-003**: A language change from Account with an unsaved draft preserves every draft value while
  persisting only locale; a failed request restores the last accepted locale.
- **SC-004**: Every preset and representative custom colours produce at least 4.5:1 minimum normal-text
  contrast on paper and surface in both light and dark schemes.
- **SC-005**: Exact 390x844 journeys for guest and authenticated screens have no horizontal overflow
  in English, Russian or Ukrainian and all global controls remain keyboard accessible.
- **SC-006**: Deleting one translation, referencing an unknown key, or adding an unapproved hardcoded
  product string makes the localisation gate fail with an actionable location.
- **SC-007**: Partial preference API tests prove owner isolation, unknown-field refusal, atomic
  validation, backward theme normalization and locale/theme-only mutation.
- **SC-008**: The complete Laravel suite, Pint, frontend typecheck/build, localisation checks and both
  Playwright projects pass with no regression in existing behavior.

## Assumptions

- No external i18n package is required: the current small Vue application can use a typed local
  catalog/runtime built on Vue reactivity and `Intl` without increasing its dependency surface.
- The existing `UserProfile.locale` values remain the public locale identifiers.
- Existing `theme_preferences` JSON can be extended additively without a schema migration.
- The appearance commit immediately preceding this feature is baseline evidence and is completed and
  specified here rather than reverted or replaced.
- Deployment, Android packaging, later roadmap modules and live data are outside this feature.
