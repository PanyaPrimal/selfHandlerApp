<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import {
  archiveRoutine,
  createRoutine,
  getRoutines,
  restoreRoutine,
  updateRoutine,
  validationErrors,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import {
  UiDatePicker,
  UiNumberInput,
  UiSegmented,
  UiSelect,
  UiSwitch,
  UiTextInput,
  UiTextarea,
  UiTimeField,
  UiToggleGroup,
} from '../components/ui'
import { useAuthSession } from '../auth/session'
import type { Routine, RoutineCreatePayload, RoutineUpdatePayload, Weekday } from '../api/types'
import type { ValidationErrors } from '../api/client'
import type { UiOption } from '../components/ui'

type FocusableControl = { focus: () => void }

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
const nameInput = ref<FocusableControl | null>(null)
const kindInput = ref<FocusableControl | null>(null)
const descriptionInput = ref<FocusableControl | null>(null)
const scheduleTypeInput = ref<FocusableControl | null>(null)
const preferredTimeInput = ref<FocusableControl | null>(null)
const weekdayGroup = ref<FocusableControl | null>(null)
const startsOnInput = ref<FocusableControl | null>(null)
const endsOnInput = ref<FocusableControl | null>(null)
const sortOrderInput = ref<FocusableControl | null>(null)
const activeInput = ref<FocusableControl | null>(null)
const routineListHeading = ref<HTMLHeadingElement | null>(null)
const session = useAuthSession()
const locale = computed(() => session.user?.preferences.locale ?? 'en-GB')
const weekdayOptions: UiOption<Weekday>[] = [
  { value: 'MO', label: 'Mon' },
  { value: 'TU', label: 'Tue' },
  { value: 'WE', label: 'Wed' },
  { value: 'TH', label: 'Thu' },
  { value: 'FR', label: 'Fri' },
  { value: 'SA', label: 'Sat' },
  { value: 'SU', label: 'Sun' },
]
const kindOptions: UiOption<Routine['kind']>[] = [
  { value: 'routine', label: 'Routine' },
  { value: 'habit', label: 'Habit' },
  { value: 'sleep', label: 'Sleep' },
]
const scheduleOptions: UiOption<Routine['schedule_type']>[] = [
  { value: 'daily', label: 'Daily' },
  { value: 'weekdays', label: 'By weekdays' },
]

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

  const inputs: Array<[keyof RoutineForm, FocusableControl | null]> = [
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

function setWeekdays(days: Weekday[]): void {
  form.weekdays = days
  clearFieldError('weekdays')
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
        <UiTextInput
          ref="nameInput"
          v-model="form.name"
          label="Name"
          name="name"
          required
          :maxlength="160"
          placeholder="Morning walk"
          :error="fieldErrors.name?.[0]"
          @update:model-value="clearFieldError('name')"
        />

        <UiSelect
          ref="kindInput"
          v-model="form.kind"
          label="Kind"
          name="kind"
          :options="kindOptions"
          :error="fieldErrors.kind?.[0]"
          @update:model-value="clearFieldError('kind')"
        />

        <UiTextarea
          ref="descriptionInput"
          v-model="form.description"
          label="Description"
          name="description"
          :rows="2"
          :maxlength="2000"
          placeholder="Optional context"
          wide
          :error="fieldErrors.description?.[0]"
          @update:model-value="clearFieldError('description')"
        />

        <UiSegmented
          ref="scheduleTypeInput"
          v-model="form.schedule_type"
          label="Schedule"
          name="schedule_type"
          :options="scheduleOptions"
          :error="fieldErrors.schedule_type?.[0]"
          @update:model-value="clearFieldError('schedule_type')"
        />

        <UiTimeField
          ref="preferredTimeInput"
          label="Preferred time"
          name="preferred_time"
          :model-value="form.preferred_time || null"
          :error="fieldErrors.preferred_time?.[0]"
          @update:model-value="(value) => { form.preferred_time = value ?? ''; clearFieldError('preferred_time') }"
        />

        <UiToggleGroup
          v-if="form.schedule_type === 'weekdays'"
          ref="weekdayGroup"
          label="Weekdays"
          name="weekdays"
          :model-value="form.weekdays"
          :options="weekdayOptions"
          wide
          helper="Schedule fields lock after the first daily result."
          :error="fieldErrors.weekdays?.[0]"
          @update:model-value="setWeekdays"
        />

        <UiDatePicker
          ref="startsOnInput"
          label="Starts on"
          name="starts_on"
          :model-value="form.starts_on || null"
          :locale="locale"
          :error="fieldErrors.starts_on?.[0]"
          @update:model-value="(value) => { form.starts_on = value ?? ''; clearFieldError('starts_on') }"
        />

        <UiDatePicker
          ref="endsOnInput"
          label="Ends on"
          name="ends_on"
          :model-value="form.ends_on || null"
          :locale="locale"
          :error="fieldErrors.ends_on?.[0]"
          @update:model-value="(value) => { form.ends_on = value ?? ''; clearFieldError('ends_on') }"
        />

        <UiNumberInput
          ref="sortOrderInput"
          label="Display order"
          name="sort_order"
          :model-value="form.sort_order"
          :min="0"
          :step="1"
          :error="fieldErrors.sort_order?.[0]"
          @update:model-value="(value) => { form.sort_order = value ?? 0; clearFieldError('sort_order') }"
        />

        <UiSwitch
          ref="activeInput"
          v-model="form.is_active"
          label="Active in planning"
          name="is_active"
          :error="fieldErrors.is_active?.[0]"
          @update:model-value="clearFieldError('is_active')"
        />

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
