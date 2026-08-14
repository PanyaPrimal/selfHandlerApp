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
  DailyReviewWorkspace,
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
  PeriodicReview,
  PeriodicReviewPayload,
  PeriodicReviewType,
  PeriodicReviewWorkspace,
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
  FoodItem,
  FoodItemInput,
  Meal,
  MealInput,
  NutritionDay,
  NutritionLifecycleState,
  NutritionSettings,
  NutritionSettingsInput,
  NutritionSummaryRange,
  Recipe,
  RecipeInput,
  Supplement,
  SupplementAdherenceRange,
  SupplementCourse,
  SupplementCourseInput,
  SupplementCourseListResponse,
  SupplementDay,
  SupplementInput,
  SupplementLifecycleState,
  SupplementListResponse,
  SupplementOccurrence,
  SupplementRestockProposal,
  SupplementStockMovement,
  SupplementStockMovementInput,
  AnalyticsCatalog,
  AnalyticsCorrelationWorkspace,
  AnalyticsGranularity,
  AnalyticsMetricKey,
  AnalyticsWorkspace,
  PortabilityRestoreResult,
  PortabilityValidation,
} from './types'
import { downloadRequest, jsonRequest, multipartRequest, request } from './http'
import type { DownloadedFile } from '../portability/files'
import type {
  FinanceAccount,
  FinanceAccountInput,
  FinanceAccountUpdate,
  FinanceCategory,
  FinanceCategoryDirection,
  FinanceCategoryInput,
  FinanceCategoryUpdate,
  FinanceCurrency,
  FinanceCurrencyCode,
  FinanceExchangeRate,
  FinanceExchangeRateInput,
  FinanceReconcileInput,
  FinanceReversalInput,
  FinanceSummary,
  FinanceTransactionGroup,
  FinanceTransactionInput,
  FinanceTransferInput,
  FinanceBudget,
  FinanceBudgetInput,
  FinanceBudgetUpdate,
  FinanceCashFlow,
  FinancePlannedOccurrence,
  FinanceRecurringOperation,
  FinanceRecurringOperationInput,
  FinanceRecurringOperationUpdate,
  FinanceCounterparty,
  FinanceCounterpartyInput,
  FinanceCounterpartyUpdate,
  FinanceDebt,
  FinanceDebtInput,
  FinanceDebtUpdate,
  FinanceDebtPayment,
  FinanceDebtPaymentInput,
  FinanceSavingFund,
  FinanceSavingFundInput,
  FinanceSavingFundUpdate,
  FinanceFundMovement,
  FinanceFundMovementInput,
  FinanceGoal,
  FinanceGoalInput,
  FinanceGoalUpdate,
  FinanceSourceExpenseInput,
  FinanceSourceExpenseResponse,
} from './types'

// The SelfHandler error contract: a message for the user plus the per-field
// validation errors of a 422 response.
export { ApiError, validationErrors } from './http'
export type { ValidationErrors } from './http'
export type { DownloadedFile } from '../portability/files'

export interface AnalyticsReportQuery {
  metric: AnalyticsMetricKey
  from: string
  to: string
  granularity: AnalyticsGranularity
  compare: boolean
}

export async function getAnalyticsCatalog(): Promise<AnalyticsCatalog> {
  return (await request<ItemResponse<AnalyticsCatalog>>('/analytics/catalog')).data
}

export async function getAnalyticsWorkspace(params: AnalyticsReportQuery): Promise<AnalyticsWorkspace> {
  const query = new URLSearchParams({
    metric: params.metric,
    from: params.from,
    to: params.to,
    granularity: params.granularity,
    compare: params.compare ? '1' : '0',
  })

  return (await request<ItemResponse<AnalyticsWorkspace>>(`/analytics/workspace?${query.toString()}`)).data
}

function analyticsReportQuery(params: AnalyticsReportQuery): string {
  return new URLSearchParams({
    metric: params.metric,
    from: params.from,
    to: params.to,
    granularity: params.granularity,
    compare: params.compare ? '1' : '0',
  }).toString()
}

