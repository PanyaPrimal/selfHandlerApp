<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import UiField from './UiField.vue'
import UiPopoverSurface from './UiPopoverSurface.vue'
import { useAnchoredSurface } from './useAnchoredSurface'
import { useFieldIds } from './useFieldIds'
import { useI18n } from '../../i18n'
import {
  addDays,
  addMonths,
  buildMonthGrid,
  clampToRange,
  firstDayOfWeek,
  formatDateForDisplay,
  formatDayLabel,
  formatMonthLabel,
  isSameCalendarDate,
  isWithinRange,
  parseCalendarDate,
  toDateString,
  weekdayLabels,
  type CalendarDate,
} from './calendar'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    /** `YYYY-MM-DD`, or `null` when the field is genuinely empty. */
    modelValue: string | null
    /** Profile locale. Never defaulted from the browser. */
    locale: string
    helper?: string
    error?: string
    disabled?: boolean
    required?: boolean
    placeholder?: string
    min?: string | null
    max?: string | null
    /** The user's current day, supplied by the screen from the profile time zone. */
    today?: string | null
    clearable?: boolean
    wide?: boolean
  }>(),
  {
    helper: undefined,
    error: undefined,
    disabled: false,
    required: false,
    placeholder: '',
    min: null,
    max: null,
    today: null,
    clearable: true,
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string | null] }>()
const { t } = useI18n()

const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const dialogId = `${ids.controlId}-dialog`
const gridLabelId = `${ids.controlId}-grid-label`
const trigger = ref<HTMLElement | null>(null)

const invalid = computed(() => Boolean(props.error))
const selected = computed(() => parseCalendarDate(props.modelValue))
const minDate = computed(() => parseCalendarDate(props.min))
const maxDate = computed(() => parseCalendarDate(props.max))
const todayDate = computed(() => parseCalendarDate(props.today))
const weekStart = computed(() => firstDayOfWeek(props.locale))
const headers = computed(() => weekdayLabels(props.locale, weekStart.value))

/**
 * The day the calendar keyboard focus sits on. Moving it never changes the
 * value: an empty field stays empty until the user actually selects a day.
 */
const cursor = ref<CalendarDate>({ year: 2000, month: 1, day: 1 })

function defaultCursor(): CalendarDate {
  // `today` remains the authoritative product-calendar day when the caller has
  // one. Older optional-date callers do not, so use the browser date only as a
  // navigation cursor instead of making people page forward from January 2000.
  // Opening the calendar still emits nothing and keeps the field empty.
  const now = new Date()
  const navigationFallback = { year: now.getFullYear(), month: now.getMonth() + 1, day: now.getDate() }
  const start = selected.value ?? todayDate.value ?? navigationFallback

  return clampToRange(start, minDate.value, maxDate.value)
}

const weeks = computed(() => buildMonthGrid(cursor.value.year, cursor.value.month, weekStart.value))

const monthLabel = computed(() => formatMonthLabel(cursor.value.year, cursor.value.month, props.locale))

const displayValue = computed(() => formatDateForDisplay(props.modelValue, props.locale))

const surface = useAnchoredSurface({
  maxHeight: 400,
  focusTarget: () => trigger.value,
})

function cellId(date: CalendarDate): string {
  return `${dialogId}-day-${toDateString(date)}`
}

function focusCursor(): void {
  void nextTick(() => {
    document.getElementById(cellId(cursor.value))?.focus()
  })
}

/** The first focus of an opening calendar has to wait for it to be placed. */
function focusCursorWhenReady(): void {
  surface.whenPositioned(focusCursor)
}

function openCalendar(): void {
  if (props.disabled) {
    return
  }

  cursor.value = defaultCursor()
  surface.open()
  focusCursorWhenReady()
}

function isSelectable(date: CalendarDate): boolean {
  return isWithinRange(date, minDate.value, maxDate.value)
}

function selectDate(date: CalendarDate): void {
  if (!isSelectable(date)) {
    return
  }

  emit('update:modelValue', toDateString(date))
  surface.close()
}

function clear(): void {
  emit('update:modelValue', null)
  surface.close()
}

function moveCursor(next: CalendarDate): void {
  cursor.value = clampToRange(next, minDate.value, maxDate.value)
  focusCursor()
}

function onGridKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowLeft':
      event.preventDefault()
      moveCursor(addDays(cursor.value, -1))
      break
    case 'ArrowRight':
      event.preventDefault()
      moveCursor(addDays(cursor.value, 1))
      break
    case 'ArrowUp':
      event.preventDefault()
      moveCursor(addDays(cursor.value, -7))
      break
    case 'ArrowDown':
      event.preventDefault()
      moveCursor(addDays(cursor.value, 7))
      break
    case 'Home':
      event.preventDefault()
      moveCursor(addDays(cursor.value, -((weekdayOffset(cursor.value) + 7) % 7)))
      break
    case 'End':
      event.preventDefault()
      moveCursor(addDays(cursor.value, 6 - ((weekdayOffset(cursor.value) + 7) % 7)))
      break
    case 'PageUp':
      event.preventDefault()
      moveCursor(addMonths(cursor.value, -1))
      break
    case 'PageDown':
      event.preventDefault()
      moveCursor(addMonths(cursor.value, 1))
      break
    case 'Enter':
    case ' ':
      event.preventDefault()
      selectDate(cursor.value)
      break
    case 'Tab':
      // Let focus leave the dialog naturally rather than trapping it.
      surface.close({ restoreFocus: false })
      break
    default:
      break
  }
}

