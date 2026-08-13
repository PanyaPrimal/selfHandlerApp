<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { replaceRoutineActivities } from '../../api/client'
import type { Routine, RoutineActivityInput } from '../../api/types'
import { useI18n } from '../../i18n'
import { UiNumberInput, UiTextInput, UiTimeField } from '../ui'

const props = defineProps<{ routine: Routine }>()
const emit = defineEmits<{ saved: [] }>()
const i18n = useI18n()
const open = ref(false)
const saving = ref(false)
const error = ref<string | null>(null)
interface Draft {
  id?: number
  name: string
  preferred_time: string | null
  progress_total: number | null
}
const drafts = reactive<Draft[]>([])
const locked = computed(() => props.routine.activities.some((activity) => activity.has_facts))

function reset(): void {
  drafts.splice(0, drafts.length, ...props.routine.activities.map((activity) => ({
    id: activity.id,
    name: activity.name,
    preferred_time: activity.preferred_time?.slice(0, 5) ?? null,
    progress_total: activity.progress_total,
  })))
}

watch(() => props.routine.activities, reset, { immediate: true })

function toggle(): void {
  open.value = !open.value
  error.value = null
  if (open.value) reset()
}

function add(): void {
  drafts.push({ name: '', preferred_time: null, progress_total: null })
}

function remove(index: number): void {
  drafts.splice(index, 1)
}

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const payload: RoutineActivityInput[] = drafts.map((draft, index) => ({
      ...(draft.id ? { id: draft.id } : {}),
      name: draft.name,
      sort_order: index,
      preferred_time: draft.preferred_time,
      progress_total: draft.progress_total,
    }))
    await replaceRoutineActivities(props.routine.id, payload)
    open.value = false
    emit('saved')
  } catch (currentError) {
    error.value = currentError instanceof Error ? currentError.message : i18n.t('routine.activitiesSaveFailed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="activity-editor">
    <button type="button" class="secondary" :aria-expanded="open" :aria-label="i18n.t('routine.editActivitiesNamed', { name: routine.name })" @click="toggle">
      {{ i18n.t('routine.editActivities') }}
    </button>
    <form v-if="open" class="activity-editor-form" :aria-label="i18n.t('routine.activitiesNamed', { name: routine.name })" @submit.prevent="save">
      <p v-if="locked" class="muted">{{ i18n.t('routine.activitiesLocked') }}</p>
      <div v-if="error" class="notice error" role="alert">{{ error }}</div>
      <ol class="activity-draft-list">
        <li v-for="(draft, index) in drafts" :key="draft.id ?? `new-${index}`" class="form-grid activity-draft">
          <UiTextInput v-model="draft.name" :label="i18n.t('routine.activityNameNumber', { number: index + 1 })" :name="`activity-name-${routine.id}-${index}`" required :maxlength="160" />
          <UiTimeField v-model="draft.preferred_time" :label="i18n.t('routine.activityTimeNumber', { number: index + 1 })" :name="`activity-time-${routine.id}-${index}`" />
          <UiNumberInput v-model="draft.progress_total" :label="i18n.t('routine.activityTotalNumber', { number: index + 1 })" :name="`activity-total-${routine.id}-${index}`" :min="0.001" :max="9999999.999" :step="0.001" />
          <button type="button" class="secondary danger" :disabled="locked" :aria-label="i18n.t('routine.removeActivityNumber', { number: index + 1 })" @click="remove(index)">{{ i18n.t('common.remove') }}</button>
        </li>
      </ol>
      <div class="form-actions">
        <button type="button" class="secondary" :disabled="locked || drafts.length >= 100" @click="add">{{ i18n.t('routine.addActivity') }}</button>
        <button type="submit" :disabled="saving">{{ i18n.t(saving ? 'common.saving' : 'routine.saveActivities') }}</button>
      </div>
    </form>
  </div>
</template>
