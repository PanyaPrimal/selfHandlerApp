import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from './support/auth'
import { expectNoHorizontalOverflow, pickDate, setTime } from './interface/support'

const api = /^https?:\/\/[^/]+\/api\//

test('a lost block acknowledgement and two intentional identical captures keep separate identities', async ({ page }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'LostBlock'), { redirectTo: '/planner' })
  await expect(page.getByRole('heading', { name: 'The day', exact: true })).toBeVisible()
  await page.route('**/api/planner/time-blocks', async route => {
    if (route.request().method() !== 'POST') return route.continue()
    await route.fetch()
    await route.abort('internetdisconnected')
  })
  const capture = async () => {
    await page.getByRole('button', { name: 'Add a block', exact: true }).click()
    const form = page.getByRole('form', { name: 'Add a time block', exact: true })
    await form.getByLabel('What is it?', { exact: true }).fill('Repeated appointment')
    await form.getByRole('button', { name: 'Add block', exact: true }).click()
    await expect(form).toHaveCount(0)
  }
  await capture()
  const firstId = await page.evaluate(async () => {
    const w = '/src/offline/workspace.ts'
    const { commands } = await import(/* @vite-ignore */ w)
    return (await commands())[0].localId
  })
  await page.route(api, route => route.abort('internetdisconnected'))
  await capture()
  await expect(page.getByRole('listitem', { name: 'Repeated appointment', exact: true })).toHaveCount(2)
  await page.evaluate(async id => {
    const h = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ h)
    await jsonRequest(`/planner/time-blocks/${id}`, 'PATCH', { title: 'Changed lost receipt' })
  }, firstId)
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Repeated appointment', exact: true })).toHaveCount(1)
  await expect(page.getByRole('listitem', { name: 'Changed lost receipt', exact: true })).toHaveCount(1)
  await page.unroute(api)
  await page.unroute('**/api/planner/time-blocks')
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  const rows = (await (await page.request.get('/api/planner/day', { headers: await xsrfHeader(page) })).json()).entries.filter((entry: { source: string }) => entry.source === 'time_block')
  expect(rows).toHaveLength(2)
  expect(rows.map((entry: { title: string }) => entry.title).sort()).toEqual(['Changed lost receipt', 'Repeated appointment'])
})

