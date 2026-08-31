<?php

namespace App\Enums;

/**
 * A number in the LDDAP check series is either still in the pool or already taken.
 */
enum LddapCheckStatus: string
{
    case Available = 'available';
    case Used = 'used';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Used => 'Used',
        };
    }
}
