<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  ApiError,
  getAnalyticsCatalog,
  getAnalyticsCorrelations,
  getAnalyticsWorkspace,
  getToday,
} from '../api/client'
import type {
  AnalyticsCatalog,
  AnalyticsCorrelationWorkspace,
  AnalyticsGranularity,
  AnalyticsMetricDefinition,
  AnalyticsWorkspace,
} from '../api/types'
import {
  analyticsQueryRecord,
  formatAnalyticsValue,
  inclusiveDays,
  metricLabelKey,
  normalizeAnalyticsQuery,
  type AnalyticsQueryState,
} from '../analytics/presentation'
import AsyncState from '../components/AsyncState.vue'
import CorrelationCard from '../components/analytics/CorrelationCard.vue'
import MetricTrendChart from '../components/analytics/MetricTrendChart.vue'
import MetricTrendTable from '../components/analytics/MetricTrendTable.vue'
import { UiCheckbox, UiDatePicker, UiSelect } from '../components/ui'
import { useI18n } from '../i18n'
import { formatCalendarDate } from '../lib/format'

const route = useRoute()
const router = useRouter()
const i18n = useI18n()
const catalog = ref<AnalyticsCatalog | null>(null)
const today = ref('')
const workspace = ref<AnalyticsWorkspace | null>(null)
const correlations = ref<AnalyticsCorrelationWorkspace | null>(null)
const isLoading = ref(true)
const isCorrelationLoading = ref(false)
const loadError = ref<string | null>(null)
const correlationError = ref<string | null>(null)
const formError = ref<string | null>(null)
const form = reactive<AnalyticsQueryState>({
  metric: 'sleep.duration_minutes',
  from: '',
  to: '',
  granularity: 'daily',
  compare: true,
})
let loadSequence = 0
let bootstrapPromise: Promise<void> | null = null

const metricOptions = computed(() => (catalog.value?.metrics ?? []).map((definition) => ({
  value: definition.key,
  label: i18n.t(metricLabelKey(definition.key)),
})))
const granularityOptions = computed(() => [
  { value: 'daily' as const, label: i18n.t('analytics.daily') },
  { value: 'weekly' as const, label: i18n.t('analytics.weekly') },
  { value: 'monthly' as const, label: i18n.t('analytics.monthly') },
])
const selectedDefinition = computed(() => workspace.value?.metric
  ?? catalog.value?.metrics.find((definition) => definition.key === form.metric)
  ?? null)
const metricLabel = computed(() => selectedDefinition.value
  ? i18n.t(metricLabelKey(selectedDefinition.value.key))
  : '')
const appliedDays = computed(() => workspace.value
  ? inclusiveDays(workspace.value.period.from, workspace.value.period.to)
  : 0)
const correlationRangeAllowed = computed(() => Boolean(
  catalog.value && appliedDays.value > 0 && appliedDays.value <= catalog.value.limits.correlation_days,
))
const availablePointCount = computed(() => workspace.value?.trend.available_points ?? 0)

function rangeLimits(): Record<AnalyticsGranularity, number> {
  return {
    daily: catalog.value?.limits.daily_days ?? 93,
    weekly: catalog.value?.limits.weekly_days ?? 730,
    monthly: catalog.value?.limits.monthly_days ?? 3653,
  }
}

async function bootstrap(): Promise<void> {
  if (catalog.value && today.value) return
  if (!bootstrapPromise) {
    bootstrapPromise = Promise.all([getAnalyticsCatalog(), getToday()]).then(([catalogData, todayData]) => {
      catalog.value = catalogData
      today.value = todayData.date
    }).catch((error) => {
      bootstrapPromise = null
      throw error
    })
  }

  await bootstrapPromise
}

function routeQuery(): Record<string, unknown> {
  return {
    metric: route.query.metric,
    from: route.query.from,
    to: route.query.to,
    granularity: route.query.granularity,
    compare: route.query.compare,
  }
}

function isCanonical(query: Record<string, string>): boolean {
  const routeKeys = Object.keys(route.query)
  return routeKeys.length === Object.keys(query).length
    && Object.entries(query).every(([key, value]) => route.query[key] === value)
}

async function loadFromRoute(): Promise<void> {
  const sequence = ++loadSequence
  isLoading.value = true
  loadError.value = null
  correlationError.value = null
  formError.value = null
  workspace.value = null
  correlations.value = null

  try {
    await bootstrap()
    if (sequence !== loadSequence || !catalog.value) return
    const normalized = normalizeAnalyticsQuery(
      routeQuery(),
      catalog.value.metrics.map((definition) => definition.key),
      rangeLimits(),
      today.value,
    )
    Object.assign(form, normalized)
    const canonical = analyticsQueryRecord(normalized)
    if (!isCanonical(canonical)) {
      await router.replace({ name: 'analytics', query: canonical })
      return
    }

    const workspacePromise = getAnalyticsWorkspace(normalized)
    const canLoadCorrelations = inclusiveDays(normalized.from, normalized.to) <= catalog.value.limits.correlation_days
    const correlationPromise = canLoadCorrelations
      ? getAnalyticsCorrelations({ from: normalized.from, to: normalized.to })
      : Promise.resolve(null)
    const [workspaceResult, correlationResult] = await Promise.allSettled([workspacePromise, correlationPromise])
    if (sequence !== loadSequence) return

    if (workspaceResult.status === 'rejected') throw workspaceResult.reason
    workspace.value = workspaceResult.value
    if (correlationResult.status === 'fulfilled') {
      correlations.value = correlationResult.value
    } else {
      correlationError.value = i18n.t('analytics.correlationLoadFailed')
    }
  } catch (error) {
    if (sequence === loadSequence) {
      loadError.value = error instanceof ApiError && error.status === 422
        ? i18n.t('analytics.loadFailed')
        : i18n.t('analytics.loadFailed')
    }
  } finally {
    if (sequence === loadSequence) isLoading.value = false
  }
}

