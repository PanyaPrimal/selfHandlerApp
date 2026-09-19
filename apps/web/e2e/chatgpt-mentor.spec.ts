import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from './support/auth'
import { expectNoHorizontalOverflow } from './interface/support'

test('ChatGPT login, account model and disconnect fit the small phone screen without an API key', async ({ page }, info) => {
  if (info.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 740 })
  let connected = false, saved: Record<string, unknown> | null = null
  const preferences = { enabled: false, memory: '', monthly_token_limit: 1000000, auth_mode: 'api', chatgpt_model: null,
    chatgpt_available: true, active_connection_id: null, budget_month: '2026-09', price_date: '2026-09-18',
    usage: { tokens: 0, reserved: 0, estimated_usd: '0', unpriced_requests: 0 } }
  await page.route('**/api/mentor/settings', async route => {
    if (route.request().method() === 'PUT') saved = route.request().postDataJSON()
    await route.fulfill({ json: { data: { ...preferences, ...saved } } })
  })
  await page.route('**/api/mentor/chatgpt', route => route.fulfill({ json: { data: { available: true, connected,
    email: connected ? 'owner@example.test' : null, plan: 'pro', limits: { primary: { usedPercent: 20 } } } } }))
  await page.route('**/api/mentor/chatgpt/login', route => route.fulfill({ json: { data: {
    verification_url: 'https://auth.openai.com/codex/device', user_code: 'ABCD-1234', expires_at: Date.now() + 900000,
  } } }))
  await page.route('**/api/mentor/chatgpt/models', route => route.fulfill({ json: { data: { models: [{ id: 'gpt-6-astra', name: 'GPT-6 Astra', default: true }] } } }))
  await page.route('**/api/mentor/chatgpt/logout', async route => { connected = false; await route.fulfill({ json: { data: { connected: false } } }) })
  await registerViaUi(page, uniqueCredentials(info, 'ChatGptConnection'), { redirectTo: '/settings/ai' })
  await page.getByLabel('Mentor connection', { exact: true }).selectOption('chatgpt')
  await page.getByRole('button', { name: 'Connect ChatGPT', exact: true }).click()
  await expect(page.getByText('ABCD-1234', { exact: true })).toBeVisible()
  if (info.project.name === 'mobile') await page.screenshot({ path: info.outputPath('chatgpt-connect-320.png'), fullPage: true })
  await expect(page.getByRole('link', { name: 'Sign in at OpenAI' })).toHaveAttribute('href', 'https://auth.openai.com/codex/device')
  connected = true
  await page.getByRole('button', { name: 'Check connection' }).click()
  await expect(page.getByText('Connected: owner@example.test · pro', { exact: true })).toBeVisible()
  await expect(page.locator('#chatgpt-model')).toHaveValue('gpt-6-astra')
  await page.getByLabel('Enable mentor access to my personal workspace').check()
  await page.getByRole('button', { name: 'Save mentor settings' }).click()
  await expect.poll(() => saved?.auth_mode).toBe('chatgpt')
  expect(saved).not.toHaveProperty('api_key')
  await expectNoHorizontalOverflow(page)
  await page.getByRole('button', { name: 'Disconnect ChatGPT' }).click()
  await expect(page.getByLabel('Enable mentor access to my personal workspace')).not.toBeChecked()
  await expect(page.getByRole('button', { name: 'Connect ChatGPT', exact: true })).toBeVisible()
})

test('subscription dictation keeps an editable draft and never calls API transcription', async ({ page }, info) => {
  let paidCalls = 0
  await page.addInitScript(() => {
    ;(window as any).SpeechRecognition = class {
      onresult: ((event: unknown) => void) | null = null
      onend: (() => void) | null = null
      start() { this.onresult?.({ results: [[{ transcript: 'Buy milk tomorrow' }]] }); this.onend?.() }
      abort() {}
    }
  })
  await page.route('**/api/mentor/settings', route => route.fulfill({ json: { data: {
    enabled: true, auth_mode: 'chatgpt', chatgpt_model: 'gpt-6-astra', active_connection_id: null,
  } } }))
  await page.route('**/api/mentor/transcribe', async route => { paidCalls++; await route.abort() })
  await registerViaUi(page, uniqueCredentials(info, 'ChatGptDictation'), { redirectTo: '/mentor' })
  await page.getByRole('button', { name: 'Dictate', exact: true }).click()
  await expect(page.getByLabel('Message or transcript')).toHaveValue('Buy milk tomorrow')
  await expect(page.getByText('Draft saved on this device.', { exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByLabel('Message or transcript')).toHaveValue('Buy milk tomorrow')
  expect(paidCalls).toBe(0)
})

test('an exhausted subscription explains the limit and a later explicit Send can retry', async ({ page }, info) => {
  const operations: string[] = []
  await page.route('**/api/mentor/settings', route => route.fulfill({ json: { data: { enabled: true, auth_mode: 'chatgpt', chatgpt_model: 'gpt-6-astra' } } }))
  await page.route('**/api/mentor/turns', async route => {
    if (route.request().method() === 'GET') return route.fulfill({ json: { data: [] } })
    const input = route.request().postDataJSON(); operations.push(input.operation_id)
    await route.fulfill({ json: { data: { id: operations.length, operation_id: input.operation_id, question: input.question,
      status: operations.length === 1 ? 'failed' : 'completed', answer: operations.length === 1 ? null : 'Ready after reset',
      error_code: operations.length === 1 ? 'chatgpt_limit_reached' : null, sources: [], actions: [], estimated_usd: '0', model: 'gpt-6-astra',
      usage: { input: 0, output: 0, cached: 0, reasoning: 0 },
    } } })
  })
  await registerViaUi(page, uniqueCredentials(info, 'ChatGptLimit'), { redirectTo: '/mentor' })
  await page.getByLabel('Message or transcript').fill('Help me plan tomorrow')
  await page.getByRole('button', { name: 'Send', exact: true }).click()
  await expect(page.getByText('Your ChatGPT subscription limit is reached. Wait for it to reset; no paid API fallback was used.', { exact: true })).toBeVisible()
  expect(operations).toHaveLength(1)
  await page.getByRole('button', { name: 'Send', exact: true }).click()
  await expect(page.getByText('Ready after reset', { exact: true })).toBeVisible()
  expect(operations).toHaveLength(2); expect(operations[0]).not.toBe(operations[1])
})
