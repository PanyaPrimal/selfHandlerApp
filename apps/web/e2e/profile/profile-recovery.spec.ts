import { expect, test } from '@playwright/test'
import { openFreshProfile } from './support'

test('failed save preserves the draft, blocks duplicates, and recovers on retry', async ({ page }, testInfo) => {
  await openFreshProfile(page, testInfo)
  let failed = false

  await page.route('**/api/profile', async (route) => {
    if (route.request().method() === 'PUT' && !failed) {
      failed = true
      await route.fulfill({ status: 503, contentType: 'application/json', body: '{"message":"Unavailable"}' })
      return
    }
    await route.continue()
  })

  const draft = `A very long profile name that remains after failure ${Date.now()}`
  await page.getByLabel('Display name').fill(draft)
  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByText(/draft is still here/)).toBeVisible()
  await expect(page.getByLabel('Display name')).toHaveValue(draft)
  await expect(page.getByText('Unsaved changes')).toBeVisible()

  await page.getByRole('button', { name: 'Save profile' }).click()
  await expect(page.getByText('Profile saved.')).toBeVisible()
  await expect(page.locator('body')).not.toHaveCSS('overflow-x', 'scroll')
})
