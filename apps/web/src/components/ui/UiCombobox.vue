<script setup lang="ts" generic="V extends string | number">
import { computed, nextTick, ref, watch } from 'vue'
import UiField from './UiField.vue'
import UiPopoverSurface from './UiPopoverSurface.vue'
import { useAnchoredSurface } from './useAnchoredSurface'
import { useFieldIds } from './useFieldIds'
import type { UiOption } from './types'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: V | null
    options: readonly UiOption<V>[]
    helper?: string
    error?: string
    disabled?: boolean
    required?: boolean
    placeholder?: string
    emptyMessage?: string
    wide?: boolean
  }>(),
  {
    helper: undefined,
    error: undefined,
    disabled: false,
    required: false,
    placeholder: 'Search…',
    emptyMessage: 'Nothing matches that search.',
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [V | null] }>()

const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const listId = `${ids.controlId}-listbox`
const statusId = `${ids.controlId}-status`
const input = ref<HTMLInputElement | null>(null)
const query = ref('')
const activeIndex = ref(-1)

const invalid = computed(() => Boolean(props.error))

const selectedOption = computed(
  () => props.options.find((option) => option.value === props.modelValue) ?? null,
)

const matches = computed(() => {
  const needle = query.value.trim().toLowerCase()

  if (needle === '') {
    return props.options.filter((option) => !option.disabled)
  }

  return props.options.filter(
    (option) => !option.disabled && option.label.toLowerCase().includes(needle),
  )
})

const surface = useAnchoredSurface({
  matchWidth: true,
  focusTarget: () => input.value,
  onDismiss: () => {
    // Escape and outside clicks restore the committed value; a half-typed filter
    // must never leak into the field.
    query.value = ''
  },
})

/** The closed field shows the committed label; the open field shows the filter. */
const displayValue = computed(() =>
  surface.isOpen.value ? query.value : (selectedOption.value?.label ?? ''),
)

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

  query.value = ''
  surface.open()
  activeIndex.value = matches.value.findIndex((option) => option.value === props.modelValue)

  if (activeIndex.value < 0 && matches.value.length > 0) {
    activeIndex.value = 0
  }

  scrollActiveIntoView()
}

function commit(index: number): void {
  const option = matches.value[index]

  if (!option) {
    return
  }

  emit('update:modelValue', option.value)
  query.value = ''
  surface.close()
}

function moveActive(delta: number): void {
  const count = matches.value.length

  if (count === 0) {
    activeIndex.value = -1
    return
  }

  const next = activeIndex.value < 0 ? (delta > 0 ? 0 : count - 1) : activeIndex.value + delta
  activeIndex.value = Math.min(count - 1, Math.max(0, next))
  scrollActiveIntoView()
}

function onInput(event: Event): void {
  query.value = (event.target as HTMLInputElement).value

  if (!surface.isOpen.value) {
    surface.open()
  }

  activeIndex.value = matches.value.length > 0 ? 0 : -1
  scrollActiveIntoView()
}

function onKeydown(event: KeyboardEvent): void {
  if (props.disabled) {
    return
  }

  if (!surface.isOpen.value) {
    if (['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) {
      event.preventDefault()
      openList()
    }

    return
  }

  switch (event.key) {
    case 'ArrowDown':
      event.preventDefault()
      moveActive(1)
      break
    case 'ArrowUp':
      event.preventDefault()
      moveActive(-1)
      break
    case 'Home':
      event.preventDefault()
      activeIndex.value = matches.value.length > 0 ? 0 : -1
      scrollActiveIntoView()
      break
    case 'End':
      event.preventDefault()
      activeIndex.value = matches.value.length - 1
      scrollActiveIntoView()
      break
    case 'Enter':
      event.preventDefault()
      commit(activeIndex.value)
      break
    case 'Tab':
      query.value = ''
      surface.close({ restoreFocus: false })
      break
    default:
      break
  }
}

function onFocus(): void {
  // Selecting the committed label means the first keystroke starts a fresh
  // filter rather than appending to the value already shown.
  input.value?.select()
}

function onBlur(): void {
  // Losing focus without choosing keeps the committed value and drops the filter.
  if (!surface.isOpen.value) {
    query.value = ''
  }
}

watch(matches, (list) => {
  if (activeIndex.value >= list.length) {
    activeIndex.value = list.length > 0 ? list.length - 1 : -1
  }
})

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
        class="ui-control ui-control--text ui-control--combobox"
        :class="{ 'ui-control--invalid': invalid, 'is-open': surface.isOpen.value }"
        type="text"
        role="combobox"
        autocomplete="off"
        spellcheck="false"
        :name="name"
        :data-field="name"
        :value="displayValue"
        :placeholder="selectedOption ? selectedOption.label : placeholder"
        :disabled="disabled"
        aria-autocomplete="list"
        :aria-required="required || undefined"
        :aria-expanded="surface.isOpen.value"
        :aria-controls="listId"
        :aria-activedescendant="activeOptionId"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        @input="onInput"
        @keydown="onKeydown"
        @focus="onFocus"
        @blur="onBlur"
        @click="openList"
      />
      <span class="ui-control__chevron ui-control__chevron--inset" aria-hidden="true"></span>

      <UiPopoverSurface
        :open="surface.isOpen.value"
        :surface-style="surface.surfaceStyle.value"
        :bind-ref="(element) => { surface.surfaceRef.value = element }"
        class="ui-listbox"
      >
        <div :id="listId" role="listbox" :aria-labelledby="labelId">
          <div
            v-for="(option, index) in matches"
            :id="optionId(index)"
            :key="String(option.value)"
            class="ui-option"
            :class="{ 'is-active': index === activeIndex, 'is-selected': option.value === modelValue }"
            role="option"
            :aria-selected="option.value === modelValue"
            @mousedown.prevent
            @click="commit(index)"
            @pointermove="activeIndex = index"
          >
            {{ option.label }}
          </div>
        </div>
        <p v-if="matches.length === 0" :id="statusId" class="ui-listbox__empty" role="status">
          {{ emptyMessage }}
        </p>
      </UiPopoverSurface>
    </div>
  </UiField>
</template>
