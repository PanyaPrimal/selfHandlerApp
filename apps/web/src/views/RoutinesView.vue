<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import {
  archiveRoutine,
  createRoutine,
  getRoutines,
  restoreRoutine,
  updateRoutine,
  validationErrors,
} from '../api/client'
import type { Routine, RoutineCreatePayload, RoutineUpdatePayload, Weekday } from '../api/types'
import type { ValidationErrors } from '../api/client'

interface RoutineForm {
  name: string
  description: string
  kind: Routine['kind']
  schedule_type: Routine['schedule_type']
  weekdays: Weekday[]
  preferred_time: string
  sort_order: number
  is_active: boolean
  starts_on: string
  ends_on: string
}

const routines = ref<Routine[]>([])
const archivedView = ref(false)
const editingId = ref<number | null>(null)
const isLoading = ref(false)
const loadFailed = ref(false)
const isSubmitting = ref(false)
const actionRoutineId = ref<number | null>(null)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const form = reactive<RoutineForm>(emptyForm())
const weekdayOptions = [
  ['MO', 'Mon'],
  ['TU', 'Tue'],
  ['WE', 'Wed'],
  ['TH', 'Thu'],
  ['FR', 'Fri'],
  ['SA', 'Sat'],
  ['SU', 'Sun'],
] as const

function emptyForm(): RoutineForm {
  return {
    name: '',
    description: '',
    kind: 'routine',
    schedule_type: 'daily',
    weekdays: [],
    preferred_time: '',
    sort_order: 0,
    is_active: true,
    starts_on: '',
    ends_on: '',
  }
}

async function loadRoutines(): Promise<void> {
  isLoading.value = true
  loadFailed.value = false
  error.value = null

  try {
    routines.value = await getRoutines(archivedView.value)
  } catch (currentError) {
    loadFailed.value = true
    error.value = currentError instanceof Error ? currentError.message : 'Failed to load routines.'
  } finally {
    isLoading.value = false
  }
}

function routinePayload(): RoutineCreatePayload {
  return {
    name: form.name,
    description: form.description || null,
    kind: form.kind,
    schedule_type: form.schedule_type,
    ...(form.schedule_type === 'weekdays' ? { weekdays: form.weekdays } : {}),
    preferred_time: form.preferred_time || null,
    sort_order: form.sort_order,
    is_active: form.is_active,
    starts_on: form.starts_on || null,
    ends_on: form.ends_on || null,
  }
}

async function submitRoutine(): Promise<void> {
  error.value = null
  success.value = null
  fieldErrors.value = {}

  if (form.schedule_type === 'weekdays' && form.weekdays.length === 0) {
    fieldErrors.value = { weekdays: ['Choose at least one weekday.'] }
    return
  }

  isSubmitting.value = true

  try {
    const payload = routinePayload()

    if (editingId.value === null) {
      await createRoutine(payload)
      success.value = 'Routine created.'
    } else {
      await updateRoutine(editingId.value, payload as RoutineUpdatePayload)
      success.value = 'Routine updated.'
    }

    resetForm()
    await loadRoutines()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = currentError instanceof Error ? currentError.message : 'Failed to save the routine.'
  } finally {
    isSubmitting.value = false
  }
}

function editRoutine(routine: Routine): void {
  editingId.value = routine.id
  Object.assign(form, {
    name: routine.name,
    description: routine.description ?? '',
    kind: routine.kind,
    schedule_type: routine.schedule_type,
    weekdays: [...routine.weekdays],
    preferred_time: routine.preferred_time?.slice(0, 5) ?? '',
    sort_order: routine.sort_order,
    is_active: routine.is_active,
    starts_on: routine.starts_on ?? '',
    ends_on: routine.ends_on ?? '',
  })
  fieldErrors.value = {}
  error.value = null
  success.value = null
  window.scrollTo({ top: 0, behavior: 'smooth' })
}

function resetForm(): void {
  editingId.value = null
  Object.assign(form, emptyForm())
  fieldErrors.value = {}
}

