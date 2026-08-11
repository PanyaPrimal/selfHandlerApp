import { expect, test, type Page, type Response, type TestInfo } from '@playwright/test'
import { dateTrigger, pickDate } from '../interface/support'
import { collectRuntimeIssues } from './support'
import { registerViaUi, uniqueCredentials } from '../support/auth'

const REVIEW_DATE = '2026-08-06'

interface ReviewValues {
  mood: number
  energy: number
  stress: number
  dayRating: number
  wentWell: string
  improveTomorrow: string
  notes: string
}

interface ReviewResponse {
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

async function setRating(page: Page, label: string, value: number): Promise<void> {
  const input = page.getByLabel(label)
  await input.focus()
  await input.press('Home')

  for (let current = 1; current < value; current += 1) {
    await input.press('ArrowRight')
  }

  await expect(input).toHaveValue(String(value))
}

async function fillReview(page: Page, values: ReviewValues): Promise<void> {
  await setRating(page, 'Mood', values.mood)
  await setRating(page, 'Energy', values.energy)
  await setRating(page, 'Stress', values.stress)
  await setRating(page, 'Day rating', values.dayRating)
  await page.getByLabel('Went well').fill(values.wentWell)
  await page.getByLabel('Improve tomorrow').fill(values.improveTomorrow)
  await page.getByLabel('Notes').fill(values.notes)
}

async function expectReviewValues(page: Page, values: ReviewValues): Promise<void> {
  await expect(page.getByLabel('Mood')).toHaveValue(String(values.mood))
  await expect(page.getByLabel('Energy')).toHaveValue(String(values.energy))
  await expect(page.getByLabel('Stress')).toHaveValue(String(values.stress))
  await expect(page.getByLabel('Day rating')).toHaveValue(String(values.dayRating))
  await expect(page.getByLabel('Went well')).toHaveValue(values.wentWell)
  await expect(page.getByLabel('Improve tomorrow')).toHaveValue(values.improveTomorrow)
  await expect(page.getByLabel('Notes')).toHaveValue(values.notes)
}

async function saveReview(page: Page): Promise<Response> {
  const responsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'PUT', `/api/daily-reviews/${REVIEW_DATE}`)
  ))

  await page.getByRole('button', { name: 'Save review' }).click()
  return responsePromise
}

