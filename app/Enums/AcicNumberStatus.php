<?php

namespace App\Enums;

/**
 * A number in the ACIC series is either still in the pool or already taken by an ACIC.
 */
enum AcicNumberStatus: string
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
