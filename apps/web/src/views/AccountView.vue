<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getProfile, updateProfile } from '../api/client'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { Profile, ProfileInput, ProfileOptions } from '../api/types'
import { logout, updateAuthenticatedUser, useAuthSession } from '../auth/session'
import {
  UiCombobox,
  UiDatePicker,
  UiNumberInput,
  UiSelect,
  UiTextInput,
} from '../components/ui'
import type { UiOption } from '../components/ui'
import {
  centimetersToMeters,
  feetInchesToMeters,
  gramsToKilograms,
  gramsToPounds,
  kilogramsToGrams,
  metersToCentimeters,
  metersToFeetInches,
  poundsToGrams,
} from '../lib/units'

const router = useRouter()
const session = useAuthSession()
const loading = ref(true)
const saving = ref(false)
const signingOut = ref(false)
const loadError = ref<string | null>(null)
const saveError = ref<string | null>(null)
const success = ref<string | null>(null)
const errors = ref<ValidationErrors>({})
const options = ref<ProfileOptions | null>(null)
const acceptedSnapshot = ref('')

const form = reactive<ProfileInput>({
  name: '', timezone: 'UTC', locale: 'en-GB', unit_system: 'metric', base_currency: 'UAH',
  recommendation_tone: 'neutral', bmr_formula: 'mifflin_st_jeor', date_of_birth: null,
  sex: null, height_meters: null, weight_grams: null, body_fat_percentage: null,
  baseline_activity: null,
})

const profile = ref<Profile | null>(null)
const locale = computed(() => form.locale)

/** Finite option lists come from the API so the client never invents a value. */
function labelled<T extends string>(values: readonly T[], labels?: Record<string, string>): UiOption<T>[] {
  return values.map((value) => ({ value, label: labels?.[value] ?? value }))
}

const timezoneOptions = computed(() => labelled(options.value?.timezones ?? []))
const localeOptions = computed(() => labelled(options.value?.locales ?? []))
const unitSystemOptions = computed(() =>
  labelled(options.value?.unit_systems ?? [], { metric: 'Metric', imperial: 'Imperial' }),
)
const currencyOptions = computed(() => labelled(options.value?.base_currencies ?? []))
const toneOptions = computed(() =>
  labelled(options.value?.recommendation_tones ?? [], {
    neutral: 'Neutral',
    friendly: 'Friendly',
    direct: 'Direct',
  }),
)
const sexOptions = computed(() =>
  labelled(options.value?.sexes ?? [], {
    female: 'Female',
    male: 'Male',
    unspecified: 'Unspecified',
  }),
)
const activityOptions = computed(() =>
  labelled(options.value?.baseline_activities ?? [], {
    sedentary: 'Sedentary',
    light: 'Light',
    moderate: 'Moderate',
    high: 'High',
  }),
)
const formulaOptions = computed(() =>
  labelled(options.value?.bmr_formulas ?? [], {
    mifflin_st_jeor: 'Mifflin-St Jeor',
    katch_mcardle: 'Katch-McArdle',
  }),
)
const userInitial = computed(() => form.name.trim().charAt(0).toUpperCase() || '?')
const dirty = computed(() => acceptedSnapshot.value !== '' && JSON.stringify(form) !== acceptedSnapshot.value)
const heightCm = computed({
  get: () => metersToCentimeters(form.height_meters),
  set: (value: number | string | null) => { form.height_meters = centimetersToMeters(nullableNumber(value)) },
})
const imperialHeight = computed(() => metersToFeetInches(form.height_meters))
const heightFeet = computed({
  get: () => imperialHeight.value.feet,
  set: (value: number | string | null) => {
    form.height_meters = feetInchesToMeters(nullableNumber(value), imperialHeight.value.inches)
  },
})
const heightInches = computed({
  get: () => imperialHeight.value.inches,
  set: (value: number | string | null) => {
    form.height_meters = feetInchesToMeters(imperialHeight.value.feet, nullableNumber(value))
  },
})
const weightKg = computed({
  get: () => gramsToKilograms(form.weight_grams),
  set: (value: number | string | null) => { form.weight_grams = kilogramsToGrams(nullableNumber(value)) },
})
const weightLb = computed({
  get: () => gramsToPounds(form.weight_grams),
  set: (value: number | string | null) => { form.weight_grams = poundsToGrams(nullableNumber(value)) },
})

