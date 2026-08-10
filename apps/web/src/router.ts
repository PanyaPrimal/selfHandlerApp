import { createRouter, createWebHistory } from 'vue-router'
import { safeRedirect } from './auth/redirect'
import { restoreSession, useAuthSession } from './auth/session'
import AppShell from './layouts/AppShell.vue'
import AccountView from './views/AccountView.vue'
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
  let session = useAuthSession()

  // A single failed/timed-out session probe leaves the status 'unavailable'.
  // Before routing on that, retry once so a transient network blip over the
  // Funnel does not strand an authenticated user on the login screen.
  if (session.status === 'unavailable') {
    await restoreSession(true)
    session = useAuthSession()
  }

  // Still unavailable: the backend is genuinely unreachable. Send the visitor
  // to the login screen (which surfaces the connection error) rather than
  // silently rendering a protected page that cannot load its data.
  if (session.status === 'unavailable') {
    return to.name === 'login' ? true : { name: 'login' }
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
