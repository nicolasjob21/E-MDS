<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One of a payee's bank accounts — shown as "account number – bank" when a payment is registered. */
#[Fillable(['payee_id', 'account_no', 'bank'])]
class PayeeAccount extends Model
{
    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class);
    }

    /** How the account reads in a select: "1701-0426-18 – LBP". */
    public function label(): string
    {
        return "{$this->account_no} – {$this->bank}";
    }
}
