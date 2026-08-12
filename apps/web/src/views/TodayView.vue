<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { clearRoutineLog, getToday, updateRoutineLog } from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import ProgressSummary from '../components/ProgressSummary.vue'
import { formatCalendarDate } from '../lib/format'
import { useAuthSession } from '../auth/session'
import { UiDatePicker } from '../components/ui'
import type { RoutineLog, TodayResponse, TodayRoutine } from '../api/types'
import { useI18n } from '../i18n'

type RoutineState = RoutineLog['status'] | 'pending'

const selectedDate = ref('')
const data = ref<TodayResponse | null>(null)
const isLoading = ref(true)
const actionRoutineId = ref<number | null>(null)
const error = ref<string | null>(null)
const statusMessage = ref<string | null>(null)
const retryAction = ref<(() => Promise<void>) | null>(null)
const dateControl = ref<{ focus: () => void } | null>(null)
// The user's real current day, resolved by the API from their profile time zone.
// It is captured on the first unparameterised load and only marks the calendar;
// it never becomes a value on its own.
const userToday = ref<string | null>(null)
const session = useAuthSession()
const i18n = useI18n()
const locale = i18n.locale
const displayName = computed(() => session.user?.name ?? i18n.t('today.there'))

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

type FocusTarget = HTMLElement | { focus: () => void }

function refocus(target: FocusTarget | null | undefined): void {
  if (!target) {
    return
  }

  if (target instanceof HTMLElement && !target.isConnected) {
    return
  }

  target.focus()
}

async function loadToday(date?: string, focusTarget?: FocusTarget | null): Promise<void> {
  isLoading.value = true
  error.value = null
  statusMessage.value = null
  retryAction.value = null

  if (date && data.value?.date !== date) {
    data.value = null
  }

  try {
    const response = await getToday(date)
    data.value = response
    selectedDate.value = response.date

    if (!date) {
      userToday.value = response.date
    }
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('today.loadFailed')
    retryAction.value = () => loadToday(date, focusTarget ?? dateControl.value)
  } finally {
    isLoading.value = false
    await nextTick()

    refocus(focusTarget)
  }
}

function cloneToday(value: TodayResponse): TodayResponse {
  return JSON.parse(JSON.stringify(value)) as TodayResponse
}

