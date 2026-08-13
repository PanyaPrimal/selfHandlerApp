<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  clearHabitLog,
  createHabit,
  getGoals,
  getHabits,
  getRoutines,
  replaceHabitLimitSteps,
  updateHabit,
  upsertHabitLog,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import type {
  Goal,
  Habit,
  HabitCreatePayload,
  HabitKind,
  HabitLimitPeriod,
  HabitLimitStepInput,
  HabitMode,
  HabitOutcome,
  HabitState,
  HabitUpdatePayload,
  Routine,
  Weekday,
} from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import {
  UiDatePicker,
  UiNumberInput,
  UiSegmented,
  UiSelect,
  UiTextInput,
  UiTextarea,
  UiTimeField,
  UiToggleGroup,
} from '../components/ui'
import { addDays, parseCalendarDate, toDateString } from '../components/ui/calendar'
import type { UiOption } from '../components/ui'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'

type FocusableControl = { focus: () => void }

interface LimitStepForm {
  effective_on: string
  limit_value: number | null
  period: HabitLimitPeriod
}

interface HabitForm {
  name: string
  description: string
  kind: HabitKind
  mode: HabitMode
  target_value: number | null
  unit: string
  schedule_type: 'daily' | 'weekdays'
  weekdays: Weekday[]
  preferred_time: string | null
  starts_on: string | null
  ends_on: string | null
  routine_id: number | null
  goal_id: number | null
  intention_place: string
  two_minute_starter: string
  limit_steps: LimitStepForm[]
}

const i18n = useI18n()
const route = useRoute()
const locale = i18n.locale
const habits = ref<Habit[]>([])
const routines = ref<Routine[]>([])
const goals = ref<Goal[]>([])
const state = ref<HabitState>('active')
const selectedDate = ref<string | null>(
  typeof route.query.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(route.query.date)
    ? route.query.date
    : null,
)
const today = ref<string | null>(null)
const isLoading = ref(true)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)
const success = ref<MessageKey | null>(null)
const isSubmitting = ref(false)
const actionId = ref<number | null>(null)
const showForm = ref(false)
const editingId = ref<number | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const form = reactive<HabitForm>(emptyForm())
const values = reactive<Record<number, number | null>>({})
const times = reactive<Record<number, string>>({})
const newHabitButton = ref<HTMLButtonElement | null>(null)
const nameInput = ref<FocusableControl | null>(null)

const stateOptions = computed<UiOption<HabitState>[]>(() => [
  { value: 'active', label: i18n.t('habit.active') },
  { value: 'paused', label: i18n.t('habit.paused') },
  { value: 'archived', label: i18n.t('habit.archived') },
])
const kindOptions = computed<UiOption<HabitKind>[]>(() => [
  { value: 'habit', label: i18n.t('habit.kindHabit') },
  { value: 'anti_habit', label: i18n.t('habit.kindAnti') },
])
const modeOptions = computed<UiOption<HabitMode>[]>(() => form.kind === 'habit'
  ? [
      { value: 'yes_no', label: i18n.t('habit.modeYesNo') },
      { value: 'numeric', label: i18n.t('habit.modeNumeric') },
    ]
  : [
      { value: 'abstinence', label: i18n.t('habit.modeAbstinence') },
      { value: 'stepped_limit', label: i18n.t('habit.modeStepped') },
    ])
const scheduleOptions = computed<UiOption<'daily' | 'weekdays'>[]>(() => [
  { value: 'daily', label: i18n.t('habit.daily') },
  { value: 'weekdays', label: i18n.t('habit.selectedWeekdays') },
])
const weekdayOptions = computed<UiOption<Weekday>[]>(() =>
  (['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as Weekday[]).map((value) => ({
    value,
    label: i18n.t(`weekday.${value}` as 'weekday.MO'),
  })),
)
const periodOptions = computed<UiOption<HabitLimitPeriod>[]>(() => [
  { value: 'day', label: i18n.t('habit.perDay') },
  { value: 'week', label: i18n.t('habit.perWeek') },
])
const routineOptions = computed<UiOption<number>[]>(() => routines.value
  .filter((routine) => routine.is_active && !routine.is_archived)
  .map((routine) => ({ value: routine.id, label: routine.name })))
