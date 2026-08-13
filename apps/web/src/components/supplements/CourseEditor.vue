<script setup lang="ts">
import { computed, reactive, watch } from 'vue'
import type { Supplement, SupplementCourse, SupplementCourseInput, SupplementDisplayUnit, Weekday } from '../../api/types'
import { UiDatePicker, UiNumberInput, UiSelect, UiSwitch, UiTextInput, UiTimeField } from '../ui'
import { useI18n } from '../../i18n'
import { supplementDisplayQuantity } from '../../supplements/quantity'

const props = defineProps<{ supplements: Supplement[], course?: SupplementCourse | null, today: string, busy?: boolean }>()
const emit = defineEmits<{ save: [SupplementCourseInput], cancel: [] }>()
const { t, locale } = useI18n()
const weekdays: Weekday[] = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']
const units: SupplementDisplayUnit[] = ['mg', 'g', 'ml', 'piece']
const cycleEnabled = reactive({ value: false })
const draft = reactive<SupplementCourseInput>({
  supplement_id: 0, goal_id: null, name: null, dose_quantity: '1', dose_display_unit: 'piece',
  starts_on: props.today, ends_on: props.today, is_active: true,
  schedule: { frequency: 'daily', interval_count: 1, weekdays: [], cycle: null, slots: [] },
})
const supplementOptions = computed(() => props.supplements.map((item) => ({ value: item.id, label: item.name })))
const unitOptions = units.map((value) => ({ value, label: t(`supplements.unit.${value}` as never) }))
const contextOptions = ['unspecified', 'with_food', 'empty_stomach'].map((value) => ({ value, label: t(`supplements.context.${value}` as never) }))

watch(() => props.course, (value) => {
  const first = props.supplements[0]
  Object.assign(draft, value ? {
    supplement_id: value.supplement_id, goal_id: value.goal_id, name: value.name,
    dose_quantity: supplementDisplayQuantity(value.dose_quantity, value.dose_display_unit), dose_display_unit: value.dose_display_unit,
    starts_on: value.starts_on, ends_on: value.ends_on, is_active: value.is_active,
    schedule: JSON.parse(JSON.stringify(value.schedule)),
  } : {
    supplement_id: first?.id ?? 0, goal_id: null, name: null,
    dose_quantity: first ? supplementDisplayQuantity(first.usual_dose_quantity, first.preferred_display_unit) : '1', dose_display_unit: first?.preferred_display_unit ?? 'piece',
    starts_on: props.today, ends_on: props.today, is_active: true,
    schedule: { frequency: 'daily', interval_count: 1, weekdays: [], cycle: null, slots: [{ slot: 'morning', time: '08:00', intake_context: 'unspecified' }] },
  })
  cycleEnabled.value = Boolean(draft.schedule.cycle)
}, { immediate: true })

function toggleWeekday(day: Weekday): void {
  const index = draft.schedule.weekdays.indexOf(day)
  if (index >= 0) draft.schedule.weekdays.splice(index, 1)
  else draft.schedule.weekdays.push(day)
}
function addSlot(): void {
  if (draft.schedule.slots.length >= 8) return
  const index = draft.schedule.slots.length + 1
  draft.schedule.slots.push({ slot: `slot_${index}`, time: '08:00', intake_context: 'unspecified' })
}
function removeSlot(index: number): void { if (draft.schedule.slots.length > 1) draft.schedule.slots.splice(index, 1) }
function setCycle(enabled: boolean): void { cycleEnabled.value = enabled; draft.schedule.cycle = enabled ? { on_days: 7, off_days: 7 } : null }
function save(): void {
  const schedule = {
    frequency: draft.schedule.frequency,
    interval_count: draft.schedule.interval_count,
    weekdays: [...draft.schedule.weekdays],
    cycle: draft.schedule.cycle ? { ...draft.schedule.cycle } : null,
    slots: draft.schedule.slots.map(({ slot, time, intake_context }) => ({ slot, time, intake_context })),
  }
  if (schedule.frequency === 'daily') schedule.weekdays = []
  emit('save', { ...draft, schedule })
}
</script>

