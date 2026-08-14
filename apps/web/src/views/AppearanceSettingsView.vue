<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { getProfile, updateThemePreferences } from '../api/client'
import type { ThemeAccent, ThemeBackground, ThemePreferences, ThemeScheme } from '../api/types'
import { updateAuthenticatedUser, useAuthSession } from '../auth/session'
import { UiSegmented, UiSwatchGroup, UiSwitch } from '../components/ui'
import type { UiOption, UiSwatchOption } from '../components/ui'
import {
  ACCENT_PRESETS,
  BACKGROUND_PRESETS,
  DEFAULT_THEME,
  applyTheme,
  backgroundContrast,
  backgroundTokens,
  contrastRatio,
  customAccentTokens,
  normalizeTheme,
  resolvedScheme,
  useTheme,
} from '../theme'
import { useI18n } from '../i18n'

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
const backgroundDraft = ref(DEFAULT_THEME.background_hex.toUpperCase())
const backgroundError = ref<string | null>(null)
const { t } = useI18n()

const draft = reactive<ThemePreferences>({ ...DEFAULT_THEME })

const schemeOptions = computed<UiOption<ThemeScheme>[]>(() => [
  { value: 'light', label: t('theme.light'), description: t('appearance.lightDescription') },
  { value: 'dark', label: t('theme.dark'), description: t('appearance.darkDescription') },
  { value: 'system', label: t('appearance.system'), description: t('appearance.systemDescription') },
])

const motionOptions = computed<UiOption<ThemePreferences['motion']>[]>(() => [
  { value: 'system', label: t('appearance.followSystem') },
  { value: 'reduce', label: t('appearance.reduceMotion') },
])

const resolved = computed(() => resolvedScheme(draft, theme.systemIsDark.value))
const resolvedLabel = computed(() => t(resolved.value === 'dark' ? 'theme.dark' : 'theme.light'))
const systemNote = computed(() => draft.scheme === 'system'
  ? t('appearance.deviceSystem', {
      device: t(theme.systemIsDark.value ? 'theme.dark' : 'theme.light'),
      resolved: resolvedLabel.value,
    })
  : t('appearance.fixedScheme', { scheme: resolvedLabel.value }))

const swatches = computed<UiSwatchOption<ThemeAccent>[]>(() =>
  (Object.keys(ACCENT_PRESETS) as Array<keyof typeof ACCENT_PRESETS>).map((key) => ({
    value: key,
    label: t(`appearance.accent.${key}` as 'appearance.accent.forest'),
    color: ACCENT_PRESETS[key][resolved.value].accent,
    hex: ACCENT_PRESETS[key][resolved.value].accent.toUpperCase(),
  })),
)

