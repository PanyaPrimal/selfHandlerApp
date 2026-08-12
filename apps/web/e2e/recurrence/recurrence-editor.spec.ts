import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  chooseSegment,
  expectNoHorizontalOverflow,
  pickDate,
  setTime,
  toggleOption,
} from '../interface/support'

test('recurrence is edited with the shared controls and saved as one schedule', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Recurrence'))
  await page.goto('/routines')

  const form = page.getByRole('form', { name: 'Create routine' })

  // Daily needs no weekday selection at all.
  await expect(form.getByRole('group', { name: 'Weekdays', exact: true })).toHaveCount(0)

  await form.getByLabel('Name').fill('Weekly recurrence')
  await chooseSegment(form, 'Schedule', 'By weekdays')
  await expect(form.getByRole('group', { name: 'Weekdays', exact: true })).toBeVisible()

  // Weekly without a weekday is rejected in the field, and nothing is sent.
  let sawRequest = false
  page.on('request', (request) => {
    if (request.method() === 'POST' && request.url().endsWith('/api/routines')) {
      sawRequest = true
    }
  })
  await form.getByRole('button', { name: 'Create routine' }).click()
  await expect(form.getByText('Choose at least one weekday.')).toBeVisible()
  expect(sawRequest).toBe(false)

  await toggleOption(form, 'Weekdays', 'Tue')
  await toggleOption(form, 'Weekdays', 'Fri')
  await setTime(form, 'Preferred time', '06:30')
  await pickDate(page, 'Starts on', '2026-09-01')

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/api/routines'),
  )
  await form.getByRole('button', { name: 'Create routine' }).click()

  expect(JSON.parse((await request).postData() ?? '{}')).toMatchObject({
    schedule_type: 'weekdays',
    weekdays: ['TU', 'FR'],
    preferred_time: '06:30',
    starts_on: '2026-09-01',
  })

  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Weekly recurrence' })).toContainText('Tue, Fri')
  await expectNoHorizontalOverflow(page)
})

test('the editor explains that the schedule locks once results exist', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'RecurrenceLock'))
  await page.goto('/routines')

  const createForm = page.getByRole('form', { name: 'Create routine' })
  await createForm.getByLabel('Name').fill('Locked schedule')
  await createForm.getByRole('button', { name: 'Create routine' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()

  await page.getByRole('button', { name: 'Edit Locked schedule' }).click()

  const editForm = page.getByRole('form', { name: 'Edit routine' })
  await expect(editForm.getByText(/Schedule fields lock after the first daily result/)).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

test('a routine reloaded after saving keeps the schedule it was given', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'RecurrenceReload'))
  await page.goto('/routines')

  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill('Persisted schedule')
  await chooseSegment(form, 'Schedule', 'By weekdays')
  await toggleOption(form, 'Weekdays', 'Wed')
  await form.getByRole('button', { name: 'Create routine' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()

  await page.reload()

  // The schedule now lives on the recurrence rule, but the routine still
  // presents it inline exactly as before.
  await expect(page.getByRole('listitem', { name: 'Persisted schedule' })).toContainText('Wed')
})
