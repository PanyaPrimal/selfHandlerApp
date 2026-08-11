<script setup lang="ts">
import { computed, ref } from 'vue'
import { useFieldIds } from './useFieldIds'

const props = withDefaults(
  defineProps<{
    label: string
    name: string
    modelValue: boolean
    helper?: string
    error?: string
    disabled?: boolean
  }>(),
  { helper: undefined, error: undefined, disabled: false },
)

const emit = defineEmits<{ 'update:modelValue': [boolean] }>()

const control = ref<HTMLButtonElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))
const labelId = `${ids.controlId}-label`

function toggle(): void {
  if (props.disabled) {
    return
  }

  emit('update:modelValue', !props.modelValue)
}

defineExpose({ focus: () => control.value?.focus() })
</script>

<template>
  <div class="ui-choice">
    <div class="ui-switch">
      <button
        :id="ids.controlId"
        ref="control"
        type="button"
        role="switch"
        class="ui-switch__control"
        :class="{ 'is-on': modelValue }"
        :data-field="name"
        :aria-checked="modelValue"
        :aria-labelledby="labelId"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        :disabled="disabled"
        @click="toggle"
      >
        <span class="ui-switch__thumb" aria-hidden="true"></span>
      </button>
      <label :id="labelId" class="ui-switch__label" :for="ids.controlId">{{ label }}</label>
    </div>
    <span v-if="helper" :id="ids.helperId" class="ui-field__helper">{{ helper }}</span>
    <span v-if="error" :id="ids.errorId" class="ui-field__error">{{ error }}</span>
  </div>
</template>
