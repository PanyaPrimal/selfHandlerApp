import type { Page, Route } from '@playwright/test'

export interface AiFixtureConnection {
  id: number
  name: string
  provider: 'anthropic' | 'openai'
  model: string
  key_mask: string
  parameters: { max_output_tokens: number }
  status: 'untested' | 'ready' | 'invalid'
  last_tested_at: string | null
  last_used_at: string | null
  last_error_code: string | null
  created_at: string
  updated_at: string
}

export interface AiRouteState {
  connections: AiFixtureConnection[]
  activeConnectionId: number | null
  consent: boolean
  draftCalls: number
  confirmationCalls: number
  confirmedItem: Record<string, unknown> | null
  appliedTokens: Set<string>
}

const timestamp = '2026-08-14T12:00:00Z'

export function emptyAiState(): AiRouteState {
  return {
    connections: [],
    activeConnectionId: null,
    consent: false,
    draftCalls: 0,
    confirmationCalls: 0,
    confirmedItem: null,
    appliedTokens: new Set(),
  }
}

export function readyAiState(consent = true): AiRouteState {
  return {
    ...emptyAiState(),
    connections: [connectionResource({ status: 'ready', last_tested_at: timestamp })],
    activeConnectionId: 1,
    consent,
  }
}

function connectionResource(overrides: Partial<AiFixtureConnection> = {}): AiFixtureConnection {
  return {
    id: 1,
    name: 'Private Anthropic',
    provider: 'anthropic',
    model: 'fixture-model',
    key_mask: '••••cdef',
    parameters: { max_output_tokens: 512 },
    status: 'untested',
    last_tested_at: null,
    last_used_at: null,
    last_error_code: null,
    created_at: timestamp,
    updated_at: timestamp,
    ...overrides,
  }
}

function settings(state: AiRouteState) {
  return {
    data: state.connections,
    active_connection_id: state.activeConnectionId,
    consents: {
      storage_inbox: {
        scope: 'storage_inbox',
        granted: state.consent,
        granted_at: state.consent ? timestamp : null,
        revoked_at: state.consent ? null : timestamp,
      },
    },
    providers: ['anthropic', 'openai'],
  }
}

async function fulfillJson(route: Route, json: unknown, status = 200): Promise<void> {
  await route.fulfill({ status, contentType: 'application/json', json })
}

export async function mockAiRoutes(page: Page, state: AiRouteState): Promise<void> {
  await page.route('**/api/ai/**', async (route) => {
    const request = route.request()
    const path = new URL(request.url()).pathname
    const method = request.method()
    const body = request.postDataJSON() as Record<string, unknown> | null

    if (path === '/api/ai/settings' && method === 'GET') {
      return fulfillJson(route, settings(state))
    }

    if (path === '/api/ai/connections' && method === 'POST' && body) {
      const secret = String(body.api_key ?? '')
      const resource = connectionResource({
        id: state.connections.length + 1,
        name: String(body.name),
        provider: body.provider as AiFixtureConnection['provider'],
        model: String(body.model),
        key_mask: `••••${secret.slice(-4)}`,
        parameters: body.parameters as AiFixtureConnection['parameters'],
      })
      state.connections.push(resource)
      return fulfillJson(route, { data: resource }, 201)
    }

    const match = path.match(/^\/api\/ai\/connections\/(\d+)(?:\/(test|activate))?$/)
    if (match) {
      const id = Number(match[1])
      const action = match[2]
      const index = state.connections.findIndex((connection) => connection.id === id)
      if (index < 0) return fulfillJson(route, { message: 'Not found.' }, 404)
      const current = state.connections[index]!

      if (method === 'PATCH' && !action && body) {
        const secret = typeof body.api_key === 'string' ? body.api_key : null
        state.connections[index] = {
          ...current,
          ...(body.name ? { name: String(body.name) } : {}),
          ...(body.provider ? { provider: body.provider as AiFixtureConnection['provider'] } : {}),
          ...(body.model ? { model: String(body.model) } : {}),
          ...(body.parameters ? { parameters: body.parameters as AiFixtureConnection['parameters'] } : {}),
          ...(secret ? { key_mask: `••••${secret.slice(-4)}` } : {}),
          status: 'untested',
          last_tested_at: null,
        }
        if (state.activeConnectionId === id) state.activeConnectionId = null
        return fulfillJson(route, { data: state.connections[index] })
      }

      if (method === 'POST' && action === 'test') {
        state.connections[index] = { ...current, status: 'ready', last_tested_at: timestamp }
        return fulfillJson(route, { data: state.connections[index] })
      }

      if (method === 'POST' && action === 'activate') {
        if (current.status !== 'ready') return fulfillJson(route, { message: 'Test this connection first.' }, 409)
        state.activeConnectionId = id
        return fulfillJson(route, settings(state))
      }

      if (method === 'DELETE' && !action) {
        state.connections.splice(index, 1)
        if (state.activeConnectionId === id) state.activeConnectionId = null
        await route.fulfill({ status: 204, body: '' })
        return
      }
    }

    if (path === '/api/ai/consents/storage-inbox' && method === 'PUT' && body) {
      state.consent = body.granted === true
      return fulfillJson(route, { data: settings(state).consents.storage_inbox })
    }

    if (path === '/api/ai/scenarios/storage-inbox/draft' && method === 'POST' && body) {
      if (!state.activeConnectionId || !state.consent) {
        return fulfillJson(route, { message: 'Finish AI setup first.', code: 'ai_consent_required' }, 409)
      }
      state.draftCalls += 1
      const locale = request.headers()['accept-language']?.split(',')[0]?.toLowerCase() ?? 'en'
      const rationale = locale.startsWith('ru')
        ? 'Это конкретная задача с заметным сроком.'
        : locale.startsWith('uk')
          ? 'Це конкретне завдання з помітним строком.'
          : 'This looks actionable and time-sensitive.'
      return fulfillJson(route, { data: {
        item_id: Number(body.item_id),
        proposal: {
          type: 'task',
          project_id: null,
          tags: ['next-step'],
          priority: 'high',
          due_on: '2026-08-20',
          rationale,
        },
        provider: 'anthropic',
        model: 'fixture-model',
        confirmation_token: `fixture-confirmation-${state.draftCalls}`,
        expires_at: '2099-08-14T12:10:00Z',
        shared_scope: 'storage_inbox',
      } })
    }

    if (path === '/api/ai/scenarios/storage-inbox/confirm' && method === 'POST' && body) {
      state.confirmationCalls += 1
      const token = String(body.confirmation_token ?? '')
      if (state.appliedTokens.has(token)) {
        return fulfillJson(route, { message: 'This proposal was already used.', code: 'ai_confirmation_replayed' }, 409)
      }
      if (!state.confirmedItem) return fulfillJson(route, { message: 'The proposal is stale.' }, 409)
      state.appliedTokens.add(token)
      return fulfillJson(route, { data: state.confirmedItem })
    }

    return fulfillJson(route, { message: `Unhandled AI fixture route: ${method} ${path}` }, 500)
  })
}