test('offline time blocks can be created, edited and moved before reconnecting', async ({ page }, info) => {
  test.setTimeout(75_000)
  if (info.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 740 })
  await registerViaUi(page, uniqueCredentials(info, 'LocalBlock'), { redirectTo: '/planner' })
  await expect(page.getByRole('heading', { name: 'The day', exact: true })).toBeVisible()
  const tomorrow = await page.evaluate(async () => {
    const h = '/src/api/http.ts'
    const { request } = await import(/* @vite-ignore */ h)
    const day = await request('/planner/day')
    const next = new Date(`${day.date}T00:00:00Z`)
    next.setUTCDate(next.getUTCDate() + 1)
    const date = next.toISOString().slice(0, 10)
    await request(`/planner/day?date=${date}`)
    return date
  })
  await page.route(api, route => route.abort('internetdisconnected'))
  await page.getByRole('button', { name: 'Add a block', exact: true }).click()
  const add = page.getByRole('form', { name: 'Add a time block', exact: true })
  await add.getByLabel('What is it?', { exact: true }).fill('Offline appointment')
  await setTime(add, 'Starts at', '14:00')
  await setTime(add, 'Ends at', '15:00')
  await add.getByRole('button', { name: 'Add block', exact: true }).click()
  await expect(page.getByRole('listitem', { name: 'Offline appointment', exact: true })).toContainText('Awaiting synchronization')
  await page.getByRole('button', { name: 'Edit Offline appointment', exact: true }).click()
  const edit = page.getByRole('form', { name: 'Edit time block', exact: true })
  await edit.getByLabel('What is it?', { exact: true }).fill('Moved appointment')
  await edit.getByLabel('Note', { exact: true }).fill('Take the documents')
  await pickDate(page, 'Block date', tomorrow)
  await edit.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByRole('listitem', { name: 'Moved appointment', exact: true })).toHaveCount(0)
  await page.getByRole('button', { name: 'Next day', exact: true }).click()
  const moved = page.getByRole('listitem', { name: 'Moved appointment', exact: true })
  await expect(moved).toBeVisible()
  await expectNoHorizontalOverflow(page)
  await page.reload()
  await expect(page.getByRole('heading', { name: 'The day', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Next day', exact: true }).click()
  await expect(moved).toBeVisible()
  await page.getByRole('button', { name: 'Edit Moved appointment', exact: true }).click()
  await expect(edit.getByLabel('Note', { exact: true })).toHaveValue('Take the documents')
  await edit.getByLabel('Note', { exact: true }).fill('Draft while synchronizing')
  await page.unroute(api)
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  await expect(edit.getByLabel('Note', { exact: true })).toHaveValue('Draft while synchronizing')
  await edit.getByRole('button', { name: 'Save', exact: true }).click()
  const result = await page.request.get(`/api/planner/day?date=${tomorrow}`, { headers: await xsrfHeader(page) })
  const blocks = (await result.json()).entries.filter((entry: { source: string }) => entry.source === 'time_block')
  expect(blocks).toHaveLength(1)
  expect(blocks[0]).toMatchObject({ title: 'Moved appointment', time: '14:00', meta: { ends_at: '15:00', note: 'Draft while synchronizing' } })
})

test('temporary block IDs stay separate from Storage, and a dependent delete survives reload', async ({ page }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'BlockNamespace'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  const date = await page.evaluate(async () => {
    const h = '/src/api/http.ts'
    const { request } = await import(/* @vite-ignore */ h)
    return (await request('/planner/day')).date
  })
  await page.route(api, route => route.abort('internetdisconnected'))
  const drafts = await page.evaluate(async date => {
    const h = '/src/api/http.ts'
    const w = '/src/offline/workspace.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ h)
    const { commands, discardCommand } = await import(/* @vite-ignore */ w)
    const block = (await jsonRequest('/planner/time-blocks', 'POST', { title: 'Delete offline block', block_date: date })).data
    const item = (await jsonRequest('/storage/items', 'POST', { title: 'Keep offline task', due_on: date })).data
    await jsonRequest(`/planner/time-blocks/${block.id}`, 'DELETE', null)
    const created = (await commands()).find((row: { path: string; localId: number }) => row.path === '/planner/time-blocks' && row.localId === block.id)
    let message = ''
    try { await discardCommand(created.id) } catch (error) { message = (error as Error).message }
    return { blockId: block.id, itemId: item.id, message }
  }, date)
  expect(drafts.blockId).toBeLessThan(0)
  expect(drafts.itemId).toBeLessThan(0)
  expect(drafts.blockId).toBe(drafts.itemId)
  expect(drafts.message).toContain('Remove dependent drafts first')
  await page.goto('/planner')
  await expect(page.getByRole('listitem', { name: 'Keep offline task', exact: true })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Delete offline block', exact: true })).toHaveCount(0)
  await page.unroute(api)
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  const result = (await (await page.request.get(`/api/planner/day?date=${date}`, { headers: await xsrfHeader(page) })).json()).entries
  expect(result.filter((entry: { source: string }) => entry.source === 'time_block')).toEqual([])
  expect(result.filter((entry: { source: string }) => entry.source === 'storage')).toEqual([expect.objectContaining({ title: 'Keep offline task' })])
})

test('a failed block receipt retains its identity and calendar draft until the same operation is replayed', async ({ page }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'BlockReceipt'), { redirectTo: '/planner' })
  await expect(page.getByRole('heading', { name: 'The day', exact: true })).toBeVisible()
  await page.evaluate(() => {
    const original = IDBObjectStore.prototype.put
    IDBObjectStore.prototype.put = function (value, key) {
      if (String(key).includes(':entity:time-block:')) throw new DOMException('Simulated block receipt failure', 'QuotaExceededError')
      return original.call(this, value, key)
    }
  })
  await page.getByRole('button', { name: 'Add a block', exact: true }).click()
  const form = page.getByRole('form', { name: 'Add a time block', exact: true })
  await form.getByLabel('What is it?', { exact: true }).fill('Recover this block')
  await form.getByRole('button', { name: 'Add block', exact: true }).click()
  await expect(page.getByText('Pending changes: 1', { exact: true })).toBeVisible()
  await expect(form.getByLabel('What is it?', { exact: true })).toHaveValue('Recover this block')
  const state = await page.evaluate(async () => {
    const d = '/src/offline/database.ts'
    const w = '/src/offline/workspace.ts'
    const { localEntries } = await import(/* @vite-ignore */ d)
    const { workspaceState } = await import(/* @vite-ignore */ w)
    return await localEntries(`account:${workspaceState.owner}:`)
  })
  expect(state.filter((entry: { key: string }) => entry.key.includes(':identity:time-block:'))).toEqual([])
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Recover this block', exact: true })).toHaveCount(1, { timeout: 15_000 })
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  const result = (await (await page.request.get('/api/planner/day', { headers: await xsrfHeader(page) })).json()).entries
  expect(result.filter((entry: { title: string }) => entry.title === 'Recover this block')).toHaveLength(1)
})