function recalculateSummary(): void {
  if (!data.value) {
    return
  }

  const scheduled = data.value.routines.length
  const done = data.value.routines.filter((routine) => routine.log?.status === 'done').length
  const skipped = data.value.routines.filter((routine) => routine.log?.status === 'skipped').length

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

function loadSelectedDate(value: string | null): void {
  if (!value) {
    return
  }

  selectedDate.value = value
  void loadToday(value, dateControl.value)
}

function retry(): void {
  void retryAction.value?.()
}

onMounted(() => loadToday())
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ selectedDate ? formatCalendarDate(selectedDate, locale) : i18n.t('nav.today') }}</p>
        <h1>{{ i18n.t('today.greeting', { name: displayName }) }}</h1>
      </div>

      <div class="compact-field">
        <UiDatePicker
          ref="dateControl"
          :label="i18n.t('today.date')"
          name="today-date"
          :model-value="selectedDate || null"
          :locale="locale"
          :today="userToday"
          :disabled="isLoading"
          :clearable="false"
          :placeholder="i18n.t('today.chooseDay')"
          @update:model-value="loadSelectedDate"
        />
      </div>
    </header>

    <div v-if="error && data" class="notice error action-notice" role="alert">
      <span>{{ error }}</span>
      <button v-if="retryAction" type="button" class="secondary" @click="retry">{{ i18n.t('common.retry') }}</button>
    </div>
    <div v-if="statusMessage" class="notice success" role="status">{{ statusMessage }}</div>

    <AsyncState
      :loading="isLoading && !data"
      :error="data ? null : error"
      :loading-title="i18n.t('today.loading')"
      :loading-aria-label="i18n.t('today.loading')"
      panel
      @retry="retry"
    >
      <template #loading>
        <p class="muted">{{ i18n.t('today.loading') }}</p>
        <div class="skeleton-line" style="width: 44%"></div>
        <div class="skeleton-line" style="width: 90%"></div>
        <div class="skeleton-line" style="width: 75%"></div>
      </template>

      <template v-if="data">
      <p v-if="isLoading" class="muted" role="status">{{ i18n.t('today.loadingDate') }}</p>

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

      <ProgressSummary :progress="data.progress" />

      <section class="panel">
        <div class="section-heading">
          <h2>{{ i18n.t('today.routines') }}</h2>
          <RouterLink to="/routines">{{ i18n.t('today.manage') }}</RouterLink>
        </div>

        <AsyncState
          :empty="data.routines.length === 0"
          :empty-title="i18n.t('today.empty')"
          :empty-description="i18n.t('today.emptyBody')"
          show-empty-icon
        >
          <template #empty>
            <div class="state-icon" aria-hidden="true"></div>
            <h3>{{ i18n.t('today.empty') }}</h3>
            <p class="muted">{{ i18n.t('today.emptyBody') }}</p>
            <RouterLink to="/routines">{{ i18n.t('today.manageRoutines') }}</RouterLink>
          </template>

          <ul class="item-list">
          <li
            v-for="routine in data.routines"
            :key="routine.id"
            class="routine-row"
            :aria-label="routine.name"
            :class="{
              'is-done': routine.log?.status === 'done',
              'is-skipped': routine.log?.status === 'skipped',
            }"
          >
            <div class="routine-main">
              <span class="routine-check" aria-hidden="true">
                {{ routine.log?.status === 'done' ? '✓' : routine.log?.status === 'skipped' ? '−' : '' }}
              </span>
              <span>
                <strong class="routine-title">{{ routine.name }}</strong>
                <span class="routine-meta">
                  <span v-if="routine.preferred_time" class="mono">{{ routine.preferred_time.slice(0, 5) }}</span>
                  <span>{{ routineKind(routine.kind) }}</span>
                  <span>{{ routineState(routine.log?.status ?? 'pending') }}</span>
                  <span
                    class="streak-badge"
                    :aria-label="i18n.t('today.currentStreak', { streak: streakLabel(routine.current_streak) })"
                  >{{ streakLabel(routine.current_streak) }}</span>
                </span>
                <span v-if="routine.goals.length > 0" class="goal-chip-list">
                  <span v-for="goal in routine.goals" :key="goal.id" class="goal-chip">{{ goal.name }}</span>
                </span>
              </span>
            </div>

            <div class="button-row state-actions" role="group" :aria-label="i18n.t('today.setState', { name: routine.name })">
              <button
                type="button"
                class="secondary"
                :aria-label="i18n.t('today.markDone', { name: routine.name })"
                :class="{ selected: routine.log?.status === 'done' }"
                :aria-pressed="routine.log?.status === 'done'"
                :disabled="actionRoutineId !== null"
                @click="setRoutineState(routine, 'done', $event.currentTarget as HTMLElement)"
              >{{ i18n.t('today.actionDone') }}</button>
              <button
                type="button"
                class="secondary"
                :aria-label="i18n.t('today.markSkipped', { name: routine.name })"
                :class="{ selected: routine.log?.status === 'skipped' }"
                :aria-pressed="routine.log?.status === 'skipped'"
                :disabled="actionRoutineId !== null"
                @click="setRoutineState(routine, 'skipped', $event.currentTarget as HTMLElement)"
              >{{ i18n.t('today.actionSkip') }}</button>
              <button
                type="button"
                class="secondary"
                :aria-label="i18n.t('today.markPending', { name: routine.name })"
                :class="{ selected: !routine.log }"
                :aria-pressed="!routine.log"
                :disabled="actionRoutineId !== null"
                @click="setRoutineState(routine, 'pending', $event.currentTarget as HTMLElement)"
              >{{ i18n.t('today.actionPending') }}</button>
            </div>
            </li>
          </ul>
        </AsyncState>
      </section>

      <section class="panel">
        <div class="section-heading">
          <h2>{{ i18n.t('today.eveningReview') }}</h2>
          <RouterLink :to="`/review/${selectedDate}`">{{ data.review ? i18n.t('common.edit') : i18n.t('today.fillIn') }}</RouterLink>
        </div>
        <p class="muted">
          {{ data.review ? i18n.t('today.reviewSaved') : i18n.t('today.noReview') }}
        </p>
      </section>
      </template>
    </AsyncState>
  </section>
</template>
