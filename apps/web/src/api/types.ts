export type Weekday = 'MO' | 'TU' | 'WE' | 'TH' | 'FR' | 'SA' | 'SU'

export type Rating = number | null

type AtLeastOne<T> = {
  [Field in keyof T]-?: Required<Pick<T, Field>> & Partial<Omit<T, Field>>
}[keyof T]

export interface Goal {
  id: number
  name: string
  description: string | null
  type: 'general'
  status: 'active' | 'completed' | 'abandoned'
  target_date: string | null
  completed_at: string | null
  is_archived: boolean
  archived_at: string | null
  routines: RoutineSummary[]
}

export interface GoalSummary extends Pick<Goal, 'id' | 'name' | 'status'> {}

export interface TodayGoalSummary extends GoalSummary, Pick<Goal, 'target_date'> {}

export interface User {
  id: number
  name: string
  email: string
  preferences: PreferenceSummary
}

export type ProfileLocale = 'en-GB' | 'uk-UA' | 'ru-UA'
export type UnitSystem = 'metric' | 'imperial'
export type BaseCurrency = 'UAH' | 'USD' | 'EUR'
export type RecommendationTone = 'neutral' | 'friendly' | 'direct'
export type BmrFormula = 'mifflin_st_jeor' | 'katch_mcardle'
export type ProfileSex = 'female' | 'male' | 'unspecified'
export type BaselineActivity = 'sedentary' | 'light' | 'moderate' | 'high'

export interface PreferenceSummary {
  timezone: string
  locale: ProfileLocale
  unit_system: UnitSystem
  base_currency: BaseCurrency
  recommendation_tone: RecommendationTone
  bmr_formula: BmrFormula
  calculation_ready: boolean
}

export interface Profile extends Omit<PreferenceSummary, 'calculation_ready'> {
  user: User
  date_of_birth: string | null
  sex: ProfileSex | null
  height_meters: number | null
  weight_grams: number | null
  body_fat_percentage: number | null
  baseline_activity: BaselineActivity | null
  calculation_ready: boolean
  missing_fields: string[]
  updated_at: string
}

export interface ProfileInput extends Omit<Profile, 'user' | 'calculation_ready' | 'missing_fields' | 'updated_at'> {
  name: string
}

export interface ProfileOptions {
  timezones: string[]
  locales: ProfileLocale[]
  unit_systems: UnitSystem[]
  base_currencies: BaseCurrency[]
  recommendation_tones: RecommendationTone[]
  bmr_formulas: BmrFormula[]
  sexes: ProfileSex[]
  baseline_activities: BaselineActivity[]
}

export interface ProfileResponse {
  data: Profile
  options: ProfileOptions
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
  goals: GoalSummary[]
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
  current_streak: number
}

export interface CompletionSummary {
  scheduled: number
  done: number
  skipped: number
  pending: number
  completion_rate: number
}

