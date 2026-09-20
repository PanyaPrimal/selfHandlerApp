import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials } from './support/auth'

async function seedOldDrafts(page: Page) {
  return page.evaluate(async () => {
    const dbPath = '/src/offline/database.ts'
    const workspacePath = '/src/offline/workspace.ts'
    const { localWrite } = await import(/* @vite-ignore */ dbPath)
    const { workspaceState, refreshQueue, commands } = await import(/* @vite-ignore */ workspacePath)
    const owner = workspaceState.owner
    for (const [index, status] of ['conflict', 'rejected', 'pending'].entries()) {
      const id = crypto.randomUUID()
      await localWrite(`account:${owner}:command:${id}`, {
        id, owner, path: index === 0 ? '/habits/999999' : '/sleep/plans',
        method: index === 0 ? 'PATCH' : 'POST', body: JSON.stringify({ name: `Old draft ${index}` }),
        base: 0, created: index + 1, status, message: 'Old saved draft', title: `Old draft ${index}`,
      })
    }
    await refreshQueue()
    return commands()
  })
}

async function readDrafts(page: Page) {
  return page.evaluate(async () => {
    const path = '/src/offline/workspace.ts'
    return (await import(/* @vite-ignore */ path)).commands()
  })
}

function inputs(rows: Array<{ id: string; body: string; path: string }>) {
  return rows.map(({ id, body, path }) => ({ id, body, path }))
}

test('online sleep form validates and saves despite three older drafts', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'OnlineSleepDrafts'), { redirectTo: '/routines' })
  const form = page.getByRole('form', { name: 'Create sleep plan' })
  await expect(form).toBeVisible()
  const before = await seedOldDrafts(page)

  // Invalid input must reach server validation, not masquerade as an offline save.
  const invalid = page.waitForResponse(response => response.url().endsWith('/api/sleep/plans') && response.request().method() === 'POST')
  await form.getByRole('button', { name: 'Create sleep plan' }).click()
  expect((await invalid).status()).toBe(422)
  await expect(form.getByLabel('Plan name')).toHaveAttribute('aria-invalid', 'true')
  expect(inputs(await readDrafts(page))).toEqual(inputs(before))

  await form.getByLabel('Plan name').fill('Normal nightly sleep')
  await form.getByRole('button', { name: 'Create sleep plan' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Sleep plan created.' })).toBeVisible()
  await expect(page.getByRole('listitem', { name: 'Normal nightly sleep', exact: true })).toHaveCount(1)
  expect(inputs(await readDrafts(page))).toEqual(inputs(before))
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Normal nightly sleep', exact: true })).toHaveCount(1)
  expect(inputs(await readDrafts(page))).toEqual(inputs(before))
})

test('explicit online save retries the same draft without creating a second operation', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'OnlineRetryDraft'), { redirectTo: '/routines' })
  await expect(page.getByRole('form', { name: 'Create sleep plan' })).toBeVisible()
  const result = await page.evaluate(async () => {
    const workspacePath = '/src/offline/workspace.ts'
    const httpPath = '/src/api/http.ts'
    const { prepareCommand, rejectCommand, workspaceState, commands } = await import(/* @vite-ignore */ workspacePath)
    const { jsonRequest } = await import(/* @vite-ignore */ httpPath)
    const body = { name: 'Recovered sleep', planned_bed_time: '23:00', planned_wake_time: '06:00', schedule_type: 'daily' }
    const command = await prepareCommand(workspaceState.owner, '/sleep/plans', { method: 'POST', body: JSON.stringify(body) })
    await rejectCommand(command, { status: 409, message: 'Old revision' })
    const first = await jsonRequest('/sleep/plans', 'POST', body)
    const second = await jsonRequest('/sleep/plans', 'POST', body, { operationId: command.id })
    return { first: first.data.id, second: second.data.id, remaining: (await commands()).length }
  })
  expect(result.first).toBe(result.second)
  expect(result.remaining).toBe(0)
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Recovered sleep', exact: true })).toHaveCount(1)
})

