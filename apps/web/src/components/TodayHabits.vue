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
    const updated = await upsertHabitLog(habit.id, props.date, { outcome: habit.mode === 'abstinence' ? 'protected' : 'done', occurred_time: occurredTime })
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
  <section class="panel today-habits" :aria-label="t('today.habits')">
    <div class="section-heading">
      <h2>{{ t('today.habits') }}</h2>
      <RouterLink :to="`/habits?date=${props.date}`">{{ t('today.manage') }}</RouterLink>
    </div>
    <p class="muted habit-hint">{{ t('calm.habitHint') }}</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <p v-if="!props.habits.length" class="muted">{{ t('today.noHabits') }}</p>
    <ul v-else class="item-list">
      <li v-for="habit in props.habits" :key="habit.id" class="today-habit" :aria-label="habit.name">
        <div>
          <strong :class="{ 'habit-completed': habit.selected_day.log?.successful }">{{ habit.name }}</strong>
          <p class="habit-status" :class="{ 'is-done': habit.selected_day.log?.successful }">
            <span v-if="habit.selected_day.log?.successful" aria-hidden="true">✓ </span>{{ status(habit) }}
          </p>
          <p v-if="habit.weekly_progress" class="muted">
            {{ t('habit.weeklyProgress', { done: habit.weekly_progress.completed, target: habit.weekly_progress.target }) }}
            <span v-if="habit.weekly_progress.achieved"> · {{ t('habit.weeklyAchieved') }}</span>
          </p>
        </div>
        <div class="today-habit__actions">
          <template v-if="['yes_no', 'abstinence'].includes(habit.mode) && habit.selected_day.is_scheduled && today && date <= today">
            <button type="button" class="habit-check" :class="{ 'is-checked': habit.selected_day.log?.successful }" :aria-pressed="Boolean(habit.selected_day.log?.successful)" :disabled="busy !== null" :aria-label="t(habit.selected_day.log?.successful ? 'habit.clearNamed' : habit.mode === 'abstinence' ? 'habit.markProtectedNamed' : 'habit.markDoneNamed', { name: habit.name })" @click="habit.selected_day.log?.successful ? undo(habit) : checkIn(habit)"><span aria-hidden="true">{{ habit.selected_day.log?.successful ? '✓' : '' }}</span></button>
          </template>
          <RouterLink v-else :to="`/habits?date=${props.date}`">{{ t(habit.selected_day.log ? 'common.edit' : 'today.openHabits') }}</RouterLink>
        </div>
      </li>
    </ul>
  </section>
</template>
<style scoped>
.today-habits .item-list { gap: 0; }
.today-habits .today-habit { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding: .85rem 0; border: 0; border-bottom: 1px solid var(--border); border-radius: 0; }
.today-habit:last-child { border-bottom: 0; }
.today-habit strong { font-weight: 600; font-size: .92rem; }
.habit-hint { font-size: .8rem; margin-top: -.4rem; }
.today-habit p { font-size: .78rem; }
.habit-check { width: 44px; min-height: 44px; padding: 0; background: var(--surface); border: 1.5px solid var(--border-strong); color: var(--subtle); }
.habit-check.is-checked { color: white; background: var(--accent); border-color: var(--accent); }
.habit-completed { color: var(--muted); }
.today-habit > div { min-width:0; overflow-wrap:anywhere; }
.today-habit__actions { display:flex; flex-wrap:wrap; gap:.5rem; align-items:center; justify-content:flex-end; }
.today-habit__actions a { min-height:44px; display:flex; align-items:center; font-size: .8rem; color: var(--accent); text-decoration: none; }
.today-habit p { margin:.35rem 0 0; }
.habit-status.is-done { color:var(--accent); font-weight:600; }
</style>
