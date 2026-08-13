<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  ApiError,
  getNotificationSettings,
  replaceNotificationSettings,
  validationErrors,
} from '../api/client'
import type {
  InAppNotification,
  NotificationSettingsData,
  NotificationSnoozeMinutes,
  NotificationView,
} from '../api/types'
import { useAuthSession } from '../auth/session'
import AsyncState from '../components/AsyncState.vue'
import { UiSwitch, UiTimeField } from '../components/ui'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
import { useNotificationStore } from '../notifications/store'

const router = useRouter()
const session = useAuthSession()
const notifications = useNotificationStore()
const { locale, t } = useI18n()

const settingsLoading = ref(true)
const settingsSaving = ref(false)
const settingsLoadError = ref<string | null>(null)
const settingsSaveError = ref<string | null>(null)
const feedback = ref<string | null>(null)
const activeSnooze = ref<number | null>(null)
const fieldErrors = ref<Record<string, string[]>>({})
const accepted = ref<NotificationSettingsData | null>(null)

const draft = reactive<NotificationSettingsData>({
  quiet_hours: { enabled: true, starts_at: '23:00', ends_at: '08:00' },
  digest: { enabled: true, time: '08:00' },
  categories: { routine: true, storage: true },
})

const snoozeLabelKeys: Record<NotificationSnoozeMinutes, MessageKey> = {
  15: 'notifications.snooze.15',
  60: 'notifications.snooze.60',
  240: 'notifications.snooze.240',
  1440: 'notifications.snooze.1440',
}

const snoozedFeedbackKeys: Record<NotificationSnoozeMinutes, MessageKey> = {
  15: 'notifications.snoozed.15',
  60: 'notifications.snoozed.60',
  240: 'notifications.snoozed.240',
  1440: 'notifications.snoozed.1440',
}

const dirty = computed(() => accepted.value !== null
  && JSON.stringify(draft) !== JSON.stringify(accepted.value))

function cloneSettings(value: NotificationSettingsData): NotificationSettingsData {
  return {
    quiet_hours: { ...value.quiet_hours },
    digest: { ...value.digest },
    categories: { ...value.categories },
  }
}

function subject(notification: InAppNotification): string {
  return notification.subject ?? notification.title
}

function sentAt(value: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: session.user?.preferences.timezone ?? 'UTC',
    }).format(new Date(value))
  } catch {
    return value
  }
}

function safeAction(url: string | null): boolean {
  return Boolean(url && /^\/planner(?:\?date=\d{4}-\d{2}-\d{2})?$/.test(url))
}

function snoozeLabel(minutes: NotificationSnoozeMinutes): string {
  return t(snoozeLabelKeys[minutes])
}

function snoozedFeedback(minutes: NotificationSnoozeMinutes): string {
  return t(snoozedFeedbackKeys[minutes])
}

function fieldError(path: string): string | undefined {
  return fieldErrors.value[path]?.[0]
}

async function switchView(view: NotificationView): Promise<void> {
  activeSnooze.value = null
  await notifications.refresh(view)
}

async function markRead(notification: InAppNotification): Promise<void> {
  feedback.value = null
  try {
    await notifications.markRead(notification.id)
  } catch {
    feedback.value = t('notifications.actionFailed')
  }
}

async function dismiss(notification: InAppNotification): Promise<void> {
  feedback.value = null
  try {
    await notifications.dismiss(notification.id)
  } catch {
    feedback.value = t('notifications.actionFailed')
  }
}

async function snooze(notification: InAppNotification, minutes: NotificationSnoozeMinutes): Promise<void> {
  feedback.value = null
  activeSnooze.value = null
  try {
    await notifications.snooze(notification.id, minutes)
    feedback.value = snoozedFeedback(minutes)
  } catch {
    feedback.value = t('notifications.actionFailed')
  }
}

async function follow(notification: InAppNotification): Promise<void> {
  if (!safeAction(notification.action_url)) return

  if (notification.status === 'sent') {
    try {
      await notifications.markRead(notification.id)
    } catch {
      feedback.value = t('notifications.actionFailed')
      return
    }
  }

  await router.push(notification.action_url as string)
}

async function loadSettings(): Promise<void> {
  settingsLoading.value = true
  settingsLoadError.value = null

  try {
    const response = await getNotificationSettings()
    accepted.value = cloneSettings(response.data)
    Object.assign(draft.quiet_hours, response.data.quiet_hours)
    Object.assign(draft.digest, response.data.digest)
    Object.assign(draft.categories, response.data.categories)
  } catch {
    settingsLoadError.value = t('notifications.settingsLoadFailed')
  } finally {
    settingsLoading.value = false
  }
}

