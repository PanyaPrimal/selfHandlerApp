<script setup lang="ts">
import { computed } from 'vue'
import type { TodayResponse } from '../api/types'
import { formatCalendarDate } from '../lib/format'
import { useAuthSession } from '../auth/session'
import { useI18n } from '../i18n'

const session = useAuthSession()
const { t } = useI18n()

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
        <p class="eyebrow">{{ t('summary.consistency') }}</p>
        <h2 id="recent-progress-heading">{{ t('summary.recent') }}</h2>
      </div>
      <p class="muted">
        {{ formatCalendarDate(progress.period_start, session.user?.preferences.locale) }}–{{ formatCalendarDate(progress.period_end, session.user?.preferences.locale) }}
      </p>
    </div>

    <div v-if="progress.seven_day.scheduled === 0" class="progress-empty">
      <h3>{{ t('summary.empty') }}</h3>
      <p class="muted">{{ t('summary.emptyBody') }}</p>
    </div>

    <div v-else class="summary-grid progress-summary-grid">
      <div class="metric">
        <span>{{ t('summary.completion') }}</span>
        <strong>{{ completionLabel }}</strong>
        <div
          class="progress-track"
          role="progressbar"
          :aria-label="t('summary.sevenDayCompletion')"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-valuenow="Math.round(progress.seven_day.completion_rate)"
        >
          <div class="progress-fill" :style="{ width: progressWidth }"></div>
        </div>
      </div>
      <div class="metric">
        <span>{{ t('summary.scheduled') }}</span>
        <strong>{{ progress.seven_day.scheduled }}</strong>
      </div>
      <div class="metric">
        <span>{{ t('summary.done') }}</span>
        <strong>{{ progress.seven_day.done }}</strong>
      </div>
      <div class="metric">
        <span>{{ t('summary.skipped') }}</span>
        <strong>{{ progress.seven_day.skipped }}</strong>
      </div>
      <div class="metric">
        <span>{{ t('summary.pending') }}</span>
        <strong>{{ progress.seven_day.pending }}</strong>
      </div>
    </div>
  </section>
</template>
