<script setup lang="ts">
import { nextTick, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { RegisterPayload } from '../api/types'
import { safeRedirect } from '../auth/redirect'
import { register } from '../auth/session'
import { UiTextInput } from '../components/ui'

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

function clearPasswords(): void {
  form.password = ''
  form.password_confirmation = ''
}

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
    return 'Please correct the highlighted fields and try again.'
  }

  return 'Your account could not be created. Please try again.'
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
        <p class="eyebrow">Independent workspace</p>
        <h1>Create your account</h1>
        <p class="muted">Your routines, goals, and reviews stay separate from every other account.</p>
      </header>

      <div v-if="error" class="notice error" role="alert" aria-live="assertive">{{ error }}</div>

      <form class="auth-form" novalidate :aria-busy="isSubmitting" @submit.prevent="submitRegistration">
        <UiTextInput
          ref="inviteInput"
          v-model="form.invite_code"
          label="Invite code"
          name="invite_code"
          autocomplete="off"
          :maxlength="64"
          required
          :disabled="isSubmitting"
          helper="Registration is invite-only. Enter the code you were given."
          :error="fieldErrors.invite_code?.[0]"
        />

        <UiTextInput
          ref="nameInput"
          v-model="form.name"
          label="Display name"
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
          autocomplete="new-password"
          required
          :disabled="isSubmitting"
          helper="Use at least 12 characters."
          :error="fieldErrors.password?.[0]"
        />

        <UiTextInput
          ref="confirmationInput"
          v-model="form.password_confirmation"
          label="Confirm password"
          name="password_confirmation"
          type="password"
          autocomplete="new-password"
          required
          :disabled="isSubmitting"
          :error="fieldErrors.password_confirmation?.[0]"
        />

        <button type="submit" :disabled="isSubmitting">
          {{ isSubmitting ? 'Creating account...' : 'Create account' }}
        </button>
      </form>

      <p class="auth-switch muted">
        Already have an account?
        <RouterLink :to="{ name: 'login', query: route.query.redirect ? { redirect: route.query.redirect } : {} }">
          Sign in
        </RouterLink>
      </p>
    </section>
  </main>
</template>
