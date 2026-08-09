import type {
  DailyReview,
  DailyReviewPayload,
  Goal,
  GoalPayload,
  ItemResponse,
  ListResponse,
  Routine,
  RoutineLog,
  RoutinePayload,
  TodayResponse,
} from './types'
import { jsonRequest, request } from './http'

export { ApiError } from './http'

export function getToday(date: string): Promise<TodayResponse> {
  return request<TodayResponse>(`/today?date=${encodeURIComponent(date)}`)
}

export async function getRoutines(): Promise<Routine[]> {
  const response = await request<ListResponse<Routine>>('/routines')
  return response.data
}

export async function createRoutine(payload: RoutinePayload): Promise<Routine> {
  const response = await jsonRequest<ItemResponse<Routine>>('/routines', 'POST', payload)
  return response.data
}

export async function updateRoutineLog(routineId: number, date: string, status: RoutineLog['status']): Promise<RoutineLog> {
  const response = await jsonRequest<ItemResponse<RoutineLog>>(`/routines/${routineId}/logs/${date}`, 'PUT', { status })
  return response.data
}

export async function getGoals(): Promise<Goal[]> {
  const response = await request<ListResponse<Goal>>('/goals')
  return response.data
}

export async function createGoal(payload: GoalPayload): Promise<Goal> {
  const response = await jsonRequest<ItemResponse<Goal>>('/goals', 'POST', payload)
  return response.data
}

export async function getDailyReview(date: string): Promise<DailyReview | null> {
  const response = await request<ItemResponse<DailyReview | null>>(`/daily-reviews/${date}`)
  return response.data
}

export async function saveDailyReview(date: string, payload: DailyReviewPayload): Promise<DailyReview> {
  const response = await jsonRequest<ItemResponse<DailyReview>>(`/daily-reviews/${date}`, 'PUT', payload)
  return response.data
}
