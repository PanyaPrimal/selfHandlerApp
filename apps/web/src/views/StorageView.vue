<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  confirmInboxTriageDraft,
  createInboxTriageDraft,
  createStorageItem,
  createFinanceSourceExpense,
  createStorageProject,
  deleteStorageItem,
  deleteStorageProject,
  getStorageItems,
  getStorageProjects,
  getFinanceAccounts,
  getFinanceCategories,
  getFinanceCurrencies,
  getAiSettings,
  updateStorageItem,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import { UiDatePicker, UiSelect, UiTextInput } from '../components/ui'
import type { UiOption } from '../components/ui'
import type {
  AiSettings,
  InboxTriageDraft,
  ItemStatus,
  ItemType,
  FinanceAccount,
  FinanceCategory,
  FinanceCurrency,
  FinanceCurrencyCode,
  StorageItem,
  StorageProject,
} from '../api/types'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
import { financeAmount } from '../finance/money'

const isLoading = ref(true)
const loadError = ref<string | null>(null)
const isSubmitting = ref(false)
const error = ref<string | null>(null)
const feedback = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const i18n = useI18n()
const route = useRoute()

const items = ref<StorageItem[]>([])
const projects = ref<StorageProject[]>([])
const inboxCount = ref(0)

const captureTitle = ref('')
const captureType = ref<ItemType>('task')
const captureEstimate = ref('')
const captureCurrency = ref<FinanceCurrencyCode>('UAH')
const captureInput = ref<{ focus: () => void } | null>(null)

const newProjectName = ref('')
const showProjectForm = ref(false)
const financeAccounts = ref<FinanceAccount[]>([])
const financeCategories = ref<FinanceCategory[]>([])
const financeCurrencies = ref<FinanceCurrency[]>([])
const financing = ref<number | null>(null)
const purchaseDrafts = reactive<Record<number, { amount: string, currency: FinanceCurrencyCode }>>({})
const sourceDraft = reactive({ account_id: 0, category_id: 0, amount: '', occurred_on: '' })
const aiSettings = ref<AiSettings | null>(null)
const aiSettingsLoadError = ref(false)
const aiBusyItem = ref<number | null>(null)
const aiDraft = ref<InboxTriageDraft | null>(null)
const aiError = ref<string | null>(null)
const aiFeedback = ref<string | null>(null)
const aiDraftExpired = ref(false)
let aiExpiryTimer: ReturnType<typeof setTimeout> | undefined
const highlightedItem = computed(() => typeof route.query.item === 'string' && /^\d+$/.test(route.query.item)
  ? Number(route.query.item) : null)

/** One in-progress child title per parent, so the drafts cannot collide. */
const childDrafts = reactive<Record<number, string>>({})

const typeOptions = computed<UiOption<ItemType>[]>(() => [
  { value: 'task', label: i18n.t('storage.task') },
  { value: 'idea', label: i18n.t('storage.idea') },
  { value: 'purchase', label: i18n.t('storage.purchase') },
])
const typeLabel = (type: ItemType): string => typeOptions.value.find((option) => option.value === type)?.label ?? type

const currencyOptions = computed<UiOption<FinanceCurrencyCode>[]>(() =>
  financeCurrencies.value.map((currency) => ({ value: currency.code, label: currency.code })),
)
const expenseCategoryOptions = computed<UiOption<number>[]>(() =>
  financeCategories.value.map((category) => ({ value: category.id, label: category.label })),
)

function expenseAccountOptions(item: StorageItem): UiOption<number>[] {
  return financeAccounts.value
    .filter((account) => account.currency === purchaseDrafts[item.id]?.currency)
    .map((account) => ({ value: account.id, label: account.name }))
}

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
const aiActiveConnection = computed(() => aiSettings.value?.data.find(
  (connection) => connection.id === aiSettings.value?.active_connection_id,
) ?? null)
const aiReady = computed(() => aiActiveConnection.value?.status === 'ready'
  && aiSettings.value?.consents.storage_inbox.granted === true)
