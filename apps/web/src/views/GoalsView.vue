<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { createGoal, getGoals } from '../api/client'
import type { Goal, GoalPayload } from '../api/types'

const goals = ref<Goal[]>([])
const isLoading = ref(false)
const error = ref<string | null>(null)
const form = reactive<GoalPayload>({
  name: '',
  type: 'general',
  status: 'active',
  target_date: null,
})

async function loadGoals(): Promise<void> {
  isLoading.value = true
  error.value = null

  try {
    goals.value = await getGoals()
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : 'Failed to load goals'
  } finally {
    isLoading.value = false
  }
}

async function submitGoal(): Promise<void> {
  await createGoal(form)
  form.name = ''
  form.target_date = null
  await loadGoals()
}

onMounted(loadGoals)
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">Goals</p>
        <h1>Outcomes linked to action</h1>
      </div>
    </header>

    <div v-if="error" class="notice error">{{ error }}</div>

    <section class="panel">
      <h2>Create goal</h2>
      <form class="form-grid" @submit.prevent="submitGoal">
        <label class="field">
          <span>Name</span>
          <input v-model="form.name" required placeholder="Improve discipline" />
        </label>

        <label class="field">
          <span>Target date</span>
          <input v-model="form.target_date" type="date" />
        </label>

        <button type="submit">Create</button>
      </form>
    </section>

    <section class="panel">
      <h2>Goals</h2>
      <p v-if="isLoading" class="muted">Loading goals...</p>
      <p v-else-if="goals.length === 0" class="muted">No goals yet.</p>

      <ul v-else class="item-list">
        <li v-for="goal in goals" :key="goal.id">
          <strong>{{ goal.name }}</strong>
          <p class="muted">{{ goal.status }}<span v-if="goal.target_date"> · {{ goal.target_date }}</span></p>
        </li>
      </ul>
    </section>
  </section>
</template>
