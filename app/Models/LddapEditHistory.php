<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One edit of an LDDAP record: who made it, when, and each field's before and after.
 */
#[Fillable(['lddap_id', 'user_id', 'changes', 'created_at'])]
class LddapEditHistory extends Model
{
    protected $table = 'lddap_edit_history';

    // Append-only: only created_at is tracked.
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function lddap(): BelongsTo
    {
        return $this->belongsTo(Lddap::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
