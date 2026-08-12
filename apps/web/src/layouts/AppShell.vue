<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'
import { useAuthSession } from '../auth/session'
import UiPopoverSurface from '../components/ui/UiPopoverSurface.vue'
import { useAnchoredSurface } from '../components/ui/useAnchoredSurface'

interface Destination {
  name: string
  to: string
  label: string
}

/**
 * Four primary destinations are the daily loop and stay as tabs on a phone; the
 * rest live behind "More". Squeezing every destination into one 390px row would
 * drop each tab below a comfortable touch target and truncate its label.
 */
const primaryDestinations: Destination[] = [
  { name: 'today', to: '/', label: 'Today' },
  { name: 'routines', to: '/routines', label: 'Routines' },
  { name: 'goals', to: '/goals', label: 'Goals' },
  { name: 'review', to: '/review', label: 'Review' },
]

const secondaryDestinations: Destination[] = [
  { name: 'body', to: '/body', label: 'Body' },
  { name: 'account', to: '/account', label: 'Account' },
  { name: 'changelog', to: '/changelog', label: 'Changelog' },
]

const route = useRoute()
const session = useAuthSession()
const moreButton = ref<HTMLElement | null>(null)
const userInitial = computed(() => session.user?.name.trim().charAt(0).toUpperCase() || '?')

const secondaryIsActive = computed(() =>
  secondaryDestinations.some((destination) => destination.name === route.name),
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
</script>

<template>
  <div class="app-shell">
    <aside class="sidebar">
      <RouterLink class="brand" to="/">
        <span class="brand-mark" aria-hidden="true"></span>
        <span>SELFHANDLER</span>
      </RouterLink>

      <nav class="nav-list nav-list--desktop" aria-label="Primary navigation">
        <RouterLink
          v-for="destination in [...primaryDestinations, ...secondaryDestinations]"
          :key="destination.name"
          :to="destination.to"
        >
          <span class="nav-dot" aria-hidden="true"></span>
          <span>{{ destination.label }}</span>
        </RouterLink>
      </nav>

      <nav class="nav-list nav-list--compact" aria-label="Primary navigation">
        <RouterLink v-for="destination in primaryDestinations" :key="destination.name" :to="destination.to">
          <span class="nav-dot" aria-hidden="true"></span>
          <span>{{ destination.label }}</span>
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
            <span>More</span>
          </button>

          <UiPopoverSurface
            id="nav-more-menu"
            :open="more.isOpen.value"
            :surface-style="more.surfaceStyle.value"
            :bind-ref="(element) => { more.surfaceRef.value = element }"
            role="menu"
            aria-label="More destinations"
            class="nav-more__menu"
          >
            <RouterLink
              v-for="destination in secondaryDestinations"
              :key="destination.name"
              class="nav-more__item"
              role="menuitem"
              :to="destination.to"
              @click="goToSecondary"
            >
              {{ destination.label }}
            </RouterLink>
          </UiPopoverSurface>
        </div>
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
