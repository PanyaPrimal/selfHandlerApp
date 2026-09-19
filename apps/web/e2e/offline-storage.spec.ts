import { expect, test } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from './support/auth'
import { expectNoHorizontalOverflow } from './interface/support'

const api = /^https?:\/\/[^/]+\/api\//

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
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
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
      if (String(key).includes(':identity:storage:')) throw new DOMException('Simulated receipt storage failure', 'QuotaExceededError')
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
  await page.getByRole('button', { name: 'Synchronize', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0)
  await expect(page.getByRole('listitem', { name: 'Atomic shelf', exact: true })).toHaveCount(1)
  const headers = await xsrfHeader(page)
  const items = (await (await page.request.get('/api/storage/items', { headers })).json()).data
  expect(items.filter((row: { title: string }) => row.title === 'Atomic shelf')).toHaveLength(1)
})
