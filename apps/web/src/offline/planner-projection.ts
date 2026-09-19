import type { PlannerDayResponse, PlannerEntry, StorageItem, TimeBlock } from '../api/types'
import type { LocalStatus, LocalValidationCode, StorageMutation, StorageState } from './storage-projection'

export interface PlannerMutation extends StorageMutation {}
export interface TimeBlockIdentity { local: number; server: number }
export type ProjectedTimeBlock = TimeBlock & { local_sync_status?: LocalStatus }

export function timeBlockTarget(path: string): { id: number | null } | null {
  const match = /^\/planner\/time-blocks(?:\/(-?\d+))?$/.exec(path)
  return match ? { id: match[1] ? Number(match[1]) : null } : null
}

function bodyOf(command: PlannerMutation): Record<string, unknown> {
  try { const body: unknown = JSON.parse(command.body ?? '{}'); return body && typeof body === 'object' && !Array.isArray(body) ? body as Record<string, unknown> : {} }
  catch { return {} }
}

export function remapTimeBlockCommand<T extends PlannerMutation>(command: T, identity: TimeBlockIdentity): T {
  return timeBlockTarget(command.path)?.id === identity.local ? { ...command, path: `/planner/time-blocks/${identity.server}` } : command
}

/** The block is reconstructed from its owning source, never from another module's entry ID. */
export function timeBlockInDays(days: PlannerDayResponse[], id: number): ProjectedTimeBlock | undefined {
  for (const day of days) {
    const entry = day.entries.find(row => row.source === 'time_block' && row.source_id === id)
    if (entry) return { id, title: entry.title, block_date: day.date, starts_at: entry.time,
      ends_at: typeof entry.meta.ends_at === 'string' ? entry.meta.ends_at : null,
      note: typeof entry.meta.note === 'string' ? entry.meta.note : null,
      local_sync_status: entry.meta.local_sync_status as LocalStatus | undefined }
  }
  return undefined
}

/** Same ordering as the server: timed entries first, then case-folded title and stable identity. */
export function sortPlannerEntries(entries: PlannerEntry[]): PlannerEntry[] {
  const compare = (left: string, right: string) => left < right ? -1 : left > right ? 1 : 0
  return [...entries].sort((left, right) => Number(left.time === null) - Number(right.time === null)
    || compare(left.time ?? '', right.time ?? '') || compare(left.title.toLowerCase(), right.title.toLowerCase())
    || left.source_id - right.source_id || compare(left.source, right.source))
}

function calendarDate(value: unknown): value is string {
  return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
    && Number.isFinite(Date.parse(value)) && new Date(value).toISOString().slice(0, 10) === value
}

export function validateTimeBlockMutation(days: PlannerDayResponse[], command: PlannerMutation): Record<string, LocalValidationCode> {
  const target = timeBlockTarget(command.path)
  if (!target) return {}
  const creating = command.method === 'POST' && target.id === null
  const previous = target.id === null ? undefined : timeBlockInDays(days, target.id)
  if (!creating && !previous) return { request: 'missing' }
  if (command.method === 'DELETE') return {}
  const body = bodyOf(command)
  const errors: Record<string, LocalValidationCode> = {}
  if (!creating && !['title', 'note', 'block_date', 'starts_at', 'ends_at'].some(key => key in body)) errors.request = 'invalid'
  if ((creating || 'title' in body) && (typeof body.title !== 'string' || !body.title.trim() || body.title.length > 200)) errors.title = 'invalid'
  if ('note' in body && body.note !== null && (typeof body.note !== 'string' || body.note.length > 500)) errors.note = 'invalid'
  if ((creating || 'block_date' in body) && !calendarDate(body.block_date)) errors.block_date = 'invalid'
  for (const key of ['starts_at', 'ends_at']) {
    if (key in body && body[key] !== null && (typeof body[key] !== 'string' || !/^([01]\d|2[0-3]):[0-5]\d$/.test(body[key] as string))) errors[key] = 'invalid'
  }
  const start = 'starts_at' in body ? body.starts_at : previous?.starts_at
  const end = 'ends_at' in body ? body.ends_at : previous?.ends_at
  if (typeof start === 'string' && typeof end === 'string' && end <= start) errors.ends_at = 'invalid'
  return errors
}

/** Changes every downloaded view of the owning day; it does not invent an empty, undownloaded day. */
export function applyTimeBlockMutation(days: PlannerDayResponse[], command: PlannerMutation, confirmed?: { data?: unknown }): { days: PlannerDayResponse[]; result: { data: ProjectedTimeBlock } | undefined } {
  const target = timeBlockTarget(command.path)
  if (!target) return { days, result: undefined }
  const body = bodyOf(command)
  const creating = command.method === 'POST' && target.id === null
  const accepted = confirmed?.data as Partial<TimeBlock> | undefined
  const id = creating ? Number(accepted?.id ?? command.localId) : target.id
  if (id === null || !Number.isSafeInteger(id)) return { days, result: undefined }
  const previous = timeBlockInDays(days, id)
  if (!creating && !previous) return { days, result: undefined }
  let block: ProjectedTimeBlock | undefined
  if (command.method !== 'DELETE') {
    block = { id, title: '', note: null, block_date: '', starts_at: null, ends_at: null, ...previous, ...body, ...accepted,
      local_sync_status: confirmed ? undefined : command.status } as ProjectedTimeBlock
    block.title = block.title.trim()
    block.starts_at = block.starts_at?.slice(0, 5) ?? null
    block.ends_at = block.ends_at?.slice(0, 5) ?? null
  }
  return { days: days.map(day => {
    const entries = day.entries.filter(entry => entry.source !== 'time_block' || entry.source_id !== id)
    if (block?.block_date === day.date) entries.push({ source: 'time_block', source_id: id, title: block.title, time: block.starts_at,
      status: 'planned', actions: ['edit', 'delete'], meta: { ends_at: block.ends_at, note: block.note, local_sync_status: block.local_sync_status } })
    return { ...day, entries: sortPlannerEntries(entries) }
  }), result: block ? { data: block } : undefined }
}

function scheduled(item: StorageItem | undefined, date: string): item is StorageItem {
  return !!item && item.due_on === date && ['inbox', 'active'].includes(item.status)
}

/** Reflect only changed Storage records, preserving planner entries outside a bounded Storage snapshot. */
export function reflectStorageInPlanner(days: PlannerDayResponse[], before: StorageState, after: StorageState): PlannerDayResponse[] {
  const fields = (item: StorageItem) => [item.title, item.type, item.status, item.due_on, item.priority, item.project_id, item.local_sync_status]
  const oldItems = new Map(before.items.map(item => [item.id, item]))
  const newItems = new Map(after.items.map(item => [item.id, item]))
  const changed = new Set([...oldItems.keys(), ...newItems.keys()].filter(id => {
    const previous = oldItems.get(id); const next = newItems.get(id)
    return !previous || !next || JSON.stringify(fields(previous)) !== JSON.stringify(fields(next))
  }))
  return days.map(day => {
    const entries = day.entries.filter(entry => entry.source !== 'storage' || !changed.has(entry.source_id))
    for (const id of changed) {
      const item = newItems.get(id)
      if (scheduled(item, day.date)) entries.push({ source: 'storage', source_id: item.id, title: item.title, time: null,
        status: item.status, actions: ['move'], meta: { type: item.type, priority: item.priority, project_id: item.project_id, local_sync_status: item.local_sync_status } })
    }
    return { ...day, entries: sortPlannerEntries(entries) }
  })
}
