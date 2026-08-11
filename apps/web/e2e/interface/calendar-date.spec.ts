import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { clearDate, dateTrigger, pickDate } from './support'

// West of UTC is where the classic `new Date('2026-08-16')` bug shows itself:
// parsing a calendar day as a UTC instant renders the previous day.
test.use({ timezoneId: 'America/New_York' })

test('a calendar date is a day, not an instant', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'CalendarDate'))

  await page.goto('/goals')
  await expect(page.getByRole('heading', { name: /goal/i }).first()).toBeVisible()

  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill('Date stability')

  await pickDate(page, 'Target date', '2026-01-01')
  const trigger = dateTrigger(page, 'Target date')
  await expect(trigger).toContainText('1 Jan 2026')

  // Reopening and closing without choosing must not move the value.
  await trigger.click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(trigger).toContainText('1 Jan 2026')

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/api/goals'),
  )
  await form.getByRole('button', { name: 'Create goal' }).click()

  const body = JSON.parse((await request).postData() ?? '{}') as { target_date?: string }
  expect(body.target_date).toBe('2026-01-01')

  await expect(page.getByRole('listitem', { name: 'Date stability' })).toContainText('1 Jan 2026')

  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Date stability' })).toContainText('1 Jan 2026')
})

test('an empty date field stays empty until a day is chosen', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'CalendarEmpty'))

  await page.goto('/goals')
  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill('No implicit date')

  const trigger = dateTrigger(page, 'Target date')
  await expect(trigger).toContainText('Pick a date')

  // Opening the calendar only moves the view; it never writes a value.
  await trigger.click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('ArrowRight')
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Escape')
  await expect(trigger).toContainText('Pick a date')

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/api/goals'),
  )
  await form.getByRole('button', { name: 'Create goal' }).click()

  const body = JSON.parse((await request).postData() ?? '{}') as { target_date?: string | null }
  expect(body.target_date).toBeNull()
})

test('a chosen date can be cleared back to empty', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'CalendarClear'))

  await page.goto('/goals')
  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill('Clearable date')

  await pickDate(page, 'Target date', '2026-03-15')
  await expect(dateTrigger(page, 'Target date')).toContainText('15 Mar 2026')

  await clearDate(page, 'Target date')
  await expect(dateTrigger(page, 'Target date')).toContainText('Pick a date')
})
