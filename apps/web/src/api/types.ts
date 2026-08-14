export type Weekday = 'MO' | 'TU' | 'WE' | 'TH' | 'FR' | 'SA' | 'SU'

export type Rating = number | null

type AtLeastOne<T> = {
  [Field in keyof T]-?: Required<Pick<T, Field>> & Partial<Omit<T, Field>>
}[keyof T]

export interface Goal {
  id: number
  name: string
  description: string | null
  type: 'general' | 'finance'
  status: 'active' | 'completed' | 'abandoned'
  target_date: string | null
  completed_at: string | null
  is_archived: boolean
  archived_at: string | null
  routines: RoutineSummary[]
  finance?: FinanceGoal
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
  mood: Rating | null
  energy: Rating | null
  stress: Rating | null
  day_rating: Rating | null
  went_well: string | null
  improve_tomorrow: string | null
  notes: string | null
  completed_at: string | null
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
  day_score: DayScore
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
  routines: ReviewStatusSummary
  sleep: SleepStatistics & { selected_night: SleepNight | null }
  routine_activities: RoutineActivitySummary
  workouts: WorkoutSummary
  nutrition: NutritionSummary
  supplements: SupplementAdherenceSummary
  habits: HabitReviewSummary
  planner: PlannerReviewSummary
  finance: FinanceActualSummary
}

export type ReviewPeriodType = 'daily' | 'weekly' | 'monthly'
export type PeriodicReviewType = Exclude<ReviewPeriodType, 'daily'>

export interface ReviewPeriod {
  type: ReviewPeriodType
  anchor: string
  start: string
  end: string
  timezone: string
}

export interface ReviewStatusSummary {
  scheduled: number
  done: number
  skipped: number
  pending: number
  completion_rate: number | null
}

export interface HabitReviewSummary extends ReviewStatusSummary {
  successful: number
  unsuccessful: number
  habit_count: number
}

export interface PlannerReviewSummary extends ReviewStatusSummary {
  time_blocks: number
  due_items: number
  open_blockers: number
}

export interface FinanceActualSummary {
  from: string
  to: string
  base_currency: FinanceCurrencyCode
  complete: boolean
  income: string | null
  expense: string | null
  net: string | null
  missing_currencies: FinanceCurrencyCode[]
}

export type DayScoreComponentKey = 'nutrition' | 'workouts' | 'supplements' | 'habits' | 'planner'
export type DayScoreReason = 'available' | 'no_target_evidence' | 'no_workout' | 'no_scheduled_items' | 'no_planner_items'

export interface DayScoreComponent {
  key: DayScoreComponentKey
  available: boolean
  value: number | null
  weight: number
  reason: DayScoreReason
}

export interface DayScore {
  value: number | null
  available_components: number
  total_components: 5
  coverage_percentage: number
  components: DayScoreComponent[]
}

export interface DailyReviewWorkspace {
  period: ReviewPeriod & { type: 'daily' }
  review: DailyReview | null
  modules: ModuleDaySummaries
  day_score: DayScore
}

export interface PeriodicReviewFields {
  period_rating?: Rating | null
  worked_well?: string | null
  did_not_work?: string | null
  learned?: string | null
  next_focus?: string | null
  notes?: string | null
}

export interface PeriodicReview extends Required<PeriodicReviewFields> {
  id: number
  period_type: PeriodicReviewType
  period_start: string
  period_end: string
  completed_at: string
  created_at: string
  updated_at: string
}

export interface NutritionPeriodReviewSummary {
  from: string
  to: string
  days: number
  meal_count: number
  entry_count: number
  calories: string
  protein_grams: string
  fat_grams: string
  carbs_grams: string
  hydration_ml: string
}

export interface PeriodicReviewModules {
  routines: ReviewStatusSummary
  sleep: SleepStatistics & { selected_night: null }
  workouts: WorkoutSummary
  nutrition: NutritionPeriodReviewSummary
  supplements: SupplementAdherenceSummary
  habits: HabitReviewSummary
  planner: PlannerReviewSummary
  finance: FinanceActualSummary
}

export interface WellBeingSummary {
  reviewed_days: number
  period_days: number
  mood: number | null
  energy: number | null
  stress: number | null
  day_rating: number | null
}

export interface PeriodicReviewWorkspace {
  period: ReviewPeriod & { type: PeriodicReviewType }
  review: PeriodicReview | null
  modules: PeriodicReviewModules
  well_being: WellBeingSummary
}

/* ------------------------------------------------------------------ */
/* Supplements, courses, intake, and stock (feature 017)              */
/* ------------------------------------------------------------------ */

export type SupplementCategory = 'vitamin' | 'sports_nutrition' | 'nootropic' | 'medication' | 'other'
export type SupplementForm = 'capsule' | 'tablet' | 'powder' | 'liquid' | 'injection' | 'other'
export type SupplementStockUnit = 'gram' | 'millilitre' | 'piece'
export type SupplementDisplayUnit = 'mg' | 'g' | 'ml' | 'piece'
export type SupplementLifecycleState = 'active' | 'archived' | 'all'
export type SupplementIntakeContext = 'unspecified' | 'with_food' | 'empty_stomach'

export interface SupplementStock {
  remaining_quantity: string
  stock_unit: SupplementStockUnit
  is_negative: boolean
}

export interface SupplementForecast {
  status: 'ready' | 'already_depleted' | 'no_stock' | 'no_active_course' | 'no_consumption' | 'course_ends_with_stock' | 'beyond_horizon'
  as_of: string
  runout_on: string | null
  horizon_until: string
  remaining_quantity: string
  stock_unit: SupplementStockUnit
  projected_occurrences: number
  projected_consumption: string
  last_course_end: string | null
}

export interface SupplementRestockProposal {
  id: number
  supplement_id: number
  forecast_runout_on: string
  needed_by: string
  suggested_quantity: string | null
  stock_unit: SupplementStockUnit
  status: 'open' | 'dismissed' | 'resolved'
  dismissed_at: string | null
  resolved_at: string | null
  created_at: string
  updated_at: string
}

