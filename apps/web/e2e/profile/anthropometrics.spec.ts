import { expect, test } from '@playwright/test'
import { openFreshProfile, saveProfile } from './support'
import { chooseOption, pickDate } from '../interface/support'

test('canonical baseline survives display-unit changes and formula validation', async ({ page }, testInfo) => {
  await openFreshProfile(page, testInfo)

  await pickDate(page, 'Date of birth', '1990-06-15')
  await chooseOption(page, 'Sex used by formula', 'Female')
  await page.getByLabel('Height (cm)').fill('172.5')
  await page.getByLabel('Weight (kg)').fill('68.4')
  await chooseOption(page, 'Non-sport activity', 'Moderate')
  await saveProfile(page)

  await chooseOption(page, 'Units', 'Imperial')
  await expect(page.getByLabel('Weight (lb)')).toHaveValue('150.8')
  await chooseOption(page, 'Metabolic formula', 'Katch-McArdle')
  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByText(/Body fat percentage is required/)).toBeVisible()

  await page.getByLabel('Body fat (%)').fill('22.5')
  await saveProfile(page)
  await page.reload()
  await expect(page.getByText('Ready', { exact: true })).toBeVisible()
  await expect(page.getByLabel('Weight (lb)')).toHaveValue('150.8')
})
