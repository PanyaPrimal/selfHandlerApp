export type Weekday = 'MO' | 'TU' | 'WE' | 'TH' | 'FR' | 'SA' | 'SU'

export type Rating = number | null

export interface Goal {
  id: number
  name: string
  description: string | null
  type: string
  status: 'active' | 'completed' | 'abandoned'
  target_date: string | null
  completed_at: string | null
  is_archived: boolean
  archived_at: string | null
  routines?: RoutineSummary[]
}

export interface GoalSummary extends Pick<Goal, 'id' | 'name' | 'status'> {}

export interface User {
  id: number
  name: string
  email: string
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
  weekdays: Weekday[]
  preferred_time: string | null
  sort_order: number
  is_active: boolean
  is_archived: boolean
  archived_at: string | null
  starts_on: string | null
  ends_on: string | null
  goals?: GoalSummary[]
}

export interface RoutineSummary extends Pick<Routine, 'id' | 'name' | 'is_active' | 'is_archived'> {}

export interface DailyReviewFields {
  mood?: Rating
  energy?: Rating
  stress?: Rating
  day_rating?: Rating
  went_well?: string | null
  improve_tomorrow?: string | null
  notes?: string | null
}

export interface DailyReview extends DailyReviewFields {
  id: number
  review_date: string
  mood: Rating
  energy: Rating
  stress: Rating
  day_rating: Rating
  went_well: string | null
  improve_tomorrow: string | null
  notes: string | null
  completed_at: string
}

export interface TodayRoutine extends Pick<Routine, 'id' | 'name' | 'description' | 'kind' | 'preferred_time' | 'sort_order' | 'is_active' | 'is_archived'> {
  log: RoutineLog | null
  goals: GoalSummary[]
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
  goals: (GoalSummary & Pick<Goal, 'target_date'>)[]
  review: DailyReview | null
}

export interface ListResponse<T> {
  data: T[]
}

export interface ItemResponse<T> {
  data: T
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  invite_code: string
}

export interface LoginPayload {
  email: string
  password: string
}

export interface RoutineInput {
  name?: string
  description?: string | null
  kind?: Routine['kind']
  schedule_type?: Routine['schedule_type']
  weekdays?: Weekday[]
  preferred_time?: string | null
  sort_order?: number
  is_active?: boolean
  is_archived?: boolean
  starts_on?: string | null
  ends_on?: string | null
}

export interface RoutineCreatePayload extends RoutineInput {
  name: string
  schedule_type: Routine['schedule_type']
}

export type RoutineUpdatePayload = RoutineInput

export interface GoalPayload {
  name: string
  description?: string | null
  type?: string
  status?: Goal['status']
  target_date?: string | null
}

export type DailyReviewPayload = {
  [Field in keyof DailyReviewFields]-?: Required<Pick<DailyReviewFields, Field>>
    & Partial<Omit<DailyReviewFields, Field>>
}[keyof DailyReviewFields]
