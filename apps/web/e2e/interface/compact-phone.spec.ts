import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

// Armor Mini 20T Pro has a 720x1600 panel. Android display/font settings
// determine its CSS viewport; cover compact widths and enlarged text separately.
for (const profile of [
  { name: 'compact-320', width: 320, height: 704, fontSize: 16 },
  { name: 'compact-360-large-text', width: 360, height: 744, fontSize: 20 },
]) {
  test(`${profile.name}: Russian workspaces stay inside the phone`, async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Compact phone acceptance')
    test.setTimeout(180_000)
    await page.setViewportSize({ width: profile.width, height: profile.height })
    await registerViaUi(page, uniqueCredentials(testInfo, profile.name))
    await page.getByRole('button', { name: 'RU', exact: true }).click()
    await expect(page.locator('html')).toHaveAttribute('lang', 'ru')

    const routes = ['/', '/routines', '/habits', '/planner', '/finance', '/nutrition',
      '/supplements', '/workouts', '/storage', '/goals', '/review', '/body',
      '/notifications', '/account', '/settings/appearance', '/settings/data',
      '/settings/integrations', '/settings/ai', '/analytics']
    for (const route of routes) {
      await test.step(route, async () => {
        await page.goto(route)
        await expect(page.locator('.content-shell')).toBeVisible({ timeout: 15_000 })
        await page.addStyleTag({ content: `:root { font-size: ${profile.fontSize}px; }` })
        await expect(page.locator('.content-shell h1')).toBeVisible()
        await page.waitForLoadState('networkidle')
        const layout = await page.evaluate(() => {
          const width = document.documentElement.clientWidth
          const offenders = [...document.querySelectorAll<HTMLElement>('body *')]
            .filter((element) => {
              const box = element.getBoundingClientRect()
              return box.width > 0 && (box.left < -1 || box.right > width + 1)
                && !element.closest('.finance-tabs, .supplement-tabs, .adherence-days')
            })
            .slice(0, 12).map((element) => ({ tag: element.tagName, class: element.className,
              text: element.textContent?.trim().slice(0, 80) }))
          return { width, scrollWidth: document.documentElement.scrollWidth, offenders }
        })
        const name = route.replaceAll('/', '-') || 'today'
        await testInfo.attach(`${name}-layout`, { body: JSON.stringify(layout), contentType: 'application/json' })
        await page.screenshot({ path: testInfo.outputPath(`${name}.png`), fullPage: true })
        expect.soft(layout.scrollWidth, `${route}: ${JSON.stringify(layout.offenders)}`)
          .toBeLessThanOrEqual(layout.width + 1)
      })
    }
  })
}

test('compact controls clear Android insets, navigation, and the keyboard', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Compact phone acceptance')
  test.setTimeout(120_000)
  await page.setViewportSize({ width: 360, height: 744 })
  await registerViaUi(page, uniqueCredentials(testInfo, 'CompactActions'))
  await page.addStyleTag({ content: ':root { --safe-area-inset-top: 28px; --safe-area-inset-bottom: 24px; }' })
  const toolbar = await page.locator('.global-preferences').boundingBox()
  const heading = await page.locator('.content-shell h1').boundingBox()
  expect.soft(heading!.y).toBeGreaterThanOrEqual(toolbar!.y + toolbar!.height)
  const summary = page.locator('.daily-summary')
  await expect(summary).toBeVisible()
  expect.soft((await summary.boundingBox())!.height).toBeLessThanOrEqual(300)
  const theme = await page.locator('.quick-theme-toggle').boundingBox()
  expect.soft(theme!.width).toBeGreaterThanOrEqual(44)
  expect.soft(theme!.height).toBeGreaterThanOrEqual(44)

  await page.goto('/account')
  await page.addStyleTag({ content: ':root { --safe-area-inset-top: 28px; --safe-area-inset-bottom: 24px; }' })
  const name = page.getByRole('textbox', { name: 'Display name', exact: true })
  await name.fill('Ulefone compact profile')
  const save = page.getByRole('button', { name: 'Save profile', exact: true })
  await save.scrollIntoViewIfNeeded()
  const navigation = await page.locator('.sidebar').boundingBox()
  const action = await save.boundingBox()
  expect.soft(744 - navigation!.y - navigation!.height).toBeGreaterThanOrEqual(35)
  expect(action!.y + action!.height).toBeLessThanOrEqual(navigation!.y - 8)
  await save.click()
  await expect(page.getByRole('status').filter({ hasText: 'Profile saved.' })).toBeVisible()
  await page.reload()
  await expect(name).toHaveValue('Ulefone compact profile')

  await page.setViewportSize({ width: 360, height: 340 })
  await page.evaluate(() => { document.documentElement.dataset.nativeKeyboard = 'open' })
  await expect(page.locator('.sidebar')).toBeHidden()
  const timezone = page.getByRole('combobox', { name: 'Timezone', exact: true })
  await timezone.fill('Kyiv')
  await expect(page.getByRole('listbox')).toBeVisible()
  const surface = page.locator('.ui-surface')
  await expect(async () => {
    const box = await surface.boundingBox()
    expect(box!.y).toBeGreaterThanOrEqual(12)
    expect(box!.y + box!.height).toBeLessThanOrEqual(328)
  }).toPass()
  await page.getByRole('option', { name: 'Europe/Kyiv', exact: true }).click()
  await expect(timezone).toHaveValue('Europe/Kyiv')
})
