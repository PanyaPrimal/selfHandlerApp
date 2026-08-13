<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  createFinanceAccount,
  createFinanceBudget,
  createFinanceCategory,
  createFinanceCounterparty,
  createFinanceDebt,
  createFinanceFundMovement,
  createFinanceGoal,
  createFinanceRecurringOperation,
  createFinanceSavingFund,
  createFinanceTransaction,
  createFinanceTransfer,
  clearFinanceOccurrenceOutcome,
  deleteFinanceBudget,
  getFinanceAccounts,
  getFinanceBudgets,
  getFinanceCashFlow,
  getFinanceCategories,
  getFinanceCurrencies,
  getFinanceCounterparties,
  getFinanceDebts,
  getFinanceExchangeRates,
  getFinanceGoals,
  getFinancePlannedOccurrences,
  getFinanceRecurringOperations,
  getFinanceSavingFunds,
  getFinanceSummary,
  getFinanceTransactions,
  getToday,
  putFinanceOccurrenceOutcome,
  payFinanceDebt,
  reconcileFinanceAccount,
  reverseFinanceTransaction,
  updateFinanceAccount,
  updateFinanceBudget,
  updateFinanceCategory,
  updateFinanceDebt,
  updateFinanceGoal,
  updateFinanceRecurringOperation,
  updateFinanceSavingFund,
  upsertFinanceExchangeRate,
} from '../api/client'
import type {
  FinanceAccount, FinanceAccountInput, FinanceCategory, FinanceCategoryInput, FinanceCurrency,
  FinanceBudget, FinanceBudgetInput, FinanceBudgetUpdate, FinanceCashFlow,
  FinanceCounterparty, FinanceCounterpartyInput, FinanceDebt, FinanceDebtInput, FinanceDebtPaymentInput,
  FinanceDebtUpdate, FinanceExchangeRate, FinanceExchangeRateInput, FinanceFundMovementInput,
  FinanceGoal, FinanceGoalInput, FinanceGoalUpdate, FinancePlannedOccurrence, FinanceReconcileInput,
  FinanceRecurringOperation, FinanceRecurringOperationInput, FinanceRecurringOperationUpdate,
  FinanceSavingFund, FinanceSavingFundInput, FinanceSavingFundUpdate, FinanceSummary,
  FinanceTransactionGroup, FinanceTransactionInput, FinanceTransferInput,
} from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import FinanceAccountPanel from '../components/finance/FinanceAccountPanel.vue'
import FinanceBudgetPanel from '../components/finance/FinanceBudgetPanel.vue'
import FinanceCategoryPanel from '../components/finance/FinanceCategoryPanel.vue'
import FinanceDebtPanel from '../components/finance/FinanceDebtPanel.vue'
import FinanceFundPanel from '../components/finance/FinanceFundPanel.vue'
import FinanceGoalPanel from '../components/finance/FinanceGoalPanel.vue'
import FinanceLedgerPanel from '../components/finance/FinanceLedgerPanel.vue'
import FinancePlanPanel from '../components/finance/FinancePlanPanel.vue'
import FinanceRatePanel from '../components/finance/FinanceRatePanel.vue'
import { financeAmount } from '../finance/money'
import { useI18n } from '../i18n'
import { useAuthSession } from '../auth/session'

