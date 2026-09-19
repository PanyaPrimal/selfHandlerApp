import type { StorageItem, StorageItemsResponse, StorageProject } from '../api/types'

export type StorageResource = 'items' | 'projects'
export type LocalStatus = 'pending' | 'conflict' | 'rejected'
export type ProjectedItem = StorageItem & { local_sync_status?: LocalStatus }
export type ProjectedProject = StorageProject & { local_sync_status?: LocalStatus }
export interface StorageState {
  items: ProjectedItem[]
  projects: ProjectedProject[]
  inboxCount: number
}
export interface StorageMutation {
  path: string
  method: string
  body: string | null
  localId?: number
  storageBefore?: StorageItem | null
  created: number
  status: LocalStatus
}
export interface StorageIdentity { resource: StorageResource; local: number; server: number }
export type LocalValidationCode = 'invalid' | 'missing' | 'blocked' | 'nested' | 'duplicate'

export function storageTarget(path: string): { resource: StorageResource; id: number | null } | null {
  const match = /^\/storage\/(items|projects)(?:\/(-?\d+))?$/.exec(path)
  return match ? { resource: match[1] as StorageResource, id: match[2] ? Number(match[2]) : null } : null
}

function payload(command: StorageMutation): Record<string, unknown> {
  try { const value: unknown = JSON.parse(command.body ?? '{}'); return value && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {} }
  catch { return {} }
}

export function storageReferences(command: StorageMutation, resource: StorageResource, id: number): boolean {
  const target = storageTarget(command.path)
  if (!target) return false
  const body = payload(command)
  return (target.resource === resource && target.id === id)
    || (target.resource === 'items' && body[resource === 'items' ? 'parent_id' : 'project_id'] === id)
}

/** Rewrite only typed relationships. A matching amount, title or unrelated ID is not a reference. */
export function remapStorageCommand<T extends StorageMutation>(command: T, identity: StorageIdentity): T {
  const target = storageTarget(command.path)
  if (!target) return command
  const previous = command.storageBefore
  const previousReference = previous && (identity.resource === 'items'
    ? previous.id === identity.local || previous.parent_id === identity.local : previous.project_id === identity.local)
  if (!storageReferences(command, identity.resource, identity.local) && !previousReference) return command
  const body = payload(command)
  if (target.resource === 'items') {
    const key = identity.resource === 'items' ? 'parent_id' : 'project_id'
    if (body[key] === identity.local) body[key] = identity.server
  }
  return { ...command,
    path: target.resource === identity.resource && target.id === identity.local ? `/storage/${target.resource}/${identity.server}` : command.path,
    body: command.body === null ? null : JSON.stringify(body),
    ...(previousReference ? { storageBefore: { ...previous,
      id: identity.resource === 'items' && previous.id === identity.local ? identity.server : previous.id,
      parent_id: identity.resource === 'items' && previous.parent_id === identity.local ? identity.server : previous.parent_id,
      project_id: identity.resource === 'projects' && previous.project_id === identity.local ? identity.server : previous.project_id,
    } } : {}),
  }
}

export function storageState(items: StorageItemsResponse, projects: StorageProject[]): StorageState {
  const records = new Map<number, ProjectedItem>()
  // The API embeds children even when they fall outside its top-level result limit.
  for (const item of items.data) for (const child of item.children ?? []) records.set(child.id, { ...child })
  for (const item of items.data) records.set(item.id, { ...item, tags: [...item.tags], children: undefined })
  return { items: [...records.values()], projects: projects.map(project => ({ ...project })), inboxCount: items.inbox_count }
}

function countItem(state: StorageState, item: StorageItem, delta: number) {
  if (item.status === 'inbox') state.inboxCount += delta
  const project = state.projects.find(row => row.id === item.project_id)
  if (!project) return
  if (item.status === 'inbox' || item.status === 'active') project.open_count += delta
  if (item.status === 'done') project.completed_count += delta
}

