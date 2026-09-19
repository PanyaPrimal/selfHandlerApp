import { test } from 'node:test'
import assert from 'node:assert/strict'
import { EventEmitter } from 'node:events'
import { Accounts } from './accounts.mjs'

class FakeRpc extends EventEmitter {
  closed = false
  calls = []
  async request(method, params) {
    this.calls.push([method, params])
    if (method === 'thread/start') return { thread: { id: 'own-thread' } }
    if (method === 'turn/start') {
      queueMicrotask(() => {
        this.emit('notification', { method: 'item/completed', params: { threadId: 'other-thread', item: { type: 'agentMessage', text: 'private other response' } } })
        this.emit('notification', { method: 'item/completed', params: { threadId: 'own-thread', item: { type: 'agentMessage', text: JSON.stringify({ name: 'finish', arguments_json: JSON.stringify({ answer: 'Привет', actions: [] }) }) } } })
        this.emit('notification', { method: 'thread/tokenUsage/updated', params: { threadId: 'own-thread', tokenUsage: { last: { inputTokens: 15, outputTokens: 5, cachedInputTokens: 3, reasoningOutputTokens: 2 } } } })
        this.emit('notification', { method: 'turn/completed', params: { threadId: 'own-thread', turn: { status: 'completed' } } })
      })
      return { turn: { id: 'turn' } }
    }
    return {}
  }
  close() { this.closed = true; this.emit('closed') }
}
test('turn replies are isolated, usage is retained and threads are ephemeral with restricted reads', async () => {
  const accounts = new Accounts({ root: '/unused' }), rpc = new FakeRpc()
  try {
    const result = await accounts.call({ rpc, work: '/accounts/owner/empty' }, { model: 'gpt-6-astra', system: 'Mentor', context: '{}', tools: [] })
    assert.equal(result.arguments.answer, 'Привет'); assert.equal(result.usage.cached_tokens, 3)
    const start = rpc.calls.find(([method]) => method === 'thread/start')[1]
    assert.equal(start.ephemeral, true); assert.equal(start.sandbox, 'read-only'); assert.equal(start.approvalPolicy, 'never')
    const turn = rpc.calls.find(([method]) => method === 'turn/start')[1]
    assert.deepEqual(turn.sandboxPolicy.access.readableRoots, ['/accounts/owner/empty'])
    assert.equal(turn.sandboxPolicy.access.includePlatformDefaults, false)
    assert.equal(rpc.calls.at(-1)[0], 'thread/unsubscribe')
    assert.equal(rpc.listenerCount('notification'), 0)
  } finally { accounts.close() }
})
test('logout cancels a pending device flow before clearing authentication', async () => {
  const accounts = new Accounts({ root: '/unused' }), rpc = new FakeRpc()
  const owner = 'c'.repeat(64)
  accounts.sessions.set(owner, { rpc, work: '/empty', busy: false, used: Date.now(), loginId: 'pending-login', login: { user_code: 'test' } })
  try {
    assert.deepEqual(await accounts.run(owner, 'logout'), { connected: false })
    assert.deepEqual(rpc.calls.map(([method]) => method), ['account/login/cancel', 'account/logout'])
    assert.equal(accounts.sessions.get(owner).login, null)
  } finally { accounts.close() }
})
