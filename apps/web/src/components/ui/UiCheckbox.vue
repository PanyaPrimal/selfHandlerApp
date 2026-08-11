<script setup lang="ts">
import { computed, ref } from 'vue'
import { useFieldIds } from './useFieldIds'

/**
 * A real `input[type="checkbox"]` under a drawn mark.
 *
 * The native element is never presented in its default appearance and never
 * opens an operating-system surface, so nothing about the product's look leaks;
 * keeping it means checkbox semantics, form participation and assistive-technology
 * behaviour are preserved rather than re-implemented.
 */
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

const input = ref<HTMLInputElement | null>(null)
const ids = useFieldIds(props.name, () => Boolean(props.helper), () => Boolean(props.error))
const invalid = computed(() => Boolean(props.error))

function onChange(event: Event): void {
  emit('update:modelValue', (event.target as HTMLInputElement).checked)
}

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div class="ui-choice">
    <label class="ui-choice__row" :class="{ 'is-disabled': disabled }">
      <input
        :id="ids.controlId"
        ref="input"
        class="ui-choice__input"
        type="checkbox"
        :name="name"
        :data-field="name"
        :checked="modelValue"
        :disabled="disabled"
        :aria-invalid="invalid || undefined"
        :aria-describedby="ids.describedBy.value"
        @change="onChange"
      />
      <span class="ui-choice__box" aria-hidden="true"></span>
      <span class="ui-choice__label">{{ label }}</span>
    </label>
    <span v-if="helper" :id="ids.helperId" class="ui-field__helper">{{ helper }}</span>
    <span v-if="error" :id="ids.errorId" class="ui-field__error">{{ error }}</span>
  </div>
</template>
