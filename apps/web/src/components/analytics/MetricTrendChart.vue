<script setup lang="ts">
import { computed } from 'vue'
import type { AnalyticsPoint } from '../../api/types'
import { buildChartGeometry } from '../../analytics/presentation'

const props = defineProps<{
  points: AnalyticsPoint[]
  title: string
  description: string
  valueLabel: (value: string | null) => string
}>()

const chart = computed(() => buildChartGeometry(props.points))
const titleId = 'analytics-metric-chart-title'
const descriptionId = `${titleId}-description`
</script>

<template>
  <div class="analytics-chart-wrap">
    <svg
      class="analytics-chart"
      viewBox="0 0 720 240"
      role="img"
      :aria-labelledby="`${titleId} ${descriptionId}`"
      preserveAspectRatio="none"
    >
      <title :id="titleId">{{ title }}</title>
      <desc :id="descriptionId">{{ description }}</desc>
      <line class="analytics-chart__axis" x1="24" y1="216" x2="696" y2="216" />
      <line class="analytics-chart__axis" x1="24" y1="24" x2="24" y2="216" />
      <line
        v-for="(segment, index) in chart.segments"
        :key="`segment-${index}`"
        class="analytics-chart__line"
        v-bind="segment"
      />
      <circle
        v-for="circle in chart.circles"
        :key="`point-${circle.index}`"
        class="analytics-chart__point"
        :cx="circle.x"
        :cy="circle.y"
        r="4"
      >
        <title>{{ points[circle.index].bucket_start }}: {{ valueLabel(points[circle.index].value) }}</title>
      </circle>
    </svg>
  </div>
</template>
