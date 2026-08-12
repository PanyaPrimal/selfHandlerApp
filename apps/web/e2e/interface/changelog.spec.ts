import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from './support'

test('the changelog opens directly, survives reload, and is ordered newest first', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Changelog'))

  await page.goto('/changelog')
  await expect(page.getByRole('heading', { name: 'What is new in SelfHandler' })).toBeVisible()

  const dates = await page.locator('.changelog-entry time').evaluateAll((nodes) =>
    nodes.map((node) => node.getAttribute('datetime') ?? ''),
  )

  expect(dates.length).toBeGreaterThanOrEqual(8)
  expect([...dates]).toEqual([...dates].sort().reverse())

  // Each entry carries the three things the reader needs.
  const first = page.locator('.changelog-entry').first()
  await expect(first.getByRole('heading')).toBeVisible()
  await expect(first.getByText('How to test')).toBeVisible()

  await page.reload()
  await expect(page.getByRole('heading', { name: 'What is new in SelfHandler' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

test('changelog links navigate into the application', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'ChangelogLinks'))

  await page.goto('/changelog')
  await page.locator('.changelog-entry').filter({ hasText: 'Profile and settings' }).getByRole('link', { name: 'Account' }).click()

  await expect(page).toHaveURL('/account')
  await expect(page.getByRole('heading', { name: 'Your personal baseline' })).toBeVisible()
})

test('a signed-out visitor is sent to sign-in and returned to the changelog', async ({ page }) => {
  await page.goto('/changelog')

  await expect(page).toHaveURL('/login?redirect=/changelog')
  await expect(page.getByRole('heading', { name: 'Welcome back' })).toBeVisible()
})
