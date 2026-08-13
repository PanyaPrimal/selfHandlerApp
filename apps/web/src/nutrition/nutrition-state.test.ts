import { describe, expect, it, vi } from 'vitest'
import { createNutritionMutationQueue, nutritionProgressPercent, nutritionTargetCopyKey } from './nutrition-state'

describe('nutrition client state', () => {
  it('keeps unavailable target progress distinct from consumed zero', () => {
    expect(nutritionProgressPercent(0, null)).toBeNull()
    expect(nutritionProgressPercent(0, 2000)).toBe('0.00')
    expect(nutritionProgressPercent(825, 1946)).toBe('42.39')
  })

  it('maps only explicit target states to product copy keys', () => {
    expect(nutritionTargetCopyKey('ready')).toBe('nutrition.target.ready')
    expect(nutritionTargetCopyKey('incomplete')).toBe('nutrition.target.incomplete')
  })

  it('serializes accepted mutations instead of dropping rapid corrections', async () => {
    const order: string[] = []
    const first = vi.fn(async () => {
      order.push('first:start')
      await Promise.resolve()
      order.push('first:end')
      return 1
    })
    const second = vi.fn(async () => {
      order.push('second:start')
      order.push('second:end')
      return 2
    })
    const queue = createNutritionMutationQueue()

    const values = await Promise.all([queue(first), queue(second)])

    expect(values).toEqual([1, 2])
    expect(order).toEqual(['first:start', 'first:end', 'second:start', 'second:end'])
  })

  it('continues after a rejected mutation so the recoverable draft can be retried', async () => {
    const queue = createNutritionMutationQueue()
    await expect(queue(async () => { throw new Error('rejected') })).rejects.toThrow('rejected')
    await expect(queue(async () => 'accepted')).resolves.toBe('accepted')
  })
})
