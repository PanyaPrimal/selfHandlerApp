import type {
  BodyGoalPayload,
  BodyGoalResponse,
  BodyGoalsResponse,
  BodyMeasurement,
  BodyMeasurementPayload,
  BodyMeasurementsResponse,
  BodyTrend,
  DailyReview,
  DailyReviewPayload,
  Goal,
  GoalCreatePayload,
  GoalUpdatePayload,
  Habit,
  HabitCreatePayload,
  HabitLimitStepInput,
  HabitLogPayload,
  HabitStatistics,
  HabitsResponse,
  HabitState,
  HabitUpdatePayload,
  ItemResponse,
  ListResponse,
  PlannerDayResponse,
  ProfileInput,
  ProfileResponse,
  Routine,
  RoutineActivityInput,
  RoutineActivityLogPayload,
  RoutineDayProjection,
  RoutineCreatePayload,
  RoutineLog,
  RoutineTemplate,
  RoutineUpdatePayload,
  SleepLogPayload,
  SleepPlan,
  SleepPlanPayload,
  SleepPlanState,
  SleepPlanUpdatePayload,
  SleepStatistics,
  SleepWorkspaceResponse,
  StorageItem,
  StorageItemPayload,
  StorageItemsResponse,
  StorageProject,
  StorageProjectPayload,
  StorageProjectsResponse,
  TimeBlock,
  TimeBlockPayload,
  TodayResponse,
  PreferencesPayload,
  NotificationActionResponse,
  NotificationListResponse,
  NotificationSettingsData,
  NotificationSettingsResponse,
  NotificationSnoozeMinutes,
  NotificationSnoozeResponse,
  NotificationView,
  Exercise,
  ExerciseCatalogueResponse,
  ExerciseInput,
  TrainingGoal,
  TrainingGoalInput,
  TrainingGoalsResponse,
  WorkoutHistoryResponse,
  WorkoutProgram,
  WorkoutProgramInput,
  WorkoutProgramsResponse,
  WorkoutSession,
  WorkoutSessionInput,
  WorkoutState,
} from './types'
import { jsonRequest, request } from './http'

// The SelfHandler error contract: a message for the user plus the per-field
// validation errors of a 422 response.
export { ApiError, validationErrors } from './http'
export type { ValidationErrors } from './http'

export function getProfile(): Promise<ProfileResponse> {
  return request<ProfileResponse>('/profile')
}

export function updateProfile(payload: ProfileInput): Promise<ProfileResponse> {
  return jsonRequest<ProfileResponse>('/profile', 'PUT', payload)
}

export function updatePreferences(payload: PreferencesPayload): Promise<ProfileResponse> {
  return jsonRequest<ProfileResponse>('/profile', 'PATCH', payload)
}

export function updateThemePreferences(theme: NonNullable<PreferencesPayload['preferences']['theme']>): Promise<ProfileResponse> {
  return updatePreferences({ preferences: { theme } })
}

export function getToday(date?: string): Promise<TodayResponse> {
  const query = date ? `?date=${encodeURIComponent(date)}` : ''
  return request<TodayResponse>(`/today${query}`)
}

export function getExercises(state: 'active' | 'archived' | 'all' = 'active'): Promise<ExerciseCatalogueResponse> {
  return request<ExerciseCatalogueResponse>(`/exercises?state=${encodeURIComponent(state)}`)
}

export async function createExercise(payload: ExerciseInput): Promise<Exercise> {
  const response = await jsonRequest<ItemResponse<Exercise>>('/exercises', 'POST', payload)
  return response.data
}

export async function updateExercise(exerciseId: number, payload: Partial<ExerciseInput> & { is_archived?: boolean }): Promise<Exercise> {
  const response = await jsonRequest<ItemResponse<Exercise>>(`/exercises/${exerciseId}`, 'PATCH', payload)
  return response.data
}

export function getWorkoutPrograms(state: WorkoutState = 'active', date?: string): Promise<WorkoutProgramsResponse> {
  const query = new URLSearchParams({ state })
  if (date) query.set('date', date)
  return request<WorkoutProgramsResponse>(`/workout-programs?${query.toString()}`)
}

export async function createWorkoutProgram(payload: WorkoutProgramInput): Promise<WorkoutProgram> {
  const response = await jsonRequest<ItemResponse<WorkoutProgram>>('/workout-programs', 'POST', payload)
  return response.data
}

