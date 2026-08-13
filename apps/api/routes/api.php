<?php

use App\Http\Controllers\BodyGoalController;
use App\Http\Controllers\BodyMeasurementController;
use App\Http\Controllers\DailyReviewController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HabitController;
use App\Http\Controllers\HabitLimitController;
use App\Http\Controllers\HabitLogController;
use App\Http\Controllers\HabitStatisticsController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\MobileNotificationController;
use App\Http\Controllers\MobileSessionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\PlannerController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RoutineLogController;
use App\Http\Controllers\TimeBlockController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::post('/mobile/session', [MobileSessionController::class, 'store']);

Route::middleware(['auth:sanctum', 'mobile.token'])->group(function () {
    Route::get('/mobile/session', [MobileSessionController::class, 'show']);
    Route::delete('/mobile/session', [MobileSessionController::class, 'destroy']);
    Route::put('/mobile/notifications/{notification}/presented', [MobileNotificationController::class, 'presented']);
});

Route::middleware('auth:sanctum')->group(function () {
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

    Route::get('/daily-reviews/{date}', [DailyReviewController::class, 'show']);
    Route::put('/daily-reviews/{date}', [DailyReviewController::class, 'upsert']);

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
