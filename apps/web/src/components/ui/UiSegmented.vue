<script setup lang="ts" generic="V extends string | number">
import { computed, nextTick, ref } from 'vue'
import UiField from './UiField.vue'
import { useFieldIds } from './useFieldIds'
import type { UiOption } from './types'

/** A radio group drawn as segments, for small mandatory choices. */
const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: V
    options: readonly UiOption<V>[]
    helper?: string
    error?: string
    disabled?: boolean
    wide?: boolean
  }>(),
  { helper: undefined, error: undefined, disabled: false, wide: false },
)

const emit = defineEmits<{ 'update:modelValue': [V] }>()

const group = ref<HTMLElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))
const selectedIndex = computed(() =>
  props.options.findIndex((option) => option.value === props.modelValue),
)

function optionId(index: number): string {
  return `${ids.controlId}-option-${index}`
}

function select(index: number): void {
  const option = props.options[index]

  if (!option || option.disabled || props.disabled) {
    return
  }

  emit('update:modelValue', option.value)
}

function move(delta: number): void {
  const count = props.options.length

  if (count === 0) {
    return
  }

  const from = selectedIndex.value < 0 ? 0 : selectedIndex.value

  for (let step = 1; step <= count; step += 1) {
    const index = (from + delta * step + count * count) % count

    if (!props.options[index].disabled) {
      select(index)
      void nextTick(() => document.getElementById(optionId(index))?.focus())
      return
    }
  }
}

function onKeydown(event: KeyboardEvent): void {
  switch (event.key) {
    case 'ArrowRight':
    case 'ArrowDown':
      event.preventDefault()
      move(1)
      break
    case 'ArrowLeft':
    case 'ArrowUp':
      event.preventDefault()
      move(-1)
      break
    default:
      break
  }
}

defineExpose({
  focus: () =>
    (group.value?.querySelector('[tabindex="0"]') as HTMLElement | null)?.focus() ??
    group.value?.focus(),
})
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
    <div
      :id="ids.controlId"
      ref="group"
      class="ui-segmented"
      role="radiogroup"
      :data-field="name"
      :aria-labelledby="labelId"
      :aria-invalid="invalid || undefined"
      :aria-describedby="ids.describedBy.value"
      @keydown="onKeydown"
    >
      <button
        v-for="(option, index) in options"
        :id="optionId(index)"
        :key="String(option.value)"
        type="button"
        role="radio"
        class="ui-segmented__item"
        :class="{ 'is-selected': index === selectedIndex }"
        :aria-checked="index === selectedIndex"
        :tabindex="index === (selectedIndex < 0 ? 0 : selectedIndex) ? 0 : -1"
        :disabled="disabled || option.disabled"
        @click="select(index)"
      >
        {{ option.label }}
      </button>
    </div>
  </UiField>
</template>
