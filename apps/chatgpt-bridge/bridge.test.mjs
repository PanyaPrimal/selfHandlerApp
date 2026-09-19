import { test } from 'node:test'
import assert from 'node:assert/strict'
import { once } from 'node:events'
import { accountPath, childEnvironment } from './accounts.mjs'
import { parseAnswer, requireSubscriptionAllowance } from './protocol.mjs'
import { createServer } from './server.mjs'

test('accounts cannot escape their directory and children do not inherit credentials', () => {
  for (const bad of ['../other', '/etc', '1', 'a'.repeat(65), 'A'.repeat(64)]) assert.throws(() => accountPath('/accounts', bad))
  assert.notEqual(accountPath('/accounts', 'a'.repeat(64)), accountPath('/accounts', 'b'.repeat(64)))
  process.env.OPENAI_API_KEY = 'never-inherit'; process.env.CHATGPT_BRIDGE_TOKEN = 'never-inherit'
  const env = childEnvironment('/accounts/user')
  assert.equal(env.CODEX_HOME, '/accounts/user'); assert.equal(env.OPENAI_API_KEY, undefined); assert.equal(env.CHATGPT_BRIDGE_TOKEN, undefined)
  delete process.env.OPENAI_API_KEY; delete process.env.CHATGPT_BRIDGE_TOKEN
})
test('only bounded structured mentor responses with usage are accepted', () => {
  const text = JSON.stringify({ name: 'finish', arguments_json: JSON.stringify({ answer: 'Hello', actions: [] }) })
  assert.equal(parseAnswer(text, { inputTokens: 10, outputTokens: 5 }).arguments.answer, 'Hello')
  assert.throws(() => parseAnswer(text, null))
  assert.throws(() => parseAnswer(text, { inputTokens: -1, outputTokens: 3 }))
  assert.throws(() => parseAnswer(JSON.stringify({ name: 'exec', arguments_json: '{}' }), {}))
})
test('subscription exhaustion blocks requests even when prepaid credits exist', () => {
  assert.throws(() => requireSubscriptionAllowance({ rateLimits: { primary: { usedPercent: 100 }, credits: { hasCredits: true } } }), /chatgpt_limit_reached/)
  assert.throws(() => requireSubscriptionAllowance({}), /chatgpt_limits_unavailable/)
  assert.throws(() => requireSubscriptionAllowance({ rateLimits: { primary: { usedPercent: 10 }, secondary: { usedPercent: 100 } } }), /chatgpt_limit_reached/)
  requireSubscriptionAllowance({ rateLimitsByLimitId: { codex: { primary: { usedPercent: 10 }, secondary: { usedPercent: 20 } } } })
})
test('HTTP bridge requires transport authentication and a server-selected account', async () => {
  const calls = [], secret = 's'.repeat(40)
  const server = createServer({ run: async (...args) => { calls.push(args); return { connected: false } } }, secret)
  server.listen(0, '127.0.0.1'); await once(server, 'listening')
  const base = `http://127.0.0.1:${server.address().port}`
  const route = `/accounts/${'a'.repeat(64)}/status`
  try {
    assert.equal((await fetch(base + route, { method: 'POST' })).status, 401)
    assert.equal((await fetch(base + '/accounts/1/status', { method: 'POST', headers: { authorization: `Bearer ${secret}` } })).status, 404)
    assert.equal(calls.length, 0)
    const response = await fetch(base + route, { method: 'POST', headers: { authorization: `Bearer ${secret}` }, body: '{}' })
    assert.equal(response.status, 200); assert.equal(response.headers.get('cache-control'), 'no-store')
    assert.equal(calls[0][0], 'a'.repeat(64))
  } finally { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)) }
})
