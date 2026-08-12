<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { computed } from 'vue'
import { useI18n } from '../i18n'

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
  loadingTitle: '',
  loadingDescription: '',
  loadingAriaLabel: '',
  emptyTitle: '',
  emptyDescription: '',
  retryLabel: '',
  showEmptyIcon: false,
  panel: false,
})
const { t } = useI18n()
const resolvedLoadingTitle = computed(() => props.loadingTitle || t('common.loading'))
const resolvedEmptyTitle = computed(() => props.emptyTitle || t('common.nothingHere'))
const resolvedRetryLabel = computed(() => props.retryLabel || t('common.retry'))

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
      <strong>{{ resolvedLoadingTitle }}</strong>
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
    <button type="button" class="secondary" @click="$emit('retry')">{{ resolvedRetryLabel }}</button>
  </div>

  <div v-else-if="empty" class="state-block async-state" :class="{ panel }">
    <slot name="empty">
      <div v-if="showEmptyIcon" class="state-icon" aria-hidden="true"></div>
      <h3>{{ resolvedEmptyTitle }}</h3>
      <p v-if="emptyDescription" class="muted">{{ emptyDescription }}</p>
    </slot>
  </div>

  <slot v-else />
</template>
