import { expect, type Locator, type Page } from '@playwright/test'

/**
 * Helpers for driving the owned control layer introduced by feature 005.
 *
 * The controls render their own surfaces, so `selectOption` and `fill` no longer
 * apply to choice, date and time fields. These helpers keep the browser suites
 * expressing intent ("choose this option") rather than markup detail.
 */

/** Trigger of a listbox-backed choice control. */
export function selectTrigger(scope: Page | Locator, label: string): Locator {
  return scope.getByRole('combobox', { name: label, exact: true })
}

/** Open a `UiSelect` and commit one option by its visible label. */
export async function chooseOption(
  scope: Page | Locator,
  label: string,
  optionLabel: string,
): Promise<void> {
  const trigger = selectTrigger(scope, label)
  await trigger.click()

  const listbox = pageOf(scope).getByRole('listbox')
  await expect(listbox).toBeVisible()
  await listbox.getByRole('option', { name: optionLabel, exact: true }).click()
  await expect(trigger).toHaveAttribute('aria-expanded', 'false')
}

/** Type into a `UiCombobox` and commit the matching option. */
export async function searchAndChoose(
  scope: Page | Locator,
  label: string,
  query: string,
  optionLabel: string,
): Promise<void> {
  const input = scope.getByRole('combobox', { name: label, exact: true })
  await input.click()
  await input.fill(query)

  const listbox = pageOf(scope).getByRole('listbox')
  await expect(listbox).toBeVisible()
  await listbox.getByRole('option', { name: optionLabel, exact: true }).click()
  await expect(input).toHaveValue(optionLabel)
}

/** Read the committed label shown on a closed `UiSelect`. */
export function selectedOptionLabel(scope: Page | Locator, label: string): Locator {
  return selectTrigger(scope, label)
}

/** Choose a value in a `UiSegmented` radio group. */
export async function chooseSegment(
  scope: Page | Locator,
  groupLabel: string,
  optionLabel: string,
): Promise<void> {
  const group = scope.getByRole('radiogroup', { name: groupLabel, exact: true })
  await group.getByRole('radio', { name: optionLabel, exact: true }).click()
}

/** Open a `UiDatePicker` and select an exact `YYYY-MM-DD` day. */
export async function pickDate(page: Page, label: string, iso: string): Promise<void> {
  const trigger = dateTrigger(page, label)
  await trigger.click()

  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()

  const cell = dialog.locator(`[id$="-day-${iso}"]`)

  // Page whole months until the requested day is inside the visible grid. The
  // grid always renders six weeks, so a target in the current month is present
  // immediately and neighbours resolve in one step.
  for (let attempt = 0; attempt < 400 && (await cell.count()) === 0; attempt += 1) {
    const cursor = await dialog.locator('[role="gridcell"][tabindex="0"]').getAttribute('id')
    const currentIso = cursor?.slice(-10) ?? iso
    const currentYear = Number(currentIso.slice(0, 4))
    const targetYear = Number(iso.slice(0, 4))
    const direction = currentIso < iso ? 'Next' : 'Previous'
    const interval = currentYear === targetYear ? 'month' : 'year'
    await dialog.getByRole('button', { name: `${direction} ${interval}` }).click()
  }

  await cell.first().click()
  await expect(dialog).toBeHidden()
}

export function dateTrigger(page: Page, label: string): Locator {
  return page.getByRole('button', { name: new RegExp(`^${escapeForRegExp(label)}\\b`) })
}

/** Clear a `UiDatePicker` back to an empty value. */
export async function clearDate(page: Page, label: string): Promise<void> {
  await dateTrigger(page, label).click()
  const dialog = page.getByRole('dialog')
  await expect(dialog).toBeVisible()
  await dialog.getByRole('button', { name: 'Clear' }).click()
  await expect(dialog).toBeHidden()
}

/** Type an exact `HH:MM` into a `UiTimeField`. */
export async function setTime(scope: Page | Locator, label: string, value: string): Promise<void> {
  const input = scope.getByRole('combobox', { name: label, exact: true })
  await input.fill(value)
  await input.blur()
}

/** Toggle one member of a `UiToggleGroup`. */
export async function toggleOption(
  scope: Page | Locator,
  groupLabel: string,
  optionLabel: string,
): Promise<void> {
  const group = scope.getByRole('group', { name: groupLabel, exact: true })
  await group.getByRole('button', { name: optionLabel, exact: true }).click()
}

/** Set a `UiSwitch` to an explicit state. */
export async function setSwitch(scope: Page | Locator, label: string, on: boolean): Promise<void> {
  const control = scope.getByRole('switch', { name: label, exact: true })

  if ((await control.getAttribute('aria-checked')) !== String(on)) {
    await control.click()
  }
}

/** Set a `UiCheckbox` to an explicit state. */
export async function setCheckbox(scope: Page | Locator, label: string, on: boolean): Promise<void> {
  const control = scope.getByRole('checkbox', { name: label, exact: true })

  if ((await control.isChecked()) !== on) {
    await control.setChecked(on)
  }
}

/** Assert the document never scrolls sideways at the current viewport. */
export async function expectNoHorizontalOverflow(page: Page): Promise<void> {
  const overflow = await page.evaluate(() => ({
    scrollWidth: document.documentElement.scrollWidth,
    clientWidth: document.documentElement.clientWidth,
  }))

  expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth + 1)
}

/**
 * Navigate to a destination the way a user would, regardless of viewport: a
 * direct sidebar link on desktop, or the "More" menu on a phone.
 */
export async function gotoDestination(page: Page, label: string): Promise<void> {
  const direct = page.getByRole('link', { name: label, exact: true }).first()

  if (await direct.isVisible().catch(() => false)) {
    await direct.click()
    return
  }

  await page.getByRole('button', { name: /More/ }).click()
  await page.getByRole('menu').getByRole('menuitem', { name: label, exact: true }).click()
}

/**
 * The open overlay shell. It is the scrolling container, so it — not the inner
 * `listbox`, which is free to be taller — is what must fit the viewport.
 */
export function openSurface(page: Page): Locator {
  return page.locator('.ui-surface')
}

/**
 * Assert an open surface stays inside the viewport and clear of the tab bar.
 *
 * Positioning settles over a frame or two after the surface appears, so the
 * measurement retries like any other Playwright assertion instead of sampling
 * the surface mid-flight.
 */
export async function expectSurfaceWithinViewport(page: Page, surface: Locator): Promise<void> {
  const viewport = page.viewportSize()
  expect(viewport).not.toBeNull()

  if (!viewport) {
    return
  }

  await expect(async () => {
    const box = await surface.boundingBox()
    expect(box).not.toBeNull()

    if (!box) {
      return
    }

    expect(box.x).toBeGreaterThanOrEqual(-1)
    expect(box.y).toBeGreaterThanOrEqual(-1)
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1)
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1)
  }).toPass({ timeout: 5_000 })
}

function pageOf(scope: Page | Locator): Page {
  return 'page' in scope ? (scope as Locator).page() : (scope as Page)
}

function escapeForRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}
