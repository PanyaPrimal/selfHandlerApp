<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  FinanceAccount, FinanceCategory, FinanceCurrency, FinanceFundMovementInput, FinancePlannedOccurrence,
  FinanceSavingFund, FinanceSavingFundInput, FinanceSavingFundUpdate,
} from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{
  funds: FinanceSavingFund[]
  occurrences: FinancePlannedOccurrence[]
  accounts: FinanceAccount[]
  categories: FinanceCategory[]
  currencies: FinanceCurrency[]
  month: string
  today: string
  focusedOccurrenceId?: number | null
  busy?: boolean
  save: (payload: FinanceSavingFundInput | FinanceSavingFundUpdate, id?: number) => Promise<boolean>
  move: (fund: FinanceSavingFund, payload: FinanceFundMovementInput) => Promise<boolean>
  outcome: (occurrenceId: number, outcome: 'actual' | 'skipped' | null) => Promise<boolean>
}>()
const emit = defineEmits<{ 'update:month': [string] }>()
const i18n = useI18n()
const editing = ref<number | null>(null)
const moving = ref<number | null>(null)
const draft = reactive<FinanceSavingFundInput>({
  name: '', fund_type: 'regular', storage_mode: 'virtual', account_id: 0, funding_account_id: null,
  category_id: null, currency: 'UAH', target_mode: 'explicit', target_amount: '', deadline: null,
  rule: { top_up_mode: 'none', fixed_amount: null, income_percent: null, expense_months: null,
    build_months: null, starts_on: null, monthday: null, reminder_time: null }, note: null,
})
const movement = reactive<{ action: 'top_up' | 'draw_down', amount: string, counterparty_account_id: number | null, occurred_on: string, note: string | null }>({
  action: 'top_up', amount: '', counterparty_account_id: null, occurred_on: '', note: null,
})
const activeAccounts = computed(() => props.accounts.filter((item) => !item.archived))
const matchingAccounts = computed(() => activeAccounts.value.filter((item) => item.currency === draft.currency))
const expenseCategories = computed(() => props.categories.filter((item) => !item.archived && item.direction === 'expense'))
const currencyOptions = computed(() => props.currencies.filter((item) => item.active))
const fundOccurrences = computed(() => props.occurrences.filter((item) => item.context.kind === 'fund'))

watch(activeAccounts, (items) => {
  if (draft.account_id === 0 && items.length > 0) {
    draft.currency = items[0]!.currency
    draft.account_id = items[0]!.id
  }
}, { immediate: true })
watch(currencyOptions, (items) => {
  if (!items.some((item) => item.code === draft.currency)) draft.currency = items[0]?.code ?? 'UAH'
}, { immediate: true })
watch(matchingAccounts, (items) => {
  if (!items.some((item) => item.id === draft.account_id)) draft.account_id = items[0]?.id ?? 0
  if (draft.funding_account_id && !items.some((item) => item.id === draft.funding_account_id)) draft.funding_account_id = null
}, { immediate: true })
watch(() => draft.fund_type, (type) => {
  if (type === 'regular') {
    draft.target_mode = 'explicit'
    if (!['none', 'fixed'].includes(draft.rule.top_up_mode)) draft.rule.top_up_mode = 'none'
  } else if (draft.rule.top_up_mode === 'none') draft.rule.top_up_mode = 'fixed'
})
watch(() => draft.storage_mode, (mode) => {
  if (mode === 'virtual') draft.funding_account_id = null
})
watch(() => draft.rule.top_up_mode, (mode) => {
  draft.rule.fixed_amount = mode === 'fixed' ? (draft.rule.fixed_amount ?? '') : null
  draft.rule.income_percent = mode === 'income_percent' ? (draft.rule.income_percent ?? 10) : null
  draft.rule.expense_months = mode === 'expense_months' ? (draft.rule.expense_months ?? 3) : null
  draft.rule.build_months = mode === 'expense_months' ? (draft.rule.build_months ?? 6) : null
  if (mode === 'none') {
    draft.rule.starts_on = null
    draft.rule.monthday = null
    draft.rule.reminder_time = null
  } else {
    draft.rule.starts_on ??= props.today
    draft.rule.monthday ??= 1
  }
})