function nullableNumber(value: number | string | null): number | null {
  if (value === null || value === '') return null
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : null
}

function accept(current: Profile): void {
  profile.value = current
  Object.assign(form, {
    name: current.user.name,
    timezone: current.timezone,
    locale: current.locale,
    unit_system: current.unit_system,
    base_currency: current.base_currency,
    recommendation_tone: current.recommendation_tone,
    bmr_formula: current.bmr_formula,
    date_of_birth: current.date_of_birth,
    sex: current.sex,
    height_meters: current.height_meters,
    weight_grams: current.weight_grams,
    body_fat_percentage: current.body_fat_percentage,
    baseline_activity: current.baseline_activity,
  })
  acceptedSnapshot.value = JSON.stringify(form)
  updateAuthenticatedUser(current.user)
}

async function load(): Promise<void> {
  loading.value = true
  loadError.value = null
  try {
    const response = await getProfile()
    options.value = response.options
    accept(response.data)
  } catch {
    loadError.value = 'Could not load your profile. Check the service and try again.'
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  if (saving.value || !dirty.value) return
  saving.value = true
  errors.value = {}
  saveError.value = null
  success.value = null

  try {
    const response = await updateProfile({ ...form })
    options.value = response.options
    accept(response.data)
    success.value = 'Profile saved.'
  } catch (currentError) {
    errors.value = validationErrors(currentError)
    if (Object.keys(errors.value).length > 0) {
      saveError.value = 'Check the highlighted fields. Nothing was saved.'
      await nextTick()
      const firstField = Object.keys(errors.value)[0]
      document.querySelector<HTMLElement>(`[data-field="${firstField}"]`)?.focus()
    } else if (currentError instanceof ApiError && currentError.status === 401) {
      await router.replace({ name: 'login' })
    } else {
      saveError.value = 'Could not save your profile. Your draft is still here; please try again.'
    }
  } finally {
    saving.value = false
  }
}

async function signOut(): Promise<void> {
  if (signingOut.value) return
  signingOut.value = true
  saveError.value = null
  try {
    await logout()
    await router.replace({ name: 'login' })
  } catch {
    saveError.value = 'Could not sign out. Check the service and try again.'
  } finally {
    signingOut.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="view-stack profile-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">Profile &amp; settings</p>
        <h1>Your personal baseline</h1>
      </div>
    </header>

    <div v-if="loading" class="state-block" role="status">Loading profile…</div>
    <div v-else-if="loadError" class="state-block error" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" @click="load">Retry</button>
    </div>

    <form v-else-if="options" class="profile-form" novalidate @submit.prevent="save">
      <div v-if="saveError" class="notice error" role="alert" aria-live="assertive">{{ saveError }}</div>
      <div v-if="success" class="notice success" role="status" aria-live="polite">{{ success }}</div>

      <section class="panel profile-section" aria-labelledby="identity-heading">
        <div class="account-identity">
          <span class="account-avatar" aria-hidden="true">{{ userInitial }}</span>
          <div>
            <h2 id="identity-heading">Identity</h2>
            <p class="muted">{{ session.user?.email }}</p>
          </div>
        </div>
        <UiTextInput
          v-model="form.name"
          label="Display name"
          name="name"
          :maxlength="100"
          autocomplete="name"
          :error="errors.name?.[0]"
        />
      </section>

      <section class="panel profile-section" aria-labelledby="regional-heading">
        <div>
          <h2 id="regional-heading">Regional preferences</h2>
          <p class="muted">Your timezone defines what “Today” means for this account.</p>
        </div>
        <div class="form-grid">
          <UiCombobox
            v-model="form.timezone"
            label="Timezone"
            name="timezone"
            :options="timezoneOptions"
            wide
            placeholder="Search time zones"
            helper="Type a city or region to narrow the list."
            :error="errors.timezone?.[0]"
          />
          <UiSelect
            v-model="form.locale"
            label="Language & date format"
            name="locale"
            :options="localeOptions"
            :error="errors.locale?.[0]"
          />
          <UiSelect
            v-model="form.unit_system"
            label="Units"
            name="unit_system"
            :options="unitSystemOptions"
            :error="errors.unit_system?.[0]"
          />
          <UiSelect
            v-model="form.base_currency"
            label="Base currency"
            name="base_currency"
            :options="currencyOptions"
            :error="errors.base_currency?.[0]"
          />
          <UiSelect
            v-model="form.recommendation_tone"
            label="Recommendation tone"
            name="recommendation_tone"
            :options="toneOptions"
            :error="errors.recommendation_tone?.[0]"
          />
        </div>
      </section>

      <section class="panel profile-section" aria-labelledby="baseline-heading">
        <div class="section-heading">
          <div>
            <h2 id="baseline-heading">Calculation baseline</h2>
            <p class="muted">Optional until you want personalised calculations.</p>
          </div>
          <span class="readiness-badge" :class="{ ready: profile?.calculation_ready }">
            {{ profile?.calculation_ready ? 'Ready' : 'Incomplete' }}
          </span>
        </div>
        <div class="form-grid">
          <UiDatePicker
            v-model="form.date_of_birth"
            label="Date of birth"
            name="date_of_birth"
            :locale="locale"
            :error="errors.date_of_birth?.[0]"
          />
          <UiSelect
            v-model="form.sex"
            label="Sex used by formula"
            name="sex"
            :options="sexOptions"
            nullable
            :error="errors.sex?.[0]"
          />

          <UiNumberInput
            v-if="form.unit_system === 'metric'"
            v-model="heightCm"
            label="Height (cm)"
            name="height_meters"
            :min="50"
            :max="300"
            :step="0.1"
            :error="errors.height_meters?.[0]"
          />
          <div v-else class="imperial-height">
            <UiNumberInput
              v-model="heightFeet"
              label="Height (ft)"
              name="height_meters"
              :min="1"
              :max="9"
              :error="errors.height_meters?.[0]"
            />
            <UiNumberInput
              v-model="heightInches"
              label="Inches"
              name="height_inches"
              :min="0"
              :max="11.9"
              :step="0.1"
            />
          </div>

          <UiNumberInput
            v-if="form.unit_system === 'metric'"
            v-model="weightKg"
            label="Weight (kg)"
            name="weight_grams"
            :min="20"
            :max="500"
            :step="0.01"
            :error="errors.weight_grams?.[0]"
          />
          <UiNumberInput
            v-else
            v-model="weightLb"
            label="Weight (lb)"
            name="weight_grams"
            :min="44"
            :max="1102"
            :step="0.1"
            :error="errors.weight_grams?.[0]"
          />

          <UiNumberInput
            v-model="form.body_fat_percentage"
            label="Body fat (%)"
            name="body_fat_percentage"
            :min="2"
            :max="75"
            :step="0.01"
            :error="errors.body_fat_percentage?.[0]"
          />
          <UiSelect
            v-model="form.baseline_activity"
            label="Non-sport activity"
            name="baseline_activity"
            :options="activityOptions"
            nullable
            :error="errors.baseline_activity?.[0]"
          />
          <UiSelect
            v-model="form.bmr_formula"
            label="Metabolic formula"
            name="bmr_formula"
            :options="formulaOptions"
            wide
            :error="errors.bmr_formula?.[0]"
          />
        </div>
        <p v-if="profile && !profile.calculation_ready" class="helper-text">Missing: {{ profile.missing_fields.join(', ') }}. Readiness updates after saving.</p>
      </section>

      <div class="profile-actions">
        <span class="muted">{{ dirty ? 'Unsaved changes' : 'All changes saved' }}</span>
        <button type="submit" :disabled="saving || !dirty">{{ saving ? 'Saving…' : 'Save profile' }}</button>
      </div>
    </form>

    <section v-if="!loading" class="panel danger-zone">
      <div><h2>Session</h2><p class="muted">Sign out of this browser.</p></div>
      <button type="button" class="secondary account-signout" :disabled="signingOut" @click="signOut">{{ signingOut ? 'Signing out…' : 'Sign out' }}</button>
    </section>
  </section>
</template>
