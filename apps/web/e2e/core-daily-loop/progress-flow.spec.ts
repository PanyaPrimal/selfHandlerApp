import { expect, test, type Locator, type Page, type Response, type TestInfo } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from './support'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { chooseSegment, dateTrigger, pickDate, toggleOption } from '../interface/support'

const PERIOD_END = '2026-08-06'

interface ItemResponse {
  data: {
    id: number
  }
}

function isApiResponse(response: Response, method: string, path: string): boolean {
  return response.request().method() === method && new URL(response.url()).pathname === path
}

async function useRequiredViewport(page: Page, testInfo: TestInfo): Promise<void> {
  if (testInfo.project.name === 'mobile') {
    await page.setViewportSize({ width: 390, height: 844 })
    expect(page.viewportSize()?.width).toBe(390)
  }
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }))

  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth)
}

async function createRoutine(
  page: Page,
  name: string,
  schedule: 'daily' | 'weekdays',
  weekdays: string[] = [],
): Promise<number> {
  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill(name)
  await chooseSegment(form, 'Schedule', schedule === 'daily' ? 'Daily' : 'By weekdays')

  if (schedule === 'weekdays') {
    for (const weekday of weekdays) {
      await toggleOption(form, 'Weekdays', weekday)
    }
  }

  const responsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'POST', '/api/routines')
  ))
  await form.getByRole('button', { name: 'Create routine' }).click()
  const response = await responsePromise
  expect(response.status()).toBe(201)
  const payload = await response.json() as ItemResponse

  await expect(page.getByRole('listitem', { name, exact: true })).toBeVisible()
  return payload.data.id
}

async function seedLog(
  page: Page,
  headers: Record<string, string>,
  routineId: number,
  date: string,
  status: 'done' | 'skipped',
): Promise<void> {
  const response = await page.request.put(`/api/routines/${routineId}/logs/${date}`, {
    headers,
    data: { status },
  })

  expect(response.status()).toBe(200)
}

async function openPeriodEnd(page: Page): Promise<void> {
  await expect(dateTrigger(page, 'Date')).toBeEnabled()

  const responsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return response.request().method() === 'GET'
      && url.pathname === '/api/today'
      && url.searchParams.get('date') === PERIOD_END
  })
  await pickDate(page, 'Date', PERIOD_END)
  expect((await responsePromise).status()).toBe(200)
}

function labeledValue(container: Locator, label: string): Locator {
  return container.getByText(label, { exact: true }).locator('..')
}

test('seven-day progress and current streak match controlled history', async ({ page }, testInfo) => {
  test.slow()
  await useRequiredViewport(page, testInfo)

  const credentials = uniqueCredentials(testInfo, 'ProgressFlow')
  const dailyName = 'Daily progress routine'
  const weekdayName = 'Selected weekdays routine'

  await registerViaUi(page, credentials, { redirectTo: '/routines' })
  const issues = collectRuntimeIssues(page)
  const dailyId = await createRoutine(page, dailyName, 'daily')
  const weekdayId = await createRoutine(page, weekdayName, 'weekdays', ['Mon', 'Wed', 'Thu'])
  const headers = await xsrfHeader(page)

  // Daily: seven scheduled, four done, one skipped, two pending. The final
  // three dates are done, so the selected-day streak is exactly three.
  await seedLog(page, headers, dailyId, '2026-07-31', 'done')
  await seedLog(page, headers, dailyId, '2026-08-01', 'skipped')
  await seedLog(page, headers, dailyId, '2026-08-04', 'done')
  await seedLog(page, headers, dailyId, '2026-08-05', 'done')
  await seedLog(page, headers, dailyId, PERIOD_END, 'done')

  // Weekdays MO/WE/TH: three scheduled, one done, one skipped, one pending.
  await seedLog(page, headers, weekdayId, '2026-08-03', 'done')
  await seedLog(page, headers, weekdayId, '2026-08-05', 'skipped')

  await page.getByRole('link', { name: 'Today', exact: true }).click()
  await openPeriodEnd(page)

  const todaySummary = page.getByRole('region', { name: 'Daily completion summary' })
  await expect(labeledValue(todaySummary, 'Completion')).toContainText('50%')
  await expect(labeledValue(todaySummary, 'Scheduled')).toContainText('2')
  await expect(labeledValue(todaySummary, 'Done')).toContainText('1')
  await expect(labeledValue(todaySummary, 'Skipped / pending')).toContainText('0 / 1')

  const dailyRoutine = page.getByRole('listitem', { name: dailyName, exact: true })
  await expect(dailyRoutine).toContainText('done')
  await expect(dailyRoutine).toContainText('3-day streak')

  const recentProgress = page.getByRole('region', { name: 'Recent progress' })
  await expect(labeledValue(recentProgress, 'Scheduled')).toContainText('10')
  await expect(labeledValue(recentProgress, 'Done')).toContainText('5')
  await expect(labeledValue(recentProgress, 'Skipped')).toContainText('2')
  await expect(labeledValue(recentProgress, 'Pending')).toContainText('3')
  await expect(recentProgress.getByText('50%', { exact: true })).toBeVisible()

  const sevenDayProgress = recentProgress.getByRole('progressbar', { name: 'Seven-day completion' })
  await expect(sevenDayProgress).toHaveAttribute('aria-valuenow', '50')
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('seven-day progress has a deliberate empty state', async ({ page }, testInfo) => {
  await useRequiredViewport(page, testInfo)

  const credentials = uniqueCredentials(testInfo, 'ProgressEmpty')
  await registerViaUi(page, credentials)
  const issues = collectRuntimeIssues(page)
  await openPeriodEnd(page)

  const recentProgress = page.getByRole('region', { name: 'Recent progress' })
  await expect(recentProgress).toContainText('No scheduled occurrences in this seven-day period.')
  await expect(recentProgress.getByRole('progressbar', { name: 'Seven-day completion' })).toHaveCount(0)
  await expect(recentProgress).not.toContainText('%')
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})
