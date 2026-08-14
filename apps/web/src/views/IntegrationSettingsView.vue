<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  connectAppleCalendar,
  disconnectCalendar,
  getCalendarIntegrations,
  getProviderCalendars,
  selectProviderCalendar,
  startGoogleCalendarAuthorization,
  syncCalendar,
  updateCalendarSettings,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import type {
  CalendarDescriptor,
  CalendarExportCategory,
  CalendarIntegration,
  CalendarProvider,
  CalendarProviderAvailability,
  CalendarSettings,
  CalendarSyncResult,
} from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { UiCheckbox, UiSelect, UiTextInput } from '../components/ui'
import type { MessageKey } from '../i18n/locales/en'
import { useI18n } from '../i18n'
import { isAndroidNative } from '../mobile/platform'

const i18n = useI18n()
const route = useRoute()
const router = useRouter()
const nativeAndroid = isAndroidNative()
const loading = ref(true)
const loadError = ref<string | null>(null)
const busy = ref<string | null>(null)
const feedback = ref<string | null>(null)
const error = ref<string | null>(null)
const integrations = ref<CalendarIntegration[]>([])
const providers = ref<CalendarProviderAvailability[]>([])
const calendars = ref<Record<number, CalendarDescriptor[]>>({})
const selectedCalendars = ref<Record<number, string | null>>({})
const drafts = ref<Record<number, CalendarSettings>>({})
const syncResults = ref<Record<number, CalendarSyncResult>>({})
const disconnecting = ref<number | null>(null)
const appleAccount = ref('')
const applePassword = ref('')
const appleErrors = ref<ValidationErrors>({})

const providerNames: Record<CalendarProvider, string> = {
  google_calendar: 'Google Calendar',
  apple_calendar: 'Apple Calendar',
}

const calendarProviders: readonly CalendarProvider[] = ['google_calendar', 'apple_calendar']
const providerCards = computed(() => calendarProviders.map((provider) => ({
  provider,
  integration: connection(provider),
  availability: availability(provider),
})))

const exportCategories: readonly { value: CalendarExportCategory, label: MessageKey, sensitive: boolean }[] = [
  { value: 'time_block', label: 'integrations.category.timeBlock', sensitive: false },
  { value: 'routine', label: 'integrations.category.routine', sensitive: false },
  { value: 'sleep', label: 'integrations.category.sleep', sensitive: true },
  { value: 'habit', label: 'integrations.category.habit', sensitive: false },
  { value: 'workout', label: 'integrations.category.workout', sensitive: true },
  { value: 'supplement', label: 'integrations.category.supplement', sensitive: true },
  { value: 'finance', label: 'integrations.category.finance', sensitive: true },
]

const importOptions = computed(() => [
  { value: 'busy_only' as const, label: i18n.t('integrations.privacy.busyOnly') },
  { value: 'title' as const, label: i18n.t('integrations.privacy.title') },
])

function connection(provider: CalendarProvider): CalendarIntegration | undefined {
  return integrations.value.find((entry) => entry.provider === provider)
}

function availability(provider: CalendarProvider): CalendarProviderAvailability | undefined {
  return providers.value.find((entry) => entry.provider === provider)
}

function calendarOptions(integration: CalendarIntegration) {
  return (calendars.value[integration.id] ?? []).map((calendar) => ({
    value: calendar.id,
    label: calendar.writable ? calendar.name : `${calendar.name} · ${i18n.t('integrations.readOnly')}`,
    disabled: !calendar.writable,
  }))
}

