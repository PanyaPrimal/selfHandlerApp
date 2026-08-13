<script setup lang="ts">
import { reactive, watch } from 'vue'
import type { Supplement, SupplementCategory, SupplementDisplayUnit, SupplementForm, SupplementInput, SupplementStockUnit } from '../../api/types'
import { UiNumberInput, UiSelect, UiTextInput, UiTextarea } from '../ui'
import { useI18n } from '../../i18n'
import { supplementDisplayQuantity } from '../../supplements/quantity'

const props = defineProps<{ supplement?: Supplement | null, busy?: boolean, errors?: Record<string, string[]> }>()
const emit = defineEmits<{ save: [SupplementInput], cancel: [] }>()
const { t } = useI18n()

const draft = reactive<SupplementInput>({
  name: '', category: 'vitamin', form: 'capsule', stock_unit: 'piece',
  preferred_display_unit: 'piece', usual_dose_quantity: '1', package_quantity: null,
  restock_lead_days: 7, note: null,
})

const categories: SupplementCategory[] = ['vitamin', 'sports_nutrition', 'nootropic', 'medication', 'other']
const forms: SupplementForm[] = ['capsule', 'tablet', 'powder', 'liquid', 'injection', 'other']
const stockUnits: SupplementStockUnit[] = ['gram', 'millilitre', 'piece']
const displayUnits: SupplementDisplayUnit[] = ['mg', 'g', 'ml', 'piece']
const options = <T extends string>(items: readonly T[], prefix: string) => items.map((value) => ({ value, label: t(`${prefix}.${value}` as never) }))

watch(() => props.supplement, (value) => {
  Object.assign(draft, value ? {
    name: value.name, category: value.category, form: value.form, stock_unit: value.stock_unit,
    preferred_display_unit: value.preferred_display_unit,
    usual_dose_quantity: supplementDisplayQuantity(value.usual_dose_quantity, value.preferred_display_unit),
    package_quantity: value.package_quantity === null ? null : supplementDisplayQuantity(value.package_quantity, value.preferred_display_unit),
    restock_lead_days: value.restock_lead_days, note: value.note,
  } : {
    name: '', category: 'vitamin', form: 'capsule', stock_unit: 'piece', preferred_display_unit: 'piece',
    usual_dose_quantity: '1', package_quantity: null, restock_lead_days: 7, note: null,
  })
}, { immediate: true })

function setStockUnit(value: SupplementStockUnit | null): void {
  if (!value) return
  draft.stock_unit = value
  draft.preferred_display_unit = value === 'gram' ? 'g' : value === 'millilitre' ? 'ml' : 'piece'
}
</script>

<template>
  <form class="supplement-editor form-grid" :aria-label="t('supplements.referenceEditor')" @submit.prevent="emit('save', { ...draft })">
    <UiTextInput v-model="draft.name" name="supplement-name" :label="t('supplements.name')" :error="errors?.name?.[0]" required />
    <UiSelect v-model="draft.category" name="supplement-category" :label="t('supplements.category')" :options="options(categories, 'supplements.category')" required />
    <UiSelect v-model="draft.form" name="supplement-form" :label="t('supplements.form')" :options="options(forms, 'supplements.form')" required />
    <UiSelect :model-value="draft.stock_unit" name="supplement-stock-unit" :label="t('supplements.stockUnit')" :options="options(stockUnits, 'supplements.unit')" :disabled="Boolean(supplement)" required @update:model-value="setStockUnit" />
    <UiSelect v-model="draft.preferred_display_unit" name="supplement-display-unit" :label="t('supplements.displayUnit')" :options="options(displayUnits, 'supplements.unit')" :error="errors?.preferred_display_unit?.[0]" required />
    <UiTextInput v-model="draft.usual_dose_quantity" name="supplement-dose" :label="t('supplements.usualDose')" inputmode="numeric" required />
    <UiTextInput :model-value="draft.package_quantity ?? ''" name="supplement-package" :label="t('supplements.packageQuantity')" inputmode="numeric" @update:model-value="draft.package_quantity = $event || null" />
    <UiNumberInput v-model="draft.restock_lead_days" name="supplement-lead" :label="t('supplements.leadDays')" :min="0" :max="90" required />
    <UiTextarea :model-value="draft.note ?? ''" name="supplement-note" :label="t('supplements.note')" wide @update:model-value="draft.note = $event || null" />
    <div class="form-actions wide">
      <button type="submit" :disabled="busy">{{ t(busy ? 'common.saving' : 'common.save') }}</button>
      <button type="button" class="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</button>
    </div>
  </form>
</template>