export function downloadAnalyticsReport(
  format: 'csv' | 'pdf',
  params: AnalyticsReportQuery,
): Promise<DownloadedFile> {
  return downloadRequest(
    `/reports/analytics.${format}?${analyticsReportQuery(params)}`,
    `selfhandler-analytics.${format}`,
    format === 'csv' ? 'text/csv' : 'application/pdf',
  )
}

export function downloadPortableBackup(): Promise<DownloadedFile> {
  return downloadRequest('/portability/backup', 'selfhandler-backup.zip', 'application/zip')
}

export async function validatePortableBackup(backup: File): Promise<PortabilityValidation> {
  const form = new FormData()
  form.append('backup', backup, backup.name)
  return (await multipartRequest<ItemResponse<PortabilityValidation>>(
    '/portability/restore/validate', form,
  )).data
}

export async function restorePortableBackup(
  backup: File,
  restoreToken: string,
  confirmation: 'RESTORE',
): Promise<PortabilityRestoreResult> {
  const form = new FormData()
  form.append('backup', backup, backup.name)
  form.append('restore_token', restoreToken)
  form.append('confirmation', confirmation)
  return (await multipartRequest<ItemResponse<PortabilityRestoreResult>>(
    '/portability/restore', form,
  )).data
}

export async function getAnalyticsCorrelations(params: { from: string, to: string }): Promise<AnalyticsCorrelationWorkspace> {
  const query = new URLSearchParams(params)

  return (await request<ItemResponse<AnalyticsCorrelationWorkspace>>(`/analytics/correlations?${query.toString()}`)).data
}

export async function getFinanceCurrencies(): Promise<FinanceCurrency[]> {
  return (await request<ListResponse<FinanceCurrency>>('/finance/currencies')).data
}

export async function getFinanceExchangeRates(from?: FinanceCurrencyCode, to?: FinanceCurrencyCode, dateFrom?: string, dateTo?: string): Promise<FinanceExchangeRate[]> {
  const query = new URLSearchParams()
  if (from) query.set('from_currency', from)
  if (to) query.set('to_currency', to)
  if (dateFrom) query.set('from', dateFrom)
  if (dateTo) query.set('to', dateTo)
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return (await request<ListResponse<FinanceExchangeRate>>(`/finance/exchange-rates${suffix}`)).data
}

export async function upsertFinanceExchangeRate(payload: FinanceExchangeRateInput): Promise<FinanceExchangeRate> {
  return (await jsonRequest<ItemResponse<FinanceExchangeRate>>('/finance/exchange-rates', 'PUT', payload)).data
}

export async function getFinanceAccounts(includeArchived = true): Promise<FinanceAccount[]> {
  return (await request<ListResponse<FinanceAccount>>(`/finance/accounts?include_archived=${includeArchived ? '1' : '0'}`)).data
}

export async function createFinanceAccount(payload: FinanceAccountInput): Promise<FinanceAccount> {
  return (await jsonRequest<ItemResponse<FinanceAccount>>('/finance/accounts', 'POST', payload)).data
}

export async function updateFinanceAccount(id: number, payload: FinanceAccountUpdate): Promise<FinanceAccount> {
  return (await jsonRequest<ItemResponse<FinanceAccount>>(`/finance/accounts/${id}`, 'PATCH', payload)).data
}

export function reconcileFinanceAccount(id: number, payload: FinanceReconcileInput): Promise<{ data: FinanceAccount, transaction: FinanceTransactionGroup | null }> {
  return jsonRequest(`/finance/accounts/${id}/reconcile`, 'POST', payload)
}

export async function getFinanceCategories(direction?: FinanceCategoryDirection, includeArchived = true): Promise<FinanceCategory[]> {
  const query = new URLSearchParams()
  if (direction) query.set('direction', direction)
  query.set('include_archived', includeArchived ? '1' : '0')
  return (await request<ListResponse<FinanceCategory>>(`/finance/categories?${query.toString()}`)).data
}

export async function createFinanceCategory(payload: FinanceCategoryInput): Promise<FinanceCategory> {
  return (await jsonRequest<ItemResponse<FinanceCategory>>('/finance/categories', 'POST', payload)).data
}

