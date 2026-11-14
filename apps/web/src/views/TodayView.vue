<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  clearRoutineActivityLog,
  clearRoutineLog,
  getToday,
  replaceRoutineDaySelections,
  updateRoutineLog,
  upsertRoutineActivityLog,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import ProgressSummary from '../components/ProgressSummary.vue'
import TodayHabits from '../components/TodayHabits.vue'
import TodayAgenda from '../components/TodayAgenda.vue'
import { formatCalendarDate } from '../lib/format'
import AppIcon from '../components/ui/AppIcon.vue'
import { UiDatePicker, UiNumberInput, UiSelect, UiTextarea } from '../components/ui'
import type { Habit, RoutineLog, TodayResponse, TodayRoutine } from '../api/types'
import type { UiOption } from '../components/ui'
import { useI18n } from '../i18n'

type RoutineState = RoutineLog['status'] | 'pending'

const selectedDate = ref('')
const data = ref<TodayResponse | null>(null)
const isLoading = ref(true)
const actionRoutineId = ref<number | null>(null)
const actionActivityId = ref<number | null>(null)
const savingSelection = ref(false)
const progressDrafts = ref<Record<number, number | null>>({})
const noteDrafts = ref<Record<number, string>>({})
const expandedRoutines = ref<Record<string, boolean>>({})
let selectionSaveChain: Promise<void> = Promise.resolve()
const error = ref<string | null>(null)
const statusMessage = ref<string | null>(null)
const retryAction = ref<(() => Promise<void>) | null>(null)
const dateControl = ref<{ focus: () => void } | null>(null)
// The user's real current day, resolved by the API from their profile time zone.
// It is captured on the first unparameterised load and only marks the calendar;
// it never becomes a value on its own.
const userToday = ref<string | null>(null)
const i18n = useI18n()
const locale = i18n.locale
let loadGeneration = 0
const weekDays = computed(() => {
  if (!selectedDate.value) return []
  const start = new Date(`${selectedDate.value}T12:00:00Z`)
  start.setUTCDate(start.getUTCDate() - (start.getUTCDay() + 6) % 7)
  return Array.from({ length: 7 }, (_, index) => {
    const day = new Date(start)
    day.setUTCDate(day.getUTCDate() + index)
    return {
      date: day.toISOString().slice(0, 10),
      label: new Intl.DateTimeFormat(locale.value, { weekday: 'short', timeZone: 'UTC' }).format(day),
      number: day.getUTCDate(),
    }
  })
})

function shiftWeek(amount: number): void {
  const day = new Date(`${selectedDate.value}T12:00:00Z`)
  day.setUTCDate(day.getUTCDate() + amount * 7)
  loadSelectedDate(day.toISOString().slice(0, 10))
}

function routineKind(kind: TodayRoutine['kind']): string {
  return i18n.t(`today.kind.${kind}` as 'today.kind.routine')
}

function routineState(state: RoutineState): string {
  return i18n.t(`today.state.${state}` as 'today.state.done')
}

function streakLabel(count: number): string {
  return i18n.plural(count, {
    one: 'today.streak.one', few: 'today.streak.few', many: 'today.streak.many', other: 'today.streak.other',
  })
}

const completionLabel = computed(() => `${Math.round(data.value?.summary.completion_rate ?? 0)}%`)
const progressWidth = computed(() => `${data.value?.summary.completion_rate ?? 0}%`)
const morningOptions = computed<UiOption<number>[]>(() => (data.value?.routine_day.morning.candidates ?? []).map((candidate) => ({
  value: candidate.routine_id,
  label: candidate.name,
})))
const eveningOptions = computed<UiOption<number>[]>(() => (data.value?.routine_day.evening.candidates ?? []).map((candidate) => ({
  value: candidate.routine_id,
  label: candidate.name,
})))

function habitChanged(habit: Habit): void {
  if (!data.value || data.value.date !== habit.selected_day.date) return
  data.value.habits = (data.value.habits ?? []).map((item) => item.id === habit.id ? habit : item)
  void loadToday(selectedDate.value)
}

type FocusTarget = HTMLElement | { focus: () => void }

