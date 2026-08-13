import { createRouter, createWebHistory } from 'vue-router'
import { safeRedirect } from './auth/redirect'
import { restoreSession, useAuthSession } from './auth/session'
import AppShell from './layouts/AppShell.vue'
import AccountView from './views/AccountView.vue'
import BodyView from './views/BodyView.vue'
import StorageView from './views/StorageView.vue'
import ChangelogView from './views/ChangelogView.vue'
import GoalsView from './views/GoalsView.vue'
import HabitsView from './views/HabitsView.vue'
import LoginView from './views/LoginView.vue'
import PlannerView from './views/PlannerView.vue'
import RegisterView from './views/RegisterView.vue'
import ReviewView from './views/ReviewView.vue'
import RoutinesView from './views/RoutinesView.vue'
import TodayView from './views/TodayView.vue'
import AppearanceSettingsView from './views/AppearanceSettingsView.vue'
import NotificationsView from './views/NotificationsView.vue'
import WorkoutsView from './views/WorkoutsView.vue'
import NutritionView from './views/NutritionView.vue'
import SupplementsView from './views/SupplementsView.vue'
import { isAndroidNative } from './mobile/platform'

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
          path: 'habits',
          name: 'habits',
          component: HabitsView,
        },
        {
          path: 'workouts',
          name: 'workouts',
          component: WorkoutsView,
        },
        {
          path: 'nutrition',
          name: 'nutrition',
          component: NutritionView,
        },
        {
          path: 'supplements',
          name: 'supplements',
          component: SupplementsView,
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
          path: 'planner',
          name: 'planner',
          component: PlannerView,
        },
        {
          path: 'storage',
          name: 'storage',
          component: StorageView,
        },
        {
          path: 'body',
          name: 'body',
          component: BodyView,
        },
        {
          path: 'settings/appearance',
          name: 'settings-appearance',
          component: AppearanceSettingsView,
        },
        {
          path: 'notifications',
          name: 'notifications',
          component: NotificationsView,
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

  if (isAndroidNative() && to.name === 'register') {
    return {
      name: 'login',
      query: {
        ...(to.query.redirect ? { redirect: to.query.redirect } : {}),
        mobileRegistration: '1',
      },
    }
  }

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