export interface Supplement {
  id: number
  name: string
  category: SupplementCategory
  form: SupplementForm
  stock_unit: SupplementStockUnit
  preferred_display_unit: SupplementDisplayUnit
  usual_dose_quantity: string
  package_quantity: string | null
  restock_lead_days: number
  note: string | null
  is_archived: boolean
  archived_at: string | null
  stock: SupplementStock
  forecast: SupplementForecast
  restock_proposal: SupplementRestockProposal | null
  created_at: string
  updated_at: string
}

export interface SupplementInput {
  name: string
  category: SupplementCategory
  form: SupplementForm
  stock_unit: SupplementStockUnit
  preferred_display_unit: SupplementDisplayUnit
  usual_dose_quantity: string
  package_quantity: string | null
  restock_lead_days: number
  note: string | null
}

export interface SupplementListResponse {
  data: Supplement[]
  meta: {
    categories: SupplementCategory[]
    forms: SupplementForm[]
    canonical_units: SupplementStockUnit[]
    display_units: SupplementDisplayUnit[]
  }
}

export interface SupplementCourseSlot {
  id?: number
  slot: string
  time: string
  intake_context: SupplementIntakeContext
  sort_order?: number
}

export interface SupplementSchedule {
  frequency: 'daily' | 'weekly'
  interval_count: number
  weekdays: Weekday[]
  cycle: { on_days: number, off_days: number } | null
  slots: SupplementCourseSlot[]
}

export interface SupplementCourse {
  id: number
  supplement_id: number
  supplement_name: string
  stock_unit: SupplementStockUnit
  goal_id: number | null
  name: string | null
  dose_quantity: string
  dose_display_unit: SupplementDisplayUnit
  starts_on: string
  ends_on: string
  is_active: boolean
  is_archived: boolean
  archived_at: string | null
  schedule: SupplementSchedule & { timezone: string, materialized_until: string | null }
  created_at: string
  updated_at: string
}

export interface SupplementCourseInput {
  supplement_id: number
  goal_id: number | null
  name: string | null
  dose_quantity: string
  dose_display_unit: SupplementDisplayUnit
  starts_on: string
  ends_on: string
  is_active: boolean
  schedule: SupplementSchedule
}

export interface SupplementCourseListResponse {
  data: SupplementCourse[]
  meta: {
    frequencies: Array<'daily' | 'weekly'>
    weekdays: Weekday[]
    intake_contexts: SupplementIntakeContext[]
    max_slots: number
  }
}

export interface SupplementIntake {
  id: number
  supplement_course_id: number
  supplement_id: number
  planned_on: string
  effective_on: string
  slot: string
  outcome: 'taken' | 'skipped'
  dose_quantity: string
  dose_display_unit: SupplementDisplayUnit
  supplement_name: string
  taken_at: string | null
  taken_time: string | null
  note: string | null
  created_at: string
  updated_at: string
}

export interface SupplementOccurrence {
  id: number
  course_id: number
  course_name: string
  supplement_id: number
  supplement_name: string
  stock_unit: SupplementStockUnit
  occurrence_date: string
  rescheduled_to: string | null
  effective_date: string
  slot: string
  time: string
  intake_context: SupplementIntakeContext
  status: 'planned' | 'done' | 'skipped'
  dose_quantity: string
  dose_display_unit: SupplementDisplayUnit
  actions: Array<'take' | 'skip' | 'correct' | 'clear' | 'reschedule'>
  intake: SupplementIntake | null
}

export interface SupplementAdherenceSummary {
  done: number
  skipped: number
  overdue: number
  pending: number
  eligible: number
  adherence_percentage: number | null
}

export interface SupplementDay {
  date: string
  today: string
  occurrences: SupplementOccurrence[]
  summary: SupplementAdherenceSummary
}

export interface SupplementAdherenceRange {
  from: string
  to: string
  today: string
  summary: SupplementAdherenceSummary
  days: Array<SupplementAdherenceSummary & { date: string }>
}

export interface SupplementStockMovement {
  id: number
  supplement_id: number
  kind: 'restock' | 'correction'
  quantity_delta: string
  stock_unit: SupplementStockUnit
  effective_on: string
  reason: string | null
  note: string | null
  created_at: string
}

export interface SupplementStockMovementInput {
  kind: 'restock' | 'correction'
  quantity: string
  display_unit: SupplementDisplayUnit
  effective_on: string
  reason: string | null
  note: string | null
}

/* ------------------------------------------------------------------ */
/* Nutrition, meals, hydration, and targets (feature 016)             */
/* ------------------------------------------------------------------ */

export type FoodBasis = 'gram' | 'millilitre'
export type NutritionLifecycleState = 'active' | 'archived' | 'all'
export type MealCategory = 'breakfast' | 'lunch' | 'dinner' | 'snack' | 'custom'

export interface FoodItem {
  id: number
  system_key: string | null
  name: string
  basis_unit: FoodBasis
  is_beverage: boolean
  calories_per_100: string
  protein_per_100: string
  fat_per_100: string
  carbs_per_100: string
  quality_score: string | null
  hydration_ratio: string
  is_archived: boolean
  is_public: boolean
}

export interface FoodItemInput {
  name: string
  basis_unit: FoodBasis
  is_beverage: boolean
  calories_per_100: number
  protein_per_100: number
  fat_per_100: number
  carbs_per_100: number
  quality_score: number | null
  hydration_ratio: number
}

export interface RecipeComponent {
  id: number
  sort_order: number
  quantity_grams: string
  food: FoodItem
}

export interface RecipeNutrition {
  total_weight_grams: string
  calories: string
  protein_grams: string
  fat_grams: string
  carbs_grams: string
  quality_score: string | null
}

export interface Recipe {
  id: number
  name: string
  description: string | null
  is_archived: boolean
  components: RecipeComponent[]
  nutrition_per_100: RecipeNutrition
}

export interface RecipeInput {
  name: string
  description: string | null
  components: Array<{ food_item_id: number, quantity_grams: number }>
}

export interface NutritionSettings {
  body_goal_id: number | null
  protein_percent: string
  fat_percent: string
  carbs_percent: string
  water_override_ml: number | null
}

