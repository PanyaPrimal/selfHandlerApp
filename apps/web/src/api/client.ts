import type {
  DailyReview,
  DailyReviewPayload,
  Goal,
  GoalCreatePayload,
  GoalUpdatePayload,
  ItemResponse,
  ListResponse,
  Routine,
  RoutineCreatePayload,
  RoutineLog,
  RoutineUpdatePayload,
  TodayResponse,
} from './types'
import { jsonRequest, request } from './http'

// The SelfHandler error contract: a message for the user plus the per-field
// validation errors of a 422 response.
export { ApiError, validationErrors } from './http'
export type { ValidationErrors } from './http'

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
