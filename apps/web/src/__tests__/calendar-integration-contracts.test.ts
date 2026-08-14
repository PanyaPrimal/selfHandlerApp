import { beforeEach, describe, expect, it, vi } from 'vitest'

const mocks = vi.hoisted(() => ({
  request: vi.fn(),
  jsonRequest: vi.fn(),
}))

vi.mock('../api/http', () => ({
  request: mocks.request,
  jsonRequest: mocks.jsonRequest,
  downloadRequest: vi.fn(),
  multipartRequest: vi.fn(),
  ApiError: class ApiError extends Error {},
  validationErrors: vi.fn(() => ({})),
}))

import {
  connectAppleCalendar,
  disconnectCalendar,
  getCalendarIntegrations,
  getProviderCalendars,
  selectProviderCalendar,
  startGoogleCalendarAuthorization,
  syncCalendar,
  updateCalendarSettings,
} from '../api/client'

describe('calendar integration API contracts', () => {
  beforeEach(() => vi.clearAllMocks())

  it('uses closed owner-scoped list, authorization, discovery and selection routes', async () => {
    mocks.request.mockResolvedValueOnce({ data: [], providers: [] })
    mocks.jsonRequest.mockResolvedValue({ data: { id: 7 } })
    mocks.request.mockResolvedValueOnce({ data: [{ id: 'primary' }] })

    await getCalendarIntegrations()
    await startGoogleCalendarAuthorization()
    await getProviderCalendars(7)
    await selectProviderCalendar(7, 'primary')

    expect(mocks.request).toHaveBeenNthCalledWith(1, '/integrations/calendars')
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(1, '/integrations/calendars/google/authorize', 'POST', {})
    expect(mocks.request).toHaveBeenNthCalledWith(2, '/integrations/calendars/7/calendars')
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(
      2,
      '/integrations/calendars/7/selection',
      'PUT',
      { calendar_id: 'primary' },
    )
  })

  it('never retains Apple password and sends exact settings, sync and disconnect bodies', async () => {
    mocks.jsonRequest.mockResolvedValue({ data: { id: 9 }, calendars: [] })

    const secret = 'abcd-efgh-ijkl-mnop'
    await connectAppleCalendar('owner@example.test', secret)
    await updateCalendarSettings(9, {
      import_detail: 'title',
      export_categories: ['time_block', 'finance'],
    })
    await syncCalendar(9)
    await disconnectCalendar(9)

    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(
      1,
      '/integrations/calendars/apple/connect',
      'POST',
      { account: 'owner@example.test', app_specific_password: secret },
    )
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(
      2,
      '/integrations/calendars/9',
      'PATCH',
      { import_detail: 'title', export_categories: ['time_block', 'finance'] },
    )
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(3, '/integrations/calendars/9/sync', 'POST', {})
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(
      4,
      '/integrations/calendars/9',
      'DELETE',
      { confirmation: 'DISCONNECT' },
    )
  })
})
