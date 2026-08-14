import { expect, test, type Page } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'

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

test('captures valid Data preflight in every locale and scheme', async ({ page }, testInfo) => {
  test.setTimeout(240_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'PortabilityVisual'), { redirectTo: '/settings/data' })
  const issues = collectRuntimeIssues(page)
  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Download backup' }).click()
  const backupPath = await (await downloadPromise).path()

  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      await page.goto('/settings/data')
      await page.locator('input[type="file"]').setInputFiles(backupPath!)
      await page.locator('.data-actions button').last().click()
      await expect(page.locator('.data-validation')).toBeVisible()
      await expectNoHorizontalOverflow(page)
      await page.evaluate(() => window.scrollTo(0, 0))
      await page.screenshot({
        path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-valid.png`),
        fullPage: true,
        scale: 'css',
      })
    }
  }

  expectNoRuntimeIssues(issues)
})