export async function updateFinanceCategory(id: number, payload: FinanceCategoryUpdate): Promise<FinanceCategory> {
  return (await jsonRequest<ItemResponse<FinanceCategory>>(`/finance/categories/${id}`, 'PATCH', payload)).data
}

export async function getFinanceTransactions(from?: string, to?: string, accountId?: number): Promise<FinanceTransactionGroup[]> {
  const query = new URLSearchParams()
  if (from) query.set('from', from)
  if (to) query.set('to', to)
  if (accountId) query.set('account_id', String(accountId))
  const suffix = query.toString() ? `?${query.toString()}` : ''
  return (await request<ListResponse<FinanceTransactionGroup>>(`/finance/transactions${suffix}`)).data
}

export async function createFinanceTransaction(payload: FinanceTransactionInput): Promise<FinanceTransactionGroup> {
  return (await jsonRequest<ItemResponse<FinanceTransactionGroup>>('/finance/transactions', 'POST', payload)).data
}

export async function createFinanceTransfer(payload: FinanceTransferInput): Promise<FinanceTransactionGroup> {
  return (await jsonRequest<ItemResponse<FinanceTransactionGroup>>('/finance/transfers', 'POST', payload)).data
}

export async function reverseFinanceTransaction(id: string, payload: FinanceReversalInput): Promise<FinanceTransactionGroup> {
  return (await jsonRequest<ItemResponse<FinanceTransactionGroup>>(`/finance/transactions/${encodeURIComponent(id)}/reverse`, 'POST', payload)).data
}

export async function getFinanceSummary(from: string, to: string, asOf: string): Promise<FinanceSummary> {
  const query = new URLSearchParams({ from, to, as_of: asOf })
  return (await request<ItemResponse<FinanceSummary>>(`/finance/summary?${query.toString()}`)).data
}

export async function getFinanceBudgets(month: string): Promise<FinanceBudget[]> {
  return (await request<{ month: string, data: FinanceBudget[] }>(`/finance/budgets?month=${encodeURIComponent(month)}`)).data
}

export async function createFinanceBudget(payload: FinanceBudgetInput): Promise<FinanceBudget> {
  return (await jsonRequest<ItemResponse<FinanceBudget>>('/finance/budgets', 'POST', payload)).data
}

export async function updateFinanceBudget(id: number, payload: FinanceBudgetUpdate): Promise<FinanceBudget> {
  return (await jsonRequest<ItemResponse<FinanceBudget>>(`/finance/budgets/${id}`, 'PATCH', payload)).data
}

export function deleteFinanceBudget(id: number): Promise<void> {
  return request<void>(`/finance/budgets/${id}`, { method: 'DELETE' })
}

export async function getFinanceRecurringOperations(includeArchived = true): Promise<FinanceRecurringOperation[]> {
  return (await request<ListResponse<FinanceRecurringOperation>>(`/finance/recurring-operations?include_archived=${includeArchived ? '1' : '0'}`)).data
}

export async function createFinanceRecurringOperation(payload: FinanceRecurringOperationInput): Promise<FinanceRecurringOperation> {
  return (await jsonRequest<ItemResponse<FinanceRecurringOperation>>('/finance/recurring-operations', 'POST', payload)).data
}

export async function updateFinanceRecurringOperation(id: number, payload: FinanceRecurringOperationUpdate): Promise<FinanceRecurringOperation> {
  return (await jsonRequest<ItemResponse<FinanceRecurringOperation>>(`/finance/recurring-operations/${id}`, 'PATCH', payload)).data
}

export async function getFinanceCashFlow(month: string): Promise<FinanceCashFlow> {
  return (await request<ItemResponse<FinanceCashFlow>>(`/finance/cash-flow?month=${encodeURIComponent(month)}`)).data
}

export async function getFinancePlannedOccurrences(from: string, to: string): Promise<FinancePlannedOccurrence[]> {
  const query = new URLSearchParams({ from, to })
  return (await request<{ from: string, to: string, data: FinancePlannedOccurrence[] }>(`/finance/planned-occurrences?${query.toString()}`)).data
}

