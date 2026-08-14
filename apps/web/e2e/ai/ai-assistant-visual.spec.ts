import { expect, test, type Page } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { mockAiRoutes, readyAiState } from './support'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') === 'true') return
  const saved = page.waitForResponse((response) => response.request().method() === 'PATCH'
    && new URL(response.url()).pathname === '/api/profile')
  await button.click()
  await saved
}

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  await page.goto('/settings/appearance')
  const choice = page.locator('[data-field="theme_scheme"] [role="radio"]').nth(scheme === 'light' ? 0 : 1)
  if (await choice.getAttribute('aria-checked') !== 'true') {
    await choice.click()
    const saved = page.waitForResponse((response) => response.request().method() === 'PATCH'
      && new URL(response.url()).pathname === '/api/profile')
    await page.locator('.appearance-save button:not(.secondary)').click()
    await saved
  }
}

test('AI Settings and proposal review fit every locale, scheme, and supported viewport', async ({ page }, testInfo) => {
  test.setTimeout(240_000)
  const state = readyAiState(true)
  await mockAiRoutes(page, state)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AiVisual'), { redirectTo: '/storage' })
  const issues = collectRuntimeIssues(page)

  const form = page.getByRole('form', { name: 'Capture an item' })
  const saved = page.waitForResponse((response) => response.request().method() === 'POST'
    && new URL(response.url()).pathname === '/api/storage/items')
  await form.getByLabel('What is on your mind?').fill('Review the annual plan')
  await form.getByRole('button', { name: 'Capture' }).click()
  await saved

  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      await page.goto('/settings/ai')
      await expect(page.locator('.ai-connection-card')).toHaveCount(1)
      await expectNoHorizontalOverflow(page)
      await page.screenshot({
        path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-settings.png`),
        fullPage: true,
        scale: 'css',
      })

      await page.goto('/storage')
      await page.locator('.storage-inbox-item').first().getByRole('button').first().click()
      await expect(page.locator('.storage-ai-proposal')).toBeVisible()
      await expectNoHorizontalOverflow(page)
      await page.screenshot({
        path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-proposal.png`),
        fullPage: true,
        scale: 'css',
      })
    }
  }

  expectNoRuntimeIssues(issues)
})
