import type { Page, Route } from '@playwright/test'

type Provider = 'google_calendar' | 'apple_calendar'

interface FixtureIntegration {
  id: number
  provider: Provider
  status: 'pending' | 'active'
  account: string
  calendar: null | { name: string, timezone: string, writable: boolean }
  settings: { import_detail: 'busy_only' | 'title', export_categories: string[] }
  last_sync_at: string | null
  last_success_at: string | null
  last_error_code: null
}

export interface CalendarRouteState {
  google: FixtureIntegration | null
  apple: FixtureIntegration | null
  failNextSync: boolean
}

function pending(id: number, provider: Provider, account: string): FixtureIntegration {
  return {
    id,
    provider,
    status: 'pending',
    account,
    calendar: null,
    settings: { import_detail: 'busy_only', export_categories: [] },
    last_sync_at: null,
    last_success_at: null,
    last_error_code: null,
  }
}

function descriptors(provider: Provider) {
  return [{
    id: provider === 'google_calendar' ? 'primary@example.test' : 'https://caldav.icloud.test/calendars/personal/',
    name: provider === 'google_calendar' ? 'Personal Google calendar' : 'Personal Apple calendar',
    timezone: 'Europe/Kyiv',
    writable: true,
    is_default: true,
  }]
}

async function json(route: Route, status: number, body: unknown): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
}

export async function mockCalendarRoutes(page: Page, state: CalendarRouteState): Promise<void> {
  await page.route('**/api/integrations/calendars**', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const path = url.pathname
    const method = request.method()

    if (path === '/api/integrations/calendars' && method === 'GET') {
      await json(route, 200, {
        data: [state.google, state.apple].filter(Boolean),
        providers: [
          { provider: 'google_calendar', available: true, connection_mode: 'oauth_browser', android_connect_supported: false, unavailable_code: null },
          { provider: 'apple_calendar', available: true, connection_mode: 'app_specific_password', android_connect_supported: true, unavailable_code: null },
        ],
      })
      return
    }
    if (path.endsWith('/google/authorize') && method === 'POST') {
      state.google = pending(41, 'google_calendar', 'o***@example.test')
      await json(route, 200, {
        authorization_url: '/settings/integrations?calendar=oauth_connected',
        expires_at: '2026-08-14T12:10:00Z',
      })
      return
    }
    if (path.endsWith('/apple/connect') && method === 'POST') {
      state.apple = pending(42, 'apple_calendar', 'a***@icloud.test')
      await json(route, 201, { data: state.apple, calendars: descriptors('apple_calendar') })
      return
    }

    const match = path.match(/^\/api\/integrations\/calendars\/(41|42)(?:\/(calendars|selection|sync))?$/)
    if (!match) {
      await route.fallback()
      return
    }
    const integration = match[1] === '41' ? state.google : state.apple
    if (!integration) {
      await json(route, 404, { message: 'Not found.' })
      return
    }
    if (match[2] === 'calendars' && method === 'GET') {
      await json(route, 200, { data: descriptors(integration.provider) })
      return
    }
    if (match[2] === 'selection' && method === 'PUT') {
      const descriptor = descriptors(integration.provider)[0]!
      integration.status = 'active'
      integration.calendar = { name: descriptor.name, timezone: descriptor.timezone, writable: true }
      await json(route, 200, { data: integration })
      return
    }
    if (!match[2] && method === 'PATCH') {
      const update = request.postDataJSON() as FixtureIntegration['settings']
      integration.settings = { ...integration.settings, ...update }
      await json(route, 200, { data: integration })
      return
    }
    if (match[2] === 'sync' && method === 'POST') {
      if (state.failNextSync) {
        state.failNextSync = false
        await json(route, 502, { message: 'The provider did not respond in time.', code: 'calendar_provider_timeout' })
        return
      }
      integration.last_sync_at = '2026-08-14T12:00:00Z'
      integration.last_success_at = '2026-08-14T12:00:00Z'
      await json(route, 200, { data: {
        imported: 1, updated: 0, removed: 0, exported: 1,
        deleted: 0, conflicts: 0, unchanged: 2, completed_at: '2026-08-14T12:00:00Z',
      } })
      return
    }
    if (!match[2] && method === 'DELETE') {
      if (integration.provider === 'google_calendar') state.google = null
      else state.apple = null
      await route.fulfill({ status: 204 })
      return
    }

    await json(route, 405, { message: 'Method not allowed.' })
  })
}

export function activeCalendarState(): CalendarRouteState {
  const google = pending(41, 'google_calendar', 'o***@example.test')
  google.status = 'active'
  google.calendar = { name: 'Personal Google calendar', timezone: 'Europe/Kyiv', writable: true }
  return { google, apple: null, failNextSync: false }
}
