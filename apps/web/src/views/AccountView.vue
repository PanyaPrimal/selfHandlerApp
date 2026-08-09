<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ApiError } from '../api/http'
import { logout, useAuthSession } from '../auth/session'

const router = useRouter()
const session = useAuthSession()
const isSubmitting = ref(false)
const error = ref<string | null>(null)
const userInitial = computed(() => session.user?.name.trim().charAt(0).toUpperCase() || '?')

async function signOut(): Promise<void> {
  if (isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  error.value = null

  try {
    await logout()
    await router.replace({ name: 'login' })
  } catch (currentError) {
    if (currentError instanceof ApiError && currentError.status === 419) {
      error.value = 'Your secure session changed. Please try signing out again.'
    } else {
      error.value = 'Could not sign out. Check the service and try again.'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <section class="view-stack">
    <header class="view-header">
      <div>
        <p class="eyebrow">Account</p>
        <h1>Your account</h1>
      </div>
    </header>

    <div v-if="error" class="notice error" role="alert" aria-live="assertive">{{ error }}</div>

    <section v-if="session.user" class="panel account-panel">
      <div class="account-identity">
        <span class="account-avatar" aria-hidden="true">{{ userInitial }}</span>
        <div>
          <h2>{{ session.user.name }}</h2>
          <p class="muted">{{ session.user.email }}</p>
        </div>
      </div>

      <p class="muted">This browser is signed in to this personal workspace only.</p>
      <button type="button" class="account-signout" :disabled="isSubmitting" @click="signOut">
        {{ isSubmitting ? 'Signing out...' : 'Sign out' }}
      </button>
    </section>
  </section>
</template>
