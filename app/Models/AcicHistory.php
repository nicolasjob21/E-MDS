<?php

namespace App\Models;

use App\Enums\AcicTellerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in an ACIC's teller life: where it went, who moved it, when, and the fields that
 * step carried. Append-only — a forward-and-return cycle is never overwritten by the next one.
 */
#[Fillable(['acic_id', 'from_status', 'to_status', 'action', 'user_id', 'details', 'note', 'created_at'])]
class AcicHistory extends Model
{
    protected $table = 'acic_history';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'from_status' => AcicTellerStatus::class,
            'to_status' => AcicTellerStatus::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function acic(): BelongsTo
    {
        return $this->belongsTo(Acic::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