async function reloadCorrelations(): Promise<void> {
  if (!workspace.value || !correlationRangeAllowed.value || isCorrelationLoading.value) return
  isCorrelationLoading.value = true
  correlationError.value = null
  correlations.value = null
  try {
    correlations.value = await getAnalyticsCorrelations({
      from: workspace.value.period.from,
      to: workspace.value.period.to,
    })
  } catch {
    correlationError.value = i18n.t('analytics.correlationLoadFailed')
  } finally {
    isCorrelationLoading.value = false
  }
}

function applyFilters(): void {
  const days = inclusiveDays(form.from, form.to)
  if (days <= 0) {
    formError.value = i18n.t('analytics.invalidRange')
    return
  }
  if (days > rangeLimits()[form.granularity]) {
    formError.value = i18n.t('analytics.rangeTooLong')
    return
  }

  formError.value = null
  void router.push({ name: 'analytics', query: analyticsQueryRecord(form) })
}

function unitLabels() {
  return {
    hours: i18n.t('analytics.hoursShort'),
    minutes: i18n.t('analytics.minutesShort'),
    kilograms: i18n.t('analytics.kilogramsShort'),
    rating: (maximum: number) => i18n.t('analytics.ratingOutOf', { maximum }),
  }
}

function valueLabel(value: string | null, definition: AnalyticsMetricDefinition | null = selectedDefinition.value): string {
  if (!definition) return '—'
  return formatAnalyticsValue(value, definition, i18n.locale.value, workspace.value?.currency ?? null, unitLabels())
}

function slopeLabel(value: string | null): string {
  if (value === null) return '—'
  const formatted = valueLabel(value)
  return i18n.t('analytics.slopeUnit', {
    value: formatted,
    interval: i18n.t(`analytics.${form.granularity}`),
  })
}

function reasonLabel(reason: string): string {
  if (reason.startsWith('missing_fx:')) {
    return i18n.t('analytics.missingFx', { currency: reason.slice('missing_fx:'.length) })
  }

  return i18n.t('analytics.missingEvidence')
}

function aggregateRange(from: string, to: string): string {
  return `${formatCalendarDate(from, i18n.locale.value)} – ${formatCalendarDate(to, i18n.locale.value)}`
}

function comparisonReason(): string | null {
  const comparison = workspace.value?.comparison
  if (!comparison || comparison.percentage_delta_reason === 'available') return null
  return comparison.percentage_delta_reason === 'previous_zero'
    ? i18n.t('analytics.previousZero')
    : i18n.t('analytics.comparisonUnavailable')
}

watch(() => route.fullPath, () => { void loadFromRoute() }, { immediate: true })
</script>

