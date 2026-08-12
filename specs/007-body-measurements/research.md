# Research: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements` · **Date**: 2026-08-12

## R1 — Profile baseline versus measurement history

**Question**: The Profile already stores weight, height and body fat. Is the measurement log a second
copy?

**Findings**: `docs/design/modules.md` treats them as two different things: the Profile holds the
*inputs* other modules calculate from ("Anthropometrics (baseline)"), while the measurement log is "a
profile snapshot with history … a log with history, not a single value". Feature 004 deliberately
recorded "Profile stores current inputs, not measurement history" as its limitation.

**Decision**: two separate facts, no automatic synchronisation in either direction. The Profile is not
turned into a journal, and a measurement never silently rewrites the baseline the user typed into their
settings. A future feature may offer "copy today's weight into my profile" as an explicit action; this
one does not, because an implicit rule would make it impossible to tell which value the user actually
meant.

## R2 — Extensible metrics without JSON and without a table per number

**Question**: `modules.md` calls the metric list extensible ("weight, arms, legs, waist, chest,
glutes/hips, etc."). How is that stored?

**Options**

| Option | Verdict |
|---|---|
| A column per metric on one wide row | Rejected: every new metric is a migration, and most columns are null. |
| A JSON blob per date | Rejected: `data-conventions.md` §2 — "If a field needs to be filtered/aggregated/validated, it's a column or a detail table, not JSON". Trends filter and aggregate. |
| A table per metric | Rejected: identical structure repeated, and every query becomes a union. |
| **One row per observation, with a validated `metric` column** | **Adopted.** |

**Decision**: `body_measurements(user_id, metric, measured_on, value)`. The `metric` column is backed by
a PHP enum, `App\ValueObjects\BodyMetric`, which carries the canonical unit, the display units, the
decimal precision, the plausible bounds and whether a safe-pace boundary exists. Adding a metric is one
enum case — no migration, no schema drift — while the value stays indexed, filterable and type-checked
at the request boundary.

This is exactly the "single-table + type" branch of `data-conventions.md` §2: the observations are
structurally identical and differ only by which quantity they name.

## R3 — Canonical units and precision

`data-conventions.md` §6 fixes the base units: mass in grams, distance in metres, and the display unit
is a presentation concern.

**Decision**: `value` is `DECIMAL(12,4)` in the metric's base unit — grams for mass, metres for lengths,
percent for ratios. Decimal rather than float for the same reason money is decimal: repeated conversion
through binary floating point loses the value the user typed. A round trip through kilograms or pounds
therefore returns exactly the stored quantity, which SC-002 asserts.

## R4 — Body goal as a typed detail, not a second goal system

`modules.md` Module 4 is explicit: "a general goal mechanism with types — a single Goal entity for the
whole app, the type defining the specifics", and for the body type "progress is measured by the
measurement log".

**Decision**: `goals.type = 'body'` plus a one-to-one `body_goal_details` row. Class-table rather than
nullable columns on `goals`, because `data-conventions.md` §2 sends divergent types to a detail table
and the later training and finance types will diverge much further. Name, description, status,
lifecycle, target date, archive and ownership all stay on the goal and keep behaving exactly as they do
today.

Milestones become `goal_milestones`, a table on the goal rather than on the body detail, because
`modules.md` states milestones are "a general mechanism for goals of any type". It is introduced here
because this feature is its first real consumer.

## R5 — Trend method

**Question**: What is "the trend", precisely enough that two runs cannot disagree?

**Findings**: Entries are manual and sparse — a few per month, at irregular intervals. A trailing
n-day moving average is undefined for most days in such a series, and interpolating to fill it would
invent observations the user never made.

**Decision**: ordinary least squares over the observations in the requested window, with the calendar
date converted to days-since-first-observation as the independent variable, reported as change per week.
It is defined for any two or more distinct dates, is invariant to insertion order, needs no smoothing
parameter, and can be checked by hand. Fewer than two points returns an explicit insufficient-data
state rather than a zero slope, because "not enough information" and "no change" are different answers.
Rounding is applied once, to four decimals in canonical units, at the boundary.

Duplicate dates cannot occur: `(user, metric, date)` is unique, so the independent variable is strictly
increasing.

## R6 — Safe pace boundaries

The task is explicit that medical numbers must not be recalled from memory, and `docs/design/` does not
specify a rate.

**Weight loss** — the U.S. Centers for Disease Control and Prevention publishes: "People who lose weight
at a gradual, steady pace — about 1 to 2 pounds a week — are more likely to keep the weight off than
people who lose weight quicker."
Source: [Steps for Losing Weight, CDC](https://www.cdc.gov/healthy-weight-growth/losing-weight/index.html).

**Decision**: the loss boundary is 2 lb per week, stored as its exact metric equivalent
907.1847 g/week. A goal implying a faster loss produces a warning that names the source.

**Weight gain** — no comparable authority publishes a general weekly rate. Rather than invent one, the
application applies an explicit **product limitation** of 500 g/week and says so in the message: it is
presented as this application's own conservative boundary, not as guidance. This satisfies "state a safe
product limitation" without dressing a guess up as medicine.

**Other metrics** — girths and body-fat percentage have no boundary here, and none is invented. FR-024
forbids inventing one; a metric without a documented boundary simply produces no warning.

**Behaviour**: a warning, never a block. It is returned alongside the successfully saved goal, the
target and date are stored exactly as entered, and unrelated fields remain editable. The product does
not diagnose, does not recommend, and does not quietly move the user's target.

## R7 — Query bounds

History is unbounded over time. Every read takes an explicit range or a limit, defaulting to the last
365 days, and the trend is computed over the same bounded set. Indexed by `(user_id, metric,
measured_on)`, which also serves the uniqueness constraint and the ordering.

## Constitution Check

| Principle | Assessment |
|---|---|
| I | Full contract before implementation. |
| II | Follows `modules.md` on both the measurement log and the typed goal; no design document contradicted. |
| III | Milestones and the metric vocabulary both have a consumer in this feature. Attachments, reminders and analytics are deferred to the features that own them. |
| IV | Trend, progress and pace are deterministic arithmetic. No AI, no interpretation. |
| V | `user_id` on all three new tables, ownership on every query, health data confined to the owner. |
| VI | Migration, unit, API, contract and browser coverage change with the code. |
