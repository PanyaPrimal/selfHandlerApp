import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const

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

test('captures EN RU UK light dark Budget Plans and Cash Flow surfaces', async ({ page }, testInfo) => {
  test.setTimeout(150_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinancePlanningVisual'), { redirectTo: '/finance' })
  const issues = collectRuntimeIssues(page)
  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      for (const tab of ['budgets', 'plans'] as const) {
        await page.getByTestId(`finance-tab-${tab}`).click()
        await expect(page.locator('main')).toBeVisible()
        await expect(page.getByRole('form', { name: tab === 'budgets'
          ? locale === 'EN' ? 'Budget editor' : locale === 'RU' ? 'Редактор бюджета' : 'Редактор бюджету'
          : locale === 'EN' ? 'Recurring operation editor' : locale === 'RU' ? 'Редактор регулярной операции' : 'Редактор регулярної операції' })).toBeVisible()
        await expectNoHorizontalOverflow(page)
        await page.screenshot({
          path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-${tab}.png`),
          fullPage: testInfo.project.name !== 'mobile',
          scale: 'css',
        })
      }
      await page.getByTestId('finance-tab-plans').click()
      await expect(page.getByTestId('finance-cash-flow')).toBeVisible()
      await page.screenshot({
        path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-cash-flow.png`),
        fullPage: testInfo.project.name !== 'mobile',
        scale: 'css',
      })
    }
  }
  expectNoRuntimeIssues(issues)
})
