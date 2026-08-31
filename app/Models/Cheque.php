<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cheque_number', 'payee_name', 'amount', 'cheque_date', 'acic_id', 'status', 'used_by', 'used_at', 'received_by', 'received_at', 'reviewed_by', 'reviewed_at', 'review_note', 'created_by'])]
class Cheque extends Model
{
    protected function casts(): array
    {
        return [
            'cheque_number' => 'integer',
            'amount' => 'decimal:2',
            'cheque_date' => 'date',
            'status' => ChequeStatus::class,
            'used_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    public function isReviewed(): bool
    {
        return $this->status->isReviewed();
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

    public function acic(): BelongsTo
    {
        return $this->belongsTo(Acic::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updateRequests(): HasMany
    {
        return $this->hasMany(ChequeUpdateRequest::class);
    }
}
