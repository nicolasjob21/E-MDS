<?php

namespace App\Models;

use App\Enums\AcicStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['acic_number', 'status', 'used_by', 'used_at', 'forwarded_at', 'received_by', 'received_name', 'completed_at', 'completed_by', 'created_by'])]
class Acic extends Model
{
    protected function casts(): array
    {
        return [
            'acic_number' => 'integer',
            'status' => AcicStatus::class,
            'used_at' => 'datetime',
            'forwarded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function cheques(): HasMany
    {
        return $this->hasMany(Cheque::class);
    }

    /**
     * LDDAP records on this ACIC. LDDAPs draw on their own check series, so they are carried
     * alongside cheques rather than through them.
     */
    public function lddaps(): HasMany
    {
        return $this->hasMany(Lddap::class);
    }

    /**
     * Who the ACIC was forwarded to: the receiving user's name, or the typed-in name when the
     * recipient is not a system user. Null until the ACIC is forwarded.
     */
    public function recipientName(): ?string
    {
        return $this->receivedBy?->name ?? $this->received_name;
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
