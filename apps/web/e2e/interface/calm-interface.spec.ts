import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { chooseOption, expectNoHorizontalOverflow, revealRoutine } from './support'

test('daily checkboxes save habit and anti-habit facts and can undo them', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'CalmHabits'))
  const headers = await xsrfHeader(page)
  for (const habit of [
    { name: 'Read a page', kind: 'habit', mode: 'yes_no' },
    { name: 'No scrolling', kind: 'anti_habit', mode: 'abstinence' },
  ]) {
    expect((await page.request.post('/api/habits', { headers, data: { ...habit, schedule_type: 'daily' } })).status()).toBe(201)
  }
  await page.reload()
  await page.getByRole('button', { name: 'Mark Read a page as done', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Clear Read a page result', exact: true })).toHaveAttribute('aria-pressed', 'true')
  const protectedButton = page.getByRole('button', { name: 'Mark No scrolling as protected', exact: true })
  await protectedButton.focus()
  await page.keyboard.press('Space')
  await expect(page.getByRole('button', { name: 'Clear No scrolling result', exact: true })).toHaveAttribute('aria-pressed', 'true')
  await page.reload()
  const habits = (await (await page.request.get('/api/habits', { headers })).json()).data
  expect(habits.find((h: { name: string }) => h.name === 'No scrolling').selected_day.log.outcome).toBe('protected')
  await page.getByRole('button', { name: 'Clear No scrolling result', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Mark No scrolling as protected', exact: true })).toHaveAttribute('aria-pressed', 'false')
  await expectNoHorizontalOverflow(page)
})

test('routine checklist keeps notes optional and persists check, skip and undo', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'CalmRoutine'))
  const headers = await xsrfHeader(page)
  const routineResponse = await page.request.post('/api/routines', { headers, data: { name: 'Morning', day_period: 'morning', schedule_type: 'daily' } })
  expect(routineResponse.status()).toBe(201)
  const { data: routine } = await routineResponse.json()
  expect((await page.request.put(`/api/routines/${routine.id}/activities`, { headers, data: { activities: [{ name: 'Stretch', sort_order: 0 }, { name: 'Breathe', sort_order: 1, progress_total: 20 }] } })).ok()).toBeTruthy()
  await page.reload()
  const activity = page.getByRole('listitem', { name: 'Stretch', exact: true })
  await expect(activity.getByRole('textbox')).toBeHidden()
  const check = activity.getByRole('button', { name: 'Mark Stretch done', exact: true })
  await check.click()
  await expect(activity.getByRole('button', { name: 'Set Stretch to pending', exact: true }).first()).toHaveAttribute('aria-pressed', 'true')
  await page.reload()
  await activity.locator('summary').click()
  await activity.getByRole('textbox').fill('A short stretch')
  await activity.getByRole('button', { name: 'Mark Stretch skipped', exact: true }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Stretch' })).toBeVisible()
  const day = await (await page.request.get('/api/today', { headers })).json()
  const saved = day.routines.find((r: { id: number }) => r.id === routine.id).activities.find((a: { name: string }) => a.name === 'Stretch').selected_day_log
  expect(saved.status).toBe('skipped')
  expect(saved.note).toBe('A short stretch')
  if (!await activity.locator('details').evaluate(element => (element as HTMLDetailsElement).open)) await activity.locator('summary').click()
  const numeric = page.getByRole('form', { name: 'Record Breathe', exact: true })
  let release!: () => void
  let announce!: () => void
  const held = new Promise<void>(resolve => { release = resolve })
  const started = new Promise<void>(resolve => { announce = resolve })
  await page.route('**/api/today?date=*', async route => {
    const response = await route.fetch()
    announce()
    await held
    await route.fulfill({ response })
  }, { times: 1 })
  try {
    await activity.getByRole('button', { name: 'Set Stretch to pending', exact: true }).click()
    await started
    await numeric.getByRole('spinbutton', { name: 'Progress', exact: true }).fill('10')
    release()
    await expect(activity.getByRole('button', { name: 'Mark Stretch done', exact: true })).toHaveAttribute('aria-pressed', 'false')
    await expect(numeric.getByRole('spinbutton', { name: 'Progress', exact: true })).toHaveValue('10')
  } finally { release() }
  await numeric.getByRole('button', { name: 'Mark Breathe done', exact: true }).click()
  await expect(page.getByRole('listitem', { name: 'Breathe', exact: true })).toContainText('10 / 20')
  await activity.getByRole('button', { name: 'Mark Stretch done', exact: true }).focus()
  await page.keyboard.press('Space')
  const routineCard = page.getByRole('listitem', { name: 'Morning', exact: true })
  await expect(routineCard).toHaveAttribute('open')
  await expect(activity.getByRole('button', { name: 'Set Stretch to pending', exact: true }).first()).toBeFocused()
  await page.reload()
  await revealRoutine(page, 'Morning')
  await expect(page.getByRole('listitem', { name: 'Breathe', exact: true })).toContainText('10 / 20')
  await expectNoHorizontalOverflow(page)
})

