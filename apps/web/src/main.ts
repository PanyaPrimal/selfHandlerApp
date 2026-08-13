import { createApp } from 'vue'
import App from './App.vue'
import { setUnauthorizedHandler } from './api/http'
import { expireSession } from './auth/session'
import { router } from './router'
import './style.css'
import { initializeTheme } from './theme'
import { initializeLocale } from './i18n'
import { isAndroidNative } from './mobile/platform'
import { initializeMobileRuntime } from './mobile/runtime'

initializeLocale()
initializeTheme()

setUnauthorizedHandler(async () => {
  const currentRoute = router.currentRoute.value
  const redirect = currentRoute.meta.requiresAuth ? currentRoute.fullPath : '/'

  await expireSession()

  if (currentRoute.name !== 'login') {
    void router.replace({
      name: 'login',
      query: redirect === '/' ? {} : { redirect },
    })
  }
})

createApp(App).use(router).mount('#app')

if (isAndroidNative()) {
  void initializeMobileRuntime(router)
}
