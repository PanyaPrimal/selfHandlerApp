<?php

use App\Http\Controllers\DailyReviewController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\RoutineController;
use App\Http\Controllers\RoutineLogController;
use App\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

Route::get('/today', TodayController::class);

Route::apiResource('routines', RoutineController::class)->except(['show']);
Route::put('/routines/{routine}/logs/{date}', [RoutineLogController::class, 'upsert']);

Route::get('/daily-reviews/{date}', [DailyReviewController::class, 'show']);
Route::put('/daily-reviews/{date}', [DailyReviewController::class, 'upsert']);

Route::apiResource('goals', GoalController::class)->except(['show', 'destroy']);
Route::post('/goals/{goal}/routines/{routine}', [GoalController::class, 'linkRoutine']);
Route::delete('/goals/{goal}/routines/{routine}', [GoalController::class, 'unlinkRoutine']);
