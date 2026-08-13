import { reactive, readonly } from 'vue'
import {
  dismissNotification,
  getNotifications,
  readNotification,
  snoozeNotification,
} from '../api/client'
import type {
  InAppNotification,
  NotificationSnoozeMinutes,
  NotificationView,
} from '../api/types'
import { translate } from '../i18n'

interface NotificationState {
  items: InAppNotification[]
  unreadCount: number
  view: NotificationView
  loading: boolean
  error: string | null
  snoozeOptions: NotificationSnoozeMinutes[]
}

const state = reactive<NotificationState>({
  items: [],
  unreadCount: 0,
  view: 'all',
  loading: false,
  error: null,
  snoozeOptions: [15, 60, 240, 1440],
})

let requestSequence = 0
let polling: number | null = null
let presentationHandler: ((items: InAppNotification[]) => Promise<unknown>) | null = null

async function refresh(view: NotificationView = state.view): Promise<void> {
  const sequence = ++requestSequence
  state.view = view
  state.loading = state.items.length === 0
  state.error = null

  try {
    const response = await getNotifications(view)
    if (sequence !== requestSequence) return

    state.items = response.data
    state.unreadCount = response.unread_count
    state.snoozeOptions = response.snooze_options
    try { await presentationHandler?.(response.data) } catch { /* native presentation never blocks the inbox */ }
  } catch {
    if (sequence === requestSequence) state.error = translate('notifications.loadFailed')
  } finally {
    if (sequence === requestSequence) state.loading = false
  }
}

async function markRead(id: number): Promise<void> {
  const response = await readNotification(id)
  const index = state.items.findIndex((item) => item.id === id)

  if (index >= 0) {
    if (state.view === 'unread') state.items.splice(index, 1)
    else state.items[index] = response.data
  }
  state.unreadCount = response.unread_count
}

async function dismiss(id: number): Promise<void> {
  const item = state.items.find((candidate) => candidate.id === id)
  await dismissNotification(id)
  state.items = state.items.filter((candidate) => candidate.id !== id)
  if (item?.status === 'sent') state.unreadCount = Math.max(0, state.unreadCount - 1)
}

async function snooze(id: number, minutes: NotificationSnoozeMinutes): Promise<void> {
  const item = state.items.find((candidate) => candidate.id === id)
  await snoozeNotification(id, minutes)
  state.items = state.items.filter((candidate) => candidate.id !== id)
  if (item?.status === 'sent') state.unreadCount = Math.max(0, state.unreadCount - 1)
}

function onFocus(): void {
  void refresh()
}

function start(): void {
  if (polling !== null) return

  void refresh()
  window.addEventListener('focus', onFocus)
  polling = window.setInterval(() => void refresh(), 60_000)
}

function stop(): void {
  if (polling !== null) window.clearInterval(polling)
  polling = null
  window.removeEventListener('focus', onFocus)
  requestSequence++
  state.items = []
  state.unreadCount = 0
  state.loading = false
  state.error = null
}

export function useNotificationStore() {
  return {
    state: readonly(state),
    refresh,
    markRead,
    dismiss,
    snooze,
    start,
    stop,
  }
}

export function setNotificationPresentationHandler(
  handler: ((items: InAppNotification[]) => Promise<unknown>) | null,
): void {
  presentationHandler = handler
}
