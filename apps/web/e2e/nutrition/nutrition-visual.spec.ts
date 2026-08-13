import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const surfaces = [
  { name: 'nutrition', path: '/nutrition' },
  { name: 'today', path: '/' },
  { name: 'review', path: '/review/2026-08-13' },
] as const

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  if (await page.locator('html').getAttribute('data-theme') !== scheme) {
    await Promise.all([
      page.waitForResponse((response) => response.url().includes('/api/profile')
        && response.request().method() === 'PATCH' && response.ok()),
      page.getByTestId('quick-theme-toggle').click(),
    ])
  }
  await expect(page.locator('html')).toHaveAttribute('data-theme', scheme)
}

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') !== 'true') {
    await Promise.all([
      page.waitForResponse((response) => response.url().includes('/api/profile')
        && response.request().method() === 'PATCH' && response.ok()),
      button.click(),
    ])
  }
  await expect(button).toHaveAttribute('aria-pressed', 'true')
}

test('captures and validates every Nutrition shared-surface visual state', async ({ page }, testInfo: TestInfo) => {
  test.setTimeout(120_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'NutritionVisual'), { redirectTo: '/nutrition' })
  await page.waitForLoadState('networkidle')
  const issues = collectRuntimeIssues(page)

  for (const locale of locales) {
    await setLocale(page, locale)

    for (const scheme of schemes) {
      await setScheme(page, scheme)

      for (const surface of surfaces) {
        await page.goto(surface.path)
        await expect(page.locator('main')).toBeVisible()
        await page.waitForLoadState('networkidle')
        await expectNoHorizontalOverflow(page)
        await page.screenshot({
          path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-${surface.name}.png`),
          fullPage: true,
        })
      }
    }
  }

  expectNoRuntimeIssues(issues)
})
