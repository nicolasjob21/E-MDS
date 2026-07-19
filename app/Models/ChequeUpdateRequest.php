<?php

namespace App\Models;

use App\Enums\RequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['cheque_id', 'proposed_payee_name', 'proposed_amount', 'proposed_cheque_date', 'requested_by', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class ChequeUpdateRequest extends Model
{
    protected function casts(): array
    {
        return [
            'status' => RequestStatus::class,
            'proposed_amount' => 'decimal:2',
            'proposed_cheque_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === RequestStatus::Pending;
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
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
