<script setup lang="ts">
import { computed, reactive } from 'vue'
import type { FinanceAccount, FinanceCategory, FinanceTransactionGroup, FinanceTransactionInput, FinanceTransferInput } from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{ accounts: FinanceAccount[], categories: FinanceCategory[], transactions: FinanceTransactionGroup[], today: string, busy?: boolean }>()
const emit = defineEmits<{ actual: [FinanceTransactionInput], transfer: [FinanceTransferInput], reverse: [FinanceTransactionGroup] }>()
const i18n = useI18n()
const actual = reactive({ kind: 'expense' as 'income' | 'expense', account_id: 0, category_id: 0, amount: '', occurred_on: props.today, note: '', tag: '' })
const transfer = reactive({ source_account_id: 0, destination_account_id: 0, source_amount: '', destination_amount: '', occurred_on: props.today, note: '' })
const activeAccounts = computed(() => props.accounts.filter((item) => !item.archived))
const matchingCategories = computed(() => props.categories.filter((item) => item.direction === actual.kind && !item.archived))

function saveActual(): void {
  emit('actual', {
    idempotency_key: `actual-${Date.now()}`,
    kind: actual.kind,
    account_id: actual.account_id,
    category_id: actual.category_id,
    amount: actual.amount,
    occurred_on: actual.occurred_on,
    note: actual.note || null,
    tag: actual.tag || null,
  })
}

function saveTransfer(): void {
  emit('transfer', {
    idempotency_key: `transfer-${Date.now()}`,
    source_account_id: transfer.source_account_id,
    destination_account_id: transfer.destination_account_id,
    source_amount: transfer.source_amount,
    destination_amount: transfer.destination_amount,
    occurred_on: transfer.occurred_on,
    note: transfer.note || null,
    tag: null,
  })
}
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-activity-heading">
    <div class="section-heading"><div><h2 id="finance-activity-heading">{{ i18n.t('finance.activity') }}</h2><p class="muted">{{ i18n.t('finance.activityHelp') }}</p></div></div>
    <div class="finance-editor-grid">
      <form class="finance-form finance-form--stack" :aria-label="i18n.t('finance.actualEditor')" @submit.prevent="saveActual">
        <h3>{{ i18n.t('finance.actual') }}</h3>
        <label><span>{{ i18n.t('finance.direction') }}</span><select v-model="actual.kind"><option value="expense">{{ i18n.t('finance.expense') }}</option><option value="income">{{ i18n.t('finance.income') }}</option></select></label>
        <label><span>{{ i18n.t('finance.account') }}</span><select v-model="actual.account_id" required><option :value="0" disabled>{{ i18n.t('finance.chooseAccount') }}</option><option v-for="item in activeAccounts" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }}</option></select></label>
        <label><span>{{ i18n.t('finance.category') }}</span><select v-model="actual.category_id" required><option :value="0" disabled>{{ i18n.t('finance.chooseCategory') }}</option><option v-for="item in matchingCategories" :key="item.id" :value="item.id">{{ item.label }}</option></select></label>
        <label><span>{{ i18n.t('finance.amount') }}</span><input v-model="actual.amount" inputmode="decimal" required></label>
        <label><span>{{ i18n.t('finance.date') }}</span><input v-model="actual.occurred_on" type="date" :max="today" required></label>
        <label><span>{{ i18n.t('finance.note') }}</span><input v-model="actual.note" maxlength="1000"></label>
        <button type="submit" :disabled="busy || !actual.account_id || !actual.category_id">{{ i18n.t('finance.postActual') }}</button>
      </form>
      <form class="finance-form finance-form--stack" :aria-label="i18n.t('finance.transferEditor')" @submit.prevent="saveTransfer">
        <h3>{{ i18n.t('finance.transfer') }}</h3>
        <label><span>{{ i18n.t('finance.sourceAccount') }}</span><select v-model="transfer.source_account_id" required><option :value="0" disabled>{{ i18n.t('finance.chooseAccount') }}</option><option v-for="item in activeAccounts" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }}</option></select></label>
        <label><span>{{ i18n.t('finance.sourceAmount') }}</span><input v-model="transfer.source_amount" inputmode="decimal" required></label>
        <label><span>{{ i18n.t('finance.destinationAccount') }}</span><select v-model="transfer.destination_account_id" required><option :value="0" disabled>{{ i18n.t('finance.chooseAccount') }}</option><option v-for="item in activeAccounts" :key="item.id" :value="item.id">{{ item.name }} · {{ item.currency }}</option></select></label>
        <label><span>{{ i18n.t('finance.destinationAmount') }}</span><input v-model="transfer.destination_amount" inputmode="decimal" required></label>
        <label><span>{{ i18n.t('finance.date') }}</span><input v-model="transfer.occurred_on" type="date" :max="today" required></label>
        <label><span>{{ i18n.t('finance.note') }}</span><input v-model="transfer.note" maxlength="1000"></label>
        <button type="submit" :disabled="busy || !transfer.source_account_id || !transfer.destination_account_id">{{ i18n.t('finance.postTransfer') }}</button>
      </form>
    </div>
    <div class="finance-history" role="list" :aria-label="i18n.t('finance.history')">
      <article v-for="group in transactions" :key="group.id" role="listitem" class="finance-history-item">
        <header><div><span class="token-caption">{{ group.occurred_on }} · {{ i18n.t(`finance.kind.${group.kind}` as never) }}</span><h3>{{ group.note || i18n.t('finance.noNote') }}</h3></div><span v-if="group.reversed_by_id || group.reverses_id" class="status-chip">{{ i18n.t(group.reverses_id ? 'finance.reversal' : 'finance.reversed') }}</span></header>
        <div class="finance-legs"><span v-for="entry in group.entries" :key="entry.id"><span>{{ entry.account_name }}<small v-if="entry.category_label"> · {{ entry.category_label }}</small></span><strong>{{ financeAmount(entry.delta_amount, entry.currency, i18n.locale.value) }}</strong></span></div>
        <button v-if="!group.reversed_by_id && !group.reverses_id" type="button" class="text-button" :disabled="busy" @click="emit('reverse', group)">{{ i18n.t('finance.reverse') }}</button>
      </article>
    </div>
    <p v-if="transactions.length === 0" class="empty-copy">{{ i18n.t('finance.noTransactions') }}</p>
  </section>
</template>
