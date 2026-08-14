import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { mockAnalytics, type AnalyticsRouteState } from '../analytics/support'

const analyticsUrl = '/analytics?metric=review.energy&from=2026-08-01&to=2026-08-07&granularity=daily&compare=1'

async function registerAt(page: Page, testInfo: TestInfo, path: string, label: string): Promise<void> {
  await registerViaUi(page, uniqueCredentials(testInfo, label), { redirectTo: path })
}

test('Analytics downloads the applied query as CSV and PDF and recovers from failure', async ({ page }, testInfo) => {
  const analytics: AnalyticsRouteState = {
    mode: 'ready', corrected: false, failWorkspace: false, failCorrelations: false, captured: [],
  }
  await mockAnalytics(page, analytics)
  const reportRequests: URL[] = []
  let failCsv = true
  await page.route('**/api/reports/analytics.*', async (route) => {
    const url = new URL(route.request().url())
    reportRequests.push(url)
    const format = url.pathname.endsWith('.pdf') ? 'pdf' : 'csv'
    if (format === 'csv' && failCsv) {
      failCsv = false
      await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable.' }) })
      return
    }
    await route.fulfill({
      status: 200,
      contentType: format === 'pdf' ? 'application/pdf' : 'text/csv',
      headers: { 'Content-Disposition': `attachment; filename="applied-report.${format}"` },
      body: format === 'pdf' ? '%PDF-1.7\nfixture' : '\uFEFFperiod,value\n2026-08-01,4',
    })
  })

  await registerAt(page, testInfo, analyticsUrl, 'Reports')
  await page.getByRole('button', { name: 'Download CSV' }).click()
  await expect(page.getByRole('alert')).toContainText('The report could not be downloaded.')

  const csvDownload = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Retry' }).click()
  expect((await csvDownload).suggestedFilename()).toBe('applied-report.csv')

  const pdfDownload = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Download PDF' }).click()
  expect((await pdfDownload).suggestedFilename()).toBe('applied-report.pdf')

  const last = reportRequests.at(-1)!
  expect(Object.fromEntries(last.searchParams)).toEqual({
    metric: 'review.energy', from: '2026-08-01', to: '2026-08-07', granularity: 'daily', compare: '1',
  })
  await expectNoHorizontalOverflow(page)
})

test('Data validates a selected archive, clears malformed state, and restores with exact confirmation', async ({ page }, testInfo) => {
  await registerAt(page, testInfo, '/settings/data', 'Portability')
  const issues = collectRuntimeIssues(page)
  await expect(page.getByRole('heading', { name: 'Your data' })).toBeVisible()

  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Download backup' }).click()
  const download = await downloadPromise
  expect(download.suggestedFilename()).toMatch(/^selfhandler-backup-.*\.zip$/)
  const backupPath = await download.path()
  expect(backupPath).not.toBeNull()

  const chooser = page.getByLabel('Choose backup ZIP')
  await chooser.setInputFiles({ name: 'broken.zip', mimeType: 'application/zip', buffer: Buffer.from('not a zip') })
  await page.getByRole('button', { name: 'Validate backup' }).click()
  await expect(page.getByRole('alert')).toContainText('intact supported SelfHandler backup')
  issues.length = 0

  await chooser.setInputFiles(backupPath!)
  await expect(page.getByRole('alert')).toHaveCount(0)
  await page.getByRole('button', { name: 'Validate backup' }).click()
  await expect(page.getByRole('heading', { name: 'Validation summary' })).toBeVisible()
  await expect(page.getByText('The archive is valid.')).toBeVisible()
  await expect(page.getByText('Account credentials and login identity')).toBeVisible()

  const restore = page.getByRole('button', { name: 'Restore backup' })
  await expect(restore).toBeDisabled()
  await page.getByLabel('Type RESTORE to confirm').fill('restore')
  await expect(restore).toBeDisabled()
  await page.getByLabel('Type RESTORE to confirm').fill('RESTORE')
  await expect(restore).toBeEnabled()
  await restore.click()
  await expect(page.getByRole('heading', { name: 'Restore complete' })).toBeVisible()
  await expect(page.getByText(/target login identity was kept/i)).toBeVisible()

  expect((await page.getByLabel('Choose backup ZIP').boundingBox())?.height).toBeGreaterThan(0)
  await page.keyboard.press('Tab')
  expect(await page.evaluate(() => document.activeElement !== document.body)).toBeTruthy()
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('non-empty account receives a read-only ineligible preflight on desktop and exact phone', async ({ page }, testInfo) => {
  await registerAt(page, testInfo, '/settings/data', 'PortabilityBusy')
  const headers = await xsrfHeader(page)
  const created = await page.request.put('/api/daily-reviews/2026-08-14', { headers, data: { energy: 8 } })
  expect(created.ok()).toBeTruthy()

  const downloadPromise = page.waitForEvent('download')
  await page.getByRole('button', { name: 'Download backup' }).click()
  const backupPath = await (await downloadPromise).path()
  await page.getByLabel('Choose backup ZIP').setInputFiles(backupPath!)
  await page.getByRole('button', { name: 'Validate backup' }).click()

  await expect(page.getByText('This account is not empty, so restore is unavailable.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Restore backup' })).toHaveCount(0)
  const validateBox = await page.getByRole('button', { name: 'Validate backup' }).boundingBox()
  expect(validateBox?.height).toBeGreaterThanOrEqual(44)

  if (testInfo.project.name === 'mobile') {
    await page.getByRole('button', { name: 'More' }).click()
    const dataLink = page.getByRole('menuitem', { name: 'Data' })
    await expect(dataLink).toBeVisible()
    expect((await dataLink.boundingBox())?.height).toBeGreaterThanOrEqual(44)
  } else {
    await expect(page.getByRole('link', { name: 'Data', exact: true }).first()).toBeVisible()
  }
  await expectNoHorizontalOverflow(page)
})
