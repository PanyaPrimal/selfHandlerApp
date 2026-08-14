<script setup lang="ts">
import { computed } from 'vue'
import type { AnalyticsCorrelationFinding } from '../../api/types'
import { correlationLabelKey, directionLabelKeys, strengthLabelKeys } from '../../analytics/presentation'
import { useI18n } from '../../i18n'

const props = defineProps<{ finding: AnalyticsCorrelationFinding }>()
const i18n = useI18n()
const direction = computed(() => directionLabelKeys[props.finding.direction ?? 'none'])
const strength = computed(() => strengthLabelKeys[props.finding.strength ?? 'none'])
</script>

<template>
  <article class="analytics-correlation-card">
    <h3>{{ i18n.t(correlationLabelKey(finding.key)) }}</h3>
    <template v-if="finding.state === 'ready'">
      <div class="analytics-correlation-card__coefficient">
        <span>{{ i18n.t('analytics.correlationCoefficient') }}</span>
        <strong>{{ finding.coefficient }}</strong>
      </div>
      <dl class="analytics-facts">
        <div><dt>{{ i18n.t('analytics.correlationDirection') }}</dt><dd>{{ i18n.t(direction) }}</dd></div>
        <div><dt>{{ i18n.t('analytics.correlationStrength') }}</dt><dd>{{ i18n.t(strength) }}</dd></div>
      </dl>
    </template>
    <template v-else>
      <strong>{{ i18n.t('analytics.correlationUnavailable') }}</strong>
      <p class="muted">
        {{ finding.reason === 'zero_variance'
          ? i18n.t('analytics.zeroVariance')
          : i18n.t('analytics.insufficientSamples', { count: finding.minimum_samples }) }}
      </p>
    </template>
    <p class="muted">{{ i18n.t('analytics.correlationSamples', { count: finding.sample_count }) }}</p>
  </article>
</template>