async function switchArchiveView(archived: boolean): Promise<void> {
  if (archivedView.value === archived) {
    return
  }

  archivedView.value = archived
  resetForm()
  success.value = null
  await loadRoutines()
}

async function setArchived(routine: Routine): Promise<void> {
  actionRoutineId.value = routine.id
  error.value = null
  success.value = null

  try {
    if (routine.is_archived) {
      await restoreRoutine(routine.id)
      success.value = 'Routine restored.'
    } else {
      await archiveRoutine(routine.id)
      success.value = 'Routine archived.'
    }

    if (editingId.value === routine.id) {
      resetForm()
    }

    await loadRoutines()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to change the archive state.'
  } finally {
    actionRoutineId.value = null
  }
}

async function toggleActive(routine: Routine): Promise<void> {
  actionRoutineId.value = routine.id
  error.value = null
  success.value = null

  try {
    await updateRoutine(routine.id, { is_active: !routine.is_active })
    success.value = routine.is_active ? 'Routine paused.' : 'Routine resumed.'
    await loadRoutines()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to change the routine state.'
  } finally {
    actionRoutineId.value = null
  }
}

function toggleWeekday(day: Weekday): void {
  const selected = new Set(form.weekdays)

  if (selected.has(day)) {
    selected.delete(day)
  } else {
    selected.add(day)
  }

  form.weekdays = weekdayOptions
    .map(([value]) => value)
    .filter((value) => selected.has(value))
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

    <div v-if="error" class="notice error" role="alert">
      {{ error }}
      <button v-if="loadFailed" type="button" class="secondary inline-action" @click="loadRoutines">
        Retry
      </button>
    </div>
    <div v-if="success" class="notice success" role="status">{{ success }}</div>

    <section class="panel" aria-labelledby="routine-form-heading">
      <div class="section-heading">
        <h2 id="routine-form-heading">{{ editingId === null ? 'Create routine' : 'Edit routine' }}</h2>
        <button v-if="editingId !== null" type="button" class="secondary" @click="resetForm">Cancel edit</button>
      </div>

      <form class="form-grid" :aria-label="editingId === null ? 'Create routine' : 'Edit routine'" novalidate @submit.prevent="submitRoutine">
        <label class="field">
          <span>Name</span>
          <input v-model="form.name" required maxlength="160" placeholder="Morning walk" />
          <span v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name[0] }}</span>
        </label>

        <label class="field">
          <span>Kind</span>
          <select v-model="form.kind">
            <option value="routine">Routine</option>
            <option value="habit">Habit</option>
            <option value="sleep">Sleep</option>
          </select>
        </label>

        <label class="field wide-field">
          <span>Description</span>
          <textarea v-model="form.description" rows="2" maxlength="2000" placeholder="Optional context"></textarea>
          <span v-if="fieldErrors.description" class="field-error">{{ fieldErrors.description[0] }}</span>
        </label>

        <label class="field">
          <span>Schedule</span>
          <select v-model="form.schedule_type">
            <option value="daily">Daily</option>
            <option value="weekdays">By weekdays</option>
          </select>
          <span v-if="fieldErrors.schedule_type" class="field-error">{{ fieldErrors.schedule_type[0] }}</span>
        </label>

        <label class="field">
          <span>Preferred time</span>
          <input v-model="form.preferred_time" type="time" />
        </label>

        <div v-if="form.schedule_type === 'weekdays'" class="field wide-field">
          <span>Weekdays</span>
          <div class="segmented-list" aria-label="Weekdays">
            <button
              v-for="[value, label] in weekdayOptions"
              :key="value"
              type="button"
              class="secondary"
              :class="{ selected: form.weekdays.includes(value) }"
              :aria-pressed="form.weekdays.includes(value)"
              @click="toggleWeekday(value)"
            >
              {{ label }}
            </button>
          </div>
          <span v-if="fieldErrors.weekdays" class="field-error">{{ fieldErrors.weekdays[0] }}</span>
          <span class="helper-text">Schedule fields lock after the first daily result.</span>
        </div>

        <label class="field">
          <span>Starts on</span>
          <input v-model="form.starts_on" type="date" />
          <span v-if="fieldErrors.starts_on" class="field-error">{{ fieldErrors.starts_on[0] }}</span>
        </label>

        <label class="field">
          <span>Ends on</span>
          <input v-model="form.ends_on" type="date" />
          <span v-if="fieldErrors.ends_on" class="field-error">{{ fieldErrors.ends_on[0] }}</span>
        </label>

        <label class="field">
          <span>Display order</span>
          <input v-model.number="form.sort_order" type="number" min="0" step="1" />
        </label>

        <label class="checkbox-field">
          <input v-model="form.is_active" type="checkbox" />
          <span>Active in planning</span>
        </label>

        <div class="form-actions wide-field">
          <button type="submit" :disabled="isSubmitting">
            {{ isSubmitting ? 'Saving…' : editingId === null ? 'Create routine' : 'Save changes' }}
          </button>
        </div>
      </form>
    </section>

    <section class="panel" :aria-labelledby="archivedView ? 'archived-routines-heading' : 'current-routines-heading'">
      <div class="section-heading archive-heading">
        <h2 :id="archivedView ? 'archived-routines-heading' : 'current-routines-heading'">{{ archivedView ? 'Archived routines' : 'Current routines' }}</h2>
        <div class="segmented-list" aria-label="Routine archive filter">
          <button type="button" class="secondary" :class="{ selected: !archivedView }" :aria-pressed="!archivedView" @click="switchArchiveView(false)">
            Current
          </button>
          <button type="button" class="secondary" :class="{ selected: archivedView }" :aria-pressed="archivedView" @click="switchArchiveView(true)">
            Archived
          </button>
        </div>
      </div>

      <p v-if="isLoading" class="muted" role="status">Loading routines…</p>
      <div v-else-if="routines.length === 0" class="state-block">
        <div class="state-icon" aria-hidden="true"></div>
        <h3>{{ archivedView ? 'No archived routines' : 'No routines yet' }}</h3>
        <p class="muted">{{ archivedView ? 'Archived routines will remain available here.' : 'Create a routine to start the daily loop.' }}</p>
      </div>

      <ul v-else class="item-list">
        <li v-for="routine in routines" :key="routine.id" class="management-row" :aria-label="routine.name">
          <div class="management-copy">
            <div class="meta-row">
              <strong>{{ routine.name }}</strong>
              <span class="kind-chip">{{ routine.kind }}</span>
              <span v-if="!routine.is_active" class="kind-chip">paused</span>
            </div>
            <p v-if="routine.description" class="muted">{{ routine.description }}</p>
            <p class="muted">
              {{ routine.schedule_type === 'daily' ? 'Daily' : routine.weekdays.join(', ') }}
              <span v-if="routine.preferred_time"> · {{ routine.preferred_time.slice(0, 5) }}</span>
              · order {{ routine.sort_order }}
            </p>
          </div>

          <div class="button-row management-actions">
            <button type="button" class="secondary" :aria-label="`Edit ${routine.name}`" :disabled="actionRoutineId === routine.id" @click="editRoutine(routine)">Edit</button>
            <button v-if="!routine.is_archived" type="button" class="secondary" :aria-label="`${routine.is_active ? 'Pause' : 'Resume'} ${routine.name}`" :disabled="actionRoutineId === routine.id" @click="toggleActive(routine)">
              {{ routine.is_active ? 'Pause' : 'Resume' }}
            </button>
            <button type="button" class="secondary" :aria-label="`${routine.is_archived ? 'Restore' : 'Archive'} ${routine.name}`" :disabled="actionRoutineId === routine.id" @click="setArchived(routine)">
              {{ routine.is_archived ? 'Restore' : 'Archive' }}
            </button>
          </div>
        </li>
      </ul>
    </section>
  </section>
</template>