<template>
  <form class="course-editor form-grid" :aria-label="t('supplements.courseEditor')" @submit.prevent="save">
    <UiSelect v-model="draft.supplement_id" name="course-supplement" :label="t('supplements.reference')" :options="supplementOptions" :disabled="Boolean(course)" required />
    <UiTextInput :model-value="draft.name ?? ''" name="course-name" :label="t('supplements.courseName')" @update:model-value="draft.name = $event || null" />
    <UiTextInput v-model="draft.dose_quantity" name="course-dose" :label="t('supplements.dose')" required />
    <UiSelect v-model="draft.dose_display_unit" name="course-unit" :label="t('supplements.displayUnit')" :options="unitOptions" required />
    <UiDatePicker v-model="draft.starts_on" name="course-start" :label="t('supplements.startsOn')" :locale="locale" :today="today" :min="today" required />
    <UiDatePicker v-model="draft.ends_on" name="course-end" :label="t('supplements.endsOn')" :locale="locale" :today="today" :min="draft.starts_on" required />
    <UiSelect v-model="draft.schedule.frequency" name="course-frequency" :label="t('supplements.frequency')" :options="[{ value: 'daily', label: t('supplements.daily') }, { value: 'weekly', label: t('supplements.weekly') }]" required />
    <UiNumberInput v-model="draft.schedule.interval_count" name="course-interval" :label="t('supplements.interval')" :min="1" :max="52" required />
    <fieldset v-if="draft.schedule.frequency === 'weekly'" class="wide weekday-fieldset">
      <legend>{{ t('supplements.weekdays') }}</legend>
      <label v-for="day in weekdays" :key="day" class="check-row"><input type="checkbox" :checked="draft.schedule.weekdays.includes(day)" @change="toggleWeekday(day)"> {{ t(`weekday.${day}` as never) }}</label>
    </fieldset>
    <UiSwitch :model-value="cycleEnabled.value" name="course-cycle" :label="t('supplements.cycle')" :helper="t('supplements.cycleHelp')" @update:model-value="setCycle" />
    <template v-if="draft.schedule.cycle">
      <UiNumberInput v-model="draft.schedule.cycle.on_days" name="cycle-on" :label="t('supplements.onDays')" :min="1" :max="366" />
      <UiNumberInput v-model="draft.schedule.cycle.off_days" name="cycle-off" :label="t('supplements.offDays')" :min="1" :max="366" />
    </template>
    <fieldset class="wide slot-list">
      <legend>{{ t('supplements.slots') }}</legend>
      <div v-for="(slot, index) in draft.schedule.slots" :key="index" class="slot-row">
        <UiTextInput v-model="slot.slot" :name="`slot-${index}-name`" :label="t('supplements.slotName')" required />
        <UiTimeField v-model="slot.time" :name="`slot-${index}-time`" :label="t('supplements.time')" required />
        <UiSelect v-model="slot.intake_context" :name="`slot-${index}-context`" :label="t('supplements.context')" :options="contextOptions" required />
        <button type="button" class="ghost" :disabled="draft.schedule.slots.length === 1" @click="removeSlot(index)">{{ t('common.delete') }}</button>
      </div>
      <button type="button" class="secondary" :disabled="draft.schedule.slots.length >= 8" @click="addSlot">{{ t('supplements.addSlot') }}</button>
    </fieldset>
    <UiSwitch v-model="draft.is_active" name="course-active" :label="t('supplements.activeCourse')" />
    <div class="form-actions wide"><button type="submit" :disabled="busy || !draft.supplement_id">{{ t(busy ? 'common.saving' : 'common.save') }}</button><button type="button" class="ghost" @click="emit('cancel')">{{ t('common.cancel') }}</button></div>
  </form>
</template>
