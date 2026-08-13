<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  clearSleepLog,
  createSleepPlan,
  getSleepWorkspace,
  updateSleepPlan,
  upsertSleepLog,
  validationErrors,
  type ValidationErrors,
} from '../../api/client'
import type {
  SleepPlan,
  SleepPlanPayload,
  SleepPlanState,
  Weekday,
} from '../../api/types'
import AsyncState from '../AsyncState.vue'
import {
  UiDatePicker,
  UiNumberInput,
  UiSegmented,
  UiTextInput,
  UiTextarea,
  UiTimeField,
  UiToggleGroup,
} from '../ui'
import type { UiOption } from '../ui'
import { addDays, parseCalendarDate, toDateString } from '../ui/calendar'
import { useI18n } from '../../i18n'
import type { MessageKey } from '../../i18n/locales/en'

const i18n = useI18n()
const route = useRoute()
const locale = i18n.locale
const state = ref<SleepPlanState>('active')
const deepLinkedDate = typeof route.query.sleep_date === 'string'
  && /^\d{4}-\d{2}-\d{2}$/.test(route.query.sleep_date)
  ? route.query.sleep_date
  : null
const date = ref<string | null>(deepLinkedDate)
const today = ref<string | null>(null)
const plans = ref<SleepPlan[]>([])
const statistics = ref({ planned_nights: 0, recorded_nights: 0, average_duration_minutes: null as number | null, average_quality: null as number | null })
const loading = ref(true)
const submitting = ref(false)
const actionId = ref<number | null>(null)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)
const successKey = ref<MessageKey | null>(null)
const fieldErrors = ref<ValidationErrors>({})

const createForm = reactive<SleepPlanPayload>({
  name: '',
  planned_bed_time: '23:00',
  planned_wake_time: '07:00',
  schedule_type: 'daily',
  weekdays: [],
  starts_on: null,
  ends_on: null,
  is_active: true,
})
interface SleepLogDraft {
  actual_bed_date: string | null
  actual_bed_time: string | null
  actual_wake_date: string | null
  actual_wake_time: string | null
  quality: number | null
  note: string
}

const logForms = reactive<Record<number, SleepLogDraft>>({})

const stateOptions = computed<UiOption<SleepPlanState>[]>(() => [
  { value: 'active', label: i18n.t('sleep.filter.active') },
  { value: 'paused', label: i18n.t('sleep.filter.paused') },
  { value: 'archived', label: i18n.t('sleep.filter.archived') },
])
const scheduleOptions = computed<UiOption<'daily' | 'weekdays'>[]>(() => [
  { value: 'daily', label: i18n.t('routine.daily') },
  { value: 'weekdays', label: i18n.t('routine.byWeekdays') },
])
const weekdayOptions = computed<UiOption<Weekday>[]>(() =>
  (['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as Weekday[]).map((value) => ({
    value,
    label: i18n.t(`weekday.${value}` as 'weekday.MO'),
  })),
)
const success = computed(() => successKey.value ? i18n.t(successKey.value) : null)

function nextDate(value: string): string {
  const parsed = parseCalendarDate(value)
  return parsed ? toDateString(addDays(parsed, 1)) : value
}

function formFor(plan: SleepPlan): SleepLogDraft {
  if (!logForms[plan.id]) {
    const night = plan.selected_night
    logForms[plan.id] = night?.log ? {
      actual_bed_date: night.log.actual_bed_date,
      actual_bed_time: night.log.actual_bed_time,
      actual_wake_date: night.log.actual_wake_date,
      actual_wake_time: night.log.actual_wake_time,
      quality: night.log.quality,
      note: night.log.note ?? '',
    } : {
      actual_bed_date: night?.date ?? date.value ?? today.value ?? '',
      actual_bed_time: night?.planned_bed_time ?? plan.schedule.planned_bed_time,
      actual_wake_date: night?.planned_wake_date ?? nextDate(date.value ?? today.value ?? ''),
      actual_wake_time: night?.planned_wake_time ?? plan.planned_wake_time,
      quality: 7,
      note: '',
    }
  }
  return logForms[plan.id]
}

