<?php

use App\Http\Controllers\BodyGoalController;
use App\Http\Controllers\BodyMeasurementController;
use App\Http\Controllers\DailyReviewController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RoutineLogController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/today', TodayController::class);

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
