import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const
const surfaces = [{ name: 'supplements', path: '/supplements' }, { name: 'today', path: '/' }, { name: 'review', path: '/review/2026-08-13' }, { name: 'planner', path: '/planner?date=2026-08-13' }, { name: 'notifications', path: '/notifications' }] as const

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') !== 'true') {
    const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
    await button.click()
    await saved
  }
  await expect(button).toHaveAttribute('aria-pressed', 'true')
}

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  if (await page.locator('html').getAttribute('data-theme') === scheme) return
  const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
  await page.getByTestId('quick-theme-toggle').click()
  await saved
  await expect(page.locator('html')).toHaveAttribute('data-theme', scheme)
}

test('captures EN RU UK light dark desktop and exact mobile shared surfaces', async ({ page }, testInfo) => {
  test.setTimeout(150_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'SupplementsVisual'), { redirectTo: '/supplements' })
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
          fullPage: testInfo.project.name !== 'mobile',
          scale: 'css',
        })
      }
    }
  }
  expectNoRuntimeIssues(issues)
})
