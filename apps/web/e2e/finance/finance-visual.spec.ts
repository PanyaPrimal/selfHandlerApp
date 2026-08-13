import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const
const tabs = ['Overview', 'Accounts', 'Categories', 'Rates', 'Activity'] as const

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') !== 'true') {
    const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
    await button.click(); await saved; await page.waitForLoadState('networkidle')
  }
}

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  if (await page.locator('html').getAttribute('data-theme') === scheme) return
  const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
  await page.getByTestId('quick-theme-toggle').click(); await saved
}

test('captures EN RU UK light dark Finance desktop and exact 390 surfaces', async ({ page }, testInfo) => {
  test.setTimeout(150_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinanceVisual'), { redirectTo: '/finance' })
  await page.waitForLoadState('networkidle')
  const issues = collectRuntimeIssues(page)
  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      for (const [index, tab] of tabs.entries()) {
        await page.getByRole('tab').nth(index).click()
        await expect(page.locator('main')).toBeVisible()
        await expectNoHorizontalOverflow(page)
        await page.screenshot({
          path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-${tab.toLowerCase()}.png`),
          fullPage: testInfo.project.name !== 'mobile',
          scale: 'css',
        })
      }
    }
  }
  expectNoRuntimeIssues(issues)
})
