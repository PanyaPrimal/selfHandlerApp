import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  request: vi.fn<(path: string, init?: RequestInit) => Promise<unknown>>(async () => ({ data: [] })),
  jsonRequest: vi.fn<(path: string, method: string, body: unknown) => Promise<unknown>>(async () => ({ data: {} })),
}))

vi.mock('../api/http', () => ({
  request: mocks.request,
  jsonRequest: mocks.jsonRequest,
  ApiError: class extends Error {},
  validationErrors: () => ({}),
}))

import {
  createFinanceAccount,
  createFinanceCategory,
  createFinanceTransaction,
  createFinanceTransfer,
  getFinanceAccounts,
  getFinanceCategories,
  getFinanceCurrencies,
  getFinanceExchangeRates,
  getFinanceSummary,
  getFinanceTransactions,
  reconcileFinanceAccount,
  reverseFinanceTransaction,
  updateFinanceAccount,
  updateFinanceCategory,
  upsertFinanceExchangeRate,
} from '../api/client'
import { financeAmount, financeInputAmount } from '../finance/money'

describe('finance API contract', () => {
  beforeEach(() => { mocks.request.mockClear(); mocks.jsonRequest.mockClear() })

  it('maps all fifteen operations to their authenticated route and method', async () => {
    await getFinanceCurrencies()
    await getFinanceExchangeRates('USD', 'UAH', '2026-08-01', '2026-08-13')
    await upsertFinanceExchangeRate({ from_currency: 'USD', to_currency: 'UAH', rate_date: '2026-08-13', rate: '41.25' })
    await getFinanceAccounts(true)
    await createFinanceAccount({ name: 'Cash', type: 'cash', currency: 'UAH', opening_balance: '10', opening_date: '2026-08-13', opening_note: null })
    await updateFinanceAccount(7, { archived: false })
    await reconcileFinanceAccount(7, { idempotency_key: 'reconcile-123', observed_balance: '10', occurred_on: '2026-08-13', reason: 'Counted cash' })
    await getFinanceCategories('expense', true)
    await createFinanceCategory({ direction: 'expense', parent_id: null, name: 'Custom' })
    await updateFinanceCategory(8, { name: 'Renamed' })
    await getFinanceTransactions('2026-08-01', '2026-08-13', 7)
    await createFinanceTransaction({ idempotency_key: 'entry-123', kind: 'expense', account_id: 7, category_id: 8, amount: '1.25', occurred_on: '2026-08-13', note: null, tag: null })
    await createFinanceTransfer({ idempotency_key: 'transfer-123', source_account_id: 7, destination_account_id: 9, source_amount: '10', destination_amount: '0.25', occurred_on: '2026-08-13', note: null, tag: null })
    await reverseFinanceTransaction('00000000-0000-4000-8000-000000000001', { idempotency_key: 'reverse-123', reason: 'Correction' })
    await getFinanceSummary('2026-08-01', '2026-08-13', '2026-08-13')

    expect(mocks.request.mock.calls.map(([path, init]) => [path, init?.method ?? 'GET'])).toEqual([
      ['/finance/currencies', 'GET'],
      ['/finance/exchange-rates?from_currency=USD&to_currency=UAH&from=2026-08-01&to=2026-08-13', 'GET'],
      ['/finance/accounts?include_archived=1', 'GET'],
      ['/finance/categories?direction=expense&include_archived=1', 'GET'],
      ['/finance/transactions?from=2026-08-01&to=2026-08-13&account_id=7', 'GET'],
      ['/finance/summary?from=2026-08-01&to=2026-08-13&as_of=2026-08-13', 'GET'],
    ])
    expect(mocks.jsonRequest.mock.calls.map(([path, method]) => [path, method])).toEqual([
      ['/finance/exchange-rates', 'PUT'], ['/finance/accounts', 'POST'],
      ['/finance/accounts/7', 'PATCH'], ['/finance/accounts/7/reconcile', 'POST'],
      ['/finance/categories', 'POST'], ['/finance/categories/8', 'PATCH'],
      ['/finance/transactions', 'POST'], ['/finance/transfers', 'POST'],
      ['/finance/transactions/00000000-0000-4000-8000-000000000001/reverse', 'POST'],
    ])
  })

  it('keeps money as exact strings and never parses an invalid draft', () => {
    expect(financeAmount('123.4500', 'UAH', 'en-GB')).toContain('123.45')
    expect(financeInputAmount('001.2300')).toBe('1.2300')
    expect(financeInputAmount('-0.0001')).toBe('-0.0001')
    expect(financeInputAmount('1e3')).toBeNull()
    expect(financeInputAmount('0.00001')).toBeNull()
    expect(financeInputAmount('+00010.1200')).toBe('10.1200')
    expect(financeInputAmount('1000000000000000')).toBeNull()
    expect(financeInputAmount('-0.0000')).toBe('0.0000')
  })
})
