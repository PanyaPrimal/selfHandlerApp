import { App as CapacitorApp } from '@capacitor/app'
import { Keyboard } from '@capacitor/keyboard'
import { LocalNotifications } from '@capacitor/local-notifications'
import { reactive, readonly } from 'vue'
import type { Router } from 'vue-router'
import { acknowledgeMobileNotificationPresentation } from '../api/client'
import { restoreSession, useAuthSession } from '../auth/session'
import {
  setNotificationPresentationHandler,
  useNotificationStore,
} from '../notifications/store'
import { initializeAndroidShell } from './android-shell'
import { offerRestoredAttachment } from '../attachments/restored-source'
import { createAndroidLocalPresenter } from './local-notifications'
import { nativePlugin } from './platform'

type DisplayPermission = 'granted' | 'denied' | 'prompt' | 'prompt-with-rationale' | 'unavailable'

const permissionState = reactive({
  permission: 'unavailable' as DisplayPermission,
  enabling: false,
  error: false,
})

export const mobileNotificationState = readonly(permissionState)

let presenter: ReturnType<typeof createAndroidLocalPresenter> | null = null
let initialization: Promise<() => Promise<void>> | null = null

function presenterFor(router: Router) {
  const notifications = useNotificationStore()
  return createAndroidLocalPresenter({
    plugin: nativePlugin('LocalNotifications', LocalNotifications) as any,
    acknowledge: acknowledgeMobileNotificationPresentation,
    markRead: notifications.markRead,
    navigate: (path) => router.push(path),
  })
}

export async function enableMobileNotifications(): Promise<DisplayPermission> {
  if (!presenter || permissionState.enabling) return permissionState.permission

  permissionState.enabling = true
  permissionState.error = false
  try {
    permissionState.permission = await presenter.enable()
  } catch {
    permissionState.error = true
  } finally {
    permissionState.enabling = false
  }

  return permissionState.permission
}

export function initializeMobileRuntime(router: Router): Promise<() => Promise<void>> {
  if (initialization) return initialization

  initialization = (async () => {
    const notifications = useNotificationStore()
    presenter = presenterFor(router)
    try {
      permissionState.permission = await presenter.permission()
    } catch {
      permissionState.permission = 'unavailable'
      permissionState.error = true
    }
    setNotificationPresentationHandler((items) => presenter?.present(items) ?? Promise.resolve(0))
    let stopNotificationActions = async (): Promise<void> => undefined
    try {
      stopNotificationActions = await presenter.start()
    } catch {
      permissionState.error = true
    }

    const stopShell = await initializeAndroidShell({
      app: nativePlugin('App', CapacitorApp) as any,
      keyboard: nativePlugin('Keyboard', Keyboard) as any,
      router,
      canGoBack: () => Number(window.history.state?.position ?? 0) > 0,
      onResume: async () => {
        await restoreSession(true)
        if (useAuthSession().status === 'authenticated') await notifications.refresh()
      },
      onRestoredResult: (result) => {
        offerRestoredAttachment(result as Parameters<typeof offerRestoredAttachment>[0])
      },
    })

    return async () => {
      setNotificationPresentationHandler(null)
      await Promise.all([stopNotificationActions(), stopShell()])
      presenter = null
      initialization = null
    }
  })()

  return initialization
}
