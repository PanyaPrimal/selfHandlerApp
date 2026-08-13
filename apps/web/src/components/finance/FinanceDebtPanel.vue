<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type {
  FinanceAccount, FinanceCategory, FinanceCounterparty, FinanceCounterpartyInput, FinanceCurrency,
  FinanceDebt, FinanceDebtInput, FinanceDebtPaymentInput, FinanceDebtUpdate,
} from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{
  debts: FinanceDebt[]
  counterparties: FinanceCounterparty[]
  accounts: FinanceAccount[]
  categories: FinanceCategory[]
  currencies: FinanceCurrency[]
  today: string
  initialPurchaseId?: number | null
  focusedOccurrenceId?: number | null
  busy?: boolean
  saveCounterparty: (payload: FinanceCounterpartyInput) => Promise<boolean>
  saveDebt: (payload: FinanceDebtInput | FinanceDebtUpdate, id?: number) => Promise<boolean>
  pay: (debt: FinanceDebt, payload: FinanceDebtPaymentInput) => Promise<boolean>
  skip: (occurrenceId: number) => Promise<boolean>
}>()
const i18n = useI18n()
const editing = ref<number | null>(null)
const paying = ref<number | null>(null)
const counterparty = reactive<FinanceCounterpartyInput>({ name: '', kind: 'person', note: null })
const draft = reactive<FinanceDebtInput>({
  name: '', counterparty_id: 0, direction: 'owe', repayment_mode: 'flexible', original_amount: '',
  currency: 'UAH', originated_on: '', deadline: null, account_id: null, category_id: null,
  purchase_item_id: null, schedule: null, note: null,
})
const payment = reactive<FinanceDebtPaymentInput>({
  planned_occurrence_id: null, amount: '', account_id: 0, category_id: 0, occurred_on: '',
  idempotency_key: '', note: null,
})
const activeCounterparties = computed(() => props.counterparties.filter((item) => !item.archived))
const activeAccounts = computed(() => props.accounts.filter((item) => !item.archived))
const matchingAccounts = computed(() => activeAccounts.value.filter((item) => item.currency === draft.currency))
const matchingCategories = computed(() => props.categories.filter((item) => !item.archived
  && item.direction === (draft.direction === 'owe' ? 'expense' : 'income')))
const currencyOptions = computed(() => props.currencies.filter((item) => item.active))

watch(activeAccounts, (items) => {
  if (draft.account_id === null && items.length > 0) {
    draft.currency = items[0]!.currency
    draft.account_id = items[0]!.id
  }
}, { immediate: true })
watch(activeCounterparties, (items) => {
  if (!items.some((item) => item.id === draft.counterparty_id)) draft.counterparty_id = items[0]?.id ?? 0
}, { immediate: true })
watch(currencyOptions, (items) => {
  if (!items.some((item) => item.code === draft.currency)) draft.currency = items[0]?.code ?? 'UAH'
}, { immediate: true })
watch(matchingAccounts, (items) => {
  if (!items.some((item) => item.id === draft.account_id)) draft.account_id = items[0]?.id ?? null
}, { immediate: true })
watch(matchingCategories, (items) => {
  if (!items.some((item) => item.id === draft.category_id)) draft.category_id = items[0]?.id ?? null
}, { immediate: true })
watch(() => draft.repayment_mode, (mode) => {
  if (mode === 'fixed' && draft.schedule === null) draft.schedule = {
    installment_amount: '', installment_count: 1, interval_months: 1, monthday: 1,
    first_due_on: props.today, reminder_time: null,
  }
  if (mode === 'flexible') draft.schedule = null
})

function resetDebt(): void {
  const account = activeAccounts.value[0]
  editing.value = null
  Object.assign(draft, {
    name: '', counterparty_id: activeCounterparties.value[0]?.id ?? 0, direction: 'owe',
    repayment_mode: 'flexible', original_amount: '', currency: account?.currency ?? currencyOptions.value[0]?.code ?? 'UAH',
    originated_on: props.today, deadline: null, account_id: account?.id ?? null, category_id: null,
    purchase_item_id: props.initialPurchaseId ?? null, schedule: null, note: null,
  })
}

