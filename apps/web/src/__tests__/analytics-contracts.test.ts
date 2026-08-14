import { describe, expect, it } from 'vitest'
import type { AnalyticsMetricDefinition, AnalyticsPoint } from '../api/types'
import {
  analyticsQueryRecord,
  buildChartGeometry,
  formatAnalyticsValue,
  inclusiveDays,
  metricLabelKey,
  normalizeAnalyticsQuery,
  shiftCalendarDate,
} from '../analytics/presentation'

const metrics = [
  'routines.completion_rate',
  'sleep.duration_minutes',
  'sleep.quality',
  'workouts.completed_sessions',
  'workouts.duration_minutes',
  'nutrition.calorie_target_adherence',
  'supplements.adherence',
  'habits.completion_rate',
  'planner.completion_rate',
  'finance.income',
  'finance.expense',
  'finance.net',
  'review.energy',
  'review.mood',
  'review.stress',
  'review.day_rating',
  'body.body_mass',
] as const

const labels = {
  hours: 'h',
  minutes: 'min',
  kilograms: 'kg',
  rating: (maximum: number) => `out of ${maximum}`,
}

function definition(unit: AnalyticsMetricDefinition['unit'], precision: 0 | 2 | 4 = 2): AnalyticsMetricDefinition {
  return {
    key: 'sleep.duration_minutes',
    module: 'sleep',
    unit,
    operator: 'mean',
    precision,
    empty_is_zero: false,
    sensitivity: 'health',
  }
}

function point(value: string | null, state: AnalyticsPoint['state'] = 'ready'): AnalyticsPoint {
  return {
    bucket_start: '2026-08-01',
    bucket_end: '2026-08-01',
    state,
    value,
    sample_count: value === null ? 0 : 1,
    numerator: value,
    denominator: value === null ? null : '1',
    reasons: value === null ? ['missing_evidence'] : [],
  }
}

describe('Analytics presentation contract', () => {
  it('keeps the closed 17-metric order and label mapping', () => {
    expect(metrics).toHaveLength(17)
    expect(new Set(metrics)).toHaveLength(17)
    expect(metrics.map(metricLabelKey)).toEqual(metrics.map((metric) => `analytics.metric.${metric}`))
  })

  it('normalizes missing and hostile URL state to the documented safe defaults', () => {
    const limits = { daily: 93, weekly: 730, monthly: 3653 }
    const normalized = normalizeAnalyticsQuery({ metric: 'unknown', from: '../../etc', compare: '0' }, metrics, limits, '2026-08-14')

    expect(normalized).toEqual({
      metric: 'sleep.duration_minutes',
      from: '2026-07-16',
      to: '2026-08-14',
      granularity: 'daily',
      compare: false,
    })
    expect(analyticsQueryRecord(normalized)).toEqual({
      metric: 'sleep.duration_minutes', from: '2026-07-16', to: '2026-08-14', granularity: 'daily', compare: '0',
    })
  })

  it('uses inclusive UTC-safe calendar math and resets overlong ranges', () => {
    expect(inclusiveDays('2024-02-28', '2024-03-01')).toBe(3)
    expect(shiftCalendarDate('2024-03-01', -2)).toBe('2024-02-28')
    expect(normalizeAnalyticsQuery(
      { from: '2026-01-01', to: '2026-08-14', granularity: 'daily' },
      metrics,
      { daily: 93, weekly: 730, monthly: 3653 },
      '2026-08-14',
    ).from).toBe('2026-07-16')
  })

  it('formats every metric unit without converting decimal strings in the transport contract', () => {
    expect(formatAnalyticsValue('450', definition('minutes'), 'en-GB', null, labels)).toBe('7 h 30 min')
    expect(formatAnalyticsValue('87.25', definition('percent'), 'en-GB', null, labels)).toBe('87.25%')
    expect(formatAnalyticsValue('4.5', definition('rating_5'), 'en-GB', null, labels)).toBe('4.5 out of 5')
    expect(formatAnalyticsValue('8.5', definition('rating_10'), 'en-GB', null, labels)).toBe('8.5 out of 10')
    expect(formatAnalyticsValue('81.1234', definition('kilograms', 4), 'en-GB', null, labels)).toBe('81.1234 kg')
    expect(formatAnalyticsValue('7', definition('count', 0), 'en-GB', null, labels)).toBe('7')
    expect(formatAnalyticsValue('1234.5678', definition('currency', 4), 'en-GB', 'GBP', labels)).toBe('£1,234.5678')
    expect(formatAnalyticsValue(null, definition('percent'), 'en-GB', null, labels)).toBe('—')
  })

  it('draws only adjacent ready values and never bridges missing intervals', () => {
    const points = [point('1'), point(null, 'empty'), point('3'), point('4'), point(null, 'incomplete')]
    const geometry = buildChartGeometry(points)

    expect(geometry.circles.map((circle) => circle.index)).toEqual([0, 2, 3])
    expect(geometry.segments).toHaveLength(1)
  })
})