<template>
  <section class="view-stack analytics-page">
    <header class="view-header analytics-header">
      <div>
        <p class="eyebrow">{{ i18n.t('analytics.eyebrow') }}</p>
        <h1>{{ i18n.t('analytics.title') }}</h1>
        <p class="muted analytics-subtitle">{{ i18n.t('analytics.subtitle') }}</p>
      </div>
    </header>

    <form class="panel analytics-filters" novalidate @submit.prevent="applyFilters">
      <UiSelect
        v-model="form.metric"
        name="analytics-metric"
        :label="i18n.t('analytics.metric')"
        :options="metricOptions"
        required
      />
      <UiDatePicker
        v-model="form.from"
        name="analytics-from"
        :label="i18n.t('analytics.from')"
        :locale="i18n.locale.value"
        :today="today || null"
        :clearable="false"
        required
      />
      <UiDatePicker
        v-model="form.to"
        name="analytics-to"
        :label="i18n.t('analytics.to')"
        :locale="i18n.locale.value"
        :today="today || null"
        :clearable="false"
        required
      />
      <UiSelect
        v-model="form.granularity"
        name="analytics-granularity"
        :label="i18n.t('analytics.granularity')"
        :options="granularityOptions"
        required
      />
      <UiCheckbox
        v-model="form.compare"
        name="analytics-compare"
        :label="i18n.t('analytics.compare')"
      />
      <button type="submit">{{ i18n.t('analytics.apply') }}</button>
      <p v-if="formError" class="notice error analytics-filter-error" role="alert">{{ formError }}</p>
    </form>

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      :loading-title="i18n.t('analytics.loading')"
      :loading-description="i18n.t('analytics.loadingBody')"
      panel
      @retry="loadFromRoute"
    >
      <template v-if="workspace">
        <section class="panel">
          <div>
            <p class="eyebrow">{{ i18n.t('analytics.trendEyebrow') }}</p>
            <h2>{{ i18n.t('analytics.trendTitle') }}</h2>
          </div>
          <div class="analytics-summary-grid">
            <div class="metric"><span>{{ i18n.t('analytics.availablePoints') }}</span><strong>{{ workspace.trend.available_points }}</strong></div>
            <div class="metric"><span>{{ i18n.t('analytics.totalBuckets') }}</span><strong>{{ workspace.trend.total_buckets }}</strong></div>
            <div class="metric"><span>{{ i18n.t('analytics.first') }}</span><strong>{{ valueLabel(workspace.trend.first) }}</strong></div>
            <div class="metric"><span>{{ i18n.t('analytics.last') }}</span><strong>{{ valueLabel(workspace.trend.last) }}</strong></div>
            <div class="metric"><span>{{ i18n.t('analytics.delta') }}</span><strong>{{ valueLabel(workspace.trend.delta) }}</strong></div>
            <div class="metric"><span>{{ i18n.t('analytics.slope') }}</span><strong>{{ slopeLabel(workspace.trend.slope_per_bucket) }}</strong></div>
          </div>
          <p v-if="workspace.trend.state === 'empty'" class="notice">{{ i18n.t('analytics.trendEmpty') }}</p>
          <p v-else-if="workspace.trend.state === 'insufficient'" class="notice">{{ i18n.t('analytics.trendInsufficient') }}</p>
        </section>

        <section v-if="workspace.comparison" class="panel">
          <div>
            <p class="eyebrow">{{ i18n.t('analytics.comparisonEyebrow') }}</p>
            <h2>{{ i18n.t('analytics.comparisonTitle') }}</h2>
          </div>
          <div class="analytics-comparison-grid">
            <article class="metric">
              <span>{{ i18n.t('analytics.currentPeriod') }}</span>
              <small>{{ aggregateRange(workspace.comparison.current.from, workspace.comparison.current.to) }}</small>
              <strong>{{ valueLabel(workspace.comparison.current.value) }}</strong>
            </article>
            <article class="metric">
              <span>{{ i18n.t('analytics.previousPeriod') }}</span>
              <small>{{ aggregateRange(workspace.comparison.previous.from, workspace.comparison.previous.to) }}</small>
              <strong>{{ valueLabel(workspace.comparison.previous.value) }}</strong>
            </article>
            <article class="metric">
              <span>{{ i18n.t('analytics.percentageChange') }}</span>
              <strong>{{ workspace.comparison.percentage_delta === null ? '—' : `${i18n.number(Number(workspace.comparison.percentage_delta), { maximumFractionDigits: 2 })}%` }}</strong>
            </article>
          </div>
          <p v-if="comparisonReason()" class="notice">{{ comparisonReason() }}</p>
        </section>

        <section class="panel">
          <div>
            <p class="eyebrow">{{ i18n.t('analytics.chartEyebrow') }}</p>
            <h2>{{ i18n.t('analytics.chartTitle', { metric: metricLabel }) }}</h2>
          </div>
          <MetricTrendChart
            v-if="availablePointCount > 0"
            :points="workspace.points"
            :title="i18n.t('analytics.chartTitle', { metric: metricLabel })"
            :description="i18n.t('analytics.chartDescription', { metric: metricLabel })"
            :value-label="valueLabel"
          />
          <p v-else class="notice">{{ i18n.t('analytics.trendEmpty') }}</p>
          <MetricTrendTable :points="workspace.points" :value-label="valueLabel" :reason-label="reasonLabel" />
        </section>

        <section class="panel" aria-labelledby="analytics-correlations-heading">
          <div>
            <p class="eyebrow">{{ i18n.t('analytics.correlationEyebrow') }}</p>
            <h2 id="analytics-correlations-heading">{{ i18n.t('analytics.correlationTitle') }}</h2>
            <p class="muted">{{ i18n.t('analytics.correlationBody') }}</p>
          </div>
          <AsyncState
            :loading="isCorrelationLoading"
            :error="correlationError"
            :loading-title="i18n.t('analytics.correlationLoading')"
            @retry="reloadCorrelations"
          >
            <div v-if="correlations" class="analytics-correlations-grid">
              <CorrelationCard v-for="finding in correlations.findings" :key="finding.key" :finding="finding" />
            </div>
            <p v-else-if="!correlationRangeAllowed" class="notice">
              {{ i18n.t('analytics.correlationRangeTooLong', { days: catalog?.limits.correlation_days ?? 366 }) }}
            </p>
          </AsyncState>
          <p class="analytics-disclaimer">{{ i18n.t('analytics.correlationDisclaimer') }}</p>
        </section>
      </template>
    </AsyncState>
  </section>
</template>
