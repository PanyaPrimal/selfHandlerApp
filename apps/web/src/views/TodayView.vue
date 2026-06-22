<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { getToday, updateRoutineLog } from '../api/client'
import type { RoutineLog, TodayResponse, TodayRoutine } from '../api/types'

const today = new Date().toISOString().slice(0, 10)
const selectedDate = ref(today)
const data = ref<TodayResponse | null>(null)
const isLoading = ref(false)
const error = ref<string | null>(null)

const completionLabel = computed(() => {
  if (!data.value) {
    return '0%'
  }

  return `${Math.round(data.value.summary.completion_rate)}%`
})

const progressWidth = computed(() => `${data.value?.summary.completion_rate ?? 0}%`)

async function loadToday(): Promise<void> {
  isLoading.value = true
  error.value = null

  try {
    data.value = await getToday(selectedDate.value)
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to load Today'
  } finally {
    isLoading.value = false
  }
}

function markLocalRoutine(routineId: number, status: RoutineLog['status']): void {
  if (!data.value) {
    return
  }

  data.value.routines = data.value.routines.map((routine) => {
    if (routine.id !== routineId) {
      return routine
    }

    return {
      ...routine,
      log: {
        id: routine.log?.id ?? 0,
        routine_id: routine.id,
        log_date: selectedDate.value,
        status,
        note: routine.log?.note ?? null,
        completed_at: status === 'done' ? new Date().toISOString() : null,
      },
    }
  })
}

async function markRoutine(routine: TodayRoutine, status: RoutineLog['status']): Promise<void> {
  const previousData = data.value ? JSON.parse(JSON.stringify(data.value)) as TodayResponse : null
  markLocalRoutine(routine.id, status)
  error.value = null

  try {
    await updateRoutineLog(routine.id, selectedDate.value, status)
    await loadToday()
  } catch (currentError) {
    data.value = previousData
    error.value = currentError instanceof Error ? currentError.message : 'Failed to update routine'
  }
}

onMounted(loadToday)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ selectedDate }}</p>
        <h1>Good evening, Alex</h1>
      </div>

      <label class="field compact-field">
        <span>Date</span>
        <input v-model="selectedDate" type="date" @change="loadToday" />
      </label>
    </header>

    <div v-if="error" class="notice error">{{ error }}</div>

    <template v-if="data">
      <section class="summary-grid">
        <div class="metric">
          <span>Completion</span>
          <strong>{{ completionLabel }}</strong>
          <div class="progress-track" aria-hidden="true">
            <div class="progress-fill" :style="{ width: progressWidth }"></div>
          </div>
        </div>
        <div class="metric">
          <span>Done</span>
          <strong>{{ data.summary.done }}/{{ data.summary.scheduled }}</strong>
        </div>
        <div class="metric">
          <span>Handled</span>
          <strong>{{ data.summary.done + data.summary.skipped }}/{{ data.summary.scheduled }}</strong>
        </div>
      </section>

      <section class="panel">
        <div class="section-heading">
          <h2>Routines</h2>
          <RouterLink to="/routines">Manage</RouterLink>
        </div>

        <div v-if="data.routines.length === 0" class="state-block">
          <div class="state-icon" aria-hidden="true"></div>
          <h3>No routines yet</h3>
          <p class="muted">Add your first routine and it'll show up here every day.</p>
          <RouterLink to="/routines">New routine</RouterLink>
        </div>

        <ul v-else class="item-list">
          <li
            v-for="routine in data.routines"
            :key="routine.id"
            class="routine-row"
            :class="{
              'is-done': routine.log?.status === 'done',
              'is-skipped': routine.log?.status === 'skipped',
            }"
          >
            <button class="routine-main ghost" type="button" @click="markRoutine(routine, 'done')">
              <span class="routine-check" aria-hidden="true">
                {{ routine.log?.status === 'done' ? '✓' : routine.log?.status === 'skipped' ? '-' : '' }}
              </span>
              <span>
                <strong class="routine-title">{{ routine.name }}</strong>
                <span class="routine-meta">
                  <span v-if="routine.preferred_time" class="mono">{{ routine.preferred_time }}</span>
                  <span>{{ routine.kind }}</span>
                  <span v-if="routine.log">marked {{ routine.log.status }}</span>
                </span>
                <span v-if="routine.goals.length > 0" class="goal-chip-list">
                  <span v-for="goal in routine.goals" :key="goal.id" class="goal-chip">{{ goal.name }}</span>
                </span>
              </span>
            </button>

            <div class="button-row">
              <button type="button" class="secondary" @click="markRoutine(routine, 'skipped')">Skip</button>
            </div>
          </li>
        </ul>
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

    <section v-else-if="isLoading" class="panel">
      <div class="skeleton-line" style="width: 44%"></div>
      <div class="skeleton-line" style="width: 90%"></div>
      <div class="skeleton-line" style="width: 75%"></div>
    </section>
  </section>
</template>
