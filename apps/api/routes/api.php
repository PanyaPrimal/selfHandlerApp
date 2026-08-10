<?php

use App\Http\Controllers\DailyReviewController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RoutineLogController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/today', TodayController::class);

    Route::get('/routines', [RoutineController::class, 'index']);
    Route::post('/routines', [RoutineController::class, 'store']);
    Route::patch('/routines/{routine}', [RoutineController::class, 'update']);
    Route::put('/routines/{routine}/logs/{date}', [RoutineLogController::class, 'upsert']);
    Route::delete('/routines/{routine}/logs/{date}', [RoutineLogController::class, 'clear']);

    Route::get('/daily-reviews/{date}', [DailyReviewController::class, 'show']);
    Route::put('/daily-reviews/{date}', [DailyReviewController::class, 'upsert']);

    Route::get('/goals', [GoalController::class, 'index']);
    Route::post('/goals', [GoalController::class, 'store']);
    Route::patch('/goals/{goal}', [GoalController::class, 'update']);
    Route::post('/goals/{goal}/routines/{routine}', [GoalController::class, 'linkRoutine']);
    Route::delete('/goals/{goal}/routines/{routine}', [GoalController::class, 'unlinkRoutine']);
});
