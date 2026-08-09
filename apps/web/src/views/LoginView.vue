<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { LoginPayload } from '../api/types'
import { safeRedirect } from '../auth/redirect'
import { login } from '../auth/session'

const route = useRoute()
const router = useRouter()
const form = reactive<LoginPayload>({
  email: '',
  password: '',
})
const fieldErrors = ref<ValidationErrors>({})
const error = ref<string | null>(null)
const isSubmitting = ref(false)
const emailInput = ref<HTMLInputElement | null>(null)
const passwordInput = ref<HTMLInputElement | null>(null)

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
        <label class="field">
          <span>Email</span>
          <input
            ref="emailInput"
            v-model="form.email"
            name="email"
            type="email"
            autocomplete="email"
            maxlength="255"
            required
            :disabled="isSubmitting"
            :aria-invalid="Boolean(fieldErrors.email?.length)"
            :aria-describedby="fieldErrors.email?.length ? 'login-email-error' : undefined"
          />
          <small v-if="fieldErrors.email?.length" id="login-email-error" class="field-error">
            {{ fieldErrors.email[0] }}
          </small>
        </label>

        <label class="field">
          <span>Password</span>
          <input
            ref="passwordInput"
            v-model="form.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            :disabled="isSubmitting"
            :aria-invalid="Boolean(fieldErrors.password?.length)"
            :aria-describedby="fieldErrors.password?.length ? 'login-password-error' : undefined"
          />
          <small v-if="fieldErrors.password?.length" id="login-password-error" class="field-error">
            {{ fieldErrors.password[0] }}
          </small>
        </label>

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
