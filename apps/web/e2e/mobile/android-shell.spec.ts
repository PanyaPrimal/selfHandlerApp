import { expect, test, type Page } from '@playwright/test'

async function emulateAndroid(
  page: Page,
  options: { token?: string; sessionStatus?: number; vaultWriteFails?: boolean } = {},
) {
  await page.addInitScript(({ token, sessionStatus, vaultWriteFails }) => {
    const listeners: Record<string, Array<(value: any) => void>> = {}
    let vaultedToken = token ?? null
    let revokeCount = 0

    ;(window as any).__androidTest = {
      listeners,
      get token() { return vaultedToken },
      get revokeCount() { return revokeCount },
      dispatch(plugin: string, event: string, value: any) {
        for (const listener of listeners[`${plugin}:${event}`] ?? []) listener(value)
      },
    }
    // Capacitor derives the Android platform from the bridge injected by the
    // native Activity; the plugin doubles below then replace native calls.
    ;(window as any).androidBridge = {}
    ;(window as any).Capacitor = {
      getPlatform: () => 'android',
      isNativePlatform: () => true,
      Plugins: {
        MobileCredentialVault: {
          read: async () => ({ token: vaultedToken }),
          write: async ({ token: next }: { token: string }) => {
            if (vaultWriteFails) throw new Error('Vault unavailable')
            vaultedToken = next
          },
          clear: async () => { vaultedToken = null },
        },
        CapacitorHttp: {
          request: async ({ url, method }: { url: string; method: string }) => {
            if (url.endsWith('/api/mobile/session') && method === 'POST') {
              return {
                status: 201,
                data: {
                  data: {
                    token: 'issued-device-token',
                    token_type: 'Bearer',
                    expires_at: '2026-09-12T09:00:00.000000Z',
                    user: {
                      id: 1,
                      name: 'Android Owner',
                      email: 'owner@example.test',
                      preferences: {
                        timezone: 'Europe/Kyiv',
                        locale: 'en-GB',
                        unit_system: 'metric',
                        base_currency: 'UAH',
                        recommendation_tone: 'neutral',
                        bmr_formula: 'mifflin_st_jeor',
                        calculation_ready: false,
                        theme: {
                          scheme: 'system', accent: 'forest', background: 'plain',
                          custom_background: null, texture: true, motion: 'system', mono_numerals: true,
                        },
                      },
                    },
                  },
                },
                headers: {},
              }
            }
            if (url.endsWith('/api/mobile/session') && method === 'DELETE') {
              revokeCount += 1
              return { status: 204, data: null, headers: {} }
            }
            if (url.endsWith('/api/mobile/session') && method === 'GET') {
              return { status: sessionStatus ?? 401, data: { message: 'Unauthenticated.' }, headers: {} }
            }
            if (url.includes('/api/notifications?') && method === 'GET') {
              return {
                status: 200,
                data: {
                  data: [], unread_count: 0, views: ['all', 'unread'], snooze_options: [15, 60, 240, 1440],
                },
                headers: {},
              }
            }
            return { status: 401, data: { message: 'Unauthenticated.' }, headers: {} }
          },
        },
        App: {
          addListener: async (event: string, listener: (value: any) => void) => {
            ;(listeners[`App:${event}`] ??= []).push(listener)
            return { remove: async () => undefined }
          },
          minimizeApp: async () => undefined,
        },
        Keyboard: {
          addListener: async (event: string, listener: (value: any) => void) => {
            ;(listeners[`Keyboard:${event}`] ??= []).push(listener)
            return { remove: async () => undefined }
          },
        },
        Device: { getInfo: async () => ({ model: 'Pixel Test' }) },
        LocalNotifications: {
          addListener: async () => ({ remove: async () => undefined }),
          checkPermissions: async () => ({ display: 'prompt' }),
          requestPermissions: async () => ({ display: 'granted' }),
          createChannel: async () => undefined,
          getPending: async () => ({ notifications: [] }),
          getDeliveredNotifications: async () => ({ notifications: [] }),
          schedule: async () => ({ notifications: [] }),
        },
      },
    }
    ;(window as any).__androidTest.plugins = { ...(window as any).Capacitor.Plugins }
  }, options)
}

test.describe('Android shell shared UI', () => {
  test('existing account sign-in vaults the one-time token without Web Storage', async ({ page }) => {
    await emulateAndroid(page)
    await page.goto('/login?redirect=/changelog')
    await page.getByLabel('Email').fill('owner@example.test')
    await page.getByLabel('Password').fill('correct horse battery staple')
    await page.getByRole('button', { name: 'Sign in' }).click()

    await expect(page).toHaveURL(/\/changelog$/)
    await expect.poll(() => page.evaluate(() => (window as any).__androidTest.token))
      .toBe('issued-device-token')
    const webStorage = await page.evaluate(() => [
      ...Object.values(localStorage),
      ...Object.values(sessionStorage),
      document.cookie,
    ].join('|'))
    expect(webStorage).not.toContain('issued-device-token')
  })

  test('vault failure revokes the issued token and keeps the password transient', async ({ page }) => {
    await emulateAndroid(page, { vaultWriteFails: true })
    await page.goto('/login')
    await page.getByLabel('Email').fill('owner@example.test')
    await page.getByLabel('Password').fill('correct horse battery staple')
    await page.getByRole('button', { name: 'Sign in' }).click()

    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByLabel('Password')).toHaveValue('')
    await expect.poll(() => page.evaluate(() => (window as any).__androidTest.revokeCount)).toBe(1)
    await expect.poll(() => page.evaluate(() => (window as any).__androidTest.token)).toBeNull()
  })

  test('native registration redirects to existing-account sign-in guidance in every locale', async ({ page }) => {
    await emulateAndroid(page)
    const expected = [
      ['en-GB', /create your account in a browser/i],
      ['ru-UA', /создайте аккаунт в браузере/i],
      ['uk-UA', /створіть обліковий запис у браузері/i],
    ] as const

    for (const [locale, copy] of expected) {
      await page.addInitScript((value) => localStorage.setItem('selfhandler.locale.v1', value), locale)
      await page.goto('/register')
      await expect(page).toHaveURL(/\/login/)
      await expect(page.getByText(copy)).toBeVisible()
    }
  })

  test('expired native token is cleared and returns to sign-in', async ({ page }) => {
    await emulateAndroid(page, { token: 'expired-device-token', sessionStatus: 401 })
    await page.goto('/planner')

    await expect(page).toHaveURL(/\/login\?redirect=(?:%2F|\/)planner/)
    await expect.poll(() => page.evaluate(() => (window as any).__androidTest.token)).toBeNull()
  })

  test('keyboard bridge keeps the active sign-in control inside a 390x844 viewport', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Exact Android viewport assertion.')
    await emulateAndroid(page)
    await page.goto('/login')
    const password = page.getByLabel('Password')
    await password.focus()
    await page.evaluate(() => (window as any).__androidTest.dispatch(
      'Keyboard',
      'keyboardWillShow',
      { keyboardHeight: 312 },
    ))

    await expect.poll(() => page.evaluate(() => document.documentElement.dataset.nativeKeyboard)).toBe('open')
    await expect(password).toBeInViewport()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  })
})