export async function updateWorkoutProgram(programId: number, payload: Partial<Omit<WorkoutProgramInput, 'workout_type'>> & { is_active?: boolean, is_archived?: boolean }): Promise<WorkoutProgram> {
  const response = await jsonRequest<ItemResponse<WorkoutProgram>>(`/workout-programs/${programId}`, 'PATCH', payload)
  return response.data
}

export async function replaceWorkoutProgramExercises(
  programId: number,
  exercises: Array<{
    exercise_id: number
    sort_order: number
    target_sets: number
    target_reps: number
    starting_weight_kg: number
    increment_kg: number
    successes_required: number
  }>,
): Promise<WorkoutProgram> {
  const response = await jsonRequest<ItemResponse<WorkoutProgram>>(
    `/workout-programs/${programId}/exercises`, 'PUT', { exercises },
  )
  return response.data
}

export async function upsertPlannedWorkout(
  programId: number,
  date: string,
  payload: WorkoutSessionInput,
): Promise<WorkoutSession> {
  const response = await jsonRequest<ItemResponse<WorkoutSession>>(
    `/workout-programs/${programId}/sessions/${encodeURIComponent(date)}`, 'PUT', payload,
  )
  return response.data
}

export function getWorkouts(from: string, to: string, programId?: number): Promise<WorkoutHistoryResponse> {
  const query = new URLSearchParams({ from, to })
  if (programId) query.set('program_id', String(programId))
  return request<WorkoutHistoryResponse>(`/workouts?${query.toString()}`)
}

export async function createWorkout(payload: WorkoutSessionInput): Promise<WorkoutSession> {
  const response = await jsonRequest<ItemResponse<WorkoutSession>>('/workouts', 'POST', payload)
  return response.data
}

export async function updateWorkout(workoutId: number, payload: WorkoutSessionInput): Promise<WorkoutSession> {
  const response = await jsonRequest<ItemResponse<WorkoutSession>>(`/workouts/${workoutId}`, 'PATCH', payload)
  return response.data
}

export function deleteWorkout(workoutId: number): Promise<void> {
  return request<void>(`/workouts/${workoutId}`, { method: 'DELETE' })
}

export function getTrainingGoals(archived = false): Promise<TrainingGoalsResponse> {
  return request<TrainingGoalsResponse>(`/training/goals?archived=${archived ? '1' : '0'}`)
}

export async function createTrainingGoal(payload: TrainingGoalInput): Promise<TrainingGoal> {
  const response = await jsonRequest<ItemResponse<TrainingGoal>>('/training/goals', 'POST', payload)
  return response.data
}

export async function updateTrainingGoal(goalId: number, payload: Partial<Pick<TrainingGoalInput, 'name' | 'description' | 'target_date' | 'target_value'>> & { status?: TrainingGoal['status'], is_archived?: boolean }): Promise<TrainingGoal> {
  const response = await jsonRequest<ItemResponse<TrainingGoal>>(`/training/goals/${goalId}`, 'PATCH', payload)
  return response.data
}

export async function getRoutines(archived = false): Promise<Routine[]> {
  const response = await request<ListResponse<Routine>>(`/routines?archived=${archived}`)
  return response.data
}

export async function createRoutine(payload: RoutineCreatePayload): Promise<Routine> {
  const response = await jsonRequest<ItemResponse<Routine>>('/routines', 'POST', payload)
  return response.data
}

export async function updateRoutine(routineId: number, payload: RoutineUpdatePayload): Promise<Routine> {
  const response = await jsonRequest<ItemResponse<Routine>>(`/routines/${routineId}`, 'PATCH', payload)
  return response.data
}

export function archiveRoutine(routineId: number): Promise<Routine> {
  return updateRoutine(routineId, { is_archived: true })
}

export function restoreRoutine(routineId: number): Promise<Routine> {
  return updateRoutine(routineId, { is_archived: false })
}

export async function updateRoutineLog(
  routineId: number,
  date: string,
  status: RoutineLog['status'],
  note?: string | null,
): Promise<RoutineLog> {
  const response = await jsonRequest<ItemResponse<RoutineLog>>(`/routines/${routineId}/logs/${date}`, 'PUT', {
    status,
    ...(note === undefined ? {} : { note }),
  })
  return response.data
}

export function clearRoutineLog(routineId: number, date: string): Promise<void> {
  return request<void>(`/routines/${routineId}/logs/${date}`, { method: 'DELETE' })
}

