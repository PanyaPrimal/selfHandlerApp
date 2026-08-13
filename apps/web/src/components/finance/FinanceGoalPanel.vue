<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import type { FinanceDebt, FinanceGoal, FinanceGoalInput, FinanceGoalUpdate, FinanceSavingFund } from '../../api/types'
import { financeAmount } from '../../finance/money'
import { useI18n } from '../../i18n'

const props = defineProps<{
  goals: FinanceGoal[]
  debts: FinanceDebt[]
  funds: FinanceSavingFund[]
  busy?: boolean
  save: (payload: FinanceGoalInput | FinanceGoalUpdate, id?: number) => Promise<boolean>
}>()
const i18n = useI18n()
const editing = ref<number | null>(null)
const milestoneText = ref('')
const draft = reactive<FinanceGoalInput>({
  name: '', description: null, target_date: null, kind: 'save', saving_fund_id: null,
  debt_id: null, milestones: [],
})
const availableFunds = computed(() => props.funds.filter((item) => item.active && !item.archived
  && item.projection.target_amount !== null))
const availableDebts = computed(() => props.debts.filter((item) => item.active && !item.archived))
const targetOptions = computed(() => draft.kind === 'save' ? availableFunds.value : availableDebts.value)

watch(() => draft.kind, (kind) => {
  if (editing.value !== null) return
  draft.saving_fund_id = kind === 'save' ? availableFunds.value[0]?.id ?? null : null
  draft.debt_id = kind === 'pay_off' ? availableDebts.value[0]?.id ?? null : null
  milestoneText.value = ''
})

function reset(): void {
  editing.value = null
  Object.assign(draft, { name: '', description: null, target_date: null, kind: 'save',
    saving_fund_id: availableFunds.value[0]?.id ?? null, debt_id: null, milestones: [] })
  milestoneText.value = ''
}

function edit(goal: FinanceGoal): void {
  editing.value = goal.id
  Object.assign(draft, {
    name: goal.name, description: goal.description, target_date: goal.target_date, kind: goal.kind,
    saving_fund_id: goal.kind === 'save' ? goal.aggregate_id : null,
    debt_id: goal.kind === 'pay_off' ? goal.aggregate_id : null,
    milestones: goal.milestones.map((item) => ({ target_value: item.target_value, target_date: item.target_date })),
  })
  milestoneText.value = goal.milestones.map((item) => item.target_value).join(', ')
}

function parseMilestones(): Array<{ target_value: string, target_date: null }> {
  return milestoneText.value.split(',').map((value) => value.trim()).filter(Boolean)
    .map((target_value) => ({ target_value, target_date: null }))
}

async function submit(): Promise<void> {
  const milestones = parseMilestones()
  const payload = editing.value === null ? { ...draft, milestones } : {
    name: draft.name, description: draft.description, target_date: draft.target_date, milestones,
  }
  if (await props.save(payload, editing.value ?? undefined)) reset()
}

reset()
</script>

<template>
  <section class="finance-section" aria-labelledby="finance-goals-heading">
    <div class="section-heading"><div><h2 id="finance-goals-heading">{{ i18n.t('finance.goals') }}</h2><p class="muted">{{ i18n.t('finance.goalsHelp') }}</p></div></div>
    <form class="finance-form finance-form--commitment" :aria-label="i18n.t('finance.goalEditor')" @submit.prevent="submit">
      <label><span>{{ i18n.t('finance.name') }}</span><input v-model="draft.name" required maxlength="160"></label>
      <label><span>{{ i18n.t('finance.goalKind') }}</span><select v-model="draft.kind" :disabled="editing !== null"><option value="save">{{ i18n.t('finance.goalKind.save') }}</option><option value="pay_off">{{ i18n.t('finance.goalKind.pay_off') }}</option></select></label>
      <label><span>{{ i18n.t('finance.goalAggregate') }}</span><select v-if="draft.kind === 'save'" v-model.number="draft.saving_fund_id" required :disabled="editing !== null"><option v-for="item in targetOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select><select v-else v-model.number="draft.debt_id" required :disabled="editing !== null"><option v-for="item in targetOptions" :key="item.id" :value="item.id">{{ item.name }}</option></select></label>
      <label><span>{{ i18n.t('finance.targetDate') }}</span><input v-model="draft.target_date" type="date"></label>
      <label><span>{{ i18n.t('finance.milestones') }}</span><input v-model="milestoneText" inputmode="decimal" :placeholder="i18n.t('finance.milestonesPlaceholder')"></label>
      <label><span>{{ i18n.t('finance.note') }}</span><input v-model="draft.description" maxlength="5000"></label>
      <div class="form-actions finance-form__actions"><button type="submit" :disabled="busy || (draft.kind === 'save' ? !draft.saving_fund_id : !draft.debt_id)">{{ i18n.t(editing === null ? 'finance.addGoal' : 'finance.saveGoal') }}</button><button v-if="editing !== null" type="button" class="ghost" @click="reset">{{ i18n.t('common.cancel') }}</button></div>
    </form>
    <div class="finance-card-grid">
      <article v-for="goal in goals" :id="`finance-goal-${goal.id}`" :key="goal.id" class="finance-card" :class="{ 'is-muted': goal.archived || goal.status !== 'active' }">
        <header><div><span class="token-caption">{{ i18n.t(`finance.goalKind.${goal.kind}` as never) }} · {{ goal.currency }}</span><h3>{{ goal.name }}</h3></div><strong class="finance-money">{{ financeAmount(goal.current_value, goal.currency, i18n.locale.value) }}</strong></header>
        <div class="finance-budget-progress" aria-hidden="true"><span :style="{ width: `${Math.min(100, goal.progress * 100)}%` }"></span></div>
        <dl class="finance-facts"><div><dt>{{ i18n.t('finance.startingValue') }}</dt><dd>{{ financeAmount(goal.starting_value, goal.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.targetAmount') }}</dt><dd>{{ financeAmount(goal.target_value, goal.currency, i18n.locale.value) }}</dd></div><div><dt>{{ i18n.t('finance.remaining') }}</dt><dd>{{ financeAmount(goal.remaining_value, goal.currency, i18n.locale.value) }}</dd></div></dl>
        <ul v-if="goal.milestones.length" class="finance-milestones"><li v-for="item in goal.milestones" :key="item.id" :class="{ achieved: item.achieved }"><span>{{ financeAmount(item.target_value, goal.currency, i18n.locale.value) }}</span><span>{{ i18n.t(item.achieved ? 'finance.achieved' : 'finance.pending') }}</span></li></ul>
        <div class="form-actions"><button type="button" class="secondary" :disabled="busy" @click="edit(goal)">{{ i18n.t('common.edit') }}</button><button v-if="goal.status === 'active'" type="button" :disabled="busy" @click="props.save({ status: 'completed' }, goal.id)">{{ i18n.t('finance.completeGoal') }}</button><button v-if="goal.status === 'active'" type="button" class="ghost" :disabled="busy" @click="props.save({ status: 'abandoned' }, goal.id)">{{ i18n.t('finance.abandonGoal') }}</button><button type="button" class="ghost" :disabled="busy" @click="props.save({ archived: !goal.archived }, goal.id)">{{ i18n.t(goal.archived ? 'finance.restore' : 'finance.archive') }}</button></div>
      </article>
    </div>
    <p v-if="goals.length === 0" class="empty-copy">{{ i18n.t('finance.noGoals') }}</p>
  </section>
</template>