function edit(debt: FinanceDebt): void {
  editing.value = debt.id
  Object.assign(draft, {
    name: debt.name, counterparty_id: debt.counterparty.id, direction: debt.direction,
    repayment_mode: debt.repayment_mode, original_amount: debt.original_amount, currency: debt.currency,
    originated_on: debt.originated_on, deadline: debt.deadline, account_id: debt.account_id,
    category_id: debt.category_id, purchase_item_id: debt.purchase_item_id,
    schedule: debt.schedule ? { ...debt.schedule } : null, note: null,
  })
}

async function submitCounterparty(): Promise<void> {
  if (await props.saveCounterparty({ ...counterparty })) Object.assign(counterparty, { name: '', kind: 'person', note: null })
}

async function submitDebt(): Promise<void> {
  const payload = editing.value === null ? { ...draft, schedule: draft.schedule ? { ...draft.schedule } : null } : {
    name: draft.name, counterparty_id: draft.counterparty_id, deadline: draft.deadline,
    account_id: draft.account_id, category_id: draft.category_id,
    schedule: draft.schedule ? { ...draft.schedule } : undefined, note: draft.note,
  }
  if (await props.saveDebt(payload, editing.value ?? undefined)) resetDebt()
}

function startPayment(debt: FinanceDebt, occurrenceId: number | null = null, amount = ''): void {
  paying.value = debt.id
  Object.assign(payment, {
    planned_occurrence_id: occurrenceId, amount, account_id: debt.account_id ?? 0,
    category_id: debt.category_id ?? 0, occurred_on: props.today,
    idempotency_key: `debt-payment-${debt.id}-${Date.now()}`, note: null,
  })
}

async function submitPayment(debt: FinanceDebt): Promise<void> {
  if (await props.pay(debt, { ...payment })) paying.value = null
}

