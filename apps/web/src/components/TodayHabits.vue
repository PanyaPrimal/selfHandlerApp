<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { Habit } from '../api/types'
import { useI18n } from '../i18n'
const props = defineProps<{ habits: Habit[], date: string }>()
const { t } = useI18n()
function status(habit: Habit): string {
  const log = habit.selected_day.log
  if (!log) return t(habit.weekly_progress ? 'habit.weeklyFlexible' : 'today.state.pending')
  if (log.outcome === 'skipped') return t('habit.skipped')
  if (log.outcome === 'protected') return t('habit.protected')
  if (log.outcome === 'relapse') return t('habit.relapseRecorded')
  if (log.outcome === 'recorded') return t(log.successful ? 'habit.targetMet' : 'habit.belowTarget')
  return t(log.successful ? 'habit.done' : 'habit.notDone')
}
</script>
<template>
  <section class="panel" :aria-label="t('today.habits')">
    <div class="section-heading">
      <h2>{{ t('today.habits') }}</h2>
      <RouterLink :to="`/habits?date=${props.date}`">{{ t('today.manage') }}</RouterLink>
    </div>
    <p v-if="!props.habits.length" class="muted">{{ t('today.noHabits') }}</p>
    <ul v-else class="item-list">
      <li v-for="habit in props.habits" :key="habit.id" class="today-habit" :aria-label="habit.name">
        <div>
          <strong>{{ habit.name }}</strong>
          <p class="habit-status" :class="{ 'is-done': habit.selected_day.log?.successful }">
            <span v-if="habit.selected_day.log?.successful" aria-hidden="true">✓ </span>{{ status(habit) }}
          </p>
          <p v-if="habit.weekly_progress" class="muted">
            {{ t('habit.weeklyProgress', { done: habit.weekly_progress.completed, target: habit.weekly_progress.target }) }}
            <span v-if="habit.weekly_progress.achieved"> · {{ t('habit.weeklyAchieved') }}</span>
          </p>
        </div>
        <RouterLink :to="`/habits?date=${props.date}`">{{ t(habit.selected_day.log ? 'common.edit' : 'today.openHabits') }}</RouterLink>
      </li>
    </ul>
  </section>
</template>
<style scoped>
.today-habit { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding-block:1rem; }
.today-habit > div { min-width:0; overflow-wrap:anywhere; }
.today-habit > a { flex-shrink:0; min-height:44px; display:flex; align-items:center; }
.today-habit p { margin:.35rem 0 0; }
.habit-status.is-done { color:var(--accent); font-weight:600; }
</style>
