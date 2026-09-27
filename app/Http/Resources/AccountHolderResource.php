<?php

namespace App\Http\Resources;

use App\Models\AccountHolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A creditor or PCG personnel entry, with who added it.
 *
 * @mixin AccountHolder
 */
class AccountHolderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'account_no' => $this->account_no,
            'unit' => $this->unit,
            'created_at' => $this->created_at,
            'added_by' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
            ] : null,
        ];
    }
}
