<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import {
  createExercise,
  createTrainingGoal,
  createWorkout,
  createWorkoutProgram,
  deleteWorkout,
  getExercises,
  getTrainingGoals,
  getWorkoutPrograms,
  getWorkouts,
  replaceWorkoutProgramExercises,
  updateExercise,
  updateTrainingGoal,
  updateWorkout,
  updateWorkoutProgram,
  upsertPlannedWorkout,
} from '../api/client'
import type {
  Exercise,
  TrainingGoal,
  TrainingGoalKind,
  Weekday,
  WorkoutHistoryResponse,
  WorkoutIntensity,
  WorkoutProgram,
  WorkoutSession,
  WorkoutState,
  WorkoutType,
} from '../api/types'
import AsyncState from '../components/AsyncState.vue'
import { UiDatePicker, UiNumberInput, UiSegmented, UiSelect, UiTextInput, UiTimeField, UiToggleGroup } from '../components/ui'
import type { UiOption } from '../components/ui'
import { useI18n } from '../i18n'

interface PrescriptionDraft {
  exerciseId: number | null
  startingWeight: number | null
}

interface PlannedDraft {
  durationMinutes: number | null
  distanceKm: number | null
  activityName: string
  strength: StrengthExerciseDraft[]
}

interface StrengthSetDraft {
  weight: number | null
  reps: number | null
}

interface StrengthExerciseDraft {
  exerciseId: number | null
  simpleWeight: number | null
  simpleReps: number | null
  sets: StrengthSetDraft[]
}

const route = useRoute()
const i18n = useI18n()
const locale = i18n.locale
const isLoading = ref(true)
const isSaving = ref(false)
const loadFailed = ref(false)
const error = ref<string | null>(null)
const feedback = ref<string | null>(null)
const selectedDate = ref<string | null>(typeof route.query.date === 'string' ? route.query.date : null)
const today = ref<string | null>(null)
const state = ref<WorkoutState>('active')
const exerciseState = ref<'active' | 'archived'>('active')
const goalArchived = ref(false)
const exercises = ref<Exercise[]>([])
const programs = ref<WorkoutProgram[]>([])
const history = ref<WorkoutHistoryResponse | null>(null)
const goals = ref<TrainingGoal[]>([])
const editingProgramId = ref<number | null>(null)
const editingExerciseId = ref<number | null>(null)
const editingProgramDetailsId = ref<number | null>(null)
const editingGoalId = ref<number | null>(null)
const editingSessionId = ref<number | null>(null)
const prescriptions = reactive<Record<number, PrescriptionDraft[]>>({})
const planned = reactive<Record<number, PlannedDraft>>({})
let loadSequence = 0
let saveQueue: Promise<void> = Promise.resolve()

const exerciseForm = reactive({ name: '', muscleGroup: '', equipment: '' })
const exerciseDraft = reactive({ name: '', muscleGroup: '', equipment: '' })
const programForm = reactive({
  name: '', type: 'strength' as WorkoutType, intensity: 'moderate' as WorkoutIntensity,
  preferredTime: null as string | null, scheduleType: 'daily' as 'daily' | 'weekdays',
  weekdays: [] as Weekday[], plannedDurationMinutes: null as number | null,
  activity: 'running', runType: 'easy', targetDistanceKm: null as number | null, activityName: '',
})
const programDraft = reactive({
  name: '', intensity: 'moderate' as WorkoutIntensity, preferredTime: null as string | null,
  scheduleType: 'daily' as 'daily' | 'weekdays', weekdays: [] as Weekday[],
  plannedDurationMinutes: null as number | null, activity: 'running', runType: 'easy',
  targetDistanceKm: null as number | null, activityName: '',
})
const manualForm = reactive({
  name: '', type: 'cardio' as WorkoutType, date: null as string | null,
  durationMinutes: null as number | null, distanceKm: null as number | null,
  activityName: '', activity: 'running', runType: 'easy', exerciseId: null as number | null,
  weight: null as number | null, reps: null as number | null,
})
const goalForm = reactive({
  name: '', kind: 'distance' as TrainingGoalKind, target: null as number | null,
  exerciseId: null as number | null, programId: null as number | null,
  targetDate: null as string | null,
})
const goalDraft = reactive({ name: '', target: null as number | null, targetDate: null as string | null })
const correction = reactive({
  distanceKm: null as number | null,
  durationMinutes: null as number | null,
  activityName: '',
  strength: [] as StrengthExerciseDraft[],
})

const workoutTypeOptions = computed<UiOption<WorkoutType>[]>(() => [
  { value: 'strength', label: i18n.t('workouts.type.strength') },
  { value: 'cardio', label: i18n.t('workouts.type.cardio') },
  { value: 'flexibility', label: i18n.t('workouts.type.flexibility') },
  { value: 'sport', label: i18n.t('workouts.type.sport') },
])
const intensityOptions = computed<UiOption<WorkoutIntensity>[]>(() => [
  { value: 'light', label: i18n.t('workouts.intensity.light') },
  { value: 'moderate', label: i18n.t('workouts.intensity.moderate') },
  { value: 'vigorous', label: i18n.t('workouts.intensity.vigorous') },
])
const goalKindOptions = computed<UiOption<TrainingGoalKind>[]>(() => [
  { value: 'strength', label: i18n.t('workouts.goalKind.strength') },
  { value: 'distance', label: i18n.t('workouts.goalKind.distance') },
  { value: 'race', label: i18n.t('workouts.goalKind.race') },
  { value: 'consistency', label: i18n.t('workouts.goalKind.consistency') },
])
const scheduleOptions = computed<UiOption<'daily' | 'weekdays'>[]>(() => [
  { value: 'daily', label: i18n.t('routine.daily') },
  { value: 'weekdays', label: i18n.t('routine.byWeekdays') },
])
const weekdayOptions = computed<UiOption<Weekday>[]>(() =>
  (['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'] as Weekday[]).map((day) => ({
    value: day,
    label: i18n.t(`weekday.${day}` as 'weekday.MO'),
  })),
)
const activityOptions = computed<UiOption<string>[]>(() => [
  'running', 'cycling', 'walking', 'swimming', 'other',
].map((activity) => ({ value: activity, label: i18n.t(`workouts.activity.${activity}` as 'workouts.activity.running') })))
const runTypeOptions = computed<UiOption<string>[]>(() => [
  'easy', 'tempo', 'intervals', 'long',
].map((runType) => ({ value: runType, label: i18n.t(`workouts.runType.${runType}` as 'workouts.runType.easy') })))
const catalogueExercises = computed(() => exercises.value.filter((exercise) =>
  exerciseState.value === 'archived' ? !exercise.is_builtin && exercise.is_archived : !exercise.is_archived,
))
const exerciseOptions = computed<UiOption<number>[]>(() => exercises.value.filter((exercise) => !exercise.is_archived).map((exercise) => ({
  value: exercise.id,
  label: exerciseLabel(exercise),
})))
const programOptions = computed<UiOption<number>[]>(() => programs.value.map((program) => ({
  value: program.id,
  label: program.name,
})))

