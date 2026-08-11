# Data Model: Profile and Settings Foundation

## UserProfile

One current profile belongs to exactly one existing authenticated `User`.

| Field | Meaning | Validation / persistence rule |
|-------|---------|-------------------------------|
| `user_id` | Owner and identity | Required unique FK to `users.id`; cascade on account deletion |
| `timezone` | Named calendar zone | Required IANA identifier; default from `SELFHANDLER_TIMEZONE` |
| `locale` | Date/number format | Required: `en-GB`, `uk-UA`, or `ru-UA`; default `en-GB` |
| `unit_system` | Physical-value display | Required: `metric` or `imperial`; default `metric` |
| `base_currency` | Future consolidated currency | Required: `UAH`, `USD`, or `EUR`; default `UAH` |
| `recommendation_tone` | Future recommendation presentation | Required: `neutral`, `friendly`, or `direct`; default `neutral` |
| `bmr_formula` | Future metabolic formula | Required: `mifflin_st_jeor` or `katch_mcardle`; default `mifflin_st_jeor` |
| `date_of_birth` | Current age source | Nullable date; today or earlier; no more than 120 years ago |
| `sex` | Formula input | Nullable/required enum when present: `female`, `male`, `unspecified` |
| `height_meters` | Canonical current height | Nullable fixed decimal, 0.500 through 3.000, three decimal places |
| `weight_grams` | Canonical current weight | Nullable unsigned integer, 20,000 through 500,000 |
| `body_fat_percentage` | Optional current body fat | Nullable fixed decimal, 2.00 through 75.00 |
| `baseline_activity` | Non-sport activity | Nullable: `sedentary`, `light`, `moderate`, or `high` |
| `created_at` / `updated_at` | Audit timestamps | UTC framework timestamps |

### Relationships

- `User hasOne UserProfile`
- `UserProfile belongsTo User`
- `user_id` is not accepted as client ownership input; it comes only from the authenticated user.

### Invariants

1. At most one profile exists per user at the database boundary.
2. Normal registration and migration provisioning produce one profile for every user.
3. Profile and account display-name changes commit in one transaction.
4. Katch-McArdle cannot be accepted without body-fat percentage.
5. A profile may otherwise remain incomplete; incomplete values do not block current unrelated modules.
6. Physical values keep canonical meaning when `unit_system` changes.
7. Changing timezone never rewrites timestamps or explicit `Y-m-d` domain values.

## Account Identity Changes

The existing `User` retains `id`, `name`, `email`, password/session fields, and ownership relationships.
Only `name` is changed by this feature. `email`, password, identity, and authentication lifecycle are
read-only from the profile contract.

## Derived Profile State

Derived state is returned but not persisted:

| Derived field | Rule |
|---------------|------|
| `calculation_ready` | True when `missing_fields` is empty for the selected formula |
| `missing_fields` for Mifflin-St Jeor | Missing `date_of_birth`, male/female `sex`, `height_meters`, `weight_grams`, or `baseline_activity` |
| `missing_fields` for Katch-McArdle | Missing `weight_grams`, `body_fat_percentage`, or `baseline_activity` |

`sex=unspecified` is a saved user choice but counts as missing for Mifflin-St Jeor readiness.

## Unit Boundary

Persistence and HTTP contracts use canonical values:

- height: metres to three decimals;
- weight: whole grams;
- body fat: percentage to two decimals.

The UI presents:

- metric height in centimetres and weight in kilograms;
- imperial height in feet/inches and weight in pounds.

Conversion uses unrounded canonical values as input. Rounding is display-only; saving converts the
edited display value back once and never converts an already rounded display value merely because the
unit-system preference changed.

## Provisioning Transition

```text
existing user without table
  -> additive migration creates user_profiles
  -> one default row inserted for every existing users.id
  -> existing domain rows/counts/dates remain unchanged

new registration
  -> account row created
  -> default profile row created in same transaction
  -> authenticated session established
```

A missing profile discovered for an authenticated legacy/test account may be repaired idempotently
from the same defaults. A unique owner key prevents duplicate repair rows.
