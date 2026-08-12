import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

test('guest locale and quick scheme are applied from cache before mount', async ({ page }) => {
  await page.addInitScript(() => {
    if (!localStorage.getItem('selfhandler.locale.v1')) localStorage.setItem('selfhandler.locale.v1', 'ru-UA')
    if (!localStorage.getItem('selfhandler.theme.v1')) {
      localStorage.setItem('selfhandler.theme.v1', JSON.stringify({
        scheme: 'dark',
        accent: 'forest',
        accent_hex: '#6d5ac4',
        background: 'paper',
        background_hex: '#ece9e2',
        texture: true,
        mono_numerals: true,
        motion: 'system',
      }))
    }
  })

  await page.goto('/login')
  await expect(page.locator('html')).toHaveAttribute('lang', 'ru')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await expect(page.getByRole('heading', { name: 'С возвращением' })).toBeVisible()

  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'uk')
  await expect(page.getByRole('heading', { name: 'З поверненням' })).toBeVisible()

  await page.getByTestId('quick-theme-toggle').click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('lang', 'uk')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
})

test('authenticated locale persists independently and preserves an Account draft', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'LocaleDraft'), { redirectTo: '/account' })
  await expect(page.getByRole('heading', { name: 'Your personal baseline' })).toBeVisible()

  const name = page.getByLabel('Display name')
  await name.fill('Unsaved profile draft')
  await page.getByRole('button', { name: 'RU', exact: true }).click()

  await expect(page.locator('html')).toHaveAttribute('lang', 'ru')
  await expect(page.getByLabel('Отображаемое имя')).toHaveValue('Unsaved profile draft')
  await expect(page.getByText('Несохранённые изменения')).toBeVisible()

  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('lang', 'ru')
  await expect(page.getByRole('heading', { name: 'Ваши исходные данные' })).toBeVisible()
})

test('failed authenticated locale and theme changes roll back to accepted profile values', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PreferenceRollback'))
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

  await page.route('**/api/profile', async (route) => {
    if (route.request().method() === 'PATCH') {
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable' }) })
      return
    }
    await route.continue()
  })

  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'en')
  await expect(page.getByText('Could not save your language. English was restored.')).toBeVisible()

  await page.getByTestId('quick-theme-toggle').click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  await expect(page.getByText('Could not save your theme. Light mode was restored.')).toBeVisible()
})

test('quick scheme persists the full authenticated theme', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'QuickTheme'))

  await page.getByTestId('quick-theme-toggle').click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await page.reload()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

  const profile = await page.evaluate(async () => {
    const response = await fetch('/api/profile', { headers: { Accept: 'application/json' } })
    return { ok: response.ok, body: await response.json() }
  })
  expect(profile.ok).toBeTruthy()
  expect(profile.body.data.theme.scheme).toBe('dark')
})

test('rapid locale changes ignore an older response that finishes last', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PreferenceRace'))
  const profile = await page.evaluate(async () => (await fetch('/api/profile', {
    headers: { Accept: 'application/json' },
  })).json())

  await page.route('**/api/profile', async (route) => {
    if (route.request().method() !== 'PATCH') {
      await route.continue()
      return
    }

    const locale = route.request().postDataJSON().preferences.locale as 'ru-UA' | 'uk-UA'
    if (locale === 'ru-UA') await new Promise((resolve) => setTimeout(resolve, 500))

    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        ...profile,
        data: {
          ...profile.data,
          locale,
          user: {
            ...profile.data.user,
            preferences: { ...profile.data.user.preferences, locale },
          },
        },
      }),
    })
  })

  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('lang', 'uk')
  await page.waitForTimeout(700)
  await expect(page.locator('html')).toHaveAttribute('lang', 'uk')
  await expect(page.getByRole('heading', { name: /Добрий вечір/ })).toBeVisible()
})
