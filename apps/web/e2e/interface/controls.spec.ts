import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  chooseOption,
  chooseSegment,
  expectNoHorizontalOverflow,
  expectSurfaceWithinViewport,
  openSurface,
  searchAndChoose,
  selectTrigger,
  setSwitch,
  setTime,
} from './support'

test('choice, search, calendar and time controls render owned surfaces that fit the viewport', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Controls'))

  await page.goto('/account')
  await expect(page.getByRole('heading', { name: 'Your personal baseline' })).toBeVisible()

  // A listbox-backed choice control draws its own list.
  const units = selectTrigger(page, 'Units')
  await units.click()
  const listbox = page.getByRole('listbox')
  await expect(listbox).toBeVisible()
  await expect(listbox.getByRole('option', { name: 'Metric', exact: true })).toHaveAttribute('aria-selected', 'true')
  await expectSurfaceWithinViewport(page, openSurface(page))
  await expectNoHorizontalOverflow(page)
  await page.keyboard.press('Escape')
  await expect(listbox).toBeHidden()

  // The searchable control filters and states an empty result explicitly.
  const timezone = page.getByRole('combobox', { name: 'Timezone', exact: true })
  await timezone.click()
  await timezone.fill('kyi')
  await expect(page.getByRole('option', { name: 'Europe/Kyiv', exact: true })).toBeVisible()
  await timezone.fill('definitely-not-a-timezone')
  await expect(page.getByText('Nothing matches that search.')).toBeVisible()
  await page.keyboard.press('Escape')

  // Escape restored the committed value rather than leaving the filter behind.
  await expect(timezone).not.toHaveValue('definitely-not-a-timezone')

  await chooseOption(page, 'Units', 'Imperial')
  await expect(selectTrigger(page, 'Units')).toHaveText('Imperial')

  // The calendar is an application dialog with grid semantics.
  await page.getByRole('button', { name: /^Date of birth\b/ }).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await expect(dialog.getByRole('grid')).toBeVisible()
  await expect(dialog.getByRole('columnheader')).toHaveCount(7)
  await expectSurfaceWithinViewport(page, dialog)
  await expectNoHorizontalOverflow(page)
  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()

  await page.goto('/routines')
  await expect(page.getByRole('heading', { name: 'Routines & sleep' })).toBeVisible()

  const form = page.getByRole('form', { name: 'Create routine' })
  await chooseSegment(form, 'Schedule', 'By weekdays')
  await expect(form.getByRole('group', { name: 'Weekdays', exact: true })).toBeVisible()

  await setSwitch(form, 'Active in planning', false)
  await expect(form.getByRole('switch', { name: 'Active in planning', exact: true })).toHaveAttribute('aria-checked', 'false')
  await setSwitch(form, 'Active in planning', true)

  // The time control offers an owned list and still accepts typing.
  const time = form.getByRole('combobox', { name: 'Preferred time', exact: true })
  await form.getByRole('button', { name: 'Choose a time for Preferred time' }).click()
  const timeList = page.getByRole('listbox')
  await expect(timeList).toBeVisible()
  // The shell is what must fit; the list inside it scrolls.
  await expectSurfaceWithinViewport(page, openSurface(page))
  await page.keyboard.press('Escape')

  await setTime(form, 'Preferred time', '7:05')
  await expect(time).toHaveValue('07:05')
  await expectNoHorizontalOverflow(page)
})
