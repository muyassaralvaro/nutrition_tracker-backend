<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('api/v1/auth')->group(function (): void {
    Route::get('options', [AuthController::class, 'options']);
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('google/redirect', [GoogleAuthController::class, 'redirect']);
    Route::get('google/callback', [GoogleAuthController::class, 'callback']);

    Route::middleware('auth')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth')->prefix('api/v1/me')->group(function (): void {
    Route::patch('password', [AuthController::class, 'changePassword']);
    Route::delete('/', [AuthController::class, 'destroy']);
});
