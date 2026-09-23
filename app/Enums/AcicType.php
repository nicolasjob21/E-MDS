<?php

namespace App\Enums;

/**
 * What an ACIC carries. Fixed when the first record goes on it, so an ACIC never becomes a
 * mixture — the teller handles one kind of paper at a time.
 */
enum AcicType: string
{
    case Cheque = 'cheque';
    case Lddap = 'lddap';

    public function label(): string
    {
        return match ($this) {
            self::Cheque => 'Cheque',
            self::Lddap => 'LDDAP',
        };
    }
}
