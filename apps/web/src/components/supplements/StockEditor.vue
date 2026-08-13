<script setup lang="ts">
import { reactive } from 'vue'
import type { Supplement, SupplementDisplayUnit, SupplementStockMovementInput } from '../../api/types'
import { UiDatePicker, UiSelect, UiTextInput, UiTextarea } from '../ui'
import { useI18n } from '../../i18n'

const props = defineProps<{ supplement: Supplement, today: string, busy?: boolean }>()
const emit = defineEmits<{ save: [SupplementStockMovementInput], cancel: [] }>()
const { t, locale } = useI18n()
const draft = reactive<SupplementStockMovementInput>({ kind: 'restock', quantity: '', display_unit: props.supplement.preferred_display_unit, effective_on: props.today, reason: null, note: null })
const units: SupplementDisplayUnit[] = ['mg', 'g', 'ml', 'piece']
</script>

<template>
  <form class="stock-editor form-grid" :aria-label="t('supplements.stockEditor')" @submit.prevent="emit('save', { ...draft })">
    <UiSelect v-model="draft.kind" name="movement-kind" :label="t('supplements.movementKind')" :options="[{ value: 'restock', label: t('supplements.restock') }, { value: 'correction', label: t('supplements.correction') }]" required />
    <UiTextInput v-model="draft.quantity" name="movement-quantity" :label="t('supplements.quantity')" required />
    <UiSelect v-model="draft.display_unit" name="movement-unit" :label="t('supplements.displayUnit')" :options="units.map((value) => ({ value, label: t(`supplements.unit.${value}` as never) }))" required />
    <UiDatePicker v-model="draft.effective_on" name="movement-date" :label="t('supplements.effectiveOn')" :locale="locale" :today="today" :max="today" required />
    <UiTextInput :model-value="draft.reason ?? ''" name="movement-reason" :label="t('supplements.reason')" @update:model-value="draft.reason = $event || null" />
    <UiTextarea :model-value="draft.note ?? ''" name="movement-note" :label="t('supplements.note')" @update:model-value="draft.note = $event || null" />
    <div class="form-actions wide"><button type="submit" :disabled="busy">{{ t(busy ? 'common.saving' : 'common.save') }}</button><button type="button" class="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</button></div>
  </form>
</template>
