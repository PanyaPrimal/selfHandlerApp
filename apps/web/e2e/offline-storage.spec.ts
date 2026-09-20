import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from './support/auth'
import { expectNoHorizontalOverflow } from './interface/support'

const api = /^https?:\/\/[^/]+\/api\//

test('changing a task project preserves a child draft during the list refresh', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'DraftRefresh'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  await page.evaluate(async () => {
    const h = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ h)
    await jsonRequest('/storage/projects', 'POST', { name: 'Draft project' })
    await jsonRequest('/storage/items', 'POST', { title: 'Draft parent', status: 'active' })
  })
  await page.reload()
  const parent = page.getByRole('listitem', { name: 'Draft parent', exact: true })
  await expect(parent).toBeVisible()
  const childForm = page.getByRole('form', { name: 'Add a child to Draft parent', exact: true })
  const input = childForm.getByRole('textbox')
  await input.fill('Keep this child draft')
  let start!: () => void
  let finish!: () => void
  const started = new Promise<void>(resolve => { start = resolve })
  const finished = new Promise<void>(resolve => { finish = resolve })
  await page.route('**/api/storage/items', async route => {
    if (route.request().method() !== 'GET') return route.continue()
    start()
    await finished
    await route.continue()
  })
  await parent.getByRole('combobox', { name: 'Project of Draft parent', exact: true }).click()
  await page.getByRole('option', { name: 'Draft project', exact: true }).click()
  await started
  try {
    await expect(input).toBeVisible()
    await expect(input).toHaveValue('Keep this child draft')
    // Editing must remain possible while the refresh is waiting for the network.
    await input.fill('Edited during refresh')
  } finally { finish() }
  await expect(parent.locator('.kind-chip').filter({ hasText: 'Draft project' })).toBeVisible()
  await expect(input).toHaveValue('Edited during refresh')
  await childForm.getByRole('button').click()
  await expect(page.getByRole('listitem', { name: 'Edited during refresh', exact: true })).toBeVisible()
})

