import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  request: vi.fn<(path: string, init?: RequestInit) => Promise<unknown>>(async () => ({ data: {} })),
  jsonRequest: vi.fn<(path: string, method: string, body: unknown) => Promise<unknown>>(async () => ({ data: {} })),
}))

vi.mock('../api/http', () => ({
  request: mocks.request,
  jsonRequest: mocks.jsonRequest,
  ApiError: class extends Error {},
  validationErrors: () => ({}),
}))

import {
  getDailyReviewWorkspace,
  getPeriodicReviewWorkspace,
  savePeriodicReview,
} from '../api/client'

describe('Review workspace API contract', () => {
  beforeEach(() => {
    mocks.request.mockClear()
    mocks.jsonRequest.mockClear()
  })

  it('maps daily, weekly, and monthly reads to canonical routes', async () => {
    await getDailyReviewWorkspace('2026-08-14')
    await getPeriodicReviewWorkspace('weekly', '2026-08-14')
    await getPeriodicReviewWorkspace('monthly', '2026-02-29')

    expect(mocks.request.mock.calls.map(([path]) => path)).toEqual([
      '/review-workspaces/daily/2026-08-14',
      '/periodic-reviews/weekly/2026-08-14',
      '/periodic-reviews/monthly/2026-02-29',
    ])
  })

  it('keeps the periodic save payload on the authenticated PUT boundary', async () => {
    const payload = {
      period_rating: 8 as const,
      worked_well: 'Protected focus time',
      next_focus: 'Keep mornings quiet',
    }

    await savePeriodicReview('weekly', '2026-08-14', payload)

    expect(mocks.jsonRequest).toHaveBeenCalledWith(
      '/periodic-reviews/weekly/2026-08-14',
      'PUT',
      payload,
    )
  })
})
