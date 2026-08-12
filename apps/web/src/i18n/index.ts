import { computed, reactive, readonly } from 'vue'
import type { ProfileLocale } from '../api/types'
import { en, type MessageKey } from './locales/en'
import { ru } from './locales/ru'
import { uk } from './locales/uk'

export const LOCALE_CACHE_KEY = 'selfhandler.locale.v1'
export const DEFAULT_LOCALE: ProfileLocale = 'en-GB'
export const SUPPORTED_LOCALES: readonly ProfileLocale[] = ['en-GB', 'ru-UA', 'uk-UA']

const messages: Record<ProfileLocale, Record<MessageKey, string>> = {
  'en-GB': en,
  'ru-UA': ru,
  'uk-UA': uk,
}

const state = reactive({
  locale: DEFAULT_LOCALE as ProfileLocale,
  initialized: false,
})

export function normalizeLocale(value: unknown): ProfileLocale {
  return SUPPORTED_LOCALES.includes(value as ProfileLocale) ? value as ProfileLocale : DEFAULT_LOCALE
}

function documentLanguage(locale: ProfileLocale): string {
  return locale.slice(0, 2)
}

function writeCache(locale: ProfileLocale): void {
  try {
    localStorage.setItem(LOCALE_CACHE_KEY, locale)
  } catch {
    // Browser storage is a paint cache, never required application state.
  }
}

export function applyLocale(value: unknown, cache = false): ProfileLocale {
  const locale = normalizeLocale(value)
  state.locale = locale
  document.documentElement.lang = documentLanguage(locale)

  if (cache) writeCache(locale)

  return locale
}

export function initializeLocale(): void {
  if (state.initialized) return

  let cached: unknown = DEFAULT_LOCALE
  try {
    cached = localStorage.getItem(LOCALE_CACHE_KEY)
  } catch {
    // Use the deterministic default.
  }

  applyLocale(cached)
  state.initialized = true
}

export function syncLocaleFromProfile(locale: ProfileLocale | undefined): void {
  applyLocale(locale, true)
}

export function activeLocaleValue(): ProfileLocale {
  return state.locale
}

export function translate(key: MessageKey, parameters: Record<string, string | number> = {}): string {
  const template = messages[state.locale][key] ?? en[key]

  if (template === undefined) {
    if (import.meta.env.DEV) console.warn(`[i18n] Missing message: ${key}`)
    return `[missing:${key}]`
  }

  return Object.entries(parameters).reduce(
    (text, [name, value]) => text.replaceAll(`{${name}}`, String(value)),
    template,
  )
}

export function formatNumber(value: number, options: Intl.NumberFormatOptions = {}): string {
  return new Intl.NumberFormat(state.locale, options).format(value)
}

export function formatList(values: readonly string[]): string {
  return new Intl.ListFormat(state.locale, { style: 'long', type: 'conjunction' }).format(values)
}

export function pluralCategory(value: number): Intl.LDMLPluralRule {
  return new Intl.PluralRules(state.locale).select(value)
}

export function translatePlural(
  value: number,
  forms: { one?: MessageKey, few?: MessageKey, many?: MessageKey, other: MessageKey },
  parameters: Record<string, string | number> = {},
): string {
  const category = pluralCategory(value)
  return translate(forms[category as 'one' | 'few' | 'many'] ?? forms.other, { count: value, ...parameters })
}

export function useI18n() {
  return {
    locale: computed(() => state.locale),
    state: readonly(state),
    t: translate,
    number: formatNumber,
    plural: translatePlural,
  }
}
