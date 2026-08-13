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
import AttachmentGallery from '../components/attachments/AttachmentGallery.vue'
import AttachmentUploader from '../components/attachments/AttachmentUploader.vue'
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
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
import type {
  Attachment,
  BodyGoal,
  BodyGoalDirection,
  BodyGoalWarning,
  BodyMeasurement,
  BodyMetricKey,
  BodyMetricOption,
  BodyTrend,
} from '../api/types'

const session = useAuthSession()
const i18n = useI18n()
const locale = i18n.locale
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

const metricKeys: Record<BodyMetricKey, MessageKey> = {
  body_mass: 'body.metric.bodyMass', body_fat_percentage: 'body.metric.bodyFat',
  waist: 'body.metric.waist', chest: 'body.metric.chest', hips: 'body.metric.hips',
  thigh: 'body.metric.thigh', upper_arm: 'body.metric.upperArm', neck: 'body.metric.neck', calf: 'body.metric.calf',
}
const metricLabel = (metric: BodyMetricKey): string => i18n.t(metricKeys[metric])
const metricOptions = computed<UiOption<BodyMetricKey>[]>(() =>
  metrics.value.map((metric) => ({ value: metric.value, label: metricLabel(metric.value) })),
)

const activeMetric = computed(
  () => metrics.value.find((metric) => metric.value === selectedMetric.value) ?? null,
)

const unitLabel = computed(() =>
  activeMetric.value ? displayUnit(activeMetric.value, unitSystem.value) : '',
)

const directionOptions = computed<UiOption<BodyGoalDirection>[]>(() => [
  { value: 'lose', label: i18n.t('body.lose') },
  { value: 'maintain', label: i18n.t('body.maintain') },
  { value: 'gain', label: i18n.t('body.gain') },
])
const directionLabel = (direction: BodyGoalDirection | undefined): string => direction
  ? directionOptions.value.find((option) => option.value === direction)?.label ?? direction
  : ''

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
    loadError.value = i18n.t('body.loadFailed')
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
      fieldErrors.value = { value: [i18n.t('body.valueRequired')] }
      return
    }

    await saveBodyMeasurement({
      metric: selectedMetric.value,
      measured_on: entryDate.value,
      value: canonical,
    })

    feedback.value = i18n.t('body.measurementSaved')
    entryValue.value = null
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)

    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = i18n.t('body.measurementSaveFailed')
    }
  } finally {
    isSaving.value = false
  }
}

async function removeMeasurement(measurement: BodyMeasurement): Promise<void> {
  error.value = null

  try {
    await deleteBodyMeasurement(measurement.id)
    feedback.value = i18n.t('body.measurementDeleted')
    await load()
  } catch {
    error.value = i18n.t('body.measurementDeleteFailed')
  }
}

function addAttachment(measurement: BodyMeasurement, attachment: Attachment): void {
  if (!measurement.attachments.some(({ id }) => id === attachment.id)) {
    measurement.attachments = [...measurement.attachments, attachment]
  }
}

