<script setup lang="ts">
import { computed, ref } from 'vue'
import { attachmentPlatform, type AttachmentSource } from '../../attachments/platform'
import { consumeRestoredAttachment, useRestoredAttachmentSource } from '../../attachments/restored-source'
import type { Attachment, AttachmentParentType } from '../../api/types'
import { useI18n } from '../../i18n'

const props = defineProps<{
  parentType: AttachmentParentType
  parentId: number
  disabled?: boolean
}>()
const emit = defineEmits<{ uploaded: [attachment: Attachment] }>()
const i18n = useI18n()
const input = ref<HTMLInputElement | null>(null)
const pending = ref<{ source: AttachmentSource, key: string } | null>(null)
const busy = ref(false)
const feedback = ref<string | null>(null)
const error = ref<string | null>(null)
const native = computed(() => attachmentPlatform.native)
const restored = useRestoredAttachmentSource()

async function upload(source: AttachmentSource, key: string = crypto.randomUUID()): Promise<boolean> {
  if (busy.value || props.disabled) return false
  busy.value = true
  feedback.value = null
  error.value = null
  pending.value = { source, key }
  try {
    const attachment = await attachmentPlatform.upload(
      { type: props.parentType, id: props.parentId }, source, key,
    )
    emit('uploaded', attachment)
    pending.value = null
    feedback.value = i18n.t('attachments.uploaded')
    return true
  } catch (reason) {
    error.value = reason instanceof Error && reason.message
      ? reason.message : i18n.t('attachments.uploadFailed')
  } finally {
    busy.value = false
    if (input.value) input.value.value = ''
  }
  return false
}

async function chooseNative(source: 'camera' | 'gallery'): Promise<void> {
  if (busy.value || props.disabled) return
  error.value = null
  try {
    const selected = await attachmentPlatform.choose(source)
    if (selected) await upload(selected)
  } catch {
    error.value = i18n.t('attachments.chooseFailed')
  }
}

function selectBrowser(event: Event): void {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  if (file) void upload({ kind: 'browser', file })
}

function retry(): void {
  if (pending.value) void upload(pending.value.source, pending.value.key)
}

function uploadRestored(): void {
  const source = consumeRestoredAttachment()
  if (source) void upload(source)
}
</script>

<template>
  <div class="attachment-uploader">
    <div class="attachment-actions">
      <template v-if="native">
        <button type="button" class="secondary" :disabled="busy || disabled" @click="chooseNative('camera')">
          {{ i18n.t('attachments.takePhoto') }}
        </button>
        <button type="button" class="secondary" :disabled="busy || disabled" @click="chooseNative('gallery')">
          {{ i18n.t('attachments.chooseGallery') }}
        </button>
      </template>
      <label v-else class="button secondary attachment-file-button" :class="{ disabled: busy || disabled }">
        {{ i18n.t('attachments.choosePhoto') }}
        <input
          ref="input"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          :disabled="busy || disabled"
          @change="selectBrowser"
        >
      </label>
      <button v-if="pending && error" type="button" class="secondary" :disabled="busy" @click="retry">
        {{ i18n.t('common.retry') }}
      </button>
      <button v-if="native && restored" type="button" class="secondary" :disabled="busy || disabled" @click="uploadRestored">
        {{ i18n.t('attachments.useRestored') }}
      </button>
    </div>
    <p class="muted attachment-hint">{{ i18n.t(disabled ? 'attachments.limitReached' : 'attachments.hint') }}</p>
    <p v-if="busy" class="muted" role="status">{{ i18n.t('attachments.uploading') }}</p>
    <p v-else-if="feedback" class="success-text" role="status">{{ feedback }}</p>
    <p v-if="error" class="error-text" role="alert">{{ error }}</p>
    <p v-if="native && restored" class="muted" role="status">{{ i18n.t('attachments.restoredReady') }}</p>
  </div>
</template>
