<?php

namespace App\Http\Resources;

use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use App\Models\Cheque;
use App\Support\Validity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cheque
 */
class ChequeResource extends JsonResource
{
    /** Every step but the teller's belongs to an admin. */
    private function admin(Request $request): bool
    {
        return $request->user()?->role === UserRole::Admin;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cheque_number' => $this->cheque_number,
            'payee_name' => $this->payee_name,
            'amount' => $this->amount,
            'cheque_date' => $this->cheque_date?->toDateString(),
            // The ACIC this cheque sits on, if any.
            'acic_id' => $this->acic_id,
            'acic_number' => $this->whenLoaded('acic', fn () => $this->acic?->acic_number),
            'acic_status' => $this->whenLoaded('acic', fn () => $this->acic?->status->value),
            'status' => $this->status->value,
            'used_by' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->only(['id', 'name', 'username'])),
            'used_by_name' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->name),
            'used_at' => $this->used_at,
            // Teller receipt confirmation (separate lifecycle event).
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->only(['id', 'name', 'username'])),
            'received_by_name' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'received_at' => $this->received_at,
            'is_received' => $this->isReceived(),
            // Admin review outcome (the status itself is approved | complies | disapproved).
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name', 'username'])),
            'reviewed_by_name' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            // Approved / Disapproved are settled; "Returned" sends it back for rework.
            'is_final' => $this->status->isFinal(),
            'status_label' => $this->status->label(),
            // Present only when the pending count was loaded (list view).
            'has_pending_update' => $this->when(
                $this->pending_update_count !== null,
                fn () => (int) $this->pending_update_count > 0,
            ),

            // ---- the flow, and the 90-day clock that runs beside it ----
            //
            // `status` is what is stored; `effective_status` is what it is *now* — they differ
            // only for a cheque that has run out since the last nightly sweep, and it is the
            // effective one the UI must obey.
            'effective_status' => $this->effectiveStatus()->value,
            'effective_status_label' => $this->effectiveStatus()->label(),
            'validity_until' => $this->validity_until?->toDateString(),
            'days_left' => $this->daysLeft(),
            'countdown' => Validity::countdown($this->validity_until),
            'is_stale' => $this->effectiveStatus() === ChequeStatus::Stale,
            'is_expiring_soon' => $this->isExpiringSoon(),
            'expiring_tag' => $this->when($this->isExpiringSoon(), fn () => $this->status->tag()),
            'stale_at' => $this->stale_at,

            // What this viewer may do next, decided server-side so the buttons and the
            // endpoints can never disagree. Every step but the teller's is the admin's.
            'can_route' => $this->admin($request) && $this->effectiveStatus() === ChequeStatus::Registered,
            'can_receive' => $this->admin($request) && $this->effectiveStatus() === ChequeStatus::OutForSignature,
            'can_assign' => $this->admin($request) && $this->effectiveStatus()->isAcicEligible() && $this->acic_id === null,
            'can_release' => $this->admin($request) && $this->effectiveStatus() === ChequeStatus::Approved && $this->acic_id !== null,
            'can_rts' => $this->admin($request) && $this->effectiveStatus()->canRts(),
            'can_cancel' => $this->admin($request) && $this->effectiveStatus()->canCancel(),
            'can_void' => $this->admin($request) && $this->effectiveStatus()->canVoid(),
            // The cheque face can be printed once the cheque is on an ACIC — that is when it
            // carries everything the printed form needs.
            'can_print' => $this->status->isOnAcic(),
            'can_replace' => $this->admin($request) && $this->effectiveStatus() === ChequeStatus::Stale && $this->replaced_by_id === null,

            // Step 2 — out for signature.
            'routing' => $this->when($this->date_forwarded !== null, fn () => [
                'forward_to_name' => $this->forward_to_name,
                'forward_unit_name' => $this->forward_unit_name,
                'forwarded_by' => $this->whenLoaded('forwardedBy', fn () => $this->forwardedBy?->only(['id', 'name'])),
                'date_forwarded' => $this->date_forwarded,
                'note' => $this->forward_note,
            ]),

            // Step 3 — signed and back.
            'receipt' => $this->when($this->date_received_in !== null, fn () => [
                'received_by_name' => $this->received_by_name_in,
                'date_received' => $this->date_received_in?->toDateString(),
                'from_unit_name' => $this->from_unit_name,
            ]),

            // The reason behind an RTS, a cancel or a void.
            'exception_reason' => $this->exception_reason,

            // Path A. Note `release.received_by_name` is who the cheque was released TO, which
            // is a different thing from the top-level `received_by_name` above (the teller who
            // confirmed physical receipt).
            'release' => $this->when($this->released_at !== null, fn () => [
                'received_by_name' => $this->received_by_name,
                'date_received' => $this->date_received?->toDateString(),
                'released_by' => $this->whenLoaded('releasedBy', fn () => $this->releasedBy?->only(['id', 'name', 'username'])),
                'released_at' => $this->released_at,
                'note' => $this->release_note,
            ]),

            // Path B, step 1.
            'forward' => $this->when($this->date_forwarded !== null, fn () => [
                'forwarded_to' => $this->whenLoaded('forwardedTo', fn () => $this->forwardedTo?->only(['id', 'name', 'username'])),
                'forwarded_by' => $this->whenLoaded('forwardedBy', fn () => $this->forwardedBy?->only(['id', 'name', 'username'])),
                'date_forwarded' => $this->date_forwarded,
                'note' => $this->forward_note,
            ]),

            // Path B, step 2.
            'deposit' => $this->when($this->date_deposited !== null, fn () => [
                'approved_by_teller' => $this->whenLoaded('approvedByTeller', fn () => $this->approvedByTeller?->only(['id', 'name', 'username'])),
                'teller_approved_at' => $this->teller_approved_at,
                'bank_name' => $this->bank_name,
                'date_deposited' => $this->date_deposited?->toDateString(),
                'reference' => $this->deposit_reference,
                'note' => $this->deposit_note,
            ]),

            // The last time a teller handed the request back.
            'returned' => $this->when($this->returned_at !== null, fn () => [
                'returned_by' => $this->whenLoaded('returnedBy', fn () => $this->returnedBy?->only(['id', 'name', 'username'])),
                'returned_at' => $this->returned_at,
                'reason' => $this->return_reason,
            ]),

            // Branch B is taken by the whole ACIC; these are its columns, carried along.
            'acic_teller' => $this->whenLoaded('acic', fn () => $this->acic === null ? null : [
                'forwarded_at' => $this->acic->forwarded_to_teller_at,
                'accepted_by' => $this->acic->acceptedBy?->only(['id', 'name']),
                'accepted_at' => $this->acic->accepted_at,
                'deposit_date' => $this->acic->deposit_date?->toDateString(),
                'deposit_bank' => $this->acic->deposit_bank,
                'deposit_reference' => $this->acic->deposit_reference,
                'return_reason' => $this->acic->return_reason,
            ]),

            // The stale cheque this replaces, and the one that replaced it.
            'replaces' => $this->whenLoaded('replaces', fn () => $this->replaces?->only(['id', 'cheque_number'])),
            'replaced_by' => $this->whenLoaded('replacedBy', fn () => $this->replacedBy?->only(['id', 'cheque_number'])),
        ];
    }
}