function refocus(target: FocusTarget | null | undefined): void {
  if (!target) {
    return
  }

  if (target instanceof HTMLElement && !target.isConnected) {
    return
  }

  if (target instanceof HTMLElement) {
    const closedGroup = target.closest('details:not([open])')
    if (closedGroup) {
      closedGroup.querySelector<HTMLElement>(':scope > summary')?.focus()
      return
    }
  }

  target.focus()
}

async function loadToday(date?: string, focusTarget?: FocusTarget | null): Promise<void> {
  const generation = ++loadGeneration
  isLoading.value = true
  error.value = null
  statusMessage.value = null
  retryAction.value = null

  if (date && data.value?.date !== date) {
    data.value = null
  }

  try {
    const response = await getToday(date)
    if (generation !== loadGeneration) return
    // Refresh saved facts without overwriting a draft in another activity.
    // Read drafts after the request: the user may have typed while it was pending.
    const previousActivities = new Map((data.value?.date === response.date ? data.value.routines : [])
      .flatMap((routine) => routine.activities.map((activity) => [activity.id, activity] as const)))
    progressDrafts.value = Object.fromEntries(response.routines.flatMap((routine) => routine.activities.map((activity) => [
      activity.id,
      previousActivities.has(activity.id) && activity.id !== actionActivityId.value
        && progressDrafts.value[activity.id] !== (previousActivities.get(activity.id)?.selected_day_log?.progress_value ?? null)
        ? progressDrafts.value[activity.id]
        : activity.selected_day_log?.progress_value ?? null,
    ])))
    noteDrafts.value = Object.fromEntries(response.routines.flatMap((routine) => routine.activities.map((activity) => [
      activity.id,
      previousActivities.has(activity.id) && activity.id !== actionActivityId.value
        && noteDrafts.value[activity.id] !== (previousActivities.get(activity.id)?.selected_day_log?.note ?? '')
        ? noteDrafts.value[activity.id]
        : activity.selected_day_log?.note ?? '',
    ])))
    data.value = response
    selectedDate.value = response.date

    if (!date) {
      userToday.value = response.date
    }
  } catch (currentError) {
    if (generation !== loadGeneration) return
    error.value = currentError instanceof Error ? currentError.message : i18n.t('today.loadFailed')
    retryAction.value = () => loadToday(date, focusTarget ?? dateControl.value)
  } finally {
    if (generation === loadGeneration) {
      isLoading.value = false
      await nextTick()
      refocus(focusTarget)
    }
  }
}

function cloneToday(value: TodayResponse): TodayResponse {
  return JSON.parse(JSON.stringify(value)) as TodayResponse
}

function recalculateSummary(): void {
  if (!data.value) {
    return
  }

  const habits = data.value.module_summaries.habits
  const scheduled = data.value.routines.length + (habits?.scheduled ?? 0)
  const done = data.value.routines.filter((routine) => routine.log?.status === 'done').length + (habits?.done ?? 0)
  const skipped = data.value.routines.filter((routine) => routine.log?.status === 'skipped').length + (habits?.skipped ?? 0)

  data.value.summary = {
    scheduled,
    done,
    skipped,
    pending: scheduled - done - skipped,
    completion_rate: scheduled === 0 ? 0 : Math.round((done / scheduled) * 10_000) / 100,
  }
}

function setLocalState(routine: TodayRoutine, state: RoutineState, savedLog?: RoutineLog): void {
  if (!data.value) {
    return
  }

  const currentLog = routine.log
  data.value.routines = data.value.routines.map((item) => {
    if (item.id !== routine.id) {
      return item
    }

    if (state === 'pending') {
      return { ...item, log: null }
    }

    return {
      ...item,
      log: savedLog ?? {
        id: currentLog?.id ?? 0,
        routine_id: item.id,
        log_date: selectedDate.value,
        status: state,
        note: currentLog?.note ?? null,
        completed_at: state === 'done' ? new Date().toISOString() : null,
      },
    }
  })
  recalculateSummary()
}

