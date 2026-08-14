import type {
  AnalyticsGranularity,
  AnalyticsMetricDefinition,
  AnalyticsMetricKey,
  AnalyticsPoint,
} from '../api/types'
import type { MessageKey } from '../i18n/locales/en'

export interface AnalyticsQueryState {
  metric: AnalyticsMetricKey
  from: string
  to: string
  granularity: AnalyticsGranularity
  compare: boolean
}

export interface AnalyticsUnitLabels {
  hours: string
  minutes: string
  kilograms: string
  rating: (maximum: number) => string
}

export interface ChartGeometry {
  circles: Array<{ x: number, y: number, index: number }>
  segments: Array<{ x1: number, y1: number, x2: number, y2: number }>
}

export const DEFAULT_ANALYTICS_METRIC: AnalyticsMetricKey = 'sleep.duration_minutes'

const metricLabelKeys: Record<AnalyticsMetricKey, MessageKey> = {
  'routines.completion_rate': 'analytics.metric.routines.completion_rate',
  'sleep.duration_minutes': 'analytics.metric.sleep.duration_minutes',
  'sleep.quality': 'analytics.metric.sleep.quality',
  'workouts.completed_sessions': 'analytics.metric.workouts.completed_sessions',
  'workouts.duration_minutes': 'analytics.metric.workouts.duration_minutes',
  'nutrition.calorie_target_adherence': 'analytics.metric.nutrition.calorie_target_adherence',
  'supplements.adherence': 'analytics.metric.supplements.adherence',
  'habits.completion_rate': 'analytics.metric.habits.completion_rate',
  'planner.completion_rate': 'analytics.metric.planner.completion_rate',
  'finance.income': 'analytics.metric.finance.income',
  'finance.expense': 'analytics.metric.finance.expense',
  'finance.net': 'analytics.metric.finance.net',
  'review.energy': 'analytics.metric.review.energy',
  'review.mood': 'analytics.metric.review.mood',
  'review.stress': 'analytics.metric.review.stress',
  'review.day_rating': 'analytics.metric.review.day_rating',
  'body.body_mass': 'analytics.metric.body.body_mass',
}

const correlationLabelKeys: Record<string, MessageKey> = {
  sleep_energy: 'analytics.correlation.sleep_energy',
  sleep_quality_mood: 'analytics.correlation.sleep_quality_mood',
  habit_completion_day_rating: 'analytics.correlation.habit_completion_day_rating',
}

export const pointStateLabelKeys = {
  ready: 'analytics.stateReady',
  empty: 'analytics.stateEmpty',
  incomplete: 'analytics.stateIncomplete',
} as const satisfies Record<AnalyticsPoint['state'], MessageKey>

export const directionLabelKeys = {
  positive: 'analytics.directionPositive',
  negative: 'analytics.directionNegative',
  none: 'analytics.directionNone',
} as const

export const strengthLabelKeys = {
  none: 'analytics.strengthNone',
  weak: 'analytics.strengthWeak',
  moderate: 'analytics.strengthModerate',
  strong: 'analytics.strengthStrong',
} as const

export function metricLabelKey(metric: AnalyticsMetricKey): MessageKey {
  return metricLabelKeys[metric]
}

export function correlationLabelKey(key: string): MessageKey {
  return correlationLabelKeys[key] ?? 'analytics.correlationTitle'
}

export function isCalendarDate(value: unknown): value is string {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year, month - 1, day))

  return date.getUTCFullYear() === year && date.getUTCMonth() === month - 1 && date.getUTCDate() === day
}

export function shiftCalendarDate(value: string, days: number): string {
  const [year, month, day] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year, month - 1, day + days))

  return date.toISOString().slice(0, 10)
}

export function inclusiveDays(from: string, to: string): number {
  if (!isCalendarDate(from) || !isCalendarDate(to)) return 0
  const start = Date.parse(`${from}T00:00:00Z`)
  const end = Date.parse(`${to}T00:00:00Z`)

  return Math.floor((end - start) / 86_400_000) + 1
}

