<script setup lang="ts">
import { computed, ref } from 'vue'
import UiField from './UiField.vue'
import { useFieldIds } from './useFieldIds'
import type { UiTextInputType } from './types'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: string
    type?: UiTextInputType
    helper?: string
    error?: string
    disabled?: boolean
    readonly?: boolean
    required?: boolean
    maxlength?: number
    placeholder?: string
    autocomplete?: string
    inputmode?: 'text' | 'email' | 'numeric' | 'search'
    wide?: boolean
  }>(),
  {
    type: 'text',
    helper: undefined,
    error: undefined,
    disabled: false,
    readonly: false,
    required: false,
    maxlength: undefined,
    placeholder: undefined,
    autocomplete: undefined,
    inputmode: undefined,
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const input = ref<HTMLInputElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).value)
}

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <UiField
    :label="label"
    :control-id="ids.controlId"
    :helper-id="ids.helperId"
    :error-id="ids.errorId"
    :helper="helper"
    :error="error"
    :wide="wide"
  >
    <input
      :id="ids.controlId"
      ref="input"
      class="ui-control ui-control--text"
      :class="{ 'ui-control--invalid': invalid }"
      :name="name"
      :data-field="name"
      :type="type"
      :value="modelValue"
      :disabled="disabled"
      :readonly="readonly"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :inputmode="inputmode"
      :aria-required="required || undefined"
      :aria-invalid="invalid || undefined"
      :aria-describedby="ids.describedBy.value"
      @input="onInput"
    />
  </UiField>
</template>
