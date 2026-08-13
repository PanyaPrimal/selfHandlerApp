<script setup lang="ts">
import type { SupplementAdherenceSummary } from '../../api/types'
import { useI18n } from '../../i18n'
defineProps<{ summary: SupplementAdherenceSummary }>()
const { t, number } = useI18n()
</script>

<template>
  <article class="adherence-card">
    <div><span class="token-caption">{{ t('supplements.adherence') }}</span><strong>{{ summary.adherence_percentage === null ? '—' : `${number(summary.adherence_percentage)}%` }}</strong></div>
    <dl><div><dt>{{ t('supplements.done') }}</dt><dd>{{ summary.done }}</dd></div><div><dt>{{ t('supplements.skipped') }}</dt><dd>{{ summary.skipped }}</dd></div><div><dt>{{ t('supplements.overdue') }}</dt><dd>{{ summary.overdue }}</dd></div><div><dt>{{ t('supplements.pending') }}</dt><dd>{{ summary.pending }}</dd></div></dl>
  </article>
</template>
