<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  createTimeBlock,
  deleteTimeBlock,
  getPlannerDay,
  reschedulePlannerOccurrence,
  skipPlannerOccurrence,
  updateStorageItem,
  validationErrors,
  type ValidationErrors,
} from '../api/client'
import AsyncState from '../components/AsyncState.vue'
import { UiDatePicker, UiTextInput, UiTimeField } from '../components/ui'
import { addDays, formatDateForDisplay, parseCalendarDate, toDateString } from '../components/ui/calendar'
import type { PlannerEntry, PlannerSource } from '../api/types'
import { useI18n } from '../i18n'

const i18n = useI18n()
const router = useRouter()
const locale = i18n.locale

const isLoading = ref(true)
const loadError = ref<string | null>(null)
const error = ref<string | null>(null)
const feedback = ref<string | null>(null)
const isSubmitting = ref(false)

/** `null` until the first load, because only the server knows the user's today. */
const date = ref<string | null>(null)
const today = ref<string | null>(null)
const entries = ref<PlannerEntry[]>([])
const materializedUntil = ref<string | null>(null)
const beyondWindow = ref(false)

/** The entry whose "move to" field is open, keyed as `source:id`. */
const movingKey = ref<string | null>(null)
const moveTarget = ref<string | null>(null)
const moveError = ref<string | null>(null)

const showBlockForm = ref(false)
const blockTitle = ref('')
const blockStart = ref<string | null>(null)
const blockEnd = ref<string | null>(null)
const blockErrors = ref<ValidationErrors>({})

const sourceLabels = computed<Record<PlannerSource, string>>(() => ({
  routine: i18n.t('planner.routine'),
  habit: i18n.t('planner.habit'),
  storage: i18n.t('planner.task'),
  time_block: i18n.t('planner.block'),
}))

const isToday = computed(() => date.value !== null && date.value === today.value)

const heading = computed(() => {
  if (date.value === null) {
    return i18n.t('planner.yourDay')
  }

  if (isToday.value) {
    return i18n.t('nav.today')
  }

  return formatDateForDisplay(date.value, locale.value)
})

const timed = computed(() => entries.value.filter((entry) => entry.time !== null))
const untimed = computed(() => entries.value.filter((entry) => entry.time === null))

function keyFor(entry: PlannerEntry): string {
  return `${entry.source}:${entry.source_id}`
}

function metaText(entry: PlannerEntry): string | null {
  if (entry.source === 'time_block' && typeof entry.meta.ends_at === 'string') {
    return i18n.t('planner.until', { time: entry.meta.ends_at })
  }

  // A day that arrived here from another date should say so, or it looks like
  // the schedule itself changed.
  if ((entry.source === 'routine' || entry.source === 'habit') && typeof entry.meta.occurrence_date === 'string') {
    return entry.meta.occurrence_date === date.value ? null : i18n.t('planner.movedFrom', { date: entry.meta.occurrence_date })
  }

  return null
}

/** Step a whole calendar day without ever touching a UTC instant. */
function shiftDay(amount: number): void {
  const parsed = parseCalendarDate(date.value)

  if (parsed) {
    date.value = toDateString(addDays(parsed, amount))
  }
}

function goToToday(): void {
  date.value = today.value
}

async function load(): Promise<void> {
  isLoading.value = true
  loadError.value = null

  try {
    const day = await getPlannerDay(date.value ?? undefined)

    date.value = day.date
    today.value = day.today
    entries.value = day.entries
    materializedUntil.value = day.window.materialized_until
    beyondWindow.value = day.window.beyond
  } catch {
    loadError.value = i18n.t('planner.loadFailed')
  } finally {
    isLoading.value = false
  }
}

function startMove(entry: PlannerEntry): void {
  movingKey.value = keyFor(entry)
  moveError.value = null
  moveTarget.value = date.value
}

function cancelMove(): void {
  movingKey.value = null
  moveError.value = null
}

async function confirmMove(entry: PlannerEntry): Promise<void> {
  if (moveTarget.value === null || isSubmitting.value) {
    return
  }

  isSubmitting.value = true
  moveError.value = null
  error.value = null

  try {
    if (entry.source === 'routine' || entry.source === 'habit') {
      await reschedulePlannerOccurrence(entry.source_id, moveTarget.value)
    } else {
      // A task's date belongs to Storage, so the move goes through Storage.
      await updateStorageItem(entry.source_id, { due_on: moveTarget.value })
    }

    movingKey.value = null
    feedback.value = i18n.t('planner.movedTo', { date: moveTarget.value })
    await load()
  } catch (currentError) {
    const errors = validationErrors(currentError)
    // The refusals explain themselves: already done, in the past, or past the
    // window. Showing that text beats a generic failure.
    moveError.value = errors.rescheduled_to?.[0] ?? errors.due_on?.[0] ?? i18n.t('planner.moveFailed')
  } finally {
    isSubmitting.value = false
  }
}

