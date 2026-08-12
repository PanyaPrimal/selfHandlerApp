<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref, shallowRef } from 'vue'
import {
  abandonGoal,
  archiveGoal,
  completeGoal,
  createGoal,
  getGoals,
  getRoutines,
  linkRoutineToGoal,
  reactivateGoal,
  restoreGoal,
  unlinkRoutineFromGoal,
  updateGoal,
  validationErrors,
  ApiError,
  type ValidationErrors,
} from '../api/client'
import type { Goal, GoalCreatePayload, Routine } from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { UiCheckbox, UiDatePicker, UiTextInput, UiTextarea } from '../components/ui'
import { formatCalendarDate } from '../lib/format'
import { useAuthSession } from '../auth/session'

type FocusableControl = { focus: () => void }

interface GoalForm {
  name: string
  description: string
  target_date: string
}

type GoalAction = 'complete' | 'abandon' | 'reactivate' | 'archive' | 'restore'
type WorkspaceFocus = 'none' | 'form' | 'list'
const session = useAuthSession()

const goals = ref<Goal[]>([])
const routines = ref<Routine[]>([])
const archivedView = ref(false)
const editingId = ref<number | null>(null)
const isLoading = ref(true)
const loadFailed = ref(false)
const isSubmitting = ref(false)
const actionGoalId = ref<number | null>(null)
const linkSavingGoalId = ref<number | null>(null)
const error = ref<string | null>(null)
const success = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const retryAction = shallowRef<(() => void) | null>(null)
const linkSelections = reactive<Record<number, number[]>>({})
const form = reactive<GoalForm>(emptyForm())
const nameInput = ref<FocusableControl | null>(null)
const descriptionInput = ref<FocusableControl | null>(null)
const targetDateInput = ref<FocusableControl | null>(null)
const locale = computed(() => session.user?.preferences.locale ?? 'en-GB')
const goalListHeading = ref<HTMLHeadingElement | null>(null)
const feedbackRetryButton = ref<HTMLButtonElement | null>(null)
const activeRoutines = computed(() => routines.value.filter((routine) => routine.is_active && !routine.is_archived))

interface RoutineLinkOption {
  id: number
  label: string
  unavailable: boolean
}

/**
 * The routines this goal can be linked to or unlinked from.
 *
 * Active routines are offered for linking. A routine the goal is already linked
 * to is always included even once it is paused or archived: the goal card keeps
 * showing that link, so the control that removes it has to keep existing too.
 */
function linkOptionsFor(goal: Goal): RoutineLinkOption[] {
  const options = new Map<number, RoutineLinkOption>()

  for (const routine of activeRoutines.value) {
    options.set(routine.id, { id: routine.id, label: routine.name, unavailable: false })
  }

  for (const routine of goal.routines) {
    if (options.has(routine.id)) {
      continue
    }

    const state = routine.is_archived ? 'archived' : 'paused'
    options.set(routine.id, { id: routine.id, label: `${routine.name} (${state})`, unavailable: true })
  }

  return [...options.values()]
}
const mutationBusy = computed(() => (
  isSubmitting.value || actionGoalId.value !== null || linkSavingGoalId.value !== null
))

function emptyForm(): GoalForm {
  return {
    name: '',
    description: '',
    target_date: '',
  }
}

function resetLinkSelections(): void {
  for (const goalId of Object.keys(linkSelections)) {
    delete linkSelections[Number(goalId)]
  }

  for (const goal of goals.value) {
    linkSelections[goal.id] = goal.routines.map((routine) => routine.id)
  }
}

function failureMessage(currentError: unknown, operation: 'load' | 'save' | 'action' | 'links'): string {
  if (currentError instanceof ApiError && currentError.status === 422) {
    return 'Please correct the highlighted fields and try again.'
  }

  const subject = operation === 'load'
    ? 'loaded'
    : operation === 'save'
      ? 'saved'
      : operation === 'links'
        ? 'linked'
        : 'updated'

  return `Goals could not be ${subject}. Check the service and try again.`
}

function setRetry(action: () => void): void {
  retryAction.value = action
}

function clearFeedback(): void {
  error.value = null
  success.value = null
  retryAction.value = null
}