const goalOptions = computed<UiOption<number>[]>(() => goals.value
  .filter((goal) => goal.status === 'active' && !goal.is_archived)
  .map((goal) => ({ value: goal.id, label: goal.name })))
const modeLabelKeys: Record<HabitMode, MessageKey> = {
  yes_no: 'habit.mode.yes_no',
  numeric: 'habit.mode.numeric',
  abstinence: 'habit.mode.abstinence',
  stepped_limit: 'habit.mode.stepped_limit',
}
const listLabelKeys: Record<HabitState, MessageKey> = {
  active: 'habit.list.active',
  paused: 'habit.list.paused',
  archived: 'habit.list.archived',
}
const emptyLabelKeys: Record<HabitState, MessageKey> = {
  active: 'habit.empty.active',
  paused: 'habit.empty.paused',
  archived: 'habit.empty.archived',
}
const limitLabelKeys: Record<NonNullable<Habit['limit_status']>['state'], MessageKey> = {
  no_active_step: 'habit.limit.no_active_step',
  within: 'habit.limit.within',
  exceeded: 'habit.limit.exceeded',
}

function stepDate(offset: number): string {
  const base = parseCalendarDate(today.value ?? selectedDate.value)
  return base ? toDateString(addDays(base, offset)) : ''
}

function emptyForm(): HabitForm {
  return {
    name: '',
    description: '',
    kind: 'habit',
    mode: 'yes_no',
    target_value: null,
    unit: '',
    schedule_type: 'daily',
    weekdays: [],
    preferred_time: null,
    starts_on: null,
    ends_on: null,
    routine_id: null,
    goal_id: null,
    intention_place: '',
    two_minute_starter: '',
    limit_steps: [{ effective_on: stepDate(0), limit_value: null, period: 'day' }],
  }
}

function localTime(): string {
  const parts = new Intl.DateTimeFormat('en-GB', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).formatToParts(new Date())
  const hour = parts.find((part) => part.type === 'hour')?.value ?? '12'
  const minute = parts.find((part) => part.type === 'minute')?.value ?? '00'
  return `${hour === '24' ? '00' : hour}:${minute}`
}

async function load(focusAfter = false): Promise<void> {
  isLoading.value = true
  loadError.value = null

  try {
    const [response, routineList, goalList] = await Promise.all([
      getHabits(state.value, selectedDate.value ?? undefined),
      getRoutines(false),
      getGoals(false),
    ])
    habits.value = response.data
    routines.value = routineList
    goals.value = goalList
    today.value = response.today
    selectedDate.value = response.date

    for (const habit of response.data) {
      values[habit.id] = habit.selected_day.log?.value ?? null
      times[habit.id] = localTime()
    }
  } catch (currentError) {
    loadError.value = currentError instanceof Error ? currentError.message : i18n.t('habit.loadFailed')
  } finally {
    isLoading.value = false
    if (focusAfter && !loadError.value) await nextTick()
  }
}

async function changeState(next: HabitState): Promise<void> {
  if (state.value === next) return
  state.value = next
  success.value = null
  await load()
}

async function changeDate(value: string | null): Promise<void> {
  if (!value || value === selectedDate.value) return
  selectedDate.value = value
  success.value = null
  await load()
}

async function openCreate(): Promise<void> {
  editingId.value = null
  Object.assign(form, emptyForm())
  fieldErrors.value = {}
  error.value = null
  showForm.value = true
  await nextTick()
  nameInput.value?.focus()
}

