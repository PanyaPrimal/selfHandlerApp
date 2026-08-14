import { expect, test, type Page, type Response, type TestInfo } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

function isPeriodicSave(response: Response, period: 'weekly' | 'monthly'): boolean {
  return response.request().method() === 'PUT'
    && new URL(response.url()).pathname.startsWith(`/api/periodic-reviews/${period}/`)
}

async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const dimensions = await page.evaluate(() => ({
    clientWidth: document.documentElement.clientWidth,
    scrollWidth: document.documentElement.scrollWidth,
  }))

  expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth)
}

async function useRequiredViewport(page: Page, testInfo: TestInfo): Promise<void> {
  if (testInfo.project.name === 'mobile') {
    await page.setViewportSize({ width: 390, height: 844 })
    expect(page.viewportSize()).toEqual({ width: 390, height: 844 })
  }
}

function dailyWorkspace(scoreMode: 'full' | 'partial' | 'empty') {
  const values = { nutrition: 90, workouts: 50, supplements: 60, habits: 80, planner: 80 }
  const reasons = {
    nutrition: 'no_target_evidence', workouts: 'no_workout', supplements: 'no_scheduled_items',
    habits: 'no_scheduled_items', planner: 'no_planner_items',
  } as const
  const keys = Object.keys(values) as Array<keyof typeof values>
  const available: Array<keyof typeof values> = scoreMode === 'full' ? keys : scoreMode === 'partial' ? ['nutrition'] : []

  return {
    period: { type: 'daily', anchor: '2026-08-12', start: '2026-08-12', end: '2026-08-12', timezone: 'UTC' },
    review: null,
    modules: {
      routines: { scheduled: 2, done: 1, skipped: 0, pending: 1, completion_rate: 50 },
      routine_activities: { scheduled: 2, done: 1, skipped: 0, pending: 1, completion_rate: 50, templates: [{ routine_id: 1, name: 'Morning reset', scheduled: 2, done: 1, skipped: 0, pending: 1, completion_rate: 50 }] },
      sleep: { period_start: '2026-08-12', period_end: '2026-08-12', planned_nights: 1, recorded_nights: 1, average_duration_minutes: 480, average_quality: 8, selected_night: null },
      workouts: { planned: 2, completed: 1, skipped: 0, unplanned: 0, duration_seconds: 3600, distance_m: 5000, strength_volume_kg: '2500.000' },
      nutrition: { date: '2026-08-12', meal_count: 3, entry_count: 4, calories: '2100.000', protein_grams: '120.000', fat_grams: '70.000', carbs_grams: '250.000', hydration_ml: '2200.000', quality_score: '8.000', progress: {} },
      supplements: { done: 3, skipped: 1, overdue: 0, pending: 1, eligible: 4, adherence_percentage: 75 },
      habits: { scheduled: 4, done: 3, skipped: 0, pending: 1, completion_rate: 75, successful: 3, unsuccessful: 1, habit_count: 4 },
      planner: { scheduled: 4, done: 3, skipped: 0, pending: 1, completion_rate: 75, time_blocks: 2, due_items: 2, open_blockers: 0 },
      finance: { from: '2026-08-12', to: '2026-08-12', base_currency: 'UAH', complete: true, income: '1000.0000', expense: '400.0000', net: '600.0000', missing_currencies: [] },
    },
    day_score: {
      value: scoreMode === 'full' ? 72 : scoreMode === 'partial' ? 90 : null,
      available_components: available.length,
      total_components: 5,
      coverage_percentage: available.length * 20,
      components: keys.map((key) => ({
        key,
        available: available.includes(key),
        value: available.includes(key) ? values[key] : null,
        weight: available.includes(key) ? 1 / available.length : 0,
        reason: available.includes(key) ? 'available' : reasons[key],
      })),
    },
  }
}

test('daily score renders all-source full partial and empty evidence states', async ({ page }, testInfo) => {
  await useRequiredViewport(page, testInfo)
  await registerViaUi(page, uniqueCredentials(testInfo, 'DailyReviewEvidence'))
  let scoreMode: 'full' | 'partial' | 'empty' = 'full'
  await page.route('**/api/review-workspaces/daily/2026-08-12', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: dailyWorkspace(scoreMode) }) })
  })

  await page.goto('/review/2026-08-12')
  const score = page.getByRole('region', { name: 'Day score' })
  await expect(score).toContainText('72%')
  await expect(score).toContainText('5 of 5 components included with equal weight.')
  for (const name of ['Routines', 'Sleep summary', 'Workout summary', 'Nutrition summary', 'Supplements summary', 'Habits', 'Planner', 'Finance']) {
    await expect(page.getByRole('region', { name })).toBeVisible()
  }

  scoreMode = 'partial'
  await page.reload()
  await expect(score).toContainText('90%')
  await expect(score).toContainText('1 of 5 components included with equal weight.')
  await expect(score).toContainText('No workout evidence')

  scoreMode = 'empty'
  await page.reload()
  await expect(score).toContainText('No score components have enough evidence for this date.')
  await expect(score).toContainText('No nutrition target evidence')
  await expectNoHorizontalOverflow(page)
})

