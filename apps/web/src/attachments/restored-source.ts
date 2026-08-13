import { shallowRef } from 'vue'
import { attachmentPlatform, type AttachmentSource, type RestoredCameraResult } from './platform'

const source = shallowRef<AttachmentSource | null>(null)

export function useRestoredAttachmentSource() {
  return source
}

export function offerRestoredAttachment(result: RestoredCameraResult): boolean {
  const restored = attachmentPlatform.restore(result)
  if (!restored) return false
  source.value = restored
  return true
}

export function consumeRestoredAttachment(): AttachmentSource | null {
  const restored = source.value
  source.value = null
  return restored
}

export function clearRestoredAttachment(): void {
  source.value = null
}
