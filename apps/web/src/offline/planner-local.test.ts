import { describe, expect, it } from 'vitest'
import type { PlannerDayResponse, PlannerEntry, StorageItem } from '../api/types'
import type { LocalEntry } from './database'
import type { CachedRead, LocalCommand } from './workspace'
import { acknowledgedPlannerStorageEntries, projectPlannerStorage } from './planner-local'
import { storageState } from './storage-projection'

const owner = 73
const today = '2026-09-19'
const tomorrow = '2026-09-20'
const item: StorageItem = { id: 8, title: 'Task', type: 'task', status: 'active', due_on: today, description: null, tags: [], priority: null,
  project_id: 2, parent_id: null, is_blocker: false, estimated_amount: null, estimated_currency_code: null, completed_at: null, dropped_at: null }
const entry = (row: StorageItem): PlannerEntry => ({ source: 'storage', source_id: row.id, title: row.title, time: null, status: row.status,
  actions: ['move'], meta: { type: row.type, priority: row.priority, project_id: row.project_id } })
const day = (date: string, entries: PlannerEntry[] = []): PlannerDayResponse => ({ date, today, entries, sources: ['storage'], window: { beyond: false, materialized_until: tomorrow } })
const cached = (path: string, data: unknown, revision = 4, account = owner): LocalEntry => ({ key: `account:${account}:read:${path}`, value: { data, revision, saved: 1 } })
const command: LocalCommand = { id: 'move', owner, path: '/storage/items/8', method: 'PATCH', body: JSON.stringify({ due_on: tomorrow }),
  localProjected: true, storageBefore: item, base: 4, created: 1, status: 'pending', message: null, title: '' }
const state = () => storageState({ data: [item], inbox_count: 0, types: ['task'], statuses: ['active'], priorities: [] }, [])
const snapshots = (): LocalEntry[] => [cached('/storage/items', { data: [item], inbox_count: 0 }), cached('/storage/projects', { data: [] }),
  cached(`/planner/day?date=${today}`, day(today, [entry(item)])), cached(`/planner/day?date=${tomorrow}`, day(tomorrow))]
const value = (entries: LocalEntry[], date: string) => (entries.find(row => row.key.endsWith(date))!.value as CachedRead).data as PlannerDayResponse

describe('Storage and Planner local consistency', () => {
  it('shows a queued move in both downloaded days without changing the underlying snapshot', () => {
    const original = day(today, [entry(item)])
    expect(projectPlannerStorage(original, state(), [command]).entries).toEqual([])
    expect(projectPlannerStorage(day(tomorrow), state(), [command]).entries[0]).toMatchObject({ source_id: 8, meta: { local_sync_status: 'pending' } })
    expect(original.entries).toHaveLength(1)
  })

  it('updates both dated snapshots atomically when a move receives its receipt', () => {
    const entries = snapshots()
    const writes = acknowledgedPlannerStorageEntries(owner, entries, command, { data: { ...item, due_on: tomorrow } }, 5)
    expect(writes).toHaveLength(2)
    expect(value(writes, today).entries).toEqual([])
    expect(value(writes, tomorrow).entries).toHaveLength(1)
    expect(value(writes, tomorrow).entries[0]?.meta.local_sync_status).toBeUndefined()
    expect(value(entries, today).entries).toHaveLength(1)
  })

  it('repairs a stale calendar from the preimage when Storage is already newer', () => {
    const entries = snapshots()
    entries[0] = cached('/storage/items', { data: [{ ...item, due_on: tomorrow }], inbox_count: 0 }, 5)
    const writes = acknowledgedPlannerStorageEntries(owner, entries, command, { data: { ...item, due_on: tomorrow } }, 5)
    expect(value(writes, today).entries).toEqual([])
    expect(value(writes, tomorrow).entries[0]?.source_id).toBe(8)
    const removed = acknowledgedPlannerStorageEntries(owner, entries, { ...command, method: 'DELETE', body: null }, undefined, 5)
    expect(value(removed, today).entries).toEqual([])
    expect(value(removed, tomorrow).entries).toEqual([])
  })

  it('does not rewrite newer calendar snapshots or another account, even for receipt replay', () => {
    const entries = snapshots()
    entries[2] = cached(`/planner/day?date=${today}`, day(today), 7)
    entries[3] = cached(`/planner/day?date=${tomorrow}`, day(tomorrow, [entry({ ...item, title: 'Newer title' })]), 7)
    entries.push(cached(`/planner/day?date=${today}`, day(today, [entry(item)]), 4, 99))
    expect(acknowledgedPlannerStorageEntries(owner, entries, command, { data: { ...item, due_on: tomorrow } }, 5)).toEqual([])
  })

  it('detaches unknown older calendar tasks when their project is deleted', () => {
    const older = { ...item, id: 999 }
    const deletion = { ...command, path: '/storage/projects/2', method: 'DELETE', body: null }
    const local = projectPlannerStorage(day(today, [entry(older)]), state(), [deletion])
    expect(local.entries[0]?.meta).toMatchObject({ project_id: null, local_sync_status: 'pending' })
    const entries = snapshots()
    entries[2] = cached(`/planner/day?date=${today}`, day(today, [entry(older)]))
    const writes = acknowledgedPlannerStorageEntries(owner, entries, deletion, undefined, 5)
    expect(value(writes, today).entries[0]?.meta).toMatchObject({ project_id: null, local_sync_status: undefined })
  })

  it('receives a server ID for an offline creation, then overlays a dependent edit', () => {
    const create = { ...command, path: '/storage/items', method: 'POST', body: JSON.stringify({ title: 'Created', due_on: today }), localId: -2, storageBefore: null }
    const entries = snapshots()
    const writes = acknowledgedPlannerStorageEntries(owner, entries, create, { data: { ...item, id: 40, title: 'Created' } }, 5)
    expect(value(writes, today).entries.map(row => row.source_id)).toEqual([40, 8])
    const next = storageState({ data: [item, { ...item, id: 40, title: 'Created' }], inbox_count: 0 } as Parameters<typeof storageState>[0], [])
    const edited = projectPlannerStorage(value(writes, today), next, [{ ...command, path: '/storage/items/40', body: JSON.stringify({ title: 'Edited' }) }])
    expect(edited.entries.find(row => row.source_id === 40)?.title).toBe('Edited')
  })
})
