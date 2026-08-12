<script setup lang="ts" generic="V extends string | number">
import { computed, nextTick, ref } from 'vue'
import UiField from './UiField.vue'
import { useFieldIds } from './useFieldIds'
import type { UiSwatchOption } from './types'

const props = defineProps<{
  label: string
  name: string
  modelValue: V
  options: readonly UiSwatchOption<V>[]
  helper?: string
  error?: string
  disabled?: boolean
}>()

const emit = defineEmits<{ 'update:modelValue': [V] }>()
const group = ref<HTMLElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const selectedIndex = computed(() => props.options.findIndex((option) => option.value === props.modelValue))

function optionId(index: number): string {
  return `${ids.controlId}-swatch-${index}`
}

function select(index: number): void {
  const option = props.options[index]
  if (!option || option.disabled || props.disabled) return
  emit('update:modelValue', option.value)
}

function move(delta: number): void {
  const count = props.options.length
  if (!count) return
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
  if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
    event.preventDefault()
    move(1)
  } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
    event.preventDefault()
    move(-1)
  }
}

defineExpose({ focus: () => group.value?.querySelector<HTMLElement>('[tabindex="0"]')?.focus() })
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
    labelledby
  >
    <div
      :id="ids.controlId"
      ref="group"
      class="ui-swatch-group"
      role="radiogroup"
      :aria-labelledby="labelId"
      :aria-describedby="ids.describedBy.value"
      :aria-invalid="error ? true : undefined"
      :data-field="name"
      @keydown="onKeydown"
    >
      <button
        v-for="(option, index) in options"
        :id="optionId(index)"
        :key="String(option.value)"
        type="button"
        role="radio"
        class="ui-swatch"
        :class="{ 'is-selected': index === selectedIndex }"
        :style="{ '--swatch-color': option.color }"
        :aria-checked="index === selectedIndex"
        :aria-label="`${option.label}, ${option.hex}`"
        :tabindex="index === (selectedIndex < 0 ? 0 : selectedIndex) ? 0 : -1"
        :disabled="disabled || option.disabled"
        @click="select(index)"
      >
        <span class="ui-swatch__colour" aria-hidden="true"></span>
        <strong>{{ option.label }}</strong>
        <small>{{ option.hex }}</small>
      </button>
    </div>
  </UiField>
</template>
