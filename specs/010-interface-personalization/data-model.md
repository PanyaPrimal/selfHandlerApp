# Data Model: Interface Personalisation and Complete Localisation

## Persisted Data

### UserProfile (existing)

No new table or column is introduced.

| Field | Type | Change |
|---|---|---|
| `locale` | supported string | Existing authoritative authenticated locale; partial PATCH capable |
| `theme_preferences` | nullable JSON | Existing value gains `background` and `background_hex` defaults |

### Normalized ThemePreferences

```text
scheme          light | dark | system
accent          forest | slate | gold | brick | custom
accent_hex      #RRGGBB
background      paper | sand | mist | sage | custom
background_hex  #RRGGBB
texture         boolean
mono_numerals   boolean
motion          system | reduce
```

All fields are required in a submitted theme. Reads normalize missing/invalid fields independently to
defaults. Old JSON therefore becomes complete without a migration; it is rewritten only after the
user next saves a theme.

Default additions:

```text
background      paper
background_hex  #ECE9E2
```

`paper` reproduces the pre-feature light/dark token values.

## Derived Browser State

### Locale state

```text
active          en-GB | ru-UA | uk-UA
accepted        last profile-accepted locale, or null for guest
initialized     boolean
requestSequence monotonically increasing integer
```

### Locale cache

Key: `selfhandler.locale.v1`

Value: one supported locale string. Invalid/blocked/missing storage yields `en-GB`.

### Theme cache

Key remains `selfhandler.theme.v1`.

Value: normalized `ThemePreferences`. An old cache is normalized with the new background defaults.
It is only a paint cache.

## Derived BackgroundPalette

Not persisted:

```text
paper, paperSoft, surface, surfaceWarm,
ink, muted, subtle,
border, borderStrong, fieldBorder,
textureDot,
minimumTextContrast
```

A preset provides one palette for each resolved scheme. A custom colour is normalized, mixed into a
bounded scheme base, and adjusted deterministically until:

```text
contrast(ink, paper) >= 4.5
contrast(ink, surface) >= 4.5
```

## State Transitions

### Guest locale/theme change

1. Normalize selection.
2. Apply to reactive state and document tokens/lang.
3. Best-effort cache write.
4. Do not call the profile API.

### Session restoration

1. Prehydration/runtime reads cache for first paint.
2. Successful current-user response supplies profile locale/theme.
3. Normalize profile values, apply them, replace caches and set accepted values.
4. `401` keeps guest cache; unavailable state keeps the current paint without claiming account state.

### Authenticated optimistic preference change

1. Capture last accepted profile value and increment request sequence.
2. Normalize/apply/cache requested value.
3. PATCH only the changed preference under `preferences`.
4. Latest success accepts and reconciles the returned profile.
5. Latest failure restores the captured accepted value; stale completions do nothing.

### Account full-profile save

The Account draft excludes locale. Its PUT payload injects the current accepted session locale at
submission time. Global locale changes therefore neither modify the draft snapshot nor get reverted
by an unrelated profile save.

## Validation and Ownership

- PATCH requires authenticated ownership and derives the profile from the session.
- `preferences` must contain at least one of `locale` or `theme` and no other keys.
- `locale` must be in the configured supported set.
- A submitted theme must contain only and all normalized fields.
- HEX fields must match `^#[0-9a-fA-F]{6}$` and are stored lowercase.
- The whole PATCH validates before one profile save; partial invalid data changes nothing.
- No request may supply a user/profile identifier.
