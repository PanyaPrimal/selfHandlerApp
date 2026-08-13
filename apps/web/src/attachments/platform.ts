import { Camera, EncodingType, MediaTypeSelection } from '@capacitor/camera'
import { Capacitor } from '@capacitor/core'
import { FileTransfer } from '@capacitor/file-transfer'
import { Directory, Filesystem } from '@capacitor/filesystem'
import { request } from '../api/http'
import type { Attachment, AttachmentParent } from '../api/types'
import { activeLocaleValue } from '../i18n'
import { mobileCredentialVault } from '../mobile/credential-vault'
import { configuredMobileApiOrigin, isAndroidNative, mobileApiBaseUrl, nativePlugin } from '../mobile/platform'

export type AttachmentSource =
  | { kind: 'browser', file: File }
  | { kind: 'native', uri: string, name: string, mimeType: Attachment['mime_type'] }

export interface AttachmentPreview {
  url: string
  release(): Promise<void>
}

export interface RestoredCameraResult {
  pluginId: string
  methodName: string
  success: boolean
  data?: unknown
}

interface CameraPort {
  takePhoto(options: Record<string, unknown>): Promise<{ uri?: string, metadata?: { format?: string } }>
  chooseFromGallery(options: Record<string, unknown>): Promise<{ results: Array<{ uri?: string, metadata?: { format?: string } }> }>
}

interface TransferPort {
  uploadFile(options: Record<string, unknown>): Promise<{ responseCode: string, response?: string }>
  downloadFile(options: Record<string, unknown>): Promise<{ path?: string }>
}

interface FilesystemPort {
  mkdir(options: Record<string, unknown>): Promise<unknown>
  getUri(options: Record<string, unknown>): Promise<{ uri: string }>
  deleteFile(options: Record<string, unknown>): Promise<unknown>
}

interface AttachmentPlatformDependencies {
  native(): boolean
  apiBaseUrl: string
  apiOrigin(): string
  locale(): string
  token: { read(): Promise<string | null> }
  browserRequest(path: string, init?: RequestInit): Promise<unknown>
  fetch(input: RequestInfo | URL, init?: RequestInit): Promise<Response>
  camera: CameraPort
  transfer: TransferPort
  filesystem: FilesystemPort
  convertFileSrc(path: string): string
  createObjectUrl(blob: Blob): string
  revokeObjectUrl(url: string): void
  uuid(): string
}

export interface AttachmentPlatform {
  readonly native: boolean
  choose(source: 'camera' | 'gallery'): Promise<AttachmentSource | null>
  upload(parent: AttachmentParent, source: AttachmentSource, uploadKey: string): Promise<Attachment>
  preview(attachment: Attachment): Promise<AttachmentPreview>
  remove(attachmentId: number): Promise<void>
  restore(result: RestoredCameraResult): AttachmentSource | null
}

function apiPath(parent: AttachmentParent, uploadKey: string): string {
  const query = new URLSearchParams({
    attachable_type: parent.type,
    attachable_id: String(parent.id),
    upload_key: uploadKey,
  })

  return `/attachments?${query.toString()}`
}

function imageFormat(value?: string): { extension: string, mimeType: Attachment['mime_type'] } | null {
  const format = value?.toLowerCase()
  if (format === 'jpeg' || format === 'jpg') return { extension: 'jpg', mimeType: 'image/jpeg' }
  if (format === 'png') return { extension: 'png', mimeType: 'image/png' }
  if (format === 'webp') return { extension: 'webp', mimeType: 'image/webp' }
  return null
}

function mediaSource(value: unknown): AttachmentSource | null {
  if (typeof value !== 'object' || value === null) return null
  const media = value as { uri?: unknown, type?: unknown, metadata?: { format?: unknown } }
  if (media.type === 1 || media.type === 'Video' || media.type === 'video') return null
  if (typeof media.uri !== 'string' || !media.uri) return null
  const format = imageFormat(typeof media.metadata?.format === 'string' ? media.metadata.format : undefined)
  if (!format) return null
  const uriName = decodeURIComponent(media.uri.split('/').pop() ?? `photo.${format.extension}`)
  const stem = uriName.replace(/\.[^.]*$/, '') || 'photo'
  return { kind: 'native', uri: media.uri, name: `${stem}.${format.extension}`, mimeType: format.mimeType }
}

function responseErrorMessage(value?: string): string | null {
  if (!value) return null
  try {
    const payload = JSON.parse(value) as unknown
    if (typeof payload === 'object' && payload !== null && 'message' in payload) {
      const message = (payload as { message?: unknown }).message
      return typeof message === 'string' && message.trim() ? message : null
    }
  } catch {
    return null
  }
  return null
}

function responseAttachment(value: unknown): Attachment {
  if (typeof value !== 'object' || value === null || !('data' in value)) {
    throw new Error('')
  }

  return (value as { data: Attachment }).data
}

function cancelled(error: unknown): boolean {
  const code = typeof error === 'object' && error !== null && 'code' in error
    ? String((error as { code: unknown }).code)
    : ''
  return ['OS-PLUG-CAMR-0006', 'OS-PLUG-CAMR-0020'].includes(code)
}

