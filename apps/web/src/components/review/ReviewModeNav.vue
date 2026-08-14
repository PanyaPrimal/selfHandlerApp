<script setup lang="ts">
import { RouterLink } from 'vue-router'
import type { ReviewPeriodType } from '../../api/types'
import { useI18n } from '../../i18n'

defineProps<{
  active: ReviewPeriodType
  anchor?: string
}>()

const i18n = useI18n()
</script>

<template>
  <nav class="review-mode-nav" :aria-label="i18n.t('review.modeNavigation')">
    <RouterLink
      :to="anchor ? `/review/${anchor}` : '/review'"
      :aria-current="active === 'daily' ? 'page' : undefined"
    >
      {{ i18n.t('review.modeDaily') }}
    </RouterLink>
    <RouterLink
      :to="anchor ? `/review/weekly/${anchor}` : '/review/weekly'"
      :aria-current="active === 'weekly' ? 'page' : undefined"
    >
      {{ i18n.t('review.modeWeekly') }}
    </RouterLink>
    <RouterLink
      :to="anchor ? `/review/monthly/${anchor}` : '/review/monthly'"
      :aria-current="active === 'monthly' ? 'page' : undefined"
    >
      {{ i18n.t('review.modeMonthly') }}
    </RouterLink>
  </nav>
</template>
