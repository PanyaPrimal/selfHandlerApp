import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow, expectSurfaceWithinViewport } from './support'

const desktopDestinations = ['Today', 'Routines', 'Goals', 'Review', 'Settings', 'Account', 'Changelog']
const mobilePrimary = ['Today', 'Routines', 'Goals']
const mobileMore = ['Review', 'Settings', 'Account', 'Changelog']

test('the desktop sidebar lists every destination directly', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop', 'Desktop sidebar layout')

  await registerViaUi(page, uniqueCredentials(testInfo, 'NavDesktop'))

  const sidebar = page.locator('.nav-list--desktop')
  await expect(sidebar).toBeVisible()

  for (const label of desktopDestinations) {
    await expect(sidebar.getByRole('link', { name: label, exact: true })).toBeVisible()
  }

  await expect(page.getByRole('button', { name: /More/ })).toBeHidden()
  await expectNoHorizontalOverflow(page)
})

test('at 390px the primary tabs stay, and the rest live behind More', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Compact navigation layout')

  await registerViaUi(page, uniqueCredentials(testInfo, 'NavMobile'))

  const bar = page.locator('.nav-list--compact')
  await expect(bar).toBeVisible()

  for (const label of mobilePrimary) {
    const tab = bar.getByRole('link', { name: label, exact: true })
    await expect(tab).toBeVisible()

    const box = await tab.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(40)
  }

  for (const label of mobileMore) {
    await expect(bar.getByRole('link', { name: label, exact: true })).toHaveCount(0)
  }

  const more = bar.getByRole('button', { name: /More/ })
  await expect(more).toBeVisible()
  await more.click()

  const menu = page.getByRole('menu', { name: 'More destinations' })
  await expect(menu).toBeVisible()
  await expectSurfaceWithinViewport(page, menu)
  await expectNoHorizontalOverflow(page)

  // Escape closes and returns focus to the trigger.
  await page.keyboard.press('Escape')
  await expect(menu).toBeHidden()
  await expect(more).toBeFocused()

  await more.click()
  await menu.getByRole('menuitem', { name: 'Changelog' }).click()

  await expect(page).toHaveURL('/changelog')
  await expect(menu).toBeHidden()

  // The More entry indicates that the active destination lives inside it.
  await expect(more).toHaveAttribute('aria-current', 'page')
  await expectNoHorizontalOverflow(page)
})

test('every destination is reachable at 390px', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Compact navigation layout')

  await registerViaUi(page, uniqueCredentials(testInfo, 'NavReach'))

  const bar = page.locator('.nav-list--compact')

  for (const [label, url] of [['Routines', '/routines'], ['Goals', '/goals']] as const) {
    await bar.getByRole('link', { name: label, exact: true }).click()
    await expect(page).toHaveURL(new RegExp(`^.*${url}`))
  }

  for (const [label, url] of [
    ['Review', '/review'],
    ['Settings', '/settings/appearance'],
    ['Account', '/account'],
    ['Changelog', '/changelog'],
  ] as const) {
    await bar.getByRole('button', { name: /More/ }).click()
    await page.getByRole('menu').getByRole('menuitem', { name: label, exact: true }).click()
    await expect(page).toHaveURL(url)
  }

  await expectNoHorizontalOverflow(page)
})
