import type { InAppNotification } from '../api/types'

const ANDROID_ID_MODULUS = 2_000_000_000
const CHANNEL_ID = 'selfhandler-reminders'

type Permission = 'granted' | 'denied' | 'prompt' | 'prompt-with-rationale'

interface LocalNotificationPlugin {
  checkPermissions(): Promise<{ display: Permission }>
  requestPermissions(): Promise<{ display: Permission }>
  createChannel(options: Record<string, unknown>): Promise<void>
  getPending(): Promise<{ notifications: Array<Record<string, any>> }>
  getDeliveredNotifications(): Promise<{ notifications?: Array<Record<string, any>>, deliveredNotifications?: Array<Record<string, any>> }>
  schedule(options: { notifications: Array<Record<string, unknown>> }): Promise<unknown>
  addListener(event: string, listener: (event: any) => void | Promise<void>): Promise<{ remove(): Promise<void> }>
}

interface PresenterOptions {
  plugin: LocalNotificationPlugin
  acknowledge: (notificationId: number) => Promise<unknown>
  markRead: (notificationId: number) => Promise<unknown>
  navigate: (path: string) => Promise<unknown> | unknown
  now?: () => Date
}

export class NativeNotificationIdCollisionError extends Error {
  constructor() {
    super('A native notification id is already bound to a different server notification.')
    this.name = 'NativeNotificationIdCollisionError'
  }
}

export function nativeNotificationId(serverId: number): number {
  if (!Number.isSafeInteger(serverId) || serverId <= 0) {
    throw new TypeError('A positive integer server notification id is required.')
  }

  return ((serverId - 1) % ANDROID_ID_MODULUS) + 1
}

function originalId(notification: Record<string, any>): string | null {
  const value = notification.extra?.notificationId
  return typeof value === 'string' ? value : value == null ? null : String(value)
}

function safeAction(value: unknown): string {
  if (typeof value !== 'string' || !value.startsWith('/') || value.startsWith('//')) {
    return '/notifications'
  }

  try {
    const url = new URL(value, 'https://selfhandler.invalid')
    if (!['/planner', '/notifications'].includes(url.pathname)) return '/notifications'
    return `${url.pathname}${url.search}${url.hash}`
  } catch {
    return '/notifications'
  }
}

export function createAndroidLocalPresenter(options: PresenterOptions) {
  async function permission(): Promise<Permission> {
    return (await options.plugin.checkPermissions()).display
  }

  async function ensureChannel(): Promise<void> {
    await options.plugin.createChannel({
      id: CHANNEL_ID,
      name: 'SelfHandler reminders',
      description: 'Reminders already delivered to your SelfHandler inbox',
      importance: 4,
      visibility: 1,
    })
  }

  async function enable(): Promise<Permission> {
    const current = await permission()
    const result = current === 'granted'
      ? current
      : (await options.plugin.requestPermissions()).display
    if (result === 'granted') await ensureChannel()
    return result
  }

  async function present(events: InAppNotification[]): Promise<number> {
    if (await permission() !== 'granted') return 0
    await ensureChannel()

    const pending = (await options.plugin.getPending()).notifications ?? []
    const deliveredResult = await options.plugin.getDeliveredNotifications()
    const delivered = deliveredResult.notifications ?? deliveredResult.deliveredNotifications ?? []
    const existing = [...pending, ...delivered]
    let count = 0

    for (const event of events) {
      if (event.status !== 'sent' || event.channels.includes('android_local')) continue

      const id = nativeNotificationId(event.id)
      const match = existing.find((candidate) => Number(candidate.id) === id)
      if (match) {
        if (originalId(match) !== String(event.id)) throw new NativeNotificationIdCollisionError()
        await options.acknowledge(event.id)
        continue
      }

      await options.plugin.schedule({
        notifications: [{
          id,
          title: event.title,
          body: event.body,
          channelId: CHANNEL_ID,
          extra: {
            notificationId: String(event.id),
            actionUrl: safeAction(event.action_url),
          },
        }],
      })
      await options.acknowledge(event.id)
      count += 1
    }

    return count
  }

  async function start(): Promise<() => Promise<void>> {
    const handle = await options.plugin.addListener('localNotificationActionPerformed', async ({ notification }) => {
      const notificationId = Number.parseInt(originalId(notification) ?? '', 10)
      if (Number.isSafeInteger(notificationId) && notificationId > 0) {
        try { await options.markRead(notificationId) } catch { /* best effort */ }
      }
      await options.navigate(safeAction(notification?.extra?.actionUrl))
    })

    return () => handle.remove()
  }

  return { permission, enable, present, start }
}
