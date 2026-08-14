import { CapacitorHttp } from '@capacitor/core'
import { activeLocaleValue, translate } from '../i18n'
import { mobileCredentialVault } from '../mobile/credential-vault'
import { createNativeTransport, type TransportResponse } from '../mobile/native-transport'
import { configuredMobileApiOrigin, isAndroidNative, nativePlugin } from '../mobile/platform'
import { contentDispositionFilename, type DownloadedFile } from '../portability/files'

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api'
const csrfUrl = import.meta.env.VITE_CSRF_URL ?? '/sanctum/csrf-cookie'

export type ValidationErrors = Record<string, string[]>

export interface RequestBehavior {
  handleUnauthorized?: boolean
  retryCsrf?: boolean
  mobileAuthenticated?: boolean
}

export class ApiError extends Error {
  public readonly status: number
  public readonly payload: unknown
  public readonly retryAfter: number | null

  constructor(
    message: string,
    status: number,
    payload: unknown = null,
    retryAfter: number | null = null,
    cause?: unknown,
  ) {
    super(message, cause === undefined ? undefined : { cause })
    this.name = 'ApiError'
    this.status = status
    this.payload = payload
    this.retryAfter = retryAfter
  }
}

let unauthorizedHandler: (() => void | Promise<void>) | null = null
let csrfReady = false
let csrfRequest: Promise<void> | null = null

export function setUnauthorizedHandler(handler: (() => void | Promise<void>) | null): void {
  unauthorizedHandler = handler
}

export function resetCsrfProtection(): void {
  csrfReady = false
  csrfRequest = null
}

function isUnsafeMethod(method: string): boolean {
  return !['GET', 'HEAD', 'OPTIONS'].includes(method)
}

function readCookie(name: string): string | null {
  const prefix = `${encodeURIComponent(name)}=`
  const cookie = document.cookie
    .split(';')
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix))

  if (!cookie) {
    return null
  }

  try {
    return decodeURIComponent(cookie.slice(prefix.length))
  } catch {
    return cookie.slice(prefix.length)
  }
}

function parsePayload(response: TransportResponse): unknown {
  if (response.status === 204) {
    return null
  }

  const text = response.body

  if (!text) {
    return null
  }

  try {
    return JSON.parse(text) as unknown
  } catch {
    return { message: text }
  }
}

function retryAfterSeconds(response: TransportResponse): number | null {
  const entry = Object.entries(response.headers)
    .find(([key]) => key.toLowerCase() === 'retry-after')
  const value = entry?.[1]

  if (!value) {
    return null
  }

  const seconds = Number.parseInt(value, 10)
  return Number.isFinite(seconds) && seconds > 0 ? seconds : null
}

function responseMessage(payload: unknown, fallback: string): string {
  if (typeof payload === 'object' && payload !== null && 'message' in payload) {
    const message = (payload as { message?: unknown }).message

    if (typeof message === 'string' && message.trim()) {
      return message
    }
  }

  return fallback
}

async function initializeCsrf(force = false): Promise<void> {
  if (force) {
    csrfReady = false
  }

  if (csrfReady && readCookie('XSRF-TOKEN')) {
    return
  }

  if (csrfRequest) {
    return csrfRequest
  }

  const request = (async () => {
    let response: Response

    try {
      response = await fetch(csrfUrl, {
        method: 'GET',
        headers: {
          Accept: 'application/json',
          'Accept-Language': activeLocaleValue(),
        },
        credentials: 'same-origin',
      })
    } catch (cause) {
      throw new ApiError(translate('common.errorReach'), 0, null, null, cause)
    }

    const normalizedResponse = {
      status: response.status,
      body: await response.text(),
      headers: Object.fromEntries(response.headers.entries()),
    }
    const payload = parsePayload(normalizedResponse)

    if (!response.ok) {
      throw new ApiError(
        responseMessage(payload, translate('common.errorInitProtection')),
        response.status,
        payload,
        retryAfterSeconds(normalizedResponse),
      )
    }

    if (!readCookie('XSRF-TOKEN')) {
      throw new ApiError(translate('common.errorInitProtection'), 419)
    }

    csrfReady = true
  })()

  csrfRequest = request

  try {
    await request
  } finally {
    if (csrfRequest === request) {
      csrfRequest = null
    }
  }
}

async function executeRequest<T>(
  path: string,
  init: RequestInit,
  behavior: RequestBehavior,
  csrfRetried: boolean,
): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  const unsafe = isUnsafeMethod(method)
  const native = isAndroidNative()

  if (unsafe && !native) {
    await initializeCsrf()
  }

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Accept-Language', activeLocaleValue())

  if (init.body !== undefined && init.body !== null && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  if (unsafe && !native) {
    const token = readCookie('XSRF-TOKEN')

    if (token) {
      headers.set('X-XSRF-TOKEN', token)
    }
  }

  let response: TransportResponse

  try {
    if (native) {
      const transport = createNativeTransport(
        configuredMobileApiOrigin(),
        mobileCredentialVault,
        nativePlugin('CapacitorHttp', CapacitorHttp),
      )
      response = await transport(
        path,
        { ...init, method, headers },
        behavior.mobileAuthenticated !== false,
      )
    } else {
      const browserResponse = await fetch(`${apiBaseUrl}${path}`, {
        ...init,
        method,
        headers,
        credentials: 'same-origin',
      })
      response = {
        status: browserResponse.status,
        body: await browserResponse.text(),
        headers: Object.fromEntries(browserResponse.headers.entries()),
      }
    }
  } catch (cause) {
    throw new ApiError(translate('common.errorReach'), 0, null, null, cause)
  }

  if (response.status === 419 && unsafe && !native && behavior.retryCsrf !== false && !csrfRetried) {
    await initializeCsrf(true)
    return executeRequest<T>(path, init, behavior, true)
  }

  const payload = parsePayload(response)

  if (response.status < 200 || response.status >= 300) {
    const error = new ApiError(
      responseMessage(payload, translate('common.errorApiStatus', { status: response.status })),
      response.status,
      payload,
      retryAfterSeconds(response),
    )

    if (response.status === 401 && behavior.handleUnauthorized !== false) {
      await unauthorizedHandler?.()
    }

    throw error
  }

  if (response.status === 204) {
    return undefined as T
  }

  return payload as T
}

