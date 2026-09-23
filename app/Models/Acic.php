<?php

namespace App\Models;

use App\Enums\AcicStatus;
use App\Enums\AcicTellerStatus;
use App\Enums\AcicType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['acic_number', 'status', 'used_by', 'used_at', 'forwarded_at', 'received_by', 'received_name', 'completed_at', 'completed_by', 'forwarded_to_teller_by', 'forwarded_to_teller_at', 'forward_note', 'accepted_by', 'accepted_at', 'deposit_date', 'deposit_bank', 'deposit_reference', 'deposit_note', 'returned_to_admin_by', 'returned_to_admin_at', 'return_reason',
    'type', 'teller_status', 'forwarded_to_land_bank_at', 'transmittal_no', 'land_bank_note',
    'returned_by_bank_at', 'bank_return_reason', 'credited_at', 'bank_confirmation_no',
    'confirmed_by', 'completion_note', 'created_by'])]
class Acic extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AcicType::class,
            'teller_status' => AcicTellerStatus::class,
            'forwarded_to_land_bank_at' => 'datetime',
            'returned_by_bank_at' => 'datetime',
            'credited_at' => 'datetime',
            'forwarded_to_teller_at' => 'datetime',
            'accepted_at' => 'datetime',
            'deposit_date' => 'date',
            'returned_to_admin_at' => 'datetime',
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

    // ---- Branch B: the ACIC as a whole goes to the tellers ----

    public function forwardedToTellerBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_to_teller_by');
    }

    /** The teller who claimed it. Null while it is still Pending for everyone. */
    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    public function returnedToAdminBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_to_admin_by');
    }

    /** The teller who confirmed the bank's credit. */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Every step of its teller life, oldest first. */
    public function history(): HasMany
    {
        return $this->hasMany(AcicHistory::class);
    }

    /**
     * Every record on this ACIC, of whichever kind it carries — the thing each teller step
     * moves. Cheques and LDDAPs are separate tables, so they come back as one merged list.
     *
     * @return Collection<int, Cheque|Lddap>
     */
    public function records(): Collection
    {
        return collect([...$this->cheques()->get()->all(), ...$this->lddaps()->get()->all()]);
    }

    /** Does any record still carry the bank's return? Completion is blocked while one does. */
    public function hasUnresolvedBankReturns(): bool
    {
        return $this->cheques()->where('returned_by_bank', true)->exists()
            || $this->lddaps()->where('returned_by_bank', true)->exists();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
