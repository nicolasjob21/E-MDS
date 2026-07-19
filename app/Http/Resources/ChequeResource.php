<?php

namespace App\Http\Resources;

use App\Enums\ChequeStatus;
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
            'status' => $this->status->value,
            'used_by' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->only(['id', 'name', 'username'])),
            'used_by_name' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->name),
            'used_at' => $this->used_at,
            // Teller receipt confirmation (separate lifecycle event).
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->only(['id', 'name', 'username'])),
            'received_by_name' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy?->name),
            'received_at' => $this->received_at,
            'is_received' => $this->status === ChequeStatus::Received,
            // Present only when the pending count was loaded (list view).
            'has_pending_update' => $this->when(
                $this->pending_update_count !== null,
                fn () => (int) $this->pending_update_count > 0,
            ),
        ];
    }
}
