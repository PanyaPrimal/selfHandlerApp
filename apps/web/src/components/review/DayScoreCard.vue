<script setup lang="ts">
import type { DayScore, DayScoreComponentKey, DayScoreReason } from '../../api/types'
import { useI18n } from '../../i18n'

defineProps<{ score: DayScore }>()

const i18n = useI18n()
const componentKeys: Record<DayScoreComponentKey, Parameters<typeof i18n.t>[0]> = {
  nutrition: 'review.scoreComponentNutrition',
  workouts: 'review.scoreComponentWorkouts',
  supplements: 'review.scoreComponentSupplements',
  habits: 'review.scoreComponentHabits',
  planner: 'review.scoreComponentPlanner',
}
const reasonKeys: Record<DayScoreReason, Parameters<typeof i18n.t>[0]> = {
  available: 'review.scoreReasonAvailable',
  no_target_evidence: 'review.scoreReasonNoTargetEvidence',
  no_workout: 'review.scoreReasonNoWorkout',
  no_scheduled_items: 'review.scoreReasonNoScheduledItems',
  no_planner_items: 'review.scoreReasonNoPlannerItems',
}
</script>

<template>
  <section class="panel day-score-card" :aria-label="i18n.t('review.scoreTitle')">
    <div class="day-score-heading">
      <div>
        <p class="eyebrow">{{ i18n.t('review.scoreEyebrow') }}</p>
        <h2>{{ i18n.t('review.scoreTitle') }}</h2>
      </div>
      <strong>{{ score.value === null ? '—' : `${i18n.number(score.value)}%` }}</strong>
    </div>
    <p class="muted">
      {{ score.value === null
        ? i18n.t('review.scoreUnavailable')
        : i18n.t('review.scoreCoverage', { available: score.available_components, total: score.total_components }) }}
    </p>
    <div class="score-components">
      <div v-for="component in score.components" :key="component.key" class="score-component">
        <span>{{ i18n.t(componentKeys[component.key]) }}</span>
        <strong>{{ component.value === null ? '—' : `${i18n.number(component.value)}%` }}</strong>
        <small>{{ i18n.t(reasonKeys[component.reason]) }}</small>
      </div>
    </div>
  </section>
</template>
