import { expect, test } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { mockCalendarRoutes, type CalendarRouteState } from './support'

test('Google and Apple connect, configure, retry sync, and disconnect safely', async ({ page }, testInfo) => {
  const state: CalendarRouteState = { google: null, apple: null, failNextSync: false }
  await mockCalendarRoutes(page, state)
  await registerViaUi(page, uniqueCredentials(testInfo, 'CalendarFlow'), { redirectTo: '/settings/integrations' })
  const issues = collectRuntimeIssues(page)
  await expect(page.getByRole('heading', { name: 'Calendar integrations' })).toBeVisible()

  const google = page.locator('.integration-card').filter({ has: page.getByRole('heading', { name: 'Google Calendar' }) })
  await google.getByRole('button', { name: 'Connect Google Calendar' }).click()
  await expect(google.getByText('Choose calendar', { exact: true })).toBeVisible()
  await google.getByRole('combobox', { name: 'Calendar' }).click()
  await page.getByRole('option', { name: 'Personal Google calendar' }).click()
  await google.getByRole('button', { name: 'Use this calendar' }).click()
  await expect(google.getByText('Active', { exact: true })).toBeVisible()
  await google.getByRole('checkbox', { name: 'Time blocks' }).check()
  await google.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status')).toContainText('Calendar privacy and export choices saved.')

  state.failNextSync = true
  await google.getByRole('button', { name: 'Sync now' }).click()
  await expect(page.getByRole('alert')).toContainText('provider did not respond')
  issues.length = 0
  await google.getByRole('button', { name: 'Sync now' }).click()
  await expect(google.getByRole('status')).toContainText('Imported 1')

  const apple = page.locator('.integration-card').filter({ has: page.getByRole('heading', { name: 'Apple Calendar' }) })
  await apple.getByLabel('Apple Account email').fill('apple-owner@icloud.test')
  const password = apple.getByLabel('App-specific password')
  await password.fill('abcd-efgh-ijkl-mnop')
  await apple.getByRole('button', { name: 'Connect Apple Calendar' }).click()
  await expect(password).toHaveCount(0)
  await expect(page.locator('body')).not.toContainText('abcd-efgh-ijkl-mnop')
  await apple.getByRole('combobox', { name: 'Calendar' }).click()
  await page.getByRole('option', { name: 'Personal Apple calendar' }).click()
  await apple.getByRole('button', { name: 'Use this calendar' }).click()
  await expect(apple.getByText('Active', { exact: true })).toBeVisible()

  await google.getByRole('button', { name: 'Disconnect' }).click()
  await expect(google.getByText(/removes local credentials/i)).toBeVisible()
  await google.getByRole('button', { name: 'Confirm disconnect' }).click()
  await expect(google.getByRole('button', { name: 'Connect Google Calendar' })).toBeVisible()

  for (const button of await page.locator('.integration-card button:visible').all()) {
    expect((await button.boundingBox())?.height).toBeGreaterThanOrEqual(44)
  }
  await expectNoHorizontalOverflow(page)
  await page.keyboard.press('Tab')
  expect(await page.evaluate(() => document.activeElement !== document.body)).toBeTruthy()
  expectNoRuntimeIssues(issues)
})

test('external calendar Planner entries are read-only and hide titles in busy mode', async ({ page }, testInfo) => {
  await page.route('**/api/planner/day**', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      date: '2026-08-14', today: '2026-08-14',
      entries: [{
        source: 'external_calendar', source_id: 90, title: 'Busy', time: '10:00', status: 'confirmed',
        actions: [], meta: { ends_at: '11:00', all_day: false, provider: 'google_calendar', read_only: true },
      }],
      window: { materialized_until: '2026-11-12', beyond: false },
      sources: ['external_calendar'],
    }) })
  })
  await registerViaUi(page, uniqueCredentials(testInfo, 'CalendarPlanner'), { redirectTo: '/planner' })

  const entry = page.getByRole('listitem', { name: 'Busy' })
  await expect(entry).toContainText('External calendar')
  await expect(entry).toContainText('until 11:00')
  await expect(entry.getByRole('button')).toHaveCount(0)
  await expect(page.getByText('Private provider title')).toHaveCount(0)
  await expectNoHorizontalOverflow(page)
})
