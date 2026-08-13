import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  request: vi.fn<(path: string, init?: RequestInit) => Promise<unknown>>(async () => ({ data: [], month: '2026-08' })),
  jsonRequest: vi.fn<(path: string, method: string, body?: unknown) => Promise<unknown>>(async () => ({ data: {} })),
}))

vi.mock('../api/http', () => ({
  request: mocks.request,
  jsonRequest: mocks.jsonRequest,
  ApiError: class extends Error {},
  validationErrors: () => ({}),
}))

import {
  clearFinanceOccurrenceOutcome,
  createFinanceBudget,
  createFinanceRecurringOperation,
  deleteFinanceBudget,
  getFinanceBudgets,
  getFinanceCashFlow,
  getFinancePlannedOccurrences,
  getFinanceRecurringOperations,
  putFinanceOccurrenceOutcome,
  updateFinanceBudget,
  updateFinanceRecurringOperation,
} from '../api/client'

describe('finance planning API contract', () => {
  beforeEach(() => { mocks.request.mockClear(); mocks.jsonRequest.mockClear() })

  it('maps all eleven operations to their authenticated path and method', async () => {
    await getFinanceBudgets('2026-08')
    await createFinanceBudget({ month: '2026-08', category_id: 2, limit_amount: '1000', currency: 'UAH' })
    await updateFinanceBudget(3, { limit_amount: '900' })
    await deleteFinanceBudget(3)
    await getFinanceRecurringOperations(true)
    await createFinanceRecurringOperation({ name: 'Rent', direction: 'expense', account_id: 1, category_id: 2, amount: '100', mandatory: true, starts_on: '2026-08-01', ends_on: null, interval_months: 1, month_days: [5], reminder_time: '09:00' })
    await updateFinanceRecurringOperation(4, { active: false })
    await getFinanceCashFlow('2026-08')
    await getFinancePlannedOccurrences('2026-08-01', '2026-08-31')
    await putFinanceOccurrenceOutcome(5, 'actual')
    await clearFinanceOccurrenceOutcome(5)

    expect(mocks.request.mock.calls.map(([path, init]) => [path, init?.method ?? 'GET'])).toEqual([
      ['/finance/budgets?month=2026-08', 'GET'],
      ['/finance/budgets/3', 'DELETE'],
      ['/finance/recurring-operations?include_archived=1', 'GET'],
      ['/finance/cash-flow?month=2026-08', 'GET'],
      ['/finance/planned-occurrences?from=2026-08-01&to=2026-08-31', 'GET'],
      ['/finance/planned-occurrences/5/outcome', 'DELETE'],
    ])
    expect(mocks.jsonRequest.mock.calls.map(([path, method]) => [path, method])).toEqual([
      ['/finance/budgets', 'POST'], ['/finance/budgets/3', 'PATCH'],
      ['/finance/recurring-operations', 'POST'], ['/finance/recurring-operations/4', 'PATCH'],
      ['/finance/planned-occurrences/5/outcome', 'PUT'],
    ])
  })
})
