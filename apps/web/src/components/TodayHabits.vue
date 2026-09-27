<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { ref } from 'vue'
import { clearHabitLog, getHabits, upsertHabitLog } from '../api/client'
import type { Habit } from '../api/types'
import { useI18n } from '../i18n'
const props = defineProps<{ habits: Habit[], date: string, today: string | null }>()
const emit = defineEmits<{ changed: [Habit] }>()
const { t } = useI18n()
const busy = ref<number | null>(null)
const error = ref('')
async function checkIn(habit: Habit): Promise<void> {
  if (busy.value !== null || !props.today || props.date > props.today) return
  busy.value = habit.id
  error.value = ''
  try {
    const occurredTime = new Intl.DateTimeFormat('en-GB', { timeZone: habit.schedule.timezone, hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date())
    const updated = await upsertHabitLog(habit.id, props.date, { outcome: 'done', occurred_time: occurredTime })
    emit('changed', updated)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('habit.resultFailed')
  } finally { busy.value = null }
}
async function undo(habit: Habit): Promise<void> {
  if (busy.value !== null) return
  busy.value = habit.id
  error.value = ''
  const date = props.date
  try {
    await clearHabitLog(habit.id, date)
    const response = await getHabits('active', date)
    const updated = response.data.find((item) => item.id === habit.id)
    if (updated) emit('changed', updated)
  } catch (cause) {
    error.value = cause instanceof Error ? cause.message : t('habit.resultFailed')
  } finally { busy.value = null }
}
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
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
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
        <div class="today-habit__actions">
          <template v-if="habit.mode === 'yes_no' && habit.selected_day.is_scheduled && today && date <= today">
            <button v-if="!habit.selected_day.log?.successful" type="button" :disabled="busy !== null" :aria-label="t('habit.markDoneNamed', { name: habit.name })" @click="checkIn(habit)">{{ t('habit.done') }}</button>
            <button v-else type="button" class="ghost" :disabled="busy !== null" :aria-label="t('habit.clearNamed', { name: habit.name })" @click="undo(habit)">{{ t('daily.undo') }}</button>
          </template>
          <RouterLink :to="`/habits?date=${props.date}`">{{ t(habit.selected_day.log ? 'common.edit' : 'today.openHabits') }}</RouterLink>
        </div>
      </li>
    </ul>
  </section>
</template>
<style scoped>
.today-habit { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding-block:1rem; }
.today-habit > div { min-width:0; overflow-wrap:anywhere; }
.today-habit__actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; justify-content:flex-end; }
.today-habit__actions a { min-height:44px; display:flex; align-items:center; }
.today-habit p { margin:.35rem 0 0; }
.habit-status.is-done { color:var(--accent); font-weight:600; }
</style>