function exerciseLabel(exercise: Exercise): string {
  return exercise.system_key
    ? i18n.t(`workouts.exercise.${exercise.system_key}` as 'workouts.exercise.squat')
    : exercise.name
}

function showError(currentError: unknown, fallback = 'workouts.saveFailed'): void {
  error.value = currentError instanceof Error ? currentError.message : i18n.t(fallback as 'workouts.saveFailed')
}

function clearMessages(): void {
  error.value = null
  feedback.value = null
}

async function loadAll(): Promise<void> {
  const sequence = ++loadSequence
  isLoading.value = true
  loadFailed.value = false
  error.value = null
  try {
    const [catalogue, programResponse, goalResponse] = await Promise.all([
      getExercises('all'),
      getWorkoutPrograms(state.value, selectedDate.value ?? undefined),
      getTrainingGoals(goalArchived.value),
    ])
    if (sequence !== loadSequence) return
    exercises.value = catalogue.data
    programs.value = programResponse.data
    today.value = programResponse.today
    selectedDate.value = programResponse.date
    manualForm.date ??= programResponse.date
    const sessionResponse = await getWorkouts(programResponse.date, programResponse.date)
    if (sequence !== loadSequence) return
    history.value = sessionResponse
    goals.value = goalResponse.data
    for (const program of programs.value) {
      planned[program.id] = plannedDraftFor(program, sessionResponse.data)
    }
    manualForm.exerciseId ??= exerciseOptions.value[0]?.value ?? null
  } catch (currentError) {
    if (sequence !== loadSequence) return
    loadFailed.value = true
    showError(currentError, 'workouts.loadFailed')
  } finally {
    if (sequence === loadSequence) isLoading.value = false
  }
}

function plannedDraftFor(program: WorkoutProgram, sessions: WorkoutSession[]): PlannedDraft {
  const existing = sessions.find((session) => session.planned_occurrence_id === program.occurrence?.id)
  const strength = existing?.strength?.exercises.map((row) => ({
    exerciseId: row.exercise.id,
    simpleWeight: row.simple_weight_kg === null ? null : Number(row.simple_weight_kg),
    simpleReps: row.simple_reps,
    sets: row.sets.map((set) => ({ weight: Number(set.weight_kg), reps: set.reps })),
  })) ?? program.exercises.map((item) => ({
    exerciseId: item.exercise.id,
    simpleWeight: null,
    simpleReps: null,
    sets: Array.from({ length: item.target_sets }, () => ({
      weight: Number(item.progression.next_weight_kg),
      reps: item.target_reps,
    })),
  }))

  return {
    durationMinutes: existing?.duration_seconds
      ? existing.duration_seconds / 60
      : program.planned_duration_seconds ? Math.round(program.planned_duration_seconds / 60) : null,
    distanceKm: existing?.endurance?.distance_m
      ? existing.endurance.distance_m / 1000
      : program.endurance?.target_distance_m ? program.endurance.target_distance_m / 1000 : null,
    activityName: existing?.timed?.activity_name ?? program.timed?.activity_name ?? '',
    strength,
  }
}

async function saveAction(action: () => Promise<void>, success: string): Promise<void> {
  const queuedAction = saveQueue.then(async () => {
    isSaving.value = true
    clearMessages()
    try {
      await action()
      await loadAll()
      feedback.value = i18n.t(success as 'workouts.programCreated')
    } catch (currentError) {
      showError(currentError)
    } finally {
      isSaving.value = false
    }
  })

  saveQueue = queuedAction.catch(() => undefined)
  await queuedAction
}

async function submitExercise(): Promise<void> {
  await saveAction(async () => {
    await createExercise({
      name: exerciseForm.name,
      muscle_group: exerciseForm.muscleGroup.toLowerCase(),
      equipment: exerciseForm.equipment || null,
      exercise_type: 'strength',
    })
    exerciseForm.name = ''
    exerciseForm.muscleGroup = ''
    exerciseForm.equipment = ''
  }, 'workouts.exerciseCreated')
}

function editExercise(exercise: Exercise): void {
  editingExerciseId.value = editingExerciseId.value === exercise.id ? null : exercise.id
  exerciseDraft.name = exercise.name
  exerciseDraft.muscleGroup = exercise.muscle_group
  exerciseDraft.equipment = exercise.equipment ?? ''
}

async function saveExercise(exercise: Exercise): Promise<void> {
  await saveAction(async () => {
    await updateExercise(exercise.id, {
      name: exerciseDraft.name,
      muscle_group: exerciseDraft.muscleGroup.toLowerCase(),
      equipment: exerciseDraft.equipment || null,
      exercise_type: exercise.exercise_type,
    })
    editingExerciseId.value = null
  }, 'workouts.exerciseUpdated')
}

async function setExerciseArchived(exercise: Exercise, isArchived: boolean): Promise<void> {
  await saveAction(
    () => updateExercise(exercise.id, { is_archived: isArchived }).then(() => undefined),
    isArchived ? 'workouts.exerciseArchived' : 'workouts.exerciseRestored',
  )
}

async function submitProgram(): Promise<void> {
  if (programForm.scheduleType === 'weekdays' && programForm.weekdays.length === 0) {
    error.value = i18n.t('workouts.chooseWeekday')
    return
  }
  await saveAction(async () => {
    await createWorkoutProgram({
      name: programForm.name,
      workout_type: programForm.type,
      intensity: programForm.intensity,
      planned_duration_seconds: programForm.plannedDurationMinutes ? programForm.plannedDurationMinutes * 60 : null,
      schedule_type: programForm.scheduleType,
      ...(programForm.scheduleType === 'weekdays' ? { weekdays: programForm.weekdays } : {}),
      preferred_time: programForm.preferredTime,
      starts_on: selectedDate.value,
      ...(programForm.type === 'cardio' ? { endurance: {
        activity: programForm.activity,
        run_type: programForm.activity === 'running' ? programForm.runType : null,
        target_distance_m: programForm.targetDistanceKm ? Math.round(programForm.targetDistanceKm * 1000) : null,
      } } : {}),
      ...(programForm.type === 'flexibility' || programForm.type === 'sport'
        ? { timed: { activity_name: programForm.activityName || null } } : {}),
    })
    programForm.name = ''
  }, 'workouts.programCreated')
}