test('a delayed day response cannot replace the more recently selected day', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'CalmDates'))
  await expect(page.locator('.day-strip__day')).toHaveCount(7)
  const firstLabel = await page.locator('.day-strip__day').nth(0).getAttribute('aria-label')
  const secondLabel = await page.locator('.day-strip__day').nth(1).getAttribute('aria-label')
  let release!: () => void
  let announce!: () => void
  const held = new Promise<void>(resolve => { release = resolve })
  const started = new Promise<void>(resolve => { announce = resolve })
  let first = true
  await page.route('**/api/today?date=*', async route => {
    const response = await route.fetch()
    if (first) {
      first = false
      announce()
      await held
    }
    await route.fulfill({ response })
  })
  try {
    await page.getByRole('button', { name: firstLabel!, exact: true }).click()
    await started
    await page.getByRole('button', { name: secondLabel!, exact: true }).click()
    await expect(page.locator('.today-checkins .today-habits')).toBeVisible()
    release()
    await page.waitForLoadState('networkidle')
    await expect(page.getByRole('button', { name: secondLabel!, exact: true })).toHaveAttribute('aria-pressed', 'true')
    await expect(page.getByRole('button', { name: firstLabel!, exact: true })).toHaveAttribute('aria-pressed', 'false')
  } finally { release() }
})

test('nutrition shortcuts reveal forms and a meal survives a reload', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'CalmNutrition'))
  await page.goto('/nutrition')
  const foodForm = page.getByRole('form', { name: 'Create food', exact: true })
  const mealForm = page.getByRole('form', { name: 'Log meal', exact: true })
  await expect(foodForm).toBeHidden()
  await expect(mealForm).toBeHidden()
  await page.locator('.workspace-shortcuts').getByRole('link', { name: 'Food catalogue', exact: true }).click()
  await foodForm.getByRole('textbox', { name: 'Food name', exact: true }).fill('Calm oats')
  await foodForm.getByRole('button', { name: 'Create food', exact: true }).click()
  await expect(page.getByRole('listitem', { name: 'Calm oats', exact: true })).toBeVisible()
  await page.locator('.workspace-shortcuts').getByRole('link', { name: 'Log meal', exact: true }).click()
  await chooseOption(mealForm, 'Food or drink 1', 'Calm oats')
  await mealForm.getByRole('spinbutton', { name: 'Entry 1 quantity', exact: true }).fill('100')
  await mealForm.getByRole('button', { name: 'Log meal', exact: true }).click()
  await expect(page.getByRole('list', { name: 'Meals', exact: true })).toContainText('Calm oats')
  await page.reload()
  await expect(mealForm).toBeHidden()
  await expect(page.getByRole('list', { name: 'Meals', exact: true })).toContainText('Calm oats')
  await expectNoHorizontalOverflow(page)
})
