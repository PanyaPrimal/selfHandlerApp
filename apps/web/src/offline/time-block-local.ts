import type { PlannerDayResponse, PlannerEntry, TimeBlock } from '../api/types'
import { mutateLocalEntries, type LocalEntry } from './database'
import type { CachedRead, LocalCommand } from './workspace'
import { applyTimeBlockMutation, sortPlannerEntries, timeBlockInDays, timeBlockTarget, validateTimeBlockMutation, type ProjectedTimeBlock, type TimeBlockIdentity } from './planner-projection'
import type { LocalValidationCode } from './storage-projection'

interface BlockRecord { data: TimeBlock | null; revision: number }
const prefix = (owner: number) => `account:${owner}:`
const blockKey = (owner: number, id: number) => `${prefix(owner)}entity:time-block:${id}`
export const timeBlockIdentityKey = (owner: number, id: number) => `${prefix(owner)}identity:time-block:${id}`
const dayPath = (path: string) => /^\/planner\/day(?:\?date=\d{4}-\d{2}-\d{2})?$/.test(path)

function dayEntries(owner: number, entries: LocalEntry[]): LocalEntry[] {
  const start = `${prefix(owner)}read:`
  return entries.filter(entry => entry.key.startsWith(start) && dayPath(entry.key.slice(start.length))
    && Array.isArray(((entry.value as CachedRead).data as PlannerDayResponse)?.entries))
}

/** Tombstones keep an older downloaded day from resurrecting a deleted block. */
function records(owner: number, entries: LocalEntry[]): Map<number, BlockRecord> {
  const result = new Map<number, BlockRecord>()
  for (const entry of dayEntries(owner, entries)) {
    const cached = entry.value as CachedRead
    const day = cached.data as PlannerDayResponse
    for (const row of day.entries.filter(row => row.source === 'time_block')) {
      const old = result.get(row.source_id)
      if (!old || old.revision < cached.revision) result.set(row.source_id, { data: timeBlockInDays([day], row.source_id)!, revision: cached.revision })
    }
  }
  const start = `${prefix(owner)}entity:time-block:`
  for (const entry of entries.filter(entry => entry.key.startsWith(start))) {
    const id = Number(entry.key.slice(start.length))
    const record = entry.value as BlockRecord
    if (!result.has(id) || result.get(id)!.revision <= record.revision) result.set(id, record)
  }
  return result
}

export function cachedTimeBlockEntries(owner: number, entries: LocalEntry[], path: string, data: unknown, revision: number): LocalEntry[] {
  if (!dayPath(path) || !Array.isArray((data as PlannerDayResponse)?.entries)) return []
  const day = data as PlannerDayResponse
  const known = records(owner, entries)
  const received = new Set<number>()
  const put: LocalEntry[] = []
  for (const entry of day.entries.filter(row => row.source === 'time_block')) {
    received.add(entry.source_id)
    if ((known.get(entry.source_id)?.revision ?? -1) <= revision) put.push({ key: blockKey(owner, entry.source_id), value: { data: timeBlockInDays([day], entry.source_id), revision } })
  }
  for (const [id, record] of known) {
    if (record.revision <= revision && record.data?.block_date === day.date && !received.has(id)) put.push({ key: blockKey(owner, id), value: { data: null, revision } })
  }
  return put
}

function blockEntry(block: ProjectedTimeBlock): PlannerEntry {
  return { source: 'time_block', source_id: block.id, title: block.title, time: block.starts_at, status: 'planned', actions: ['edit', 'delete'],
    meta: { note: block.note, ends_at: block.ends_at, local_sync_status: block.local_sync_status } }
}

/** Internal record adapter only. These are never returned as complete downloaded calendar days. */
function recordDays(blocks: ProjectedTimeBlock[]): PlannerDayResponse[] {
  return [...new Set(blocks.map(block => block.block_date))].map(date => ({ date, today: date, sources: ['time_block'],
    window: { materialized_until: null, beyond: true }, entries: blocks.filter(block => block.block_date === date).map(blockEntry) }))
}

function queued(owner: number, entries: LocalEntry[]): LocalCommand[] {
  return entries.filter(entry => entry.key.startsWith(`${prefix(owner)}command:`)).map(entry => entry.value as LocalCommand)
    .filter(command => timeBlockTarget(command.path)).sort((a, b) => a.created - b.created || a.id.localeCompare(b.id))
}

