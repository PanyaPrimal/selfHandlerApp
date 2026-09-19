import http from 'node:http'
import { timingSafeEqual, createHmac } from 'node:crypto'
import { pathToFileURL } from 'node:url'
import { Accounts } from './accounts.mjs'

export function createServer(accounts, secret) {
  if (typeof secret !== 'string' || secret.length < 32) throw new Error('Bridge secret is required')
  const busyOwners = new Set()
  return http.createServer(async (req, res) => {
    const respond = (code, value) => { res.writeHead(code, { 'content-type': 'application/json', 'cache-control': 'no-store' }); res.end(JSON.stringify(value)) }
    if (req.url === '/health' && req.method === 'GET') return respond(200, { ok: true })
    const actual = Buffer.from(req.headers.authorization ?? ''), expected = Buffer.from(`Bearer ${secret}`)
    if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) return respond(401, { error: 'unauthorized' })
    const route = /^\/accounts\/([a-f0-9]{64})\/(status|login|logout|models|call)$/.exec(req.url ?? '')
    if (req.method !== 'POST' || !route) return respond(404, { error: 'not_found' })
    if (busyOwners.has(route[1]) || busyOwners.size >= 2) return respond(429, { error: 'chatgpt_busy' })
    busyOwners.add(route[1])
    try {
      const chunks = []; let bytes = 0
      for await (const chunk of req) { bytes += chunk.length; if (bytes > 100_000) return respond(413, { error: 'too_large' }); chunks.push(chunk) }
      const result = await accounts.run(route[1], route[2], JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}'))
      respond(200, result)
    } catch (error) {
      const code = error instanceof Error ? error.message : ''
      const safe = /^chatgpt_[a-z_]+$/.test(code) ? code : 'chatgpt_unavailable'
      respond(['chatgpt_busy', 'chatgpt_limit_reached'].includes(safe) ? 429 : safe === 'chatgpt_login_required' ? 409 : 503, { error: safe })
    } finally { busyOwners.delete(route[1]) }
  })
}
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const accounts = new Accounts({ root: process.env.CHATGPT_DATA_DIR ?? '/app/storage/app/private/chatgpt', binary: process.env.CHATGPT_CODEX_BINARY ?? '/opt/chatgpt/node_modules/@openai/codex-linux-x64/vendor/x86_64-unknown-linux-musl/bin/codex' })
  const secret = process.env.CHATGPT_BRIDGE_TOKEN ?? (process.env.APP_KEY ? createHmac('sha256', process.env.APP_KEY).update('selfhandler-chatgpt-bridge').digest('hex') : undefined)
  const server = createServer(accounts, secret)
  server.requestTimeout = 60_000; server.headersTimeout = 10_000
  server.listen(Number(process.env.PORT ?? 8091), process.env.HOST ?? '127.0.0.1')
  for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => { accounts.close(); server.close(); setTimeout(() => process.exit(0), 2000).unref() })
}
