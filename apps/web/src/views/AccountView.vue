<script setup lang="ts">
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { getProfile, updateProfile } from '../api/client'
import { ApiError, validationErrors, type ValidationErrors } from '../api/http'
import type { Profile, ProfileInput, ProfileOptions } from '../api/types'
import { logout, updateAuthenticatedUser, useAuthSession } from '../auth/session'
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
        <label class="field">
          <span>Display name</span>
          <input v-model="form.name" data-field="name" maxlength="100" autocomplete="name" :aria-invalid="!!errors.name" />
          <span v-if="errors.name" class="field-error">{{ errors.name[0] }}</span>
        </label>
      </section>

      <section class="panel profile-section" aria-labelledby="regional-heading">
        <div>
          <h2 id="regional-heading">Regional preferences</h2>
          <p class="muted">Your timezone defines what “Today” means for this account.</p>
        </div>
        <div class="form-grid">
          <label class="field wide-field">
            <span>Timezone</span>
            <select v-model="form.timezone" data-field="timezone" :aria-invalid="!!errors.timezone">
              <option v-for="timezone in options.timezones" :key="timezone" :value="timezone">{{ timezone }}</option>
            </select>
            <span v-if="errors.timezone" class="field-error">{{ errors.timezone[0] }}</span>
          </label>
          <label class="field"><span>Language &amp; date format</span><select v-model="form.locale" data-field="locale"><option v-for="value in options.locales" :key="value" :value="value">{{ value }}</option></select></label>
          <label class="field"><span>Units</span><select v-model="form.unit_system" data-field="unit_system"><option v-for="value in options.unit_systems" :key="value" :value="value">{{ value }}</option></select></label>
          <label class="field"><span>Base currency</span><select v-model="form.base_currency" data-field="base_currency"><option v-for="value in options.base_currencies" :key="value" :value="value">{{ value }}</option></select></label>
          <label class="field"><span>Recommendation tone</span><select v-model="form.recommendation_tone" data-field="recommendation_tone"><option v-for="value in options.recommendation_tones" :key="value" :value="value">{{ value }}</option></select></label>
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
          <label class="field"><span>Date of birth</span><input v-model="form.date_of_birth" data-field="date_of_birth" type="date" :aria-invalid="!!errors.date_of_birth" /><span v-if="errors.date_of_birth" class="field-error">{{ errors.date_of_birth[0] }}</span></label>
          <label class="field"><span>Sex used by formula</span><select v-model="form.sex" data-field="sex"><option :value="null">Not set</option><option v-for="value in options.sexes" :key="value" :value="value">{{ value }}</option></select></label>

          <label v-if="form.unit_system === 'metric'" class="field"><span>Height (cm)</span><input v-model="heightCm" data-field="height_meters" type="number" min="50" max="300" step="0.1" /><span v-if="errors.height_meters" class="field-error">{{ errors.height_meters[0] }}</span></label>
          <div v-else class="imperial-height">
            <label class="field"><span>Height (ft)</span><input v-model="heightFeet" data-field="height_meters" type="number" min="1" max="9" /></label>
            <label class="field"><span>Inches</span><input v-model="heightInches" type="number" min="0" max="11.9" step="0.1" /></label>
            <span v-if="errors.height_meters" class="field-error">{{ errors.height_meters[0] }}</span>
          </div>

          <label v-if="form.unit_system === 'metric'" class="field"><span>Weight (kg)</span><input v-model="weightKg" data-field="weight_grams" type="number" min="20" max="500" step="0.01" /><span v-if="errors.weight_grams" class="field-error">{{ errors.weight_grams[0] }}</span></label>
          <label v-else class="field"><span>Weight (lb)</span><input v-model="weightLb" data-field="weight_grams" type="number" min="44" max="1102" step="0.1" /><span v-if="errors.weight_grams" class="field-error">{{ errors.weight_grams[0] }}</span></label>

          <label class="field"><span>Body fat (%)</span><input v-model="form.body_fat_percentage" data-field="body_fat_percentage" type="number" min="2" max="75" step="0.01" :aria-invalid="!!errors.body_fat_percentage" /><span v-if="errors.body_fat_percentage" class="field-error">{{ errors.body_fat_percentage[0] }}</span></label>
          <label class="field"><span>Non-sport activity</span><select v-model="form.baseline_activity" data-field="baseline_activity"><option :value="null">Not set</option><option v-for="value in options.baseline_activities" :key="value" :value="value">{{ value }}</option></select></label>
          <label class="field wide-field"><span>Metabolic formula</span><select v-model="form.bmr_formula" data-field="bmr_formula"><option v-for="value in options.bmr_formulas" :key="value" :value="value">{{ value === 'mifflin_st_jeor' ? 'Mifflin–St Jeor' : 'Katch–McArdle' }}</option></select><span v-if="errors.bmr_formula" class="field-error">{{ errors.bmr_formula[0] }}</span></label>
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
