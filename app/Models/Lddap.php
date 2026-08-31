<?php

namespace App\Models;

use App\Enums\LddapStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'lddap_check_id', 'lddap_no', 'obj_no', 'amount', 'payee_name', 'check_date',
    'status', 'used_by', 'used_at', 'received_by', 'received_at',
    'reviewed_by', 'reviewed_at', 'review_note', 'acic_id', 'created_by',
])]
class Lddap extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'check_date' => 'date',
            'status' => LddapStatus::class,
            'used_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /** The number this LDDAP took from the independent check series. */
    public function lddapCheck(): BelongsTo
    {
        return $this->belongsTo(LddapCheck::class);
    }

    public function acic(): BelongsTo
    {
        return $this->belongsTo(Acic::class);
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Staff-proposed corrections to this record's details. */
    public function updateRequests(): HasMany
    {
        return $this->hasMany(LddapUpdateRequest::class);
    }
}
