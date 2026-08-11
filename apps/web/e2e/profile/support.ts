import { expect, type Page, type TestInfo } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

export async function openFreshProfile(page: Page, testInfo: TestInfo): Promise<void> {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Profile'), { redirectTo: '/account' })
  await expect(page.getByRole('heading', { name: 'Your personal baseline' })).toBeVisible()
  await expect(page.getByText('All changes saved')).toBeVisible()
}

export async function saveProfile(page: Page): Promise<void> {
  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByText('Profile saved.')).toBeVisible()
  await expect(page.getByText('All changes saved')).toBeVisible()
}
