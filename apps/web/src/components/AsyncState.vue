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
const hasSettled = ref(false)
const settledEmpty = ref(false)
// Keep the existing slot mounted during a refresh: focus, disclosures and
// in-progress inputs belong to the screen, not to the lifetime of a request.
watch(() => [props.loading, props.error, props.empty] as const, ([loading, error, empty]) => {
  if (!loading && !error) {
    hasSettled.value = true
    settledEmpty.value = empty
  }
}, { immediate: true })
const showingEmpty = computed(() => (props.loading || props.error) ? settledEmpty.value : props.empty)

watch(
  () => props.error,
  async (error) => {
    if (!error || hasSettled.value) {
      return
    }

    await nextTick()
    errorState.value?.focus()
  },
)
</script>

<template>
  <div
    v-if="loading && !hasSettled"
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
    v-if="error && !loading"
    ref="errorState"
    class="state-block error async-state focus-target"
    :class="{ panel }"
    role="alert"
    tabindex="-1"
  >
    <strong>{{ error }}</strong>
    <button type="button" class="secondary" @click="$emit('retry')">{{ resolvedRetryLabel }}</button>
  </div>

  <span v-if="loading && hasSettled" class="visually-hidden" role="status" aria-live="polite">{{ resolvedLoadingTitle }}</span>

  <div v-if="hasSettled && showingEmpty" class="state-block async-state" :class="{ panel }">
    <slot name="empty">
      <div v-if="showEmptyIcon" class="state-icon" aria-hidden="true"></div>
      <h3>{{ resolvedEmptyTitle }}</h3>
      <p v-if="emptyDescription" class="muted">{{ emptyDescription }}</p>
    </slot>
  </div>

  <slot v-if="hasSettled && !showingEmpty" />
</template>
