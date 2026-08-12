<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { getProfile, updateThemePreferences } from '../api/client'
import type { ThemeAccent, ThemePreferences, ThemeScheme } from '../api/types'
import { updateAuthenticatedUser, useAuthSession } from '../auth/session'
import { UiSegmented, UiSwatchGroup, UiSwitch } from '../components/ui'
import type { UiOption, UiSwatchOption } from '../components/ui'
import {
  ACCENT_PRESETS,
  DEFAULT_THEME,
  applyTheme,
  contrastRatio,
  customAccentTokens,
  normalizeTheme,
  resolvedScheme,
  useTheme,
} from '../theme'

const session = useAuthSession()
const theme = useTheme()
const loading = ref(true)
const saving = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const success = ref<string | null>(null)
const accepted = ref<ThemePreferences | null>(null)
const customDraft = ref(DEFAULT_THEME.accent_hex.toUpperCase())
const customError = ref<string | null>(null)

const draft = reactive<ThemePreferences>({ ...DEFAULT_THEME })

const schemeOptions: UiOption<ThemeScheme>[] = [
  { value: 'light', label: 'Light', description: 'Warm paper' },
  { value: 'dark', label: 'Dark', description: 'Warm charcoal' },
  { value: 'system', label: 'System', description: 'Follow device' },
]

const motionOptions: UiOption<ThemePreferences['motion']>[] = [
  { value: 'system', label: 'Follow system' },
  { value: 'reduce', label: 'Always reduce' },
]

const resolved = computed(() => resolvedScheme(draft, theme.systemIsDark.value))
const resolvedLabel = computed(() => resolved.value === 'dark' ? 'Dark' : 'Light')
const systemNote = computed(() => draft.scheme === 'system'
  ? `Device reports ${theme.systemIsDark.value ? 'dark' : 'light'} — showing ${resolvedLabel.value}`
  : `Fixed to ${draft.scheme === 'dark' ? 'Dark' : 'Light'}, ignores the device`)

const swatches = computed<UiSwatchOption<ThemeAccent>[]>(() =>
  (Object.keys(ACCENT_PRESETS) as Array<keyof typeof ACCENT_PRESETS>).map((key) => ({
    value: key,
    label: ACCENT_PRESETS[key].label,
    color: ACCENT_PRESETS[key][resolved.value].accent,
    hex: ACCENT_PRESETS[key][resolved.value].accent.toUpperCase(),
  })),
)

const customTokens = computed(() => customAccentTokens(draft.accent_hex, resolved.value))
const palette = computed(() => resolved.value === 'dark'
  ? { surface: '#24231f', paper: '#171613' }
  : { surface: '#ffffff', paper: '#ece9e2' })
const contrast = computed(() => Math.min(
  contrastRatio(draft.accent_hex, palette.value.surface),
  contrastRatio(draft.accent_hex, palette.value.paper),
))
const contrastPasses = computed(() => contrast.value >= 3)
const dirty = computed(() => accepted.value !== null && JSON.stringify(draft) !== JSON.stringify(accepted.value))

function copyTheme(value: ThemePreferences): ThemePreferences {
  return { ...value }
}

function chooseCustom(raw = customDraft.value): void {
  const candidate = raw.trim().startsWith('#') ? raw.trim() : `#${raw.trim()}`

  if (!/^#[0-9a-f]{6}$/i.test(candidate)) {
    customError.value = 'Enter a six-digit hex colour, for example #6D5AC4.'
    return
  }

  customError.value = null
  draft.accent_hex = candidate.toLowerCase()
  draft.accent = 'custom'
  customDraft.value = candidate.toUpperCase()
}

function pickNativeColour(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  customDraft.value = value.toUpperCase()
  chooseCustom(value)
}