/** Pure overlay over a server snapshot; rejected/conflicting drafts remain explicitly marked. */
export function applyStorageMutation(base: StorageState, command: StorageMutation, confirmed?: { data?: unknown }): { state: StorageState; result: unknown } {
  const state: StorageState = { items: base.items.map(row => ({ ...row })), projects: base.projects.map(row => ({ ...row })), inboxCount: base.inboxCount }
  const target = storageTarget(command.path)
  if (!target) return { state, result: undefined }
  const body = payload(command)
  const confirmedRow = confirmed?.data as Record<string, unknown> | undefined
  const mark = confirmed ? undefined : command.status
  const timestamp = new Date(command.created).toISOString()
  const creating = command.method === 'POST' && target.id === null
  const id = creating ? Number(confirmedRow?.id ?? command.localId) : target.id
  if (id === null || !Number.isSafeInteger(id)) return { state, result: undefined }

  if (target.resource === 'projects') {
    const before = state.projects.find(row => row.id === id)
    if (command.method === 'DELETE') {
      state.projects = state.projects.filter(row => row.id !== id)
      state.items = state.items.map(row => row.project_id === id ? { ...row, project_id: null, local_sync_status: mark } : row)
      return { state, result: undefined }
    }
    if (!before && !creating) return { state, result: undefined }
    const archived = body.is_archived ?? before?.is_archived ?? false
    const row = { id, name: '', description: null, open_count: 0, completed_count: 0,
      ...before, ...body, is_archived: archived, archived_at: archived ? before?.archived_at ?? timestamp : null,
      ...confirmedRow, local_sync_status: mark } as ProjectedProject
    row.name = row.name.trim()
    state.projects = [...state.projects.filter(project => project.id !== id), row]
    return { state, result: { data: row } }
  }

  const before = state.items.find(row => row.id === id)
  if (command.method === 'DELETE') {
    if (before) countItem(state, before, -1)
    state.items = state.items.filter(row => row.id !== id).map(row => row.parent_id === id ? { ...row, parent_id: null, local_sync_status: mark } : row)
    return { state, result: undefined }
  }
  if (!before && !creating) return { state, result: undefined }
  const type = body.type ?? before?.type ?? 'task'
  const status = body.status ?? before?.status ?? (type === 'purchase' ? 'active' : 'inbox')
  const tags = Array.isArray(body.tags) ? [...new Set((body.tags as string[]).map(tag => tag.trim()).filter(Boolean))].map((name, index) => ({ id: -(index + 1), name })) : before?.tags ?? []
  const row = { id, title: '', description: null, priority: null, estimated_amount: null, estimated_currency_code: null,
    due_on: null, project_id: null, parent_id: null, is_blocker: false,
    ...before, ...body, type, status, tags,
    completed_at: status === 'done' ? before?.completed_at ?? timestamp : null,
    dropped_at: status === 'dropped' ? before?.dropped_at ?? timestamp : null,
    ...confirmedRow, local_sync_status: mark } as ProjectedItem
  row.title = row.title.trim()
  if (before) countItem(state, before, -1)
  countItem(state, row, 1)
  state.items = [...state.items.filter(item => item.id !== id), row]
  return { state, result: { data: row } }
}

export function projectStorage(base: StorageState, commands: StorageMutation[]): StorageState {
  return commands.reduce((state, command) => applyStorageMutation(state, command).state, base)
}

export function storageItemsResponse(state: StorageState, template: StorageItemsResponse, query = ''): StorageItemsResponse {
  const params = new URLSearchParams(query)
  const rows = state.items.filter(item => {
    for (const field of ['status', 'type', 'project_id', 'parent_id'] as const) {
      if (params.has(field) && String(item[field] ?? '') !== params.get(field)) return false
    }
    return !params.has('tag') || item.tags.some(tag => tag.name === params.get('tag'))
  }).sort((a, b) => a.id < 0 && b.id >= 0 ? -1 : b.id < 0 && a.id >= 0 ? 1 : a.id < 0 ? a.id - b.id : b.id - a.id)
  return { ...template, inbox_count: Math.max(0, state.inboxCount), data: rows.map(item => ({ ...item,
    children: state.items.filter(child => child.parent_id === item.id).map(child => ({ ...child, children: undefined })),
  })) }
}