async function putBack(entry: PlannerEntry): Promise<void> {
  isSubmitting.value = true
  error.value = null

  try {
    await reschedulePlannerOccurrence(entry.source_id, null)
    feedback.value = i18n.t('planner.putBackDone')
    await load()
  } catch {
    error.value = i18n.t('planner.putBackFailed')
  } finally {
    isSubmitting.value = false
  }
}

async function skip(entry: PlannerEntry): Promise<void> {
  isSubmitting.value = true
  error.value = null

  try {
    await skipPlannerOccurrence(entry.source_id)
    feedback.value = i18n.t('planner.skippedDone')
    await load()
  } catch {
    error.value = i18n.t('planner.skipFailed')
  } finally {
    isSubmitting.value = false
  }
}

async function openHabit(): Promise<void> {
  if (date.value) await router.push({ path: '/habits', query: { date: date.value } })
}

async function addBlock(): Promise<void> {
  if (isSubmitting.value || date.value === null) {
    return
  }

  isSubmitting.value = true
  blockErrors.value = {}
  error.value = null

  try {
    await createTimeBlock({
      title: blockTitle.value,
      block_date: date.value,
      starts_at: blockStart.value,
      ends_at: blockEnd.value,
    })

    blockTitle.value = ''
    blockStart.value = null
    blockEnd.value = null
    showBlockForm.value = false
    feedback.value = i18n.t('planner.blockAdded')
    await load()
  } catch (currentError) {
    blockErrors.value = validationErrors(currentError)

    if (Object.keys(blockErrors.value).length === 0) {
      error.value = i18n.t('planner.blockAddFailed')
    }
  } finally {
    isSubmitting.value = false
  }
}

async function removeBlock(entry: PlannerEntry): Promise<void> {
  error.value = null

  try {
    await deleteTimeBlock(entry.source_id)
    feedback.value = i18n.t('planner.blockDeleted')
    await load()
  } catch {
    error.value = i18n.t('planner.blockDeleteFailed')
  }
}

// Changing the day is a fresh read, never a filter over a cached one.
watch(date, (next, previous) => {
  if (previous !== null && next !== previous) {
    movingKey.value = null
    feedback.value = null
    void load()
  }
})

void load()
</script>

