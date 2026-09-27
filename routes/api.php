<?php

use App\Http\Controllers\AcicController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChequeController;
use App\Http\Controllers\ChequeLogController;
use App\Http\Controllers\CreditorController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LddapController;
use App\Http\Controllers\LddapUpdateRequestController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\PcgPersonnelController;
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
        // The draft-checking flow: the preparer prints a draft, the admin in charge (Super Admin)
        // approves or returns it, the preparer prints it for real. Authorized in the requests.
        Route::put('cheques/{cheque}', [ChequeController::class, 'update']);
        Route::post('cheques/{cheque}/print-draft', [ChequeController::class, 'printDraft']);
        Route::post('cheques/{cheque}/approve-draft', [ChequeController::class, 'approveDraft']);
        Route::post('cheques/{cheque}/return-draft', [ChequeController::class, 'returnDraft']);
        Route::post('cheques/{cheque}/final-print', [ChequeController::class, 'confirmFinalPrint']);
        Route::post('cheques/{cheque}/release', [ChequeController::class, 'release']);
        Route::post('cheques/{cheque}/cancel', [ChequeController::class, 'cancel']);
        // Spoil: the number is used up and the payment moves to the next available number.
        Route::post('cheques/{cheque}/spoil', [ChequeController::class, 'spoil']);
        // Admin/staff (authorized in the request): a spoiled cheque's replacement onto its old ACIC.
        Route::post('cheques/{cheque}/use-previous-acic', [ChequeController::class, 'usePreviousAcic']);

        // Branch B is taken by the whole ACIC: the admin forwards it, the first teller to
        // accept claims it, and only that teller deposits or hands it back.
        Route::get('acics/teller-queue', [AcicController::class, 'tellerQueue']);
        Route::get('acics/{acic}/history', [AcicController::class, 'history']);
        // Admin sends it out; the rest belong to the teller who claimed it.
        Route::post('acics/{acic}/forward-to-teller', [AcicController::class, 'forwardToTeller']);
        Route::post('acics/{acic}/accept', [AcicController::class, 'acceptByTeller']);
        // Only the teller who accepted it: Forward (to Land Bank or the payee), then the Action.
        Route::post('acics/{acic}/teller-forward', [AcicController::class, 'tellerForward']);
        Route::post('acics/{acic}/teller-complete', [AcicController::class, 'tellerComplete']);
        // Cheque ACICs: one, several or all cheques to their payees, with who received them.
        Route::post('acics/{acic}/forward-to-payee', [AcicController::class, 'forwardChequesToPayee']);
        Route::post('acics/{acic}/teller-rts', [AcicController::class, 'tellerRts']);
        Route::post('acics/{acic}/return-to-admin', [AcicController::class, 'returnToAdmin']);

        // Teller only: confirm an LDDAP has been received.
        Route::middleware('teller')->group(function () {
            Route::post('lddaps/{lddap}/receive', [LddapController::class, 'confirmReceipt']);
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
        Route::post('cheques/assign-acic', [AcicController::class, 'assignChequesByNumber']);
        // Swapping a record on an ACIC is authorized (admin/staff) in the Form Request.
        Route::post('acics/{acic}/reassign', [AcicController::class, 'reassign']);

        // LDDAP-ADA records. Reading is open to any authenticated user; using a cheque number
        // for them and putting them on an ACIC is gated to admin/staff in the Form Requests.
        Route::get('lddaps', [LddapController::class, 'index']);
        Route::get('lddaps/next-numbers', [LddapController::class, 'nextNumbers']);
        Route::get('lddaps/series', [LddapController::class, 'series']);
        Route::get('lddaps/options', [LddapController::class, 'options']);
        // The payee search: Creditors and PCG Personnel in one list.
        Route::get('lddaps/payee-options', [LddapController::class, 'payeeOptions']);
        // Registered payees, searched by name or account number.
        Route::get('payees', [PayeeController::class, 'index']);
        Route::get('payees/{payee}', [PayeeController::class, 'show']);
        Route::get('lddaps/linkable', [LddapController::class, 'linkable']);
        // One LDDAP at a time, without a check number; the number is issued when the approved
        // record is put on an ACIC.
        Route::post('lddaps', [LddapController::class, 'store']);
        // Edit: the register form again, on a For Signature or RTS record; every edit is kept.
        Route::put('lddaps/{lddap}', [LddapController::class, 'update']);
        Route::get('lddaps/{lddap}/edit-history', [LddapController::class, 'editHistory']);
        // Resubmit a corrected RTS record back to For Signature (admin/staff, in the request);
        // the trail is readable by anyone.
        Route::post('lddaps/{lddap}/resubmit', [LddapController::class, 'resubmit']);
        Route::get('lddaps/{lddap}/routing-history', [LddapController::class, 'routingHistory']);
        // "Assign LDDAP to ACIC" by typed ACIC number. Check numbers are issued here, one per
        // record, consecutively, under a row lock.
        Route::post('lddaps/assign-acic', [LddapController::class, 'assignToAcicByNumber']);
        Route::post('acics/{acic}/lddaps', [LddapController::class, 'assignToAcic']);

        // The creditor and PCG personnel lists: list, add one, batch upload (.xlsx / .csv).
        // Admin/staff only, authorized in the Form Requests.
        foreach (['creditors' => CreditorController::class, 'pcg-personnel' => PcgPersonnelController::class] as $path => $controller) {
            Route::get($path, [$controller, 'index']);
            Route::post($path, [$controller, 'store']);
            Route::post("{$path}/batch-upload", [$controller, 'upload']);
        }

        // Staff propose a correction to an LDDAP's details (authorized in the Form Request).
        Route::post('lddaps/{lddap}/update-requests', [LddapUpdateRequestController::class, 'store']);
        // Anyone authenticated can read an LDDAP's request history (with outcomes).
        Route::get('lddaps/{lddap}/update-requests', [LddapUpdateRequestController::class, 'forLddap']);

        // Admin only
        Route::middleware('admin')->group(function () {
            Route::post('cheques/add-range', [ChequeController::class, 'addRange']);
            // Replacing a stale cheque.
            Route::post('cheques/{cheque}/replace', [ChequeController::class, 'replace']);
            Route::post('lddaps/add-range', [LddapController::class, 'addRange']);
            // The admin's actions on a For Signature record: RTS and Cancel.
            Route::post('lddaps/{lddap}/rts', [LddapController::class, 'rts']);
            Route::post('lddaps/{lddap}/cancel', [LddapController::class, 'cancel']);
            // Direct correction by an admin — takes effect immediately, reason required.
            Route::patch('lddaps/{lddap}', [LddapUpdateRequestController::class, 'applyDirect']);
            Route::post('acics/add-range', [AcicController::class, 'addRange']);
            Route::post('acics/{acic}/approve', [AcicController::class, 'approve']);

            Route::get('lddap-update-requests', [LddapUpdateRequestController::class, 'index']);
            Route::post('lddap-update-requests/{updateRequest}/approve', [LddapUpdateRequestController::class, 'approve']);
            Route::post('lddap-update-requests/{updateRequest}/reject', [LddapUpdateRequestController::class, 'reject']);

            Route::get('logs', [ChequeLogController::class, 'index']);

            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::delete('users/{user}', [UserController::class, 'destroy']);
        });
    });
});
