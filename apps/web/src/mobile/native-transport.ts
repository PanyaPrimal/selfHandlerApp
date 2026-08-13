import type { MobileCredentialVault } from './credential-vault'
import { mobileApiBaseUrl } from './platform'

interface NativeHttpResponse {
  status: number
  data: unknown
  headers: Record<string, string>
}

interface NativeHttp {
  request(options: any): Promise<NativeHttpResponse>
}

export interface TransportResponse {
  status: number
  body: string
  headers: Record<string, string>
}

function safeApiPath(path: string): string {
  if (!path.startsWith('/') || path.startsWith('//') || /(^|\/)\.\.?($|\/)/.test(decodeURIComponent(path.split('?')[0] ?? ''))) {
    throw new TypeError('Native API requests require a safe relative API path.')
  }

  const parsed = new URL(path, 'https://selfhandler.invalid')
  if (parsed.origin !== 'https://selfhandler.invalid') {
    throw new TypeError('Native API requests cannot change origin.')
  }

  return `${parsed.pathname}${parsed.search}`
}

export function createNativeTransport(
  origin: string,
  vault: Pick<MobileCredentialVault, 'read'>,
  http: NativeHttp,
) {
  const baseUrl = mobileApiBaseUrl(origin)

  return async function nativeTransport(
    path: string,
    init: RequestInit = {},
    authenticated = true,
  ): Promise<TransportResponse> {
    const headers: Record<string, string> = {}
    new Headers(init.headers).forEach((value, key) => {
      const name = key === 'accept-language'
        ? 'Accept-Language'
        : key === 'content-type' ? 'Content-Type' : key === 'accept' ? 'Accept' : key
      headers[name] = value
    })

    if (authenticated) {
      const token = await vault.read()
      if (!token) {
        throw new Error('The Android session is not available.')
      }
      headers.Authorization = `Bearer ${token}`
    }

    const options: Record<string, unknown> = {
      url: `${baseUrl}${safeApiPath(path)}`,
      method: (init.method ?? 'GET').toUpperCase(),
      headers,
    }

    if (typeof init.body === 'string' && init.body.length > 0) {
      const contentType = new Headers(init.headers).get('Content-Type')
      options.data = contentType?.includes('application/json') ? JSON.parse(init.body) : init.body
    }

    const response = await http.request(options)

    return {
      status: response.status,
      body: response.data === null || response.data === undefined
        ? ''
        : typeof response.data === 'string' ? response.data : JSON.stringify(response.data),
      headers: response.headers ?? {},
    }
  }
}
