import { createRouter, createWebHistory } from 'vue-router'
import { safeRedirect } from './auth/redirect'
import { restoreSession, useAuthSession } from './auth/session'
import AppShell from './layouts/AppShell.vue'
import AccountView from './views/AccountView.vue'
import ChangelogView from './views/ChangelogView.vue'
import GoalsView from './views/GoalsView.vue'
import LoginView from './views/LoginView.vue'
import RegisterView from './views/RegisterView.vue'
import ReviewView from './views/ReviewView.vue'
import RoutinesView from './views/RoutinesView.vue'
import TodayView from './views/TodayView.vue'

declare module 'vue-router' {
  interface RouteMeta {
    guestOnly?: boolean
    requiresAuth?: boolean
  }
}

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      name: 'register',
      component: RegisterView,
      meta: { guestOnly: true },
    },
    {
      path: '/',
      component: AppShell,
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'today',
          component: TodayView,
        },
        {
          path: 'routines',
          name: 'routines',
          component: RoutinesView,
        },
        {
          path: 'goals',
          name: 'goals',
          component: GoalsView,
        },
        {
          path: 'review/:date?',
          name: 'review',
          component: ReviewView,
        },
        {
          path: 'account',
          name: 'account',
          component: AccountView,
        },
        {
          path: 'changelog',
          name: 'changelog',
          component: ChangelogView,
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/',
    },
  ],
})

router.beforeEach(async (to) => {
  await restoreSession()
  const session = useAuthSession()

  // The backend could not be reached to confirm the session. App.vue renders a
  // dedicated "unavailable" screen with a Retry button in this state, so let the
  // navigation proceed and be handled there rather than routing on stale status.
  if (session.status === 'unavailable') {
    return true
  }

  if (to.meta.requiresAuth && session.status !== 'authenticated') {
    return {
      name: 'login',
      query: { redirect: to.fullPath },
    }
  }

  if (to.meta.guestOnly && session.status === 'authenticated') {
    return safeRedirect(to.query.redirect)
  }

  return true
})
