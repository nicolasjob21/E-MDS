<?php

namespace App\Http\Resources;

use App\Models\LddapUpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LddapUpdateRequest
 */
class LddapUpdateRequestResource extends JsonResource
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
            // True when an admin changed the details directly rather than reviewing a request.
            'applied_directly' => $this->applied_directly,
            'proposed_lddap_no' => $this->proposed_lddap_no,
            'proposed_obj_no' => $this->proposed_obj_no,
            'proposed_payee_name' => $this->proposed_payee_name,
            'proposed_amount' => $this->proposed_amount,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->only(['id', 'name', 'username'])),
            'reviewed_by' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->only(['id', 'name', 'username'])),
            'reviewed_at' => $this->reviewed_at,
            'review_note' => $this->review_note,
            'created_at' => $this->created_at,
            'lddap' => $this->whenLoaded('lddap', fn () => new LddapResource($this->lddap)),
        ];
    }
}
