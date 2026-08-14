<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\BodyGoalController;
use App\Http\Controllers\BodyMeasurementController;
use App\Http\Controllers\DailyReviewController;
use App\Http\Controllers\ExerciseController;
use App\Http\Controllers\Finance\FinanceAccountController;
use App\Http\Controllers\Finance\FinanceBudgetController;
use App\Http\Controllers\Finance\FinanceCashFlowController;
use App\Http\Controllers\Finance\FinanceCategoryController;
use App\Http\Controllers\Finance\FinanceCounterpartyController;
use App\Http\Controllers\Finance\FinanceDebtController;
use App\Http\Controllers\Finance\FinanceGoalController;
use App\Http\Controllers\Finance\FinanceOccurrenceController;
use App\Http\Controllers\Finance\FinanceRecurringOperationController;
use App\Http\Controllers\Finance\FinanceReferenceController;
use App\Http\Controllers\Finance\FinanceSavingFundController;
use App\Http\Controllers\Finance\FinanceSourceExpenseController;
use App\Http\Controllers\Finance\FinanceSummaryController;
use App\Http\Controllers\Finance\FinanceTransactionController;
use App\Http\Controllers\Finance\FinanceTransferController;
use App\Http\Controllers\FoodItemController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitLimitController;
use App\Http\Controllers\HabitLogController;
use App\Http\Controllers\HabitStatisticsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\MealController;
use App\Http\Controllers\MobileNotificationController;
use App\Http\Controllers\MobileSessionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\NutritionDayController;
use App\Http\Controllers\NutritionSettingsController;
use App\Http\Controllers\PeriodicReviewController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ReviewWorkspaceController;
use App\Http\Controllers\RoutineActivityController;
use App\Http\Controllers\RoutineActivityLogController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RoutineDaySelectionController;
use App\Http\Controllers\RoutineLogController;
use App\Http\Controllers\SleepController;
use App\Http\Controllers\SleepLogController;
use App\Http\Controllers\SleepPlanController;
use App\Http\Controllers\SleepStatisticsController;
use App\Http\Controllers\SupplementController;
use App\Http\Controllers\SupplementCourseController;
use App\Http\Controllers\SupplementDayController;
use App\Http\Controllers\SupplementIntakeController;
use App\Http\Controllers\SupplementRestockProposalController;
use App\Http\Controllers\SupplementStockMovementController;
use App\Http\Controllers\TimeBlockController;
use App\Http\Controllers\TodayController;
use App\Http\Controllers\TrainingGoalController;
use App\Http\Controllers\WorkoutProgramController;
use App\Http\Controllers\WorkoutSessionController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/mobile/session', [MobileSessionController::class, 'store']);