function editProgramDetails(program: WorkoutProgram): void {
  editingProgramDetailsId.value = editingProgramDetailsId.value === program.id ? null : program.id
  programDraft.name = program.name
  programDraft.intensity = program.intensity
  programDraft.preferredTime = program.recurring_rule.slot_time
  programDraft.scheduleType = program.recurring_rule.schedule_type
  programDraft.weekdays = [...program.recurring_rule.weekdays]
  programDraft.plannedDurationMinutes = program.planned_duration_seconds ? program.planned_duration_seconds / 60 : null
  programDraft.activity = program.endurance?.activity ?? 'running'
  programDraft.runType = program.endurance?.run_type ?? 'easy'
  programDraft.targetDistanceKm = program.endurance?.target_distance_m ? program.endurance.target_distance_m / 1000 : null
  programDraft.activityName = program.timed?.activity_name ?? ''
}

async function saveProgramDetails(program: WorkoutProgram): Promise<void> {
  if (programDraft.scheduleType === 'weekdays' && programDraft.weekdays.length === 0) {
    error.value = i18n.t('workouts.chooseWeekday')
    return
  }
  await saveAction(async () => {
    await updateWorkoutProgram(program.id, {
      name: programDraft.name,
      intensity: programDraft.intensity,
      planned_duration_seconds: programDraft.plannedDurationMinutes ? programDraft.plannedDurationMinutes * 60 : null,
      schedule_type: programDraft.scheduleType,
      ...(programDraft.scheduleType === 'weekdays' ? { weekdays: programDraft.weekdays } : { weekdays: [] }),
      preferred_time: programDraft.preferredTime,
      ...(program.workout_type === 'cardio' ? { endurance: {
        activity: programDraft.activity,
        run_type: programDraft.activity === 'running' ? programDraft.runType : null,
        target_distance_m: programDraft.targetDistanceKm ? Math.round(programDraft.targetDistanceKm * 1000) : null,
      } } : {}),
      ...(program.workout_type === 'flexibility' || program.workout_type === 'sport'
        ? { timed: { activity_name: programDraft.activityName || null } } : {}),
    })
    editingProgramDetailsId.value = null
  }, 'workouts.programUpdated')
}

function editExercises(program: WorkoutProgram): void {
  editingProgramId.value = editingProgramId.value === program.id ? null : program.id
  prescriptions[program.id] = program.exercises.map((item) => ({
    exerciseId: item.exercise.id,
    startingWeight: Number(item.starting_weight_kg),
  }))
}

function addExercise(program: WorkoutProgram): void {
  prescriptions[program.id] ??= []
  prescriptions[program.id].push({ exerciseId: null, startingWeight: 20 })
}

async function saveExercises(program: WorkoutProgram): Promise<void> {
  await saveAction(async () => {
    await replaceWorkoutProgramExercises(program.id, (prescriptions[program.id] ?? []).map((item, index) => ({
      exercise_id: item.exerciseId ?? 0,
      sort_order: index,
      target_sets: 3,
      target_reps: 5,
      starting_weight_kg: item.startingWeight ?? 0,
      increment_kg: 2.5,
      successes_required: 2,
    })))
    editingProgramId.value = null
  }, 'workouts.exercisesSaved')
}

async function toggleProgram(program: WorkoutProgram): Promise<void> {
  await saveAction(
    () => updateWorkoutProgram(program.id, { is_active: !program.is_active }).then(() => undefined),
    program.is_active ? 'workouts.programPaused' : 'workouts.programRestored',
  )
}

async function setProgramArchived(program: WorkoutProgram, isArchived: boolean): Promise<void> {
  await saveAction(
    () => updateWorkoutProgram(program.id, {
      is_archived: isArchived,
      ...(isArchived ? {} : { is_active: true }),
    }).then(() => undefined),
    isArchived ? 'workouts.programArchived' : 'workouts.programRestored',
  )
}

async function recordPlanned(program: WorkoutProgram, outcome: 'completed' | 'skipped'): Promise<void> {
  const draft = planned[program.id]
  await saveAction(async () => {
    if (! selectedDate.value) return
    const common = { outcome, duration_seconds: draft.durationMinutes ? draft.durationMinutes * 60 : null }
    if (outcome === 'skipped') {
      await upsertPlannedWorkout(program.id, selectedDate.value, { outcome })
    } else if (program.workout_type === 'strength') {
      await upsertPlannedWorkout(program.id, selectedDate.value, {
        ...common,
        strength: {
          mode: 'detailed',
          exercises: draft.strength.map((row, exerciseIndex) => ({
            exercise_id: row.exerciseId ?? 0,
            sort_order: exerciseIndex,
            simple_weight_kg: null,
            simple_reps: null,
            note: null,
            sets: row.sets.map((set, setIndex) => ({
              set_order: setIndex,
              weight_kg: set.weight ?? 0,
              reps: set.reps ?? 1,
              rest_seconds: null,
            })),
          })),
        },
      })
    } else if (program.workout_type === 'cardio') {
      await upsertPlannedWorkout(program.id, selectedDate.value, {
        ...common,
        endurance: {
          activity: program.endurance?.activity ?? 'running',
          run_type: program.endurance?.run_type,
          distance_m: draft.distanceKm ? Math.round(draft.distanceKm * 1000) : null,
        },
      })
    } else {
      await upsertPlannedWorkout(program.id, selectedDate.value, {
        ...common,
        timed: { activity_name: draft.activityName || program.timed?.activity_name || null },
      })
    }
  }, outcome === 'skipped' ? 'workouts.workoutSkipped' : 'workouts.workoutRecorded')
}

async function submitManual(): Promise<void> {
  await saveAction(async () => {
    const common = {
      name: manualForm.name,
      workout_type: manualForm.type,
      performed_on: manualForm.date ?? selectedDate.value ?? '',
      duration_seconds: manualForm.durationMinutes ? manualForm.durationMinutes * 60 : null,
    }
    if (manualForm.type === 'cardio') {
      await createWorkout({ ...common, endurance: {
        activity: manualForm.activity,
        run_type: manualForm.activity === 'running' ? manualForm.runType : null,
        distance_m: manualForm.distanceKm ? Math.round(manualForm.distanceKm * 1000) : null,
      } })
    } else if (manualForm.type === 'strength') {
      await createWorkout({ ...common, strength: { mode: 'simple', exercises: [{
        exercise_id: manualForm.exerciseId ?? 0, sort_order: 0,
        simple_weight_kg: manualForm.weight ?? 0, simple_reps: manualForm.reps ?? 1, sets: [],
      }] } })
    } else {
      await createWorkout({ ...common, timed: { activity_name: manualForm.activityName || null } })
    }
    manualForm.name = ''
  }, 'workouts.workoutRecorded')
}

