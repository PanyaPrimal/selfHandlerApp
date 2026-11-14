import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { gotoDestination, pickDate, revealForm } from './support'

async function holdNextResponse(page: Page, url: string) {
  let release!: () => void
  let announce!: () => void
  const held = new Promise<void>(resolve => { release = resolve })
  const started = new Promise<void>(resolve => { announce = resolve })
  await page.route(url, async route => {
    const response = await route.fetch()
    announce()
    await held
    await route.fulfill({ response })
  }, { times: 1 })
  return { started, release }
}

test('saving an intake keeps the other editor mounted, while a new date gets its own screen', async ({ page }, info) => {
  const today = new Date().toISOString().slice(0, 10)
  await registerViaUi(page, uniqueCredentials(info, 'ContinuousIntakes'), { redirectTo: `/supplements?date=${today}` })
  const headers = await xsrfHeader(page)
  const reference = await page.request.post('/api/supplements', { headers, data: {
    name: 'Continuity reference', category: 'other', form: 'powder', stock_unit: 'gram', preferred_display_unit: 'mg',
    usual_dose_quantity: '25', package_quantity: null, restock_lead_days: 0, note: null,
  } })
  expect(reference.status()).toBe(201)
  const supplementId = (await reference.json()).data.id
  const course = await page.request.post('/api/supplement-courses', { headers, data: {
    supplement_id: supplementId, goal_id: null, name: 'Continuity plan', dose_quantity: '25', dose_display_unit: 'mg',
    starts_on: today, duration_days: 2, is_active: true,
    schedule: { frequency: 'daily', interval_count: 1, weekdays: [], cycle: null, slots: [
      { slot: 'morning', time: '00:00', intake_context: 'unspecified' },
      { slot: 'evening', time: '00:01', intake_context: 'unspecified' },
    ] },
  } })
  expect(course.status(), await course.text()).toBe(201)
  await page.reload()
  const rows = page.locator('.supplement-occurrence')
  await expect(rows).toHaveCount(2)
  const first = rows.first()
  const neighbor = rows.last()
  await first.locator('summary').click()
  await neighbor.locator('summary').click()
  const note = neighbor.getByLabel('Note', { exact: true })
  await note.fill('Keep this unsaved note')
  const original = await note.elementHandle()
  const held = await holdNextResponse(page, `**/api/supplements/days/${today}`)
  try {
    await first.getByRole('button', { name: 'Save', exact: true }).click()
    await held.started
    expect(await original!.evaluate(element => element.isConnected)).toBe(true)
    await expect(first.locator('details')).toHaveAttribute('open')
    await note.focus()
    await page.keyboard.type(' while loading')
    held.release()
    await expect(first.locator('.intake-state')).toHaveText('✓ Taken')
    await expect(note).toHaveValue('Keep this unsaved note while loading')
    await expect(note).toBeFocused()
    expect(await original!.evaluate(element => element.isConnected)).toBe(true)
  } finally { held.release() }

  const tomorrow = new Date(`${today}T12:00:00Z`)
  tomorrow.setUTCDate(tomorrow.getUTCDate() + 1)
  const nextDate = tomorrow.toISOString().slice(0, 10)
  const changingDate = await holdNextResponse(page, `**/api/supplements/days/${nextDate}`)
  try {
    await pickDate(page, 'Selected date', nextDate)
    await changingDate.started
    await expect(rows).toHaveCount(0)
    changingDate.release()
    await expect(rows).toHaveCount(2)
    await neighbor.locator('summary').click()
    await expect(note).toHaveValue('')
  } finally { changingDate.release() }
})

test('logging water preserves an open meal draft during and after refresh', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'ContinuousNutrition'), { redirectTo: '/nutrition' })
  await revealForm(page, 'Log meal')
  const meal = page.getByRole('form', { name: 'Log meal', exact: true })
  const name = meal.getByLabel('Meal name')
  await name.fill('Unfinished breakfast')
  const optional = meal.locator('details')
  await optional.locator('summary').click()
  await meal.getByLabel('Note', { exact: true }).fill('Keep the details open')
  const original = await name.elementHandle()
  const held = await holdNextResponse(page, '**/api/nutrition/days/*')
  try {
    await page.getByRole('button', { name: 'Log water', exact: true }).click()
    await held.started
    expect(await original!.evaluate(element => element.isConnected)).toBe(true)
    await expect(optional).toHaveAttribute('open')
    await name.focus()
    await page.keyboard.type(' draft')
    held.release()
    await expect(page.getByRole('region', { name: 'Nutrition progress' })).toContainText('250 ml')
    await expect(name).toHaveValue('Unfinished breakfast draft')
    await expect(name).toBeFocused()
    await expect(optional).toHaveAttribute('open')
    await expect(meal.getByLabel('Note', { exact: true })).toHaveValue('Keep the details open')
    expect(await original!.evaluate(element => element.isConnected)).toBe(true)
  } finally { held.release() }
})

test('a delayed analytics bootstrap cannot return to a screen the user has left', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'ContinuousNavigation'))
  const held = await holdNextResponse(page, '**/api/analytics/catalog')
  try {
    await gotoDestination(page, 'Analytics')
    await held.started
    await gotoDestination(page, 'Tasks')
    await expect(page).toHaveURL('/storage')
    const response = page.waitForResponse('**/api/analytics/catalog')
    held.release()
    await (await response).finished()
    // Observe the late response's navigation side effects after leaving.
    const returned = await page.waitForURL(/\/analytics(?:\?|$)/, { timeout: 1000 }).then(() => true, () => false)
    expect(returned).toBe(false)
    await expect(page).toHaveURL('/storage')
  } finally { held.release() }
})
