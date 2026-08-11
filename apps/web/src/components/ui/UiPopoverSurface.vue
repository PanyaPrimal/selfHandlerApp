<script setup lang="ts">
import type { CSSProperties } from 'vue'

/**
 * The overlay shell every control popup renders into.
 *
 * It is teleported to the document body so no ancestor with `overflow: hidden`
 * can clip it, and it carries no ARIA of its own: the consuming control supplies
 * the role and states through fallthrough attributes.
 *
 * `bindRef` hands the real DOM node back to the control, because the positioning
 * primitive needs to measure the surface itself rather than a component instance.
 */
defineProps<{
  open: boolean
  surfaceStyle?: CSSProperties
  bindRef: (element: HTMLElement | null) => void
}>()

defineOptions({ inheritAttrs: false })
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      :ref="(element) => bindRef(element as HTMLElement | null)"
      class="ui-surface"
      :style="surfaceStyle"
      v-bind="$attrs"
    >
      <slot></slot>
    </div>
  </Teleport>
</template>