function formatDuration(minutes: number | null): string {
  if (minutes === null) return i18n.t('sleep.notRecorded')
  const hours = Math.floor(minutes / 60)
  const remainder = minutes % 60
  return remainder === 0
    ? i18n.t('sleep.durationHours', { hours: i18n.number(hours) })
    : i18n.t('sleep.durationHoursMinutes', {
        hours: i18n.number(hours),
        minutes: i18n.number(remainder),
      })
}

async function load(nextDateValue = date.value, focus = false): Promise<void> {
  loading.value = true
  loadError.value = null
  error.value = null
  try {
    const response = await getSleepWorkspace(state.value, nextDateValue ?? undefined)
    date.value = response.date
    today.value = response.today
    plans.value = response.data
    statistics.value = response.statistics
    for (const key of Object.keys(logForms)) delete logForms[Number(key)]
    for (const plan of plans.value) formFor(plan)
    if (focus) document.querySelector<HTMLElement>('#sleep-plan-list-heading')?.focus()
  } catch (currentError) {
    loadError.value = currentError instanceof Error ? currentError.message : i18n.t('sleep.loadFailed')
  } finally {
    loading.value = false
  }
}

async function switchState(value: SleepPlanState): Promise<void> {
  state.value = value
  successKey.value = null
  await load(date.value, true)
}

async function selectDate(value: string | null): Promise<void> {
  if (value) await load(value, true)
}

async function submitPlan(): Promise<void> {
  submitting.value = true
  error.value = null
  successKey.value = null
  fieldErrors.value = {}
  try {
    await createSleepPlan({
      ...createForm,
      weekdays: createForm.schedule_type === 'weekdays' ? createForm.weekdays : undefined,
    })
    Object.assign(createForm, {
      name: '', planned_bed_time: '23:00', planned_wake_time: '07:00', schedule_type: 'daily',
      weekdays: [], starts_on: null, ends_on: null, is_active: true,
    })
    state.value = 'active'
    successKey.value = 'sleep.created'
    await load(date.value)
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = currentError instanceof Error ? currentError.message : i18n.t('sleep.saveFailed')
  } finally {
    submitting.value = false
  }
}

async function saveLog(plan: SleepPlan): Promise<void> {
  if (!date.value) return
  const form = formFor(plan)
  if (!form.actual_bed_date || !form.actual_bed_time || !form.actual_wake_date || !form.actual_wake_time || form.quality === null) {
    error.value = i18n.t('sleep.logRequired')
    return
  }
  actionId.value = plan.id
  error.value = null
  successKey.value = null
  try {
    await upsertSleepLog(plan.id, date.value, {
      actual_bed_date: form.actual_bed_date,
      actual_bed_time: form.actual_bed_time,
      actual_wake_date: form.actual_wake_date,
      actual_wake_time: form.actual_wake_time,
      quality: form.quality,
      note: form.note || null,
    })
    successKey.value = 'sleep.recorded'
    await load(date.value)
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('sleep.recordFailed')
  } finally {
    actionId.value = null
  }
}

async function clearLog(plan: SleepPlan): Promise<void> {
  if (!date.value) return
  actionId.value = plan.id
  error.value = null
  try {
    await clearSleepLog(plan.id, date.value)
    successKey.value = 'sleep.cleared'
    await load(date.value)
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('sleep.clearFailed')
  } finally {
    actionId.value = null
  }
}

async function lifecycle(plan: SleepPlan, mode: 'pause' | 'resume' | 'archive' | 'restore'): Promise<void> {
  actionId.value = plan.id
  error.value = null
  try {
    const payload = mode === 'pause' ? { is_active: false }
      : mode === 'resume' ? { is_active: true }
        : mode === 'archive' ? { is_archived: true }
          : { is_archived: false, is_active: false }
    await updateSleepPlan(plan.id, payload)
    successKey.value = `sleep.${mode}d` as MessageKey
    await load(date.value, true)
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('sleep.stateFailed')
  } finally {
    actionId.value = null
  }
}

