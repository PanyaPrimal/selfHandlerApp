<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { LoginPayload } from '../api/types'
import { safeRedirect } from '../auth/redirect'
import { login, restoreSession, useAuthSession } from '../auth/session'
import { UiTextInput } from '../components/ui'
import { useI18n } from '../i18n'

type FocusableControl = { focus: () => void }

const route = useRoute()
const router = useRouter()
const form = reactive<LoginPayload>({
  email: '',
  password: '',
})
const fieldErrors = ref<ValidationErrors>({})
const error = ref<string | null>(null)
const isSubmitting = ref(false)
const emailInput = ref<FocusableControl | null>(null)
const passwordInput = ref<FocusableControl | null>(null)
const { t } = useI18n()

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
    return t('auth.loginInvalid')
  }

  return t('auth.loginFailed')
}

async function focusFirstError(): Promise<void> {
  await nextTick()

  if (fieldErrors.value.email?.length) {
    emailInput.value?.focus()
    return
  }

  passwordInput.value?.focus()
}

async function submitLogin(): Promise<void> {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  fieldErrors.value = {}
  error.value = null

  try {
    await login({ ...form })
    form.password = ''
    await router.replace(safeRedirect(route.query.redirect))
  } catch (currentError) {
    // 409 = this browser already has a live session. Instead of asking the user
    // to reload manually, restore the session and continue to the workspace.
    if (currentError instanceof ApiError && currentError.status === 409) {
      form.password = ''
      await restoreSession(true)
      if (useAuthSession().status === 'authenticated') {
        await router.replace(safeRedirect(route.query.redirect))
        return
      }
    }

    fieldErrors.value = validationErrors(currentError)
    error.value = failureMessage(currentError)
    form.password = ''
    await focusFirstError()
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <main class="auth-shell">
    <section class="auth-card">
      <RouterLink class="brand auth-brand" to="/login">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </RouterLink>

      <header class="auth-heading">
        <p class="eyebrow">{{ t('auth.personalWorkspace') }}</p>
        <h1>{{ t('auth.welcomeBack') }}</h1>
        <p class="muted">{{ t('auth.signInBody') }}</p>
      </header>

      <div v-if="error" class="notice error" role="alert" aria-live="assertive">{{ error }}</div>

      <form class="auth-form" novalidate :aria-busy="isSubmitting" @submit.prevent="submitLogin">
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
          autocomplete="current-password"
          required
          :disabled="isSubmitting"
          :error="fieldErrors.password?.[0]"
        />

        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? t('auth.signingIn') : t('auth.signIn') }}
        </button>
      </form>

      <p class="auth-switch muted">
        {{ t('auth.new') }}
        <RouterLink :to="{ name: 'register', query: route.query.redirect ? { redirect: route.query.redirect } : {} }">
          {{ t('auth.createAccount') }}
        </RouterLink>
      </p>
    </section>
  </main>
</template>