test('review is created, restored, updated idempotently, and reflected on Today', async ({ page }, testInfo) => {
  test.slow()
  await useRequiredViewport(page, testInfo)

  const credentials = uniqueCredentials(testInfo, 'ReviewFlow')
  const originalValues: ReviewValues = {
    mood: 7,
    energy: 8,
    stress: 3,
    dayRating: 9,
    wentWell: 'I protected time for the important work.',
    improveTomorrow: 'Start the first task before checking messages.',
    notes: 'Created from the deterministic review journey.',
  }
  const updatedValues: ReviewValues = {
    mood: 8,
    energy: 6,
    stress: 4,
    dayRating: 8,
    wentWell: 'I finished the planned work and took a proper break.',
    improveTomorrow: 'Leave a smaller task for the evening.',
    notes: 'This update must keep the same daily review id.',
  }

  await registerViaUi(page, credentials, { redirectTo: `/review/${REVIEW_DATE}` })
  await expect(page.getByRole('button', { name: 'Save review' })).toBeVisible()
  const issues = collectRuntimeIssues(page)
  await expectNoHorizontalOverflow(page)

  await fillReview(page, originalValues)
  const createResponse = await saveReview(page)
  expect(createResponse.status()).toBe(200)
  const created = await createResponse.json() as ReviewResponse
  await expect(page.getByRole('status')).toContainText('Review saved.')

  await page.reload()
  await expect(page.getByRole('button', { name: 'Save review' })).toBeVisible()
  await expectReviewValues(page, originalValues)

  let nextFailure: 'validation' | 'service' | null = null
  await page.route(`**/api/daily-reviews/${REVIEW_DATE}`, async (route) => {
    if (route.request().method() !== 'PUT' || nextFailure === null) {
      await route.continue()
      return
    }

    const failure = nextFailure
    nextFailure = null

    if (failure === 'validation') {
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Please correct the highlighted fields.',
          errors: {
            mood: ['The mood value could not be saved.'],
          },
        }),
      })
      return
    }

    await route.fulfill({
      status: 503,
      contentType: 'application/json',
      body: JSON.stringify({ message: 'Review service temporarily unavailable.' }),
    })
  })

  await fillReview(page, updatedValues)
  nextFailure = 'validation'
  const validationResponse = await saveReview(page)
  expect(validationResponse.status()).toBe(422)
  await expect(page.getByRole('alert')).toContainText('Please correct the highlighted fields')
  await expect(page.getByText('The mood value could not be saved.')).toBeVisible()
  await expectReviewValues(page, updatedValues)

  const updateResponse = await saveReview(page)
  expect(updateResponse.status()).toBe(200)
  const updated = await updateResponse.json() as ReviewResponse
  expect(updated.data.id).toBe(created.data.id)
  await expect(page.getByRole('status')).toContainText('Review saved.')

  const serviceFailureValues = {
    ...updatedValues,
    notes: 'This unsaved text must survive a temporary service failure.',
  }
  await page.getByLabel('Notes').fill(serviceFailureValues.notes)
  nextFailure = 'service'
  const unavailableResponse = await saveReview(page)
  expect(unavailableResponse.status()).toBe(503)
  await expect(page.getByRole('alert')).toContainText('The review could not be saved. Check the service and try again.')
  await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible()
  await expectReviewValues(page, serviceFailureValues)

  const retryResponsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'PUT', `/api/daily-reviews/${REVIEW_DATE}`)
  ))
  await page.getByRole('button', { name: 'Retry' }).click()
  const retryResponse = await retryResponsePromise
  expect(retryResponse.status()).toBe(200)
  const retried = await retryResponse.json() as ReviewResponse
  expect(retried.data.id).toBe(created.data.id)
  await expect(page.getByRole('status')).toContainText('Review saved.')

  await page.reload()
  await expect(page.getByRole('button', { name: 'Save review' })).toBeVisible()
  await expectReviewValues(page, serviceFailureValues)
  await expectNoHorizontalOverflow(page)

  await page.getByRole('link', { name: 'Today', exact: true }).click()
  await expect(dateTrigger(page, 'Date')).toBeEnabled()
  const todayResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return response.request().method() === 'GET'
      && url.pathname === '/api/today'
      && url.searchParams.get('date') === REVIEW_DATE
  })
  await pickDate(page, 'Date', REVIEW_DATE)
  expect((await todayResponsePromise).status()).toBe(200)

  await expect(page.getByText('Review saved for this date.')).toBeVisible()
  const editLink = page.getByRole('link', { name: 'Edit', exact: true })
  await expect(editLink).toHaveAttribute('href', `/review/${REVIEW_DATE}`)
  await expectNoHorizontalOverflow(page)

  await editLink.click()
  await expect(page).toHaveURL(`/review/${REVIEW_DATE}`)
  await expect(page.getByRole('button', { name: 'Save review' })).toBeVisible()
  await expectReviewValues(page, serviceFailureValues)

  const expectedFailures = issues.filter((issue) => (
    issue.includes(`[response] 422 PUT `)
      || issue.includes(`[response] 503 PUT `)
  ) && issue.includes(`/api/daily-reviews/${REVIEW_DATE}`))
  const expectedConsoleFailures = issues.filter((issue) => (
    issue === '[console:error] Failed to load resource: the server responded with a status of 422 (Unprocessable Entity)'
      || issue === '[console:error] Failed to load resource: the server responded with a status of 503 (Service Unavailable)'
  ))

  expect(expectedFailures).toHaveLength(2)
  expect(expectedConsoleFailures).toHaveLength(2)
  expect(issues.filter((issue) => (
    !expectedFailures.includes(issue) && !expectedConsoleFailures.includes(issue)
  ))).toEqual([])
})