export function request<T>(path: string, init: RequestInit = {}, behavior: RequestBehavior = {}): Promise<T> {
  return executeRequest<T>(path, init, behavior, false)
}

export function jsonRequest<T>(
  path: string,
  method: string,
  body: unknown,
  behavior: RequestBehavior = {},
): Promise<T> {
  return request<T>(
    path,
    {
      method,
      body: JSON.stringify(body),
    },
    behavior,
  )
}

function fetchApiUrl(path: string, native: boolean): string {
  if (!path.startsWith('/') || path.startsWith('//') || /(^|\/)\.\.?($|\/)/.test(decodeURIComponent(path.split('?')[0] ?? ''))) {
    throw new TypeError('API file requests require a safe relative path.')
  }

  return native
    ? `${configuredMobileApiOrigin()}/api${path}`
    : `${apiBaseUrl}${path}`
}

async function executeFileRequest(
  path: string,
  init: RequestInit,
  behavior: RequestBehavior,
  csrfRetried: boolean,
): Promise<Response> {
  const method = (init.method ?? 'GET').toUpperCase()
  const unsafe = isUnsafeMethod(method)
  const native = isAndroidNative()

  if (unsafe && !native) await initializeCsrf()

  const headers = new Headers(init.headers)
  headers.set('Accept-Language', activeLocaleValue())
  if (!headers.has('Accept')) headers.set('Accept', 'application/json')

  if (unsafe && !native) {
    const csrfToken = readCookie('XSRF-TOKEN')
    if (csrfToken) headers.set('X-XSRF-TOKEN', csrfToken)
  }

  if (native && behavior.mobileAuthenticated !== false) {
    const token = await mobileCredentialVault.read()
    if (!token) throw new ApiError('The Android session is not available.', 401)
    headers.set('Authorization', `Bearer ${token}`)
  }

  let response: Response
  try {
    response = await fetch(fetchApiUrl(path, native), {
      ...init,
      method,
      headers,
      credentials: native ? 'omit' : 'same-origin',
      cache: 'no-store',
    })
  } catch (cause) {
    throw new ApiError(translate('common.errorReach'), 0, null, null, cause)
  }

  if (response.status === 419 && unsafe && !native && behavior.retryCsrf !== false && !csrfRetried) {
    await initializeCsrf(true)
    return executeFileRequest(path, init, behavior, true)
  }

  if (!response.ok) {
    const normalized = {
      status: response.status,
      body: await response.text(),
      headers: Object.fromEntries(response.headers.entries()),
    }
    const payload = parsePayload(normalized)
    const error = new ApiError(
      responseMessage(payload, translate('common.errorApiStatus', { status: response.status })),
      response.status,
      payload,
      retryAfterSeconds(normalized),
    )

    if (response.status === 401 && behavior.handleUnauthorized !== false) await unauthorizedHandler?.()
    throw error
  }

  return response
}

export async function downloadRequest(
  path: string,
  fallbackFilename: string,
  accept: string,
  behavior: RequestBehavior = {},
): Promise<DownloadedFile> {
  const response = await executeFileRequest(path, { headers: { Accept: accept } }, behavior, false)

  return {
    blob: await response.blob(),
    filename: contentDispositionFilename(response.headers.get('Content-Disposition'), fallbackFilename),
  }
}

export async function multipartRequest<T>(
  path: string,
  form: FormData,
  behavior: RequestBehavior = {},
): Promise<T> {
  const response = await executeFileRequest(path, { method: 'POST', body: form }, behavior, false)
  if (response.status === 204) return undefined as T

  const text = await response.text()
  if (!text) return null as T

  try {
    return JSON.parse(text) as T
  } catch (cause) {
    throw new ApiError(translate('common.errorApiStatus', { status: response.status }), response.status, null, null, cause)
  }
}

export function validationErrors(error: unknown): ValidationErrors {
  if (!(error instanceof ApiError) || typeof error.payload !== 'object' || error.payload === null) {
    return {}
  }

  const errors = (error.payload as { errors?: unknown }).errors

  if (typeof errors !== 'object' || errors === null) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(errors).flatMap(([field, messages]) => {
      if (!Array.isArray(messages)) {
        return []
      }

      const safeMessages = messages.filter((message): message is string => typeof message === 'string')
      return safeMessages.length > 0 ? [[field, safeMessages]] : []
    }),
  )
}
