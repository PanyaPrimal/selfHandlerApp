import { expect, test } from '@playwright/test'
import { loginViaUi, logoutViaUi, registerViaUi, uniqueCredentials, xsrfHeader } from './support/auth'
import { expectNoHorizontalOverflow } from './interface/support'

test('offline capture survives reload and synchronizes once after reconnect', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'OfflineCapture'), { redirectTo: '/storage' })
  await expect(page.getByRole('form', { name: 'Capture an item' })).toBeVisible()
  await expect.poll(() => page.evaluate(async () => {
    const databasePath = '/src/offline/database.ts'
    const workspacePath = '/src/offline/workspace.ts'
    const { localEntries } = await import(/* @vite-ignore */ databasePath)
    const { workspaceState } = await import(/* @vite-ignore */ workspacePath)
    return (await localEntries(`account:${workspaceState.owner}:read:`)).length
  })).toBeGreaterThanOrEqual(5)
  // Leave the dev shell online while simulating an unavailable API. Built shell is tested separately.
  await page.route(/^https?:\/\/[^/]+\/api\//, route => route.abort('internetdisconnected'))
  const form = page.getByRole('form', { name: 'Capture an item' })
  await form.getByLabel('What is on your mind?').fill('Offline milk')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByText(/^Saved on this device, awaiting synchronization/)).toBeVisible()
  await expect(page.getByText('Pending changes: 1', { exact: true })).toBeVisible()
  // A new, deliberately identical capture is another intent, not a retry.
  await form.getByLabel('What is on your mind?').fill('Offline milk')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByText(/pending changes: 2/i)).toBeVisible()
  await page.reload()
  await expect(page.getByRole('form', { name: 'Capture an item' })).toBeVisible()
  await expect(page.getByText('Offline · pending changes: 2', { exact: true })).toBeVisible()
  await page.unroute(/^https?:\/\/[^/]+\/api\//)
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  await expect(page.getByText(/pending changes: 2/i)).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Offline milk', exact: true })).toHaveCount(2)
  await expectNoHorizontalOverflow(page)
})

test('voice and text drafts are private and persist independently of provider availability', async ({ page }, info) => {
  const first = uniqueCredentials(info, 'MentorOwner')
  await registerViaUi(page, first, { redirectTo: '/mentor' })
  await expect(page.getByRole('heading', { name: 'Mentor', exact: true })).toBeVisible()
  const text = page.getByLabel('Message or transcript')
  await expect(text).toBeEnabled()
  await text.fill('Private mentor draft')
  await expect(page.getByText('Draft saved on this device.', { exact: true })).toBeVisible()
  await page.reload()
  await expect(text).toHaveValue('Private mentor draft')
  await logoutViaUi(page)
  await registerViaUi(page, uniqueCredentials(info, 'MentorOther'), { redirectTo: '/mentor' })
  await expect(text).toHaveValue('')
  await logoutViaUi(page)
  await loginViaUi(page, first, '/mentor')
  await expect(text).toHaveValue('Private mentor draft')
  await page.setViewportSize({ width: 320, height: 640 })
  await expectNoHorizontalOverflow(page)
})

test('mentor previews changes and renders model content as plain text', async ({ page }, info) => {
  const turn = { id: 1, operation_id: 'fixture', status: 'completed', question: 'Remember milk',
    answer: '<script>window.bad=true</script> Create this task?', model: 'gpt-6-astra', created_at: new Date().toISOString(),
    estimated_usd: '0.007', error_code: null, sources: [], usage: { input: 200, output: 100, reasoning: 20, cached: 0, reserved: 0 },
    actions: [{ kind: 'capture_item', label: 'Buy milk', payload: { title: 'Buy milk' }, status: 'pending' }] }
  let releaseHistory!: () => void
  const historyGate = new Promise<void>(resolve => { releaseHistory = resolve })
  await page.route('**/api/mentor/turns', async route => {
    if (route.request().method() === 'GET') await historyGate
    await route.fulfill({ json: { data: route.request().method() === 'GET' ? [] : turn } })
  })
  let confirmations = 0
  await page.route('**/api/mentor/turns/1/actions/0', async route => { confirmations++; await route.fulfill({ json: { data: { ...turn, actions: [{ ...turn.actions[0], status: 'applied' }] } } }) })
  await registerViaUi(page, uniqueCredentials(info, 'MentorPreview'), { redirectTo: '/mentor' })
  await expect(page.getByLabel('Message or transcript')).toBeEnabled()
  await page.getByLabel('Message or transcript').fill('Remember milk')
  await page.getByRole('button', { name: 'Send', exact: true }).click()
  await expect(page.getByText(turn.answer, { exact: true })).toBeVisible()
  // An older, slow initial history response must not erase the just-received answer.
  releaseHistory()
  await expect(page.getByText('Enable the mentor and activate an AI connection in account settings.', { exact: true })).toBeVisible()
  expect(confirmations).toBe(0)
  await page.getByRole('button', { name: 'Confirm and save' }).click()
  await expect(page.getByRole('button', { name: 'Saved', exact: true })).toBeDisabled()
  expect(confirmations).toBe(1)
  expect(await page.evaluate(() => (window as unknown as { bad?: boolean }).bad)).toBeUndefined()
})

