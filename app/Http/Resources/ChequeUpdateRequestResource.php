<?php

namespace App\Http\Resources;

use App\Models\ChequeUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChequeUpdateRequest
 */
class ChequeUpdateRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'proposed_payee_name' => $this->proposed_payee_name,
            'proposed_amount' => $this->proposed_amount,
            'proposed_cheque_date' => $this->proposed_cheque_date?->toDateString(),
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->only(['id', 'name', 'username'])),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name', 'username'])),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at,
            'cheque' => $this->whenLoaded('cheque', fn () => new ChequeResource($this->cheque)),
        ];
    }
}