test('sleep saves automatically when the server recovers without a browser online event', async ({ page }, info) => {
  test.setTimeout(45_000)
  await registerViaUi(page, uniqueCredentials(info, 'ServerRecovery'), { redirectTo: '/routines' })
  const form = page.getByRole('form', { name: 'Create sleep plan' })
  await expect(form).toBeVisible()
  await page.route('**/api/sleep/plans', route => route.fulfill({ status: 503, json: { message: 'Temporarily unavailable' } }))
  await form.getByLabel('Plan name').fill('Automatic recovery')
  await form.getByRole('button', { name: 'Create sleep plan' }).click()
  await expect(page.getByRole('status').filter({ hasText: 'Saved on this device' })).toBeVisible()
  expect(await page.evaluate(() => navigator.onLine)).toBe(true)
  expect(await readDrafts(page)).toHaveLength(1)
  // No synchronize button, focus event, reload, or connection event is used.
  await page.unroute('**/api/sleep/plans')
  await expect(page.getByRole('listitem', { name: 'Automatic recovery', exact: true })).toHaveCount(1, { timeout: 20_000 })
  expect(await readDrafts(page)).toHaveLength(0)
  await expect(page.getByRole('button', { name: 'Synchronize', exact: true })).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Automatic recovery', exact: true })).toHaveCount(1)
})

test('an invalid draft does not block automatic recovery of a legacy conflict', async ({ page }, info) => {
  test.setTimeout(45_000)
  await registerViaUi(page, uniqueCredentials(info, 'LegacyAutoRecovery'), { redirectTo: '/routines' })
  await expect(page.getByRole('form', { name: 'Create sleep plan' })).toBeVisible()
  await page.evaluate(async () => {
    const dbPath = '/src/offline/database.ts'
    const path = '/src/offline/workspace.ts'
    const { localWrite } = await import(/* @vite-ignore */ dbPath)
    const { workspaceState, refreshQueue } = await import(/* @vite-ignore */ path)
    for (const [index, name] of ['', 'Recovered legacy plan'].entries()) {
      const id = crypto.randomUUID()
      await localWrite(`account:${workspaceState.owner}:command:${id}`, {
        id, owner: workspaceState.owner, path: '/sleep/plans', method: 'POST',
        body: JSON.stringify({ name, planned_bed_time: '23:00', planned_wake_time: '06:00', schedule_type: 'daily' }),
        created: index + 1, base: null, status: index === 0 ? 'pending' : 'conflict', message: null, title: name,
      })
    }
    await refreshQueue()
  })
  await expect(page.getByRole('listitem', { name: 'Recovered legacy plan', exact: true })).toHaveCount(1, { timeout: 20_000 })
  const remaining = await readDrafts(page)
  expect(remaining).toHaveLength(1)
  expect(remaining[0].status).toBe('rejected')
  expect(JSON.parse(remaining[0].body).name).toBe('')
  await expect(page.getByText('Check your input: 1', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Pending changes', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Synchronize', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Apply after review', exact: true })).toHaveCount(0)
})

test('reconnecting sends an older edit before the users newest online edit', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'OrderedOnlineSave'), { redirectTo: '/routines' })
  await expect(page.getByRole('form', { name: 'Create sleep plan' })).toBeVisible()
  const id = await page.evaluate(async () => {
    const path = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ path)
    return (await jsonRequest('/sleep/plans', 'POST', { name: 'Initial sleep', planned_bed_time: '23:00', planned_wake_time: '06:00', schedule_type: 'daily' })).data.id
  })
  await page.route(`**/api/sleep/plans/${id}`, route => route.abort('internetdisconnected'))
  await page.evaluate(async id => {
    const path = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ path)
    try { await jsonRequest(`/sleep/plans/${id}`, 'PATCH', { name: 'Older offline edit' }) } catch { /* queued */ }
  }, id)
  await page.unroute(`**/api/sleep/plans/${id}`)
  await page.evaluate(async id => {
    const path = '/src/api/http.ts'
    const { jsonRequest } = await import(/* @vite-ignore */ path)
    await jsonRequest(`/sleep/plans/${id}`, 'PATCH', { name: 'Newest online edit' })
  }, id)
  expect(await readDrafts(page)).toHaveLength(0)
  await page.reload()
  await expect(page.getByRole('listitem', { name: 'Newest online edit', exact: true })).toHaveCount(1)
  await expect(page.getByRole('listitem', { name: 'Older offline edit', exact: true })).toHaveCount(0)
})
