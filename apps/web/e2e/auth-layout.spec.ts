import { expect, test } from '@playwright/test'

test('Russian auth remains usable on compact screens and with enlarged text', async ({ page }, testInfo) => {
  await page.addInitScript(() => localStorage.setItem('selfhandler.locale.v1', 'ru-UA'))
  for (const width of [320, 360, 1366]) {
    await page.setViewportSize({ width, height: width === 1366 ? 900 : 720 })
    await page.goto('/login')
    await expect(page.getByRole('heading', { name: 'С возвращением' })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Планирование', exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Здоровье', exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Финансы', exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Создать аккаунт' })).toBeVisible()
    if (width === 320) await page.addStyleTag({ content: 'html { font-size: 24px !important; }' })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.screenshot({ path: testInfo.outputPath(`login-ru-${width}.png`), fullPage: true })
    await page.getByRole('link', { name: 'Создать аккаунт' }).click()
    await expect(page.getByRole('heading', { name: 'Создайте аккаунт' })).toBeVisible()
    await expect(page.locator('[name="invite_code"]')).toHaveCount(0)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
    await page.screenshot({ path: testInfo.outputPath(`register-ru-${width}.png`), fullPage: true })
  }
})
