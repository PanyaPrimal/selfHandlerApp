import { EventEmitter } from 'node:events'
import { spawn } from 'node:child_process'
import { createInterface } from 'node:readline'

export class Rpc extends EventEmitter {
  pending = new Map()
  sequence = 0
  closed = false
  constructor(binary, args, options) {
    super()
    this.process = spawn(binary, args, { ...options, shell: false, windowsHide: true, stdio: ['pipe', 'pipe', 'pipe'] })
    // Never log provider output: it may contain credentials or personal context.
    this.process.stderr.resume()
    this.process.stdin.on('error', () => this.close())
    const lines = createInterface({ input: this.process.stdout })
    lines.on('line', line => {
      if (line.length > 2_000_000) return this.close()
      let message
      try { message = JSON.parse(line) } catch { return this.close() }
      if (message.method && message.id !== undefined) {
        this.send({ id: message.id, error: { code: -32601, message: 'Tools and approval requests are disabled.' } })
      } else if (message.id !== undefined) {
        const pending = this.pending.get(message.id)
        if (!pending) return
        clearTimeout(pending.timer); this.pending.delete(message.id)
        message.error ? pending.reject(new Error('chatgpt_request_failed')) : pending.resolve(message.result)
      } else if (message.method) this.emit('notification', message)
    })
    this.process.on('error', () => this.close())
    this.process.on('exit', () => this.close())
  }
  send(message) {
    if (!this.closed) this.process.stdin.write(JSON.stringify(message) + '\n')
  }
  request(method, params = {}, timeout = 10_000) {
    if (this.closed) return Promise.reject(new Error('chatgpt_unavailable'))
    const id = ++this.sequence
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => { this.pending.delete(id); reject(new Error('chatgpt_timeout')); this.close() }, timeout)
      this.pending.set(id, { resolve, reject, timer }); this.send({ id, method, params })
    })
  }
  close() {
    if (this.closed) return
    this.closed = true
    this.process.kill()
    for (const item of this.pending.values()) { clearTimeout(item.timer); item.reject(new Error('chatgpt_unavailable')) }
    this.pending.clear(); this.emit('closed')
  }
}

export function parseAnswer(text, usage) {
  const value = JSON.parse(text)
  if (!['read_records', 'finish'].includes(value.name) || typeof value.arguments_json !== 'string') throw new Error('chatgpt_invalid_response')
  const args = JSON.parse(value.arguments_json)
  if (!args || typeof args !== 'object' || Array.isArray(args)) throw new Error('chatgpt_invalid_response')
  if (!usage || !Number.isSafeInteger(usage.inputTokens) || !Number.isSafeInteger(usage.outputTokens)
      || usage.inputTokens < 0 || usage.outputTokens < 0) throw new Error('chatgpt_usage_unavailable')
  return { valid: true, name: value.name, arguments: args, usage: {
    input_tokens: usage.inputTokens, output_tokens: usage.outputTokens,
    cached_tokens: Math.max(0, usage.cachedInputTokens ?? 0), cache_write_tokens: 0,
    reasoning_tokens: Math.max(0, usage.reasoningOutputTokens ?? 0),
  } }
}

export function subscriptionLimits(result) {
  const limits = result?.rateLimitsByLimitId?.codex ?? result?.rateLimits
  return limits ? { primary: limits.primary ?? null, secondary: limits.secondary ?? null } : null
}
export function requireSubscriptionAllowance(result) {
  const limits = subscriptionLimits(result)
  const windows = [limits?.primary, limits?.secondary].filter(Boolean)
  if (!windows.length || windows.some(window => !Number.isFinite(window.usedPercent))) throw new Error('chatgpt_limits_unavailable')
  if (windows.some(window => window.usedPercent >= 100)) throw new Error('chatgpt_limit_reached')
}