test('a lost acknowledgement is retried without duplicate capture', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'LostAck'), { redirectTo: '/storage' })
  let operation: string | undefined
  await page.route('**/api/storage/items', async route => {
    if (route.request().method() !== 'POST') return route.continue()
    operation = route.request().headers()['x-workspace-operation']
    await route.fetch()
    await route.abort('internetdisconnected')
  })
  const form = page.getByRole('form', { name: 'Capture an item' })
  await form.getByLabel('What is on your mind?').fill('Acknowledgement lost')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByText(/^Saved on this device, awaiting synchronization/)).toBeVisible()
  await expect(page.getByText('Pending changes: 1', { exact: true })).toBeVisible()
  expect(operation).toBeTruthy()
  await page.unroute('**/api/storage/items')
  const replay = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/storage/items')
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  expect((await replay).headers()['x-workspace-replayed']).toBe('true')
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Acknowledgement lost', exact: true })).toHaveCount(1)
})

test('a second device edit preserves both the server record and the conflicting draft', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'SyncConflict'), { redirectTo: '/storage' })
  const headers = await xsrfHeader(page)
  const created = await page.request.post('/api/storage/items', { headers, data: { title: 'Original task' } })
  expect(created.status()).toBe(201)
  const id = (await created.json()).data.id
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Original task', exact: true })).toBeVisible({ timeout: 15000 })
  await page.route(`**/api/storage/items/${id}`, route => route.abort('internetdisconnected'))
  await page.evaluate(async itemId => {
    const path = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ path)
    try { await jsonRequest(`/storage/items/${itemId}`, 'PATCH', { title: 'Offline task' }) } catch { /* queued */ }
  }, id)
  const remote = await page.request.patch(`/api/storage/items/${id}`, { headers, data: { title: 'Newer server task' } })
  expect(remote.ok()).toBeTruthy()
  await page.unroute(`**/api/storage/items/${id}`)
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  await page.getByRole('button', { name: 'Pending changes', exact: true }).click()
  await expect(page.getByText('Needs review: server data changed', { exact: true })).toBeVisible()
  const records = await (await page.request.get('/api/storage/items', { headers })).json()
  expect(records.data.find((row: { id: number }) => row.id === id).title).toBe('Newer server task')
  await expect(page.getByText('Offline task', { exact: true }).first()).toBeVisible()
})

test('an unavailable device store preserves the form and never claims a durable save', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'StorageFailure'), { redirectTo: '/storage' })
  const form = page.getByRole('form', { name: 'Capture an item' })
  await expect(form).toBeVisible()
  await page.evaluate(() => {
    IDBObjectStore.prototype.put = function () { throw new DOMException('Device storage full', 'QuotaExceededError') }
  })
  let writes = 0
  page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/storage/items') writes++ })
  await form.getByLabel('What is on your mind?').fill('Keep this unsaved draft')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByRole('alert').filter({ hasText: 'Could not capture that.' })).toBeVisible()
  await expect(page.getByText('Could not load Storage. Check the service and try again.', { exact: true })).toHaveCount(0)
  await expect(form.getByLabel('What is on your mind?')).toHaveValue('Keep this unsaved draft')
  expect(writes).toBe(0)
  await expect(page.getByText(/^Saved on this device/)).toHaveCount(0)
})

test('retrying an unacknowledged command without a saved revision requires review', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'NoBaseline'), { redirectTo: '/storage' })
  // The form appears before its initial reads finish. Wait for those reads before
  // removing their snapshots, otherwise a late response restores the baseline.
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  await page.route('**/api/storage/items', route => route.request().method() === 'POST' ? route.abort('internetdisconnected') : route.continue())
  const base = await page.evaluate(async () => {
    const databasePath = '/src/offline/database.ts'
    const workspacePath = '/src/offline/workspace.ts'
    const httpPath = '/src/api/http.ts'
    const { localEntries, localRemove } = await import(/* @vite-ignore */ databasePath)
    const { workspaceState, commands } = await import(/* @vite-ignore */ workspacePath)
    const { jsonRequest } = await import(/* @vite-ignore */ httpPath)
    for (const entry of await localEntries(`account:${workspaceState.owner}:read:`)) await localRemove(entry.key)
    try { await jsonRequest('/storage/items', 'POST', { title: 'No saved revision' }) } catch { /* durable command */ }
    return (await commands())[0]?.base
  })
  expect(base).toBeNull()
  await page.unroute('**/api/storage/items')
  let writes = 0
  page.on('request', request => { if (request.method() === 'POST' && new URL(request.url()).pathname === '/api/storage/items') writes++ })
  await page.evaluate(async () => {
    const httpPath = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ httpPath)
    try { await jsonRequest('/storage/items', 'POST', { title: 'No saved revision' }) } catch { /* requires review */ }
  })
  await page.getByRole('button', { name: 'Pending changes', exact: true }).click()
  await expect(page.getByText('Needs review: server data changed', { exact: true })).toBeVisible()
  expect(writes).toBe(0)
})