export async function replaceRoutineActivities(
  routineId: number,
  activities: RoutineActivityInput[],
): Promise<RoutineTemplate> {
  const response = await jsonRequest<ItemResponse<RoutineTemplate>>(
    `/routines/${routineId}/activities`,
    'PUT',
    { activities },
  )
  return response.data
}

export async function upsertRoutineActivityLog(
  routineId: number,
  activityId: number,
  date: string,
  payload: RoutineActivityLogPayload,
): Promise<RoutineTemplate> {
  const response = await jsonRequest<ItemResponse<RoutineTemplate>>(
    `/routines/${routineId}/activities/${activityId}/logs/${encodeURIComponent(date)}`,
    'PUT',
    payload,
  )
  return response.data
}

export async function clearRoutineActivityLog(routineId: number, activityId: number, date: string): Promise<RoutineTemplate> {
  const response = await request<ItemResponse<RoutineTemplate>>(
    `/routines/${routineId}/activities/${activityId}/logs/${encodeURIComponent(date)}`,
    { method: 'DELETE' },
  )
  return response.data
}

export async function replaceRoutineDaySelections(
  date: string,
  morningRoutineId: number | null,
  eveningRoutineId: number | null,
): Promise<RoutineDayProjection> {
  const response = await jsonRequest<ItemResponse<RoutineDayProjection>>(
    `/routine-selections/${encodeURIComponent(date)}`,
    'PUT',
    { morning_routine_id: morningRoutineId, evening_routine_id: eveningRoutineId },
  )
  return response.data
}

export function getSleepWorkspace(state: SleepPlanState = 'active', date?: string): Promise<SleepWorkspaceResponse> {
  const query = new URLSearchParams({ state })
  if (date) query.set('date', date)

  return request<SleepWorkspaceResponse>(`/sleep?${query.toString()}`)
}

export async function createSleepPlan(payload: SleepPlanPayload): Promise<SleepPlan> {
  const response = await jsonRequest<ItemResponse<SleepPlan>>('/sleep/plans', 'POST', payload)
  return response.data
}

export async function updateSleepPlan(planId: number, payload: SleepPlanUpdatePayload): Promise<SleepPlan> {
  const response = await jsonRequest<ItemResponse<SleepPlan>>(`/sleep/plans/${planId}`, 'PATCH', payload)
  return response.data
}

export async function upsertSleepLog(planId: number, date: string, payload: SleepLogPayload): Promise<SleepPlan> {
  const response = await jsonRequest<ItemResponse<SleepPlan>>(
    `/sleep/plans/${planId}/logs/${encodeURIComponent(date)}`,
    'PUT',
    payload,
  )
  return response.data
}

export function clearSleepLog(planId: number, date: string): Promise<void> {
  return request<void>(`/sleep/plans/${planId}/logs/${encodeURIComponent(date)}`, { method: 'DELETE' })
}

export async function getSleepStatistics(from: string, to: string): Promise<SleepStatistics> {
  const query = new URLSearchParams({ from, to })
  const response = await request<ItemResponse<SleepStatistics>>(`/sleep/statistics?${query.toString()}`)
  return response.data
}

export function getHabits(state: HabitState = 'active', date?: string): Promise<HabitsResponse> {
  const query = new URLSearchParams({ state })
  if (date) query.set('date', date)

  return request<HabitsResponse>(`/habits?${query.toString()}`)
}

export async function createHabit(payload: HabitCreatePayload): Promise<Habit> {
  const response = await jsonRequest<ItemResponse<Habit>>('/habits', 'POST', payload)
  return response.data
}

export async function updateHabit(habitId: number, payload: HabitUpdatePayload): Promise<Habit> {
  const response = await jsonRequest<ItemResponse<Habit>>(`/habits/${habitId}`, 'PATCH', payload)
  return response.data
}

export async function upsertHabitLog(
  habitId: number,
  date: string,
  payload: HabitLogPayload,
): Promise<Habit> {
  const response = await jsonRequest<ItemResponse<Habit>>(
    `/habits/${habitId}/logs/${encodeURIComponent(date)}`,
    'PUT',
    payload,
  )
  return response.data
}

export function clearHabitLog(habitId: number, date: string): Promise<void> {
  return request<void>(`/habits/${habitId}/logs/${encodeURIComponent(date)}`, { method: 'DELETE' })
}

