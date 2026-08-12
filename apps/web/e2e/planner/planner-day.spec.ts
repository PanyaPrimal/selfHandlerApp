import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import {
  dateTrigger,
  expectNoHorizontalOverflow,
  gotoDestination,
  pickDate,
  setTime,
} from '../interface/support'

/** The day the planner opens on, read from the app rather than from Node's clock. */
async function currentDay(page: Page): Promise<string> {
  const day = await page.evaluate(async () => {
    const response = await fetch('/api/planner/day', { headers: { Accept: 'application/json' } })
    return (await response.json()) as { date: string }
  })

  return day.date
}

function addDays(iso: string, amount: number): string {
  const [year, month, day] = iso.split('-').map(Number)
  // UTC only, and read back only through the UTC getters: this is calendar
  // arithmetic, not an instant.
  const shifted = new Date(Date.UTC(year, month - 1, day + amount))

  return [
    shifted.getUTCFullYear(),
    String(shifted.getUTCMonth() + 1).padStart(2, '0'),
    String(shifted.getUTCDate()).padStart(2, '0'),
  ].join('-')
}

async function createDailyRoutine(page: Page, name: string): Promise<void> {
  await page.goto('/routines')

  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill(name)
  await form.getByRole('button', { name: 'Create routine' }).click()

  await expect(page.getByRole('listitem', { name })).toBeVisible()
}

async function addTimeBlock(page: Page, title: string, startsAt: string, endsAt: string): Promise<void> {
  await page.getByRole('button', { name: 'Add a block' }).click()

  const form = page.getByRole('form', { name: 'Add a time block' })
  await form.getByLabel('What is it?').fill(title)
  await setTime(form, 'Starts at', startsAt)
  await setTime(form, 'Ends at', endsAt)
  await form.getByRole('button', { name: 'Add block' }).click()

  await expect(page.getByRole('status').filter({ hasText: 'Block added.' })).toBeVisible()
}

test('one day shows every source, and a routine day can be moved and put back', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Planner'))
  await createDailyRoutine(page, 'Morning walk')

  await gotoDestination(page, 'Planner')
  await expect(page).toHaveURL('/planner')
  await expect(page.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible()

  const today = await currentDay(page)
  const tomorrow = addDays(today, 1)

  // The routine arrives through the source contract, not through a copy.
  await expect(page.getByRole('listitem', { name: 'Morning walk' })).toBeVisible()

  await addTimeBlock(page, 'Dentist', '14:00', '15:00')
  await expect(page.getByRole('listitem', { name: 'Dentist' })).toBeVisible()
  await expect(page.getByText('2 planned')).toBeVisible()

  // Move the routine day to tomorrow: it leaves today and appears there.
  await page.getByRole('button', { name: 'Move Morning walk' }).click()
  const moveForm = page.getByRole('form', { name: 'Move Morning walk' })
  await pickDate(page, 'Move to', tomorrow)
  await moveForm.getByRole('button', { name: 'Move', exact: true }).click()

  await expect(page.getByRole('listitem', { name: 'Morning walk' })).toBeHidden()
  await expect(page.getByRole('listitem', { name: 'Dentist' })).toBeVisible()

  await page.getByRole('button', { name: 'Next day' }).click()

  // The routine is daily, so tomorrow now holds its own day and the moved one.
  // Two real commitments, shown as two rows rather than silently merged.
  const walksTomorrow = page.getByRole('listitem', { name: 'Morning walk' })
  await expect(walksTomorrow).toHaveCount(2)
  // The moved one says where it came from rather than looking like a schedule change.
  await expect(page.getByText(`moved from ${today}`)).toBeVisible()
  // A block belongs to its own day only.
  await expect(page.getByRole('listitem', { name: 'Dentist' })).toBeHidden()

  await page.getByRole('button', { name: 'Put Morning walk back' }).click()
  await expect(walksTomorrow).toHaveCount(1)
  await expect(page.getByText(`moved from ${today}`)).toBeHidden()

  await page.getByRole('button', { name: 'Today' }).first().click()
  await expect(page.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Morning walk' })).toBeVisible()

  await expectNoHorizontalOverflow(page)
})

test('an empty day and a day beyond the window say different things', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PlannerEmpty'))
  await page.goto('/planner')

  // No routines and no blocks: genuinely nothing planned.
  await expect(page.getByText('Nothing planned for this day.')).toBeVisible()

  await createDailyRoutine(page, 'Morning walk')
  await page.goto('/planner')

  const today = await currentDay(page)
  // Well past the 90-day window: not empty, just not expanded that far.
  await pickDate(page, 'Day', addDays(today, 200))

  await expect(page.getByText(/Recurring days are only planned out to/)).toBeVisible()
  await expect(page.getByText('Nothing planned for this day.')).toBeHidden()
})

test('a day can only be moved forwards, and a settled day cannot be moved at all', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PlannerRefuse'))
  await createDailyRoutine(page, 'Morning walk')
  await page.goto('/planner')

  const today = await currentDay(page)

  await page.getByRole('button', { name: 'Move Morning walk' }).click()
  await dateTrigger(page, 'Move to').click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  // The past is not offered, so the refusal is visible before it is attempted.
  await expect(dialog.locator(`[id$="-day-${addDays(today, -1)}"]`)).toHaveAttribute('aria-disabled', 'true')
  await expect(dialog.locator(`[id$="-day-${addDays(today, 1)}"]`)).not.toHaveAttribute('aria-disabled', 'true')

  await page.keyboard.press('Escape')
  await expect(dialog).toBeHidden()

  // Once the day has a result it is history, and history does not move.
  await page.getByRole('button', { name: 'Skip Morning walk' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Marked as skipped.' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Move Morning walk' })).toBeHidden()
})

test('skipping from the planner is the same fact Today records', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PlannerSkip'))
  await createDailyRoutine(page, 'Morning walk')
  await page.goto('/planner')

  await page.getByRole('button', { name: 'Skip Morning walk' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Marked as skipped.' })).toBeVisible()

  // The day is now settled, so the planner offers nothing further on it.
  await expect(page.getByRole('button', { name: 'Skip Morning walk' })).toBeHidden()
  await expect(page.getByRole('button', { name: 'Move Morning walk' })).toBeHidden()

  // And Today shows the same skip, because it is the same routine log.
  await page.goto('/')
  await expect(page.getByRole('listitem', { name: 'Morning walk' })).toContainText(/[Ss]kipped/)
})

test('the day is usable on a phone and reachable by keyboard', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Phone layout is asserted in the mobile project.')

  await registerViaUi(page, uniqueCredentials(testInfo, 'PlannerPhone'))
  await createDailyRoutine(page, 'Morning walk')

  await gotoDestination(page, 'Planner')
  await expect(page.getByRole('heading', { name: 'Today', level: 1 })).toBeVisible()

  await addTimeBlock(page, 'A rather long appointment title that must wrap', '09:00', '10:00')

  await expectNoHorizontalOverflow(page)

  // The day picker opens from the keyboard and closes without stranding focus.
  const trigger = dateTrigger(page, 'Day')
  await trigger.focus()
  await page.keyboard.press('Enter')
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).toBeHidden()
  await expect(trigger).toBeFocused()
})
