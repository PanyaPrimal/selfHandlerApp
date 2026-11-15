<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthSession } from '../auth/session'
import UiPopoverSurface from '../components/ui/UiPopoverSurface.vue'
import { useAnchoredSurface } from '../components/ui/useAnchoredSurface'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
import { useNotificationStore } from '../notifications/store'
import WorkspaceStatus from '../components/WorkspaceStatus.vue'
import AppIcon from '../components/ui/AppIcon.vue'

interface Destination {
  name: string
  to: string
  label: MessageKey
}

/**
 * Three daily-loop destinations stay direct on a phone and "More" is the fourth
 * tab. Squeezing every destination into one 390px row would drop each tab below
 * a comfortable touch target and truncate its label.
 */
const desktopDestinations: Destination[] = [
  { name: 'today', to: '/', label: 'nav.today' },
  { name: 'routines', to: '/routines', label: 'nav.routines' },
  { name: 'habits', to: '/habits', label: 'nav.habits' },
  { name: 'workouts', to: '/workouts', label: 'nav.workouts' },
  { name: 'nutrition', to: '/nutrition', label: 'nav.nutrition' },
  { name: 'supplements', to: '/supplements', label: 'nav.supplements' },
  { name: 'finance', to: '/finance', label: 'nav.finance' },
  { name: 'goals', to: '/goals', label: 'nav.goals' },
  { name: 'review', to: '/review', label: 'nav.review' },
  { name: 'analytics', to: '/analytics', label: 'nav.analytics' },
  { name: 'planner', to: '/planner', label: 'nav.planner' },
  { name: 'storage', to: '/storage', label: 'nav.storage' },
  { name: 'lists', to: '/lists', label: 'nav.lists' },
  { name: 'body', to: '/body', label: 'nav.body' },
]

const utilityDestinations: Destination[] = [
  { name: 'mentor', to: '/mentor', label: 'mentor.title' },
  { name: 'notifications', to: '/notifications', label: 'nav.notifications' },
  { name: 'settings-appearance', to: '/settings/appearance', label: 'nav.settings' },
  { name: 'settings-data', to: '/settings/data', label: 'nav.data' },
  { name: 'settings-integrations', to: '/settings/integrations', label: 'nav.integrations' },
  { name: 'settings-ai', to: '/settings/ai', label: 'nav.ai' },
  { name: 'account', to: '/account', label: 'nav.account' },
  { name: 'changelog', to: '/changelog', label: 'nav.changelog' },
]

const dailyNames = ['today', 'planner', 'storage']
const mobileDestinations = dailyNames.map((name) => desktopDestinations.find((item) => item.name === name)!)
const destinationGroups: { label: MessageKey; items: Destination[] }[] = [
  { label: 'nav.daily', items: [...mobileDestinations, desktopDestinations.find((item) => item.name === 'lists')!] },
  { label: 'nav.health', items: desktopDestinations.filter((item) => ['routines', 'habits', 'workouts', 'nutrition', 'supplements', 'body'].includes(item.name)) },
  { label: 'nav.direction', items: desktopDestinations.filter((item) => ['goals', 'finance', 'review', 'analytics'].includes(item.name)) },
  { label: 'nav.workspace', items: utilityDestinations },
]
// Daily destinations that are not phone tabs still need a way in through "More".
const moreGroups = destinationGroups
  .map((group) => ({ ...group, items: group.items.filter((item) => !dailyNames.includes(item.name)) }))
  .filter((group) => group.items.length)
const moreDestinations = moreGroups.flatMap((group) => group.items)

const route = useRoute()
const session = useAuthSession()
const moreButton = ref<HTMLElement | null>(null)
const { t } = useI18n()
const notifications = useNotificationStore()
const userInitial = computed(() => session.user?.name.trim().charAt(0).toUpperCase() || '?')
const navSearch = ref('')
const desktopSearch = ref<HTMLInputElement | null>(null)
const mobileSearch = ref<HTMLInputElement | null>(null)
const filteredGroups = computed(() => destinationGroups.map(group => ({
  ...group,
  items: group.items.filter(item => `${t(item.label)} ${item.name}`.toLocaleLowerCase().includes(navSearch.value.trim().toLocaleLowerCase())),
})).filter(group => group.items.length))
const filteredMoreGroups = computed(() => filteredGroups.value
  .map(group => ({ ...group, items: group.items.filter(item => !dailyNames.includes(item.name)) }))
  .filter(group => group.items.length))

function isDestinationActive(destination: Destination): boolean {
  return route.path === destination.to || (destination.to !== '/' && route.path.startsWith(`${destination.to}/`))
}

const secondaryIsActive = computed(() =>
  moreDestinations.some(isDestinationActive),
)

const more = useAnchoredSurface({
  placement: 'top-end',
  gap: 10,
  maxHeight: 440,
  focusTarget: () => moreButton.value,
})

function goToSecondary(): void {
  more.close({ restoreFocus: false })
}

function focusNavigation(event: KeyboardEvent): void {
  if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k') return
  event.preventDefault()
  if (window.matchMedia('(min-width: 761px)').matches) {
    desktopSearch.value?.focus()
  } else {
    more.open()
    more.whenPositioned(() => {
      void nextTick(() => { if (more.isOpen.value) mobileSearch.value?.focus() })
    })
  }
}

