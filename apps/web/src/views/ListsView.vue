<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { createStorageProject, getStorageItems, getStorageProjects, validationErrors } from '../api/client'
import type { StorageItem, StorageProject } from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { useI18n } from '../i18n'
import { OPEN_STATUSES, inChecklist, type ChecklistId } from '../storage/checklist'

const i18n = useI18n()
const router = useRouter()
const items = ref<StorageItem[]>([])
const projects = ref<StorageProject[]>([])
const isLoading = ref(true)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)
const newName = ref('')
const isSaving = ref(false)

const lists = computed(() => [
  { id: 'general' as ChecklistId, name: i18n.t('lists.general') },
  ...projects.value.filter(project => !project.is_archived).map(project => ({ id: project.id as ChecklistId, name: project.name })),
].map(list => ({ ...list, open: items.value.filter(item => inChecklist(item, list.id) && OPEN_STATUSES.includes(item.status)).length })))

async function load(quiet = false): Promise<void> {
  if (!quiet) isLoading.value = true
  loadError.value = null
  try {
    const [itemList, projectList] = await Promise.all([getStorageItems(), getStorageProjects()])
    const rows = new Map<number, StorageItem>()
    for (const item of itemList.data) for (const child of item.children ?? []) rows.set(child.id, child)
    for (const item of itemList.data) rows.set(item.id, item)
    items.value = [...rows.values()]
    projects.value = projectList.data
  } catch {
    if (!quiet) loadError.value = i18n.t('lists.loadFailed')
  } finally {
    isLoading.value = false
  }
}

async function create(): Promise<void> {
  const name = newName.value.trim()
  if (!name || isSaving.value) return
  isSaving.value = true
  error.value = null
  try {
    const project = await createStorageProject({ name })
    newName.value = ''
    await router.push(`/lists/${project.id}`)
  } catch (caught) {
    error.value = Object.values(validationErrors(caught))[0]?.[0] ?? i18n.t('lists.saveFailed')
  } finally {
    isSaving.value = false
  }
}

function storageChanged(): void {
  void load(true)
}

onMounted(() => { void load(); window.addEventListener('workspace-storage-changed', storageChanged) })
onBeforeUnmount(() => window.removeEventListener('workspace-storage-changed', storageChanged))
</script>

<template>
  <section class="view-stack lists-page">
    <header class="view-header">
      <div>
        <h1>{{ i18n.t('lists.title') }}</h1>
        <p class="muted">{{ i18n.t('lists.subtitle') }}</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>

    <AsyncState :loading="isLoading" :error="loadError" panel @retry="load">
      <nav class="lists-grid" :aria-label="i18n.t('lists.title')">
        <RouterLink v-for="list in lists" :key="list.id" :to="`/lists/${list.id}`" class="panel lists-card">
          <strong>{{ list.name }}</strong>
          <span class="muted">{{ i18n.t('lists.openCount', { count: list.open }) }}</span>
        </RouterLink>
      </nav>

      <form class="panel lists-create" @submit.prevent="create">
        <input
          v-model="newName"
          type="text"
          maxlength="160"
          :placeholder="i18n.t('lists.newList')"
          :aria-label="i18n.t('lists.newList')"
        />
        <button type="submit" :disabled="isSaving || !newName.trim()">{{ i18n.t('lists.create') }}</button>
      </form>
    </AsyncState>
  </section>
</template>

<style scoped>
.lists-page { max-width: 760px; }
.lists-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.lists-card { display: flex; flex-direction: column; gap: 4px; color: var(--ink); text-decoration: none; min-height: 88px; }
.lists-card strong { font-size: 1.1rem; overflow-wrap: anywhere; }
.lists-create { display: flex; gap: 8px; flex-wrap: wrap; }
.lists-create input { flex: 1 1 200px; min-height: 44px; }
</style>
