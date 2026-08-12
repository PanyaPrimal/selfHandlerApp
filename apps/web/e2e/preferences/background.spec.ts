import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

test('background presets and custom tint preview both schemes and persist', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Background'), { redirectTo: '/settings/appearance' })

  await page.getByRole('radio', { name: /^Mist,/ }).click()
  await expect(page.locator('html')).toHaveAttribute('data-background', 'mist')

  await page.getByLabel('Background hex').fill('#345678')
  await page.getByRole('button', { name: 'Use background colour' }).click()
  await expect(page.locator('html')).toHaveAttribute('data-background', 'custom')

  const contrast = page.getByTestId('background-contrast')
  await expect(contrast).toContainText(':1')
  expect(Number((await contrast.textContent())?.split(':')[0])).toBeGreaterThanOrEqual(4.5)

  await page.getByRole('radio', { name: /^Dark/ }).click()
  expect(Number((await contrast.textContent())?.split(':')[0])).toBeGreaterThanOrEqual(4.5)

  await page.getByRole('button', { name: 'Save appearance' }).click()
  await expect(page.getByText('Appearance saved.')).toBeVisible()
  await page.reload()

  await expect(page.locator('html')).toHaveAttribute('data-background', 'custom')
  await expect(page.getByLabel('Background hex')).toHaveValue('#345678')
})

test('invalid custom background keeps the last valid palette and blocks save', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'BackgroundInvalid'), { redirectTo: '/settings/appearance' })

  await page.getByRole('radio', { name: /^Sand,/ }).click()
  await expect(page.locator('html')).toHaveAttribute('data-background', 'sand')

  await page.getByLabel('Background hex').fill('#123')
  await page.getByRole('button', { name: 'Use background colour' }).click()

  await expect(page.getByText('Enter a six-digit hex colour, for example #345678.')).toBeVisible()
  await expect(page.locator('html')).toHaveAttribute('data-background', 'sand')
  await expect(page.getByRole('button', { name: 'Save appearance' })).toBeDisabled()
})

test('legacy theme cache receives background defaults before first paint', async ({ page }) => {
  await page.addInitScript(() => {
    localStorage.setItem('selfhandler.theme.v1', JSON.stringify({
      scheme: 'dark', accent: 'slate', accent_hex: '#6d5ac4', texture: true,
      mono_numerals: true, motion: 'system',
    }))
  })

  await page.goto('/login')
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await expect(page.locator('html')).toHaveAttribute('data-background', 'paper')
})
