<script setup lang="ts">
import { computed, ref } from 'vue'
import { updatePreferences } from '../api/client'
import type { ProfileLocale, ThemePreferences } from '../api/types'
import { updateAuthenticatedUser, useAuthSession } from '../auth/session'
import { applyLocale, normalizeLocale, useI18n } from '../i18n'
import { applyTheme, resolvedScheme, useTheme } from '../theme'

const session = useAuthSession()
const i18n = useI18n()
const theme = useTheme()
const notice = ref<string | null>(null)
let localeSequence = 0
let themeSequence = 0

const localeChoices: Array<{ code: 'EN' | 'RU' | 'UK', value: ProfileLocale }> = [
  { code: 'EN', value: 'en-GB' },
  { code: 'RU', value: 'ru-UA' },
  { code: 'UK', value: 'uk-UA' },
]

const resolved = computed(() => resolvedScheme(theme.preferences as ThemePreferences, theme.systemIsDark.value))
const toggleLabel = computed(() => i18n.t(resolved.value === 'dark' ? 'global.switchLight' : 'global.switchDark'))

function localeName(locale: ProfileLocale): string {
  return i18n.t(locale === 'ru-UA' ? 'global.locale.ru' : locale === 'uk-UA' ? 'global.locale.uk' : 'global.locale.en')
}

async function chooseLocale(value: ProfileLocale): Promise<void> {
  const locale = normalizeLocale(value)
  if (locale === i18n.locale.value) return

  const previous = session.user?.preferences.locale ?? i18n.locale.value
  const sequence = ++localeSequence
  notice.value = null
  applyLocale(locale, true)

  if (session.status !== 'authenticated') return

  try {
    const response = await updatePreferences({ preferences: { locale } })
    if (sequence === localeSequence) updateAuthenticatedUser(response.data.user)
  } catch {
    if (sequence !== localeSequence) return
    applyLocale(previous, true)
    notice.value = i18n.t('global.localeSaveFailed', { language: localeName(previous) })
  }
}

async function toggleTheme(): Promise<void> {
  const previous = { ...theme.preferences } as ThemePreferences
  const next: ThemePreferences = {
    ...previous,
    scheme: resolved.value === 'dark' ? 'light' : 'dark',
  }
  const sequence = ++themeSequence
  notice.value = null
  applyTheme(next, true)

  if (session.status !== 'authenticated') return

  try {
    const response = await updatePreferences({ preferences: { theme: next } })
    if (sequence === themeSequence) updateAuthenticatedUser(response.data.user)
  } catch {
    if (sequence !== themeSequence) return
    applyTheme(previous, true)
    notice.value = i18n.t('global.themeSaveFailed', {
      scheme: i18n.t(resolvedScheme(previous, theme.systemIsDark.value) === 'dark' ? 'theme.dark' : 'theme.light'),
    })
  }
}
</script>

<template>
  <aside class="global-preferences" data-testid="global-preferences">
    <div class="locale-switcher" role="group" :aria-label="i18n.t('global.language')">
      <button
        v-for="choice in localeChoices"
        :key="choice.value"
        type="button"
        class="locale-switcher__button"
        :class="{ selected: i18n.locale.value === choice.value }"
        :aria-pressed="i18n.locale.value === choice.value"
        :title="localeName(choice.value)"
        @click="chooseLocale(choice.value)"
      >{{ choice.code }}</button>
    </div>
    <button
      type="button"
      class="quick-theme-toggle"
      data-testid="quick-theme-toggle"
      :aria-label="toggleLabel"
      :title="toggleLabel"
      @click="toggleTheme"
    ><span aria-hidden="true">{{ resolved === 'dark' ? '☀' : '◐' }}</span></button>
    <p v-if="notice" class="global-preferences__notice notice error" role="alert">{{ notice }}</p>
  </aside>
</template>
