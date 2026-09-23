<?php

namespace App\Models;

use App\Enums\LddapStatus;
use App\Enums\NatureOfPayment;
use App\Support\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'lddap_check_id', 'lddap_no', 'nca_no', 'orb_no', 'dv_no', 'nature_of_payment', 'unit_id',
    'obj_no', 'amount', 'gross_amount',
    'payee_name', 'payee_id', 'payee_account_id', 'payee_account_no', 'payee_bank', 'acic_ref',
    'wtax_1', 'wtax_2', 'wtax_3', 'wtax_5',
    'vat_1', 'vat_2', 'vat_3', 'vat_5', 'vat_10', 'vat_12', 'vat_30',
    'retention', 'liquidated_damages', 'advance_payment',
    'check_date', 'fwd_to_lbp_at', 'date_loaded', 'note', 'remarks',
    'forward_to', 'forward_unit_id', 'forwarded_by', 'date_forwarded',
    'return_unit_id', 'returned_by', 'date_returned',
    'canceled_by', 'date_canceled', 'cancel_reason',
    'status', 'used_by', 'used_at', 'received_by', 'received_at',
    'reviewed_by', 'reviewed_at', 'review_note', 'acic_id', 'created_by',
])]
class Lddap extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'wtax_1' => 'decimal:2',
            'wtax_2' => 'decimal:2',
            'wtax_3' => 'decimal:2',
            'wtax_5' => 'decimal:2',
            'vat_1' => 'decimal:2',
            'vat_2' => 'decimal:2',
            'vat_3' => 'decimal:2',
            'vat_5' => 'decimal:2',
            'vat_10' => 'decimal:2',
            'vat_12' => 'decimal:2',
            'vat_30' => 'decimal:2',
            'retention' => 'decimal:2',
            'liquidated_damages' => 'decimal:2',
            'advance_payment' => 'decimal:2',
            'check_date' => 'date',
            'fwd_to_lbp_at' => 'date',
            'date_loaded' => 'date',
            'date_forwarded' => 'date',
            'date_returned' => 'date',
            'date_canceled' => 'date',
            'returned_by_bank' => 'boolean',
            'nature_of_payment' => NatureOfPayment::class,
            'status' => LddapStatus::class,
            'used_at' => 'datetime',
            'received_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * The table's search box. One term matches any of three things: part of the LDDAP number,
     * part of the check number, or — when the term reads as an amount, with or without the
     * peso sign and thousands separators — the gross amount exactly.
     *
     * @param  Builder<static>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $amount = Money::parse($term);

        $query->where(function (Builder $q) use ($term, $amount) {
            $q->where('lddap_no', 'like', "%{$term}%")
                // check_no is an integer; compare it as text so "2026" finds 120260.
                ->orWhereHas('lddapCheck', fn (Builder $c) => $c->whereRaw('CAST(check_no AS TEXT) LIKE ?', ["%{$term}%"]));

            if ($amount !== null) {
                $q->orWhere('gross_amount', '=', $amount);
            }
        });
    }

    public function isReceived(): bool
    {
        return $this->received_at !== null;
    }

    /** The number this LDDAP took from the independent check series. */
    public function lddapCheck(): BelongsTo
    {
        return $this->belongsTo(LddapCheck::class);
    }

    public function acic(): BelongsTo
    {
        return $this->belongsTo(Acic::class);
    }

    /** The office unit this LDDAP is drawn for. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The registered payee. `payee_name` and `payee_account_no` are copied from it at
     * registration, so the record reads the same even if the payee is edited later.
     */
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /** The account the payment goes to; its number and bank are copied at registration too. */
    public function payeeAccount(): BelongsTo
    {
        return $this->belongsTo(PayeeAccount::class);
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

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Staff-proposed corrections to this record's details. */
    public function updateRequests(): HasMany
    {
        return $this->hasMany(LddapUpdateRequest::class);
    }

    /** Every routing step taken on this record, oldest first. */
    /** Every edit through "Edit LDDAP Record", oldest first. */
    public function editHistory(): HasMany
    {
        return $this->hasMany(LddapEditHistory::class);
    }

    public function routingHistory(): HasMany
    {
        return $this->hasMany(LddapRoutingHistory::class)->orderBy('id');
    }

    public function forwardUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'forward_unit_id');
    }

    public function forwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_by');
    }

    public function returnUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'return_unit_id');
    }

    public function returnedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'returned_by');
    }

    public function canceledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'canceled_by');
    }
}