export function normalizeAnalyticsQuery(
  query: Record<string, unknown>,
  metricKeys: readonly AnalyticsMetricKey[],
  limits: Record<AnalyticsGranularity, number>,
  today: string,
): AnalyticsQueryState {
  const fallback = { from: shiftCalendarDate(today, -29), to: today }
  const metric = metricKeys.includes(query.metric as AnalyticsMetricKey)
    ? query.metric as AnalyticsMetricKey
    : DEFAULT_ANALYTICS_METRIC
  const granularity = ['daily', 'weekly', 'monthly'].includes(String(query.granularity))
    ? query.granularity as AnalyticsGranularity
    : 'daily'
  const requestedFrom = isCalendarDate(query.from) ? query.from : fallback.from
  const requestedTo = isCalendarDate(query.to) ? query.to : fallback.to
  const days = inclusiveDays(requestedFrom, requestedTo)
  const validRange = days > 0 && days <= limits[granularity]

  return {
    metric,
    from: validRange ? requestedFrom : fallback.from,
    to: validRange ? requestedTo : fallback.to,
    granularity,
    compare: query.compare === undefined ? true : String(query.compare) !== '0',
  }
}

export function analyticsQueryRecord(state: AnalyticsQueryState): Record<string, string> {
  return {
    metric: state.metric,
    from: state.from,
    to: state.to,
    granularity: state.granularity,
    compare: state.compare ? '1' : '0',
  }
}

export function formatAnalyticsValue(
  value: string | null,
  definition: AnalyticsMetricDefinition,
  locale: string,
  currency: string | null,
  labels: AnalyticsUnitLabels,
): string {
  if (value === null) return '—'
  const numeric = Number(value)
  if (!Number.isFinite(numeric)) return '—'
  const number = (maximumFractionDigits = definition.precision) => new Intl.NumberFormat(locale, {
    maximumFractionDigits,
  }).format(numeric)

  switch (definition.unit) {
    case 'currency':
      return currency
        ? new Intl.NumberFormat(locale, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: definition.precision,
          }).format(numeric)
        : number()
    case 'percent':
      return `${number()}%`
    case 'minutes': {
      if (Math.abs(numeric) < 60) return `${number()} ${labels.minutes}`
      const sign = numeric < 0 ? '-' : ''
      const absolute = Math.abs(numeric)
      const hours = Math.floor(absolute / 60)
      const minutes = absolute - hours * 60
      const hourLabel = new Intl.NumberFormat(locale).format(hours)
      const minuteLabel = new Intl.NumberFormat(locale, { maximumFractionDigits: definition.precision }).format(minutes)

      return minutes === 0
        ? `${sign}${hourLabel} ${labels.hours}`
        : `${sign}${hourLabel} ${labels.hours} ${minuteLabel} ${labels.minutes}`
    }
    case 'kilograms':
      return `${number()} ${labels.kilograms}`
    case 'rating_5':
      return `${number()} ${labels.rating(5)}`
    case 'rating_10':
      return `${number()} ${labels.rating(10)}`
    case 'count':
      return number(0)
  }
}

export function buildChartGeometry(points: AnalyticsPoint[], width = 720, height = 240): ChartGeometry {
  const padding = 24
  const available = points
    .map((point, index) => ({ point, index, numeric: Number(point.value) }))
    .filter((entry) => entry.point.state === 'ready' && entry.point.value !== null && Number.isFinite(entry.numeric))
  if (available.length === 0) return { circles: [], segments: [] }

  const values = available.map((entry) => entry.numeric)
  const minimum = Math.min(...values)
  const maximum = Math.max(...values)
  const range = maximum - minimum
  const x = (index: number) => points.length === 1
    ? width / 2
    : padding + index * ((width - padding * 2) / (points.length - 1))
  const y = (value: number) => range === 0
    ? height / 2
    : padding + (maximum - value) * ((height - padding * 2) / range)
  const coordinates = new Map(available.map((entry) => [entry.index, { x: x(entry.index), y: y(entry.numeric) }]))
  const circles = available.map((entry) => ({ ...coordinates.get(entry.index)!, index: entry.index }))
  const segments: ChartGeometry['segments'] = []

  for (let index = 1; index < points.length; index += 1) {
    const previous = coordinates.get(index - 1)
    const current = coordinates.get(index)
    if (previous && current) segments.push({ x1: previous.x, y1: previous.y, x2: current.x, y2: current.y })
  }

  return { circles, segments }
}