export async function putFinanceOccurrenceOutcome(id: number, outcome: 'actual' | 'skipped'): Promise<FinancePlannedOccurrence> {
  return (await jsonRequest<ItemResponse<FinancePlannedOccurrence>>(`/finance/planned-occurrences/${id}/outcome`, 'PUT', { outcome })).data
}

export async function clearFinanceOccurrenceOutcome(id: number): Promise<FinancePlannedOccurrence> {
  return (await request<ItemResponse<FinancePlannedOccurrence>>(`/finance/planned-occurrences/${id}/outcome`, { method: 'DELETE' })).data
}

export async function getFinanceCounterparties(archived = false): Promise<FinanceCounterparty[]> {
  return (await request<{ data: FinanceCounterparty[] }>(`/finance/counterparties?archived=${archived ? '1' : '0'}`)).data
}

export async function createFinanceCounterparty(payload: FinanceCounterpartyInput): Promise<FinanceCounterparty> {
  return (await jsonRequest<ItemResponse<FinanceCounterparty>>('/finance/counterparties', 'POST', payload)).data
}

export async function updateFinanceCounterparty(id: number, payload: FinanceCounterpartyUpdate): Promise<FinanceCounterparty> {
  return (await jsonRequest<ItemResponse<FinanceCounterparty>>(`/finance/counterparties/${id}`, 'PATCH', payload)).data
}

export async function getFinanceDebts(archived = false): Promise<FinanceDebt[]> {
  return (await request<{ data: FinanceDebt[] }>(`/finance/debts?archived=${archived ? '1' : '0'}`)).data
}

export async function createFinanceDebt(payload: FinanceDebtInput): Promise<FinanceDebt> {
  return (await jsonRequest<ItemResponse<FinanceDebt>>('/finance/debts', 'POST', payload)).data
}

export async function updateFinanceDebt(id: number, payload: FinanceDebtUpdate): Promise<FinanceDebt> {
  return (await jsonRequest<ItemResponse<FinanceDebt>>(`/finance/debts/${id}`, 'PATCH', payload)).data
}

export async function payFinanceDebt(id: number, payload: FinanceDebtPaymentInput): Promise<{ data: FinanceDebtPayment, debt: FinanceDebt }> {
  return jsonRequest(`/finance/debts/${id}/payments`, 'POST', payload)
}

export async function getFinanceSavingFunds(month?: string, archived = false): Promise<FinanceSavingFund[]> {
  const query = new URLSearchParams({ archived: archived ? '1' : '0' })
  if (month) query.set('month', month)
  return (await request<{ data: FinanceSavingFund[] }>(`/finance/saving-funds?${query.toString()}`)).data
}

export async function createFinanceSavingFund(payload: FinanceSavingFundInput): Promise<FinanceSavingFund> {
  return (await jsonRequest<ItemResponse<FinanceSavingFund>>('/finance/saving-funds', 'POST', payload)).data
}

export async function updateFinanceSavingFund(id: number, payload: FinanceSavingFundUpdate): Promise<FinanceSavingFund> {
  return (await jsonRequest<ItemResponse<FinanceSavingFund>>(`/finance/saving-funds/${id}`, 'PATCH', payload)).data
}

export async function createFinanceFundMovement(id: number, payload: FinanceFundMovementInput): Promise<{ data: FinanceFundMovement, fund: FinanceSavingFund }> {
  return jsonRequest(`/finance/saving-funds/${id}/movements`, 'POST', payload)
}

export async function getFinanceGoals(archived = false): Promise<FinanceGoal[]> {
  return (await request<{ data: FinanceGoal[] }>(`/finance/goals?archived=${archived ? '1' : '0'}`)).data
}

export async function createFinanceGoal(payload: FinanceGoalInput): Promise<FinanceGoal> {
  return (await jsonRequest<ItemResponse<FinanceGoal>>('/finance/goals', 'POST', payload)).data
}

