import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { setCheckbox } from '../interface/support'

/**
 * A routine link must stay removable for as long as it is shown.
 *
 * The goal card lists every linked routine, but the checkbox list only offered
 * the currently active ones. Pausing or archiving a linked routine therefore
 * removed its checkbox while the link itself remained, leaving the user looking
 * at a link with no way to undo it.
 */

async function createRoutine(page: Page, name: string): Promise<void> {
  await page.goto('/routines')
  const form = page.getByRole('form', { name: 'Create routine' })
  await form.getByLabel('Name').fill(name)
  await form.getByRole('button', { name: 'Create routine' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine created.' })).toBeVisible()
}

async function createGoal(page: Page, name: string): Promise<void> {
  await page.goto('/goals')
  const form = page.getByRole('form', { name: 'Create goal' })
  await form.getByLabel('Name').fill(name)
  await form.getByRole('button', { name: 'Create goal' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Goal created.' })).toBeVisible()
}

async function saveLinks(page: Page, goalName: string): Promise<void> {
  const saved = page.waitForResponse(
    (response) => ['POST', 'DELETE'].includes(response.request().method())
      && /\/api\/goals\/\d+\/routines\/\d+$/.test(new URL(response.url()).pathname),
  )
  await page.getByRole('button', { name: `Save routine links for ${goalName}` }).click()
  expect((await saved).status()).toBeLessThan(400)
  await expect(page.getByRole('status').filter({ hasText: 'Routine links saved.' })).toBeVisible()
}

test('a link to an active routine can be removed again', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'LinkRemoval'))

  await createRoutine(page, 'Evening walk')
  await createGoal(page, 'Stay consistent')

  const goal = page.getByRole('listitem', { name: 'Stay consistent' })

  await setCheckbox(goal, 'Evening walk', true)
  await saveLinks(page, 'Stay consistent')
  await expect(goal).toContainText('Linked: Evening walk')

  await setCheckbox(goal, 'Evening walk', false)
  await saveLinks(page, 'Stay consistent')
  await expect(goal).toContainText('No routines linked')
})

test('a link to a paused routine is still shown and still removable', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'LinkPaused'))

  await createRoutine(page, 'Evening walk')
  await createGoal(page, 'Stay consistent')

  const goal = page.getByRole('listitem', { name: 'Stay consistent' })
  await setCheckbox(goal, 'Evening walk', true)
  await saveLinks(page, 'Stay consistent')
  await expect(goal).toContainText('Linked: Evening walk')

  await page.goto('/routines')
  await page.getByRole('button', { name: 'Pause Evening walk' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine paused.' })).toBeVisible()

  await page.goto('/goals')
  const pausedGoal = page.getByRole('listitem', { name: 'Stay consistent' })

  // The link is still displayed, so the control that removes it must still exist.
  await expect(pausedGoal).toContainText('Linked: Evening walk')
  await setCheckbox(pausedGoal, 'Evening walk (paused)', false)
  await saveLinks(page, 'Stay consistent')

  await expect(pausedGoal).toContainText('No routines linked')
})

test('a link to an archived routine is still shown and still removable', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'LinkArchived'))

  await createRoutine(page, 'Evening walk')
  await createGoal(page, 'Stay consistent')

  const goal = page.getByRole('listitem', { name: 'Stay consistent' })
  await setCheckbox(goal, 'Evening walk', true)
  await saveLinks(page, 'Stay consistent')

  await page.goto('/routines')
  await page.getByRole('button', { name: 'Archive Evening walk' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Routine archived.' })).toBeVisible()

  await page.goto('/goals')
  const archivedGoal = page.getByRole('listitem', { name: 'Stay consistent' })

  await expect(archivedGoal).toContainText('Linked: Evening walk')
  await setCheckbox(archivedGoal, 'Evening walk (archived)', false)
  await saveLinks(page, 'Stay consistent')

  await expect(archivedGoal).toContainText('No routines linked')
})