<template>
  <section class="view-stack planner-page">
    <header class="view-header">
      <div>
        <p class="eyebrow">{{ i18n.t('planner.eyebrow') }}</p>
        <h1>{{ heading }}</h1>
        <p class="muted">{{ i18n.t('planner.subtitle') }}</p>
      </div>
    </header>

    <nav class="planner-dates" :aria-label="i18n.t('planner.chooseDay')">
      <button type="button" @click="shiftDay(-1)">{{ i18n.t('planner.previousDay') }}</button>
      <button type="button" :disabled="isToday" @click="goToToday">{{ i18n.t('nav.today') }}</button>
      <button type="button" @click="shiftDay(1)">{{ i18n.t('planner.nextDay') }}</button>
      <UiDatePicker
        v-model="date"
        :label="i18n.t('planner.day')"
        name="planner-date"
        :locale="locale"
        :today="today"
      />
    </nav>

    <div v-if="error" class="notice error" role="alert">{{ error }}</div>
    <div v-if="feedback" class="notice success" role="status">{{ feedback }}</div>

    <AsyncState
      :loading="isLoading"
      :error="loadError"
      :loading-title="i18n.t('planner.loading')"
      panel
      @retry="load"
    >
      <p v-if="beyondWindow" class="notice" role="status">
        {{ i18n.t('planner.beyondWindow', { date: materializedUntil ?? '' }) }}
      </p>

      <section class="panel" aria-labelledby="day-heading">
        <div class="section-heading">
          <h2 id="day-heading">{{ i18n.t('planner.theDay') }}</h2>
          <span class="kind-chip">{{ i18n.plural(entries.length, { one: 'planner.planned.one', few: 'planner.planned.few', many: 'planner.planned.many', other: 'planner.planned.other' }) }}</span>
        </div>

        <p v-if="entries.length === 0 && !beyondWindow" class="muted">
          {{ i18n.t('planner.empty') }}
        </p>

        <ul v-else class="planner-list">
          <li
            v-for="entry in [...timed, ...untimed]"
            :key="keyFor(entry)"
            class="planner-entry"
            :aria-label="entry.title"
          >
            <div class="planner-entry__main">
              <span class="planner-entry__time">{{ entry.time ?? i18n.t('planner.anyTime') }}</span>
              <div class="planner-entry__body">
                <strong>{{ entry.title }}</strong>
                <p class="muted">
                  <span class="kind-chip">{{ sourceLabels[entry.source] }}</span>
                  <span v-if="entry.status !== 'planned'"> · {{ i18n.t(entry.status === 'done' ? 'planner.done' : entry.status === 'skipped' ? 'planner.skipped' : 'planner.changed') }}</span>
                  <span v-if="metaText(entry)"> · {{ metaText(entry) }}</span>
                </p>
              </div>
            </div>

            <div v-if="entry.actions.length > 0 || entry.source === 'habit'" class="planner-entry__actions">
              <button
                v-if="entry.source === 'habit'"
                type="button"
                class="secondary"
                @click="openHabit"
              >
                {{ i18n.t('planner.checkIn') }}
              </button>
              <button
                v-if="entry.actions.includes('skip')"
                type="button"
                class="secondary"
                :aria-label="i18n.t('planner.skipNamed', { name: entry.title })"
                :disabled="isSubmitting"
                @click="skip(entry)"
              >
                {{ i18n.t('planner.skip') }}
              </button>
              <button
                v-if="entry.actions.includes('reschedule') || entry.actions.includes('move')"
                type="button"
                class="secondary"
                :aria-label="i18n.t('planner.moveNamed', { name: entry.title })"
                :disabled="isSubmitting"
                @click="startMove(entry)"
              >
                {{ i18n.t('planner.move') }}
              </button>
              <button
                v-if="(entry.source === 'routine' || entry.source === 'habit') && entry.meta.rescheduled_to"
                type="button"
                class="secondary"
                :aria-label="i18n.t('planner.putBackNamed', { name: entry.title })"
                :disabled="isSubmitting"
                @click="putBack(entry)"
              >
                {{ i18n.t('planner.putBack') }}
              </button>
              <button
                v-if="entry.actions.includes('delete')"
                type="button"
                class="secondary"
                :aria-label="i18n.t('planner.deleteNamed', { name: entry.title })"
                :disabled="isSubmitting"
                @click="removeBlock(entry)"
              >
                {{ i18n.t('common.delete') }}
              </button>
            </div>

            <form
              v-if="movingKey === keyFor(entry)"
              class="planner-move"
              :aria-label="i18n.t('planner.moveNamed', { name: entry.title })"
              novalidate
              @submit.prevent="confirmMove(entry)"
            >
              <UiDatePicker
                v-model="moveTarget"
                :label="i18n.t('planner.moveTo')"
                name="move-to"
                :locale="locale"
                :today="today"
                :min="today"
                :error="moveError ?? undefined"
              />
              <div class="form-actions">
                <button type="submit" :disabled="isSubmitting">{{ i18n.t('planner.move') }}</button>
                <button type="button" class="ghost" @click="cancelMove">{{ i18n.t('common.cancel') }}</button>
              </div>
            </form>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="block-heading">
        <div class="section-heading">
          <h2 id="block-heading">{{ i18n.t('planner.timeBlocks') }}</h2>
          <button v-if="!showBlockForm" type="button" @click="showBlockForm = true">{{ i18n.t('planner.addBlock') }}</button>
        </div>

        <p class="muted">
          {{ i18n.t('planner.blocksHelp') }}
        </p>

        <form
          v-if="showBlockForm"
          class="planner-block-form"
          :aria-label="i18n.t('planner.addTimeBlock')"
          novalidate
          @submit.prevent="addBlock"
        >
          <UiTextInput
            v-model="blockTitle"
            :label="i18n.t('planner.what')"
            name="block-title"
            :maxlength="200"
            :placeholder="i18n.t('planner.blockExample')"
            :disabled="isSubmitting"
            :error="blockErrors.title?.[0]"
          />
          <UiTimeField
            v-model="blockStart"
            :label="i18n.t('planner.startsAt')"
            name="block-start"
            :helper="i18n.t('planner.timeOptional')"
            :disabled="isSubmitting"
            :error="blockErrors.starts_at?.[0]"
          />
          <UiTimeField
            v-model="blockEnd"
            :label="i18n.t('planner.endsAt')"
            name="block-end"
            :disabled="isSubmitting"
            :error="blockErrors.ends_at?.[0]"
          />
          <div class="form-actions">
            <button type="submit" :disabled="isSubmitting">{{ i18n.t(isSubmitting ? 'common.saving' : 'planner.addBlockAction') }}</button>
            <button type="button" class="ghost" @click="showBlockForm = false">{{ i18n.t('common.cancel') }}</button>
          </div>
        </form>
      </section>
    </AsyncState>
  </section>
</template>
