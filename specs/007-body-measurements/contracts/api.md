# Contracts: Body Measurements and Body Goals

**Feature ID**: `007-body-measurements`

> The machine-readable contract is [openapi.yaml](openapi.yaml) (OpenAPI 3.1, 5 paths, 18 schemas).
> This document is the prose companion: it explains the reasoning the schema cannot carry. Both are
> held against the implementation by `apps/api/tests/Feature/Body/BodyOpenApiContractTest.php`, which
> fails when a documented operation is not a route, a body route is undocumented, or a documented
> vocabulary drifts from its enum.

All routes are behind `auth:sanctum` and scoped to the authenticated user. Every value in a request or
response body is in the metric's **canonical base unit**; unit conversion is a client presentation
concern, exactly as it already is for the Profile.

## `GET /api/body/measurements`

Query: `metric` (optional), `from` / `to` (`YYYY-MM-DD`, optional, default last 365 days),
`limit` (optional, max 1000).

```json
{
  "data": [
    { "id": 1, "metric": "body_mass", "measured_on": "2026-08-01", "value": "82500.0000", "note": null }
  ],
  "metrics": [
    {
      "value": "body_mass",
      "label": "Body mass",
      "canonical_unit": "gram",
      "display_unit": { "metric": "kg", "imperial": "lb" },
      "minimum": "20000.0000",
      "maximum": "500000.0000"
    }
  ],
  "today": "2026-08-12"
}
```

`today` is the user's current day in their profile time zone, so the client never derives it from the
browser.

## `PUT /api/body/measurements`

```json
{ "metric": "body_mass", "measured_on": "2026-08-12", "value": 82500, "note": null }
```

Creates or corrects the single observation for that user, metric and date. `200` with the stored row.
`422` with field errors for an unknown metric, a malformed date or a value outside the metric's bounds;
nothing is written on rejection.

## `DELETE /api/body/measurements/{measurement}`

`204`. `404` for another account's row.

## `GET /api/body/trend`

Query: `metric` (required), `from` / `to` (optional).

```json
{
  "metric": "body_mass",
  "state": "ready",
  "points": 6,
  "first": { "measured_on": "2026-06-01", "value": "84000.0000" },
  "last":  { "measured_on": "2026-08-12", "value": "82500.0000" },
  "change_per_week": "-146.3415"
}
```

`state` is `empty` (no observations), `insufficient` (exactly one) or `ready`. In the first two states
`change_per_week` is `null` — never `0`.

## `GET /api/body/goals`, `POST /api/body/goals`, `PATCH /api/body/goals/{goal}`

```json
{
  "name": "Reach 78 kg",
  "description": null,
  "target_date": "2026-12-01",
  "metric": "body_mass",
  "direction": "lose",
  "starting_value": 82500,
  "target_value": 78000,
  "milestones": [{ "target_value": 81000 }, { "target_value": 79500 }]
}
```

Creates the `Goal` (`type = "body"`) and its detail in one transaction, and returns:

```json
{
  "data": {
    "id": 7, "name": "Reach 78 kg", "type": "body", "status": "active",
    "target_date": "2026-12-01", "is_archived": false,
    "body": {
      "metric": "body_mass", "direction": "lose",
      "starting_value": "82500.0000", "target_value": "78000.0000",
      "current_value": "82500.0000", "progress": 0,
      "milestones": [{ "id": 3, "target_value": "81000.0000", "target_date": null, "achieved": false }]
    }
  },
  "warnings": [
    {
      "field": "target_date",
      "code": "pace_above_guidance",
      "message": "That target needs about 1.4 kg a week. The CDC describes 1 to 2 pounds a week as a gradual, steady pace. The goal was saved exactly as you entered it."
    }
  ]
}
```

`warnings` is always present and is `[]` when there is nothing to say. A warning never changes a stored
value and never turns the response into an error.

`current_value` and `progress` are `null` when the metric has no observation yet — never `0`.

There is no delete endpoint. A body goal is archived the same way any goal is, by sending
`is_archived: true` to `PATCH`, so the row and its history survive.

## Existing contracts

`GET /api/goals` continues to work unchanged. A body goal appears in that list with its existing goal
fields; the body detail is served only by the body endpoints, so no existing consumer changes.

## Internal contracts

```php
BodyTrendService::for(User $user, BodyMetric $metric, string $from, string $to): array;
BodyGoalProgressService::describe(Goal $goal): array;
SafePaceValidator::warningsFor(
    BodyMetric $metric,
    string $direction,
    string $startingValue,
    string $targetValue,
    ?string $targetDate,
    string $today,
): array;
```

All three read and compute; none mutates.

## Frontend contract

`apps/web/src/api/types.ts` gains `BodyMetricOption`, `BodyMeasurement`, `BodyTrend`, `BodyGoal` and
`BodyGoalPayload`. Existing types are unchanged.
