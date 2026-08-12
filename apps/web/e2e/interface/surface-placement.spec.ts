import { expect, test, type Locator, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { openSurface, selectTrigger } from './support'

/**
 * An overlay must never be painted before it has been placed.
 *
 * floating-ui positions the surface with `transform: translate(x, y)`, and its
 * styles are the top-left corner until the first measurement lands. Painting
 * that frame - or animating `transform`, which overrides the placement for the
 * whole animation - shows the surface in the corner of the screen and then
 * moves it to the control.
 *
 * The surface is therefore kept hidden until it is positioned, which means the
 * first frame in which it is visible must already be at its anchor. These tests
 * measure exactly that frame.
 */
async function expectAnchoredOnFirstPaint(page: Page, anchor: Locator, surface: Locator): Promise<void> {
  await expect(surface).toBeVisible()

  const anchorBox = await anchor.boundingBox()
  const surfaceBox = await surface.boundingBox()

  expect(anchorBox).not.toBeNull()
  expect(surfaceBox).not.toBeNull()

  if (!anchorBox || !surfaceBox) {
    return
  }

  // Not parked in the corner of the viewport.
  expect(surfaceBox.x + surfaceBox.y).toBeGreaterThan(0)

  // Horizontally aligned with the control it belongs to.
  expect(Math.abs(surfaceBox.x - anchorBox.x)).toBeLessThanOrEqual(24)

  // Vertically adjacent to it: directly below, or flipped directly above.
  const below = surfaceBox.y >= anchorBox.y - 2
  const above = surfaceBox.y + surfaceBox.height <= anchorBox.y + anchorBox.height + 2

  expect(below || above).toBe(true)
}

test('a listbox is already at its control the moment it becomes visible', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Placement'))
  await page.goto('/routines')

  const form = page.getByRole('form', { name: 'Create routine' })
  const trigger = selectTrigger(form, 'Kind')

  await trigger.click()
  await expectAnchoredOnFirstPaint(page, trigger, openSurface(page))
})

test('a calendar is already at its control the moment it becomes visible', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PlacementCalendar'))
  await page.goto('/routines')

  const trigger = page.getByRole('button', { name: /^Starts on\b/ })

  await trigger.click()
  await expectAnchoredOnFirstPaint(page, trigger, page.getByRole('dialog'))
})

test('the appear animation never drives transform', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'PlacementAnimation'))
  await page.goto('/routines')

  await selectTrigger(page.getByRole('form', { name: 'Create routine' }), 'Kind').click()
  await expect(openSurface(page)).toBeVisible()

  // The placement lives in `transform`, so any keyframe touching that property
  // would take the surface over while it plays.
  const animatedProperties = await page.evaluate(() => {
    const surface = document.querySelector('.ui-surface')

    if (!surface) {
      return ['no-surface']
    }

    const name = getComputedStyle(surface).animationName
    const properties: string[] = []

    for (const sheet of Array.from(document.styleSheets)) {
      let rules: CSSRuleList

      try {
        rules = sheet.cssRules
      } catch {
        continue
      }

      for (const rule of Array.from(rules)) {
        if (!(rule instanceof CSSKeyframesRule) || rule.name !== name) {
          continue
        }

        for (const frame of Array.from(rule.cssRules)) {
          const style = (frame as CSSKeyframeRule).style

          for (let index = 0; index < style.length; index += 1) {
            properties.push(style.item(index))
          }
        }
      }
    }

    return properties
  })

  expect(animatedProperties).not.toContain('transform')
})