test('weekly reflection uses one canonical period, preserves drafts, validates, and retries', async ({ page }, testInfo) => {
  test.slow()
  await useRequiredViewport(page, testInfo)
  await registerViaUi(page, uniqueCredentials(testInfo, 'WeeklyReview'), {
    redirectTo: '/review/weekly/2026-08-14',
  })

  await expect(page.getByRole('heading', { name: 'Weekly review' }).first()).toBeVisible()
  await expect(page.getByText('10 Aug 2026 – 16 Aug 2026')).toBeVisible()
  await expect(page.getByRole('navigation', { name: 'Review period' })).toBeVisible()
  await expectNoHorizontalOverflow(page)

  await page.getByLabel('Period rating').fill('8')
  await page.getByLabel('What worked well').fill('Protected the first focus block.')
  await page.getByLabel('What did not work').fill('Late meetings fragmented the afternoon.')
  await page.getByLabel('What I learned').fill('Morning work is the most reliable.')
  await page.getByLabel('Focus for the next period').fill('Keep mornings quiet.')
  await page.getByLabel('Notes').fill('Canonical weekly reflection.')

  let failWithValidation = true
  await page.route('**/api/periodic-reviews/weekly/*', async (route) => {
    if (route.request().method() !== 'PUT' || !failWithValidation) {
      await route.continue()
      return
    }

    failWithValidation = false
    await route.fulfill({
      status: 422,
      contentType: 'application/json',
      body: JSON.stringify({
        message: 'Validation failed.',
        errors: { period_rating: ['The period rating must be between 1 and 10.'] },
      }),
    })
  })

  const invalidResponse = page.waitForResponse((response) => isPeriodicSave(response, 'weekly'))
  await page.getByRole('button', { name: 'Save periodic review' }).click()
  expect((await invalidResponse).status()).toBe(422)
  await expect(page.getByText('The period rating must be between 1 and 10.')).toBeVisible()
  await expect(page.getByLabel('Notes')).toHaveValue('Canonical weekly reflection.')

  const createResponse = page.waitForResponse((response) => isPeriodicSave(response, 'weekly'))
  await page.getByRole('button', { name: 'Save periodic review' }).click()
  const created = await (await createResponse).json() as { data: { id: number } }
  await expect(page.getByRole('status')).toContainText('Periodic review saved.')

  await page.goto('/review/weekly/2026-08-10')
  await expect(page.getByLabel('Period rating')).toHaveValue('8')
  await expect(page.getByLabel('Notes')).toHaveValue('Canonical weekly reflection.')
  const aliasResponse = page.waitForResponse((response) => (
    response.request().method() === 'GET'
      && new URL(response.url()).pathname === '/api/periodic-reviews/weekly/2026-08-16'
  ))
  await page.goto('/review/weekly/2026-08-16')
  const aliasWorkspace = await aliasResponse
  expect(aliasWorkspace.ok()).toBeTruthy()
  expect((await aliasWorkspace.json()).data.review.id).toBe(created.data.id)

  await page.getByLabel('Notes').fill('Unsaved weekly draft.')
  page.once('dialog', (dialog) => dialog.dismiss())
  await page.getByRole('link', { name: 'Monthly' }).click()
  await expect(page).toHaveURL('/review/weekly/2026-08-16')
  await expect(page.getByLabel('Notes')).toHaveValue('Unsaved weekly draft.')

  let failWithService = true
  await page.route('**/api/periodic-reviews/weekly/*', async (route) => {
    if (route.request().method() !== 'PUT' || !failWithService) {
      await route.continue()
      return
    }
    failWithService = false
    await route.fulfill({ status: 503, contentType: 'application/json', body: JSON.stringify({ message: 'Unavailable.' }) })
  })
  const failedResponse = page.waitForResponse((response) => isPeriodicSave(response, 'weekly'))
  await page.getByRole('button', { name: 'Save periodic review' }).click()
  expect((await failedResponse).status()).toBe(503)
  await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible()
  await expect(page.getByLabel('Notes')).toHaveValue('Unsaved weekly draft.')

  const retryResponse = page.waitForResponse((response) => isPeriodicSave(response, 'weekly'))
  await page.getByRole('button', { name: 'Retry' }).click()
  expect((await retryResponse).status()).toBe(200)
  await expect(page.getByRole('status')).toContainText('Periodic review saved.')
  await expectNoHorizontalOverflow(page)
})

test('monthly reflection canonicalizes a leap-month anchor and restores after reload', async ({ page }, testInfo) => {
  await useRequiredViewport(page, testInfo)
  await registerViaUi(page, uniqueCredentials(testInfo, 'MonthlyReview'), {
    redirectTo: '/review/monthly/2028-02-29',
  })

  await expect(page.getByRole('heading', { name: 'Monthly review' }).first()).toBeVisible()
  await expect(page.getByText('1 Feb 2028 – 29 Feb 2028')).toBeVisible()
  await expect(page.getByText('No reflection is saved for this period yet.')).toBeVisible()

  await page.getByLabel('Period rating').fill('9')
  await page.getByLabel('What worked well').fill('The month stayed aligned with the plan.')
  await page.getByLabel('Focus for the next period').fill('Protect recovery time.')
  await page.getByLabel('Notes').fill('Leap-month reflection.')
  const saveResponse = page.waitForResponse((response) => isPeriodicSave(response, 'monthly'))
  await page.getByRole('button', { name: 'Save periodic review' }).click()
  expect((await saveResponse).status()).toBe(200)

  await page.reload()
  await expect(page.getByLabel('Period rating')).toHaveValue('9')
  await expect(page.getByLabel('Notes')).toHaveValue('Leap-month reflection.')
  await expect(page.getByRole('link', { name: 'Plan the next period' })).toHaveAttribute('href', '/planner?date=2028-02-29')
  await expect(page.getByRole('link', { name: 'Review goals' })).toHaveAttribute('href', '/goals')
  await expectNoHorizontalOverflow(page)
})
