import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { chooseOption, expectNoHorizontalOverflow, pickDate } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

const today = '2026-08-13'

test('catalogue course intake stock and shared daily surfaces form one loop', async ({ page }, testInfo) => {
  test.setTimeout(90_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'SupplementsFlow'), { redirectTo: '/supplements' })
  const issues = collectRuntimeIssues(page)
  await expect(page.getByRole('heading', { name: 'Supplements', exact: true })).toBeVisible()

  await page.getByRole('tab', { name: 'Catalogue' }).click()
  await page.getByRole('button', { name: 'Add reference' }).click()
  const reference = page.getByRole('form', { name: 'Supplement reference editor' })
  await reference.getByLabel('Name').fill('Magnesium capsules')
  await chooseOption(reference, 'Category', 'Vitamin')
  await chooseOption(reference, 'Form', 'Capsule')
  await reference.getByLabel('Usual dose').fill('1')
  await reference.getByLabel('Package quantity').fill('30')
  await reference.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Supplement reference created.' })).toBeVisible()

  const card = page.getByRole('article').filter({ hasText: 'Magnesium capsules' }).first()
  await card.getByRole('button', { name: 'Manage stock' }).click()
  const stock = page.getByRole('form', { name: 'Stock movement editor' })
  await stock.getByLabel('Quantity').fill('3')
  await stock.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Stock movement recorded.' })).toBeVisible()
  await expect(page.getByText('3.000', { exact: false }).first()).toBeVisible()

  await page.getByRole('tab', { name: 'Courses' }).click()
  await page.getByRole('button', { name: 'Add course' }).click()
  const course = page.getByRole('form', { name: 'Course editor' })
  await course.getByLabel('Course name').fill('Evening magnesium')
  await pickDate(page, 'Starts on', today)
  await pickDate(page, 'Ends on', '2026-08-20')
  await course.getByRole('button', { name: 'Save' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Course created.' })).toBeVisible()

  await page.getByRole('tab', { name: 'Day' }).click()
  const intake = page.getByRole('article').filter({ hasText: 'Evening magnesium' }).first()
  await intake.getByRole('button', { name: 'Mark taken' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Intake fact saved.' })).toBeVisible()
  await expect(page.getByText('100%', { exact: true }).first()).toBeVisible()

  await page.goto(`/?date=${today}`)
  await expect(page.getByRole('region', { name: 'Supplements summary' })).toContainText('100%')
  await page.goto(`/review/${today}`)
  await expect(page.getByRole('region', { name: 'Supplements summary' })).toContainText('100%')
  await page.getByRole('link', { name: 'Open Supplements' }).click()
  await expect(page).toHaveURL(/\/supplements\?date=2026-08-13/)
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('workspace localizes and keeps a rejected reference draft recoverable', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'SupplementsLocale'), { redirectTo: '/supplements' })
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Добавки', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Добавки', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'EN', exact: true }).click()

  await page.getByRole('tab', { name: 'Catalogue' }).click()
  await page.getByRole('button', { name: 'Add reference' }).click()
  const form = page.getByRole('form', { name: 'Supplement reference editor' })
  await form.getByLabel('Name').fill('Recoverable draft')
  await page.route('**/api/supplements', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Reference rejected.', errors: { name: ['Reference rejected.'] } }) })
      return
    }
    await route.continue()
  })
  await form.getByRole('button', { name: 'Save' }).click()
  await expect(form.getByLabel('Name')).toHaveValue('Recoverable draft')
  await expect(page.getByRole('alert').filter({ hasText: 'Reference rejected.' })).toBeVisible()
  if (testInfo.project.name === 'mobile') {
    const save = form.getByRole('button', { name: 'Save' })
    expect((await save.boundingBox())?.height ?? 0).toBeGreaterThanOrEqual(44)
  }
  await expectNoHorizontalOverflow(page)
})