function reset(): void {
  const account = activeAccounts.value[0]
  editing.value = null
  Object.assign(draft, {
    name: '', fund_type: 'regular', storage_mode: 'virtual', account_id: account?.id ?? 0,
    funding_account_id: null, category_id: null, currency: account?.currency ?? currencyOptions.value[0]?.code ?? 'UAH',
    target_mode: 'explicit', target_amount: '', deadline: null,
    rule: { top_up_mode: 'none', fixed_amount: null, income_percent: null, expense_months: null,
      build_months: null, starts_on: null, monthday: null, reminder_time: null }, note: null,
  })
}

function edit(fund: FinanceSavingFund): void {
  editing.value = fund.id
  Object.assign(draft, {
    name: fund.name, fund_type: fund.fund_type, storage_mode: fund.storage_mode,
    account_id: fund.account_id, funding_account_id: fund.funding_account_id,
    category_id: fund.category_id, currency: fund.currency, target_mode: fund.target_mode,
    target_amount: fund.projection.target_amount, deadline: fund.deadline, rule: { ...fund.rule }, note: null,
  })
}

async function submit(): Promise<void> {
  const payload = editing.value === null ? { ...draft, rule: { ...draft.rule } } : {
    name: draft.name, funding_account_id: draft.funding_account_id, category_id: draft.category_id,
    target_amount: draft.target_amount, deadline: draft.deadline, rule: { ...draft.rule }, note: draft.note,
  }
  if (await props.save(payload, editing.value ?? undefined)) reset()
}

function startMovement(fund: FinanceSavingFund): void {
  moving.value = fund.id
  Object.assign(movement, { action: 'top_up', amount: '', counterparty_account_id: fund.funding_account_id,
    occurred_on: props.today, note: null })
}

async function submitMovement(fund: FinanceSavingFund): Promise<void> {
  if (await props.move(fund, { ...movement, idempotency_key: `fund-${fund.id}-${Date.now()}` })) moving.value = null
}

function reverse(fund: FinanceSavingFund, movementId: number): void {
  void props.move(fund, { action: 'reverse', reverses_movement_id: movementId,
    idempotency_key: `fund-reverse-${movementId}-${Date.now()}`, note: i18n.t('finance.correction') })
}

