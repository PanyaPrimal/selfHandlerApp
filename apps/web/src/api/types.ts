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

export type ThemeScheme = 'light' | 'dark' | 'system'
export type ThemeAccent = 'forest' | 'slate' | 'gold' | 'brick' | 'custom'
export type ThemeBackground = 'paper' | 'sand' | 'mist' | 'sage' | 'custom'
export type ThemeMotion = 'system' | 'reduce'

export interface ThemePreferences {
  scheme: ThemeScheme
  accent: ThemeAccent
  accent_hex: string
  background: ThemeBackground
  background_hex: string
  texture: boolean
  mono_numerals: boolean
  motion: ThemeMotion
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
  theme: ThemePreferences
}

export interface Profile extends Omit<PreferenceSummary, 'calculation_ready' | 'theme'> {
  user: User
  date_of_birth: string | null
  sex: ProfileSex | null
  height_meters: number | null
  weight_grams: number | null
  body_fat_percentage: number | null
  baseline_activity: BaselineActivity | null
  calculation_ready: boolean
  missing_fields: string[]
  theme: ThemePreferences
  updated_at: string
}

export interface ProfileInput extends Omit<Profile, 'user' | 'calculation_ready' | 'missing_fields' | 'theme' | 'updated_at'> {
  name: string
}

export interface PreferencesPayload {
  preferences: {
    locale?: ProfileLocale
    theme?: ThemePreferences
  }
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
  day_period: RoutineDayPeriod
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
  activities: RoutineActivity[]
}

export type RoutineDayPeriod = 'morning' | 'evening' | 'anytime'

export interface RoutineActivityLog {
  id: number
  routine_activity_id: number
  log_date: string
  status: 'done' | 'skipped'
  progress_value: number | null
  note: string | null
  completed_at: string | null
}

export interface RoutineActivity {
  id: number
  name: string
  sort_order: number
  preferred_time: string | null
  progress_total: number | null
  has_facts: boolean
  selected_day_log: RoutineActivityLog | null
}

export interface RoutineActivityInput {
  id?: number
  name: string
  sort_order: number
  preferred_time?: string | null
  progress_total?: number | null
}

