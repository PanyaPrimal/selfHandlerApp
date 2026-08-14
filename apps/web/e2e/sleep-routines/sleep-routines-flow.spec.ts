import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  chooseOption,
  expectNoHorizontalOverflow,
  gotoDestination,
  pickDate,
  setTime,
} from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const night = new Date().toISOString().slice(0, 10)
const wakeDate = new Date(Date.now() + (24 * 60 * 60 * 1000)).toISOString().slice(0, 10)

async function createSleepPlan(page: Page, name = 'Regular night'): Promise<void> {
  const form = page.getByRole('form', { name: 'Create sleep plan' })
  await form.getByLabel('Plan name').fill(name)
  await setTime(form, 'Planned bedtime', '23:00')
  await setTime(form, 'Planned wake time', '07:00')
  await form.getByRole('button', { name: 'Create sleep plan' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Sleep plan created.' })).toBeVisible()
}

async function createRoutineTemplate(page: Page, name: string, period: 'Morning' | 'Evening'): Promise<void> {
  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill(name)
  await chooseOption(form, 'Day period', period)
  await form.getByRole('button', { name: 'Create routine' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()

  await page.getByRole('button', { name: `Edit activities for ${name}` }).click()
  const activities = page.getByRole('form', { name: `Activities for ${name}` })
  await activities.getByRole('button', { name: 'Add activity' }).click()
  await activities.getByLabel('Activity 1 name').fill('Drink water')
  await activities.getByRole('button', { name: 'Add activity' }).click()
  await activities.getByLabel('Activity 2 name').fill('Read')
  await activities.getByLabel('Activity 2 progress total').fill('20')
  await activities.getByRole('button', { name: 'Save activities' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Activities saved.' })).toBeVisible()
}

test('sleep plan, cross-midnight fact, correction, clear and lifecycle survive reload', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'SleepLoop'), { redirectTo: '/routines' })
  const issues = collectRuntimeIssues(page)
  await expect(page.getByRole('heading', { name: 'Routines & sleep' })).toBeVisible()

  await createSleepPlan(page)
  const card = page.getByRole('listitem', { name: 'Regular night' })
  const record = card.getByRole('form', { name: 'Record sleep for Regular night' })
  await pickDate(page, 'Bed date', night)
  await setTime(record, 'Bed time', '23:15')
  await pickDate(page, 'Wake date', wakeDate)
  await setTime(record, 'Wake time', '07:15')
  await record.getByLabel('Quality').fill('8')
  await record.getByLabel('Note').fill('Restful')
  await record.getByRole('button', { name: 'Record sleep' }).click()
  await expect(card).toContainText('8 h')
  await expect(card).toContainText('8 / 10')

  await setTime(record, 'Wake time', '06:45')
  await record.getByLabel('Quality').fill('6')
  await record.getByRole('button', { name: 'Update sleep' }).click()
  await expect(card).toContainText('7 h 30 min')
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Regular night' })).toContainText('6 / 10')

  await page.getByRole('button', { name: 'Clear Regular night sleep record' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Sleep record cleared.' })).toBeVisible()
  await card.getByRole('button', { name: 'Pause Regular night' }).click()
  await expect(card).toBeHidden()
  await page.getByRole('radio', { name: 'Paused sleep plans' }).click()
  await expect(page.getByRole('listitem', { name: 'Regular night' })).toBeVisible()

  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('ordered activities resolve independently and derive one parent state', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'RoutineTemplate'), { redirectTo: '/routines' })
  await createRoutineTemplate(page, 'Morning reset', 'Morning')

  await gotoDestination(page, 'Today')
  await pickDate(page, 'Date', night)
  const routine = page.getByRole('listitem', { name: 'Morning reset' })
  await routine.getByRole('button', { name: 'Mark Drink water done' }).click()
  await expect(routine).toContainText('1 of 2 resolved')
  await expect(routine).toContainText('Pending')

  const read = routine.getByRole('form', { name: 'Record Read' })
  await read.getByLabel('Progress').fill('15')
  await read.getByRole('button', { name: 'Mark Read done' }).click()
  await expect(routine).toContainText('Done')
  await expect(routine).toContainText('15 / 20')

  await routine.getByRole('button', { name: 'Mark Drink water skipped' }).click()
  await expect(routine).toContainText('Skipped')
  await routine.getByRole('button', { name: 'Set Drink water to pending' }).click()
  await expect(routine).toContainText('Pending')
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Morning reset' })).toContainText('1 of 2 resolved')
  await expectNoHorizontalOverflow(page)
})

test('day choices agree across Today Planner and Review summaries with rollback', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'RoutineChoice'), { redirectTo: '/routines' })
  await createSleepPlan(page, 'Night plan')
  await createRoutineTemplate(page, 'Morning A', 'Morning')
  await createRoutineTemplate(page, 'Morning B', 'Morning')
  await createRoutineTemplate(page, 'Evening A', 'Evening')

  await gotoDestination(page, 'Today')
  await pickDate(page, 'Date', night)
  await chooseOption(page, 'Morning template', 'Morning B')
  await chooseOption(page, 'Evening template', 'No evening template')
  await expect(page.getByRole('listitem', { name: 'Morning B' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Morning A' })).toBeHidden()
  await expect(page.getByRole('listitem', { name: 'Evening A' })).toBeHidden()

  await page.route('**/api/routine-selections/*', async (route) => {
    if (route.request().method() === 'PUT') {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({
        message: 'Selection could not be saved.',
        errors: { morning_routine_id: ['Selection could not be saved.'] },
      }) })
      return
    }
    await route.continue()
  })
  await chooseOption(page, 'Morning template', 'Morning A')
  await expect(page.getByRole('alert')).toContainText('Selection could not be saved.')
  await expect(page.getByRole('listitem', { name: 'Morning B' })).toBeVisible()
  await page.unroute('**/api/routine-selections/*')

  await gotoDestination(page, 'Planner')
  await pickDate(page, 'Day', night)
  await expect(page.getByRole('listitem', { name: 'Morning B' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Night plan' })).toContainText('Wake 07:00')

  await page.goto(`/review/${night}`)
  await expect(page).toHaveURL(new RegExp(`/review/${night}$`))
  await expect(page.getByRole('region', { name: 'Sleep summary' })).toBeVisible()
  await expect(page.getByRole('region', { name: 'Routine activity summary' })).toContainText('Morning B')
  await expectNoHorizontalOverflow(page)
})

test('workspace localizes in RU and UK and stays keyboard/mobile accessible', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'SleepLocales'))
  const russianSaved = page.waitForResponse((response) => response.url().endsWith('/api/profile')
    && response.request().method() === 'PATCH' && response.ok())
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await russianSaved
  await page.goto('/routines')
  await expect(page.getByRole('heading', { name: 'Рутины и сон' })).toBeVisible()
  await expect(page.getByRole('form', { name: 'Создать план сна' })).toBeVisible()

  const ukrainianSaved = page.waitForResponse((response) => response.url().endsWith('/api/profile')
    && response.request().method() === 'PATCH' && response.ok())
  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await ukrainianSaved
  await expect(page.getByRole('heading', { name: 'Рутини та сон' })).toBeVisible()
  const name = page.getByRole('form', { name: 'Створити план сну' }).getByLabel('Назва плану')
  await name.focus()
  await expect(name).toBeFocused()

  await page.getByTestId('quick-theme-toggle').click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')
  await page.goto('/notifications')
  await expect(page.getByRole('switch', { name: 'Нагадування про сон' })).toBeVisible()
  await page.goto('/routines')

  if (testInfo.project.name === 'mobile') {
    const button = page.getByRole('button', { name: 'Створити план сну' })
    const box = await button.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)
    await expectNoHorizontalOverflow(page)
  }
})
