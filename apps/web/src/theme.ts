import { computed, reactive, readonly } from 'vue'
import type { ThemeAccent, ThemeBackground, ThemePreferences, ThemeScheme } from './api/types'

export const THEME_CACHE_KEY = 'selfhandler.theme.v1'

export const DEFAULT_THEME: ThemePreferences = {
  scheme: 'light',
  accent: 'forest',
  accent_hex: '#6d5ac4',
  background: 'paper',
  background_hex: '#ece9e2',
  texture: true,
  mono_numerals: true,
  motion: 'system',
}

export const ACCENT_PRESETS = {
  forest: {
    light: { accent: '#3d6b4e', soft: '#eef3ef', border: '#cfe0d3', halo: 'rgba(61, 107, 78, 0.22)' },
    dark: { accent: '#6fa982', soft: '#22302a', border: '#35503f', halo: 'rgba(111, 169, 130, 0.28)' },
  },
  slate: {
    light: { accent: '#4f6d8a', soft: '#eef1f5', border: '#cfd9e4', halo: 'rgba(79, 109, 138, 0.22)' },
    dark: { accent: '#7f9fc0', soft: '#1f2733', border: '#33465c', halo: 'rgba(127, 159, 192, 0.28)' },
  },
  gold: {
    light: { accent: '#b57a2f', soft: '#f7f1e6', border: '#e6d9bf', halo: 'rgba(181, 122, 47, 0.22)' },
    dark: { accent: '#d8a45f', soft: '#2d2519', border: '#4d3d23', halo: 'rgba(216, 164, 95, 0.28)' },
  },
  brick: {
    light: { accent: '#9a553f', soft: '#faf2ef', border: '#ecdcd5', halo: 'rgba(154, 85, 63, 0.22)' },
    dark: { accent: '#c9836a', soft: '#2e211d', border: '#4d332b', halo: 'rgba(201, 131, 106, 0.28)' },
  },
} as const

interface BackgroundTokens {
  paper: string
  paperSoft: string
  surface: string
  surfaceWarm: string
  ink: string
  muted: string
  subtle: string
  border: string
  borderStrong: string
  fieldBorder: string
  textureDot: string
}

export const BACKGROUND_PRESETS: Record<Exclude<ThemeBackground, 'custom'>, Record<'light' | 'dark', BackgroundTokens>> = {
  paper: {
    light: { paper: '#ece9e2', paperSoft: '#faf8f4', surface: '#ffffff', surfaceWarm: '#fffaf2', ink: '#232220', muted: '#56524b', subtle: '#6b645b', border: '#e0dcd2', borderStrong: '#c4beb2', fieldBorder: '#cfcabf', textureDot: 'rgba(30, 28, 24, 0.05)' },
    dark: { paper: '#171613', paperSoft: '#1f1e1a', surface: '#24231f', surfaceWarm: '#2b2823', ink: '#f1eee7', muted: '#b9b3a7', subtle: '#968f83', border: '#35332d', borderStrong: '#4b473f', fieldBorder: '#433f38', textureDot: 'rgba(255, 255, 255, 0.055)' },
  },
  sand: {
    light: { paper: '#f2e8d6', paperSoft: '#fbf6ed', surface: '#fffdfa', surfaceWarm: '#fff6e7', ink: '#29231c', muted: '#625646', subtle: '#786955', border: '#e6d7bf', borderStrong: '#cdbb9d', fieldBorder: '#d8c7aa', textureDot: 'rgba(62, 45, 24, 0.055)' },
    dark: { paper: '#1d1812', paperSoft: '#251f17', surface: '#2b241b', surfaceWarm: '#33291e', ink: '#f4eadb', muted: '#c2b39e', subtle: '#9f907b', border: '#3d3327', borderStrong: '#574938', fieldBorder: '#4b3e2f', textureDot: 'rgba(255, 239, 213, 0.06)' },
  },
  mist: {
    light: { paper: '#e7edf0', paperSoft: '#f5f8f9', surface: '#fcfeff', surfaceWarm: '#f5fafb', ink: '#20272b', muted: '#4e5b62', subtle: '#63727a', border: '#d4dfe4', borderStrong: '#b8c8cf', fieldBorder: '#c5d3d9', textureDot: 'rgba(23, 49, 61, 0.05)' },
    dark: { paper: '#13191c', paperSoft: '#1a2226', surface: '#20292e', surfaceWarm: '#253036', ink: '#edf2f4', muted: '#adb9bf', subtle: '#89979e', border: '#303c42', borderStrong: '#44555d', fieldBorder: '#3b4a51', textureDot: 'rgba(225, 243, 250, 0.055)' },
  },
  sage: {
    light: { paper: '#e7ece4', paperSoft: '#f5f8f3', surface: '#fcfefb', surfaceWarm: '#f5faf2', ink: '#222820', muted: '#515c4d', subtle: '#667261', border: '#d5dfd1', borderStrong: '#bac9b5', fieldBorder: '#c6d4c1', textureDot: 'rgba(31, 54, 27, 0.05)' },
    dark: { paper: '#151a14', paperSoft: '#1c231b', surface: '#232b21', surfaceWarm: '#293226', ink: '#eef2eb', muted: '#b0baaa', subtle: '#8c9987', border: '#333e30', borderStrong: '#485844', fieldBorder: '#3f4d3b', textureDot: 'rgba(235, 249, 229, 0.055)' },
  },
}

