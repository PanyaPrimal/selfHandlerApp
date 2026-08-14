<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, onBeforeRouteUpdate, useRoute, useRouter } from 'vue-router'
import {
  ApiError,
  getPeriodicReviewWorkspace,
  getToday,
  savePeriodicReview,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import type {
  PeriodicReview,
  PeriodicReviewModules,
  PeriodicReviewPayload,
  PeriodicReviewType,
  ReviewPeriod,
  WellBeingSummary,
} from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import ReviewModeNav from '../components/review/ReviewModeNav.vue'
import ReviewModuleGrid from '../components/review/ReviewModuleGrid.vue'
import { UiDatePicker, UiNumberInput, UiTextarea } from '../components/ui'
import { useI18n } from '../i18n'
import { formatCalendarDate } from '../lib/format'

interface PeriodicForm {
  period_rating: number | null
  worked_well: string
  did_not_work: string
  learned: string
  next_focus: string
  notes: string
}

type PeriodicField = keyof PeriodicForm

const props = defineProps<{ period: PeriodicReviewType }>()
const route = useRoute()
const router = useRouter()
const i18n = useI18n()
const anchor = ref('')
const canonicalPeriod = ref<ReviewPeriod | null>(null)
const modules = ref<PeriodicReviewModules | null>(null)
const wellBeing = ref<WellBeingSummary | null>(null)
const hasSavedReview = ref(false)
const isLoading = ref(true)
const isReady = ref(false)
const isSaving = ref(false)
const isSaved = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const canRetrySave = ref(false)
const fieldErrors = ref<ValidationErrors>({})
const form = reactive<PeriodicForm>(emptyForm())
const savedSnapshot = ref(snapshot())
const saveButton = ref<HTMLButtonElement | null>(null)
const retrySaveButton = ref<HTMLButtonElement | null>(null)
const isDirty = computed(() => isReady.value && snapshot() !== savedSnapshot.value)
const titleKey = computed(() => props.period === 'weekly' ? 'review.weeklyTitle' : 'review.monthlyTitle')
const periodLabel = computed(() => canonicalPeriod.value
  ? i18n.t('review.periodRange', {
      start: formatCalendarDate(canonicalPeriod.value.start, i18n.locale.value),
      end: formatCalendarDate(canonicalPeriod.value.end, i18n.locale.value),
    })
  : '')
let loadSequence = 0

function emptyForm(): PeriodicForm {
  return {
    period_rating: 5,
    worked_well: '',
    did_not_work: '',
    learned: '',
    next_focus: '',
    notes: '',
  }
}

function snapshot(): string {
  return JSON.stringify(form)
}

function restoreForm(review: PeriodicReview | null): void {
  Object.assign(form, review
    ? {
        period_rating: review.period_rating,
        worked_well: review.worked_well ?? '',
        did_not_work: review.did_not_work ?? '',
        learned: review.learned ?? '',
        next_focus: review.next_focus ?? '',
        notes: review.notes ?? '',
      }
    : emptyForm())
  hasSavedReview.value = review !== null
  savedSnapshot.value = snapshot()
}

function loadFailureMessage(error: unknown): string {
  return error instanceof ApiError && error.status === 422
    ? i18n.t('review.invalidDate')
    : i18n.t('review.periodicLoadFailed')
}

async function loadReview(): Promise<void> {
  const sequence = ++loadSequence
  const routeAnchor = typeof route.params.anchor === 'string' && route.params.anchor
    ? route.params.anchor
    : null
  isLoading.value = true
  isReady.value = false
  isSaved.value = false
  loadError.value = null
  saveError.value = null
  fieldErrors.value = {}

  try {
    const requestedAnchor = routeAnchor ?? (await getToday()).date
    const workspace = await getPeriodicReviewWorkspace(props.period, requestedAnchor)
    if (sequence !== loadSequence) return

    anchor.value = requestedAnchor
    canonicalPeriod.value = workspace.period
    modules.value = workspace.modules
    wellBeing.value = workspace.well_being
    restoreForm(workspace.review)
    isReady.value = true
  } catch (error) {
    if (sequence === loadSequence) loadError.value = loadFailureMessage(error)
  } finally {
    if (sequence === loadSequence) isLoading.value = false
  }
}

function payload(): PeriodicReviewPayload {
  return {
    period_rating: form.period_rating,
    worked_well: form.worked_well,
    did_not_work: form.did_not_work,
    learned: form.learned,
    next_focus: form.next_focus,
    notes: form.notes,
  }
}

async function submitReview(): Promise<void> {
  if (isSaving.value || !isReady.value || !anchor.value) return

  isSaving.value = true
  isSaved.value = false
  saveError.value = null
  canRetrySave.value = false
  fieldErrors.value = {}

  try {
    const review = await savePeriodicReview(props.period, anchor.value, payload())
    restoreForm(review)
    isSaved.value = true
  } catch (error) {
    fieldErrors.value = validationErrors(error)
    saveError.value = error instanceof ApiError && error.status === 422
      ? i18n.t('review.invalid')
      : i18n.t('review.periodicSaveFailed')
    canRetrySave.value = !(error instanceof ApiError && error.status === 422)
    if (canRetrySave.value) {
      await nextTick()
      retrySaveButton.value?.focus()
    }
  } finally {
    isSaving.value = false
    if (isSaved.value) {
      await nextTick()
      saveButton.value?.focus()
    }
  }
}

function markChanged(field: PeriodicField): void {
  isSaved.value = false
  if (fieldErrors.value[field]) {
    const remaining = { ...fieldErrors.value }
    delete remaining[field]
    fieldErrors.value = remaining
  }
}

function selectAnchor(value: string | null): void {
  if (value && value !== anchor.value) {
    void router.push({ name: `review-${props.period}`, params: { anchor: value } })
  }
}

watch(
  [() => props.period, () => route.params.anchor],
  () => { void loadReview() },
  { immediate: true },
)

function confirmDiscard(): boolean {
  return !isDirty.value || window.confirm(i18n.t('review.discardChanges'))
}

onBeforeRouteUpdate(confirmDiscard)
onBeforeRouteLeave(confirmDiscard)
</script>

<template>
  <section class="view-stack review-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('review.periodicEyebrow') }}</p>
        <h1>{{ i18n.t(titleKey) }}</h1>
        <p v-if="periodLabel" class="muted">{{ periodLabel }}</p>
      </div>
      <div class="compact-field">
        <UiDatePicker
          :model-value="anchor || null"
          :label="i18n.t('review.anchorDate')"
          name="periodic-review-anchor"
          :locale="i18n.locale.value"
          :today="anchor || null"
          :clearable="false"
          @update:model-value="selectAnchor"
        />
      </div>
    </header>

    <ReviewModeNav :active="period" :anchor="anchor" />

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      :loading-title="i18n.t('review.loadingPeriodic')"
      :loading-description="i18n.t('review.loadingPeriodicBody')"
      @retry="loadReview"
    >
      <section v-if="isReady && wellBeing" class="panel">
        <div>
          <p class="eyebrow">{{ i18n.t('review.wellBeingEyebrow') }}</p>
          <h2>{{ i18n.t('review.wellBeingTitle') }}</h2>
        </div>
        <div class="summary-grid well-being-grid">
          <div class="metric"><span>{{ i18n.t('review.reviewedDays') }}</span><strong>{{ wellBeing.reviewed_days }} / {{ wellBeing.period_days }}</strong></div>
          <div class="metric"><span>{{ i18n.t('review.averageMood') }}</span><strong>{{ wellBeing.mood === null ? '—' : i18n.number(wellBeing.mood) }}</strong></div>
          <div class="metric"><span>{{ i18n.t('review.averageEnergy') }}</span><strong>{{ wellBeing.energy === null ? '—' : i18n.number(wellBeing.energy) }}</strong></div>
          <div class="metric"><span>{{ i18n.t('review.averageStress') }}</span><strong>{{ wellBeing.stress === null ? '—' : i18n.number(wellBeing.stress) }}</strong></div>
          <div class="metric"><span>{{ i18n.t('review.averageDayRating') }}</span><strong>{{ wellBeing.day_rating === null ? '—' : i18n.number(wellBeing.day_rating) }}</strong></div>
        </div>
      </section>

      <section v-if="isReady && modules" class="panel">
        <div>
          <p class="eyebrow">{{ i18n.t('review.moduleEyebrow') }}</p>
          <h2>{{ i18n.t('review.moduleSummaries') }}</h2>
        </div>
        <ReviewModuleGrid :modules="modules" />
      </section>

      <section v-if="isReady" class="panel">
        <div>
          <p class="eyebrow">{{ i18n.t('review.reflectionEyebrow') }}</p>
          <h2>{{ i18n.t(titleKey) }}</h2>
          <p v-if="!hasSavedReview" class="muted">{{ i18n.t('review.periodicEmptyBody') }}</p>
        </div>

        <form class="form-grid periodic-review-form" novalidate :aria-busy="isSaving" @submit.prevent="submitReview">
          <UiNumberInput
            v-model="form.period_rating"
            :label="i18n.t('review.periodRating')"
            name="period_rating"
            :min="1"
            :max="10"
            :step="1"
            suffix="/ 10"
            :disabled="isSaving"
            :error="fieldErrors.period_rating?.[0]"
            @update:model-value="markChanged('period_rating')"
          />
          <UiTextarea v-model="form.worked_well" :label="i18n.t('review.workedWell')" name="worked_well" :rows="3" :maxlength="5000" wide :disabled="isSaving" :error="fieldErrors.worked_well?.[0]" @update:model-value="markChanged('worked_well')" />
          <UiTextarea v-model="form.did_not_work" :label="i18n.t('review.didNotWork')" name="did_not_work" :rows="3" :maxlength="5000" wide :disabled="isSaving" :error="fieldErrors.did_not_work?.[0]" @update:model-value="markChanged('did_not_work')" />
          <UiTextarea v-model="form.learned" :label="i18n.t('review.learned')" name="learned" :rows="3" :maxlength="5000" wide :disabled="isSaving" :error="fieldErrors.learned?.[0]" @update:model-value="markChanged('learned')" />
          <UiTextarea v-model="form.next_focus" :label="i18n.t('review.nextFocus')" name="next_focus" :rows="3" :maxlength="5000" wide :disabled="isSaving" :error="fieldErrors.next_focus?.[0]" @update:model-value="markChanged('next_focus')" />
          <UiTextarea v-model="form.notes" :label="i18n.t('review.notes')" name="periodic_notes" :rows="4" :maxlength="10000" wide :disabled="isSaving" :error="fieldErrors.notes?.[0]" @update:model-value="markChanged('notes')" />

          <div v-if="saveError" class="notice error wide-field" role="alert" aria-live="assertive">
            <span>{{ saveError }}</span>
            <button v-if="canRetrySave" ref="retrySaveButton" type="button" class="secondary" :disabled="isSaving" @click="submitReview">{{ i18n.t('common.retry') }}</button>
          </div>

          <div class="review-actions wide-field">
            <p v-if="isSaving" class="muted" role="status">{{ i18n.t('review.periodicSaving') }}</p>
            <p v-else-if="isSaved" class="notice success" role="status">{{ i18n.t('review.periodicSaved') }}</p>
            <p v-else-if="isDirty" class="muted">{{ i18n.t('review.unsaved') }}</p>
            <span v-else></span>
            <button ref="saveButton" type="submit" :disabled="isSaving">{{ i18n.t(isSaving ? 'review.periodicSaving' : 'review.savePeriodic') }}</button>
          </div>
        </form>

        <div class="periodic-next-actions">
          <RouterLink :to="`/planner?date=${canonicalPeriod?.end ?? anchor}`">{{ i18n.t('review.planNext') }}</RouterLink>
          <RouterLink to="/goals">{{ i18n.t('review.reviewGoals') }}</RouterLink>
        </div>
      </section>
    </AsyncState>
  </section>
</template>
