import { describe, expect, it } from 'vitest'
import { financeDraftAmount } from './money'

describe('visible budget amounts', () => {
  it('accepts grouping and decimal commas without rounding', () => {
    expect(financeDraftAmount('15 000', 'uk')).toBe('15000')
    expect(financeDraftAmount('15\u202f000,50', 'uk')).toBe('15000.50')
    expect(financeDraftAmount('15, 000', 'uk')).toBe('15000')
    expect(financeDraftAmount('15,000.50', 'en')).toBe('15000.50')
    expect(financeDraftAmount('15000,50', 'ru')).toBe('15000.50')
    expect(financeDraftAmount('999999999999999.9999', 'en')).toBe('999999999999999.9999')
  })
  it('rejects ambiguous grouping and excess precision', () => {
    for (const value of ['15,000', '1 50', '1e3', '1.00001', '1,2,3']) expect(financeDraftAmount(value, 'uk')).toBeNull()
  })
})
