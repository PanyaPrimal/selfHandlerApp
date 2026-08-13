<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  FinanceBudget, FinanceBudgetInput, FinanceBudgetUpdate, FinanceCategory, FinanceCurrency,
  FinanceCurrencyCode,
} from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{
  budgets: FinanceBudget[]
  categories: FinanceCategory[]
  currencies: FinanceCurrency[]
  month: string
  busy?: boolean
  save: (payload: FinanceBudgetInput | FinanceBudgetUpdate, id?: number) => Promise<boolean>
  remove: (budget: FinanceBudget) => Promise<boolean>
}>()
const emit = defineEmits<{ 'update:month': [string] }>()
const i18n = useI18n()
const editing = ref<number | null>(null)
const draft = reactive<{ category_id: number, limit_amount: string, currency: FinanceCurrencyCode }>({ category_id: 0, limit_amount: '', currency: 'UAH' })
const expenseCategories = computed(() => props.categories.filter((item) => item.direction === 'expense' && !item.archived))
const currencyOptions = computed(() => props.currencies.filter((item) => item.active))

watch(expenseCategories, (options) => {
  if (!options.some((item) => item.id === draft.category_id)) draft.category_id = options[0]?.id ?? 0
}, { immediate: true })

watch(currencyOptions, (options) => {
  if (!options.some((item) => item.code === draft.currency)) draft.currency = options[0]?.code ?? 'UAH'
}, { immediate: true })

function reset(): void {
  editing.value = null
  draft.category_id = expenseCategories.value[0]?.id ?? 0
  draft.limit_amount = ''
  draft.currency = currencyOptions.value[0]?.code ?? 'UAH'
}

function edit(budget: FinanceBudget): void {
  editing.value = budget.id
  draft.category_id = budget.category.id
  draft.limit_amount = budget.limit_amount
  draft.currency = budget.currency
}

async function submit(): Promise<void> {
  const payload = {
    month: props.month,
    category_id: draft.category_id,
    limit_amount: draft.limit_amount,
    currency: draft.currency,
  }
  if (await props.save(payload, editing.value ?? undefined)) reset()
}

function percent(budget: FinanceBudget): string {
  if (budget.utilization_percent === null) return '—'
  return `${new Intl.NumberFormat(i18n.locale.value, { maximumFractionDigits: 2 }).format(Number(budget.utilization_percent))}%`
}
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-budgets-heading">
    <div class="section-heading finance-planning-heading">
      <div><h2 id="finance-budgets-heading">{{ i18n.t('finance.budgets') }}</h2><p class="muted">{{ i18n.t('finance.budgetsHelp') }}</p></div>
      <label class="finance-month"><span>{{ i18n.t('finance.month') }}</span><input :value="month" type="month" @input="emit('update:month', ($event.target as HTMLInputElement).value)"></label>
    </div>
    <form class="finance-form finance-form--budget" :aria-label="i18n.t('finance.budgetEditor')" @submit.prevent="submit">
      <label><span>{{ i18n.t('finance.expenseCategory') }}</span><select v-model.number="draft.category_id" required><option v-for="category in expenseCategories" :key="category.id" :value="category.id">{{ category.label }}</option></select></label>
      <label><span>{{ i18n.t('finance.monthlyLimit') }}</span><input v-model="draft.limit_amount" inputmode="decimal" required placeholder="0.0000"></label>
      <label><span>{{ i18n.t('finance.currency') }}</span><select v-model="draft.currency"><option v-for="item in currencyOptions" :key="item.code" :value="item.code">{{ item.code }}</option></select></label>
      <div class="form-actions finance-form__actions">
        <button type="submit" :disabled="busy || !draft.category_id">{{ i18n.t(editing === null ? 'finance.addBudget' : 'finance.saveBudget') }}</button>
        <button v-if="editing !== null" type="button" class="ghost" :disabled="busy" @click="reset">{{ i18n.t('common.cancel') }}</button>
      </div>
    </form>
    <div class="finance-card-grid finance-budget-grid">
      <article v-for="budget in budgets" :key="budget.id" class="finance-card finance-budget-card" :class="`is-${budget.state ?? 'incomplete'}`">
        <header><div><span class="token-caption">{{ budget.month }} · {{ budget.currency }}</span><h3>{{ budget.category.label }}</h3></div><div class="finance-budget-state"><strong class="finance-budget-percent">{{ percent(budget) }}</strong><span v-if="budget.state" class="kind-chip">{{ i18n.t(`finance.budgetState.${budget.state}` as never) }}</span></div></header>
        <div class="finance-budget-progress" aria-hidden="true"><span :style="{ width: `${Math.min(100, Number(budget.utilization_percent ?? 0))}%` }"></span></div>
        <dl class="finance-facts">
          <div><dt>{{ i18n.t('finance.limit') }}</dt><dd>{{ financeAmount(budget.limit_amount, budget.currency, i18n.locale.value) }}</dd></div>
          <div><dt>{{ i18n.t('finance.spent') }}</dt><dd>{{ budget.actual_amount === null ? '—' : financeAmount(budget.actual_amount, budget.currency, i18n.locale.value) }}</dd></div>
          <div><dt>{{ i18n.t('finance.remaining') }}</dt><dd>{{ budget.remaining_amount === null ? '—' : financeAmount(budget.remaining_amount, budget.currency, i18n.locale.value) }}</dd></div>
        </dl>
        <p v-if="!budget.complete" class="notice">{{ i18n.t('finance.missingRates', { currencies: budget.missing_currencies.join(', ') }) }}</p>
        <details v-if="budget.conversions.length" class="finance-evidence"><summary>{{ i18n.t('finance.conversionEvidence', { count: budget.conversions.length }) }}</summary><ul><li v-for="(conversion, index) in budget.conversions" :key="`${conversion.on}-${conversion.from_currency}-${index}`">{{ conversion.on }} · {{ conversion.source_amount }} {{ conversion.from_currency }} × {{ conversion.rate }} ({{ conversion.rate_date }}) = {{ conversion.converted_amount }} {{ budget.currency }}</li></ul></details>
        <div class="form-actions"><button type="button" class="secondary" :disabled="busy" @click="edit(budget)">{{ i18n.t('common.edit') }}</button><button type="button" class="ghost danger" :disabled="busy" @click="remove(budget)">{{ i18n.t('common.delete') }}</button></div>
      </article>
    </div>
    <p v-if="budgets.length === 0" class="empty-copy">{{ i18n.t('finance.noBudgets') }}</p>
  </section>
</template>
