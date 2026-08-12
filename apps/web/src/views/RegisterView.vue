<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { RegisterPayload } from '../api/types'
import { safeRedirect } from '../auth/redirect'
import { register } from '../auth/session'
import { UiTextInput } from '../components/ui'
import { useI18n } from '../i18n'

type FocusableControl = { focus: () => void }

const route = useRoute()
const router = useRouter()
const form = reactive<RegisterPayload>({
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
  invite_code: '',
})
const fieldErrors = ref<ValidationErrors>({})
const error = ref<string | null>(null)
const isSubmitting = ref(false)
const inviteInput = ref<FocusableControl | null>(null)
const nameInput = ref<FocusableControl | null>(null)
const emailInput = ref<FocusableControl | null>(null)
const passwordInput = ref<FocusableControl | null>(null)
const confirmationInput = ref<FocusableControl | null>(null)
const { t } = useI18n()

function clearPasswords(): void {
  form.password = ''
  form.password_confirmation = ''
}

function failureMessage(currentError: unknown): string {
  if (!(currentError instanceof ApiError)) {
    return t('common.errorGeneric')
  }

  if (currentError.status === 0 || currentError.status >= 500) {
    return t('common.errorReach')
  }

  if (currentError.status === 419) {
    return t('common.errorCsrf')
  }

  if (currentError.status === 429) {
    return currentError.retryAfter
      ? t('common.errorRateSeconds', { seconds: currentError.retryAfter })
      : t('common.errorRate')
  }

  if (currentError.status === 409) {
    return t('common.alreadySignedIn')
  }

  if (currentError.status === 422) {
    return t('auth.registrationInvalid')
  }

  return t('auth.registrationFailed')
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  const inputs: Array<[string, FocusableControl | null]> = [
    ['invite_code', inviteInput.value],
    ['name', nameInput.value],
    ['email', emailInput.value],
    ['password', passwordInput.value],
    ['password_confirmation', confirmationInput.value],
  ]

  inputs.find(([field]) => fieldErrors.value[field]?.length)?.[1]?.focus()
}

async function submitRegistration(): Promise<void> {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  fieldErrors.value = {}
  error.value = null

  try {
    await register({ ...form })
    clearPasswords()
    await router.replace(safeRedirect(route.query.redirect))
  } catch (currentError) {
    fieldErrors.value = validationErrors(currentError)
    error.value = failureMessage(currentError)
    clearPasswords()
    await focusFirstError()
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="auth-shell">
    <section class="auth-card">
      <RouterLink class="brand auth-brand" to="/register">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </RouterLink>

      <header class="auth-heading">
        <p class="eyebrow">{{ t('auth.independentWorkspace') }}</p>
        <h1>{{ t('auth.createTitle') }}</h1>
        <p class="muted">{{ t('auth.createBody') }}</p>
      </header>

      <div v-if="error" class="notice error" role="alert" aria-live="assertive">{{ error }}</div>

      <form class="auth-form" novalidate :aria-busy="isSubmitting" @submit.prevent="submitRegistration">
        <UiTextInput
          ref="inviteInput"
          v-model="form.invite_code"
          :label="t('auth.inviteCode')"
          name="invite_code"
          autocomplete="off"
          :maxlength="64"
          required
          :disabled="isSubmitting"
          :helper="t('auth.inviteHelper')"
          :error="fieldErrors.invite_code?.[0]"
        />

        <UiTextInput
          ref="nameInput"
          v-model="form.name"
          :label="t('auth.displayName')"
          name="name"
          autocomplete="name"
          :maxlength="100"
          required
          :disabled="isSubmitting"
          :error="fieldErrors.name?.[0]"
        />

        <UiTextInput
          ref="emailInput"
          v-model="form.email"
          :label="t('auth.email')"
          name="email"
          type="email"
          autocomplete="email"
          :maxlength="255"
          required
          :disabled="isSubmitting"
          :error="fieldErrors.email?.[0]"
        />

        <UiTextInput
          ref="passwordInput"
          v-model="form.password"
          :label="t('auth.password')"
          name="password"
          type="password"
          autocomplete="new-password"
          required
          :disabled="isSubmitting"
          :helper="t('auth.passwordHelper')"
          :error="fieldErrors.password?.[0]"
        />

        <UiTextInput
          ref="confirmationInput"
          v-model="form.password_confirmation"
          :label="t('auth.confirmPassword')"
          name="password_confirmation"
          type="password"
          autocomplete="new-password"
          required
          :disabled="isSubmitting"
          :error="fieldErrors.password_confirmation?.[0]"
        />

        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? t('auth.creating') : t('auth.createAccount') }}
        </button>
      </form>

      <p class="auth-switch muted">
        {{ t('auth.already') }}
        <RouterLink :to="{ name: 'login', query: route.query.redirect ? { redirect: route.query.redirect } : {} }">
          {{ t('auth.signIn') }}
        </RouterLink>
      </p>
    </section>
  </main>
</template>
