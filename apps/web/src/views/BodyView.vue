<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import {
  createBodyGoal,
  deleteBodyMeasurement,
  getBodyGoals,
  getBodyMeasurements,
  getBodyTrend,
  saveBodyMeasurement,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import {
  UiDatePicker,
  UiNumberInput,
  UiSegmented,
  UiSelect,
  UiTextInput,
} from '../components/ui'
import type { UiOption } from '../components/ui'
import { useAuthSession } from '../auth/session'
import { formatCalendarDate } from '../lib/format'
import { displayUnit, toCanonical, toDisplay } from '../lib/bodyUnits'
import type {
  BodyGoal,
  BodyGoalDirection,
  BodyGoalWarning,
  BodyMeasurement,
  BodyMetricKey,
  BodyMetricOption,
  BodyTrend,
} from '../api/types'

const session = useAuthSession()
const locale = computed(() => session.user?.preferences.locale ?? 'en-GB')
const unitSystem = computed(() => session.user?.preferences.unit_system ?? 'metric')

const isLoading = ref(true)
const loadError = ref<string | null>(null)
const isSaving = ref(false)
const feedback = ref<string | null>(null)
const error = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})

const metrics = ref<BodyMetricOption[]>([])
const measurements = ref<BodyMeasurement[]>([])
const goals = ref<BodyGoal[]>([])
const trend = ref<BodyTrend | null>(null)
const today = ref<string | null>(null)

const selectedMetric = ref<BodyMetricKey>('body_mass')
const entryDate = ref<string | null>(null)
const entryValue = ref<number | null>(null)

const goalOpen = ref(false)
const goalWarnings = ref<BodyGoalWarning[]>([])
const goalForm = ref({
  name: '',
  direction: 'lose' as BodyGoalDirection,
  starting_value: null as number | null,
  target_value: null as number | null,
  target_date: null as string | null,
})

const metricOptions = computed<UiOption<BodyMetricKey>[]>(() =>
  metrics.value.map((metric) => ({ value: metric.value, label: metric.label })),
)

const activeMetric = computed(
  () => metrics.value.find((metric) => metric.value === selectedMetric.value) ?? null,
)

const unitLabel = computed(() =>
  activeMetric.value ? displayUnit(activeMetric.value, unitSystem.value) : '',
)

const directionOptions: UiOption<BodyGoalDirection>[] = [
  { value: 'lose', label: 'Lose' },
  { value: 'maintain', label: 'Maintain' },
  { value: 'gain', label: 'Gain' },
]

const history = computed(() =>
  measurements.value
    .filter((measurement) => measurement.metric === selectedMetric.value)
    .slice()
    .reverse(),
)

const goalsForMetric = computed(() =>
  goals.value.filter((goal) => goal.body?.metric === selectedMetric.value),
)

/** Change per week in the user's display unit, or null in a non-ready state. */
const trendPerWeek = computed(() => {
  if (!trend.value || trend.value.state !== 'ready' || !activeMetric.value) {
    return null
  }

  return toDisplay(trend.value.change_per_week, activeMetric.value, unitSystem.value)
})

function show(measurement: BodyMeasurement): string {
  if (!activeMetric.value) {
    return measurement.value
  }

  return `${toDisplay(measurement.value, activeMetric.value, unitSystem.value) ?? '—'} ${unitLabel.value}`
}

function showCanonical(value: string | null): string {
  if (value === null || !activeMetric.value) {
    return '—'
  }

  return `${toDisplay(value, activeMetric.value, unitSystem.value) ?? '—'} ${unitLabel.value}`
}

async function load(): Promise<void> {
  isLoading.value = true
  loadError.value = null

  try {
    const [history, goalList] = await Promise.all([getBodyMeasurements(), getBodyGoals()])
    metrics.value = history.metrics
    measurements.value = history.data
    today.value = history.today
    entryDate.value ??= history.today
    goals.value = goalList.data
    await refreshTrend()
  } catch {
    loadError.value = 'Could not load your body history. Check the service and try again.'
  } finally {
    isLoading.value = false
  }
}

async function refreshTrend(): Promise<void> {
  try {
    trend.value = await getBodyTrend(selectedMetric.value)
  } catch {
    trend.value = null
  }
}