type Tab = 'overview' | 'accounts' | 'categories' | 'rates' | 'activity' | 'budgets' | 'plans' | 'debts' | 'funds' | 'goals'
const i18n = useI18n()
const session = useAuthSession()
const route = useRoute()
const router = useRouter()
const tabs: Tab[] = ['overview', 'accounts', 'categories', 'rates', 'activity', 'budgets', 'plans', 'debts', 'funds', 'goals']
const requestedTab = typeof route.query.tab === 'string' && tabs.includes(route.query.tab as Tab) ? route.query.tab as Tab : 'overview'
const tab = ref<Tab>(requestedTab)
const today = ref('')
const from = ref('')
const month = ref('')
const currencies = ref<FinanceCurrency[]>([])
const accounts = ref<FinanceAccount[]>([])
const categories = ref<FinanceCategory[]>([])
const rates = ref<FinanceExchangeRate[]>([])
const transactions = ref<FinanceTransactionGroup[]>([])
const summary = ref<FinanceSummary | null>(null)
const budgets = ref<FinanceBudget[]>([])
const operations = ref<FinanceRecurringOperation[]>([])
const occurrences = ref<FinancePlannedOccurrence[]>([])
const cashFlow = ref<FinanceCashFlow | null>(null)
const counterparties = ref<FinanceCounterparty[]>([])
const debts = ref<FinanceDebt[]>([])
const funds = ref<FinanceSavingFund[]>([])
const financeGoals = ref<FinanceGoal[]>([])
const loading = ref(true)
const busy = ref(false)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)
const feedback = ref<string | null>(null)
const consolidated = computed(() => summary.value?.consolidated)

function minusDays(date: string, days: number): string {
  const value = new Date(`${date}T12:00:00Z`)
  value.setUTCDate(value.getUTCDate() - days)
  return value.toISOString().slice(0, 10)
}

function lastDayOfMonth(value: string): string {
  const [year, monthNumber] = value.split('-').map(Number)
  return new Date(Date.UTC(year, monthNumber, 0)).toISOString().slice(0, 10)
}

function positiveIntegerQuery(value: unknown): number | null {
  return typeof value === 'string' && /^\d+$/.test(value) && Number(value) > 0 ? Number(value) : null
}

function transactionQuery(value: unknown): string | null {
  return typeof value === 'string'
    && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value)
    ? value : null
}

async function focusDeepLink(): Promise<void> {
  await nextTick()
  const target = tab.value === 'activity' ? transactionQuery(route.query.transaction)
    : ['debts', 'funds'].includes(tab.value) ? positiveIntegerQuery(route.query.occurrence) : null
  const selector = target === null ? (tab.value === 'goals' && /^#finance-goal-\d+$/.test(route.hash) ? route.hash : null)
    : tab.value === 'activity' ? `[data-finance-transaction="${target}"]`
      : `[data-finance-occurrence="${target}"]`
  if (!selector) return
  document.querySelector<HTMLElement>(selector)?.scrollIntoView({ block: 'center' })
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    if (!today.value) today.value = (await getToday()).date
    if (!from.value) from.value = minusDays(today.value, 29)
    if (!month.value) {
      const requestedMonth = typeof route.query.month === 'string' ? route.query.month : ''
      month.value = /^\d{4}-\d{2}$/.test(requestedMonth) && requestedMonth >= today.value.slice(0, 7)
        ? requestedMonth : today.value.slice(0, 7)
    }
    const rangeFrom = `${month.value}-01`
    const rangeTo = lastDayOfMonth(month.value)
    const data = await Promise.all([
      getFinanceCurrencies(), getFinanceAccounts(true), getFinanceCategories(undefined, true),
      getFinanceExchangeRates(), getFinanceTransactions(from.value, today.value),
      getFinanceSummary(from.value, today.value, today.value),
      getFinanceBudgets(month.value), getFinanceRecurringOperations(true),
      getFinancePlannedOccurrences(rangeFrom, rangeTo), getFinanceCashFlow(month.value),
      getFinanceCounterparties(), getFinanceDebts(), getFinanceSavingFunds(month.value), getFinanceGoals(),
    ])
    ;[currencies.value, accounts.value, categories.value, rates.value, transactions.value, summary.value,
      budgets.value, operations.value, occurrences.value, cashFlow.value, counterparties.value, debts.value,
      funds.value, financeGoals.value] = data
  } catch (current) {
    loadError.value = current instanceof Error ? current.message : i18n.t('finance.loadFailed')
  } finally {
    loading.value = false
    await focusDeepLink()
  }
}