async function setRoutineState(
  routine: TodayRoutine,
  state: RoutineState,
  focusTarget?: HTMLElement | null,
): Promise<void> {
  if (!data.value || actionRoutineId.value !== null) {
    return
  }

  const previousData = cloneToday(data.value)
  actionRoutineId.value = routine.id
  error.value = null
  statusMessage.value = null
  retryAction.value = null
  setLocalState(routine, state)

  try {
    if (state === 'pending') {
      await clearRoutineLog(routine.id, selectedDate.value)
      setLocalState(routine, state)
    } else {
      const savedLog = await updateRoutineLog(routine.id, selectedDate.value, state)
      setLocalState(routine, state, savedLog)
    }

    data.value = await getToday(selectedDate.value)
    statusMessage.value = i18n.t('today.stateSaved', { name: routine.name, state: routineState(state) })
  } catch (currentError) {
    data.value = previousData
    error.value = currentError instanceof Error ? currentError.message : i18n.t('today.updateFailed')
    retryAction.value = () => setRoutineState(routine, state, focusTarget)
  } finally {
    actionRoutineId.value = null
    await nextTick()

    refocus(focusTarget)
  }
}

function saveSelection(period: 'morning' | 'evening', routineId: number | null): void {
  if (!data.value) return
  const rollback = cloneToday(data.value)
  const currentPeriod = data.value.routine_day[period]
  const previousId = currentPeriod.selected?.routine_id ?? null
  currentPeriod.selected = currentPeriod.candidates.find((candidate) => candidate.routine_id === routineId) ?? null
  if (previousId !== null && previousId !== routineId) {
    data.value.routines = data.value.routines.filter((routine) => routine.id !== previousId)
  }
  savingSelection.value = true
  error.value = null
  selectionSaveChain = selectionSaveChain.then(async () => {
    if (!data.value) return
    const current = data.value.routine_day
    await replaceRoutineDaySelections(
        selectedDate.value,
        period === 'morning' ? routineId : current.morning.selected?.routine_id ?? null,
        period === 'evening' ? routineId : current.evening.selected?.routine_id ?? null,
      )
    await loadToday(selectedDate.value)
    statusMessage.value = i18n.t('today.selectionSaved')
  }).catch((currentError: unknown) => {
    data.value = rollback
    error.value = currentError instanceof Error ? currentError.message : i18n.t('today.selectionFailed')
  }).finally(() => {
    savingSelection.value = false
  })
}

function resolvedCount(routine: TodayRoutine): number {
  return routine.activities.filter((activity) => activity.selected_day_log !== null).length
}

async function setActivityState(
  routine: TodayRoutine,
  activityId: number,
  state: 'done' | 'skipped' | 'pending',
  focusTarget?: HTMLElement | null,
): Promise<void> {
  if (actionActivityId.value !== null) return
  const activity = routine.activities.find((candidate) => candidate.id === activityId)
  if (!activity) return
  actionActivityId.value = activityId
  error.value = null
  try {
    if (state === 'pending') {
      await clearRoutineActivityLog(routine.id, activityId, selectedDate.value)
    } else {
      await upsertRoutineActivityLog(routine.id, activityId, selectedDate.value, {
        status: state,
        ...(state === 'done' && activity.progress_total !== null
          ? { progress_value: progressDrafts.value[activityId] }
          : {}),
        note: noteDrafts.value[activityId] || null,
      })
    }
    await loadToday(selectedDate.value)
    statusMessage.value = i18n.t('today.activitySaved', { name: activity.name })
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('today.activityFailed')
  } finally {
    actionActivityId.value = null
    await nextTick()
    refocus(focusTarget)
  }
}

function loadSelectedDate(value: string | null): void {
  if (!value) {
    return
  }

  selectedDate.value = value
  // The date picker already restores focus when its surface closes. Re-focusing
  // after the request finishes can steal focus from the user's next control and
  // scroll a newly opened popup out of the viewport on mobile.
  void loadToday(value)
}

function retry(): void {
  void retryAction.value?.()
}

onMounted(() => loadToday())
onBeforeUnmount(() => { loadGeneration++ })
</script>

