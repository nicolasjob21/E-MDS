<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\ChequeLogController;
use App\Http\Controllers\ChequeUpdateRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('login', [AuthController::class, 'login']);

    // Authenticated (any role)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        // In-app notifications (bell dropdown) — available to any authenticated user.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('cheques', [ChequeController::class, 'index']);
        Route::get('cheques/summary', [ChequeController::class, 'summary']);
        Route::get('cheques/next', [ChequeController::class, 'next']);
        Route::post('cheques/use', [ChequeController::class, 'use']);

        // Teller only: confirm a used cheque has been received.
        Route::middleware('teller')->group(function () {
            Route::post('cheques/{cheque}/receive', [ChequeController::class, 'confirmReceipt']);
        });

        // Staff request a correction to a cheque's details (authorized in the Form Request).
        Route::post('cheques/{cheque}/update-requests', [ChequeUpdateRequestController::class, 'store']);
        // Anyone authenticated can read a cheque's update-request history (with outcomes).
        Route::get('cheques/{cheque}/update-requests', [ChequeUpdateRequestController::class, 'forCheque']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::post('cheques/add-range', [ChequeController::class, 'addRange']);

            Route::get('update-requests', [ChequeUpdateRequestController::class, 'index']);
            Route::post('update-requests/{updateRequest}/approve', [ChequeUpdateRequestController::class, 'approve']);
            Route::post('update-requests/{updateRequest}/reject', [ChequeUpdateRequestController::class, 'reject']);

            Route::get('logs', [ChequeLogController::class, 'index']);

            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });
    });
});
