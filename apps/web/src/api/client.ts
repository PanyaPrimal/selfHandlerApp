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
  ItemResponse,
  ListResponse,
  PlannerDayResponse,
  ProfileInput,
  ProfileResponse,
  Routine,
  RoutineCreatePayload,
  RoutineLog,
  RoutineUpdatePayload,
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
