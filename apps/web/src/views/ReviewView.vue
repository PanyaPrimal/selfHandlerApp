<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { getDailyReview, saveDailyReview } from '../api/client'
import type { DailyReviewPayload } from '../api/types'

const route = useRoute()
const today = new Date().toISOString().slice(0, 10)
const reviewDate = computed(() => String(route.params.date ?? today))
const isLoading = ref(false)
const isSaved = ref(false)
const error = ref<string | null>(null)
const form = reactive<DailyReviewPayload>({
  mood: 5,
  energy: 5,
  stress: 5,
  day_rating: 5,
  went_well: '',
  improve_tomorrow: '',
  notes: '',
})

async function loadReview(): Promise<void> {
  isLoading.value = true
  error.value = null

  try {
    const review = await getDailyReview(reviewDate.value)

    if (review) {
      form.mood = review.mood
      form.energy = review.energy
      form.stress = review.stress
      form.day_rating = review.day_rating
      form.went_well = review.went_well
      form.improve_tomorrow = review.improve_tomorrow
      form.notes = review.notes
    }
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to load review'
  } finally {
    isLoading.value = false
  }
}

async function submitReview(): Promise<void> {
  isSaved.value = false
  await saveDailyReview(reviewDate.value, form)
  isSaved.value = true
}

onMounted(loadReview)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">Evening review</p>
        <h1>{{ reviewDate }}</h1>
      </div>
    </header>

    <div v-if="error" class="notice error">{{ error }}</div>
    <div v-if="isSaved" class="notice success">Review saved.</div>

    <section class="panel">
      <p v-if="isLoading" class="muted">Loading review...</p>

      <form v-else class="form-grid review-form" @submit.prevent="submitReview">
        <div class="rating-grid wide-field">
          <label class="field rating-field">
            <span>Mood</span>
            <strong class="rating-value">{{ form.mood ?? '-' }}</strong>
            <input v-model.number="form.mood" type="range" min="1" max="10" />
          </label>

          <label class="field rating-field">
            <span>Energy</span>
            <strong class="rating-value">{{ form.energy ?? '-' }}</strong>
            <input v-model.number="form.energy" type="range" min="1" max="10" />
          </label>

          <label class="field rating-field">
            <span>Stress</span>
            <strong class="rating-value">{{ form.stress ?? '-' }}</strong>
            <input v-model.number="form.stress" type="range" min="1" max="10" />
          </label>

          <label class="field rating-field">
            <span>Day rating</span>
            <strong class="rating-value">{{ form.day_rating ?? '-' }}</strong>
            <input v-model.number="form.day_rating" type="range" min="1" max="10" />
          </label>
        </div>

        <label class="field wide-field">
          <span>Went well</span>
          <textarea v-model="form.went_well" rows="3" />
        </label>

        <label class="field wide-field">
          <span>Improve tomorrow</span>
          <textarea v-model="form.improve_tomorrow" rows="3" />
        </label>

        <label class="field wide-field">
          <span>Notes</span>
          <textarea v-model="form.notes" rows="4" />
        </label>

        <button type="submit">Save review</button>
      </form>
    </section>
  </section>
</template>