async function editHabit(habit: Habit): Promise<void> {
  editingId.value = habit.id
  Object.assign(form, {
    name: habit.name,
    description: habit.description ?? '',
    kind: habit.kind,
    mode: habit.mode,
    target_value: habit.target_value,
    unit: habit.unit ?? '',
    schedule_type: habit.schedule.schedule_type,
    weekdays: [...habit.schedule.weekdays],
    preferred_time: habit.schedule.preferred_time,
    starts_on: habit.schedule.starts_on,
    ends_on: habit.schedule.ends_on,
    routine_id: habit.routine?.id ?? null,
    goal_id: habit.goal?.id ?? null,
    intention_place: habit.intention_place ?? '',
    two_minute_starter: habit.two_minute_starter ?? '',
    limit_steps: habit.limit_steps.map((step) => ({
      effective_on: step.effective_on,
      limit_value: step.limit_value,
      period: step.period,
    })),
  })
  fieldErrors.value = {}
  error.value = null
  showForm.value = true
  window.scrollTo({ top: 0, behavior: 'smooth' })
  await nextTick()
  nameInput.value?.focus()
}

async function closeForm(): Promise<void> {
  showForm.value = false
  editingId.value = null
  fieldErrors.value = {}
  await nextTick()
  newHabitButton.value?.focus()
}

function setKind(kind: HabitKind | null): void {
  if (!kind) return
  form.kind = kind
  form.mode = kind === 'habit' ? 'yes_no' : 'abstinence'
  form.target_value = null
  form.unit = ''
}

function addStep(): void {
  form.limit_steps.push({
    effective_on: stepDate(form.limit_steps.length * 7),
    limit_value: null,
    period: 'week',
  })
}

function removeStep(index: number): void {
  if (form.limit_steps.length > 1) form.limit_steps.splice(index, 1)
}

function limitStepsPayload(): HabitLimitStepInput[] {
  return form.limit_steps.map((step) => ({
    effective_on: step.effective_on,
    limit_value: step.limit_value ?? 0,
    period: step.period,
  }))
}

function showSuccess(message: MessageKey): void {
  success.value = message
}

function basePayload(): HabitUpdatePayload {
  return {
    name: form.name,
    description: form.description || null,
    target_value: form.mode === 'numeric' ? form.target_value : null,
    unit: ['numeric', 'stepped_limit'].includes(form.mode) ? form.unit || null : null,
    schedule_type: form.schedule_type,
    ...(form.schedule_type === 'weekdays' ? { weekdays: form.weekdays } : {}),
    preferred_time: form.preferred_time,
    starts_on: form.starts_on,
    ends_on: form.ends_on,
    routine_id: form.routine_id,
    goal_id: form.goal_id,
    intention_place: form.intention_place || null,
    two_minute_starter: form.two_minute_starter || null,
  }
}

async function submit(): Promise<void> {
  error.value = null
  success.value = null
  fieldErrors.value = {}

  if (form.schedule_type === 'weekdays' && form.weekdays.length === 0) {
    fieldErrors.value = { weekdays: [i18n.t('habit.chooseWeekday')] }
    return
  }

  isSubmitting.value = true
  try {
    if (editingId.value === null) {
      await createHabit({
        ...basePayload(),
        name: form.name,
        kind: form.kind,
        mode: form.mode,
        schedule_type: form.schedule_type,
        ...(form.mode === 'stepped_limit' ? { limit_steps: limitStepsPayload() } : {}),
      } as HabitCreatePayload)
      showSuccess('habit.created')
    } else {
      await updateHabit(editingId.value, basePayload())
      if (form.mode === 'stepped_limit') {
        await replaceHabitLimitSteps(editingId.value, limitStepsPayload())
      }
      showSuccess('habit.updated')
    }

    showForm.value = false
    editingId.value = null
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = currentError instanceof Error ? currentError.message : i18n.t('habit.saveFailed')
  } finally {
    isSubmitting.value = false
  }
}

function replaceInList(updated: Habit): void {
  const index = habits.value.findIndex((habit) => habit.id === updated.id)
  if (index >= 0) habits.value.splice(index, 1, updated)
  values[updated.id] = updated.selected_day.log?.value ?? null
}

