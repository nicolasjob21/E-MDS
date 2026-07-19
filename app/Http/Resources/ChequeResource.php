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
            'status' => $this->status->value,
            'used_by' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->only(['id', 'name', 'username'])),
            'used_by_name' => $this->whenLoaded('usedBy', fn () => $this->usedBy?->name),
            'used_at' => $this->used_at,
            // Bank encashment (separate lifecycle event).
            'teller_name' => $this->teller_name,
            'cashed_at' => $this->cashed_at?->toDateString(),
            'is_cashed' => $this->cashed_at !== null,
        ];
    }
}