Route::middleware(['auth:sanctum', 'mobile.token'])->group(function () {
    Route::get('/mobile/session', [MobileSessionController::class, 'show']);
    Route::delete('/mobile/session', [MobileSessionController::class, 'destroy']);
    Route::put('/mobile/notifications/{notification}/presented', [MobileNotificationController::class, 'presented']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachment}/content', [AttachmentController::class, 'content'])
        ->whereNumber('attachment');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])
        ->whereNumber('attachment');

    Route::get('/finance/counterparties', [FinanceCounterpartyController::class, 'index']);
    Route::post('/finance/counterparties', [FinanceCounterpartyController::class, 'store']);
    Route::patch('/finance/counterparties/{counterparty}', [FinanceCounterpartyController::class, 'update']);
    Route::get('/finance/debts', [FinanceDebtController::class, 'index']);
    Route::post('/finance/debts', [FinanceDebtController::class, 'store']);
    Route::patch('/finance/debts/{debt}', [FinanceDebtController::class, 'update']);
    Route::post('/finance/debts/{debt}/payments', [FinanceDebtController::class, 'payment']);
    Route::get('/finance/saving-funds', [FinanceSavingFundController::class, 'index']);
    Route::post('/finance/saving-funds', [FinanceSavingFundController::class, 'store']);
    Route::patch('/finance/saving-funds/{fund}', [FinanceSavingFundController::class, 'update']);
    Route::post('/finance/saving-funds/{fund}/movements', [FinanceSavingFundController::class, 'movement']);
    Route::get('/finance/goals', [FinanceGoalController::class, 'index']);
    Route::post('/finance/goals', [FinanceGoalController::class, 'store']);
    Route::patch('/finance/goals/{goal}', [FinanceGoalController::class, 'update']);
    Route::post('/finance/source-expenses', [FinanceSourceExpenseController::class, 'store']);
    Route::get('/finance/accounts', [FinanceAccountController::class, 'index']);
    Route::get('/finance/budgets', [FinanceBudgetController::class, 'index']);
    Route::post('/finance/budgets', [FinanceBudgetController::class, 'store']);
    Route::patch('/finance/budgets/{budget}', [FinanceBudgetController::class, 'update']);
    Route::delete('/finance/budgets/{budget}', [FinanceBudgetController::class, 'destroy']);
    Route::get('/finance/cash-flow', [FinanceCashFlowController::class, 'show']);
    Route::get('/finance/recurring-operations', [FinanceRecurringOperationController::class, 'index']);
    Route::post('/finance/recurring-operations', [FinanceRecurringOperationController::class, 'store']);
    Route::patch('/finance/recurring-operations/{operation}', [FinanceRecurringOperationController::class, 'update']);
    Route::get('/finance/planned-occurrences', [FinanceOccurrenceController::class, 'index']);
    Route::put('/finance/planned-occurrences/{occurrence}/outcome', [FinanceOccurrenceController::class, 'put']);
    Route::delete('/finance/planned-occurrences/{occurrence}/outcome', [FinanceOccurrenceController::class, 'clear']);
    Route::post('/finance/accounts', [FinanceAccountController::class, 'store']);
    Route::patch('/finance/accounts/{account}', [FinanceAccountController::class, 'update']);
    Route::post('/finance/accounts/{account}/reconcile', [FinanceAccountController::class, 'reconcile']);
    Route::get('/finance/categories', [FinanceCategoryController::class, 'index']);
    Route::post('/finance/categories', [FinanceCategoryController::class, 'store']);
    Route::patch('/finance/categories/{category}', [FinanceCategoryController::class, 'update']);
    Route::get('/finance/transactions', [FinanceTransactionController::class, 'index']);
    Route::post('/finance/transactions', [FinanceTransactionController::class, 'store']);
    Route::post('/finance/transfers', [FinanceTransferController::class, 'store']);
    Route::post('/finance/transactions/{transaction}/reverse', [FinanceTransactionController::class, 'reverse']);
    Route::get('/finance/currencies', [FinanceReferenceController::class, 'currencies']);
    Route::get('/finance/exchange-rates', [FinanceReferenceController::class, 'rates']);
    Route::put('/finance/exchange-rates', [FinanceReferenceController::class, 'upsert']);
    Route::get('/finance/summary', [FinanceSummaryController::class, 'show']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::patch('/profile', [ProfileController::class, 'updatePreferences']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/settings', [NotificationSettingsController::class, 'show']);
    Route::put('/notifications/settings', [NotificationSettingsController::class, 'replace']);
    Route::put('/notifications/{notification}/read', [NotificationController::class, 'read']);
    Route::put('/notifications/{notification}/dismiss', [NotificationController::class, 'dismiss']);
    Route::put('/notifications/{notification}/snooze', [NotificationController::class, 'snooze']);

    Route::get('/today', TodayController::class);

    Route::get('/supplements', [SupplementController::class, 'index']);
    Route::post('/supplements', [SupplementController::class, 'store']);
    Route::patch('/supplements/{supplement}', [SupplementController::class, 'update']);
    Route::get('/supplements/days/{date}', [SupplementDayController::class, 'show']);
    Route::get('/supplements/adherence', [SupplementDayController::class, 'adherence']);
    Route::get('/supplement-courses', [SupplementCourseController::class, 'index']);
    Route::post('/supplement-courses', [SupplementCourseController::class, 'store']);
    Route::patch('/supplement-courses/{course}', [SupplementCourseController::class, 'update']);
    Route::put('/supplement-occurrences/{occurrence}/intake', [SupplementIntakeController::class, 'upsert']);
    Route::delete('/supplement-occurrences/{occurrence}/intake', [SupplementIntakeController::class, 'clear']);
    Route::get('/supplements/{supplement}/stock-movements', [SupplementStockMovementController::class, 'index']);
    Route::post('/supplements/{supplement}/stock-movements', [SupplementStockMovementController::class, 'store']);
    Route::patch('/supplement-restock-proposals/{proposal}', [SupplementRestockProposalController::class, 'update']);

    Route::get('/nutrition/foods', [FoodItemController::class, 'index']);
    Route::post('/nutrition/foods', [FoodItemController::class, 'store']);
    Route::patch('/nutrition/foods/{food}', [FoodItemController::class, 'update']);
    Route::get('/nutrition/recipes', [RecipeController::class, 'index']);
    Route::post('/nutrition/recipes', [RecipeController::class, 'store']);
    Route::patch('/nutrition/recipes/{recipe}', [RecipeController::class, 'update']);
    Route::get('/nutrition/settings', [NutritionSettingsController::class, 'show']);
    Route::put('/nutrition/settings', [NutritionSettingsController::class, 'replace']);
    Route::get('/nutrition/days/{date}', [NutritionDayController::class, 'show']);
    Route::get('/nutrition/summary', [NutritionDayController::class, 'summary']);
    Route::post('/nutrition/meals', [MealController::class, 'store']);
    Route::patch('/nutrition/meals/{meal}', [MealController::class, 'update']);
    Route::delete('/nutrition/meals/{meal}', [MealController::class, 'destroy']);

    Route::get('/exercises', [ExerciseController::class, 'index']);
    Route::post('/exercises', [ExerciseController::class, 'store']);
    Route::patch('/exercises/{exercise}', [ExerciseController::class, 'update']);

    Route::get('/workout-programs', [WorkoutProgramController::class, 'index']);
    Route::post('/workout-programs', [WorkoutProgramController::class, 'store']);
    Route::patch('/workout-programs/{program}', [WorkoutProgramController::class, 'update']);
    Route::put('/workout-programs/{program}/exercises', [WorkoutProgramController::class, 'replaceExercises']);
    Route::put('/workout-programs/{program}/sessions/{date}', [WorkoutSessionController::class, 'upsertPlanned']);

    Route::get('/workouts', [WorkoutSessionController::class, 'index']);
    Route::post('/workouts', [WorkoutSessionController::class, 'store']);
    Route::patch('/workouts/{workout}', [WorkoutSessionController::class, 'update']);
    Route::delete('/workouts/{workout}', [WorkoutSessionController::class, 'destroy']);

    Route::get('/training/goals', [TrainingGoalController::class, 'index']);
    Route::post('/training/goals', [TrainingGoalController::class, 'store']);
    Route::patch('/training/goals/{goal}', [TrainingGoalController::class, 'update']);

    Route::get('/habits', [HabitController::class, 'index']);
    Route::post('/habits', [HabitController::class, 'store']);
    Route::patch('/habits/{habit}', [HabitController::class, 'update']);
    Route::put('/habits/{habit}/logs/{date}', [HabitLogController::class, 'upsert']);
    Route::delete('/habits/{habit}/logs/{date}', [HabitLogController::class, 'clear']);
    Route::get('/habits/{habit}/statistics', [HabitStatisticsController::class, 'show']);
    Route::put('/habits/{habit}/limit-steps', [HabitLimitController::class, 'replace']);

    Route::get('/routines', [RoutineController::class, 'index']);
    Route::post('/routines', [RoutineController::class, 'store']);
    Route::patch('/routines/{routine}', [RoutineController::class, 'update']);
    Route::put('/routines/{routine}/logs/{date}', [RoutineLogController::class, 'upsert']);
    Route::delete('/routines/{routine}/logs/{date}', [RoutineLogController::class, 'clear']);
    Route::put('/routines/{routine}/activities', [RoutineActivityController::class, 'replace']);
    Route::put('/routines/{routine}/activities/{activity}/logs/{date}', [RoutineActivityLogController::class, 'upsert']);
    Route::delete('/routines/{routine}/activities/{activity}/logs/{date}', [RoutineActivityLogController::class, 'clear']);
    Route::put('/routine-selections/{date}', [RoutineDaySelectionController::class, 'replace']);

    Route::get('/sleep', SleepController::class);
    Route::post('/sleep/plans', [SleepPlanController::class, 'store']);
    Route::patch('/sleep/plans/{sleepPlan}', [SleepPlanController::class, 'update']);
    Route::put('/sleep/plans/{sleepPlan}/logs/{date}', [SleepLogController::class, 'upsert']);
    Route::delete('/sleep/plans/{sleepPlan}/logs/{date}', [SleepLogController::class, 'clear']);
    Route::get('/sleep/statistics', SleepStatisticsController::class);

    Route::get('/daily-reviews/{date}', [DailyReviewController::class, 'show']);
    Route::put('/daily-reviews/{date}', [DailyReviewController::class, 'upsert']);
    Route::get('/review-workspaces/daily/{date}', [ReviewWorkspaceController::class, 'daily']);
    Route::get('/periodic-reviews/{period}/{anchor}', [PeriodicReviewController::class, 'show']);
    Route::put('/periodic-reviews/{period}/{anchor}', [PeriodicReviewController::class, 'upsert']);

    Route::get('/body/measurements', [BodyMeasurementController::class, 'index']);
    Route::put('/body/measurements', [BodyMeasurementController::class, 'upsert']);
    Route::delete('/body/measurements/{measurement}', [BodyMeasurementController::class, 'destroy']);
    Route::get('/body/trend', [BodyMeasurementController::class, 'trend']);

    Route::get('/body/goals', [BodyGoalController::class, 'index']);
    Route::post('/body/goals', [BodyGoalController::class, 'store']);
    Route::patch('/body/goals/{goal}', [BodyGoalController::class, 'update']);

    Route::get('/planner/day', [PlannerController::class, 'day']);
    Route::patch('/planner/occurrences/{occurrence}/reschedule', [PlannerController::class, 'reschedule']);
    Route::put('/planner/occurrences/{occurrence}/skip', [PlannerController::class, 'skip']);

    Route::post('/planner/time-blocks', [TimeBlockController::class, 'store']);
    Route::patch('/planner/time-blocks/{block}', [TimeBlockController::class, 'update']);
    Route::delete('/planner/time-blocks/{block}', [TimeBlockController::class, 'destroy']);

    Route::get('/storage/items', [ItemController::class, 'index']);
    Route::post('/storage/items', [ItemController::class, 'store']);
    Route::patch('/storage/items/{item}', [ItemController::class, 'update']);
    Route::delete('/storage/items/{item}', [ItemController::class, 'destroy']);

    Route::get('/storage/projects', [ProjectController::class, 'index']);
    Route::post('/storage/projects', [ProjectController::class, 'store']);
    Route::patch('/storage/projects/{project}', [ProjectController::class, 'update']);
    Route::delete('/storage/projects/{project}', [ProjectController::class, 'destroy']);

    Route::get('/goals', [GoalController::class, 'index']);
    Route::post('/goals', [GoalController::class, 'store']);
    Route::patch('/goals/{goal}', [GoalController::class, 'update']);
    Route::post('/goals/{goal}/routines/{routine}', [GoalController::class, 'linkRoutine']);
    Route::delete('/goals/{goal}/routines/{routine}', [GoalController::class, 'unlinkRoutine']);
});
