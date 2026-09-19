import type { StorageItem, StorageItemsResponse, StorageProject } from '../api/types'
import { mutateLocalEntries, type LocalEntry } from './database'
import type { CachedRead, LocalCommand } from './workspace'
import { applyStorageMutation, projectStorage, storageItemsResponse, storageState, storageTarget, validateStorageMutation, type StorageIdentity, type LocalValidationCode } from './storage-projection'

const prefix = (owner: number) => `account:${owner}:`
const itemPath = '/storage/items'
const projectPath = '/storage/projects'
export const localStoragePath = (path: string) => path === itemPath || path === projectPath
export const storageCacheKey = (owner: number, path: string) => `${prefix(owner)}read:${path}`
export const storageIdentityKey = (owner: number, identity: StorageIdentity) => `${prefix(owner)}identity:storage:${identity.resource}:${identity.local}`

function snapshots(owner: number, entries: LocalEntry[]) {
  const itemEntry = entries.find(entry => entry.key === storageCacheKey(owner, itemPath))
  const projectEntry = entries.find(entry => entry.key === storageCacheKey(owner, projectPath))
  if (!itemEntry || !projectEntry) return null
  const items = itemEntry.value as CachedRead
  const projects = projectEntry.value as CachedRead
  const template = items.data as StorageItemsResponse
  if (!Array.isArray(template?.data) || !Array.isArray((projects.data as { data?: unknown })?.data)) return null
  return { itemEntry, projectEntry, items, projects, template, state: storageState(template, (projects.data as { data: StorageProject[] }).data) }
}

function pending(owner: number, entries: LocalEntry[]): LocalCommand[] {
  return entries.filter(entry => entry.key.startsWith(`${prefix(owner)}command:`)).map(entry => entry.value as LocalCommand)
    .sort((a, b) => a.created - b.created || a.id.localeCompare(b.id))
}

/** Preserve the original item for count deltas when one list is refreshed before another. */
export function storageMutationBefore(owner: number, entries: LocalEntry[], command: LocalCommand): StorageItem | null | undefined {
  const target = storageTarget(command.path)
  if (!target || target.resource !== 'items') return undefined
  if (command.method === 'POST') return null
  const snapshot = snapshots(owner, entries)
  if (!snapshot) return undefined
  return projectStorage(snapshot.state, pending(owner, entries).filter(row => row.localProjected)).items.find(row => row.id === target.id)
}

export async function pendingStorageRead(owner: number, path: string): Promise<{ handled: boolean; value?: unknown }> {
  if (!localStoragePath(path)) return { handled: false }
  return mutateLocalEntries<{ handled: boolean; value?: unknown }>([`${prefix(owner)}read:/storage/`, `${prefix(owner)}command:`], entries => {
    const queued = pending(owner, entries).filter(command => storageTarget(command.path))
    // A conflict needs current server data for comparison. Its complete draft stays in the queue.
    if (!queued.some(command => command.localProjected) || queued.some(command => command.status !== 'pending')) return { result: { handled: false } }
    const snapshot = snapshots(owner, entries)
    if (!snapshot) return { result: { handled: false } }
    const state = projectStorage(snapshot.state, queued.filter(command => command.localProjected))
    return { result: { handled: true, value: path === itemPath ? storageItemsResponse(state, snapshot.template) : { data: state.projects } } }
  })
}

/** Make the local success and the visible projection durable before returning to a form. */
export async function stageStorageProjection(command: LocalCommand): Promise<{ handled: boolean; errors?: Record<string, LocalValidationCode>; value?: unknown }> {
  const target = storageTarget(command.path)
  if (!target || (command.method === 'POST' && command.localId === undefined)) return { handled: false }
  return mutateLocalEntries<{ handled: boolean; errors?: Record<string, LocalValidationCode>; value?: unknown }>([`${prefix(command.owner)}read:/storage/`, `${prefix(command.owner)}command:`], entries => {
    const snapshot = snapshots(command.owner, entries)
    const queued = pending(command.owner, entries)
    const position = queued.findIndex(row => row.id === command.id)
    if (!snapshot || position < 0 || queued.some(row => storageTarget(row.path) && row.status !== 'pending')) return { result: { handled: false } }
    const stored = queued[position]!
    const before = projectStorage(snapshot.state, queued.slice(0, position).filter(row => row.localProjected))
    const errors = validateStorageMutation(before, stored)
    if (Object.keys(errors).length) return { result: { handled: true, errors, value: undefined } }
    const { result } = applyStorageMutation(before, stored)
    return { put: [{ key: `${prefix(command.owner)}command:${command.id}`, value: { ...stored, localProjected: true } }], result: { handled: true, errors: undefined, value: result } }
  })
}

/** Runs inside the receipt transaction: snapshots, identity mapping and queue deletion commit together. */
export function acknowledgedStorageEntries(owner: number, entries: LocalEntry[], command: LocalCommand, data: unknown, revision: number): { put: LocalEntry[]; identity?: StorageIdentity } {
  const target = storageTarget(command.path)
  if (!target) return { put: [] }
  const response = data && typeof data === 'object' ? data as { data?: { id?: number } } : {}
  const identity: StorageIdentity | undefined = command.method === 'POST' && command.localId !== undefined && Number.isSafeInteger(response.data?.id) && response.data!.id! > 0
    ? { resource: target.resource, local: command.localId, server: response.data!.id! } : undefined
  const put: LocalEntry[] = identity ? [{ key: storageIdentityKey(owner, identity), value: identity }] : []
  const snapshot = snapshots(owner, entries)
  // A newer complete read already contains this receipt; replay must not increment its counts twice.
  if (!snapshot || Math.min(snapshot.items.revision, snapshot.projects.revision) >= revision) return { put, identity }
  let base = snapshot.state
  if (target.resource === 'items' && snapshot.items.revision >= revision && command.storageBefore !== undefined) {
    const id = target.id ?? identity?.server
    base = { ...base, items: base.items.filter(row => row.id !== id) }
    if (command.storageBefore) base.items.push(command.storageBefore)
  }
  const { state } = applyStorageMutation(base, command, response)
  if (snapshot.items.revision < revision) put.push({ key: snapshot.itemEntry.key, value: { ...snapshot.items, data: storageItemsResponse(state, snapshot.template) } })
  if (snapshot.projects.revision < revision) put.push({ key: snapshot.projectEntry.key, value: { ...snapshot.projects, data: { data: state.projects } } })
  return { put, identity }
}

export function needsStorageIdentity(command: LocalCommand): boolean {
  const target = storageTarget(command.path)
  if (!target) return false
  if (target.id !== null && target.id < 0) return true
  if (target.resource !== 'items') return false
  try { const body = JSON.parse(command.body ?? '{}'); return (typeof body.parent_id === 'number' && body.parent_id < 0) || (typeof body.project_id === 'number' && body.project_id < 0) }
  catch { return false }
}