resetDebt()
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-debts-heading">
    <div class="section-heading"><div><h2 id="finance-debts-heading">{{ i18n.t('finance.debts') }}</h2><p class="muted">{{ i18n.t('finance.debtsHelp') }}</p></div></div>
    <form class="finance-form finance-form--compact" :aria-label="i18n.t('finance.counterpartyEditor')" @submit.prevent="submitCounterparty">
      <label><span>{{ i18n.t('finance.counterparty') }}</span><input v-model="counterparty.name" required maxlength="160"></label>
      <label><span>{{ i18n.t('finance.counterpartyKind') }}</span><select v-model="counterparty.kind"><option v-for="kind in ['person', 'bank', 'store', 'other']" :key="kind" :value="kind">{{ i18n.t(`finance.counterpartyKind.${kind}` as never) }}</option></select></label>
      <label><span>{{ i18n.t('finance.note') }}</span><input v-model="counterparty.note" maxlength="5000"></label>
      <button type="submit" :disabled="busy">{{ i18n.t('finance.addCounterparty') }}</button>
    </form>
    <form class="finance-form finance-form--commitment" :aria-label="i18n.t('finance.debtEditor')" @submit.prevent="submitDebt">
      <label><span>{{ i18n.t('finance.name') }}</span><input v-model="draft.name" required maxlength="160"></label>
      <label><span>{{ i18n.t('finance.counterparty') }}</span><select v-model.number="draft.counterparty_id" required><option v-for="item in activeCounterparties" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
      <label><span>{{ i18n.t('finance.direction') }}</span><select v-model="draft.direction" :disabled="editing !== null"><option value="owe">{{ i18n.t('finance.debtDirection.owe') }}</option><option value="owed_to_me">{{ i18n.t('finance.debtDirection.owed_to_me') }}</option></select></label>
      <label><span>{{ i18n.t('finance.repaymentMode') }}</span><select v-model="draft.repayment_mode" :disabled="editing !== null"><option value="flexible">{{ i18n.t('finance.repaymentMode.flexible') }}</option><option value="fixed">{{ i18n.t('finance.repaymentMode.fixed') }}</option></select></label>
      <label><span>{{ i18n.t('finance.principal') }}</span><input v-model="draft.original_amount" inputmode="decimal" required :disabled="editing !== null"></label>
      <label><span>{{ i18n.t('finance.currency') }}</span><select v-model="draft.currency" :disabled="editing !== null"><option v-for="item in currencyOptions" :key="item.code" :value="item.code">{{ item.code }}</option></select></label>
      <label><span>{{ i18n.t('finance.originatedOn') }}</span><input v-model="draft.originated_on" type="date" required :disabled="editing !== null"></label>
      <label><span>{{ i18n.t('finance.deadline') }}</span><input v-model="draft.deadline" type="date"></label>
      <label><span>{{ i18n.t('finance.account') }}</span><select v-model.number="draft.account_id"><option :value="null">—</option><option v-for="item in matchingAccounts" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
      <label><span>{{ i18n.t('finance.category') }}</span><select v-model.number="draft.category_id"><option :value="null">—</option><option v-for="item in matchingCategories" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
      <label v-if="draft.purchase_item_id !== null || initialPurchaseId"><span>{{ i18n.t('finance.purchaseItemId') }}</span><input v-model.number="draft.purchase_item_id" type="number" min="1" :disabled="editing !== null"></label>
      <template v-if="draft.schedule">
        <label><span>{{ i18n.t('finance.installmentAmount') }}</span><input v-model="draft.schedule.installment_amount" inputmode="decimal" required></label>
        <label><span>{{ i18n.t('finance.installmentCount') }}</span><input v-model.number="draft.schedule.installment_count" type="number" min="1" max="120" required></label>
        <label><span>{{ i18n.t('finance.everyMonths') }}</span><input v-model.number="draft.schedule.interval_months" type="number" min="1" max="12" required></label>
        <label><span>{{ i18n.t('finance.monthDay') }}</span><input v-model.number="draft.schedule.monthday" type="number" min="1" max="31" required></label>
        <label><span>{{ i18n.t('finance.firstDueOn') }}</span><input v-model="draft.schedule.first_due_on" type="date" required></label>
        <label><span>{{ i18n.t('finance.reminderTime') }}</span><input v-model="draft.schedule.reminder_time" type="time"></label>
      </template>
      <label><span>{{ i18n.t('finance.note') }}</span><input v-model="draft.note" maxlength="5000"></label>
      <div class="form-actions finance-form__actions"><button type="submit" :disabled="busy || !draft.counterparty_id">{{ i18n.t(editing === null ? 'finance.addDebt' : 'finance.saveDebt') }}</button><button v-if="editing !== null" type="button" class="ghost" @click="resetDebt">{{ i18n.t('common.cancel') }}</button></div>
    </form>
    <div class="finance-card-grid">
      <article v-for="debt in debts" :id="`debt-${debt.id}`" :key="debt.id" class="finance-card" :class="[`is-${debt.state}`, { 'is-muted': debt.archived || !debt.active }]">
        <header><div><span class="token-caption">{{ i18n.t(`finance.debtDirection.${debt.direction}` as never) }} · {{ debt.counterparty.name }}</span><h3>{{ debt.name }}</h3></div><strong class="finance-money">{{ financeAmount(debt.remaining_amount, debt.currency, i18n.locale.value) }}</strong></header>
        <div class="finance-budget-progress" aria-hidden="true"><span :style="{ width: `${Math.min(100, debt.progress * 100)}%` }"></span></div>
        <dl class="finance-facts"><div><dt>{{ i18n.t('finance.principal') }}</dt><dd>{{ financeAmount(debt.original_amount, debt.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.paid') }}</dt><dd>{{ financeAmount(debt.paid_amount, debt.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.state') }}</dt><dd>{{ i18n.t(`finance.debtState.${debt.state}` as never) }}</dd></div></dl>
        <div class="form-actions"><button type="button" class="secondary" :disabled="busy" @click="edit(debt)">{{ i18n.t('common.edit') }}</button><button type="button" :disabled="busy || debt.state === 'settled'" @click="startPayment(debt)">{{ i18n.t('finance.recordPayment') }}</button><button type="button" class="ghost" :disabled="busy" @click="props.saveDebt({ active: !debt.active }, debt.id)">{{ i18n.t(debt.active ? 'finance.pause' : 'finance.resume') }}</button><button type="button" class="ghost" :disabled="busy" @click="props.saveDebt({ archived: !debt.archived }, debt.id)">{{ i18n.t(debt.archived ? 'finance.restore' : 'finance.archive') }}</button></div>
        <form v-if="paying === debt.id" class="finance-form finance-form--compact" :aria-label="i18n.t('finance.paymentEditor')" @submit.prevent="submitPayment(debt)">
          <label><span>{{ i18n.t('finance.amount') }}</span><input v-model="payment.amount" inputmode="decimal" required></label><label><span>{{ i18n.t('finance.date') }}</span><input v-model="payment.occurred_on" type="date" :max="today" required></label><label><span>{{ i18n.t('finance.account') }}</span><select v-model.number="payment.account_id" required><option v-for="item in activeAccounts.filter((row) => row.currency === debt.currency)" :key="item.id" :value="item.id">{{ item.name }}</option></select></label><label><span>{{ i18n.t('finance.category') }}</span><select v-model.number="payment.category_id" required><option v-for="item in categories.filter((row) => !row.archived && row.direction === (debt.direction === 'owe' ? 'expense' : 'income'))" :key="item.id" :value="item.id">{{ item.label }}</option></select></label><div class="form-actions finance-form__actions"><button type="submit" :disabled="busy">{{ i18n.t('finance.savePayment') }}</button><button type="button" class="ghost" @click="paying = null">{{ i18n.t('common.cancel') }}</button></div>
        </form>
        <details v-if="debt.occurrences.length" class="finance-evidence" :open="debt.occurrences.some((item) => item.id === focusedOccurrenceId)"><summary>{{ i18n.t('finance.scheduleHistory', { count: debt.occurrences.length }) }}</summary><ul><li v-for="occurrence in debt.occurrences" :key="occurrence.id" :class="{ 'is-deep-linked': occurrence.id === focusedOccurrenceId }" :data-finance-occurrence="occurrence.id"><span>{{ occurrence.due_on }} · {{ financeAmount(occurrence.amount, occurrence.currency, i18n.locale.value) }} · {{ i18n.t(`finance.debtOccurrenceState.${occurrence.status}` as never) }}</span><span v-if="occurrence.status !== 'paid'" class="form-actions"><button type="button" class="secondary" @click="startPayment(debt, occurrence.id, occurrence.amount)">{{ i18n.t('finance.recordPayment') }}</button><button type="button" class="ghost" @click="props.skip(occurrence.id)">{{ i18n.t('finance.skip') }}</button></span></li></ul></details>
        <details v-if="debt.payments.length" class="finance-evidence"><summary>{{ i18n.t('finance.paymentHistory', { count: debt.payments.length }) }}</summary><ul><li v-for="item in debt.payments" :key="item.id">{{ item.occurred_on }} · {{ financeAmount(item.principal_amount, item.currency, i18n.locale.value) }} · <a :href="`/finance?tab=activity&transaction=${item.transaction_public_id}`">{{ item.transaction_public_id }}</a><span v-if="item.reversed"> · {{ i18n.t('finance.reversed') }}</span></li></ul></details>
      </article>
    </div>
    <p v-if="debts.length === 0" class="empty-copy">{{ i18n.t('finance.noDebts') }}</p>
  </section>
</template>