async function loadWorkspace(focusAfter: WorkspaceFocus = 'none'): Promise<void> {
  isLoading.value = true
  loadFailed.value = false
  error.value = null
  retryAction.value = null

  try {
    const [loadedGoals, loadedRoutines] = await Promise.all([
      getGoals(archivedView.value),
      getRoutines(false),
    ])

    goals.value = loadedGoals
    routines.value = loadedRoutines
    resetLinkSelections()
  } catch (currentError) {
    goals.value = []
    routines.value = []
    loadFailed.value = true
    error.value = failureMessage(currentError, 'load')
  } finally {
    isLoading.value = false

    if (!loadFailed.value && focusAfter !== 'none') {
      await nextTick()

      if (focusAfter === 'form') {
        nameInput.value?.focus()
      } else {
        goalListHeading.value?.focus()
      }
    }
  }
}

function goalPayload(): GoalCreatePayload {
  return {
    name: form.name,
    description: form.description || null,
    target_date: form.target_date || null,
  }
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const inputs: Array<[keyof GoalForm, FocusableControl | null]> = [
    ['name', nameInput.value],
    ['target_date', targetDateInput.value],
    ['description', descriptionInput.value],
  ]

  inputs.find(([field]) => fieldErrors.value[field]?.length)?.[1]?.focus()
}

async function focusFeedbackRetry(): Promise<void> {
  await nextTick()
  feedbackRetryButton.value?.focus()
}

async function submitGoal(): Promise<void> {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  clearFeedback()
  fieldErrors.value = {}

  try {
    const payload = goalPayload()

    if (editingId.value === null) {
      await createGoal(payload)
      success.value = 'Goal created.'
    } else {
      await updateGoal(editingId.value, payload)
      success.value = 'Goal updated.'
    }

    resetForm()
    await loadWorkspace('list')
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = failureMessage(currentError, 'save')

    if (!(currentError instanceof ApiError && currentError.status === 422)) {
      setRetry(() => {
        void submitGoal()
      })
    }

    isSubmitting.value = false

    if (Object.keys(fieldErrors.value).length > 0) {
      await focusFirstError()
    } else {
      await focusFeedbackRetry()
    }
  } finally {
    isSubmitting.value = false
  }
}

async function editGoal(goal: Goal): Promise<void> {
  editingId.value = goal.id
  Object.assign(form, {
    name: goal.name,
    description: goal.description ?? '',
    target_date: goal.target_date ?? '',
  })
  fieldErrors.value = {}
  clearFeedback()
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
  goals.value = []
  resetForm()
  success.value = null
  await loadWorkspace('list')
}

async function changeGoalLifecycle(goal: Goal, action: GoalAction): Promise<void> {
  if (mutationBusy.value) {
    return
  }

  actionGoalId.value = goal.id
  clearFeedback()

  const operations: Record<GoalAction, (goalId: number) => Promise<Goal>> = {
    complete: completeGoal,
    abandon: abandonGoal,
    reactivate: reactivateGoal,
    archive: archiveGoal,
    restore: restoreGoal,
  }
  const messages: Record<GoalAction, string> = {
    complete: 'Goal completed.',
    abandon: 'Goal abandoned.',
    reactivate: 'Goal reactivated.',
    archive: 'Goal archived.',
    restore: 'Goal restored.',
  }

  try {
    await operations[action](goal.id)
    success.value = messages[action]

    if (editingId.value === goal.id && (action === 'archive' || action === 'restore')) {
      resetForm()
    }

    await loadWorkspace('list')
  } catch (currentError) {
    error.value = failureMessage(currentError, 'action')
    setRetry(() => {
      void changeGoalLifecycle(goal, action)
    })
    await focusFeedbackRetry()
  } finally {
    actionGoalId.value = null
  }
}

