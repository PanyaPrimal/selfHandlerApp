import { describe, expect, it } from 'vitest'
import type { PlannerDayResponse, TimeBlock } from '../api/types'
import type { LocalEntry } from './database'
import type { CachedRead, LocalCommand } from './workspace'
import { acknowledgedTimeBlockEntries, cachedTimeBlockEntries, needsTimeBlockIdentity, projectKnownTimeBlocks, projectPendingTimeBlocks } from './time-block-local'

const owner = 17
const date = '2026-09-19'
const next = '2026-09-20'
const block: TimeBlock = { id: 30, title: 'Call', block_date: date, starts_at: '13:00', ends_at: '14:00', note: 'Remember' }
const day = (on = date, blocks: TimeBlock[] = [block]): PlannerDayResponse => ({ date: on, today: date, sources: ['time_block', 'routine'],
  window: { beyond: false, materialized_until: next }, entries: blocks.map(row => ({ source: 'time_block', source_id: row.id, title: row.title,
    time: row.starts_at, status: 'planned', actions: ['edit', 'delete'], meta: { ends_at: row.ends_at, note: row.note } })) })
const read = (data: PlannerDayResponse, revision = 4, account = owner): LocalEntry => ({ key: `account:${account}:read:/planner/day?date=${data.date}`, value: { data, revision, saved: 1 } })
const command = (path: string, method: string, body: unknown, extra = {}): LocalCommand => ({ id: 'operation', owner, path, method,
  body: body === null ? null : JSON.stringify(body), base: 4, created: 1, status: 'pending', title: '', message: null, localProjected: true, ...extra })
const row = (value: LocalCommand): LocalEntry => ({ key: `account:${value.owner}:command:${value.id}`, value })
const putInto = (entries: LocalEntry[], put: LocalEntry[]) => [...new Map([...entries, ...put].map(entry => [entry.key, entry])).values()]

