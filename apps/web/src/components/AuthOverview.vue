<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from '../i18n'

const { t } = useI18n()
const modules = computed(() => [
  { name: 'planning', title: t('auth.planning'), body: t('auth.planningBody'), icon: 'calendar' },
  { name: 'health', title: t('auth.health'), body: t('auth.healthBody'), icon: 'heart' },
  { name: 'finance', title: t('auth.finance'), body: t('auth.financeBody'), icon: 'wallet' },
  { name: 'library', title: t('auth.library'), body: t('auth.libraryBody'), icon: 'folder' },
  { name: 'progress', title: t('auth.progress'), body: t('auth.progressBody'), icon: 'chart' },
] as const)
</script>

<template>
  <aside class="auth-overview" aria-labelledby="auth-overview-title">
    <h2 id="auth-overview-title">{{ t('auth.overview') }}</h2>
    <ul class="auth-modules">
      <li v-for="module in modules" :key="module.name">
        <svg class="auth-module-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <g v-if="module.icon === 'calendar'"><rect x="4" y="5" width="16" height="16" rx="3" /><path d="M8 3v4m8-4v4M4 11h16m-11 4h2m3 0h2m-7 3h2" /></g>
          <path v-else-if="module.icon === 'heart'" d="M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8-1c-4 4 1 10 8 15 7-5 12-11 8-15Z" />
          <g v-else-if="module.icon === 'wallet'"><rect x="3" y="6" width="18" height="15" rx="3" /><path d="M4 6V4a1 1 0 0 1 1-1h12v3m4 6h-5v5h5m-2-2.5h.1" /></g>
          <path v-else-if="module.icon === 'folder'" d="M3 8V5a2 2 0 0 1 2-2h5l3 4h6a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8Z" />
          <path v-else d="M4 3v18h17M8 16v-4m5 4V8m5 8V5" />
        </svg>
        <div><h3>{{ module.title }}</h3><p>{{ module.body }}</p></div>
      </li>
    </ul>
  </aside>
</template>
