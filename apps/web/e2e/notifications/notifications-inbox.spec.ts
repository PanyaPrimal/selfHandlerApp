import { expect, test } from '@playwright/test'
import { gotoDestination } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { seedDeliveredNotification } from './support'

test('global badge and inbox triage keep domain actions distinct', async ({ page }, testInfo) => {
  const credentials = uniqueCredentials(testInfo, 'NotificationInbox')
  await registerViaUi(page, credentials)

  const seed = Date.now() + testInfo.retry * 10
  seedDeliveredNotification(credentials.email, 'Morning walk', seed)
  seedDeliveredNotification(credentials.email, 'Evening review', seed + 1)
  seedDeliveredNotification(credentials.email, 'Plan tomorrow', seed + 2)

  await page.reload()
  await expect(page.getByTestId('notification-unread-count').first()).toHaveText('3')
  await gotoDestination(page, 'Notifications')
  await expect(page).toHaveURL('/notifications')
  await expect(page.getByRole('heading', { name: 'Your notifications' })).toBeVisible()

  const morning = page.getByRole('article').filter({ hasText: 'Morning walk' })
  await morning.getByRole('button', { name: 'Mark Morning walk as read' }).click()
  await expect(page.getByTestId('notification-unread-count').first()).toHaveText('2')
  await expect(morning).toHaveAttribute('data-status', 'read')

  const evening = page.getByRole('article').filter({ hasText: 'Evening review' })
  await evening.getByRole('button', { name: 'Snooze Evening review' }).click()
  await page.getByRole('menu').getByRole('menuitem', { name: '1 hour' }).click()
  await expect(evening).toBeHidden()
  await expect(page.getByText('Snoozed for 1 hour.')).toBeVisible()

  const tomorrow = page.getByRole('article').filter({ hasText: 'Plan tomorrow' })
  await tomorrow.getByRole('button', { name: 'Dismiss Plan tomorrow' }).click()
  await expect(tomorrow).toBeHidden()
  await expect(page.getByTestId('notification-unread-count')).toHaveCount(0)
})

test('following a notification reads it and opens its safe Planner day', async ({ page }, testInfo) => {
  const credentials = uniqueCredentials(testInfo, 'NotificationAction')
  await registerViaUi(page, credentials, { redirectTo: '/notifications' })
  seedDeliveredNotification(credentials.email, 'Open the day', Date.now() + testInfo.retry)

  await page.reload()
  const card = page.getByRole('article').filter({ hasText: 'Open the day' })
  await card.getByRole('link', { name: 'Open in Planner' }).click()

  await expect(page).toHaveURL('/planner?date=2026-08-13')
  await expect(page.getByRole('main').getByText('Planner', { exact: true })).toBeVisible()

  const response = await page.evaluate(async () => {
    const result = await fetch('/api/notifications?view=unread', { headers: { Accept: 'application/json' } })
    return { status: result.status, body: await result.json() }
  })
  expect(response.status).toBe(200)
  expect(response.body.unread_count).toBe(0)
})