export interface TodayResponse {
  date: string
  summary: CompletionSummary
  routines: TodayRoutine[]
  goals: TodayGoalSummary[]
  review: DailyReview | null
  progress: {
    period_start: string
    period_end: string
    seven_day: CompletionSummary
  }
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

type RoutineCreateFields = Omit<RoutineInput, 'name' | 'schedule_type' | 'weekdays'> & {
  name: string
}

export type RoutineCreatePayload = RoutineCreateFields & (
  | { schedule_type: 'daily', weekdays?: never }
  | { schedule_type: 'weekdays', weekdays: Weekday[] }
)

export type RoutineUpdatePayload = AtLeastOne<RoutineInput>

export interface GoalInput {
  name?: string
  description?: string | null
  status?: Goal['status']
  target_date?: string | null
  is_archived?: boolean
}

export interface GoalCreatePayload extends GoalInput {
  name: string
}

export type GoalUpdatePayload = AtLeastOne<GoalInput>

export type DailyReviewPayload = AtLeastOne<DailyReviewFields>

/* ------------------------------------------------------------------ */
/* Body measurements and body goals (feature 007)                      */
/* ------------------------------------------------------------------ */

export type BodyMetricKey =
  | 'body_mass'
  | 'body_fat_percentage'
  | 'waist'
  | 'chest'
  | 'hips'
  | 'thigh'
  | 'upper_arm'
  | 'neck'
  | 'calf'

export type BodyGoalDirection = 'lose' | 'gain' | 'maintain'

export interface BodyMetricOption {
  value: BodyMetricKey
  label: string
  /** `gram`, `metre` or `percent` — the unit every value crosses the API in. */
  canonical_unit: 'gram' | 'metre' | 'percent'
  display_unit: { metric: string, imperial: string }
  minimum: string
  maximum: string
}

export interface BodyMeasurement {
  id: number
  metric: BodyMetricKey
  measured_on: string
  /** Canonical base unit, as an exact decimal string. */
  value: string
  note: string | null
}

export interface BodyMeasurementsResponse {
  data: BodyMeasurement[]
  metrics: BodyMetricOption[]
  /** The user's current day in their profile time zone. */
  today: string
  from: string
  to: string
}

export interface BodyMeasurementPayload {
  metric: BodyMetricKey
  measured_on: string
  value: number
  note?: string | null
}

export interface BodyTrendPoint {
  measured_on: string
  value: string
}

export interface BodyTrend {
  metric: BodyMetricKey
  state: 'empty' | 'insufficient' | 'ready'
  points: number
  first: BodyTrendPoint | null
  last: BodyTrendPoint | null
  /** Null in the empty and insufficient states — never zero. */
  change_per_week: string | null
}

export interface BodyGoalMilestone {
  id: number
  target_value: string
  target_date: string | null
  achieved: boolean
}

export interface BodyGoalDetail {
  metric: BodyMetricKey
  metric_label: string
  direction: BodyGoalDirection
  starting_value: string
  target_value: string
  /** Null until the metric has an observation — never zero. */
  current_value: string | null
  measured_on: string | null
  progress: number | null
  milestones: BodyGoalMilestone[]
}

export interface BodyGoal {
  id: number
  name: string
  description: string | null
  type: 'body'
  status: Goal['status']
  target_date: string | null
  completed_at: string | null
  is_archived: boolean
  archived_at: string | null
  body: BodyGoalDetail | null
}

export interface BodyGoalWarning {
  field: string
  code: string
  message: string
}

export interface BodyGoalPayload {
  name: string
  description?: string | null
  target_date?: string | null
  metric: BodyMetricKey
  direction: BodyGoalDirection
  starting_value: number
  target_value: number
  milestones?: Array<{ target_value: number, target_date?: string | null }>
}

export interface BodyGoalsResponse {
  data: BodyGoal[]
  metrics: BodyMetricOption[]
  directions: BodyGoalDirection[]
}

export interface BodyGoalResponse {
  data: BodyGoal
  warnings: BodyGoalWarning[]
}

/* ------------------------------------------------------------------ */
/* Storage inbox (feature 008)                                         */
/* ------------------------------------------------------------------ */

export type ItemType = 'task' | 'idea'
export type ItemStatus = 'inbox' | 'active' | 'done' | 'dropped'
export type ItemPriority = 'low' | 'normal' | 'high'

export interface StorageTag {
  id: number
  name: string
}

export interface StorageItem {
  id: number
  type: ItemType
  title: string
  description: string | null
  status: ItemStatus
  priority: ItemPriority | null
  due_on: string | null
  project_id: number | null
  parent_id: number | null
  is_blocker: boolean
  completed_at: string | null
  dropped_at: string | null
  tags: StorageTag[]
  children?: StorageItem[]
}

export interface StorageItemsResponse {
  data: StorageItem[]
  /** How much is still unsorted, computed by the Storage module. */
  inbox_count: number
  types: ItemType[]
  statuses: ItemStatus[]
  priorities: ItemPriority[]
}

export interface StorageItemPayload {
  title?: string
  type?: ItemType
  description?: string | null
  status?: ItemStatus
  priority?: ItemPriority | null
  due_on?: string | null
  project_id?: number | null
  parent_id?: number | null
  is_blocker?: boolean
  tags?: string[]
}

export interface StorageProject {
  id: number
  name: string
  description: string | null
  is_archived: boolean
  archived_at: string | null
  open_count: number
  completed_count: number
}

export interface StorageProjectsResponse {
  data: StorageProject[]
}

export interface StorageProjectPayload {
  name?: string
  description?: string | null
  is_archived?: boolean
}

/* ------------------------------------------------------------------ */
/* Planner                                                            */
/* ------------------------------------------------------------------ */

/** Which module a day entry came from. Planner owns only `time_block`. */
export type PlannerSource = 'routine' | 'storage' | 'time_block'

/** What the user may do with an entry from inside the planner. */
export type PlannerAction = 'skip' | 'reschedule' | 'move' | 'edit' | 'delete'

export interface PlannerEntry {
  source: PlannerSource
  /** The id in the owning module, not a planner id: nothing is copied here. */
  source_id: number
  title: string
  /** `HH:MM`, or null for an entry with no time of day. */
  time: string | null
  status: string
  actions: PlannerAction[]
  meta: Record<string, unknown>
}

export interface PlannerWindow {
  /** How far routine days have been expanded, as `YYYY-MM-DD`. */
  materialized_until: string | null
  /** True when the day asked for lies past that point. */
  beyond: boolean
}

export interface PlannerDayResponse {
  date: string
  today: string
  entries: PlannerEntry[]
  window: PlannerWindow
  sources: PlannerSource[]
}

export interface TimeBlock {
  id: number
  title: string
  note: string | null
  block_date: string
  starts_at: string | null
  ends_at: string | null
}

export interface TimeBlockPayload {
  title?: string
  note?: string | null
  block_date?: string
  starts_at?: string | null
  ends_at?: string | null
}
