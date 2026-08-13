<script setup lang="ts">
import { nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { attachmentPlatform, type AttachmentPreview } from '../../attachments/platform'
import type { Attachment } from '../../api/types'
import { useI18n } from '../../i18n'

const props = defineProps<{ attachments: Attachment[], parentLabel: string }>()
const emit = defineEmits<{ deleted: [attachmentId: number] }>()
const i18n = useI18n()
const urls = reactive(new Map<number, string>())
const errors = reactive(new Set<number>())
const handles = new Map<number, AttachmentPreview>()
const confirmId = ref<number | null>(null)
const deletingId = ref<number | null>(null)
const deleteError = ref<string | null>(null)
const galleryRoot = ref<HTMLElement | null>(null)
const confirmButton = ref<HTMLButtonElement | null>(null)
let deleteTrigger: HTMLElement | null = null
let disposed = false

async function release(id: number): Promise<void> {
  const preview = handles.get(id)
  handles.delete(id)
  urls.delete(id)
  errors.delete(id)
  await preview?.release()
}

async function synchronize(): Promise<void> {
  const ids = new Set(props.attachments.map(({ id }) => id))
  await Promise.all([...handles.keys()].filter((id) => !ids.has(id)).map(release))
  await Promise.all(props.attachments.map(async (attachment) => {
    if (handles.has(attachment.id) || errors.has(attachment.id)) return
    try {
      const preview = await attachmentPlatform.preview(attachment)
      if (disposed || !props.attachments.some(({ id }) => id === attachment.id)) {
        await preview.release()
        return
      }
      handles.set(attachment.id, preview)
      urls.set(attachment.id, preview.url)
    } catch {
      errors.add(attachment.id)
    }
  }))
}

watch(() => props.attachments.map(({ id }) => id).join(','), () => void synchronize(), { immediate: true })

onBeforeUnmount(() => {
  disposed = true
  void Promise.all([...handles.keys()].map(release))
})

async function remove(): Promise<void> {
  const id = confirmId.value
  if (id === null || deletingId.value !== null) return
  deletingId.value = id
  deleteError.value = null
  try {
    await attachmentPlatform.remove(id)
    await release(id)
    emit('deleted', id)
    confirmId.value = null
    await nextTick()
    galleryRoot.value?.focus()
  } catch (reason) {
    deleteError.value = reason instanceof Error && reason.message
      ? reason.message : i18n.t('attachments.deleteFailed')
  } finally {
    deletingId.value = null
  }
}

async function openConfirm(id: number, event: Event): Promise<void> {
  confirmId.value = id
  deleteError.value = null
  deleteTrigger = event.currentTarget as HTMLElement
  await nextTick()
  confirmButton.value?.focus()
}

async function cancelConfirm(): Promise<void> {
  confirmId.value = null
  await nextTick()
  deleteTrigger?.focus()
}
</script>

<template>
  <div ref="galleryRoot" class="attachment-gallery" tabindex="-1">
    <p v-if="attachments.length === 0" class="muted attachment-empty">{{ i18n.t('attachments.empty') }}</p>
    <ul v-else class="attachment-grid" :aria-label="i18n.t('attachments.photos')">
      <li v-for="(attachment, index) in attachments" :key="attachment.id" class="attachment-card">
        <img
          v-if="urls.get(attachment.id)"
          :src="urls.get(attachment.id)"
          :alt="i18n.t('attachments.previewAlt', { parent: parentLabel, number: index + 1 })"
          loading="lazy"
        >
        <div v-else class="attachment-placeholder" :class="{ error: errors.has(attachment.id) }" role="status">
          {{ i18n.t(errors.has(attachment.id) ? 'attachments.previewFailed' : 'attachments.loadingPreview') }}
        </div>
        <div class="attachment-card-copy">
          <span :title="attachment.original_name">{{ attachment.original_name }}</span>
          <small>{{ Math.ceil(attachment.size_bytes / 1024) }} KB · {{ attachment.width }}×{{ attachment.height }}</small>
        </div>
        <button
          type="button"
          class="danger attachment-delete"
          :aria-label="i18n.t('attachments.deleteNamed', { name: attachment.original_name })"
          :disabled="deletingId !== null"
          @click="openConfirm(attachment.id, $event)"
        >{{ i18n.t('common.delete') }}</button>
      </li>
    </ul>

    <div v-if="confirmId !== null" class="attachment-confirm" role="alertdialog" aria-modal="true" :aria-label="i18n.t('attachments.deleteConfirmTitle')" @keydown.esc="cancelConfirm">
      <p>{{ i18n.t('attachments.deleteConfirm') }}</p>
      <div class="button-row">
        <button ref="confirmButton" type="button" class="danger" :disabled="deletingId !== null" @click="remove">
          {{ i18n.t('attachments.confirmDelete') }}
        </button>
        <button type="button" class="secondary" :disabled="deletingId !== null" @click="cancelConfirm">
          {{ i18n.t('common.cancel') }}
        </button>
      </div>
    </div>
    <p v-if="deleteError" class="error-text" role="alert">{{ deleteError }}</p>
  </div>
</template>
