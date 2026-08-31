<?php

use App\Http\Controllers\AcicController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\ChequeLogController;
use App\Http\Controllers\ChequeUpdateRequestController;
use App\Http\Controllers\LddapController;
use App\Http\Controllers\LddapUpdateRequestController;
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

        // Teller only: confirm a used cheque has been received, and complete a forwarded ACIC.
        Route::middleware('teller')->group(function () {
            Route::post('cheques/{cheque}/receive', [ChequeController::class, 'confirmReceipt']);
            Route::post('lddaps/{lddap}/receive', [LddapController::class, 'confirmReceipt']);
            Route::post('acics/{acic}/complete', [AcicController::class, 'complete']);
        });

        // ACIC records. Reading is open to any authenticated user; creating and using them is
        // gated to admin/staff in the Form Requests, and forwarding is admin-only (below).
        Route::get('acics', [AcicController::class, 'index']);
        Route::get('acics/next', [AcicController::class, 'next']);
        Route::get('acics/series', [AcicController::class, 'series']);
        Route::get('acics/linkable-cheques', [AcicController::class, 'linkableCheques']);
        Route::get('acics/{acic}', [AcicController::class, 'show']);
        Route::post('acics', [AcicController::class, 'store']);
        Route::post('acics/{acic}/cheques', [AcicController::class, 'assignCheques']);
        // Swapping a record on an ACIC is authorized (admin/staff) in the Form Request.
        Route::post('acics/{acic}/reassign', [AcicController::class, 'reassign']);

        // LDDAP-ADA records. Reading is open to any authenticated user; using a cheque number
        // for them and putting them on an ACIC is gated to admin/staff in the Form Requests.
        Route::get('lddaps', [LddapController::class, 'index']);
        Route::get('lddaps/next-numbers', [LddapController::class, 'nextNumbers']);
        Route::get('lddaps/series', [LddapController::class, 'series']);
        Route::get('lddaps/linkable', [LddapController::class, 'linkable']);
        Route::post('lddaps/use-cheque', [LddapController::class, 'useCheque']);
        Route::post('acics/{acic}/lddaps', [LddapController::class, 'assignToAcic']);

        // Staff propose a correction to an LDDAP's details (authorized in the Form Request).
        Route::post('lddaps/{lddap}/update-requests', [LddapUpdateRequestController::class, 'store']);
        // Anyone authenticated can read an LDDAP's request history (with outcomes).
        Route::get('lddaps/{lddap}/update-requests', [LddapUpdateRequestController::class, 'forLddap']);

        // Staff request a correction to a cheque's details (authorized in the Form Request).
        Route::post('cheques/{cheque}/update-requests', [ChequeUpdateRequestController::class, 'store']);
        // Anyone authenticated can read a cheque's update-request history (with outcomes).
        Route::get('cheques/{cheque}/update-requests', [ChequeUpdateRequestController::class, 'forCheque']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::post('cheques/add-range', [ChequeController::class, 'addRange']);
            Route::post('lddaps/add-range', [LddapController::class, 'addRange']);
            Route::post('lddaps/{lddap}/review', [LddapController::class, 'review']);
            // Direct correction by an admin — takes effect immediately, reason required.
            Route::patch('lddaps/{lddap}', [LddapUpdateRequestController::class, 'applyDirect']);
            Route::post('cheques/{cheque}/review', [ChequeController::class, 'review']);
            Route::post('acics/add-range', [AcicController::class, 'addRange']);
            Route::post('acics/{acic}/approve', [AcicController::class, 'approve']);
            Route::post('acics/{acic}/forward', [AcicController::class, 'forward']);

            Route::get('lddap-update-requests', [LddapUpdateRequestController::class, 'index']);
            Route::post('lddap-update-requests/{updateRequest}/approve', [LddapUpdateRequestController::class, 'approve']);
            Route::post('lddap-update-requests/{updateRequest}/reject', [LddapUpdateRequestController::class, 'reject']);

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
