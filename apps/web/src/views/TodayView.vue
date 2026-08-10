<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { clearRoutineLog, getToday, updateRoutineLog } from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import ProgressSummary from '../components/ProgressSummary.vue'
import { formatCalendarDate } from '../lib/format'
import { useAuthSession } from '../auth/session'
import type { RoutineLog, TodayResponse, TodayRoutine } from '../api/types'

type RoutineState = RoutineLog['status'] | 'pending'

const selectedDate = ref('')
const data = ref<TodayResponse | null>(null)
const isLoading = ref(true)
const actionRoutineId = ref<number | null>(null)
const error = ref<string | null>(null)
const statusMessage = ref<string | null>(null)
const retryAction = ref<(() => Promise<void>) | null>(null)
const dateInput = ref<HTMLInputElement | null>(null)
const session = useAuthSession()
const displayName = computed(() => session.user?.name ?? 'there')

const completionLabel = computed(() => `${Math.round(data.value?.summary.completion_rate ?? 0)}%`)
const progressWidth = computed(() => `${data.value?.summary.completion_rate ?? 0}%`)

async function loadToday(date?: string, focusTarget?: HTMLElement | null): Promise<void> {
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
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to load Today.'
    retryAction.value = () => loadToday(date, focusTarget ?? dateInput.value)
  } finally {
    isLoading.value = false
    await nextTick()

    if (focusTarget?.isConnected) {
      focusTarget.focus()
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
    statusMessage.value = `${routine.name} is ${state}.`
  } catch (currentError) {
    data.value = previousData
    error.value = currentError instanceof Error ? currentError.message : 'Failed to update the routine.'
    retryAction.value = () => setRoutineState(routine, state, focusTarget)
  } finally {
    actionRoutineId.value = null
    await nextTick()

    if (focusTarget?.isConnected) {
      focusTarget.focus()
    }
  }
}

function loadSelectedDate(): void {
  void loadToday(selectedDate.value, dateInput.value)
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
        <p class="eyebrow">{{ selectedDate ? formatCalendarDate(selectedDate) : 'Today' }}</p>
        <h1>Good evening, {{ displayName }}</h1>
      </div>

      <label class="field compact-field">
        <span>Date</span>
        <input ref="dateInput" v-model="selectedDate" type="date" :disabled="isLoading" @change="loadSelectedDate" />
      </label>
    </header>

    <div v-if="error && data" class="notice error action-notice" role="alert">
      <span>{{ error }}</span>
      <button v-if="retryAction" type="button" class="secondary" @click="retry">Retry</button>
    </div>
    <div v-if="statusMessage" class="notice success" role="status">{{ statusMessage }}</div>

    <AsyncState
      :loading="isLoading && !data"
      :error="data ? null : error"
      loading-title="Loading Today…"
      loading-aria-label="Loading Today"
      panel
      @retry="retry"
    >
      <template #loading>
        <p class="muted">Loading Today…</p>
        <div class="skeleton-line" style="width: 44%"></div>
        <div class="skeleton-line" style="width: 90%"></div>
        <div class="skeleton-line" style="width: 75%"></div>
      </template>

      <template v-if="data">
      <p v-if="isLoading" class="muted" role="status">Loading selected date…</p>

      <section class="summary-grid daily-summary" aria-label="Daily completion summary">
        <div class="metric">
          <span>Completion</span>
          <strong>{{ completionLabel }}</strong>
          <div class="progress-track" role="progressbar" aria-label="Daily completion" aria-valuemin="0" aria-valuemax="100" :aria-valuenow="Math.round(data.summary.completion_rate)">
            <div class="progress-fill" :style="{ width: progressWidth }"></div>
          </div>
        </div>
        <div class="metric">
          <span>Scheduled</span>
          <strong>{{ data.summary.scheduled }}</strong>
        </div>
        <div class="metric">
          <span>Done</span>
          <strong>{{ data.summary.done }}</strong>
        </div>
        <div class="metric">
          <span>Skipped / pending</span>
          <strong>{{ data.summary.skipped }} / {{ data.summary.pending }}</strong>
        </div>
      </section>

      <ProgressSummary :progress="data.progress" />

      <section class="panel">
        <div class="section-heading">
          <h2>Routines</h2>
          <RouterLink to="/routines">Manage</RouterLink>
        </div>

        <AsyncState
          :empty="data.routines.length === 0"
          empty-title="No routines scheduled"
          empty-description="There is nothing planned for this date."
          show-empty-icon
        >
          <template #empty>
            <div class="state-icon" aria-hidden="true"></div>
            <h3>No routines scheduled</h3>
            <p class="muted">There is nothing planned for this date.</p>
            <RouterLink to="/routines">Manage routines</RouterLink>
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
                  <span>{{ routine.kind }}</span>
                  <span>{{ routine.log?.status ?? 'pending' }}</span>
                  <span
                    class="streak-badge"
                    :aria-label="`Current streak: ${routine.current_streak} days`"
                  >{{ routine.current_streak }}-day streak</span>
                </span>
                <span v-if="routine.goals.length > 0" class="goal-chip-list">
                  <span v-for="goal in routine.goals" :key="goal.id" class="goal-chip">{{ goal.name }}</span>
                </span>
              </span>
            </div>

            <div class="button-row state-actions" role="group" :aria-label="`Set ${routine.name} state`">
              <button
                type="button"
                class="secondary"
                :aria-label="`Mark ${routine.name} done`"
                :class="{ selected: routine.log?.status === 'done' }"
                :aria-pressed="routine.log?.status === 'done'"
                :disabled="actionRoutineId !== null"
                @click="setRoutineState(routine, 'done', $event.currentTarget as HTMLElement)"
              >Done</button>
              <button
                type="button"
                class="secondary"
                :aria-label="`Mark ${routine.name} skipped`"
                :class="{ selected: routine.log?.status === 'skipped' }"
                :aria-pressed="routine.log?.status === 'skipped'"
                :disabled="actionRoutineId !== null"
                @click="setRoutineState(routine, 'skipped', $event.currentTarget as HTMLElement)"
              >Skip</button>
              <button
                type="button"
                class="secondary"
                :aria-label="`Set ${routine.name} to pending`"
                :class="{ selected: !routine.log }"
                :aria-pressed="!routine.log"
                :disabled="actionRoutineId !== null"
                @click="setRoutineState(routine, 'pending', $event.currentTarget as HTMLElement)"
              >Pending</button>
            </div>
            </li>
          </ul>
        </AsyncState>
      </section>

      <section class="panel">
        <div class="section-heading">
          <h2>Evening review</h2>
          <RouterLink :to="`/review/${selectedDate}`">{{ data.review ? 'Edit' : 'Fill in' }}</RouterLink>
        </div>
        <p class="muted">
          {{ data.review ? 'Review saved for this date.' : 'No review yet.' }}
        </p>
      </section>
      </template>
    </AsyncState>
  </section>
</template>