export interface NutritionSettingsInput {
  body_goal_id: number | null
  protein_percent: number
  fat_percent: number
  carbs_percent: number
  water_override_ml: number | null
}

export interface MealEntry {
  id: number
  food_item_id: number | null
  recipe_id: number | null
  sort_order: number
  reference_name: string
  basis_unit: FoodBasis
  quantity: string
  calories: string
  protein_grams: string
  fat_grams: string
  carbs_grams: string
  hydration_ml: string
  quality_numerator: string | null
  quality_denominator: string
}

export interface Attachment {
  id: number
  kind: 'photo'
  original_name: string
  mime_type: 'image/jpeg' | 'image/png' | 'image/webp'
  size_bytes: number
  width: number
  height: number
  created_at: string
  content_url: string
}

export type AttachmentParentType = 'body_measurement' | 'meal'

export interface AttachmentParent {
  type: AttachmentParentType
  id: number
}

export interface Meal {
  id: number
  consumed_on: string
  name: string
  category: MealCategory | null
  consumed_at_local: string | null
  note: string | null
  submission_key: string
  entries: MealEntry[]
  attachments: Attachment[]
}

export interface MealInput {
  consumed_on: string
  name: string
  category: MealCategory | null
  consumed_at_local: string | null
  note: string | null
  submission_key?: string
  entries: Array<{ food_item_id: number | null, recipe_id: number | null, quantity: number }>
}

export interface NutritionSettingsBasis extends NutritionSettings {}

export interface NutritionTargetBasis {
  missing_fields: string[]
  profile_updated_at: string | null
  profile_inputs: {
    weight_kg: string | null
    height_cm: string | null
    age_years: number | null
    sex: string | null
    body_fat_percent: string | null
  }
  activity_coefficient: string | null
  settings: NutritionSettingsBasis
  goal: {
    id: number | null
    start_weight_kg: string | null
    target_weight_kg: string | null
    deadline: string | null
    raw_adjustment_kcal: string | null
    applied_adjustment_kcal: number
    status_code: string
  }
  planned_occurrence_ids: number[]
  planned_energy_missing_count: number
  water_rule: {
    source: 'estimate' | 'override' | 'unavailable'
    base_ml: number | null
    planned_duration_seconds: number
    workout_addition_ml: number
    applied_ml: number | null
  }
  limitation_codes: string[]
}

export interface NutritionTarget {
  date: string
  status: 'ready' | 'incomplete'
  formula: BmrFormula
  bmr_kcal: string | null
  baseline_kcal: string | null
  goal_adjustment_kcal: number
  planned_workout_kcal: number
  calorie_target: number | null
  protein_target_grams: string | null
  fat_target_grams: string | null
  carbs_target_grams: string | null
  water_target_ml: number | null
  quality_target: string
  calculation_basis: NutritionTargetBasis
}

export interface NutritionRefinement {
  status: 'available' | 'incomplete_target' | 'no_completed_workouts' | 'missing_energy'
  reference_calorie_target: number | null
  planned_workout_kcal: number
  actual_workout_kcal: number
  refined_calorie_target: number | null
  missing_actual_energy_count: number
}

export interface NutritionProgressValue {
  consumed: string
  target: string | null
  percent: string | null
}

export interface NutritionSummary {
  date: string
  meal_count: number
  entry_count: number
  calories: string
  protein_grams: string
  fat_grams: string
  carbs_grams: string
  hydration_ml: string
  quality_score: string | null
  progress: {
    calories: NutritionProgressValue
    protein: NutritionProgressValue
    fat: NutritionProgressValue
    carbs: NutritionProgressValue
    hydration: NutritionProgressValue
    quality: { consumed: string | null, target: string, percent: string | null }
  }
}

export interface NutritionDay {
  date: string
  meals: Meal[]
  target: NutritionTarget
  refinement: NutritionRefinement
  summary: NutritionSummary
}

export interface NutritionSummaryRange {
  from: string
  to: string
  days: NutritionSummary[]
}

/* ------------------------------------------------------------------ */
/* Workouts and training goals (feature 015)                           */
/* ------------------------------------------------------------------ */

export type WorkoutType = 'strength' | 'cardio' | 'flexibility' | 'sport'
export type WorkoutIntensity = 'light' | 'moderate' | 'vigorous'
export type WorkoutState = 'active' | 'paused' | 'archived'
export type TrainingGoalKind = 'strength' | 'distance' | 'race' | 'consistency'

export interface Exercise {
  id: number
  system_key: string | null
  name: string
  display_key: string | null
  muscle_group: string
  equipment: string | null
  exercise_type: 'strength' | 'mobility'
  is_builtin: boolean
  is_archived: boolean
  archived_at: string | null
}

export interface ExerciseInput {
  name: string
  muscle_group: string
  equipment?: string | null
  exercise_type: Exercise['exercise_type']
}

export interface ExerciseCatalogueResponse {
  data: Exercise[]
  options: {
    muscle_groups: string[]
    equipment: string[]
    exercise_types: Exercise['exercise_type'][]
  }
}

export interface WorkoutProgression {
  next_weight_kg: string
  successful_sessions: number
  successes_required: number
  successes_remaining: number
}

export interface WorkoutProgramExercise {
  id: number
  exercise: Exercise
  sort_order: number
  target_sets: number
  target_reps: number
  starting_weight_kg: string
  increment_kg: string
  successes_required: number
  progression: WorkoutProgression
}

export interface WorkoutOccurrence {
  id: number
  occurrence_date: string
  effective_date: string
  time: string | null
  status: 'planned' | 'done' | 'skipped' | 'rescheduled'
  workout_session_id: number | null
}

export interface WorkoutProgram {
  id: number
  name: string
  description: string | null
  workout_type: WorkoutType
  intensity: WorkoutIntensity
  planned_duration_seconds: number | null
  planned_energy_kcal: number | null
  is_active: boolean
  is_archived: boolean
  archived_at: string | null
  recurring_rule: {
    id: number
    frequency: 'daily' | 'weekly'
    schedule_type: 'daily' | 'weekdays'
    starts_on: string | null
    ends_on: string | null
    timezone: string
    slot_time: string | null
    weekdays: Weekday[]
    last_materialized_until: string | null
  }
  exercises: WorkoutProgramExercise[]
  endurance: { activity: string, run_type: string | null, target_distance_m: number | null } | null
  timed: { activity_name: string | null } | null
  selected_date: string
  occurrence: WorkoutOccurrence | null
}