export async function replaceHabitLimitSteps(
  habitId: number,
  steps: HabitLimitStepInput[],
): Promise<Habit> {
  const response = await jsonRequest<ItemResponse<Habit>>(`/habits/${habitId}/limit-steps`, 'PUT', { steps })
  return response.data
}

export async function getHabitStatistics(
  habitId: number,
  from: string,
  to: string,
): Promise<HabitStatistics> {
  const query = new URLSearchParams({ from, to })
  const response = await request<ItemResponse<HabitStatistics>>(`/habits/${habitId}/statistics?${query.toString()}`)
  return response.data
}

export async function getGoals(archived = false): Promise<Goal[]> {
  const response = await request<ListResponse<Goal>>(`/goals?archived=${archived}`)
  return response.data
}

export async function createGoal(payload: GoalCreatePayload): Promise<Goal> {
  const response = await jsonRequest<ItemResponse<Goal>>('/goals', 'POST', payload)
  return response.data
}

export async function updateGoal(goalId: number, payload: GoalUpdatePayload): Promise<Goal> {
  const response = await jsonRequest<ItemResponse<Goal>>(`/goals/${goalId}`, 'PATCH', payload)
  return response.data
}

export function completeGoal(goalId: number): Promise<Goal> {
  return updateGoal(goalId, { status: 'completed' })
}

export function abandonGoal(goalId: number): Promise<Goal> {
  return updateGoal(goalId, { status: 'abandoned' })
}

export function reactivateGoal(goalId: number): Promise<Goal> {
  return updateGoal(goalId, { status: 'active' })
}

export function archiveGoal(goalId: number): Promise<Goal> {
  return updateGoal(goalId, { is_archived: true })
}

export function restoreGoal(goalId: number): Promise<Goal> {
  return updateGoal(goalId, { is_archived: false })
}

export async function linkRoutineToGoal(goalId: number, routineId: number): Promise<Goal> {
  const response = await request<ItemResponse<Goal>>(`/goals/${goalId}/routines/${routineId}`, {
    method: 'POST',
  })
  return response.data
}

export function unlinkRoutineFromGoal(goalId: number, routineId: number): Promise<void> {
  return request<void>(`/goals/${goalId}/routines/${routineId}`, { method: 'DELETE' })
}

export async function getDailyReview(date: string): Promise<DailyReview | null> {
  const response = await request<ItemResponse<DailyReview | null>>(`/daily-reviews/${encodeURIComponent(date)}`)
  return response.data
}

export async function saveDailyReview(date: string, payload: DailyReviewPayload): Promise<DailyReview> {
  const response = await jsonRequest<ItemResponse<DailyReview>>(
    `/daily-reviews/${encodeURIComponent(date)}`,
    'PUT',
    payload,
  )
  return response.data
}

export function getBodyMeasurements(params: { metric?: string, from?: string, to?: string } = {}): Promise<BodyMeasurementsResponse> {
  const query = new URLSearchParams(
    Object.entries(params).filter((entry): entry is [string, string] => Boolean(entry[1])),
  )
  const suffix = query.toString() ? `?${query.toString()}` : ''

  return request<BodyMeasurementsResponse>(`/body/measurements${suffix}`)
}

export async function saveBodyMeasurement(payload: BodyMeasurementPayload): Promise<BodyMeasurement> {
  const response = await jsonRequest<ItemResponse<BodyMeasurement>>('/body/measurements', 'PUT', payload)
  return response.data
}

export function deleteBodyMeasurement(measurementId: number): Promise<void> {
  return request<void>(`/body/measurements/${measurementId}`, { method: 'DELETE' })
}

export function getBodyTrend(metric: string, params: { from?: string, to?: string } = {}): Promise<BodyTrend> {
  const query = new URLSearchParams({ metric })

  for (const [key, value] of Object.entries(params)) {
    if (value) {
      query.set(key, value)
    }
  }

  return request<BodyTrend>(`/body/trend?${query.toString()}`)
}

export function getBodyGoals(): Promise<BodyGoalsResponse> {
  return request<BodyGoalsResponse>('/body/goals')
}

export function createBodyGoal(payload: BodyGoalPayload): Promise<BodyGoalResponse> {
  return jsonRequest<BodyGoalResponse>('/body/goals', 'POST', payload)
}

export function updateBodyGoal(goalId: number, payload: Partial<BodyGoalPayload>): Promise<BodyGoalResponse> {
  return jsonRequest<BodyGoalResponse>(`/body/goals/${goalId}`, 'PATCH', payload)
}

