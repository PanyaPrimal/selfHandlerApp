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

const apiBaseUrl = import.meta.env.VITE_API_BASE_URL ?? '/api'

export class ApiError extends Error {
  public readonly status: number
  public readonly payload: unknown

  constructor(message: string, status: number, payload: unknown) {
    super(message)
    this.status = status
    this.payload = payload
  }
}

async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetch(`${apiBaseUrl}${path}`, {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...init.headers,
    },
    ...init,
  })

  const text = await response.text()
  const payload = text ? JSON.parse(text) : null

  if (!response.ok) {
    throw new ApiError(`API request failed with ${response.status}`, response.status, payload)
  }

  return payload as T
}

function jsonRequest<T>(path: string, method: string, body: unknown): Promise<T> {
  return request<T>(path, {
    method,
    body: JSON.stringify(body),
  })
}

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
