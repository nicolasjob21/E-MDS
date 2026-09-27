<?php

namespace App\Models;

use App\Enums\AcicStatus;
use App\Enums\AcicTellerStatus;
use App\Enums\AcicType;
use App\Enums\ChequeStatus;
use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['acic_number', 'status', 'used_by', 'used_at', 'forwarded_at', 'received_by', 'received_name', 'completed_at', 'completed_by', 'forwarded_to_teller_by', 'forwarded_to_teller_at', 'forward_note', 'accepted_by', 'accepted_at', 'deposit_date', 'deposit_bank', 'deposit_reference', 'deposit_note', 'returned_to_admin_by', 'returned_to_admin_at', 'return_reason',
    'type', 'teller_status', 'forwarded_to_land_bank_at', 'transmittal_no', 'land_bank_note',
    'returned_by_bank_at', 'bank_return_reason', 'credited_at', 'bank_confirmation_no',
    'confirmed_by', 'completion_note', 'created_by',
    // The teller's Forward (to Land Bank or the payee) and Action (Completed or RTS).
    'teller_forwarded_to', 'teller_forwarded_by', 'teller_forwarded_at', 'teller_action_by', 'teller_action_at', 'rts_reason'])]
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
            'teller_forwarded_at' => 'datetime',
            'teller_action_at' => 'datetime',
        ];
    }

    /**
     * The ACIC tables' search: part of the ACIC number, or of the cheque number, LDDAP number,
     * LDDAP check number or DV number of anything on it — any case. `%` and `_` are literal.
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';

        $query->where(function (Builder $q) use ($like) {
            $q->whereRaw("CAST(acic_number AS TEXT) LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('cheques', fn (Builder $c) => $c->whereRaw("CAST(cheque_number AS TEXT) LIKE ? ESCAPE '\\'", [$like]))
                // Grouped, so the "or"s never escape the link to this ACIC.
                ->orWhereHas('lddaps', fn (Builder $l) => $l->where(fn (Builder $w) => $w
                    ->whereRaw("LOWER(lddap_no) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereRaw("LOWER(dv_no) LIKE ? ESCAPE '\\'", [$like])
                    ->orWhereHas('lddapCheck', fn (Builder $k) => $k->whereRaw("CAST(check_no AS TEXT) LIKE ? ESCAPE '\\'", [$like]))));
        });
    }

    /** May it be forwarded to the payee? Cheque ACICs only — an LDDAP ACIC goes to LBP. */
    public function canGoToPayee(): bool
    {
        if ($this->type === AcicType::Lddap) {
            return false;
        }

        // The tables already count what an ACIC carries; use that rather than asking again.
        return $this->lddaps_count !== null ? (int) $this->lddaps_count === 0 : $this->lddaps()->doesntExist();
    }

    /** Has any cheque on it been forwarded to its payee (and not yet settled)? */
    public function hasChequesWithPayee(): bool
    {
        return $this->cheques()->where('status', ChequeStatus::ForwardedToPayee->value)->exists();
    }

    /**
     * The cheques still waiting to go to their payees: in play with the teller and not stale.
     *
     * @return Collection<int, Cheque>
     */
    public function chequesLeftForPayee(): Collection
    {
        return $this->cheques()->get()
            ->filter(fn (Cheque $c) => in_array($c->status, [ChequeStatus::AcceptedByTeller, ChequeStatus::Returned, ChequeStatus::ReturnedByBank], true)
                && $c->effectiveStatus() !== ChequeStatus::Stale)
            ->values();
    }

    /**
     * "3/5 forwarded to payee": of the cheques that can go (not stale, not settled), how many
     * have. Null for an ACIC where it does not apply.
     *
     * @return array{forwarded: int, total: int}|null
     */
    public function payeeProgress(): ?array
    {
        if ($this->type === AcicType::Lddap || $this->teller_status === null || $this->teller_status === AcicTellerStatus::Pending) {
            return null;
        }

        $cheques = $this->relationLoaded('cheques') ? $this->cheques : $this->cheques()->get();
        $forwarded = $cheques->where('status', ChequeStatus::ForwardedToPayee)->count();
        $left = $cheques->filter(fn (Cheque $c) => in_array($c->status, [ChequeStatus::AcceptedByTeller, ChequeStatus::Returned, ChequeStatus::ReturnedByBank], true)
            && $c->effectiveStatus() !== ChequeStatus::Stale)->count();

        return $forwarded === 0 && $this->teller_status !== AcicTellerStatus::ForwardedToPayee
            ? ['forwarded' => 0, 'total' => $left]
            : ['forwarded' => $forwarded, 'total' => $forwarded + $left];
    }

    /** What the ACIC tables show as its status: the teller's status once it is with the tellers. */
    public const DISPLAY_STATUSES = [
        'open', 'used', 'approved', 'pending', 'accepted_by_teller',
        'forwarded_to_land_bank', 'forwarded_to_payee', 'rts', 'completed',
    ];

    /**
     * Its status as the ACIC tables show it: Open, Used or Approved while it is with the admin;
     * from the moment it is forwarded to the tellers, the teller's status — Pending until a
     * teller accepts it, then Accepted by Teller, Forwarded to Land Bank / Payee, RTS, Completed.
     */
    public function displayStatus(): string
    {
        if ($this->teller_status !== null) {
            return $this->teller_status === AcicTellerStatus::ReturnedByBank ? 'rts' : $this->teller_status->value;
        }

        return $this->status->value;
    }

    public function displayStatusLabel(): string
    {
        return match ($this->displayStatus()) {
            'rts' => 'RTS',
            default => $this->teller_status?->label() ?? $this->status->label(),
        };
    }

    /** The ACIC tables' Status filter, on the status they show (see displayStatus()). */
    public function scopeInDisplayStatus(Builder $query, string $status): void
    {
        match ($status) {
            'open', 'used', 'approved', 'forwarded' => $query->where('status', $status)->whereNull('teller_status'),
            'completed' => $query->where(fn (Builder $q) => $q->where('teller_status', AcicTellerStatus::Completed->value)
                ->orWhere(fn (Builder $o) => $o->whereNull('teller_status')->where('status', AcicStatus::Completed->value))),
            'rts' => $query->whereIn('teller_status', [AcicTellerStatus::Rts->value, AcicTellerStatus::ReturnedByBank->value]),
            default => $query->where('teller_status', $status),
        };
    }

    /**
     * What a user may see in the ACIC table: a teller, only ACICs that have been forwarded to
     * the tellers (or, before that step existed, forwarded at all); everyone else, every ACIC.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->role === UserRole::Teller) {
            $query->where(fn (Builder $q) => $q->whereNotNull('teller_status')->orWhereNotNull('forwarded_at'));
        }
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->role !== UserRole::Teller || $this->teller_status !== null || $this->forwarded_at !== null;
    }

    /**
     * Is the ACIC still with the admin — not with a teller, not deposited? An ACIC a teller
     * returned to the admin is with the admin again. Only then may a record be added to it.
     */
    public function isWithAdmin(): bool
    {
        return $this->notWithAdminBecause() === null;
    }

    /** Why the ACIC is no longer with the admin, as a sentence fragment; null while it is. */
    public function notWithAdminBecause(): ?string
    {
        if ($this->teller_status === AcicTellerStatus::Completed || $this->status === AcicStatus::Completed) {
            return 'has been completed (deposited)';
        }

        if ($this->teller_status !== null) {
            return "is with the teller ({$this->teller_status->label()})";
        }

        if ($this->status === AcicStatus::Forwarded) {
            return 'has been forwarded';
        }

        return null;
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

    /** The accepting teller who forwarded it to Land Bank or the payee. */
    public function tellerForwardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_forwarded_by');
    }

    /** The accepting teller who took the Action (Completed or RTS). */
    public function tellerActionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teller_action_by');
    }

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
