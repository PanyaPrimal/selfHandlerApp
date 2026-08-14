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
  activateLlmConnection,
  confirmInboxTriageDraft,
  createInboxTriageDraft,
  createLlmConnection,
  deleteLlmConnection,
  getAiSettings,
  replaceStorageInboxConsent,
  testLlmConnection,
  updateLlmConnection,
} from '../api/client'

describe('AI assistant API contracts', () => {
  beforeEach(() => vi.clearAllMocks())

  it('uses the closed settings and connection lifecycle routes', async () => {
    mocks.request.mockResolvedValue({ data: [], active_connection_id: null, consents: {}, providers: [] })
    mocks.jsonRequest.mockResolvedValue({ data: { id: 7 } })

    await getAiSettings()
    await createLlmConnection({
      name: 'Personal Anthropic',
      provider: 'anthropic',
      model: 'owner-model',
      api_key: 'secret-key',
      parameters: { max_output_tokens: 512 },
    })
    await updateLlmConnection(7, { name: 'Renamed' })
    await testLlmConnection(7)
    await activateLlmConnection(7)
    await deleteLlmConnection(7)

    expect(mocks.request).toHaveBeenNthCalledWith(1, '/ai/settings')
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(1, '/ai/connections', 'POST', {
      name: 'Personal Anthropic',
      provider: 'anthropic',
      model: 'owner-model',
      api_key: 'secret-key',
      parameters: { max_output_tokens: 512 },
    })
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(2, '/ai/connections/7', 'PATCH', { name: 'Renamed' })
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(3, '/ai/connections/7/test', 'POST', {})
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(4, '/ai/connections/7/activate', 'POST', {})
    expect(mocks.request).toHaveBeenNthCalledWith(2, '/ai/connections/7', { method: 'DELETE' })
  })

  it('keeps consent, proposal and confirmation as separate explicit requests', async () => {
    mocks.jsonRequest.mockResolvedValue({ data: {} })

    await replaceStorageInboxConsent(true)
    await createInboxTriageDraft(41)
    await confirmInboxTriageDraft('encrypted-confirmation-token')

    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(1, '/ai/consents/storage-inbox', 'PUT', { granted: true })
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(2, '/ai/scenarios/storage-inbox/draft', 'POST', { item_id: 41 })
    expect(mocks.jsonRequest).toHaveBeenNthCalledWith(3, '/ai/scenarios/storage-inbox/confirm', 'POST', {
      confirmation_token: 'encrypted-confirmation-token',
    })
  })

  it('returns only the server resource and never synthesizes a stored key', async () => {
    mocks.jsonRequest.mockResolvedValue({ data: {
      id: 3,
      name: 'OpenAI',
      provider: 'openai',
      model: 'owner-model',
      key_mask: '•••1234',
      parameters: { max_output_tokens: 512 },
      status: 'untested',
    } })

    const connection = await createLlmConnection({
      name: 'OpenAI', provider: 'openai', model: 'owner-model', api_key: 'private-value',
      parameters: { max_output_tokens: 512 },
    })

    expect(connection).not.toHaveProperty('api_key')
    expect(connection.key_mask).toBe('•••1234')
  })
})
