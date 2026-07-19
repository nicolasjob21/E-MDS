<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\ChequeStatus;
use App\Enums\RequestStatus;
use App\Models\Cheque;
use App\Models\ChequeUpdateRequest;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class UpdateRequestService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * A staff member proposes corrected details for a cheque, with a reason.
     * Nothing changes on the cheque yet — an admin must approve the change.
     *
     * @param  array{payee_name: string, amount: mixed, cheque_date: mixed}  $proposed
     *
     * @throws ValidationException
     */
    public function create(User $staff, Cheque $cheque, array $proposed, string $reason): ChequeUpdateRequest
    {
        if (! in_array($cheque->status, [ChequeStatus::Used, ChequeStatus::Received], true)) {
            throw ValidationException::withMessages([
                'cheque' => 'Only a used or received cheque has details that can be updated.',
            ]);
        }

        $pendingExists = $cheque->updateRequests()
            ->where('status', RequestStatus::Pending)
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'cheque' => "Cheque #{$cheque->cheque_number} already has a pending update request.",
            ]);
        }

        $proposedDate = $proposed['cheque_date'] instanceof \DateTimeInterface
            ? Carbon::parse($proposed['cheque_date'])->toDateString()
            : (string) $proposed['cheque_date'];

        // Reject a no-op: at least one detail must actually differ from the current cheque.
        $unchanged = (string) $cheque->payee_name === (string) $proposed['payee_name']
            && number_format((float) $cheque->amount, 2, '.', '') === number_format((float) $proposed['amount'], 2, '.', '')
            && (string) $cheque->cheque_date?->toDateString() === $proposedDate;

        if ($unchanged) {
            throw ValidationException::withMessages([
                'reason' => 'The proposed details are identical to the current ones — nothing to update.',
            ]);
        }

        $request = ChequeUpdateRequest::create([
            'cheque_id' => $cheque->id,
            'proposed_payee_name' => $proposed['payee_name'],
            'proposed_amount' => $proposed['amount'],
            'proposed_cheque_date' => $proposedDate,
            'requested_by' => $staff->id,
            'reason' => $reason,
            'status' => RequestStatus::Pending,
        ]);

        $this->logger->log(
            $staff,
            ChequeAction::RequestedUpdate,
            $cheque->cheque_number,
            "Requested an update to cheque number {$cheque->cheque_number}: {$reason}",
        );

        // Notify every active admin (except the requester, if they happen to be one) so the
        // pending request surfaces in their bell dropdown.
        Notification::send(
            User::query()->activeAdmins()->whereKeyNot($staff->id)->get(),
            new ActivityNotification(
                kind: 'request',
                title: "Update requested · Cheque #{$cheque->cheque_number}",
                message: "{$staff->name} asked to correct cheque #{$cheque->cheque_number}: {$reason}",
                url: '/admin/update-requests',
                chequeNumber: $cheque->cheque_number,
            ),
        );

        return $request->fresh(['requestedBy']);
    }

    /**
     * An admin approves a pending request, applying the staff-proposed details to the cheque.
     *
     * @throws ValidationException
     */
    public function approve(User $admin, ChequeUpdateRequest $request, ?string $note = null): ChequeUpdateRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($admin, $request, $note) {
            $cheque = $request->cheque()->lockForUpdate()->first();

            $before = [
                'payee_name' => $cheque->payee_name,
                'amount' => $cheque->amount,
                'cheque_date' => $cheque->cheque_date?->toDateString(),
            ];

            $cheque->update([
                'payee_name' => $request->proposed_payee_name,
                'amount' => $request->proposed_amount,
                'cheque_date' => $request->proposed_cheque_date,
            ]);

            $request->update([
                'status' => RequestStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => Carbon::now(),
                'review_note' => $note,
            ]);

            $changes = $this->describeChanges($before, [
                'payee_name' => $cheque->payee_name,
                'amount' => $cheque->amount,
                'cheque_date' => $cheque->cheque_date?->toDateString(),
            ]);

            $this->logger->log(
                $admin,
                ChequeAction::ApprovedUpdate,
                $cheque->cheque_number,
                "Approved update to cheque number {$cheque->cheque_number}."
                    .($changes !== '' ? " Changes: {$changes}." : ' No field changes.'),
            );

            $request->requestedBy?->notify(new ActivityNotification(
                kind: 'approved',
                title: "Update approved · Cheque #{$cheque->cheque_number}",
                message: "{$admin->name} approved your update to cheque #{$cheque->cheque_number}."
                    .($note ? " Note: {$note}" : ''),
                url: '/cheques',
                chequeNumber: $cheque->cheque_number,
            ));

            return $request->fresh(['requestedBy', 'reviewedBy', 'cheque']);
        });
    }

    /**
     * An admin rejects a pending request; the cheque is left unchanged.
     *
     * @throws ValidationException
     */
    public function reject(User $admin, ChequeUpdateRequest $request, ?string $note = null): ChequeUpdateRequest
    {
        $this->assertPending($request);

        $request->update([
            'status' => RequestStatus::Rejected,
            'reviewed_by' => $admin->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $note,
        ]);

        $this->logger->log(
            $admin,
            ChequeAction::RejectedUpdate,
            $request->cheque->cheque_number,
            "Rejected update request for cheque number {$request->cheque->cheque_number}."
                .($note ? " Reason: {$note}" : ''),
        );

        $request->requestedBy?->notify(new ActivityNotification(
            kind: 'rejected',
            title: "Update rejected · Cheque #{$request->cheque->cheque_number}",
            message: "{$admin->name} rejected your update to cheque #{$request->cheque->cheque_number}."
                .($note ? " Reason: {$note}" : ''),
            url: '/cheques',
            chequeNumber: $request->cheque->cheque_number,
        ));

        return $request->fresh(['requestedBy', 'reviewedBy', 'cheque']);
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(ChequeUpdateRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => 'This request has already been reviewed.',
            ]);
        }
    }

    /**
     * Build a short human-readable "field: old -> new" summary of what actually changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function describeChanges(array $before, array $after): string
    {
        $parts = [];
        foreach ($after as $key => $value) {
            if ((string) $before[$key] !== (string) $value) {
                $parts[] = "{$key} '{$before[$key]}' → '{$value}'";
            }
        }

        return implode(', ', $parts);
    }
}
