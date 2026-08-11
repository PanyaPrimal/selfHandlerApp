<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import UiField from './UiField.vue'
import UiPopoverSurface from './UiPopoverSurface.vue'
import { useAnchoredSurface } from './useAnchoredSurface'
import { useFieldIds } from './useFieldIds'
import { addMinutes, buildTimeSlots, parseTimeOfDay, toTimeString } from './calendar'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    /** `HH:MM`, or `null` when the field is genuinely empty. */
    modelValue: string | null
    helper?: string
    error?: string
    disabled?: boolean
    required?: boolean
    placeholder?: string
    /** Minutes between offered slots. */
    step?: number
    wide?: boolean
  }>(),
  {
    helper: undefined,
    error: undefined,
    disabled: false,
    required: false,
    placeholder: '--:--',
    step: 15,
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string | null] }>()

const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const listId = `${ids.controlId}-listbox`
const input = ref<HTMLInputElement | null>(null)
const text = ref(props.modelValue ?? '')
const activeIndex = ref(-1)

const invalid = computed(() => Boolean(props.error))
const slots = computed(() => buildTimeSlots(props.step))

const surface = useAnchoredSurface({
  matchWidth: true,
  maxHeight: 260,
  focusTarget: () => input.value,
})

watch(
  () => props.modelValue,
  (value) => {
    if ((value ?? '') !== normalise(text.value)) {
      text.value = value ?? ''
    }
  },
)

function normalise(raw: string): string {
  const parsed = parseTimeOfDay(raw)

  return parsed ? toTimeString(parsed) : ''
}

function optionId(index: number): string {
  return `${listId}-option-${index}`
}

const activeOptionId = computed(() =>
  surface.isOpen.value && activeIndex.value >= 0 ? optionId(activeIndex.value) : undefined,
)

function scrollActiveIntoView(): void {
  void nextTick(() => {
    if (activeIndex.value < 0) {
      return
    }

    document.getElementById(optionId(activeIndex.value))?.scrollIntoView({ block: 'nearest' })
  })
}

function openList(): void {
  if (props.disabled || surface.isOpen.value) {
    return
  }

  surface.open()
  const current = normalise(text.value)
  const index = slots.value.indexOf(current)
  activeIndex.value = index >= 0 ? index : 0
  scrollActiveIntoView()
}

function commit(index: number): void {
  const slot = slots.value[index]

  if (!slot) {
    return
  }

  text.value = slot
  emit('update:modelValue', slot)
  surface.close()
}

function onInput(event: Event): void {
  // Keep the user's raw text while typing; normalisation happens on blur so a
  // half-entered "7:3" is not destroyed mid-keystroke.
  text.value = (event.target as HTMLInputElement).value
  const parsed = parseTimeOfDay(text.value)

  if (parsed) {
    emit('update:modelValue', toTimeString(parsed))
  } else if (text.value.trim() === '') {
    emit('update:modelValue', null)
  }
}

function onBlur(): void {
  if (text.value.trim() === '') {
    text.value = ''
    emit('update:modelValue', null)
    return
  }

  const parsed = parseTimeOfDay(text.value)

  if (!parsed) {
    text.value = props.modelValue ?? ''
    return
  }

  text.value = toTimeString(parsed)
  emit('update:modelValue', text.value)
}

function stepValue(delta: number): void {
  const parsed = parseTimeOfDay(text.value) ?? { hour: 8, minute: 0 }
  const next = toTimeString(addMinutes(parsed, delta))
  text.value = next
  emit('update:modelValue', next)
}

function onKeydown(event: KeyboardEvent): void {
  if (props.disabled) {
    return
  }

  if (!surface.isOpen.value) {
    if (event.key === 'ArrowDown' && event.altKey) {
      event.preventDefault()
      openList()
      return
    }

    if (event.key === 'ArrowUp') {
      event.preventDefault()
      stepValue(5)
      return
    }

    if (event.key === 'ArrowDown') {
      event.preventDefault()
      stepValue(-5)
      return
    }

    return
  }

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      activeIndex.value = Math.min(slots.value.length - 1, activeIndex.value + 1)
      scrollActiveIntoView()
      break
    case 'ArrowUp':
      event.preventDefault()
      activeIndex.value = Math.max(0, activeIndex.value - 1)
      scrollActiveIntoView()
      break
    case 'Home':
      event.preventDefault()
      activeIndex.value = 0
      scrollActiveIntoView()
      break
    case 'End':
      event.preventDefault()
      activeIndex.value = slots.value.length - 1
      scrollActiveIntoView()
      break
    case 'Enter':
      event.preventDefault()
      commit(activeIndex.value)
      break
    case 'Tab':
      surface.close({ restoreFocus: false })
      break
    default:
      break
  }
}

defineExpose({ focus: () => input.value?.focus() })
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
  >
    <div :ref="(element) => { surface.anchorRef.value = element as HTMLElement | null }" class="ui-anchor">
      <input
        :id="ids.controlId"
        ref="input"
        class="ui-control ui-control--text ui-control--time"
        :class="{ 'ui-control--invalid': invalid, 'is-open': surface.isOpen.value }"
        type="text"
        role="combobox"
        inputmode="numeric"
        autocomplete="off"
        maxlength="5"
        :name="name"
        :data-field="name"
        :value="text"
        :placeholder="placeholder"
        :disabled="disabled"
        aria-autocomplete="none"
        :aria-required="required || undefined"
        :aria-expanded="surface.isOpen.value"
        :aria-controls="listId"
        :aria-activedescendant="activeOptionId"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        @input="onInput"
        @blur="onBlur"
        @keydown="onKeydown"
      />
      <button
        type="button"
        class="ui-control__adornment"
        :disabled="disabled"
        :aria-label="`Choose a time for ${label}`"
        :aria-expanded="surface.isOpen.value"
        :aria-controls="listId"
        tabindex="-1"
        @click="surface.isOpen.value ? surface.close() : openList()"
      >⏱</button>

      <UiPopoverSurface
        :open="surface.isOpen.value"
        :surface-style="surface.surfaceStyle.value"
        :bind-ref="(element) => { surface.surfaceRef.value = element }"
        class="ui-listbox"
      >
        <div :id="listId" role="listbox" :aria-labelledby="labelId">
          <div
            v-for="(slot, index) in slots"
            :id="optionId(index)"
            :key="slot"
            class="ui-option ui-option--time"
            :class="{ 'is-active': index === activeIndex, 'is-selected': slot === modelValue }"
            role="option"
            :aria-selected="slot === modelValue"
            @mousedown.prevent
            @click="commit(index)"
            @pointermove="activeIndex = index"
          >
            {{ slot }}
          </div>
        </div>
      </UiPopoverSurface>
    </div>
  </UiField>
</template>
