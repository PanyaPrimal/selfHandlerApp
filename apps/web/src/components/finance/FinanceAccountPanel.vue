<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import type { FinanceAccount, FinanceAccountInput, FinanceCurrency, FinanceReconcileInput } from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{ accounts: FinanceAccount[], currencies: FinanceCurrency[], today: string, busy?: boolean }>()
const emit = defineEmits<{
  create: [FinanceAccountInput]
  lifecycle: [FinanceAccount, boolean]
  reconcile: [FinanceAccount, FinanceReconcileInput]
}>()
const i18n = useI18n()
const draft = reactive<FinanceAccountInput>({ name: '', type: 'cash', currency: 'UAH', opening_balance: '', opening_date: props.today, opening_note: null })
const reconciling = ref<number | null>(null)
const reconcile = reactive({ observed_balance: '', reason: '' })
const currencyOptions = computed(() => props.currencies.filter((item) => item.active))

function save(): void {
  emit('create', {
    name: draft.name,
    type: draft.type,
    currency: draft.currency,
    ...(draft.opening_balance ? { opening_balance: draft.opening_balance, opening_date: draft.opening_date, opening_note: draft.opening_note } : {}),
  })
}

function saveReconciliation(account: FinanceAccount): void {
  emit('reconcile', account, {
    idempotency_key: `reconcile-${account.id}-${Date.now()}`,
    observed_balance: reconcile.observed_balance,
    occurred_on: props.today,
    reason: reconcile.reason,
  })
}
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-accounts-heading">
    <div class="section-heading"><div><h2 id="finance-accounts-heading">{{ i18n.t('finance.accounts') }}</h2><p class="muted">{{ i18n.t('finance.accountsHelp') }}</p></div></div>
    <form class="finance-form finance-form--account" :aria-label="i18n.t('finance.accountEditor')" @submit.prevent="save">
      <label><span>{{ i18n.t('finance.name') }}</span><input v-model="draft.name" name="finance-account-name" required maxlength="120"></label>
      <label><span>{{ i18n.t('finance.accountType') }}</span><select v-model="draft.type" name="finance-account-type"><option v-for="type in ['cash', 'card', 'savings', 'currency']" :key="type" :value="type">{{ i18n.t(`finance.accountType.${type}` as never) }}</option></select></label>
      <label><span>{{ i18n.t('finance.currency') }}</span><select v-model="draft.currency" name="finance-account-currency"><option v-for="item in currencyOptions" :key="item.code" :value="item.code">{{ item.code }}</option></select></label>
      <label><span>{{ i18n.t('finance.openingBalance') }}</span><input v-model="draft.opening_balance" name="finance-opening-balance" inputmode="decimal" placeholder="0.0000"></label>
      <label><span>{{ i18n.t('finance.openingDate') }}</span><input v-model="draft.opening_date" name="finance-opening-date" type="date" :max="today"></label>
      <button type="submit" :disabled="busy">{{ i18n.t('finance.addAccount') }}</button>
    </form>
    <div class="finance-card-grid">
      <article v-for="account in accounts" :key="account.id" class="finance-card" :class="{ 'is-muted': account.archived }">
        <header><div><span class="token-caption">{{ i18n.t(`finance.accountType.${account.type}` as never) }} · {{ account.currency }}</span><h3>{{ account.name }}</h3></div><strong class="finance-money">{{ financeAmount(account.balance, account.currency, i18n.locale.value) }}</strong></header>
        <dl class="finance-facts"><div><dt>{{ i18n.t('finance.reservedAmount') }}</dt><dd>{{ financeAmount(account.reserved_amount, account.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.availableBalance') }}</dt><dd>{{ financeAmount(account.available_balance, account.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.state') }}</dt><dd>{{ account.over_reserved ? i18n.t('finance.overReserved') : '—' }}</dd></div></dl>
        <div class="form-actions"><button type="button" class="ghost" :disabled="busy" @click="emit('lifecycle', account, !account.archived)">{{ i18n.t(account.archived ? 'finance.restore' : 'finance.archive') }}</button><button v-if="!account.archived" type="button" class="secondary" @click="reconciling = reconciling === account.id ? null : account.id">{{ i18n.t('finance.reconcile') }}</button></div>
        <form v-if="reconciling === account.id" class="finance-reconcile" :aria-label="i18n.t('finance.reconcileAccount', { name: account.name })" @submit.prevent="saveReconciliation(account)">
          <label><span>{{ i18n.t('finance.observedBalance') }}</span><input v-model="reconcile.observed_balance" inputmode="decimal" required></label>
          <label><span>{{ i18n.t('finance.reason') }}</span><input v-model="reconcile.reason" required maxlength="500"></label>
          <button type="submit" :disabled="busy">{{ i18n.t('finance.saveReconciliation') }}</button>
        </form>
      </article>
    </div>
    <p v-if="accounts.length === 0" class="empty-copy">{{ i18n.t('finance.noAccounts') }}</p>
  </section>
</template>
