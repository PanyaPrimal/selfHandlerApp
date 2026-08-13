<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  FinanceAccount, FinanceCashFlow, FinanceCategory, FinancePlannedOccurrence,
  FinanceRecurringOperation, FinanceRecurringOperationInput, FinanceRecurringOperationUpdate,
} from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{
  operations: FinanceRecurringOperation[]
  occurrences: FinancePlannedOccurrence[]
  cashFlow: FinanceCashFlow | null
  accounts: FinanceAccount[]
  categories: FinanceCategory[]
  month: string
  today: string
  busy?: boolean
  save: (payload: FinanceRecurringOperationInput | FinanceRecurringOperationUpdate, id?: number) => Promise<boolean>
  outcome: (occurrence: FinancePlannedOccurrence, outcome: 'actual' | 'skipped' | null) => Promise<boolean>
}>()
const emit = defineEmits<{ 'update:month': [string] }>()
const i18n = useI18n()
const editing = ref<number | null>(null)
const days = Array.from({ length: 31 }, (_, index) => index + 1)
const draft = reactive({
  name: '', direction: 'expense' as 'income' | 'expense', account_id: 0, category_id: 0,
  amount: '', mandatory: true, starts_on: '', ends_on: '', interval_months: 1,
  month_days: [] as number[], reminder_time: '',
})
const activeAccounts = computed(() => props.accounts.filter((item) => !item.archived))
const matchingCategories = computed(() => props.categories.filter((item) => item.direction === draft.direction && !item.archived))

watch(() => props.today, (value) => {
  if (!draft.starts_on) draft.starts_on = value
  if (draft.month_days.length === 0 && value) draft.month_days = [Number(value.slice(8, 10))]
}, { immediate: true })
watch(activeAccounts, (options) => {
  if (!options.some((item) => item.id === draft.account_id)) draft.account_id = options[0]?.id ?? 0
}, { immediate: true })
watch(matchingCategories, (options) => {
  if (!options.some((item) => item.id === draft.category_id)) draft.category_id = options[0]?.id ?? 0
}, { immediate: true })

function reset(): void {
  editing.value = null
  draft.name = ''
  draft.direction = 'expense'
  draft.account_id = activeAccounts.value[0]?.id ?? 0
  draft.category_id = props.categories.find((item) => item.direction === 'expense' && !item.archived)?.id ?? 0
  draft.amount = ''
  draft.mandatory = true
  draft.starts_on = props.today
  draft.ends_on = ''
  draft.interval_months = 1
  draft.month_days = props.today ? [Number(props.today.slice(8, 10))] : []
  draft.reminder_time = ''
}

function edit(operation: FinanceRecurringOperation): void {
  editing.value = operation.id
  Object.assign(draft, {
    name: operation.name, direction: operation.direction, account_id: operation.account.id,
    category_id: operation.category.id, amount: operation.amount, mandatory: operation.mandatory,
    starts_on: operation.rule.starts_on, ends_on: operation.rule.ends_on ?? '',
    interval_months: operation.rule.interval_months, month_days: [...operation.rule.month_days],
    reminder_time: operation.rule.reminder_time ?? '',
  })
}

async function submit(): Promise<void> {
  const payload: FinanceRecurringOperationInput = {
    name: draft.name,
    direction: draft.direction,
    account_id: draft.account_id,
    category_id: draft.category_id,
    amount: draft.amount,
    mandatory: draft.mandatory,
    starts_on: draft.starts_on,
    ends_on: draft.ends_on || null,
    interval_months: draft.interval_months,
    month_days: [...draft.month_days].sort((a, b) => a - b),
    reminder_time: draft.reminder_time || null,
  }
  if (await props.save(payload, editing.value ?? undefined)) reset()
}

function toggleDay(day: number, checked: boolean): void {
  draft.month_days = checked
    ? [...new Set([...draft.month_days, day])].sort((a, b) => a - b)
    : draft.month_days.filter((item) => item !== day)
}