async function record(habit: Habit, outcome: HabitOutcome): Promise<void> {
  actionId.value = habit.id
  error.value = null
  success.value = null

  try {
    const updated = await upsertHabitLog(habit.id, habit.selected_day.date, {
      outcome,
      ...(outcome === 'recorded' ? { value: values[habit.id] } : {}),
      ...(outcome === 'skipped' ? {} : { occurred_time: times[habit.id] || localTime() }),
    })
    replaceInList(updated)
    showSuccess(outcome === 'relapse' ? 'habit.relapseRecorded' : 'habit.resultSaved')
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('habit.resultFailed')
  } finally {
    actionId.value = null
  }
}

async function clearResult(habit: Habit): Promise<void> {
  actionId.value = habit.id
  error.value = null
  try {
    await clearHabitLog(habit.id, habit.selected_day.date)
    showSuccess('habit.resultCleared')
    await load()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('habit.resultFailed')
  } finally {
    actionId.value = null
  }
}

async function lifecycle(habit: Habit, action: 'pause' | 'resume' | 'archive' | 'restore'): Promise<void> {
  actionId.value = habit.id
  error.value = null
  success.value = null

  const payload: HabitUpdatePayload = action === 'pause'
    ? { is_active: false }
    : action === 'resume'
      ? { is_active: true }
      : action === 'archive'
        ? { is_archived: true }
        : { is_archived: false }

  try {
    await updateHabit(habit.id, payload)
    const notice = {
      pause: 'habit.pausedNotice',
      resume: 'habit.resumedNotice',
      archive: 'habit.archivedNotice',
      restore: 'habit.restoredNotice',
    } as const
    showSuccess(notice[action])
    await load()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('habit.stateFailed')
  } finally {
    actionId.value = null
  }
}

function valueText(habit: Habit): string | null {
  const value = habit.selected_day.log?.value
  if (value === null || value === undefined) return null
  return `${i18n.number(value, { maximumFractionDigits: 3 })} ${habit.unit ?? ''}`.trim()
}

function outcomeText(habit: Habit): string | null {
  const log = habit.selected_day.log
  if (!log) return null
  if (log.outcome === 'protected') return i18n.t('habit.protected')
  if (log.outcome === 'relapse') return i18n.t('habit.relapseRecorded')
  if (log.outcome === 'done') return i18n.t('habit.done')
  if (log.outcome === 'not_done') return i18n.t('habit.notDone')
  if (log.outcome === 'skipped') return i18n.t('habit.skipped')
  return log.successful ? i18n.t('habit.targetMet') : i18n.t('habit.belowTarget')
}

