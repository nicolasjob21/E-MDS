<?php

namespace App\Http\Resources;

use App\Models\ChequeLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ChequeLog
 */
class ChequeLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'username' => $this->username,
            'cheque_number' => $this->cheque_number,
            'action' => $this->action->value,
            'description' => $this->description,
            'created_at' => $this->created_at,
        ];
    }
}
