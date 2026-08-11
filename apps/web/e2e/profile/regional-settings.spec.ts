import { expect, test } from '@playwright/test'
import { openFreshProfile, saveProfile } from './support'

test('regional preferences persist and update the accepted account summary', async ({ page }, testInfo) => {
  await openFreshProfile(page, testInfo)

  await page.getByLabel('Display name').fill('Олексій Profile')
  await page.getByLabel('Timezone').selectOption('Europe/Kyiv')
  await page.getByLabel('Language & date format').selectOption('uk-UA')
  await page.getByLabel('Units').selectOption('imperial')
  await page.getByLabel('Base currency').selectOption('EUR')
  await page.getByLabel('Recommendation tone').selectOption('friendly')
  await expect(page.getByText('Unsaved changes')).toBeVisible()
  await saveProfile(page)

  await page.reload()
  await expect(page.getByLabel('Display name')).toHaveValue('Олексій Profile')
  await expect(page.getByLabel('Timezone')).toHaveValue('Europe/Kyiv')
  await expect(page.getByLabel('Language & date format')).toHaveValue('uk-UA')
  await expect(page.getByLabel('Units')).toHaveValue('imperial')
})
