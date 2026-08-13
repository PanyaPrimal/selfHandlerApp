import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'

const locales = ['EN', 'RU', 'UK'] as const
const schemes = ['light', 'dark'] as const
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAFklEQVQImWPUimliYGBgYmBgYGBgAAAMMgEMApEIAQAAAABJRU5ErkJggg==', 'base64')
const today = new Date().toISOString().slice(0, 10)

async function setLocale(page: Page, locale: typeof locales[number]): Promise<void> {
  const button = page.getByRole('button', { name: locale, exact: true })
  if (await button.getAttribute('aria-pressed') !== 'true') {
    const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
    await button.click()
    await saved
  }
}

async function setScheme(page: Page, scheme: typeof schemes[number]): Promise<void> {
  if (await page.locator('html').getAttribute('data-theme') === scheme) return
  const saved = page.waitForResponse((response) => response.url().endsWith('/api/profile') && response.request().method() === 'PATCH')
  await page.getByTestId('quick-theme-toggle').click()
  await saved
}

test('captures Body and Nutrition private-photo surfaces in every locale scheme and viewport', async ({ page }, testInfo) => {
  test.setTimeout(240_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AttachmentVisual'))
  const headers = await xsrfHeader(page)
  const bodyResponse = await page.request.put('/api/body/measurements', {
    headers, data: { metric: 'body_mass', measured_on: today, value: 70000, note: 'Visual progress marker' },
  })
  const bodyId = (await bodyResponse.json()).data.id
  const foods = await (await page.request.get('/api/nutrition/foods', { headers })).json()
  const water = foods.data.find((food: { system_key: string | null }) => food.system_key === 'plain_water')
  const mealResponse = await page.request.post('/api/nutrition/meals', { headers, data: {
    consumed_on: today, name: 'Visual meal photo', category: 'dinner', consumed_at_local: '19:00', note: null,
    submission_key: crypto.randomUUID(), entries: [{ food_item_id: water.id, recipe_id: null, quantity: 200 }],
  } })
  const mealId = (await mealResponse.json()).data.id

  await page.goto('/body')
  let parent = page.locator(`[data-attachment-parent="body_measurement:${bodyId}"]`)
  await parent.locator('input[type=file]').setInputFiles({ name: 'progress.png', mimeType: 'image/png', buffer: png })
  await expect(parent.getByRole('img')).toBeVisible()
  await page.goto(`/nutrition?date=${today}`)
  parent = page.locator(`[data-attachment-parent="meal:${mealId}"]`)
  await parent.locator('input[type=file]').setInputFiles({ name: 'meal.png', mimeType: 'image/png', buffer: png })
  await expect(parent.getByRole('img')).toBeVisible()

  for (const locale of locales) {
    await setLocale(page, locale)
    for (const scheme of schemes) {
      await setScheme(page, scheme)
      for (const [surface, url, selector] of [
        ['body', '/body', `[data-attachment-parent="body_measurement:${bodyId}"]`],
        ['meal', `/nutrition?date=${today}`, `[data-attachment-parent="meal:${mealId}"]`],
      ] as const) {
        await page.goto(url)
        const attachmentSurface = page.locator(selector)
        await expect(attachmentSurface.getByRole('img')).toBeVisible()
        await attachmentSurface.scrollIntoViewIfNeeded()
        await expect.poll(() => attachmentSurface.getByRole('img').evaluate((image) => (image as HTMLImageElement).naturalWidth)).toBeGreaterThan(0)
        await page.waitForTimeout(400)
        await expectNoHorizontalOverflow(page)
        await page.screenshot({
          path: testInfo.outputPath(`${testInfo.project.name}-${locale.toLowerCase()}-${scheme}-${surface}.png`),
          fullPage: testInfo.project.name !== 'mobile', scale: 'css',
        })
      }
    }
  }
})
