export interface Goal {
  id: number
  name: string
  description: string | null
  type: string
  status: 'active' | 'completed' | 'abandoned'
  target_date: string | null
  completed_at: string | null
}

export interface RoutineLog {
  id: number
  routine_id: number
  log_date: string
  status: 'done' | 'skipped'
  note: string | null
  completed_at: string | null
}

export interface Routine {
  id: number
  name: string
  description: string | null
  kind: 'routine' | 'sleep' | 'habit'
  schedule_type: 'daily' | 'weekdays'
  weekdays: string[] | null
  preferred_time: string | null
  sort_order: number
  is_active: boolean
  starts_on: string | null
  ends_on: string | null
  goals?: Goal[]
}

export interface DailyReview {
  id: number
  review_date: string
  mood: number | null
  energy: number | null
  stress: number | null
  day_rating: number | null
  went_well: string | null
  improve_tomorrow: string | null
  notes: string | null
  completed_at: string | null
}

export interface TodayRoutine extends Pick<Routine, 'id' | 'name' | 'description' | 'kind' | 'preferred_time' | 'sort_order'> {
  log: RoutineLog | null
  goals: Pick<Goal, 'id' | 'name' | 'status'>[]
}

export interface TodaySummary {
  scheduled: number
  done: number
  skipped: number
  pending: number
  completion_rate: number
}

export interface TodayResponse {
  date: string
  summary: TodaySummary
  routines: TodayRoutine[]
  goals: Pick<Goal, 'id' | 'name' | 'status' | 'target_date'>[]
  review: DailyReview | null
}

export interface ListResponse<T> {
  data: T[]
}

export interface ItemResponse<T> {
  data: T
}

export interface RoutinePayload {
  name: string
  description?: string | null
  kind?: Routine['kind']
  schedule_type?: Routine['schedule_type']
  weekdays?: string[] | null
  preferred_time?: string | null
  sort_order?: number
  is_active?: boolean
  starts_on?: string | null
  ends_on?: string | null
}

export interface GoalPayload {
  name: string
  description?: string | null
  type?: string
  status?: Goal['status']
  target_date?: string | null
}

export interface DailyReviewPayload {
  mood?: number | null
  energy?: number | null
  stress?: number | null
  day_rating?: number | null
  went_well?: string | null
  improve_tomorrow?: string | null
  notes?: string | null
}