function reset(): void {
  Object.assign(draft, DEFAULT_THEME)
  customDraft.value = DEFAULT_THEME.accent_hex.toUpperCase()
  customError.value = null
  success.value = null
  saveError.value = null
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null

  try {
    const response = await getProfile()
    const current = normalizeTheme(response.data.theme)
    accepted.value = copyTheme(current)
    Object.assign(draft, current)
    customDraft.value = current.accent_hex.toUpperCase()
    updateAuthenticatedUser(response.data.user)
  } catch {
    loadError.value = 'Could not load appearance settings. Check the service and try again.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (saving.value || !dirty.value) return
  saving.value = true
  saveError.value = null
  success.value = null

  try {
    const response = await updateThemePreferences({ preferences: { theme: copyTheme(draft) } })
    const current = normalizeTheme(response.data.theme)
    accepted.value = copyTheme(current)
    Object.assign(draft, current)
    customDraft.value = current.accent_hex.toUpperCase()
    applyTheme(current, true)
    updateAuthenticatedUser(response.data.user)
    success.value = 'Appearance saved.'
  } catch {
    if (accepted.value) {
      Object.assign(draft, accepted.value)
      customDraft.value = accepted.value.accent_hex.toUpperCase()
      applyTheme(accepted.value, true)
    }
    saveError.value = 'Could not save appearance. Your previous theme has been restored; please try again.'
  } finally {
    saving.value = false
  }
}

watch(draft, (value) => {
  if (!loading.value) applyTheme(copyTheme(value))
}, { deep: true })

onMounted(load)
onBeforeUnmount(() => {
  if (accepted.value && dirty.value) applyTheme(accepted.value, true)
})
</script>

<template>
  <section class="view-stack appearance-page">
    <header class="appearance-header">
      <p class="eyebrow">Settings</p>
      <h1>Appearance</h1>
      <p class="muted">Colour only. Your choice is stored on your profile, so it follows you across devices.</p>
    </header>

    <nav class="settings-tabs" aria-label="Settings sections">
      <RouterLink to="/settings/appearance" aria-current="page">Appearance</RouterLink>
      <RouterLink to="/account">Profile</RouterLink>
      <span aria-disabled="true">Preferences</span>
      <span aria-disabled="true">Data</span>
    </nav>

    <div v-if="loading" class="state-block" role="status">Loading appearance…</div>
    <div v-else-if="loadError" class="state-block error" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" @click="load">Retry</button>
    </div>

    <template v-else>
      <div v-if="saveError" class="notice error" role="alert" aria-live="assertive">{{ saveError }}</div>
      <div v-if="success" class="notice success" role="status" aria-live="polite">{{ success }}</div>

      <div class="appearance-layout">
        <div class="appearance-controls">
          <section class="panel appearance-card" aria-labelledby="scheme-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="scheme-heading">Colour scheme</h2>
                <p class="muted">{{ systemNote }}</p>
              </div>
              <span class="token-caption">data-theme</span>
            </div>
            <UiSegmented
              v-model="draft.scheme"
              class="appearance-scheme"
              label="Scheme"
              name="theme_scheme"
              :options="schemeOptions"
            />
          </section>

          <section class="panel appearance-card" aria-labelledby="accent-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="accent-heading">Accent</h2>
                <p class="muted">Each accent is tuned separately for light and dark.</p>
              </div>
              <span class="token-caption">--accent</span>
            </div>

            <UiSwatchGroup
              v-model="draft.accent"
              class="appearance-swatches"
              label="Accent presets"
              name="theme_accent"
              :options="swatches"
            />

            <div class="custom-colour" :class="{ 'is-selected': draft.accent === 'custom' }">
              <div class="custom-colour__heading">
                <div>
                  <h3>Custom colour</h3>
                  <p class="muted">Soft fill, border and focus halo are derived from one colour.</p>
                </div>
                <span
                  class="contrast-pill"
                  :class="{ 'is-error': !contrastPasses }"
                  :title="contrastPasses ? 'Passes on surface and paper' : `Pick a ${resolved === 'dark' ? 'lighter' : 'deeper'} tone`"
                >
                  {{ contrast.toFixed(1) }}:1
                </span>
              </div>

              <div class="custom-colour__control">
                <label class="colour-well" :style="{ '--custom-colour': draft.accent_hex }">
                  <span class="visually-hidden">Choose custom colour</span>
                  <input type="color" :value="draft.accent_hex" @input="pickNativeColour" />
                </label>
                <label class="custom-hex-field">
                  <span>Hex</span>
                  <input
                    v-model="customDraft"
                    class="ui-control ui-control--text mono"
                    name="accent_hex"
                    autocomplete="off"
                    spellcheck="false"
                    :aria-invalid="customError ? true : undefined"
                    aria-describedby="custom-colour-feedback"
                    @keydown.enter.prevent="chooseCustom()"
                  />
                </label>
                <button type="button" @click="chooseCustom()">Use</button>
              </div>

              <p id="custom-colour-feedback" class="ui-field__helper" :class="{ 'ui-field__error': customError }">
                {{ customError ?? (contrastPasses
                  ? 'Passes on surface and paper.'
                  : `Contrast is low — pick a ${resolved === 'dark' ? 'lighter' : 'deeper'} tone before saving.`) }}
              </p>

              <div class="derived-tokens" aria-label="Derived colour tokens">
                <span><i :style="{ background: customTokens.accent }"></i>--accent</span>
                <span><i :style="{ background: customTokens.soft }"></i>--accent-soft</span>
                <span><i :style="{ background: customTokens.border }"></i>--accent-border</span>
                <span><i class="halo-token" :style="{ '--halo-colour': customTokens.halo }"></i>--focus-halo</span>
              </div>
            </div>
          </section>

          <section class="panel appearance-card appearance-switches" aria-labelledby="details-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="details-heading">Interface details</h2>
                <p class="muted">These preferences change presentation, never layout.</p>
              </div>
            </div>
            <UiSwitch
              v-model="draft.texture"
              label="Dotted page texture"
              name="theme_texture"
              helper="A subtle 28px grid that retints with the scheme."
            />
            <UiSwitch
              v-model="draft.mono_numerals"
              label="Monospace numerals"
              name="theme_mono_numerals"
              helper="Keeps metrics, times and streaks aligned."
            />
            <UiSegmented
              v-model="draft.motion"
              class="appearance-motion"
              label="Motion"
              name="theme_motion"
              :options="motionOptions"
              helper="Reduce interface transitions when you prefer less motion."
            />
          </section>
        </div>

        <aside class="panel appearance-preview" aria-labelledby="preview-heading">
          <div class="appearance-card__heading">
            <h2 id="preview-heading">Live preview</h2>
            <span class="preview-scheme">{{ resolvedLabel }}</span>
          </div>
          <div class="preview-canvas">
            <div class="preview-card">
              <div class="preview-metric">
                <strong>Today</strong>
                <span class="mono">60%</span>
              </div>
              <div class="preview-progress"><span></span></div>
              <label class="preview-label">Field at rest</label>
              <div class="preview-field">Strength training</div>
              <label class="preview-label">Field focused <small class="mono">tap to toggle</small></label>
              <div class="preview-field is-focused">18:00</div>
              <div class="preview-buttons">
                <button type="button">Save</button>
                <button type="button" class="secondary">Cancel</button>
                <button type="button" class="ghost">Ghost</button>
              </div>
              <div class="preview-chips">
                <span class="chip"><i></i>Ship MVP</span>
                <span class="chip accent-chip mono">12d</span>
              </div>
              <div class="notice error">Couldn't reach the server — retrying</div>
            </div>
          </div>
          <div class="appearance-save">
            <button type="button" :disabled="saving || !dirty" @click="save">
              {{ saving ? 'Saving…' : 'Save appearance' }}
            </button>
            <button type="button" class="secondary" :disabled="saving" @click="reset">Reset</button>
          </div>
          <p class="api-caption">PATCH /api/profile · preferences.theme</p>
          <p class="save-state" aria-live="polite">
            {{ dirty ? 'Unsaved appearance changes' : `Saved for ${session.user?.name ?? 'your profile'}` }}
          </p>
        </aside>
      </div>
    </template>
  </section>
</template>
