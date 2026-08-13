import { describe, expect, it, vi } from 'vitest'
import { createAttachmentPlatform } from '../attachments/platform'
import type { Attachment } from '../api/types'

const attachment: Attachment = {
  id: 9,
  kind: 'photo',
  original_name: 'progress.jpg',
  mime_type: 'image/jpeg',
  size_bytes: 120,
  width: 40,
  height: 20,
  created_at: '2026-08-14T00:00:00Z',
  content_url: '/api/attachments/9/content',
}

function dependencies(native: boolean) {
  return {
    native: () => native,
    apiBaseUrl: '/api',
    apiOrigin: () => 'https://selfhandler.example.test',
    locale: () => 'en',
    token: { read: vi.fn(async () => 'secret-token') },
    browserRequest: vi.fn(async (_path: string, _init?: RequestInit): Promise<unknown> => ({ data: attachment })),
    fetch: vi.fn(async () => new Response(new Blob(['photo']), {
      status: 200,
      headers: { 'Content-Type': 'image/jpeg' },
    })),
    camera: {
      takePhoto: vi.fn(async () => ({ uri: 'file:///camera/photo.jpg', webPath: 'web-photo', metadata: { format: 'jpeg' } })),
      chooseFromGallery: vi.fn(async () => ({ results: [{ uri: 'file:///gallery/photo.png', webPath: 'web-gallery', metadata: { format: 'png' } }] })),
    },
    transfer: {
      uploadFile: vi.fn(async (_options: Record<string, unknown>) => ({ responseCode: '201', response: JSON.stringify({ data: attachment }), bytesSent: 120, headers: {} })),
      downloadFile: vi.fn(async (options: { path: string }) => ({ path: options.path })),
    },
    filesystem: {
      mkdir: vi.fn(async () => undefined),
      getUri: vi.fn(async ({ path }: { path: string }) => ({ uri: `file:///cache/${path}` })),
      deleteFile: vi.fn(async () => undefined),
    },
    convertFileSrc: vi.fn((path: string) => `capacitor://${path}`),
    createObjectUrl: vi.fn(() => 'blob:attachment'),
    revokeObjectUrl: vi.fn(),
    uuid: () => '11111111-1111-4111-8111-111111111111',
  }
}

describe('private attachment platform', () => {
  it('uploads browser File as multipart and releases its object URL', async () => {
    const deps = dependencies(false)
    const platform = createAttachmentPlatform(deps)
    const file = new File(['jpeg'], 'progress.jpg', { type: 'image/jpeg' })

    await expect(platform.upload(
      { type: 'body_measurement', id: 7 }, { kind: 'browser', file }, 'browser-key',
    )).resolves.toEqual(attachment)
    expect(deps.browserRequest).toHaveBeenCalledOnce()
    const [path, init] = deps.browserRequest.mock.calls[0]!
    expect(path).toBe('/attachments?attachable_type=body_measurement&attachable_id=7&upload_key=browser-key')
    expect(init?.method).toBe('POST')
    expect(init?.body).toBeInstanceOf(FormData)

    const preview = await platform.preview(attachment)
    expect(preview.url).toBe('blob:attachment')
    await preview.release()
    expect(deps.revokeObjectUrl).toHaveBeenCalledWith('blob:attachment')
  })

  it('streams native Camera URI through File Transfer and never reads base64', async () => {
    const deps = dependencies(true)
    const platform = createAttachmentPlatform(deps)
    const selected = await platform.choose('camera')

    expect(selected).toEqual({ kind: 'native', uri: 'file:///camera/photo.jpg', name: 'photo.jpg', mimeType: 'image/jpeg' })
    await expect(platform.upload({ type: 'meal', id: 12 }, selected!, 'native-key')).resolves.toEqual(attachment)
    expect(deps.transfer.uploadFile).toHaveBeenCalledWith(expect.objectContaining({
      path: 'file:///camera/photo.jpg',
      fileKey: 'file',
      mimeType: 'image/jpeg',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    }))
    const url = deps.transfer.uploadFile.mock.calls[0]![0].url
    expect(url).toContain('/api/attachments?attachable_type=meal&attachable_id=12&upload_key=native-key')
    expect(JSON.stringify(deps.transfer.uploadFile.mock.calls)).not.toContain('base64')
  })

  it('downloads native private preview into cache and deletes it on release', async () => {
    const deps = dependencies(true)
    const platform = createAttachmentPlatform(deps)

    const preview = await platform.preview(attachment)
    expect(preview.url).toContain('capacitor://file:///cache/selfhandler-attachments/9-11111111-1111-4111-8111-111111111111.jpg')
    expect(deps.transfer.downloadFile).toHaveBeenCalledWith(expect.objectContaining({
      url: 'https://selfhandler.example.test/api/attachments/9/content',
      headers: expect.objectContaining({ Authorization: 'Bearer secret-token' }),
    }))
    await preview.release()
    expect(deps.filesystem.deleteFile).toHaveBeenCalledWith({
      directory: 'CACHE', path: 'selfhandler-attachments/9-11111111-1111-4111-8111-111111111111.jpg',
    })
  })

  it('cleans a native cache target when authenticated preview download fails', async () => {
    const deps = dependencies(true)
    deps.transfer.downloadFile.mockRejectedValueOnce(new Error('network unavailable'))
    const platform = createAttachmentPlatform(deps)

    await expect(platform.preview(attachment)).rejects.toThrow('network unavailable')
    expect(deps.filesystem.deleteFile).toHaveBeenCalledWith({
      directory: 'CACHE', path: 'selfhandler-attachments/9-11111111-1111-4111-8111-111111111111.jpg',
    })
  })

  it('offers restored Camera results for explicit foreground upload only', () => {
    const platform = createAttachmentPlatform(dependencies(true))

    expect(platform.restore({
      pluginId: 'Camera', methodName: 'takePhoto', success: true,
      data: { type: 0, uri: 'file:///restored/progress.webp', metadata: { format: 'webp' } },
    })).toEqual({ kind: 'native', uri: 'file:///restored/progress.webp', name: 'progress.webp', mimeType: 'image/webp' })
    expect(platform.restore({
      pluginId: 'Camera', methodName: 'chooseFromGallery', success: true,
      data: { results: [{ type: 0, uri: 'file:///restored/meal.png', metadata: { format: 'png' } }] },
    })).toEqual({ kind: 'native', uri: 'file:///restored/meal.png', name: 'meal.png', mimeType: 'image/png' })
    expect(platform.restore({ pluginId: 'Camera', methodName: 'takePhoto', success: false })).toBeNull()
    expect(platform.restore({
      pluginId: 'Camera', methodName: 'takePhoto', success: true,
      data: { type: 1, uri: 'file:///restored/video.mp4', metadata: { format: 'mp4' } },
    })).toBeNull()
  })
})
