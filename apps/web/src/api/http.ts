import { activeLocaleValue, translate } from '../i18n'

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api'
const csrfUrl = import.meta.env.VITE_CSRF_URL ?? '/sanctum/csrf-cookie'

export type ValidationErrors = Record<string, string[]>

export interface RequestBehavior {
  handleUnauthorized?: boolean
  retryCsrf?: boolean
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

let unauthorizedHandler: (() => void) | null = null
let csrfReady = false
let csrfRequest: Promise<void> | null = null

export function setUnauthorizedHandler(handler: (() => void) | null): void {
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

async function parsePayload(response: Response): Promise<unknown> {
  if (response.status === 204) {
    return null
  }

  const text = await response.text()

  if (!text) {
    return null
  }

  try {
    return JSON.parse(text) as unknown
  } catch {
    return { message: text }
  }
}

function retryAfterSeconds(response: Response): number | null {
  const value = response.headers.get('Retry-After')

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

    const payload = await parsePayload(response)

    if (!response.ok) {
      throw new ApiError(
        responseMessage(payload, translate('common.errorInitProtection')),
        response.status,
        payload,
        retryAfterSeconds(response),
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

  if (unsafe) {
    await initializeCsrf()
  }

  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Accept-Language', activeLocaleValue())

  if (init.body !== undefined && init.body !== null && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  if (unsafe) {
    const token = readCookie('XSRF-TOKEN')

    if (token) {
      headers.set('X-XSRF-TOKEN', token)
    }
  }

  let response: Response

  try {
    response = await fetch(`${apiBaseUrl}${path}`, {
      ...init,
      method,
      headers,
      credentials: 'same-origin',
    })
  } catch (cause) {
    throw new ApiError(translate('common.errorReach'), 0, null, null, cause)
  }

  if (response.status === 419 && unsafe && behavior.retryCsrf !== false && !csrfRetried) {
    await initializeCsrf(true)
    return executeRequest<T>(path, init, behavior, true)
  }

  const payload = await parsePayload(response)

  if (!response.ok) {
    const error = new ApiError(
      responseMessage(payload, translate('common.errorApiStatus', { status: response.status })),
      response.status,
      payload,
      retryAfterSeconds(response),
    )

    if (response.status === 401 && behavior.handleUnauthorized !== false) {
      unauthorizedHandler?.()
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
