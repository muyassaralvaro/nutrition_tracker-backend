<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\FoodController;
use App\Http\Controllers\Api\MealAnalysisController;
use App\Http\Controllers\Api\MealController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SummaryController;
use App\Http\Controllers\Api\TargetController;
use App\Http\Controllers\Api\WeightController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function (): void {
    Route::get('me', [AuthController::class, 'me']);
    Route::get('me/profile', [ProfileController::class, 'show']);
    Route::put('me/profile', [ProfileController::class, 'update']);
    Route::put('me/setup', [ProfileController::class, 'setup']);
    Route::post('target-estimates', [ProfileController::class, 'estimate']);
    Route::get('me/targets', [TargetController::class, 'index']);
    Route::put('me/targets/{date}', [TargetController::class, 'put']);

    Route::get('me/weights', [WeightController::class, 'index']);
    Route::post('me/weights', [WeightController::class, 'store']);
    Route::put('me/weights/{id}', [WeightController::class, 'update']);
    Route::delete('me/weights/{id}', [WeightController::class, 'destroy']);

    Route::get('foods', [FoodController::class, 'index']);
    Route::get('foods/{id}', [FoodController::class, 'show']);

    Route::get('meals', [MealController::class, 'index']);
    Route::post('meals', [MealController::class, 'store']);
    Route::get('meals/{id}', [MealController::class, 'show']);
    Route::put('meals/{id}', [MealController::class, 'update']);
    Route::delete('meals/{id}', [MealController::class, 'destroy']);

    Route::post('meal-analyses', [MealAnalysisController::class, 'store'])->middleware('throttle:5,1');
    Route::get('meal-analyses/{id}', [MealAnalysisController::class, 'show']);
    Route::get('meal-analyses/{id}/image', [MealAnalysisController::class, 'image'])->name('meal-analyses.image');
    Route::delete('meal-analyses/{id}', [MealAnalysisController::class, 'destroy']);

    Route::get('days/{date}', [SummaryController::class, 'day']);
    Route::get('calendar', [SummaryController::class, 'calendar']);
});
