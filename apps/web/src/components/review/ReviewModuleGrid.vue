<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { ModuleDaySummaries, PeriodicReviewModules } from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{
  modules: ModuleDaySummaries | PeriodicReviewModules
  reviewDate?: string
}>()

const i18n = useI18n()
const isDaily = (modules: ModuleDaySummaries | PeriodicReviewModules): modules is ModuleDaySummaries =>
  'routine_activities' in modules

function percentage(value: number | null): string {
  return value === null ? '—' : `${i18n.number(value)}%`
}

function money(value: string | null): string {
  return value === null ? '—' : financeAmount(value, props.modules.finance.base_currency, i18n.locale.value)
}
</script>

<template>
  <div class="summary-grid review-module-summaries" :aria-label="i18n.t('review.moduleSummaries')">
    <section class="metric" :aria-label="i18n.t('review.routineSummary')">
      <span>{{ i18n.t('review.routineSummary') }}</span>
      <strong>{{ percentage(modules.routines.completion_rate) }}</strong>
      <p class="muted">{{ i18n.t('review.resolvedOf', { resolved: modules.routines.done + modules.routines.skipped, total: modules.routines.scheduled }) }}</p>
    </section>
    <section v-if="isDaily(modules)" class="metric" :aria-label="i18n.t('review.routineActivitySummary')">
      <span>{{ i18n.t('review.routineActivitySummary') }}</span>
      <strong>{{ modules.routine_activities.completion_rate === null ? i18n.t('review.noActivities') : percentage(modules.routine_activities.completion_rate) }}</strong>
      <p v-for="template in modules.routine_activities.templates" :key="template.routine_id" class="muted">
        {{ template.name }} · {{ i18n.t('today.activitiesResolved', { resolved: template.done + template.skipped, total: template.scheduled }) }}
      </p>
    </section>
    <section class="metric" :aria-label="i18n.t('review.sleepSummary')">
      <span>{{ i18n.t('review.sleepSummary') }}</span>
      <template v-if="isDaily(modules)">
        <strong>{{ modules.sleep.selected_night?.log ? i18n.t('review.sleepRecorded') : i18n.t('review.sleepNotRecorded') }}</strong>
        <p v-if="modules.sleep.selected_night?.log" class="muted">
          {{ i18n.t('review.sleepQuality', { quality: i18n.number(modules.sleep.selected_night.log.quality) }) }}
        </p>
      </template>
      <template v-else>
        <strong>{{ modules.sleep.recorded_nights }} / {{ modules.sleep.planned_nights }}</strong>
        <p class="muted">{{ modules.sleep.average_quality === null ? i18n.t('review.sleepNotRecorded') : i18n.t('review.sleepQuality', { quality: i18n.number(modules.sleep.average_quality) }) }}</p>
      </template>
    </section>
    <section class="metric" :aria-label="i18n.t('review.workoutSummary')">
      <span>{{ i18n.t('review.workoutSummary') }}</span>
      <strong>{{ isDaily(modules) ? i18n.t('today.workoutPlanned', { count: modules.workouts.planned }) : `${modules.workouts.completed} / ${modules.workouts.planned}` }}</strong>
      <p class="muted">{{ i18n.t('review.workoutFacts', { completed: modules.workouts.completed, distance: i18n.number(modules.workouts.distance_m / 1000) }) }}</p>
    </section>
    <section class="metric" :aria-label="i18n.t('review.nutritionSummary')">
      <span>{{ i18n.t('review.nutritionSummary') }}</span>
      <strong>{{ i18n.number(Number(modules.nutrition.calories)) }} kcal</strong>
      <p class="muted">{{ i18n.t('review.mealsAndHydration', { meals: modules.nutrition.meal_count, hydration: i18n.number(Number(modules.nutrition.hydration_ml)) }) }}</p>
      <RouterLink v-if="reviewDate" :to="`/nutrition?date=${reviewDate}`">{{ i18n.t('today.openNutrition') }}</RouterLink>
    </section>
    <section class="metric" :aria-label="i18n.t('review.supplementSummary')">
      <span>{{ i18n.t('review.supplementSummary') }}</span>
      <strong>{{ percentage(modules.supplements.adherence_percentage) }}</strong>
      <p class="muted">{{ i18n.t('review.resolvedOf', { resolved: modules.supplements.done + modules.supplements.skipped, total: modules.supplements.eligible + modules.supplements.pending }) }}</p>
      <RouterLink v-if="reviewDate" :to="`/supplements?date=${reviewDate}`">{{ i18n.t('today.openSupplements') }}</RouterLink>
    </section>
    <section class="metric" :aria-label="i18n.t('review.habitSummary')">
      <span>{{ i18n.t('review.habitSummary') }}</span>
      <strong>{{ percentage(modules.habits.completion_rate) }}</strong>
      <p class="muted">{{ i18n.t('review.successfulOf', { successful: modules.habits.successful, total: modules.habits.scheduled }) }}</p>
    </section>
    <section class="metric" :aria-label="i18n.t('review.plannerSummary')">
      <span>{{ i18n.t('review.plannerSummary') }}</span>
      <strong>{{ percentage(modules.planner.completion_rate) }}</strong>
      <p class="muted">{{ i18n.t('review.plannerFacts', { done: modules.planner.done, scheduled: modules.planner.scheduled, blocks: modules.planner.time_blocks }) }}</p>
    </section>
    <section class="metric" :aria-label="i18n.t('review.financeSummary')">
      <span>{{ i18n.t('review.financeSummary') }}</span>
      <strong>{{ money(modules.finance.net) }}</strong>
      <p class="muted">{{ modules.finance.complete ? i18n.t('review.financeNet') : i18n.t('review.financeIncomplete') }}</p>
    </section>
  </div>
</template>
