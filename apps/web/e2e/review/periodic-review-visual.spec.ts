import { expect, test, type Page } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark', 'system'] as const
const surfaces = [
  { name: 'daily', path: '/review/2026-08-14' },
  { name: 'weekly', path: '/review/weekly/2026-08-14' },
  { name: 'monthly', path: '/review/monthly/2026-08-14' },
] as const

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') === 'true') return

  const saved = page.waitForResponse((response) => (
    response.request().method() === 'PATCH' && new URL(response.url()).pathname === '/api/profile'
  ))
  await button.click()
  await saved
}

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  await page.goto('/settings/appearance')
  const index = schemes.indexOf(scheme)
  const choice = page.locator('[data-field="theme_scheme"] [role="radio"]').nth(index)
  await expect(choice).toBeVisible()

  if (await choice.getAttribute('aria-checked') !== 'true') {
    await choice.click()
    const saved = page.waitForResponse((response) => (
      response.request().method() === 'PATCH' && new URL(response.url()).pathname === '/api/profile'
    ))
    await page.locator('.appearance-save button:not(.secondary)').click()
    await saved
  }
}

test('captures daily weekly and monthly Review in EN RU UK and light dark system modes', async ({ page }, testInfo) => {
  test.setTimeout(300_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'ReviewVisual'), {
    redirectTo: '/review/weekly/2026-08-14',
  })
  await page.waitForLoadState('networkidle')
  const issues = collectRuntimeIssues(page)

  for (const locale of locales) {
    await setLocale(page, locale)

    for (const scheme of schemes) {
      if (scheme === 'system') await page.emulateMedia({ colorScheme: 'dark' })
      await setScheme(page, scheme)

      for (const surface of surfaces) {
        await page.goto(surface.path)
        await expect(page.locator('main')).toBeVisible()
        await page.waitForLoadState('networkidle')
        await expectNoHorizontalOverflow(page)
        await page.evaluate(() => window.scrollTo(0, 0))
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
