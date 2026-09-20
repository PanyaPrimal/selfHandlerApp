import { reactive } from 'vue'
import type { StorageItem, User } from '../api/types'
import { translate } from '../i18n'
import { localEntries, localRead, localRemove, localWrite, mutateLocalEntries, type LocalEntry } from './database'
import { acknowledgedStorageEntries, localStoragePath, needsStorageIdentity, storageMutationBefore } from './storage-local'
import { remapStorageCommand, storageReferences, storageTarget, type StorageIdentity } from './storage-projection'
import { acknowledgedPlannerStorageEntries, localPlannerPath } from './planner-local'
import { acknowledgedTimeBlockEntries, cachedTimeBlockEntries, needsTimeBlockIdentity, projectKnownTimeBlocks } from './time-block-local'
import { remapTimeBlockCommand, timeBlockTarget, type TimeBlockIdentity } from './planner-projection'
import type { PlannerDayResponse } from '../api/types'

export interface LocalCommand {
  id: string; owner: number; path: string; method: string; body: string | null
  base: number | null; created: number; status: 'pending' | 'conflict' | 'rejected'
  message: string | null; title: string
  localId?: number; localProjected?: boolean
  storageBefore?: StorageItem | null
}
export interface CachedRead { data: unknown; revision: number; saved: number }
type Sender = <T>(path: string, init?: RequestInit) => Promise<T>
export const workspaceState = reactive({ owner: null as number | null, online: navigator.onLine, syncing: false, pending: 0, issue: '', lastSync: null as number | null })
let rawSender: Sender | null = null
let generation = 0
let synchronizing: Promise<void> | null = null
let writing: Promise<unknown> = Promise.resolve()
export function withWorkspaceWriteLock<T>(owner: number, action: () => Promise<T>): Promise<T> {
  if (navigator.locks) return navigator.locks.request(`selfhandler-sync-${owner}`, action)
  const next = writing.catch(() => undefined).then(action)
  writing = next
  return next
}
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
  try {
    const prefix = accountPrefix(owner)
    await mutateLocalEntries([`${prefix}read:`, `${prefix}command:`, `${prefix}entity:time-block:`], entries => {
      const key = `${prefix}read:${path}`
      const previous = entries.find(entry => entry.key === key)?.value as CachedRead | undefined
      if (previous && previous.revision > revision) return { result: undefined }
      // Freeze affected baselines until their local intents have receipts.
      // A GET following a lost acknowledgement might already contain that intent.
      if (entries.some(entry => entry.key.startsWith(`${prefix}command:`) && (entry.value as LocalCommand).status === 'pending'
        && (((localStoragePath(path) || localPlannerPath(path)) && storageTarget((entry.value as LocalCommand).path))
          || (localPlannerPath(path) && timeBlockTarget((entry.value as LocalCommand).path))))) return { result: undefined }
      const records = cachedTimeBlockEntries(owner, entries, path, data, revision)
      const snapshot = { key, value: { data, revision, saved: Date.now() } satisfies CachedRead }
      if (localPlannerPath(path)) {
        const updated = [...new Map([...entries, snapshot, ...records].map(entry => [entry.key, entry])).values()]
        snapshot.value.data = projectKnownTimeBlocks(owner, updated, data as PlannerDayResponse)
      }
      const put: LocalEntry[] = [snapshot, ...records]
      // /planner/day and its explicit date are the same downloaded day.
      // Forms switch to the dated URL as soon as the server supplies today's date.
      if (localPlannerPath(path)) {
        const datedKey = `${prefix}read:/planner/day?date=${(data as PlannerDayResponse).date}`
        const dated = entries.find(entry => entry.key === datedKey)?.value as CachedRead | undefined
        if (datedKey !== key && (!dated || dated.revision <= revision)) put.push({ key: datedKey, value: snapshot.value })
      }
      return { put, result: undefined }
    })
  }
  catch { storageFailure() } // A full device must not hide a successful online read.
}
export async function cachedRead(owner: number, path: string): Promise<unknown> {
  const key = `${accountPrefix(owner)}read:${path}`
  const cached = localPlannerPath(path) ? await mutateLocalEntries<CachedRead | undefined>([`${accountPrefix(owner)}read:`, `${accountPrefix(owner)}entity:time-block:`], entries => {
    const value = entries.find(entry => entry.key === key)?.value as CachedRead | undefined
    return { result: value ? { ...value, data: projectKnownTimeBlocks(owner, entries, value.data as PlannerDayResponse) } : undefined }
  }) : await localRead<CachedRead>(key)
  if (!cached) throw new Error(translate('offline.notDownloaded'))
  workspaceState.online = false
  return cached.data
}
export async function prepareCommand(owner: number, path: string, init: RequestInit, operationId?: string): Promise<LocalCommand> {
  let body = typeof init.body === 'string' ? init.body : null
  const method = (init.method ?? 'POST').toUpperCase()
  const prefix = accountPrefix(owner)
  const command = await mutateLocalEntries<LocalCommand>([`${prefix}read:`, `${prefix}command:`, `${prefix}identity:`, `${prefix}sequence:`], entries => {
    for (const entry of entries.filter(entry => entry.key.startsWith(`${prefix}identity:storage:`))) {
      const mapped = remapStorageCommand({ path, body, method, created: 0, status: 'pending' as const }, entry.value as StorageIdentity)
      path = mapped.path; body = mapped.body
    }
    for (const entry of entries.filter(entry => entry.key.startsWith(`${prefix}identity:time-block:`))) {
      path = remapTimeBlockCommand({ path, body, method, created: 0, status: 'pending' as const }, entry.value as TimeBlockIdentity).path
    }
    const existing = entries.filter(entry => entry.key.startsWith(`${prefix}command:`)).map(entry => entry.value as LocalCommand)
    const duplicate = existing.find(row => operationId ? row.id === operationId : row.path === path && row.method === method && row.body === body)
    if (duplicate && (duplicate.path !== path || duplicate.method !== method || duplicate.body !== body)) throw new Error(translate('offline.rejected'))
    if (duplicate) return { result: duplicate }
    const cached = entries.filter(entry => entry.key.startsWith(`${prefix}read:`)).map(entry => entry.value as CachedRead)
    // Conservative baseline: no offline intent may overwrite a version newer than any retained screen.
    const base = cached.length ? Math.min(...cached.map(row => row.revision)) : null
    let title = ''
    try { const payload = JSON.parse(body ?? '{}'); title = String(payload.title ?? payload.name ?? payload.note ?? '').slice(0, 120) } catch { /* empty DELETE */ }
    const command: LocalCommand = { id: operationId ?? crypto.randomUUID(), owner, path, method, body, base, created: Math.max(Date.now(), ...existing.map(row => row.created + 1)), status: 'pending', message: null, title }
    command.storageBefore = storageMutationBefore(owner, entries, command)
    const put: LocalEntry[] = []
    if ((storageTarget(path)?.id === null || timeBlockTarget(path)?.id === null) && method === 'POST') {
      const sequenceKey = `${prefix}sequence:${timeBlockTarget(path) ? 'time-block' : 'storage'}`
      command.localId = Number(entries.find(entry => entry.key === sequenceKey)?.value ?? 0) - 1
      if (!Number.isSafeInteger(command.localId)) throw new Error(translate('offline.storageFailed'))
      put.push({ key: sequenceKey, value: command.localId })
    }
    put.push({ key: commandKey(command), value: command })
    return { put, result: command }
  })
  await refreshQueue()
  return command
}
export function commandHeaders(command: LocalCommand, checkBase: boolean): Record<string, string> {
  return { 'X-Workspace-Operation': command.id, 'X-Workspace-Account': String(command.owner),
    ...(checkBase && command.base !== null ? { 'X-Workspace-Base': String(command.base) } : {}) }
}
export async function acknowledgeCommand(command: LocalCommand, revision: number, checkedBase: boolean, data?: unknown) {
  // Only advance matching snapshots if the server proves there was no intervening writer.
  const ownTransition = checkedBase || (command.base !== null && revision === command.base + 1)
  const prefix = accountPrefix(command.owner)
  const identity = await mutateLocalEntries<{ storage?: StorageIdentity; block?: TimeBlockIdentity } | undefined>([`${prefix}read:`, `${prefix}command:`, `${prefix}identity:`, `${prefix}entity:time-block:`], entries => {
    if (!entries.some(entry => entry.key === commandKey(command))) return { result: undefined }
    const storage = acknowledgedStorageEntries(command.owner, entries, command, data, revision)
    const planner = acknowledgedPlannerStorageEntries(command.owner, entries, command, data, revision)
    const block = acknowledgedTimeBlockEntries(command.owner, entries, command, data, revision)
    const put = new Map([...storage.put, ...planner, ...block.put].map(entry => [entry.key, entry]))
    for (const entry of entries) {
      if (entry.key.startsWith(`${prefix}read:`) && ownTransition) {
        const value = (put.get(entry.key)?.value ?? entry.value) as CachedRead
        if (value.revision === command.base) put.set(entry.key, { key: entry.key, value: { ...value, revision } })
      }
      if (entry.key.startsWith(`${prefix}command:`)) {
        let value = entry.value as LocalCommand
        if (value.id === command.id) continue
        if (storage.identity) value = remapStorageCommand(value, storage.identity)
        if (block.identity) value = remapTimeBlockCommand(value, block.identity)
        if (ownTransition && value.base === command.base && value.created >= command.created && value.status === 'pending') value = { ...value, base: revision }
        if (value !== entry.value) put.set(entry.key, { key: entry.key, value })
      }
    }
    return { put: [...put.values()], remove: [commandKey(command)], result: { storage: storage.identity, block: block.identity } }
  })
  await refreshQueue()
  if (workspaceState.owner === command.owner) {
    if (identity?.block) window.dispatchEvent(new CustomEvent('workspace-time-block-identity', { detail: identity.block }))
    window.dispatchEvent(new CustomEvent('workspace-storage-changed', { detail: identity?.storage }))
  }
}
export async function rejectCommand(command: LocalCommand, error: unknown) {
  const problem = error as { status?: number; message?: string }
  if (problem.status === 0 || problem.status === undefined || problem.status === 401 || problem.status === 419 || problem.status >= 500 || problem.status === 429) return
  await localWrite(commandKey(command), { ...command, status: problem.status === 409 ? 'conflict' : 'rejected', message: problem.message ?? translate('offline.rejected') })
  await refreshQueue()
}
export async function discardCommand(id: string) {
  const owner = workspaceState.owner
  if (owner === null) return
  await mutateLocalEntries([`${accountPrefix(owner)}command:`], entries => {
    const rows = entries.map(entry => entry.value as LocalCommand)
    const command = rows.find(row => row.id === id)
    const target = command && storageTarget(command.path)
    if (command?.localId !== undefined && target && rows.some(row => row.id !== id && storageReferences(row, target.resource, command.localId!))) throw new Error(translate('offline.dependencies'))
    if (command?.localId !== undefined && timeBlockTarget(command.path) && rows.some(row => row.id !== id && timeBlockTarget(row.path)?.id === command.localId)) throw new Error(translate('offline.dependencies'))
    return { remove: command ? [commandKey(command)] : [], result: undefined }
  })
  await refreshQueue()
  if (workspaceState.owner === owner) window.dispatchEvent(new Event('workspace-storage-changed'))
}
export async function retryReviewedCommand(id: string) {
  const command = (await commands()).find(row => row.id === id)
  if (!command || !rawSender) return
  const current = await rawSender<{ user_id: number; revision: number }>('/workspace/revision', { headers: { 'X-Workspace-Account': String(command.owner) } })
  if (current.user_id !== command.owner || workspaceState.owner !== command.owner) throw new Error(translate('offline.accountChanged'))
  await localWrite(commandKey(command), { ...command, base: current.revision, status: 'pending', message: null })
  await synchronizeWorkspace()
}
export async function retryRejectedCommand(id: string): Promise<void> {
  const owner = workspaceState.owner
  if (owner === null) return
  await withWorkspaceWriteLock(owner, async () => {
    if (workspaceState.owner !== owner) return
    const command = (await commands()).find(row => row.id === id)
    if (!command || command.status !== 'rejected') return
    // Keep the original revision and operation ID: retry must neither bypass
    // conflict detection nor duplicate an operation whose response was lost.
    await localWrite(commandKey(command), { ...command, status: 'pending', message: null })
    await refreshQueue()
  })
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
        if (needsStorageIdentity(row) || needsTimeBlockIdentity(row)) {
          await localWrite(commandKey(row), { ...row, status: 'conflict', message: translate('offline.dependencies') }); break
        }
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
  const promise = withWorkspaceWriteLock(owner, run)
  synchronizing = promise
  try { await promise } finally { if (synchronizing === promise) synchronizing = null }
}
export async function acceptResponse(owner: number, path: string, init: RequestInit, data: unknown, revision: number) {
  const id = new Headers(init.headers).get('X-Workspace-Operation')
  if (id) {
    const command = await localRead<LocalCommand>(`${accountPrefix(owner)}command:${id}`)
    if (command) await acknowledgeCommand(command, revision, new Headers(init.headers).has('X-Workspace-Base'), data)
  } else if ((init.method ?? 'GET').toUpperCase() === 'GET') await cacheRead(owner, path, data, revision)
}
window.addEventListener('online', () => { workspaceState.online = true; void synchronizeWorkspace() })
window.addEventListener('offline', () => { workspaceState.online = false })
window.addEventListener('focus', () => { void synchronizeWorkspace() })