export async function updateFinanceGoal(id: number, payload: FinanceGoalUpdate): Promise<FinanceGoal> {
  return (await jsonRequest<ItemResponse<FinanceGoal>>(`/finance/goals/${id}`, 'PATCH', payload)).data
}

export function createFinanceSourceExpense(payload: FinanceSourceExpenseInput): Promise<FinanceSourceExpenseResponse> {
  return jsonRequest<FinanceSourceExpenseResponse>('/finance/source-expenses', 'POST', payload)
}

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

export function getSupplements(state: SupplementLifecycleState = 'active'): Promise<SupplementListResponse> {
  return request<SupplementListResponse>(`/supplements?state=${encodeURIComponent(state)}`)
}

export async function createSupplement(payload: SupplementInput): Promise<Supplement> {
  return (await jsonRequest<ItemResponse<Supplement>>('/supplements', 'POST', payload)).data
}

export async function updateSupplement(supplementId: number, payload: Partial<SupplementInput> & { is_archived?: boolean }): Promise<Supplement> {
  return (await jsonRequest<ItemResponse<Supplement>>(`/supplements/${supplementId}`, 'PATCH', payload)).data
}

export function getSupplementCourses(state: SupplementLifecycleState = 'active'): Promise<SupplementCourseListResponse> {
  return request<SupplementCourseListResponse>(`/supplement-courses?state=${encodeURIComponent(state)}`)
}

export async function createSupplementCourse(payload: SupplementCourseInput): Promise<SupplementCourse> {
  return (await jsonRequest<ItemResponse<SupplementCourse>>('/supplement-courses', 'POST', payload)).data
}

export async function updateSupplementCourse(courseId: number, payload: Partial<Omit<SupplementCourseInput, 'supplement_id'>> & { is_archived?: boolean }): Promise<SupplementCourse> {
  return (await jsonRequest<ItemResponse<SupplementCourse>>(`/supplement-courses/${courseId}`, 'PATCH', payload)).data
}

export interface SupplementIntakeResponse extends ItemResponse<SupplementOccurrence> {
  stock: Supplement['stock']
  forecast: Supplement['forecast']
  restock_proposal: SupplementRestockProposal | null
}

export function upsertSupplementIntake(
  occurrenceId: number,
  payload: { outcome: 'taken' | 'skipped', dose_quantity: string | null, dose_display_unit: string | null, taken_time: string | null, note: string | null },
): Promise<SupplementIntakeResponse> {
  return jsonRequest<SupplementIntakeResponse>(`/supplement-occurrences/${occurrenceId}/intake`, 'PUT', payload)
}

export function clearSupplementIntake(occurrenceId: number): Promise<void> {
  return request<void>(`/supplement-occurrences/${occurrenceId}/intake`, { method: 'DELETE' })
}

export async function getSupplementStockMovements(supplementId: number): Promise<SupplementStockMovement[]> {
  return (await request<ListResponse<SupplementStockMovement>>(`/supplements/${supplementId}/stock-movements`)).data
}

export function createSupplementStockMovement(supplementId: number, payload: SupplementStockMovementInput): Promise<ItemResponse<SupplementStockMovement> & Pick<SupplementIntakeResponse, 'stock' | 'forecast' | 'restock_proposal'>> {
  return jsonRequest(`/supplements/${supplementId}/stock-movements`, 'POST', payload)
}

export async function dismissSupplementRestockProposal(proposalId: number): Promise<SupplementRestockProposal> {
  return (await jsonRequest<ItemResponse<SupplementRestockProposal>>(`/supplement-restock-proposals/${proposalId}`, 'PATCH', { status: 'dismissed' })).data
}

export async function getSupplementDay(date: string): Promise<SupplementDay> {
  return (await request<ItemResponse<SupplementDay>>(`/supplements/days/${encodeURIComponent(date)}`)).data
}

export async function getSupplementAdherence(from: string, to: string): Promise<SupplementAdherenceRange> {
  const query = new URLSearchParams({ from, to })
  return (await request<ItemResponse<SupplementAdherenceRange>>(`/supplements/adherence?${query.toString()}`)).data
}

