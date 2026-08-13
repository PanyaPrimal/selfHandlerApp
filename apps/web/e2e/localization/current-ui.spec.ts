import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'

const routes = [
  ['/', 'Добрый вечер'],
  ['/routines', 'Рутины и сон'],
  ['/goals', 'Цели, связанные с действиями'],
  ['/review', 'Вечерний обзор'],
  ['/planner', 'Планировщик'],
  ['/storage', 'Записывайте сейчас, разбирайте позже'],
  ['/body', 'Замеры и цели тела'],
  ['/settings/appearance', 'Оформление'],
  ['/account', 'Ваши исходные данные'],
  ['/changelog', 'Что нового в SelfHandler'],
] as const

async function selectLocale(page: Page, code: 'EN' | 'RU' | 'UK'): Promise<void> {
  const saved = page.waitForResponse((response) =>
    response.url().includes('/api/profile') && response.request().method() === 'PATCH',
  )
  await page.getByRole('button', { name: code, exact: true }).click()
  expect((await saved).ok()).toBeTruthy()
}

test('every current route renders Russian product copy and localized formatting', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'AllRoutes'))
  await selectLocale(page, 'RU')

  for (const [path, expected] of routes) {
    await page.goto(path)
    await expect(page.locator('html')).toHaveAttribute('lang', 'ru')
    await expect(page.locator('main').getByText(expected, { exact: false }).first()).toBeVisible()
    await expect(page.locator('body')).not.toContainText('[missing:')
  }

  await selectLocale(page, 'UK')
  await page.goto('/routines')
  await expect(page.getByRole('heading', { name: 'Рутини та сон' })).toBeVisible()
  await expect(page.getByRole('form', { name: 'Створити рутину' })
    .getByRole('radio', { name: 'Щодня', exact: true })).toBeVisible()

  await selectLocale(page, 'EN')
  await page.goto('/routines')
  await expect(page.getByRole('heading', { name: 'Routines & sleep' })).toBeVisible()
})

test('validation, empty state, ARIA labels and user content remain correctly separated', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'LocalizedFeedback'), { redirectTo: '/storage' })
  await selectLocale(page, 'UK')

  await expect(page.getByText('Поки що нічого не очікує.')).toBeVisible()
  await page.getByLabel('Що у вас на думці?').fill('English user-authored title')
  await page.getByRole('button', { name: 'Зберегти' }).click()
  await expect(page.getByText('English user-authored title')).toBeVisible()

  await page.getByRole('button', { name: 'UK', exact: true }).focus()
  await expect(page.getByRole('group', { name: 'Мова інтерфейсу' })).toBeVisible()
  await expect(page.getByTestId('quick-theme-toggle')).toHaveAccessibleName(/темн|світл/i)
})
