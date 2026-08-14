import type { Page, Route } from '@playwright/test'

export type AnalyticsFixtureMode = 'ready' | 'empty' | 'previous-zero'

const metrics = [
  ['routines.completion_rate', 'routines', 'percent', 'percentage', 2, false, 'standard'],
  ['sleep.duration_minutes', 'sleep', 'minutes', 'mean', 2, false, 'health'],
  ['sleep.quality', 'sleep', 'rating_5', 'mean', 2, false, 'health'],
  ['workouts.completed_sessions', 'workouts', 'count', 'sum', 0, true, 'health'],
  ['workouts.duration_minutes', 'workouts', 'minutes', 'sum', 2, true, 'health'],
  ['nutrition.calorie_target_adherence', 'nutrition', 'percent', 'mean', 2, false, 'health'],
  ['supplements.adherence', 'supplements', 'percent', 'percentage', 2, false, 'health'],
  ['habits.completion_rate', 'habits', 'percent', 'percentage', 2, false, 'standard'],
  ['planner.completion_rate', 'planner', 'percent', 'percentage', 2, false, 'standard'],
  ['finance.income', 'finance', 'currency', 'sum', 4, true, 'finance'],
  ['finance.expense', 'finance', 'currency', 'sum', 4, true, 'finance'],
  ['finance.net', 'finance', 'currency', 'sum', 4, true, 'finance'],
  ['review.energy', 'review', 'rating_10', 'mean', 2, false, 'well_being'],
  ['review.mood', 'review', 'rating_10', 'mean', 2, false, 'well_being'],
  ['review.stress', 'review', 'rating_10', 'mean', 2, false, 'well_being'],
  ['review.day_rating', 'review', 'rating_10', 'mean', 2, false, 'well_being'],
  ['body.body_mass', 'body', 'kilograms', 'last', 4, false, 'health'],
] as const

export const catalogFixture = {
  metrics: metrics.map(([key, module, unit, operator, precision, empty_is_zero, sensitivity]) => ({
    key, module, unit, operator, precision, empty_is_zero, sensitivity,
  })),
  correlations: [
    { key: 'sleep_energy', left_metric: 'sleep.duration_minutes', right_metric: 'review.energy', minimum_samples: 7 },
    { key: 'sleep_quality_mood', left_metric: 'sleep.quality', right_metric: 'review.mood', minimum_samples: 7 },
    { key: 'habit_completion_day_rating', left_metric: 'habits.completion_rate', right_metric: 'review.day_rating', minimum_samples: 7 },
  ],
  limits: { daily_days: 93, weekly_days: 730, monthly_days: 3653, correlation_days: 366 },
}

function metricDefinition(key: string) {
  return catalogFixture.metrics.find((metric) => metric.key === key) ?? catalogFixture.metrics[1]
}

function metricValues(key: string): string[] {
  if (key.startsWith('finance.')) return ['120.0000', '85.0000', '140.0000', '100.0000', '160.0000']
  if (key === 'sleep.duration_minutes') return ['420.00', '450.00', '465.00', '480.00', '510.00']
  if (key === 'body.body_mass') return ['81.5000', '81.2000', '80.9000', '80.7000', '80.5000']
  if (key.startsWith('review.')) return ['4.00', '5.00', '7.00', '8.00', '10.00']
  return ['40.00', '50.00', '70.00', '80.00', '90.00']
}

