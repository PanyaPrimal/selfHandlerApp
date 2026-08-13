import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  chooseOption,
  expectNoHorizontalOverflow,
  gotoDestination,
  setTime,
} from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

async function openCreate(page: Page): Promise<ReturnType<Page['getByRole']>> {
  await page.getByRole('button', { name: 'New habit' }).click()
  const form = page.getByRole('form', { name: 'Create habit' })
  await expect(form).toBeVisible()
  return form
}

async function createYesNo(page: Page, name: string): Promise<void> {
  const form = await openCreate(page)
  await form.getByLabel('Name').fill(name)
  await form.getByRole('button', { name: 'Create habit' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Habit created.' })).toBeVisible()
}

test('ordinary habit and numeric facts create, correct, clear and survive reload', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Habits'))
  const issues = collectRuntimeIssues(page)
  await gotoDestination(page, 'Habits')
  await expect(page).toHaveURL('/habits')
  await expect(page.getByRole('heading', { name: 'Habits & anti-habits' })).toBeVisible()

  await createYesNo(page, 'Morning sunlight')
  const sunlight = page.getByRole('listitem', { name: 'Morning sunlight' })

  const preferences = page.getByTestId('global-preferences')
  const russianSave = page.waitForResponse((response) => (
    response.request().method() === 'PATCH' && new URL(response.url()).pathname === '/api/profile'
  ))
  await preferences.getByRole('button', { name: 'RU', exact: true }).click()
  expect((await russianSave).status()).toBe(200)
  await expect(page.getByRole('status').filter({ hasText: 'Привычка создана.' })).toBeVisible()

  const englishSave = page.waitForResponse((response) => (
    response.request().method() === 'PATCH' && new URL(response.url()).pathname === '/api/profile'
  ))
  await preferences.getByRole('button', { name: 'EN', exact: true }).click()
  expect((await englishSave).status()).toBe(200)
  await expect(page.getByRole('status').filter({ hasText: 'Habit created.' })).toBeVisible()

  await sunlight.getByRole('button', { name: 'Mark Morning sunlight as done' }).click()
  await expect(sunlight).toContainText('1-day streak')

  const form = await openCreate(page)
  await form.getByLabel('Name').fill('Read pages')
  await chooseOption(form, 'Tracking', 'Number')
  await form.getByLabel('Target').fill('20')
  await form.getByLabel('Unit').fill('pages')
  await chooseOption(form, 'Schedule', 'Selected weekdays')
  await form.getByRole('button', { name: 'Thu', exact: true }).click()
  await setTime(form, 'Time', '21:00')
  await form.getByLabel('Place').fill('Bedroom')
  await form.getByLabel('Two-minute starter').fill('Read one page')
  await form.getByRole('button', { name: 'Create habit' }).click()

  const reading = page.getByRole('listitem', { name: 'Read pages' })
  const record = reading.getByRole('form', { name: 'Record Read pages' })
  await record.getByLabel('Value').fill('25')
  await record.getByRole('button', { name: 'Record result' }).click()
  await expect(reading).toContainText('25 pages')
  await expect(reading).toContainText('Target met')

  await record.getByLabel('Value').fill('10')
  await record.getByRole('button', { name: 'Update result' }).click()
  await expect(reading).toContainText('Below target')
  await expect(reading.getByText('10 pages')).toBeVisible()

  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Read pages' })).toContainText('10 pages')
  await page.getByRole('listitem', { name: 'Read pages' })
    .getByRole('button', { name: 'Clear Read pages result' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Result cleared.' })).toBeVisible()

  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('abstinence and stepped limits keep opposite success semantics', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'AntiHabit'))
  await page.goto('/habits')

  let form = await openCreate(page)
  await form.getByLabel('Name').fill('No smoking')
  await chooseOption(form, 'Kind', 'Anti-habit')
  await chooseOption(form, 'Tracking', 'Abstinence')
  await form.getByRole('button', { name: 'Create habit' }).click()

  const abstinence = page.getByRole('listitem', { name: 'No smoking' })
  await abstinence.getByRole('button', { name: 'Mark No smoking as protected' }).click()
  await expect(abstinence).toContainText('Protected')
  await abstinence.getByRole('button', { name: 'Record relapse for No smoking' }).click()
  await expect(abstinence).toContainText('Relapse recorded')
  await expect(abstinence).toContainText('Current streak 0')

  form = await openCreate(page)
  await form.getByLabel('Name').fill('Energy drinks')
  await chooseOption(form, 'Kind', 'Anti-habit')
  await chooseOption(form, 'Tracking', 'Stepped limit')
  await form.getByLabel('Unit').fill('drinks')
  await form.getByLabel('Step 1 ceiling').fill('1')
  await chooseOption(form, 'Step 1 period', 'Per day')
  await form.getByRole('button', { name: 'Add step' }).click()
  await form.getByLabel('Step 2 ceiling').fill('5')
  await chooseOption(form, 'Step 2 period', 'Per week')
  await form.getByRole('button', { name: 'Create habit' }).click()

  const drinks = page.getByRole('listitem', { name: 'Energy drinks' })
  const record = drinks.getByRole('form', { name: 'Record Energy drinks' })
  await record.getByLabel('Value').fill('2')
  await record.getByRole('button', { name: 'Record result' }).click()
  await expect(drinks).toContainText(/remaining|exceeded/i)
  await expectNoHorizontalOverflow(page)
})

test('lifecycle retains history and failed save rolls the interface back', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'HabitLifecycle'))
  await page.goto('/habits')
  await createYesNo(page, 'Journal')
  const card = page.getByRole('listitem', { name: 'Journal' })
  await card.getByRole('button', { name: 'Mark Journal as done' }).click()

  await card.getByRole('button', { name: 'Pause Journal' }).click()
  await expect(card).toBeHidden()
  await page.getByRole('radio', { name: 'Paused' }).click()
  await expect(page.getByRole('listitem', { name: 'Journal' })).toContainText('1-day streak')

  await page.getByRole('button', { name: 'Resume Journal' }).click()
  await page.getByRole('radio', { name: 'Active' }).click()
  await expect(page.getByRole('listitem', { name: 'Journal' })).toBeVisible()

  await page.route('**/api/habits/*', async (route) => {
    if (route.request().method() === 'PATCH') {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({
        message: 'Could not save.', errors: { name: ['Could not save.'] },
      }) })
      return
    }
    await route.continue()
  })
  await page.getByRole('button', { name: 'Archive Journal' }).click()
  await expect(page.getByRole('alert')).toContainText('Could not save.')
  await expect(page.getByRole('listitem', { name: 'Journal' })).toBeVisible()
})

test('Habits localizes in RU and UK and remains keyboard/mobile accessible', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'HabitLocales'))
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await page.goto('/habits')
  await expect(page.getByRole('heading', { name: 'Привычки и антипривычки' })).toBeVisible()
  await expect(page.getByText('Пока нет активных привычек.')).toBeVisible()

  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Звички й антизвички' })).toBeVisible()

  const newHabit = page.getByRole('button', { name: 'Нова звичка' })
  await newHabit.focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('form', { name: 'Створити звичку' })).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(newHabit).toBeFocused()

  if (testInfo.project.name === 'mobile') {
    await expectNoHorizontalOverflow(page)
    const box = await newHabit.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)
  }
})
