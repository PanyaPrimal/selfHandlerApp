import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'

test('appearance applies immediately, persists on the profile, and survives reload', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Appearance'), { redirectTo: '/settings/appearance' })
  await expect(page.getByRole('heading', { name: 'Appearance' })).toBeVisible()

  await page.getByRole('radio', { name: /^Dark/ }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

  await page.getByRole('radio', { name: /^Slate,/ }).click()
  await expect(page.locator('html')).toHaveAttribute('data-accent', 'slate')

  await page.getByRole('switch', { name: 'Dotted page texture' }).click()
  await page.getByRole('switch', { name: 'Monospace numerals' }).click()
  await page.getByRole('radio', { name: 'Always reduce' }).click()

  await expect(page.locator('html')).toHaveAttribute('data-texture', 'off')
  await expect(page.locator('html')).toHaveAttribute('data-mono-numerals', 'off')
  await expect(page.locator('html')).toHaveAttribute('data-motion', 'reduce')

  await page.getByRole('button', { name: 'Save appearance' }).click()
  await expect(page.getByText('Appearance saved.')).toBeVisible()
  await page.reload()

  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await expect(page.locator('html')).toHaveAttribute('data-accent', 'slate')
  await expect(page.getByText(/Saved for Appearance/)).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

test('a rejected save restores the accepted theme', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'AppearanceRollback'), { redirectTo: '/settings/appearance' })
  await expect(page.getByRole('heading', { name: 'Appearance' })).toBeVisible()

  await page.route('**/api/profile', async (route) => {
    if (route.request().method() === 'PATCH') {
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable' }) })
      return
    }
    await route.continue()
  })

  await page.getByRole('radio', { name: /^Dark/ }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await page.getByRole('button', { name: 'Save appearance' }).click()

  await expect(page.getByText(/previous theme has been restored/)).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
})

test('custom colour and the complete settings layout fit the 390px target', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Exact mobile layout check')
  await registerViaUi(page, uniqueCredentials(testInfo, 'AppearanceMobile'), { redirectTo: '/settings/appearance' })

  await page.getByLabel('Hex', { exact: true }).fill('#6D5AC4')
  await page.getByRole('button', { name: 'Use', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('data-accent', 'custom')
  await expect(page.getByText(/:1/).first()).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Live preview' })).toBeVisible()
  await expectNoHorizontalOverflow(page)

  const more = page.locator('.nav-list--compact').getByRole('button', { name: /More/ })
  await expect(more).toHaveAttribute('aria-current', 'page')
})
