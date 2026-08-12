<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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
import { useAuthSession } from '../auth/session'
import type { PlannerEntry, PlannerSource } from '../api/types'

const session = useAuthSession()
const locale = computed(() => session.user?.preferences.locale ?? 'en-GB')

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

const sourceLabels: Record<PlannerSource, string> = {
  routine: 'Routine',
  storage: 'Task',
  time_block: 'Block',
}

const isToday = computed(() => date.value !== null && date.value === today.value)

const heading = computed(() => {
  if (date.value === null) {
    return 'Your day'
  }

  if (isToday.value) {
    return 'Today'
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
    return `until ${entry.meta.ends_at}`
  }

  // A day that arrived here from another date should say so, or it looks like
  // the schedule itself changed.
  if (entry.source === 'routine' && typeof entry.meta.occurrence_date === 'string') {
    return entry.meta.occurrence_date === date.value ? null : `moved from ${entry.meta.occurrence_date}`
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
    loadError.value = 'Could not load your day. Check the service and try again.'
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
    if (entry.source === 'routine') {
      await reschedulePlannerOccurrence(entry.source_id, moveTarget.value)
    } else {
      // A task's date belongs to Storage, so the move goes through Storage.
      await updateStorageItem(entry.source_id, { due_on: moveTarget.value })
    }

    movingKey.value = null
    feedback.value = `Moved to ${moveTarget.value}.`
    await load()
  } catch (currentError) {
    const errors = validationErrors(currentError)
    // The refusals explain themselves: already done, in the past, or past the
    // window. Showing that text beats a generic failure.
    moveError.value = errors.rescheduled_to?.[0] ?? errors.due_on?.[0] ?? 'Could not move that.'
  } finally {
    isSubmitting.value = false
  }
}

async function putBack(entry: PlannerEntry): Promise<void> {
  isSubmitting.value = true
  error.value = null

  try {
    await reschedulePlannerOccurrence(entry.source_id, null)
    feedback.value = 'Put back on its original day.'
    await load()
  } catch {
    error.value = 'Could not put that back.'
  } finally {
    isSubmitting.value = false
  }
}

async function skip(entry: PlannerEntry): Promise<void> {
  isSubmitting.value = true
  error.value = null

  try {
    await skipPlannerOccurrence(entry.source_id)
    feedback.value = 'Marked as skipped.'
    await load()
  } catch {
    error.value = 'Could not record that skip.'
  } finally {
    isSubmitting.value = false
  }
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
    feedback.value = 'Block added.'
    await load()
  } catch (currentError) {
    blockErrors.value = validationErrors(currentError)

    if (Object.keys(blockErrors.value).length === 0) {
      error.value = 'Could not add that block.'
    }
  } finally {
    isSubmitting.value = false
  }
}

