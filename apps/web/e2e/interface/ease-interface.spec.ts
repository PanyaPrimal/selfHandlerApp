import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { expectNoHorizontalOverflow } from './support'

test('review puts reflection before optional evidence and saves a keyboard draft', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'EasyReview'), { redirectTo: '/review/2026-08-12' })
  const context = page.locator('.review-context')
  await expect(page.getByRole('slider', { name: 'Mood', exact: true })).toBeVisible()
  await expect(context).not.toHaveAttribute('open')
  const form = page.getByRole('form', { name: 'Daily review', exact: true })
  expect((await form.boundingBox())!.y).toBeLessThan((await context.boundingBox())!.y)
  await page.getByLabel('Went well').fill('A calm afternoon')
  await page.getByLabel('Improve tomorrow').fill('Take a walk')
  await page.getByRole('button', { name: 'Save review' }).focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('status')).toContainText('Review saved.')
  await page.reload()
  await expect(page.getByLabel('Went well')).toHaveValue('A calm afternoon')
  await context.locator('summary').press('Enter')
  await expect(page.getByRole('region', { name: 'Sleep summary' })).toBeVisible()
  if (info.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 740 })
  await expectNoHorizontalOverflow(page)
})

test('section search works from the keyboard and highlights a dated review', async ({ page }, info) => {
  const errors: string[] = []
  page.on('pageerror', error => errors.push(error.message))
  await registerViaUi(page, uniqueCredentials(info, 'EasyNavigation'), { redirectTo: '/review/2026-08-12' })
  await expect(page.getByRole('button', { name: 'Save review' })).toBeVisible()
  await page.keyboard.press('Control+k')
  const search = page.getByRole('searchbox', { name: 'Find a section' })
  await expect(search).toBeFocused()
  const review = info.project.name === 'mobile'
    ? page.getByRole('menuitem', { name: 'Review', exact: true })
    : page.locator('.nav-list--desktop').getByRole('link', { name: 'Review', exact: true })
  await expect(review).toHaveAttribute('aria-current', 'page')
  await search.fill('nonexistent-section')
  await expect(page.getByRole('status').filter({ hasText: 'No matching sections' })).toBeVisible()
  await search.fill('supple')
  const destination = info.project.name === 'mobile'
    ? page.getByRole('menuitem', { name: 'Supplements', exact: true })
    : page.locator('.nav-list--desktop').getByRole('link', { name: 'Supplements', exact: true })
  await destination.press('Enter')
  await expect(page).toHaveURL('/supplements')
  await page.keyboard.press('Control+k')
  await expect(search).toHaveValue('')
  await expect(search).toBeFocused()
  if (info.project.name === 'mobile') {
    await page.keyboard.press('Escape')
    await expect(page.getByRole('menu')).toBeHidden()
    await expect(page.getByRole('button', { name: 'More', exact: true })).toBeFocused()
  }
  await expectNoHorizontalOverflow(page)
  expect(errors).toEqual([])
})

test('closed task history expands, searches all records and respects deep links', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'EasyHistory'), { redirectTo: '/storage' })
  const headers = await xsrfHeader(page)
  let oldestId = 0
  for (let index = 0; index < 7; index++) {
    const response = await page.request.post('/api/storage/items', { headers, data: { title: `Finished task ${index}`, type: 'task', status: 'done' } })
    expect(response.status()).toBe(201)
    if (!index) oldestId = (await response.json()).data.id
  }
  await page.reload()
  const rows = page.locator('#closed-tasks > li')
  await expect(rows).toHaveCount(3)
  await page.getByRole('button', { name: 'Show all closed items (7)' }).click()
  await expect(rows).toHaveCount(7)
  await page.getByRole('button', { name: 'Show less' }).click()
  await expect(rows).toHaveCount(3)
  const search = page.locator('input[name="task-search"]')
  await search.fill('Finished task 0')
  await expect(rows).toHaveCount(1)
  await expect(rows.first()).toContainText('Finished task 0')
  await search.fill('')
  await expect(rows).toHaveCount(3)
  await page.goto(`/storage?item=${oldestId}`)
  const oldest = page.getByRole('listitem', { name: 'Finished task 0', exact: true })
  await expect(oldest).toBeVisible()
  await expect(oldest).toHaveClass(/is-deep-linked/)
  await oldest.getByRole('button', { name: 'Reopen Finished task 0' }).click()
  await expect(page.locator('#task-active')).toContainText('Finished task 0')
  await expectNoHorizontalOverflow(page)
})