async function saveSettings(): Promise<void> {
  if (!dirty.value || settingsSaving.value) return

  settingsSaving.value = true
  settingsSaveError.value = null
  feedback.value = null
  fieldErrors.value = {}

  try {
    const response = await replaceNotificationSettings(cloneSettings(draft))
    accepted.value = cloneSettings(response.data)
    Object.assign(draft.quiet_hours, response.data.quiet_hours)
    Object.assign(draft.digest, response.data.digest)
    Object.assign(draft.categories, response.data.categories)
    feedback.value = t('notifications.settingsSaved')
  } catch (error) {
    const errors = validationErrors(error)

    if (error instanceof ApiError && error.status === 422 && Object.keys(errors).length > 0) {
      fieldErrors.value = errors
      settingsSaveError.value = error.message
    } else {
      if (accepted.value) {
        Object.assign(draft.quiet_hours, accepted.value.quiet_hours)
        Object.assign(draft.digest, accepted.value.digest)
        Object.assign(draft.categories, accepted.value.categories)
      }
      settingsSaveError.value = t('notifications.settingsSaveFailed')
    }
  } finally {
    settingsSaving.value = false
  }
}

onMounted(() => {
  void notifications.refresh()
  void loadSettings()
})
</script>

<template>
  <section class="view-stack notifications-page">
    <header class="view-header notifications-header">
      <div>
        <p class="eyebrow">{{ t('notifications.eyebrow') }}</p>
        <h1>{{ t('notifications.title') }}</h1>
        <p class="muted">{{ t('notifications.subtitle') }}</p>
      </div>
      <span v-if="notifications.state.unreadCount > 0" class="notification-count">
        {{ notifications.state.unreadCount }} {{ t('notifications.unread').toLocaleLowerCase(locale) }}
      </span>
    </header>

    <div v-if="feedback" class="notice success" role="status" aria-live="polite">{{ feedback }}</div>

    <section class="panel notifications-inbox" aria-labelledby="notification-list-heading">
      <div class="notifications-section-heading">
        <div>
          <h2 id="notification-list-heading">{{ t('notifications.eyebrow') }}</h2>
          <p class="muted">{{ t('notifications.subtitle') }}</p>
        </div>
        <div class="notification-filters" role="group" :aria-label="t('notifications.filters')">
          <button
            type="button"
            class="secondary"
            :class="{ 'is-active': notifications.state.view === 'all' }"
            :aria-pressed="notifications.state.view === 'all'"
            @click="switchView('all')"
          >{{ t('notifications.all') }}</button>
          <button
            type="button"
            class="secondary"
            :class="{ 'is-active': notifications.state.view === 'unread' }"
            :aria-pressed="notifications.state.view === 'unread'"
            @click="switchView('unread')"
          >{{ t('notifications.unread') }}</button>
        </div>
      </div>

      <AsyncState
        :loading="notifications.state.loading"
        :error="notifications.state.error"
        :empty="notifications.state.items.length === 0"
        :loading-title="t('notifications.loading')"
        :empty-title="notifications.state.view === 'unread'
          ? t('notifications.unreadEmpty')
          : t('notifications.empty')"
        :empty-description="notifications.state.view === 'all' ? t('notifications.emptyBody') : ''"
        show-empty-icon
        @retry="notifications.refresh()"
      >
        <div class="notification-list">
          <article
            v-for="notification in notifications.state.items"
            :key="notification.id"
            class="notification-card"
            :class="{ 'is-unread': notification.status === 'sent' }"
            :data-status="notification.status"
          >
            <div class="notification-card__copy">
              <div class="notification-card__heading">
                <span class="notification-card__dot" aria-hidden="true"></span>
                <h3>{{ notification.title }}</h3>
                <span v-if="notification.escalation_count > 0" class="token-caption">
                  {{ t('notifications.repeat', { count: notification.escalation_count }) }}
                </span>
              </div>
              <p>{{ notification.body }}</p>
              <small class="muted">{{ t('notifications.sentAt', { time: sentAt(notification.sent_at) }) }}</small>
            </div>

            <div class="notification-card__actions">
              <a
                v-if="safeAction(notification.action_url)"
                :href="notification.action_url ?? undefined"
                class="button-link"
                @click.prevent="follow(notification)"
              >{{ t('notifications.openPlanner') }}</a>
              <button
                v-if="notification.status === 'sent'"
                type="button"
                class="secondary"
                :aria-label="t('notifications.markReadNamed', { name: subject(notification) })"
                @click="markRead(notification)"
              >{{ t('notifications.markRead') }}</button>
              <div class="notification-snooze">
                <button
                  type="button"
                  class="secondary"
                  aria-haspopup="menu"
                  :aria-expanded="activeSnooze === notification.id"
                  :aria-label="t('notifications.snoozeNamed', { name: subject(notification) })"
                  @click="activeSnooze = activeSnooze === notification.id ? null : notification.id"
                >{{ t('notifications.snooze') }}</button>
                <div
                  v-if="activeSnooze === notification.id"
                  class="notification-snooze__menu"
                  role="menu"
                  :aria-label="t('notifications.snoozeMenuNamed', { name: subject(notification) })"
                >
                  <button
                    v-for="minutes in notifications.state.snoozeOptions"
                    :key="minutes"
                    type="button"
                    role="menuitem"
                    @click="snooze(notification, minutes)"
                  >{{ snoozeLabel(minutes) }}</button>
                </div>
              </div>
              <button
                type="button"
                class="text-button"
                :aria-label="t('notifications.dismissNamed', { name: subject(notification) })"
                @click="dismiss(notification)"
              >{{ t('notifications.dismiss') }}</button>
            </div>
          </article>
        </div>
      </AsyncState>
    </section>

    <section class="panel notification-settings" aria-labelledby="notification-settings-heading">
      <div class="notifications-section-heading">
        <div>
          <h2 id="notification-settings-heading">{{ t('notifications.settingsTitle') }}</h2>
          <p class="muted">{{ t('notifications.settingsBody') }}</p>
        </div>
      </div>

      <div v-if="settingsLoading" class="state-block" role="status">
        {{ t('notifications.settingsLoading') }}
      </div>
      <div v-else-if="settingsLoadError" class="state-block error" role="alert">
        <p>{{ settingsLoadError }}</p>
        <button type="button" @click="loadSettings">{{ t('common.retry') }}</button>
      </div>
      <form v-else class="notification-settings__form" @submit.prevent="saveSettings">
        <div v-if="settingsSaveError" class="notice error" role="alert">{{ settingsSaveError }}</div>

        <fieldset class="notification-settings__group">
          <UiSwitch
            v-model="draft.quiet_hours.enabled"
            name="quiet_hours_enabled"
            :label="t('notifications.quiet')"
            :helper="t('notifications.quietHelp')"
          />
          <div class="notification-settings__times">
            <UiTimeField
              v-model="draft.quiet_hours.starts_at"
              name="quiet_starts_at"
              :label="t('notifications.quietStarts')"
              :error="fieldError('quiet_hours.starts_at')"
            />
            <UiTimeField
              v-model="draft.quiet_hours.ends_at"
              name="quiet_ends_at"
              :label="t('notifications.quietEnds')"
              :error="fieldError('quiet_hours.ends_at')"
            />
          </div>
          <p class="muted notification-settings__note">{{ t('notifications.quietPrecedence') }}</p>
        </fieldset>

        <fieldset class="notification-settings__group">
          <UiSwitch
            v-model="draft.digest.enabled"
            name="digest_enabled"
            :label="t('notifications.digest')"
            :helper="t('notifications.digestHelp')"
          />
          <UiTimeField
            v-model="draft.digest.time"
            name="digest_time"
            :label="t('notifications.digestTime')"
            :error="fieldError('digest.time')"
          />
        </fieldset>

        <fieldset class="notification-settings__group">
          <legend>{{ t('notifications.categories') }}</legend>
          <p class="muted">{{ t('notifications.categoriesHelp') }}</p>
          <UiSwitch
            v-model="draft.categories.routine"
            name="routine_notifications"
            :label="t('notifications.routine')"
            :helper="t('notifications.routineHelp')"
          />
          <UiSwitch
            v-model="draft.categories.storage"
            name="storage_notifications"
            :label="t('notifications.storage')"
            :helper="t('notifications.storageHelp')"
          />
        </fieldset>

        <button type="submit" :disabled="settingsSaving || !dirty">
          {{ settingsSaving ? t('common.saving') : t('notifications.saveSettings') }}
        </button>
      </form>
    </section>
  </section>
</template>
