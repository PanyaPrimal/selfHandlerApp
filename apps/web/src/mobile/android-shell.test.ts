import { beforeEach, describe, expect, it, vi } from 'vitest'
import { initializeAndroidShell } from './android-shell'

type Listener = (...args: any[]) => void

function pluginHarness() {
  const listeners = new Map<string, Listener>()
  const removed: string[] = []

  return {
    listeners,
    removed,
    plugin: {
      addListener: vi.fn(async (event: string, listener: Listener) => {
        listeners.set(event, listener)
        return { remove: async () => { removed.push(event); listeners.delete(event) } }
      }),
      minimizeApp: vi.fn().mockResolvedValue(undefined),
    },
  }
}

describe('Android shell lifecycle', () => {
  beforeEach(() => {
    document.documentElement.removeAttribute('data-native-keyboard')
    document.documentElement.style.removeProperty('--native-keyboard-height')
    document.body.innerHTML = ''
  })

  it('lets an open shared surface consume Back before router navigation', async () => {
    const app = pluginHarness()
    const keyboard = pluginHarness()
    const router = {
      currentRoute: { value: { path: '/routines' } },
      back: vi.fn(),
    }
    const consume = (event: Event) => event.preventDefault()
    window.addEventListener('selfhandler:back', consume)
    const dispose = await initializeAndroidShell({
      app: app.plugin,
      keyboard: keyboard.plugin,
      router,
      canGoBack: () => true,
      onResume: vi.fn(),
    })

    await app.listeners.get('backButton')?.({ canGoBack: true })

    expect(router.back).not.toHaveBeenCalled()
    expect(app.plugin.minimizeApp).not.toHaveBeenCalled()
    window.removeEventListener('selfhandler:back', consume)
    await dispose()
  })

  it('navigates history and minimizes only at application roots', async () => {
    const app = pluginHarness()
    const keyboard = pluginHarness()
    const route = { path: '/planner' }
    const router = { currentRoute: { value: route }, back: vi.fn() }
    const dispose = await initializeAndroidShell({
      app: app.plugin,
      keyboard: keyboard.plugin,
      router,
      canGoBack: () => route.path !== '/',
      onResume: vi.fn(),
    })

    await app.listeners.get('backButton')?.({ canGoBack: true })
    expect(router.back).toHaveBeenCalledOnce()

    route.path = '/'
    await app.listeners.get('backButton')?.({ canGoBack: false })
    expect(app.plugin.minimizeApp).toHaveBeenCalledOnce()
    await dispose()
  })

  it('publishes keyboard state, keeps focus visible, refreshes on resume, and removes listeners', async () => {
    const app = pluginHarness()
    const keyboard = pluginHarness()
    const onResume = vi.fn().mockResolvedValue(undefined)
    const input = document.createElement('input')
    input.scrollIntoView = vi.fn()
    document.body.append(input)
    input.focus()

    const dispose = await initializeAndroidShell({
      app: app.plugin,
      keyboard: keyboard.plugin,
      router: { currentRoute: { value: { path: '/' } }, back: vi.fn() },
      canGoBack: () => false,
      onResume,
    })

    await keyboard.listeners.get('keyboardWillShow')?.({ keyboardHeight: 312 })
    expect(document.documentElement.dataset.nativeKeyboard).toBe('open')
    expect(document.documentElement.style.getPropertyValue('--native-keyboard-height')).toBe('312px')
    expect(input.scrollIntoView).toHaveBeenCalledWith({ block: 'nearest', inline: 'nearest' })

    await keyboard.listeners.get('keyboardWillHide')?.()
    expect(document.documentElement.dataset.nativeKeyboard).toBeUndefined()
    await app.listeners.get('appStateChange')?.({ isActive: true })
    expect(onResume).toHaveBeenCalledOnce()

    await dispose()
    expect(app.removed).toEqual(expect.arrayContaining(['backButton', 'appStateChange']))
    expect(keyboard.removed).toEqual(expect.arrayContaining(['keyboardWillShow', 'keyboardWillHide']))
  })
})
