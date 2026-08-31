<?php

namespace App\Services;

use App\Enums\ChequeAction;
use App\Enums\LddapStatus;
use App\Enums\RequestStatus;
use App\Models\Lddap;
use App\Models\LddapUpdateRequest;
use App\Models\User;
use App\Notifications\ActivityNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Staff-proposed corrections to an LDDAP's details, applied only once an admin approves.
 *
 * Mirrors {@see UpdateRequestService} for the cheque register. The check number is never among
 * the correctable fields — it is assigned by the LDDAP series, not entered by hand.
 */
class LddapUpdateRequestService
{
    public function __construct(private readonly ActivityLogger $logger) {}

    /**
     * A staff member proposes corrected details, with a reason. Nothing changes yet.
     *
     * @param  array{lddap_no: string, obj_no: ?string, payee_name: ?string, amount: mixed}  $proposed
     *
     * @throws ValidationException
     */
    public function create(User $staff, Lddap $lddap, array $proposed, string $reason): LddapUpdateRequest
    {
        // A Returned record belongs to the staff member who used the number: the admin handed
        // *them* the remark, and they are the one notified. Anyone else correcting it would be
        // answering a question they were never asked.
        $this->assertOwnsReturned($staff, $lddap);

        $pendingExists = $lddap->updateRequests()
            ->where('status', RequestStatus::Pending)
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'lddap' => "LDDAP {$lddap->lddap_no} already has a pending update request.",
            ]);
        }

        $proposed = $this->normalise($proposed);

        // The LDDAP number is unique across the register, so a correction cannot collide with
        // another record. Re-checked under a lock at approval time, when it actually matters.
        $this->assertNumberFree($proposed['lddap_no'], $lddap);

        if ($this->matches($lddap, $proposed)) {
            throw ValidationException::withMessages([
                'reason' => 'The proposed details are identical to the current ones — nothing to update.',
            ]);
        }

        $request = LddapUpdateRequest::create([
            'lddap_id' => $lddap->id,
            'proposed_lddap_no' => $proposed['lddap_no'],
            'proposed_obj_no' => $proposed['obj_no'],
            'proposed_payee_name' => $proposed['payee_name'],
            'proposed_amount' => $proposed['amount'],
            'requested_by' => $staff->id,
            'reason' => $reason,
            'status' => RequestStatus::Pending,
        ]);

        $this->logger->log(
            $staff,
            ChequeAction::RequestedLddapUpdate,
            null,
            "Requested an update to LDDAP {$lddap->lddap_no}: {$reason}",
        );

        Notification::send(
            User::query()->activeAdmins()->whereKeyNot($staff->id)->get(),
            new ActivityNotification(
                kind: 'request',
                title: "Update requested · LDDAP {$lddap->lddap_no}",
                message: "{$staff->name} asked to correct LDDAP {$lddap->lddap_no}: {$reason}",
                url: '/admin/update-requests',
            ),
        );

        return $request->fresh(['requestedBy', 'lddap.lddapCheck']);
    }

    /**
     * A Returned LDDAP may only be corrected by the staff member it was returned to — the one
     * who used the check number in the first place.
     *
     * @throws ValidationException
     */
    private function assertOwnsReturned(User $staff, Lddap $lddap): void
    {
        if (! $lddap->status->awaitsCompliance() || $lddap->used_by === $staff->id) {
            return;
        }

        $owner = $lddap->usedBy?->name;

        throw ValidationException::withMessages([
            'lddap' => "LDDAP {$lddap->lddap_no} was returned to "
                .($owner !== null ? $owner : 'the staff member who used it')
                .'. Only they can update it.',
        ]);
    }

    /**
     * An admin corrects the details directly, taking effect immediately.
     *
     * No approval step — but the reason is mandatory and the change is written into the same
     * correction history as a staff request (flagged `applied_directly`), so the record always
     * shows who changed what and why. A record with a request already pending is refused: the
     * admin resolves that request rather than editing around it.
     *
     * @param  array{lddap_no: string, obj_no: ?string, payee_name: ?string, amount: mixed}  $proposed
     *
     * @throws ValidationException
     */
    public function applyDirect(User $admin, Lddap $lddap, array $proposed, string $reason): LddapUpdateRequest
    {
        $pendingExists = $lddap->updateRequests()
            ->where('status', RequestStatus::Pending)
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'lddap' => "LDDAP {$lddap->lddap_no} has a pending update request. Approve or reject that first, rather than editing around it.",
            ]);
        }

        $proposed = $this->normalise($proposed);

        if ($this->matches($lddap, $proposed)) {
            throw ValidationException::withMessages([
                'reason' => 'The details are identical to the current ones — nothing to update.',
            ]);
        }

        return DB::transaction(function () use ($admin, $lddap, $proposed, $reason) {
            $lddap = Lddap::query()->whereKey($lddap->getKey())->lockForUpdate()->firstOrFail();

            $this->assertNumberFree($proposed['lddap_no'], $lddap);

            $before = $this->snapshot($lddap);

            $lddap->update([
                'lddap_no' => $proposed['lddap_no'],
                'obj_no' => $proposed['obj_no'],
                'payee_name' => $proposed['payee_name'],
                'amount' => $proposed['amount'],
            ]);

            $now = Carbon::now();

            // Recorded as an already-approved entry so the history reads the same either way.
            $record = LddapUpdateRequest::create([
                'lddap_id' => $lddap->id,
                'proposed_lddap_no' => $proposed['lddap_no'],
                'proposed_obj_no' => $proposed['obj_no'],
                'proposed_payee_name' => $proposed['payee_name'],
                'proposed_amount' => $proposed['amount'],
                'requested_by' => $admin->id,
                'reason' => $reason,
                'status' => RequestStatus::Approved,
                'applied_directly' => true,
                'reviewed_by' => $admin->id,
                'reviewed_at' => $now,
            ]);

            $changes = $this->describeChanges($before, $this->snapshot($lddap->fresh()));

            $this->logger->log(
                $admin,
                ChequeAction::UpdatedLddap,
                null,
                "Updated LDDAP {$lddap->lddap_no} directly. Reason: {$reason}."
                    .($changes !== '' ? " Changes: {$changes}." : ''),
            );

            // The staff member who used the number should know their record changed under them.
            $lddap->usedBy?->notify(new ActivityNotification(
                kind: 'request',
                title: "LDDAP {$lddap->lddap_no} updated by an admin",
                message: "{$admin->name} corrected LDDAP {$lddap->lddap_no}. Reason: {$reason}",
                url: '/lddaps',
            ));

            return $record->fresh(['requestedBy', 'reviewedBy', 'lddap.lddapCheck']);
        });
    }

    /**
     * An admin approves a pending request, applying the proposed details to the LDDAP.
     *
     * @throws ValidationException
     */
    public function approve(User $admin, LddapUpdateRequest $request, ?string $note = null): LddapUpdateRequest
    {
        $this->assertPending($request);

        return DB::transaction(function () use ($admin, $request, $note) {
            $lddap = $request->lddap()->lockForUpdate()->first();

            // Another record may have taken the proposed number since the request was raised.
            $this->assertNumberFree($request->proposed_lddap_no, $lddap);

            $before = $this->snapshot($lddap);
            $now = Carbon::now();

            // Approving a correction on a Returned record *is* the sign-off:
            // the deficiency the remark described has been fixed and accepted, so the record
            // moves straight to Approved rather than waiting on a second review.
            $resolvesCompliance = $lddap->status === LddapStatus::Compliance;

            $lddap->update([
                'lddap_no' => $request->proposed_lddap_no,
                'obj_no' => $request->proposed_obj_no,
                'payee_name' => $request->proposed_payee_name,
                'amount' => $request->proposed_amount,
            ] + ($resolvesCompliance ? [
                'status' => LddapStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => $now,
                'review_note' => $note,
            ] : []));

            $request->update([
                'status' => RequestStatus::Approved,
                'reviewed_by' => $admin->id,
                'reviewed_at' => $now,
                'review_note' => $note,
            ]);

            $changes = $this->describeChanges($before, $this->snapshot($lddap->fresh()));

            $this->logger->log(
                $admin,
                ChequeAction::ApprovedLddapUpdate,
                null,
                "Approved update to LDDAP {$lddap->lddap_no}."
                    .($changes !== '' ? " Changes: {$changes}." : ' No field changes.')
                    .($resolvesCompliance ? ' Compliance resolved — the record is now Approved.' : ''),
            );

            $request->requestedBy?->notify(new ActivityNotification(
                kind: 'approved',
                title: "Update approved · LDDAP {$lddap->lddap_no}",
                message: "{$admin->name} approved your update to LDDAP {$lddap->lddap_no}."
                    .($resolvesCompliance ? ' It is now Approved.' : '')
                    .($note ? " Note: {$note}" : ''),
                url: '/lddaps',
            ));

            return $request->fresh(['requestedBy', 'reviewedBy', 'lddap.lddapCheck']);
        });
    }

    /**
     * An admin rejects a pending request; the LDDAP is left unchanged.
     *
     * @throws ValidationException
     */
    public function reject(User $admin, LddapUpdateRequest $request, ?string $note = null): LddapUpdateRequest
    {
        $this->assertPending($request);

        $request->update([
            'status' => RequestStatus::Rejected,
            'reviewed_by' => $admin->id,
            'reviewed_at' => Carbon::now(),
            'review_note' => $note,
        ]);

        $lddapNo = $request->lddap->lddap_no;

        $this->logger->log(
            $admin,
            ChequeAction::RejectedLddapUpdate,
            null,
            "Rejected update request for LDDAP {$lddapNo}.".($note ? " Reason: {$note}" : ''),
        );

        $request->requestedBy?->notify(new ActivityNotification(
            kind: 'rejected',
            title: "Update rejected · LDDAP {$lddapNo}",
            message: "{$admin->name} rejected your update to LDDAP {$lddapNo}."
                .($note ? " Reason: {$note}" : ''),
            url: '/lddaps',
        ));

        return $request->fresh(['requestedBy', 'reviewedBy', 'lddap.lddapCheck']);
    }

    /**
     * Trim the proposed values and turn blank optionals into nulls, so a comparison against the
     * stored record is like-for-like.
     *
     * @param  array{lddap_no: string, obj_no: ?string, payee_name: ?string, amount: mixed}  $proposed
     * @return array{lddap_no: string, obj_no: ?string, payee_name: ?string, amount: mixed}
     */
    private function normalise(array $proposed): array
    {
        $blankToNull = function (?string $value): ?string {
            $value = $value === null ? null : trim($value);

            return $value === '' ? null : $value;
        };

        return [
            'lddap_no' => trim($proposed['lddap_no']),
            'obj_no' => $blankToNull($proposed['obj_no'] ?? null),
            'payee_name' => $blankToNull($proposed['payee_name'] ?? null),
            'amount' => $proposed['amount'],
        ];
    }

    /**
     * @param  array{lddap_no: string, obj_no: ?string, payee_name: ?string, amount: mixed}  $proposed
     */
    private function matches(Lddap $lddap, array $proposed): bool
    {
        return $lddap->lddap_no === $proposed['lddap_no']
            && $lddap->obj_no === $proposed['obj_no']
            && $lddap->payee_name === $proposed['payee_name']
            && $this->money($lddap->amount) === $this->money($proposed['amount']);
    }

    /**
     * @throws ValidationException
     */
    private function assertNumberFree(string $lddapNo, Lddap $lddap): void
    {
        $taken = Lddap::query()
            ->where('lddap_no', $lddapNo)
            ->whereKeyNot($lddap->getKey())
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'lddap_no' => "LDDAP {$lddapNo} is already registered against another check number.",
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertPending(LddapUpdateRequest $request): void
    {
        if (! $request->isPending()) {
            throw ValidationException::withMessages([
                'request' => 'This request has already been reviewed.',
            ]);
        }
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(Lddap $lddap): array
    {
        return [
            'lddap_no' => (string) $lddap->lddap_no,
            'obj_no' => (string) $lddap->obj_no,
            'payee_name' => (string) $lddap->payee_name,
            'amount' => $this->money($lddap->amount),
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    /**
     * Build a short human-readable "field: old -> new" summary of what actually changed.
     *
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function describeChanges(array $before, array $after): string
    {
        $parts = [];
        foreach ($after as $key => $value) {
            if ($before[$key] !== $value) {
                $parts[] = "{$key} '{$before[$key]}' → '{$value}'";
            }
        }

        return implode(', ', $parts);
    }
}