async function saveMeasurement(): Promise<void> {
  if (isSaving.value || !activeMetric.value || !entryDate.value) {
    return
  }

  isSaving.value = true
  fieldErrors.value = {}
  error.value = null
  feedback.value = null

  try {
    const canonical = toCanonical(entryValue.value, activeMetric.value, unitSystem.value)

    if (canonical === null) {
      fieldErrors.value = { value: ['Enter a value first.'] }
      return
    }

    await saveBodyMeasurement({
      metric: selectedMetric.value,
      measured_on: entryDate.value,
      value: canonical,
    })

    feedback.value = 'Measurement saved.'
    entryValue.value = null
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)

    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = 'Could not save the measurement. Your entry is still here; please try again.'
    }
  } finally {
    isSaving.value = false
  }
}

async function removeMeasurement(measurement: BodyMeasurement): Promise<void> {
  error.value = null

  try {
    await deleteBodyMeasurement(measurement.id)
    feedback.value = 'Measurement deleted.'
    await load()
  } catch {
    error.value = 'Could not delete that measurement. Please try again.'
  }
}

async function saveGoal(): Promise<void> {
  if (isSaving.value || !activeMetric.value) {
    return
  }

  isSaving.value = true
  fieldErrors.value = {}
  error.value = null
  goalWarnings.value = []

  try {
    const response = await createBodyGoal({
      name: goalForm.value.name,
      metric: selectedMetric.value,
      direction: goalForm.value.direction,
      starting_value: toCanonical(goalForm.value.starting_value, activeMetric.value, unitSystem.value) ?? 0,
      target_value: toCanonical(goalForm.value.target_value, activeMetric.value, unitSystem.value) ?? 0,
      target_date: goalForm.value.target_date,
    })

    goalWarnings.value = response.warnings
    feedback.value = 'Body goal saved.'
    goalOpen.value = false
    goalForm.value = {
      name: '',
      direction: 'lose',
      starting_value: null,
      target_value: null,
      target_date: null,
    }
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)

    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = 'Could not save the goal. Your draft is still here; please try again.'
    }
  } finally {
    isSaving.value = false
  }
}

watch(selectedMetric, () => {
  void refreshTrend()
})

onMounted(load)
</script>

