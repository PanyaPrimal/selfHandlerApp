<script setup lang="ts">
import type { Supplement } from '../../api/types'
import { useI18n } from '../../i18n'
defineProps<{ supplement: Supplement, busy?: boolean }>()
const emit = defineEmits<{ dismiss: [number] }>()
const { t, number } = useI18n()
</script>

<template>
  <article class="forecast-card">
    <div><span class="token-caption">{{ t('supplements.remaining') }}</span><strong>{{ number(Number(supplement.stock.remaining_quantity)) }} {{ t(`supplements.unit.${supplement.stock_unit}` as never) }}</strong></div>
    <div><span class="token-caption">{{ t('supplements.forecast') }}</span><strong>{{ t(`supplements.forecast.${supplement.forecast.status}` as never) }}</strong><small v-if="supplement.forecast.runout_on" class="muted">{{ supplement.forecast.runout_on }}</small></div>
    <div v-if="supplement.restock_proposal" class="notice warning">
      <strong>{{ t('supplements.restockSuggested') }}</strong>
      <span>{{ t('supplements.neededBy', { date: supplement.restock_proposal.needed_by }) }}</span>
      <button type="button" class="text-button" :disabled="busy" @click="emit('dismiss', supplement.restock_proposal!.id)">{{ t('supplements.dismissProposal') }}</button>
    </div>
  </article>
</template>
