import { describe, expect, it } from 'vitest'
import { mobileApiBaseUrl, normalizeMobileApiOrigin } from './platform'

describe('mobile API origin', () => {
  it('normalizes a public HTTPS origin and derives the API base', () => {
    expect(normalizeMobileApiOrigin(' HTTPS://SelfHandler.Example.Test:443/ '))
      .toBe('https://selfhandler.example.test')
    expect(mobileApiBaseUrl('https://selfhandler.example.test'))
      .toBe('https://selfhandler.example.test/api')
  })

  it.each([
    '',
    'http://selfhandler.example.test',
    'https://user:password@selfhandler.example.test',
    'https://selfhandler.example.test/api',
    'https://selfhandler.example.test?token=secret',
    'https://selfhandler.example.test/#fragment',
    'https://localhost',
    'https://127.0.0.1:8443',
    'https://10.0.0.1',
    'https://[fd00::1]',
    'https://selfhandler',
    'https://selfhandler.example.test/%2e%2e',
    'not a url',
  ])('rejects unsafe value %s', (value) => {
    expect(() => normalizeMobileApiOrigin(value)).toThrow()
  })
})
