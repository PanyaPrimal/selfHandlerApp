import { expect, test, type Page } from '@playwright/test'
import { logoutViaUi, registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'

const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPUimliYGBgYmBgYGBgAAAMMgEMApEIAQAAAABJRU5ErkJggg==', 'base64')
const today = new Date().toISOString().slice(0, 10)

async function createBodyMeasurement(page: Page): Promise<number> {
  const headers = await xsrfHeader(page)
  const response = await page.request.put('/api/body/measurements', {
    headers,
    data: { metric: 'body_mass', measured_on: today, value: 70000, note: 'Photo marker' },
  })
  expect(response.ok()).toBeTruthy()
  return (await response.json()).data.id
}

async function createMeal(page: Page): Promise<number> {
  const headers = await xsrfHeader(page)
  const foods = await (await page.request.get('/api/nutrition/foods', { headers })).json()
  const water = foods.data.find((food: { system_key: string | null }) => food.system_key === 'plain_water')
  const response = await page.request.post('/api/nutrition/meals', {
    headers,
    data: {
      consumed_on: today, name: 'Private photo meal', category: 'lunch', consumed_at_local: '12:30', note: null,
      submission_key: crypto.randomUUID(),
      entries: [{ food_item_id: water.id, recipe_id: null, quantity: 250 }],
    },
  })
  expect(response.ok()).toBeTruthy()
  return (await response.json()).data.id
}

async function uploadThrough(parent: ReturnType<Page['locator']>): Promise<void> {
  await parent.locator('input[type=file]').setInputFiles({ name: 'progress.png', mimeType: 'image/png', buffer: png })
  await expect(parent.getByRole('img')).toBeVisible()
  await expect(parent.getByRole('status')).toContainText(/uploaded|added/i)
  const src = await parent.getByRole('img').getAttribute('src')
  expect(src).toMatch(/^blob:/)
  expect(src).not.toContain('storage')
}

async function nutritionSummary(page: Page): Promise<unknown> {
  return page.evaluate(async (date) => {
    const response = await fetch(`/api/nutrition/days/${date}`, {
      headers: { Accept: 'application/json' }, credentials: 'same-origin',
    })
    if (!response.ok) throw new Error(`Nutrition summary failed with ${response.status}`)
    return (await response.json()).data.summary
  }, today)
}

test('body and meal photos upload privately, preview, retry safely, and delete', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Attachments'))
  const bodyId = await createBodyMeasurement(page)
  const mealId = await createMeal(page)

  await page.goto('/body')
  const body = page.locator(`[data-attachment-parent="body_measurement:${bodyId}"]`)
  await expect(body).toBeVisible()
  await uploadThrough(body)
  await body.locator('input[type=file]').setInputFiles({ name: 'progress.png', mimeType: 'image/png', buffer: png })
  await expect(body.getByRole('img')).toHaveCount(2)
  await body.getByRole('button', { name: /delete photo/i }).first().click()
  await page.getByRole('button', { name: /confirm delete/i }).click()
  await expect(body.getByRole('img')).toHaveCount(1)

  await page.goto(`/nutrition?date=${today}`)
  const meal = page.locator(`[data-attachment-parent="meal:${mealId}"]`)
  await expect(meal).toBeVisible()
  const summaryBefore = await nutritionSummary(page)
  await uploadThrough(meal)
  const summaryAfter = await nutritionSummary(page)
  expect(summaryAfter).toEqual(summaryBefore)
  await meal.getByRole('button', { name: /delete photo/i }).click()
  await page.getByRole('button', { name: /confirm delete/i }).click()
  await expect(meal.getByRole('img')).toHaveCount(0)
  await expectNoHorizontalOverflow(page)
})

test('unsupported upload preserves the parent and exposes recoverable localized feedback', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'AttachmentError'))
  const bodyId = await createBodyMeasurement(page)
  await page.goto('/body')
  const body = page.locator(`[data-attachment-parent="body_measurement:${bodyId}"]`)

  await body.locator('input[type=file]').setInputFiles({ name: 'not-photo.svg', mimeType: 'image/svg+xml', buffer: Buffer.from('<svg/>') })
  await expect(body.getByRole('alert')).toBeVisible()
  await expect(body.locator('..')).toContainText('70')
  await expect(body.getByRole('button', { name: /retry/i })).toBeVisible()
  await expect(body.locator('input[type=file]')).toBeEnabled()
})

test('foreign and missing parent uploads are indistinguishable and create no visible photo', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'AttachmentOwner'))
  const foreignBodyId = await createBodyMeasurement(page)
  await logoutViaUi(page)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AttachmentAttacker'))
  const headers = await xsrfHeader(page)
  delete headers['Content-Type']

  const upload = async (id: number) => page.request.post(
    `/api/attachments?attachable_type=body_measurement&attachable_id=${id}&upload_key=${crypto.randomUUID()}`,
    { headers, multipart: { file: { name: 'private.png', mimeType: 'image/png', buffer: png } } },
  )
  const foreign = await upload(foreignBodyId)
  const missing = await upload(999_999_999)

  expect(foreign.status()).toBe(404)
  expect(missing.status()).toBe(404)
  expect(await foreign.json()).toEqual(await missing.json())
  await page.goto('/body')
  await expect(page.getByRole('img')).toHaveCount(0)
})
