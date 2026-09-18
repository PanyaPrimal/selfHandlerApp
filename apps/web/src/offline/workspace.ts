import { reactive } from 'vue'
import type { User } from '../api/types'
import { translate } from '../i18n'
import { localEntries, localRead, localRemove, localWrite } from './database'

export interface LocalCommand {
  id: string; owner: number; path: string; method: string; body: string | null
  base: number | null; created: number; status: 'pending' | 'conflict' | 'rejected'
  message: string | null; title: string
}
interface CachedRead { data: unknown; revision: number; saved: number }
type Sender = <T>(path: string, init?: RequestInit) => Promise<T>
export const workspaceState = reactive({ owner: null as number | null, online: navigator.onLine, syncing: false, pending: 0, issue: '', lastSync: null as number | null })
let rawSender: Sender | null = null
let generation = 0
let synchronizing: Promise<void> | null = null
const accountPrefix = (owner: number) => `account:${owner}:`
const commandKey = (command: LocalCommand) => `${accountPrefix(command.owner)}command:${command.id}`
export function workspacePath(path: string): boolean {
  return /^\/(routines|routine-selections|habits|goals|daily-reviews|periodic-reviews|review-workspaces|body|storage|planner|nutrition|exercises|workout-programs|workouts|training|supplements|supplement-courses|supplement-occurrences|supplement-restock-proposals|finance|sleep|today|analytics)(\/|\?|$)/.test(path)
}
export function configureWorkspace(sender: Sender) { rawSender = sender }
export async function rememberWorkspaceUser(user: User): Promise<void> {
  await localWrite('last-account', user)
}
export function activateWorkspace(owner: number | null) {
  if (workspaceState.owner !== owner) generation++
  workspaceState.owner = owner; workspaceState.pending = 0; workspaceState.issue = ''
  if (owner !== null) void refreshQueue().catch(storageFailure)
}
export async function rememberedWorkspaceUser(): Promise<User | undefined> { return localRead<User>('last-account') }
export async function forgetWorkspaceSession(): Promise<void> { activateWorkspace(null); await localRemove('last-account') }
function storageFailure() { workspaceState.issue = translate('offline.storageFailed') }
function signalChanged() { window.dispatchEvent(new Event('workspace-queue-changed')) }
export async function commands(): Promise<LocalCommand[]> {
  const owner = workspaceState.owner
  if (owner === null) return []
  const entries = await localEntries<LocalCommand>(`${accountPrefix(owner)}command:`)
  return entries.map(entry => entry.value).sort((a, b) => a.created - b.created || a.id.localeCompare(b.id))
}
export async function refreshQueue() {
  const current = generation
  const rows = await commands()
  if (current !== generation) return
  workspaceState.pending = rows.length; signalChanged()
}
export async function cacheRead(owner: number, path: string, data: unknown, revision: number) {
  if (!workspacePath(path)) return
  await localWrite(`${accountPrefix(owner)}read:${path}`, { data, revision, saved: Date.now() } satisfies CachedRead)
}
export async function cachedRead(owner: number, path: string): Promise<unknown> {
  const cached = await localRead<CachedRead>(`${accountPrefix(owner)}read:${path}`)
  if (!cached) throw new Error(translate('offline.notDownloaded'))
  workspaceState.online = false
  return cached.data
}
export async function prepareCommand(owner: number, path: string, init: RequestInit, operationId?: string): Promise<LocalCommand> {
  const body = typeof init.body === 'string' ? init.body : null
  const method = (init.method ?? 'POST').toUpperCase()
  const existing = await commands()
  const duplicate = existing.find(row => operationId ? row.id === operationId : row.path === path && row.method === method && row.body === body)
  if (duplicate && (duplicate.path !== path || duplicate.method !== method || duplicate.body !== body)) throw new Error(translate('offline.rejected'))
  if (duplicate) return duplicate
  const cached = await localEntries<CachedRead>(`${accountPrefix(owner)}read:`)
  // Conservative baseline: no offline intent may overwrite a version newer than any retained screen.
  const base = cached.length ? Math.min(...cached.map(row => row.value.revision)) : null
  let title = ''
  try { const payload = JSON.parse(body ?? '{}'); title = String(payload.title ?? payload.name ?? payload.note ?? '').slice(0, 120) } catch { /* empty DELETE */ }
  const command: LocalCommand = { id: operationId ?? crypto.randomUUID(), owner, path, method, body, base, created: Date.now(), status: 'pending', message: null, title }
  await localWrite(commandKey(command), command)
  await refreshQueue()
  return command
}
export function commandHeaders(command: LocalCommand, checkBase: boolean): Record<string, string> {
  return { 'X-Workspace-Operation': command.id, 'X-Workspace-Account': String(command.owner),
    ...(checkBase && command.base !== null ? { 'X-Workspace-Base': String(command.base) } : {}) }
}
export async function acknowledgeCommand(command: LocalCommand, revision: number, checkedBase: boolean) {
  // Only advance matching snapshots if the server proves there was no intervening writer.
  const ownTransition = checkedBase || (command.base !== null && revision === command.base + 1)
  if (ownTransition) {
    const cached = await localEntries<CachedRead>(`${accountPrefix(command.owner)}read:`)
    for (const entry of cached) {
      if (entry.value.revision === command.base) await localWrite(entry.key, { ...entry.value, revision })
    }
  }
  // A crash before deletion leaves the same UUID for server-side replay.
  const rows = await localEntries<LocalCommand>(`${accountPrefix(command.owner)}command:`)
  for (const { key, value } of rows) {
    if (ownTransition && value.id !== command.id && value.base === command.base && value.created >= command.created && value.status === 'pending') {
      await localWrite(key, { ...value, base: revision })
    }
  }
  await localRemove(commandKey(command)); await refreshQueue()
}
export async function rejectCommand(command: LocalCommand, error: unknown) {
  const problem = error as { status?: number; message?: string }
  if (problem.status === 0 || problem.status === undefined || problem.status === 401 || problem.status === 419 || problem.status >= 500 || problem.status === 429) return
  await localWrite(commandKey(command), { ...command, status: problem.status === 409 ? 'conflict' : 'rejected', message: problem.message ?? translate('offline.rejected') })
  await refreshQueue()
}
export async function discardCommand(id: string) {
  const command = (await commands()).find(row => row.id === id)
  if (command) await localRemove(commandKey(command))
  await refreshQueue()
}
export async function retryReviewedCommand(id: string) {
  const command = (await commands()).find(row => row.id === id)
  if (!command || !rawSender) return
  const current = await rawSender<{ user_id: number; revision: number }>('/workspace/revision', { headers: { 'X-Workspace-Account': String(command.owner) } })
  if (current.user_id !== command.owner || workspaceState.owner !== command.owner) throw new Error(translate('offline.accountChanged'))
  await localWrite(commandKey(command), { ...command, base: current.revision, status: 'pending', message: null })
  await synchronizeWorkspace()
}
export async function synchronizeWorkspace(): Promise<void> {
  if (synchronizing) return synchronizing
  if (!rawSender || workspaceState.owner === null || !navigator.onLine) return
  const owner = workspaceState.owner
  const current = generation
  const run = async () => {
    workspaceState.syncing = true
    try {
      const identity = await rawSender!<{ user_id: number }>('/workspace/revision', { headers: { 'X-Workspace-Account': String(owner) } })
      if (identity.user_id !== owner || current !== generation) return
      workspaceState.online = true
      for (;;) {
        const row = (await commands())[0]
        if (!row || row.status !== 'pending' || current !== generation) break
        if (row.base === null) {
          const receipt = await rawSender!<{ acknowledged: boolean }>(`/workspace/operations/${row.id}`, { headers: { 'X-Workspace-Account': String(owner) } })
          if (!receipt.acknowledged) {
            await localWrite(commandKey(row), { ...row, status: 'conflict', message: translate('offline.noBaseline') }); break
          }
        }
        try {
          await rawSender!(row.path, { method: row.method, body: row.body, headers: commandHeaders(row, true) })
          // HTTP response handling acknowledges using the revision header.
          if ((await commands()).some(command => command.id === row.id)) break
        } catch (e) { await rejectCommand(row, e); throw e }
      }
      if (current === generation) { workspaceState.lastSync = Date.now(); workspaceState.issue = '' }
    } catch (e) {
      if (current === generation) workspaceState.issue = e instanceof Error ? e.message : translate('offline.syncFailed')
    } finally { workspaceState.syncing = false; await refreshQueue() }
  }
  const promise = navigator.locks ? navigator.locks.request(`selfhandler-sync-${owner}`, run) : run()
  synchronizing = promise
  try { await promise } finally { if (synchronizing === promise) synchronizing = null }
}
export async function acceptResponse(owner: number, path: string, init: RequestInit, data: unknown, revision: number) {
  const id = new Headers(init.headers).get('X-Workspace-Operation')
  if (id) {
    const command = await localRead<LocalCommand>(`${accountPrefix(owner)}command:${id}`)
    if (command) await acknowledgeCommand(command, revision, new Headers(init.headers).has('X-Workspace-Base'))
  } else if ((init.method ?? 'GET').toUpperCase() === 'GET') await cacheRead(owner, path, data, revision)
}
window.addEventListener('online', () => { workspaceState.online = true; void synchronizeWorkspace() })
window.addEventListener('offline', () => { workspaceState.online = false })
window.addEventListener('focus', () => { void synchronizeWorkspace() })