<template>
  <section class="view-stack body-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">Body</p>
        <h1>Measurements and body goals</h1>
        <p class="muted">Dated observations, a deterministic trend, and progress toward a target.</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="feedback" class="notice success" role="status">{{ feedback }}</div>

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      loading-title="Loading body history…"
      panel
      @retry="load"
    >
      <section class="panel" aria-labelledby="body-entry-heading">
        <div class="section-heading">
          <h2 id="body-entry-heading">Record a measurement</h2>
        </div>

        <form class="form-grid" aria-label="Record a measurement" novalidate @submit.prevent="saveMeasurement">
          <UiSelect
            v-model="selectedMetric"
            label="Metric"
            name="metric"
            :options="metricOptions"
            :error="fieldErrors.metric?.[0]"
          />

          <UiDatePicker
            v-model="entryDate"
            label="Measured on"
            name="measured_on"
            :locale="locale"
            :today="today"
            :clearable="false"
            :error="fieldErrors.measured_on?.[0]"
          />

          <UiNumberInput
            v-model="entryValue"
            label="Value"
            name="value"
            :step="0.1"
            :suffix="unitLabel"
            :helper="`Entered and shown in ${unitLabel}. Stored exactly as you typed it.`"
            :error="fieldErrors.value?.[0]"
          />

          <div class="form-actions wide-field">
            <button type="submit" :disabled="isSaving">
              {{ isSaving ? 'Saving…' : 'Save measurement' }}
            </button>
          </div>
        </form>
      </section>

      <section class="panel" aria-labelledby="body-trend-heading">
        <div class="section-heading">
          <h2 id="body-trend-heading">Trend</h2>
          <span class="kind-chip">{{ activeMetric?.label }}</span>
        </div>

        <p v-if="!trend || trend.state === 'empty'" class="muted">
          No measurements yet for this metric. Record one above and the history will start here.
        </p>
        <p v-else-if="trend.state === 'insufficient'" class="muted">
          One measurement so far. A second one is needed before a direction can be calculated.
        </p>
        <div v-else class="summary-grid">
          <div class="metric">
            <span>Change per week</span>
            <strong>{{ trendPerWeek !== null && trendPerWeek > 0 ? '+' : '' }}{{ trendPerWeek }}</strong>
            <span>{{ unitLabel }}</span>
          </div>
          <div class="metric">
            <span>First</span>
            <strong>{{ showCanonical(trend.first?.value ?? null) }}</strong>
            <span>{{ formatCalendarDate(trend.first?.measured_on, locale) }}</span>
          </div>
          <div class="metric">
            <span>Latest</span>
            <strong>{{ showCanonical(trend.last?.value ?? null) }}</strong>
            <span>{{ formatCalendarDate(trend.last?.measured_on, locale) }}</span>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="body-goal-heading">
        <div class="section-heading">
          <h2 id="body-goal-heading">Body goals</h2>
          <button type="button" class="secondary" @click="goalOpen = !goalOpen">
            {{ goalOpen ? 'Cancel' : 'Add a body goal' }}
          </button>
        </div>

        <div v-for="warning in goalWarnings" :key="warning.code" class="notice error" role="status">
          {{ warning.message }}
        </div>

        <form v-if="goalOpen" class="form-grid" aria-label="Create body goal" novalidate @submit.prevent="saveGoal">
          <UiTextInput
            v-model="goalForm.name"
            label="Goal name"
            name="name"
            :maxlength="160"
            required
            :error="fieldErrors.name?.[0]"
          />
          <UiSegmented
            v-model="goalForm.direction"
            label="Direction"
            name="direction"
            :options="directionOptions"
          />
          <UiNumberInput
            v-model="goalForm.starting_value"
            label="Starting value"
            name="starting_value"
            :step="0.1"
            :suffix="unitLabel"
            :error="fieldErrors.starting_value?.[0]"
          />
          <UiNumberInput
            v-model="goalForm.target_value"
            label="Target value"
            name="target_value"
            :step="0.1"
            :suffix="unitLabel"
            :error="fieldErrors.target_value?.[0]"
          />
          <UiDatePicker
            v-model="goalForm.target_date"
            label="Target date"
            name="target_date"
            :locale="locale"
            :today="today"
            wide
            :error="fieldErrors.target_date?.[0]"
          />
          <div class="form-actions wide-field">
            <button type="submit" :disabled="isSaving">{{ isSaving ? 'Saving…' : 'Save body goal' }}</button>
          </div>
        </form>

        <p v-if="goalsForMetric.length === 0" class="muted">
          No body goal for this metric yet.
        </p>
        <ul v-else class="item-list">
          <li v-for="goal in goalsForMetric" :key="goal.id" class="body-goal" :aria-label="goal.name">
            <div class="meta-row">
              <strong>{{ goal.name }}</strong>
              <span class="kind-chip">{{ goal.body?.direction }}</span>
            </div>
            <p class="muted">
              {{ showCanonical(goal.body?.starting_value ?? null) }}
              →
              {{ showCanonical(goal.body?.target_value ?? null) }}
              <span v-if="goal.target_date"> · by {{ formatCalendarDate(goal.target_date, locale) }}</span>
            </p>

            <p v-if="goal.body?.progress === null" class="muted">
              No measurement for this metric yet, so there is nothing to measure progress against.
            </p>
            <div v-else class="progress-track" role="img" :aria-label="`Progress ${Math.round((goal.body?.progress ?? 0) * 100)}%`">
              <div class="progress-fill" :style="{ width: `${(goal.body?.progress ?? 0) * 100}%` }"></div>
            </div>

            <ul v-if="goal.body?.milestones.length" class="goal-chip-list">
              <li
                v-for="milestone in goal.body.milestones"
                :key="milestone.id"
                class="kind-chip"
                :class="{ 'is-achieved': milestone.achieved }"
              >
                {{ showCanonical(milestone.target_value) }}{{ milestone.achieved ? ' ✓' : '' }}
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="body-history-heading">
        <div class="section-heading">
          <h2 id="body-history-heading">History</h2>
        </div>

        <p v-if="history.length === 0" class="muted">Nothing recorded for this metric yet.</p>
        <ul v-else class="item-list">
          <li v-for="measurement in history" :key="measurement.id" class="management-row">
            <div class="management-copy">
              <strong class="mono">{{ show(measurement) }}</strong>
              <p class="muted">{{ formatCalendarDate(measurement.measured_on, locale) }}</p>
            </div>
            <div class="button-row">
              <button
                type="button"
                class="secondary"
                :aria-label="`Delete measurement from ${measurement.measured_on}`"
                @click="removeMeasurement(measurement)"
              >Delete</button>
            </div>
          </li>
        </ul>
      </section>
    </AsyncState>
  </section>
</template>
