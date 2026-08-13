import assert from 'node:assert/strict'
import test from 'node:test'
import { configuredApiOrigin, normalizeApiOrigin } from '../scripts/mobile-config.mjs'

test('normalizes one public HTTPS API origin', () => {
  assert.equal(
    normalizeApiOrigin(' HTTPS://SelfHandler.Example.Test:443/ '),
    'https://selfhandler.example.test',
  )
})

test('reads the public origin from environment before the optional ignored env file', () => {
  assert.equal(
    configuredApiOrigin(
      { SELFHANDLER_MOBILE_API_ORIGIN: 'https://environment.example.test' },
      'missing-file',
    ),
    'https://environment.example.test',
  )
})

for (const value of [
  '',
  'http://selfhandler.example.test',
  'https://user:pass@selfhandler.example.test',
  'https://selfhandler.example.test/api',
  'https://selfhandler.example.test?token=secret',
  'https://localhost',
  'https://127.0.0.1',
  'https://10.0.0.1',
  'https://[fd00::1]',
  'https://selfhandler',
  'https://selfhandler.example.test/%2e%2e',
]) {
  test(`rejects unsafe API origin: ${value || '<blank>'}`, () => {
    assert.throws(() => normalizeApiOrigin(value))
  })
}
