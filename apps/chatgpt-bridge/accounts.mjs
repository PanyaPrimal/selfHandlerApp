import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { Rpc, parseAnswer, subscriptionLimits, requireSubscriptionAllowance } from './protocol.mjs'

const here = path.dirname(fileURLToPath(import.meta.url))
export function accountPath(root, owner) {
  if (!/^[a-f0-9]{64}$/.test(owner)) throw new Error('chatgpt_invalid_account')
  return path.join(path.resolve(root), owner)
}
export function childEnvironment(home) {
  // Explicit allowlist: never inherit API keys, the bridge secret or host Codex authentication.
  const env = { PATH: process.env.PATH, CODEX_HOME: home, HOME: home, USERPROFILE: home, TMPDIR: home, TMP: home, TEMP: home }
  if (process.platform === 'win32') env.SystemRoot = process.env.SystemRoot
  return env
}
export class Accounts {
  sessions = new Map()
  constructor({ root, binary = 'codex', maxSessions = 2 }) {
    this.root = root; this.binary = binary; this.maxSessions = maxSessions
    this.sweep = setInterval(() => {
      for (const session of this.sessions.values()) if (!session.busy && Date.now() - session.used > (session.login ? 900_000 : 120_000)) session.rpc.close()
    }, 30_000).unref()
  }
  async session(owner) {
    const home = accountPath(this.root, owner)
    if (this.sessions.has(owner)) return this.sessions.get(owner)
    if (this.sessions.size >= this.maxSessions) {
      const idle = [...this.sessions.values()].find(item => !item.busy && !item.login)
      idle?.rpc.close()
    }
    if (this.sessions.size >= this.maxSessions) throw new Error('chatgpt_busy')
    const work = path.join(home, 'empty')
    await mkdir(work, { recursive: true, mode: 0o700 })
    const config = [
      'forced_login_method = "chatgpt"', 'cli_auth_credentials_store = "file"',
      'approval_policy = "never"', 'sandbox_mode = "read-only"', 'web_search = "disabled"',
      'project_doc_max_bytes = 0', 'history.persistence = "none"', 'analytics.enabled = false', 'feedback.enabled = false',
      'agents.enabled = false', 'tools.view_image = false',
      'features.shell_tool = false', 'features.unified_exec = false', 'features.multi_agent = false',
      'features.apps = false', 'features.plugins = false', 'features.remote_plugin = false',
      'features.code_mode.enabled = false', 'features.hooks = true',
      '[[hooks.PreToolUse]]', 'matcher = ".*"', '[[hooks.PreToolUse.hooks]]', 'type = "command"',
      `command = ${JSON.stringify(`node "${path.join(here, 'deny-tool.cjs').replaceAll('\\', '/')}"`)}`,
    ].join('\n')
    await writeFile(path.join(home, 'config.toml'), config, { mode: 0o600 })
    const rpc = new Rpc(this.binary, ['app-server', '--listen', 'stdio://'], { cwd: work, env: childEnvironment(home) })
    const session = { rpc, work, used: Date.now(), busy: false, login: null, loginId: null }
    this.sessions.set(owner, session)
    rpc.on('closed', () => { if (this.sessions.get(owner) === session) this.sessions.delete(owner) })
    rpc.on('notification', message => { if (message.method === 'account/login/completed') { session.login = null; session.loginId = null } })
    try {
      await rpc.request('initialize', { clientInfo: { name: 'selfhandler', version: '1.0.0' }, capabilities: {} })
      rpc.send({ method: 'initialized' })
    } catch (error) { rpc.close(); throw error }
    return session
  }
  async run(owner, action, data = {}) {
    const session = await this.session(owner)
    if (session.busy) throw new Error('chatgpt_busy')
    session.busy = true; session.used = Date.now()
    try {
      const { rpc } = session
      if (action === 'login') {
        if (session.login && session.login.expires_at > Date.now()) return session.login
        const result = await rpc.request('account/login/start', { type: 'chatgptDeviceCode' }, 20_000)
        if (result.type !== 'chatgptDeviceCode' || result.verificationUrl !== 'https://auth.openai.com/codex/device'
          || typeof result.userCode !== 'string') throw new Error('chatgpt_login_failed')
        session.login = { verification_url: result.verificationUrl, user_code: result.userCode, expires_at: Date.now() + 900_000 }
        session.loginId = result.loginId
        return session.login
      }
      if (action === 'logout') {
        if (session.loginId) await rpc.request('account/login/cancel', { loginId: session.loginId })
        await rpc.request('account/logout'); session.login = null; session.loginId = null
        return { connected: false }
      }
      const { account } = await rpc.request('account/read', { refreshToken: false })
      const connected = account?.type === 'chatgpt'
      if (action === 'status') {
        let limits = null
        if (connected) {
          try { limits = subscriptionLimits(await rpc.request('account/rateLimits/read')) } catch { /* Availability is separate from account state. */ }
        }
        return { connected, email: connected ? account.email : null, plan: connected ? account.planType : null, limits, login: session.login }
      }
      if (!connected) throw new Error('chatgpt_login_required')
      const result = await rpc.request('model/list', { limit: 100, includeHidden: false })
      const models = result.data.map(item => ({ id: item.model, name: item.displayName, default: item.isDefault }))
      if (action === 'models') return { models }
      if (action !== 'call' || !models.some(item => item.id === data.model)) throw new Error('chatgpt_model_unavailable')
      // Stop at the subscription limit; never deliberately continue on purchased credits.
      requireSubscriptionAllowance(await rpc.request('account/rateLimits/read'))
      return await this.call(session, data)
    } finally { session.busy = false; session.used = Date.now() }
  }
  async call(session, data) {
    if (typeof data.system !== 'string' || typeof data.context !== 'string' || !Array.isArray(data.tools)
      || Buffer.byteLength(JSON.stringify(data)) > 100_000) throw new Error('chatgpt_invalid_request')
    const { rpc } = session
    const { thread } = await rpc.request('thread/start', {
      model: data.model, modelProvider: 'openai', cwd: session.work, ephemeral: true,
      approvalPolicy: 'never', sandbox: 'read-only', serviceName: 'selfhandler-mentor', serviceTier: 'default',
      baseInstructions: data.system + '\nDo not execute any tools. Return JSON describing exactly one proposed tool step. The host validates and executes it. Tool schemas: ' + JSON.stringify(data.tools),
    })
    let text = '', usage = null, timer, listener, closed
    const completed = new Promise((resolve, reject) => {
      timer = setTimeout(() => { reject(new Error('chatgpt_timeout')); rpc.close() }, 40_000)
      closed = () => reject(new Error('chatgpt_unavailable'))
      rpc.once('closed', closed)
      listener = event => {
        if (event.params?.threadId !== thread.id) return
        if (event.method === 'thread/tokenUsage/updated') usage = event.params.tokenUsage?.last
        if (event.method === 'item/completed' && event.params.item?.type === 'agentMessage') text = event.params.item.text
        if (event.method === 'turn/completed') {
          event.params.turn.status === 'completed' ? resolve() : reject(new Error('chatgpt_turn_failed'))
        }
      }
      rpc.on('notification', listener)
    })
    // Attach a rejection handler before awaiting turn/start.
    completed.catch(() => {})
    try {
      await rpc.request('turn/start', { threadId: thread.id, input: [{ type: 'text', text: data.context }], effort: 'low',
        sandboxPolicy: { type: 'readOnly', access: { type: 'restricted', includePlatformDefaults: false, readableRoots: [session.work] } },
        outputSchema: { type: 'object', additionalProperties: false,
          properties: { name: { type: 'string', enum: ['read_records', 'finish'] }, arguments_json: { type: 'string' } }, required: ['name', 'arguments_json'] },
      })
      await completed
      return parseAnswer(text, usage)
    } finally {
      clearTimeout(timer); rpc.off('notification', listener); rpc.off('closed', closed)
      // Ephemeral sessions do not retain the user's prompt or dataset transcript.
      if (!rpc.closed) await rpc.request('thread/unsubscribe', { threadId: thread.id }).catch(() => rpc.close())
    }
  }
  close() { clearInterval(this.sweep); for (const session of this.sessions.values()) session.rpc.close() }
}
