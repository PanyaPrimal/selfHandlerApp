import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { chooseOption, expectNoHorizontalOverflow, pickDate } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const
const tabs = ['debts', 'funds', 'goals'] as const
const today = new Date().toISOString().slice(0, 10)
const courseEnd = new Date(Date.now() + (7 * 24 * 60 * 60 * 1000)).toISOString().slice(0, 10)

async function createWallet(page: Page, name: string): Promise<void> {
  await page.getByTestId('finance-tab-accounts').click()
  const form = page.getByRole('form', { name: 'Account editor' })
  await form.getByLabel('Name').fill(name)
  await form.getByLabel('Opening balance').fill('1000.0000')
  await form.getByRole('button', { name: 'Add account' }).click()
  await expect(page.getByText(name, { exact: true })).toBeVisible()
}

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') !== 'true') {
    const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
    await button.click()
    await saved
    await page.waitForLoadState('networkidle')
  }
}

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  if (await page.locator('html').getAttribute('data-theme') === scheme) return
  const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
  await page.getByTestId('quick-theme-toggle').click()
  await saved
}

test('captures commitment editors in EN RU UK light dark desktop and exact 390', async ({ page }, testInfo) => {
  test.setTimeout(300_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinanceCommitmentVisual'), { redirectTo: '/finance' })
  const issues = collectRuntimeIssues(page)
  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      for (const tab of tabs) {
        await page.getByTestId(`finance-tab-${tab}`).click()
        await expect(page.locator(`#finance-${tab}-heading`)).toBeVisible()
        await expectNoHorizontalOverflow(page)
        await page.screenshot({
          path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-${tab}.png`),
          fullPage: testInfo.project.name !== 'mobile',
          scale: 'css',
        })
      }
    }
  }
  expectNoRuntimeIssues(issues)
})

test('captures purchase and restock source editors in EN RU UK light dark desktop and exact 390', async ({ page }, testInfo) => {
  test.setTimeout(300_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinanceSourceVisual'), { redirectTo: '/finance' })
  const issues = collectRuntimeIssues(page)
  await createWallet(page, 'Visual source wallet')

  await page.goto('/storage')
  const capture = page.getByRole('form', { name: 'Capture an item' })
  await chooseOption(capture, 'Type', 'Purchase')
  await capture.getByLabel('What is on your mind?').fill('Visual office chair')
  await capture.getByLabel('Estimate').fill('250.0000')
  await capture.getByRole('button', { name: 'Capture' }).click()
  await expect(page.getByRole('listitem', { name: 'Visual office chair' })).toBeVisible()

  await page.goto('/supplements')
  await page.getByRole('tab', { name: 'Catalogue' }).click()
  await page.getByRole('button', { name: 'Add reference' }).click()
  const reference = page.getByRole('form', { name: 'Supplement reference editor' })
  await reference.getByLabel('Name').fill('Visual magnesium')
  await reference.getByLabel('Package quantity').fill('30')
  await reference.getByRole('button', { name: 'Save' }).click()
  let supplement = page.getByRole('article').filter({ hasText: 'Visual magnesium' }).first()
  await supplement.getByRole('button', { name: 'Manage stock' }).click()
  const stock = page.getByRole('form', { name: 'Stock movement editor' })
  await stock.getByLabel('Quantity').fill('1')
  await stock.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Stock movement recorded.' })).toBeVisible()
  await expect(page.getByRole('form', { name: 'Stock movement editor' }).getByRole('button', { name: 'Save' })).toBeEnabled()
  const coursesTab = page.getByRole('tab', { name: 'Courses' })
  await coursesTab.click()
  await expect(coursesTab).toHaveAttribute('aria-selected', 'true')
  await page.getByRole('button', { name: 'Add course' }).click()
  const course = page.getByRole('form', { name: 'Course editor' })
  await course.getByLabel('Course name').fill('Visual restock course')
  await pickDate(page, 'Starts on', today)
  await pickDate(page, 'Ends on', courseEnd)
  await course.getByRole('button', { name: 'Save' }).click()
  await page.getByRole('tab', { name: 'Catalogue' }).click()
  supplement = page.getByRole('article').filter({ hasText: 'Visual magnesium' }).first()
  const restockId = await supplement.locator('[data-restock-proposal]').getAttribute('data-restock-proposal')
  expect(restockId).toMatch(/^\d+$/)

  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)

      await page.goto('/storage')
      const purchase = page.getByRole('listitem', { name: 'Visual office chair' })
      await purchase.locator('.purchase-finance button:not(.secondary)').click()
      await expect(purchase.locator('.purchase-finance form[aria-label]')).toBeVisible()
      await expectNoHorizontalOverflow(page)
      await page.screenshot({
        path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-source-purchase.png`),
        fullPage: testInfo.project.name !== 'mobile',
        scale: 'css',
      })

      await page.goto(`/supplements?restock=${restockId}`)
      const proposal = page.locator(`[data-restock-proposal="${restockId}"]`)
      await expect(proposal).toBeVisible()
      await proposal.locator('button.secondary').click()
      await expect(proposal.locator('form.finance-form')).toBeVisible()
      await expectNoHorizontalOverflow(page)
      await page.screenshot({
        path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-source-restock.png`),
        fullPage: testInfo.project.name !== 'mobile',
        scale: 'css',
      })
    }
  }
  expectNoRuntimeIssues(issues)
})