function overlay(owner: number, entries: LocalEntry[], commands: LocalCommand[]): ProjectedTimeBlock[] {
  let blocks = [...records(owner, entries).values()].flatMap(record => record.data ? [record.data] : [])
  for (const command of commands.filter(command => command.localProjected)) {
    const target = timeBlockTarget(command.path)!
    const result = applyTimeBlockMutation(recordDays(blocks), command).result
    if (command.method === 'DELETE') blocks = blocks.filter(block => block.id !== target.id)
    else if (result) blocks = [...blocks.filter(block => block.id !== result.data.id), result.data]
  }
  return blocks
}

export function projectPendingTimeBlocks(owner: number, entries: LocalEntry[], day: PlannerDayResponse): PlannerDayResponse {
  const blocks = overlay(owner, entries, queued(owner, entries))
  return { ...day, entries: sortPlannerEntries([...day.entries.filter(entry => entry.source !== 'time_block'),
    ...blocks.filter(block => block.block_date === day.date).map(blockEntry)]) }
}

/** Normalize an incoming snapshot without persisting any unacknowledged commands in it. */
export function projectKnownTimeBlocks(owner: number, entries: LocalEntry[], day: PlannerDayResponse): PlannerDayResponse {
  const blocks = [...records(owner, entries).values()].flatMap(record => record.data ? [record.data] : [])
  return { ...day, entries: sortPlannerEntries([...day.entries.filter(entry => entry.source !== 'time_block'),
    ...blocks.filter(block => block.block_date === day.date).map(blockEntry)]) }
}

export async function stageTimeBlockProjection(command: LocalCommand): Promise<{ handled: boolean; errors?: Record<string, LocalValidationCode>; value?: unknown }> {
  const target = timeBlockTarget(command.path)
  if (!target || (command.method === 'POST' && command.localId === undefined)) return { handled: false }
  const start = prefix(command.owner)
  return mutateLocalEntries<{ handled: boolean; errors?: Record<string, LocalValidationCode>; value?: unknown }>([`${start}read:`, `${start}entity:time-block:`, `${start}command:`], entries => {
    const pending = queued(command.owner, entries)
    const position = pending.findIndex(row => row.id === command.id)
    if (position < 0 || !dayEntries(command.owner, entries).length || pending.some(row => row.status !== 'pending')) return { result: { handled: false } }
    const stored = pending[position]!
    const days = recordDays(overlay(command.owner, entries, pending.slice(0, position)))
    const errors = validateTimeBlockMutation(days, stored)
    if (Object.keys(errors).length) return { result: { handled: true, errors } }
    const { result } = applyTimeBlockMutation(days, stored)
    return { put: [{ key: `${start}command:${stored.id}`, value: { ...stored, localProjected: true } }], result: { handled: true, value: result } }
  })
}

export function acknowledgedTimeBlockEntries(owner: number, entries: LocalEntry[], command: LocalCommand, data: unknown, revision: number): { put: LocalEntry[]; identity?: TimeBlockIdentity } {
  const target = timeBlockTarget(command.path)
  if (!target) return { put: [] }
  const accepted = (data as { data?: TimeBlock } | null)?.data
  const id = target.id ?? accepted?.id
  if (!Number.isSafeInteger(id) || id! <= 0) return { put: [] }
  const identity = command.method === 'POST' && command.localId !== undefined ? { local: command.localId, server: id! } : undefined
  const put: LocalEntry[] = identity ? [{ key: timeBlockIdentityKey(owner, identity.local), value: identity }] : []
  const previous = records(owner, entries).get(id!)
  const block = previous && previous.revision >= revision ? previous.data : command.method === 'DELETE' ? null : accepted
    ? { ...accepted, starts_at: accepted.starts_at?.slice(0, 5) ?? null, ends_at: accepted.ends_at?.slice(0, 5) ?? null } : previous?.data
  if (block === undefined) return { put, identity }
  if (!previous || previous.revision < revision) put.push({ key: blockKey(owner, id!), value: { data: block, revision } })
  for (const entry of dayEntries(owner, entries)) {
    const cached = entry.value as CachedRead
    if (cached.revision >= revision) continue
    const day = cached.data as PlannerDayResponse
    const rows = day.entries.filter(row => row.source !== 'time_block' || row.source_id !== id)
    if (block?.block_date === day.date) rows.push(blockEntry(block))
    put.push({ key: entry.key, value: { ...cached, data: { ...day, entries: sortPlannerEntries(rows) } } })
  }
  return { put, identity }
}

export function needsTimeBlockIdentity(command: LocalCommand): boolean {
  const id = timeBlockTarget(command.path)?.id
  return typeof id === 'number' && id < 0
}
