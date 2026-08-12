<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import {
  createStorageItem,
  createStorageProject,
  deleteStorageItem,
  deleteStorageProject,
  getStorageItems,
  getStorageProjects,
  updateStorageItem,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import { UiSelect, UiTextInput } from '../components/ui'
import type { UiOption } from '../components/ui'
import type {
  ItemStatus,
  ItemType,
  StorageItem,
  StorageProject,
} from '../api/types'

const isLoading = ref(true)
const loadError = ref<string | null>(null)
const isSubmitting = ref(false)
const error = ref<string | null>(null)
const feedback = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})

const items = ref<StorageItem[]>([])
const projects = ref<StorageProject[]>([])
const inboxCount = ref(0)

const captureTitle = ref('')
const captureInput = ref<{ focus: () => void } | null>(null)

const newProjectName = ref('')
const showProjectForm = ref(false)

/** One in-progress child title per parent, so the drafts cannot collide. */
const childDrafts = reactive<Record<number, string>>({})

const typeOptions: UiOption<ItemType>[] = [
  { value: 'task', label: 'Task' },
  { value: 'idea', label: 'Idea' },
]

const projectOptions = computed<UiOption<number>[]>(() =>
  projects.value
    .filter((project) => !project.is_archived)
    .map((project) => ({ value: project.id, label: project.name })),
)

/** Top-level items only; children are shown under their parent. */
const roots = computed(() => items.value.filter((item) => item.parent_id === null))
const inbox = computed(() => roots.value.filter((item) => item.status === 'inbox'))
const active = computed(() => roots.value.filter((item) => item.status === 'active'))
const closed = computed(() => roots.value.filter((item) => item.status === 'done' || item.status === 'dropped'))

function childrenOf(item: StorageItem): StorageItem[] {
  return items.value.filter((candidate) => candidate.parent_id === item.id)
}

function projectName(item: StorageItem): string | null {
  return projects.value.find((project) => project.id === item.project_id)?.name ?? null
}

async function load(): Promise<void> {
  isLoading.value = true
  loadError.value = null

  try {
    const [itemList, projectList] = await Promise.all([getStorageItems(), getStorageProjects()])
    items.value = itemList.data
    inboxCount.value = itemList.inbox_count
    projects.value = projectList.data
  } catch {
    loadError.value = 'Could not load Storage. Check the service and try again.'
  } finally {
    isLoading.value = false
  }
}

/** Capture costs one field, so the form keeps focus and clears itself. */
async function capture(): Promise<void> {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  fieldErrors.value = {}
  error.value = null
  feedback.value = null

  try {
    await createStorageItem({ title: captureTitle.value })
    captureTitle.value = ''
    feedback.value = 'Captured.'
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)

    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = 'Could not capture that. Your text is still here; please try again.'
    }
  } finally {
    // The field is disabled while the request is in flight, and a disabled
    // input cannot take focus, so the flag has to clear first.
    isSubmitting.value = false
    await nextTick()
    captureInput.value?.focus()
  }
}

async function patch(item: StorageItem, changes: Parameters<typeof updateStorageItem>[1]): Promise<void> {
  error.value = null
  feedback.value = null

  try {
    await updateStorageItem(item.id, changes)
    await load()
  } catch (currentError) {
    const errors = validationErrors(currentError)
    // A refused completion explains what is blocking it.
    error.value = errors.status?.[0] ?? errors.parent_id?.[0] ?? 'Could not save that change.'
  }
}

async function remove(item: StorageItem): Promise<void> {
  error.value = null

  try {
    await deleteStorageItem(item.id)
    feedback.value = 'Deleted.'
    await load()
  } catch {
    error.value = 'Could not delete that item.'
  }
}

