<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  ApiError,
  downloadPortableBackup,
  restorePortableBackup,
  validatePortableBackup,
  validationErrors,
} from '../api/client'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
import {
  canRestoreSelection,
  createBackupSelection,
  saveDownloadedFile,
  validatedBackupSelection,
  type BackupSelection,
} from '../portability/files'

const i18n = useI18n()
const downloading = ref(false)
const downloadError = ref<string | null>(null)
const downloadSuccess = ref<string | null>(null)
const validating = ref(false)
const restoring = ref(false)
const selection = ref<BackupSelection>(createBackupSelection(null))
let validationSequence = 0

const canRestore = computed(() => canRestoreSelection(selection.value) && !restoring.value)

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${i18n.number(bytes)} B`
  if (bytes < 1024 * 1024) return `${i18n.number(bytes / 1024, { maximumFractionDigits: 1 })} KB`
  return `${i18n.number(bytes / (1024 * 1024), { maximumFractionDigits: 1 })} MB`
}

function formatDate(value: string | null): string {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(i18n.locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function exclusionLabel(code: string): string {
  const keys: Record<string, MessageKey> = {
    account_credentials: 'data.exclusion.account_credentials',
    auth_sessions_tokens: 'data.exclusion.auth_sessions_tokens',
    invitations: 'data.exclusion.invitations',
    framework_runtime: 'data.exclusion.framework_runtime',
    public_catalog_rows: 'data.exclusion.public_catalog_rows',
    notification_deliveries: 'data.exclusion.notification_deliveries',
  }
  return keys[code] ? i18n.t(keys[code]) : code
}

function issueLabel(code: string): string {
  return code === 'target_not_empty' ? i18n.t('data.issue.target_not_empty') : code
}

function chooseFile(event: Event): void {
  validationSequence += 1
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  selection.value = createBackupSelection(file)
}

async function downloadBackup(): Promise<void> {
  if (downloading.value) return
  downloading.value = true
  downloadError.value = null
  downloadSuccess.value = null

  try {
    saveDownloadedFile(await downloadPortableBackup())
    downloadSuccess.value = i18n.t('data.backupReady')
  } catch {
    downloadError.value = i18n.t('data.backupFailed')
  } finally {
    downloading.value = false
  }
}

async function validateBackup(): Promise<void> {
  const file = selection.value.file
  const fingerprint = selection.value.fingerprint
  if (!file || !fingerprint || validating.value || restoring.value) return
  const sequence = ++validationSequence
  validating.value = true
  selection.value = { ...createBackupSelection(file), fingerprint }

  try {
    const validation = await validatePortableBackup(file)
    if (sequence !== validationSequence || selection.value.fingerprint !== fingerprint) return
    selection.value = validatedBackupSelection(selection.value, validation)
  } catch (error) {
    if (sequence !== validationSequence || selection.value.fingerprint !== fingerprint) return
    const serverMessage = validationErrors(error).backup?.[0]
    selection.value = {
      ...selection.value,
      error: serverMessage ?? i18n.t('data.validationFailed'),
    }
  } finally {
    if (sequence === validationSequence) validating.value = false
  }
}

async function restoreBackup(): Promise<void> {
  if (!canRestore.value) return
  const current = selection.value
  const file = current.file
  const token = current.validation?.restore_token
  const fingerprint = current.fingerprint
  if (!file || !token || !fingerprint) return

  restoring.value = true
  selection.value = { ...current, error: null }

  try {
    const result = await restorePortableBackup(file, token, 'RESTORE')
    if (selection.value.fingerprint !== fingerprint) return
    selection.value = { ...selection.value, result, error: null }
  } catch (error) {
    if (selection.value.fingerprint !== fingerprint) return
    const message = error instanceof ApiError && error.status === 409
      ? i18n.t('data.restoreConflict')
      : i18n.t('data.restoreFailed')
    selection.value = {
      ...selection.value,
      validatedFingerprint: null,
      validation: null,
      confirmation: '',
      error: message,
    }
  } finally {
    restoring.value = false
  }
}
</script>

<template>
  <section class="view-stack data-page">
    <header class="appearance-header">
      <p class="eyebrow">{{ i18n.t('data.eyebrow') }}</p>
      <h1>{{ i18n.t('data.title') }}</h1>
      <p class="muted">{{ i18n.t('data.body') }}</p>
    </header>

    <nav class="settings-tabs" :aria-label="i18n.t('data.sections')">
      <RouterLink to="/settings/appearance">{{ i18n.t('appearance.tab') }}</RouterLink>
      <RouterLink to="/account">{{ i18n.t('appearance.profileTab') }}</RouterLink>
      <span aria-disabled="true">{{ i18n.t('appearance.preferencesTab') }}</span>
      <RouterLink to="/settings/data" aria-current="page">{{ i18n.t('appearance.dataTab') }}</RouterLink>
    </nav>

    <section class="panel data-card" aria-labelledby="backup-heading">
      <div class="data-card__heading">
        <div>
          <p class="eyebrow">{{ i18n.t('data.backupFormat') }}</p>
          <h2 id="backup-heading">{{ i18n.t('data.backupTitle') }}</h2>
        </div>
      </div>
      <p>{{ i18n.t('data.backupBody') }}</p>
      <p class="data-privacy">{{ i18n.t('data.backupPrivacy') }}</p>
      <div class="data-actions">
        <button type="button" :disabled="downloading" @click="downloadBackup">
          {{ downloading ? i18n.t('data.downloadingBackup') : i18n.t('data.downloadBackup') }}
        </button>
        <button v-if="downloadError" type="button" class="secondary" :disabled="downloading" @click="downloadBackup">
          {{ i18n.t('common.retry') }}
        </button>
      </div>
      <p v-if="downloadError" class="notice error" role="alert">{{ downloadError }}</p>
      <p v-else-if="downloadSuccess" class="notice success" role="status">{{ downloadSuccess }}</p>
    </section>

    <section class="panel data-card" aria-labelledby="restore-heading">
      <div class="data-card__heading">
        <div>
          <p class="eyebrow">{{ i18n.t('data.preflight') }}</p>
          <h2 id="restore-heading">{{ i18n.t('data.restoreTitle') }}</h2>
        </div>
      </div>
      <p>{{ i18n.t('data.restoreBody') }}</p>
      <p class="notice">{{ i18n.t('data.restoreEmptyOnly') }}</p>

      <div class="data-file-picker">
        <label class="button secondary" for="portable-backup">{{ i18n.t('data.chooseBackup') }}</label>
        <input
          id="portable-backup"
          type="file"
          name="portable-backup"
          accept=".zip,application/zip"
          :disabled="validating || restoring"
          @change="chooseFile"
        />
        <p class="data-filename">
          {{ selection.file
            ? i18n.t('data.selectedFile', { name: selection.file.name, size: formatBytes(selection.file.size) })
            : i18n.t('data.noFile') }}
        </p>
      </div>

      <div class="data-actions">
        <button type="button" :disabled="!selection.file || validating || restoring" @click="validateBackup">
          {{ validating ? i18n.t('data.validating') : i18n.t('data.validate') }}
        </button>
        <button v-if="selection.error" type="button" class="secondary" :disabled="validating || restoring" @click="validateBackup">
          {{ i18n.t('common.retry') }}
        </button>
      </div>
      <p v-if="selection.error" class="notice error" role="alert" aria-live="assertive">{{ selection.error }}</p>

      <section v-if="selection.validation" class="data-validation" aria-labelledby="validation-heading">
        <div>
          <h3 id="validation-heading">{{ i18n.t('data.validationTitle') }}</h3>
          <p class="notice" :class="selection.validation.eligible ? 'success' : 'error'" role="status">
            {{ selection.validation.eligible ? i18n.t('data.validArchive') : i18n.t('data.ineligible') }}
          </p>
        </div>

        <dl class="data-facts">
          <div><dt>{{ i18n.t('data.schema') }}</dt><dd class="mono">v{{ selection.validation.schema_version }}</dd></div>
          <div><dt>{{ i18n.t('data.created') }}</dt><dd>{{ formatDate(selection.validation.created_at) }}</dd></div>
          <div><dt>{{ i18n.t('data.records') }}</dt><dd class="mono">{{ i18n.number(selection.validation.counts?.total_records ?? 0) }}</dd></div>
          <div><dt>{{ i18n.t('data.attachments') }}</dt><dd class="mono">{{ i18n.number(selection.validation.counts?.attachments ?? 0) }}</dd></div>
          <div><dt>{{ i18n.t('data.bytes') }}</dt><dd class="mono">{{ formatBytes(selection.validation.counts?.total_bytes ?? 0) }}</dd></div>
        </dl>

        <div class="data-exclusions">
          <h4>{{ i18n.t('data.exclusions') }}</h4>
          <ul>
            <li v-for="code in selection.validation.exclusions" :key="code">{{ exclusionLabel(code) }}</li>
          </ul>
        </div>

        <ul v-if="selection.validation.issues.length" class="notice error data-issues">
          <li v-for="issue in selection.validation.issues" :key="issue">{{ issueLabel(issue) }}</li>
        </ul>

        <form v-if="selection.validation.eligible && selection.validation.restore_token" class="data-confirm" @submit.prevent="restoreBackup">
          <label for="restore-confirmation">{{ i18n.t('data.confirmLabel') }}</label>
          <input
            id="restore-confirmation"
            v-model="selection.confirmation"
            class="ui-control ui-control--text mono"
            name="restore-confirmation"
            autocomplete="off"
            spellcheck="false"
            :disabled="restoring || Boolean(selection.result)"
          />
          <p class="ui-field__helper">
            {{ i18n.t('data.confirmHelp', { expires: formatDate(selection.validation.expires_at) }) }}
          </p>
          <button type="submit" :disabled="!canRestore">
            {{ restoring ? i18n.t('data.restoring') : i18n.t('data.restore') }}
          </button>
        </form>
      </section>

      <section v-if="selection.result" class="notice success data-result" role="status" aria-live="polite">
        <h3>{{ i18n.t('data.restoreSuccess') }}</h3>
        <p>{{ i18n.t('data.restoreSuccessBody', {
          records: i18n.number(selection.result.total_records),
          attachments: i18n.number(selection.result.attachments),
        }) }}</p>
      </section>
    </section>
  </section>
</template>
