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
import { useAuthSession } from '../auth/session'

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
const session = useAuthSession()
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
const wentWellInput = ref<HTMLTextAreaElement | null>(null)
const improveTomorrowInput = ref<HTMLTextAreaElement | null>(null)
const notesInput = ref<HTMLTextAreaElement | null>(null)
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
    return 'This review date is not a valid calendar date.'
  }

  return 'The review could not be loaded. Check the service and try again.'
}

function saveFailureMessage(currentError: unknown): string {
  if (currentError instanceof ApiError && currentError.status === 422) {
    return 'Please correct the highlighted fields and try again.'
  }

  return 'The review could not be saved. Check the service and try again.'
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

  const inputs: Array<[ReviewField, HTMLElement | null]> = [
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
        <p class="eyebrow">Evening review</p>
        <h1>{{ reviewDate ? formatCalendarDate(reviewDate, session.user?.preferences.locale) : 'Your daily review' }}</h1>
      </div>
    </header>

    <section class="panel" aria-label="Daily review workspace">
      <AsyncState
        :loading="isLoading"
        :error="loadError"
        loading-title="Loading review…"
        loading-description="Restoring the reflection saved for this date."
        @retry="retryLoadReview"
      >
        <AsyncState
          :empty="isReady && !hasSavedReview"
          empty-title="No review saved yet"
          empty-description="Use the form below to reflect on this date."
        />

        <form
        v-if="isReady"
        class="form-grid review-form"
        aria-label="Daily review"
        novalidate
        :aria-busy="isSaving"
        @submit.prevent="submitReview"
        >
        <div class="rating-grid wide-field">
          <label class="field rating-field">
            <span>Mood</span>
            <strong class="rating-value">{{ form.mood }}</strong>
            <input
              ref="moodInput"
              v-model.number="form.mood"
              name="mood"
              type="range"
              min="1"
              max="10"
              aria-label="Mood"
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
            <span>Energy</span>
            <strong class="rating-value">{{ form.energy }}</strong>
            <input
              ref="energyInput"
              v-model.number="form.energy"
              name="energy"
              type="range"
              min="1"
              max="10"
              aria-label="Energy"
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
            <span>Stress</span>
            <strong class="rating-value">{{ form.stress }}</strong>
            <input
              ref="stressInput"
              v-model.number="form.stress"
              name="stress"
              type="range"
              min="1"
              max="10"
              aria-label="Stress"
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
            <span>Day rating</span>
            <strong class="rating-value">{{ form.day_rating }}</strong>
            <input
              ref="dayRatingInput"
              v-model.number="form.day_rating"
              name="day_rating"
              type="range"
              min="1"
              max="10"
              aria-label="Day rating"
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

        <label class="field wide-field">
          <span>Went well</span>
          <textarea
            ref="wentWellInput"
            v-model="form.went_well"
            name="went_well"
            rows="3"
            maxlength="5000"
            :disabled="isSaving"
            :aria-invalid="Boolean(fieldErrors.went_well?.length)"
            :aria-describedby="fieldErrors.went_well?.length ? 'review-went-well-error' : undefined"
            @input="markChanged('went_well')"
          />
          <small v-if="fieldErrors.went_well?.length" id="review-went-well-error" class="field-error">
            {{ fieldErrors.went_well[0] }}
          </small>
        </label>

        <label class="field wide-field">
          <span>Improve tomorrow</span>
          <textarea
            ref="improveTomorrowInput"
            v-model="form.improve_tomorrow"
            name="improve_tomorrow"
            rows="3"
            maxlength="5000"
            :disabled="isSaving"
            :aria-invalid="Boolean(fieldErrors.improve_tomorrow?.length)"
            :aria-describedby="fieldErrors.improve_tomorrow?.length ? 'review-improve-error' : undefined"
            @input="markChanged('improve_tomorrow')"
          />
          <small v-if="fieldErrors.improve_tomorrow?.length" id="review-improve-error" class="field-error">
            {{ fieldErrors.improve_tomorrow[0] }}
          </small>
        </label>

        <label class="field wide-field">
          <span>Notes</span>
          <textarea
            ref="notesInput"
            v-model="form.notes"
            name="notes"
            rows="4"
            maxlength="10000"
            :disabled="isSaving"
            :aria-invalid="Boolean(fieldErrors.notes?.length)"
            :aria-describedby="fieldErrors.notes?.length ? 'review-notes-error' : undefined"
            @input="markChanged('notes')"
          />
          <small v-if="fieldErrors.notes?.length" id="review-notes-error" class="field-error">
            {{ fieldErrors.notes[0] }}
          </small>
        </label>

        <div v-if="saveError" class="notice error wide-field" role="alert" aria-live="assertive">
          <span>{{ saveError }}</span>
          <button v-if="canRetrySave" ref="retrySaveButton" type="button" class="secondary" :disabled="isSaving" @click="submitReview">
            Retry
          </button>
        </div>

        <div class="review-actions wide-field">
          <p v-if="isSaving" class="muted" role="status">Saving review…</p>
          <p v-else-if="isSaved" class="notice success" role="status">Review saved.</p>
          <p v-else-if="isDirty" class="muted">Unsaved changes.</p>
          <span v-else></span>
          <button ref="saveButton" type="submit" :disabled="isSaving">
            {{ isSaving ? 'Saving review…' : 'Save review' }}
          </button>
        </div>
        </form>
      </AsyncState>
    </section>
  </section>
</template>