async function removeBlock(entry: PlannerEntry): Promise<void> {
  error.value = null

  try {
    await deleteTimeBlock(entry.source_id)
    feedback.value = 'Block deleted.'
    await load()
  } catch {
    error.value = 'Could not delete that block.'
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
        <p class="eyebrow">Planner</p>
        <h1>{{ heading }}</h1>
        <p class="muted">Everything you have committed to on one day, from wherever it lives.</p>
      </div>
    </header>

    <nav class="planner-dates" aria-label="Choose a day">
      <button type="button" @click="shiftDay(-1)">Previous day</button>
      <button type="button" :disabled="isToday" @click="goToToday">Today</button>
      <button type="button" @click="shiftDay(1)">Next day</button>
      <UiDatePicker
        v-model="date"
        label="Day"
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
      loading-title="Loading your day…"
      panel
      @retry="load"
    >
      <p v-if="beyondWindow" class="notice" role="status">
        Recurring days are only planned out to {{ materializedUntil }}, so this day is not filled in yet
        rather than empty.
      </p>

      <section class="panel" aria-labelledby="day-heading">
        <div class="section-heading">
          <h2 id="day-heading">The day</h2>
          <span class="kind-chip">{{ entries.length }} planned</span>
        </div>

        <p v-if="entries.length === 0 && !beyondWindow" class="muted">
          Nothing planned for this day.
        </p>

        <ul v-else class="planner-list">
          <li
            v-for="entry in [...timed, ...untimed]"
            :key="keyFor(entry)"
            class="planner-entry"
            :aria-label="entry.title"
          >
            <div class="planner-entry__main">
              <span class="planner-entry__time">{{ entry.time ?? 'Any time' }}</span>
              <div class="planner-entry__body">
                <strong>{{ entry.title }}</strong>
                <p class="muted">
                  <span class="kind-chip">{{ sourceLabels[entry.source] }}</span>
                  <span v-if="entry.status !== 'planned'"> · {{ entry.status }}</span>
                  <span v-if="metaText(entry)"> · {{ metaText(entry) }}</span>
                </p>
              </div>
            </div>

            <div v-if="entry.actions.length > 0" class="planner-entry__actions">
              <button
                v-if="entry.actions.includes('skip')"
                type="button"
                class="secondary"
                :aria-label="`Skip ${entry.title}`"
                :disabled="isSubmitting"
                @click="skip(entry)"
              >
                Skip
              </button>
              <button
                v-if="entry.actions.includes('reschedule') || entry.actions.includes('move')"
                type="button"
                class="secondary"
                :aria-label="`Move ${entry.title}`"
                :disabled="isSubmitting"
                @click="startMove(entry)"
              >
                Move
              </button>
              <button
                v-if="entry.source === 'routine' && entry.meta.rescheduled_to"
                type="button"
                class="secondary"
                :aria-label="`Put ${entry.title} back`"
                :disabled="isSubmitting"
                @click="putBack(entry)"
              >
                Put back
              </button>
              <button
                v-if="entry.actions.includes('delete')"
                type="button"
                class="secondary"
                :aria-label="`Delete ${entry.title}`"
                :disabled="isSubmitting"
                @click="removeBlock(entry)"
              >
                Delete
              </button>
            </div>

            <form
              v-if="movingKey === keyFor(entry)"
              class="planner-move"
              :aria-label="`Move ${entry.title}`"
              novalidate
              @submit.prevent="confirmMove(entry)"
            >
              <UiDatePicker
                v-model="moveTarget"
                label="Move to"
                name="move-to"
                :locale="locale"
                :today="today"
                :min="today"
                :error="moveError ?? undefined"
              />
              <div class="form-actions">
                <button type="submit" :disabled="isSubmitting">Move</button>
                <button type="button" class="ghost" @click="cancelMove">Cancel</button>
              </div>
            </form>
          </li>
        </ul>
      </section>

      <section class="panel" aria-labelledby="block-heading">
        <div class="section-heading">
          <h2 id="block-heading">Time blocks</h2>
          <button v-if="!showBlockForm" type="button" @click="showBlockForm = true">Add a block</button>
        </div>

        <p class="muted">
          An appointment or a stretch of time that belongs to this day only. Overlaps are allowed.
        </p>

        <form
          v-if="showBlockForm"
          class="planner-block-form"
          aria-label="Add a time block"
          novalidate
          @submit.prevent="addBlock"
        >
          <UiTextInput
            v-model="blockTitle"
            label="What is it?"
            name="block-title"
            :maxlength="200"
            placeholder="Dentist"
            :disabled="isSubmitting"
            :error="blockErrors.title?.[0]"
          />
          <UiTimeField
            v-model="blockStart"
            label="Starts at"
            name="block-start"
            helper="Optional. Leave it empty if the time is not settled."
            :disabled="isSubmitting"
            :error="blockErrors.starts_at?.[0]"
          />
          <UiTimeField
            v-model="blockEnd"
            label="Ends at"
            name="block-end"
            :disabled="isSubmitting"
            :error="blockErrors.ends_at?.[0]"
          />
          <div class="form-actions">
            <button type="submit" :disabled="isSubmitting">{{ isSubmitting ? 'Saving…' : 'Add block' }}</button>
            <button type="button" class="ghost" @click="showBlockForm = false">Cancel</button>
          </div>
        </form>
      </section>
    </AsyncState>
  </section>
</template>