async function mutate(action: () => Promise<unknown>, message: string): Promise<boolean> {
  busy.value = true
  error.value = null
  feedback.value = null
  try {
    await action()
    feedback.value = message
    await load()
    return true
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('finance.saveFailed')
    return false
  } finally { busy.value = false }
}

function createAccount(payload: FinanceAccountInput): void { void mutate(() => createFinanceAccount(payload), i18n.t('finance.accountCreated')) }
function lifecycleAccount(account: FinanceAccount, archived: boolean): void { void mutate(() => updateFinanceAccount(account.id, { archived }), i18n.t('finance.accountUpdated')) }
function reconcileAccount(account: FinanceAccount, payload: FinanceReconcileInput): void { void mutate(() => reconcileFinanceAccount(account.id, payload), i18n.t('finance.reconciled')) }
function createCategory(payload: FinanceCategoryInput): void { void mutate(() => createFinanceCategory(payload), i18n.t('finance.categoryCreated')) }
function lifecycleCategory(category: FinanceCategory, archived: boolean): void { void mutate(() => updateFinanceCategory(category.id, { archived }), i18n.t('finance.categoryUpdated')) }
function saveRate(payload: FinanceExchangeRateInput): void { void mutate(() => upsertFinanceExchangeRate(payload), i18n.t('finance.rateSaved')) }
function saveActual(payload: FinanceTransactionInput): void { void mutate(() => createFinanceTransaction(payload), i18n.t('finance.actualSaved')) }
function saveTransfer(payload: FinanceTransferInput): void { void mutate(() => createFinanceTransfer(payload), i18n.t('finance.transferSaved')) }
function saveBudget(payload: FinanceBudgetInput | FinanceBudgetUpdate, id?: number): Promise<boolean> {
  return mutate(() => id === undefined ? createFinanceBudget(payload as FinanceBudgetInput) : updateFinanceBudget(id, payload), i18n.t(id === undefined ? 'finance.budgetCreated' : 'finance.budgetUpdated'))
}
function removeBudget(budget: FinanceBudget): Promise<boolean> { return mutate(() => deleteFinanceBudget(budget.id), i18n.t('finance.budgetDeleted')) }
function saveRecurring(payload: FinanceRecurringOperationInput | FinanceRecurringOperationUpdate, id?: number): Promise<boolean> {
  return mutate(() => id === undefined ? createFinanceRecurringOperation(payload as FinanceRecurringOperationInput) : updateFinanceRecurringOperation(id, payload), i18n.t(id === undefined ? 'finance.recurringCreated' : 'finance.recurringUpdated'))
}
function saveCounterparty(payload: FinanceCounterpartyInput): Promise<boolean> {
  return mutate(() => createFinanceCounterparty(payload), i18n.t('finance.counterpartyCreated'))
}
function saveDebt(payload: FinanceDebtInput | FinanceDebtUpdate, id?: number): Promise<boolean> {
  return mutate(() => id === undefined ? createFinanceDebt(payload as FinanceDebtInput) : updateFinanceDebt(id, payload as FinanceDebtUpdate), i18n.t(id === undefined ? 'finance.debtCreated' : 'finance.debtUpdated'))
}
function payDebt(debt: FinanceDebt, payload: FinanceDebtPaymentInput): Promise<boolean> {
  return mutate(() => payFinanceDebt(debt.id, payload), i18n.t('finance.paymentSaved'))
}
function saveFund(payload: FinanceSavingFundInput | FinanceSavingFundUpdate, id?: number): Promise<boolean> {
  return mutate(() => id === undefined ? createFinanceSavingFund(payload as FinanceSavingFundInput) : updateFinanceSavingFund(id, payload), i18n.t(id === undefined ? 'finance.fundCreated' : 'finance.fundUpdated'))
}
function moveFund(fund: FinanceSavingFund, payload: FinanceFundMovementInput): Promise<boolean> {
  return mutate(() => createFinanceFundMovement(fund.id, payload), i18n.t('finance.movementSaved'))
}
function saveFinanceGoal(payload: FinanceGoalInput | FinanceGoalUpdate, id?: number): Promise<boolean> {
  return mutate(() => id === undefined ? createFinanceGoal(payload as FinanceGoalInput) : updateFinanceGoal(id, payload), i18n.t(id === undefined ? 'finance.goalCreated' : 'finance.goalUpdated'))
}
function setOutcome(occurrence: FinancePlannedOccurrence, outcome: 'actual' | 'skipped' | null): Promise<boolean> {
  return setOutcomeById(occurrence.id, outcome)
}
function setOutcomeById(id: number, outcome: 'actual' | 'skipped' | null): Promise<boolean> {
  return mutate(() => outcome === null ? clearFinanceOccurrenceOutcome(id) : putFinanceOccurrenceOutcome(id, outcome), i18n.t(outcome === 'actual' ? 'finance.actualSaved' : outcome === 'skipped' ? 'finance.skippedSaved' : 'finance.skipCleared'))
}
function changeMonth(value: string): void {
  if (!/^\d{4}-\d{2}$/.test(value) || value < today.value.slice(0, 7)) return
  month.value = value
  void router.replace({ query: { ...route.query, tab: tab.value === 'overview' ? undefined : tab.value, month: value } })
  void load()
}
function reverse(group: FinanceTransactionGroup): void {
  const reason = window.prompt(i18n.t('finance.reversalPrompt'))?.trim()
  if (!reason) return
  void mutate(() => reverseFinanceTransaction(group.id, { idempotency_key: `reverse-${Date.now()}`, reason }), i18n.t('finance.reversalSaved'))
}