<template>
  <section class="view-stack today-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ selectedDate ? formatCalendarDate(selectedDate, locale) : i18n.t('nav.today') }}</p>
        <h1>{{ i18n.t('calm.myDay') }}</h1>
        <p class="muted today-intro">{{ i18n.t('calm.dayHint') }}</p>
      </div>
      <div class="today-date-tools">
        <button v-if="userToday && selectedDate !== userToday" type="button" class="secondary" :disabled="actionActivityId !== null || actionRoutineId !== null || savingSelection" @click="loadSelectedDate(userToday)">{{ i18n.t('nav.today') }}</button>
        <UiDatePicker ref="dateControl" :label="i18n.t('today.date')" name="today-date" :model-value="selectedDate || null" :locale="locale" :today="userToday" :disabled="isLoading || actionActivityId !== null || actionRoutineId !== null || savingSelection" :clearable="false" :placeholder="i18n.t('today.chooseDay')" @update:model-value="loadSelectedDate" />
      </div>
    </header>

    <nav v-if="selectedDate" class="day-strip" :aria-label="i18n.t('calm.week')">
      <button class="day-strip__arrow" type="button" :aria-label="i18n.t('calm.previousWeek')" :disabled="actionActivityId !== null || actionRoutineId !== null || savingSelection" @click="shiftWeek(-1)">‹</button>
      <button v-for="day in weekDays" :key="day.date" type="button" class="day-strip__day" :class="{ 'is-selected': day.date === selectedDate, 'is-today': day.date === userToday }" :aria-label="formatCalendarDate(day.date, locale)" :aria-pressed="day.date === selectedDate" :disabled="actionActivityId !== null || actionRoutineId !== null || savingSelection" @click="loadSelectedDate(day.date)">
        <span>{{ day.label }}</span><strong>{{ day.number }}</strong><span class="day-strip__dot" aria-hidden="true"></span>
      </button>
      <button class="day-strip__arrow" type="button" :aria-label="i18n.t('calm.nextWeek')" :disabled="actionActivityId !== null || actionRoutineId !== null || savingSelection" @click="shiftWeek(1)">›</button>
    </nav>

    <div v-if="error && data" class="notice error action-notice" role="alert"><span>{{ error }}</span><button v-if="retryAction" type="button" class="secondary" @click="retry">{{ i18n.t('common.retry') }}</button></div>
    <div v-if="statusMessage" class="notice success" role="status">{{ statusMessage }}</div>

    <div class="today-workspace">
      <div class="today-main">
        <TodayAgenda v-if="selectedDate" :date="selectedDate" />
        <section v-if="data" class="panel today-care" :aria-label="i18n.t('calm.care')">
          <h2>{{ i18n.t('calm.care') }}</h2>
          <div class="care-links">
            <RouterLink :to="`/nutrition?date=${selectedDate}`"><span class="care-icon"><AppIcon name="nutrition" /></span><span><strong>{{ i18n.t('nav.nutrition') }}</strong><small>{{ i18n.number(Number(data.module_summaries.nutrition.calories), { maximumFractionDigits: 0 }) }} kcal · {{ i18n.number(Number(data.module_summaries.nutrition.hydration_ml) / 1000, { maximumFractionDigits: 1 }) }} {{ i18n.t('calm.litres') }}</small></span><AppIcon name="arrow" /></RouterLink>
            <RouterLink :to="`/supplements?date=${selectedDate}`"><span class="care-icon"><AppIcon name="supplements" /></span><span><strong>{{ i18n.t('nav.supplements') }}</strong><small>{{ i18n.t('calm.taken', { count: data.module_summaries.supplements.done }) }} · {{ i18n.t('calm.remaining', { count: data.module_summaries.supplements.pending + data.module_summaries.supplements.overdue }) }}</small></span><AppIcon name="arrow" /></RouterLink>
            <RouterLink :to="`/workouts?date=${selectedDate}`"><span class="care-icon"><AppIcon name="workouts" /></span><span><strong>{{ i18n.t('nav.workouts') }}</strong><small>{{ i18n.t('calm.workoutsDone', { count: data.module_summaries.workouts.completed }) }}</small></span><AppIcon name="arrow" /></RouterLink>
            <RouterLink to="/routines"><span class="care-icon"><AppIcon name="moon" /></span><span><strong>{{ i18n.t('today.kind.sleep') }}</strong><small>{{ i18n.t('calm.logSleep') }}</small></span><AppIcon name="arrow" /></RouterLink>
          </div>
        </section>
        <section v-if="data" class="today-review">
          <span class="care-icon"><AppIcon name="review" /></span>
          <div><h2>{{ i18n.t('today.eveningReview') }}</h2><p class="muted">{{ i18n.t(data.review ? 'today.reviewSaved' : 'calm.reviewHint') }}</p></div>
          <RouterLink class="review-link" :to="`/review/${selectedDate}`">{{ i18n.t(data.review ? 'common.edit' : 'today.fillIn') }} <span aria-hidden="true">→</span></RouterLink>
        </section>
      </div>

      <div class="today-checkins">
        <AsyncState :loading="isLoading && !data" :error="data ? null : error" :loading-title="i18n.t('today.loading')" panel @retry="retry">
          <template v-if="data">
            <TodayHabits :habits="data.habits ?? []" :date="selectedDate" :today="userToday" @changed="habitChanged" />
            <section class="panel today-routines" :aria-label="i18n.t('today.routines')">
              <div class="section-heading"><h2>{{ i18n.t('today.routines') }}</h2><RouterLink to="/routines">{{ i18n.t('today.manage') }}</RouterLink></div>
              <p v-if="!data.routines.length" class="muted">{{ i18n.t('today.empty') }}</p>
              <div role="list">
              <details v-for="routine in data.routines" :key="`${selectedDate}:${routine.id}`" class="routine-fold" role="listitem" :aria-label="routine.name" :open="expandedRoutines[`${selectedDate}:${routine.id}`] ?? !routine.log" @toggle="expandedRoutines[`${selectedDate}:${routine.id}`] = ($event.target as HTMLDetailsElement).open">
                <summary>
                  <span class="routine-check" :class="{ 'is-done': routine.log?.status === 'done' }" aria-hidden="true">{{ routine.log?.status === 'done' ? '✓' : routine.log?.status === 'skipped' ? '−' : '' }}</span>
                  <span><strong>{{ routine.name }}</strong><small>{{ routine.preferred_time?.slice(0, 5) }} · {{ routine.activities.length ? i18n.t('today.activitiesResolved', { resolved: resolvedCount(routine), total: routine.activities.length }) : routineState(routine.log?.status ?? 'pending') }}<span v-if="routine.activities.length && routine.log"> · {{ routineState(routine.log.status) }}</span></small></span>
                  <span class="fold-chevron" aria-hidden="true">⌄</span>
                </summary>
                <div class="routine-fold__body">
                  <div v-if="!routine.activities.length" class="button-row" role="group" :aria-label="i18n.t('today.setState', { name: routine.name })">
                    <button type="button" :disabled="actionRoutineId !== null" :aria-pressed="routine.log?.status === 'done'" :aria-label="i18n.t('today.markDone', { name: routine.name })" @click="setRoutineState(routine, 'done', $event.currentTarget as HTMLElement)">{{ i18n.t('today.actionDone') }}</button>
                    <button type="button" class="secondary" :disabled="actionRoutineId !== null" :aria-pressed="routine.log?.status === 'skipped'" :aria-label="i18n.t('today.markSkipped', { name: routine.name })" @click="setRoutineState(routine, 'skipped', $event.currentTarget as HTMLElement)">{{ i18n.t('today.actionSkip') }}</button>
                    <button type="button" class="secondary" :disabled="actionRoutineId !== null" :aria-pressed="!routine.log" :aria-label="i18n.t('today.markPending', { name: routine.name })" @click="setRoutineState(routine, 'pending', $event.currentTarget as HTMLElement)">{{ i18n.t('daily.undo') }}</button>
                  </div>
                  <ul v-else class="routine-checklist">
                    <li v-for="activity in routine.activities" :key="activity.id" :aria-label="activity.name">
                      <div class="routine-checklist__line">
                        <button v-if="activity.progress_total === null" type="button" class="check-button" :class="{ 'is-checked': activity.selected_day_log?.status === 'done' }" :aria-pressed="activity.selected_day_log?.status === 'done'" :disabled="actionActivityId !== null" :aria-label="i18n.t(activity.selected_day_log?.status === 'done' ? 'today.setActivityPending' : 'today.markActivityDone', { name: activity.name })" @click="setActivityState(routine, activity.id, activity.selected_day_log?.status === 'done' ? 'pending' : 'done', $event.currentTarget as HTMLElement)"><span aria-hidden="true">{{ activity.selected_day_log?.status === 'done' ? '✓' : activity.selected_day_log?.status === 'skipped' ? '−' : '' }}</span></button>
                        <span class="routine-checklist__name" :class="{ 'is-done': activity.selected_day_log?.status === 'done' }">{{ activity.name }}</span>
                        <span v-if="activity.progress_total !== null" class="muted">{{ i18n.number(activity.selected_day_log?.progress_value ?? 0) }} / {{ i18n.number(activity.progress_total) }}</span>
                      </div>
                      <form v-if="activity.progress_total !== null" class="routine-progress" :aria-label="i18n.t('today.recordActivity', { name: activity.name })" @submit.prevent="setActivityState(routine, activity.id, 'done', $event.submitter as HTMLElement)">
                        <UiNumberInput v-model="progressDrafts[activity.id]" :label="i18n.t('today.progress')" :name="`activity-progress-${activity.id}`" :min="0" :max="activity.progress_total" :step="0.001" />
                        <button type="submit" class="secondary" :disabled="actionActivityId !== null" :aria-label="i18n.t('today.markActivityDone', { name: activity.name })">{{ i18n.t('today.actionDone') }}</button>
                      </form>
                      <details class="activity-more">
                        <summary :title="i18n.t('calm.noteOrSkip')"><span class="visually-hidden">{{ i18n.t('calm.noteOrSkip') }}</span><span aria-hidden="true">···</span></summary>
                        <UiTextarea v-model="noteDrafts[activity.id]" :label="i18n.t('today.activityNote', { name: activity.name })" :name="`activity-note-${activity.id}`" :rows="2" :maxlength="2000" />
                        <div class="button-row">
                          <button type="button" class="secondary" :disabled="actionActivityId !== null" @click="setActivityState(routine, activity.id, activity.selected_day_log?.status === 'skipped' ? 'skipped' : 'done', $event.currentTarget as HTMLElement)">{{ i18n.t('common.save') }}</button>
                          <button type="button" class="secondary" :disabled="actionActivityId !== null" :aria-label="i18n.t('today.markActivitySkipped', { name: activity.name })" @click="setActivityState(routine, activity.id, 'skipped', $event.currentTarget as HTMLElement)">{{ i18n.t('today.actionSkip') }}</button>
                          <button type="button" class="secondary" :disabled="actionActivityId !== null" :aria-label="i18n.t('today.setActivityPending', { name: activity.name })" @click="setActivityState(routine, activity.id, 'pending', $event.currentTarget as HTMLElement)">{{ i18n.t('daily.undo') }}</button>
                        </div>
                      </details>
                    </li>
                  </ul>
                  <p class="routine-context muted">{{ routineKind(routine.kind) }} · {{ streakLabel(routine.current_streak) }}<span v-if="routine.goals.length"> · {{ routine.goals.map(goal => goal.name).join(', ') }}</span></p>
                </div>
              </details>
              </div>
              <details class="routine-settings">
                <summary>{{ i18n.t('calm.chooseRoutines') }}</summary>
                <div class="form-grid">
                  <UiSelect :model-value="data.routine_day.morning.selected?.routine_id ?? null" :label="i18n.t('today.morningTemplate')" name="morning-template" :options="morningOptions" nullable :nullable-label="i18n.t('today.noMorningTemplate')" :disabled="savingSelection" @update:model-value="saveSelection('morning', $event)" />
                  <UiSelect :model-value="data.routine_day.evening.selected?.routine_id ?? null" :label="i18n.t('today.eveningTemplate')" name="evening-template" :options="eveningOptions" nullable :nullable-label="i18n.t('today.noEveningTemplate')" :disabled="savingSelection" @update:model-value="saveSelection('evening', $event)" />
                </div>
              </details>
            </section>
          </template>
        </AsyncState>
      </div>
    </div>
    <details v-if="data" class="today-insights">
        <summary>{{ i18n.t('daily.insights') }}</summary>
        <div class="view-stack">
      <section class="summary-grid daily-summary" :aria-label="i18n.t('today.dailySummary')">
        <div class="metric">
          <span>{{ i18n.t('summary.completion') }}</span>
          <strong>{{ completionLabel }}</strong>
          <div class="progress-track" role="progressbar" :aria-label="i18n.t('today.dailyCompletion')" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(data.summary.completion_rate)">
            <div class="progress-fill" :style="{ width: progressWidth }"></div>
          </div>
        </div>
        <div class="metric">
          <span>{{ i18n.t('summary.scheduled') }}</span>
          <strong>{{ data.summary.scheduled }}</strong>
        </div>
        <div class="metric">
          <span>{{ i18n.t('summary.done') }}</span>
          <strong>{{ data.summary.done }}</strong>
        </div>
        <div class="metric">
          <span>{{ i18n.t('today.skippedPending') }}</span>
          <strong>{{ data.summary.skipped }} / {{ data.summary.pending }}</strong>
        </div>
      </section>

      <p class="muted">{{ i18n.t('today.summaryScope') }}</p>
      <ProgressSummary :progress="data.progress" />

      <section class="panel" :aria-label="i18n.t('today.workoutSummary')">
        <div class="section-heading">
          <h2>{{ i18n.t('today.workoutSummary') }}</h2>
          <RouterLink to="/workouts">{{ i18n.t('today.manage') }}</RouterLink>
        </div>
        <p class="summary-value">{{ i18n.t('today.workoutPlanned', { count: data.module_summaries.workouts.planned }) }}</p>
        <p class="muted">{{ i18n.t('workouts.completed') }}: {{ data.module_summaries.workouts.completed }} · {{ i18n.t('workouts.distanceTotal') }}: {{ i18n.number(data.module_summaries.workouts.distance_m / 1000) }} km</p>
      </section>

      <section class="panel" :aria-label="i18n.t('today.nutritionSummary')">
        <div class="section-heading">
          <h2>{{ i18n.t('today.nutritionSummary') }}</h2>
          <RouterLink :to="`/nutrition?date=${selectedDate}`">{{ i18n.t('today.openNutrition') }}</RouterLink>
        </div>
        <p class="summary-value">{{ i18n.number(Number(data.module_summaries.nutrition.calories)) }} kcal</p>
        <p class="muted">{{ i18n.t('nutrition.hydration') }}: {{ i18n.number(Number(data.module_summaries.nutrition.hydration_ml)) }} ml · {{ i18n.t('nutrition.meals') }}: {{ data.module_summaries.nutrition.meal_count }}</p>
      </section>
      <section class="panel" :aria-label="i18n.t('today.supplementSummary')">
        <div class="section-heading">
          <h2>{{ i18n.t('today.supplementSummary') }}</h2>
          <RouterLink :to="`/supplements?date=${selectedDate}`">{{ i18n.t('today.openSupplements') }}</RouterLink>
        </div>
        <p class="summary-value">{{ data.module_summaries.supplements.adherence_percentage === null ? '—' : `${i18n.number(data.module_summaries.supplements.adherence_percentage)}%` }}</p>
        <p class="muted">{{ i18n.t('supplements.done') }}: {{ data.module_summaries.supplements.done }} · {{ i18n.t('supplements.pending') }}: {{ data.module_summaries.supplements.pending }} · {{ i18n.t('supplements.overdue') }}: {{ data.module_summaries.supplements.overdue }}</p>
      </section>

        </div>
      </details>
  </section>