function startCorrection(session: WorkoutSession): void {
  editingSessionId.value = session.id
  correction.distanceKm = session.endurance?.distance_m ? session.endurance.distance_m / 1000 : null
  correction.durationMinutes = session.duration_seconds ? session.duration_seconds / 60 : null
  correction.activityName = session.timed?.activity_name ?? ''
  correction.strength = session.strength?.exercises.map((row) => ({
    exerciseId: row.exercise.id,
    simpleWeight: row.simple_weight_kg === null ? null : Number(row.simple_weight_kg),
    simpleReps: row.simple_reps,
    sets: row.sets.map((set) => ({ weight: Number(set.weight_kg), reps: set.reps })),
  })) ?? []
}

async function saveCorrection(session: WorkoutSession): Promise<void> {
  await saveAction(async () => {
    await updateWorkout(session.id, {
      duration_seconds: correction.durationMinutes ? correction.durationMinutes * 60 : null,
      ...(session.endurance ? { endurance: {
        activity: session.endurance.activity,
        run_type: session.endurance.run_type,
        distance_m: correction.distanceKm ? Math.round(correction.distanceKm * 1000) : null,
        average_heart_rate: session.endurance.average_heart_rate,
        energy_kcal: session.endurance.energy_kcal,
      } } : {}),
      ...(session.strength ? { strength: {
        mode: session.strength.mode,
        exercises: correction.strength.map((row, exerciseIndex) => ({
          exercise_id: row.exerciseId ?? 0,
          sort_order: exerciseIndex,
          simple_weight_kg: session.strength?.mode === 'simple' ? row.simpleWeight : null,
          simple_reps: session.strength?.mode === 'simple' ? row.simpleReps : null,
          note: session.strength?.exercises[exerciseIndex]?.note ?? null,
          sets: session.strength?.mode === 'detailed'
            ? row.sets.map((set, setIndex) => ({
              set_order: setIndex,
              weight_kg: set.weight ?? 0,
              reps: set.reps ?? 1,
              rest_seconds: session.strength?.exercises[exerciseIndex]?.sets[setIndex]?.rest_seconds ?? null,
            }))
            : [],
        })),
      } } : {}),
      ...(session.timed ? { timed: { activity_name: correction.activityName || null } } : {}),
    })
    editingSessionId.value = null
  }, 'workouts.workoutUpdated')
}

async function removeSession(session: WorkoutSession): Promise<void> {
  await saveAction(() => deleteWorkout(session.id), 'workouts.workoutDeleted')
}

async function submitGoal(): Promise<void> {
  await saveAction(async () => {
    const target = goalForm.target ?? 0
    await createTrainingGoal({
      name: goalForm.name,
      kind: goalForm.kind,
      target_value: goalForm.kind === 'distance' || goalForm.kind === 'race' ? target * 1000 : target,
      exercise_id: goalForm.kind === 'strength' ? goalForm.exerciseId : null,
      activity: goalForm.kind === 'distance' || goalForm.kind === 'race' ? 'running' : null,
      workout_program_id: goalForm.kind === 'consistency' ? goalForm.programId : null,
      target_date: goalForm.kind === 'race' ? goalForm.targetDate : null,
    })
    goalForm.name = ''
  }, 'workouts.goalCreated')
}

async function completeGoal(goal: TrainingGoal): Promise<void> {
  await setGoalStatus(goal, 'completed')
}

async function setGoalStatus(goal: TrainingGoal, status: TrainingGoal['status']): Promise<void> {
  await saveAction(() => updateTrainingGoal(goal.id, { status }).then(() => undefined), 'workouts.goalUpdated')
}

async function setGoalArchived(goal: TrainingGoal, isArchived: boolean): Promise<void> {
  await saveAction(
    () => updateTrainingGoal(goal.id, { is_archived: isArchived }).then(() => undefined),
    isArchived ? 'workouts.goalArchived' : 'workouts.goalRestored',
  )
}

function editGoal(goal: TrainingGoal): void {
  editingGoalId.value = editingGoalId.value === goal.id ? null : goal.id
  goalDraft.name = goal.name
  goalDraft.target = goal.training.unit === 'm'
    ? Number(goal.training.target_value) / 1000
    : Number(goal.training.target_value)
  goalDraft.targetDate = goal.target_date
}

async function saveGoal(goal: TrainingGoal): Promise<void> {
  await saveAction(async () => {
    await updateTrainingGoal(goal.id, {
      name: goalDraft.name,
      target_value: goal.training.unit === 'm' ? (goalDraft.target ?? 0) * 1000 : goalDraft.target ?? 0,
      ...(goal.training.kind === 'race' ? { target_date: goalDraft.targetDate } : {}),
    })
    editingGoalId.value = null
  }, 'workouts.goalUpdated')
}

function pace(seconds: number | null): string {
  if (seconds === null) return i18n.t('common.notSet')
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')} /km`
}

function goalProgress(goal: TrainingGoal): string {
  const current = Number(goal.training.current_value ?? 0)
  const target = Number(goal.training.target_value)
  if (goal.training.unit === 'm') {
    return i18n.t('workouts.goalDistanceProgress', { current: i18n.number(current / 1000), target: i18n.number(target / 1000) })
  }
  return i18n.t('workouts.goalValueProgress', { current: i18n.number(current), target: i18n.number(target) })
}

onMounted(loadAll)
</script>

