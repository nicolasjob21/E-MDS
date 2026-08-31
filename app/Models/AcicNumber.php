<?php

namespace App\Models;

use App\Enums\AcicNumberStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One number in the ACIC series.
 *
 * ACIC numbers are registered in blocks and handed out lowest-unused-first, exactly as cheque
 * and LDDAP check numbers are — they are not a counter the system invents. A block registered
 * below numbers already in use is therefore drawn on first.
 */
#[Fillable(['acic_number', 'status', 'created_by'])]
class AcicNumber extends Model
{
    protected function casts(): array
    {
        return [
            'acic_number' => 'integer',
            'status' => AcicNumberStatus::class,
        ];
    }

    public function acic(): HasOne
    {
        return $this->hasOne(Acic::class, 'acic_number', 'acic_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