void load()
</script>

<template>
  <section class="panel sleep-workspace" aria-labelledby="sleep-heading">
    <div class="section-heading">
      <div>
        <p class="eyebrow">{{ i18n.t('sleep.eyebrow') }}</p>
        <h2 id="sleep-heading">{{ i18n.t('sleep.title') }}</h2>
        <p class="muted">{{ i18n.t('sleep.subtitle') }}</p>
      </div>
      <UiDatePicker
        :model-value="date"
        :label="i18n.t('sleep.nightDate')"
        name="sleep-date"
        :locale="locale"
        :today="today"
        @update:model-value="selectDate"
      />
    </div>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="success" class="notice success" role="status">{{ success }}</div>

    <form class="form-grid sleep-plan-form" :aria-label="i18n.t('sleep.create')" novalidate @submit.prevent="submitPlan">
      <UiTextInput v-model="createForm.name" :label="i18n.t('sleep.planName')" name="sleep-name" required :maxlength="160" :error="fieldErrors.name?.[0]" />
      <UiTimeField v-model="createForm.planned_bed_time" :label="i18n.t('sleep.plannedBed')" name="planned-bed" :error="fieldErrors.planned_bed_time?.[0]" />
      <UiTimeField v-model="createForm.planned_wake_time" :label="i18n.t('sleep.plannedWake')" name="planned-wake" :error="fieldErrors.planned_wake_time?.[0]" />
      <UiSegmented v-model="createForm.schedule_type" :label="i18n.t('routine.schedule')" name="sleep-schedule" :options="scheduleOptions" />
      <UiToggleGroup
        v-if="createForm.schedule_type === 'weekdays'"
        :model-value="createForm.weekdays ?? []"
        :label="i18n.t('routine.weekdays')"
        name="sleep-weekdays"
        :options="weekdayOptions"
        wide
        :error="fieldErrors.weekdays?.[0]"
        @update:model-value="createForm.weekdays = $event"
      />
      <UiDatePicker :model-value="createForm.starts_on ?? null" :label="i18n.t('sleep.startsOn')" name="sleep-starts" :locale="locale" @update:model-value="createForm.starts_on = $event" />
      <UiDatePicker :model-value="createForm.ends_on ?? null" :label="i18n.t('sleep.endsOn')" name="sleep-ends" :locale="locale" @update:model-value="createForm.ends_on = $event" />
      <div class="form-actions wide-field">
        <button type="submit" :disabled="submitting">{{ i18n.t(submitting ? 'common.saving' : 'sleep.create') }}</button>
      </div>
    </form>

    <UiSegmented
      :model-value="state"
      :label="i18n.t('sleep.filters')"
      name="sleep-state"
      :options="stateOptions"
      @update:model-value="switchState"
    />

    <AsyncState
      :loading="loading"
      :error="loadError"
      :empty="plans.length === 0"
      :loading-title="i18n.t('sleep.loading')"
      :empty-title="i18n.t(`sleep.empty.${state}` as MessageKey)"
      show-empty-icon
      @retry="load(date)"
    >
      <div class="metric-grid sleep-metrics" :aria-label="i18n.t('sleep.title')">
        <div class="metric"><span>{{ i18n.t('sleep.plannedNights') }}</span><strong>{{ i18n.number(statistics.planned_nights) }}</strong></div>
        <div class="metric"><span>{{ i18n.t('sleep.recordedNights') }}</span><strong>{{ i18n.number(statistics.recorded_nights) }}</strong></div>
        <div class="metric"><span>{{ i18n.t('sleep.averageDuration') }}</span><strong>{{ formatDuration(statistics.average_duration_minutes) }}</strong></div>
        <div class="metric"><span>{{ i18n.t('sleep.averageQuality') }}</span><strong>{{ statistics.average_quality === null ? i18n.t('sleep.notRecorded') : `${i18n.number(statistics.average_quality)} / 10` }}</strong></div>
      </div>

      <h3 id="sleep-plan-list-heading" class="focus-target" tabindex="-1">{{ i18n.t('sleep.plans') }}</h3>
      <ul class="item-list sleep-plan-list">
        <li v-for="plan in plans" :key="plan.id" class="management-row sleep-plan-card" :aria-label="plan.name">
          <div class="management-copy">
            <div class="meta-row">
              <strong>{{ plan.name }}</strong>
              <span class="kind-chip">{{ plan.schedule.planned_bed_time }} → {{ plan.planned_wake_time }}</span>
            </div>
            <p v-if="plan.selected_night" class="muted">
              {{ i18n.t('sleep.plannedFor', { date: plan.selected_night.date }) }} ·
              {{ plan.selected_night.log ? formatDuration(plan.selected_night.log.duration_minutes) : i18n.t('sleep.notRecorded') }}
              <span v-if="plan.selected_night.log"> · {{ i18n.t('sleep.qualityValue', { value: i18n.number(plan.selected_night.log.quality) }) }}</span>
            </p>
          </div>

          <form v-if="plan.selected_night && !plan.is_archived" class="form-grid sleep-log-form" :aria-label="i18n.t('sleep.recordNamed', { name: plan.name })" @submit.prevent="saveLog(plan)">
            <UiDatePicker v-model="formFor(plan).actual_bed_date" :label="i18n.t('sleep.bedDate')" :name="`bed-date-${plan.id}`" :locale="locale" />
            <UiTimeField v-model="formFor(plan).actual_bed_time" :label="i18n.t('sleep.bedTime')" :name="`bed-time-${plan.id}`" />
            <UiDatePicker v-model="formFor(plan).actual_wake_date" :label="i18n.t('sleep.wakeDate')" :name="`wake-date-${plan.id}`" :locale="locale" />
            <UiTimeField v-model="formFor(plan).actual_wake_time" :label="i18n.t('sleep.wakeTime')" :name="`wake-time-${plan.id}`" />
            <UiNumberInput v-model="formFor(plan).quality" :label="i18n.t('sleep.quality')" :name="`quality-${plan.id}`" :min="1" :max="10" :step="1" />
            <UiTextarea v-model="formFor(plan).note" :label="i18n.t('sleep.note')" :name="`sleep-note-${plan.id}`" :rows="2" :maxlength="5000" />
            <div class="form-actions wide-field">
              <button type="submit" :disabled="actionId === plan.id">{{ i18n.t(plan.selected_night.log ? 'sleep.updateRecord' : 'sleep.record') }}</button>
              <button v-if="plan.selected_night.log" type="button" class="secondary" :aria-label="i18n.t('sleep.clearNamed', { name: plan.name })" :disabled="actionId === plan.id" @click="clearLog(plan)">{{ i18n.t('common.clear') }}</button>
            </div>
          </form>

          <div class="button-row management-actions">
            <button v-if="!plan.is_archived" type="button" class="secondary" :aria-label="i18n.t(plan.is_active ? 'sleep.pauseNamed' : 'sleep.resumeNamed', { name: plan.name })" :disabled="actionId === plan.id" @click="lifecycle(plan, plan.is_active ? 'pause' : 'resume')">{{ i18n.t(plan.is_active ? 'sleep.pause' : 'sleep.resume') }}</button>
            <button type="button" class="secondary" :aria-label="i18n.t(plan.is_archived ? 'sleep.restoreNamed' : 'sleep.archiveNamed', { name: plan.name })" :disabled="actionId === plan.id" @click="lifecycle(plan, plan.is_archived ? 'restore' : 'archive')">{{ i18n.t(plan.is_archived ? 'sleep.restore' : 'sleep.archive') }}</button>
          </div>
        </li>
      </ul>
    </AsyncState>
  </section>
</template>
