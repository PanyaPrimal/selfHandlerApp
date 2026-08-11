import { expect, test } from '@playwright/test'
import { openFreshProfile, saveProfile } from './support'
import { chooseOption, searchAndChoose, selectTrigger } from '../interface/support'

test('regional preferences persist and update the accepted account summary', async ({ page }, testInfo) => {
  await openFreshProfile(page, testInfo)

  await page.getByLabel('Display name').fill('Олексій Profile')
  await searchAndChoose(page, 'Timezone', 'Europe/Kyiv', 'Europe/Kyiv')
  await chooseOption(page, 'Language & date format', 'uk-UA')
  await chooseOption(page, 'Units', 'Imperial')
  await chooseOption(page, 'Base currency', 'EUR')
  await chooseOption(page, 'Recommendation tone', 'Friendly')
  await expect(page.getByText('Unsaved changes')).toBeVisible()
  await saveProfile(page)

  await page.reload()
  await expect(page.getByLabel('Display name')).toHaveValue('Олексій Profile')
  await expect(page.getByLabel('Timezone')).toHaveValue('Europe/Kyiv')
  await expect(selectTrigger(page, 'Language & date format')).toHaveText('uk-UA')
  await expect(selectTrigger(page, 'Units')).toHaveText('Imperial')
})