const aiGuidanceKey = computed<MessageKey>(() => {
  if (aiSettingsLoadError.value) return 'storage.aiUnavailable'
  if (!aiActiveConnection.value) return 'storage.aiNeedsConnection'
  if (aiActiveConnection.value.status !== 'ready') return 'storage.aiNeedsTest'
  if (!aiSettings.value?.consents.storage_inbox.granted) return 'storage.aiNeedsConsent'
  return 'storage.aiReady'
})

function childrenOf(item: StorageItem): StorageItem[] {
  return items.value.filter((candidate) => candidate.parent_id === item.id)
}

function projectName(item: StorageItem): string | null {
  return projects.value.find((project) => project.id === item.project_id)?.name ?? null
}

function aiProposalProject(draft: InboxTriageDraft): string {
  if (draft.proposal.project_id === null) return i18n.t('storage.noProject')
  return projects.value.find((project) => project.id === draft.proposal.project_id)?.name
    ?? i18n.t('storage.aiUnknownProject')
}

function formatAiDate(value: string | null): string {
  if (!value) return i18n.t('common.notSet')
  const date = new Date(`${value}T00:00:00`)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(i18n.locale.value, {
    dateStyle: 'medium',
  }).format(date)
}

function formatAiTimestamp(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(i18n.locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

function aiPriorityLabel(priority: NonNullable<InboxTriageDraft['proposal']['priority']>): string {
  const keys: Record<typeof priority, MessageKey> = {
    low: 'storage.aiPriority.low',
    normal: 'storage.aiPriority.normal',
    high: 'storage.aiPriority.high',
  }
  return i18n.t(keys[priority])
}

async function loadAiSettings(): Promise<void> {
  aiSettingsLoadError.value = false
  try {
    aiSettings.value = await getAiSettings()
  } catch {
    aiSettings.value = null
    aiSettingsLoadError.value = true
  }
}

function clearAiExpiryTimer(): void {
  if (aiExpiryTimer !== undefined) clearTimeout(aiExpiryTimer)
  aiExpiryTimer = undefined
}

function scheduleAiExpiry(draft: InboxTriageDraft): void {
  clearAiExpiryTimer()
  const delay = Math.max(0, new Date(draft.expires_at).getTime() - Date.now())
  aiDraftExpired.value = delay === 0
  if (delay > 0) {
    aiExpiryTimer = setTimeout(() => {
      aiDraftExpired.value = true
    }, Math.min(delay, 2_147_483_647))
  }
}

async function requestAiDraft(item: StorageItem): Promise<void> {
  if (!aiReady.value || aiBusyItem.value !== null) return
  aiBusyItem.value = item.id
  aiError.value = null
  aiFeedback.value = null
  try {
    const draft = await createInboxTriageDraft(item.id)
    aiDraft.value = draft
    scheduleAiExpiry(draft)
    await nextTick()
    document.querySelector<HTMLElement>('.storage-ai-proposal')?.focus()
  } catch (current) {
    aiError.value = current instanceof Error ? current.message : i18n.t('storage.aiDraftFailed')
    await loadAiSettings()
  } finally {
    aiBusyItem.value = null
  }
}

function dismissAiDraft(): void {
  clearAiExpiryTimer()
  aiDraft.value = null
  aiDraftExpired.value = false
  aiError.value = null
  aiFeedback.value = i18n.t('storage.aiDismissed')
}

async function confirmAiDraft(): Promise<void> {
  const draft = aiDraft.value
  if (!draft || aiDraftExpired.value || aiBusyItem.value !== null) return
  aiBusyItem.value = draft.item_id
  aiError.value = null
  aiFeedback.value = null
  try {
    const updated = await confirmInboxTriageDraft(draft.confirmation_token)
    items.value = items.value.map((item) => item.id === updated.id ? updated : item)
    inboxCount.value = Math.max(0, inboxCount.value - 1)
    clearAiExpiryTimer()
    aiDraft.value = null
    aiFeedback.value = i18n.t('storage.aiApplied')
  } catch (current) {
    aiError.value = current instanceof Error ? current.message : i18n.t('storage.aiConfirmFailed')
    await loadAiSettings()
  } finally {
    aiBusyItem.value = null
  }
}

async function load(): Promise<void> {
  isLoading.value = true
  loadError.value = null

  try {
    const [itemList, projectList, accountList, categoryList, currencyList] = await Promise.all([
      getStorageItems(), getStorageProjects(), getFinanceAccounts(), getFinanceCategories(), getFinanceCurrencies(),
    ])
    items.value = itemList.data
    inboxCount.value = itemList.inbox_count
    projects.value = projectList.data
    financeAccounts.value = accountList.filter((item) => !item.archived)
    financeCategories.value = categoryList.filter((item) => !item.archived && item.direction === 'expense')
    financeCurrencies.value = currencyList.filter((item) => item.active)
    for (const item of items.value.filter((row) => row.type === 'purchase')) {
      purchaseDrafts[item.id] ??= { amount: item.estimated_amount ?? '', currency: item.estimated_currency_code ?? 'UAH' }
    }
  } catch {
    loadError.value = i18n.t('storage.loadFailed')
  } finally {
    isLoading.value = false
    await nextTick()
    if (highlightedItem.value !== null) {
      document.querySelector<HTMLElement>(`[data-storage-item="${highlightedItem.value}"]`)
        ?.scrollIntoView({ block: 'center' })
    }
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
    await createStorageItem({ title: captureTitle.value, type: captureType.value,
      ...(captureType.value === 'purchase' && captureEstimate.value ? {
        estimated_amount: captureEstimate.value, estimated_currency_code: captureCurrency.value,
      } : {}) })
    captureTitle.value = ''
    captureEstimate.value = ''
    feedback.value = i18n.t('storage.captured')
    await load()
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)

    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = i18n.t('storage.captureFailed')
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
    error.value = errors.status?.[0] ?? errors.parent_id?.[0] ?? i18n.t('storage.changeFailed')
  }
}

async function remove(item: StorageItem): Promise<void> {
  error.value = null

  try {
    await deleteStorageItem(item.id)
    feedback.value = i18n.t('storage.deleted')
    await load()
  } catch {
    error.value = i18n.t('storage.deleteFailed')
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
    error.value = errors.parent_id?.[0] ?? errors.title?.[0] ?? i18n.t('storage.childFailed')
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
    feedback.value = i18n.t('storage.projectDeleted')
    await load()
  } catch {
    error.value = i18n.t('storage.projectDeleteFailed')
  }
}

function statusLabel(status: ItemStatus): string {
  return i18n.t(status === 'done' ? 'storage.done' : status === 'dropped' ? 'storage.dropped' : status === 'inbox' ? 'storage.inboxStatus' : 'storage.activeStatus')
}

function purchaseStatus(item: StorageItem): string {
  return i18n.t(item.status === 'done' ? 'storage.bought' : item.status === 'dropped' ? 'storage.canceled' : 'storage.wanted')
}

async function saveEstimate(item: StorageItem): Promise<void> {
  const value = purchaseDrafts[item.id]
  if (!value) return
  await patch(item, { estimated_amount: value.amount || null,
    estimated_currency_code: value.amount ? value.currency : null })
}

function startExpense(item: StorageItem): void {
  financing.value = item.id
  const estimate = purchaseDrafts[item.id]
  sourceDraft.account_id = financeAccounts.value.find((row) => row.currency === estimate?.currency)?.id ?? 0
  sourceDraft.category_id = financeCategories.value[0]?.id ?? 0
  sourceDraft.amount = estimate?.amount ?? ''
  sourceDraft.occurred_on = new Date().toISOString().slice(0, 10)
}

async function postExpense(item: StorageItem): Promise<void> {
  error.value = null
  try {
    await createFinanceSourceExpense({ source_type: 'purchase_item', source_id: item.id,
      account_id: sourceDraft.account_id, category_id: sourceDraft.category_id, amount: sourceDraft.amount,
      occurred_on: sourceDraft.occurred_on, idempotency_key: `purchase-${item.id}-${Date.now()}`, note: null })
    financing.value = null
    feedback.value = i18n.t('storage.expenseCreated')
    await load()
  } catch (currentError) {
    error.value = validationErrors(currentError).source_id?.[0] ?? i18n.t('storage.expenseFailed')
  }
}

onMounted(load)
onMounted(loadAiSettings)
onBeforeUnmount(clearAiExpiryTimer)
</script>

<template>
  <section class="view-stack storage-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('storage.eyebrow') }}</p>
        <h1>{{ i18n.t('storage.title') }}</h1>
        <p class="muted">{{ i18n.t('storage.subtitle') }}</p>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="feedback" class="notice success" role="status">{{ feedback }}</div>
    <div v-if="aiError" class="notice error" role="alert" aria-live="assertive">{{ aiError }}</div>
    <div v-else-if="aiFeedback" class="notice success" role="status" aria-live="polite">{{ aiFeedback }}</div>

    <section class="panel" aria-labelledby="capture-heading">
      <h2 id="capture-heading">{{ i18n.t('storage.capture') }}</h2>
      <form class="capture-form" :aria-label="i18n.t('storage.captureItem')" novalidate @submit.prevent="capture">
        <UiTextInput
          ref="captureInput"
          v-model="captureTitle"
          :label="i18n.t('storage.prompt')"
          name="title"
          :maxlength="200"
          :placeholder="i18n.t('storage.promptExample')"
          :disabled="isSubmitting"
          :error="fieldErrors.title?.[0]"
        />
        <UiSelect v-model="captureType" name="capture-type" :label="i18n.t('storage.type')" :options="typeOptions" required />
        <template v-if="captureType === 'purchase'"><label class="field"><span>{{ i18n.t('storage.estimate') }}</span><input v-model="captureEstimate" inputmode="decimal" placeholder="0.0000"></label><UiSelect v-model="captureCurrency" name="capture-currency" :label="i18n.t('finance.currency')" :options="currencyOptions" required /></template>
        <div class="form-actions">
          <button type="submit" :disabled="isSubmitting">{{ i18n.t(isSubmitting ? 'common.saving' : 'storage.captureAction') }}</button>
        </div>
      </form>
    </section>

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      :loading-title="i18n.t('storage.loading')"
      panel
      @retry="load"
    >
      <section class="panel" aria-labelledby="inbox-heading">
        <div class="section-heading">
          <h2 id="inbox-heading">{{ i18n.t('storage.inbox') }}</h2>
          <span class="kind-chip">{{ i18n.plural(inboxCount, { one: 'storage.unsorted.one', few: 'storage.unsorted.few', many: 'storage.unsorted.many', other: 'storage.unsorted.other' }) }}</span>
        </div>

        <aside class="storage-ai-guidance" :class="{ 'is-ready': aiReady }" aria-labelledby="storage-ai-heading">
          <div>
            <h3 id="storage-ai-heading">{{ i18n.t('storage.aiTitle') }}</h3>
            <p>{{ i18n.t(aiGuidanceKey) }}</p>
            <p class="muted">{{ i18n.t('storage.aiDisclosure') }}</p>
          </div>
          <RouterLink class="button secondary" to="/settings/ai">{{ i18n.t(aiReady ? 'storage.aiManage' : 'storage.aiSetUp') }}</RouterLink>
        </aside>

        <p v-if="inbox.length === 0" class="muted">
          {{ i18n.t('storage.inboxEmpty') }}
        </p>
        <ul v-else class="item-list">
          <li v-for="item in inbox" :key="item.id" class="storage-inbox-item" :class="{ 'is-deep-linked': item.id === highlightedItem }" :data-storage-item="item.id" :aria-label="item.title">
            <div class="management-row">
              <div class="management-copy">
                <strong>{{ item.title }}</strong>
                <p class="muted">{{ typeLabel(item.type) }}</p>
              </div>
              <div class="button-row management-actions">
                <button type="button" class="secondary" :disabled="!aiReady || aiBusyItem !== null" :aria-label="i18n.t('storage.aiDraftNamed', { name: item.title })" @click="requestAiDraft(item)">{{ i18n.t(aiBusyItem === item.id ? 'storage.aiDrafting' : 'storage.aiDraft') }}</button>
                <button type="button" class="secondary" :aria-label="i18n.t('storage.triageNamed', { name: item.title })" @click="patch(item, { status: 'active' })">{{ i18n.t('storage.triage') }}</button>
                <button type="button" class="secondary" :aria-label="i18n.t('storage.dropNamed', { name: item.title })" @click="patch(item, { status: 'dropped' })">{{ i18n.t('storage.drop') }}</button>
              </div>
            </div>

            <section
              v-if="aiDraft?.item_id === item.id"
              class="storage-ai-proposal"
              :aria-labelledby="`ai-proposal-${item.id}`"
              tabindex="-1"
            >
              <div class="section-heading">
                <div>
                  <p class="eyebrow">{{ i18n.t('storage.aiProposalEyebrow') }}</p>
                  <h3 :id="`ai-proposal-${item.id}`">{{ i18n.t('storage.aiProposalTitle') }}</h3>
                </div>
                <span class="kind-chip">{{ aiDraft.provider === 'anthropic' ? 'Anthropic' : 'OpenAI' }} · {{ aiDraft.model }}</span>
              </div>
              <p class="notice">{{ i18n.t('storage.aiNoWriteNotice') }}</p>
              <p>{{ aiDraft.proposal.rationale }}</p>
              <dl class="storage-ai-facts">
                <div><dt>{{ i18n.t('storage.type') }}</dt><dd>{{ typeLabel(aiDraft.proposal.type) }}</dd></div>
                <div><dt>{{ i18n.t('storage.aiProject') }}</dt><dd>{{ aiProposalProject(aiDraft) }}</dd></div>
                <div><dt>{{ i18n.t('storage.aiPriority') }}</dt><dd>{{ aiDraft.proposal.priority ? aiPriorityLabel(aiDraft.proposal.priority) : i18n.t('common.notSet') }}</dd></div>
                <div><dt>{{ i18n.t('storage.aiDueDate') }}</dt><dd>{{ formatAiDate(aiDraft.proposal.due_on) }}</dd></div>
                <div class="storage-ai-facts__wide"><dt>{{ i18n.t('storage.aiTags') }}</dt><dd>{{ aiDraft.proposal.tags.length ? aiDraft.proposal.tags.join(', ') : i18n.t('common.notSet') }}</dd></div>
              </dl>
              <p v-if="aiDraftExpired" class="notice error" role="status">{{ i18n.t('storage.aiExpired') }}</p>
              <p v-else class="muted">{{ i18n.t('storage.aiExpires', { date: formatAiTimestamp(aiDraft.expires_at) }) }}</p>
              <div class="button-row storage-ai-actions">
                <button type="button" :disabled="aiDraftExpired || aiBusyItem !== null" @click="confirmAiDraft">{{ i18n.t(aiBusyItem === item.id ? 'storage.aiApplying' : 'storage.aiConfirm') }}</button>
                <button type="button" class="secondary" :disabled="aiBusyItem !== null" @click="requestAiDraft(item)">{{ i18n.t('storage.aiRegenerate') }}</button>
                <button type="button" class="ghost" :disabled="aiBusyItem !== null" @click="dismissAiDraft">{{ i18n.t('storage.aiDismiss') }}</button>
              </div>
            </section>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="active-heading">
        <div class="section-heading">
          <h2 id="active-heading">{{ i18n.t('storage.inProgress') }}</h2>
        </div>

        <p v-if="active.length === 0" class="muted">{{ i18n.t('storage.activeEmpty') }}</p>
        <ul v-else class="item-list">
          <li v-for="item in active" :key="item.id" class="storage-item" :class="{ 'is-deep-linked': item.id === highlightedItem }" :data-storage-item="item.id" :aria-label="item.title">
            <div class="management-row">
              <div class="management-copy">
                <strong>{{ item.title }}</strong>
                <p class="routine-meta">
                  <span class="kind-chip">{{ typeLabel(item.type) }}</span>
                  <span v-if="item.type === 'purchase'" class="kind-chip">{{ purchaseStatus(item) }}</span>
                  <span v-if="projectName(item)" class="kind-chip">{{ projectName(item) }}</span>
                  <span v-for="tag in item.tags" :key="tag.id" class="kind-chip">{{ tag.name }}</span>
                </p>
              </div>
              <div class="button-row management-actions">
                <UiSelect
                  :model-value="item.type"
                  :label="i18n.t('storage.typeNamed', { name: item.title })"
                  :name="`type-${item.id}`"
                  :options="typeOptions"
                  @update:model-value="(value) => value && patch(item, { type: value })"
                />
                <UiSelect
                  :model-value="item.project_id"
                  :label="i18n.t('storage.projectNamed', { name: item.title })"
                  :name="`project-${item.id}`"
                  :options="projectOptions"
                  nullable
                  :nullable-label="i18n.t('storage.noProject')"
                  :placeholder="i18n.t('storage.noProject')"
                  @update:model-value="(value) => patch(item, { project_id: value })"
                />
                <button v-if="item.type !== 'purchase'" type="button" class="secondary" :aria-label="i18n.t('storage.completeNamed', { name: item.title })" @click="patch(item, { status: 'done' })">{{ i18n.t('storage.complete') }}</button>
                <button type="button" class="secondary" :aria-label="i18n.t('storage.deleteNamed', { name: item.title })" @click="remove(item)">{{ i18n.t('common.delete') }}</button>
              </div>
            </div>

            <section v-if="item.type === 'purchase'" class="purchase-finance" :aria-label="i18n.t('storage.purchaseFinanceNamed', { name: item.title })">
              <form class="capture-form" @submit.prevent="saveEstimate(item)"><label class="field"><span>{{ i18n.t('storage.estimate') }}</span><input v-model="purchaseDrafts[item.id]!.amount" inputmode="decimal" placeholder="0.0000"></label><UiSelect v-model="purchaseDrafts[item.id]!.currency" :name="`purchase-currency-${item.id}`" :label="i18n.t('finance.currency')" :options="currencyOptions" required /><div class="form-actions"><button type="submit" class="secondary">{{ i18n.t('storage.saveEstimate') }}</button><button type="button" :disabled="!financeAccounts.length || !financeCategories.length" @click="startExpense(item)">{{ i18n.t('storage.buyDirect') }}</button><a class="button secondary" :href="`/finance?tab=debts&purchase=${item.id}`">{{ i18n.t('storage.buyInstallments') }}</a></div></form>
              <form v-if="financing === item.id" class="capture-form" :aria-label="i18n.t('storage.expenseEditor')" @submit.prevent="postExpense(item)"><UiSelect v-model="sourceDraft.account_id" :name="`purchase-account-${item.id}`" :label="i18n.t('finance.account')" :options="expenseAccountOptions(item)" required /><UiSelect v-model="sourceDraft.category_id" :name="`purchase-category-${item.id}`" :label="i18n.t('finance.expenseCategory')" :options="expenseCategoryOptions" required /><label class="field"><span>{{ i18n.t('finance.amount') }}</span><input v-model="sourceDraft.amount" inputmode="decimal" required></label><UiDatePicker :model-value="sourceDraft.occurred_on" :name="`purchase-date-${item.id}`" :label="i18n.t('finance.date')" :locale="i18n.locale.value" :today="sourceDraft.occurred_on" :max="sourceDraft.occurred_on" :clearable="false" required @update:model-value="(value) => { if (value) sourceDraft.occurred_on = value }" /><div class="form-actions"><button type="submit" :disabled="!sourceDraft.account_id || !sourceDraft.category_id">{{ i18n.t('storage.postExpense') }}</button><button type="button" class="ghost" @click="financing = null">{{ i18n.t('common.cancel') }}</button></div></form>
            </section>

            <div class="storage-children">
              <p v-if="childrenOf(item).length === 0" class="muted">
                {{ i18n.t('storage.noChildren') }}
              </p>
              <ul v-else class="item-list">
                <li v-for="child in childrenOf(item)" :key="child.id" class="management-row" :aria-label="child.title">
                  <div class="management-copy">
                    <strong>{{ child.title }}</strong>
                    <p class="routine-meta">
                      <span class="kind-chip">{{ statusLabel(child.status) }}</span>
                      <span v-if="child.is_blocker" class="kind-chip is-blocker">{{ i18n.t('storage.blocker') }}</span>
                    </p>
                  </div>
                  <div class="button-row management-actions">
                    <button
                      type="button"
                      class="secondary"
                      :aria-label="i18n.t(child.is_blocker ? 'storage.unmarkBlockerNamed' : 'storage.markBlockerNamed', { name: child.title })"
                      @click="patch(child, { is_blocker: !child.is_blocker })"
                    >{{ i18n.t(child.is_blocker ? 'storage.notBlocker' : 'storage.blocker') }}</button>
                    <button
                      v-if="child.status !== 'done'"
                      type="button"
                      class="secondary"
                      :aria-label="i18n.t('storage.completeNamed', { name: child.title })"
                      @click="patch(child, { status: 'done' })"
                    >{{ i18n.t('storage.complete') }}</button>
                  </div>
                </li>
              </ul>

              <form
                class="capture-form"
                :aria-label="i18n.t('storage.addChildNamed', { name: item.title })"
                novalidate
                @submit.prevent="addChild(item)"
              >
                <UiTextInput
                  :model-value="childDrafts[item.id] ?? ''"
                  :label="i18n.t('storage.addChildNamed', { name: item.title })"
                  :name="`child-${item.id}`"
                  :maxlength="200"
                  :placeholder="i18n.t('storage.childExample')"
                  :disabled="isSubmitting"
                  @update:model-value="(value) => { childDrafts[item.id] = value }"
                />
                <div class="form-actions">
                  <button type="submit" class="secondary">{{ i18n.t('storage.addChild') }}</button>
                </div>
              </form>
            </div>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="projects-heading">
        <div class="section-heading">
          <h2 id="projects-heading">{{ i18n.t('storage.projects') }}</h2>
          <button type="button" class="secondary" @click="showProjectForm = !showProjectForm">
            {{ i18n.t(showProjectForm ? 'common.cancel' : 'storage.newProject') }}
          </button>
        </div>

        <form v-if="showProjectForm" class="capture-form" :aria-label="i18n.t('storage.createProject')" novalidate @submit.prevent="addProject">
          <UiTextInput
            v-model="newProjectName"
            :label="i18n.t('storage.projectName')"
            name="name"
            :maxlength="160"
            :error="fieldErrors.name?.[0]"
          />
          <div class="form-actions">
            <button type="submit" :disabled="isSubmitting">{{ i18n.t('storage.createProject') }}</button>
          </div>
        </form>

        <p v-if="projects.length === 0" class="muted">{{ i18n.t('storage.noProjects') }}</p>
        <ul v-else class="item-list">
          <li v-for="project in projects" :key="project.id" class="management-row" :aria-label="project.name">
            <div class="management-copy">
              <strong>{{ project.name }}</strong>
              <p class="muted">{{ i18n.t('storage.projectCounts', { open: project.open_count, done: project.completed_count }) }}</p>
            </div>
            <div class="button-row management-actions">
              <button type="button" class="secondary" :aria-label="i18n.t('storage.deleteNamed', { name: project.name })" @click="removeProject(project)">{{ i18n.t('common.delete') }}</button>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="closed.length > 0" class="panel" aria-labelledby="closed-heading">
        <h2 id="closed-heading">{{ i18n.t('storage.closed') }}</h2>
        <ul class="item-list">
          <li v-for="item in closed" :key="item.id" class="management-row" :class="{ 'is-deep-linked': item.id === highlightedItem }" :data-storage-item="item.id" :aria-label="item.title">
            <div class="management-copy">
              <strong>{{ item.title }}</strong>
              <p class="muted">{{ item.type === 'purchase' ? purchaseStatus(item) : statusLabel(item.status) }}<template v-if="item.estimated_amount"> · {{ financeAmount(item.estimated_amount, item.estimated_currency_code!, i18n.locale.value) }}</template></p>
            </div>
            <div class="button-row management-actions">
              <button v-if="item.type !== 'purchase' || item.status === 'dropped'" type="button" class="secondary" :aria-label="i18n.t('storage.reopenNamed', { name: item.title })" @click="patch(item, { status: 'active' })">{{ i18n.t('storage.reopen') }}</button>
            </div>
          </li>
        </ul>
      </section>
    </AsyncState>
  </section>
</template>
