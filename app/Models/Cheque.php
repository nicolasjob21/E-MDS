<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['cheque_number', 'payee_name', 'amount', 'cheque_date', 'status', 'used_by', 'used_at', 'received_by', 'received_at', 'created_by'])]
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
        ];
    }

    public function isReceived(): bool
    {
        return $this->status === ChequeStatus::Received;
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
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
