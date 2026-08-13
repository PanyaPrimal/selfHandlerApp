<script setup lang="ts">
import { reactive, ref } from 'vue'
import type { FinanceAccount, FinanceCategory, Supplement } from '../../api/types'
import { useI18n } from '../../i18n'
defineProps<{ supplement: Supplement, financeAccounts?: FinanceAccount[], financeCategories?: FinanceCategory[], today?: string, focusedRestockId?: number | null, busy?: boolean }>()
const emit = defineEmits<{ dismiss: [number], expense: [number, { account_id: number, category_id: number, amount: string, occurred_on: string }] }>()
const { t, number } = useI18n()
const spending = ref(false)
const expense = reactive({ account_id: 0, category_id: 0, amount: '', occurred_on: '' })

function openExpense(accounts: FinanceAccount[], categories: FinanceCategory[], today: string): void {
  spending.value = true
  expense.account_id = accounts[0]?.id ?? 0
  expense.category_id = categories[0]?.id ?? 0
  expense.amount = ''
  expense.occurred_on = today
}
</script>

<template>
  <article class="forecast-card">
    <div><span class="token-caption">{{ t('supplements.remaining') }}</span><strong>{{ number(Number(supplement.stock.remaining_quantity)) }} {{ t(`supplements.unit.${supplement.stock_unit}` as never) }}</strong></div>
    <div><span class="token-caption">{{ t('supplements.forecast') }}</span><strong>{{ t(`supplements.forecast.${supplement.forecast.status}` as never) }}</strong><small v-if="supplement.forecast.runout_on" class="muted">{{ supplement.forecast.runout_on }}</small></div>
    <div v-if="supplement.restock_proposal" class="notice warning" :class="{ 'is-deep-linked': supplement.restock_proposal.id === focusedRestockId }" :data-restock-proposal="supplement.restock_proposal.id">
      <strong>{{ t('supplements.restockSuggested') }}</strong>
      <span>{{ t('supplements.neededBy', { date: supplement.restock_proposal.needed_by }) }}</span>
      <p class="muted">{{ t('supplements.financeExpenseHelp') }}</p>
      <div class="form-actions"><button type="button" class="secondary" :disabled="busy || !financeAccounts?.length || !financeCategories?.length" @click="openExpense(financeAccounts ?? [], financeCategories ?? [], today ?? '')">{{ t('supplements.recordExpense') }}</button><button type="button" class="text-button" :disabled="busy" @click="emit('dismiss', supplement.restock_proposal!.id)">{{ t('supplements.dismissProposal') }}</button></div>
      <form v-if="spending" class="finance-form finance-form--compact" @submit.prevent="emit('expense', supplement.restock_proposal!.id, { ...expense }); spending = false"><label><span>{{ t('finance.account') }}</span><select v-model.number="expense.account_id" required><option v-for="account in financeAccounts" :key="account.id" :value="account.id">{{ account.name }} · {{ account.currency }}</option></select></label><label><span>{{ t('finance.expenseCategory') }}</span><select v-model.number="expense.category_id" required><option v-for="category in financeCategories" :key="category.id" :value="category.id">{{ category.label }}</option></select></label><label><span>{{ t('finance.amount') }}</span><input v-model="expense.amount" inputmode="decimal" required></label><label><span>{{ t('finance.date') }}</span><input v-model="expense.occurred_on" type="date" required></label><div class="form-actions finance-form__actions"><button type="submit" :disabled="busy">{{ t('supplements.postExpense') }}</button><button type="button" class="ghost" @click="spending = false">{{ t('common.cancel') }}</button></div></form>
    </div>
  </article>
</template>
