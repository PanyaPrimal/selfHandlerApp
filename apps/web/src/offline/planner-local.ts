import type { PlannerDayResponse, StorageItemsResponse, StorageProject } from '../api/types'
import { mutateLocalEntries, type LocalEntry } from './database'
import type { CachedRead, LocalCommand } from './workspace'
import { applyStorageMutation, storageState, storageTarget, type StorageState } from './storage-projection'
import { reflectStorageInPlanner, timeBlockTarget } from './planner-projection'
import { projectPendingTimeBlocks } from './time-block-local'

const prefix = (owner: number) => `account:${owner}:`
export const localPlannerPath = (path: string) => /^\/planner\/day(?:\?date=\d{4}-\d{2}-\d{2})?$/.test(path)

function storageSnapshot(owner: number, entries: LocalEntry[]): StorageState | undefined {
  const items = (entries.find(entry => entry.key === `${prefix(owner)}read:/storage/items`)?.value as CachedRead | undefined)?.data as StorageItemsResponse | undefined
  const projects = (entries.find(entry => entry.key === `${prefix(owner)}read:/storage/projects`)?.value as CachedRead | undefined)?.data as { data: StorageProject[] } | undefined
  return Array.isArray(items?.data) && Array.isArray(projects?.data) ? storageState(items, projects.data) : undefined
}

function daySnapshot(entry: LocalEntry): PlannerDayResponse | undefined {
  const value = (entry.value as CachedRead).data as PlannerDayResponse | undefined
  return value && typeof value.date === 'string' && Array.isArray(value.entries) ? value : undefined
}

/** Storage owns the task; the calendar is only its dated view. */
export function projectPlannerStorage(day: PlannerDayResponse, state: StorageState, queued: LocalCommand[]): PlannerDayResponse {
  for (const command of queued) {
    const after = applyStorageMutation(state, command).state
    day = reflectStorageInPlanner([day], state, after)[0]!
    const target = storageTarget(command.path)
    // The calendar can include older tasks outside the bounded Storage list.
    if (target?.resource === 'projects' && command.method === 'DELETE') {
      day = { ...day, entries: day.entries.map(entry => entry.source === 'storage' && entry.meta.project_id === target.id
        ? { ...entry, meta: { ...entry.meta, project_id: null, local_sync_status: command.status } } : entry) }
    }
    state = after
  }
  return day
}

export async function pendingPlannerRead(owner: number, path: string): Promise<{ handled: boolean; value?: unknown }> {
  if (!localPlannerPath(path)) return { handled: false }
  return mutateLocalEntries<{ handled: boolean; value?: unknown }>([`${prefix(owner)}read:`, `${prefix(owner)}command:`, `${prefix(owner)}entity:time-block:`], entries => {
    const queued = entries.filter(entry => entry.key.startsWith(`${prefix(owner)}command:`)).map(entry => entry.value as LocalCommand)
      .filter(command => storageTarget(command.path) || timeBlockTarget(command.path)).sort((a, b) => a.created - b.created || a.id.localeCompare(b.id))
    if (!queued.some(command => command.localProjected) || queued.some(command => command.status !== 'pending')) return { result: { handled: false } }
    const entry = entries.find(row => row.key === `${prefix(owner)}read:${path}`)
    const day = entry && daySnapshot(entry)
    const state = storageSnapshot(owner, entries)
    if (!day) return { result: { handled: false } }
    const storageCommands = queued.filter(command => command.localProjected && storageTarget(command.path))
    if (storageCommands.length && !state) return { result: { handled: false } }
    const projected = state ? projectPlannerStorage(day, state, storageCommands) : day
    return { result: { handled: true, value: projectPendingTimeBlocks(owner, entries, projected) } }
  })
}

/** Called in the same transaction as Storage snapshots, receipt identity and queue deletion. */
export function acknowledgedPlannerStorageEntries(owner: number, entries: LocalEntry[], command: LocalCommand, data: unknown, revision: number): LocalEntry[] {
  const target = storageTarget(command.path)
  if (!target) return []
  let before = storageSnapshot(owner, entries)
  if (!before) return []
  // Reconstruct the actual transition even if Storage has a newer snapshot than the calendar.
  if (target.resource === 'items' && command.storageBefore !== undefined) {
    const responseId = (data as { data?: { id?: number } } | null)?.data?.id
    before = { ...before, items: before.items.filter(item => item.id !== (target.id ?? responseId)) }
    if (command.storageBefore) before.items.push(command.storageBefore)
  }
  const response = data && typeof data === 'object' ? data as { data?: unknown } : {}
  const after = applyStorageMutation(before, command, response).state
  return entries.flatMap(entry => {
    if (!entry.key.startsWith(`${prefix(owner)}read:`) || !localPlannerPath(entry.key.slice(`${prefix(owner)}read:`.length))) return []
    const cached = entry.value as CachedRead
    const day = daySnapshot(entry)
    if (!day || cached.revision >= revision) return []
    let updated = reflectStorageInPlanner([day], before!, after)[0]!
    if (target.resource === 'projects' && command.method === 'DELETE') {
      updated = { ...updated, entries: updated.entries.map(row => row.source === 'storage' && row.meta.project_id === target.id
        ? { ...row, meta: { ...row.meta, project_id: null, local_sync_status: undefined } } : row) }
    }
    return [{ key: entry.key, value: { ...cached, data: updated } }]
  })
}
