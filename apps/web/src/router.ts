import { createRouter, createWebHistory } from 'vue-router'
import GoalsView from './views/GoalsView.vue'
import ReviewView from './views/ReviewView.vue'
import RoutinesView from './views/RoutinesView.vue'
import TodayView from './views/TodayView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'today',
      component: TodayView,
    },
    {
      path: '/routines',
      name: 'routines',
      component: RoutinesView,
    },
    {
      path: '/goals',
      name: 'goals',
      component: GoalsView,
    },
    {
      path: '/review/:date?',
      name: 'review',
      component: ReviewView,
    },
  ],
})
