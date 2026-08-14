import { expect, test, type Page } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { mockAnalytics, type AnalyticsFixtureMode, type AnalyticsRouteState } from './support'

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
  await page.waitForLoadState('networkidle')
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

test('captures Analytics ready, empty, incomplete, comparison, and correlation states in every locale and scheme', async ({ page }, testInfo) => {
  test.setTimeout(300_000)
  const routeState: AnalyticsRouteState = {
    mode: 'ready', corrected: false, failWorkspace: false, failCorrelations: false, captured: [],
  }
  await mockAnalytics(page, routeState)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AnalyticsVisual'), {
    redirectTo: '/analytics?metric=finance.net&from=2026-08-01&to=2026-08-07&granularity=daily&compare=1',
  })
  const issues = collectRuntimeIssues(page)

  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      for (const mode of ['ready', 'empty'] as AnalyticsFixtureMode[]) {
        routeState.mode = mode
        await page.waitForLoadState('networkidle')
        await page.goto('/analytics?metric=finance.net&from=2026-08-01&to=2026-08-07&granularity=daily&compare=1')
        await expect(page.locator('main')).toBeVisible()
        await page.waitForLoadState('networkidle')
        await expectNoHorizontalOverflow(page)
        await page.evaluate(() => window.scrollTo(0, 0))
        await page.screenshot({
          path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-${mode}.png`),
          fullPage: true,
          scale: 'css',
        })
      }
    }
  }

  expectNoRuntimeIssues(issues)
})
