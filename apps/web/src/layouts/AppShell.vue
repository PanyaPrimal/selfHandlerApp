<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink, RouterView } from 'vue-router'
import { useAuthSession } from '../auth/session'

const session = useAuthSession()
const userInitial = computed(() => session.user?.name.trim().charAt(0).toUpperCase() || '?')
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <RouterLink class="brand" to="/">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </RouterLink>
      <nav class="nav-list" aria-label="Primary navigation">
        <RouterLink to="/">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Today</span>
        </RouterLink>
        <RouterLink to="/routines">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Routines</span>
        </RouterLink>
        <RouterLink to="/goals">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Goals</span>
        </RouterLink>
        <RouterLink to="/review">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Review</span>
        </RouterLink>
        <RouterLink to="/account">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>Account</span>
        </RouterLink>
      </nav>

      <RouterLink
        v-if="session.user"
        class="user-pill"
        to="/account"
        :aria-label="`Open account for ${session.user.name}`"
      >
        <span>{{ userInitial }}</span>
        <div>
          <strong>{{ session.user.name }}</strong>
          <small>{{ session.user.email }}</small>
        </div>
      </RouterLink>
    </aside>

    <main class="content-shell">
      <RouterView v-slot="{ Component }">
        <component :is="Component" :key="session.generation" />
      </RouterView>
    </main>
  </div>
</template>