const SCHEMES: ThemeScheme[] = ['light', 'dark', 'system']
const ACCENTS: ThemeAccent[] = ['forest', 'slate', 'gold', 'brick', 'custom']
const BACKGROUNDS: ThemeBackground[] = ['paper', 'sand', 'mist', 'sage', 'custom']
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
    background: BACKGROUNDS.includes(candidate.background as ThemeBackground)
      ? candidate.background as ThemeBackground
      : DEFAULT_THEME.background,
    background_hex: typeof candidate.background_hex === 'string' && HEX_PATTERN.test(candidate.background_hex)
      ? candidate.background_hex.toLowerCase()
      : DEFAULT_THEME.background_hex,
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

export function customBackgroundTokens(hex: string, scheme: 'light' | 'dark'): BackgroundTokens {
  const normalized = HEX_PATTERN.test(hex) ? hex.toLowerCase() : DEFAULT_THEME.background_hex

  if (scheme === 'dark') {
    const paper = mixHex(normalized, '#11110f', 0.84)
    const surface = mixHex(normalized, '#262521', 0.86)

    return {
      paper,
      paperSoft: mixHex(normalized, '#1b1a17', 0.86),
      surface,
      surfaceWarm: mixHex(normalized, '#2d2a24', 0.87),
      ink: '#f3f1eb',
      muted: '#bcb7ad',
      subtle: '#999288',
      border: mixHex(normalized, '#37342e', 0.84),
      borderStrong: mixHex(normalized, '#504b42', 0.82),
      fieldBorder: mixHex(normalized, '#464139', 0.83),
      textureDot: 'rgba(255, 255, 255, 0.055)',
    }
  }

  const paper = mixHex(normalized, '#ffffff', 0.86)
  const surface = mixHex(normalized, '#ffffff', 0.97)

  return {
    paper,
    paperSoft: mixHex(normalized, '#ffffff', 0.94),
    surface,
    surfaceWarm: mixHex(normalized, '#fffaf2', 0.94),
    ink: '#222220',
    muted: '#55534e',
    subtle: '#6b6760',
    border: mixHex(normalized, '#dedbd4', 0.82),
    borderStrong: mixHex(normalized, '#c2bdb3', 0.78),
    fieldBorder: mixHex(normalized, '#cdc8be', 0.8),
    textureDot: 'rgba(30, 28, 24, 0.05)',
  }
}

export function backgroundTokens(preferences: ThemePreferences, scheme = resolvedScheme(preferences)): BackgroundTokens {
  return preferences.background === 'custom'
    ? customBackgroundTokens(preferences.background_hex, scheme)
    : BACKGROUND_PRESETS[preferences.background][scheme]
}

export function backgroundContrast(preferences: ThemePreferences, scheme = resolvedScheme(preferences)): number {
  const tokens = backgroundTokens(preferences, scheme)
  return Math.min(contrastRatio(tokens.ink, tokens.paper), contrastRatio(tokens.ink, tokens.surface))
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
  root.dataset.background = normalized.background
  root.dataset.texture = normalized.texture ? 'on' : 'off'
  root.dataset.monoNumerals = normalized.mono_numerals ? 'on' : 'off'
  root.dataset.motion = normalized.motion

  for (const property of ['--accent', '--accent-soft', '--accent-border', '--focus-halo']) {
    root.style.removeProperty(property)
  }

  const background = backgroundTokens(normalized, scheme)
  const backgroundProperties: Record<string, string> = {
    '--paper': background.paper,
    '--paper-soft': background.paperSoft,
    '--surface': background.surface,
    '--surface-warm': background.surfaceWarm,
    '--ink': background.ink,
    '--muted': background.muted,
    '--subtle': background.subtle,
    '--border': background.border,
    '--border-strong': background.borderStrong,
    '--field-border': background.fieldBorder,
    '--texture-dot': background.textureDot,
  }
  for (const [property, value] of Object.entries(backgroundProperties)) {
    root.style.setProperty(property, value)
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
