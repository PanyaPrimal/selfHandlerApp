import type { StorageItem } from '../api/types'
import { MAX_ITEM_DEPTH, itemDescendants, itemHeight, itemOrder } from '../offline/storage-projection'

/** A list is a Storage project, or "general" for tasks without one. */
export type ChecklistId = number | 'general'

export interface ChecklistRow {
  item: StorageItem
  /** Visual level inside its section, 0 for a top-level row. */
  depth: number
}

export const OPEN_STATUSES: StorageItem['status'][] = ['inbox', 'active']

export function parseChecklistId(value: unknown): ChecklistId | null {
  if (value === 'general') return 'general'
  return typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : null
}

export function inChecklist(item: StorageItem, list: ChecklistId): boolean {
  return item.type === 'task' && (item.project_id ?? null) === (list === 'general' ? null : list)
}

/**
 * Rows of one section in tree order. An item starts a branch when its parent is
 * not in the same section, so a finished child of an open group still shows
 * among the finished rows and an open child of a finished group among the open.
 */
export function sectionRows(items: StorageItem[], list: ChecklistId, done: boolean): ChecklistRow[] {
  const members = items.filter(item => inChecklist(item, list)
    && (done ? item.status === 'done' : OPEN_STATUSES.includes(item.status)))
  const ids = new Set(members.map(item => item.id))
  const rows: ChecklistRow[] = []
  const visit = (item: StorageItem, depth: number) => {
    rows.push({ item, depth })
    for (const child of members.filter(row => row.parent_id === item.id).sort(itemOrder)) visit(child, Math.min(depth + 1, MAX_ITEM_DEPTH - 1))
  }
  for (const root of members.filter(item => item.parent_id === null || !ids.has(item.parent_id)).sort(itemOrder)) visit(root, 0)
  return rows
}

export interface DropTarget {
  parent_id: number | null
  /** 0-based index among all siblings of the new parent, finished ones included. */
  position: number
  depth: number
}

/**
 * Where a dragged open row lands. `targetIndex` is its new index among the
 * visible open rows without itself and its own descendants, `depthOffset` the
 * horizontal shift in levels.
 */
export function dropTarget(items: StorageItem[], rows: ChecklistRow[], dragId: number, targetIndex: number, depthOffset: number): DropTarget | null {
  const dragged = rows.find(row => row.item.id === dragId)
  if (!dragged) return null
  const moving = new Set([dragId, ...itemDescendants(items, dragId).map(item => item.id)])
  const others = rows.filter(row => !moving.has(row.item.id))
  const index = Math.max(0, Math.min(targetIndex, others.length))
  const previous = others[index - 1]
  const height = itemHeight(items, dragId)
  const maximum = Math.min(previous ? previous.depth + 1 : 0, MAX_ITEM_DEPTH - 1 - height)
  const depth = Math.max(0, Math.min(dragged.depth + depthOffset, maximum))

  let parent: StorageItem | null = null
  let previousSibling: StorageItem | null = null
  for (let cursor = index - 1; cursor >= 0; cursor--) {
    const row = others[cursor]!
    // Deeper rows belong to earlier siblings; the first shallower row is the parent.
    if (row.depth === depth && !previousSibling) previousSibling = row.item
    if (row.depth < depth) { parent = row.item; break }
  }
  if (depth > 0 && !parent) return null
  const parentId = depth === 0 ? null : parent!.id
  const siblings = items.filter(item => item.id !== dragId && item.parent_id === parentId
    && (parentId !== null || (item.project_id ?? null) === (dragged.item.project_id ?? null)) && item.type === 'task').sort(itemOrder)
  const after = previousSibling && previousSibling.parent_id === parentId ? siblings.findIndex(item => item.id === previousSibling!.id) : -1
  return { parent_id: parentId, position: after + 1, depth }
}

/** Index after which a new sibling of `item` goes. */
export function positionAfter(items: StorageItem[], item: StorageItem): number {
  const siblings = items.filter(row => row.parent_id === item.parent_id
    && (item.parent_id !== null || (row.project_id ?? null) === (item.project_id ?? null))).sort(itemOrder)
  return siblings.findIndex(row => row.id === item.id) + 1
}