</template>

<style scoped>
.today-intro { margin-top: 8px; }
.today-date-tools { display: flex; align-items: end; gap: 8px; }
.today-date-tools :deep(.ui-field) { width: 185px; }
.day-strip { display: grid; grid-template-columns: 36px repeat(7, minmax(0, 1fr)) 36px; gap: 8px; padding: 8px; background: var(--surface); border: 1px solid var(--border); border-radius: 16px; }
.day-strip button { background: transparent; color: var(--muted); border-radius: 10px; }
.day-strip__day { display: grid; justify-items: center; gap: 2px; padding: 6px; }
.day-strip__day > span:first-child { font-size: .78rem; font-weight: 500; text-transform: capitalize; }
.day-strip__day strong { font-size: 1.05rem; font-variant-numeric: tabular-nums; }
.day-strip__dot { width: 4px; height: 4px; border-radius: 50%; }
.day-strip__day.is-today .day-strip__dot { background: currentColor; }
.day-strip .is-selected { color: white; background: var(--accent); }
.day-strip button:hover:not(.is-selected) { background: var(--paper-soft); }
.day-strip__arrow { padding: 0; font-size: 1.5rem; }
.today-workspace { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr); align-items: start; gap: 22px; }
.today-main, .today-checkins { display: grid; align-content: start; gap: 22px; min-width: 0; }
.today-page :deep(.section-heading > a) { color: var(--accent); font-size: .83rem; text-decoration: none; }
.today-page :deep(.section-heading > a:hover) { text-decoration: underline; }
.care-links { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 18px; }
.care-links a { display: flex; align-items: center; gap: 10px; padding: 14px 0; text-decoration: none; min-width: 0; }
.care-links a:hover strong { color: var(--accent); }
.care-links a > span:nth-child(2) { min-width: 0; }
.care-links strong { display: block; font-size: .9rem; font-weight: 600; }
.care-links small { display: block; color: var(--subtle); font-size: .75rem; margin-top: 4px; }
.care-links a > .app-icon { margin-left: auto; width: 16px; flex-basis: 16px; color: var(--subtle); }
.care-icon { display: grid; place-items: center; flex: 0 0 34px; height: 34px; color: var(--accent); background: var(--accent-soft); border-radius: 10px; }
.today-review { display: flex; align-items: center; gap: 12px; padding: 6px 4px; }
.today-review h2 { font-size: .95rem; }
.today-review p { font-size: .8rem; margin-top: 3px; }
.review-link { margin-left: auto; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; color: var(--accent); text-decoration: none; font-size: .85rem; flex-shrink: 0; }
.routine-fold { border-top: 1px solid var(--border); }
.routine-fold > summary { display: flex; gap: 12px; align-items: center; min-height: 64px; cursor: pointer; list-style: none; }
.routine-fold > summary::-webkit-details-marker { display: none; }
.routine-fold > summary strong { display: block; font-size: .92rem; font-weight: 600; }
.routine-fold > summary small { display: block; font-size: .77rem; color: var(--subtle); margin-top: 3px; }
.fold-chevron { margin-left: auto; color: var(--subtle); }
.routine-fold[open] .fold-chevron { transform: rotate(180deg); }
.routine-check.is-done { color: var(--accent); background: var(--accent-soft); border: 1px solid var(--accent-border); }
.routine-fold__body { padding-bottom: 12px; }
.routine-checklist { list-style: none; padding: 0; margin: 0; }
.routine-checklist > li { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: start; padding: 5px 0; }
.routine-checklist__line { display: flex; align-items: center; gap: 8px; min-height: 42px; }
.routine-checklist__name { font-size: .9rem; }
.routine-checklist__name.is-done { color: var(--subtle); text-decoration: line-through; }
.check-button { width: 44px; min-height: 44px; flex: 0 0 44px; padding: 10px; color: var(--subtle); background: transparent; }
.check-button > span { width: 23px; height: 23px; display: grid; place-items: center; border: 1.5px solid var(--border-strong); border-radius: 7px; }
.check-button.is-checked > span { background: var(--accent); border-color: var(--accent); color: white; }
.activity-more { font-size: .8rem; }
.activity-more summary { display: grid; place-items: center; list-style: none; width: 40px; min-height: 42px; color: var(--subtle); cursor: pointer; font-size: 1.1rem; }
.activity-more summary::-webkit-details-marker { display: none; }
.activity-more[open] { grid-column: 1 / -1; width: 100%; padding-bottom: 12px; }
.activity-more .button-row { margin-top: 8px; }
.routine-progress { grid-column: 1 / -1; display: flex; align-items: end; gap: 8px; padding: 8px 0; }
.routine-progress > :first-child { flex: 1; }
.routine-context { margin-top: 10px; font-size: .75rem; }
.routine-settings { border-top: 1px solid var(--border); padding-top: 8px; }
.routine-settings summary { cursor: pointer; color: var(--subtle); font-size: .8rem; padding: 8px 0; }
.routine-settings .form-grid { margin-top: 10px; }
.today-insights > summary { cursor: pointer; color: var(--subtle); padding: 12px 0; font-size: .85rem; }
.today-insights[open] > summary { margin-bottom: 12px; }
.today-page .daily-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
@media (max-width: 1100px) { .care-links { grid-template-columns: 1fr; } }
@media (max-width: 900px) { .today-workspace { grid-template-columns: 1fr; } .care-links { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 760px) {
  .today-page { gap: 16px; }
  .today-date-tools { width: 100%; justify-content: flex-end; }
  .today-date-tools :deep(.ui-field) { width: auto; flex: 1; }
  .day-strip { grid-template-columns: 24px repeat(7, minmax(0, 1fr)) 24px; gap: 1px; padding: 5px; }
  .day-strip__day { padding: 5px 1px; min-width: 0; }
  .today-main, .today-checkins, .today-workspace { gap: 16px; }
  .today-review { flex-wrap: wrap; }
  .today-page .daily-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 380px) { .care-links { grid-template-columns: 1fr; } .today-review > div { flex: 1; } }
</style>
