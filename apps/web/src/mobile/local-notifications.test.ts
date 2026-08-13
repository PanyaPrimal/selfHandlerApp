import { describe, expect, it, vi } from 'vitest'
import type { InAppNotification } from '../api/types'
import {
  createAndroidLocalPresenter,
  nativeNotificationId,
  NativeNotificationIdCollisionError,
} from './local-notifications'

function event(overrides: Partial<InAppNotification> = {}): InAppNotification {
  return {
    id: 42,
    type: 'daily_digest',
    category: 'routine',
    status: 'sent',
    title: 'Daily overview',
    body: 'You have one item planned.',
    subject: 'Daily overview',
    action_url: '/notifications',
    channels: ['in_app'],
    escalation_count: 0,
    sent_at: '2026-08-13T09:00:00.000000Z',
    read_at: null,
    ...overrides,
  }
}

function pluginHarness(permission: 'granted' | 'denied' | 'prompt' = 'granted') {
  let current = permission
  const actionListeners: Array<(event: any) => void> = []
  const plugin = {
    checkPermissions: vi.fn(async () => ({ display: current })),
    requestPermissions: vi.fn(async () => {
      current = current === 'prompt' ? 'granted' : current
      return { display: current }
    }),
    createChannel: vi.fn().mockResolvedValue(undefined),
    getPending: vi.fn().mockResolvedValue({ notifications: [] }),
    getDeliveredNotifications: vi.fn().mockResolvedValue({ notifications: [] }),
    schedule: vi.fn().mockResolvedValue({ notifications: [{ id: 42 }] }),
    addListener: vi.fn(async (_event: string, listener: (event: any) => void) => {
      actionListeners.push(listener)
      return { remove: vi.fn().mockResolvedValue(undefined) }
    }),
  }

  return { plugin, actionListeners }
}

describe('Android local notification presenter', () => {
  it('maps positive server ids into the Android signed 32-bit range', () => {
    expect(nativeNotificationId(1)).toBe(1)
    expect(nativeNotificationId(2_000_000_001)).toBe(1)
    expect(() => nativeNotificationId(0)).toThrow()
    expect(() => nativeNotificationId(-1)).toThrow()
  })

  it('requests permission only from explicit enable and presents/acknowledges once', async () => {
    const native = pluginHarness('prompt')
    const acknowledge = vi.fn().mockResolvedValue(undefined)
    const presenter = createAndroidLocalPresenter({
      plugin: native.plugin,
      acknowledge,
      markRead: vi.fn(),
      navigate: vi.fn(),
      now: () => new Date('2026-08-13T09:00:00Z'),
    })

    await expect(presenter.permission()).resolves.toBe('prompt')
    expect(native.plugin.requestPermissions).not.toHaveBeenCalled()
    await expect(presenter.enable()).resolves.toBe('granted')
    await expect(presenter.present([event()])).resolves.toBe(1)
    await expect(presenter.present([event({ channels: ['in_app', 'android_local'] })])).resolves.toBe(0)

    expect(native.plugin.schedule).toHaveBeenCalledOnce()
    expect(native.plugin.schedule).toHaveBeenCalledWith({
      notifications: [expect.objectContaining({
        id: 42,
        title: 'Daily overview',
        body: 'You have one item planned.',
        channelId: 'selfhandler-reminders',
        extra: { notificationId: '42', actionUrl: '/notifications' },
      })],
    })
    expect(acknowledge).toHaveBeenCalledWith(42)
  })

  it('does nothing and never acknowledges when permission is denied', async () => {
    const native = pluginHarness('denied')
    const acknowledge = vi.fn()
    const presenter = createAndroidLocalPresenter({
      plugin: native.plugin,
      acknowledge,
      markRead: vi.fn(),
      navigate: vi.fn(),
    })

    await expect(presenter.present([event()])).resolves.toBe(0)
    expect(native.plugin.schedule).not.toHaveBeenCalled()
    expect(acknowledge).not.toHaveBeenCalled()
  })

  it('deduplicates pending ids and refuses a modulo collision with different server metadata', async () => {
    const native = pluginHarness()
    native.plugin.getPending.mockResolvedValue({
      notifications: [{ id: 42, extra: { notificationId: '42' } }],
    })
    const presenter = createAndroidLocalPresenter({
      plugin: native.plugin,
      acknowledge: vi.fn(),
      markRead: vi.fn(),
      navigate: vi.fn(),
    })

    await expect(presenter.present([event()])).resolves.toBe(0)

    native.plugin.getPending.mockResolvedValue({
      notifications: [{ id: 42, extra: { notificationId: '2000000042' } }],
    })
    await expect(presenter.present([event()])).rejects.toBeInstanceOf(NativeNotificationIdCollisionError)
  })

  it('routes only safe relative actions and marks the tapped event read best effort', async () => {
    const native = pluginHarness()
    const markRead = vi.fn().mockResolvedValue(undefined)
    const navigate = vi.fn().mockResolvedValue(undefined)
    const presenter = createAndroidLocalPresenter({
      plugin: native.plugin,
      acknowledge: vi.fn(),
      markRead,
      navigate,
    })
    await presenter.start()

    await native.actionListeners[0]?.({
      notification: { extra: { notificationId: '42', actionUrl: '/planner?date=2026-08-13' } },
    })
    await native.actionListeners[0]?.({
      notification: { extra: { notificationId: '43', actionUrl: 'https://evil.example' } },
    })

    expect(markRead).toHaveBeenNthCalledWith(1, 42)
    expect(navigate).toHaveBeenNthCalledWith(1, '/planner?date=2026-08-13')
    expect(navigate).toHaveBeenNthCalledWith(2, '/notifications')
  })
})
