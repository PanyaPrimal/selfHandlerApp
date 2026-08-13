interface ListenerHandle {
  remove(): Promise<void>
}

interface ListenerPlugin {
  addListener(event: string, listener: (event: any) => void | Promise<void>): Promise<ListenerHandle>
}

interface AppPlugin extends ListenerPlugin {
  minimizeApp(): Promise<void>
}

interface RouterLike {
  currentRoute: { value: { path: string } }
  back(): void
}

interface AndroidShellOptions {
  app: AppPlugin
  keyboard: ListenerPlugin
  router: RouterLike
  canGoBack: () => boolean
  onResume: () => void | Promise<void>
}

export async function initializeAndroidShell(options: AndroidShellOptions): Promise<() => Promise<void>> {
  const handles: ListenerHandle[] = []

  handles.push(await options.app.addListener('backButton', async () => {
    const event = new Event('selfhandler:back', { cancelable: true })
    if (!window.dispatchEvent(event)) return

    const path = options.router.currentRoute.value.path
    if (path === '/' || path === '/login' || !options.canGoBack()) {
      await options.app.minimizeApp()
      return
    }

    options.router.back()
  }))

  handles.push(await options.app.addListener('appStateChange', async ({ isActive }) => {
    if (isActive) await options.onResume()
  }))

  handles.push(await options.keyboard.addListener('keyboardWillShow', ({ keyboardHeight }) => {
    const root = document.documentElement
    root.dataset.nativeKeyboard = 'open'
    root.style.setProperty('--native-keyboard-height', `${Math.max(0, Number(keyboardHeight) || 0)}px`)
    const focused = document.activeElement
    if (focused instanceof HTMLElement) {
      focused.scrollIntoView({ block: 'nearest', inline: 'nearest' })
    }
  }))

  handles.push(await options.keyboard.addListener('keyboardWillHide', () => {
    const root = document.documentElement
    delete root.dataset.nativeKeyboard
    root.style.removeProperty('--native-keyboard-height')
  }))

  return async () => {
    delete document.documentElement.dataset.nativeKeyboard
    document.documentElement.style.removeProperty('--native-keyboard-height')
    await Promise.all(handles.map((handle) => handle.remove()))
  }
}
