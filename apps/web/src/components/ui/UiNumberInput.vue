<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import UiField from './UiField.vue'
import { useFieldIds } from './useFieldIds'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: number | null
    helper?: string
    error?: string
    disabled?: boolean
    readonly?: boolean
    required?: boolean
    min?: number
    max?: number
    step?: number
    placeholder?: string
    suffix?: string
    wide?: boolean
  }>(),
  {
    helper: undefined,
    error: undefined,
    disabled: false,
    readonly: false,
    required: false,
    min: undefined,
    max: undefined,
    step: undefined,
    placeholder: undefined,
    suffix: undefined,
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [number | null] }>()

const input = ref<HTMLInputElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))

// The raw text is kept locally so a half-typed value such as "1." survives, while
// only a genuinely numeric entry is emitted. Clearing the field emits null rather
// than 0 or NaN, because the domain distinguishes "not set" from "zero".
const text = ref(props.modelValue === null ? '' : String(props.modelValue))

watch(
  () => props.modelValue,
  (value) => {
    const current = text.value === '' ? null : Number(text.value)

    if (value !== current) {
      text.value = value === null ? '' : String(value)
    }
  },
)

function onInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value
  text.value = raw

  if (raw.trim() === '') {
    emit('update:modelValue', null)
    return
  }

  const parsed = Number(raw)

  if (Number.isFinite(parsed)) {
    emit('update:modelValue', parsed)
  }
}

function onBlur(): void {
  if (text.value.trim() === '') {
    text.value = ''
    emit('update:modelValue', null)
    return
  }

  const parsed = Number(text.value)

  if (!Number.isFinite(parsed)) {
    text.value = props.modelValue === null ? '' : String(props.modelValue)
    return
  }

  text.value = String(parsed)
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
    <span class="ui-number">
      <input
        :id="ids.controlId"
        ref="input"
        class="ui-control ui-control--text ui-control--number"
        :class="{ 'ui-control--invalid': invalid }"
        type="number"
        inputmode="decimal"
        :name="name"
        :data-field="name"
        :value="text"
        :disabled="disabled"
        :readonly="readonly"
        :min="min"
        :max="max"
        :step="step"
        :placeholder="placeholder"
        :aria-required="required || undefined"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        @input="onInput"
        @blur="onBlur"
      />
      <span v-if="suffix" class="ui-number__suffix" aria-hidden="true">{{ suffix }}</span>
    </span>
  </UiField>
</template>
