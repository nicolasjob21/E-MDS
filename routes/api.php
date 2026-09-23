<?php

use App\Http\Controllers\AcicController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\ChequeLogController;
use App\Http\Controllers\ChequeUpdateRequestController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LddapController;
use App\Http\Controllers\LddapUpdateRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::post('login', [AuthController::class, 'login']);

    // Authenticated (any role)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        // The user menu's Profile and Change Password pages — a user's own account only.
        Route::put('me', [ProfileController::class, 'update']);
        Route::put('me/password', [ProfileController::class, 'changePassword']);

        // The dashboard — attention items by role, plus every register's counts.
        Route::get('dashboard', [DashboardController::class, 'show']);

        // In-app notifications (bell dropdown) — available to any authenticated user.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead']);

        Route::get('cheques', [ChequeController::class, 'index']);
        Route::get('cheques/summary', [ChequeController::class, 'summary']);
        Route::get('cheques/next', [ChequeController::class, 'next']);
        Route::post('cheques/use', [ChequeController::class, 'use']);
        // The cheque view/print payload; refused unless the cheque is approved.
        Route::get('cheques/{cheque}/print', [ChequeController::class, 'print']);

        // The 90-day validity axis — the banner's counts, the deposit queue and the tellers.
        Route::get('cheques/validity-summary', [ChequeController::class, 'validitySummary']);
        // The cheque's own steps. Each one is admin-only, gated in its Form Request.
        Route::get('cheques/{cheque}/status-history', [ChequeController::class, 'statusHistory']);
        Route::post('cheques/{cheque}/route', [ChequeController::class, 'routeForSignature']);
        Route::post('cheques/{cheque}/receive', [ChequeController::class, 'markAsReceived']);
        Route::post('cheques/{cheque}/release', [ChequeController::class, 'release']);
        Route::post('cheques/{cheque}/rts', [ChequeController::class, 'rts']);
        Route::post('cheques/{cheque}/cancel', [ChequeController::class, 'cancel']);
        Route::post('cheques/{cheque}/void', [ChequeController::class, 'void']);

        // Branch B is taken by the whole ACIC: the admin forwards it, the first teller to
        // accept claims it, and only that teller deposits or hands it back.
        Route::get('acics/teller-queue', [AcicController::class, 'tellerQueue']);
        Route::get('acics/{acic}/history', [AcicController::class, 'history']);
        // Admin sends it out; the rest belong to the teller who claimed it.
        Route::post('acics/{acic}/forward-to-teller', [AcicController::class, 'forwardToTeller']);
        Route::post('acics/{acic}/accept', [AcicController::class, 'acceptByTeller']);
        Route::post('acics/{acic}/forward-to-land-bank', [AcicController::class, 'forwardToLandBank']);
        Route::post('acics/{acic}/returned-by-bank', [AcicController::class, 'returnedByBank']);
        Route::post('acics/{acic}/complete-teller', [AcicController::class, 'markCredited']);
        Route::post('acics/{acic}/return-to-admin', [AcicController::class, 'returnToAdmin']);

        // Teller only: confirm a used cheque has been received, and complete a forwarded ACIC.
        Route::middleware('teller')->group(function () {
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
        Route::get('lddaps/options', [LddapController::class, 'options']);
        // Registered payees, searched by name or account number.
        Route::get('payees', [PayeeController::class, 'index']);
        Route::get('payees/{payee}', [PayeeController::class, 'show']);
        Route::get('lddaps/linkable', [LddapController::class, 'linkable']);
        // One LDDAP at a time, without a check number; the number is issued when the approved
        // record is put on an ACIC.
        Route::post('lddaps', [LddapController::class, 'store']);
        // Edit: the register form again, on a Registered or RTS record; every edit is kept.
        Route::put('lddaps/{lddap}', [LddapController::class, 'update']);
        Route::get('lddaps/{lddap}/edit-history', [LddapController::class, 'editHistory']);
        // The routing: Forward (Registered → For Out) and Receive (For Out → Returned for ACIC),
        // both authorized admin/staff in their Form Requests; the trail is readable by anyone.
        Route::post('lddaps/{lddap}/forward', [LddapController::class, 'forward']);
        Route::post('lddaps/{lddap}/receive-back', [LddapController::class, 'receive']);
        Route::get('lddaps/{lddap}/routing-history', [LddapController::class, 'routingHistory']);
        // "Assign LDDAP to ACIC" by typed ACIC number. Check numbers are issued here, one per
        // record, consecutively, under a row lock.
        Route::post('lddaps/assign-acic', [LddapController::class, 'assignToAcicByNumber']);
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
            Route::post('cheques/{cheque}/replace', [ChequeController::class, 'replace']);
            // Replacing a stale cheque, and correcting release details after the fact.
            Route::post('cheques/{cheque}/replace', [ChequeController::class, 'replace']);
            Route::post('lddaps/add-range', [LddapController::class, 'addRange']);
            // The admin's action on a record Returned for ACIC.
            Route::post('lddaps/{lddap}/approve', [LddapController::class, 'approve']);
            Route::post('lddaps/{lddap}/rts', [LddapController::class, 'rts']);
            Route::post('lddaps/{lddap}/cancel', [LddapController::class, 'cancel']);
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
