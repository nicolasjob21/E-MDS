<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'lddap_id', 'proposed_lddap_no', 'proposed_obj_no', 'proposed_payee_name', 'proposed_amount',
    'requested_by', 'reason', 'status', 'applied_directly', 'reviewed_by', 'reviewed_at', 'review_note',
])]
class LddapUpdateRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'proposed_amount' => 'decimal:2',
            'applied_directly' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === RequestStatus::Pending;
    }

    public function lddap(): BelongsTo
    {
        return $this->belongsTo(Lddap::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
