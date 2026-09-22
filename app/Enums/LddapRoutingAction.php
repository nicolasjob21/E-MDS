<?php

namespace App\Enums;

/** The step a routing-history entry records. */
enum LddapRoutingAction: string
{
    case Registered = 'registered';
    case Forwarded = 'forwarded';
    case Received = 'received';
    case Approved = 'approved';
    case Rts = 'rts';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Registered => 'Registered',
            self::Forwarded => 'Forwarded',
            self::Received => 'Received',
            self::Approved => 'Approved',
            self::Rts => 'Returned to sender',
            self::Canceled => 'Canceled',
        };
    }
}
