<?php

namespace App\Http\Resources;

use App\Models\Lddap;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the LDDAP table.
 *
 * The check number comes from the LDDAP's own series (`lddap_checks`), not from the cheque
 * register — `check_no` here is unrelated to any `cheque_number`.
 *
 * @mixin Lddap
 */
class LddapResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // `whenLoaded()` yields a MissingValue rather than null, which `?->` would not
        // short-circuit, so the relation is checked directly before it is read through.
        $acic = $this->relationLoaded('acic') ? $this->acic : null;

        return [
            'id' => $this->id,
            // Check No., from the independent LDDAP check series.
            'check_no' => $this->whenLoaded('lddapCheck', fn () => $this->lddapCheck?->check_no),
            'check_date' => $this->check_date?->toDateString(),

            'lddap_no' => $this->lddap_no,
            'obj_no' => $this->obj_no,
            'amount' => $this->amount,
            'payee_name' => $this->payee_name,
            'status' => $this->status->value,

            'used_by' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->only(['id', 'name', 'username'])),
            'used_at' => $this->used_at,

            // Teller receipt confirmation (separate lifecycle event).
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->only(['id', 'name', 'username'])),
            'received_at' => $this->received_at,
            'is_received' => $this->isReceived(),

            // Admin review outcome (the status itself is completed | compliance | cancelled).
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name', 'username'])),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'is_reviewed' => $this->status->isReviewed(),
            'is_final' => $this->status->isFinal(),
            'awaits_compliance' => $this->status->awaitsCompliance(),

            // ACIC No. and Forward To / Date.
            'acic_id' => $this->acic_id,
            'acic_number' => $acic?->acic_number,
            'acic_status' => $acic?->status->value,
            'forwarded_at' => $acic?->forwarded_at,
            'forwarded_to' => $acic?->recipientName(),

            // Present only when the pending count was loaded (list view).
            'has_pending_update' => $this->when(
                $this->pending_update_count !== null,
                fn () => (int) $this->pending_update_count > 0,
            ),

            'created_at' => $this->created_at,
        ];
    }
}
