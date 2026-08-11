<script setup lang="ts">
import { computed } from 'vue'

/**
 * Label, helper text and error message for one control.
 *
 * The `<label>` element deliberately wraps only the label text. Wrapping the
 * control and its helper/error text as well would fold that text into the
 * field's accessible name, which is both wrong for screen readers and makes
 * label-based test selectors ambiguous.
 *
 * Composite controls — a listbox trigger, a calendar grid, a toggle group —
 * cannot be the target of `for`, so they pass `labelledby` and associate
 * themselves with the rendered label id instead.
 */
const props = withDefaults(
  defineProps<{
    label: string
    controlId: string
    helperId: string
    errorId: string
    helper?: string
    error?: string
    labelledby?: boolean
    /** Stretch across a two-column form grid. */
    wide?: boolean
  }>(),
  { helper: undefined, error: undefined, labelledby: false, wide: false },
)

const labelId = computed(() => `${props.controlId}-label`)
</script>

<template>
  <div
    class="ui-field"
    :class="{ 'ui-field--wide': wide, 'ui-field--invalid': Boolean(error) }"
  >
    <component
      :is="labelledby ? 'span' : 'label'"
      :id="labelId"
      class="ui-field__label"
      :for="labelledby ? undefined : controlId"
    >{{ label }}</component>

    <slot :labelId="labelId"></slot>

    <span v-if="helper" :id="helperId" class="ui-field__helper">{{ helper }}</span>
    <span v-if="error" :id="errorId" class="ui-field__error">{{ error }}</span>
  </div>
</template>
