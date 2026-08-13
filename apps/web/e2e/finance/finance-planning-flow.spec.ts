import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { expectNoHorizontalOverflow } from '../interface/support'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'

test('budget recurring plan cash flow and explicit outcome form one loop', async ({ page }, testInfo) => {
  test.setTimeout(120_000)
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinancePlanningFlow'), { redirectTo: '/finance' })
  const issues = collectRuntimeIssues(page)

  await page.getByRole('tab', { name: 'Accounts' }).click()
  const account = page.getByRole('form', { name: 'Account editor' })
  await account.getByLabel('Name').fill('Planning card')
  await account.getByRole('button', { name: 'Add account' }).click()
  await expect(page.getByText('Planning card', { exact: true })).toBeVisible()

  await page.getByRole('tab', { name: 'Budgets' }).click()
  const budget = page.getByRole('form', { name: 'Budget editor' })
  await budget.getByLabel('Expense category').selectOption({ label: 'Food' })
  await budget.getByLabel('Monthly limit').fill('1000.0000')
  await budget.getByRole('button', { name: 'Add budget' }).click()
  await expect(page.getByRole('article').filter({ hasText: 'Food' })).toContainText('0%')

  await page.getByRole('tab', { name: 'Plans' }).click()
  const plan = page.getByRole('form', { name: 'Recurring operation editor' })
  await plan.getByLabel('Name').fill('Groceries plan')
  await plan.getByLabel('Account').selectOption({ label: 'Planning card · UAH' })
  await plan.getByLabel('Category').selectOption({ label: 'Food' })
  await plan.getByLabel('Amount').fill('100.0000')
  await plan.getByLabel('Day 13').check()
  await plan.getByRole('button', { name: 'Add recurring operation' }).click()
  await expect(page.getByText('Groceries plan', { exact: true }).first()).toBeVisible()
  await expect(page.getByTestId('finance-cash-flow')).toContainText('Mandatory expenses')

  const occurrence = page.getByRole('article').filter({ hasText: 'Groceries plan' }).last()
  await occurrence.getByRole('button', { name: 'Mark paid' }).click()
  await expect(occurrence).toContainText('Actual')
  await page.getByRole('tab', { name: 'Budgets' }).click()
  await expect(page.getByRole('article').filter({ hasText: 'Food' })).toContainText('10%')
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('rejected planning mutation keeps the exact draft and localized controls remain usable', async ({ page }, testInfo) => {
  await registerViaUi(page, uniqueCredentials(testInfo, 'FinancePlanningDraft'), { redirectTo: '/finance?tab=budgets' })
  await page.getByRole('tab', { name: 'Budgets' }).click()
  const form = page.getByRole('form', { name: 'Budget editor' })
  await form.getByLabel('Monthly limit').fill('123.4567')
  await page.route('**/api/finance/budgets', async (route) => {
    if (route.request().method() === 'POST') {
      await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Budget rejected.', errors: { limit_amount: ['Budget rejected.'] } }) })
      return
    }
    await route.continue()
  })
  await form.getByRole('button', { name: 'Add budget' }).click()
  await expect(form.getByLabel('Monthly limit')).toHaveValue('123.4567')
  await expect(page.getByRole('alert').filter({ hasText: 'Budget rejected.' })).toBeVisible()
  await page.getByRole('button', { name: 'RU', exact: true }).click()
  await expect(page.getByRole('tab', { name: 'Бюджеты' })).toBeVisible()
  await page.getByRole('button', { name: 'UK', exact: true }).click()
  await expect(page.getByRole('tab', { name: 'Бюджети' })).toBeVisible()
  await expectNoHorizontalOverflow(page)
})
