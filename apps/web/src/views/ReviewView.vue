<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  ApiError,
  getDailyReview,
  getToday,
  saveDailyReview,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import type { DailyReview, DailyReviewPayload } from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { formatCalendarDate } from '../lib/format'
import { UiTextarea } from '../components/ui'
import { useI18n } from '../i18n'

type FocusableControl = { focus: () => void }

interface ReviewForm {
  mood: number
  energy: number
  stress: number
  day_rating: number
  went_well: string
  improve_tomorrow: string
  notes: string
}

type ReviewField = keyof ReviewForm

const route = useRoute()
const i18n = useI18n()
const reviewDate = ref('')
const isLoading = ref(true)
const isReady = ref(false)
const isSaving = ref(false)
const isSaved = ref(false)
const hasSavedReview = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const canRetrySave = ref(false)
const fieldErrors = ref<ValidationErrors>({})
const form = reactive<ReviewForm>(emptyForm())
const savedSnapshot = ref(snapshot())
const moodInput = ref<HTMLInputElement | null>(null)
const energyInput = ref<HTMLInputElement | null>(null)
const stressInput = ref<HTMLInputElement | null>(null)
const dayRatingInput = ref<HTMLInputElement | null>(null)
const wentWellInput = ref<FocusableControl | null>(null)
const improveTomorrowInput = ref<FocusableControl | null>(null)
const notesInput = ref<FocusableControl | null>(null)
const retrySaveButton = ref<HTMLButtonElement | null>(null)
const saveButton = ref<HTMLButtonElement | null>(null)
const isDirty = computed(() => isReady.value && snapshot() !== savedSnapshot.value)
let loadSequence = 0

function emptyForm(): ReviewForm {
  return {
    mood: 5,
    energy: 5,
    stress: 5,
    day_rating: 5,
    went_well: '',
    improve_tomorrow: '',
    notes: '',
  }
}

function snapshot(): string {
  return JSON.stringify(form)
}

function restoreForm(review: DailyReview | null): void {
  Object.assign(form, review
    ? {
        mood: review.mood ?? 5,
        energy: review.energy ?? 5,
        stress: review.stress ?? 5,
        day_rating: review.day_rating ?? 5,
        went_well: review.went_well ?? '',
        improve_tomorrow: review.improve_tomorrow ?? '',
        notes: review.notes ?? '',
      }
    : emptyForm())
  hasSavedReview.value = review !== null
  savedSnapshot.value = snapshot()
}

function loadFailureMessage(currentError: unknown): string {
  if (currentError instanceof ApiError && currentError.status === 422) {
    return i18n.t('review.invalidDate')
  }

  return i18n.t('review.loadFailed')
}

function saveFailureMessage(currentError: unknown): string {
  if (currentError instanceof ApiError && currentError.status === 422) {
    return i18n.t('review.invalid')
  }

  return i18n.t('review.saveFailed')
}

async function loadReview(): Promise<void> {
  const sequence = ++loadSequence
  const routeDate = typeof route.params.date === 'string' && route.params.date
    ? route.params.date
    : null

  isLoading.value = true
  isReady.value = false
  isSaved.value = false
  loadError.value = null
  saveError.value = null
  fieldErrors.value = {}

  if (routeDate) {
    reviewDate.value = routeDate
  }

  try {
    let date: string
    let review: DailyReview | null

    if (routeDate) {
      date = routeDate
      review = await getDailyReview(date)
    } else {
      const today = await getToday()
      date = today.date
      review = today.review
    }

    if (sequence !== loadSequence) {
      return
    }

    reviewDate.value = date
    restoreForm(review)
    isReady.value = true
  } catch (currentError) {
    if (sequence === loadSequence) {
      loadError.value = loadFailureMessage(currentError)
    }
  } finally {
    if (sequence === loadSequence) {
      isLoading.value = false
    }
  }
}

async function retryLoadReview(): Promise<void> {
  await loadReview()

  if (isReady.value) {
    await nextTick()
    moodInput.value?.focus()
  }
}