const backgroundSwatches = computed<UiSwatchOption<ThemeBackground>[]>(() =>
  (Object.keys(BACKGROUND_PRESETS) as Array<keyof typeof BACKGROUND_PRESETS>).map((key) => ({
    value: key,
    label: t(`appearance.background.${key}` as 'appearance.background.paper'),
    color: BACKGROUND_PRESETS[key][resolved.value].paper,
    hex: BACKGROUND_PRESETS[key][resolved.value].paper.toUpperCase(),
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
const backgroundPalette = computed(() => backgroundTokens(draft, resolved.value))
const backgroundRatio = computed(() => backgroundContrast(draft, resolved.value))
const dirty = computed(() => accepted.value !== null && JSON.stringify(draft) !== JSON.stringify(accepted.value))

function copyTheme(value: ThemePreferences): ThemePreferences {
  return { ...value }
}

function chooseCustom(raw = customDraft.value): void {
  const candidate = raw.trim().startsWith('#') ? raw.trim() : `#${raw.trim()}`

  if (!/^#[0-9a-f]{6}$/i.test(candidate)) {
    customError.value = t('appearance.invalidAccent')
    return
  }

  customError.value = null
  draft.accent_hex = candidate.toLowerCase()
  draft.accent = 'custom'
  customDraft.value = candidate.toUpperCase()
}

function chooseCustomBackground(raw = backgroundDraft.value): void {
  const candidate = raw.trim().startsWith('#') ? raw.trim() : `#${raw.trim()}`

  if (!/^#[0-9a-f]{6}$/i.test(candidate)) {
    backgroundError.value = t('appearance.invalidBackground')
    return
  }

  backgroundError.value = null
  draft.background_hex = candidate.toLowerCase()
  draft.background = 'custom'
  backgroundDraft.value = candidate.toUpperCase()
}

function pickNativeBackground(event: Event): void {
  const value = (event.target as HTMLInputElement).value
  backgroundDraft.value = value.toUpperCase()
  chooseCustomBackground(value)
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
  backgroundDraft.value = DEFAULT_THEME.background_hex.toUpperCase()
  backgroundError.value = null
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
    backgroundDraft.value = current.background_hex.toUpperCase()
    updateAuthenticatedUser(response.data.user)
  } catch {
    loadError.value = t('appearance.loadFailed')
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (saving.value || !dirty.value || backgroundError.value !== null) return
  saving.value = true
  saveError.value = null
  success.value = null

  try {
    const response = await updateThemePreferences(copyTheme(draft))
    const current = normalizeTheme(response.data.theme)
    accepted.value = copyTheme(current)
    Object.assign(draft, current)
    customDraft.value = current.accent_hex.toUpperCase()
    backgroundDraft.value = current.background_hex.toUpperCase()
    applyTheme(current, true)
    updateAuthenticatedUser(response.data.user)
    success.value = t('appearance.saved')
  } catch {
    if (accepted.value) {
      Object.assign(draft, accepted.value)
      customDraft.value = accepted.value.accent_hex.toUpperCase()
      backgroundDraft.value = accepted.value.background_hex.toUpperCase()
      applyTheme(accepted.value, true)
    }
    saveError.value = t('appearance.saveFailed')
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
      <p class="eyebrow">{{ t('appearance.settings') }}</p>
      <h1>{{ t('appearance.title') }}</h1>
      <p class="muted">{{ t('appearance.body') }}</p>
    </header>

    <nav class="settings-tabs" :aria-label="t('appearance.sections')">
      <RouterLink to="/settings/appearance" aria-current="page">{{ t('appearance.tab') }}</RouterLink>
      <RouterLink to="/account">{{ t('appearance.profileTab') }}</RouterLink>
      <span aria-disabled="true">{{ t('appearance.preferencesTab') }}</span>
      <RouterLink to="/settings/data">{{ t('appearance.dataTab') }}</RouterLink>
    </nav>

    <div v-if="loading" class="state-block" role="status">{{ t('appearance.loading') }}</div>
    <div v-else-if="loadError" class="state-block error" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" @click="load">{{ t('common.retry') }}</button>
    </div>

    <template v-else>
      <div v-if="saveError" class="notice error" role="alert" aria-live="assertive">{{ saveError }}</div>
      <div v-if="success" class="notice success" role="status" aria-live="polite">{{ success }}</div>

      <div class="appearance-layout">
        <div class="appearance-controls">
          <section class="panel appearance-card" aria-labelledby="scheme-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="scheme-heading">{{ t('appearance.scheme') }}</h2>
                <p class="muted">{{ systemNote }}</p>
              </div>
              <span class="token-caption">data-theme</span>
            </div>
            <UiSegmented
              v-model="draft.scheme"
              class="appearance-scheme"
              :label="t('appearance.schemeLabel')"
              name="theme_scheme"
              :options="schemeOptions"
            />
          </section>

          <section class="panel appearance-card" aria-labelledby="accent-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="accent-heading">{{ t('appearance.accent') }}</h2>
                <p class="muted">{{ t('appearance.accentBody') }}</p>
              </div>
              <span class="token-caption">--accent</span>
            </div>

            <UiSwatchGroup
              v-model="draft.accent"
              class="appearance-swatches"
              :label="t('appearance.accentPresets')"
              name="theme_accent"
              :options="swatches"
            />

            <div class="custom-colour" :class="{ 'is-selected': draft.accent === 'custom' }">
              <div class="custom-colour__heading">
                <div>
                  <h3>{{ t('appearance.customColour') }}</h3>
                  <p class="muted">{{ t('appearance.customAccentBody') }}</p>
                </div>
                <span
                  class="contrast-pill"
                  :class="{ 'is-error': !contrastPasses }"
                  :title="contrastPasses ? t('appearance.contrastPass') : t('appearance.contrastLow', { direction: t(resolved === 'dark' ? 'appearance.lighter' : 'appearance.deeper') })"
                >
                  {{ contrast.toFixed(1) }}:1
                </span>
              </div>

              <div class="custom-colour__control">
                <label class="colour-well" :style="{ '--custom-colour': draft.accent_hex }">
                  <span class="visually-hidden">{{ t('appearance.chooseAccent') }}</span>
                  <input type="color" :value="draft.accent_hex" @input="pickNativeColour" />
                </label>
                <label class="custom-hex-field">
                  <span>{{ t('appearance.hex') }}</span>
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
                <button type="button" @click="chooseCustom()">{{ t('appearance.use') }}</button>
              </div>

              <p id="custom-colour-feedback" class="ui-field__helper" :class="{ 'ui-field__error': customError }">
                {{ customError ?? (contrastPasses
                  ? t('appearance.contrastPass')
                  : t('appearance.contrastLow', { direction: t(resolved === 'dark' ? 'appearance.lighter' : 'appearance.deeper') })) }}
              </p>

              <div class="derived-tokens" :aria-label="t('appearance.derivedAccent')">
                <span><i :style="{ background: customTokens.accent }"></i>--accent</span>
                <span><i :style="{ background: customTokens.soft }"></i>--accent-soft</span>
                <span><i :style="{ background: customTokens.border }"></i>--accent-border</span>
                <span><i class="halo-token" :style="{ '--halo-colour': customTokens.halo }"></i>--focus-halo</span>
              </div>
            </div>
          </section>

          <section class="panel appearance-card" aria-labelledby="background-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="background-heading">{{ t('appearance.background') }}</h2>
                <p class="muted">{{ t('appearance.backgroundBody') }}</p>
              </div>
              <span class="token-caption">--paper</span>
            </div>

            <UiSwatchGroup
              v-model="draft.background"
              class="appearance-swatches"
              :label="t('appearance.backgroundPresets')"
              name="theme_background"
              :options="backgroundSwatches"
              @update:model-value="backgroundError = null"
            />

            <div class="custom-colour" :class="{ 'is-selected': draft.background === 'custom' }">
              <div class="custom-colour__heading">
                <div>
                  <h3>{{ t('appearance.customBackground') }}</h3>
                  <p class="muted">{{ t('appearance.customBackgroundBody') }}</p>
                </div>
                <span class="contrast-pill" data-testid="background-contrast">
                  {{ backgroundRatio.toFixed(1) }}:1
                </span>
              </div>

              <div class="custom-colour__control">
                <label class="colour-well" :style="{ '--custom-colour': draft.background_hex }">
                  <span class="visually-hidden">{{ t('appearance.customBackground') }}</span>
                  <input type="color" :value="draft.background_hex" @input="pickNativeBackground" />
                </label>
                <label class="custom-hex-field">
                  <span>{{ t('appearance.backgroundHex') }}</span>
                  <input
                    v-model="backgroundDraft"
                    class="ui-control ui-control--text mono"
                    name="background_hex"
                    autocomplete="off"
                    spellcheck="false"
                    :aria-invalid="backgroundError ? true : undefined"
                    aria-describedby="custom-background-feedback"
                    @keydown.enter.prevent="chooseCustomBackground()"
                  />
                </label>
                <button type="button" @click="chooseCustomBackground()">{{ t('appearance.useBackground') }}</button>
              </div>

              <p id="custom-background-feedback" class="ui-field__helper" :class="{ 'ui-field__error': backgroundError }">
                {{ backgroundError ?? t('appearance.backgroundSafe', { ratio: backgroundRatio.toFixed(1) }) }}
              </p>

              <div class="derived-tokens" aria-hidden="true">
                <span><i :style="{ background: backgroundPalette.paper }"></i>--paper</span>
                <span><i :style="{ background: backgroundPalette.surface }"></i>--surface</span>
                <span><i :style="{ background: backgroundPalette.border }"></i>--border</span>
                <span><i :style="{ background: backgroundPalette.ink }"></i>--ink</span>
              </div>
            </div>
          </section>

          <section class="panel appearance-card appearance-switches" aria-labelledby="details-heading">
            <div class="appearance-card__heading">
              <div>
                <h2 id="details-heading">{{ t('appearance.interfaceDetails') }}</h2>
                <p class="muted">{{ t('appearance.interfaceBody') }}</p>
              </div>
            </div>
            <UiSwitch
              v-model="draft.texture"
              :label="t('appearance.texture')"
              name="theme_texture"
              :helper="t('appearance.textureHelper')"
            />
            <UiSwitch
              v-model="draft.mono_numerals"
              :label="t('appearance.mono')"
              name="theme_mono_numerals"
              :helper="t('appearance.monoHelper')"
            />
            <UiSegmented
              v-model="draft.motion"
              class="appearance-motion"
              :label="t('appearance.motion')"
              name="theme_motion"
              :options="motionOptions"
              :helper="t('appearance.motionHelper')"
            />
          </section>
        </div>

        <aside class="panel appearance-preview" aria-labelledby="preview-heading">
          <div class="appearance-card__heading">
            <h2 id="preview-heading">{{ t('appearance.preview') }}</h2>
            <span class="preview-scheme">{{ resolvedLabel }}</span>
          </div>
          <div class="preview-canvas">
            <div class="preview-card">
              <div class="preview-metric">
                <strong>{{ t('appearance.previewToday') }}</strong>
                <span class="mono">60%</span>
              </div>
              <div class="preview-progress"><span></span></div>
              <label class="preview-label">{{ t('appearance.previewRest') }}</label>
              <div class="preview-field">{{ t('appearance.previewTraining') }}</div>
              <label class="preview-label">{{ t('appearance.previewFocus') }} <small class="mono">{{ t('appearance.previewToggle') }}</small></label>
              <div class="preview-field is-focused">18:00</div>
              <div class="preview-buttons">
                <button type="button">{{ t('common.save') }}</button>
                <button type="button" class="secondary">{{ t('appearance.previewCancel') }}</button>
                <button type="button" class="ghost">{{ t('appearance.previewGhost') }}</button>
              </div>
              <div class="preview-chips">
                <span class="chip"><i></i>{{ t('appearance.previewGoal') }}</span>
                <span class="chip accent-chip mono">12d</span>
              </div>
              <div class="notice error">{{ t('appearance.previewError') }}</div>
            </div>
          </div>
          <div class="appearance-save">
            <button type="button" :disabled="saving || !dirty || Boolean(backgroundError)" @click="save">
              {{ saving ? t('common.saving') : t('appearance.save') }}
            </button>
            <button type="button" class="secondary" :disabled="saving" @click="reset">{{ t('common.reset') }}</button>
          </div>
          <p class="api-caption">PATCH /api/profile · preferences.theme</p>
          <p class="save-state" aria-live="polite">
            {{ dirty ? t('appearance.unsaved') : t('appearance.savedFor', { name: session.user?.name ?? t('appearance.yourProfile') }) }}
          </p>
        </aside>
      </div>
    </template>
  </section>
</template>