export interface RoutineTemplate {
  id: number
  name: string
  day_period: RoutineDayPeriod
  activities: RoutineActivity[]
  parent_state: 'pending' | 'done' | 'skipped'
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

export interface TodayRoutine extends Pick<Routine, 'id' | 'name' | 'description' | 'kind' | 'day_period' | 'preferred_time' | 'sort_order' | 'is_active' | 'is_archived' | 'activities'> {
  log: RoutineLog | null
  parent_state: 'pending' | 'done' | 'skipped'
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
  module_summaries: ModuleDaySummaries
  routine_day: RoutineDayProjection
}

export interface ListResponse<T> {
  data: T[]
}

export interface ItemResponse<T> {
  data: T
}

export interface MobileSessionResponse {
  data: {
    token: string
    token_type: 'Bearer'
    expires_at: string
    user: User
  }
}

export interface MobileCurrentSessionResponse {
  data: {
    expires_at: string
    user: User
  }
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
  day_period?: RoutineDayPeriod
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

/* ------------------------------------------------------------------ */
/* Sleep and rich routine templates (feature 014)                     */
/* ------------------------------------------------------------------ */

export type SleepPlanState = 'active' | 'paused' | 'archived'

export interface SleepSchedule {
  schedule_type: 'daily' | 'weekdays'
  weekdays: Weekday[]
  planned_bed_time: string
  starts_on: string | null
  ends_on: string | null
}

export interface SleepLog {
  id: number
  sleep_plan_id: number
  sleep_date: string
  actual_bed_at: string
  actual_wake_at: string
  actual_bed_date: string
  actual_bed_time: string
  actual_wake_date: string
  actual_wake_time: string
  duration_minutes: number
  quality: number
  note: string | null
}

export interface SleepNight {
  sleep_plan_id?: number
  date: string
  occurrence_id: number
  planned_bed_time: string
  planned_wake_date: string
  planned_wake_time: string
  state: 'planned' | 'recorded'
  rescheduled_from: string | null
  log: SleepLog | null
}

export interface SleepPlan {
  id: number
  name: string
  planned_wake_time: string
  is_active: boolean
  is_archived: boolean
  archived_at: string | null
  schedule: SleepSchedule
  selected_night: SleepNight | null
}

export interface SleepStatistics {
  period_start: string
  period_end: string
  planned_nights: number
  recorded_nights: number
  average_duration_minutes: number | null
  average_quality: number | null
}

export interface SleepWorkspaceResponse {
  date: string
  today: string
  data: SleepPlan[]
  statistics: SleepStatistics
}

export interface SleepPlanPayload {
  name: string
  planned_bed_time: string
  planned_wake_time: string
  schedule_type: 'daily' | 'weekdays'
  weekdays?: Weekday[]
  starts_on?: string | null
  ends_on?: string | null
  is_active?: boolean
}

export type SleepPlanUpdatePayload = AtLeastOne<Partial<SleepPlanPayload> & {
  is_archived?: boolean
}>

export interface SleepLogPayload {
  actual_bed_date: string
  actual_bed_time: string
  actual_wake_date: string
  actual_wake_time: string
  quality: number
  note?: string | null
}

export interface RoutineActivitySummary {
  scheduled: number
  done: number
  skipped: number
  pending: number
  completion_rate: number | null
  templates: Array<{
    routine_id: number
    name: string
    scheduled: number
    done: number
    skipped: number
    pending: number
    completion_rate: number | null
  }>
}

export interface RoutineCandidate {
  routine_id: number
  occurrence_id: number
  name: string
  day_period: RoutineDayPeriod
  preferred_time: string | null
  sort_order: number
}

export interface RoutinePeriodProjection {
  period: 'morning' | 'evening'
  source: 'default' | 'explicit'
  selected: RoutineCandidate | null
  candidates: RoutineCandidate[]
}

export interface RoutineDayProjection {
  date: string
  morning: RoutinePeriodProjection
  evening: RoutinePeriodProjection
  anytime: RoutineCandidate[]
  activity_summary: RoutineActivitySummary
}

export interface RoutineActivityLogPayload {
  status: 'done' | 'skipped'
  progress_value?: number | null
  note?: string | null
}

export interface ModuleDaySummaries {
  sleep: SleepStatistics & { selected_night: SleepNight | null }
  routine_activities: RoutineActivitySummary
}

/* ------------------------------------------------------------------ */
/* Habits and anti-habits (feature 013)                                */
/* ------------------------------------------------------------------ */

export type HabitKind = 'habit' | 'anti_habit'
export type HabitMode = 'yes_no' | 'numeric' | 'abstinence' | 'stepped_limit'
export type HabitOutcome = 'done' | 'not_done' | 'recorded' | 'protected' | 'relapse' | 'skipped'
export type HabitState = 'active' | 'paused' | 'archived'
export type HabitLimitPeriod = 'day' | 'week'

export interface HabitLog {
  id: number
  log_date: string
  outcome: HabitOutcome
  value: number | null
  occurred_at: string | null
  note: string | null
  successful: boolean
}

export interface HabitStatistics {
  from: string
  to: string
  opportunities: number
  successes: number
  completion_percentage: number
  current_streak: number
  best_streak: number
  numeric_total: number | null
}

export interface HabitLimitStep {
  id: number
  effective_on: string
  limit_value: number
  period: HabitLimitPeriod
  status: 'completed' | 'current' | 'upcoming'
}

export interface HabitLimitStepInput {
  effective_on: string
  limit_value: number
  period: HabitLimitPeriod
}

export interface HabitLimitStatus {
  state: 'no_active_step' | 'within' | 'exceeded'
  step: HabitLimitStep | null
  period_from: string | null
  period_to: string | null
  consumed: number
  remaining: number | null
  within_limit: boolean | null
}

export interface Habit {
  id: number
  name: string
  description: string | null
  kind: HabitKind
  mode: HabitMode
  target_value: number | null
  unit: string | null
  schedule: {
    schedule_type: 'daily' | 'weekdays'
    weekdays: Weekday[]
    preferred_time: string | null
    starts_on: string | null
    ends_on: string | null
    timezone: string
    materialized_until: string | null
  }
  routine: { id: number, name: string } | null
  goal: { id: number, name: string } | null
  intention_place: string | null
  two_minute_starter: string | null
  is_active: boolean
  is_archived: boolean
  archived_at: string | null
  limit_steps: HabitLimitStep[]
  selected_day: {
    date: string
    occurrence_id: number | null
    is_scheduled: boolean
    is_open: boolean
    log: HabitLog | null
  }
  statistics: HabitStatistics
  limit_status: HabitLimitStatus | null
}

export interface HabitInput {
  name?: string
  description?: string | null
  target_value?: number | null
  unit?: string | null
  schedule_type?: 'daily' | 'weekdays'
  weekdays?: Weekday[]
  preferred_time?: string | null
  starts_on?: string | null
  ends_on?: string | null
  routine_id?: number | null
  goal_id?: number | null
  intention_place?: string | null
  two_minute_starter?: string | null
  is_active?: boolean
  is_archived?: boolean
}

export interface HabitCreatePayload extends HabitInput {
  name: string
  kind: HabitKind
  mode: HabitMode
  schedule_type: 'daily' | 'weekdays'
  limit_steps?: HabitLimitStepInput[]
}

export type HabitUpdatePayload = AtLeastOne<HabitInput>

export interface HabitsResponse {
  date: string
  today: string
  data: Habit[]
  options: {
    kinds: HabitKind[]
    modes: HabitMode[]
    outcomes: HabitOutcome[]
    periods: HabitLimitPeriod[]
    weekdays: Weekday[]
  }
}

export interface HabitLogPayload {
  outcome: HabitOutcome
  value?: number | null
  occurred_time?: string | null
  note?: string | null
}

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
export type PlannerSource = 'routine' | 'sleep' | 'habit' | 'storage' | 'time_block'

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

/* ------------------------------------------------------------------ */
/* In-app notifications                                                */
/* ------------------------------------------------------------------ */

export type NotificationType = 'routine_reminder' | 'habit_reminder' | 'storage_due' | 'daily_digest'
export type NotificationCategory = 'routine' | 'habit' | 'storage' | 'digest'
export type NotificationStatus = 'sent' | 'read'
export type NotificationView = 'all' | 'unread'
export type NotificationSnoozeMinutes = 15 | 60 | 240 | 1440

export interface InAppNotification {
  id: number
  type: NotificationType
  category: NotificationCategory
  subject: string | null
  title: string
  body: string
  action_url: string | null
  status: NotificationStatus
  channels: readonly ('in_app' | 'android_local')[]
  escalation_count: number
  sent_at: string
  read_at: string | null
}

export interface NotificationListResponse {
  data: InAppNotification[]
  unread_count: number
  views: NotificationView[]
  snooze_options: NotificationSnoozeMinutes[]
}

export interface NotificationSettingsData {
  quiet_hours: {
    enabled: boolean
    starts_at: string
    ends_at: string
  }
  digest: {
    enabled: boolean
    time: string
  }
  categories: {
    routine: boolean
    storage: boolean
    habit: boolean
    sleep: boolean
  }
}

export interface NotificationSettingsResponse {
  data: NotificationSettingsData
  options: {
    categories: Array<'routine' | 'storage' | 'habit' | 'sleep'>
    channels: ['in_app']
    snooze_minutes: NotificationSnoozeMinutes[]
  }
}

export interface NotificationActionResponse {
  data: InAppNotification
  unread_count: number
}

export interface NotificationSnoozeResponse {
  data: {
    id: number
    status: 'snoozed'
    snoozed_until: string
  }
}
