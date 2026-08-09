import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from './support/auth'

function collectRuntimeIssues(page: Page): string[] {
  const issues: string[] = []

  page.on('console', (message) => {
    const text = message.text()

    if (text.includes('[vite]')) {
      return
    }

    if (message.type() === 'warning' || message.type() === 'error') {
      issues.push(`[console:${message.type()}] ${text}`)
    }
  })

  page.on('pageerror', (error) => {
    issues.push(`[pageerror] ${error.message}`)
  })

  page.on('requestfailed', (request) => {
    issues.push(`[requestfailed] ${request.method()} ${request.url()} ${request.failure()?.errorText}`)
  })

  page.on('response', (response) => {
    const url = new URL(response.url())

    if (response.status() === 401 && url.pathname === '/api/auth/user') {
      return
    }

    if (response.url().includes('/api/') && response.status() >= 400) {
      issues.push(`[response] ${response.status()} ${response.request().method()} ${response.url()}`)
    }
  })

  return issues
}

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

  expect(issues).toEqual([])
})
