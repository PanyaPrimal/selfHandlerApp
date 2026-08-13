<script setup lang="ts">
import { reactive } from 'vue'
import type { FinanceCurrency, FinanceExchangeRate, FinanceExchangeRateInput } from '../../api/types'
import { useI18n } from '../../i18n'

const props = defineProps<{ currencies: FinanceCurrency[], rates: FinanceExchangeRate[], today: string, busy?: boolean }>()
const emit = defineEmits<{ save: [FinanceExchangeRateInput] }>()
const i18n = useI18n()
const draft = reactive<FinanceExchangeRateInput>({ from_currency: 'USD', to_currency: 'UAH', rate_date: props.today, rate: '' })
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-rates-heading">
    <div class="section-heading"><div><h2 id="finance-rates-heading">{{ i18n.t('finance.rates') }}</h2><p class="muted">{{ i18n.t('finance.ratesHelp') }}</p></div></div>
    <form class="finance-form" :aria-label="i18n.t('finance.rateEditor')" @submit.prevent="emit('save', { ...draft })">
      <label><span>{{ i18n.t('finance.fromCurrency') }}</span><select v-model="draft.from_currency"><option v-for="item in currencies" :key="item.code" :value="item.code">{{ item.code }}</option></select></label>
      <label><span>{{ i18n.t('finance.toCurrency') }}</span><select v-model="draft.to_currency"><option v-for="item in currencies" :key="item.code" :value="item.code">{{ item.code }}</option></select></label>
      <label><span>{{ i18n.t('finance.date') }}</span><input v-model="draft.rate_date" type="date" :max="today" required></label>
      <label><span>{{ i18n.t('finance.rate') }}</span><input v-model="draft.rate" inputmode="decimal" placeholder="41.250000000000" required></label>
      <button type="submit" :disabled="busy">{{ i18n.t('finance.saveRate') }}</button>
    </form>
    <div class="finance-rate-list">
      <article v-for="rate in rates" :key="rate.id"><strong>{{ rate.from_currency }} → {{ rate.to_currency }}</strong><span>{{ rate.rate }}</span><time :datetime="rate.rate_date">{{ rate.rate_date }}</time></article>
    </div>
    <p v-if="rates.length === 0" class="empty-copy">{{ i18n.t('finance.noRates') }}</p>
  </section>
</template>
