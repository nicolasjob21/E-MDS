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
    /** The ACIC steps and the ways out belong to an admin (or Super Admin). */
    private function admin(Request $request): bool
    {
        return $request->user()?->isAdmin() === true;
    }

    /** Preparing a cheque — drafts, the final print, editing — is anyone who can use one. */
    private function preparer(Request $request): bool
    {
        return $this->admin($request) || $request->user()?->role === UserRole::Staff;
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
            'account_no' => $this->account_no,
            'unit_name' => $this->unit_name,
            'amount' => $this->amount,
            // The teller's Action: who received it (forwarded to the payee), and its RTS outcome.
            'payee_received_by' => $this->payee_received_by,
            'payee_received_on' => $this->payee_received_on?->toDateString(),
            'rts_status' => $this->rts_status,
            // Forward to Payee (teller) and Release to Payee (admin) share these: who received it,
            // when, their unit, and who recorded it when. The status says which it was.
            'payee_receipt' => $this->when($this->received_by_name !== null || $this->date_received !== null, fn () => [
                'received_by' => $this->received_by_name,
                'date_received' => $this->date_received?->toDateString(),
                'unit' => $this->payee_unit_name,
                'recorded_by' => $this->whenLoaded('releasedBy', fn () => $this->releasedBy?->only(['id', 'name'])),
                'recorded_at' => $this->released_at,
            ]),
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
            // The draft-checking flow.
            'can_edit' => $this->preparer($request) && $this->effectiveStatus()->isEditable(),
            'can_print_draft' => $this->preparer($request) && $this->effectiveStatus()->canPrintDraft(),
            // Approve or Return — Administrators and Super Admins.
            'can_check_draft' => $this->admin($request) && $this->effectiveStatus() === ChequeStatus::ForChecking,
            'can_final_print' => $this->preparer($request) && $this->effectiveStatus() === ChequeStatus::ForFinalPrint,
            'can_assign' => $this->admin($request) && $this->effectiveStatus()->isAcicEligible() && $this->acic_id === null,
            'can_release' => $this->admin($request) && $this->effectiveStatus() === ChequeStatus::Approved && $this->acic_id !== null,
            'can_cancel' => $this->admin($request) && $this->effectiveStatus()->canCancel(),
            'can_spoil' => $this->admin($request) && $this->effectiveStatus()->canSpoil(),
            // A cheque on an ACIC can be viewed and reprinted from its row. (Before that, the
            // draft and the final print are steps of their own.)
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

            // The reason behind an RTS, a cancel or a spoil.
            'exception_reason' => $this->exception_reason,

            // Who marked it Spoiled, and when. The replacement is `replaced_by`.
            'spoil' => $this->when($this->spoiled_at !== null, fn () => [
                'spoiled_by' => $this->whenLoaded('spoiledBy', fn () => $this->spoiledBy?->only(['id', 'name', 'username'])),
                'spoiled_at' => $this->spoiled_at,
                'reason' => $this->exception_reason,
            ]),

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

            // The cheque this one replaces (stale or spoiled), and the one that replaced it.
            'replaces' => $this->whenLoaded('replaces', fn () => $this->replaces?->only(['id', 'cheque_number'])),
            'replaced_by' => $this->whenLoaded('replacedBy', fn () => $this->replacedBy?->only(['id', 'cheque_number'])),
            // A spoiled cheque: the ACIC it was on when it was spoiled.
            'spoiled_from_acic' => $this->whenLoaded('spoiledFromAcic', fn () => $this->spoiledFromAcic?->only(['id', 'acic_number'])),
            // A replacement of a spoiled cheque that was on an ACIC: that ACIC, and whether
            // "Use previous ACIC" is open (only while it is still with the admin). Null otherwise.
            'previous_acic' => $this->when(
                $this->relationLoaded('replaces') && $this->replaces?->relationLoaded('spoiledFromAcic'),
                fn () => $this->previousAcic(),
            ),
        ];
    }

    /** @return array{id: int, acic_number: int, spoiled_cheque_number: int, allowed: bool, reason: ?string}|null */
    private function previousAcic(): ?array
    {
        $spoiled = $this->replaces;
        $acic = $spoiled?->spoiledFromAcic;

        if ($spoiled === null || $spoiled->status !== ChequeStatus::Spoiled || $acic === null) {
            return null;
        }

        $why = $acic->notWithAdminBecause();

        return [
            'id' => $acic->id,
            'acic_number' => $acic->acic_number,
            'spoiled_cheque_number' => $spoiled->cheque_number,
            'allowed' => $why === null,
            'reason' => $why === null ? null : "ACIC #{$acic->acic_number} {$why}, so the replacement must go on a new ACIC.",
        ];
    }
}
