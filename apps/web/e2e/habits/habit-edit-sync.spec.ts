import { expect, test, type Page } from '@playwright/test'
import { registerViaUi, uniqueCredentials, xsrfHeader } from '../support/auth'
import { chooseOption, expectNoHorizontalOverflow, setTime } from '../interface/support'

async function createHabit(page: Page, name: string): Promise<number> {
  await page.getByRole('button', { name: 'New habit' }).click()
  const form = page.getByRole('form', { name: 'Create habit' })
  await form.getByLabel('Name').fill(name)
  const saved = page.waitForResponse(response => response.request().method() === 'POST' && new URL(response.url()).pathname === '/api/habits')
  await form.getByRole('button', { name: 'Create habit', exact: true }).click()
  const response = await saved
  expect(response.status()).toBe(201)
  await expect(page.getByRole('listitem', { name, exact: true })).toBeVisible()
  return (await response.json()).data.id
}

test('yes-no habit saves changed weekdays and start time without queueing', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'HabitSchedule'), { redirectTo: '/habits' })
  const id = await createHabit(page, 'River routine')
  await page.getByRole('listitem', { name: 'River routine', exact: true }).getByRole('button', { name: 'Edit', exact: true }).click()
  const form = page.getByRole('form', { name: 'Edit habit', exact: true })
  await chooseOption(form, 'Schedule', 'Selected weekdays')
  for (const day of ['Tue', 'Thu', 'Sat']) await form.getByRole('button', { name: day, exact: true }).click()
  await setTime(form, 'Time', '07:30')
  const saved = page.waitForResponse(response => response.request().method() === 'PATCH' && new URL(response.url()).pathname === `/api/habits/${id}`)
  await form.getByRole('button', { name: 'Save changes', exact: true }).click()
  const response = await saved
  expect(response.status()).toBe(200)
  expect(response.request().postDataJSON()).not.toHaveProperty('target_value')
  expect(response.request().postDataJSON()).not.toHaveProperty('unit')
  await expect(page.getByRole('button', { name: 'Pending changes', exact: true })).toHaveCount(0)
  await page.reload()
  await page.getByRole('listitem', { name: 'River routine', exact: true }).getByRole('button', { name: 'Edit', exact: true }).click()
  for (const day of ['Tue', 'Thu', 'Sat']) await expect(form.getByRole('button', { name: day, exact: true })).toHaveAttribute('aria-pressed', 'true')
  const records = await (await page.request.get('/api/habits', { headers: await xsrfHeader(page) })).json()
  expect(records.data.find((row: { id: number }) => row.id === id).schedule).toMatchObject({ weekdays: ['TU', 'TH', 'SA'], preferred_time: '07:30' })
  await expectNoHorizontalOverflow(page)
})

test('rejected legacy habit draft retries without losing the following change and stays in a corner', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'HabitDraft'), { redirectTo: '/habits' })
  const id = await createHabit(page, 'River draft')
  const heading = page.getByRole('heading', { name: 'Habits & anti-habits', exact: true })
  const before = await heading.boundingBox()
  await page.evaluate(async habitId => {
    const workspacePath = '/src/offline/workspace.ts'
    const { prepareCommand, rejectCommand, workspaceState } = await import(/* @vite-ignore */ workspacePath)
    const draft = await prepareCommand(workspaceState.owner, `/habits/${habitId}`, {
      method: 'PATCH', body: JSON.stringify({ name: 'River draft', target_value: null, unit: null, schedule_type: 'weekdays', weekdays: ['TU', 'TH'], preferred_time: '06:45' }),
    })
    await rejectCommand(draft, { status: 422, message: 'This tracking mode does not use a numeric target.' })
    await prepareCommand(workspaceState.owner, `/habits/${habitId}`, { method: 'PATCH', body: JSON.stringify({ intention_place: 'Riverbank' }) })
  }, id)
  const toggle = page.getByRole('button', { name: 'Pending changes', exact: true })
  await expect(toggle).toHaveAttribute('aria-expanded', 'false')
  await expect(page.getByRole('region', { name: 'Pending changes' })).toHaveCount(0)
  expect((await heading.boundingBox())!.y).toBe(before!.y)
  const bounds = (await toggle.boundingBox())!
  expect(bounds.x + bounds.width).toBeGreaterThan(page.viewportSize()!.width - 35)
  expect(bounds.y).toBeGreaterThan(page.viewportSize()!.height / 2)
  await toggle.click()
  const panel = page.getByRole('region', { name: 'Pending changes', exact: true })
  await expect(panel).toContainText('This tracking mode does not use a numeric target.')
  await page.screenshot({ path: info.outputPath('habit-sync-panel.png') })
  await panel.press('Escape')
  await expect(toggle).toBeFocused()
  await expect(panel).toHaveCount(0)
  await toggle.click()
  await panel.getByRole('button', { name: 'Try again', exact: true }).click()
  await expect(toggle).toHaveCount(0, { timeout: 15_000 })
  await page.reload()
  const records = await (await page.request.get('/api/habits', { headers: await xsrfHeader(page) })).json()
  expect(records.data.find((row: { id: number }) => row.id === id)).toMatchObject({
    intention_place: 'Riverbank', schedule: { weekdays: ['TU', 'TH'], preferred_time: '06:45' },
  })
  await expectNoHorizontalOverflow(page)
})

test('retrying a rejected draft still protects a newer server edit', async ({ page }, info) => {
  await registerViaUi(page, uniqueCredentials(info, 'HabitConflict'), { redirectTo: '/habits' })
  const id = await createHabit(page, 'Original habit')
  await page.evaluate(async habitId => {
    const workspacePath = '/src/offline/workspace.ts'
    const { prepareCommand, rejectCommand, workspaceState } = await import(/* @vite-ignore */ workspacePath)
    const draft = await prepareCommand(workspaceState.owner, `/habits/${habitId}`, { method: 'PATCH', body: JSON.stringify({ name: 'Older offline name', target_value: null, unit: null }) })
    await rejectCommand(draft, { status: 422, message: 'Old validation error' })
  }, id)
  const remote = await page.request.patch(`/api/habits/${id}`, { headers: await xsrfHeader(page), data: { name: 'Newer server name' } })
  expect(remote.status()).toBe(200)
  await page.getByRole('button', { name: 'Pending changes', exact: true }).click()
  await page.getByRole('button', { name: 'Try again', exact: true }).click()
  await expect(page.getByText('Needs review: server data changed', { exact: true })).toBeVisible()
  const records = await (await page.request.get('/api/habits', { headers: await xsrfHeader(page) })).json()
  expect(records.data.find((row: { id: number }) => row.id === id).name).toBe('Newer server name')
})
