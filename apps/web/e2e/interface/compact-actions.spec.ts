import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { dateTrigger, expectNoHorizontalOverflow, expectSurfaceWithinViewport, pickDate } from './support'

test('320px with enlarged text: navigate, choose a date and persist a goal in dark mode', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Compact phone acceptance')
  test.setTimeout(90_000)
  await page.setViewportSize({ width: 320, height: 704 })
  await registerViaUi(page, uniqueCredentials(testInfo, 'CompactDark'))
  await page.addStyleTag({ content: ':root { font-size: 20px; }' })
  await page.getByRole('button', { name: 'Switch to dark mode', exact: true }).click()
  await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

  await page.locator('.nav-list--compact').getByRole('button', { name: /More/ }).click()
  const menu = page.getByRole('menu')
  await expectSurfaceWithinViewport(page, menu)
  await menu.getByRole('menuitem', { name: 'Goals', exact: true }).click()
  await expect(page).toHaveURL('/goals')
  const form = page.getByRole('form', { name: 'Create goal' })
  const title = 'Проверить привычки на маленьком экране Ulefone'
  await form.getByLabel('Name', { exact: true }).fill(title)
  await dateTrigger(page, 'Target date').click()
  const calendar = page.getByRole('dialog')
  await expectSurfaceWithinViewport(page, calendar)
  const dock = await page.locator('.sidebar').boundingBox()
  await expect(async () => {
    const box = await calendar.boundingBox()
    expect(box!.y + box!.height).toBeLessThanOrEqual(dock!.y - 8)
  }).toPass()
  await page.keyboard.press('Escape')
  await pickDate(page, 'Target date', '2026-09-30')
  const create = form.getByRole('button', { name: 'Create goal', exact: true })
  expect((await create.boundingBox())!.height).toBeGreaterThanOrEqual(44)
  await create.click()
  const goal = page.getByRole('listitem', { name: title })
  await expect(goal).toContainText('30 Sept 2026')
  await expectNoHorizontalOverflow(page)
  await goal.scrollIntoViewIfNeeded()
  await page.screenshot({ path: testInfo.outputPath('compact-dark-goal.png'), fullPage: true })
  await page.reload()
  await expect(goal).toContainText('30 Sept 2026')
})
