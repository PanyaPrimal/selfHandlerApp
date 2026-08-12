<script setup lang="ts">
import { ref } from 'vue'
import { RouterView, useRoute, useRouter } from 'vue-router'
import { restoreSession, useAuthSession } from './auth/session'
import GlobalPreferences from './components/GlobalPreferences.vue'
import { useI18n } from './i18n'

const session = useAuthSession()
const route = useRoute()
const router = useRouter()
const isRetrying = ref(false)
const { t } = useI18n()

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
  <GlobalPreferences />
  <main v-if="session.status === 'checking'" class="auth-shell" aria-live="polite" aria-busy="true">
    <section class="auth-card startup-card">
      <span class="brand auth-brand">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </span>
      <div class="skeleton-line" style="width: 48%"></div>
      <div class="skeleton-line" style="width: 82%"></div>
      <p class="muted">{{ t('app.restoringSession') }}</p>
    </section>
  </main>

  <main v-else-if="session.status === 'unavailable'" class="auth-shell" aria-live="assertive">
    <section class="auth-card startup-card">
      <span class="brand auth-brand">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </span>
      <div class="state-icon startup-error" aria-hidden="true">!</div>
      <h1>{{ t('app.unavailableTitle') }}</h1>
      <p class="muted">{{ t('app.unavailableBody') }}</p>
      <button type="button" :disabled="isRetrying" @click="retrySession">
        {{ isRetrying ? t('app.retrying') : t('common.retry') }}
      </button>
    </section>
  </main>

  <main
    v-else-if="session.status === 'guest' && route.meta.requiresAuth"
    class="auth-shell"
    aria-live="polite"
  >
    <p class="muted">{{ t('app.returningToSignIn') }}</p>
  </main>

  <RouterView v-else />
</template>
