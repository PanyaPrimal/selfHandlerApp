<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthSession } from '../auth/session'
import UiPopoverSurface from '../components/ui/UiPopoverSurface.vue'
import { useAnchoredSurface } from '../components/ui/useAnchoredSurface'
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
import { useNotificationStore } from '../notifications/store'

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
  { name: 'goals', to: '/goals', label: 'nav.goals' },
  { name: 'review', to: '/review', label: 'nav.review' },
  { name: 'planner', to: '/planner', label: 'nav.planner' },
  { name: 'storage', to: '/storage', label: 'nav.storage' },
  { name: 'body', to: '/body', label: 'nav.body' },
]

const utilityDestinations: Destination[] = [
  { name: 'notifications', to: '/notifications', label: 'nav.notifications' },
  { name: 'settings-appearance', to: '/settings/appearance', label: 'nav.settings' },
  { name: 'account', to: '/account', label: 'nav.account' },
  { name: 'changelog', to: '/changelog', label: 'nav.changelog' },
]

const mobileDestinations = desktopDestinations.slice(0, 3)
const moreDestinations = [...desktopDestinations.slice(3), ...utilityDestinations]

const route = useRoute()
const session = useAuthSession()
const moreButton = ref<HTMLElement | null>(null)
const { t } = useI18n()
const notifications = useNotificationStore()
const userInitial = computed(() => session.user?.name.trim().charAt(0).toUpperCase() || '?')

const secondaryIsActive = computed(() =>
  moreDestinations.some((destination) => destination.name === route.name),
)

const more = useAnchoredSurface({
  placement: 'top-end',
  gap: 10,
  maxHeight: 260,
  focusTarget: () => moreButton.value,
})

function goToSecondary(): void {
  more.close({ restoreFocus: false })
}

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
        <div class="nav-group">
          <RouterLink
            v-for="destination in desktopDestinations"
            :key="destination.name"
            :to="destination.to"
          >
            <span class="nav-dot" aria-hidden="true"></span>
            <span>{{ t(destination.label) }}</span>
          </RouterLink>
        </div>
        <div class="nav-group nav-group--utility">
          <RouterLink
            v-for="destination in utilityDestinations"
            :key="destination.name"
            :to="destination.to"
          >
            <span class="nav-dot" aria-hidden="true"></span>
            <span>{{ t(destination.label) }}</span>
            <span
              v-if="destination.name === 'notifications' && notifications.state.unreadCount > 0"
              class="notification-badge"
              data-testid="notification-unread-count"
              aria-hidden="true"
            >{{ notifications.state.unreadCount }}</span>
          </RouterLink>
        </div>
      </nav>

      <nav class="nav-list nav-list--compact" :aria-label="t('nav.primary')">
        <RouterLink v-for="destination in mobileDestinations" :key="destination.name" :to="destination.to">
          <span class="nav-dot" aria-hidden="true"></span>
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
            <span class="nav-dot" aria-hidden="true"></span>
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
            <RouterLink
              v-for="destination in moreDestinations"
              :key="destination.name"
              class="nav-more__item"
              role="menuitem"
              :to="destination.to"
              @click="goToSecondary"
            >
              {{ t(destination.label) }}
              <span
                v-if="destination.name === 'notifications' && notifications.state.unreadCount > 0"
                class="notification-badge"
                aria-hidden="true"
              >{{ notifications.state.unreadCount }}</span>
            </RouterLink>
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
      <RouterView v-slot="{ Component }">
        <component :is="Component" :key="session.generation" />
      </RouterView>
    </main>
  </div>
</template>