export function getStorageItems(params: Record<string, string> = {}): Promise<StorageItemsResponse> {
  const query = new URLSearchParams(params)
  const suffix = query.toString() ? `?${query.toString()}` : ''

  return request<StorageItemsResponse>(`/storage/items${suffix}`)
}

export async function createStorageItem(payload: StorageItemPayload): Promise<StorageItem> {
  const response = await jsonRequest<ItemResponse<StorageItem>>('/storage/items', 'POST', payload)
  return response.data
}

export async function updateStorageItem(itemId: number, payload: StorageItemPayload): Promise<StorageItem> {
  const response = await jsonRequest<ItemResponse<StorageItem>>(`/storage/items/${itemId}`, 'PATCH', payload)
  return response.data
}

export function deleteStorageItem(itemId: number): Promise<void> {
  return request<void>(`/storage/items/${itemId}`, { method: 'DELETE' })
}

export function getStorageProjects(): Promise<StorageProjectsResponse> {
  return request<StorageProjectsResponse>('/storage/projects')
}

export async function createStorageProject(payload: StorageProjectPayload): Promise<StorageProject> {
  const response = await jsonRequest<ItemResponse<StorageProject>>('/storage/projects', 'POST', payload)
  return response.data
}

export function deleteStorageProject(projectId: number): Promise<void> {
  return request<void>(`/storage/projects/${projectId}`, { method: 'DELETE' })
}

export function getPlannerDay(date?: string): Promise<PlannerDayResponse> {
  const query = date ? `?date=${encodeURIComponent(date)}` : ''
  return request<PlannerDayResponse>(`/planner/day${query}`)
}

/** Move a planned routine day, or pass `null` to put it back where it was. */
export function reschedulePlannerOccurrence(occurrenceId: number, rescheduledTo: string | null): Promise<unknown> {
  return jsonRequest<unknown>(`/planner/occurrences/${occurrenceId}/reschedule`, 'PATCH', {
    rescheduled_to: rescheduledTo,
  })
}

export function skipPlannerOccurrence(occurrenceId: number): Promise<unknown> {
  return jsonRequest<unknown>(`/planner/occurrences/${occurrenceId}/skip`, 'PUT', {})
}

export async function createTimeBlock(payload: TimeBlockPayload): Promise<TimeBlock> {
  const response = await jsonRequest<ItemResponse<TimeBlock>>('/planner/time-blocks', 'POST', payload)
  return response.data
}

export async function updateTimeBlock(blockId: number, payload: TimeBlockPayload): Promise<TimeBlock> {
  const response = await jsonRequest<ItemResponse<TimeBlock>>(`/planner/time-blocks/${blockId}`, 'PATCH', payload)
  return response.data
}

export function deleteTimeBlock(blockId: number): Promise<void> {
  return request<void>(`/planner/time-blocks/${blockId}`, { method: 'DELETE' })
}

export function getNotifications(view: NotificationView = 'all'): Promise<NotificationListResponse> {
  return request<NotificationListResponse>(`/notifications?view=${encodeURIComponent(view)}`)
}

export function getNotificationSettings(): Promise<NotificationSettingsResponse> {
  return request<NotificationSettingsResponse>('/notifications/settings')
}

export function replaceNotificationSettings(
  payload: NotificationSettingsData,
): Promise<NotificationSettingsResponse> {
  return jsonRequest<NotificationSettingsResponse>('/notifications/settings', 'PUT', payload)
}

export function readNotification(notificationId: number): Promise<NotificationActionResponse> {
  return jsonRequest<NotificationActionResponse>(`/notifications/${notificationId}/read`, 'PUT', {})
}

export function dismissNotification(notificationId: number): Promise<void> {
  return jsonRequest<void>(`/notifications/${notificationId}/dismiss`, 'PUT', {})
}

export function snoozeNotification(
  notificationId: number,
  minutes: NotificationSnoozeMinutes,
): Promise<NotificationSnoozeResponse> {
  return jsonRequest<NotificationSnoozeResponse>(`/notifications/${notificationId}/snooze`, 'PUT', { minutes })
}

export function acknowledgeMobileNotificationPresentation(notificationId: number): Promise<unknown> {
  return jsonRequest<unknown>(`/mobile/notifications/${notificationId}/presented`, 'PUT', {})
}
