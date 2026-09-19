import { describe, expect, it } from 'vitest'
import type { PlannerDayResponse, PlannerEntry, StorageItem } from '../api/types'
import { applyTimeBlockMutation, reflectStorageInPlanner, remapTimeBlockCommand, sortPlannerEntries, timeBlockInDays, validateTimeBlockMutation, type PlannerMutation } from './planner-projection'
import type { StorageState } from './storage-projection'

const today = '2026-09-19'
const tomorrow = '2026-09-20'
const day = (date: string, entries: PlannerEntry[] = []): PlannerDayResponse => ({ date, today, entries,
  window: { materialized_until: '2026-12-31', beyond: false }, sources: ['routine', 'storage', 'time_block'] })
const command = (path: string, method: string, body: unknown, localId?: number): PlannerMutation => ({ path, method, body: body === null ? null : JSON.stringify(body), localId, created: 1, status: 'pending' })
const block = (extra = {}) => command('/planner/time-blocks', 'POST', { title: 'Dentist', block_date: today, starts_at: '14:00', ends_at: '15:00', ...extra }, -1)
const item = (extra = {}): StorageItem => ({ id: 3, title: 'Buy supplies', type: 'task', status: 'active', description: null, tags: [], due_on: today,
  priority: 'normal', parent_id: null, project_id: null, is_blocker: false, estimated_amount: null, estimated_currency_code: null, completed_at: null, dropped_at: null, ...extra })
const state = (items: StorageItem[]): StorageState => ({ items, projects: [], inboxCount: 0 })

describe('offline Planner projection', () => {
  it('creates, moves and deletes a local time block without altering another source sharing its ID', () => {
    const routine: PlannerEntry = { source: 'routine', source_id: -1, title: 'Routine', time: '08:00', status: 'planned', actions: [], meta: {} }
    const base = [day(today, [routine]), day(tomorrow)]
    const created = applyTimeBlockMutation(base, block())
    expect(created.result?.data).toMatchObject({ id: -1, title: 'Dentist', local_sync_status: 'pending' })
    expect(created.days[0]?.entries.map(entry => entry.source)).toEqual(['routine', 'time_block'])
    const moved = applyTimeBlockMutation(created.days, command('/planner/time-blocks/-1', 'PATCH', { block_date: tomorrow }))
    expect(moved.days[0]?.entries).toEqual([routine])
    expect(timeBlockInDays(moved.days, -1)?.block_date).toBe(tomorrow)
    const removed = applyTimeBlockMutation(moved.days, command('/planner/time-blocks/-1', 'DELETE', null))
    expect(removed.days[1]?.entries).toEqual([])
    expect(base[0]?.entries).toEqual([routine])
  })

  it('validates real calendar dates, local clock values and partial updates against the existing span', () => {
    expect(validateTimeBlockMutation([day(today)], block())).toEqual({})
    expect(validateTimeBlockMutation([day(today)], block({ title: ' ', block_date: '2026-02-30', starts_at: '24:00' }))).toMatchObject({ title: 'invalid', block_date: 'invalid', starts_at: 'invalid' })
    const created = applyTimeBlockMutation([day(today)], block()).days
    expect(validateTimeBlockMutation(created, command('/planner/time-blocks/-1', 'PATCH', { ends_at: '13:59' }))).toEqual({ ends_at: 'invalid' })
    expect(validateTimeBlockMutation(created, command('/planner/time-blocks/-1', 'PATCH', { starts_at: null, ends_at: '13:59' }))).toEqual({})
    expect(validateTimeBlockMutation(created, command('/planner/time-blocks/-1', 'PATCH', {}))).toEqual({ request: 'invalid' })
    expect(validateTimeBlockMutation(created, command('/planner/time-blocks/99', 'DELETE', null))).toEqual({ request: 'missing' })
  })

  it('accepts overlapping blocks and untimed appointments, while ordering timed entries first', () => {
    const first = applyTimeBlockMutation([day(today)], block()).days
    const overlap = { ...block({ title: 'Call', starts_at: '14:30', ends_at: '15:15' }), localId: -2 }
    expect(validateTimeBlockMutation(first, overlap)).toEqual({})
    const second = applyTimeBlockMutation(first, overlap).days
    const third = applyTimeBlockMutation(second, { ...block({ title: 'A note', starts_at: null, ends_at: null }), localId: -3 }).days
    expect(third[0]?.entries.map(entry => entry.title)).toEqual(['Dentist', 'Call', 'A note'])
    expect(sortPlannerEntries(third[0]!.entries)).toEqual(third[0]!.entries)
  })

  it('uses confirmed server values and rewrites only a dependent time-block path', () => {
    const response = applyTimeBlockMutation([day(today)], block(), { data: { id: 40, title: 'Accepted dentist', starts_at: '14:05:00' } })
    expect(response.result?.data).toMatchObject({ id: 40, title: 'Accepted dentist', starts_at: '14:05', local_sync_status: undefined })
    expect(remapTimeBlockCommand(command('/planner/time-blocks/-1', 'PATCH', { title: '-1' }), { local: -1, server: 40 }).path).toBe('/planner/time-blocks/40')
    expect(remapTimeBlockCommand(command('/storage/items/-1', 'DELETE', null), { local: -1, server: 40 }).path).toBe('/storage/items/-1')
  })

  it('moves and completes the same Storage record without dropping entries outside the downloaded Storage list', () => {
    const unknown: PlannerEntry = { source: 'storage', source_id: 999, title: 'Older item', time: null, status: 'active', actions: ['move'], meta: {} }
    const initial = reflectStorageInPlanner([day(today, [unknown]), day(tomorrow)], state([]), state([item()]))
    const movedItem = item({ due_on: tomorrow, local_sync_status: 'pending' })
    const moved = reflectStorageInPlanner(initial, state([item()]), state([movedItem]))
    expect(moved[0]?.entries).toEqual([unknown])
    expect(moved[1]?.entries[0]).toMatchObject({ source: 'storage', source_id: 3, meta: { local_sync_status: 'pending' } })
    const completed = reflectStorageInPlanner(moved, state([movedItem]), state([item({ due_on: tomorrow, status: 'done' })]))
    expect(completed[1]?.entries).toEqual([])
    expect(completed[0]?.entries).toEqual([unknown])
  })

  it('adds offline Storage creations, removes deletions, and reflects project deletion in entry metadata', () => {
    const draft = item({ id: -7, project_id: -8, local_sync_status: 'pending' })
    const created = reflectStorageInPlanner([day(today)], state([]), state([draft]))
    expect(created[0]?.entries[0]?.meta.project_id).toBe(-8)
    const detached = reflectStorageInPlanner(created, state([draft]), state([{ ...draft, project_id: null }]))
    expect(detached[0]?.entries[0]?.meta.project_id).toBeNull()
    expect(reflectStorageInPlanner(detached, state([{ ...draft, project_id: null }]), state([]))[0]?.entries).toEqual([])
  })

  it('does not present an undownloaded day as an empty, complete calendar', () => {
    const result = applyTimeBlockMutation([day(today)], block({ block_date: tomorrow }))
    expect(result.days.map(row => row.date)).toEqual([today])
    expect(result.result?.data.block_date).toBe(tomorrow)
  })
})