async function saveRoutineLinks(goal: Goal): Promise<void> {
  if (mutationBusy.value) {
    return
  }

  linkSavingGoalId.value = goal.id
  clearFeedback()

  const editableRoutineIds = new Set(linkOptionsFor(goal).map((option) => option.id))
  const existingRoutineIds = new Set(
    goal.routines
      .map((routine) => routine.id)
      .filter((routineId) => editableRoutineIds.has(routineId)),
  )
  const selectedRoutineIds = new Set(
    (linkSelections[goal.id] ?? []).filter((routineId) => editableRoutineIds.has(routineId)),
  )
  const toLink = [...selectedRoutineIds].filter((routineId) => !existingRoutineIds.has(routineId))
  const toUnlink = [...existingRoutineIds].filter((routineId) => !selectedRoutineIds.has(routineId))

  try {
    for (const routineId of toLink) {
      await linkRoutineToGoal(goal.id, routineId)
    }

    for (const routineId of toUnlink) {
      await unlinkRoutineFromGoal(goal.id, routineId)
    }

    success.value = 'Routine links saved.'
    await loadWorkspace('list')
  } catch (currentError) {
    error.value = failureMessage(currentError, 'links')
    setRetry(() => {
      void saveRoutineLinks(goal)
    })
    await focusFeedbackRetry()
  } finally {
    linkSavingGoalId.value = null
  }
}

function toggleRoutineLink(goal: Goal, routineId: number, checked: boolean): void {
  const current = new Set(linkSelections[goal.id] ?? [])

  if (checked) {
    current.add(routineId)
  } else {
    current.delete(routineId)
  }

  // Ordered by the options this goal shows, so the saved set does not depend on
  // the order the user ticked the boxes and never loses an already-linked
  // routine that is no longer active.
  linkSelections[goal.id] = linkOptionsFor(goal)
    .map((option) => option.id)
    .filter((id) => current.has(id))
}

function clearFieldError(field: keyof GoalForm): void {
  if (!fieldErrors.value[field]) {
    return
  }

  const remainingErrors = { ...fieldErrors.value }
  delete remainingErrors[field]
  fieldErrors.value = remainingErrors
}

