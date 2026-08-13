import { expect, test } from '@playwright/test'
import { expectNoHorizontalOverflow, setSwitch, setTime } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'

test('quiet hours digest and categories save atomically and survive reload', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'NotificationSettings'), {
    redirectTo: '/notifications',
  })

  await expect(page.getByRole('heading', { name: 'Your notifications' })).toBeVisible()
  await setSwitch(page, 'Quiet hours', true)
  await setTime(page, 'Quiet starts', '22:30')
  await setTime(page, 'Quiet ends', '07:15')
  await setSwitch(page, 'Daily digest', false)
  await setTime(page, 'Digest time', '09:00')
  await setSwitch(page, 'Routine reminders', true)
  await setSwitch(page, 'Storage reminders', false)
  await page.getByRole('button', { name: 'Save notification settings' }).click()
  await expect(page.getByText('Notification settings saved.')).toBeVisible()

  await page.reload()
  await expect(page.getByRole('switch', { name: 'Quiet hours' })).toHaveAttribute('aria-checked', 'true')
  await expect(page.getByRole('combobox', { name: 'Quiet starts' })).toHaveValue('22:30')
  await expect(page.getByRole('combobox', { name: 'Quiet ends' })).toHaveValue('07:15')
  await expect(page.getByRole('switch', { name: 'Daily digest' })).toHaveAttribute('aria-checked', 'false')
  await expect(page.getByRole('switch', { name: 'Storage reminders' })).toHaveAttribute('aria-checked', 'false')

  const response = await page.evaluate(async () => {
    const result = await fetch('/api/notifications/settings', { headers: { Accept: 'application/json' } })
    return { status: result.status, body: await result.json() }
  })
  expect(response.status).toBe(200)
  expect(response.body.data).toEqual({
    quiet_hours: { enabled: true, starts_at: '22:30', ends_at: '07:15' },
    digest: { enabled: false, time: '09:00' },
    categories: { routine: true, storage: false },
  })
})

test('notification surface is complete in all locales and mobile-safe', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'NotificationLocales'), {
    redirectTo: '/notifications',
  })

  await expect(page.getByText('No notifications yet.')).toBeVisible()
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Ваши уведомления' })).toBeVisible()
  await expect(page.getByRole('switch', { name: 'Тихие часы' })).toBeVisible()
  await expect(page.getByText('Уведомлений пока нет.')).toBeVisible()

  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Ваші сповіщення' })).toBeVisible()
  await expect(page.getByRole('switch', { name: 'Тихі години' })).toBeVisible()
  await expect(page.getByText('Сповіщень ще немає.')).toBeVisible()

  await page.keyboard.press('Tab')
  await expect(page.locator(':focus')).toBeVisible()
  await expectNoHorizontalOverflow(page)
})
