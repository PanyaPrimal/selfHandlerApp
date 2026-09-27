<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { createStorageItem, getPlannerDay, getStorageItems, getStorageProjects, updateStorageItem, validationErrors } from '../api/client'
import { ApiError } from '../api/http'
import type { ItemType, PlannerEntry } from '../api/types'
import { useI18n } from '../i18n'
import { UiSelect, UiTextInput } from './ui'

const props = defineProps<{ date: string }>()
const { t } = useI18n()
const title = ref('')
const type = ref<ItemType>('task')
const destination = ref('day')
const saving = ref(false)
const actionId = ref<number | null>(null)
const error = ref('')
const fieldError = ref('')
const feedback = ref('')
const loadError = ref('')
const loading = ref(false)
const entries = ref<PlannerEntry[]>([])
const input = ref<{ focus: () => void } | null>(null)
const typeOptions = computed(() => [
  { value: 'task' as const, label: t('storage.task') },
  { value: 'idea' as const, label: t('storage.idea') },
  { value: 'purchase' as const, label: t('storage.purchase') },
])
const destinationOptions = computed(() => [
  { value: 'day', label: t('daily.selectedDay') },
  { value: 'inbox', label: t('storage.inbox') },
])
let attempt: { body: string; id: string } | null = null
let generation = 0

async function load(): Promise<void> {
  const current = ++generation
  if (!props.date) return
  loading.value = true
  loadError.value = ''
  try {
    // Warm the existing Storage snapshots as well: offline capture needs both
    // item and project baselines even when the user has only opened Today.
    const [dayResult] = await Promise.allSettled([getPlannerDay(props.date), getStorageItems(), getStorageProjects()])
    if (dayResult.status === 'rejected') throw dayResult.reason
    const day = dayResult.value
    if (current === generation) entries.value = day.entries.filter((entry) => entry.source === 'storage' || entry.source === 'time_block')
  } catch {
    if (current === generation) loadError.value = t('planner.loadFailed')
  } finally {
    if (current === generation) loading.value = false
  }
}

async function capture(): Promise<void> {
  if (saving.value || !props.date) return
  fieldError.value = ''
  error.value = ''
  feedback.value = ''
  if (!title.value.trim()) {
    fieldError.value = t('daily.titleRequired')
    input.value?.focus()
    return
  }
  const capturedDate = props.date
  saving.value = true
  const payload = {
    title: title.value.trim(), type: type.value,
    ...(type.value === 'task' && destination.value === 'day' ? { due_on: capturedDate, status: 'active' as const } : {}),
  }
  const body = JSON.stringify(payload)
  if (attempt?.body !== body) attempt = { body, id: crypto.randomUUID() }
  try {
    const saved = await createStorageItem(payload, attempt.id)
    attempt = null
    title.value = ''
    feedback.value = t(saved.local_sync_status ? 'offline.savedPending' : payload.due_on ? 'daily.savedToDay' : 'daily.savedToInbox')
    await load()
  } catch (cause) {
    if (cause instanceof ApiError && cause.status === 202) {
      attempt = null
      title.value = ''
      feedback.value = t('offline.savedPending')
      return
    }
    const fields = validationErrors(cause)
    fieldError.value = fields.title?.[0] ?? ''
    if (!fieldError.value) error.value = cause instanceof Error ? cause.message : t('storage.captureFailed')
  } finally {
    saving.value = false
    await nextTick()
    if (props.date === capturedDate) input.value?.focus()
  }
}

async function complete(entry: PlannerEntry): Promise<void> {
  if (actionId.value !== null) return
  actionId.value = entry.source_id
  error.value = ''
  feedback.value = ''
  try {
    const saved = await updateStorageItem(entry.source_id, { status: 'done' })
    feedback.value = t(saved.local_sync_status ? 'offline.savedPending' : 'daily.completed', { name: entry.title })
    await load()
  } catch (cause) {
    error.value = validationErrors(cause).status?.[0] ?? (cause instanceof Error ? cause.message : t('today.updateFailed'))
  } finally {
    actionId.value = null
  }
}