test('a lost parent acknowledgement preserves dependent offline edits across reload', async ({ page }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'LostParent'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  await page.route('**/api/storage/items', async route => {
    if (route.request().method() !== 'POST') return route.continue()
    await route.fetch()
    await route.abort('internetdisconnected')
  })
  const form = page.getByRole('form', { name: 'Capture an item' })
  await form.getByLabel('What is on your mind?').fill('Lost parent receipt')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByRole('listitem', { name: 'Lost parent receipt', exact: true })).toBeVisible()
  await page.route(api, route => route.abort('internetdisconnected'))
  await page.getByRole('button', { name: 'Triage Lost parent receipt', exact: true }).click()
  const childForm = page.getByRole('form', { name: 'Add a child to Lost parent receipt', exact: true })
  await childForm.getByRole('textbox').fill('Dependent child')
  await childForm.getByRole('button').click()
  await expect(page.getByRole('listitem', { name: 'Dependent child', exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Dependent child', exact: true })).toBeVisible()
  await page.unroute(api)
  await page.unroute('**/api/storage/items')
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  const rows = (await (await page.request.get('/api/storage/items', { headers: await xsrfHeader(page) })).json()).data
  expect(rows).toHaveLength(2)
  const parent = rows.find((row: { title: string }) => row.title === 'Lost parent receipt')
  expect(parent.status).toBe('active')
  expect(rows.find((row: { title: string }) => row.title === 'Dependent child').parent_id).toBe(parent.id)
})

test('two offline tabs allocate distinct IDs and cannot discard a project with dependent drafts', async ({ page, context }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'TwoTabs'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  const other = await context.newPage()
  await other.goto('/storage')
  await expect(other.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  await context.route(api, route => route.abort('internetdisconnected'))
  const create = async (title: string) => {
    const path = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ path)
    return await jsonRequest('/storage/projects', 'POST', { name: title })
  }
  const [first, second] = await Promise.all([page.evaluate(create, 'First tab'), other.evaluate(create, 'Second tab')])
  expect(first.data.id).toBeLessThan(0)
  expect(second.data.id).toBeLessThan(0)
  expect(first.data.id).not.toBe(second.data.id)
  const result = await page.evaluate(async projectId => {
    const h = '/src/api/http.ts'
    const w = '/src/offline/workspace.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ h)
    const { commands, discardCommand } = await import(/* @vite-ignore */ w)
    await jsonRequest('/storage/items', 'POST', { title: 'Shared queue child', project_id: projectId })
    const project = (await commands()).find((row: { localId?: number }) => row.localId === projectId)
    let failure = ''
    try { await discardCommand(project.id) } catch (error) { failure = (error as Error).message }
    return { failure, count: (await commands()).length }
  }, first.data.id)
  expect(result.count).toBe(3)
  expect(result.failure).toContain('Remove dependent drafts first')
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Shared queue child', exact: true })).toBeVisible()
  await context.unroute(api)
  const synchronize = async () => {
    const w = '/src/offline/workspace.ts'
    const { synchronizeWorkspace } = await import(/* @vite-ignore */ w)
    await synchronizeWorkspace()
  }
  await Promise.all([page.evaluate(synchronize), other.evaluate(synchronize)])
  const headers = await xsrfHeader(page)
  const projects = (await (await page.request.get('/api/storage/projects', { headers })).json()).data
  const items = (await (await page.request.get('/api/storage/items', { headers })).json()).data
  expect(projects).toHaveLength(2)
  expect(items).toHaveLength(1)
  expect(items[0].project_id).toBe(projects.find((row: { name: string }) => row.name === 'First tab').id)
  await other.close()
})

test('offline projects, parent tasks and blockers survive reload and keep relationships after sync', async ({ page }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'LocalRelationships'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  await page.route(api, route => route.abort('internetdisconnected'))
  await page.getByRole('button', { name: 'New project', exact: true }).click()
  await page.getByLabel('Project name', { exact: true }).fill('Offline workshop')
  await page.getByRole('button', { name: 'Create project', exact: true }).click()
  const project = page.getByRole('listitem', { name: 'Offline workshop', exact: true })
  await expect(project).toBeVisible()
  const form = page.getByRole('form', { name: 'Capture an item' })
  await form.getByLabel('What is on your mind?').fill('Build shelf')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await page.getByRole('button', { name: 'Triage Build shelf', exact: true }).click()
  const parent = page.getByRole('listitem', { name: 'Build shelf', exact: true })
  await parent.getByRole('combobox', { name: 'Project of Build shelf', exact: true }).click()
  await page.getByRole('option', { name: 'Offline workshop', exact: true }).click()
  const childForm = page.getByRole('form', { name: 'Add a child to Build shelf', exact: true })
  await childForm.getByRole('textbox').fill('Buy screws')
  await childForm.getByRole('button').click()
  await page.getByRole('button', { name: 'Mark Buy screws as a blocker', exact: true }).click()
  await page.getByRole('button', { name: 'Complete Build shelf', exact: true }).click()
  await expect(page.getByText('Complete the blocking subtasks first.', { exact: true })).toBeVisible()
  await page.reload()
  await expect(parent).toBeVisible()
  await expect(parent.getByRole('listitem', { name: 'Buy screws', exact: true })).toBeVisible()
  await expect(project).toBeVisible()
  await expectNoHorizontalOverflow(page)
  const local = await page.evaluate(async () => {
    const p = '/src/offline/workspace.ts'
    const { commands } = await import(/* @vite-ignore */ p)
    return await commands()
  })
  expect(local.filter((row: { method: string }) => row.method === 'POST')).toHaveLength(3)
  expect(local.every((row: { localProjected: boolean }) => row.localProjected)).toBe(true)
  await page.unroute(api)
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  const headers = await xsrfHeader(page)
  const items = (await (await page.request.get('/api/storage/items', { headers })).json()).data
  const projects = (await (await page.request.get('/api/storage/projects', { headers })).json()).data
  expect(items).toHaveLength(2)
  expect(projects).toHaveLength(1)
  const savedParent = items.find((row: { title: string }) => row.title === 'Build shelf')
  const savedChild = items.find((row: { title: string }) => row.title === 'Buy screws')
  expect(savedParent.project_id).toBe(projects[0].id)
  expect(savedChild.parent_id).toBe(savedParent.id)
  expect(savedChild.is_blocker).toBe(true)
  expect(savedParent.status).toBe('active')
  await expect(parent.locator('small').filter({ hasText: 'Awaiting synchronization' })).toHaveCount(0)
})

test('receipt transaction abort retains the entire draft and retries without duplicate server records', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'AtomicReceipt'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  await page.evaluate(() => {
    const original = IDBObjectStore.prototype.put
    IDBObjectStore.prototype.put = function (value, key) {
      // Fail after the identity and item snapshot writes were queued in the same transaction.
      if (String(key).endsWith('read:/storage/projects')) throw new DOMException('Simulated receipt storage failure', 'QuotaExceededError')
      return original.call(this, value, key)
    }
  })
  const form = page.getByRole('form', { name: 'Capture an item' })
  await form.getByLabel('What is on your mind?').fill('Atomic shelf')
  await form.getByRole('button', { name: 'Capture', exact: true }).click()
  await expect(page.getByText('Pending changes: 1', { exact: true })).toBeVisible()
  await expect(page.getByRole('alert').filter({ hasText: 'Could not capture that.' })).toBeVisible()
  await expect(form.getByLabel('What is on your mind?')).toHaveValue('Atomic shelf')
  const state = await page.evaluate(async () => {
    const p = '/src/offline/database.ts'
    const w = '/src/offline/workspace.ts'
    const { localEntries } = await import(/* @vite-ignore */ p)
    const { workspaceState } = await import(/* @vite-ignore */ w)
    return await localEntries(`account:${workspaceState.owner}:`)
  })
  expect(state.filter((entry: { key: string }) => entry.key.includes(':identity:storage:'))).toHaveLength(0)
  expect(state.find((entry: { key: string }) => entry.key.endsWith('read:/storage/items')).value.data.data).toHaveLength(0)
  // Reload restores the actual IDB implementation, leaving the durable operation UUID intact.
  await page.reload()
  // Restoring an online session already starts synchronization; do not race its disappearing button.
  await expect(page.getByRole('listitem', { name: 'Atomic shelf', exact: true })).toHaveCount(1, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  const headers = await xsrfHeader(page)
  const items = (await (await page.request.get('/api/storage/items', { headers })).json()).data
  expect(items.filter((row: { title: string }) => row.title === 'Atomic shelf')).toHaveLength(1)
})
