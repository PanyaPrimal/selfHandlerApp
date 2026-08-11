import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { selectTrigger } from './support'

test('every control pattern is operable from the keyboard alone', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Keyboard'))

  await page.goto('/routines')
  await expect(page.getByRole('heading', { name: 'Repeatable actions' })).toBeVisible()

  const form = page.getByRole('form', { name: 'Create routine' })

  // Listbox: open, move, commit, and confirm focus came back to the trigger.
  const kind = selectTrigger(form, 'Kind')
  await kind.focus()
  await page.keyboard.press('Enter')
  await expect(kind).toHaveAttribute('aria-expanded', 'true')

  const listbox = page.getByRole('listbox')
  await expect(listbox).toBeVisible()
  await page.keyboard.press('ArrowDown')
  await expect(kind).toHaveAttribute('aria-activedescendant', /option-1$/)
  await page.keyboard.press('End')
  await expect(kind).toHaveAttribute('aria-activedescendant', /option-2$/)
  await page.keyboard.press('Home')
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')
  await expect(listbox).toBeHidden()
  await expect(kind).toHaveText('Habit')
  await expect(kind).toBeFocused()

  // Escape closes without committing and restores focus.
  await page.keyboard.press('Enter')
  await expect(listbox).toBeVisible()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Escape')
  await expect(listbox).toBeHidden()
  await expect(kind).toHaveText('Habit')
  await expect(kind).toBeFocused()

  // Radio group: arrows move and commit the selection.
  const schedule = form.getByRole('radiogroup', { name: 'Schedule', exact: true })
  await schedule.getByRole('radio', { name: 'Daily', exact: true }).focus()
  await page.keyboard.press('ArrowRight')
  await expect(schedule.getByRole('radio', { name: 'By weekdays', exact: true })).toHaveAttribute('aria-checked', 'true')

  // Toggle group: Space toggles an individual member.
  const weekdays = form.getByRole('group', { name: 'Weekdays', exact: true })
  const monday = weekdays.getByRole('button', { name: 'Mon', exact: true })
  await monday.focus()
  await page.keyboard.press('Space')
  await expect(monday).toHaveAttribute('aria-pressed', 'true')

  // Calendar: arrows move by day, week and month; Enter commits.
  const startsOn = page.getByRole('button', { name: /^Starts on\b/ })
  await startsOn.focus()
  await page.keyboard.press('Enter')

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  const cursor = dialog.locator('[role="gridcell"][tabindex="0"]')
  const before = await cursor.getAttribute('id')
  await page.keyboard.press('ArrowRight')
  await expect(cursor).not.toHaveAttribute('id', before ?? '')
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('PageDown')
  await page.keyboard.press('PageUp')
  await page.keyboard.press('Enter')
  await expect(dialog).toBeHidden()
  await expect(startsOn).toBeFocused()
  await expect(startsOn).not.toContainText('Pick a date')

  // Switch: Space toggles and the state is exposed.
  const active = form.getByRole('switch', { name: 'Active in planning', exact: true })
  await active.focus()
  await page.keyboard.press('Space')
  await expect(active).toHaveAttribute('aria-checked', 'false')
  await page.keyboard.press('Space')
  await expect(active).toHaveAttribute('aria-checked', 'true')

  // Searchable control: typing filters, Enter commits, focus returns.
  await page.goto('/account')
  const timezone = page.getByRole('combobox', { name: 'Timezone', exact: true })
  await timezone.focus()
  await page.keyboard.type('Kyiv')
  await expect(page.getByRole('listbox')).toBeVisible()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('listbox')).toBeHidden()
  await expect(timezone).toHaveValue('Europe/Kyiv')
  await expect(timezone).toBeFocused()
})

test('a routine is created end to end without a pointer', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'KeyboardCreate'))

  await page.goto('/routines')
  const form = page.getByRole('form', { name: 'Create routine' })

  await form.getByLabel('Name').focus()
  await page.keyboard.type('Keyboard-only routine')

  const kind = selectTrigger(form, 'Kind')
  await kind.focus()
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('ArrowDown')
  await page.keyboard.press('Enter')
  await expect(kind).toHaveText('Habit')

  const time = form.getByRole('combobox', { name: 'Preferred time', exact: true })
  await time.focus()
  await page.keyboard.type('06:45')
  await page.keyboard.press('Tab')
  await expect(time).toHaveValue('06:45')

  await form.getByRole('button', { name: 'Create routine' }).focus()
  await page.keyboard.press('Enter')

  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Keyboard-only routine' })).toBeVisible()
})
