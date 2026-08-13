<script setup lang="ts">
import { reactive, watch } from 'vue'
import type { SupplementOccurrence } from '../../api/types'
import { UiTextInput, UiTextarea, UiTimeField } from '../ui'
import { useI18n } from '../../i18n'
import { supplementDisplayQuantity } from '../../supplements/quantity'

const props = defineProps<{ occurrence: SupplementOccurrence, busy?: boolean }>()
const emit = defineEmits<{ save: [{ outcome: 'taken' | 'skipped', dose_quantity: string | null, dose_display_unit: string | null, taken_time: string | null, note: string | null }], clear: [] }>()
const { t } = useI18n()
const draft = reactive({ dose: '', time: '', note: '' })
watch(() => props.occurrence, (value) => {
  draft.dose = supplementDisplayQuantity(value.intake?.dose_quantity ?? value.dose_quantity, value.intake?.dose_display_unit ?? value.dose_display_unit)
  draft.time = value.intake?.taken_time ?? value.time
  draft.note = value.intake?.note ?? ''
}, { immediate: true })
</script>

<template>
  <div class="intake-editor">
    <UiTextInput v-model="draft.dose" :name="`intake-dose-${occurrence.id}`" :label="t('supplements.dose')" />
    <UiTimeField v-model="draft.time" :name="`intake-time-${occurrence.id}`" :label="t('supplements.takenTime')" />
    <UiTextarea v-model="draft.note" :name="`intake-note-${occurrence.id}`" :label="t('supplements.note')" />
    <div class="form-actions">
      <button type="button" :disabled="busy" @click="emit('save', { outcome: 'taken', dose_quantity: draft.dose || null, dose_display_unit: draft.dose ? occurrence.dose_display_unit : null, taken_time: draft.time, note: draft.note || null })">{{ t(occurrence.intake ? 'supplements.correct' : 'supplements.take') }}</button>
      <button type="button" class="secondary" :disabled="busy" @click="emit('save', { outcome: 'skipped', dose_quantity: null, dose_display_unit: null, taken_time: null, note: draft.note || null })">{{ t('supplements.skip') }}</button>
      <button v-if="occurrence.intake" type="button" class="ghost" :disabled="busy" @click="emit('clear')">{{ t('common.clear') }}</button>
    </div>
  </div>
</template>
