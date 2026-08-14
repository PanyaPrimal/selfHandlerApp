import { expect, test, type Page, type TestInfo } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { mockAnalytics, type AnalyticsRouteState } from './support'

const initialUrl = '/analytics?metric=review.energy&from=2026-08-01&to=2026-08-07&granularity=daily&compare=1'

function state(): AnalyticsRouteState {
  return { mode: 'ready', corrected: false, failWorkspace: false, failCorrelations: false, captured: [] }
}

async function openAnalytics(page: Page, testInfo: TestInfo, routeState: AnalyticsRouteState): Promise<void> {
  await mockAnalytics(page, routeState)
  await registerViaUi(page, uniqueCredentials(testInfo, 'Analytics'), { redirectTo: initialUrl })
  await expect(page.getByRole('heading', { name: 'Analytics' })).toBeVisible()
}

test('trend, exact buckets, comparison, correlations, correction, and URL reload stay aligned', async ({ page, context }, testInfo) => {
  const routeState = state()
  await openAnalytics(page, testInfo, routeState)
  const issues = collectRuntimeIssues(page)

  await expect(page).toHaveURL(initialUrl)
  await expect(page.getByText('7', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('4 out of 10', { exact: true }).first()).toBeVisible()
  await expect(page.getByText('10 out of 10', { exact: true }).first()).toBeVisible()
  const table = page.getByRole('table', { name: 'Exact interval values and evidence coverage' })
  await expect(table.locator('tbody tr')).toHaveCount(7)
  await expect(table).toContainText('No evidence')
  const chart = page.getByRole('img', { name: /Energy over time/ })
  await expect(chart).toBeVisible()
  await expect(chart.locator('.analytics-chart__line')).toHaveCount(4)

  await expect(page.getByRole('heading', { name: 'Sleep duration and energy' })).toBeVisible()
  await expect(page.getByText('0.8123').first()).toBeVisible()
  await expect(page.getByText('At least 7 aligned days are required.')).toBeVisible()
  await expect(page.getByText('One metric did not vary in the selected range.')).toBeVisible()
  await expect(page.getByText(/association, not causation/i)).toBeVisible()

  for (const payload of routeState.captured) {
    const serialized = JSON.stringify(payload)
    for (const forbidden of ['"id":', '"notes":', '"journal":', '"attachment":', '"transaction":', '"secret":']) {
      expect(serialized).not.toContain(forbidden)
    }
  }

  routeState.corrected = true
  await page.reload()
  await expect(page.getByText('9 out of 10', { exact: true }).first()).toBeVisible()
  await expect(page).toHaveURL(initialUrl)

  const secondPage = await context.newPage()
  await mockAnalytics(secondPage, routeState)
  await secondPage.goto(initialUrl)
  await expect(secondPage.getByText('9 out of 10', { exact: true }).first()).toBeVisible()
  await secondPage.close()
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('comparison toggle and guarded previous-zero state are explicit', async ({ page }, testInfo) => {
  const routeState = state()
  routeState.mode = 'previous-zero'
  await openAnalytics(page, testInfo, routeState)

  await expect(page.getByText('Relative change is unavailable because the preceding value is zero.')).toBeVisible()
  await page.getByLabel('Compare with the preceding equal period').uncheck()
  await page.getByRole('button', { name: 'Apply' }).click()
  await expect(page).toHaveURL(/compare=0/)
  await expect(page.getByRole('heading', { name: 'Current and preceding period' })).toHaveCount(0)

  await page.getByLabel('Compare with the preceding equal period').check()
  await page.getByRole('button', { name: 'Apply' }).click()
  await expect(page).toHaveURL(/compare=1/)
  await expect(page.getByText('Relative change is unavailable because the preceding value is zero.')).toBeVisible()
})

test('workspace and correlation failures clear stale data and recover independently', async ({ page }, testInfo) => {
  const routeState = state()
  await openAnalytics(page, testInfo, routeState)
  await expect(page.getByRole('table')).toBeVisible()

  routeState.failWorkspace = true
  await page.reload()
  await expect(page.getByRole('alert')).toContainText('Analytics could not be loaded.')
  await expect(page.getByRole('table')).toHaveCount(0)
  await page.getByRole('button', { name: 'Retry' }).click()
  await expect(page.getByRole('table')).toBeVisible()

  routeState.failCorrelations = true
  await page.reload()
  await expect(page.getByRole('alert')).toContainText('Correlations could not be loaded.')
  await expect(page.getByRole('heading', { name: 'Sleep duration and energy' })).toHaveCount(0)
  await page.getByRole('button', { name: 'Retry' }).click()
  await expect(page.getByRole('heading', { name: 'Sleep duration and energy' })).toBeVisible()
})

test('invalid URL state canonicalizes and controls remain keyboard, touch, and mobile-navigation safe', async ({ page }, testInfo) => {
  const routeState = state()
  await mockAnalytics(page, routeState)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AnalyticsA11y'), { redirectTo: initialUrl })
  await page.goto('/analytics?metric=private.value&from=2026-02-30&to=broken&granularity=year&compare=0')
  await expect(page).toHaveURL(/metric=sleep.duration_minutes/)
  await expect(page).toHaveURL(/granularity=daily/)
  await expect(page).toHaveURL(/compare=0/)

  for (const name of ['Metric', 'From', 'To', 'Group by', 'Compare with the preceding equal period']) {
    await expect(page.getByLabel(name, { exact: true })).toBeVisible()
  }
  await expect(page.getByRole('img', { name: /Sleep duration over time/ }).locator('title').first()).toHaveText('Sleep duration over time')
  await expect(page.getByRole('table', { name: 'Exact interval values and evidence coverage' })).toBeVisible()

  await page.keyboard.press('Tab')
  const focusVisible = await page.evaluate(() => document.activeElement !== document.body)
  expect(focusVisible).toBeTruthy()
  const applyBox = await page.getByRole('button', { name: 'Apply' }).boundingBox()
  expect(applyBox?.height).toBeGreaterThanOrEqual(44)

  if (testInfo.project.name === 'mobile') {
    await page.getByRole('button', { name: 'More' }).click()
    const analyticsLink = page.getByRole('menuitem', { name: 'Analytics' })
    await expect(analyticsLink).toBeVisible()
    expect((await analyticsLink.boundingBox())?.height).toBeGreaterThanOrEqual(44)
  } else {
    await expect(page.getByRole('link', { name: 'Analytics', exact: true })).toBeVisible()
  }
  await expectNoHorizontalOverflow(page)
})
