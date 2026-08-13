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
import type { Routine, RoutineCreatePayload, RoutineUpdatePayload, Weekday } from '../api/types'
import type { ValidationErrors } from '../api/client'
import type { UiOption } from '../components/ui'
import { useI18n } from '../i18n'
import SleepWorkspace from '../components/sleep/SleepWorkspace.vue'
import RoutineActivityEditor from '../components/routines/RoutineActivityEditor.vue'

type FocusableControl = { focus: () => void }

interface RoutineForm {
  name: string
  description: string
  kind: Routine['kind']
  day_period: Routine['day_period']
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
const dayPeriodInput = ref<FocusableControl | null>(null)
const descriptionInput = ref<FocusableControl | null>(null)
const scheduleTypeInput = ref<FocusableControl | null>(null)
const preferredTimeInput = ref<FocusableControl | null>(null)
const weekdayGroup = ref<FocusableControl | null>(null)
const startsOnInput = ref<FocusableControl | null>(null)
const endsOnInput = ref<FocusableControl | null>(null)
const sortOrderInput = ref<FocusableControl | null>(null)
const activeInput = ref<FocusableControl | null>(null)
const routineListHeading = ref<HTMLHeadingElement | null>(null)
const i18n = useI18n()
const locale = i18n.locale
const weekdayOptions = computed<UiOption<Weekday>[]>(() =>
  (['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as Weekday[]).map((value) => ({
    value,
    label: i18n.t(`weekday.${value}` as 'weekday.MO'),
  })),
)
// The schedule locks once a routine has results, so the rule is explained in the
// editor rather than only surfacing as a rejected save.
const scheduleHelper = computed(() => (editingId.value === null
  ? i18n.t('routine.scheduleCreateHelp')
  : i18n.t('routine.scheduleEditHelp')))

const kindOptions = computed<UiOption<Routine['kind']>[]>(() => [
  { value: 'routine', label: i18n.t('today.kind.routine') },
  { value: 'habit', label: i18n.t('today.kind.habit') },
  { value: 'sleep', label: i18n.t('today.kind.sleep') },
])
const scheduleOptions = computed<UiOption<Routine['schedule_type']>[]>(() => [
  { value: 'daily', label: i18n.t('routine.daily') },
  { value: 'weekdays', label: i18n.t('routine.byWeekdays') },
])
const dayPeriodOptions = computed<UiOption<Routine['day_period']>[]>(() => [
  { value: 'morning', label: i18n.t('routine.period.morning') },
  { value: 'evening', label: i18n.t('routine.period.evening') },
  { value: 'anytime', label: i18n.t('routine.period.anytime') },
])

function kindLabel(kind: Routine['kind']): string {
  return i18n.t(`today.kind.${kind}` as 'today.kind.routine')
}

