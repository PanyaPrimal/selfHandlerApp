<script setup lang="ts">
import { computed, ref } from 'vue'
import UiField from './UiField.vue'
import { useFieldIds } from './useFieldIds'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: string
    helper?: string
    error?: string
    disabled?: boolean
    readonly?: boolean
    required?: boolean
    maxlength?: number
    placeholder?: string
    rows?: number
    wide?: boolean
  }>(),
  {
    helper: undefined,
    error: undefined,
    disabled: false,
    readonly: false,
    required: false,
    maxlength: undefined,
    placeholder: undefined,
    rows: 3,
    wide: false,
  },
)

const emit = defineEmits<{ 'update:modelValue': [string] }>()

const textarea = ref<HTMLTextAreaElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))

function onInput(event: Event): void {
  emit('update:modelValue', (event.target as HTMLTextAreaElement).value)
}

defineExpose({ focus: () => textarea.value?.focus() })
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
    <textarea
      :id="ids.controlId"
      ref="textarea"
      class="ui-control ui-control--textarea"
      :class="{ 'ui-control--invalid': invalid }"
      :name="name"
      :data-field="name"
      :value="modelValue"
      :disabled="disabled"
      :readonly="readonly"
      :maxlength="maxlength"
      :placeholder="placeholder"
      :rows="rows"
      :aria-required="required || undefined"
      :aria-invalid="invalid || undefined"
      :aria-describedby="ids.describedBy.value"
      @input="onInput"
    ></textarea>
  </UiField>
</template>
