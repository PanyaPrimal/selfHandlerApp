import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  request: vi.fn<(path: string, init?: RequestInit) => Promise<unknown>>(async () => ({ data: [], meta: {} })),
  jsonRequest: vi.fn<(path: string, method: string, body: unknown) => Promise<unknown>>(async () => ({ data: {}, stock: {}, forecast: {}, restock_proposal: null })),
}))

vi.mock('../api/http', () => ({
  request: mocks.request,
  jsonRequest: mocks.jsonRequest,
  ApiError: class extends Error {},
  validationErrors: () => ({}),
}))

import {
  clearSupplementIntake,
  createSupplement,
  createSupplementCourse,
  createSupplementStockMovement,
  dismissSupplementRestockProposal,
  getSupplementAdherence,
  getSupplementCourses,
  getSupplementDay,
  getSupplements,
  getSupplementStockMovements,
  updateSupplement,
  updateSupplementCourse,
  upsertSupplementIntake,
} from '../api/client'
import { supplementDisplayQuantity } from '../supplements/quantity'

describe('supplements API contract', () => {
  beforeEach(() => { mocks.request.mockClear(); mocks.jsonRequest.mockClear() })

  it('maps all thirteen operations to their owned endpoint and method', async () => {
    const reference = {
      name: 'Magnesium', category: 'vitamin' as const, form: 'capsule' as const,
      stock_unit: 'piece' as const, preferred_display_unit: 'piece' as const,
      usual_dose_quantity: '1', package_quantity: '30', restock_lead_days: 7, note: null,
    }
    const course = {
      supplement_id: 7, goal_id: null, name: null, dose_quantity: '1', dose_display_unit: 'piece' as const,
      starts_on: '2026-08-13', ends_on: '2026-08-20', is_active: true,
      schedule: { frequency: 'daily' as const, interval_count: 1, weekdays: [], cycle: null, slots: [{ slot: 'morning', time: '08:00', intake_context: 'with_food' as const }] },
    }
    await getSupplements('all')
    await createSupplement(reference)
    await updateSupplement(7, { name: 'Magnesium citrate' })
    await getSupplementCourses('active')
    await createSupplementCourse(course)
    await updateSupplementCourse(8, { is_active: false })
    await upsertSupplementIntake(9, { outcome: 'taken', dose_quantity: null, dose_display_unit: null, taken_time: '08:10', note: null })
    await clearSupplementIntake(9)
    await getSupplementStockMovements(7)
    await createSupplementStockMovement(7, { kind: 'restock', quantity: '30', display_unit: 'piece', effective_on: '2026-08-13', reason: null, note: null })
    await dismissSupplementRestockProposal(10)
    await getSupplementDay('2026-08-13')
    await getSupplementAdherence('2026-08-01', '2026-08-13')

    expect(mocks.request.mock.calls.map(([path, init]) => [path, init?.method ?? 'GET'])).toEqual([
      ['/supplements?state=all', 'GET'],
      ['/supplement-courses?state=active', 'GET'],
      ['/supplement-occurrences/9/intake', 'DELETE'],
      ['/supplements/7/stock-movements', 'GET'],
      ['/supplements/days/2026-08-13', 'GET'],
      ['/supplements/adherence?from=2026-08-01&to=2026-08-13', 'GET'],
    ])
    expect(mocks.jsonRequest.mock.calls.map(([path, method]) => [path, method])).toEqual([
      ['/supplements', 'POST'], ['/supplements/7', 'PATCH'],
      ['/supplement-courses', 'POST'], ['/supplement-courses/8', 'PATCH'],
      ['/supplement-occurrences/9/intake', 'PUT'], ['/supplements/7/stock-movements', 'POST'],
      ['/supplement-restock-proposals/10', 'PATCH'],
    ])
  })

  it('keeps exact quantities as strings and the forecast vocabulary closed', () => {
    const quantity: string = '0.001000'
    const states = ['ready', 'already_depleted', 'no_stock', 'no_active_course', 'no_consumption', 'course_ends_with_stock', 'beyond_horizon'] as const
    expect(quantity).toBe('0.001000')
    expect(new Set(states).size).toBe(7)
    expect(supplementDisplayQuantity('1.000000', 'piece')).toBe('1')
    expect(supplementDisplayQuantity('0.500000', 'mg')).toBe('500')
    expect(supplementDisplayQuantity('0.000125', 'mg')).toBe('0.125')
  })
})
