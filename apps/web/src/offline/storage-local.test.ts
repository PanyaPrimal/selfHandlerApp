import { describe, expect, it } from 'vitest'
import type { LocalEntry } from './database'
import type { CachedRead, LocalCommand } from './workspace'
import { acknowledgedStorageEntries, needsStorageIdentity, storageCacheKey } from './storage-local'

const owner = 73
const command: LocalCommand = { id: 'intent', owner, path: '/storage/items', method: 'POST', body: JSON.stringify({ title: 'Milk', project_id: 9 }), localId: -4, localProjected: true, storageBefore: null, base: 4, created: 1, status: 'pending', message: null, title: 'Milk' }
function snapshots(itemsRevision = 4, projectRevision = 4): LocalEntry[] {
  return [
    { key: storageCacheKey(owner, '/storage/items'), value: { data: { data: [], inbox_count: 40, types: ['task'], statuses: ['inbox'], priorities: [] }, revision: itemsRevision, saved: 1 } },
    { key: storageCacheKey(owner, '/storage/projects'), value: { data: { data: [{ id: 9, name: 'Home', description: null, is_archived: false, archived_at: null, open_count: 50, completed_count: 5 }] }, revision: projectRevision, saved: 1 } },
  ]
}
const receipt = { data: { id: 81, title: 'Milk', project_id: 9, parent_id: null, type: 'task', status: 'inbox', tags: [] } }

describe('Storage receipt metadata', () => {
  it('returns the identity and both updated snapshots as one transaction payload', () => {
    const entries = snapshots()
    const result = acknowledgedStorageEntries(owner, entries, command, receipt, 5)
    expect(result.identity).toEqual({ resource: 'items', local: -4, server: 81 })
    expect(result.put).toHaveLength(3)
    const items = result.put.find(row => row.key.endsWith('read:/storage/items'))!.value as CachedRead
    const projects = result.put.find(row => row.key.endsWith('read:/storage/projects'))!.value as CachedRead
    expect(items.data).toMatchObject({ inbox_count: 41, data: [{ id: 81, title: 'Milk', local_sync_status: undefined }] })
    expect(projects.data).toMatchObject({ data: [{ open_count: 51, completed_count: 5 }] })
    expect((entries[0]!.value as CachedRead).data).toMatchObject({ inbox_count: 40, data: [] })
  })

  it('does not count an old receipt twice, while preserving its identity for dependent drafts', () => {
    const result = acknowledgedStorageEntries(owner, snapshots(8, 8), command, receipt, 5)
    expect(result.put).toHaveLength(1)
    expect(result.identity?.server).toBe(81)
    expect(result.put[0]?.key).toBe('account:73:identity:storage:items:-4')
  })

  it('does not let an already fresh projects response suppress a missing item acknowledgement', () => {
    const result = acknowledgedStorageEntries(owner, snapshots(4, 5), command, receipt, 5)
    expect(result.put.some(row => row.key.endsWith('read:/storage/items'))).toBe(true)
    expect(result.put.some(row => row.key.endsWith('read:/storage/projects'))).toBe(false)
  })

  it('never sends unresolved temporary references or mistakes arbitrary negative values for IDs', () => {
    expect(needsStorageIdentity(command)).toBe(false)
    expect(needsStorageIdentity({ ...command, path: '/storage/items/-4', method: 'PATCH' })).toBe(true)
    expect(needsStorageIdentity({ ...command, body: JSON.stringify({ project_id: -1 }) })).toBe(true)
    expect(needsStorageIdentity({ ...command, body: JSON.stringify({ parent_id: -2 }) })).toBe(true)
    expect(needsStorageIdentity({ ...command, body: JSON.stringify({ title: '-2', estimated_amount: '-1' }) })).toBe(false)
  })

  it('updates older project totals even when the item list already contains the new record', () => {
    const entries = snapshots(5, 4)
    ;(entries[0]!.value as CachedRead).data = { data: [receipt.data], inbox_count: 41 }
    const result = acknowledgedStorageEntries(owner, entries, command, receipt, 5)
    expect(result.put.some(row => row.key.endsWith('read:/storage/items'))).toBe(false)
    const projects = result.put.find(row => row.key.endsWith('read:/storage/projects'))!.value as CachedRead
    expect(projects.data).toMatchObject({ data: [{ open_count: 51, completed_count: 5 }] })
  })

  it('uses the original item for project count changes after a newer item list omits a deleted record', () => {
    const entries = snapshots(5, 4)
    const deleted: LocalCommand = { ...command, method: 'DELETE', path: '/storage/items/81', body: null, storageBefore: { ...receipt.data, description: null, priority: null, estimated_amount: null, estimated_currency_code: null, due_on: null, is_blocker: false, completed_at: null, dropped_at: null, type: 'task', status: 'inbox' } }
    const result = acknowledgedStorageEntries(owner, entries, deleted, undefined, 5)
    const projects = result.put.find(row => row.key.endsWith('read:/storage/projects'))!.value as CachedRead
    expect(projects.data).toMatchObject({ data: [{ open_count: 49, completed_count: 5 }] })
  })
})
