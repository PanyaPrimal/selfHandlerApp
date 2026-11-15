<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  createStorageItem,
  deleteStorageItem,
  getStorageItems,
  getStorageProjects,
  updateStorageItem,
  validationErrors,
} from '../api/client'
import type { StorageItem, StorageItemsResponse, StorageProject } from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { useI18n } from '../i18n'
import { itemDescendants } from '../offline/storage-projection'
import {
  dropTarget,
  parseChecklistId,
  positionAfter,
  sectionRows,
  type ChecklistId,
  type ChecklistRow,
} from '../storage/checklist'

/** Horizontal distance that changes a row's level while dragging. */
const INDENT = 28

const i18n = useI18n()
const route = useRoute()
const items = ref<StorageItem[]>([])
const projects = ref<StorageProject[]>([])
const isLoading = ref(true)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)
const doneExpanded = ref(true)
const titles = ref<Record<number, string>>({})
const draft = ref<{ parentId: number | null; position: number | null; depth: number; afterId: number | null; title: string } | null>(null)
const bottomTitle = ref('')
const listElement = ref<HTMLElement | null>(null)
let loadGeneration = 0

const listId = computed<ChecklistId | null>(() => parseChecklistId(route.params.list))
const project = computed(() => typeof listId.value === 'number' ? projects.value.find(row => row.id === listId.value) ?? null : null)
const listName = computed(() => listId.value === 'general' ? i18n.t('lists.general') : project.value?.name ?? '')
const highlighted = computed(() => typeof route.query.item === 'string' && /^\d+$/.test(route.query.item) ? Number(route.query.item) : null)
const openRows = computed(() => listId.value === null ? [] : sectionRows(items.value, listId.value, false))
const doneRows = computed(() => listId.value === null ? [] : sectionRows(items.value, listId.value, true))

/** The API embeds children under their parents; the checklist needs one flat set. */
function flatten(response: StorageItemsResponse): StorageItem[] {
  const rows = new Map<number, StorageItem>()
  for (const item of response.data) for (const child of item.children ?? []) rows.set(child.id, { ...child, children: undefined })
  for (const item of response.data) rows.set(item.id, { ...item, children: undefined })
  return [...rows.values()]
}

async function load(quiet = false): Promise<void> {
  const current = ++loadGeneration
  if (!quiet) isLoading.value = true
  loadError.value = null
  try {
    const [itemList, projectList] = await Promise.all([getStorageItems(), getStorageProjects()])
    if (current !== loadGeneration) return
    items.value = flatten(itemList)
    projects.value = projectList.data
    titles.value = Object.fromEntries(items.value.map(item => [item.id, item.title]))
    if (listId.value === null || (typeof listId.value === 'number' && !project.value)) loadError.value = i18n.t('lists.notFound')
  } catch {
    if (current === loadGeneration && !quiet) loadError.value = i18n.t('lists.loadFailed')
  } finally {
    if (current === loadGeneration) {
      isLoading.value = false
      await nextTick()
      if (highlighted.value !== null && !quiet) {
        document.querySelector<HTMLElement>(`[data-checklist-item="${highlighted.value}"]`)?.scrollIntoView({ block: 'center' })
      }
    }
  }
}

async function run(action: () => Promise<unknown>): Promise<void> {
  error.value = null
  try {
    await action()
  } catch (caught) {
    const errors = validationErrors(caught)
    error.value = Object.values(errors)[0]?.[0] ?? i18n.t('lists.saveFailed')
  }
  await load(true)
}

function toggle(item: StorageItem): void {
  const status = item.status === 'done' ? 'active' : 'done'
  // Show the result at once; the server's cascade comes back with the reload.
  items.value = items.value.map(row => row.id === item.id ? { ...row, status } : row)
  void run(() => updateStorageItem(item.id, { status, cascade: true }))
}

function saveTitle(item: StorageItem): void {
  const title = (titles.value[item.id] ?? '').trim()
  if (!title) {
    titles.value[item.id] = item.title
    return
  }
  if (title === item.title) return
  items.value = items.value.map(row => row.id === item.id ? { ...row, title } : row)
  void run(() => updateStorageItem(item.id, { title }))
}

