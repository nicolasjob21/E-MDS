<?php

namespace App\Http\Resources;

use App\Models\LddapCheck;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LddapCheck
 */
class LddapCheckResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'check_no' => $this->check_no,
            'status' => $this->status->value,
            'created_at' => $this->created_at,
        ];
    }
}
