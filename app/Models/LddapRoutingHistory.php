<?php

namespace App\Models;

use App\Enums\LddapRoutingAction;
use App\Enums\LddapStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of an LDDAP's routing — who took it, when, with what note — so the whole trail can
 * be read back on the record. Append-only.
 */
#[Fillable(['lddap_id', 'action', 'from_status', 'to_status', 'user_id', 'unit_id', 'counterparty', 'received_by_name', 'received_on', 'acted_on', 'note'])]
class LddapRoutingHistory extends Model
{
    protected $table = 'lddap_routing_history';

    protected function casts(): array
    {
        return [
            'action' => LddapRoutingAction::class,
            'from_status' => LddapStatus::class,
            'to_status' => LddapStatus::class,
            'acted_on' => 'date',
            'received_on' => 'date',
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

    /** The unit forwarded to, or received from. */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
