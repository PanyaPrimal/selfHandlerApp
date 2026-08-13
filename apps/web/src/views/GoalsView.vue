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
import { financeAmount } from '../finance/money'
import { useI18n } from '../i18n'

type FocusableControl = { focus: () => void }

interface GoalForm {
  name: string
  description: string
  target_date: string
}

type GoalAction = 'complete' | 'abandon' | 'reactivate' | 'archive' | 'restore'
type WorkspaceFocus = 'none' | 'form' | 'list'
const i18n = useI18n()

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
const locale = i18n.locale
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

    const state = i18n.t(routine.is_archived ? 'goal.routineArchived' : 'goal.routinePaused')
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
    return i18n.t('goal.invalid')
  }

  const subject = operation === 'load'
    ? i18n.t('goal.operation.loaded')
    : operation === 'save'
      ? i18n.t('goal.operation.saved')
      : operation === 'links'
        ? i18n.t('goal.operation.linked')
        : i18n.t('goal.operation.updated')

  return i18n.t('goal.operationFailed', { operation: subject })
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
      success.value = i18n.t('goal.created')
    } else {
      await updateGoal(editingId.value, payload)
      success.value = i18n.t('goal.updated')
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
    complete: i18n.t('goal.completed'),
    abandon: i18n.t('goal.abandoned'),
    reactivate: i18n.t('goal.reactivated'),
    archive: i18n.t('goal.archivedNotice'),
    restore: i18n.t('goal.restoredNotice'),
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

    success.value = i18n.t('goal.linksSaved')
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
        <p class="eyebrow">{{ i18n.t('goal.eyebrow') }}</p>
        <h1>{{ i18n.t('goal.title') }}</h1>
      </div>
    </header>

    <div v-if="!isLoading && !loadFailed && error" class="notice error action-notice" role="alert">
      <span>{{ error }}</span>
      <button v-if="retryAction" ref="feedbackRetryButton" type="button" class="secondary" @click="retryAction">{{ i18n.t('common.retry') }}</button>
    </div>
    <div v-if="success" class="notice success" role="status">{{ success }}</div>

    <AsyncState
      :loading="isLoading"
      :error="loadFailed ? error : null"
      :loading-title="i18n.t('goal.loading')"
      :loading-description="i18n.t('goal.loadingBody')"
      panel
      @retry="loadWorkspace('form')"
    >
      <section class="panel">
        <h2>{{ i18n.t(editingId === null ? 'goal.create' : 'goal.edit') }}</h2>
        <form
          class="form-grid"
          :aria-label="i18n.t(editingId === null ? 'goal.create' : 'goal.edit')"
          novalidate
          :aria-busy="isSubmitting"
          @submit.prevent="submitGoal"
        >
          <UiTextInput
            ref="nameInput"
            v-model="form.name"
            :label="i18n.t('goal.name')"
            name="name"
            :maxlength="160"
            required
            :disabled="mutationBusy"
            :error="fieldErrors.name?.[0]"
            @update:model-value="clearFieldError('name')"
          />

          <UiDatePicker
            ref="targetDateInput"
            :label="i18n.t('goal.targetDate')"
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
            :label="i18n.t('goal.description')"
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
              {{ i18n.t('common.cancel') }}
            </button>
            <button type="submit" :disabled="mutationBusy">
              {{ isSubmitting ? i18n.t('goal.saving') : i18n.t(editingId === null ? 'goal.create' : 'goal.saveChanges') }}
            </button>
          </div>
        </form>
      </section>

      <section class="panel" :aria-label="i18n.t('goal.lists')">
        <div class="section-heading archive-heading">
          <div>
            <p class="eyebrow">{{ i18n.t('goal.library') }}</p>
            <h2 ref="goalListHeading" class="focus-target" tabindex="-1">{{ i18n.t(archivedView ? 'goal.archived' : 'goal.current') }}</h2>
          </div>
          <div class="segmented-list" role="group" :aria-label="i18n.t('goal.archiveFilter')">
            <button
              type="button"
              class="secondary"
              :class="{ selected: !archivedView }"
              :aria-pressed="!archivedView"
              :disabled="mutationBusy"
              @click="switchArchiveView(false)"
            >{{ i18n.t('goal.current') }}</button>
            <button
              type="button"
              class="secondary"
              :class="{ selected: archivedView }"
              :aria-pressed="archivedView"
              :disabled="mutationBusy"
              @click="switchArchiveView(true)"
            >{{ i18n.t('goal.archived') }}</button>
          </div>
        </div>

        <AsyncState
          :empty="goals.length === 0"
          :empty-title="i18n.t(archivedView ? 'goal.emptyArchived' : 'goal.emptyCurrent')"
          :empty-description="i18n.t(archivedView ? 'goal.emptyArchivedBody' : 'goal.emptyCurrentBody')"
        >
          <ul class="item-list" :aria-label="i18n.t(archivedView ? 'goal.archived' : 'goal.current')">
          <li v-for="goal in goals" :key="goal.id" class="goal-card" :aria-label="goal.name">
            <div class="management-row">
              <div class="management-copy">
                <strong>{{ goal.name }}</strong>
                <p v-if="goal.description" class="muted">{{ goal.description }}</p>
                <p class="routine-meta">
                  <span>{{ i18n.t(`goal.status.${goal.status}` as 'goal.status.active') }}</span>
                  <span v-if="goal.target_date">{{ i18n.t('goal.byDate', { date: formatCalendarDate(goal.target_date, locale) }) }}</span>
                  <span v-if="goal.type !== 'finance' && goal.routines.length > 0">
                    {{ i18n.t('goal.linked', { names: goal.routines.map((routine) => routine.name).join(', ') }) }}
                  </span>
                  <span v-else-if="goal.type !== 'finance'">{{ i18n.t('goal.noLinks') }}</span>
                </p>
                <div v-if="goal.type === 'finance' && goal.finance" class="goal-finance-summary">
                  <div class="finance-budget-progress" aria-hidden="true">
                    <span :style="{ width: `${Math.min(100, goal.finance.progress * 100)}%` }"></span>
                  </div>
                  <p class="muted">
                    {{ i18n.t(`finance.goalKind.${goal.finance.kind}` as never) }} ·
                    {{ financeAmount(goal.finance.current_value, goal.finance.currency, locale) }} /
                    {{ financeAmount(goal.finance.target_value, goal.finance.currency, locale) }}
                  </p>
                  <a :href="`/finance?tab=goals#finance-goal-${goal.id}`" class="button-link">
                    {{ i18n.t('goal.openFinance') }}
                  </a>
                </div>
              </div>

              <div class="button-row management-actions">
                <button
                  type="button"
                  class="secondary"
                  :aria-label="i18n.t('goal.editNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                  @click="editGoal(goal)"
                >{{ i18n.t('common.edit') }}</button>
                <button
                  v-if="goal.status === 'active' && !goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="i18n.t('goal.completeNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'complete')"
                >{{ i18n.t('goal.complete') }}</button>
                <button
                  v-if="goal.status === 'active' && !goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="i18n.t('goal.abandonNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'abandon')"
                >{{ i18n.t('goal.abandon') }}</button>
                <button
                  v-if="goal.status !== 'active' && !goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="i18n.t('goal.reactivateNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'reactivate')"
                >{{ i18n.t('goal.reactivate') }}</button>
                <button
                  v-if="!goal.is_archived"
                  type="button"
                  class="secondary"
                  :aria-label="i18n.t('goal.archiveNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'archive')"
                >{{ i18n.t('goal.archive') }}</button>
                <button
                  v-else
                  type="button"
                  class="secondary"
                  :aria-label="i18n.t('goal.restoreNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                  @click="changeGoalLifecycle(goal, 'restore')"
                >{{ i18n.t('goal.restore') }}</button>
              </div>
            </div>

            <form
              v-if="!goal.is_archived && goal.type !== 'finance'"
              class="routine-link-form"
              :aria-label="i18n.t('goal.routineLinksNamed', { name: goal.name })"
              @submit.prevent="saveRoutineLinks(goal)"
            >
              <div>
                <strong>{{ i18n.t('goal.routineLinks') }}</strong>
                <p class="muted">
                  {{ i18n.t('goal.routineLinksHelp') }}
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
              <p v-else class="muted">{{ i18n.t('goal.noActiveRoutines') }}</p>
              <div class="form-actions">
                <button
                  type="submit"
                  class="secondary"
                  :aria-label="i18n.t('goal.saveLinksNamed', { name: goal.name })"
                  :disabled="mutationBusy"
                >{{ i18n.t(linkSavingGoalId === goal.id ? 'goal.savingLinks' : 'goal.saveLinks') }}</button>
              </div>
            </form>
          </li>
          </ul>
        </AsyncState>
      </section>
    </AsyncState>
  </section>
</template>
