import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { chooseOption, expectNoHorizontalOverflow, gotoDestination } from '../interface/support'

async function capture(page: Page, title: string): Promise<void> {
  const form = page.getByRole('form', { name: 'Capture an item' })
  const saved = page.waitForResponse(
    (response) => response.request().method() === 'POST'
      && response.url().endsWith('/api/storage/items'),
  )

  await form.getByLabel('What is on your mind?').fill(title)
  await form.getByRole('button', { name: 'Capture' }).click()

  expect((await saved).status()).toBe(201)
}

test('one field captures, and triage moves the item out of the inbox', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'Storage'))
  await gotoDestination(page, 'Storage')
  await expect(page).toHaveURL('/storage')

  // The empty inbox explains itself rather than showing a blank frame.
  await expect(page.getByText(/Anything you capture lands here/)).toBeVisible()

  await capture(page, 'Book the dentist')

  await expect(page.getByRole('listitem', { name: 'Book the dentist' })).toBeVisible()
  await expect(page.getByText('1 unsorted')).toBeVisible()

  // The capture field clears and keeps focus, ready for the next thought.
  const field = page.getByRole('form', { name: 'Capture an item' }).getByLabel('What is on your mind?')
  await expect(field).toHaveValue('')
  await expect(field).toBeFocused()

  await capture(page, 'Learn to weld')
  await expect(page.getByText('2 unsorted')).toBeVisible()

  await page.getByRole('button', { name: 'Triage Book the dentist' }).click()
  await expect(page.getByText('1 unsorted')).toBeVisible()
  await expect(page.getByRole('heading', { name: 'In progress' })).toBeVisible()

  await expectNoHorizontalOverflow(page)
})

test('a blocking child refuses the parent completion until it is closed', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'StorageBlock'))
  await page.goto('/storage')

  await capture(page, 'Fit the shelf')
  await page.getByRole('button', { name: 'Triage Fit the shelf' }).click()
  await expect(page.getByRole('heading', { name: 'In progress' })).toBeVisible()

  const parent = page.getByRole('listitem', { name: 'Fit the shelf' })
  await expect(parent.getByText(/No child items/)).toBeVisible()

  const childForm = page.getByRole('form', { name: 'Add a child to Fit the shelf' })
  await childForm.getByLabel('Add a child to Fit the shelf').fill('Buy brackets')
  await childForm.getByRole('button', { name: 'Add child' }).click()

  const child = page.getByRole('listitem', { name: 'Buy brackets' })
  await expect(child).toBeVisible()

  await page.getByRole('button', { name: 'Mark Buy brackets as a blocker' }).click()
  await expect(child.getByText('blocker')).toBeVisible()

  // The parent cannot be finished while the blocker is open, and the message
  // names what is in the way.
  await page.getByRole('button', { name: 'Complete Fit the shelf' }).click()
  await expect(page.getByRole('alert')).toContainText('Buy brackets')
  await expect(page.getByRole('listitem', { name: 'Fit the shelf' })).toBeVisible()

  await page.getByRole('button', { name: 'Complete Buy brackets' }).click()
  await page.getByRole('button', { name: 'Complete Fit the shelf' }).click()

  await expect(page.getByRole('heading', { name: 'Closed' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})

test('a project groups items and deleting it keeps the work', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'StorageProject'))
  await page.goto('/storage')

  await page.getByRole('button', { name: 'New project' }).click()
  const projectForm = page.getByRole('form', { name: 'Create project' })
  await projectForm.getByLabel('Project name').fill('Renovation')
  await projectForm.getByRole('button', { name: 'Create project' }).click()

  await expect(page.getByRole('listitem', { name: 'Renovation' })).toBeVisible()

  await capture(page, 'Order tiles')
  await page.getByRole('button', { name: 'Triage Order tiles' }).click()

  await chooseOption(page, 'Project of Order tiles', 'Renovation')
  await expect(page.getByRole('listitem', { name: 'Renovation' })).toContainText('1 open')

  await page.getByRole('button', { name: 'Delete Renovation' }).click()

  // The container goes; the work stays.
  await expect(page.getByText('Project deleted. Its items are still here.')).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Order tiles' })).toBeVisible()
})

test('storage is reachable and usable at 390px', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile', 'Compact layout')

  await registerViaUi(page, uniqueCredentials(testInfo, 'StorageMobile'))
  await gotoDestination(page, 'Storage')

  await expect(page).toHaveURL('/storage')
  await capture(page, 'Captured on a phone')
  await expect(page.getByRole('listitem', { name: 'Captured on a phone' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})