function remove(item: StorageItem): void {
  const below = itemDescendants(items.value, item.id)
  if (below.length && !window.confirm(i18n.t('lists.deleteGroup', { name: item.title, count: below.length }))) return
  items.value = items.value.filter(row => row.id !== item.id && !below.some(child => child.id === row.id))
  // Deepest first, so no child is ever left without its parent in between.
  void run(async () => {
    for (const row of [...below].reverse()) await deleteStorageItem(row.id)
    await deleteStorageItem(item.id)
  })
}

function listProject(): number | null {
  return typeof listId.value === 'number' ? listId.value : null
}

async function focusDraft(): Promise<void> {
  await nextTick()
  document.querySelector<HTMLTextAreaElement>('[data-checklist-draft]')?.focus()
}

/** Enter on a row starts a new item right after it, on the same level. */
function startDraftAfter(row: ChecklistRow): void {
  saveTitle(row.item)
  draft.value = {
    parentId: row.item.parent_id, position: positionAfter(items.value, row.item),
    depth: row.depth, afterId: lastRowOfBranch(row.item.id), title: '',
  }
  void focusDraft()
}

function lastRowOfBranch(id: number): number {
  const below = new Set(itemDescendants(items.value, id).map(item => item.id))
  const rows = openRows.value
  let last = id
  for (const row of rows.slice(rows.findIndex(row => row.item.id === id) + 1)) {
    if (!below.has(row.item.id)) break
    last = row.item.id
  }
  return last
}

async function commitDraft(keepGoing: boolean): Promise<void> {
  const current = draft.value
  const title = current?.title.trim()
  if (!current || !title) {
    draft.value = null
    return
  }
  draft.value = keepGoing ? { ...current, title: '', position: current.position === null ? null : current.position + 1 } : null
  await run(async () => {
    const created = await createStorageItem({
      title, type: 'task', status: 'active', project_id: listProject(),
      ...(current.parentId !== null ? { parent_id: current.parentId } : {}),
      ...(current.position !== null ? { position: current.position } : {}),
    })
    if (draft.value && keepGoing) draft.value.afterId = created.id
  })
  if (keepGoing) void focusDraft()
}

async function addAtBottom(): Promise<void> {
  const title = bottomTitle.value.trim()
  if (!title) return
  bottomTitle.value = ''
  await run(() => createStorageItem({ title, type: 'task', status: 'active', project_id: listProject() }))
}

/* Dragging: the handle moves a row (with its group) up/down and left/right. */
const drag = ref<{ id: number; startX: number; index: number; offset: number; pointer: number } | null>(null)
const dragRows = computed<ChecklistRow[]>(() => {
  const current = drag.value
  if (!current) return openRows.value
  const moving = new Set([current.id, ...itemDescendants(items.value, current.id).map(item => item.id)])
  const others = openRows.value.filter(row => !moving.has(row.item.id))
  const target = dropTarget(items.value, openRows.value, current.id, current.index, current.offset)
  const dragged = openRows.value.find(row => row.item.id === current.id)!
  others.splice(Math.min(current.index, others.length), 0, { item: dragged.item, depth: target?.depth ?? dragged.depth })
  return others
})

