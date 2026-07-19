<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cheque_number', 'payee_name', 'amount', 'cheque_date', 'status', 'used_by', 'used_at', 'teller_name', 'cashed_at', 'created_by'])]
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
            'cashed_at' => 'date',
        ];
    }

    public function isCashed(): bool
    {
        return $this->cashed_at !== null;
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
