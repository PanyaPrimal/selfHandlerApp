import { computed, reactive, readonly } from 'vue'
import type { ThemeAccent, ThemePreferences, ThemeScheme } from './api/types'

export const THEME_CACHE_KEY = 'selfhandler.theme.v1'

export const DEFAULT_THEME: ThemePreferences = {
  scheme: 'light',
  accent: 'forest',
  accent_hex: '#6d5ac4',
  texture: true,
  mono_numerals: true,
  motion: 'system',
}

export const ACCENT_PRESETS = {
  forest: {
    label: 'Forest',
    light: { accent: '#3d6b4e', soft: '#eef3ef', border: '#cfe0d3', halo: 'rgba(61, 107, 78, 0.22)' },
    dark: { accent: '#6fa982', soft: '#22302a', border: '#35503f', halo: 'rgba(111, 169, 130, 0.28)' },
  },
  slate: {
    label: 'Slate',
    light: { accent: '#4f6d8a', soft: '#eef1f5', border: '#cfd9e4', halo: 'rgba(79, 109, 138, 0.22)' },
    dark: { accent: '#7f9fc0', soft: '#1f2733', border: '#33465c', halo: 'rgba(127, 159, 192, 0.28)' },
  },
  gold: {
    label: 'Gold',
    light: { accent: '#b57a2f', soft: '#f7f1e6', border: '#e6d9bf', halo: 'rgba(181, 122, 47, 0.22)' },
    dark: { accent: '#d8a45f', soft: '#2d2519', border: '#4d3d23', halo: 'rgba(216, 164, 95, 0.28)' },
  },
  brick: {
    label: 'Brick',
    light: { accent: '#9a553f', soft: '#faf2ef', border: '#ecdcd5', halo: 'rgba(154, 85, 63, 0.22)' },
    dark: { accent: '#c9836a', soft: '#2e211d', border: '#4d332b', halo: 'rgba(201, 131, 106, 0.28)' },
  },
} as const

const SCHEMES: ThemeScheme[] = ['light', 'dark', 'system']
const ACCENTS: ThemeAccent[] = ['forest', 'slate', 'gold', 'brick', 'custom']
const HEX_PATTERN = /^#[0-9a-f]{6}$/i

const state = reactive({
  preferences: { ...DEFAULT_THEME } as ThemePreferences,
  systemIsDark: false,
  initialized: false,
})

let media: MediaQueryList | null = null

export function normalizeTheme(value: unknown): ThemePreferences {
  if (!value || typeof value !== 'object') return { ...DEFAULT_THEME }
  const candidate = value as Partial<ThemePreferences>

  return {
    scheme: SCHEMES.includes(candidate.scheme as ThemeScheme) ? candidate.scheme as ThemeScheme : DEFAULT_THEME.scheme,
    accent: ACCENTS.includes(candidate.accent as ThemeAccent) ? candidate.accent as ThemeAccent : DEFAULT_THEME.accent,
    accent_hex: typeof candidate.accent_hex === 'string' && HEX_PATTERN.test(candidate.accent_hex)
      ? candidate.accent_hex.toLowerCase()
      : DEFAULT_THEME.accent_hex,
    texture: typeof candidate.texture === 'boolean' ? candidate.texture : DEFAULT_THEME.texture,
    mono_numerals: typeof candidate.mono_numerals === 'boolean'
      ? candidate.mono_numerals
      : DEFAULT_THEME.mono_numerals,
    motion: candidate.motion === 'reduce' ? 'reduce' : 'system',
  }
}

export function resolvedScheme(preferences: ThemePreferences, systemIsDark = state.systemIsDark): 'light' | 'dark' {
  return preferences.scheme === 'system' ? (systemIsDark ? 'dark' : 'light') : preferences.scheme
}

export function hexToRgb(hex: string): [number, number, number] | null {
  if (!HEX_PATTERN.test(hex)) return null
  const value = Number.parseInt(hex.slice(1), 16)
  return [(value >> 16) & 255, (value >> 8) & 255, value & 255]
}

