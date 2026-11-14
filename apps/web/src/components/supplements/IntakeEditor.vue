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
watch(() => JSON.stringify([
  props.occurrence.id, props.occurrence.dose_quantity, props.occurrence.dose_display_unit, props.occurrence.time,
  props.occurrence.intake?.outcome, props.occurrence.intake?.dose_quantity, props.occurrence.intake?.dose_display_unit,
  props.occurrence.intake?.taken_time, props.occurrence.intake?.note,
]), () => {
  const value = props.occurrence
  draft.dose = supplementDisplayQuantity(value.intake?.dose_quantity ?? value.dose_quantity, value.intake?.dose_display_unit ?? value.dose_display_unit)
  draft.time = value.intake?.taken_time ?? value.time
  draft.note = value.intake?.note ?? ''
}, { immediate: true })
</script>

<template>
  <div class="intake-actions">
    <div class="intake-quick">
      <button v-if="!occurrence.intake" type="button" :disabled="busy" @click="emit('save', { outcome: 'taken', dose_quantity: draft.dose || null, dose_display_unit: draft.dose ? occurrence.dose_display_unit : null, taken_time: draft.time, note: draft.note || null })">{{ t('supplements.take') }}</button>
      <span v-else class="intake-state" :class="{ 'is-taken': occurrence.intake.outcome === 'taken' }"><span aria-hidden="true">{{ occurrence.intake.outcome === 'taken' ? '✓' : '−' }}</span> {{ t(occurrence.intake.outcome === 'taken' ? 'supplements.status.done' : 'supplements.status.skipped') }}</span>
      <button v-if="occurrence.intake" type="button" class="ghost" :disabled="busy" @click="emit('clear')">{{ t('common.clear') }}</button>
    </div>
    <details class="intake-details">
      <summary>{{ t(occurrence.intake ? 'common.edit' : 'ease.intakeDetails') }}</summary>
      <div class="intake-editor">
    <UiTextInput v-model="draft.dose" :name="`intake-dose-${occurrence.id}`" :label="t('supplements.dose')" />
    <UiTimeField v-model="draft.time" :name="`intake-time-${occurrence.id}`" :label="t('supplements.takenTime')" />
    <UiTextarea v-model="draft.note" :name="`intake-note-${occurrence.id}`" :label="t('supplements.note')" />
    <div class="form-actions">
      <button type="button" :disabled="busy" @click="emit('save', { outcome: 'taken', dose_quantity: draft.dose || null, dose_display_unit: draft.dose ? (occurrence.intake?.dose_display_unit ?? occurrence.dose_display_unit) : null, taken_time: draft.time, note: draft.note || null })">{{ t(occurrence.intake ? 'supplements.correct' : 'common.save') }}</button>
      <button type="button" class="secondary" :disabled="busy" @click="emit('save', { outcome: 'skipped', dose_quantity: null, dose_display_unit: null, taken_time: null, note: draft.note || null })">{{ t('supplements.skip') }}</button>
    </div>
      </div>
    </details>
  </div>
</template>