watch(() => props.date, () => { entries.value = []; void load() }, { immediate: true })
window.addEventListener('workspace-storage-changed', load)
onBeforeUnmount(() => { generation++; window.removeEventListener('workspace-storage-changed', load) })
</script>

<template>
  <section class="panel daily-agenda" :aria-label="t('daily.agenda')">
    <div class="section-heading">
      <div><h2>{{ t('daily.agenda') }}</h2><p class="muted">{{ t('daily.hint') }}</p></div>
      <RouterLink :to="{ path: '/planner', query: { date } }">{{ t('daily.openPlan') }}</RouterLink>
    </div>
    <form class="daily-capture" :aria-label="t('daily.capture')" @submit.prevent="capture">
      <UiTextInput ref="input" v-model="title" :label="t('storage.prompt')" name="daily-title" :maxlength="200" :placeholder="t('storage.promptExample')" :disabled="saving" :error="fieldError" />
      <button type="submit" :disabled="saving || !date">{{ t(saving ? 'common.saving' : 'daily.add') }}</button>
      <details class="daily-capture__options">
        <summary>{{ t(type === 'task' && destination === 'day' ? 'daily.taskForDay' : 'daily.toInbox') }}</summary>
        <div class="form-grid">
          <UiSelect v-model="type" :label="t('storage.type')" name="daily-type" :options="typeOptions" :disabled="saving" required />
          <UiSelect v-if="type === 'task'" v-model="destination" :label="t('daily.destination')" name="daily-destination" :options="destinationOptions" :disabled="saving" required />
        </div>
      </details>
    </form>
    <p v-if="feedback" class="notice success" role="status">{{ feedback }}</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
    <div v-if="loadError" class="notice error" role="alert">{{ loadError }} <button type="button" class="secondary" @click="load">{{ t('common.retry') }}</button></div>
    <p v-else-if="loading && !entries.length" class="muted" role="status">{{ t('planner.loading') }}</p>
    <p v-else-if="!entries.length" class="muted">{{ t('daily.empty') }}</p>
    <ul v-else class="item-list">
      <li v-for="entry in entries" :key="`${entry.source}:${entry.source_id}`" class="daily-task" :aria-label="entry.title">
        <button v-if="entry.source === 'storage' && entry.meta.type === 'task'" type="button" class="secondary daily-task__check" :aria-label="t('storage.completeNamed', { name: entry.title })" :disabled="actionId !== null" @click="complete(entry)">✓</button>
        <RouterLink :to="entry.source === 'storage' ? { path: '/storage', query: { item: entry.source_id } } : { path: '/planner', query: { date } }">
          <span v-if="entry.time" class="mono">{{ entry.time }} · </span>{{ entry.title }}
          <small v-if="entry.meta.local_sync_status" class="muted">{{ t('offline.queued') }}</small>
        </RouterLink>
      </li>
    </ul>
    <RouterLink class="daily-all-tasks" to="/storage">{{ t('daily.allTasks') }}</RouterLink>
  </section>
</template>

<style scoped>
.daily-agenda .section-heading p { margin: .35rem 0 0; }
.daily-capture { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-items: end; gap: .65rem; margin-block: 1rem; }
.daily-capture__options { grid-column: 1 / -1; }
.daily-capture__options summary { color: var(--muted); cursor: pointer; padding: .5rem 0; }
.daily-task { display: flex; align-items: center; gap: .75rem; padding-block: .6rem; }
.daily-task > a { min-width: 0; overflow-wrap: anywhere; color: var(--ink); text-decoration: none; padding-block: .5rem; }
.daily-task small { display: block; }
.daily-task__check { flex: 0 0 44px; min-height: 44px; padding: 0; }
.daily-all-tasks { display: inline-flex; min-height: 44px; align-items: center; }
@media (max-width: 360px) { .daily-capture { grid-template-columns: 1fr; } }
</style>