export function workspaceFixture(url: URL, mode: AnalyticsFixtureMode = 'ready', corrected = false) {
  const key = url.searchParams.get('metric') ?? 'sleep.duration_minutes'
  const definition = metricDefinition(key)
  const from = url.searchParams.get('from') ?? '2026-08-01'
  const to = url.searchParams.get('to') ?? '2026-08-07'
  const granularity = url.searchParams.get('granularity') ?? 'daily'
  const compare = url.searchParams.get('compare') !== '0'
  const values = metricValues(key)
  if (corrected) values[values.length - 1] = key.startsWith('review.') ? '9.00' : '175.0000'
  const dates = ['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07']
  const points = dates.map((date, index) => {
    if (mode === 'empty') {
      return { bucket_start: date, bucket_end: date, state: 'empty', value: null, sample_count: 0, numerator: null, denominator: null, reasons: ['missing_evidence'] }
    }
    if (index === 2) {
      return { bucket_start: date, bucket_end: date, state: 'empty', value: null, sample_count: 0, numerator: null, denominator: null, reasons: ['missing_evidence'] }
    }
    if (index === 5 && key.startsWith('finance.')) {
      return { bucket_start: date, bucket_end: date, state: 'incomplete', value: null, sample_count: 2, numerator: null, denominator: null, reasons: ['missing_fx:USD'] }
    }
    const value = values[index > 2 ? index - 2 : index]
    return { bucket_start: date, bucket_end: date, state: 'ready', value, sample_count: 1, numerator: value, denominator: '1', reasons: [] }
  })
  const ready = points.filter((point) => point.state === 'ready')
  const first = ready[0]?.value ?? null
  const last = ready.at(-1)?.value ?? null
  const precision = definition.precision
  const decimal = (value: number) => value.toFixed(precision)
  const delta = first !== null && last !== null ? decimal(Number(last) - Number(first)) : null
  const aggregate = (periodFrom: string, periodTo: string, value: string | null) => ({
    from: periodFrom, to: periodTo, state: value === null ? 'empty' : 'ready', value,
    sample_count: value === null ? 0 : 5, numerator: value, denominator: value === null ? null : '1',
    reasons: value === null ? ['missing_evidence'] : [],
  })
  const current = mode === 'empty' ? null : decimal(ready.reduce((sum, point) => sum + Number(point.value), 0) / ready.length)
  const previous = mode === 'empty' ? null : mode === 'previous-zero' ? decimal(0) : decimal(Number(current) - 2)

  return {
    period: { from, to, granularity, timezone: 'UTC' },
    metric: definition,
    currency: definition.unit === 'currency' ? 'UAH' : null,
    points,
    trend: {
      state: mode === 'empty' ? 'empty' : 'ready', available_points: ready.length, total_buckets: points.length,
      first, last, delta, slope_per_bucket: mode === 'empty' ? null : decimal(1.25),
    },
    comparison: compare ? {
      current: aggregate(from, to, current),
      previous: aggregate('2026-07-25', '2026-07-31', previous),
      absolute_delta: current === null || previous === null ? null : decimal(Number(current) - Number(previous)),
      percentage_delta: current === null || previous === null || Number(previous) === 0
        ? null
        : decimal((Number(current) - Number(previous)) / Number(previous) * 100),
      percentage_delta_reason: current === null || previous === null
        ? 'missing_value'
        : Number(previous) === 0 ? 'previous_zero' : 'available',
    } : null,
  }
}

export function correlationsFixture() {
  return {
    period: { from: '2026-08-01', to: '2026-08-07', timezone: 'UTC' },
    findings: [
      {
        key: 'sleep_energy', left_metric: 'sleep.duration_minutes', right_metric: 'review.energy',
        from: '2026-08-01', to: '2026-08-07', state: 'ready', coefficient: '0.8123',
        direction: 'positive', strength: 'strong', sample_count: 7, minimum_samples: 7, reason: null,
      },
      {
        key: 'sleep_quality_mood', left_metric: 'sleep.quality', right_metric: 'review.mood',
        from: '2026-08-01', to: '2026-08-07', state: 'unavailable', coefficient: null,
        direction: null, strength: null, sample_count: 4, minimum_samples: 7, reason: 'insufficient_samples',
      },
      {
        key: 'habit_completion_day_rating', left_metric: 'habits.completion_rate', right_metric: 'review.day_rating',
        from: '2026-08-01', to: '2026-08-07', state: 'unavailable', coefficient: null,
        direction: null, strength: null, sample_count: 7, minimum_samples: 7, reason: 'zero_variance',
      },
    ],
  }
}

export interface AnalyticsRouteState {
  mode: AnalyticsFixtureMode
  corrected: boolean
  failWorkspace: boolean
  failCorrelations: boolean
  captured: unknown[]
}

export async function mockAnalytics(page: Page, state: AnalyticsRouteState): Promise<void> {
  await page.route('**/api/analytics/**', async (route: Route) => {
    const url = new URL(route.request().url())
    if (url.pathname.endsWith('/catalog')) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: catalogFixture }) })
      return
    }
    if (url.pathname.endsWith('/workspace')) {
      if (state.failWorkspace) {
        state.failWorkspace = false
        await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable.' }) })
        return
      }
      const data = workspaceFixture(url, state.mode, state.corrected)
      state.captured.push(data)
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) })
      return
    }
    if (state.failCorrelations) {
      state.failCorrelations = false
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable.' }) })
      return
    }
    const data = correlationsFixture()
    state.captured.push(data)
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data }) })
  })
}
