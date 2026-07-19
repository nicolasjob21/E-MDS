<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\ChequeLogController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('login', [AuthController::class, 'login']);

    // Authenticated (any role)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('cheques', [ChequeController::class, 'index']);
        Route::get('cheques/summary', [ChequeController::class, 'summary']);
        Route::get('cheques/next', [ChequeController::class, 'next']);
        Route::post('cheques/use', [ChequeController::class, 'use']);
        Route::post('cheques/{cheque}/cash', [ChequeController::class, 'cash']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::post('cheques/add-range', [ChequeController::class, 'addRange']);

            Route::get('logs', [ChequeLogController::class, 'index']);

            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });
    });
});