function reviewPayload(): DailyReviewPayload {
  return {
    mood: form.mood,
    energy: form.energy,
    stress: form.stress,
    day_rating: form.day_rating,
    went_well: form.went_well,
    improve_tomorrow: form.improve_tomorrow,
    notes: form.notes,
  }
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const inputs: Array<[ReviewField, FocusableControl | null]> = [
    ['mood', moodInput.value],
    ['energy', energyInput.value],
    ['stress', stressInput.value],
    ['day_rating', dayRatingInput.value],
    ['went_well', wentWellInput.value],
    ['improve_tomorrow', improveTomorrowInput.value],
    ['notes', notesInput.value],
  ]

  inputs.find(([field]) => fieldErrors.value[field]?.length)?.[1]?.focus()
}

async function submitReview(): Promise<void> {
  if (isSaving.value || !isReady.value || !reviewDate.value) {
    return
  }

  isSaving.value = true
  isSaved.value = false
  saveError.value = null
  canRetrySave.value = false
  fieldErrors.value = {}

  try {
    const review = await saveDailyReview(reviewDate.value, reviewPayload())
    restoreForm(review)
    isSaved.value = true
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    saveError.value = saveFailureMessage(currentError)
    canRetrySave.value = !(currentError instanceof ApiError && currentError.status === 422)
    isSaving.value = false

    if (Object.keys(fieldErrors.value).length > 0) {
      await focusFirstError()
    } else if (canRetrySave.value) {
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

function markChanged(field: ReviewField): void {
  isSaved.value = false

  if (!fieldErrors.value[field]) {
    return
  }

  const remainingErrors = { ...fieldErrors.value }
  delete remainingErrors[field]
  fieldErrors.value = remainingErrors
}

watch(
  () => route.params.date,
  () => {
    void loadReview()
  },
  { immediate: true },
)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('review.eyebrow') }}</p>
        <h1>{{ reviewDate ? formatCalendarDate(reviewDate, i18n.locale.value) : i18n.t('review.daily') }}</h1>
      </div>
    </header>

    <section class="panel" :aria-label="i18n.t('review.workspace')">
      <AsyncState
        :loading="isLoading"
        :error="loadError"
        :loading-title="i18n.t('review.loading')"
        :loading-description="i18n.t('review.loadingBody')"
        @retry="retryLoadReview"
      >
        <AsyncState
          :empty="isReady && !hasSavedReview"
          :empty-title="i18n.t('review.empty')"
          :empty-description="i18n.t('review.emptyBody')"
        />

        <form
        v-if="isReady"
        class="form-grid review-form"
        :aria-label="i18n.t('review.form')"
        novalidate
        :aria-busy="isSaving"
        @submit.prevent="submitReview"
        >
        <div class="rating-grid wide-field">
          <label class="field rating-field">
            <span>{{ i18n.t('review.mood') }}</span>
            <strong class="rating-value">{{ form.mood }}</strong>
            <input
              ref="moodInput"
              v-model.number="form.mood"
              name="mood"
              type="range"
              min="1"
              max="10"
              :aria-label="i18n.t('review.mood')"
              :disabled="isSaving"
              :aria-invalid="Boolean(fieldErrors.mood?.length)"
              :aria-describedby="fieldErrors.mood?.length ? 'review-mood-error' : undefined"
              @input="markChanged('mood')"
            />
            <small v-if="fieldErrors.mood?.length" id="review-mood-error" class="field-error">
              {{ fieldErrors.mood[0] }}
            </small>
          </label>

          <label class="field rating-field">
            <span>{{ i18n.t('review.energy') }}</span>
            <strong class="rating-value">{{ form.energy }}</strong>
            <input
              ref="energyInput"
              v-model.number="form.energy"
              name="energy"
              type="range"
              min="1"
              max="10"
              :aria-label="i18n.t('review.energy')"
              :disabled="isSaving"
              :aria-invalid="Boolean(fieldErrors.energy?.length)"
              :aria-describedby="fieldErrors.energy?.length ? 'review-energy-error' : undefined"
              @input="markChanged('energy')"
            />
            <small v-if="fieldErrors.energy?.length" id="review-energy-error" class="field-error">
              {{ fieldErrors.energy[0] }}
            </small>
          </label>

          <label class="field rating-field">
            <span>{{ i18n.t('review.stress') }}</span>
            <strong class="rating-value">{{ form.stress }}</strong>
            <input
              ref="stressInput"
              v-model.number="form.stress"
              name="stress"
              type="range"
              min="1"
              max="10"
              :aria-label="i18n.t('review.stress')"
              :disabled="isSaving"
              :aria-invalid="Boolean(fieldErrors.stress?.length)"
              :aria-describedby="fieldErrors.stress?.length ? 'review-stress-error' : undefined"
              @input="markChanged('stress')"
            />
            <small v-if="fieldErrors.stress?.length" id="review-stress-error" class="field-error">
              {{ fieldErrors.stress[0] }}
            </small>
          </label>

          <label class="field rating-field">
            <span>{{ i18n.t('review.dayRating') }}</span>
            <strong class="rating-value">{{ form.day_rating }}</strong>
            <input
              ref="dayRatingInput"
              v-model.number="form.day_rating"
              name="day_rating"
              type="range"
              min="1"
              max="10"
              :aria-label="i18n.t('review.dayRating')"
              :disabled="isSaving"
              :aria-invalid="Boolean(fieldErrors.day_rating?.length)"
              :aria-describedby="fieldErrors.day_rating?.length ? 'review-day-rating-error' : undefined"
              @input="markChanged('day_rating')"
            />
            <small v-if="fieldErrors.day_rating?.length" id="review-day-rating-error" class="field-error">
              {{ fieldErrors.day_rating[0] }}
            </small>
          </label>
        </div>

        <UiTextarea
          ref="wentWellInput"
          v-model="form.went_well"
          :label="i18n.t('review.wentWell')"
          name="went_well"
          :rows="3"
          :maxlength="5000"
          wide
          :disabled="isSaving"
          :error="fieldErrors.went_well?.[0]"
          @update:model-value="markChanged('went_well')"
        />

        <UiTextarea
          ref="improveTomorrowInput"
          v-model="form.improve_tomorrow"
          :label="i18n.t('review.improveTomorrow')"
          name="improve_tomorrow"
          :rows="3"
          :maxlength="5000"
          wide
          :disabled="isSaving"
          :error="fieldErrors.improve_tomorrow?.[0]"
          @update:model-value="markChanged('improve_tomorrow')"
        />

        <UiTextarea
          ref="notesInput"
          v-model="form.notes"
          :label="i18n.t('review.notes')"
          name="notes"
          :rows="4"
          :maxlength="10000"
          wide
          :disabled="isSaving"
          :error="fieldErrors.notes?.[0]"
          @update:model-value="markChanged('notes')"
        />

        <div v-if="saveError" class="notice error wide-field" role="alert" aria-live="assertive">
          <span>{{ saveError }}</span>
          <button v-if="canRetrySave" ref="retrySaveButton" type="button" class="secondary" :disabled="isSaving" @click="submitReview">
            {{ i18n.t('common.retry') }}
          </button>
        </div>

        <div class="review-actions wide-field">
          <p v-if="isSaving" class="muted" role="status">{{ i18n.t('review.saving') }}</p>
          <p v-else-if="isSaved" class="notice success" role="status">{{ i18n.t('review.saved') }}</p>
          <p v-else-if="isDirty" class="muted">{{ i18n.t('review.unsaved') }}</p>
          <span v-else></span>
          <button ref="saveButton" type="submit" :disabled="isSaving">
            {{ i18n.t(isSaving ? 'review.saving' : 'review.save') }}
          </button>
        </div>
        </form>
      </AsyncState>
    </section>
  </section>
</template>
