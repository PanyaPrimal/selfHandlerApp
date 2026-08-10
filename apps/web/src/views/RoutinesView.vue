<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue'
import {
  archiveRoutine,
  createRoutine,
  getRoutines,
  restoreRoutine,
  updateRoutine,
  validationErrors,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
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
const isLoading = ref(true)
const loadError = ref<string | null>(null)
const isSubmitting = ref(false)
const actionRoutineId = ref<number | null>(null)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const form = reactive<RoutineForm>(emptyForm())
const nameInput = ref<HTMLInputElement | null>(null)
const kindInput = ref<HTMLSelectElement | null>(null)
const descriptionInput = ref<HTMLTextAreaElement | null>(null)
const scheduleTypeInput = ref<HTMLSelectElement | null>(null)
const preferredTimeInput = ref<HTMLInputElement | null>(null)
const weekdayGroup = ref<HTMLDivElement | null>(null)
const startsOnInput = ref<HTMLInputElement | null>(null)
const endsOnInput = ref<HTMLInputElement | null>(null)
const sortOrderInput = ref<HTMLInputElement | null>(null)
const activeInput = ref<HTMLInputElement | null>(null)
const routineListHeading = ref<HTMLHeadingElement | null>(null)
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

async function loadRoutines(focusAfter = false): Promise<void> {
  isLoading.value = true
  loadError.value = null
  error.value = null

  try {
    routines.value = await getRoutines(archivedView.value)
  } catch (currentError) {
    loadError.value = currentError instanceof Error ? currentError.message : 'Failed to load routines.'
  } finally {
    isLoading.value = false

    if (focusAfter && !loadError.value) {
      await nextTick()
      routineListHeading.value?.focus()
    }
  }
}

function routinePayload(): RoutineCreatePayload {
  const fields = {
    name: form.name,
    description: form.description || null,
    kind: form.kind,
    preferred_time: form.preferred_time || null,
    sort_order: form.sort_order,
    is_active: form.is_active,
    starts_on: form.starts_on || null,
    ends_on: form.ends_on || null,
  }

  return form.schedule_type === 'weekdays'
    ? { ...fields, schedule_type: 'weekdays', weekdays: form.weekdays }
    : { ...fields, schedule_type: 'daily' }
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const inputs: Array<[keyof RoutineForm, HTMLElement | null]> = [
    ['name', nameInput.value],
    ['kind', kindInput.value],
    ['description', descriptionInput.value],
    ['schedule_type', scheduleTypeInput.value],
    ['preferred_time', preferredTimeInput.value],
    ['weekdays', weekdayGroup.value],
    ['starts_on', startsOnInput.value],
    ['ends_on', endsOnInput.value],
    ['sort_order', sortOrderInput.value],
    ['is_active', activeInput.value],
  ]

  inputs.find(([field]) => fieldErrors.value[field]?.length)?.[1]?.focus()
}

function clearFieldError(field: keyof RoutineForm): void {
  if (!fieldErrors.value[field]) {
    return
  }

  const remainingErrors = { ...fieldErrors.value }
  delete remainingErrors[field]
  fieldErrors.value = remainingErrors
}

async function submitRoutine(): Promise<void> {
  error.value = null
  success.value = null
  fieldErrors.value = {}

  if (form.schedule_type === 'weekdays' && form.weekdays.length === 0) {
    fieldErrors.value = { weekdays: ['Choose at least one weekday.'] }
    await focusFirstError()
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
    await loadRoutines(true)
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = currentError instanceof Error ? currentError.message : 'Failed to save the routine.'
    await focusFirstError()
  } finally {
    isSubmitting.value = false
  }
}

async function editRoutine(routine: Routine): Promise<void> {
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
  await nextTick()
  nameInput.value?.focus()
}

function resetForm(): void {
  editingId.value = null
  Object.assign(form, emptyForm())
  fieldErrors.value = {}
}

async function cancelEdit(): Promise<void> {
  resetForm()
  await nextTick()
  nameInput.value?.focus()
}

async function switchArchiveView(archived: boolean): Promise<void> {
  if (archivedView.value === archived) {
    return
  }

  archivedView.value = archived
  resetForm()
  success.value = null
  await loadRoutines(true)
}

async function setArchived(routine: Routine, focusTarget?: HTMLElement | null): Promise<void> {
  actionRoutineId.value = routine.id
  error.value = null
  success.value = null
  let focusMoved = false

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

    await loadRoutines(true)
    focusMoved = !loadError.value
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to change the archive state.'
  } finally {
    actionRoutineId.value = null
    await nextTick()

    if (!focusMoved && focusTarget?.isConnected) {
      focusTarget.focus()
    }
  }
}

