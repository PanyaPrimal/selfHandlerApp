<script setup lang="ts">
import type { AnalyticsPoint } from '../../api/types'
import { pointStateLabelKeys } from '../../analytics/presentation'
import { useI18n } from '../../i18n'
import { formatCalendarDate } from '../../lib/format'

defineProps<{
  points: AnalyticsPoint[]
  valueLabel: (value: string | null) => string
  reasonLabel: (reason: string) => string
}>()

const i18n = useI18n()
</script>

<template>
  <div class="analytics-table-wrap">
    <table class="analytics-table">
      <caption>{{ i18n.t('analytics.tableCaption') }}</caption>
      <thead>
        <tr>
          <th scope="col">{{ i18n.t('analytics.period') }}</th>
          <th scope="col">{{ i18n.t('analytics.value') }}</th>
          <th scope="col">{{ i18n.t('analytics.evidence') }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="point in points" :key="`${point.bucket_start}-${point.bucket_end}`">
          <th scope="row">
            {{ formatCalendarDate(point.bucket_start, i18n.locale.value) }}
            <span v-if="point.bucket_end !== point.bucket_start">– {{ formatCalendarDate(point.bucket_end, i18n.locale.value) }}</span>
          </th>
          <td>{{ valueLabel(point.value) }}</td>
          <td>
            <span class="analytics-state" :class="`is-${point.state}`">{{ i18n.t(pointStateLabelKeys[point.state]) }}</span>
            <span v-if="point.state === 'ready'" class="muted"> · {{ i18n.t('analytics.samples', { count: point.sample_count }) }}</span>
            <span v-for="reason in point.reasons" v-else :key="reason" class="analytics-reason">{{ reasonLabel(reason) }}</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
