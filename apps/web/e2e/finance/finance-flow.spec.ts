import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

test('account category rate actual transfer reversal and reconciliation form one exact loop', async ({ page }, testInfo) => {
  test.setTimeout(120_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinanceFlow'), { redirectTo: '/finance' })
  const issues = collectRuntimeIssues(page)
  await expect(page.getByRole('heading', { name: 'Finance', exact: true })).toBeVisible()

  await page.getByRole('tab', { name: 'Accounts' }).click()
  const account = page.getByRole('form', { name: 'Account editor' })
  await account.getByLabel('Name').fill('Cash wallet')
  await account.getByLabel('Opening balance').fill('100.0000')
  await account.getByRole('button', { name: 'Add account' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Account created.' })).toBeVisible()
  await account.getByLabel('Name').fill('Dollar wallet')
  await account.locator('[name="finance-account-currency"]').selectOption('USD')
  await account.getByLabel('Opening balance').fill('0')
  await account.getByRole('button', { name: 'Add account' }).click()
  await expect(page.getByText('Dollar wallet', { exact: true })).toBeVisible()

  await page.getByRole('tab', { name: 'Categories' }).click()
  await expect(page.getByText('Food', { exact: true })).toBeVisible()
  const category = page.getByRole('form', { name: 'Category editor' })
  await category.getByLabel('Name').fill('Coffee runs')
  await category.getByRole('button', { name: 'Add category' }).click()
  await expect(page.getByRole('strong').filter({ hasText: 'Coffee runs' })).toBeVisible()

  await page.getByRole('tab', { name: 'Rates' }).click()
  const rate = page.getByRole('form', { name: 'Exchange-rate editor' })
  await rate.getByLabel('Rate').fill('40.000000000000')
  await rate.getByRole('button', { name: 'Save rate' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Historical rate saved.' })).toBeVisible()

  await page.getByRole('tab', { name: 'Activity' }).click()
  const actual = page.getByRole('form', { name: 'Actual transaction editor' })
  await actual.getByLabel('Account').selectOption({ label: 'Cash wallet · UAH' })
  await actual.getByLabel('Category').selectOption({ label: 'Coffee runs' })
  await actual.getByLabel('Amount').fill('12.5000')
  await actual.getByLabel('Note').fill('Coffee')
  await actual.getByRole('button', { name: 'Post actual' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Actual transaction posted.' })).toBeVisible()

  const transfer = page.getByRole('form', { name: 'Transfer editor' })
  await transfer.getByLabel('Source account').selectOption({ label: 'Cash wallet · UAH' })
  await transfer.getByLabel('Source amount').fill('40.0000')
  await transfer.getByLabel('Destination account').selectOption({ label: 'Dollar wallet · USD' })
  await transfer.getByLabel('Destination amount').fill('1.0000')
  await transfer.getByLabel('Note').fill('Cash exchange')
  await transfer.getByRole('button', { name: 'Post transfer' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Transfer posted.' })).toBeVisible()

  const transferFact = page.getByRole('listitem').filter({ hasText: 'Cash exchange' }).first()
  page.once('dialog', (dialog) => dialog.accept('Wrong wallet'))
  await transferFact.getByRole('button', { name: 'Reverse' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Reversal posted.' })).toBeVisible()
  await expect(page.getByText('Reversal', { exact: true }).first()).toBeVisible()

  await page.getByRole('tab', { name: 'Accounts' }).click()
  const cashCard = page.getByRole('article').filter({ hasText: 'Cash wallet' }).first()
  await cashCard.getByRole('button', { name: 'Reconcile' }).click()
  const reconciliation = page.getByRole('form', { name: 'Reconcile Cash wallet' })
  await reconciliation.getByLabel('Observed balance').fill('90.0000')
  await reconciliation.getByLabel('Reason').fill('Cash count')
  await reconciliation.getByRole('button', { name: 'Save reconciliation' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Account reconciled.' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('locale switching works and rejected exact draft remains recoverable', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinanceDraft'), { redirectTo: '/finance' })
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Финансы', exact: true })).toBeVisible()
  await page.getByRole('tab', { name: 'Категории' }).click()
  await expect(page.getByText('Зарплата', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Фінанси', exact: true })).toBeVisible()
  await page.getByRole('tab', { name: 'Категорії' }).click()
  await expect(page.getByText('Зарплата', { exact: true })).toBeVisible()
  const englishSaved = page.waitForResponse((response) => response.url().endsWith('/api/profile')
    && response.request().method() === 'PATCH' && response.ok())
  await page.getByRole('button', { name: 'EN', exact: true }).click()
  await englishSaved
  await page.waitForLoadState('networkidle')

  await page.getByRole('tab', { name: 'Accounts' }).click()
  const form = page.getByRole('form', { name: 'Account editor' })
  await form.getByLabel('Name').fill('Recoverable finance draft')
  await form.getByLabel('Opening balance').fill('10.1234')
  await page.route('**/api/finance/accounts', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Account rejected.', errors: { name: ['Account rejected.'] } }) })
      return
    }
    await route.continue()
  })
  await form.getByRole('button', { name: 'Add account' }).click()
  await expect(form.getByLabel('Name')).toHaveValue('Recoverable finance draft')
  await expect(form.getByLabel('Opening balance')).toHaveValue('10.1234')
  await expect(page.getByRole('alert').filter({ hasText: 'Account rejected.' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})
