<?php

namespace App\Http\Resources;

use App\Models\Cheque;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Cheque
 */
class ChequeResource extends JsonResource
{
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
            'is_reviewed' => $this->status->isReviewed(),
            // Approved / Disapproved are settled; "Returned" sends it back for rework.
            'is_final' => $this->status->isFinal(),
            'awaits_compliance' => $this->status->awaitsCompliance(),
            // Present only when the pending count was loaded (list view).
            'has_pending_update' => $this->when(
                $this->pending_update_count !== null,
                fn () => (int) $this->pending_update_count > 0,
            ),
        ];
    }
}
