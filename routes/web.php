<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/v1/auth')->group(function (): void {
    Route::get('options', [AuthController::class, 'options']);
    Route::get('google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1');
    Route::get('google/callback', [GoogleAuthController::class, 'callback']);

    Route::middleware('auth')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth')->prefix('api/v1/me')->group(function (): void {
    Route::delete('/', [AuthController::class, 'destroy']);
});