onMounted(loadWorkspace)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">Goals</p>
        <h1>Outcomes linked to action</h1>
      </div>
    </header>

    <div v-if="!isLoading && !loadFailed && error" class="notice error action-notice" role="alert">
      <span>{{ error }}</span>
      <button v-if="retryAction" ref="feedbackRetryButton" type="button" class="secondary" @click="retryAction">Retry</button>
    </div>
    <div v-if="success" class="notice success" role="status">{{ success }}</div>

    <AsyncState
      :loading="isLoading"
      :error="loadFailed ? error : null"
      loading-title="Loading goals…"
      loading-description="Restoring goals and their routine links."
      panel
      @retry="loadWorkspace('form')"
    >
      <section class="panel">
        <h2>{{ editingId === null ? 'Create goal' : 'Edit goal' }}</h2>
        <form
          class="form-grid"
          :aria-label="editingId === null ? 'Create goal' : 'Edit goal'"
          novalidate
          :aria-busy="isSubmitting"
          @submit.prevent="submitGoal"
        >
          <UiTextInput
            ref="nameInput"
            v-model="form.name"
            label="Name"
            name="name"
            :maxlength="160"
            required
            :disabled="mutationBusy"
            :error="fieldErrors.name?.[0]"
            @update:model-value="clearFieldError('name')"
          />

          <UiDatePicker
            ref="targetDateInput"
            label="Target date"
            name="target_date"
            :model-value="form.target_date || null"
            :locale="locale"
            :disabled="mutationBusy"
            :error="fieldErrors.target_date?.[0]"
            @update:model-value="(value) => { form.target_date = value ?? ''; clearFieldError('target_date') }"
          />

          <UiTextarea
            ref="descriptionInput"
            v-model="form.description"
            label="Description"
            name="description"
            :rows="3"
            :maxlength="5000"
            wide
            :disabled="mutationBusy"
            :error="fieldErrors.description?.[0]"
            @update:model-value="clearFieldError('description')"
          />

          <div class="form-actions wide-field button-row">
            <button v-if="editingId !== null" type="button" class="secondary" :disabled="mutationBusy" @click="cancelEdit">
              Cancel
            </button>
            <button type="submit" :disabled="mutationBusy">
              {{ isSubmitting ? 'Saving goal…' : editingId === null ? 'Create goal' : 'Save changes' }}
            </button>
          </div>
        </form>
      </section>

      <section class="panel" aria-label="Goal lists">
        <div class="section-heading archive-heading">
          <div>
            <p class="eyebrow">Goal library</p>
            <h2 ref="goalListHeading" class="focus-target" tabindex="-1">{{ archivedView ? 'Archived goals' : 'Current goals' }}</h2>
          </div>
          <div class="segmented-list" role="group" aria-label="Goal archive filter">
            <button
              type="button"
              class="secondary"
              :class="{ selected: !archivedView }"
              :aria-pressed="!archivedView"
              :disabled="mutationBusy"
              @click="switchArchiveView(false)"
            >Current goals</button>
            <button
              type="button"
              class="secondary"
              :class="{ selected: archivedView }"
              :aria-pressed="archivedView"
              :disabled="mutationBusy"
              @click="switchArchiveView(true)"
            >Archived goals</button>
          </div>
        </div>

        <AsyncState
          :empty="goals.length === 0"
          :empty-title="archivedView ? 'No archived goals yet' : 'No goals yet'"
          :empty-description="archivedView ? 'Archived goals will remain available here.' : 'Create a goal to add purpose to daily routines.'"
        >
          <ul class="item-list" :aria-label="archivedView ? 'Archived goals' : 'Current goals'">
          <li v-for="goal in goals" :key="goal.id" class="goal-card" :aria-label="goal.name">
            <div class="management-row">
              <div class="management-copy">
                <strong>{{ goal.name }}</strong>
                <p v-if="goal.description" class="muted">{{ goal.description }}</p>
                <p class="routine-meta">
                  <span>{{ goal.status }}</span>
                  <span v-if="goal.target_date">by {{ formatCalendarDate(goal.target_date, session.user?.preferences.locale) }}</span>
                  <span v-if="goal.routines.length > 0">
                    Linked: {{ goal.routines.map((routine) => routine.name).join(', ') }}
                  </span>
                  <span v-else>No routines linked</span>
                </p>
              </div>

              <div class="button-row management-actions">
                <button
                  type="button"
                  class="secondary"
                  :aria-label="`Edit ${goal.name}`"
                  :disabled="mutationBusy"
                  @click="editGoal(goal)"
                >Edit</button>
                <button
                  v-if="goal.status === 'active' && !goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="`Complete ${goal.name}`"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'complete')"
                >Complete</button>
                <button
                  v-if="goal.status === 'active' && !goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="`Abandon ${goal.name}`"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'abandon')"
                >Abandon</button>
                <button
                  v-if="goal.status !== 'active' && !goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="`Reactivate ${goal.name}`"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'reactivate')"
                >Reactivate</button>
                <button
                  v-if="!goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="`Archive ${goal.name}`"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'archive')"
                >Archive</button>
                <button
                  v-else
                  type="button"
                  class="secondary"
                  :aria-label="`Restore ${goal.name}`"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'restore')"
                >Restore</button>
              </div>
            </div>

            <form
              v-if="!goal.is_archived"
              class="routine-link-form"
              :aria-label="`Routine links for ${goal.name}`"
              @submit.prevent="saveRoutineLinks(goal)"
            >
              <div>
                <strong>Routine links</strong>
                <p class="muted">
                  Tick an active routine to link it, untick one to unlink it, then save.
                </p>
              </div>
              <div v-if="linkOptionsFor(goal).length > 0" class="routine-link-options">
                <UiCheckbox
                  v-for="option in linkOptionsFor(goal)"
                  :key="option.id"
                  :label="option.label"
                  :name="`goal-${goal.id}-routine-${option.id}`"
                  :model-value="(linkSelections[goal.id] ?? []).includes(option.id)"
                  :disabled="mutationBusy"
                  @update:model-value="(checked) => toggleRoutineLink(goal, option.id, checked)"
                />
              </div>
              <p v-else class="muted">No active routines are available to link.</p>
              <div class="form-actions">
                <button
                  type="submit"
                  class="secondary"
                  :aria-label="`Save routine links for ${goal.name}`"
                  :disabled="mutationBusy"
                >{{ linkSavingGoalId === goal.id ? 'Saving links…' : 'Save routine links' }}</button>
              </div>
            </form>
          </li>
          </ul>
        </AsyncState>
      </section>
    </AsyncState>
  </section>
</template>