/** Validate the locally knowable rules before acknowledging a durable local save. Server validates again on sync. */
export function validateStorageMutation(state: StorageState, command: StorageMutation): Record<string, LocalValidationCode> {
  const target = storageTarget(command.path)
  if (!target) return {}
  const body = payload(command)
  const creating = command.method === 'POST'
  const before = target.resource === 'items' ? state.items.find(row => row.id === target.id) : state.projects.find(row => row.id === target.id)
  const errors: Record<string, LocalValidationCode> = {}
  if (!creating && !before) return { request: 'missing' }
  if (command.method === 'DELETE') return errors
  const title = target.resource === 'items' ? 'title' : 'name'
  if ((creating || title in body) && (typeof body[title] !== 'string' || !(body[title] as string).trim() || (body[title] as string).length > (title === 'title' ? 200 : 160))) errors[title] = 'invalid'
  if ('description' in body && body.description !== null && (typeof body.description !== 'string' || body.description.length > 5000)) errors.description = 'invalid'
  if (target.resource === 'projects') {
    if ('is_archived' in body && typeof body.is_archived !== 'boolean') errors.is_archived = 'invalid'
    if (typeof body.name === 'string' && state.projects.some(row => row.id !== target.id && row.name.toLocaleLowerCase() === (body.name as string).trim().toLocaleLowerCase())) errors.name = 'duplicate'
    return errors
  }
  const item = before as StorageItem | undefined
  for (const [field, allowed] of Object.entries({ type: ['task', 'idea', 'purchase'], status: ['inbox', 'active', 'done', 'dropped'], priority: ['low', 'normal', 'high', null] })) {
    if (field in body && !allowed.includes(body[field] as string)) errors[field] = 'invalid'
  }
  if ('is_blocker' in body && typeof body.is_blocker !== 'boolean') errors.is_blocker = 'invalid'
  if (body.project_id != null && !state.projects.some(project => project.id === body.project_id)) errors.project_id = 'missing'
  if (body.parent_id != null) {
    const parent = state.items.find(row => row.id === body.parent_id)
    if (!parent) errors.parent_id = 'missing'
    else if (parent.id === target.id || parent.parent_id !== null || (target.id !== null && state.items.some(row => row.parent_id === target.id))) errors.parent_id = 'nested'
  }
  if (body.status === 'done' && state.items.some(row => row.parent_id === target.id && row.is_blocker && ['inbox', 'active'].includes(row.status))) errors.status = 'blocked'
  const type = body.type ?? item?.type ?? 'task'
  if (type === 'purchase' && body.status === 'done') errors.status = 'invalid'
  const amount = 'estimated_amount' in body ? body.estimated_amount : item?.estimated_amount ?? null
  const currency = 'estimated_currency_code' in body ? body.estimated_currency_code : item?.estimated_currency_code ?? null
  if ((amount === null) !== (currency === null) || (amount !== null && (type !== 'purchase' || typeof amount !== 'string' || amount.length > 32 || !/^\d+(?:\.\d{1,4})?$/.test(amount) || !/[1-9]/.test(amount)))) errors.estimated_amount = 'invalid'
  if (currency !== null && (typeof currency !== 'string' || currency.length !== 3)) errors.estimated_currency_code = 'invalid'
  if (body.due_on != null && (typeof body.due_on !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(body.due_on) || Number.isNaN(Date.parse(body.due_on)) || new Date(body.due_on).toISOString().slice(0, 10) !== body.due_on)) errors.due_on = 'invalid'
  if ('tags' in body && (!Array.isArray(body.tags) || body.tags.length > 20 || body.tags.some(tag => typeof tag !== 'string' || tag.length > 64))) errors.tags = 'invalid'
  return errors
}