function onKeydown(event: KeyboardEvent): void {
  if (event.key === 'Escape' && showForm.value) {
    event.preventDefault()
    void closeForm()
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  void load()
})
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <section class="view-stack habits-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('habit.eyebrow') }}</p>
        <h1>{{ i18n.t('habit.title') }}</h1>
        <p class="muted">{{ i18n.t('habit.subtitle') }}</p>
      </div>
      <button ref="newHabitButton" type="button" @click="openCreate">{{ i18n.t('habit.new') }}</button>
    </header>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="success" class="notice success" role="status">{{ i18n.t(success) }}</div>

    <section v-if="showForm" class="panel" aria-labelledby="habit-form-heading">
      <div class="section-heading">
        <h2 id="habit-form-heading">{{ i18n.t(editingId === null ? 'habit.create' : 'habit.edit') }}</h2>
        <button type="button" class="secondary" @click="closeForm">{{ i18n.t('common.cancel') }}</button>
      </div>

      <form class="form-grid" :aria-label="i18n.t(editingId === null ? 'habit.create' : 'habit.edit')" novalidate @submit.prevent="submit">
        <UiTextInput ref="nameInput" v-model="form.name" :label="i18n.t('habit.name')" name="name" required :maxlength="160" :error="fieldErrors.name?.[0]" />
        <UiSelect :model-value="form.kind" :label="i18n.t('habit.kind')" name="kind" :options="kindOptions" :disabled="editingId !== null" :error="fieldErrors.kind?.[0]" @update:model-value="setKind" />
        <UiSelect v-model="form.mode" :label="i18n.t('habit.tracking')" name="mode" :options="modeOptions" :disabled="editingId !== null" :error="fieldErrors.mode?.[0]" />
        <UiTextarea v-model="form.description" :label="i18n.t('habit.description')" name="description" :rows="2" :maxlength="2000" wide :error="fieldErrors.description?.[0]" />

        <UiNumberInput v-if="form.mode === 'numeric'" v-model="form.target_value" :label="i18n.t('habit.target')" name="target_value" :min="0.001" :step="0.001" required :error="fieldErrors.target_value?.[0]" />
        <UiTextInput v-if="form.mode === 'numeric' || form.mode === 'stepped_limit'" v-model="form.unit" :label="i18n.t('habit.unit')" name="unit" required :maxlength="32" :error="fieldErrors.unit?.[0]" />

        <UiSelect v-model="form.schedule_type" :label="i18n.t('habit.schedule')" name="schedule_type" :options="scheduleOptions" :error="fieldErrors.schedule_type?.[0]" />
        <UiToggleGroup v-if="form.schedule_type === 'weekdays'" v-model="form.weekdays" :label="i18n.t('habit.weekdays')" name="weekdays" :options="weekdayOptions" wide :error="fieldErrors.weekdays?.[0]" />
        <UiTimeField v-model="form.preferred_time" :label="i18n.t('habit.time')" name="preferred_time" :error="fieldErrors.preferred_time?.[0]" />
        <UiDatePicker v-model="form.starts_on" :label="i18n.t('habit.startsOn')" name="starts_on" :locale="locale" :today="today" :error="fieldErrors.starts_on?.[0]" />
        <UiDatePicker v-model="form.ends_on" :label="i18n.t('habit.endsOn')" name="ends_on" :locale="locale" :today="today" :error="fieldErrors.ends_on?.[0]" />

        <UiSelect v-model="form.routine_id" :label="i18n.t('habit.routine')" name="routine_id" :options="routineOptions" nullable :error="fieldErrors.routine_id?.[0]" />
        <UiSelect v-model="form.goal_id" :label="i18n.t('habit.goal')" name="goal_id" :options="goalOptions" nullable :error="fieldErrors.goal_id?.[0]" />
        <UiTextInput v-model="form.intention_place" :label="i18n.t('habit.place')" name="intention_place" :maxlength="160" :error="fieldErrors.intention_place?.[0]" />
        <UiTextInput v-model="form.two_minute_starter" :label="i18n.t('habit.starter')" name="two_minute_starter" :maxlength="300" :error="fieldErrors.two_minute_starter?.[0]" />

        <fieldset v-if="form.mode === 'stepped_limit'" class="wide-field habit-steps">
          <legend>{{ i18n.t('habit.limitPlan') }}</legend>
          <div v-for="(step, index) in form.limit_steps" :key="index" class="habit-step-row">
            <UiDatePicker v-model="step.effective_on" :label="i18n.t('habit.stepDate', { number: index + 1 })" :name="`step_${index}_date`" :locale="locale" :today="today" :error="fieldErrors[`limit_steps.${index}.effective_on`]?.[0]" />
            <UiNumberInput v-model="step.limit_value" :label="i18n.t('habit.stepCeiling', { number: index + 1 })" :name="`step_${index}_ceiling`" :min="0.001" :step="0.001" :error="fieldErrors[`limit_steps.${index}.limit_value`]?.[0]" />
            <UiSelect v-model="step.period" :label="i18n.t('habit.stepPeriod', { number: index + 1 })" :name="`step_${index}_period`" :options="periodOptions" :error="fieldErrors[`limit_steps.${index}.period`]?.[0]" />
            <button v-if="form.limit_steps.length > 1" type="button" class="ghost" @click="removeStep(index)">{{ i18n.t('habit.removeStep') }}</button>
          </div>
          <button type="button" class="secondary" @click="addStep">{{ i18n.t('habit.addStep') }}</button>
        </fieldset>

        <div class="form-actions wide-field">
          <button type="submit" :disabled="isSubmitting">{{ i18n.t(isSubmitting ? 'common.saving' : editingId === null ? 'habit.create' : 'habit.save') }}</button>
        </div>
      </form>
    </section>

    <section class="panel habit-toolbar" :aria-label="i18n.t('habit.filters')">
      <UiSegmented :model-value="state" :label="i18n.t('habit.state')" name="habit_state" :options="stateOptions" @update:model-value="changeState" />
      <UiDatePicker :model-value="selectedDate" :label="i18n.t('habit.date')" name="habit_date" :locale="locale" :today="today" @update:model-value="changeDate" />
    </section>

    <AsyncState :loading="isLoading" :error="loadError" :loading-title="i18n.t('habit.loading')" panel @retry="load">
      <section class="panel" aria-labelledby="habit-list-heading">
        <div class="section-heading">
          <h2 id="habit-list-heading">{{ i18n.t(listLabelKeys[state]) }}</h2>
          <span class="kind-chip">{{ habits.length }}</span>
        </div>

        <p v-if="habits.length === 0" class="muted">{{ i18n.t(emptyLabelKeys[state]) }}</p>

        <ul v-else class="item-list habit-list">
          <li v-for="habit in habits" :key="habit.id" class="management-row habit-card" :aria-label="habit.name">
            <div class="management-copy habit-card__copy">
              <div class="meta-row">
                <strong>{{ habit.name }}</strong>
                <span class="kind-chip">{{ i18n.t(habit.kind === 'habit' ? 'habit.kindHabit' : 'habit.kindAnti') }}</span>
                <span class="kind-chip">{{ i18n.t(modeLabelKeys[habit.mode]) }}</span>
              </div>
              <p v-if="habit.description" class="muted">{{ habit.description }}</p>
              <p class="muted">
                {{ habit.schedule.schedule_type === 'daily' ? i18n.t('habit.daily') : habit.schedule.weekdays.map((day) => i18n.t(`weekday.${day}` as 'weekday.MO')).join(', ') }}
                <span v-if="habit.schedule.preferred_time"> · {{ habit.schedule.preferred_time }}</span>
                <span v-if="habit.intention_place"> · {{ habit.intention_place }}</span>
              </p>

              <div class="habit-stats" :aria-label="i18n.t('habit.statistics')">
                <span>{{ i18n.t('habit.currentStreak') }} {{ habit.statistics.current_streak }}</span>
                <span v-if="habit.statistics.current_streak > 0">{{ i18n.t('habit.dayStreak', { count: habit.statistics.current_streak }) }}</span>
                <span>{{ i18n.t('habit.bestStreak') }} {{ habit.statistics.best_streak }}</span>
                <span>{{ i18n.number(habit.statistics.completion_percentage, { maximumFractionDigits: 1 }) }}%</span>
                <span>{{ habit.statistics.successes }}/{{ habit.statistics.opportunities }} {{ i18n.t('habit.successes') }}</span>
              </div>

              <div v-if="habit.limit_status" class="habit-limit" role="status">
                <strong>{{ i18n.t(limitLabelKeys[habit.limit_status.state]) }}</strong>
                <span v-if="habit.limit_status.step">{{ i18n.number(habit.limit_status.consumed) }} / {{ i18n.number(habit.limit_status.step.limit_value) }} {{ habit.unit }}</span>
                <span v-if="habit.limit_status.remaining !== null">{{ i18n.number(habit.limit_status.remaining) }} {{ habit.unit }} {{ i18n.t('habit.remaining') }}</span>
              </div>

              <div v-if="habit.selected_day.is_scheduled && selectedDate && today && selectedDate <= today" class="habit-checkin">
                <p v-if="outcomeText(habit)" class="habit-outcome"><strong>{{ outcomeText(habit) }}</strong><span v-if="valueText(habit)"> · {{ valueText(habit) }}</span></p>

                <div v-if="habit.mode === 'yes_no'" class="button-row">
                  <button type="button" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.markDoneNamed', { name: habit.name })" @click="record(habit, 'done')">{{ i18n.t('habit.done') }}</button>
                  <button type="button" class="secondary" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.markNotDoneNamed', { name: habit.name })" @click="record(habit, 'not_done')">{{ i18n.t('habit.notDone') }}</button>
                </div>

                <div v-else-if="habit.mode === 'abstinence'" class="button-row">
                  <button type="button" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.markProtectedNamed', { name: habit.name })" @click="record(habit, 'protected')">{{ i18n.t('habit.protected') }}</button>
                  <button type="button" class="secondary" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.relapseNamed', { name: habit.name })" @click="record(habit, 'relapse')">{{ i18n.t('habit.relapse') }}</button>
                </div>

                <form v-else :aria-label="i18n.t('habit.recordNamed', { name: habit.name })" class="habit-record-form" @submit.prevent="record(habit, 'recorded')">
                  <UiNumberInput v-model="values[habit.id]" :label="i18n.t('habit.value')" :name="`habit_value_${habit.id}`" :min="0" :step="0.001" :suffix="habit.unit ?? undefined" />
                  <UiTimeField v-model="times[habit.id]" :label="i18n.t('habit.time')" :name="`habit_time_${habit.id}`" />
                  <button type="submit" :disabled="actionId === habit.id">{{ i18n.t(habit.selected_day.log ? 'habit.updateResult' : 'habit.recordResult') }}</button>
                </form>

                <div v-if="habit.selected_day.log" class="button-row">
                  <button type="button" class="ghost" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.clearNamed', { name: habit.name })" @click="clearResult(habit)">{{ i18n.t('habit.clearResult') }}</button>
                </div>
              </div>
              <p v-else class="muted">{{ i18n.t('habit.notScheduled') }}</p>
            </div>

            <div class="button-row management-actions habit-actions">
              <button type="button" class="secondary" :disabled="actionId === habit.id" @click="editHabit(habit)">{{ i18n.t('common.edit') }}</button>
              <button v-if="state === 'active'" type="button" class="secondary" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.pauseNamed', { name: habit.name })" @click="lifecycle(habit, 'pause')">{{ i18n.t('habit.pause') }}</button>
              <button v-if="state === 'paused'" type="button" class="secondary" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.resumeNamed', { name: habit.name })" @click="lifecycle(habit, 'resume')">{{ i18n.t('habit.resume') }}</button>
              <button v-if="state !== 'archived'" type="button" class="secondary" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.archiveNamed', { name: habit.name })" @click="lifecycle(habit, 'archive')">{{ i18n.t('habit.archive') }}</button>
              <button v-else type="button" class="secondary" :disabled="actionId === habit.id" :aria-label="i18n.t('habit.restoreNamed', { name: habit.name })" @click="lifecycle(habit, 'restore')">{{ i18n.t('habit.restore') }}</button>
            </div>
          </li>
        </ul>
      </section>
    </AsyncState>
  </section>
</template>

<style scoped>
.habits-page :deep(button),
.habits-page :deep([role='combobox']) {
  min-height: 44px;
}

.habit-toolbar {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(14rem, 1fr);
  gap: 1rem;
  align-items: end;
}

.habit-card {
  align-items: flex-start;
}

.habit-card__copy {
  min-width: 0;
}

.habit-stats,
.habit-limit {
  display: flex;
  flex-wrap: wrap;
  gap: .45rem .9rem;
  margin-top: .65rem;
  font-variant-numeric: tabular-nums;
}

.habit-checkin {
  display: grid;
  gap: .7rem;
  margin-top: 1rem;
}

.habit-record-form,
.habit-step-row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: .75rem;
  align-items: end;
}

.habit-steps {
  display: grid;
  gap: .8rem;
  min-width: 0;
}

.habit-step-row + .habit-step-row {
  border-top: 1px solid var(--border);
  padding-top: .8rem;
}

.habit-outcome {
  margin: 0;
}

@media (max-width: 700px) {
  .habit-toolbar,
  .habit-record-form,
  .habit-step-row {
    grid-template-columns: minmax(0, 1fr);
  }

  .habit-actions,
  .habit-actions button {
    width: 100%;
  }
}
</style>