export interface WorkoutProgramsResponse {
  date: string
  today: string
  data: WorkoutProgram[]
  options: {
    workout_types: WorkoutType[]
    intensities: WorkoutIntensity[]
    activities: string[]
    run_types: string[]
    weekdays: Weekday[]
  }
}

export interface WorkoutProgramInput {
  name: string
  description?: string | null
  workout_type: WorkoutType
  intensity: WorkoutIntensity
  planned_duration_seconds?: number | null
  planned_energy_kcal?: number | null
  schedule_type: 'daily' | 'weekdays'
  weekdays?: Weekday[]
  preferred_time?: string | null
  starts_on?: string | null
  ends_on?: string | null
  endurance?: { activity: string, run_type?: string | null, target_distance_m?: number | null } | null
  timed?: { activity_name?: string | null } | null
}

export interface WorkoutSetInput {
  set_order: number
  weight_kg: number
  reps: number
  rest_seconds?: number | null
}

export interface WorkoutStrengthInput {
  mode: 'simple' | 'detailed'
  exercises: Array<{
    exercise_id: number
    sort_order: number
    simple_weight_kg?: number | null
    simple_reps?: number | null
    note?: string | null
    sets: WorkoutSetInput[]
  }>
}

export interface WorkoutSessionInput {
  name?: string
  workout_type?: WorkoutType
  performed_on?: string
  outcome?: 'completed' | 'skipped'
  started_time?: string | null
  duration_seconds?: number | null
  note?: string | null
  strength?: WorkoutStrengthInput | null
  endurance?: {
    activity: string
    run_type?: string | null
    distance_m?: number | null
    average_heart_rate?: number | null
    energy_kcal?: number | null
  } | null
  timed?: { activity_name?: string | null } | null
}

export interface WorkoutSession {
  id: number
  workout_program_id: number | null
  planned_occurrence_id: number | null
  name: string
  workout_type: WorkoutType
  outcome: 'completed' | 'skipped'
  performed_on: string
  started_at: string | null
  started_time: string | null
  duration_seconds: number | null
  note: string | null
  strength: {
    mode: 'simple' | 'detailed'
    exercises: Array<{
      id: number
      exercise: Exercise
      sort_order: number
      simple_weight_kg: string | null
      simple_reps: number | null
      note: string | null
      sets: Array<{ id: number, set_order: number, weight_kg: string, reps: number, rest_seconds: number | null }>
    }>
  } | null
  endurance: {
    activity: string
    run_type: string | null
    distance_m: number | null
    average_heart_rate: number | null
    energy_kcal: number | null
    pace_seconds_per_km: number | null
  } | null
  timed: { activity_name: string | null } | null
  totals: { duration_seconds: number, distance_m: number, strength_volume_kg: string }
}

export interface WorkoutSummary {
  planned: number
  completed: number
  skipped: number
  unplanned: number
  duration_seconds: number
  distance_m: number
  strength_volume_kg: string
}

export interface WorkoutHistoryResponse {
  from: string
  to: string
  today: string
  data: WorkoutSession[]
  summary: WorkoutSummary
  records: {
    exercises: Array<{ exercise: Exercise, max_weight_kg: string | null, max_volume_kg: string | null, recorded_on: string | null }>
    paces: Array<{ activity: string, best_pace_seconds_per_km: number | null, recorded_on: string | null }>
  }
}

export interface TrainingGoal {
  id: number
  name: string
  description: string | null
  type: 'training'
  status: 'active' | 'completed' | 'abandoned'
  target_date: string | null
  completed_at: string | null
  is_archived: boolean
  archived_at: string | null
  training: {
    kind: TrainingGoalKind
    unit: 'kg' | 'm' | 'sessions_per_week'
    exercise: Exercise | null
    activity: string | null
    workout_program_id: number | null
    starting_value: string
    target_value: string
    current_value: string | null
    current_on: string | null
    progress: number | null
  }
}

export interface TrainingGoalsResponse {
  data: TrainingGoal[]
  kinds: TrainingGoalKind[]
}