async function addChild(parent: StorageItem): Promise<void> {
  const title = (childDrafts[parent.id] ?? '').trim()

  if (title === '' || isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  error.value = null

  try {
    // A child starts in progress rather than in the inbox: it was created with
    // a decision already made about where it belongs.
    await createStorageItem({ title, parent_id: parent.id, status: 'active' })
    childDrafts[parent.id] = ''
    await load()
  } catch (currentError) {
    const errors = validationErrors(currentError)
    error.value = errors.parent_id?.[0] ?? errors.title?.[0] ?? 'Could not add that child item.'
  } finally {
    isSubmitting.value = false
  }
}

async function addProject(): Promise<void> {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  fieldErrors.value = {}

  try {
    await createStorageProject({ name: newProjectName.value })
    newProjectName.value = ''
    showProjectForm.value = false
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
  } finally {
    isSubmitting.value = false
  }
}

async function removeProject(project: StorageProject): Promise<void> {
  try {
    await deleteStorageProject(project.id)
    feedback.value = 'Project deleted. Its items are still here.'
    await load()
  } catch {
    error.value = 'Could not delete that project.'
  }
}

function statusLabel(status: ItemStatus): string {
  return status === 'done' ? 'done' : status === 'dropped' ? 'dropped' : status
}

onMounted(load)
</script>

<template>
  <section class="view-stack storage-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">Storage</p>
        <h1>Capture now, sort later</h1>
        <p class="muted">One field is enough. Everything else is triage.</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="feedback" class="notice success" role="status">{{ feedback }}</div>

    <section class="panel" aria-labelledby="capture-heading">
      <h2 id="capture-heading">Capture</h2>
      <form class="capture-form" aria-label="Capture an item" novalidate @submit.prevent="capture">
        <UiTextInput
          ref="captureInput"
          v-model="captureTitle"
          label="What is on your mind?"
          name="title"
          :maxlength="200"
          placeholder="Book the dentist"
          :disabled="isSubmitting"
          :error="fieldErrors.title?.[0]"
        />
        <div class="form-actions">
          <button type="submit" :disabled="isSubmitting">{{ isSubmitting ? 'Saving…' : 'Capture' }}</button>
        </div>
      </form>
    </section>

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      loading-title="Loading Storage…"
      panel
      @retry="load"
    >
      <section class="panel" aria-labelledby="inbox-heading">
        <div class="section-heading">
          <h2 id="inbox-heading">Inbox</h2>
          <span class="kind-chip">{{ inboxCount }} unsorted</span>
        </div>

        <p v-if="inbox.length === 0" class="muted">
          Nothing waiting. Anything you capture lands here until you sort it.
        </p>
        <ul v-else class="item-list">
          <li v-for="item in inbox" :key="item.id" class="management-row" :aria-label="item.title">
            <div class="management-copy">
              <strong>{{ item.title }}</strong>
              <p class="muted">{{ item.type }}</p>
            </div>
            <div class="button-row management-actions">
              <button type="button" class="secondary" :aria-label="`Triage ${item.title}`" @click="patch(item, { status: 'active' })">Triage</button>
              <button type="button" class="secondary" :aria-label="`Drop ${item.title}`" @click="patch(item, { status: 'dropped' })">Drop</button>
            </div>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="active-heading">
        <div class="section-heading">
          <h2 id="active-heading">In progress</h2>
        </div>

        <p v-if="active.length === 0" class="muted">Nothing in progress yet.</p>
        <ul v-else class="item-list">
          <li v-for="item in active" :key="item.id" class="storage-item" :aria-label="item.title">
            <div class="management-row">
              <div class="management-copy">
                <strong>{{ item.title }}</strong>
                <p class="routine-meta">
                  <span class="kind-chip">{{ item.type }}</span>
                  <span v-if="projectName(item)" class="kind-chip">{{ projectName(item) }}</span>
                  <span v-for="tag in item.tags" :key="tag.id" class="kind-chip">{{ tag.name }}</span>
                </p>
              </div>
              <div class="button-row management-actions">
                <UiSelect
                  :model-value="item.type"
                  :label="`Type of ${item.title}`"
                  :name="`type-${item.id}`"
                  :options="typeOptions"
                  @update:model-value="(value) => value && patch(item, { type: value })"
                />
                <UiSelect
                  :model-value="item.project_id"
                  :label="`Project of ${item.title}`"
                  :name="`project-${item.id}`"
                  :options="projectOptions"
                  nullable
                  nullable-label="No project"
                  placeholder="No project"
                  @update:model-value="(value) => patch(item, { project_id: value })"
                />
                <button type="button" class="secondary" :aria-label="`Complete ${item.title}`" @click="patch(item, { status: 'done' })">Complete</button>
                <button type="button" class="secondary" :aria-label="`Delete ${item.title}`" @click="remove(item)">Delete</button>
              </div>
            </div>

            <div class="storage-children">
              <p v-if="childrenOf(item).length === 0" class="muted">
                No child items. Attach one to break this down.
              </p>
              <ul v-else class="item-list">
                <li v-for="child in childrenOf(item)" :key="child.id" class="management-row" :aria-label="child.title">
                  <div class="management-copy">
                    <strong>{{ child.title }}</strong>
                    <p class="routine-meta">
                      <span class="kind-chip">{{ statusLabel(child.status) }}</span>
                      <span v-if="child.is_blocker" class="kind-chip is-blocker">blocker</span>
                    </p>
                  </div>
                  <div class="button-row management-actions">
                    <button
                      type="button"
                      class="secondary"
                      :aria-label="`${child.is_blocker ? 'Unmark' : 'Mark'} ${child.title} as a blocker`"
                      @click="patch(child, { is_blocker: !child.is_blocker })"
                    >{{ child.is_blocker ? 'Not a blocker' : 'Blocker' }}</button>
                    <button
                      v-if="child.status !== 'done'"
                      type="button"
                      class="secondary"
                      :aria-label="`Complete ${child.title}`"
                      @click="patch(child, { status: 'done' })"
                    >Complete</button>
                  </div>
                </li>
              </ul>

              <form
                class="capture-form"
                :aria-label="`Add a child to ${item.title}`"
                novalidate
                @submit.prevent="addChild(item)"
              >
                <UiTextInput
                  :model-value="childDrafts[item.id] ?? ''"
                  :label="`Add a child to ${item.title}`"
                  :name="`child-${item.id}`"
                  :maxlength="200"
                  placeholder="Something this depends on"
                  :disabled="isSubmitting"
                  @update:model-value="(value) => { childDrafts[item.id] = value }"
                />
                <div class="form-actions">
                  <button type="submit" class="secondary">Add child</button>
                </div>
              </form>
            </div>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="projects-heading">
        <div class="section-heading">
          <h2 id="projects-heading">Projects</h2>
          <button type="button" class="secondary" @click="showProjectForm = !showProjectForm">
            {{ showProjectForm ? 'Cancel' : 'New project' }}
          </button>
        </div>

        <form v-if="showProjectForm" class="capture-form" aria-label="Create project" novalidate @submit.prevent="addProject">
          <UiTextInput
            v-model="newProjectName"
            label="Project name"
            name="name"
            :maxlength="160"
            :error="fieldErrors.name?.[0]"
          />
          <div class="form-actions">
            <button type="submit" :disabled="isSubmitting">Create project</button>
          </div>
        </form>

        <p v-if="projects.length === 0" class="muted">No projects yet.</p>
        <ul v-else class="item-list">
          <li v-for="project in projects" :key="project.id" class="management-row" :aria-label="project.name">
            <div class="management-copy">
              <strong>{{ project.name }}</strong>
              <p class="muted">{{ project.open_count }} open · {{ project.completed_count }} done</p>
            </div>
            <div class="button-row management-actions">
              <button type="button" class="secondary" :aria-label="`Delete ${project.name}`" @click="removeProject(project)">Delete</button>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="closed.length > 0" class="panel" aria-labelledby="closed-heading">
        <h2 id="closed-heading">Closed</h2>
        <ul class="item-list">
          <li v-for="item in closed" :key="item.id" class="management-row" :aria-label="item.title">
            <div class="management-copy">
              <strong>{{ item.title }}</strong>
              <p class="muted">{{ statusLabel(item.status) }}</p>
            </div>
            <div class="button-row management-actions">
              <button type="button" class="secondary" :aria-label="`Reopen ${item.title}`" @click="patch(item, { status: 'active' })">Reopen</button>
            </div>
          </li>
        </ul>
      </section>
    </AsyncState>
  </section>
</template>
