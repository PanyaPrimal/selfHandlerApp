import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  chooseOption,
  chooseSegment,
  expectNoHorizontalOverflow,
  gotoDestination,
  dateTrigger,
  pickDate,
  searchAndChoose,
} from '../interface/support'

/**
 * Saving reloads the whole panel, so the entry form is replaced between saves.
 * Waiting on the request rather than on the status text keeps the steps ordered
 * against the real work instead of against a message that is still fading.
 */
async function saveMeasurement(page: Page, date: string, value: string): Promise<void> {
  const form = page.getByRole('form', { name: 'Record a measurement' })

  await pickDate(page, 'Measured on', date)
  await expect(dateTrigger(page, 'Measured on')).not.toContainText('Pick a date')
  await form.getByLabel('Value').fill(value)

  const saved = page.waitForResponse(
    (response) => response.request().method() === 'PUT'
      && response.url().endsWith('/api/body/measurements'),
  )
  await form.getByRole('button', { name: 'Save measurement' }).click()

  expect((await saved).status()).toBe(200)
  await expect(page.getByRole('status').filter({ hasText: 'Measurement saved.' })).toBeVisible()
}

test('measurements are recorded, corrected, trended and deleted', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Body'))
  await gotoDestination(page, 'Body')
  await expect(page).toHaveURL('/body')

  // An empty metric explains itself rather than showing zeros.
  await expect(page.getByText('No measurements yet for this metric.')).toBeVisible()

  await saveMeasurement(page, '2026-07-01', '84')

  // One point is an explicit insufficient-data state, not a zero trend.
  await expect(page.getByText(/A second one is needed/)).toBeVisible()

  await saveMeasurement(page, '2026-07-15', '83')

  // -1 kg over 14 days is -0.5 kg a week.
  const trend = page.getByRole('region', { name: 'Trend' })
  await expect(trend).toContainText('-0.5')

  const history = page.getByRole('region', { name: 'History' })
  await expect(history.getByText('84 kg')).toBeVisible()
  await expect(history.getByText('83 kg')).toBeVisible()

  // Saving the same day again corrects it instead of adding a row.
  await saveMeasurement(page, '2026-07-15', '82.5')
  await expect(history.getByText('82.5 kg')).toBeVisible()
  await expect(history.getByText('83 kg')).toHaveCount(0)

  await page.getByRole('button', { name: 'Delete measurement from 2026-07-01' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Measurement deleted.' })).toBeVisible()
  await expect(page.getByText(/A second one is needed/)).toBeVisible()

  await expectNoHorizontalOverflow(page)
})

test('a body goal shows progress and warns about an unsafe pace without changing the target', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'BodyGoal'))
  await page.goto('/body')

  const entry = page.getByRole('form', { name: 'Record a measurement' })
  await entry.getByLabel('Value').fill('90')
  await entry.getByRole('button', { name: 'Save measurement' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Measurement saved.' })).toBeVisible()

  await page.getByRole('button', { name: 'Add a body goal' }).click()

  const goalForm = page.getByRole('form', { name: 'Create body goal' })
  await goalForm.getByLabel('Goal name').fill('Reach 80 kg')
  await chooseSegment(goalForm, 'Direction', 'Lose')
  await goalForm.getByLabel('Starting value').fill('90')
  await goalForm.getByLabel('Target value').fill('80')
  await pickDate(page, 'Target date', '2026-09-09')

  const request = page.waitForRequest(
    (candidate) => candidate.method() === 'POST' && candidate.url().endsWith('/api/body/goals'),
  )
  await goalForm.getByRole('button', { name: 'Save body goal' }).click()

  // Values cross the API in canonical grams, not the kilograms shown.
  expect(JSON.parse((await request).postData() ?? '{}')).toMatchObject({
    metric: 'body_mass',
    direction: 'lose',
    starting_value: 90000,
    target_value: 80000,
    target_date: '2026-09-09',
  })

  // 10 kg in four weeks is far past the guidance, so it is flagged, and the
  // goal is still saved with exactly the numbers that were entered.
  await expect(page.getByText(/CDC describes 1 to 2 pounds a week/)).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Reach 80 kg' })).toContainText('90 kg')
  await expect(page.getByRole('listitem', { name: 'Reach 80 kg' })).toContainText('80 kg')

  await expectNoHorizontalOverflow(page)
})

test('display units convert without drift', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'BodyUnits'))

  await page.goto('/body')
  const form = page.getByRole('form', { name: 'Record a measurement' })
  await form.getByLabel('Value').fill('82.5')
  await form.getByRole('button', { name: 'Save measurement' }).click()
  await expect(page.getByRole('region', { name: 'History' }).getByText('82.5 kg')).toBeVisible()

  await page.goto('/account')
  await chooseOption(page, 'Units', 'Imperial')
  await searchAndChoose(page, 'Timezone', 'UTC', 'UTC')
  await page.locator('.profile-actions button[type="submit"]').click()
  await expect(page.getByText('Profile saved.')).toBeVisible()

  await page.goto('/body')
  await expect(page.getByRole('region', { name: 'History' }).getByText('181.88 lb')).toBeVisible()

  await page.goto('/account')
  await chooseOption(page, 'Units', 'Metric')
  await page.locator('.profile-actions button[type="submit"]').click()
  await expect(page.getByText('Profile saved.')).toBeVisible()

  await page.goto('/body')
  // Back to exactly the number that was entered: the canonical value never moved.
  await expect(page.getByRole('region', { name: 'History' }).getByText('82.5 kg')).toBeVisible()
})

test('the body screen is reachable and usable at 390px', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Compact layout')

  await registerViaUi(page, uniqueCredentials(testInfo, 'BodyMobile'))
  await gotoDestination(page, 'Body')

  await expect(page).toHaveURL('/body')
  await expect(page.getByRole('heading', { name: 'Measurements and body goals' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})
