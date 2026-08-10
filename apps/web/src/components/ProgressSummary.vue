<script setup lang="ts">
import { computed } from 'vue'
import type { TodayResponse } from '../api/types'
import { formatCalendarDate } from '../lib/format'

const props = defineProps<{
  progress: TodayResponse['progress']
}>()

const completionLabel = computed(() => {
  const rate = props.progress.seven_day.completion_rate
  return `${Number.isInteger(rate) ? rate : rate.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')}%`
})
const progressWidth = computed(() => `${props.progress.seven_day.completion_rate}%`)
</script>

<template>
  <section class="panel" role="region" aria-labelledby="recent-progress-heading">
    <div class="section-heading">
      <div>
        <p class="eyebrow">Consistency</p>
        <h2 id="recent-progress-heading">Recent progress</h2>
      </div>
      <p class="muted">
        {{ formatCalendarDate(progress.period_start) }}–{{ formatCalendarDate(progress.period_end) }}
      </p>
    </div>

    <div v-if="progress.seven_day.scheduled === 0" class="progress-empty">
      <h3>No scheduled occurrences in this seven-day period.</h3>
      <p class="muted">Add a routine or choose a period containing scheduled days to see recent completion.</p>
    </div>

    <div v-else class="summary-grid progress-summary-grid">
      <div class="metric">
        <span>Completion</span>
        <strong>{{ completionLabel }}</strong>
        <div
          class="progress-track"
          role="progressbar"
          aria-label="Seven-day completion"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="Math.round(progress.seven_day.completion_rate)"
        >
          <div class="progress-fill" :style="{ width: progressWidth }"></div>
        </div>
      </div>
      <div class="metric">
        <span>Scheduled</span>
        <strong>{{ progress.seven_day.scheduled }}</strong>
      </div>
      <div class="metric">
        <span>Done</span>
        <strong>{{ progress.seven_day.done }}</strong>
      </div>
      <div class="metric">
        <span>Skipped</span>
        <strong>{{ progress.seven_day.skipped }}</strong>
      </div>
      <div class="metric">
        <span>Pending</span>
        <strong>{{ progress.seven_day.pending }}</strong>
      </div>
    </div>
  </section>
</template>