test('one click intake preserves exact units and supports correction, skip and undo', async ({ page }, info) => {
  const today = new Date().toISOString().slice(0, 10)
  await registerViaUi(page, uniqueCredentials(info, 'EasyIntake'), { redirectTo: `/supplements?date=${today}` })
  const headers = await xsrfHeader(page)
  const reference = await page.request.post('/api/supplements', { headers, data: {
    name: 'Test reference', category: 'other', form: 'powder', stock_unit: 'gram', preferred_display_unit: 'mg',
    usual_dose_quantity: '25', package_quantity: null, restock_lead_days: 0, note: null,
  } })
  expect(reference.status()).toBe(201)
  const supplementId = (await reference.json()).data.id
  const course = await page.request.post('/api/supplement-courses', { headers, data: {
    supplement_id: supplementId, goal_id: null, name: 'Test daily plan', dose_quantity: '25', dose_display_unit: 'mg',
    starts_on: today, duration_days: 2, is_active: true,
    schedule: { frequency: 'daily', interval_count: 1, weekdays: [], cycle: null, slots: [{ slot: 'morning', time: '00:00', intake_context: 'unspecified' }] },
  } })
  expect(course.status(), await course.text()).toBe(201)
  await page.reload()
  const intake = page.locator('.supplement-occurrence').filter({ hasText: 'Test daily plan' })
  await expect(intake.getByLabel('Dose', { exact: true })).toBeHidden()
  await expect(intake).toContainText('25 mg')
  const save = page.waitForResponse(response => response.url().endsWith('/intake') && response.request().method() === 'PUT')
  await intake.getByRole('button', { name: 'Mark taken' }).click()
  const saved = await save
  expect(saved.status()).toBe(201)
  const occurrence = (await saved.json()).data
  expect(occurrence.intake).toMatchObject({ dose_quantity: '0.025000', dose_display_unit: 'mg', taken_time: '00:00' })
  await expect(intake.locator('.intake-state')).toHaveText('✓ Taken')
  await page.reload()
  await intake.locator('.intake-details > summary').click()
  await expect(intake.getByLabel('Dose', { exact: true })).toHaveValue('25')
  await intake.getByLabel('Dose', { exact: true }).fill('12.5')
  await intake.getByLabel('Note', { exact: true }).fill('Corrected record')
  await intake.getByRole('button', { name: 'Save correction' }).click()
  await expect(intake).toContainText('12.5 mg')
  await expect(intake.locator('.intake-details')).toHaveAttribute('open')
  // An existing fact can use a different display unit from its planned dose.
  const changedUnit = await page.request.put(`/api/supplement-occurrences/${occurrence.id}/intake`, { headers, data: {
    outcome: 'taken', dose_quantity: '0.01', dose_display_unit: 'g', taken_time: '00:00', note: 'Corrected record',
  } })
  expect(changedUnit.status()).toBe(200)
  await page.reload()
  await intake.locator('.intake-details > summary').click()
  await expect(intake.getByLabel('Dose', { exact: true })).toHaveValue('0.01')
  await expect(intake.getByLabel('Note', { exact: true })).toHaveValue('Corrected record')
  const correction = page.waitForResponse(response => response.url().endsWith('/intake') && response.request().method() === 'PUT')
  await intake.getByRole('button', { name: 'Save correction' }).click()
  expect((await (await correction).json()).data.intake).toMatchObject({ dose_quantity: '0.010000', dose_display_unit: 'g' })
  await expect(intake.locator('.intake-details')).toHaveAttribute('open')
  await intake.getByRole('button', { name: 'Skip', exact: true }).click()
  await expect(intake.locator('.intake-state')).toHaveText('− Skipped')
  await intake.getByRole('button', { name: 'Clear', exact: true }).click()
  await expect(intake.getByRole('button', { name: 'Mark taken' })).toBeVisible()
  await page.reload()
  await expect(intake.getByRole('button', { name: 'Mark taken' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})
