import { expect, test, type Page } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from './support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { chooseSegment, dateTrigger, pickDate } from '../interface/support'

const selectedMonday = '2026-08-10'

async function createWeekdayRoutine(page: Page, name: string, weekday: 'Mon' | 'Tue', order: number): Promise<void> {
  const form = page.getByRole('form', { name: 'Create routine' })

  await form.getByLabel('Name').fill(name)
  await chooseSegment(form, 'Schedule', 'By weekdays')
  await form.getByRole('button', { name: weekday, exact: true }).click()
  await form.getByLabel('Display order').fill(String(order))
  await form.getByRole('button', { name: 'Create routine' }).click()

  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name })).toBeVisible()
}

async function selectDate(page: Page, date: string): Promise<void> {
  await pickDate(page, 'Date', date)
  await expect(dateTrigger(page, 'Date')).toBeVisible()
  await expect(page.getByRole('heading', { name: /Good evening/ })).toBeVisible()
  await expect(page.getByText('Loading selected date…')).toBeHidden()
}

async function expectMetric(page: Page, label: string, value: string): Promise<void> {
  const summary = page.getByRole('region', { name: 'Daily completion summary' })
  await expect(summary.locator('.metric').filter({ hasText: label }).locator('strong')).toHaveText(value)
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  await expect.poll(() => page.evaluate(
    () => document.documentElement.scrollWidth <= document.documentElement.clientWidth,
  )).toBe(true)
}

test('weekday routine planning and daily state transitions survive reload', async ({ page }, testInfo) => {
  const suffix = `${testInfo.project.name}-${Date.now()}`
  const firstName = `First Monday ${suffix}`
  const laterName = `Later Monday ${suffix}`
  const otherDayName = `Tuesday only ${suffix}`

  await registerViaUi(page, uniqueCredentials(testInfo, 'RoutineFlow'), { redirectTo: '/routines' })
  const issues = collectRuntimeIssues(page)

  await expect(page.getByRole('heading', { name: 'No routines yet' })).toBeVisible()

  const createForm = page.getByRole('form', { name: 'Create routine' })
  await createForm.getByLabel('Name').fill('Missing weekday')
  await chooseSegment(createForm, 'Schedule', 'By weekdays')
  await createForm.getByRole('button', { name: 'Create routine' }).click()
  await expect(createForm.getByText('Choose at least one weekday.')).toBeVisible()

  await createWeekdayRoutine(page, laterName, 'Mon', 5)
  await createWeekdayRoutine(page, firstName, 'Mon', 1)
  await createWeekdayRoutine(page, otherDayName, 'Tue', 0)

  await page.getByRole('button', { name: `Edit ${laterName}` }).click()
  const editForm = page.getByRole('form', { name: 'Edit routine' })
  await editForm.getByLabel('Description').fill('Edited before any history exists.')
  await editForm.getByRole('button', { name: 'Save changes' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine updated.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: laterName })).toContainText('Edited before any history exists.')

  await page.getByRole('link', { name: /Today/i }).click()
  await selectDate(page, selectedMonday)

  const routineTitles = page.locator('.routine-row .routine-title')
  await expect(routineTitles).toHaveText([firstName, laterName])
  await expect(page.getByText(otherDayName)).toBeHidden()
  await expectMetric(page, 'Scheduled', '2')
  await expectMetric(page, 'Done', '0')
  await expectMetric(page, 'Skipped / pending', '0 / 2')
  await expectMetric(page, 'Completion', '0%')

  const firstDoneResponse = page.waitForResponse((response) =>
    response.request().method() === 'PUT' && response.url().includes('/logs/2026-08-10'),
  )
  await page.getByRole('button', { name: `Mark ${firstName} done` }).click()
  const firstLogId = (await (await firstDoneResponse).json() as { data: { id: number } }).data.id
  await expectMetric(page, 'Done', '1')
  await expectMetric(page, 'Skipped / pending', '0 / 1')
  await expectMetric(page, 'Completion', '50%')

  const repeatedDoneResponse = page.waitForResponse((response) =>
    response.request().method() === 'PUT' && response.url().includes('/logs/2026-08-10'),
  )
  await page.getByRole('button', { name: `Mark ${firstName} done` }).click()
  const repeatedLogId = (await (await repeatedDoneResponse).json() as { data: { id: number } }).data.id
  expect(repeatedLogId).toBe(firstLogId)

  const skippedResponse = page.waitForResponse((response) =>
    response.request().method() === 'PUT' && response.url().includes('/logs/2026-08-10'),
  )
  await page.getByRole('button', { name: `Mark ${firstName} skipped` }).click()
  await skippedResponse
  await expect(page.getByRole('status').filter({ hasText: `${firstName} is skipped.` })).toBeVisible()
  await expectMetric(page, 'Done', '0')
  await expectMetric(page, 'Skipped / pending', '1 / 1')

  const pendingResponse = page.waitForResponse((response) =>
    response.request().method() === 'DELETE' && response.url().includes('/logs/2026-08-10'),
  )
  await page.getByRole('button', { name: `Set ${firstName} to pending` }).click()
  await pendingResponse
  await expect(page.getByRole('status').filter({ hasText: `${firstName} is pending.` })).toBeVisible()
  await expectMetric(page, 'Skipped / pending', '0 / 2')

  const finalDoneResponse = page.waitForResponse((response) =>
    response.request().method() === 'PUT' && response.url().includes('/logs/2026-08-10'),
  )
  await page.getByRole('button', { name: `Mark ${firstName} done` }).click()
  await finalDoneResponse
  await expect(page.getByRole('status').filter({ hasText: `${firstName} is done.` })).toBeVisible()
  await page.reload()
  await selectDate(page, selectedMonday)
  await expect(page.getByRole('button', { name: `Mark ${firstName} done` })).toHaveAttribute('aria-pressed', 'true')
  await expectMetric(page, 'Done', '1')
  await expectMetric(page, 'Completion', '50%')
  await expectNoHorizontalOverflow(page)

  await page.getByRole('link', { name: 'Manage' }).click()
  await page.getByRole('button', { name: `Pause ${laterName}` }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine paused.' })).toBeVisible()
  await page.getByRole('button', { name: `Resume ${laterName}` }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine resumed.' })).toBeVisible()

  await page.getByRole('button', { name: `Archive ${firstName}` }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine archived.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: firstName })).toBeHidden()
  await page.getByRole('button', { name: 'Archived', exact: true }).click()
  await expect(page.getByRole('listitem', { name: firstName })).toBeVisible()
  await page.getByRole('button', { name: `Restore ${firstName}` }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine restored.' })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'No archived routines' })).toBeVisible()
  await expectNoHorizontalOverflow(page)

  expectNoRuntimeIssues(issues)
})

test('routine list load failure has an explicit retry path', async ({ page }, testInfo) => {
  let shouldFail = true

  await page.route('**/api/routines?archived=false', async (route) => {
    if (shouldFail) {
      shouldFail = false
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Routine service is temporarily unavailable.' }),
      })
      return
    }

    await route.continue()
  })

  await registerViaUi(page, uniqueCredentials(testInfo, 'RoutineRetry'), { redirectTo: '/routines' })
  await expect(page.getByRole('alert')).toContainText('Routine service is temporarily unavailable.')
  await page.getByRole('button', { name: 'Retry' }).click()
  await expect(page.getByRole('heading', { name: 'No routines yet' })).toBeVisible()
})