reset()
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-funds-heading">
    <div class="section-heading finance-planning-heading"><div><h2 id="finance-funds-heading">{{ i18n.t('finance.funds') }}</h2><p class="muted">{{ i18n.t('finance.fundsHelp') }}</p></div><label class="finance-month"><span>{{ i18n.t('finance.month') }}</span><input :value="month" type="month" @input="emit('update:month', ($event.target as HTMLInputElement).value)"></label></div>
    <form class="finance-form finance-form--commitment" :aria-label="i18n.t('finance.fundEditor')" @submit.prevent="submit">
      <label><span>{{ i18n.t('finance.name') }}</span><input v-model="draft.name" required maxlength="160"></label>
      <label><span>{{ i18n.t('finance.fundType') }}</span><select v-model="draft.fund_type" :disabled="editing !== null"><option value="regular">{{ i18n.t('finance.fundType.regular') }}</option><option value="emergency">{{ i18n.t('finance.fundType.emergency') }}</option></select></label>
      <label><span>{{ i18n.t('finance.storageMode') }}</span><select v-model="draft.storage_mode" :disabled="editing !== null"><option value="virtual">{{ i18n.t('finance.storageMode.virtual') }}</option><option value="linked_account">{{ i18n.t('finance.storageMode.linked_account') }}</option></select></label>
      <label><span>{{ i18n.t('finance.currency') }}</span><select v-model="draft.currency" :disabled="editing !== null"><option v-for="item in currencyOptions" :key="item.code" :value="item.code">{{ item.code }}</option></select></label>
      <label><span>{{ i18n.t(draft.storage_mode === 'virtual' ? 'finance.backingAccount' : 'finance.linkedAccount') }}</span><select v-model.number="draft.account_id" required :disabled="editing !== null"><option v-for="item in matchingAccounts" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
      <label v-if="draft.storage_mode === 'linked_account'"><span>{{ i18n.t('finance.fundingAccount') }}</span><select v-model.number="draft.funding_account_id"><option :value="null">—</option><option v-for="item in matchingAccounts.filter((row) => row.id !== draft.account_id)" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
      <label><span>{{ i18n.t('finance.expenseCategory') }}</span><select v-model.number="draft.category_id"><option :value="null">—</option><option v-for="item in expenseCategories" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
      <label><span>{{ i18n.t('finance.targetMode') }}</span><select v-model="draft.target_mode" :disabled="editing !== null || draft.fund_type === 'regular'"><option value="explicit">{{ i18n.t('finance.targetMode.explicit') }}</option><option value="expense_months">{{ i18n.t('finance.targetMode.expense_months') }}</option></select></label>
      <label v-if="draft.target_mode === 'explicit'"><span>{{ i18n.t('finance.targetAmount') }}</span><input v-model="draft.target_amount" inputmode="decimal" required></label>
      <label><span>{{ i18n.t('finance.deadline') }}</span><input v-model="draft.deadline" type="date"></label>
      <label><span>{{ i18n.t('finance.topUpMode') }}</span><select v-model="draft.rule.top_up_mode"><option v-for="mode in (draft.fund_type === 'regular' ? ['none', 'fixed'] : ['fixed', 'income_percent', 'expense_months'])" :key="mode" :value="mode">{{ i18n.t(`finance.topUpMode.${mode}` as never) }}</option></select></label>
      <label v-if="draft.rule.top_up_mode === 'fixed'"><span>{{ i18n.t('finance.topUpAmount') }}</span><input v-model="draft.rule.fixed_amount" inputmode="decimal" required></label>
      <label v-if="draft.rule.top_up_mode === 'income_percent'"><span>{{ i18n.t('finance.incomePercent') }}</span><input v-model.number="draft.rule.income_percent" type="number" min="0.01" max="100" step="0.01" required></label>
      <template v-if="draft.rule.top_up_mode === 'expense_months'"><label><span>{{ i18n.t('finance.expenseMonths') }}</span><input v-model.number="draft.rule.expense_months" type="number" min="1" max="24" required></label><label><span>{{ i18n.t('finance.buildMonths') }}</span><input v-model.number="draft.rule.build_months" type="number" min="1" max="60" required></label></template>
      <template v-if="draft.rule.top_up_mode !== 'none'"><label><span>{{ i18n.t('finance.startsOn') }}</span><input v-model="draft.rule.starts_on" type="date" required></label><label><span>{{ i18n.t('finance.monthDay') }}</span><input v-model.number="draft.rule.monthday" type="number" min="1" max="31" required></label><label><span>{{ i18n.t('finance.reminderTime') }}</span><input v-model="draft.rule.reminder_time" type="time"></label></template>
      <label><span>{{ i18n.t('finance.note') }}</span><input v-model="draft.note" maxlength="5000"></label>
      <div class="form-actions finance-form__actions"><button type="submit" :disabled="busy || !draft.account_id">{{ i18n.t(editing === null ? 'finance.addFund' : 'finance.saveFund') }}</button><button v-if="editing !== null" type="button" class="ghost" @click="reset">{{ i18n.t('common.cancel') }}</button></div>
    </form>
    <div class="finance-card-grid">
      <article v-for="fund in funds" :id="`fund-${fund.id}`" :key="fund.id" class="finance-card" :class="[`is-${fund.projection.state}`, { 'is-muted': fund.archived || !fund.active }]">
        <header><div><span class="token-caption">{{ i18n.t(`finance.fundType.${fund.fund_type}` as never) }} · {{ i18n.t(`finance.storageMode.${fund.storage_mode}` as never) }}</span><h3>{{ fund.name }}</h3></div><strong class="finance-money">{{ financeAmount(fund.projection.saved_amount, fund.currency, i18n.locale.value) }}</strong></header>
        <div class="finance-budget-progress" aria-hidden="true"><span :style="{ width: `${Math.min(100, Number(fund.projection.progress ?? 0) * 100)}%` }"></span></div>
        <dl class="finance-facts"><div><dt>{{ i18n.t('finance.targetAmount') }}</dt><dd>{{ fund.projection.target_amount === null ? '—' : financeAmount(fund.projection.target_amount, fund.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.remaining') }}</dt><dd>{{ fund.projection.remaining_amount === null ? '—' : financeAmount(fund.projection.remaining_amount, fund.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.state') }}</dt><dd>{{ i18n.t(`finance.fundState.${fund.projection.state}` as never) }}</dd></div></dl>
        <p v-if="fund.projection.suggested_top_up" class="muted">{{ i18n.t('finance.suggestedTopUp') }}: {{ financeAmount(fund.projection.suggested_top_up, fund.currency, i18n.locale.value) }}</p>
        <p v-if="fund.projection.missing_history" class="notice">{{ i18n.t('finance.missingHistory') }}</p><p v-else-if="fund.projection.missing_currencies.length" class="notice">{{ i18n.t('finance.missingRates', { currencies: fund.projection.missing_currencies.join(', ') }) }}</p>
        <div class="form-actions"><button type="button" class="secondary" :disabled="busy" @click="edit(fund)">{{ i18n.t('common.edit') }}</button><button type="button" :disabled="busy || fund.spent" @click="startMovement(fund)">{{ i18n.t('finance.moveFund') }}</button><button type="button" class="ghost" :disabled="busy" @click="props.save({ active: !fund.active }, fund.id)">{{ i18n.t(fund.active ? 'finance.pause' : 'finance.resume') }}</button><button type="button" class="ghost" :disabled="busy" @click="props.save({ archived: !fund.archived }, fund.id)">{{ i18n.t(fund.archived ? 'finance.restore' : 'finance.archive') }}</button></div>
        <form v-if="moving === fund.id" class="finance-form finance-form--compact" :aria-label="i18n.t('finance.fundMovementEditor')" @submit.prevent="submitMovement(fund)"><label><span>{{ i18n.t('finance.movement') }}</span><select v-model="movement.action"><option value="top_up">{{ i18n.t('finance.topUp') }}</option><option value="draw_down">{{ i18n.t('finance.drawDown') }}</option></select></label><label><span>{{ i18n.t('finance.amount') }}</span><input v-model="movement.amount" inputmode="decimal" required></label><label><span>{{ i18n.t('finance.date') }}</span><input v-model="movement.occurred_on" type="date" :max="today" required></label><label v-if="fund.storage_mode === 'linked_account'"><span>{{ i18n.t('finance.counterpartyAccount') }}</span><select v-model.number="movement.counterparty_account_id" required><option v-for="item in activeAccounts.filter((row) => row.currency === fund.currency && row.id !== fund.account_id)" :key="item.id" :value="item.id">{{ item.name }}</option></select></label><div class="form-actions finance-form__actions"><button type="submit" :disabled="busy">{{ i18n.t('finance.saveMovement') }}</button><button type="button" class="ghost" @click="moving = null">{{ i18n.t('common.cancel') }}</button></div></form>
        <details v-if="fund.movements.length" class="finance-evidence"><summary>{{ i18n.t('finance.movementHistory', { count: fund.movements.length }) }}</summary><ul><li v-for="item in fund.movements" :key="item.id">{{ item.occurred_on }} · {{ i18n.t(`finance.movement.${item.action}` as never) }} · {{ financeAmount(item.amount, item.currency, i18n.locale.value) }}<button v-if="item.action !== 'reverse' && !item.reversed" type="button" class="ghost" :disabled="busy" @click="reverse(fund, item.id)">{{ i18n.t('finance.reverse') }}</button></li></ul></details>
        <div v-for="occurrence in fundOccurrences.filter((item) => item.context.owner_id === fund.id)" :key="occurrence.id" class="finance-occurrence" :class="{ 'is-deep-linked': occurrence.id === focusedOccurrenceId }" :data-finance-occurrence="occurrence.id"><span>{{ occurrence.date }} · {{ occurrence.time ?? i18n.t('finance.anyTime') }} · {{ occurrence.context.amount ? financeAmount(occurrence.context.amount, occurrence.context.currency, i18n.locale.value) : '—' }}</span><span class="form-actions"><button v-if="occurrence.status === 'planned'" type="button" :disabled="busy" @click="props.outcome(occurrence.id, 'actual')">{{ i18n.t('finance.topUp') }}</button><button v-if="occurrence.status === 'planned'" type="button" class="ghost" :disabled="busy" @click="props.outcome(occurrence.id, 'skipped')">{{ i18n.t('finance.skip') }}</button><button v-if="occurrence.outcome_type === 'skipped'" type="button" class="ghost" :disabled="busy" @click="props.outcome(occurrence.id, null)">{{ i18n.t('finance.clearSkip') }}</button></span></div>
      </article>
    </div>
    <p v-if="funds.length === 0" class="empty-copy">{{ i18n.t('finance.noFunds') }}</p>
  </section>
</template>