function emptyForm(): RoutineForm {
  return {
    name: '',
    description: '',
    kind: 'routine',
    day_period: 'anytime',
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
    loadError.value = currentError instanceof Error ? currentError.message : i18n.t('routine.loadFailed')
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
    day_period: form.day_period,
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
    ['day_period', dayPeriodInput.value],
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
    fieldErrors.value = { weekdays: [i18n.t('routine.chooseWeekday')] }
    await focusFirstError()
    return
  }

  isSubmitting.value = true

  try {
    const payload = routinePayload()

    if (editingId.value === null) {
      await createRoutine(payload)
      success.value = i18n.t('routine.created')
    } else {
      await updateRoutine(editingId.value, payload as RoutineUpdatePayload)
      success.value = i18n.t('routine.updated')
    }

    resetForm()
    await loadRoutines(true)
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = currentError instanceof Error ? currentError.message : i18n.t('routine.saveFailed')
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
    day_period: routine.day_period,
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
      success.value = i18n.t('routine.restored')
    } else {
      await archiveRoutine(routine.id)
      success.value = i18n.t('routine.archivedNotice')
    }

    if (editingId.value === routine.id) {
      resetForm()
    }

    await loadRoutines(true)
    focusMoved = !loadError.value
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('routine.archiveFailed')
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
    success.value = i18n.t(routine.is_active ? 'routine.pausedNotice' : 'routine.resumedNotice')
    await loadRoutines()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('routine.stateFailed')
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

async function activitiesSaved(): Promise<void> {
  success.value = i18n.t('routine.activitiesSaved')
  await loadRoutines()
}

onMounted(loadRoutines)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('routine.eyebrow') }}</p>
        <h1>{{ i18n.t('routine.workspaceTitle') }}</h1>
        <p class="muted">{{ i18n.t('routine.workspaceSubtitle') }}</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">
      {{ error }}
    </div>
    <div v-if="success" class="notice success" role="status">{{ success }}</div>

    <SleepWorkspace />

    <section class="panel" aria-labelledby="routine-form-heading">
      <div class="section-heading">
        <h2 id="routine-form-heading">{{ i18n.t(editingId === null ? 'routine.create' : 'routine.edit') }}</h2>
        <button v-if="editingId !== null" type="button" class="secondary" @click="cancelEdit">{{ i18n.t('routine.cancelEdit') }}</button>
      </div>

      <form class="form-grid" :aria-label="i18n.t(editingId === null ? 'routine.create' : 'routine.edit')" :aria-busy="isSubmitting" novalidate @submit.prevent="submitRoutine">
        <UiTextInput
          ref="nameInput"
          v-model="form.name"
          :label="i18n.t('routine.name')"
          name="name"
          required
          :maxlength="160"
          :placeholder="i18n.t('routine.namePlaceholder')"
          :error="fieldErrors.name?.[0]"
          @update:model-value="clearFieldError('name')"
        />

        <UiSelect
          ref="kindInput"
          v-model="form.kind"
          :label="i18n.t('routine.kind')"
          name="kind"
          :options="kindOptions"
          :error="fieldErrors.kind?.[0]"
          @update:model-value="clearFieldError('kind')"
        />

        <UiSelect
          ref="dayPeriodInput"
          v-model="form.day_period"
          :label="i18n.t('routine.dayPeriod')"
          name="day_period"
          :options="dayPeriodOptions"
          :error="fieldErrors.day_period?.[0]"
          @update:model-value="clearFieldError('day_period')"
        />

        <UiTextarea
          ref="descriptionInput"
          v-model="form.description"
          :label="i18n.t('routine.description')"
          name="description"
          :rows="2"
          :maxlength="2000"
          :placeholder="i18n.t('routine.optionalContext')"
          wide
          :error="fieldErrors.description?.[0]"
          @update:model-value="clearFieldError('description')"
        />

        <UiSegmented
          ref="scheduleTypeInput"
          v-model="form.schedule_type"
          :label="i18n.t('routine.schedule')"
          name="schedule_type"
          :options="scheduleOptions"
          :helper="scheduleHelper"
          :error="fieldErrors.schedule_type?.[0]"
          @update:model-value="clearFieldError('schedule_type')"
        />

        <UiTimeField
          ref="preferredTimeInput"
          :label="i18n.t('routine.preferredTime')"
          name="preferred_time"
          :model-value="form.preferred_time || null"
          :error="fieldErrors.preferred_time?.[0]"
          @update:model-value="(value) => { form.preferred_time = value ?? ''; clearFieldError('preferred_time') }"
        />

        <UiToggleGroup
          v-if="form.schedule_type === 'weekdays'"
          ref="weekdayGroup"
          :label="i18n.t('routine.weekdays')"
          name="weekdays"
          :model-value="form.weekdays"
          :options="weekdayOptions"
          wide
          :error="fieldErrors.weekdays?.[0]"
          @update:model-value="setWeekdays"
        />

        <UiDatePicker
          ref="startsOnInput"
          :label="i18n.t('routine.startsOn')"
          name="starts_on"
          :model-value="form.starts_on || null"
          :locale="locale"
          :error="fieldErrors.starts_on?.[0]"
          @update:model-value="(value) => { form.starts_on = value ?? ''; clearFieldError('starts_on') }"
        />

        <UiDatePicker
          ref="endsOnInput"
          :label="i18n.t('routine.endsOn')"
          name="ends_on"
          :model-value="form.ends_on || null"
          :locale="locale"
          :error="fieldErrors.ends_on?.[0]"
          @update:model-value="(value) => { form.ends_on = value ?? ''; clearFieldError('ends_on') }"
        />

        <UiNumberInput
          ref="sortOrderInput"
          :label="i18n.t('routine.order')"
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
          :label="i18n.t('routine.active')"
          name="is_active"
          :error="fieldErrors.is_active?.[0]"
          @update:model-value="clearFieldError('is_active')"
        />

        <div class="form-actions wide-field">
          <button type="submit" :disabled="isSubmitting">
            {{ isSubmitting ? i18n.t('common.saving') : i18n.t(editingId === null ? 'routine.create' : 'routine.saveChanges') }}
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
        >{{ i18n.t(archivedView ? 'routine.archived' : 'routine.current') }}</h2>
        <div class="segmented-list" role="group" :aria-label="i18n.t('routine.archiveFilter')">
          <button type="button" class="secondary" :class="{ selected: !archivedView }" :aria-pressed="!archivedView" @click="switchArchiveView(false)">
            {{ i18n.t('common.current') }}
          </button>
          <button type="button" class="secondary" :class="{ selected: archivedView }" :aria-pressed="archivedView" @click="switchArchiveView(true)">
            {{ i18n.t('common.archived') }}
          </button>
        </div>
      </div>

      <AsyncState
        :loading="isLoading"
        :error="loadError"
        :empty="routines.length === 0"
        :loading-title="i18n.t('routine.loading')"
        :empty-title="i18n.t(archivedView ? 'routine.emptyArchived' : 'routine.emptyCurrent')"
        :empty-description="i18n.t(archivedView ? 'routine.emptyArchivedBody' : 'routine.emptyCurrentBody')"
        show-empty-icon
        @retry="loadRoutines(true)"
      >
        <ul class="item-list">
          <li v-for="routine in routines" :key="routine.id" class="management-row" :aria-label="routine.name">
            <div class="management-copy">
              <div class="meta-row">
                <strong>{{ routine.name }}</strong>
                <span class="kind-chip">{{ kindLabel(routine.kind) }}</span>
                <span class="kind-chip">{{ i18n.t(`routine.period.${routine.day_period}` as 'routine.period.morning') }}</span>
                <span v-if="!routine.is_active" class="kind-chip">{{ i18n.t('routine.paused') }}</span>
              </div>
              <p v-if="routine.description" class="muted">{{ routine.description }}</p>
              <p class="muted">
                {{ routine.schedule_type === 'daily' ? i18n.t('routine.daily') : routine.weekdays.map((day) => i18n.t(`weekday.${day}` as 'weekday.MO')).join(', ') }}
                <span v-if="routine.preferred_time"> · {{ routine.preferred_time.slice(0, 5) }}</span>
                · {{ i18n.t('routine.orderValue', { order: routine.sort_order }) }}
              </p>
            </div>

            <div class="button-row management-actions">
              <RoutineActivityEditor v-if="!routine.is_archived" :routine="routine" @saved="activitiesSaved" />
              <button type="button" class="secondary" :aria-label="i18n.t('routine.editNamed', { name: routine.name })" :disabled="actionRoutineId === routine.id" @click="editRoutine(routine)">{{ i18n.t('common.edit') }}</button>
              <button v-if="!routine.is_archived" type="button" class="secondary" :aria-label="i18n.t(routine.is_active ? 'routine.pauseNamed' : 'routine.resumeNamed', { name: routine.name })" :disabled="actionRoutineId === routine.id" @click="toggleActive(routine, $event.currentTarget as HTMLElement)">
                {{ i18n.t(routine.is_active ? 'routine.pause' : 'routine.resume') }}
              </button>
              <button type="button" class="secondary" :aria-label="i18n.t(routine.is_archived ? 'routine.restoreNamed' : 'routine.archiveNamed', { name: routine.name })" :disabled="actionRoutineId === routine.id" @click="setArchived(routine, $event.currentTarget as HTMLElement)">
                {{ i18n.t(routine.is_archived ? 'routine.restore' : 'routine.archive') }}
              </button>
            </div>
          </li>
        </ul>
      </AsyncState>
    </section>
  </section>
</template>
