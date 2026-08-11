import { expect, test } from '@playwright/test'
import { openFreshProfile, saveProfile } from './support'

test('canonical baseline survives display-unit changes and formula validation', async ({ page }, testInfo) => {
  await openFreshProfile(page, testInfo)

  await page.getByLabel('Date of birth').fill('1990-06-15')
  await page.getByLabel('Sex used by formula').selectOption('female')
  await page.getByLabel('Height (cm)').fill('172.5')
  await page.getByLabel('Weight (kg)').fill('68.4')
  await page.getByLabel('Non-sport activity').selectOption('moderate')
  await saveProfile(page)

  await page.getByLabel('Units').selectOption('imperial')
  await expect(page.getByLabel('Weight (lb)')).toHaveValue('150.8')
  await page.getByLabel('Metabolic formula').selectOption('katch_mcardle')
  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByText(/Body fat percentage is required/)).toBeVisible()

  await page.getByLabel('Body fat (%)').fill('22.5')
  await saveProfile(page)
  await page.reload()
  await expect(page.getByText('Ready', { exact: true })).toBeVisible()
  await expect(page.getByLabel('Weight (lb)')).toHaveValue('150.8')
})