function startDrag(event: PointerEvent, row: ChecklistRow): void {
  if (event.button !== 0) return
  event.preventDefault()
  // Its own group follows it, so the rows above it are exactly the others before it.
  drag.value = { id: row.item.id, startX: event.clientX, index: openRows.value.indexOf(row), offset: 0, pointer: event.pointerId }
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

function moveDrag(event: PointerEvent): void {
  const current = drag.value
  if (!current || event.pointerId !== current.pointer || !listElement.value) return
  const rows = [...listElement.value.querySelectorAll<HTMLElement>('[data-checklist-open]:not([data-dragging])')]
  const index = rows.filter(element => {
    const box = element.getBoundingClientRect()
    return box.top + box.height / 2 < event.clientY
  }).length
  current.index = index
  current.offset = Math.round((event.clientX - current.startX) / INDENT)
}

function endDrag(event: PointerEvent): void {
  const current = drag.value
  if (!current || event.pointerId !== current.pointer) return
  drag.value = null
  const item = items.value.find(row => row.id === current.id)
  const target = dropTarget(items.value, openRows.value, current.id, current.index, current.offset)
  if (!item || !target) return
  if (item.parent_id === target.parent_id && target.position === positionAfter(items.value, item) - 1) return
  items.value = items.value.map(row => row.id === item.id ? { ...row, parent_id: target.parent_id, position: target.position - 0.5 } : row)
  void run(() => updateStorageItem(item.id, { parent_id: target.parent_id, position: target.position }))
}

function cancelDrag(): void {
  drag.value = null
}

function storageChanged(): void {
  void load(true)
}

watch(() => route.params.list, () => { draft.value = null; void load() })
onMounted(() => { void load(); window.addEventListener('workspace-storage-changed', storageChanged) })
onBeforeUnmount(() => { loadGeneration++; window.removeEventListener('workspace-storage-changed', storageChanged) })
</script>

<template>
  <section class="view-stack checklist-page">
    <div class="checklist-header">
      <RouterLink to="/lists" class="checklist-back" :aria-label="i18n.t('lists.back')">←</RouterLink>
      <h1>{{ listName || i18n.t('lists.title') }}</h1>
    </div>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>

    <AsyncState :loading="isLoading" :error="loadError" @retry="load()">
      <div
        ref="listElement"
        class="checklist"
        :class="{ 'is-dragging': drag !== null }"
        @pointermove="moveDrag"
        @pointerup="endDrag"
        @pointercancel="cancelDrag"
      >
        <template v-for="row in dragRows" :key="row.item.id">
          <div
            class="checklist-row"
            data-checklist-open
            :data-checklist-item="row.item.id"
            :data-dragging="drag?.id === row.item.id ? '' : undefined"
            :class="{ 'is-highlighted': highlighted === row.item.id, 'is-pending': row.item.local_sync_status === 'pending' }"
            :style="{ '--depth': row.depth }"
          >
            <button
              type="button"
              class="checklist-handle"
              :aria-label="i18n.t('lists.drag', { name: row.item.title })"
              @pointerdown="startDrag($event, row)"
            >⠿</button>
            <button
              type="button"
              role="checkbox"
              aria-checked="false"
              class="checklist-check"
              :aria-label="i18n.t('lists.check', { name: row.item.title })"
              @click="toggle(row.item)"
            />
            <textarea
              v-model="titles[row.item.id]"
              class="checklist-title"
              rows="1"
              maxlength="200"
              :aria-label="i18n.t('lists.itemText')"
              @keydown.enter.prevent="startDraftAfter(row)"
              @blur="saveTitle(row.item)"
            />
            <button type="button" class="checklist-delete" :aria-label="i18n.t('lists.delete', { name: row.item.title })" @click="remove(row.item)">×</button>
          </div>
          <div v-if="draft && draft.afterId === row.item.id && !drag" class="checklist-row is-draft" :style="{ '--depth': draft.depth }">
            <span class="checklist-handle" aria-hidden="true" />
            <span class="checklist-check is-placeholder" aria-hidden="true" />
            <textarea
              v-model="draft.title"
              data-checklist-draft
              class="checklist-title"
              rows="1"
              maxlength="200"
              :aria-label="i18n.t('lists.newItem')"
              @keydown.enter.prevent="commitDraft(true)"
              @keydown.esc="draft = null"
              @blur="commitDraft(false)"
            />
          </div>
        </template>

        <label class="checklist-row checklist-add">
          <span class="checklist-plus" aria-hidden="true">+</span>
          <input
            v-model="bottomTitle"
            type="text"
            maxlength="200"
            :placeholder="i18n.t('lists.newItem')"
            :aria-label="i18n.t('lists.newItem')"
            @keydown.enter.prevent="addAtBottom"
            @blur="addAtBottom"
          />
        </label>
      </div>

      <section v-if="doneRows.length" class="checklist-done" :aria-label="i18n.t('lists.doneCount', { count: doneRows.length })">
        <button type="button" class="checklist-done-toggle" :aria-expanded="doneExpanded" @click="doneExpanded = !doneExpanded">
          <span aria-hidden="true">{{ doneExpanded ? '▾' : '▸' }}</span>
          {{ i18n.t('lists.doneCount', { count: doneRows.length }) }}
        </button>
        <div v-if="doneExpanded" class="checklist">
          <div
            v-for="row in doneRows"
            :key="row.item.id"
            class="checklist-row is-done"
            :data-checklist-item="row.item.id"
            :class="{ 'is-highlighted': highlighted === row.item.id }"
            :style="{ '--depth': row.depth }"
          >
            <span class="checklist-handle" aria-hidden="true" />
            <button
              type="button"
              role="checkbox"
              aria-checked="true"
              class="checklist-check"
              :aria-label="i18n.t('lists.uncheck', { name: row.item.title })"
              @click="toggle(row.item)"
            />
            <span class="checklist-title">{{ row.item.title }}</span>
            <button type="button" class="checklist-delete" :aria-label="i18n.t('lists.delete', { name: row.item.title })" @click="remove(row.item)">×</button>
          </div>
        </div>
      </section>
    </AsyncState>
  </section>
</template>

<style scoped>
.checklist-page { max-width: 760px; }
.checklist-header { display: flex; flex-direction: row; flex-wrap: nowrap; justify-content: flex-start; align-items: center; gap: 12px; text-align: left; }
.checklist-header h1 { flex: 1; margin: 0; font-size: 1.6rem; text-align: left; overflow-wrap: anywhere; }
.checklist-back {
  display: inline-grid; place-items: center; min-width: 44px; min-height: 44px;
  border-radius: var(--radius-sm); color: var(--ink); text-decoration: none; font-size: 1.4rem;
}
.checklist { display: flex; flex-direction: column; user-select: none; }
.checklist.is-dragging { touch-action: none; }
.checklist-row {
  --step: 28px;
  display: grid; grid-template-columns: 32px 32px minmax(0, 1fr) 40px; align-items: start; gap: 4px;
  padding: 4px 0 4px calc(var(--depth, 0) * var(--step)); border-radius: var(--radius-sm);
  transition: padding-left 120ms ease;
}
.checklist-row[data-dragging] { background: var(--accent-soft); box-shadow: var(--shadow); }
.checklist-row.is-highlighted { background: var(--accent-soft); }
.checklist-row.is-pending { opacity: .8; }
.checklist-handle {
  display: grid; place-items: center; width: 32px; min-height: 40px; padding: 0; border: 0; background: none;
  color: var(--subtle); font-size: 1.15rem; cursor: grab; touch-action: none;
}
.checklist.is-dragging .checklist-handle { cursor: grabbing; }
.checklist-check {
  display: grid; place-items: center; width: 22px; height: 22px; margin: 9px 5px; padding: 0;
  border: 1.5px solid var(--border-strong); border-radius: 6px; background: var(--surface, transparent); cursor: pointer;
}
.checklist-check:focus-visible { outline: none; border-color: var(--accent); box-shadow: var(--focus-halo); }
.checklist-check[aria-checked="true"] { border-color: var(--accent); background: var(--accent); }
.checklist-check[aria-checked="true"]::after {
  content: ''; width: 10px; height: 5px; margin-top: -3px;
  border-left: 2px solid #ffffff; border-bottom: 2px solid #ffffff; transform: rotate(-45deg);
}
.checklist-check.is-placeholder { opacity: .4; cursor: default; }
.checklist-title {
  width: 100%; min-height: 40px; padding: 8px 4px; border: 0; background: transparent; color: var(--ink);
  font: inherit; font-size: 1.08rem; line-height: 1.4; resize: none; field-sizing: content; overflow-wrap: anywhere;
  user-select: text;
}
.checklist-title:focus { outline: none; box-shadow: inset 0 -2px 0 var(--accent); }
.checklist-delete {
  width: 40px; min-height: 40px; border: 0; background: none; color: var(--subtle); font-size: 1.3rem;
  opacity: 0; cursor: pointer;
}
.checklist-row:hover .checklist-delete, .checklist-row:focus-within .checklist-delete { opacity: 1; }
@media (hover: none) { .checklist-delete { opacity: .55; } }
.checklist-add { grid-template-columns: 32px minmax(0, 1fr); padding-left: 32px; color: var(--muted); cursor: text; }
.checklist-plus { display: grid; place-items: center; min-height: 40px; font-size: 1.4rem; }
.checklist-add input {
  min-height: 40px; border: 0; background: transparent; color: var(--ink); font: inherit; font-size: 1.08rem;
}
.checklist-add input:focus { outline: none; box-shadow: inset 0 -2px 0 var(--accent); }
.checklist-done { border-top: 1px solid var(--border); padding-top: 8px; }
.checklist-done-toggle {
  display: flex; align-items: center; gap: 8px; min-height: 44px; padding: 0 8px; border: 0; background: none;
  color: var(--muted); font: inherit; cursor: pointer;
}
.checklist-row.is-done .checklist-title { color: var(--subtle); text-decoration: line-through; padding-top: 9px; }
.checklist-row.is-done .checklist-check { opacity: .6; }
</style>
