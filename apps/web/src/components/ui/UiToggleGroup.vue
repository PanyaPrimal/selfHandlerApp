<script setup lang="ts" generic="V extends string | number">
import { computed, ref } from 'vue'
import UiField from './UiField.vue'
import { useFieldIds } from './useFieldIds'
import type { UiOption } from './types'

/** Independent toggles for a small multi-choice set, such as weekdays. */
const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: readonly V[]
    options: readonly UiOption<V>[]
    helper?: string
    error?: string
    disabled?: boolean
    wide?: boolean
  }>(),
  { helper: undefined, error: undefined, disabled: false, wide: false },
)

const emit = defineEmits<{ 'update:modelValue': [V[]] }>()

const group = ref<HTMLElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))

function isPressed(value: V): boolean {
  return props.modelValue.includes(value)
}

function toggle(value: V): void {
  if (props.disabled) {
    return
  }

  const selected = new Set<V>(props.modelValue)

  if (selected.has(value)) {
    selected.delete(value)
  } else {
    selected.add(value)
  }

  // Emit in option order so the payload is stable regardless of click order.
  emit(
    'update:modelValue',
    props.options.map((option) => option.value).filter((value) => selected.has(value)),
  )
}

defineExpose({ focus: () => (group.value?.querySelector('button') as HTMLElement | null)?.focus() })
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
      class="ui-toggle-group"
      role="group"
      :data-field="name"
      :aria-labelledby="labelId"
      :aria-invalid="invalid || undefined"
      :aria-describedby="ids.describedBy.value"
    >
      <button
        v-for="option in options"
        :key="String(option.value)"
        type="button"
        class="ui-toggle"
        :class="{ 'is-pressed': isPressed(option.value) }"
        :aria-pressed="isPressed(option.value)"
        :disabled="disabled || option.disabled"
        @click="toggle(option.value)"
      >
        {{ option.label }}
      </button>
    </div>
  </UiField>
</template>