watch(() => route.fullPath, () => { navSearch.value = '' })
onMounted(() => window.addEventListener('keydown', focusNavigation))
onBeforeUnmount(() => window.removeEventListener('keydown', focusNavigation))

onMounted(notifications.start)
onBeforeUnmount(notifications.stop)
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <span
        v-if="notifications.state.unreadCount > 0"
        class="visually-hidden"
        role="status"
        aria-live="polite"
      >{{ t('notifications.unreadCount', { count: notifications.state.unreadCount }) }}</span>
      <RouterLink class="brand" to="/">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </RouterLink>

      <nav class="nav-list nav-list--desktop" :aria-label="t('nav.primary')">
        <label class="nav-search"><AppIcon name="search" /><input ref="desktopSearch" v-model="navSearch" type="search" :aria-label="t('ease.findSection')" :placeholder="t('ease.findSection')" autocomplete="off" /><kbd v-if="!navSearch">{{ t('ease.searchShortcut') }}</kbd></label>
        <p v-if="!filteredGroups.length" class="nav-no-results" role="status">{{ t('ease.noSections') }}</p>
        <details v-for="group in filteredGroups" :key="group.label" class="nav-group" :open="Boolean(navSearch) || ['nav.daily', 'nav.health'].includes(group.label) || group.items.some(isDestinationActive)">
          <summary class="nav-group__label">{{ t(group.label) }}</summary>
          <RouterLink
            v-for="destination in group.items"
            :key="destination.name"
            :to="destination.to"
            :class="{ 'is-current': isDestinationActive(destination) }"
            :aria-current="isDestinationActive(destination) ? 'page' : undefined"
          >
            <AppIcon :name="destination.name" />
            <span>{{ t(destination.label) }}</span>
            <span
              v-if="destination.name === 'notifications' && notifications.state.unreadCount > 0"
              class="notification-badge"
              data-testid="notification-unread-count"
              aria-hidden="true"
            >{{ notifications.state.unreadCount }}</span>
          </RouterLink>
        </details>
      </nav>

      <nav class="nav-list nav-list--compact" :aria-label="t('nav.primary')">
        <RouterLink v-for="destination in mobileDestinations" :key="destination.name" :to="destination.to">
          <AppIcon :name="destination.name" />
          <span>{{ t(destination.label) }}</span>
        </RouterLink>

        <div :ref="(element) => { more.anchorRef.value = element as HTMLElement | null }" class="nav-more">
          <button
            ref="moreButton"
            type="button"
            class="nav-more__button"
            :class="{ 'is-active': secondaryIsActive }"
            aria-haspopup="menu"
            :aria-expanded="more.isOpen.value"
            aria-controls="nav-more-menu"
            :aria-current="secondaryIsActive ? 'page' : undefined"
            @click="more.toggle()"
          >
            <AppIcon name="more" />
            <span>{{ t('nav.more') }}</span>
            <span
              v-if="notifications.state.unreadCount > 0"
              class="notification-badge"
              data-testid="notification-unread-count"
              aria-hidden="true"
            >{{ notifications.state.unreadCount }}</span>
          </button>

          <UiPopoverSurface
            id="nav-more-menu"
            :open="more.isOpen.value"
            :surface-style="more.surfaceStyle.value"
            :bind-ref="(element) => { more.surfaceRef.value = element }"
            role="menu"
            :aria-label="t('nav.moreDestinations')"
            class="nav-more__menu"
          >
            <label class="nav-search nav-search--mobile"><AppIcon name="search" /><input ref="mobileSearch" v-model="navSearch" type="search" :aria-label="t('ease.findSection')" :placeholder="t('ease.findSection')" autocomplete="off" /></label>
            <p v-if="!filteredMoreGroups.length" class="nav-no-results" role="status">{{ t('ease.noSections') }}</p>
            <div v-for="group in filteredMoreGroups" :key="group.label" role="group" :aria-label="t(group.label)">
              <p class="nav-group__label">{{ t(group.label) }}</p>
              <RouterLink
              v-for="destination in group.items"
              :key="destination.name"
              class="nav-more__item"
              role="menuitem"
              :to="destination.to"
              :aria-current="isDestinationActive(destination) ? 'page' : undefined"
              @click="goToSecondary"
            >
              <AppIcon :name="destination.name" />{{ t(destination.label) }}
              <span
                v-if="destination.name === 'notifications' && notifications.state.unreadCount > 0"
                class="notification-badge"
                aria-hidden="true"
              >{{ notifications.state.unreadCount }}</span>
            </RouterLink>
            </div>
          </UiPopoverSurface>
        </div>
      </nav>

      <RouterLink
        v-if="session.user"
        class="user-pill"
        to="/account"
        :aria-label="t('nav.openAccount', { name: session.user.name })"
      >
        <span>{{ userInitial }}</span>
        <div>
          <strong>{{ session.user.name }}</strong>
          <small>{{ session.user.email }}</small>
        </div>
      </RouterLink>
    </aside>

    <main class="content-shell">
      <WorkspaceStatus :key="session.generation" />
      <RouterView v-slot="{ Component }">
        <component :is="Component" :key="session.generation" />
      </RouterView>
    </main>
  </div>
</template>
