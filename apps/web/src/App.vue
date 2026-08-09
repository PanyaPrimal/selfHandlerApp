<script setup lang="ts">
import { ref } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { restoreSession, useAuthSession } from './auth/session'

const session = useAuthSession()
const route = useRoute()
const router = useRouter()
const isRetrying = ref(false)

async function retrySession(): Promise<void> {
  if (isRetrying.value) {
    return
  }

  isRetrying.value = true

  try {
    await restoreSession(true)

    if (session.status === 'guest' && route.meta.requiresAuth) {
      await router.replace({
        name: 'login',
        query: { redirect: route.fullPath },
      })
    }
  } finally {
    isRetrying.value = false
  }
}
</script>

<template>
  <main v-if="session.status === 'checking'" class="auth-shell" aria-live="polite" aria-busy="true">
    <section class="auth-card startup-card">
      <span class="brand auth-brand">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </span>
      <div class="skeleton-line" style="width: 48%"></div>
      <div class="skeleton-line" style="width: 82%"></div>
      <p class="muted">Restoring your session...</p>
    </section>
  </main>

  <main v-else-if="session.status === 'unavailable'" class="auth-shell" aria-live="assertive">
    <section class="auth-card startup-card">
      <span class="brand auth-brand">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </span>
      <div class="state-icon startup-error" aria-hidden="true">!</div>
      <h1>SelfHandler is unavailable</h1>
      <p class="muted">We could not confirm your session. Check the service and try again.</p>
      <button type="button" :disabled="isRetrying" @click="retrySession">
        {{ isRetrying ? 'Retrying...' : 'Retry' }}
      </button>
    </section>
  </main>

  <main
    v-else-if="session.status === 'guest' && route.meta.requiresAuth"
    class="auth-shell"
    aria-live="polite"
  >
    <p class="muted">Returning to sign in...</p>
  </main>

  <RouterView v-else />
</template>