function removeAttachment(measurement: BodyMeasurement, attachmentId: number): void {
  measurement.attachments = measurement.attachments.filter(({ id }) => id !== attachmentId)
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
    feedback.value = i18n.t('body.goalSaved')
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
      error.value = i18n.t('body.goalSaveFailed')
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
        <p class="eyebrow">{{ i18n.t('body.eyebrow') }}</p>
        <h1>{{ i18n.t('body.title') }}</h1>
        <p class="muted">{{ i18n.t('body.subtitle') }}</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="feedback" class="notice success" role="status">{{ feedback }}</div>

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      :loading-title="i18n.t('body.loading')"
      panel
      @retry="load"
    >
      <section class="panel" aria-labelledby="body-entry-heading">
        <div class="section-heading">
          <h2 id="body-entry-heading">{{ i18n.t('body.record') }}</h2>
        </div>

        <form class="form-grid" :aria-label="i18n.t('body.record')" novalidate @submit.prevent="saveMeasurement">
          <UiSelect
            v-model="selectedMetric"
            :label="i18n.t('body.metric')"
            name="metric"
            :options="metricOptions"
            :error="fieldErrors.metric?.[0]"
          />

          <UiDatePicker
            v-model="entryDate"
            :label="i18n.t('body.measuredOn')"
            name="measured_on"
            :locale="locale"
            :today="today"
            :clearable="false"
            :error="fieldErrors.measured_on?.[0]"
          />

          <UiNumberInput
            v-model="entryValue"
            :label="i18n.t('body.value')"
            name="value"
            :step="0.1"
            :suffix="unitLabel"
            :helper="i18n.t('body.valueHelp', { unit: unitLabel })"
            :error="fieldErrors.value?.[0]"
          />

          <div class="form-actions wide-field">
            <button type="submit" :disabled="isSaving">
              {{ i18n.t(isSaving ? 'common.saving' : 'body.saveMeasurement') }}
            </button>
          </div>
        </form>
      </section>

      <section class="panel" aria-labelledby="body-trend-heading">
        <div class="section-heading">
          <h2 id="body-trend-heading">{{ i18n.t('body.trend') }}</h2>
          <span class="kind-chip">{{ activeMetric ? metricLabel(activeMetric.value) : '' }}</span>
        </div>

        <p v-if="!trend || trend.state === 'empty'" class="muted">
          {{ i18n.t('body.trendEmpty') }}
        </p>
        <p v-else-if="trend.state === 'insufficient'" class="muted">
          {{ i18n.t('body.trendInsufficient') }}
        </p>
        <div v-else class="summary-grid">
          <div class="metric">
            <span>{{ i18n.t('body.changePerWeek') }}</span>
            <strong>{{ trendPerWeek !== null && trendPerWeek > 0 ? '+' : '' }}{{ trendPerWeek }}</strong>
            <span>{{ unitLabel }}</span>
          </div>
          <div class="metric">
            <span>{{ i18n.t('body.first') }}</span>
            <strong>{{ showCanonical(trend.first?.value ?? null) }}</strong>
            <span>{{ formatCalendarDate(trend.first?.measured_on, locale) }}</span>
          </div>
          <div class="metric">
            <span>{{ i18n.t('body.latest') }}</span>
            <strong>{{ showCanonical(trend.last?.value ?? null) }}</strong>
            <span>{{ formatCalendarDate(trend.last?.measured_on, locale) }}</span>
          </div>
        </div>
      </section>

      <section class="panel" aria-labelledby="body-goal-heading">
        <div class="section-heading">
          <h2 id="body-goal-heading">{{ i18n.t('body.goals') }}</h2>
          <button type="button" class="secondary" @click="goalOpen = !goalOpen">
            {{ i18n.t(goalOpen ? 'common.cancel' : 'body.addGoal') }}
          </button>
        </div>

        <div v-for="warning in goalWarnings" :key="warning.code" class="notice error" role="status">
          {{ warning.message }}
        </div>

        <form v-if="goalOpen" class="form-grid" :aria-label="i18n.t('body.createGoal')" novalidate @submit.prevent="saveGoal">
          <UiTextInput
            v-model="goalForm.name"
            :label="i18n.t('body.goalName')"
            name="name"
            :maxlength="160"
            required
            :error="fieldErrors.name?.[0]"
          />
          <UiSegmented
            v-model="goalForm.direction"
            :label="i18n.t('body.direction')"
            name="direction"
            :options="directionOptions"
          />
          <UiNumberInput
            v-model="goalForm.starting_value"
            :label="i18n.t('body.startingValue')"
            name="starting_value"
            :step="0.1"
            :suffix="unitLabel"
            :error="fieldErrors.starting_value?.[0]"
          />
          <UiNumberInput
            v-model="goalForm.target_value"
            :label="i18n.t('body.targetValue')"
            name="target_value"
            :step="0.1"
            :suffix="unitLabel"
            :error="fieldErrors.target_value?.[0]"
          />
          <UiDatePicker
            v-model="goalForm.target_date"
            :label="i18n.t('body.targetDate')"
            name="target_date"
            :locale="locale"
            :today="today"
            wide
            :error="fieldErrors.target_date?.[0]"
          />
          <div class="form-actions wide-field">
            <button type="submit" :disabled="isSaving">{{ i18n.t(isSaving ? 'common.saving' : 'body.saveGoal') }}</button>
          </div>
        </form>

        <p v-if="goalsForMetric.length === 0" class="muted">
          {{ i18n.t('body.noGoal') }}
        </p>
        <ul v-else class="item-list">
          <li v-for="goal in goalsForMetric" :key="goal.id" class="body-goal" :aria-label="goal.name">
            <div class="meta-row">
              <strong>{{ goal.name }}</strong>
              <span class="kind-chip">{{ directionLabel(goal.body?.direction) }}</span>
            </div>
            <p class="muted">
              {{ showCanonical(goal.body?.starting_value ?? null) }}
              →
              {{ showCanonical(goal.body?.target_value ?? null) }}
              <span v-if="goal.target_date"> · {{ i18n.t('body.byDate', { date: formatCalendarDate(goal.target_date, locale) }) }}</span>
            </p>

            <p v-if="goal.body?.progress === null" class="muted">
              {{ i18n.t('body.noProgress') }}
            </p>
            <div v-else class="progress-track" role="img" :aria-label="i18n.t('body.progress', { value: Math.round((goal.body?.progress ?? 0) * 100) })">
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
          <h2 id="body-history-heading">{{ i18n.t('body.history') }}</h2>
        </div>

        <p v-if="history.length === 0" class="muted">{{ i18n.t('body.historyEmpty') }}</p>
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
                :aria-label="i18n.t('body.deleteMeasurementOn', { date: measurement.measured_on })"
                @click="removeMeasurement(measurement)"
              >{{ i18n.t('common.delete') }}</button>
            </div>
            <div class="attachment-parent" :data-attachment-parent="`body_measurement:${measurement.id}`">
              <AttachmentGallery
                :attachments="measurement.attachments"
                :parent-label="`${metricLabel(measurement.metric)}, ${formatCalendarDate(measurement.measured_on, locale)}`"
                @deleted="removeAttachment(measurement, $event)"
              />
              <AttachmentUploader
                parent-type="body_measurement"
                :parent-id="measurement.id"
                :disabled="measurement.attachments.length >= 10"
                @uploaded="addAttachment(measurement, $event)"
              />
            </div>
          </li>
        </ul>
      </section>
    </AsyncState>
  </section>
</template>