function money(value: string | null | undefined, currency: string): string {
  return value === null || value === undefined ? '—' : financeAmount(value, currency, i18n.locale.value)
}
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-plans-heading">
    <div class="section-heading finance-planning-heading">
      <div><h2 id="finance-plans-heading">{{ i18n.t('finance.plans') }}</h2><p class="muted">{{ i18n.t('finance.plansHelp') }}</p></div>
      <label class="finance-month"><span>{{ i18n.t('finance.month') }}</span><input :value="month" type="month" :min="today.slice(0, 7)" @input="emit('update:month', ($event.target as HTMLInputElement).value)"></label>
    </div>

    <section v-if="cashFlow" class="finance-cash-flow" data-testid="finance-cash-flow" aria-labelledby="finance-cash-flow-heading">
      <div class="section-heading"><div><p class="eyebrow">{{ i18n.t('finance.forecast') }}</p><h3 id="finance-cash-flow-heading">{{ i18n.t('finance.cashFlow') }}</h3></div><span class="kind-chip">{{ cashFlow.base_currency }}</span></div>
      <div class="metrics-grid finance-metrics">
        <article class="metric"><span>{{ i18n.t('finance.plannedIncome') }}</span><strong>{{ money(cashFlow.planned_income, cashFlow.base_currency) }}</strong></article>
        <article class="metric"><span>{{ i18n.t('finance.mandatoryExpenses') }}</span><strong>{{ money(cashFlow.mandatory_expense, cashFlow.base_currency) }}</strong></article>
        <article class="metric"><span>{{ i18n.t('finance.discretionaryExpenses') }}</span><strong>{{ money(cashFlow.discretionary_expense, cashFlow.base_currency) }}</strong></article>
        <article class="metric"><span>{{ i18n.t('finance.freeCashFlow') }}</span><strong>{{ money(cashFlow.free_cash_flow, cashFlow.base_currency) }}</strong></article>
      </div>
      <p class="finance-cash-flow__counts">{{ i18n.t('finance.cashFlowCounts', { total: cashFlow.counts.total, planned: cashFlow.counts.planned, actual: cashFlow.counts.actual, skipped: cashFlow.counts.skipped }) }}</p>
      <p v-if="!cashFlow.complete" class="notice">{{ i18n.t('finance.missingRates', { currencies: cashFlow.missing_currencies.join(', ') }) }}</p>
      <details v-if="cashFlow.conversions.length" class="finance-evidence"><summary>{{ i18n.t('finance.conversionEvidence', { count: cashFlow.conversions.length }) }}</summary><ul><li v-for="(conversion, index) in cashFlow.conversions" :key="`${conversion.on}-${conversion.from_currency}-${index}`">{{ conversion.on }} · {{ conversion.source_amount }} {{ conversion.from_currency }} × {{ conversion.rate }} ({{ conversion.rate_date }}) = {{ conversion.converted_amount }} {{ cashFlow.base_currency }}</li></ul></details>
    </section>

    <form class="finance-form finance-form--plan" :aria-label="i18n.t('finance.recurringEditor')" @submit.prevent="submit">
      <label><span>{{ i18n.t('finance.name') }}</span><input v-model="draft.name" required maxlength="160"></label>
      <label><span>{{ i18n.t('finance.direction') }}</span><select v-model="draft.direction"><option value="expense">{{ i18n.t('finance.kind.expense') }}</option><option value="income">{{ i18n.t('finance.kind.income') }}</option></select></label>
      <label><span>{{ i18n.t('finance.account') }}</span><select v-model.number="draft.account_id" required><option v-for="account in activeAccounts" :key="account.id" :value="account.id">{{ account.name }} · {{ account.currency }}</option></select></label>
      <label><span>{{ i18n.t('finance.category') }}</span><select v-model.number="draft.category_id" required><option v-for="category in matchingCategories" :key="category.id" :value="category.id">{{ category.label }}</option></select></label>
      <label><span>{{ i18n.t('finance.amount') }}</span><input v-model="draft.amount" inputmode="decimal" required placeholder="0.0000"></label>
      <label><span>{{ i18n.t('finance.startsOn') }}</span><input v-model="draft.starts_on" type="date" required></label>
      <label><span>{{ i18n.t('finance.endsOn') }}</span><input v-model="draft.ends_on" type="date" :min="draft.starts_on"></label>
      <label><span>{{ i18n.t('finance.everyMonths') }}</span><input v-model.number="draft.interval_months" type="number" min="1" max="12" required></label>
      <label><span>{{ i18n.t('finance.reminderTime') }}</span><input v-model="draft.reminder_time" type="time"></label>
      <label class="finance-checkbox"><input v-model="draft.mandatory" type="checkbox"><span>{{ i18n.t('finance.mandatory') }}</span></label>
      <fieldset class="finance-monthdays"><legend>{{ i18n.t('finance.monthDays') }}</legend><label v-for="day in days" :key="day"><input type="checkbox" :aria-label="i18n.t('finance.dayNumber', { day })" :checked="draft.month_days.includes(day)" @change="toggleDay(day, ($event.target as HTMLInputElement).checked)"><span>{{ day }}</span></label></fieldset>
      <div class="form-actions finance-form__actions"><button type="submit" :disabled="busy || !draft.account_id || !draft.category_id || draft.month_days.length === 0">{{ i18n.t(editing === null ? 'finance.addRecurring' : 'finance.saveRecurring') }}</button><button v-if="editing !== null" type="button" class="ghost" :disabled="busy" @click="reset">{{ i18n.t('common.cancel') }}</button></div>
    </form>

    <div class="finance-card-grid finance-operation-grid">
      <article v-for="operation in operations" :key="operation.id" class="finance-card" :class="{ 'is-muted': operation.archived || !operation.active }">
        <header><div><span class="token-caption">{{ i18n.t(`finance.kind.${operation.direction}` as never) }} · {{ operation.account.name }}</span><h3>{{ operation.name }}</h3></div><strong class="finance-money">{{ financeAmount(operation.amount, operation.currency, i18n.locale.value) }}</strong></header>
        <p class="muted">{{ operation.category.label }} · {{ i18n.t('finance.daysSummary', { days: operation.rule.month_days.join(', ') }) }} · {{ operation.mandatory ? i18n.t('finance.mandatory') : i18n.t('finance.discretionary') }}</p>
        <div class="form-actions"><button type="button" class="secondary" :disabled="busy || operation.archived" @click="edit(operation)">{{ i18n.t('common.edit') }}</button><button v-if="!operation.archived" type="button" class="ghost" :disabled="busy" @click="save({ active: !operation.active }, operation.id)">{{ i18n.t(operation.active ? 'finance.pause' : 'finance.resume') }}</button><button type="button" class="ghost" :disabled="busy" @click="save({ archived: !operation.archived }, operation.id)">{{ i18n.t(operation.archived ? 'finance.restore' : 'finance.archive') }}</button></div>
      </article>
    </div>
    <p v-if="operations.length === 0" class="empty-copy">{{ i18n.t('finance.noRecurring') }}</p>

    <div class="section-heading finance-occurrence-heading"><div><h3>{{ i18n.t('finance.monthPlan') }}</h3><p class="muted">{{ i18n.t('finance.monthPlanHelp') }}</p></div></div>
    <div class="finance-occurrence-list">
      <article v-for="occurrence in occurrences" :key="occurrence.id" class="finance-card finance-occurrence" :class="`is-${occurrence.status}`">
        <div><span class="token-caption">{{ occurrence.effective_on }}<template v-if="occurrence.reminder_time"> · {{ occurrence.reminder_time }}</template></span><h3>{{ occurrence.operation_name }}</h3><p class="muted">{{ occurrence.category.label }} · {{ occurrence.account.name }}</p></div>
        <div class="finance-occurrence__outcome"><strong>{{ financeAmount(occurrence.amount, occurrence.currency, i18n.locale.value) }}</strong><span class="kind-chip">{{ i18n.t(`finance.status.${occurrence.status}` as never) }}</span><small v-if="occurrence.outcome?.transaction_id" class="muted">{{ i18n.t('finance.ledgerReference', { id: occurrence.outcome.transaction_id }) }}</small></div>
        <div class="form-actions">
          <button v-if="occurrence.status === 'planned'" type="button" :disabled="busy || occurrence.effective_on > today" @click="outcome(occurrence, 'actual')">{{ i18n.t(occurrence.direction === 'income' ? 'finance.markReceived' : 'finance.markPaid') }}</button>
          <button v-if="occurrence.status === 'planned'" type="button" class="secondary" :disabled="busy" @click="outcome(occurrence, 'skipped')">{{ i18n.t('finance.skip') }}</button>
          <button v-if="occurrence.status === 'skipped'" type="button" class="secondary" :disabled="busy" @click="outcome(occurrence, null)">{{ i18n.t('finance.clearSkip') }}</button>
        </div>
      </article>
    </div>
    <p v-if="occurrences.length === 0" class="empty-copy">{{ i18n.t('finance.noOccurrences') }}</p>
  </section>
</template>