export interface TrainingGoalInput {
  name: string
  description?: string | null
  target_date?: string | null
  kind: TrainingGoalKind
  exercise_id?: number | null
  activity?: string | null
  workout_program_id?: number | null
  target_value: number
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

export type PeriodicReviewPayload = AtLeastOne<PeriodicReviewFields>

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
  attachments: Attachment[]
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

export type ItemType = 'task' | 'idea' | 'purchase'
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
  estimated_amount: string | null
  estimated_currency_code: FinanceCurrencyCode | null
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
  estimated_amount?: string | null
  estimated_currency_code?: FinanceCurrencyCode | null
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
/* Optional BYOK AI assistant (feature 026)                            */
/* ------------------------------------------------------------------ */

export type AiProvider = 'anthropic' | 'openai'
export type AiConnectionStatus = 'untested' | 'ready' | 'invalid'
export type AiConsentScope = 'storage_inbox'

export type AiErrorCode =
  | 'ai_active_connection_required'
  | 'ai_connection_not_ready'
  | 'ai_consent_required'
  | 'ai_credentials_invalid'
  | 'ai_provider_rate_limited'
  | 'ai_provider_timeout'
  | 'ai_provider_unavailable'
  | 'ai_provider_unsupported_capability'
  | 'ai_provider_refused'
  | 'ai_provider_invalid_response'
  | 'ai_tool_not_allowed'
  | 'ai_tool_confirmation_required'
  | 'ai_confirmation_expired'
  | 'ai_confirmation_replayed'
  | 'ai_confirmation_stale'

export interface AiParameters {
  max_output_tokens: number
}

export interface AiConnection {
  id: number
  name: string
  provider: AiProvider
  model: string
  key_mask: string
  parameters: AiParameters
  status: AiConnectionStatus
  last_tested_at: string | null
  last_used_at: string | null
  last_error_code: AiErrorCode | null
  created_at: string
  updated_at: string
}

export interface AiConnectionInput {
  name: string
  provider: AiProvider
  model: string
  api_key: string
  parameters: AiParameters
}

export type AiConnectionUpdate = Partial<AiConnectionInput>

export interface AiConsent {
  scope: AiConsentScope
  granted: boolean
  granted_at: string | null
  revoked_at: string | null
}

export interface AiSettings {
  data: AiConnection[]
  active_connection_id: number | null
  consents: { storage_inbox: AiConsent }
  providers: AiProvider[]
}

export interface InboxTriageProposal {
  type: ItemType
  project_id: number | null
  tags: string[]
  priority: ItemPriority | null
  due_on: string | null
  rationale: string
}

export interface InboxTriageDraft {
  item_id: number
  proposal: InboxTriageProposal
  provider: AiProvider
  model: string
  confirmation_token: string
  expires_at: string
  shared_scope: AiConsentScope
}

export interface AiErrorResponse {
  message: string
  code: AiErrorCode
}

/* ------------------------------------------------------------------ */
/* Planner                                                            */
/* ------------------------------------------------------------------ */

/** Which module a day entry came from. Planner owns only `time_block`. */
export type PlannerSource = 'routine' | 'sleep' | 'habit' | 'workout' | 'supplement' | 'finance' | 'training_goal' | 'storage' | 'time_block' | 'external_calendar'

/** What the user may do with an entry from inside the planner. */
export type PlannerAction = 'actualize' | 'skip' | 'reschedule' | 'move' | 'edit' | 'delete'

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

export type NotificationType = 'routine_reminder' | 'habit_reminder' | 'sleep_reminder' | 'workout_reminder' | 'storage_due' | 'daily_digest' | 'supplement_intake' | 'supplement_restock' | 'finance_reminder' | 'finance_budget_approaching' | 'finance_budget_exceeded'
export type NotificationCategory = 'routine' | 'habit' | 'sleep' | 'workout' | 'storage' | 'digest' | 'supplement' | 'finance'
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
    workout: boolean
    supplement: boolean
    finance: boolean
  }
}