export function createAttachmentPlatform(deps: AttachmentPlatformDependencies): AttachmentPlatform {
  return {
    get native() {
      return deps.native()
    },
    async choose(source) {
      if (! deps.native()) return null
      try {
        const result = source === 'camera'
          ? await deps.camera.takePhoto({
              quality: 85, targetWidth: 2560, targetHeight: 2560, correctOrientation: true,
              encodingType: EncodingType.JPEG, includeMetadata: true, saveToGallery: false,
              isPersistent: false,
            })
          : (await deps.camera.chooseFromGallery({
              mediaType: MediaTypeSelection.Photo, allowMultipleSelection: false, limit: 1,
              quality: 85, targetWidth: 2560, targetHeight: 2560, correctOrientation: true,
              includeMetadata: true,
            })).results[0]
        if (!result?.uri) return null
        return mediaSource(result)
      } catch (error) {
        if (cancelled(error)) return null
        throw error
      }
    },
    async upload(parent, source, uploadKey) {
      const path = apiPath(parent, uploadKey)
      if (source.kind === 'browser') {
        const form = new FormData()
        form.append('file', source.file, source.file.name)
        return responseAttachment(await deps.browserRequest(path, { method: 'POST', body: form }))
      }

      const token = await deps.token.read()
      if (!token) throw new Error('')
      const result = await deps.transfer.uploadFile({
        url: `${mobileApiBaseUrl(deps.apiOrigin())}${path}`,
        path: source.uri,
        chunkedMode: true,
        fileKey: 'file',
        mimeType: source.mimeType,
        method: 'POST',
        headers: { Accept: 'application/json', 'Accept-Language': deps.locale(), Authorization: `Bearer ${token}` },
        progress: false,
      })
      const status = Number.parseInt(result.responseCode, 10)
      if (status < 200 || status >= 300 || !result.response) {
        throw new Error(responseErrorMessage(result.response) ?? '')
      }
      let payload: unknown
      try {
        payload = JSON.parse(result.response) as unknown
      } catch {
        throw new Error('')
      }
      return responseAttachment(payload)
    },
    async preview(attachment) {
      if (! deps.native()) {
        const response = await deps.fetch(`${deps.apiBaseUrl}${attachment.content_url.replace(/^\/api/, '')}`, {
          method: 'GET', headers: { Accept: attachment.mime_type, 'Accept-Language': deps.locale() },
          credentials: 'same-origin', cache: 'no-store',
        })
        if (!response.ok) throw new Error('The private photo could not be loaded.')
        const url = deps.createObjectUrl(await response.blob())
        return { url, release: async () => deps.revokeObjectUrl(url) }
      }

      const token = await deps.token.read()
      if (!token) throw new Error('')
      const extension = imageFormat(attachment.mime_type.split('/')[1])?.extension ?? 'jpg'
      const relativePath = `selfhandler-attachments/${attachment.id}-${deps.uuid()}.${extension}`
      try {
        await deps.filesystem.mkdir({ directory: Directory.Cache, path: 'selfhandler-attachments', recursive: true })
      } catch {
        // Existing cache directory is the expected steady state.
      }
      const destination = await deps.filesystem.getUri({ directory: Directory.Cache, path: relativePath })
      try {
        await deps.transfer.downloadFile({
          url: `${mobileApiBaseUrl(deps.apiOrigin())}${attachment.content_url.replace(/^\/api/, '')}`,
          path: destination.uri,
          method: 'GET',
          headers: { Accept: attachment.mime_type, 'Accept-Language': deps.locale(), Authorization: `Bearer ${token}` },
          progress: false,
        })
      } catch (error) {
        try {
          await deps.filesystem.deleteFile({ directory: Directory.Cache, path: relativePath })
        } catch {
          // The failed transfer may not have created a cache file.
        }
        throw error
      }
      return {
        url: deps.convertFileSrc(destination.uri),
        release: async () => {
          try {
            await deps.filesystem.deleteFile({ directory: Directory.Cache, path: relativePath })
          } catch {
            // Cache cleanup is idempotent: an already-removed preview is released.
          }
        },
      }
    },
    async remove(attachmentId) {
      await deps.browserRequest(`/attachments/${attachmentId}`, { method: 'DELETE' })
    },
    restore(result) {
      if (!deps.native() || !result.success || result.pluginId !== 'Camera') return null
      if (!['takePhoto', 'chooseFromGallery'].includes(result.methodName)) return null
      const data = result.data as { results?: unknown[] } | undefined
      return mediaSource(result.methodName === 'chooseFromGallery' ? data?.results?.[0] : result.data)
    },
  }
}

export const attachmentPlatform = createAttachmentPlatform({
  native: isAndroidNative,
  apiBaseUrl: import.meta.env.VITE_API_BASE_URL ?? '/api',
  apiOrigin: configuredMobileApiOrigin,
  locale: activeLocaleValue,
  token: mobileCredentialVault,
  browserRequest: request,
  fetch: globalThis.fetch.bind(globalThis),
  camera: nativePlugin('Camera', Camera) as unknown as CameraPort,
  transfer: nativePlugin('FileTransfer', FileTransfer) as unknown as TransferPort,
  filesystem: nativePlugin('Filesystem', Filesystem) as unknown as FilesystemPort,
  convertFileSrc: (path) => Capacitor.convertFileSrc(path),
  createObjectUrl: (blob) => URL.createObjectURL(blob),
  revokeObjectUrl: (url) => URL.revokeObjectURL(url),
  uuid: () => crypto.randomUUID(),
})