function weekdayOffset(date: CalendarDate): number {
  const grid = buildMonthGrid(date.year, date.month, weekStart.value)

  for (const week of grid) {
    const index = week.findIndex((day) => isSameCalendarDate(day, date))

    if (index >= 0) {
      return index
    }
  }

  return 0
}

function shiftMonth(amount: number): void {
  cursor.value = clampToRange(addMonths(cursor.value, amount), minDate.value, maxDate.value)
}

function onTriggerKeydown(event: KeyboardEvent): void {
  if (surface.isOpen.value) {
    return
  }

  if (['Enter', ' ', 'ArrowDown'].includes(event.key)) {
    event.preventDefault()
    openCalendar()
  }
}

watch(
  () => props.modelValue,
  () => {
    if (!surface.isOpen.value) {
      cursor.value = defaultCursor()
    }
  },
  { immediate: true },
)

defineExpose({ focus: () => trigger.value?.focus() })
</script>

<template>
  <UiField
    v-slot="{ labelId }"
    :label="label"
    :control-id="ids.controlId"
    :helper-id="ids.helperId"
    :error-id="ids.errorId"
    :helper="helper"
    :error="error"
    :wide="wide"
    labelledby
  >
    <div :ref="(element) => { surface.anchorRef.value = element as HTMLElement | null }" class="ui-anchor">
      <button
        :id="ids.controlId"
        ref="trigger"
        type="button"
        class="ui-control ui-control--trigger"
        :class="{ 'ui-control--invalid': invalid, 'is-open': surface.isOpen.value }"
        :data-field="name"
        :data-ui-date="name"
        :disabled="disabled"
        aria-haspopup="dialog"
        :aria-required="required || undefined"
        :aria-expanded="surface.isOpen.value"
        :aria-controls="dialogId"
        :aria-labelledby="`${labelId} ${ids.controlId}`"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        @click="surface.isOpen.value ? surface.close() : openCalendar()"
        @keydown="onTriggerKeydown"
      >
        <span :class="['ui-control__value', { 'is-placeholder': !displayValue }]">
          {{ displayValue || placeholder || t('common.pickDate') }}
        </span>
        <span class="ui-control__calendar-mark" aria-hidden="true"></span>
      </button>

      <UiPopoverSurface
        :id="dialogId"
        :open="surface.isOpen.value"
        :surface-style="surface.surfaceStyle.value"
        :bind-ref="(element) => { surface.surfaceRef.value = element }"
        role="dialog"
        aria-modal="false"
        :aria-labelledby="gridLabelId"
        class="ui-calendar"
      >
        <div class="ui-calendar__header">
          <button
            type="button"
            class="ui-calendar__nav"
            :aria-label="t('common.previousYear')"
            @click="shiftMonth(-12)"
          >«</button>
          <button
            type="button"
            class="ui-calendar__nav"
            :aria-label="t('common.previousMonth')"
            @click="shiftMonth(-1)"
          >‹</button>
          <span :id="gridLabelId" class="ui-calendar__title" aria-live="polite">{{ monthLabel }}</span>
          <button
            type="button"
            class="ui-calendar__nav"
            :aria-label="t('common.nextMonth')"
            @click="shiftMonth(1)"
          >›</button>
          <button
            type="button"
            class="ui-calendar__nav"
            :aria-label="t('common.nextYear')"
            @click="shiftMonth(12)"
          >»</button>
        </div>

        <div class="ui-calendar__grid" role="grid" :aria-labelledby="gridLabelId">
          <div class="ui-calendar__row" role="row">
            <span
              v-for="header in headers"
              :key="header.long"
              class="ui-calendar__weekday"
              role="columnheader"
              :aria-label="header.long"
            >{{ header.short }}</span>
          </div>
          <div v-for="(week, weekIndex) in weeks" :key="weekIndex" class="ui-calendar__row" role="row">
            <button
              v-for="day in week"
              :id="cellId(day)"
              :key="toDateString(day)"
              type="button"
              role="gridcell"
              class="ui-calendar__day"
              :class="{
                'is-outside': day.month !== cursor.month,
                'is-selected': isSameCalendarDate(day, selected),
                'is-today': isSameCalendarDate(day, todayDate),
                'is-cursor': isSameCalendarDate(day, cursor),
              }"
              :tabindex="isSameCalendarDate(day, cursor) ? 0 : -1"
              :aria-selected="isSameCalendarDate(day, selected)"
              :aria-disabled="!isSelectable(day) || undefined"
              :disabled="!isSelectable(day)"
              :aria-label="formatDayLabel(day, locale)"
              @click="selectDate(day)"
              @keydown="onGridKeydown"
            >{{ day.day }}</button>
          </div>
        </div>

        <div v-if="clearable" class="ui-calendar__footer">
          <button type="button" class="ui-calendar__clear" @click="clear">{{ t('common.clear') }}</button>
        </div>
      </UiPopoverSurface>
    </div>
  </UiField>
</template>
