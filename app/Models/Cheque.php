<?php

namespace App\Models;

use App\Enums\ChequeStatus;
use App\Support\Validity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'cheque_number', 'payee_name', 'amount', 'cheque_date', 'acic_id', 'status', 'used_by', 'used_at',
    'received_by', 'received_at', 'reviewed_by', 'reviewed_at', 'review_note', 'created_by',
    // The disposition axis: where the cheque physically is, and its 90-day clock.
    'validity_until', 'stale_at', 'expiry_alert_sent_at', 'replaces_id', 'replaced_by_id',
    'forward_to_name', 'forward_unit_name', 'received_by_name_in', 'date_received_in',
    'from_unit_name', 'exception_reason', 'rts_at',
    'received_by_name', 'date_received', 'released_by', 'released_at', 'release_note',
    'forwarded_to', 'forwarded_by', 'date_forwarded', 'forward_note',
    'approved_by_teller', 'teller_approved_at', 'bank_name', 'date_deposited', 'deposit_reference', 'deposit_note',
    'returned_by', 'returned_at', 'return_reason',
])]
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
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'validity_until' => 'date',
            'stale_at' => 'datetime',
            'expiry_alert_sent_at' => 'datetime',
            'date_received' => 'date',
            'date_received_in' => 'date',
            'rts_at' => 'datetime',
            'returned_by_bank' => 'boolean',
            'released_at' => 'datetime',
            'date_forwarded' => 'datetime',
            'teller_approved_at' => 'datetime',
            'date_deposited' => 'date',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * The 90-day clock is derived, never typed: whenever a cheque's date is set or changed —
     * by `useNext()`, an approved correction, or an admin's direct edit — `validity_until`
     * follows it, and a date change reopens the one-time expiry alert.
     */
    protected static function booted(): void
    {
        static::saving(function (self $cheque) {
            if (! $cheque->isDirty('cheque_date')) {
                return;
            }

            $cheque->validity_until = Validity::until($cheque->cheque_date)?->toDateString();
        });

        // A new date is a new 90 days, so the cheque may be alerted on again. Done here, with
        // a targeted write, because the alert stamp is set by the sweep with a bare query —
        // an in-memory model may not know it is there, and so would not mark it dirty.
        static::saved(function (self $cheque) {
            if ($cheque->wasChanged('cheque_date')) {
                static::query()->whereKey($cheque->getKey())->update(['expiry_alert_sent_at' => null]);
                $cheque->setAttribute('expiry_alert_sent_at', null)->syncOriginalAttribute('expiry_alert_sent_at');
            }
        });
    }

    // ---------------------------------------------------------------- validity

    /**
     * Where the cheque stands **right now**, whether or not the nightly sweep has run.
     *
     * A cheque past its validity is stale from the moment the date turns, so every read — the
     * list, the detail page, and every guard — asks this rather than the stored column. The
     * sweep only writes down what this already reports.
     */
    public function effectiveStatus(): ChequeStatus
    {
        return $this->status->isPerishable() && $this->hasExpired()
            ? ChequeStatus::Stale
            : $this->status;
    }

    /** Is the cheque past its last valid day? Says nothing about what is stored. */
    public function hasExpired(): bool
    {
        return Validity::isExpired($this->validity_until);
    }

    /** Is it inside the 10-day alert window? A cheque already stale is not. */
    public function isExpiringSoon(): bool
    {
        return $this->status->isPerishable()
            && Validity::isExpiringSoon($this->validity_until);
    }

    /** Days before it goes stale; 0 today, negative once it has. */
    public function daysLeft(): ?int
    {
        return Validity::daysLeft($this->validity_until);
    }

    /**
     * Cheques the 90-day clock still runs against — and that the sweep and the alert look at.
     * Mirrors `disposition()` in SQL.
     *
     * @param  Builder<Cheque>  $query
     */
    public function scopePerishable(Builder $query): void
    {
        $query->whereIn('status', array_column(ChequeStatus::perishable(), 'value'))
            ->whereNotNull('validity_until');
    }

    /** Perishable cheques whose last valid day is behind us — the sweep's working set. */
    public function scopeExpired(Builder $query): void
    {
        $query->perishable()->whereDate('validity_until', '<', Validity::today()->toDateString());
    }

    /**
     * Cheques whose **effective** status is `$status` — the same answer `effectiveStatus()`
     * gives per row, in SQL.
     *
     * A cheque past its validity counts as Stale here and nowhere else, so an expired but
     * unswept cheque appears under Stale rather than under the status it was left in.
     *
     * @param  Builder<Cheque>  $query
     */
    public function scopeEffectivelyIn(Builder $query, ChequeStatus $status): void
    {
        $today = Validity::today()->toDateString();

        if ($status === ChequeStatus::Stale) {
            $query->where(function (Builder $q) use ($today) {
                $q->where('status', ChequeStatus::Stale->value)
                    ->orWhere(fn (Builder $e) => $e->perishable()->whereDate('validity_until', '<', $today));
            });

            return;
        }

        $query->where('status', $status->value);

        if ($status->isPerishable()) {
            $query->where(fn (Builder $q) => $q->whereNull('validity_until')->orWhereDate('validity_until', '>=', $today));
        }
    }

    /** Perishable cheques with 10 days or fewer left, expiry day included. */
    public function scopeExpiringSoon(Builder $query): void
    {
        $query->perishable()
            ->whereDate('validity_until', '>=', Validity::today()->toDateString())
            ->whereDate('validity_until', '<=', Validity::today()->addDays(Validity::ALERT_DAYS)->toDateString());
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    public function usedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function acic(): BelongsTo
    {
        return $this->belongsTo(Acic::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updateRequests(): HasMany
    {
        return $this->hasMany(ChequeUpdateRequest::class);
    }

    // ---------------------------------------------------------------- disposition relations

    public function statusHistory(): HasMany
    {
        return $this->hasMany(ChequeStatusHistory::class);
    }

    /** Who the cheque was released to is a name, not a user — this is who handed it over. */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    /** The teller the cheque was forwarded to for deposit. */
    public function forwardedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_to');
    }

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    public function approvedByTeller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_teller');
    }

    /** The teller who handed the deposit request back. */
    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    /** The stale cheque this one was issued to replace. */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id');
    }

    /** The cheque issued to replace this stale one. */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
