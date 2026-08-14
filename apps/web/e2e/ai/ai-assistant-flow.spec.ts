import { expect, test, type Page } from '@playwright/test'
import { collectRuntimeIssues, expectNoRuntimeIssues } from '../core-daily-loop/support'
import { expectNoHorizontalOverflow, gotoDestination, setCheckbox } from '../interface/support'
import { registerViaUi, uniqueCredentials } from '../support/auth'
import { emptyAiState, mockAiRoutes, readyAiState } from './support'

async function capture(page: Page, title: string): Promise<Record<string, unknown>> {
  const form = page.getByRole('form', { name: 'Capture an item' })
  const saved = page.waitForResponse((response) => response.request().method() === 'POST'
    && new URL(response.url()).pathname === '/api/storage/items')
  await form.getByLabel('What is on your mind?').fill(title)
  await form.getByRole('button', { name: 'Capture' }).click()
  await expect(page.getByRole('listitem').filter({ hasText: title })).toBeVisible()
  return ((await saved).json() as Promise<{ data: Record<string, unknown> }>).then((response) => response.data)
}

test('connection keys stay masked through test, activation, rotation, reload and delete', async ({ page }, testInfo) => {
  const state = emptyAiState()
  await mockAiRoutes(page, state)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AiSettings'), { redirectTo: '/settings/ai' })
  const issues = collectRuntimeIssues(page)

  await page.getByLabel('Connection name').fill('My Anthropic')
  await page.getByLabel('Model ID').fill('fixture-model')
  await page.getByLabel('API key').fill('fixture-abcdef')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByText('••••cdef')).toBeVisible()
  await expect(page.getByLabel('API key')).toHaveValue('')

  await page.getByRole('button', { name: 'Test connection' }).click()
  await expect(page.getByText('Ready', { exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Make active' }).click()
  await expect(page.getByText('My Anthropic is now the active connection.')).toBeVisible()

  await page.reload()
  await expect(page.getByText('••••cdef')).toBeVisible()
  await page.getByRole('button', { name: 'Edit' }).click()
  await page.getByLabel('New API key (optional)').fill('fixture-rotated-9876')
  await page.getByRole('button', { name: 'Save', exact: true }).click()
  await expect(page.getByText('••••9876')).toBeVisible()
  await expect(page.getByText('Needs test', { exact: true })).toBeVisible()
  expect(state.activeConnectionId).toBeNull()

  const stored = await page.evaluate(async () => ({
    local: Object.values(localStorage),
    session: Object.values(sessionStorage),
    databases: 'databases' in indexedDB ? (await indexedDB.databases()).map((database) => database.name) : [],
  }))
  expect(JSON.stringify(stored)).not.toContain('fixture-')

  await page.getByRole('button', { name: 'Delete' }).click()
  await page.getByRole('button', { name: 'Delete permanently' }).click()
  await expect(page.getByText('No provider connection yet.')).toBeVisible()
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})

test('consent gates a disclosed proposal and confirmation performs one visible Storage update', async ({ page }, testInfo) => {
  const state = readyAiState(false)
  const externalHosts: string[] = []
  page.on('request', (request) => {
    const host = new URL(request.url()).hostname
    if (!['127.0.0.1', 'localhost'].includes(host)) externalHosts.push(host)
  })
  await mockAiRoutes(page, state)
  await registerViaUi(page, uniqueCredentials(testInfo, 'AiTriage'), { redirectTo: '/settings/ai' })
  const issues = collectRuntimeIssues(page)

  await gotoDestination(page, 'Storage')
  await expect(page.getByText('Review and grant the Storage Inbox scope')).toBeVisible()
  expect(state.draftCalls).toBe(0)

  await page.goto('/settings/ai')
  await setCheckbox(page, 'Allow external AI processing for selected Storage Inbox items', true)
  const consentGranted = page.waitForResponse((response) => response.request().method() === 'PUT'
    && new URL(response.url()).pathname === '/api/ai/consents/storage-inbox')
  await page.getByRole('button', { name: 'Save sharing preference' }).click()
  await consentGranted
  await expect(page.getByText('Storage Inbox sharing is enabled.')).toBeVisible()

  await page.goto('/storage')
  const item = await capture(page, 'Prepare tax documents')
  state.confirmedItem = {
    ...item,
    type: 'task',
    status: 'active',
    priority: 'high',
    due_on: '2026-08-20',
    tags: [{ id: 901, name: 'next-step' }],
  }

  await page.getByRole('button', { name: 'Ask AI for a proposal for Prepare tax documents' }).click()
  await expect(page.locator('.storage-ai-proposal')).toBeFocused()
  await expect(page.getByText('This is only a proposal.')).toBeVisible()
  expect(state.draftCalls).toBe(1)
  await expect(page.getByText('1 unsorted')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Triage Prepare tax documents' })).toBeVisible()

  await page.getByRole('button', { name: 'Dismiss' }).click()
  await expect(page.getByText('Proposal dismissed. No Storage data changed.')).toBeVisible()
  expect(state.confirmationCalls).toBe(0)

  await page.getByRole('button', { name: 'Ask AI for a proposal for Prepare tax documents' }).click()
  await page.getByRole('button', { name: 'Confirm and apply' }).click()
  await expect(page.getByText('Proposal confirmed and applied once.')).toBeVisible()
  await expect(page.getByRole('button', { name: 'Triage Prepare tax documents' })).toHaveCount(0)
  await expect(page.getByText('next-step')).toBeVisible()
  expect(state.confirmationCalls).toBe(1)

  await page.goto('/settings/ai')
  await setCheckbox(page, 'Allow external AI processing for selected Storage Inbox items', false)
  const consentRevoked = page.waitForResponse((response) => response.request().method() === 'PUT'
    && new URL(response.url()).pathname === '/api/ai/consents/storage-inbox')
  await page.getByRole('button', { name: 'Save sharing preference' }).click()
  await consentRevoked
  await page.goto('/storage')
  await expect(page.getByText('Review and grant the Storage Inbox scope')).toBeVisible()
  expect(state.draftCalls).toBe(2)

  const stored = await page.evaluate(() => JSON.stringify({
    local: Object.values(localStorage),
    session: Object.values(sessionStorage),
  }))
  expect(stored).not.toContain('fixture-confirmation-')
  expect(externalHosts).toEqual([])
  await expectNoHorizontalOverflow(page)
  expectNoRuntimeIssues(issues)
})