async function toggleActive(routine: Routine, focusTarget?: HTMLElement | null): Promise<void> {
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
    await nextTick()

    if (focusTarget?.isConnected) {
      focusTarget.focus()
    }
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
    </div>
    <div v-if="success" class="notice success" role="status">{{ success }}</div>

    <section class="panel" aria-labelledby="routine-form-heading">
      <div class="section-heading">
        <h2 id="routine-form-heading">{{ editingId === null ? 'Create routine' : 'Edit routine' }}</h2>
        <button v-if="editingId !== null" type="button" class="secondary" @click="cancelEdit">Cancel edit</button>
      </div>

      <form class="form-grid" :aria-label="editingId === null ? 'Create routine' : 'Edit routine'" :aria-busy="isSubmitting" novalidate @submit.prevent="submitRoutine">
        <label class="field">
          <span>Name</span>
          <input
            ref="nameInput"
            v-model="form.name"
            name="name"
            required
            maxlength="160"
            placeholder="Morning walk"
            :aria-invalid="Boolean(fieldErrors.name?.length)"
            :aria-describedby="fieldErrors.name?.length ? 'routine-name-error' : undefined"
            @input="clearFieldError('name')"
          />
          <small v-if="fieldErrors.name?.length" id="routine-name-error" class="field-error">{{ fieldErrors.name[0] }}</small>
        </label>

        <label class="field">
          <span>Kind</span>
          <select
            ref="kindInput"
            v-model="form.kind"
            name="kind"
            :aria-invalid="Boolean(fieldErrors.kind?.length)"
            :aria-describedby="fieldErrors.kind?.length ? 'routine-kind-error' : undefined"
            @change="clearFieldError('kind')"
          >
            <option value="routine">Routine</option>
            <option value="habit">Habit</option>
            <option value="sleep">Sleep</option>
          </select>
          <small v-if="fieldErrors.kind?.length" id="routine-kind-error" class="field-error">{{ fieldErrors.kind[0] }}</small>
        </label>

        <label class="field wide-field">
          <span>Description</span>
          <textarea
            ref="descriptionInput"
            v-model="form.description"
            name="description"
            rows="2"
            maxlength="2000"
            placeholder="Optional context"
            :aria-invalid="Boolean(fieldErrors.description?.length)"
            :aria-describedby="fieldErrors.description?.length ? 'routine-description-error' : undefined"
            @input="clearFieldError('description')"
          ></textarea>
          <small v-if="fieldErrors.description?.length" id="routine-description-error" class="field-error">{{ fieldErrors.description[0] }}</small>
        </label>

        <label class="field">
          <span>Schedule</span>
          <select
            ref="scheduleTypeInput"
            v-model="form.schedule_type"
            name="schedule_type"
            :aria-invalid="Boolean(fieldErrors.schedule_type?.length)"
            :aria-describedby="fieldErrors.schedule_type?.length ? 'routine-schedule-error' : undefined"
            @change="clearFieldError('schedule_type')"
          >
            <option value="daily">Daily</option>
            <option value="weekdays">By weekdays</option>
          </select>
          <small v-if="fieldErrors.schedule_type?.length" id="routine-schedule-error" class="field-error">{{ fieldErrors.schedule_type[0] }}</small>
        </label>

        <label class="field">
          <span>Preferred time</span>
          <input
            ref="preferredTimeInput"
            v-model="form.preferred_time"
            name="preferred_time"
            type="time"
            :aria-invalid="Boolean(fieldErrors.preferred_time?.length)"
            :aria-describedby="fieldErrors.preferred_time?.length ? 'routine-time-error' : undefined"
            @input="clearFieldError('preferred_time')"
          />
          <small v-if="fieldErrors.preferred_time?.length" id="routine-time-error" class="field-error">{{ fieldErrors.preferred_time[0] }}</small>
        </label>

        <div v-if="form.schedule_type === 'weekdays'" class="field wide-field">
          <span>Weekdays</span>
          <div
            ref="weekdayGroup"
            class="segmented-list focus-target"
            role="group"
            aria-label="Weekdays"
            :aria-invalid="Boolean(fieldErrors.weekdays?.length)"
            :aria-describedby="fieldErrors.weekdays?.length ? 'routine-weekdays-error' : undefined"
            tabindex="-1"
          >
            <button
              v-for="[value, label] in weekdayOptions"
              :key="value"
              type="button"
              class="secondary"
              :class="{ selected: form.weekdays.includes(value) }"
              :aria-pressed="form.weekdays.includes(value)"
              @click="toggleWeekday(value); clearFieldError('weekdays')"
            >
              {{ label }}
            </button>
          </div>
          <small v-if="fieldErrors.weekdays?.length" id="routine-weekdays-error" class="field-error">{{ fieldErrors.weekdays[0] }}</small>
          <span class="helper-text">Schedule fields lock after the first daily result.</span>
        </div>

        <label class="field">
          <span>Starts on</span>
          <input
            ref="startsOnInput"
            v-model="form.starts_on"
            name="starts_on"
            type="date"
            :aria-invalid="Boolean(fieldErrors.starts_on?.length)"
            :aria-describedby="fieldErrors.starts_on?.length ? 'routine-starts-error' : undefined"
            @input="clearFieldError('starts_on')"
          />
          <small v-if="fieldErrors.starts_on?.length" id="routine-starts-error" class="field-error">{{ fieldErrors.starts_on[0] }}</small>
        </label>

        <label class="field">
          <span>Ends on</span>
          <input
            ref="endsOnInput"
            v-model="form.ends_on"
            name="ends_on"
            type="date"
            :aria-invalid="Boolean(fieldErrors.ends_on?.length)"
            :aria-describedby="fieldErrors.ends_on?.length ? 'routine-ends-error' : undefined"
            @input="clearFieldError('ends_on')"
          />
          <small v-if="fieldErrors.ends_on?.length" id="routine-ends-error" class="field-error">{{ fieldErrors.ends_on[0] }}</small>
        </label>

        <label class="field">
          <span>Display order</span>
          <input
            ref="sortOrderInput"
            v-model.number="form.sort_order"
            name="sort_order"
            type="number"
            min="0"
            step="1"
            :aria-invalid="Boolean(fieldErrors.sort_order?.length)"
            :aria-describedby="fieldErrors.sort_order?.length ? 'routine-order-error' : undefined"
            @input="clearFieldError('sort_order')"
          />
          <small v-if="fieldErrors.sort_order?.length" id="routine-order-error" class="field-error">{{ fieldErrors.sort_order[0] }}</small>
        </label>

        <div class="field">
          <label class="checkbox-field">
            <input
              ref="activeInput"
              v-model="form.is_active"
              name="is_active"
              type="checkbox"
              :aria-invalid="Boolean(fieldErrors.is_active?.length)"
              :aria-describedby="fieldErrors.is_active?.length ? 'routine-active-error' : undefined"
              @change="clearFieldError('is_active')"
            />
            <span>Active in planning</span>
          </label>
          <small v-if="fieldErrors.is_active?.length" id="routine-active-error" class="field-error">{{ fieldErrors.is_active[0] }}</small>
        </div>

        <div class="form-actions wide-field">
          <button type="submit" :disabled="isSubmitting">
            {{ isSubmitting ? 'Saving…' : editingId === null ? 'Create routine' : 'Save changes' }}
          </button>
        </div>
      </form>
    </section>

    <section class="panel" :aria-labelledby="archivedView ? 'archived-routines-heading' : 'current-routines-heading'">
      <div class="section-heading archive-heading">
        <h2
          ref="routineListHeading"
          class="focus-target"
          tabindex="-1"
          :id="archivedView ? 'archived-routines-heading' : 'current-routines-heading'"
        >{{ archivedView ? 'Archived routines' : 'Current routines' }}</h2>
        <div class="segmented-list" role="group" aria-label="Routine archive filter">
          <button type="button" class="secondary" :class="{ selected: !archivedView }" :aria-pressed="!archivedView" @click="switchArchiveView(false)">
            Current
          </button>
          <button type="button" class="secondary" :class="{ selected: archivedView }" :aria-pressed="archivedView" @click="switchArchiveView(true)">
            Archived
          </button>
        </div>
      </div>

      <AsyncState
        :loading="isLoading"
        :error="loadError"
        :empty="routines.length === 0"
        loading-title="Loading routines…"
        :empty-title="archivedView ? 'No archived routines' : 'No routines yet'"
        :empty-description="archivedView ? 'Archived routines will remain available here.' : 'Create a routine to start the daily loop.'"
        show-empty-icon
        @retry="loadRoutines(true)"
      >
        <ul class="item-list">
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
              <button v-if="!routine.is_archived" type="button" class="secondary" :aria-label="`${routine.is_active ? 'Pause' : 'Resume'} ${routine.name}`" :disabled="actionRoutineId === routine.id" @click="toggleActive(routine, $event.currentTarget as HTMLElement)">
                {{ routine.is_active ? 'Pause' : 'Resume' }}
              </button>
              <button type="button" class="secondary" :aria-label="`${routine.is_archived ? 'Restore' : 'Archive'} ${routine.name}`" :disabled="actionRoutineId === routine.id" @click="setArchived(routine, $event.currentTarget as HTMLElement)">
                {{ routine.is_archived ? 'Restore' : 'Archive' }}
              </button>
            </div>
          </li>
        </ul>
      </AsyncState>
    </section>
  </section>
</template>
