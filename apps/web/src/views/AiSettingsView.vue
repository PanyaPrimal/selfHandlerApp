<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  activateLlmConnection,
  createLlmConnection,
  deleteLlmConnection,
  getAiSettings,
  replaceStorageInboxConsent,
  testLlmConnection,
  updateLlmConnection,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import type { AiConnection, AiConnectionInput, AiProvider, AiSettings } from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { UiCheckbox, UiSelect, UiTextInput } from '../components/ui'
import type { UiOption } from '../components/ui'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'

const i18n = useI18n()
const loading = ref(true)
const loadError = ref<string | null>(null)
const busy = ref<string | null>(null)
const error = ref<string | null>(null)
const feedback = ref<string | null>(null)
const fieldErrors = ref<ValidationErrors>({})
const settings = ref<AiSettings | null>(null)
const editingId = ref<number | null>(null)
const deletingId = ref<number | null>(null)
const consentGranted = ref(false)
const form = reactive({
  name: '',
  provider: 'anthropic' as AiProvider,
  model: '',
  api_key: '',
  max_output_tokens: '512',
})

const providerOptions = computed<UiOption<AiProvider>[]>(() =>
  (settings.value?.providers ?? ['anthropic', 'openai']).map((provider) => ({
    value: provider,
    label: provider === 'anthropic' ? 'Anthropic' : 'OpenAI',
  })),
)

function resetForm(): void {
  editingId.value = null
  form.name = ''
  form.provider = 'anthropic'
  form.model = ''
  form.api_key = ''
  form.max_output_tokens = '512'
  fieldErrors.value = {}
}

function startEdit(connection: AiConnection): void {
  editingId.value = connection.id
  form.name = connection.name
  form.provider = connection.provider
  form.model = connection.model
  form.api_key = ''
  form.max_output_tokens = String(connection.parameters.max_output_tokens)
  fieldErrors.value = {}
  error.value = null
}

function safeError(current: unknown, fallback: MessageKey): string {
  return current instanceof Error && current.message.trim() ? current.message : i18n.t(fallback)
}

function statusLabel(connection: AiConnection): string {
  const keys: Record<AiConnection['status'], MessageKey> = {
    untested: 'ai.status.untested',
    ready: 'ai.status.ready',
    invalid: 'ai.status.invalid',
  }
  return i18n.t(keys[connection.status])
}

function formatDate(value: string | null): string {
  if (!value) return i18n.t('ai.never')
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(i18n.locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    settings.value = await getAiSettings()
    consentGranted.value = settings.value.consents.storage_inbox.granted
  } catch {
    loadError.value = i18n.t('ai.loadFailed')
  } finally {
    loading.value = false
  }
}

async function saveConnection(): Promise<void> {
  if (busy.value) return
  busy.value = 'save'
  error.value = null
  feedback.value = null
  fieldErrors.value = {}
  const payload: AiConnectionInput = {
    name: form.name,
    provider: form.provider,
    model: form.model,
    api_key: form.api_key,
    parameters: { max_output_tokens: Number(form.max_output_tokens) },
  }

  try {
    if (editingId.value === null) {
      await createLlmConnection(payload)
      feedback.value = i18n.t('ai.connectionCreated')
    } else {
      await updateLlmConnection(editingId.value, {
        name: payload.name,
        provider: payload.provider,
        model: payload.model,
        parameters: payload.parameters,
        ...(payload.api_key ? { api_key: payload.api_key } : {}),
      })
      feedback.value = i18n.t(payload.api_key ? 'ai.keyRotated' : 'ai.connectionUpdated')
    }
    resetForm()
    await load()
  } catch (current) {
    fieldErrors.value = validationErrors(current)
    if (Object.keys(fieldErrors.value).length === 0) error.value = safeError(current, 'ai.saveFailed')
  } finally {
    form.api_key = ''
    busy.value = null
  }
}

async function testConnection(connection: AiConnection): Promise<void> {
  if (busy.value) return
  busy.value = `test:${connection.id}`
  error.value = null
  feedback.value = null
  try {
    await testLlmConnection(connection.id)
    feedback.value = i18n.t('ai.testPassed', { name: connection.name })
    await load()
  } catch (current) {
    error.value = safeError(current, 'ai.testFailed')
    await load()
  } finally {
    busy.value = null
  }
}

