import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

test('long localized copy and global controls fit the exact phone viewport', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Exact 390x844 layout check')
  await registerViaUi(page, uniqueCredentials(testInfo, 'LocalizedMobile'), { redirectTo: '/settings/appearance' })

  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Оформлення' })).toBeVisible()

  const metrics = await page.evaluate(() => ({
    documentWidth: document.documentElement.scrollWidth,
    viewportWidth: document.documentElement.clientWidth,
    controls: document.querySelector('[data-testid="global-preferences"]')?.getBoundingClientRect().toJSON(),
  }))

  expect(metrics.documentWidth).toBeLessThanOrEqual(metrics.viewportWidth)
  expect(metrics.controls?.left).toBeGreaterThanOrEqual(0)
  expect(metrics.controls?.right).toBeLessThanOrEqual(metrics.viewportWidth)

  const savedLocale = page.waitForResponse((response) => (
    response.request().method() === 'PATCH' && new URL(response.url()).pathname === '/api/profile'
  ))
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  expect((await savedLocale).ok()).toBeTruthy()
  await page.goto('/account')
  await expect(page.getByText('Ваш часовой пояс определяет, что означает «Сегодня» для этого аккаунта.')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(390)
})
