import { describe, expect, it, vi } from 'vitest'
import { createNativeTransport } from './native-transport'

describe('native HTTP transport', () => {
  it('uses the configured HTTPS API and reads the bearer just in time', async () => {
    const vault = { read: vi.fn().mockResolvedValue('device-token') }
    const http = {
      request: vi.fn().mockResolvedValue({
        status: 200,
        data: { data: [{ id: 1 }] },
        headers: { 'retry-after': '7' },
      }),
    }
    const transport = createNativeTransport('https://selfhandler.example.test', vault, http)

    const response = await transport('/notifications', {
      method: 'GET',
      headers: new Headers({ Accept: 'application/json', 'Accept-Language': 'uk-UA' }),
    })

    expect(vault.read).toHaveBeenCalledOnce()
    expect(http.request).toHaveBeenCalledWith(expect.objectContaining({
      url: 'https://selfhandler.example.test/api/notifications',
      method: 'GET',
      headers: expect.objectContaining({
        Authorization: 'Bearer device-token',
        'Accept-Language': 'uk-UA',
      }),
    }))
    expect(response).toEqual({
      status: 200,
      body: JSON.stringify({ data: [{ id: 1 }] }),
      headers: { 'retry-after': '7' },
    })
  })

  it('sends parsed JSON data without cookies or browser credential options', async () => {
    const http = { request: vi.fn().mockResolvedValue({ status: 204, data: null, headers: {} }) }
    const transport = createNativeTransport(
      'https://selfhandler.example.test',
      { read: vi.fn().mockResolvedValue('token') },
      http,
    )

    await transport('/routines', {
      method: 'POST',
      headers: new Headers({ 'Content-Type': 'application/json' }),
      body: JSON.stringify({ name: 'Morning walk' }),
      credentials: 'same-origin',
    })

    const options = http.request.mock.calls[0]?.[0]
    expect(options.data).toEqual({ name: 'Morning walk' })
    expect(options).not.toHaveProperty('credentials')
    expect(JSON.stringify(options)).not.toContain('XSRF-TOKEN')
  })

  it('refuses paths that could escape the configured API base', async () => {
    const transport = createNativeTransport(
      'https://selfhandler.example.test',
      { read: vi.fn().mockResolvedValue('token') },
      { request: vi.fn() },
    )

    await expect(transport('https://evil.example/api', {})).rejects.toThrow()
    await expect(transport('//evil.example/api', {})).rejects.toThrow()
    await expect(transport('/../admin', {})).rejects.toThrow()
  })
})
