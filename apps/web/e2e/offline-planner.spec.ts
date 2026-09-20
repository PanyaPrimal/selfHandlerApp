import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from './support/auth'
import { expectNoHorizontalOverflow, pickDate } from './interface/support'

const api = /^https?:\/\/[^/]+\/api\//

test('offline task moves and completion update downloaded calendar days through reload and sync', async ({ page }, info) => {
  test.setTimeout(75_000)
  if (info.project.name === 'mobile') await page.setViewportSize({ width: 320, height: 740 })
  await registerViaUi(page, uniqueCredentials(info, 'LocalPlanner'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  const seed = await page.evaluate(async () => {
    const h = '/src/api/http.ts'
    const { request, jsonRequest } = await import(/* @vite-ignore */ h)
    const { date } = await request('/planner/day')
    const next = new Date(`${date}T00:00:00Z`)
    next.setUTCDate(next.getUTCDate() + 1)
    const tomorrow = next.toISOString().slice(0, 10)
    const { data: item } = await jsonRequest('/storage/items', 'POST', { title: 'Offline calendar task', due_on: date })
    for (const path of ['/storage/items', '/storage/projects', '/planner/day', `/planner/day?date=${date}`, `/planner/day?date=${tomorrow}`]) await request(path)
    return { date, tomorrow, id: item.id }
  })
  await page.goto('/planner')
  const row = page.getByRole('listitem', { name: 'Offline calendar task', exact: true })
  await expect(row).toBeVisible()
  await page.route(api, route => route.abort('internetdisconnected'))
  await page.getByRole('button', { name: 'Move Offline calendar task', exact: true }).click()
  await pickDate(page, 'Move to', seed.tomorrow)
  await page.getByRole('form', { name: 'Move Offline calendar task', exact: true }).getByRole('button', { name: 'Move', exact: true }).click()
  await expect(row).toHaveCount(0)
  await page.getByRole('button', { name: 'Next day', exact: true }).click()
  await expect(row).toBeVisible()
  await expect(row).toContainText('Awaiting synchronization')
  await expectNoHorizontalOverflow(page)
  await page.reload()
  await expect(row).toHaveCount(0)
  await page.getByRole('button', { name: 'Next day', exact: true }).click()
  await expect(row).toBeVisible()
  await page.evaluate(async id => {
    const h = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ h)
    await jsonRequest(`/storage/items/${id}`, 'PATCH', { status: 'done' })
  }, seed.id)
  await page.reload()
  await page.getByRole('button', { name: 'Next day', exact: true }).click()
  await expect(row).toHaveCount(0)
  await page.unroute(api)
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  await page.route(api, route => route.abort('internetdisconnected'))
  const persisted = await page.evaluate(async seed => {
    const h = '/src/api/http.ts'
    const { request } = await import(/* @vite-ignore */ h)
    return { today: await request(`/planner/day?date=${seed.date}`), tomorrow: await request(`/planner/day?date=${seed.tomorrow}`), items: await request('/storage/items') }
  }, seed)
  expect(persisted.today.entries.some((entry: { source: string; source_id: number }) => entry.source === 'storage' && entry.source_id === seed.id)).toBe(false)
  expect(persisted.tomorrow.entries.some((entry: { source: string; source_id: number }) => entry.source === 'storage' && entry.source_id === seed.id)).toBe(false)
  expect(persisted.items.data.find((item: { id: number }) => item.id === seed.id)).toMatchObject({ status: 'done', due_on: seed.tomorrow })
})

test('a failed calendar receipt rolls back Storage and retries the same operation', async ({ page }, info) => {
  test.setTimeout(75_000)
  await registerViaUi(page, uniqueCredentials(info, 'PlannerReceipt'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  const seed = await page.evaluate(async () => {
    const h = '/src/api/http.ts'
    const { request, jsonRequest } = await import(/* @vite-ignore */ h)
    const { date } = await request('/planner/day')
    const { data: item } = await jsonRequest('/storage/items', 'POST', { title: 'Calendar rollback task', due_on: date })
    for (const path of ['/storage/items', '/storage/projects', '/planner/day', `/planner/day?date=${date}`]) await request(path)
    return { id: item.id, date }
  })
  await page.route(api, route => route.abort('internetdisconnected'))
  const operation = await page.evaluate(async id => {
    const h = '/src/api/http.ts'
    const w = '/src/offline/workspace.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ h)
    const { commands } = await import(/* @vite-ignore */ w)
    await jsonRequest(`/storage/items/${id}`, 'PATCH', { status: 'done' })
    return (await commands())[0].id
  }, seed.id)
  await page.evaluate(() => {
    const original = IDBObjectStore.prototype.put
    IDBObjectStore.prototype.put = function (value, key) {
      if (String(key).includes('read:/planner/day')) throw new DOMException('Simulated calendar receipt failure', 'QuotaExceededError')
      return original.call(this, value, key)
    }
  })
  await page.unroute(api)
  const afterFailure = await page.evaluate(async () => {
    const w = '/src/offline/workspace.ts'
    const d = '/src/offline/database.ts'
    const { synchronizeWorkspace, commands, workspaceState } = await import(/* @vite-ignore */ w)
    const { localEntries } = await import(/* @vite-ignore */ d)
    await synchronizeWorkspace()
    return { commands: await commands(), entries: await localEntries(`account:${workspaceState.owner}:read:`) }
  })
  expect(afterFailure.commands.map((command: { id: string }) => command.id)).toEqual([operation])
  expect(afterFailure.entries.find((entry: { key: string }) => entry.key.endsWith('read:/storage/items')).value.data.data[0].status).toBe('inbox')
  expect(afterFailure.entries.find((entry: { key: string }) => entry.key.endsWith(`read:/planner/day?date=${seed.date}`)).value.data.entries).toHaveLength(1)
  // Reload restores IndexedDB, and session restoration replays the preserved operation UUID.
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Capture now, sort later', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0, { timeout: 15_000 })
  await expect.poll(async () => page.evaluate(async () => {
    const w = '/src/offline/workspace.ts'
    const { commands } = await import(/* @vite-ignore */ w)
    return (await commands()).length
  })).toBe(0)
  await page.route(api, route => route.abort('internetdisconnected'))
  const final = await page.evaluate(async seed => {
    const h = '/src/api/http.ts'
    const { request } = await import(/* @vite-ignore */ h)
    return { items: await request('/storage/items'), day: await request(`/planner/day?date=${seed.date}`) }
  }, seed)
  expect(final.items.data.find((item: { id: number }) => item.id === seed.id).status).toBe('done')
  expect(final.day.entries.some((entry: { source: string; source_id: number }) => entry.source === 'storage' && entry.source_id === seed.id)).toBe(false)
})

test('a late failed calendar read keeps tasks captured while that request was in flight', async ({ page }, info) => {
  test.setTimeout(60_000)
  await registerViaUi(page, uniqueCredentials(info, 'PlannerRace'), { redirectTo: '/storage' })
  await expect(page.getByText('Nothing waiting. Anything you capture lands here until you sort it.', { exact: true })).toBeVisible()
  const date = await page.evaluate(async () => {
    const h = '/src/api/http.ts'
    const { request } = await import(/* @vite-ignore */ h)
    const day = await request('/planner/day')
    await request(`/planner/day?date=${day.date}`)
    return day.date
  })
  let start!: () => void
  let finish!: () => void
  const started = new Promise<void>(resolve => { start = resolve })
  const finished = new Promise<void>(resolve => { finish = resolve })
  await page.route(`**/api/planner/day?date=${date}`, async route => {
    start()
    await finished
    await route.abort('internetdisconnected')
  })
  const read = page.evaluate(async date => {
    const h = '/src/api/http.ts'
    const { request } = await import(/* @vite-ignore */ h)
    return await request(`/planner/day?date=${date}`)
  }, date)
  await started
  await page.route('**/api/storage/items', route => route.abort('internetdisconnected'))
  try {
    await page.evaluate(async date => {
      const h = '/src/api/http.ts'
      const { jsonRequest } = await import(/* @vite-ignore */ h)
      await jsonRequest('/storage/items', 'POST', { title: 'Created during read', due_on: date })
    }, date)
  } finally { finish() }
  const result = await read
  expect(result.entries).toEqual([expect.objectContaining({ source: 'storage', title: 'Created during read' })])
})