<template>
  <section class="page-shell workout-workspace">
    <header class="page-heading">
      <div>
        <p class="eyebrow">{{ i18n.t('workouts.eyebrow') }}</p>
        <h1>{{ i18n.t('workouts.title') }}</h1>
        <p class="muted">{{ i18n.t('workouts.subtitle') }}</p>
      </div>
      <UiDatePicker v-model="selectedDate" :label="i18n.t('workouts.date')" name="workout-selected-date" :locale="locale" :today="today" :clearable="false" @update:model-value="loadAll" />
    </header>

    <p v-if="feedback" role="status" class="feedback success">{{ feedback }}</p>
    <p v-if="error" role="alert" class="feedback error">{{ error }}</p>

    <AsyncState :loading="isLoading" :error="isLoading || !loadFailed ? null : error" @retry="loadAll">
      <div class="workout-grid">
        <section class="panel catalogue-panel">
          <div class="section-heading">
            <h2>{{ i18n.t('workouts.catalogue') }}</h2>
            <div class="segmented-control" role="radiogroup" :aria-label="i18n.t('workouts.exerciseState')">
              <button type="button" role="radio" class="secondary" :aria-checked="exerciseState === 'active'" @click="exerciseState = 'active'">{{ i18n.t('workouts.exerciseState.active') }}</button>
              <button type="button" role="radio" class="secondary" :aria-checked="exerciseState === 'archived'" @click="exerciseState = 'archived'">{{ i18n.t('workouts.exerciseState.archived') }}</button>
            </div>
          </div>
          <ul class="item-list exercise-catalogue-list" :aria-label="i18n.t('workouts.catalogue')">
            <li v-for="exercise in catalogueExercises" :key="exercise.id" class="management-row" :aria-label="exerciseLabel(exercise)">
              <div><strong>{{ exerciseLabel(exercise) }}</strong><p class="muted">{{ exercise.muscle_group }}<span v-if="exercise.equipment"> · {{ exercise.equipment }}</span></p></div>
              <div v-if="!exercise.is_builtin" class="button-row">
                <button v-if="!exercise.is_archived" type="button" class="secondary" :aria-label="i18n.t('workouts.editExerciseNamed', { name: exercise.name })" @click="editExercise(exercise)">{{ i18n.t('common.edit') }}</button>
                <button type="button" class="secondary" :aria-label="i18n.t(exercise.is_archived ? 'workouts.restoreExerciseNamed' : 'workouts.archiveExerciseNamed', { name: exercise.name })" @click="setExerciseArchived(exercise, !exercise.is_archived)">{{ i18n.t(exercise.is_archived ? 'workouts.restore' : 'workouts.archive') }}</button>
              </div>
              <form v-if="editingExerciseId === exercise.id" class="form-grid compact-form" :aria-label="i18n.t('workouts.editExerciseNamed', { name: exercise.name })" @submit.prevent="saveExercise(exercise)">
                <UiTextInput v-model="exerciseDraft.name" :label="i18n.t('workouts.exerciseName')" :name="`edit-exercise-name-${exercise.id}`" required />
                <UiTextInput v-model="exerciseDraft.muscleGroup" :label="i18n.t('workouts.muscleGroup')" :name="`edit-muscle-group-${exercise.id}`" required />
                <UiTextInput v-model="exerciseDraft.equipment" :label="i18n.t('workouts.equipment')" :name="`edit-equipment-${exercise.id}`" />
                <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.saveExercise') }}</button>
              </form>
            </li>
          </ul>
          <form v-if="exerciseState === 'active'" class="form-grid compact-form" :aria-label="i18n.t('workouts.createExercise')" @submit.prevent="submitExercise">
            <UiTextInput v-model="exerciseForm.name" :label="i18n.t('workouts.exerciseName')" name="exercise-name" required />
            <UiTextInput v-model="exerciseForm.muscleGroup" :label="i18n.t('workouts.muscleGroup')" name="muscle-group" required />
            <UiTextInput v-model="exerciseForm.equipment" :label="i18n.t('workouts.equipment')" name="equipment" />
            <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.createExercise') }}</button>
          </form>
        </section>

        <section class="panel program-create-panel">
          <div class="section-heading"><h2>{{ i18n.t('workouts.programs') }}</h2></div>
          <form class="form-grid compact-form" :aria-label="i18n.t('workouts.createProgram')" @submit.prevent="submitProgram">
            <UiTextInput v-model="programForm.name" :label="i18n.t('workouts.programName')" name="program-name" required />
            <UiSelect v-model="programForm.type" :label="i18n.t('workouts.workoutType')" name="program-type" :options="workoutTypeOptions" />
            <UiSelect v-model="programForm.intensity" :label="i18n.t('workouts.intensity')" name="program-intensity" :options="intensityOptions" />
            <UiNumberInput v-model="programForm.plannedDurationMinutes" :label="i18n.t('workouts.plannedDuration')" name="program-duration" :min="1" :step="1" />
            <UiSelect v-if="programForm.type === 'cardio'" v-model="programForm.activity" :label="i18n.t('workouts.activity')" name="program-activity" :options="activityOptions" />
            <UiSelect v-if="programForm.type === 'cardio' && programForm.activity === 'running'" v-model="programForm.runType" :label="i18n.t('workouts.runType')" name="program-run-type" :options="runTypeOptions" />
            <UiNumberInput v-if="programForm.type === 'cardio'" v-model="programForm.targetDistanceKm" :label="i18n.t('workouts.targetDistance')" name="program-target-distance" :min="0.001" :step="0.001" />
            <UiTextInput v-if="programForm.type === 'flexibility' || programForm.type === 'sport'" v-model="programForm.activityName" :label="i18n.t('workouts.activityName')" name="program-activity-name" />
            <UiSegmented v-model="programForm.scheduleType" :label="i18n.t('routine.schedule')" name="program-schedule" :options="scheduleOptions" wide />
            <UiToggleGroup v-if="programForm.scheduleType === 'weekdays'" v-model="programForm.weekdays" :label="i18n.t('routine.weekdays')" name="program-weekdays" :options="weekdayOptions" wide />
            <UiTimeField v-model="programForm.preferredTime" :label="i18n.t('workouts.preferredTime')" name="program-time" />
            <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.createProgram') }}</button>
          </form>
        </section>
      </div>

      <section class="panel">
        <div class="section-heading">
          <h2>{{ i18n.t('workouts.scheduledPrograms') }}</h2>
          <div class="segmented-control" role="radiogroup" :aria-label="i18n.t('workouts.programState')">
            <button v-for="option in (['active', 'paused', 'archived'] as WorkoutState[])" :key="option" type="button" role="radio" :aria-checked="state === option" class="secondary" @click="state = option; loadAll()">{{ i18n.t(`workouts.state.${option}` as 'workouts.state.active') }}</button>
          </div>
        </div>
        <ul class="item-list workout-program-list" :aria-label="i18n.t('workouts.scheduledPrograms')">
          <li v-for="program in programs" :key="program.id" class="workout-card" :aria-label="program.name">
            <div class="section-heading">
              <div><h3>{{ program.name }}</h3><p class="muted">{{ i18n.t(`workouts.type.${program.workout_type}` as 'workouts.type.strength') }} · {{ program.recurring_rule.slot_time ?? i18n.t('workouts.anyTime') }}</p></div>
              <div class="button-row">
                <button v-if="!program.is_archived" type="button" class="secondary" :aria-label="i18n.t('workouts.editProgramNamed', { name: program.name })" @click="editProgramDetails(program)">{{ i18n.t('common.edit') }}</button>
                <button v-if="!program.is_archived && program.workout_type === 'strength'" type="button" class="secondary" :aria-label="i18n.t('workouts.editExercisesNamed', { name: program.name })" @click="editExercises(program)">{{ i18n.t('workouts.exercises') }}</button>
                <button v-if="!program.is_archived" type="button" class="secondary" :aria-label="i18n.t(program.is_active ? 'workouts.pauseNamed' : 'workouts.restoreNamed', { name: program.name })" @click="toggleProgram(program)">{{ i18n.t(program.is_active ? 'workouts.pause' : 'workouts.restore') }}</button>
                <button type="button" class="secondary" :aria-label="i18n.t(program.is_archived ? 'workouts.restoreNamed' : 'workouts.archiveNamed', { name: program.name })" @click="setProgramArchived(program, !program.is_archived)">{{ i18n.t(program.is_archived ? 'workouts.restore' : 'workouts.archive') }}</button>
              </div>
            </div>
            <p class="muted">{{ program.recurring_rule.schedule_type === 'daily' ? i18n.t('routine.daily') : program.recurring_rule.weekdays.map((day) => i18n.t(`weekday.${day}` as 'weekday.MO')).join(', ') }}</p>
            <ul v-if="program.exercises.length" class="prescription-summary">
              <li v-for="item in program.exercises" :key="item.id">{{ exerciseLabel(item.exercise) }} · {{ item.progression.next_weight_kg.replace('.000', '') }} kg · {{ item.target_sets }}×{{ item.target_reps }}</li>
            </ul>
            <form v-if="editingProgramDetailsId === program.id" class="form-grid" :aria-label="i18n.t('workouts.editProgramNamed', { name: program.name })" @submit.prevent="saveProgramDetails(program)">
              <UiTextInput v-model="programDraft.name" :label="i18n.t('workouts.programName')" :name="`edit-program-name-${program.id}`" required />
              <UiSelect v-model="programDraft.intensity" :label="i18n.t('workouts.intensity')" :name="`edit-program-intensity-${program.id}`" :options="intensityOptions" />
              <UiNumberInput v-model="programDraft.plannedDurationMinutes" :label="i18n.t('workouts.plannedDuration')" :name="`edit-program-duration-${program.id}`" :min="1" :step="1" />
              <UiSelect v-if="program.workout_type === 'cardio'" v-model="programDraft.activity" :label="i18n.t('workouts.activity')" :name="`edit-program-activity-${program.id}`" :options="activityOptions" />
              <UiSelect v-if="program.workout_type === 'cardio' && programDraft.activity === 'running'" v-model="programDraft.runType" :label="i18n.t('workouts.runType')" :name="`edit-program-run-type-${program.id}`" :options="runTypeOptions" />
              <UiNumberInput v-if="program.workout_type === 'cardio'" v-model="programDraft.targetDistanceKm" :label="i18n.t('workouts.targetDistance')" :name="`edit-program-distance-${program.id}`" :min="0.001" :step="0.001" />
              <UiTextInput v-if="program.workout_type === 'flexibility' || program.workout_type === 'sport'" v-model="programDraft.activityName" :label="i18n.t('workouts.activityName')" :name="`edit-program-activity-name-${program.id}`" />
              <UiSegmented v-model="programDraft.scheduleType" :label="i18n.t('routine.schedule')" :name="`edit-program-schedule-${program.id}`" :options="scheduleOptions" wide />
              <UiToggleGroup v-if="programDraft.scheduleType === 'weekdays'" v-model="programDraft.weekdays" :label="i18n.t('routine.weekdays')" :name="`edit-program-weekdays-${program.id}`" :options="weekdayOptions" wide />
              <UiTimeField v-model="programDraft.preferredTime" :label="i18n.t('workouts.preferredTime')" :name="`edit-program-time-${program.id}`" />
              <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.saveProgram') }}</button>
            </form>
            <form v-if="editingProgramId === program.id" class="form-grid prescription-editor" :aria-label="i18n.t('workouts.exercisesFor', { name: program.name })" @submit.prevent="saveExercises(program)">
              <fieldset v-for="(item, index) in prescriptions[program.id]" :key="index" class="inline-fields">
                <UiSelect v-model="item.exerciseId" :label="i18n.t('workouts.exerciseNumber', { number: index + 1 })" :name="`prescription-exercise-${program.id}-${index}`" :options="exerciseOptions" />
                <UiNumberInput v-model="item.startingWeight" :label="i18n.t('workouts.startingWeightNumber', { number: index + 1 })" :name="`prescription-weight-${program.id}-${index}`" :min="0" :step="0.5" />
              </fieldset>
              <div class="button-row"><button type="button" class="secondary" @click="addExercise(program)">{{ i18n.t('workouts.addExercise') }}</button><button type="submit">{{ i18n.t('workouts.saveExercises') }}</button></div>
            </form>
            <form v-if="program.occurrence" class="form-grid planned-workout-form" :aria-label="i18n.t('workouts.recordNamed', { name: program.name })" @submit.prevent="recordPlanned(program, 'completed')">
              <template v-if="program.workout_type === 'strength'">
                <fieldset v-for="(exercise, exerciseIndex) in planned[program.id].strength" :key="exerciseIndex" class="inline-fields">
                  <legend>{{ i18n.t('workouts.exerciseNumber', { number: exerciseIndex + 1 }) }}</legend>
                  <template v-for="(set, setIndex) in exercise.sets" :key="setIndex">
                    <UiNumberInput v-model="set.weight" :label="i18n.t('workouts.setWeightNumber', { exercise: exerciseIndex + 1, set: setIndex + 1 })" :name="`planned-weight-${program.id}-${exerciseIndex}-${setIndex}`" :min="0" :step="0.5" />
                    <UiNumberInput v-model="set.reps" :label="i18n.t('workouts.setRepsNumber', { exercise: exerciseIndex + 1, set: setIndex + 1 })" :name="`planned-reps-${program.id}-${exerciseIndex}-${setIndex}`" :min="1" :step="1" />
                  </template>
                </fieldset>
              </template>
              <template v-else-if="program.workout_type === 'cardio'">
                <UiNumberInput v-model="planned[program.id].distanceKm" :label="i18n.t('workouts.distance')" :name="`planned-distance-${program.id}`" :min="0.001" :step="0.001" />
                <UiNumberInput v-model="planned[program.id].durationMinutes" :label="i18n.t('workouts.duration')" :name="`planned-duration-${program.id}`" :min="1" :step="1" />
              </template>
              <template v-else>
                <UiTextInput v-model="planned[program.id].activityName" :label="i18n.t('workouts.activityName')" :name="`planned-activity-${program.id}`" />
                <UiNumberInput v-model="planned[program.id].durationMinutes" :label="i18n.t('workouts.duration')" :name="`planned-duration-${program.id}`" :min="1" :step="1" />
              </template>
              <div class="button-row"><button type="submit" :disabled="isSaving || program.exercises.length === 0 && program.workout_type === 'strength'">{{ i18n.t(program.occurrence.status === 'done' ? 'workouts.updateWorkout' : 'workouts.completeWorkout') }}</button><button type="button" class="secondary" @click="recordPlanned(program, 'skipped')">{{ i18n.t('workouts.skipWorkout') }}</button></div>
              <strong v-if="program.occurrence.status !== 'planned'" class="kind-chip">{{ i18n.t(`workouts.occurrence.${program.occurrence.status}` as 'workouts.occurrence.done') }}</strong>
            </form>
          </li>
        </ul>
      </section>

      <div class="workout-grid">
        <section class="panel">
          <h2>{{ i18n.t('workouts.unplanned') }}</h2>
          <form class="form-grid" :aria-label="i18n.t('workouts.recordUnplanned')" @submit.prevent="submitManual">
            <UiTextInput v-model="manualForm.name" :label="i18n.t('workouts.workoutName')" name="manual-name" required />
            <UiSelect v-model="manualForm.type" :label="i18n.t('workouts.workoutType')" name="manual-type" :options="workoutTypeOptions" />
            <UiDatePicker v-model="manualForm.date" :label="i18n.t('workouts.workoutDate')" name="manual-date" :locale="locale" :today="today" :clearable="false" />
            <UiSelect v-if="manualForm.type === 'strength'" v-model="manualForm.exerciseId" :label="i18n.t('workouts.exercise')" name="manual-exercise" :options="exerciseOptions" />
            <UiSelect v-if="manualForm.type === 'cardio'" v-model="manualForm.activity" :label="i18n.t('workouts.activity')" name="manual-endurance-activity" :options="activityOptions" />
            <UiSelect v-if="manualForm.type === 'cardio' && manualForm.activity === 'running'" v-model="manualForm.runType" :label="i18n.t('workouts.runType')" name="manual-run-type" :options="runTypeOptions" />
            <UiNumberInput v-if="manualForm.type === 'cardio'" v-model="manualForm.distanceKm" :label="i18n.t('workouts.distance')" name="manual-distance" :min="0.001" :step="0.001" />
            <UiNumberInput v-if="manualForm.type !== 'strength'" v-model="manualForm.durationMinutes" :label="i18n.t('workouts.duration')" name="manual-duration" :min="1" :step="1" />
            <UiTextInput v-if="manualForm.type === 'flexibility' || manualForm.type === 'sport'" v-model="manualForm.activityName" :label="i18n.t('workouts.activityName')" name="manual-activity" />
            <UiNumberInput v-if="manualForm.type === 'strength'" v-model="manualForm.weight" :label="i18n.t('workouts.weight')" name="manual-weight" :min="0" :step="0.5" />
            <UiNumberInput v-if="manualForm.type === 'strength'" v-model="manualForm.reps" :label="i18n.t('workouts.reps')" name="manual-reps" :min="1" :step="1" />
            <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.recordUnplanned') }}</button>
          </form>
        </section>

        <section class="panel">
          <h2>{{ i18n.t('workouts.trainingGoals') }}</h2>
          <form class="form-grid" :aria-label="i18n.t('workouts.createGoal')" @submit.prevent="submitGoal">
            <UiTextInput v-model="goalForm.name" :label="i18n.t('workouts.goalName')" name="goal-name" required />
            <UiSelect v-model="goalForm.kind" :label="i18n.t('workouts.goalKind')" name="goal-kind" :options="goalKindOptions" />
            <UiSelect v-if="goalForm.kind === 'strength'" v-model="goalForm.exerciseId" :label="i18n.t('workouts.exercise')" name="goal-exercise" :options="exerciseOptions" />
            <UiSelect v-if="goalForm.kind === 'consistency'" v-model="goalForm.programId" :label="i18n.t('workouts.program')" name="goal-program" :options="programOptions" nullable :nullable-label="i18n.t('workouts.allPrograms')" />
            <UiDatePicker v-if="goalForm.kind === 'race'" v-model="goalForm.targetDate" :label="i18n.t('workouts.targetDate')" name="goal-target-date" :locale="locale" :today="today" :clearable="false" />
            <UiNumberInput v-model="goalForm.target" :label="i18n.t(goalForm.kind === 'distance' || goalForm.kind === 'race' ? 'workouts.targetDistance' : goalForm.kind === 'strength' ? 'workouts.targetWeight' : 'workouts.targetSessions')" name="goal-target" :min="goalForm.kind === 'consistency' ? 1 : 0.001" :step="goalForm.kind === 'consistency' ? 1 : 0.001" />
            <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.createGoal') }}</button>
          </form>
        </section>
      </div>

      <div class="workout-grid">
        <section class="panel" :aria-label="i18n.t('workouts.records')">
          <h2>{{ i18n.t('workouts.records') }}</h2>
          <div class="summary-grid">
            <div class="metric"><span>{{ i18n.t('workouts.planned') }}</span><strong>{{ history?.summary.planned ?? 0 }}</strong></div>
            <div class="metric"><span>{{ i18n.t('workouts.completed') }}</span><strong>{{ history?.summary.completed ?? 0 }}</strong></div>
            <div class="metric"><span>{{ i18n.t('workouts.distanceTotal') }}</span><strong>{{ i18n.number((history?.summary.distance_m ?? 0) / 1000) }} km</strong></div>
          </div>
          <p v-for="record in history?.records.exercises" :key="record.exercise.id">{{ exerciseLabel(record.exercise) }} · {{ record.max_weight_kg }} kg</p>
          <p v-for="record in history?.records.paces" :key="record.activity">{{ i18n.t('workouts.bestPace') }} · {{ pace(record.best_pace_seconds_per_km) }}</p>
        </section>

        <section class="panel">
          <div class="section-heading">
            <h2>{{ i18n.t('workouts.goalProgress') }}</h2>
            <div class="segmented-control" role="radiogroup" :aria-label="i18n.t('workouts.goalState')">
              <button type="button" role="radio" class="secondary" :aria-checked="!goalArchived" @click="goalArchived = false; loadAll()">{{ i18n.t('workouts.goalState.current') }}</button>
              <button type="button" role="radio" class="secondary" :aria-checked="goalArchived" @click="goalArchived = true; loadAll()">{{ i18n.t('workouts.goalState.archived') }}</button>
            </div>
          </div>
          <ul class="item-list">
            <li v-for="goal in goals" :key="goal.id" :aria-label="goal.name" class="goal-progress-card">
              <strong>{{ goal.name }}</strong><p class="kind-chip">{{ i18n.t(`workouts.goalStatus.${goal.status}` as 'workouts.goalStatus.active') }}</p><p>{{ goalProgress(goal) }}</p><p>{{ goal.training.progress === null ? i18n.t('workouts.noProgress') : `${Math.round(goal.training.progress * 100)}%` }}</p>
              <progress :value="goal.training.progress ?? 0" max="1"></progress>
              <div class="button-row">
                <button v-if="!goal.is_archived" type="button" class="secondary" :aria-label="i18n.t('workouts.editGoalNamed', { name: goal.name })" @click="editGoal(goal)">{{ i18n.t('common.edit') }}</button>
                <button v-if="!goal.is_archived && goal.status === 'active'" type="button" class="secondary" @click="completeGoal(goal)">{{ i18n.t('workouts.completeGoal') }}</button>
                <button v-if="!goal.is_archived && goal.status === 'active'" type="button" class="secondary" @click="setGoalStatus(goal, 'abandoned')">{{ i18n.t('workouts.abandonGoal') }}</button>
                <button v-if="!goal.is_archived && goal.status !== 'active'" type="button" class="secondary" @click="setGoalStatus(goal, 'active')">{{ i18n.t('workouts.reactivateGoal') }}</button>
                <button type="button" class="secondary" @click="setGoalArchived(goal, !goal.is_archived)">{{ i18n.t(goal.is_archived ? 'workouts.restoreGoal' : 'workouts.archiveGoal') }}</button>
              </div>
              <form v-if="editingGoalId === goal.id" class="form-grid" :aria-label="i18n.t('workouts.editGoalNamed', { name: goal.name })" @submit.prevent="saveGoal(goal)">
                <UiTextInput v-model="goalDraft.name" :label="i18n.t('workouts.goalName')" :name="`edit-goal-name-${goal.id}`" required />
                <UiDatePicker v-if="goal.training.kind === 'race'" v-model="goalDraft.targetDate" :label="i18n.t('workouts.targetDate')" :name="`edit-goal-date-${goal.id}`" :locale="locale" :today="today" :clearable="false" />
                <UiNumberInput v-model="goalDraft.target" :label="i18n.t(goal.training.unit === 'm' ? 'workouts.targetDistance' : goal.training.unit === 'kg' ? 'workouts.targetWeight' : 'workouts.targetSessions')" :name="`edit-goal-target-${goal.id}`" :min="goal.training.unit === 'sessions_per_week' ? 1 : 0.001" :step="goal.training.unit === 'sessions_per_week' ? 1 : 0.001" />
                <p class="muted">{{ i18n.t('workouts.goalScopeLocked') }}</p>
                <button type="submit" :disabled="isSaving">{{ i18n.t('workouts.saveGoal') }}</button>
              </form>
            </li>
          </ul>
        </section>
      </div>

      <section class="panel">
        <h2>{{ i18n.t('workouts.history') }}</h2>
        <ul class="item-list">
          <li v-for="session in history?.data" :key="session.id" :aria-label="session.name" class="history-card">
            <div><strong>{{ session.name }}</strong><p class="muted">{{ session.performed_on }} · {{ i18n.t(`workouts.type.${session.workout_type}` as 'workouts.type.strength') }}</p></div>
            <div class="button-row"><button type="button" class="secondary" :aria-label="i18n.t('workouts.editNamed', { name: session.name })" @click="startCorrection(session)">{{ i18n.t('common.edit') }}</button><button type="button" class="secondary" @click="removeSession(session)">{{ i18n.t('common.delete') }}</button></div>
            <form v-if="editingSessionId === session.id" class="form-grid" :aria-label="i18n.t('workouts.editNamed', { name: session.name })" @submit.prevent="saveCorrection(session)">
              <template v-if="session.strength">
                <fieldset v-for="(exercise, exerciseIndex) in correction.strength" :key="exerciseIndex" class="inline-fields">
                  <legend>{{ i18n.t('workouts.exerciseNumber', { number: exerciseIndex + 1 }) }}</legend>
                  <template v-if="session.strength.mode === 'simple'">
                    <UiNumberInput v-model="exercise.simpleWeight" :label="i18n.t('workouts.simpleWeightNumber', { exercise: exerciseIndex + 1 })" :name="`edit-simple-weight-${session.id}-${exerciseIndex}`" :min="0" :step="0.5" />
                    <UiNumberInput v-model="exercise.simpleReps" :label="i18n.t('workouts.simpleRepsNumber', { exercise: exerciseIndex + 1 })" :name="`edit-simple-reps-${session.id}-${exerciseIndex}`" :min="1" :step="1" />
                  </template>
                  <template v-else>
                    <template v-for="(set, setIndex) in exercise.sets" :key="setIndex">
                      <UiNumberInput v-model="set.weight" :label="i18n.t('workouts.setWeightNumber', { exercise: exerciseIndex + 1, set: setIndex + 1 })" :name="`edit-set-weight-${session.id}-${exerciseIndex}-${setIndex}`" :min="0" :step="0.5" />
                      <UiNumberInput v-model="set.reps" :label="i18n.t('workouts.setRepsNumber', { exercise: exerciseIndex + 1, set: setIndex + 1 })" :name="`edit-set-reps-${session.id}-${exerciseIndex}-${setIndex}`" :min="1" :step="1" />
                    </template>
                  </template>
                </fieldset>
              </template>
              <UiNumberInput v-if="session.endurance" v-model="correction.distanceKm" :label="i18n.t('workouts.distance')" :name="`edit-distance-${session.id}`" :min="0.001" :step="0.001" />
              <UiTextInput v-if="session.timed" v-model="correction.activityName" :label="i18n.t('workouts.activityName')" :name="`edit-activity-${session.id}`" />
              <UiNumberInput v-model="correction.durationMinutes" :label="i18n.t('workouts.duration')" :name="`edit-duration-${session.id}`" :min="1" :step="1" />
              <button type="submit">{{ i18n.t('workouts.saveWorkout') }}</button>
            </form>
          </li>
        </ul>
      </section>
    </AsyncState>
  </section>
</template>
