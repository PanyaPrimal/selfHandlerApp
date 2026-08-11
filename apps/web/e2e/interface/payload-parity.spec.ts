import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { chooseOption, chooseSegment, pickDate, searchAndChoose, setSwitch, setTime, toggleOption } from './support'

/**
 * The migration replaces elements, not behaviour. "No payload change" is a
 * negative requirement, so it is asserted positively here: these are the exact
 * bodies the pre-migration screens produced for the same user input.
 */

test('routine create sends the same body the native controls produced', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'ParityRoutine'))
  await page.goto('/routines')

  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill('Parity routine')
  await form.getByLabel('Description').fill('Same body as before.')
  await chooseOption(form, 'Kind', 'Habit')
  await chooseSegment(form, 'Schedule', 'By weekdays')
  await toggleOption(form, 'Weekdays', 'Wed')
  await toggleOption(form, 'Weekdays', 'Mon')
  await setTime(form, 'Preferred time', '07:30')
  await pickDate(page, 'Starts on', '2026-09-01')
  await form.getByLabel('Display order').fill('3')
  await setSwitch(form, 'Active in planning', true)

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/api/routines'),
  )
  await form.getByRole('button', { name: 'Create routine' }).click()

  expect(JSON.parse((await request).postData() ?? '{}')).toEqual({
    name: 'Parity routine',
    description: 'Same body as before.',
    kind: 'habit',
    preferred_time: '07:30',
    sort_order: 3,
    is_active: true,
    starts_on: '2026-09-01',
    ends_on: null,
    schedule_type: 'weekdays',
    // Weekdays are emitted in calendar order, not in the order they were clicked.
    weekdays: ['MO', 'WE'],
  })

  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()
})

test('goal create keeps its body and preserves the draft after a rejected save', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'ParityGoal'))
  await page.goto('/goals')

  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill('Parity goal')
  await form.getByLabel('Description').fill('Draft must survive.')
  await pickDate(page, 'Target date', '2026-12-31')

  await page.route('**/api/goals', async (route) => {
    if (route.request().method() !== 'POST') {
      await route.continue()
      return
    }

    await route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Invalid.', errors: { name: ['The goal name could not be saved.'] } }),
    })
  })

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/api/goals'),
  )
  await form.getByRole('button', { name: 'Create goal' }).click()

  expect(JSON.parse((await request).postData() ?? '{}')).toEqual({
    name: 'Parity goal',
    description: 'Draft must survive.',
    target_date: '2026-12-31',
  })

  // Field error, focus recovery and the untouched draft all behave as before.
  await expect(page.getByText('The goal name could not be saved.')).toBeVisible()
  await expect(form.getByLabel('Name')).toBeFocused()
  await expect(form.getByLabel('Name')).toHaveValue('Parity goal')
  await expect(form.getByLabel('Description')).toHaveValue('Draft must survive.')
})

test('daily review keeps its body', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'ParityReview'))
  await page.goto('/review/2026-08-06')

  await page.getByLabel('Mood').fill('4')
  await page.getByLabel('Energy').fill('3')
  await page.getByLabel('Stress').fill('2')
  await page.getByLabel('Day rating').fill('5')
  await page.getByLabel('Went well').fill('Parity held.')
  await page.getByLabel('Improve tomorrow').fill('Nothing.')
  await page.getByLabel('Notes').fill('Body unchanged.')

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'PUT' && candidate.url().includes('/api/daily-reviews/2026-08-06'),
  )
  await page.getByRole('button', { name: 'Save review' }).click()

  expect(JSON.parse((await request).postData() ?? '{}')).toEqual({
    mood: 4,
    energy: 3,
    stress: 2,
    day_rating: 5,
    went_well: 'Parity held.',
    improve_tomorrow: 'Nothing.',
    notes: 'Body unchanged.',
  })
})

test('profile save keeps canonical values and a submit in flight is not duplicated', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'ParityProfile'))
  await page.goto('/account')
  await expect(page.getByRole('heading', { name: 'Your personal baseline' })).toBeVisible()

  await searchAndChoose(page, 'Timezone', 'Europe/Kyiv', 'Europe/Kyiv')
  await chooseOption(page, 'Units', 'Metric')
  await page.getByLabel('Height (cm)').fill('180')
  await page.getByLabel('Weight (kg)').fill('75')

  const requests: string[] = []
  page.on('request', (candidate) => {
    if (candidate.method() === 'PUT' && candidate.url().endsWith('/api/profile')) {
      requests.push(candidate.postData() ?? '')
    }
  })

  // Hold the response open so a second click lands while the first is in flight.
  let release = (): void => {}
  const held = new Promise<void>((resolve) => {
    release = resolve
  })
  await page.route('**/api/profile', async (route) => {
    if (route.request().method() === 'PUT') {
      await held
    }

    await route.continue()
  })

  const save = page.locator('.profile-actions button[type="submit"]')
  await save.click()
  await save.click({ force: true })
  release()

  await expect(page.getByText('Profile saved.')).toBeVisible()
  expect(requests).toHaveLength(1)

  const body = JSON.parse(requests[0]) as Record<string, unknown>
  // Canonical base units, not the displayed centimetres and kilograms.
  expect(body.height_meters).toBe(1.8)
  expect(body.weight_grams).toBe(75_000)
  expect(body.timezone).toBe('Europe/Kyiv')
  expect(body.unit_system).toBe('metric')
})
