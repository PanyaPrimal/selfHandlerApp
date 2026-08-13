import { expect, test, type Locator, type Page, type Response, type TestInfo } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from './support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { chooseSegment, dateTrigger, gotoDestination, pickDate } from '../interface/support'

const TODAY_DATE = '2026-08-06'

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

function goalRow(page: Page, goalName: string): Locator {
  return page.getByRole('listitem', { name: goalName, exact: true })
}

async function createDailyRoutine(page: Page, name: string): Promise<number> {
  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill(name)
  await chooseSegment(form, 'Schedule', 'Daily')

  const responsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'POST', '/api/routines')
  ))
  await form.getByRole('button', { name: 'Create routine' }).click()
  const response = await responsePromise
  expect(response.status()).toBe(201)
  const payload = await response.json() as ItemResponse

  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name, exact: true })).toBeVisible()
  return payload.data.id
}

async function createGoal(page: Page, name: string): Promise<number> {
  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill(name)
  await form.getByLabel('Description').fill('Connect daily action to a meaningful outcome.')
  await pickDate(page, 'Target date', '2026-12-31')

  const responsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'POST', '/api/goals')
  ))
  await form.getByRole('button', { name: 'Create goal' }).click()
  const response = await responsePromise
  expect(response.status()).toBe(201)
  const payload = await response.json() as ItemResponse

  await expect(page.getByRole('status').filter({ hasText: 'Goal created.' })).toBeVisible()
  await expect(goalRow(page, name)).toBeVisible()
  return payload.data.id
}

async function setRoutineLink(
  page: Page,
  goalName: string,
  routineName: string,
  goalId: number,
  routineId: number,
  linked: boolean,
): Promise<void> {
  const form = goalRow(page, goalName).getByRole('form', { name: `Routine links for ${goalName}` })
  await form.getByRole('checkbox', { name: routineName, exact: true }).setChecked(linked)

  const method = linked ? 'POST' : 'DELETE'
  const responsePromise = page.waitForResponse((response) => (
    isApiResponse(response, method, `/api/goals/${goalId}/routines/${routineId}`)
  ))
  await form.getByRole('button', { name: `Save routine links for ${goalName}` }).click()
  const response = await responsePromise
  expect(response.status()).toBe(linked ? 200 : 204)

  await expect(page.getByRole('status').filter({ hasText: 'Routine links saved.' })).toBeVisible()
}

async function updateGoalLifecycle(
  page: Page,
  goalName: string,
  goalId: number,
  action: 'Complete' | 'Reactivate' | 'Archive' | 'Restore',
): Promise<void> {
  const responsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'PATCH', `/api/goals/${goalId}`)
  ))
  await goalRow(page, goalName).getByRole('button', { name: `${action} ${goalName}` }).click()
  expect((await responsePromise).status()).toBe(200)

  await expect(page.getByRole('status').filter({ hasText: `Goal ${action.toLowerCase()}d.` })).toBeVisible()
}

async function openGoals(page: Page): Promise<void> {
  await gotoDestination(page, 'Goals')
  await expect(page.getByRole('form', { name: 'Create goal' })).toBeVisible()
}

async function openToday(page: Page): Promise<void> {
  await page.getByRole('link', { name: 'Today', exact: true }).click()
  await expect(dateTrigger(page, 'Date')).toBeEnabled()

  const responsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url())
    return response.request().method() === 'GET'
      && url.pathname === '/api/today'
      && url.searchParams.get('date') === TODAY_DATE
  })
  await pickDate(page, 'Date', TODAY_DATE)
  expect((await responsePromise).status()).toBe(200)
}

async function expectGoalContext(
  page: Page,
  routineName: string,
  goalName: string,
  visible: boolean,
): Promise<void> {
  const routine = page.getByRole('listitem', { name: routineName, exact: true })
  await expect(routine).toBeVisible()

  if (visible) {
    await expect(routine).toContainText(goalName)
  } else {
    await expect(routine).not.toContainText(goalName)
  }
}

