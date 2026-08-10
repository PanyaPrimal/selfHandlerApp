<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'

const props = withDefaults(defineProps<{
  loading?: boolean
  error?: string | null
  empty?: boolean
  loadingTitle?: string
  loadingDescription?: string
  loadingAriaLabel?: string
  emptyTitle?: string
  emptyDescription?: string
  retryLabel?: string
  showEmptyIcon?: boolean
  panel?: boolean
}>(), {
  loading: false,
  error: null,
  empty: false,
  loadingTitle: 'Loading…',
  loadingDescription: '',
  loadingAriaLabel: '',
  emptyTitle: 'Nothing here yet',
  emptyDescription: '',
  retryLabel: 'Retry',
  showEmptyIcon: false,
  panel: false,
})

defineEmits<{
  retry: []
}>()

const errorState = ref<HTMLElement | null>(null)

watch(
  () => props.error,
  async (error) => {
    if (!error) {
      return
    }

    await nextTick()
    errorState.value?.focus()
  },
)
</script>

<template>
  <div
    v-if="loading"
    class="state-block async-state"
    :class="{ panel }"
    role="status"
    aria-live="polite"
    aria-atomic="true"
    :aria-label="loadingAriaLabel || undefined"
  >
    <slot name="loading">
      <strong>{{ loadingTitle }}</strong>
      <span v-if="loadingDescription" class="muted">{{ loadingDescription }}</span>
    </slot>
  </div>

  <div
    v-else-if="error"
    ref="errorState"
    class="state-block error async-state focus-target"
    :class="{ panel }"
    role="alert"
    tabindex="-1"
  >
    <strong>{{ error }}</strong>
    <button type="button" class="secondary" @click="$emit('retry')">{{ retryLabel }}</button>
  </div>

  <div v-else-if="empty" class="state-block async-state" :class="{ panel }">
    <slot name="empty">
      <div v-if="showEmptyIcon" class="state-icon" aria-hidden="true"></div>
      <h3>{{ emptyTitle }}</h3>
      <p v-if="emptyDescription" class="muted">{{ emptyDescription }}</p>
    </slot>
  </div>

  <slot v-else />
</template>
