import { describe, expect, it } from 'vitest'
import type { StorageItemsResponse } from '../api/types'
import { applyStorageMutation, projectStorage, remapStorageCommand, storageItemsResponse, storageReferences, storageState, validateStorageMutation, type StorageMutation } from './storage-projection'

const empty: StorageItemsResponse = { data: [], inbox_count: 0, types: ['task', 'idea', 'purchase'], statuses: ['inbox', 'active', 'done', 'dropped'], priorities: ['low', 'normal', 'high'] }
const mutation = (path: string, method: string, body: unknown, localId?: number): StorageMutation => ({ path, method, body: body === null ? null : JSON.stringify(body), localId, status: 'pending', created: Date.UTC(2026, 8, 19) })
const create = (title: string, localId: number, extra = {}) => mutation('/storage/items', 'POST', { title, ...extra }, localId)

describe('offline Storage projection', () => {
  it('keeps a project, task and blocking child usable before they have server identifiers', () => {
    const base = storageState(empty, [])
    const project = mutation('/storage/projects', 'POST', { name: 'Home' }, -1)
    const parent = create('Plan repairs', -2, { project_id: -1 })
    const child = create('Buy materials', -3, { parent_id: -2, project_id: -1, status: 'active', is_blocker: true })
    const state = projectStorage(base, [project, parent, child])
    expect(state.projects[0]).toMatchObject({ id: -1, open_count: 2, local_sync_status: 'pending' })
    expect(state.inboxCount).toBe(1)
    expect(storageItemsResponse(state, empty).data.find(row => row.id === -2)?.children).toHaveLength(1)
    expect(validateStorageMutation(state, mutation('/storage/items/-2', 'PATCH', { status: 'done' }))).toEqual({ status: 'blocked' })
    const completed = projectStorage(state, [mutation('/storage/items/-3', 'PATCH', { status: 'done' })])
    expect(validateStorageMutation(completed, mutation('/storage/items/-2', 'PATCH', { status: 'done' }))).toEqual({})
    expect(completed.projects[0]).toMatchObject({ open_count: 1, completed_count: 1 })
    expect(base).toEqual({ items: [], projects: [], inboxCount: 0 })
  })

  it('remaps paths and typed references without rewriting amounts or arbitrary text', () => {
    const command = mutation('/storage/items/-7', 'PATCH', { parent_id: -7, project_id: -7, title: '-7', estimated_amount: '-7' })
    const mapped = remapStorageCommand(command, { resource: 'items', local: -7, server: 401 })
    expect(mapped.path).toBe('/storage/items/401')
    expect(JSON.parse(mapped.body!)).toEqual({ parent_id: 401, project_id: -7, title: '-7', estimated_amount: '-7' })
    expect(command.path).toBe('/storage/items/-7')
    expect(storageReferences(mapped, 'projects', -7)).toBe(true)
    const final = remapStorageCommand(mapped, { resource: 'projects', local: -7, server: 23 })
    expect(JSON.parse(final.body!).project_id).toBe(23)
    expect(remapStorageCommand(mutation('/body/measurements/-7', 'DELETE', null), { resource: 'items', local: -7, server: 401 }).path).toBe('/body/measurements/-7')
  })

  it('preserves children when their parent or project is deleted', () => {
    const state = projectStorage(storageState(empty, []), [mutation('/storage/projects', 'POST', { name: 'Project' }, -1), create('Parent', -2, { project_id: -1 }), create('Child', -3, { parent_id: -2, project_id: -1 })])
    const withoutParent = projectStorage(state, [mutation('/storage/items/-2', 'DELETE', null)])
    expect(withoutParent.items).toHaveLength(1)
    expect(withoutParent.items[0]).toMatchObject({ id: -3, parent_id: null, project_id: -1 })
    expect(withoutParent.projects[0]?.open_count).toBe(1)
    const withoutProject = projectStorage(withoutParent, [mutation('/storage/projects/-1', 'DELETE', null)])
    expect(withoutProject.items[0]?.project_id).toBeNull()
    expect(withoutProject.projects).toHaveLength(0)
    expect(withoutProject.inboxCount).toBe(1)
  })

  it('uses deltas instead of replacing server counts with a partial downloaded list', () => {
    const base = storageState({ ...empty, inbox_count: 230 }, [{ id: 1, name: 'Existing', description: null, is_archived: false, archived_at: null, open_count: 240, completed_count: 30 }])
    const state = projectStorage(base, [create('New task', -1, { project_id: 1 }), mutation('/storage/items/-1', 'PATCH', { status: 'done' })])
    expect(state.inboxCount).toBe(230)
    expect(state.projects[0]).toMatchObject({ open_count: 240, completed_count: 31 })
  })

  it('restores nested children from a bounded API list and avoids duplicate child records', () => {
    const local = projectStorage(storageState(empty, []), [create('Parent', -1), create('Child', -2, { parent_id: -1 })])
    const response = storageItemsResponse(local, empty)
    expect(storageState({ ...response, data: [response.data.find(row => row.id === -1)!] }, []).items).toHaveLength(2)
    expect(storageState(response, []).items).toHaveLength(2)
  })

  it('keeps conflicting edits visible and marked while leaving the downloaded snapshot unchanged', () => {
    const base = projectStorage(storageState(empty, []), [create('Original', 4)])
    const command = { ...mutation('/storage/items/4', 'PATCH', { title: 'My draft' }), status: 'conflict' as const }
    const state = projectStorage(base, [command])
    expect(state.items[0]).toMatchObject({ title: 'My draft', local_sync_status: 'conflict' })
    expect(base.items[0]?.title).toBe('Original')
  })

  it('prefers the acknowledged server values, clears the provisional status and retains totals', () => {
    const result = applyStorageMutation(storageState(empty, []), create('Draft', -1), { data: { id: 70, title: 'Server title', status: 'active', completed_at: null } })
    expect(result.state.items[0]).toMatchObject({ id: 70, title: 'Server title', status: 'active', local_sync_status: undefined })
    expect(result.state.inboxCount).toBe(0)
  })

  it('refuses unknown relationships and nesting deeper than the server supports', () => {
    const state = projectStorage(storageState(empty, []), [create('Parent', -1), create('Child', -2, { parent_id: -1 })])
    expect(validateStorageMutation(state, create('Unknown', -3, { parent_id: 99, project_id: 12 }))).toEqual({ parent_id: 'missing', project_id: 'missing' })
    expect(validateStorageMutation(state, create('Grandchild', -3, { parent_id: -2 }))).toEqual({ parent_id: 'nested' })
    expect(validateStorageMutation(state, mutation('/storage/items/-1', 'PATCH', { parent_id: -2 }))).toEqual({ parent_id: 'nested' })
    expect(validateStorageMutation(state, mutation('/storage/items/99', 'DELETE', null))).toEqual({ request: 'missing' })
  })

  it('validates purchase money pairs, completion and real calendar dates before local acceptance', () => {
    const state = storageState(empty, [])
    expect(validateStorageMutation(state, create('Milk', -1, { type: 'purchase', estimated_amount: '12.34', estimated_currency_code: 'UAH' }))).toEqual({})
    expect(projectStorage(state, [create('Milk', -1, { type: 'purchase' })]).items[0]?.status).toBe('active')
    expect(validateStorageMutation(state, create('Milk', -1, { type: 'purchase', status: 'done', estimated_amount: '0', estimated_currency_code: 'UAH', due_on: '2026-02-30' }))).toEqual({ status: 'invalid', estimated_amount: 'invalid', due_on: 'invalid' })
  })

  it('filters pending items by project/status/tag and rejects invalid names and duplicate projects', () => {
    const state = projectStorage(storageState(empty, []), [mutation('/storage/projects', 'POST', { name: 'Home' }, -1), create('Tagged', -2, { project_id: -1, tags: [' important ', 'important'] }), create('Other', -3)])
    expect(storageItemsResponse(state, empty, 'project_id=-1&status=inbox&tag=important').data.map(row => row.title)).toEqual(['Tagged'])
    expect(validateStorageMutation(state, create('   ', -4))).toEqual({ title: 'invalid' })
    expect(validateStorageMutation(state, mutation('/storage/projects', 'POST', { name: 'home' }, -4))).toEqual({ name: 'duplicate' })
  })
})
