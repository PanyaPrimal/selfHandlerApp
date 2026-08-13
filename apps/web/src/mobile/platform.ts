import { Capacitor } from '@capacitor/core'

export class MobileConfigurationError extends Error {
  constructor(message: string) {
    super(message)
    this.name = 'MobileConfigurationError'
  }
}

function isPrivateHost(hostname: string): boolean {
  const host = hostname.toLowerCase().replace(/^\[|\]$/g, '')

  return host === 'localhost'
    || host === '0.0.0.0'
    || host === '::1'
    || host.endsWith('.localhost')
    || host.endsWith('.local')
    || host.endsWith('.internal')
    || (!host.includes('.') && !host.includes(':'))
    || /^127\./.test(host)
    || /^10\./.test(host)
    || /^100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./.test(host)
    || /^192\.168\./.test(host)
    || /^169\.254\./.test(host)
    || /^172\.(1[6-9]|2\d|3[01])\./.test(host)
    || /^(?:fc|fd)/.test(host)
    || /^fe[89ab]/.test(host)
}

export function normalizeMobileApiOrigin(value: string): string {
  const candidate = value.trim()
  let url: URL

  try {
    url = new URL(candidate)
  } catch {
    throw new MobileConfigurationError('The mobile API origin must be a valid URL.')
  }

  const afterScheme = candidate.slice(candidate.indexOf('://') + 3)
  const delimiter = afterScheme.search(/[/?#]/)
  const suffix = delimiter < 0 ? '' : afterScheme.slice(delimiter)

  if (url.protocol !== 'https:') {
    throw new MobileConfigurationError('The mobile API origin must use HTTPS.')
  }

  if (url.username || url.password || !['', '/'].includes(suffix) || url.pathname !== '/' || url.search || url.hash) {
    throw new MobileConfigurationError('The mobile API origin must not contain credentials, a path, query, or fragment.')
  }

  if (isPrivateHost(url.hostname)) {
    throw new MobileConfigurationError('The mobile API origin must be a public host.')
  }

  return url.origin
}

export function mobileApiBaseUrl(origin: string): string {
  return `${normalizeMobileApiOrigin(origin)}/api`
}

export function isAndroidNative(): boolean {
  const runtime = (globalThis as any).Capacitor ?? Capacitor
  return runtime.isNativePlatform() && runtime.getPlatform() === 'android'
}

export function nativePlugin<T>(name: string, fallback: T): T {
  return ((globalThis as any).__androidTest?.plugins?.[name] as T | undefined)
    ?? ((globalThis as any).Capacitor?.Plugins?.[name] as T | undefined)
    ?? fallback
}

export function configuredMobileApiOrigin(): string {
  return normalizeMobileApiOrigin(import.meta.env.VITE_MOBILE_API_ORIGIN ?? '')
}
