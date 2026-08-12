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
import { useI18n } from '../i18n'
import type { MessageKey } from '../i18n/locales/en'
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

type AccountDraft = Omit<ProfileInput, 'locale'>

const form = reactive<AccountDraft>({
  name: '', timezone: 'UTC', unit_system: 'metric', base_currency: 'UAH',
  recommendation_tone: 'neutral', bmr_formula: 'mifflin_st_jeor', date_of_birth: null,
  sex: null, height_meters: null, weight_grams: null, body_fat_percentage: null,
  baseline_activity: null,
})

const profile = ref<Profile | null>(null)
const i18n = useI18n()
const locale = i18n.locale

/** Finite option lists come from the API so the client never invents a value. */
function labelled<T extends string>(values: readonly T[], labels?: Record<string, string>): UiOption<T>[] {
  return values.map((value) => ({ value, label: labels?.[value] ?? value }))
}

const timezoneOptions = computed(() => labelled(options.value?.timezones ?? []))
const unitSystemOptions = computed(() =>
  labelled(options.value?.unit_systems ?? [], { metric: i18n.t('account.metric'), imperial: i18n.t('account.imperial') }),
)
const currencyOptions = computed(() => labelled(options.value?.base_currencies ?? []))
const toneOptions = computed(() =>
  labelled(options.value?.recommendation_tones ?? [], {
    neutral: i18n.t('account.neutral'),
    friendly: i18n.t('account.friendly'),
    direct: i18n.t('account.direct'),
  }),
)
const sexOptions = computed(() =>
  labelled(options.value?.sexes ?? [], {
    female: i18n.t('account.female'),
    male: i18n.t('account.male'),
    unspecified: i18n.t('account.unspecified'),
  }),
)
const activityOptions = computed(() =>
  labelled(options.value?.baseline_activities ?? [], {
    sedentary: i18n.t('account.sedentary'),
    light: i18n.t('account.light'),
    moderate: i18n.t('account.moderate'),
    high: i18n.t('account.high'),
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
const missingFieldKeys: Record<string, MessageKey> = {
  date_of_birth: 'account.dateOfBirth',
  sex: 'account.sex',
  height_meters: 'account.heightCm',
  weight_grams: 'account.weightKg',
  body_fat_percentage: 'account.bodyFat',
  baseline_activity: 'account.activity',
}
const missingFields = computed(() => (profile.value?.missing_fields ?? [])
  .map((field) => i18n.t(missingFieldKeys[field] ?? 'account.unknownField'))
  .join(', '))

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
    loadError.value = i18n.t('account.loadFailed')
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
    const response = await updateProfile({
      ...form,
      locale: session.user?.preferences.locale ?? i18n.locale.value,
    })
    options.value = response.options
    accept(response.data)
    success.value = i18n.t('account.saved')
  } catch (currentError) {
    errors.value = validationErrors(currentError)
    if (Object.keys(errors.value).length > 0) {
      saveError.value = i18n.t('account.invalid')
      await nextTick()
      const firstField = Object.keys(errors.value)[0]
      document.querySelector<HTMLElement>(`[data-field="${firstField}"]`)?.focus()
    } else if (currentError instanceof ApiError && currentError.status === 401) {
      await router.replace({ name: 'login' })
    } else {
      saveError.value = i18n.t('account.saveFailed')
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
    saveError.value = i18n.t('account.signOutFailed')
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
        <p class="eyebrow">{{ i18n.t('account.eyebrow') }}</p>
        <h1>{{ i18n.t('account.title') }}</h1>
      </div>
    </header>

    <div v-if="loading" class="state-block" role="status">{{ i18n.t('account.loading') }}</div>
    <div v-else-if="loadError" class="state-block error" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" @click="load">{{ i18n.t('common.retry') }}</button>
    </div>

    <form v-else-if="options" class="profile-form" novalidate @submit.prevent="save">
      <div v-if="saveError" class="notice error" role="alert" aria-live="assertive">{{ saveError }}</div>
      <div v-if="success" class="notice success" role="status" aria-live="polite">{{ success }}</div>

      <section class="panel profile-section" aria-labelledby="identity-heading">
        <div class="account-identity">
          <span class="account-avatar" aria-hidden="true">{{ userInitial }}</span>
          <div>
            <h2 id="identity-heading">{{ i18n.t('account.identity') }}</h2>
            <p class="muted">{{ session.user?.email }}</p>
          </div>
        </div>
        <UiTextInput
          v-model="form.name"
          :label="i18n.t('account.displayName')"
          name="name"
          :maxlength="100"
          autocomplete="name"
          :error="errors.name?.[0]"
        />
      </section>

      <section class="panel profile-section" aria-labelledby="regional-heading">
        <div>
          <h2 id="regional-heading">{{ i18n.t('account.regional') }}</h2>
          <p class="muted">{{ i18n.t('account.timezoneMeaning') }}</p>
        </div>
        <div class="form-grid">
          <UiCombobox
            v-model="form.timezone"
            :label="i18n.t('account.timezone')"
            name="timezone"
            :options="timezoneOptions"
            wide
            :placeholder="i18n.t('account.searchTimezones')"
            :helper="i18n.t('account.searchTimezonesHelp')"
            :error="errors.timezone?.[0]"
          />
          <UiSelect
            v-model="form.unit_system"
            :label="i18n.t('account.units')"
            name="unit_system"
            :options="unitSystemOptions"
            :error="errors.unit_system?.[0]"
          />
          <UiSelect
            v-model="form.base_currency"
            :label="i18n.t('account.currency')"
            name="base_currency"
            :options="currencyOptions"
            :error="errors.base_currency?.[0]"
          />
          <UiSelect
            v-model="form.recommendation_tone"
            :label="i18n.t('account.tone')"
            name="recommendation_tone"
            :options="toneOptions"
            :error="errors.recommendation_tone?.[0]"
          />
        </div>
      </section>

      <section class="panel profile-section" aria-labelledby="baseline-heading">
        <div class="section-heading">
          <div>
            <h2 id="baseline-heading">{{ i18n.t('account.baseline') }}</h2>
            <p class="muted">{{ i18n.t('account.baselineHelp') }}</p>
          </div>
          <span class="readiness-badge" :class="{ ready: profile?.calculation_ready }">
            {{ i18n.t(profile?.calculation_ready ? 'account.ready' : 'account.incomplete') }}
          </span>
        </div>
        <div class="form-grid">
          <UiDatePicker
            v-model="form.date_of_birth"
            :label="i18n.t('account.dateOfBirth')"
            name="date_of_birth"
            :locale="locale"
            :error="errors.date_of_birth?.[0]"
          />
          <UiSelect
            v-model="form.sex"
            :label="i18n.t('account.sex')"
            name="sex"
            :options="sexOptions"
            nullable
            :error="errors.sex?.[0]"
          />

          <UiNumberInput
            v-if="form.unit_system === 'metric'"
            v-model="heightCm"
            :label="i18n.t('account.heightCm')"
            name="height_meters"
            :min="50"
            :max="300"
            :step="0.1"
            :error="errors.height_meters?.[0]"
          />
          <div v-else class="imperial-height">
            <UiNumberInput
              v-model="heightFeet"
              :label="i18n.t('account.heightFt')"
              name="height_meters"
              :min="1"
              :max="9"
              :error="errors.height_meters?.[0]"
            />
            <UiNumberInput
              v-model="heightInches"
              :label="i18n.t('account.inches')"
              name="height_inches"
              :min="0"
              :max="11.9"
              :step="0.1"
            />
          </div>

          <UiNumberInput
            v-if="form.unit_system === 'metric'"
            v-model="weightKg"
            :label="i18n.t('account.weightKg')"
            name="weight_grams"
            :min="20"
            :max="500"
            :step="0.01"
            :error="errors.weight_grams?.[0]"
          />
          <UiNumberInput
            v-else
            v-model="weightLb"
            :label="i18n.t('account.weightLb')"
            name="weight_grams"
            :min="44"
            :max="1102"
            :step="0.1"
            :error="errors.weight_grams?.[0]"
          />

          <UiNumberInput
            v-model="form.body_fat_percentage"
            :label="i18n.t('account.bodyFat')"
            name="body_fat_percentage"
            :min="2"
            :max="75"
            :step="0.01"
            :error="errors.body_fat_percentage?.[0]"
          />
          <UiSelect
            v-model="form.baseline_activity"
            :label="i18n.t('account.activity')"
            name="baseline_activity"
            :options="activityOptions"
            nullable
            :error="errors.baseline_activity?.[0]"
          />
          <UiSelect
            v-model="form.bmr_formula"
            :label="i18n.t('account.formula')"
            name="bmr_formula"
            :options="formulaOptions"
            wide
            :error="errors.bmr_formula?.[0]"
          />
        </div>
        <p v-if="profile && !profile.calculation_ready" class="helper-text">{{ i18n.t('account.missing', { fields: missingFields }) }}</p>
      </section>

      <div class="profile-actions">
        <span class="muted">{{ i18n.t(dirty ? 'account.unsaved' : 'account.allSaved') }}</span>
        <button type="submit" :disabled="saving || !dirty">{{ i18n.t(saving ? 'account.saving' : 'account.save') }}</button>
      </div>
    </form>

    <section v-if="!loading" class="panel danger-zone">
      <div><h2>{{ i18n.t('account.session') }}</h2><p class="muted">{{ i18n.t('account.sessionHelp') }}</p></div>
      <button type="button" class="secondary account-signout" :disabled="signingOut" @click="signOut">{{ i18n.t(signingOut ? 'account.signingOut' : 'account.signOut') }}</button>
    </section>
  </section>
</template>
