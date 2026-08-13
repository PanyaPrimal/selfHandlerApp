import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'

const privateIpv4 = /^(?:0\.0\.0\.0$|127\.|10\.|100\.(?:6[4-9]|[7-9]\d|1[01]\d|12[0-7])\.|192\.168\.|169\.254\.|172\.(?:1[6-9]|2\d|3[01])\.)/

export function normalizeApiOrigin(value) {
  let url

  try {
    url = new URL(String(value ?? '').trim())
  } catch {
    throw new Error('SELFHANDLER_MOBILE_API_ORIGIN must be a valid URL.')
  }

  const hostname = url.hostname.toLowerCase().replace(/^\[|\]$/g, '')
  const candidate = String(value ?? '').trim()
  const afterScheme = candidate.slice(candidate.indexOf('://') + 3)
  const delimiter = afterScheme.search(/[/?#]/)
  const suffix = delimiter < 0 ? '' : afterScheme.slice(delimiter)
  if (url.protocol !== 'https:') throw new Error('The mobile API origin must use HTTPS.')
  if (url.username || url.password) throw new Error('The mobile API origin must not contain credentials.')
  if (!['', '/'].includes(suffix) || url.pathname !== '/' || url.search || url.hash) {
    throw new Error('The mobile API origin must not contain a path, query, or fragment.')
  }
  if (
    hostname === 'localhost'
    || hostname === '::1'
    || hostname.endsWith('.localhost')
    || hostname.endsWith('.local')
    || hostname.endsWith('.internal')
    || (!hostname.includes('.') && !hostname.includes(':'))
    || privateIpv4.test(hostname)
    || /^(?:fc|fd)/.test(hostname)
    || /^fe[89ab]/.test(hostname)
  ) {
    throw new Error('The mobile API origin must use a public host.')
  }

  return url.origin
}

export function configuredApiOrigin(
  environment = process.env,
  envFile = resolve(import.meta.dirname, '../.env'),
) {
  if (environment.SELFHANDLER_MOBILE_API_ORIGIN) {
    return normalizeApiOrigin(environment.SELFHANDLER_MOBILE_API_ORIGIN)
  }

  if (existsSync(envFile)) {
    const entry = readFileSync(envFile, 'utf8')
      .split(/\r?\n/)
      .map((line) => line.trim())
      .find((line) => line.startsWith('SELFHANDLER_MOBILE_API_ORIGIN='))

    if (entry) {
      const value = entry.slice(entry.indexOf('=') + 1).trim().replace(/^(['"])(.*)\1$/, '$2')
      return normalizeApiOrigin(value)
    }
  }

  return normalizeApiOrigin('')
}
