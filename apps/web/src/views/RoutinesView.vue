<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { createRoutine, getRoutines } from '../api/client'
import type { Routine, RoutinePayload } from '../api/types'

const routines = ref<Routine[]>([])
const isLoading = ref(false)
const error = ref<string | null>(null)
const form = reactive<RoutinePayload>({
  name: '',
  kind: 'routine',
  schedule_type: 'daily',
  weekdays: [],
})
const weekdayOptions = [
  ['MO', 'Mon'],
  ['TU', 'Tue'],
  ['WE', 'Wed'],
  ['TH', 'Thu'],
  ['FR', 'Fri'],
  ['SA', 'Sat'],
  ['SU', 'Sun'],
] as const

async function loadRoutines(): Promise<void> {
  isLoading.value = true
  error.value = null

  try {
    routines.value = await getRoutines()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to load routines'
  } finally {
    isLoading.value = false
  }
}

async function submitRoutine(): Promise<void> {
  await createRoutine({
    ...form,
    weekdays: form.schedule_type === 'weekdays' ? form.weekdays : null,
  })

  form.name = ''
  form.weekdays = []
  await loadRoutines()
}

function toggleWeekday(day: string): void {
  const selected = new Set(form.weekdays ?? [])

  if (selected.has(day)) {
    selected.delete(day)
  } else {
    selected.add(day)
  }

  form.weekdays = [...selected]
}

onMounted(loadRoutines)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">Routines</p>
        <h1>Repeatable actions</h1>
      </div>
    </header>

    <div v-if="error" class="notice error">{{ error }}</div>

    <section class="panel">
      <h2>Create routine</h2>
      <form class="form-grid" @submit.prevent="submitRoutine">
        <label class="field">
          <span>Name</span>
          <input v-model="form.name" required placeholder="Morning walk" />
        </label>

        <label class="field">
          <span>Schedule</span>
          <select v-model="form.schedule_type">
            <option value="daily">Daily</option>
            <option value="weekdays">By weekdays</option>
          </select>
        </label>

        <div v-if="form.schedule_type === 'weekdays'" class="field wide-field">
          <span>Weekdays</span>
          <div class="segmented-list">
            <button
              v-for="[value, label] in weekdayOptions"
              :key="value"
              type="button"
              class="secondary"
              :class="{ selected: form.weekdays?.includes(value) }"
              @click="toggleWeekday(value)"
            >
              {{ label }}
            </button>
          </div>
        </div>

        <button type="submit">Create</button>
      </form>
    </section>

    <section class="panel">
      <h2>Current routines</h2>
      <p v-if="isLoading" class="muted">Loading routines...</p>
      <p v-else-if="routines.length === 0" class="muted">No routines yet.</p>

      <ul v-else class="item-list">
        <li v-for="routine in routines" :key="routine.id">
          <div class="meta-row">
            <strong>{{ routine.name }}</strong>
            <span class="kind-chip">{{ routine.kind }}</span>
          </div>
          <p class="muted">
            {{ routine.schedule_type }}
            <span v-if="routine.weekdays?.length"> · {{ routine.weekdays.join(', ') }}</span>
          </p>
        </li>
      </ul>
    </section>
  </section>
</template>
