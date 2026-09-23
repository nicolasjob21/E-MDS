<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a cheque's life: where it went, who moved it, when, and the fields that step
 * carried. Append-only. An ACIC-level step writes one row per cheque on that ACIC.
 */
#[Fillable(['cheque_id', 'from_status', 'to_status', 'action', 'user_id', 'acic_id', 'details', 'note', 'created_at'])]
class ChequeStatusHistory extends Model
{
    protected $table = 'cheque_status_history';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => ChequeStatus::class,
            'to_status' => ChequeStatus::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function acic(): BelongsTo
    {
        return $this->belongsTo(Acic::class);
    }
}