async function activate(connection: AiConnection): Promise<void> {
  if (busy.value || connection.status !== 'ready') return
  busy.value = `activate:${connection.id}`
  error.value = null
  try {
    settings.value = await activateLlmConnection(connection.id)
    consentGranted.value = settings.value.consents.storage_inbox.granted
    feedback.value = i18n.t('ai.activated', { name: connection.name })
  } catch (current) {
    error.value = safeError(current, 'ai.activateFailed')
  } finally {
    busy.value = null
  }
}

async function remove(connection: AiConnection): Promise<void> {
  if (busy.value || deletingId.value !== connection.id) {
    deletingId.value = connection.id
    return
  }
  busy.value = `delete:${connection.id}`
  error.value = null
  try {
    await deleteLlmConnection(connection.id)
    deletingId.value = null
    if (editingId.value === connection.id) resetForm()
    feedback.value = i18n.t('ai.connectionDeleted')
    await load()
  } catch (current) {
    error.value = safeError(current, 'ai.deleteFailed')
  } finally {
    busy.value = null
  }
}

async function saveConsent(): Promise<void> {
  if (busy.value) return
  busy.value = 'consent'
  error.value = null
  try {
    const consent = await replaceStorageInboxConsent(consentGranted.value)
    consentGranted.value = consent.granted
    if (settings.value) settings.value.consents.storage_inbox = consent
    feedback.value = i18n.t(consent.granted ? 'ai.consentGranted' : 'ai.consentRevoked')
  } catch (current) {
    error.value = safeError(current, 'ai.consentFailed')
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <section class="view-stack ai-settings-page">
    <header class="appearance-header">
      <p class="eyebrow">{{ i18n.t('ai.eyebrow') }}</p>
      <h1>{{ i18n.t('ai.title') }}</h1>
      <p class="muted">{{ i18n.t('ai.subtitle') }}</p>
    </header>

    <nav class="settings-tabs" :aria-label="i18n.t('ai.sections')">
      <RouterLink to="/settings/appearance">{{ i18n.t('appearance.tab') }}</RouterLink>
      <RouterLink to="/account">{{ i18n.t('appearance.profileTab') }}</RouterLink>
      <RouterLink to="/settings/data">{{ i18n.t('appearance.dataTab') }}</RouterLink>
      <RouterLink to="/settings/integrations">{{ i18n.t('nav.integrations') }}</RouterLink>
      <RouterLink to="/settings/ai" aria-current="page">{{ i18n.t('nav.ai') }}</RouterLink>
    </nav>

    <p class="notice ai-disclosure">{{ i18n.t('ai.externalWarning') }}</p>
    <p v-if="error" class="notice error" role="alert" aria-live="assertive">{{ error }}</p>
    <p v-else-if="feedback" class="notice success" role="status" aria-live="polite">{{ feedback }}</p>

    <AsyncState :loading="loading" :error="loadError" panel @retry="load">
      <section class="panel ai-connection-editor" aria-labelledby="ai-editor-heading">
        <div class="section-heading">
          <div>
            <p class="eyebrow">{{ i18n.t(editingId === null ? 'ai.addEyebrow' : 'ai.editEyebrow') }}</p>
            <h2 id="ai-editor-heading">{{ i18n.t(editingId === null ? 'ai.addTitle' : 'ai.editTitle') }}</h2>
          </div>
          <button v-if="editingId !== null" type="button" class="ghost" @click="resetForm">{{ i18n.t('common.cancel') }}</button>
        </div>
        <form class="form-grid ai-connection-form" novalidate @submit.prevent="saveConnection">
          <UiTextInput v-model="form.name" name="ai-name" :label="i18n.t('ai.name')" :maxlength="80" required :error="fieldErrors.name?.[0]" />
          <UiSelect v-model="form.provider" name="ai-provider" :label="i18n.t('ai.provider')" :options="providerOptions" required :error="fieldErrors.provider?.[0]" />
          <UiTextInput v-model="form.model" name="ai-model" :label="i18n.t('ai.model')" :helper="i18n.t('ai.modelHelp')" :maxlength="160" required :error="fieldErrors.model?.[0]" />
          <UiTextInput
            v-model="form.api_key"
            name="ai-api-key"
            type="password"
            autocomplete="new-password"
            :label="i18n.t(editingId === null ? 'ai.apiKey' : 'ai.rotateKey')"
            :helper="i18n.t(editingId === null ? 'ai.apiKeyHelp' : 'ai.rotateKeyHelp')"
            :required="editingId === null"
            :maxlength="1000"
            :error="fieldErrors.api_key?.[0]"
          />
          <UiTextInput v-model="form.max_output_tokens" name="ai-max-tokens" inputmode="numeric" :label="i18n.t('ai.maxTokens')" :helper="i18n.t('ai.maxTokensHelp')" required :error="fieldErrors['parameters.max_output_tokens']?.[0]" />
          <div class="form-actions">
            <button type="submit" :disabled="Boolean(busy)">{{ i18n.t(busy === 'save' ? 'common.saving' : 'common.save') }}</button>
          </div>
        </form>
        <p class="muted">{{ i18n.t('ai.secretStorage') }}</p>
      </section>

      <section class="panel" aria-labelledby="ai-connections-heading">
        <div class="section-heading">
          <div>
            <h2 id="ai-connections-heading">{{ i18n.t('ai.connections') }}</h2>
            <p class="muted">{{ i18n.t('ai.connectionsHelp') }}</p>
          </div>
        </div>
        <p v-if="settings?.data.length === 0" class="muted">{{ i18n.t('ai.noConnections') }}</p>
        <div v-else class="ai-connection-grid">
          <article v-for="connection in settings?.data" :key="connection.id" class="ai-connection-card">
            <div class="section-heading">
              <div>
                <h3>{{ connection.name }}</h3>
                <p class="muted">{{ connection.provider === 'anthropic' ? 'Anthropic' : 'OpenAI' }} · {{ connection.model }}</p>
              </div>
              <span class="kind-chip" :class="{ 'is-ready': connection.status === 'ready' }">{{ statusLabel(connection) }}</span>
            </div>
            <dl class="definition-list">
              <div><dt>{{ i18n.t('ai.keyMask') }}</dt><dd class="mono">{{ connection.key_mask }}</dd></div>
              <div><dt>{{ i18n.t('ai.maxTokens') }}</dt><dd>{{ i18n.number(connection.parameters.max_output_tokens) }}</dd></div>
              <div><dt>{{ i18n.t('ai.lastTested') }}</dt><dd>{{ formatDate(connection.last_tested_at) }}</dd></div>
              <div><dt>{{ i18n.t('ai.active') }}</dt><dd>{{ settings?.active_connection_id === connection.id ? i18n.t('common.yes') : i18n.t('common.no') }}</dd></div>
            </dl>
            <p v-if="connection.last_error_code" class="notice error">{{ i18n.t('ai.lastTestFailed') }}</p>
            <div class="button-row ai-connection-actions">
              <button type="button" class="secondary" :disabled="Boolean(busy)" @click="testConnection(connection)">{{ i18n.t(busy === `test:${connection.id}` ? 'ai.testing' : 'ai.test') }}</button>
              <button type="button" :disabled="Boolean(busy) || connection.status !== 'ready' || settings?.active_connection_id === connection.id" @click="activate(connection)">{{ i18n.t(settings?.active_connection_id === connection.id ? 'ai.active' : 'ai.activate') }}</button>
              <button type="button" class="secondary" :disabled="Boolean(busy)" @click="startEdit(connection)">{{ i18n.t('common.edit') }}</button>
              <button type="button" class="ghost" :disabled="Boolean(busy)" @click="remove(connection)">{{ i18n.t(deletingId === connection.id ? 'ai.confirmDelete' : 'common.delete') }}</button>
              <button v-if="deletingId === connection.id" type="button" class="ghost" :disabled="Boolean(busy)" @click="deletingId = null">{{ i18n.t('common.cancel') }}</button>
            </div>
          </article>
        </div>
      </section>

      <section class="panel ai-consent-card" aria-labelledby="ai-consent-heading">
        <div>
          <p class="eyebrow">{{ i18n.t('ai.consentEyebrow') }}</p>
          <h2 id="ai-consent-heading">{{ i18n.t('ai.consentTitle') }}</h2>
        </div>
        <p>{{ i18n.t('ai.consentDisclosure') }}</p>
        <p class="notice">{{ i18n.t('ai.costWarning') }}</p>
        <UiCheckbox v-model="consentGranted" name="storage-inbox-consent" :label="i18n.t('ai.consentLabel')" :helper="i18n.t('ai.consentHelp')" :disabled="Boolean(busy)" />
        <div class="form-actions">
          <button type="button" :disabled="Boolean(busy)" @click="saveConsent">{{ i18n.t('ai.saveConsent') }}</button>
          <RouterLink class="button secondary" to="/storage">{{ i18n.t('ai.openStorage') }}</RouterLink>
        </div>
      </section>
    </AsyncState>
  </section>
</template>