export interface NotificationSettingsResponse {
  data: NotificationSettingsData
  options: {
    categories: Array<'routine' | 'storage' | 'habit' | 'sleep' | 'workout' | 'supplement' | 'finance'>
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

/* ------------------------------------------------------------------ */
/* Finance ledger foundation                                          */
/* ------------------------------------------------------------------ */

export type FinanceCurrencyCode = 'UAH' | 'USD' | 'EUR'
export type FinanceAccountType = 'cash' | 'card' | 'savings' | 'currency'
export type FinanceCategoryDirection = 'income' | 'expense'
export type FinanceTransactionKind = 'income' | 'expense' | 'transfer' | 'adjustment'

export interface FinanceCurrency { code: FinanceCurrencyCode, decimal_places: number, active: boolean }
export interface FinanceAccount { id: number, name: string, type: FinanceAccountType, currency: FinanceCurrencyCode, balance: string, reserved_amount: string, available_balance: string, over_reserved: boolean, archived: boolean, created_at: string, updated_at: string }
export interface FinanceCategory { id: number, direction: FinanceCategoryDirection, parent_id: number | null, builtin_key: string | null, name: string | null, label: string, archived: boolean, used: boolean, created_at: string, updated_at: string }
export interface FinanceExchangeRate { id: number, from_currency: FinanceCurrencyCode, to_currency: FinanceCurrencyCode, rate_date: string, rate: string, source: 'manual', created_at: string, updated_at: string }
export interface FinanceLedgerEntry { id: number, account_id: number, account_name: string, category_id: number | null, category_label: string | null, role: 'primary' | 'source' | 'destination', delta_amount: string, currency: FinanceCurrencyCode }
export type FinanceSourceType = 'purchase_item' | 'supplement_restock_proposal'
export interface FinanceSourceContext { type: FinanceSourceType, id: number, label: string, action_url: string, active: boolean }
export interface FinanceTransactionGroup { id: string, kind: FinanceTransactionKind, occurred_on: string, note: string | null, tag: string | null, source?: FinanceSourceContext | null, reverses_id: string | null, reversed_by_id: string | null, reversal_reason: string | null, transfer: { from_currency: FinanceCurrencyCode, to_currency: FinanceCurrencyCode, effective_rate: string } | null, entries: FinanceLedgerEntry[], created_at: string }
export interface FinanceConversion { currency: FinanceCurrencyCode, amount: string, converted_amount: string, rate: string, rate_date: string, rate_direction: 'identity' | 'direct' | 'inverse' }
export interface FinanceSummary { accounts: FinanceAccount[], consolidated: { as_of: string, base_currency: FinanceCurrencyCode, complete: boolean, total: string | null, missing_currencies: FinanceCurrencyCode[], conversions: FinanceConversion[] }, actuals: { from: string, to: string, base_currency: FinanceCurrencyCode, complete: boolean, income: string | null, expense: string | null, net: string | null, missing_currencies: FinanceCurrencyCode[] } }

export interface FinanceAccountInput { name: string, type: FinanceAccountType, currency: FinanceCurrencyCode, opening_balance?: string, opening_date?: string, opening_note?: string | null }
export interface FinanceAccountUpdate { name?: string, type?: FinanceAccountType, currency?: FinanceCurrencyCode, archived?: boolean }
export interface FinanceReconcileInput { idempotency_key: string, observed_balance: string, occurred_on: string, reason: string }
export interface FinanceCategoryInput { direction: FinanceCategoryDirection, parent_id?: number | null, name: string }
export interface FinanceCategoryUpdate { name?: string, parent_id?: number | null, archived?: boolean }
export interface FinanceExchangeRateInput { from_currency: FinanceCurrencyCode, to_currency: FinanceCurrencyCode, rate_date: string, rate: string }
export interface FinanceTransactionInput { idempotency_key: string, kind: 'income' | 'expense', account_id: number, category_id: number, amount: string, occurred_on: string, note?: string | null, tag?: string | null }
export interface FinanceTransferInput { idempotency_key: string, source_account_id: number, destination_account_id: number, source_amount: string, destination_amount: string, occurred_on: string, note?: string | null, tag?: string | null }
export interface FinanceReversalInput { idempotency_key: string, reason: string }

/* Budget and recurring cash flow (feature 019) */
export type FinanceBudgetState = 'within' | 'approaching' | 'exceeded'
export type FinanceOccurrenceStatus = 'planned' | 'actual' | 'skipped' | 'overdue' | 'unavailable'
export interface FinanceCategorySummary { id: number, parent_id: number | null, label: string, archived: boolean }
export interface FinanceAccountSummary { id: number, name: string, archived: boolean }
export interface FinancePlanningConversion { on: string, from_currency: FinanceCurrencyCode, source_amount: string, converted_amount: string, rate: string, rate_date: string, rate_direction: 'identity' | 'direct' | 'inverse' }
export interface FinanceBudget { id: number, month: string, category: FinanceCategorySummary, limit_amount: string, currency: FinanceCurrencyCode, complete: boolean, actual_amount: string | null, remaining_amount: string | null, utilization_percent: string | null, state: FinanceBudgetState | null, missing_currencies: FinanceCurrencyCode[], conversions: FinancePlanningConversion[], created_at: string, updated_at: string }
export interface FinanceBudgetInput { month: string, category_id: number, limit_amount: string, currency: FinanceCurrencyCode }
export interface FinanceBudgetUpdate { month?: string, category_id?: number, limit_amount?: string, currency?: FinanceCurrencyCode }
export interface FinanceMonthlyRule { frequency: 'monthly', interval_months: number, month_days: number[], starts_on: string, ends_on: string | null, reminder_time: string | null }
export interface FinanceRecurringOperation { id: number, name: string, direction: FinanceCategoryDirection, account: FinanceAccountSummary, category: FinanceCategorySummary, amount: string, currency: FinanceCurrencyCode, mandatory: boolean, active: boolean, archived: boolean, rule: FinanceMonthlyRule, created_at: string, updated_at: string }
export interface FinanceRecurringOperationInput { name: string, direction: FinanceCategoryDirection, account_id: number, category_id: number, amount: string, mandatory: boolean, starts_on: string, ends_on: string | null, interval_months: number, month_days: number[], reminder_time: string | null }
export interface FinanceRecurringOperationUpdate { name?: string, direction?: FinanceCategoryDirection, account_id?: number, category_id?: number, amount?: string, mandatory?: boolean, starts_on?: string, ends_on?: string | null, interval_months?: number, month_days?: number[], reminder_time?: string | null, active?: boolean, archived?: boolean }
export interface FinanceOccurrenceOutcome { type: 'actual' | 'skipped', transaction_id: string | null, occurred_on: string | null, created_at: string }
export interface FinanceOccurrenceContext { kind: 'recurring_operation' | 'debt' | 'fund', owner_id: number, name: string, direction: FinanceCategoryDirection | 'allocation', amount: string | null, currency: FinanceCurrencyCode, mandatory: boolean, evidence: string | null }
export interface FinancePlannedOccurrence { id: number, original_date: string, date: string, time: string | null, status: FinanceOccurrenceStatus, outcome_type: 'actual' | 'skipped' | null, outcome: FinanceOccurrenceOutcome | null, transaction_public_id: string | null, context: FinanceOccurrenceContext, action_url: string, operation_id?: number, operation_name?: string, planned_on?: string, effective_on?: string, moved?: boolean, reminder_time?: string | null, direction?: FinanceCategoryDirection, account?: FinanceAccountSummary, category?: FinanceCategorySummary, amount?: string, currency?: FinanceCurrencyCode, mandatory?: boolean }
export interface FinanceCashFlowCounts { total: number, planned: number, actual: number, skipped: number, income: number, mandatory_expense: number, discretionary_expense: number, recurring_operation: number, debt: number, emergency_fund: number }
export interface FinanceCashFlow { month: string, from: string, to: string, base_currency: FinanceCurrencyCode, complete: boolean, planned_income: string | null, mandatory_expense: string | null, discretionary_expense: string | null, free_cash_flow: string | null, missing_currencies: FinanceCurrencyCode[], conversions: FinancePlanningConversion[], counts: FinanceCashFlowCounts }

/* Debts, saving funds, Finance goals, and source links (feature 020) */
export type FinanceCounterpartyKind = 'person' | 'bank' | 'store' | 'other'
export interface FinanceCounterparty { id: number, name: string, kind: FinanceCounterpartyKind, note: string | null, archived: boolean, created_at: string | null, updated_at: string | null }
export interface FinanceCounterpartyInput { name: string, kind: FinanceCounterpartyKind, note: string | null }
export interface FinanceCounterpartyUpdate { name?: string, kind?: FinanceCounterpartyKind, note?: string | null, archived?: boolean }
export type FinanceDebtDirection = 'owe' | 'owed_to_me'
export type FinanceDebtRepaymentMode = 'fixed' | 'flexible'
export interface FinanceDebtSchedule { installment_amount: string, installment_count: number, interval_months: number, monthday: number, first_due_on: string, reminder_time: string | null }
export interface FinanceDebtPayment { id: number, planned_occurrence_id: number | null, transaction_public_id: string, principal_amount: string, currency: FinanceCurrencyCode, occurred_on: string, reversed: boolean }
export interface FinanceDebtOccurrence { id: number, due_on: string, original_due_on: string, amount: string, currency: FinanceCurrencyCode, status: 'scheduled' | 'paid' | 'overdue', reminder_time: string | null, latest_payment: FinanceDebtPayment | null }
export interface FinanceDebt { id: number, name: string, counterparty: FinanceCounterparty, direction: FinanceDebtDirection, repayment_mode: FinanceDebtRepaymentMode, original_amount: string, paid_amount: string, remaining_amount: string, currency: FinanceCurrencyCode, progress: number, originated_on: string, deadline: string | null, state: 'active' | 'overdue' | 'settled', account_id: number | null, category_id: number | null, purchase_item_id: number | null, active: boolean, archived: boolean, schedule: FinanceDebtSchedule | null, occurrences: FinanceDebtOccurrence[], payments: FinanceDebtPayment[], counts: { scheduled: number, paid: number, overdue: number }, created_at: string | null, updated_at: string | null }
export interface FinanceDebtInput { name: string, counterparty_id: number, direction: FinanceDebtDirection, repayment_mode: FinanceDebtRepaymentMode, original_amount: string, currency: FinanceCurrencyCode, originated_on: string, deadline: string | null, account_id: number | null, category_id: number | null, purchase_item_id: number | null, schedule: FinanceDebtSchedule | null, note: string | null }
export interface FinanceDebtUpdate { name?: string, counterparty_id?: number, deadline?: string | null, account_id?: number | null, category_id?: number | null, schedule?: FinanceDebtSchedule, note?: string | null, active?: boolean, archived?: boolean }
export interface FinanceDebtPaymentInput { planned_occurrence_id: number | null, amount: string, account_id: number, category_id: number, occurred_on: string, idempotency_key: string, note: string | null }
export type FinanceFundType = 'regular' | 'emergency'
export type FinanceFundStorageMode = 'virtual' | 'linked_account'
export type FinanceFundTopUpMode = 'none' | 'fixed' | 'income_percent' | 'expense_months'
export interface FinanceFundRule { top_up_mode: FinanceFundTopUpMode, fixed_amount: string | null, income_percent: number | null, expense_months: number | null, build_months: number | null, starts_on: string | null, monthday: number | null, reminder_time: string | null }
export interface FinanceFundProjection { month: string, complete: boolean, saved_amount: string, target_amount: string | null, remaining_amount: string | null, progress: number | null, suggested_top_up: string | null, required_monthly_pace: string | null, state: 'active' | 'reached' | 'under_funded' | 'over_reserved' | 'spent' | 'unavailable', missing_currencies: FinanceCurrencyCode[], missing_history: boolean, calculation_basis: string | null, conversions: FinancePlanningConversion[] }
export interface FinanceFundMovement { id: number, action: 'top_up' | 'draw_down' | 'reverse', amount: string, currency: FinanceCurrencyCode, occurred_on: string, transaction_public_id: string | null, reversed: boolean }
export interface FinanceSavingFund { id: number, name: string, fund_type: FinanceFundType, storage_mode: FinanceFundStorageMode, account_id: number, funding_account_id: number | null, category_id: number | null, currency: FinanceCurrencyCode, target_mode: 'explicit' | 'expense_months', deadline: string | null, rule: FinanceFundRule, active: boolean, archived: boolean, spent: boolean, projection: FinanceFundProjection, movements: FinanceFundMovement[], created_at: string | null, updated_at: string | null }
export interface FinanceSavingFundInput { name: string, fund_type: FinanceFundType, storage_mode: FinanceFundStorageMode, account_id: number, funding_account_id: number | null, category_id: number | null, currency: FinanceCurrencyCode, target_mode: 'explicit' | 'expense_months', target_amount: string | null, deadline: string | null, rule: FinanceFundRule, note: string | null }
export interface FinanceSavingFundUpdate { name?: string, funding_account_id?: number | null, category_id?: number | null, target_amount?: string | null, deadline?: string | null, rule?: FinanceFundRule, note?: string | null, active?: boolean, archived?: boolean, spent?: boolean }
export type FinanceFundMovementInput = { action: 'top_up' | 'draw_down', amount: string, counterparty_account_id: number | null, occurred_on: string, idempotency_key: string, note: string | null } | { action: 'reverse', reverses_movement_id: number, idempotency_key: string, note: string | null }
export interface FinanceGoalMilestone { id: number, target_value: string, target_date: string | null, achieved: boolean }
export interface FinanceGoal { id: number, name: string, description: string | null, type: 'finance', kind: 'save' | 'pay_off', target_date: string | null, status: 'active' | 'completed' | 'abandoned', archived: boolean, currency: FinanceCurrencyCode, aggregate_id: number, starting_value: string, target_value: string, current_value: string, remaining_value: string, progress: number, milestones: FinanceGoalMilestone[], created_at: string | null, updated_at: string | null }
export interface FinanceGoalInput { name: string, description: string | null, target_date: string | null, kind: 'save' | 'pay_off', saving_fund_id: number | null, debt_id: number | null, milestones: Array<{ target_value: string, target_date: string | null }> }
export interface FinanceGoalUpdate { name?: string, description?: string | null, target_date?: string | null, status?: FinanceGoal['status'], archived?: boolean, milestones?: Array<{ target_value: string, target_date: string | null }> }
export interface FinanceSourceExpenseInput { source_type: FinanceSourceType, source_id: number, account_id: number, category_id: number, amount: string, occurred_on: string, idempotency_key: string, note: string | null }
export interface FinanceSourceExpenseResponse { transaction_public_id: string, source: FinanceSourceContext, reversed: boolean }

/* Cross-module long-period analytics (feature 023) */
export type AnalyticsMetricKey =
  | 'routines.completion_rate'
  | 'sleep.duration_minutes'
  | 'sleep.quality'
  | 'workouts.completed_sessions'
  | 'workouts.duration_minutes'
  | 'nutrition.calorie_target_adherence'
  | 'supplements.adherence'
  | 'habits.completion_rate'
  | 'planner.completion_rate'
  | 'finance.income'
  | 'finance.expense'
  | 'finance.net'
  | 'review.energy'
  | 'review.mood'
  | 'review.stress'
  | 'review.day_rating'
  | 'body.body_mass'

export type AnalyticsGranularity = 'daily' | 'weekly' | 'monthly'
export type AnalyticsMetricUnit = 'percent' | 'minutes' | 'count' | 'currency' | 'rating_5' | 'rating_10' | 'kilograms'
export type AnalyticsPointState = 'ready' | 'empty' | 'incomplete'
export type AnalyticsCorrelationKey = 'sleep_energy' | 'sleep_quality_mood' | 'habit_completion_day_rating'

export interface AnalyticsMetricDefinition {
  key: AnalyticsMetricKey
  module: 'routines' | 'sleep' | 'workouts' | 'nutrition' | 'supplements' | 'habits' | 'planner' | 'finance' | 'review' | 'body'
  unit: AnalyticsMetricUnit
  operator: 'sum' | 'mean' | 'percentage' | 'last'
  precision: 0 | 2 | 4
  empty_is_zero: boolean
  sensitivity: 'standard' | 'well_being' | 'health' | 'finance'
}

export interface AnalyticsCorrelationDefinition {
  key: AnalyticsCorrelationKey
  left_metric: AnalyticsMetricKey
  right_metric: AnalyticsMetricKey
  minimum_samples: 7
}

export interface AnalyticsLimits {
  daily_days: 93
  weekly_days: 730
  monthly_days: 3653
  correlation_days: 366
}

export interface AnalyticsCatalog {
  metrics: AnalyticsMetricDefinition[]
  correlations: AnalyticsCorrelationDefinition[]
  limits: AnalyticsLimits
}

export interface AnalyticsPoint {
  bucket_start: string
  bucket_end: string
  state: AnalyticsPointState
  value: string | null
  sample_count: number
  numerator: string | null
  denominator: string | null
  reasons: string[]
}

export interface AnalyticsTrend {
  state: 'empty' | 'insufficient' | 'ready'
  available_points: number
  total_buckets: number
  first: string | null
  last: string | null
  delta: string | null
  slope_per_bucket: string | null
}

export interface AnalyticsPeriodAggregate {
  from: string
  to: string
  state: AnalyticsPointState
  value: string | null
  sample_count: number
  numerator: string | null
  denominator: string | null
  reasons: string[]
}

export interface AnalyticsComparison {
  current: AnalyticsPeriodAggregate
  previous: AnalyticsPeriodAggregate
  absolute_delta: string | null
  percentage_delta: string | null
  percentage_delta_reason: 'available' | 'missing_value' | 'previous_zero'
}

export interface AnalyticsWorkspace {
  period: { from: string, to: string, granularity: AnalyticsGranularity, timezone: string }
  metric: AnalyticsMetricDefinition
  currency: FinanceCurrencyCode | null
  points: AnalyticsPoint[]
  trend: AnalyticsTrend
  comparison: AnalyticsComparison | null
}

export interface AnalyticsCorrelationFinding {
  key: AnalyticsCorrelationKey
  left_metric: AnalyticsMetricKey
  right_metric: AnalyticsMetricKey
  from: string
  to: string
  state: 'ready' | 'unavailable'
  coefficient: string | null
  direction: 'positive' | 'negative' | 'none' | null
  strength: 'none' | 'weak' | 'moderate' | 'strong' | null
  sample_count: number
  minimum_samples: 7
  reason: 'insufficient_samples' | 'zero_variance' | null
}

export interface AnalyticsCorrelationWorkspace {
  period: { from: string, to: string, timezone: string }
  findings: AnalyticsCorrelationFinding[]
}

/* ------------------------------------------------------------------ */
/* External calendar integrations (feature 025)                       */
/* ------------------------------------------------------------------ */

export type CalendarProvider = 'google_calendar' | 'apple_calendar'
export type CalendarIntegrationStatus = 'pending' | 'active' | 'expired' | 'revoked'
export type CalendarImportDetail = 'busy_only' | 'title'
export type CalendarExportCategory = 'time_block' | 'routine' | 'sleep' | 'habit' | 'workout' | 'supplement' | 'finance'
export type CalendarErrorCode =
  | 'calendar_provider_unavailable'
  | 'calendar_oauth_invalid_state'
  | 'calendar_oauth_denied'
  | 'calendar_credentials_invalid'
  | 'calendar_discovery_failed'
  | 'calendar_not_found'
  | 'calendar_read_only'
  | 'calendar_connection_inactive'
  | 'calendar_sync_busy'
  | 'calendar_auth_expired'
  | 'calendar_rate_limited'
  | 'calendar_provider_timeout'
  | 'calendar_provider_invalid_response'
  | 'calendar_sync_failed'

export interface CalendarSettings {
  import_detail: CalendarImportDetail
  export_categories: CalendarExportCategory[]
}

export type CalendarSettingsInput = Partial<CalendarSettings>

export interface CalendarIntegration {
  id: number
  provider: CalendarProvider
  status: CalendarIntegrationStatus
  account: string | null
  calendar: { name: string, timezone: string | null, writable: boolean } | null
  settings: CalendarSettings
  last_sync_at: string | null
  last_success_at: string | null
  last_error_code: CalendarErrorCode | null
}

export interface CalendarProviderAvailability {
  provider: CalendarProvider
  available: boolean
  connection_mode: 'oauth_browser' | 'app_specific_password'
  android_connect_supported: boolean
  unavailable_code: CalendarErrorCode | null
}

export interface CalendarIntegrationCollection {
  data: CalendarIntegration[]
  providers: CalendarProviderAvailability[]
}

export interface CalendarDescriptor {
  id: string
  name: string
  timezone: string | null
  writable: boolean
  is_default: boolean
}

export interface CalendarConnectResponse {
  data: CalendarIntegration
  calendars: CalendarDescriptor[]
}

export interface CalendarSyncResult {
  imported: number
  updated: number
  removed: number
  exported: number
  deleted: number
  conflicts: number
  unchanged: number
  completed_at: string
}

/* Human-readable reports and schema-v1 portability (feature 024) */
export interface PortabilityCounts {
  records_by_table: Record<string, number>
  total_records: number
  attachments: number
  total_bytes: number
}

export interface PortabilityValidation {
  valid: boolean
  eligible: boolean
  schema_version: number | null
  archive_sha256: string
  backup_id: string | null
  created_at: string | null
  counts: PortabilityCounts | null
  exclusions: string[]
  issues: string[]
  restore_token: string | null
  expires_at: string | null
}

export interface PortabilityRestoreResult {
  archive_sha256: string
  records_by_table: Record<string, number>
  total_records: number
  attachments: number
}