describe('durable offline time blocks', () => {
  it('imports owned records from a day and retains tombstones when a later day omits them', () => {
    const initial = [read(day())]
    const imported = cachedTimeBlockEntries(owner, initial, `/planner/day?date=${date}`, day(), 4)
    expect(imported).toHaveLength(1)
    const deleted = cachedTimeBlockEntries(owner, putInto(initial, imported), `/planner/day?date=${date}`, day(date, []), 5)
    expect(deleted[0]?.value).toEqual({ data: null, revision: 5 })
    const entries = putInto(initial, deleted)
    expect(projectPendingTimeBlocks(owner, entries, day()).entries).toEqual([])
    expect(cachedTimeBlockEntries(owner, entries, `/planner/day?date=${date}`, day(), 3)).toEqual([])
  })

  it('does not let a calendar response from another date erase a block that moved there', () => {
    const moved = { ...block, block_date: next }
    const entries = [{ key: `account:${owner}:entity:time-block:30`, value: { data: moved, revision: 5 } }]
    expect(cachedTimeBlockEntries(owner, entries, `/planner/day?date=${date}`, day(date, []), 6)).toEqual([])
    expect(projectPendingTimeBlocks(owner, entries, day(next, [])).entries[0]?.title).toBe('Call')
  })

  it('keeps creation, move to an undownloaded day and another edit as one sequence of local records', () => {
    const create = command('/planner/time-blocks', 'POST', { ...block, id: undefined }, { localId: -1 })
    const move = command('/planner/time-blocks/-1', 'PATCH', { block_date: next }, { id: 'move', created: 2 })
    const edit = command('/planner/time-blocks/-1', 'PATCH', { title: 'Changed call' }, { id: 'edit', created: 3 })
    const entries = [read(day(date, [])), row(create), row(move), row(edit)]
    expect(projectPendingTimeBlocks(owner, entries, day(date, [])).entries).toEqual([])
    expect(projectPendingTimeBlocks(owner, entries, day(next, [])).entries[0]).toMatchObject({ source_id: -1, title: 'Changed call', meta: { local_sync_status: 'pending' } })
    const removed = command('/planner/time-blocks/-1', 'DELETE', null, { id: 'delete', created: 4 })
    expect(projectPendingTimeBlocks(owner, [...entries, row(removed)], day(next, [])).entries).toEqual([])
  })

  it('persists a confirmed block even when its destination day has never been downloaded', () => {
    const mutation = command('/planner/time-blocks/30', 'PATCH', { block_date: next })
    const result = acknowledgedTimeBlockEntries(owner, [read(day())], mutation, { data: { ...block, block_date: next, starts_at: '13:00:00', ends_at: '14:00:00' } }, 5)
    expect(result.put.find(entry => entry.key.includes(':entity:'))?.value).toMatchObject({ data: { block_date: next, starts_at: '13:00', ends_at: '14:00' }, revision: 5 })
    expect(((result.put.find(entry => entry.key.includes(':read:'))!.value as CachedRead).data as PlannerDayResponse).entries).toEqual([])
    const edit = command('/planner/time-blocks/30', 'PATCH', { note: 'Second edit' })
    expect(projectPendingTimeBlocks(owner, [...result.put, row(edit)], day(next, [])).entries[0]?.meta.note).toBe('Second edit')
  })

  it('returns identity, record and downloaded-day updates in one receipt payload', () => {
    const create = command('/planner/time-blocks', 'POST', { ...block, id: undefined }, { localId: -2 })
    const result = acknowledgedTimeBlockEntries(owner, [read(day(date, []))], create, { data: block }, 5)
    expect(result.identity).toEqual({ local: -2, server: 30 })
    expect(result.put).toHaveLength(3)
    expect(((result.put.find(entry => entry.key.includes(':read:'))!.value as CachedRead).data as PlannerDayResponse).entries[0]?.meta.local_sync_status).toBeUndefined()
  })

  it('does not overwrite a newer record on replay, while still retaining the alias', () => {
    const create = command('/planner/time-blocks', 'POST', block, { localId: -1 })
    const entries = [read(day(), 4), { key: `account:${owner}:entity:time-block:30`, value: { data: { ...block, title: 'Latest' }, revision: 7 } }]
    const result = acknowledgedTimeBlockEntries(owner, entries, create, { data: block }, 5)
    expect(result.put.some(entry => entry.key.includes(':entity:'))).toBe(false)
    expect(result.identity).toEqual({ local: -1, server: 30 })
    expect(((result.put.find(entry => entry.key.includes(':read:'))!.value as CachedRead).data as PlannerDayResponse).entries[0]?.title).toBe('Latest')
  })

  it('keeps other users and other calendar sources outside the mutation', () => {
    const routine = { source: 'routine' as const, source_id: 30, title: 'Routine', time: '12:00', status: 'planned' as const, actions: [], meta: {} }
    const original = { ...day(), entries: [...day().entries, routine] }
    const entries = [read(original), read(day(date, [{ ...block, id: 99, title: 'Private other user' }]), 9, 18)]
    const result = acknowledgedTimeBlockEntries(owner, entries, command('/planner/time-blocks/30', 'DELETE', null), undefined, 5)
    expect(result.put.every(entry => entry.key.startsWith(`account:${owner}:`))).toBe(true)
    expect(((result.put.find(entry => entry.key.includes(':read:'))!.value as CachedRead).data as PlannerDayResponse).entries).toEqual([routine])
    expect(projectPendingTimeBlocks(owner, putInto(entries, result.put), original).entries).toEqual([routine])
  })

  it('requires resolution only for a temporary block path, never a negative value in its note', () => {
    expect(needsTimeBlockIdentity(command('/planner/time-blocks/-1', 'DELETE', null))).toBe(true)
    expect(needsTimeBlockIdentity(command('/planner/time-blocks', 'POST', { note: '-1' }, { localId: -1 }))).toBe(false)
    expect(needsTimeBlockIdentity(command('/storage/items/-1', 'DELETE', null))).toBe(false)
  })

  it('normalizes stale incoming day aliases without baking pending changes into their server baseline', () => {
    const create = command('/planner/time-blocks', 'POST', { ...block, title: 'Pending' }, { localId: -4 })
    const entries = [read(day(), 3), { key: `account:${owner}:entity:time-block:30`, value: { data: null, revision: 5 } }, row(create)]
    expect(projectKnownTimeBlocks(owner, entries, day()).entries).toEqual([])
    expect(projectPendingTimeBlocks(owner, entries, day()).entries[0]).toMatchObject({ title: 'Pending', source_id: -4 })
  })
})