function formatDate(value: string | null): string {
  if (!value) return i18n.t('integrations.never')
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(i18n.locale.value, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

function rememberDrafts(): void {
  drafts.value = Object.fromEntries(integrations.value.map((integration) => [
    integration.id,
    {
      import_detail: integration.settings.import_detail,
      export_categories: [...integration.settings.export_categories],
    },
  ]))
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const response = await getCalendarIntegrations()
    integrations.value = response.data
    providers.value = response.providers
    rememberDrafts()
  } catch {
    loadError.value = i18n.t('integrations.loadFailed')
  } finally {
    loading.value = false
  }
}

async function startGoogle(): Promise<void> {
  if (busy.value || nativeAndroid) return
  busy.value = 'google'
  error.value = null
  try {
    const authorization = await startGoogleCalendarAuthorization()
    window.location.assign(authorization.authorization_url)
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('integrations.connectFailed')
    busy.value = null
  }
}

async function connectApple(): Promise<void> {
  if (busy.value) return
  busy.value = 'apple'
  appleErrors.value = {}
  error.value = null
  try {
    const response = await connectAppleCalendar(appleAccount.value, applePassword.value)
    integrations.value.push(response.data)
    calendars.value = { ...calendars.value, [response.data.id]: response.calendars }
    selectedCalendars.value = {
      ...selectedCalendars.value,
      [response.data.id]: response.calendars.find((calendar) => calendar.is_default && calendar.writable)?.id
        ?? response.calendars.find((calendar) => calendar.writable)?.id
        ?? null,
    }
    rememberDrafts()
    appleAccount.value = ''
    feedback.value = i18n.t('integrations.chooseCalendar')
  } catch (current) {
    appleErrors.value = validationErrors(current)
    error.value = current instanceof Error ? current.message : i18n.t('integrations.connectFailed')
  } finally {
    applePassword.value = ''
    busy.value = null
  }
}

async function discover(integration: CalendarIntegration): Promise<void> {
  if (busy.value) return
  busy.value = `discover:${integration.id}`
  error.value = null
  try {
    const available = await getProviderCalendars(integration.id)
    calendars.value = { ...calendars.value, [integration.id]: available }
    selectedCalendars.value = {
      ...selectedCalendars.value,
      [integration.id]: selectedCalendars.value[integration.id]
        ?? available.find((calendar) => calendar.is_default && calendar.writable)?.id
        ?? available.find((calendar) => calendar.writable)?.id
        ?? null,
    }
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('integrations.discoveryFailed')
  } finally {
    busy.value = null
  }
}

async function selectCalendar(integration: CalendarIntegration): Promise<void> {
  const calendarId = selectedCalendars.value[integration.id]
  if (!calendarId || busy.value) return
  busy.value = `select:${integration.id}`
  error.value = null
  try {
    const updated = await selectProviderCalendar(integration.id, calendarId)
    replace(updated)
    feedback.value = i18n.t('integrations.calendarSelected')
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('integrations.selectFailed')
  } finally {
    busy.value = null
  }
}

function includesCategory(integrationId: number, category: CalendarExportCategory): boolean {
  return drafts.value[integrationId]?.export_categories.includes(category) ?? false
}

function toggleCategory(integrationId: number, category: CalendarExportCategory, checked: boolean): void {
  const draft = drafts.value[integrationId]
  if (!draft) return
  const categories = checked
    ? [...draft.export_categories, category]
    : draft.export_categories.filter((current) => current !== category)
  drafts.value = {
    ...drafts.value,
    [integrationId]: { ...draft, export_categories: [...new Set(categories)] },
  }
}

function updateImportDetail(integrationId: number, value: CalendarSettings['import_detail'] | null): void {
  const draft = drafts.value[integrationId]
  if (!draft || value === null) return
  drafts.value = { ...drafts.value, [integrationId]: { ...draft, import_detail: value } }
}

function syncSummary(result: CalendarSyncResult): Record<string, string | number> {
  return {
    imported: result.imported,
    updated: result.updated,
    removed: result.removed,
    exported: result.exported,
    deleted: result.deleted,
    conflicts: result.conflicts,
    unchanged: result.unchanged,
  }
}

async function saveSettings(integration: CalendarIntegration): Promise<void> {
  const draft = drafts.value[integration.id]
  if (!draft || busy.value) return
  busy.value = `settings:${integration.id}`
  error.value = null
  try {
    replace(await updateCalendarSettings(integration.id, draft))
    feedback.value = i18n.t('integrations.settingsSaved')
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('integrations.settingsFailed')
  } finally {
    busy.value = null
  }
}

async function runSync(integration: CalendarIntegration): Promise<void> {
  if (busy.value) return
  busy.value = `sync:${integration.id}`
  error.value = null
  try {
    const result = await syncCalendar(integration.id)
    syncResults.value = { ...syncResults.value, [integration.id]: result }
    feedback.value = i18n.t('integrations.syncComplete')
    await load()
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('integrations.syncFailed')
  } finally {
    busy.value = null
  }
}

async function disconnect(integration: CalendarIntegration): Promise<void> {
  if (disconnecting.value !== integration.id) {
    disconnecting.value = integration.id
    return
  }
  if (busy.value) return
  busy.value = `disconnect:${integration.id}`
  error.value = null
  try {
    await disconnectCalendar(integration.id)
    integrations.value = integrations.value.filter((entry) => entry.id !== integration.id)
    disconnecting.value = null
    feedback.value = i18n.t('integrations.disconnected')
  } catch (current) {
    error.value = current instanceof Error ? current.message : i18n.t('integrations.disconnectFailed')
  } finally {
    busy.value = null
  }
}

function replace(updated: CalendarIntegration): void {
  integrations.value = integrations.value.map((entry) => entry.id === updated.id ? updated : entry)
  drafts.value = {
    ...drafts.value,
    [updated.id]: {
      import_detail: updated.settings.import_detail,
      export_categories: [...updated.settings.export_categories],
    },
  }
}

function callbackFeedback(): void {
  const result = typeof route.query.calendar === 'string' ? route.query.calendar : null
  if (!result) return
  const success = result === 'oauth_connected'
  const callbackMessages: Partial<Record<string, MessageKey>> = {
    oauth_denied: 'integrations.callback.oauth_denied',
    oauth_invalid_state: 'integrations.callback.oauth_invalid_state',
    calendar_provider_unavailable: 'integrations.callback.calendar_provider_unavailable',
    calendar_auth_expired: 'integrations.callback.calendar_auth_expired',
    calendar_sync_failed: 'integrations.callback.calendar_sync_failed',
  }
  feedback.value = success ? i18n.t('integrations.oauthConnected') : null
  error.value = success ? null : i18n.t(callbackMessages[result] ?? 'integrations.connectFailed')
  void router.replace({ query: { ...route.query, calendar: undefined } })
}

onMounted(async () => {
  callbackFeedback()
  await load()
  const pendingGoogle = connection('google_calendar')
  if (pendingGoogle?.status === 'pending') await discover(pendingGoogle)
})
</script>

<template>
  <section class="view-stack integrations-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('integrations.eyebrow') }}</p>
        <h1>{{ i18n.t('integrations.title') }}</h1>
        <p class="muted">{{ i18n.t('integrations.subtitle') }}</p>
      </div>
    </header>

    <p v-if="feedback" class="notice success" role="status">{{ feedback }}</p>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>

    <AsyncState :loading="loading" :error="loadError" :loading-title="i18n.t('integrations.loading')" panel @retry="load">
      <section class="integration-grid" :aria-label="i18n.t('integrations.providers')">
        <article v-for="card in providerCards" :key="card.provider" class="panel integration-card">
          <div class="section-heading">
            <div>
              <p class="eyebrow">{{ i18n.t('integrations.provider') }}</p>
              <h2>{{ providerNames[card.provider] }}</h2>
            </div>
            <span v-if="card.integration" class="chip">{{ i18n.t(`integrations.status.${card.integration.status}` as MessageKey) }}</span>
          </div>

          <template v-if="!card.integration">
            <p class="muted">{{ card.provider === 'google_calendar' ? i18n.t('integrations.googleHelp') : i18n.t('integrations.appleHelp') }}</p>
            <p v-if="card.provider === 'google_calendar' && nativeAndroid" class="notice">{{ i18n.t('integrations.googleAndroid') }}</p>
            <button
              v-if="card.provider === 'google_calendar'"
              type="button"
              :disabled="busy !== null || !card.availability?.available || nativeAndroid"
              @click="startGoogle"
            >{{ busy === 'google' ? i18n.t('integrations.connecting') : i18n.t('integrations.connectGoogle') }}</button>
            <form v-else class="form-grid" :aria-label="i18n.t('integrations.connectApple')" @submit.prevent="connectApple">
              <UiTextInput v-model="appleAccount" name="apple-account" type="email" inputmode="email" autocomplete="username" required :label="i18n.t('integrations.appleAccount')" :error="appleErrors.account?.[0]" />
              <UiTextInput v-model="applePassword" name="apple-password" type="password" autocomplete="new-password" required :label="i18n.t('integrations.appPassword')" :helper="i18n.t('integrations.appPasswordHelp')" :error="appleErrors.app_specific_password?.[0]" />
              <div class="button-row wide-field"><button type="submit" :disabled="busy !== null || !appleAccount || !applePassword">{{ busy === 'apple' ? i18n.t('integrations.connecting') : i18n.t('integrations.connectApple') }}</button></div>
            </form>
          </template>

          <template v-else>
            <dl class="definition-list">
              <div><dt>{{ i18n.t('integrations.account') }}</dt><dd>{{ card.integration.account ?? '—' }}</dd></div>
              <div><dt>{{ i18n.t('integrations.calendar') }}</dt><dd>{{ card.integration.calendar?.name ?? i18n.t('integrations.notSelected') }}</dd></div>
              <div><dt>{{ i18n.t('integrations.lastSuccess') }}</dt><dd>{{ formatDate(card.integration.last_success_at) }}</dd></div>
            </dl>

            <div v-if="card.integration.status === 'pending' || !card.integration.calendar" class="integration-selection">
              <button type="button" class="secondary" :disabled="busy !== null" @click="discover(card.integration)">{{ i18n.t('integrations.findCalendars') }}</button>
              <UiSelect
                v-if="calendars[card.integration.id]?.length"
                v-model="selectedCalendars[card.integration.id]"
                :label="i18n.t('integrations.calendar')"
                :name="`calendar-${card.integration.id}`"
                :options="calendarOptions(card.integration)"
                required
              />
              <button v-if="calendars[card.integration.id]?.length" type="button" :disabled="busy !== null || !selectedCalendars[card.integration.id]" @click="selectCalendar(card.integration)">{{ i18n.t('integrations.useCalendar') }}</button>
            </div>

            <template v-else>
              <UiSelect
                :model-value="drafts[card.integration.id]?.import_detail ?? 'busy_only'"
                :label="i18n.t('integrations.importDetail')"
                :name="`privacy-${card.integration.id}`"
                :options="importOptions"
                :helper="i18n.t('integrations.importDetailHelp')"
                @update:model-value="updateImportDetail(card.integration.id, $event)"
              />

              <fieldset class="integration-categories">
                <legend>{{ i18n.t('integrations.exportCategories') }}</legend>
                <p class="muted">{{ i18n.t('integrations.exportDefault') }}</p>
                <UiCheckbox
                  v-for="category in exportCategories"
                  :key="category.value"
                  :model-value="includesCategory(card.integration.id, category.value)"
                  :name="`export-${card.integration.id}-${category.value}`"
                  :label="i18n.t(category.label)"
                  :helper="category.sensitive ? i18n.t('integrations.sensitiveWarning') : undefined"
                  @update:model-value="toggleCategory(card.integration.id, category.value, $event)"
                />
              </fieldset>

              <div class="button-row">
                <button type="button" :disabled="busy !== null" @click="saveSettings(card.integration)">{{ i18n.t('common.save') }}</button>
                <button type="button" class="secondary" :disabled="busy !== null || card.integration.status !== 'active'" @click="runSync(card.integration)">{{ busy === `sync:${card.integration.id}` ? i18n.t('integrations.syncing') : i18n.t('integrations.syncNow') }}</button>
              </div>

              <p v-if="card.integration.last_error_code" class="notice error" role="alert">{{ i18n.t(`integrations.error.${card.integration.last_error_code}` as MessageKey) }}</p>
              <p v-if="syncResults[card.integration.id]" class="notice success" role="status">
                {{ i18n.t('integrations.syncSummary', syncSummary(syncResults[card.integration.id]!)) }}
              </p>
            </template>

            <div class="integration-disconnect">
              <p v-if="disconnecting === card.integration.id" class="notice" role="status">{{ i18n.t('integrations.disconnectWarning') }}</p>
              <div class="button-row">
                <button type="button" class="secondary danger" :disabled="busy !== null" @click="disconnect(card.integration)">{{ disconnecting === card.integration.id ? i18n.t('integrations.confirmDisconnect') : i18n.t('integrations.disconnect') }}</button>
                <button v-if="disconnecting === card.integration.id" type="button" class="ghost" @click="disconnecting = null">{{ i18n.t('common.cancel') }}</button>
              </div>
            </div>
          </template>
        </article>
      </section>
    </AsyncState>
  </section>
</template>
