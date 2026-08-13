import { describe, expect, it, vi } from 'vitest'

vi.mock('../api/http', () => ({
  jsonRequest: vi.fn(async () => ({ data: [] })),
  request: vi.fn(async () => ({ data: [] })),
}))

import {
  createFinanceCounterparty,
  createFinanceDebt,
  createFinanceFundMovement,
  createFinanceGoal,
  createFinanceSavingFund,
  createFinanceSourceExpense,
  getFinanceCounterparties,
  getFinanceDebts,
  getFinanceGoals,
  getFinanceSavingFunds,
  payFinanceDebt,
} from '../api/client'

describe('finance commitments client', () => {
  it('exports every new aggregate boundary', () => {
    for (const operation of [
      getFinanceCounterparties, createFinanceCounterparty, getFinanceDebts, createFinanceDebt,
      payFinanceDebt, getFinanceSavingFunds, createFinanceSavingFund, createFinanceFundMovement,
      getFinanceGoals, createFinanceGoal, createFinanceSourceExpense,
    ]) expect(operation).toBeTypeOf('function')
  })
})
