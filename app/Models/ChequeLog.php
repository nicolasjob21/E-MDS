<?php

namespace App\Models;

use App\Enums\ChequeAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'username', 'cheque_number', 'action', 'description', 'created_at'])]
class ChequeLog extends Model
{
    // Logs are immutable, append-only records: only created_at is tracked.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'cheque_number' => 'integer',
            'action' => ChequeAction::class,
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