export function mixHex(from: string, to: string, amount: number): string {
  const a = hexToRgb(from)
  const b = hexToRgb(to)
  if (!a || !b) return from
  return `#${a.map((channel, index) => Math.round(channel + (b[index] - channel) * amount)
    .toString(16).padStart(2, '0')).join('')}`
}

function luminance(hex: string): number {
  const rgb = hexToRgb(hex) ?? [0, 0, 0]
  const channels = rgb.map((channel) => {
    const value = channel / 255
    return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2]
}

export function contrastRatio(first: string, second: string): number {
  const a = luminance(first)
  const b = luminance(second)
  return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
}

export function customAccentTokens(hex: string, scheme: 'light' | 'dark') {
  const normalized = normalizeTheme({ ...DEFAULT_THEME, accent_hex: hex }).accent_hex
  const surface = scheme === 'dark' ? '#24231f' : '#ffffff'
  const rgb = hexToRgb(normalized) ?? [61, 107, 78]

  return {
    accent: normalized,
    soft: mixHex(normalized, surface, scheme === 'dark' ? 0.82 : 0.92),
    border: mixHex(normalized, surface, scheme === 'dark' ? 0.62 : 0.72),
    halo: `rgba(${rgb.join(', ')}, ${scheme === 'dark' ? '0.28' : '0.22'})`,
  }
}

function writeCache(preferences: ThemePreferences): void {
  try {
    localStorage.setItem(THEME_CACHE_KEY, JSON.stringify(preferences))
  } catch {
    // A blocked storage area must never prevent the theme from applying.
  }
}

export function applyTheme(preferences: ThemePreferences, cache = false): void {
  const normalized = normalizeTheme(preferences)
  Object.assign(state.preferences, normalized)

  const root = document.documentElement
  const scheme = resolvedScheme(normalized)
  root.dataset.theme = scheme
  root.dataset.accent = normalized.accent
  root.dataset.texture = normalized.texture ? 'on' : 'off'
  root.dataset.monoNumerals = normalized.mono_numerals ? 'on' : 'off'
  root.dataset.motion = normalized.motion

  for (const property of ['--accent', '--accent-soft', '--accent-border', '--focus-halo']) {
    root.style.removeProperty(property)
  }

  if (normalized.accent === 'custom') {
    const tokens = customAccentTokens(normalized.accent_hex, scheme)
    root.style.setProperty('--accent', tokens.accent)
    root.style.setProperty('--accent-soft', tokens.soft)
    root.style.setProperty('--accent-border', tokens.border)
    root.style.setProperty('--focus-halo', `0 0 0 3px ${tokens.halo}`)
  }

  if (cache) writeCache(normalized)
}

export function initializeTheme(): void {
  if (state.initialized) return
  media = window.matchMedia('(prefers-color-scheme: dark)')
  state.systemIsDark = media.matches

  try {
    applyTheme(normalizeTheme(JSON.parse(localStorage.getItem(THEME_CACHE_KEY) ?? 'null')))
  } catch {
    applyTheme(DEFAULT_THEME)
  }

  media.addEventListener('change', (event) => {
    state.systemIsDark = event.matches
    if (state.preferences.scheme === 'system') applyTheme(state.preferences)
  })
  state.initialized = true
}

export function syncThemeFromProfile(preferences: ThemePreferences | undefined): void {
  applyTheme(normalizeTheme(preferences), true)
}

export function clearAccountTheme(): void {
  try {
    localStorage.removeItem(THEME_CACHE_KEY)
  } catch {
    // See writeCache: storage is only a paint cache, never required state.
  }
  applyTheme(DEFAULT_THEME)
}

export function useTheme() {
  return {
    preferences: readonly(state.preferences),
    systemIsDark: computed(() => state.systemIsDark),
    resolved: computed(() => resolvedScheme(state.preferences)),
    apply: applyTheme,
    cache: writeCache,
  }
}
