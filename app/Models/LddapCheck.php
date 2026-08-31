<?php

namespace App\Models;

use App\Enums\LddapCheckStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One number in the LDDAP check series — a register independent of cheques and of ACICs.
 */
#[Fillable(['check_no', 'status', 'created_by'])]
class LddapCheck extends Model
{
    protected function casts(): array
    {
        return [
            'check_no' => 'integer',
            'status' => LddapCheckStatus::class,
        ];
    }

    public function lddap(): HasOne
    {
        return $this->hasOne(Lddap::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
