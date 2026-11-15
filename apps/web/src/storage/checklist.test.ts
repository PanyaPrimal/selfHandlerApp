import { describe, expect, it } from 'vitest'
import type { StorageItem } from '../api/types'
import { dropTarget, inChecklist, parseChecklistId, positionAfter, sectionRows } from './checklist'

let next = 1
const task = (title: string, extra: Partial<StorageItem> = {}): StorageItem => ({
  id: next++, type: 'task', title, description: null, status: 'active', priority: null, estimated_amount: null,
  estimated_currency_code: null, due_on: null, project_id: null, parent_id: null, position: 0, is_blocker: false,
  completed_at: null, dropped_at: null, tags: [], ...extra,
})

function sample() {
  next = 1
  const trip = task('Trip', { position: 0 })
  const packing = task('Packing', { parent_id: trip.id, position: 0 })
  const socks = task('Socks', { parent_id: packing.id, position: 0 })
  const charger = task('Charger', { parent_id: packing.id, position: 1, status: 'done' })
  const tickets = task('Tickets', { parent_id: trip.id, position: 1 })
  const yoga = task('Yoga', { position: 1 })
  const done = task('Old', { position: 2, status: 'done' })
  const other = task('Elsewhere', { project_id: 5 })
  const idea = task('Idea', { type: 'idea' })
  return { items: [trip, packing, socks, charger, tickets, yoga, done, other, idea], trip, packing, socks, charger, tickets, yoga, done }
}

const titles = (rows: { item: StorageItem; depth: number }[]) => rows.map(row => `${'-'.repeat(row.depth)}${row.item.title}`)

describe('checklist', () => {
  it('parses list ids and keeps only tasks of that list', () => {
    expect([parseChecklistId('general'), parseChecklistId('12'), parseChecklistId('x')]).toEqual(['general', 12, null])
    const { items } = sample()
    expect(items.filter(item => inChecklist(item, 'general')).map(item => item.title)).not.toContain('Elsewhere')
    expect(items.filter(item => inChecklist(item, 'general')).map(item => item.title)).not.toContain('Idea')
  })

  it('splits open and finished rows into trees that keep their order', () => {
    const { items } = sample()
    expect(titles(sectionRows(items, 'general', false))).toEqual(['Trip', '-Packing', '--Socks', '-Tickets', 'Yoga'])
    expect(titles(sectionRows(items, 'general', true))).toEqual(['Charger', 'Old'])
  })

  it('projects a drop into a parent and an index among all siblings', () => {
    const { items, yoga, trip, packing, tickets } = sample()
    const rows = sectionRows(items, 'general', false)
    // Yoga dropped right after Socks, shifted two levels right: a third-level sibling of Socks.
    expect(dropTarget(items, rows, yoga.id, 3, 2)).toEqual({ parent_id: packing.id, position: 1, depth: 2 })
    // Yoga to the very top.
    expect(dropTarget(items, rows, yoga.id, 0, 0)).toEqual({ parent_id: null, position: 0, depth: 0 })
    // Yoga after Tickets, one level right: last child of Trip.
    expect(dropTarget(items, rows, yoga.id, 4, 1)).toEqual({ parent_id: trip.id, position: 2, depth: 1 })
    // A group with children cannot go deeper than the limit allows.
    expect(dropTarget(items, rows, packing.id, 2, 5)).toEqual({ parent_id: trip.id, position: 1, depth: 1 })
    expect(positionAfter(items, tickets)).toBe(2)
  })
})