export async function getNutritionFoods(state: NutritionLifecycleState = 'active'): Promise<FoodItem[]> {
  const response = await request<ListResponse<FoodItem>>(`/nutrition/foods?state=${encodeURIComponent(state)}`)
  return response.data
}

export async function createNutritionFood(payload: FoodItemInput): Promise<FoodItem> {
  const response = await jsonRequest<ItemResponse<FoodItem>>('/nutrition/foods', 'POST', payload)
  return response.data
}

export async function updateNutritionFood(foodId: number, payload: Partial<FoodItemInput> & { is_archived?: boolean }): Promise<FoodItem> {
  const response = await jsonRequest<ItemResponse<FoodItem>>(`/nutrition/foods/${foodId}`, 'PATCH', payload)
  return response.data
}

export async function getNutritionRecipes(state: NutritionLifecycleState = 'active'): Promise<Recipe[]> {
  const response = await request<ListResponse<Recipe>>(`/nutrition/recipes?state=${encodeURIComponent(state)}`)
  return response.data
}

export async function createNutritionRecipe(payload: RecipeInput): Promise<Recipe> {
  const response = await jsonRequest<ItemResponse<Recipe>>('/nutrition/recipes', 'POST', payload)
  return response.data
}

export async function updateNutritionRecipe(recipeId: number, payload: Partial<RecipeInput> & { is_archived?: boolean }): Promise<Recipe> {
  const response = await jsonRequest<ItemResponse<Recipe>>(`/nutrition/recipes/${recipeId}`, 'PATCH', payload)
  return response.data
}

export async function getNutritionSettings(): Promise<NutritionSettings> {
  const response = await request<ItemResponse<NutritionSettings>>('/nutrition/settings')
  return response.data
}

export async function updateNutritionSettings(payload: NutritionSettingsInput): Promise<NutritionSettings> {
  const response = await jsonRequest<ItemResponse<NutritionSettings>>('/nutrition/settings', 'PUT', payload)
  return response.data
}

export async function getNutritionDay(date: string): Promise<NutritionDay> {
  const response = await request<ItemResponse<NutritionDay>>(`/nutrition/days/${encodeURIComponent(date)}`)
  return response.data
}

export async function getNutritionSummary(from: string, to: string): Promise<NutritionSummaryRange> {
  const query = new URLSearchParams({ from, to })
  const response = await request<ItemResponse<NutritionSummaryRange>>(`/nutrition/summary?${query.toString()}`)
  return response.data
}

export async function createNutritionMeal(payload: MealInput & { submission_key: string }): Promise<Meal> {
  const response = await jsonRequest<ItemResponse<Meal>>('/nutrition/meals', 'POST', payload)
  return response.data
}

export async function updateNutritionMeal(mealId: number, payload: Omit<MealInput, 'submission_key'>): Promise<Meal> {
  const response = await jsonRequest<ItemResponse<Meal>>(`/nutrition/meals/${mealId}`, 'PATCH', payload)
  return response.data
}

export function deleteNutritionMeal(mealId: number): Promise<void> {
  return request<void>(`/nutrition/meals/${mealId}`, { method: 'DELETE' })
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

export async function getDailyReviewWorkspace(date: string): Promise<DailyReviewWorkspace> {
  const response = await request<ItemResponse<DailyReviewWorkspace>>(
    `/review-workspaces/daily/${encodeURIComponent(date)}`,
  )
  return response.data
}

export async function getPeriodicReviewWorkspace(
  period: PeriodicReviewType,
  anchor: string,
): Promise<PeriodicReviewWorkspace> {
  const response = await request<ItemResponse<PeriodicReviewWorkspace>>(
    `/periodic-reviews/${period}/${encodeURIComponent(anchor)}`,
  )
  return response.data
}

export async function savePeriodicReview(
  period: PeriodicReviewType,
  anchor: string,
  payload: PeriodicReviewPayload,
): Promise<PeriodicReview> {
  const response = await jsonRequest<ItemResponse<PeriodicReview>>(
    `/periodic-reviews/${period}/${encodeURIComponent(anchor)}`,
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