onMounted(load)
watch(tab, (value) => {
  void router.replace({ query: { ...route.query, tab: value === 'overview' ? undefined : value, month: month.value || undefined } })
})
watch(() => route.query.tab, (value) => {
  const requested = typeof value === 'string' && tabs.includes(value as Tab) ? value as Tab : 'overview'
  if (tab.value !== requested) tab.value = requested
})
watch(() => [route.query.occurrence, route.query.transaction, route.hash], () => { void focusDeepLink() })
watch(() => session.user?.preferences.locale, (locale, previous) => {
  if (locale && locale !== previous && !loading.value) void load()
})
</script>

<template>
  <section class="view-stack finance-workspace">
    <header class="view-header finance-header"><div><p class="eyebrow">{{ i18n.t('finance.eyebrow') }}</p><h1>{{ i18n.t('finance.title') }}</h1><p class="muted">{{ i18n.t('finance.subtitle') }}</p></div></header>
    <div v-if="feedback" class="notice success" role="status" aria-live="polite">{{ feedback }}</div>
    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <nav class="tabs finance-tabs" role="tablist" :aria-label="i18n.t('finance.sections')"><button v-for="item in tabs" :key="item" type="button" role="tab" :aria-selected="tab === item" :class="{ active: tab === item }" :data-testid="`finance-tab-${item}`" @click="tab = item">{{ i18n.t(`finance.tab.${item}` as never) }}</button></nav>
    <AsyncState :loading="loading" :error="loadError" panel @retry="load">
      <section v-if="tab === 'overview' && summary" class="finance-overview" aria-labelledby="finance-overview-heading">
        <div class="section-heading"><div><h2 id="finance-overview-heading">{{ i18n.t('finance.overview') }}</h2><p class="muted">{{ from }} — {{ today }}</p></div></div>
        <div class="metrics-grid finance-metrics">
          <article class="metric"><span>{{ i18n.t('finance.totalBalance') }}</span><strong>{{ consolidated?.total === null ? '—' : financeAmount(consolidated?.total ?? '0.0000', consolidated?.base_currency ?? 'UAH', i18n.locale.value) }}</strong><small v-if="!consolidated?.complete">{{ i18n.t('finance.missingRates', { currencies: consolidated?.missing_currencies.join(', ') ?? '' }) }}</small></article>
          <article class="metric"><span>{{ i18n.t('finance.income') }}</span><strong>{{ summary.actuals.income === null ? '—' : financeAmount(summary.actuals.income, summary.actuals.base_currency, i18n.locale.value) }}</strong></article>
          <article class="metric"><span>{{ i18n.t('finance.expense') }}</span><strong>{{ summary.actuals.expense === null ? '—' : financeAmount(summary.actuals.expense, summary.actuals.base_currency, i18n.locale.value) }}</strong></article>
          <article class="metric"><span>{{ i18n.t('finance.net') }}</span><strong>{{ summary.actuals.net === null ? '—' : financeAmount(summary.actuals.net, summary.actuals.base_currency, i18n.locale.value) }}</strong></article>
        </div>
        <div class="finance-overview-list"><article v-for="account in summary.accounts" :key="account.id"><span>{{ account.name }} · {{ account.currency }}</span><strong>{{ financeAmount(account.balance, account.currency, i18n.locale.value) }}</strong></article></div>
      </section>
      <FinanceAccountPanel v-else-if="tab === 'accounts'" :accounts="accounts" :currencies="currencies" :today="today" :busy="busy" @create="createAccount" @lifecycle="lifecycleAccount" @reconcile="reconcileAccount" />
      <FinanceCategoryPanel v-else-if="tab === 'categories'" :categories="categories" :busy="busy" @create="createCategory" @lifecycle="lifecycleCategory" />
      <FinanceRatePanel v-else-if="tab === 'rates'" :currencies="currencies" :rates="rates" :today="today" :busy="busy" @save="saveRate" />
      <FinanceLedgerPanel v-else-if="tab === 'activity'" :accounts="accounts" :categories="categories" :transactions="transactions" :today="today" :focused-transaction-id="transactionQuery(route.query.transaction)" :busy="busy" @actual="saveActual" @transfer="saveTransfer" @reverse="reverse" />
      <FinanceBudgetPanel v-else-if="tab === 'budgets'" :budgets="budgets" :categories="categories" :currencies="currencies" :month="month" :busy="busy" :save="saveBudget" :remove="removeBudget" @update:month="changeMonth" />
      <FinancePlanPanel v-else-if="tab === 'plans'" :operations="operations" :occurrences="occurrences" :cash-flow="cashFlow" :accounts="accounts" :categories="categories" :month="month" :today="today" :busy="busy" :save="saveRecurring" :outcome="setOutcome" @update:month="changeMonth" />
      <FinanceDebtPanel v-else-if="tab === 'debts'" :debts="debts" :counterparties="counterparties" :accounts="accounts" :categories="categories" :currencies="currencies" :today="today" :initial-purchase-id="positiveIntegerQuery(route.query.purchase)" :focused-occurrence-id="positiveIntegerQuery(route.query.occurrence)" :busy="busy" :save-counterparty="saveCounterparty" :save-debt="saveDebt" :pay="payDebt" :skip="(id) => setOutcomeById(id, 'skipped')" />
      <FinanceFundPanel v-else-if="tab === 'funds'" :funds="funds" :occurrences="occurrences" :accounts="accounts" :categories="categories" :currencies="currencies" :month="month" :today="today" :focused-occurrence-id="positiveIntegerQuery(route.query.occurrence)" :busy="busy" :save="saveFund" :move="moveFund" :outcome="setOutcomeById" @update:month="changeMonth" />
      <FinanceGoalPanel v-else :goals="financeGoals" :debts="debts" :funds="funds" :busy="busy" :save="saveFinanceGoal" />
    </AsyncState>
  </section>
</template>