test('goal context follows links, lifecycle, archive, and restore', async ({ page }, testInfo) => {
  test.slow()
  await useRequiredViewport(page, testInfo)

  const credentials = uniqueCredentials(testInfo, 'GoalFlow')
  const routineName = 'Daily focus block'
  const goalName = 'Build consistent focus'

  await registerViaUi(page, credentials, { redirectTo: '/routines' })
  const issues = collectRuntimeIssues(page)
  const routineId = await createDailyRoutine(page, routineName)

  await openGoals(page)
  const goalId = await createGoal(page, goalName)

  await goalRow(page, goalName).getByRole('button', { name: `Edit ${goalName}` }).click()
  const editForm = page.getByRole('form', { name: 'Edit goal' })
  await editForm.getByLabel('Description').fill('Edited goal context stays connected to daily action.')
  const editResponsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'PATCH', `/api/goals/${goalId}`)
  ))
  await editForm.getByRole('button', { name: 'Save changes' }).click()
  expect((await editResponsePromise).status()).toBe(200)
  await expect(page.getByRole('status').filter({ hasText: 'Goal updated.' })).toBeVisible()
  await expect(goalRow(page, goalName)).toContainText('Edited goal context stays connected to daily action.')

  await setRoutineLink(page, goalName, routineName, goalId, routineId, true)

  await openToday(page)
  await expectGoalContext(page, routineName, goalName, true)

  await openGoals(page)
  await setRoutineLink(page, goalName, routineName, goalId, routineId, false)
  await expect(goalRow(page, goalName)).toBeVisible()

  await openToday(page)
  await expectGoalContext(page, routineName, goalName, false)

  await openGoals(page)
  await setRoutineLink(page, goalName, routineName, goalId, routineId, true)
  await updateGoalLifecycle(page, goalName, goalId, 'Complete')

  await openToday(page)
  await expectGoalContext(page, routineName, goalName, false)

  await openGoals(page)
  await updateGoalLifecycle(page, goalName, goalId, 'Reactivate')

  await openToday(page)
  await expectGoalContext(page, routineName, goalName, true)

  await openGoals(page)
  await updateGoalLifecycle(page, goalName, goalId, 'Archive')
  await expect(goalRow(page, goalName)).toHaveCount(0)

  await openToday(page)
  await expectGoalContext(page, routineName, goalName, false)

  await openGoals(page)
  await page.getByRole('button', { name: 'Archived goals' }).click()
  await expect(goalRow(page, goalName)).toBeVisible()
  await updateGoalLifecycle(page, goalName, goalId, 'Restore')
  await page.getByRole('button', { name: 'Current goals' }).click()
  await expect(goalRow(page, goalName)).toBeVisible()

  await openToday(page)
  await expectGoalContext(page, routineName, goalName, true)

  expectNoRuntimeIssues(issues)
})

test('goal loading retries and create validation preserves the draft', async ({ page }, testInfo) => {
  await useRequiredViewport(page, testInfo)

  const credentials = uniqueCredentials(testInfo, 'GoalFailures')
  await registerViaUi(page, credentials, { redirectTo: '/routines' })
  const issues = collectRuntimeIssues(page)

  let failNextLoad = true
  await page.route('**/api/goals*', async (route) => {
    const url = new URL(route.request().url())

    if (route.request().method() === 'GET' && failNextLoad && url.pathname === '/api/goals') {
      failNextLoad = false
      await route.fulfill({
        status: 503,
        contentType: 'application/json',
        body: JSON.stringify({ message: 'Goal service temporarily unavailable.' }),
      })
      return
    }

    await route.continue()
  })

  await gotoDestination(page, 'Goals')
  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Retry' })).toBeVisible()
  await page.getByRole('button', { name: 'Retry' }).click()
  await expect(page.getByText('No goals yet')).toBeVisible()

  let rejectNextCreate = true
  await page.route('**/api/goals', async (route) => {
    if (route.request().method() === 'POST' && rejectNextCreate) {
      rejectNextCreate = false
      await route.fulfill({
        status: 422,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'Please correct the highlighted fields.',
          errors: {
            name: ['The goal name could not be saved.'],
          },
        }),
      })
      return
    }

    await route.continue()
  })

  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill('Draft goal survives validation')
  await form.getByLabel('Description').fill('Keep this description after the rejected request.')
  await pickDate(page, 'Target date', '2026-12-31')

  const validationResponsePromise = page.waitForResponse((response) => (
    isApiResponse(response, 'POST', '/api/goals')
  ))
  await form.getByRole('button', { name: 'Create goal' }).click()
  expect((await validationResponsePromise).status()).toBe(422)

  await expect(page.getByRole('alert')).toBeVisible()
  await expect(page.getByText('The goal name could not be saved.')).toBeVisible()
  await expect(form.getByLabel('Name')).toHaveValue('Draft goal survives validation')
  await expect(form.getByLabel('Description')).toHaveValue('Keep this description after the rejected request.')
  await expect(dateTrigger(page, 'Target date')).toContainText('31 Dec 2026')

  const expectedResponses = issues.filter((issue) => (
    issue.includes('[response] 503 GET ') && issue.includes('/api/goals')
  ) || (
    issue.includes('[response] 422 POST ') && issue.includes('/api/goals')
  ))
  const expectedConsoleFailures = issues.filter((issue) => (
    issue === '[console:error] Failed to load resource: the server responded with a status of 422 (Unprocessable Entity)'
      || issue === '[console:error] Failed to load resource: the server responded with a status of 503 (Service Unavailable)'
  ))

  expect(expectedResponses).toHaveLength(2)
  expect(expectedConsoleFailures).toHaveLength(2)
  expect(issues.filter((issue) => (
    !expectedResponses.includes(issue) && !expectedConsoleFailures.includes(issue)
  ))).toEqual([])
})
