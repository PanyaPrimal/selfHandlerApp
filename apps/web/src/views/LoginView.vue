<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { LoginPayload } from '../api/types'
import { safeRedirect } from '../auth/redirect'
import { login, restoreSession, useAuthSession } from '../auth/session'
import { UiTextInput } from '../components/ui'

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

function failureMessage(currentError: unknown): string {
  if (!(currentError instanceof ApiError)) {
    return 'Something went wrong. Please try again.'
  }

  if (currentError.status === 0 || currentError.status >= 500) {
    return 'SelfHandler could not be reached. Check the service and try again.'
  }

  if (currentError.status === 419) {
    return 'Your secure form session expired. Please try again.'
  }

  if (currentError.status === 429) {
    return currentError.retryAfter
      ? `Too many attempts. Try again in ${currentError.retryAfter} seconds.`
      : 'Too many attempts. Please wait and try again.'
  }

  if (currentError.status === 409) {
    return 'This browser is already signed in. Reload to continue to the workspace.'
  }

  if (currentError.status === 422) {
    return 'The email or password is incorrect.'
  }

  return 'Sign in failed. Please try again.'
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
        <p class="eyebrow">Personal workspace</p>
        <h1>Welcome back</h1>
        <p class="muted">Sign in to continue with your routines, goals, and daily review.</p>
      </header>

      <div v-if="error" class="notice error" role="alert" aria-live="assertive">{{ error }}</div>

      <form class="auth-form" novalidate :aria-busy="isSubmitting" @submit.prevent="submitLogin">
        <UiTextInput
          ref="emailInput"
          v-model="form.email"
          label="Email"
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
          label="Password"
          name="password"
          type="password"
          autocomplete="current-password"
          required
          :disabled="isSubmitting"
          :error="fieldErrors.password?.[0]"
        />

        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Signing in...' : 'Sign in' }}
        </button>
      </form>

      <p class="auth-switch muted">
        New to SelfHandler?
        <RouterLink :to="{ name: 'register', query: route.query.redirect ? { redirect: route.query.redirect } : {} }">
          Create account
        </RouterLink>
      </p>
    </section>
  </main>
</template>
