import { expect, test } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from './core-daily-loop/support'
import { registerViaUi, uniqueCredentials } from './support/auth'

test('daily MVP loop works end-to-end', async ({ page }, testInfo) => {
  const routineName = `${testInfo.project.name} smoke routine ${Date.now()}`
  const credentials = uniqueCredentials(testInfo, 'MvpLoop')

  await registerViaUi(page, credentials, { redirectTo: '/routines' })
  const issues = collectRuntimeIssues(page)
  await page.getByLabel('Name').fill(routineName)
  await page.getByRole('button', { name: 'Create' }).click()
  await expect(page.getByText(routineName)).toBeVisible()

  await page.getByRole('link', { name: /Today/i }).click()
  await expect(page).toHaveURL('/')
  await expect(page.getByText(routineName)).toBeVisible()

  const routineButton = page.getByRole('button', { name: new RegExp(routineName) })
  await routineButton.click()
  await expect(routineButton).toContainText('marked done')

  await page.getByRole('link', { name: /Review/i }).click()
  await expect(page).toHaveURL('/review')
  await page.getByLabel('Went well').fill('Smoke test: routine flow works.')
  await page.getByLabel('Improve tomorrow').fill('Check goal linking next.')
  await page.getByRole('button', { name: /Save review/i }).click()
  await expect(page.getByText('Review saved.')).toBeVisible()

  expectNoRuntimeIssues(issues)
})
